<?php
/**
 * Compatibility checks for the declared WooCommerce / storage integrations.
 * Executed by WP-CLI in a matrix of HPOS and legacy order storage.
 */

function vcap_compat_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_compat_private_call( object $object, string $method, array $args ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function vcap_compat_voucher( float $balance = 100.0, string $buyer_email = '' ): array {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-compat-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $balance );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();

	$voucher                 = new VCAP\Voucher();
	$voucher->code           = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 91000000 + $coupon->get_id();
	$voucher->type           = 'giftcard';
	$voucher->usage_mode     = 'balance';
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $balance;
	$voucher->balance        = $balance;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->buyer_email    = $buyer_email;
	$voucher->send_to        = 'buyer';
	$voucher->token          = VCAP\VoucherRepository::new_token();
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not create compatibility voucher.' );
	}
	return array( $voucher, $coupon );
}

function vcap_compat_order( string $coupon_code = '', float $discount = 0 ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	if ( $coupon_code ) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code( $coupon_code );
		$item->set_discount( $discount );
		$item->set_discount_tax( 0 );
		$order->add_item( $item );
	}
	$order->save();
	return $order;
}

global $wpdb;
$expected_hpos = 'yes' === getenv( 'VCAP_EXPECT_HPOS' );
$hpos_enabled  = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
vcap_compat_assert( $expected_hpos === $hpos_enabled, 'configured HPOS / legacy order storage is active' );

$order = vcap_compat_order();
$order->update_meta_data( '_vcap_compat_probe', 'persisted' );
$order->save();
$reloaded = wc_get_order( $order->get_id() );
vcap_compat_assert( $reloaded instanceof WC_Order && 'persisted' === $reloaded->get_meta( '_vcap_compat_probe' ), 'WooCommerce order CRUD and metadata round-trip in the selected storage mode' );

// WooCommerce 11.2.2 and Yoast SEO are activated together in this CI job.
vcap_compat_assert( class_exists( 'WPSEO_Options' ), 'Yoast SEO loads alongside Voucher Captain and WooCommerce' );
vcap_compat_assert( false !== has_action( 'woocommerce_before_add_to_cart_button' ), 'voucher product-field renderer is registered for WooCommerce product templates' );
vcap_compat_assert( false !== has_action( 'woocommerce_store_api_checkout_order_processed' ), 'Store API checkout reservation handler is registered for Checkout blocks' );

// Existing voucher fields flow through WooCommerce cart/order hooks used by classic forms and block product templates.
list( $field_voucher, $field_coupon ) = vcap_compat_voucher();
$product = new WC_Product_Simple();
$product->set_name( 'Voucher Captain compatibility product' );
$product->set_regular_price( '25' );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->save();
$fields = new VCAP\Fields();
$defs   = $fields->definitions_for( $product );
vcap_compat_assert( isset( $defs['before_button']['recipient_email'] ), 'voucher personalisation definitions are available at the product add-to-cart anchor' );
$_POST['vcap_fields'] = array( 'recipient_email' => 'recipient@example.test', 'message' => 'Block and classic product form' );
$cart_data = $fields->cart_item_data( array(), $product->get_id(), 0 );
vcap_compat_assert( 'recipient@example.test' === ( $cart_data['vcap_fields']['recipient_email']['value'] ?? '' ), 'personalisation fields persist into WooCommerce cart item data' );
$item = new WC_Order_Item_Product();
$fields->save_order_item( $item, 'vcap-compat', $cart_data + array( 'product_id' => $product->get_id() ), $order );
vcap_compat_assert( 'recipient@example.test' === $item->get_meta( '_vcap_fields' )['recipient_email']['value'], 'personalisation fields persist onto an order item' );
unset( $_POST['vcap_fields'] );

// Store API checkout flow accepts an available reservation and keeps the voucher balance consistent.
list( $block_voucher, $block_coupon ) = vcap_compat_voucher( 40 );
$block_order = vcap_compat_order( $block_coupon->get_code(), 10 );
vcap()->coupons->reserve_store_api_order( $block_order );
vcap_compat_assert( 30.0 === (float) vcap()->vouchers->get( $block_voucher->id )->balance, 'Store API checkout reserves an eligible voucher balance' );
vcap()->coupons->order_status_changed( $block_order->get_id(), 'pending', 'processing', $block_order );
vcap_compat_assert( 30.0 === (float) vcap()->vouchers->get( $block_voucher->id )->balance, 'paid Checkout Block order commits the stored reservation without deducting twice' );

// Provider compatibility contracts: verify conversion/filter hooks and renewal metadata stripping without requiring paid extensions.
$currency = new VCAP\Compat\MultiCurrency();
$base     = get_option( 'woocommerce_currency' );
vcap_compat_assert( 12.5 === $currency->convert( 12.5, $base ), 'base-currency voucher values are unchanged' );
$converted = apply_filters( 'vcap_amount_to_base_currency', 12.5, 'TEST', null );
vcap_compat_assert( 12.5 === (float) $converted, 'unknown currency providers retain the documented extension filter fallback' );
$subscriptions = new VCAP\Compat\Subscriptions();
$renewal_data  = $subscriptions->strip_meta( array( '_vcap_secret' => 'must-not-copy', 'customer_note' => 'keep' ) );
vcap_compat_assert( ! isset( $renewal_data['_vcap_secret'] ) && 'keep' === $renewal_data['customer_note'], 'subscription renewal metadata strips voucher-only state' );
$renewal_rows = $subscriptions->strip_meta_rows(
	array(
		array( 'meta_key' => '_vcap_reservations', 'meta_value' => '[]' ),
		array( 'meta_key' => 'customer_note', 'meta_value' => 'keep' ),
	)
);
vcap_compat_assert( 1 === count( $renewal_rows ) && 'customer_note' === $renewal_rows[0]['meta_key'], 'subscription renewal metadata rows exclude voucher state' );

// Refund and a new redemption race on separate MySQL connections. Both operations must succeed and
// the final balance must equal the serial outcome (50), irrespective of which row lock is acquired first.
list( $race_voucher, $race_coupon ) = vcap_compat_voucher();
$old_order = vcap_compat_order( $race_coupon->get_code(), 50 );
vcap()->coupons->reserve_order( $old_order );
vcap()->coupons->order_status_changed( $old_order->get_id(), 'pending', 'processing', $old_order );
$new_order = vcap_compat_order( $race_coupon->get_code(), 50 );
$pair      = function_exists( 'pcntl_fork' ) ? stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP ) : false;
if ( false === $pair ) {
	throw new RuntimeException( 'pcntl and Unix socket pairs are required for the refund/redemption concurrency check.' );
}
$pid = pcntl_fork();
if ( -1 === $pid ) {
	throw new RuntimeException( 'Could not fork the refund/redemption concurrency worker.' );
}
if ( 0 === $pid ) {
	fclose( $pair[0] );
	fwrite( $pair[1], 'R' );
	fread( $pair[1], 1 );
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$result = vcap_compat_private_call( vcap()->coupons, 'reserve_balance_atomic', array( $race_voucher, 50.0, $new_order->get_id(), 'parallel redemption test' ) );
	fwrite( $pair[1], $result ? '1' : '0' );
	fclose( $pair[1] );
	exit( 0 );
}
fclose( $pair[1] );
$ready = fread( $pair[0], 1 );
fwrite( $pair[0], 'S' );
$restored = vcap_compat_private_call( vcap()->coupons, 'restore_balance_atomic', array( $race_voucher, 50.0, $old_order->get_id(), 'parallel refund test' ) );
$child_result = fread( $pair[0], 1 );
pcntl_waitpid( $pid, $child_status );
fclose( $pair[0] );
vcap_compat_assert( 'R' === $ready && '1' === $child_result && $restored, 'parallel refund and redemption both finish under row locking' );
vcap_compat_assert( 50.0 === (float) vcap()->vouchers->get( $race_voucher->id )->balance, 'parallel refund and redemption preserve the serial final balance' );

// Closing the worker connection simulates a worker process dying while it owns a delivery advisory lock.
$crash_lock = 'vcap_delivery_crash_test_' . wp_generate_password( 8, false, false );
$crash_db   = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$crash_db->get_var( $crash_db->prepare( 'SELECT GET_LOCK(%s, 0)', $crash_lock ) );
$crash_db->close();
$released = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $crash_lock ) );
if ( '1' === (string) $released ) {
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $crash_lock ) );
}
vcap_compat_assert( '1' === (string) $released, 'delivery lock is automatically released when the worker connection exits' );

// Failed mail delivery stays retryable, then succeeds on a later Action Scheduler attempt.
list( $mail_voucher ) = vcap_compat_voucher( 100, 'buyer@example.test' );
$fail_mail = static function ( $pre, $atts ) { return false; };
add_filter( 'pre_wp_mail', $fail_mail, 999, 2 );
try {
	vcap()->delivery->deliver( $mail_voucher->id );
} finally {
	remove_filter( 'pre_wp_mail', $fail_mail, 999 );
}
vcap_compat_assert( null === vcap()->vouchers->get( $mail_voucher->id )->delivered_at, 'mailer failure does not mark a voucher as delivered' );
$success_mail = static function ( $pre, $atts ) { return true; };
add_filter( 'pre_wp_mail', $success_mail, 999, 2 );
try {
	vcap()->delivery->deliver( $mail_voucher->id );
} finally {
	remove_filter( 'pre_wp_mail', $success_mail, 999 );
}
vcap_compat_assert( null !== vcap()->vouchers->get( $mail_voucher->id )->delivered_at, 'failed delivery is retried and marked only after successful mail' );

// Simulate a large legacy MyISAM installation and verify upgrade preserves rows while restoring InnoDB.
list( $legacy_voucher ) = vcap_compat_voucher();
for ( $i = 0; $i < 1000; $i++ ) {
	vcap()->vouchers->log( $legacy_voucher->id, 'compat_test', null, null, 'legacy row ' . $i );
}
$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['events'] . ' WHERE voucher_id = %d', $legacy_voucher->id ) );
$tables = VCAP\Install::tables();
$wpdb->query( "ALTER TABLE {$tables['vouchers']} ENGINE=MyISAM" );
$wpdb->query( "ALTER TABLE {$tables['events']} ENGINE=MyISAM" );
VCAP\Install::create_tables();
$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tables['events'] . ' WHERE voucher_id = %d', $legacy_voucher->id ) );
vcap_compat_assert( $before === $after && VCAP\Install::tables_support_transactions(), 'MyISAM-to-InnoDB upgrade preserves a 1000-row voucher event history' );

echo "Voucher Captain compatibility checks passed.\n";
