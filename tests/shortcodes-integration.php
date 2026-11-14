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
wp_set_current_user( 0 );
$guest_form = $render( 'vcap/redeem' );
vcap_sc_assert( str_contains( $guest_form, 'vcap-redeem-form' ) && ! str_contains( $guest_form, 'vcap_redeem_nonce' ) && str_contains( $guest_form, '<div class="woocommerce"' ), 'the redeem block renders the guest form without nonce (cacheable), inside div.woocommerce' );
$no_cache = did_action( 'vcap_no_cache' );
do_shortcode( '[vcap_redeem]' );
vcap_sc_assert( did_action( 'vcap_no_cache' ) === $no_cache, 'an empty guest redeem form (e.g. in a header template part) keeps the page cacheable' );
$member_tag = strtolower( wp_generate_password( 6, false ) );
wp_set_current_user( wp_create_user( 'sc-member-' . $member_tag, wp_generate_password(), 'sc-member-' . $member_tag . '@example.com' ) );
vcap_sc_assert( str_contains( $render( 'vcap/redeem' ), 'vcap_redeem_nonce' ), 'logged-in customers get a protected redeem form' );
vcap_sc_assert( str_contains( render_block( array( 'blockName' => 'vcap/redeem', 'attrs' => array( 'buttonText' => 'Jetzt einlösen' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ), 'Jetzt einlösen' ), 'the redeem block passes its button text' );
wp_set_current_user( 0 );

// Block output passes do_shortcode() after the blocks: a code from a redeem link must not run a shortcode.
$GLOBALS['vcap_sc_ran'] = 0;
add_shortcode( 'vcap_sc_probe', static function () {
	++$GLOBALS['vcap_sc_ran'];
	return 'PROBE-RAN';
} );
wc_load_cart();
WC()->session->set( VCAP\Shortcodes::PREFILL_KEY, '[vcap_sc_probe]' );
$_GET['vcap_code'] = '[vcap_sc_probe]';
$content           = apply_filters( 'the_content', '<!-- wp:vcap/redeem /-->' );
unset( $_GET['vcap_code'] );
WC()->session->set( VCAP\Shortcodes::PREFILL_KEY, null );
vcap_sc_assert( 0 === $GLOBALS['vcap_sc_ran'] && ! str_contains( $content, 'PROBE-RAN' ), 'a redeem link cannot run a shortcode through the block output' );

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

// Redeem links: the code moves from the URL into the session (redirect to the clean URL) and fills in the form.
$_GET['vcap_code']      = 'LINK-' . strtoupper( $tag );
$_SERVER['REQUEST_URI'] = '/einloesen/?vcap_code=LINK-' . strtoupper( $tag ) . '&utm_source=mail';
$stop_redirect          = static function ( $location ) {
	throw new RuntimeException( 'redirect:' . $location );
};
add_filter( 'wp_redirect', $stop_redirect );
$redirected = '';
try {
	$sc->redeem_link();
} catch ( RuntimeException $e ) {
	$redirected = substr( $e->getMessage(), strlen( 'redirect:' ) );
}
remove_filter( 'wp_redirect', $stop_redirect );
unset( $_GET['vcap_code'] );
vcap_sc_assert( '' !== $redirected && ! str_contains( $redirected, 'vcap_code' ) && str_contains( $redirected, 'utm_source=mail' ), 'a redeem link redirects to the URL without the code' );
vcap_sc_assert( 'LINK-' . strtoupper( $tag ) === WC()->session->get( VCAP\Shortcodes::PREFILL_KEY ) && str_contains( do_shortcode( '[vcap_redeem]' ), 'value="LINK-' . strtoupper( $tag ) . '"' ), 'the code from the link fills in the redeem form from the session' );
WC()->session->set( VCAP\Shortcodes::PREFILL_KEY, null );
wc_clear_notices();

WC()->cart->empty_cart();
wc_clear_notices();
// [vcap_balance] describes each kind of voucher by what it is worth.
$check = static function ( array $props ) use ( $tag ) {
	$v                 = new VCAP\Voucher();
	$v->code           = 'vcap-bal-' . strtolower( wp_generate_password( 8, false, false ) );
	$v->order_item_id  = 70000000 + random_int( 1, 9999999 );
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

// A balance check is evaluated once per request, however often the form is rendered (a form without id, as
// in markup of an earlier version, gets the result in the first form).
$_POST      = array( 'vcap_balance_code' => 'nope-' . $tag );
$sc_balance = new VCAP\Downloads( vcap()->settings, vcap()->vouchers, vcap()->pdf );
$sc_balance->handle_balance();
$sc_balance->handle_balance();
$twice = $sc_balance->balance_shortcode() . $sc_balance->balance_shortcode();
$_POST = array();
vcap_sc_assert( 1 === substr_count( $twice, 'Voucher code not found.' ) && 1 === substr_count( $twice, 'aria-invalid="true"' ) && str_contains( $twice, 'data-vcap-focus' ), 'two balance forms on a page: one check, the result (focused) only in the sent form' );

echo "Voucher Captain shortcode checks passed.\n";
