<?php
/**
 * Shortcodes and blocks: balance, redeem page, my vouchers, referral.
 *   wp eval-file tests/shortcodes-integration.php
 */

function vcap_sc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

foreach ( array( 'vcap_balance', 'vcap_voucher_status', 'vcap_redeem', 'vcap_my_vouchers', 'vcap_referral' ) as $tag ) {
	vcap_sc_assert( shortcode_exists( $tag ), "shortcode [{$tag}] is registered" );
}
$registry = WP_Block_Type_Registry::get_instance();
foreach ( array_keys( VCAP\Shortcodes::BLOCKS ) as $block ) {
	vcap_sc_assert( $registry->is_registered( $block ), "block {$block} is registered" );
}
$render = static fn( string $name ) => render_block( array( 'blockName' => $name, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
vcap_sc_assert( false !== strpos( $render( 'vcap/balance' ), 'vcap-balance-form' ), 'the balance block renders the balance form' );
vcap_sc_assert( false !== strpos( $render( 'vcap/redeem' ), 'vcap_redeem_nonce' ), 'the redeem block renders a protected form' );

wp_set_current_user( 0 );
vcap_sc_assert( false !== strpos( do_shortcode( '[vcap_my_vouchers]' ), 'vcap-login-hint' ), 'guests are asked to log in for their vouchers' );
vcap_sc_assert( false !== strpos( do_shortcode( '[vcap_referral]' ), 'vcap-login-hint' ), 'guests are asked to log in for their referral code' );

$tag  = strtolower( wp_generate_password( 6, false ) );
$user = wp_create_user( 'sc-' . $tag, wp_generate_password(), 'sc-' . $tag . '@example.com' );
wp_set_current_user( $user );
vcap_sc_assert( false === strpos( do_shortcode( '[vcap_my_vouchers]' ), 'vcap-login-hint' ), 'logged-in customers see their voucher list' );
update_option( 'vcap_referral_enabled', 'yes' );
vcap_sc_assert( false !== strpos( do_shortcode( '[vcap_referral]' ), 'vcap-referral-code' ), 'the referral shortcode shows the personal code' );
update_option( 'vcap_referral_enabled', 'no' );
wp_set_current_user( 0 );

wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();
$coupon = new WC_Coupon();
$coupon->set_code( 'redeem-' . $tag );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( '10' );
$coupon->save();
$sc = new VCAP\Shortcodes( vcap()->downloads, new VCAP\Referrals( vcap()->settings, vcap()->store_credit ) );

vcap_sc_assert( 'invalid' === $sc->redeem( 'nope-' . $tag ), 'unknown codes are rejected' );
vcap_sc_assert( 'saved' === $sc->redeem( 'REDEEM-' . strtoupper( $tag ) ) && 'redeem-' . $tag === strtolower( (string) WC()->session->get( VCAP\CouponLink::SESSION_KEY ) ), 'with an empty cart the code is kept for later' );
WC()->session->set( VCAP\CouponLink::SESSION_KEY, null );

$product = new WC_Product_Simple();
$product->set_name( 'Redeem product' );
$product->set_regular_price( '20' );
$product->set_virtual( true );
$product->save();
WC()->cart->add_to_cart( $product->get_id(), 1 );
vcap_sc_assert( 'applied' === $sc->redeem( 'redeem-' . $tag ) && WC()->cart->has_discount( 'redeem-' . $tag ), 'with products in the cart the code is applied' );

WC()->cart->empty_cart();
wc_clear_notices();
echo "Voucher Captain shortcode checks passed.\n";
