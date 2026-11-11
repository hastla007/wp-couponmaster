<?php
/**
 * WooCommerce integration checks. Run through WP-CLI after activating WooCommerce and Voucher Captain.
 */

function vcap_integration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_integration_private_call( object $object, string $method, array $args ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function vcap_integration_create_voucher( float $balance = 100.0, string $email = '' ): array {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-it-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $balance );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();

	$voucher               = new VCAP\Voucher();
	$voucher->code         = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id    = $coupon->get_id();
	// The production schema has a unique order_item_id/item_index slot; give fixtures unique slots.
	$voucher->order_item_id = 90000000 + $coupon->get_id();
	$voucher->type         = 'giftcard';
	$voucher->usage_mode   = 'balance';
	$voucher->discount_type = 'fixed_cart';
	$voucher->initial_amount = $balance;
	$voucher->balance      = $balance;
	$voucher->currency     = get_woocommerce_currency();
	$voucher->buyer_email  = $email;
	$voucher->send_to      = 'buyer';
	$voucher->token        = VCAP\VoucherRepository::new_token();

	$repo = vcap()->vouchers;
	$voucher->id = $repo->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return array( $voucher, $coupon );
}

function vcap_integration_create_order( array $coupon_discounts ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	foreach ( $coupon_discounts as $coupon => $discount ) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code( $coupon );
		$item->set_discount( $discount );
		$item->set_discount_tax( 0 );
		$order->add_item( $item );
	}
	$order->save();
	return $order;
}

global $wpdb;
$manager = vcap()->coupons;
$repo    = vcap()->vouchers;
$table   = VCAP\Install::tables()['vouchers'];
$events  = VCAP\Install::tables()['events'];

vcap_integration_assert( VCAP\Install::tables_support_transactions(), 'voucher and event tables use InnoDB transactions' );

// A interrupted multi-coupon checkout resumes its missing reservations.
list( $first, $first_coupon )   = vcap_integration_create_voucher();
list( $second, $second_coupon ) = vcap_integration_create_voucher();
$order = vcap_integration_create_order(
	array(
		$first_coupon->get_code()  => 20,
		$second_coupon->get_code() => 30,
	)
);
$prior = vcap_integration_private_call( $manager, 'reserve_balance_atomic', array( $first, 20.0, $order->get_id(), 'test partial reservation' ) );
vcap_integration_assert( $prior && 'reserved' === $prior['state'], 'first checkout reservation succeeds' );
$order->update_meta_data( '_vcap_reservations', array( $first->code => 20.0 ) );
$order->save();
$manager->reserve_order( $order );
$reservations = (array) $order->get_meta( '_vcap_reservations' );
vcap_integration_assert( isset( $reservations[ $first->code ], $reservations[ $second->code ] ), 'retry fills the missing voucher reservation' );
vcap_integration_assert( 80.0 === (float) $repo->get( $first->id )->balance && 70.0 === (float) $repo->get( $second->id )->balance, 'partial retry reserves each voucher exactly once' );

$manager->order_status_changed( $order->get_id(), 'pending', 'processing', $order );
vcap_integration_assert( 80.0 === (float) $repo->get( $first->id )->balance && 70.0 === (float) $repo->get( $second->id )->balance, 'payment commit does not deduct reservations a second time' );
vcap_integration_assert( isset( $order->get_meta( '_vcap_redemptions' )[ $first->code ] ), 'successful payment persists voucher redemption metadata' );

update_option( 'vcap_restore_on_cancel', 'yes' );
$manager->order_status_changed( $order->get_id(), 'processing', 'refunded', $order );
vcap_integration_assert( 100.0 === (float) $repo->get( $first->id )->balance && 100.0 === (float) $repo->get( $second->id )->balance, 'refund restores committed voucher balances' );
$restored_again = vcap_integration_private_call( $manager, 'restore_balance_atomic', array( $repo->get( $first->id ), 20.0, $order->get_id(), 'duplicate restore test' ) );
vcap_integration_assert( $restored_again && 100.0 === (float) $repo->get( $first->id )->balance, 'repeated refund recovery does not restore twice' );

// If order metadata was lost after the balance was reserved, the event ledger prevents another debit.
list( $third, $third_coupon ) = vcap_integration_create_voucher();
$recovery_order = vcap_integration_create_order( array( $third_coupon->get_code() => 25 ) );
$reserved = vcap_integration_private_call( $manager, 'reserve_balance_atomic', array( $third, 25.0, $recovery_order->get_id(), 'test lost metadata' ) );
vcap_integration_assert( $reserved && 'reserved' === $reserved['state'], 'recovery reservation is created' );
$recovery_order->delete_meta_data( '_vcap_reservations' );
$recovery_order->save();
$manager->deduct_order( $recovery_order );
vcap_integration_assert( 75.0 === (float) $repo->get( $third->id )->balance, 'fallback recognizes an existing reservation without double debit' );

// Payment retry re-reserves after a failed order released its previous hold.
list( $retry_voucher, $retry_coupon ) = vcap_integration_create_voucher();
$retry_order = vcap_integration_create_order( array( $retry_coupon->get_code() => 15 ) );
$manager->reserve_order( $retry_order );
$manager->order_status_changed( $retry_order->get_id(), 'pending', 'failed', $retry_order );
vcap_integration_assert( 100.0 === (float) $repo->get( $retry_voucher->id )->balance, 'failed payment releases the reservation' );
$manager->reserve_retry_order( $retry_order );
vcap_integration_assert( 85.0 === (float) $repo->get( $retry_voucher->id )->balance, 'payment retry reserves the balance before the gateway' );
$manager->order_status_changed( $retry_order->get_id(), 'pending', 'processing', $retry_order );
vcap_integration_assert( 85.0 === (float) $repo->get( $retry_voucher->id )->balance, 'retried payment commits without a second debit' );

// The Store API receives a client error when the balance changed after cart totals were calculated.
list( $short, $short_coupon ) = vcap_integration_create_voucher( 10.0 );
$short_order = vcap_integration_create_order( array( $short_coupon->get_code() => 15 ) );
$store_api_error = false;
try {
	$manager->reserve_store_api_order( $short_order );
} catch ( Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
	$store_api_error = 'vcap_voucher_reservation_failed' === $e->getErrorCode() && 409 === $e->getCode();
}
vcap_integration_assert( $store_api_error, 'Store API reservation conflicts return HTTP 409' );
vcap_integration_assert( 10.0 === (float) $repo->get( $short->id )->balance, 'failed Store API reservation leaves the balance unchanged' );

// A failed audit insert must roll back the corresponding balance update.
list( $audit_voucher ) = vcap_integration_create_voucher();
$fail_event_insert = static function ( $query ) use ( $events ) {
	if ( str_contains( $query, 'INSERT INTO ' . $events ) ) {
		return 'THIS IS NOT VALID SQL';
	}
	return $query;
};
$was_suppressed = $wpdb->suppress_errors( true );
add_filter( 'query', $fail_event_insert, 999 );
try {
	$audit_result = vcap_integration_private_call( $manager, 'reserve_balance_atomic', array( $audit_voucher, 20.0, 9000001, 'test audit failure' ) );
} finally {
	remove_filter( 'query', $fail_event_insert, 999 );
	$wpdb->suppress_errors( $was_suppressed );
}
vcap_integration_assert( null === $audit_result && 100.0 === (float) $repo->get( $audit_voucher->id )->balance, 'failed audit insert rolls back the voucher balance' );

// Delivery locks are connection-scoped, and a no-recipient attempt must remain eligible for retry.
list( $delivery_voucher ) = vcap_integration_create_voucher( 100.0, '' );
$lock_name   = 'vcap_delivery_' . $delivery_voucher->id;
$other_db    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other_lock  = $other_db->get_var( $other_db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
$before_rows = count( $repo->events( $delivery_voucher->id ) );
vcap()->delivery->deliver( $delivery_voucher->id );
$after_rows  = count( $repo->events( $delivery_voucher->id ) );
$other_db->get_var( $other_db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
$other_db->close();
vcap_integration_assert( 1 === (int) $other_lock && $before_rows === $after_rows, 'concurrent delivery worker respects a lock held by another database connection' );
vcap()->delivery->deliver( $delivery_voucher->id );
vcap_integration_assert( null === $repo->get( $delivery_voucher->id )->delivered_at, 'unsent email remains eligible for delivery retry' );

echo "WooCommerce integration checks passed.\n";
