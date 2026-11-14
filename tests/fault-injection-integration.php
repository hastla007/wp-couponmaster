<?php
/**
 * Fault injection: failed ledger INSERTs and voucher UPDATEs must roll back completely.
 *   wp eval-file tests/fault-injection-integration.php
 */

function vcap_fault_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

/** Make the next queries matching $pattern fail (null = no injection). */
$GLOBALS['vcap_fault'] = null;
add_filter(
	'query',
	static function ( $query ) {
		if ( $GLOBALS['vcap_fault'] && preg_match( $GLOBALS['vcap_fault'], (string) $query ) ) {
			return 'SELECT * FROM vcap_injected_failure_missing_table';
		}
		return $query;
	}
);

function vcap_fault( ?string $what, callable $fn ) {
	global $wpdb;
	$tables = VCAP\Install::tables();
	$map    = array(
		'ledger'  => '/^\s*INSERT INTO `?' . preg_quote( $tables['events'], '/' ) . '`?/i',
		'voucher' => '/^\s*UPDATE `?' . preg_quote( $tables['vouchers'], '/' ) . '`?/i',
	);
	$GLOBALS['vcap_fault'] = $what ? $map[ $what ] : null;
	$wpdb->suppress_errors( true );
	try {
		return $fn();
	} finally {
		$GLOBALS['vcap_fault'] = null;
		$wpdb->suppress_errors( false );
	}
}

function vcap_fault_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-fault-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 90000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

function vcap_fault_events( int $voucher_id, int $order_id ): array {
	return array_values( array_filter( vcap()->vouchers->events( $voucher_id ), static fn( $e ) => (int) $e['order_id'] === $order_id ) );
}

$repo    = vcap()->vouchers;
$coupons = vcap()->coupons;
$card    = vcap_fault_card( 50 );
$order   = wc_create_order();
$order->save();

$result = vcap_fault( 'ledger', static fn() => $coupons->reserve_voucher_balance( $card, 20, $order ) );
vcap_fault_assert( null === $result && 50.0 === $repo->get( $card->id )->balance && ! vcap_fault_events( $card->id, $order->get_id() ), 'a failed ledger INSERT rolls back the reservation (balance unchanged, no event)' );

$result = vcap_fault( 'voucher', static fn() => $coupons->reserve_voucher_balance( $card, 20, $order ) );
vcap_fault_assert( null === $result && 50.0 === $repo->get( $card->id )->balance && ! vcap_fault_events( $card->id, $order->get_id() ), 'a failed voucher UPDATE rolls back the reservation' );

$result = $coupons->reserve_voucher_balance( $card, 20, $order );
vcap_fault_assert( is_array( $result ) && 30.0 === $repo->get( $card->id )->balance, 'without failures the reservation succeeds' );

$result = vcap_fault( 'ledger', static fn() => $coupons->commit_voucher_reservation( $repo->get( $card->id ), $order ) );
$events = vcap_fault_events( $card->id, $order->get_id() );
vcap_fault_assert( null === $result && 'reserved' === end( $events )['event'] && 30.0 === $repo->get( $card->id )->balance, 'a failed commit leaves the reservation intact and retryable' );
$result = $coupons->commit_voucher_reservation( $repo->get( $card->id ), $order );
$events = vcap_fault_events( $card->id, $order->get_id() );
vcap_fault_assert( is_array( $result ) && 'committed' === end( $events )['event'] && 30.0 === $repo->get( $card->id )->balance, 'the retried commit succeeds without deducting twice' );

$second = wc_create_order();
$second->save();
$result = vcap_fault( 'ledger', static fn() => $coupons->deduct_balance_atomic( $repo->get( $card->id ), 10, 'fault test', $second->get_id() ) );
vcap_fault_assert( null === $result && 30.0 === $repo->get( $card->id )->balance, 'a failed ledger INSERT rolls back a direct deduction' );

// D2: the rollback logs the database error with the voucher/order and a reference id; a refusal for a business
// reason (balance too low) logs nothing.
$d2_logged = array();
$d2_log    = static function ( $message, $level, $context ) use ( &$d2_logged ) {
	if ( 'voucher-captain' === ( $context['source'] ?? '' ) ) {
		$d2_logged[] = array( (string) $message, (string) $level, (array) $context );
	}
	return $message;
};
add_filter( 'woocommerce_logger_log_message', $d2_log, 10, 3 );
$d2_reason = null;
$d2_result = vcap_fault( 'ledger', static fn() => $coupons->deduct_balance_atomic( $repo->get( $card->id ), 10, 'D2 test', $second->get_id(), true, $d2_reason ) );
$d2_entry  = $d2_logged ? end( $d2_logged ) : array( '', '', array() );
vcap_fault_assert( null === $d2_result && 'error' === $d2_entry[1] && str_contains( $d2_entry[0], 'deduct_balance_atomic' ) && str_contains( $d2_entry[0], 'vcap_injected_failure_missing_table' ) && VCAP\Db::last_reference() === ( $d2_entry[2]['reference'] ?? '' ) && $card->id === (int) ( $d2_entry[2]['voucher_id'] ?? 0 ) && $second->get_id() === (int) ( $d2_entry[2]['order_id'] ?? 0 ), 'D2: a rolled-back deduction logs the database error with voucher, order and the reference id' );
$d2_logged = array();
$d2_result = $coupons->deduct_balance_atomic( $repo->get( $card->id ), 1000, 'D2 too much', $second->get_id(), false, $d2_reason );
vcap_fault_assert( null === $d2_result && 'exceeds_balance' === $d2_reason && ! $d2_logged, 'D2: a rollback for a business reason (more than the balance) is not logged as database error' );
$d2_logged = array();
$d2_tx     = new ReflectionMethod( VCAP\Install::class, 'in_transaction' );
$d2_tx->setAccessible( true );
$GLOBALS['wpdb']->suppress_errors( true );
$d2_ok = $d2_tx->invoke( null, static fn() => false !== $GLOBALS['wpdb']->query( 'SELECT * FROM vcap_injected_failure_missing_table' ), 'migrate_usage_batch' );
$GLOBALS['wpdb']->suppress_errors( false );
vcap_fault_assert( false === $d2_ok && (bool) array_filter( $d2_logged, static fn( $e ) => str_contains( $e[0], 'in migrate_usage_batch' ) && ! empty( $e[2]['reference'] ) ), 'D2: a failed migration batch is logged with its context and a reference (not only -1)' );
remove_filter( 'woocommerce_logger_log_message', $d2_log, 10 );

$result = vcap_fault( 'ledger', static fn() => $coupons->release_voucher_reservation( $repo->get( $card->id ), $order ) );
vcap_fault_assert( 30.0 === $repo->get( $card->id )->balance, 'releasing a committed order never changes the balance, even with failures' );

// P2: a checkout whose rollback cannot release a hold keeps it in the order map (with a note) instead of
// forgetting it; the ledger-based sweep releases holds the map does not know.
global $wpdb;
$p2_first  = vcap_fault_card( 50 );
$p2_second = vcap_fault_card( 5 ); // Too little for its line: the second reservation fails.
$p2_order  = wc_create_order();
foreach ( array( $p2_first, $p2_second ) as $p2_card ) {
	$p2_line = new WC_Order_Item_Coupon();
	$p2_line->set_code( $p2_card->code );
	$p2_line->set_discount( 20 );
	$p2_order->add_item( $p2_line );
}
$p2_order->set_status( 'pending' );
$p2_order->save();
$p2_updates = 0;
$p2_table   = VCAP\Install::tables()['vouchers'];
$p2_fail    = static function ( $query ) use ( &$p2_updates, $p2_table ) {
	if ( preg_match( '/^\s*UPDATE `?' . preg_quote( $p2_table, '/' ) . '`?/i', (string) $query ) && ++$p2_updates > 1 ) {
		return 'SELECT * FROM vcap_injected_failure_missing_table'; // Every voucher UPDATE after the first reservation.
	}
	return $query;
};
add_filter( 'query', $p2_fail );
$wpdb->suppress_errors( true );
$p2_thrown = false;
try {
	$coupons->reserve_order( $p2_order->get_id() );
} catch ( Exception $e ) {
	$p2_thrown = true;
}
remove_filter( 'query', $p2_fail );
$wpdb->suppress_errors( false );
$p2_order = wc_get_order( $p2_order->get_id() );
$p2_map   = VCAP\CouponManager::order_map( $p2_order, '_vcap_reservations' );
$p2_notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $p2_order->get_id() ) ), 'content' ) );
vcap_fault_assert( $p2_thrown && 30.0 === $repo->get( $p2_first->id )->balance && 20.0 === (float) ( $p2_map[ $p2_first->code ] ?? 0 ) && false !== strpos( $p2_notes, 'could not be released' ), 'P2: a hold the rollback could not release stays in the order map, with an order note' );
$p2_order->update_status( 'cancelled' );
vcap_fault_assert( 50.0 === $repo->get( $p2_first->id )->balance, 'P2: cancelling that order then gives the hold back' );
// A crash between the reservation COMMIT and the order save: the hold is only in the ledger.
$p2_lost = wc_create_order();
$p2_lost->save();
$coupons->reserve_voucher_balance( $repo->get( $p2_first->id ), 15, $p2_lost );
$p2_lost->update_status( 'cancelled' );
vcap_fault_assert( 35.0 === $repo->get( $p2_first->id )->balance, 'P2: (a hold missing from the order map is not seen by the cancellation)' );
$coupons->release_stale_order_reservations();
$p2_events = vcap_fault_events( $p2_first->id, $p2_lost->get_id() );
vcap_fault_assert( 50.0 === $repo->get( $p2_first->id )->balance && 'reservation_released' === end( $p2_events )['event'], 'P2: the ledger-based sweep releases a hold whose order was cancelled without it in the map' );

update_option( 'vcap_store_credit', 'yes' );
$user = wp_insert_user( array( 'user_login' => 'vcap_fault_' . strtolower( wp_generate_password( 6, false ) ), 'user_pass' => wp_generate_password(), 'user_email' => 'fault-' . strtolower( wp_generate_password( 6, false ) ) . '@example.com' ) );
vcap()->store_credit->adjust( $user, 40, 'Initial credit' );
$result = vcap_fault( 'ledger', static fn() => vcap()->store_credit->adjust( $user, 15, 'Should fail' ) );
vcap_fault_assert( is_wp_error( $result ) && 40.0 === vcap()->store_credit->balance( $user ), 'a failed ledger INSERT rolls back a store credit change' );
$result = vcap_fault( 'voucher', static fn() => vcap()->store_credit->adjust( $user, -15, 'Should fail' ) );
vcap_fault_assert( is_wp_error( $result ) && 40.0 === vcap()->store_credit->balance( $user ), 'a failed voucher UPDATE rolls back a store credit change' );
delete_option( 'vcap_store_credit' );

$single = vcap_fault_card( 25 );
$result = vcap_fault( 'ledger', static fn() => vcap()->redemptions->reserve( $single->code, array( 'key' => 'fault-' . wp_generate_uuid4(), 'target' => 'test:fault', 'amount' => 10 ) ) );
vcap_fault_assert( is_wp_error( $result ) && 25.0 === $repo->get( $single->id )->balance && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $single->id )->status, 'a failed ledger INSERT rolls back an external reservation' );

echo "Voucher Captain fault injection checks passed.\n";
