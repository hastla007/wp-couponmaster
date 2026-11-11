<?php
/**
 * Coupon link (?gutschein=CODE) checks. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/coupon-link-integration.php
 */

function vcap_link_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();

$link = new VCAP\CouponLink( vcap()->settings );
update_option( 'vcap_coupon_link', 'no' );
vcap_link_assert( ! $link->enabled(), 'voucher links are off by default' );
update_option( 'vcap_coupon_link', 'yes' );
update_option( 'vcap_coupon_link_param', 'gutschein' );
vcap_link_assert( $link->enabled() && 'gutschein' === $link->param(), 'voucher links can be enabled with the default parameter' );

$coupon = new WC_Coupon();
$coupon->set_code( 'vcap-link-' . strtolower( wp_generate_password( 8, false, false ) ) );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->save();

$product = new WC_Product_Simple();
$product->set_name( 'Link test product' );
$product->set_regular_price( '20' );
$product->set_status( 'publish' );
$product->save();

vcap_link_assert( ! $link->capture( 'does-not-exist-' . wp_generate_password( 6, false, false ) ), 'unknown codes are rejected' );
vcap_link_assert( $link->capture( strtoupper( $coupon->get_code() ) ), 'a valid code from the link is remembered (case-insensitive)' );

$link->apply_pending();
vcap_link_assert( ! WC()->cart->has_discount( $coupon->get_code() ), 'nothing is applied while the cart is empty' );

WC()->cart->add_to_cart( $product->get_id(), 1 );
$link->apply_pending();
vcap_link_assert( WC()->cart->has_discount( $coupon->get_code() ), 'the code is applied once the cart has products' );

WC()->cart->remove_coupon( $coupon->get_code() );
$link->apply_pending();
vcap_link_assert( ! WC()->cart->has_discount( $coupon->get_code() ), 'a coupon the customer removed is not applied again' );

update_option( 'vcap_coupon_link', 'no' );
WC()->cart->empty_cart();
wc_clear_notices();

echo "Voucher Captain coupon link checks passed.\n";
