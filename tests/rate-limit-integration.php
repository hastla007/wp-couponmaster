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
	$wpdb->query( 'DELETE FROM ' . VCAP\Install::tables()['rate'] );
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
	VCAP\Plugin::rate_limit_failure( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW );
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
vcap_rl_reset(); // The site-wide counter filled above would brake the next visitors.

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
$site_before = VCAP\Plugin::rate_site_count( VCAP\CouponGuard::BUCKET, VCAP\CouponGuard::WINDOW );
for ( $i = 0; $i < VCAP\CouponGuard::PER_VISITOR; $i++ ) {
	$apply( 'rl-guess-' . $i . '-' . $tag );
}
$site_after = VCAP\Plugin::rate_site_count( VCAP\CouponGuard::BUCKET, VCAP\CouponGuard::WINDOW );
vcap_rl_assert( VCAP\CouponGuard::PER_VISITOR === $site_after - $site_before, 'unknown codes entered in the cart count as failed attempts' );
$refused = $apply( 'rl-valid-' . $tag );
vcap_rl_assert( 429 === $refused->get_status() && str_contains( wp_json_encode( $refused->get_data() ), 'Too many attempts' ) && ! WC()->cart->has_discount( 'rl-valid-' . $tag ), 'a limited visitor is refused also for an existing code (the answer reveals nothing)' );
$unknown = $apply( 'rl-guess-more-' . $tag );
vcap_rl_assert( 429 === $unknown->get_status() && str_contains( wp_json_encode( $unknown->get_data() ), 'Too many attempts' ), 'unknown codes get the same answer while limited' );
vcap_rl_visitor( '192.0.2.41' );
$ok = $apply( 'rl-valid-' . $tag );
vcap_rl_assert( 200 === $ok->get_status() && WC()->cart->has_discount( 'rl-valid-' . $tag ), 'another visitor applies the code normally' );
WC()->cart->remove_coupon( 'rl-valid-' . $tag );
for ( $i = 0; $i < 20; $i++ ) {
	$apply( 'rl-valid-' . $tag );
	WC()->cart->remove_coupon( 'rl-valid-' . $tag );
}
vcap_rl_assert( 200 === $apply( 'rl-valid-' . $tag )->get_status(), 'applying an existing code again and again never counts' );
// S1: WordPress matches REST routes case-insensitively – other spellings of the route count the same.
vcap_rl_reset();
vcap_rl_visitor( '192.0.2.50' );
$before = VCAP\Plugin::rate_site_count( VCAP\CouponGuard::BUCKET, VCAP\CouponGuard::WINDOW );
foreach ( array( '/wc/store/v1/Cart/Apply-Coupon', '/wc/store/v1/cart/APPLY-coupon' ) as $i => $route ) {
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_param( 'code', 'rl-case-' . $i . '-' . $tag );
	rest_do_request( $request );
}
vcap_rl_assert( $before + 2 === VCAP\Plugin::rate_site_count( VCAP\CouponGuard::BUCKET, VCAP\CouponGuard::WINDOW ), 'Store API routes in other upper/lower case spellings count as failed attempts' );
$batch = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
$batch->set_header( 'Content-Type', 'application/json' );
$batch->set_body( wp_json_encode( array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/CART/APPLY-COUPON', 'body' => array( 'code' => 'rl-batch-' . $tag ) ) ) ) ) );
rest_do_request( $batch );
vcap_rl_assert( $before + 3 === VCAP\Plugin::rate_site_count( VCAP\CouponGuard::BUCKET, VCAP\CouponGuard::WINDOW ), 'also inside a Store API batch request' );
vcap_rl_assert( VCAP\CouponGuard::applies_coupon( array(), '/wc/store/v1/Cart/Apply-Coupon' ) && ! VCAP\CouponGuard::applies_coupon( array(), '/wc/store/v1/cart/add-item' ), 'route check is case-insensitive and specific' );
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

// --- V8: the redemption API also counts per address, in the bucket shared with all code forms.
vcap_rl_reset();
vcap_rl_visitor( '203.0.113.80' );
$acc_a = wp_insert_user( array( 'user_login' => 'vcap-rl-a-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
$acc_b = wp_insert_user( array( 'user_login' => 'vcap-rl-b-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
$codes = array();
foreach ( array( $acc_a, $acc_b ) as $account ) {
	wp_set_current_user( $account );
	for ( $i = 0; $i < 8; $i++ ) {
		$result  = vcap()->redemptions->check( 'rl-none-' . $account . '-' . $i . '-' . $tag );
		$codes[] = is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	}
}
vcap_rl_assert( 15 === count( array_keys( array_slice( $codes, 0, 15 ), 'vcap_not_found', true ) ) && 'vcap_rate_limited' === $codes[15], 'redemption API: a second self-registered account from the same address does not get a new quota (per address, not only per account)' );
wp_set_current_user( 0 );
$_POST['vcap_balance_code'] = 'rl-balance-' . $tag;
$downloads                  = new VCAP\Downloads( vcap()->settings, vcap()->vouchers, vcap()->pdf );
$downloads->handle_balance();
unset( $_POST['vcap_balance_code'] );
vcap_rl_assert( str_contains( $downloads->balance_shortcode(), 'Too many attempts' ), 'one bucket for all code forms: after the API quota the balance check is limited too' );
vcap_rl_reset();
// Staff (connectors with voucher management rights) count only per account.
$staff = wp_insert_user( array( 'user_login' => 'vcap-rl-staff-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'role' => 'shop_manager' ) );
update_option( 'vcap_redemption_max_failures', 30 );
wp_set_current_user( $staff );
for ( $i = 0; $i < 16; $i++ ) {
	vcap()->redemptions->check( 'rl-staff-' . $i . '-' . $tag );
}
wp_set_current_user( 0 );
delete_option( 'vcap_redemption_max_failures' );
vcap_rl_assert( ! VCAP\CodeLookup::begin(), 'staff typos do not use up the address quota of the shop' );
vcap_rl_reset();

// --- V15: another customer's store credit reads like an unknown code everywhere and counts as a failure.
$owner_id = wp_insert_user( array( 'user_login' => 'vcap-rl-owner-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'rl-owner-' . $tag . '@example.test', 'role' => 'customer' ) );
$foreign  = vcap()->store_credit->account( $owner_id, true );
vcap_rl_assert( $foreign instanceof VCAP\Voucher && '' !== $foreign->code, 'a store credit account with a coupon exists for the test' );
vcap_rl_visitor( '203.0.113.81' );
WC()->cart->empty_cart();
wc_clear_notices();
$before = VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW );
vcap_rl_assert( 'invalid' === $sc->redeem( $foreign->code ) && $before + 1 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'redeem form: another customer\'s store credit is "not found" and counts as a failed attempt' );
wc_clear_notices();
$_POST['vcap_balance_code'] = $foreign->code;
$downloads                  = new VCAP\Downloads( vcap()->settings, vcap()->vouchers, vcap()->pdf );
$downloads->handle_balance();
unset( $_POST['vcap_balance_code'] );
$answer = $downloads->balance_shortcode();
vcap_rl_assert( str_contains( $answer, 'Voucher code not found' ) && ! str_contains( $answer, 'Balance' ), 'balance check: another customer\'s store credit is "not found" (no balance revealed)' );
$topup    = new VCAP\TopUp( vcap()->vouchers, vcap()->coupons );
$check    = new ReflectionMethod( $topup, 'check' );
$messages = array( $check->invoke( $topup, $foreign->code ), $check->invoke( $topup, 'rl-nothing-' . $tag ) );
vcap_rl_assert( is_string( $messages[0] ) && $messages[0] === $messages[1], 'top-up: an existing code that cannot be topped up gets the same answer as an unknown code' );
vcap_rl_reset();
// AP-S1-3: WooCommerce's coupon field (WC_Discounts, Store API) answers like for an unknown code and books a failure.
$ap_unknown  = 'rl-nothing-ap-' . $tag;
$ap_plain    = static fn( $message, string $code ) => str_ireplace( $code, 'CODE', wp_strip_all_tags( (string) $message ) );
$ap_foreign  = ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $foreign->code ) );
$ap_nothing  = ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $ap_unknown ) );
vcap_rl_assert( is_wp_error( $ap_foreign ) && is_wp_error( $ap_nothing ) && $ap_plain( $ap_foreign->get_error_message(), $foreign->code ) === $ap_plain( $ap_nothing->get_error_message(), $ap_unknown ) && ! str_contains( $ap_foreign->get_error_message(), 'another customer' ), 'AP-S1-3: another customer\'s store credit in the coupon field gets WooCommerce\'s "does not exist" message' );
vcap_rl_visitor( '203.0.113.83' );
$before   = VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW );
$ap_store = array( $apply( $foreign->code ), $apply( $ap_unknown ) );
vcap_rl_assert( 400 === $ap_store[0]->get_status() && $ap_plain( $ap_store[0]->get_data()['message'] ?? '', $foreign->code ) === $ap_plain( $ap_store[1]->get_data()['message'] ?? '', $ap_unknown ) && $before + 2 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'AP-S1-3: Store API – the same answer as for an unknown code, and both count as failed attempts' );
vcap_rl_reset();
// M12-01: POST cart/coupons validates the code (coupon_exists) before any callback: no longer an uncounted oracle.
$m01_post = static function ( array $body, bool $nonce_off = true ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/coupons' );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( ! $nonce_off ) {
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
	}
	$response = rest_do_request( $request );
	add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
	return $response;
};
$m01_unknown = 'rl-m01-none-' . $tag;
vcap_rl_visitor( '203.0.113.90' );
$before  = VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW );
$m01_a   = $m01_post( array( 'code' => $m01_unknown ), false );
$m01_b   = $m01_post( array( 'code' => $foreign->code ), false );
$m01_msg = static fn( $r, string $code ) => $ap_plain( $r->get_data()['message'] ?? '', $code );
vcap_rl_assert( 400 === $m01_a->get_status() && 'woocommerce_rest_cart_coupon_error' === ( $m01_a->get_data()['code'] ?? '' ) && 'rest_invalid_param' !== ( $m01_a->get_data()['code'] ?? '' ), 'M12-01: cart/coupons – an unknown code gets one uniform 400 "invalid code" (no rest_invalid_param)' );
vcap_rl_assert( 400 === $m01_b->get_status() && $m01_msg( $m01_a, $m01_unknown ) === $m01_msg( $m01_b, $foreign->code ) && ( $m01_a->get_data()['code'] ?? '' ) === ( $m01_b->get_data()['code'] ?? '' ), 'M12-01: cart/coupons – another customer\'s store credit gets the same answer, also without a nonce (no 401 oracle)' );
vcap_rl_assert( $before + 2 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'M12-01: cart/coupons – both are booked as failed attempts' );
$m01_c = $m01_post( array( 'code' => 'rl-valid-' . $tag, 'discount_type' => $foreign->code ), false );
$m01_d = $m01_post( array( 'code' => 'rl-valid-' . $tag, 'discount_type' => $m01_unknown ), false );
vcap_rl_assert( 400 === $m01_c->get_status() && 400 === $m01_d->get_status() && $m01_c->get_data()['code'] === $m01_d->get_data()['code'] && $before + 4 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'M12-01: cart/coupons – a code probed through discount_type (same validate_callback) gets the same answer and counts' );
$m01_batch = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
$m01_batch->set_header( 'Content-Type', 'application/json' );
$m01_batch->set_body( wp_json_encode( array( 'validation' => 'require-all-validate', 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/cart/coupons', 'body' => array( 'code' => $m01_unknown . '-b' ) ), array( 'method' => 'POST', 'path' => '/wc/store/v1/Cart/Coupons', 'body' => array( 'code' => $foreign->code ) ) ) ) ) );
$m01_out   = rest_do_request( $m01_batch )->get_data();
$m01_codes = array_map( static fn( $r ) => is_array( $r ) ? ( $r['body']['code'] ?? '' ) : '', (array) ( $m01_out['responses'] ?? array() ) );
vcap_rl_assert( array( 'woocommerce_rest_cart_coupon_error', 'woocommerce_rest_cart_coupon_error' ) === $m01_codes && $before + 6 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'M12-01: cart/coupons inside /batch (also "require-all-validate") – uniform answer, every request counts' );
WC()->cart->add_to_cart( $product->get_id(), 1 );
$m01_ok = $m01_post( array( 'code' => 'rl-valid-' . $tag ) );
vcap_rl_assert( 201 === $m01_ok->get_status() && WC()->cart->has_discount( 'rl-valid-' . $tag ) && $before + 6 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'M12-01: cart/coupons – an existing code is applied as before and does not count as a failure' );
WC()->cart->remove_coupon( 'rl-valid-' . $tag );
for ( $i = 0; $i < VCAP\CodeLookup::PER_VISITOR; $i++ ) {
	$m01_post( array( 'code' => $m01_unknown . '-' . $i ) );
}
$m01_l1 = $m01_post( array( 'code' => 'rl-valid-' . $tag ) );
$m01_l2 = $m01_post( array( 'code' => $m01_unknown . '-x' ), false );
vcap_rl_assert( 429 === $m01_l1->get_status() && 429 === $m01_l2->get_status() && $m01_l1->get_data()['message'] === $m01_l2->get_data()['message'] && ! WC()->cart->has_discount( 'rl-valid-' . $tag ), 'M12-01: cart/coupons – a limited visitor gets 429 for an existing and an unknown code alike' );
WC()->cart->empty_cart();
wc_clear_notices();
vcap_rl_reset();
// AP-S1-2: REST /redemptions/check (and reserve) as another customer: 404 like an unknown code, counted as a failure.
$ap_other = (int) wp_insert_user( array( 'user_login' => 'vcap-rl-other-' . $tag, 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'rl-other-' . $tag . '@example.test', 'role' => 'customer' ) );
wp_set_current_user( $ap_other );
vcap_rl_visitor( '203.0.113.84' );
$before   = VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW );
$ap_check = new WP_REST_Request( 'POST', '/vcap/v1/redemptions/check' );
$ap_check->set_param( 'code', $foreign->code );
$ap_rest  = rest_do_request( $ap_check );
vcap_rl_assert( 404 === $ap_rest->get_status() && 'vcap_not_found' === ( $ap_rest->get_data()['code'] ?? '' ) && $before + 1 === VCAP\Plugin::rate_site_count( VCAP\CodeLookup::BUCKET, VCAP\CodeLookup::WINDOW ), 'AP-S1-2: REST /redemptions/check – another customer\'s store credit is 404 "not found" and counts as a failed attempt' );
$ap_reserve = vcap()->redemptions->reserve( $foreign->code, array( 'key' => 'ap-s12-' . $tag, 'target' => 'test:ap', 'user_id' => $ap_other ) );
vcap_rl_assert( is_wp_error( $ap_reserve ) && 'vcap_not_found' === $ap_reserve->get_error_code() && 404 === $ap_reserve->get_error_data()['status'], 'AP-S1-2: reserve – the same 404 for another customer\'s store credit' );
wp_set_current_user( $owner_id );
$ap_own = rest_do_request( $ap_check );
vcap_rl_assert( 422 === $ap_own->get_status() && 'vcap_not_redeemable' === ( $ap_own->get_data()['code'] ?? '' ), 'AP-S1-2: the owner still learns that store credit is only used in the shop (422)' );
wp_set_current_user( 0 );
vcap_rl_reset();

// --- S9: counter names are keyed hashes, not md5(bucket|address).
vcap_rl_visitor( '198.51.100.90' );
VCAP\CodeLookup::begin();
$rl_table = VCAP\Install::tables()['rate'];
$plain    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rl_table} WHERE counter_key = %s", md5( VCAP\CodeLookup::BUCKET . '|198.51.100.90' ) ) );
$keyed    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$rl_table} WHERE counter_key = %s", hash_hmac( 'sha256', VCAP\CodeLookup::BUCKET . '|198.51.100.90', wp_salt( 'auth' ) ) ) );
vcap_rl_assert( 0 === $plain && 1 === $keyed, 'the counter of an address is stored under an HMAC with the site salt (not reversible by trying all IPv4 addresses)' );
vcap_rl_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_rl_' ) . '%' ) ), 'B16: rate limit counters are rows of vcap_rate_limits, no wp_options rows' );
// B16: the daily purge removes counters of finished windows only; counters left as options before schema 1.7 too.
$wpdb->insert( $rl_table, array( 'window_end' => time() - 10, 'counter_key' => str_repeat( 'a', 64 ), 'hits' => 3 ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'vcap_rl_' . sprintf( '%010d', time() - 10 ) . '_' . str_repeat( 'b', 64 ), 'option_value' => '2', 'autoload' => 'no' ) );
VCAP\Plugin::purge_rate_limits( 5.0 );
vcap_rl_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rl_table} WHERE window_end <= %d", time() ) ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rl_table}" ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_rl_' ) . '%' ) ), 'B16: the purge removes finished windows (table and old options) and keeps the running one' );
vcap_rl_reset();

// --- V14: IPv6 also per /48, so one allocation cannot rotate through its /64 networks.
$b48 = 'rl-48-' . wp_generate_password( 6, false, false );
$hit = array();
for ( $net = 1; $net <= 5; $net++ ) {
	vcap_rl_visitor( '2001:db8:77:' . dechex( $net ) . '::1' );
	for ( $i = 0; $i < 3; $i++ ) {
		$hit[] = VCAP\Plugin::rate_limited( $b48, 3, 0, HOUR_IN_SECONDS, false );
	}
}
vcap_rl_assert( ! in_array( true, array_slice( $hit, 0, 12 ), true ) && $hit[12], 'a /48 gets four times the quota of a /64, then every /64 in it is limited' );
vcap_rl_visitor( '2001:db8:78:1::1' );
vcap_rl_assert( ! VCAP\Plugin::rate_limited( $b48, 3, 0, HOUR_IN_SECONDS, false ), 'another /48 is not affected' );
vcap_rl_reset();

// --- V13: the site-wide counter of counted requests (preview) only brakes, it never switches the preview off.
$bp = 'rl-pv-' . wp_generate_password( 6, false, false );
vcap_rl_visitor( '203.0.113.90' );
for ( $i = 0; $i < 5; $i++ ) {
	VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS );
}
vcap_rl_visitor( '203.0.113.91' );
vcap_rl_assert( ! VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS ) && ! VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS ) && ! VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS ) && ! VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS ) && VCAP\Plugin::rate_limited( $bp, 20, 5, HOUR_IN_SECONDS ), 'site-wide preview counter full: other visitors still get previews, with a fifth of the quota' );
vcap_rl_reset();

// --- C2: an object cache that does not count falls back to the database (opt-in backend only).
$real_cache = $GLOBALS['wp_object_cache'];
$broken     = new class() extends WP_Object_Cache {
	public function incr( $key, $offset = 1, $group = 'default' ) {
		return 'vcap_rate' === $group ? false : parent::incr( $key, $offset, $group );
	}
};
$GLOBALS['wp_object_cache'] = $broken;
$was_ext                    = wp_using_ext_object_cache( true );
add_filter( 'vcap_rate_limit_backend', static fn() => 'object_cache' );
$bc = 'rl-cache-' . wp_generate_password( 6, false, false );
vcap_rl_visitor( '203.0.113.95' );
$res = array();
for ( $i = 0; $i < 4; $i++ ) {
	$res[] = VCAP\Plugin::rate_limited( $bc, 3, 0, HOUR_IN_SECONDS, false );
}
remove_all_filters( 'vcap_rate_limit_backend' );
wp_using_ext_object_cache( $was_ext );
$GLOBALS['wp_object_cache'] = $real_cache;
vcap_rl_assert( array( false, false, false, true ) === $res, 'object cache increment returns false: the database counts and the limit holds' );
vcap_rl_reset();

// --- D11: limits per bucket by filter, the first hit is logged and shown, counters can be reset.
$bd = 'rl-d11-' . wp_generate_password( 6, false, false );
add_filter( "vcap_rate_limit_{$bd}", static fn( $limits ) => array( 'per_visitor' => 1 ) + $limits );
$fired = 0;
add_action( 'vcap_rate_limit_hit', static function () use ( &$fired ) {
	++$fired;
} );
vcap_rl_visitor( '203.0.113.96' );
$res = array( VCAP\Plugin::rate_limited( $bd, 10, 0, HOUR_IN_SECONDS ), VCAP\Plugin::rate_limited( $bd, 10, 0, HOUR_IN_SECONDS ), VCAP\Plugin::rate_limited( $bd, 10, 0, HOUR_IN_SECONDS ) );
vcap_rl_assert( array( false, true, true ) === $res, 'the filter "vcap_rate_limit_{bucket}" changes the limit of a bucket' );
$hits = get_option( VCAP\Plugin::RATE_HITS_OPTION );
vcap_rl_assert( 1 === $fired && is_array( $hits ) && isset( $hits[ $bd ] ) && $hits[ $bd ]['last'] > time() - 60, 'the first hit of a window is logged once and recorded for Site Health' );
wp_set_current_user( 1 );
$health = ( new VCAP\Health() )->test_rate_limits();
wp_set_current_user( 0 );
vcap_rl_assert( 'recommended' === $health['status'] && str_contains( $health['description'], $bd ) && str_contains( (string) $health['actions'], 'vcap_reset_rate_limits' ), 'Site Health names the bucket that was hit and offers the reset' );
VCAP\Plugin::reset_rate_limits();
vcap_rl_assert( ! VCAP\Plugin::rate_limited( $bd, 10, 0, HOUR_IN_SECONDS ) && false === get_option( VCAP\Plugin::RATE_HITS_OPTION ), 'reset removes all counters and the recorded hits' );
remove_all_filters( "vcap_rate_limit_{$bd}" );
remove_all_actions( 'vcap_rate_limit_hit' );
remove_all_filters( 'vcap_client_ip' );
vcap_rl_assert( VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '10.0.0.5' ) ), 'Site Health: a private REMOTE_ADDR without "vcap_client_ip" means a proxy hides the visitors' );
vcap_rl_assert( VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1' ) ), 'Site Health: forwarding headers without "vcap_client_ip" are reported' );
add_filter( 'vcap_client_ip', static fn() => '198.51.100.1' );
vcap_rl_assert( ! VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1' ) ), 'Site Health: no warning once "vcap_client_ip" returns the visitor address' );
vcap_rl_reset();

remove_all_filters( 'vcap_client_ip' );
vcap_rl_reset();
echo "Voucher Captain rate limit checks passed.\n";
