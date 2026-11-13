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
// [vcap_balance] describes each kind of voucher by what it is worth.
$check = static function ( array $props ) use ( $tag ) {
	$v                 = new VCAP\Voucher();
	$v->code           = 'vcap-bal-' . strtolower( wp_generate_password( 8, false, false ) );
	$v->order_item_id  = 0;
	$v->type           = $props['type'] ?? 'giftcard';
	$v->usage_mode     = $props['usage_mode'] ?? 'balance';
	$v->discount_type  = $props['discount_type'] ?? 'fixed_cart';
	$v->initial_amount = $props['initial'] ?? 50;
	$v->balance        = $props['balance'] ?? 50;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	$_POST             = array( 'vcap_balance_code' => $v->code, 'vcap_balance_nonce' => wp_create_nonce( 'vcap_balance' ) );
	$html              = do_shortcode( '[vcap_balance]' );
	$_POST             = array();
	return wp_strip_all_tags( html_entity_decode( $html ) );
};
$card = $check( array( 'initial' => 80, 'balance' => 12.25 ) );
vcap_sc_assert( str_contains( $card, '12' ) && ! str_contains( $card, '80' ), 'a gift card shows its remaining balance' );
$percent = $check( array( 'usage_mode' => 'single', 'discount_type' => 'percent', 'initial' => 15, 'balance' => 15 ) );
vcap_sc_assert( str_contains( $percent, '15%' ) && ! str_contains( $percent, get_woocommerce_currency_symbol() ), 'a percentage voucher shows its discount, not an amount of money' );
$single = $check( array( 'usage_mode' => 'single', 'initial' => 30, 'balance' => 30 ) );
vcap_sc_assert( str_contains( $single, '30' ), 'a single-use voucher shows its value' );
$ticket = $check( array( 'type' => 'ticket', 'usage_mode' => 'single', 'initial' => 20, 'balance' => 20 ) );
vcap_sc_assert( ! str_contains( $ticket, '20' ), 'an admission ticket is not shown as an amount of money' );

echo "Voucher Captain shortcode checks passed.\n";
