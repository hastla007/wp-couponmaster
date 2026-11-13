<?php
/**
 * Rate limits of code lookups: atomic attempt counting, site-wide brake that never blocks valid codes, IPv6 /64,
 * WooCommerce's own coupon entry (Store API) and the redemption API.
 *   wp eval-file tests/rate-limit-integration.php
 */

global $wpdb;

function vcap_rl_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_rl_reset(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_rl_' ) . '%' ) );
	wp_cache_flush();
}

/** Acts as a visitor from $ip until the next call. */
function vcap_rl_visitor( string $ip ): void {
	remove_all_filters( 'vcap_client_ip' );
	add_filter( 'vcap_client_ip', static fn() => $ip );
}

vcap_rl_reset();
wp_set_current_user( 0 );

// --- Visitor key: IPv6 by its /64 network, IPv4-mapped addresses as IPv4.
vcap_rl_assert( VCAP\Plugin::client_network( '2001:db8:1:2:aaaa::1' ) === VCAP\Plugin::client_network( '2001:db8:1:2:ffff:1:2:3' ), 'addresses of one IPv6 /64 share a counter' );
vcap_rl_assert( VCAP\Plugin::client_network( '2001:db8:1:2::1' ) !== VCAP\Plugin::client_network( '2001:db8:1:3::1' ), 'other /64 networks count separately' );
vcap_rl_assert( '198.51.100.7' === VCAP\Plugin::client_network( '::ffff:198.51.100.7' ) && '198.51.100.7' === VCAP\Plugin::client_network( '198.51.100.7' ), 'IPv4 (also IPv4-mapped) addresses count per address' );
vcap_rl_visitor( '2001:db8:aa:1::1' );
$v6_bucket = 'rl-v6-' . wp_generate_password( 6, false, false );
for ( $i = 0; $i < 3; $i++ ) {
	VCAP\Plugin::rate_limited( $v6_bucket, 3, 300, HOUR_IN_SECONDS, false );
	VCAP\Plugin::rate_limit_failure( $v6_bucket, HOUR_IN_SECONDS );
}
vcap_rl_visitor( '2001:db8:aa:1:dead:beef:0:7' );
vcap_rl_assert( VCAP\Plugin::rate_limited( $v6_bucket, 3, 300, HOUR_IN_SECONDS, false ), 'rotating addresses inside the /64 does not reset the counter' );

// --- Check and count in one step: an attempt counts before the lookup, existing codes give it back.
vcap_rl_visitor( '203.0.113.20' );
$bucket = 'rl-atomic-' . wp_generate_password( 6, false, false );
vcap_rl_assert( ! VCAP\Plugin::rate_limited( $bucket, 2, 300, HOUR_IN_SECONDS, false ) && ! VCAP\Plugin::rate_limited( $bucket, 2, 300, HOUR_IN_SECONDS, false ) && VCAP\Plugin::rate_limited( $bucket, 2, 300, HOUR_IN_SECONDS, false ), 'parallel lookups without an outcome yet are counted (no check-then-count race)' );
$bucket = 'rl-valid-' . wp_generate_password( 6, false, false );
$never  = true;
for ( $i = 0; $i < 30; $i++ ) {
	$never = $never && ! VCAP\Plugin::rate_limited( $bucket, 2, 300, HOUR_IN_SECONDS, false );
	VCAP\Plugin::rate_limit_success( $bucket, HOUR_IN_SECONDS );
}
vcap_rl_assert( $never, 'lookups of existing codes never use up the quota' );

// --- Site-wide counter full: valid codes still work, guessing is braked per visitor.
wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();
$tag    = strtolower( wp_generate_password( 6, false, false ) );
$coupon = new WC_Coupon();
$coupon->set_code( 'rl-valid-' . $tag );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( '10' );
$coupon->save();
$sc = new VCAP\Shortcodes( vcap()->downloads, new VCAP\Referrals( vcap()->settings, vcap()->store_credit ) );
for ( $i = 0; $i < 300; $i++ ) {
	VCAP\Plugin::rate_limit_failure( 'redeem', 15 * MINUTE_IN_SECONDS );
}
vcap_rl_visitor( '198.51.100.30' );
$works = true;
for ( $i = 0; $i < 20; $i++ ) {
	$works = $works && in_array( $sc->redeem( 'rl-valid-' . $tag ), array( 'saved', 'applied' ), true );
}
vcap_rl_assert( $works, 'site-wide counter full: a valid code still works, again and again' );
$results = array();
for ( $i = 0; $i < 4; $i++ ) {
	$results[] = $sc->redeem( 'rl-unknown-' . $i . '-' . $tag );
}
vcap_rl_assert( array( 'invalid', 'invalid', 'invalid', 'limited' ) === $results, 'while the site-wide counter is full each visitor has a fifth of the quota for unknown codes' );
vcap_rl_visitor( '198.51.100.31' );
vcap_rl_assert( in_array( $sc->redeem( 'rl-valid-' . $tag ), array( 'saved', 'applied' ), true ), 'other visitors are not locked out by someone else\'s guessing' );

// Logged-in customers with vouchers of their own keep the full quota while the brake is on.
$customer = wp_insert_user( array( 'user_login' => 'vcap-rl-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'rl-' . $tag . '@example.test', 'role' => 'customer' ) );
$own           = new VCAP\Voucher();
$own->code     = 'credit-rl-' . $tag;
$own->type     = 'credit';
$own->user_id  = $customer;
$own->currency = get_woocommerce_currency();
$own->token    = VCAP\VoucherRepository::new_token();
$own->item_index = $customer;
vcap()->vouchers->insert( $own );
wp_set_current_user( $customer );
vcap_rl_visitor( '198.51.100.32' );
$results = array();
for ( $i = 0; $i < 4; $i++ ) {
	$results[] = $sc->redeem( 'rl-typo-' . $i . '-' . $tag );
}
vcap_rl_assert( array( 'invalid', 'invalid', 'invalid', 'invalid' ) === $results, 'customers with own vouchers are not braked' );
wp_set_current_user( 0 );
WC()->session->set( VCAP\CouponLink::SESSION_KEY, null );
wc_clear_notices();

// --- WooCommerce's coupon entry (Store API): unknown codes count, a limited visitor learns nothing.
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
$product = new WC_Product_Simple();
$product->set_name( 'Rate limit product' );
$product->set_regular_price( '20' );
$product->set_virtual( true );
$product->save();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$apply = static function ( string $code ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/apply-coupon' );
	$request->set_param( 'code', $code );
	return rest_do_request( $request );
};
vcap_rl_visitor( '192.0.2.40' );
$site_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(option_value) FROM {$wpdb->options} WHERE option_name LIKE %s", '%\_' . md5( VCAP\CouponGuard::BUCKET . '|all' ) ) );
for ( $i = 0; $i < VCAP\CouponGuard::PER_VISITOR; $i++ ) {
	$apply( 'rl-guess-' . $i . '-' . $tag );
}
$site_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(option_value) FROM {$wpdb->options} WHERE option_name LIKE %s", '%\_' . md5( VCAP\CouponGuard::BUCKET . '|all' ) ) );
vcap_rl_assert( wp_using_ext_object_cache() || VCAP\CouponGuard::PER_VISITOR === $site_after - $site_before, 'unknown codes entered in the cart count as failed attempts' );
$refused = $apply( 'rl-valid-' . $tag );
vcap_rl_assert( 400 === $refused->get_status() && str_contains( wp_json_encode( $refused->get_data() ), 'Too many attempts' ) && ! WC()->cart->has_discount( 'rl-valid-' . $tag ), 'a limited visitor is refused also for an existing code (the answer reveals nothing)' );
$unknown = $apply( 'rl-guess-more-' . $tag );
vcap_rl_assert( 400 === $unknown->get_status() && str_contains( wp_json_encode( $unknown->get_data() ), 'Too many attempts' ), 'unknown codes get the same answer while limited' );
vcap_rl_visitor( '192.0.2.41' );
$ok = $apply( 'rl-valid-' . $tag );
vcap_rl_assert( 200 === $ok->get_status() && WC()->cart->has_discount( 'rl-valid-' . $tag ), 'another visitor applies the code normally' );
WC()->cart->remove_coupon( 'rl-valid-' . $tag );
for ( $i = 0; $i < 20; $i++ ) {
	$apply( 'rl-valid-' . $tag );
	WC()->cart->remove_coupon( 'rl-valid-' . $tag );
}
vcap_rl_assert( 200 === $apply( 'rl-valid-' . $tag )->get_status(), 'applying an existing code again and again never counts' );
WC()->cart->empty_cart();
wc_clear_notices();

// --- Redemption API: atomic counter of the plugin, the site-wide brake never blocks valid codes.
$api_coupon = new WC_Coupon();
$api_coupon->set_code( 'rl-api-' . $tag );
$api_coupon->set_discount_type( 'fixed_cart' );
$api_coupon->set_amount( 10 );
$api_coupon->set_usage_limit( 1 );
$api_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$api_coupon->save();
$api                 = new VCAP\Voucher();
$api->code           = 'rl-api-' . $tag;
$api->coupon_id      = $api_coupon->get_id();
$api->order_item_id  = 81000000 + $api_coupon->get_id();
$api->initial_amount = 10;
$api->balance        = 10;
$api->currency       = get_woocommerce_currency();
$api->token          = VCAP\VoucherRepository::new_token();
vcap()->vouchers->insert( $api );
$api_user = wp_insert_user( array( 'user_login' => 'vcap-rl-api-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
wp_set_current_user( $api_user );
for ( $i = 0; $i < 300; $i++ ) {
	VCAP\Plugin::rate_limit_failure( 'redemption', 900 );
}
vcap_rl_assert( ! is_wp_error( vcap()->redemptions->check( $api->code ) ) && ! is_wp_error( vcap()->redemptions->check( $api->code ) ), 'redemption API: site-wide counter full, a valid code is still checked' );
wp_set_current_user( 0 );

remove_all_filters( 'vcap_client_ip' );
vcap_rl_reset();
echo "Voucher Captain rate limit checks passed.\n";
