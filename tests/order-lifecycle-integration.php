<?php
/**
 * Order lifecycle regression checks for stored-balance vouchers and scheduled delivery.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/order-lifecycle-integration.php
 */

function vcap_life_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_life_voucher( float $balance = 50.0, array $props = array() ): array {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-life-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $balance );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();

	$voucher                 = new VCAP\Voucher();
	$voucher->code           = $props['code'] ?? wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 70000000 + $coupon->get_id();
	$voucher->type           = 'giftcard';
	$voucher->usage_mode     = 'balance';
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $balance;
	$voucher->balance        = $balance;
	$voucher->currency       = get_option( 'woocommerce_currency' );
	$voucher->status         = $props['status'] ?? VCAP\Voucher::STATUS_ACTIVE;
	$voucher->delivery_at    = $props['delivery_at'] ?? null;
	$voucher->buyer_email    = $props['buyer_email'] ?? '';
	$voucher->send_to        = 'buyer';
	$voucher->token          = VCAP\VoucherRepository::new_token();
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return array( $voucher, $coupon );
}

function vcap_life_order( array $coupon_discounts, string $currency = '' ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	if ( $currency ) {
		$order->set_currency( $currency );
	}
	foreach ( $coupon_discounts as $code => $discount ) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code( $code );
		$item->set_discount( $discount );
		$item->set_discount_tax( 0 );
		$order->add_item( $item );
	}
	$order->save();
	return $order;
}

$manager = vcap()->coupons;
$repo    = vcap()->vouchers;

// A paid order whose voucher was spent elsewhere meanwhile must not slip through with a free discount.
list( $shared, $shared_coupon ) = vcap_life_voucher( 50.0 );
$late  = vcap_life_order( array( $shared_coupon->get_code() => 50 ) );
$other = vcap_life_order( array( $shared_coupon->get_code() => 50 ) );
$manager->order_status_changed( $other->get_id(), 'pending', 'processing', $other );
vcap_life_assert( 0.0 === (float) $repo->get( $shared->id )->balance, 'the first paid order uses the whole balance' );
$manager->order_status_changed( $late->get_id(), 'failed', 'processing', $late );
$late = wc_get_order( $late->get_id() );
vcap_life_assert( 'on-hold' === $late->get_status(), 'a paid order whose voucher balance is gone is put on hold for review' );

// Coupon removed from an order after checkout: the hold is released instead of committed.
list( $edited, $edited_coupon ) = vcap_life_voucher( 60.0 );
$edited_order = vcap_life_order( array( $edited_coupon->get_code() => 20 ) );
$manager->reserve_order( $edited_order );
vcap_life_assert( 40.0 === (float) $repo->get( $edited->id )->balance, 'checkout reserves the coupon amount' );
foreach ( $edited_order->get_items( 'coupon' ) as $item_id => $item ) {
	$edited_order->remove_item( $item_id );
}
$edited_order->save();
$manager->order_status_changed( $edited_order->get_id(), 'on-hold', 'processing', $edited_order );
$edited_order = wc_get_order( $edited_order->get_id() );
vcap_life_assert( 60.0 === (float) $repo->get( $edited->id )->balance, 'a coupon removed before payment is not charged' );
vcap_life_assert( ! $edited_order->get_meta( '_vcap_reservations' ) && ! $edited_order->get_meta( '_vcap_redemptions' ), 'no reservation or redemption remains on the edited order' );

// Trashed unpaid order releases its hold.
list( $trashed, $trashed_coupon ) = vcap_life_voucher( 30.0 );
$trash_order = vcap_life_order( array( $trashed_coupon->get_code() => 30 ) );
$manager->reserve_order( $trash_order );
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $trashed->id )->status, 'reserving the full balance blocks the voucher' );
$trash_order->delete( false );
vcap_life_assert( 30.0 === (float) $repo->get( $trashed->id )->balance && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $trashed->id )->status, 'trashing an unpaid order releases its voucher hold' );

// Abandoned unpaid orders are released by the hourly sweep.
list( $abandoned, $abandoned_coupon ) = vcap_life_voucher( 25.0 );
$abandoned_order = vcap_life_order( array( $abandoned_coupon->get_code() => 10 ) );
$manager->reserve_order( $abandoned_order );
// Age the order directly in the database (saving through CRUD would reset the modification date).
global $wpdb;
$aged = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
if ( class_exists( Automattic\WooCommerce\Utilities\OrderUtil::class ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $aged ), array( 'id' => $abandoned_order->get_id() ) );
} else {
	$wpdb->update( $wpdb->posts, array( 'post_modified' => get_date_from_gmt( $aged ), 'post_modified_gmt' => $aged ), array( 'ID' => $abandoned_order->get_id() ) );
	clean_post_cache( $abandoned_order->get_id() );
}
wp_cache_flush();
$manager->release_stale_order_reservations();
$abandoned_order = wc_get_order( $abandoned_order->get_id() );
vcap_life_assert( 25.0 === (float) $repo->get( $abandoned->id )->balance && ! $abandoned_order->get_meta( '_vcap_reservations' ), 'the stale-reservation sweep releases abandoned pending orders' );
$manager->order_status_changed( $abandoned_order->get_id(), 'pending', 'processing', $abandoned_order );
vcap_life_assert( 15.0 === (float) $repo->get( $abandoned->id )->balance, 'a late payment after the sweep is still deducted' );

// Order currency differs from the shop currency: the balance is charged in the base currency.
list( $fx, $fx_coupon ) = vcap_life_voucher( 50.0 );
$foreign = 'EUR' === get_option( 'woocommerce_currency' ) ? 'USD' : 'EUR';
$half    = static function ( $amount, $currency ) use ( $foreign ) {
	return $currency === $foreign ? $amount / 2 : $amount;
};
add_filter( 'vcap_amount_to_base_currency', $half, 10, 2 );
$fx_order = vcap_life_order( array( $fx_coupon->get_code() => 40 ), $foreign );
$manager->reserve_order( $fx_order );
remove_filter( 'vcap_amount_to_base_currency', $half, 10 );
vcap_life_assert( 30.0 === (float) $repo->get( $fx->id )->balance, 'foreign-currency discounts are converted before reserving the balance' );

// The order-scoped API stores meta keys like checkout does (lower-case codes), so payment commits them.
list( $upper, $upper_coupon ) = vcap_life_voucher( 40.0, array( 'code' => strtoupper( 'vcap-life-up-' . wp_generate_password( 8, false, false ) ) ) );
$api_order = vcap_life_order( array( $upper_coupon->get_code() => 10 ) );
vcap()->api_v1->reserve( $upper->code, 10.0, $api_order );
$api_order    = wc_get_order( $api_order->get_id() );
$reservations = (array) $api_order->get_meta( '_vcap_reservations' );
vcap_life_assert( isset( $reservations[ wc_format_coupon_code( $upper->code ) ] ), 'API reservations use the normalised coupon code as meta key' );

// A scheduled gift becomes redeemable on its date even if no email can be sent.
list( $scheduled, $scheduled_coupon ) = vcap_life_voucher( 20.0, array( 'status' => VCAP\Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
vcap()->coupons->set_coupon_enabled( $scheduled, false );
$no_mail = static fn() => false;
add_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $no_mail );
add_filter( 'woocommerce_email_enabled_vcap_voucher_recipient', $no_mail );
vcap()->delivery->deliver( $scheduled->id );
remove_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $no_mail );
remove_filter( 'woocommerce_email_enabled_vcap_voucher_recipient', $no_mail );
vcap_life_assert( VCAP\Voucher::STATUS_ACTIVE === $repo->get( $scheduled->id )->status && 'publish' === get_post_status( $scheduled_coupon->get_id() ), 'a due scheduled voucher is activated even when no email is sent' );

// Gift-card coupons are always cart-wide, never per item.
$card             = new VCAP\Voucher();
$card->code       = 'vcap-life-fp-' . strtolower( wp_generate_password( 8, false, false ) );
$card->usage_mode = 'balance';
$card->discount_type = 'fixed_product';
$card->initial_amount = 10;
$card->balance    = 10;
$coupon_id        = vcap()->coupons->create_coupon( $card, VCAP\ProductConfig::DEFAULTS );
vcap_life_assert( 'fixed_cart' === ( new WC_Coupon( $coupon_id ) )->get_discount_type(), 'stored-balance coupons are created as fixed_cart' );

echo "Voucher Captain order lifecycle checks passed.\n";
