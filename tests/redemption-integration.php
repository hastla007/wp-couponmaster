<?php
/**
 * External redemption API checks (PHP service + REST). Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/redemption-integration.php
 */

function vcap_redeem_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_redeem_code( $result ): string {
	return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
}

function vcap_redeem_voucher( string $mode = 'single', float $value = 50.0, array $props = array() ): array {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-rd-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	if ( ! empty( $props['emails'] ) ) {
		$coupon->set_email_restrictions( $props['emails'] );
	}
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();

	$voucher                 = new VCAP\Voucher();
	$voucher->code           = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 80000000 + $coupon->get_id();
	$voucher->type           = $props['type'] ?? ( 'balance' === $mode ? 'giftcard' : 'voucher' );
	$voucher->usage_mode     = $mode;
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $value;
	$voucher->balance        = $value;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->status         = $props['status'] ?? VCAP\Voucher::STATUS_ACTIVE;
	$voucher->expires_at     = $props['expires_at'] ?? null;
	$voucher->send_to        = 'buyer';
	$voucher->token          = VCAP\VoucherRepository::new_token();
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return array( $voucher, $coupon );
}

function vcap_redeem_user( string $name ): int {
	$login = 'vcap_rd_' . $name . '_' . strtolower( wp_generate_password( 6, false, false ) );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.com',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return (int) $id;
}

function vcap_redeem_cart_rejects( WC_Coupon $coupon ): bool {
	try {
		vcap()->coupons->validate( true, new WC_Coupon( $coupon->get_id() ) );
		return false;
	} catch ( Exception $e ) {
		return true;
	}
}

function vcap_redeem_rest( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

$service  = vcap()->redemptions;
$repo     = vcap()->vouchers;
$user_a   = vcap_redeem_user( 'a' );
$user_b   = vcap_redeem_user( 'b' );
$redeemed = array();
add_action(
	'vcap_voucher_redeemed',
	static function ( array $data ) use ( &$redeemed ) {
		$redeemed[] = $data['redemption_key'];
	}
);
wp_set_current_user( $user_a );

// --- Single-use voucher: reserve → idempotent retry → conflict → cart blocked → commit (exactly once).
list( $single, $single_coupon ) = vcap_redeem_voucher( 'single', 40.0 );
$check = $service->check( strtoupper( $single->code ), array( 'target' => 'test:course:1' ) );
vcap_redeem_assert( ! is_wp_error( $check ) && 40.0 === (float) $check['value'], 'check() accepts a valid code case-insensitively and reports its value' );

$key1  = 'it-' . wp_generate_uuid4();
$first = $service->reserve( $single->code, array( 'key' => $key1, 'target' => 'test:course:1' ) );
vcap_redeem_assert( ! is_wp_error( $first ) && 'reserved' === $first['state'] && 40.0 === (float) $first['amount'] && $user_a === $first['user_id'], 'reserve() locks the whole single-use voucher for the authenticated user' );
vcap_redeem_assert( VCAP\Voucher::STATUS_RESERVED === $repo->get( $single->id )->status, 'reserved voucher gets the "reserved" status' );
vcap_redeem_assert( vcap_redeem_cart_rejects( $single_coupon ), 'WooCommerce cart refuses a voucher that is reserved externally' );

$retry = $service->reserve( $single->code, array( 'key' => $key1, 'target' => 'test:course:1' ) );
vcap_redeem_assert( ! is_wp_error( $retry ) && $retry['redemption_key'] === $key1 && 'reserved' === $retry['state'], 'repeating the same key and request is idempotent' );
vcap_redeem_assert( 'vcap_idempotency_conflict' === vcap_redeem_code( $service->reserve( $single->code, array( 'key' => $key1, 'target' => 'test:course:2' ) ) ), 'same key with a different request is a conflict' );
vcap_redeem_assert( 'vcap_reserved' === vcap_redeem_code( $service->reserve( $single->code, array( 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:course:1' ) ) ), 'a second key cannot reserve an already reserved voucher' );
vcap_redeem_assert( 'vcap_not_allowed' === vcap_redeem_code( $service->commit( $key1, array( 'user_id' => $user_b ) ) ), 'another user cannot commit the reservation' );

$commit = $service->commit( $key1 );
vcap_redeem_assert( ! is_wp_error( $commit ) && 'committed' === $commit['state'] && null !== $commit['committed_at'], 'commit() completes the redemption' );
$fresh_single = $repo->get( $single->id );
$wc_single    = new WC_Coupon( $single_coupon->get_id() );
vcap_redeem_assert( VCAP\Voucher::STATUS_REDEEMED === $fresh_single->status && 0.0 === (float) $fresh_single->balance, 'committed single-use voucher is redeemed' );
vcap_redeem_assert( $wc_single->get_usage_limit() > 0 && $wc_single->get_usage_count() >= $wc_single->get_usage_limit(), 'WooCommerce usage limit is exhausted after an external redemption' );
vcap_redeem_assert( vcap_redeem_cart_rejects( $single_coupon ), 'WooCommerce cart refuses an externally redeemed voucher' );
vcap_redeem_assert( 'committed' === $service->commit( $key1 )['state'], 'repeating commit() is idempotent' );
vcap_redeem_assert( array( $key1 ) === $redeemed, 'vcap_voucher_redeemed fires exactly once' );
vcap_redeem_assert( 'vcap_already_redeemed' === vcap_redeem_code( $service->release( $key1 ) ), 'a committed redemption cannot be released' );
vcap_redeem_assert( 'vcap_already_redeemed' === vcap_redeem_code( $service->reserve( $single->code, array( 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:course:1' ) ) ), 'a redeemed voucher cannot be reserved again' );

// --- Release returns the voucher to the cart.
list( $released, $released_coupon ) = vcap_redeem_voucher( 'single', 25.0 );
$key2 = 'it-' . wp_generate_uuid4();
$service->reserve( $released->code, array( 'key' => $key2, 'target' => 'test:course:1' ) );
$release = $service->release( $key2 );
vcap_redeem_assert( ! is_wp_error( $release ) && 'released' === $release['state'] && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $released->id )->status, 'release() makes the voucher active again' );
vcap_redeem_assert( ! vcap_redeem_cart_rejects( $released_coupon ), 'released voucher is accepted by the WooCommerce cart again' );
vcap_redeem_assert( 'released' === $service->release( $key2 )['state'], 'repeating release() is idempotent' );
vcap_redeem_assert( 'vcap_reservation_missing' === vcap_redeem_code( $service->commit( $key2 ) ), 'a released reservation cannot be committed' );

// --- Gift card: partial amounts, insufficient balance, coupon amount follows the balance.
list( $card, $card_coupon ) = vcap_redeem_voucher( 'balance', 100.0 );
vcap_redeem_assert( 'vcap_invalid_request' === vcap_redeem_code( $service->reserve( $card->code, array( 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:course:1' ) ) ), 'gift card reservations require an amount' );
$key3 = 'it-' . wp_generate_uuid4();
$part = $service->reserve( $card->code, array( 'key' => $key3, 'target' => 'test:course:1', 'amount' => 30 ) );
vcap_redeem_assert( ! is_wp_error( $part ) && 70.0 === (float) $part['balance_after'] && 70.0 === (float) ( new WC_Coupon( $card_coupon->get_id() ) )->get_amount(), 'gift card reservation deducts the amount and updates the coupon' );
vcap_redeem_assert( 'vcap_insufficient_balance' === vcap_redeem_code( $service->reserve( $card->code, array( 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:course:1', 'amount' => 80 ) ) ), 'reservations above the remaining balance are refused' );
$key4 = 'it-' . wp_generate_uuid4();
$service->reserve( $card->code, array( 'key' => $key4, 'target' => 'test:course:2', 'amount' => 20 ) );
$service->commit( $key3 );
$service->release( $key4 );
$card_after = $repo->get( $card->id );
vcap_redeem_assert( 70.0 === (float) $card_after->balance && VCAP\Voucher::STATUS_ACTIVE === $card_after->status, 'committed and released gift card reservations leave the correct balance' );

// --- Expired reservations are released automatically.
list( $stale_card ) = vcap_redeem_voucher( 'balance', 50.0 );
$key5 = 'it-' . wp_generate_uuid4();
$service->reserve( $stale_card->code, array( 'key' => $key5, 'target' => 'test:course:1', 'amount' => 50 ) );
vcap_redeem_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $stale_card->id )->status, 'reserving the full balance marks the gift card as used up' );
global $wpdb;
$wpdb->update( VCAP\Install::tables()['redemptions'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'redemption_key' => $key5 ) );
$service->release_all_expired();
$stale_after = $repo->get( $stale_card->id );
vcap_redeem_assert( 'released' === $service->get( $key5 )['state'] && 50.0 === (float) $stale_after->balance && VCAP\Voucher::STATUS_ACTIVE === $stale_after->status, 'expired reservations return the full balance' );

// --- Business rules and stable error codes.
list( $expired ) = vcap_redeem_voucher( 'single', 10.0, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
list( $void )    = vcap_redeem_voucher( 'single', 10.0, array( 'status' => VCAP\Voucher::STATUS_VOID ) );
list( $ticket )  = vcap_redeem_voucher( 'single', 0.0, array( 'type' => 'ticket' ) );
list( $mine )    = vcap_redeem_voucher( 'single', 10.0, array( 'emails' => array( 'someone-else@example.com' ) ) );
vcap_redeem_assert( 'vcap_expired' === vcap_redeem_code( $service->check( $expired->code ) ), 'expired vouchers report vcap_expired' );
vcap_redeem_assert( 'vcap_cancelled' === vcap_redeem_code( $service->check( $void->code ) ), 'void vouchers report vcap_cancelled' );
vcap_redeem_assert( 'vcap_not_redeemable' === vcap_redeem_code( $service->check( $ticket->code ) ), 'tickets cannot be redeemed through the API' );
vcap_redeem_assert( 'vcap_not_allowed' === vcap_redeem_code( $service->check( $mine->code ) ), 'email-restricted vouchers are refused for other accounts' );
list( $filtered ) = vcap_redeem_voucher( 'single', 10.0 );
$deny = static function ( $allowed, $voucher, $target ) {
	return 'test:forbidden' === $target ? false : $allowed;
};
add_filter( 'vcap_redemption_allowed', $deny, 10, 3 );
$unavailable = static function ( $available, $target ) {
	return 'test:gone' === $target ? false : $available;
};
add_filter( 'vcap_redemption_target_available', $unavailable, 10, 2 );
vcap_redeem_assert( 'vcap_not_allowed' === vcap_redeem_code( $service->check( $filtered->code, array( 'target' => 'test:forbidden' ) ) ), 'vcap_redemption_allowed can refuse a target' );
vcap_redeem_assert( 'vcap_target_unavailable' === vcap_redeem_code( $service->check( $filtered->code, array( 'target' => 'test:gone' ) ) ), 'vcap_redemption_target_available reports unavailable targets' );
remove_filter( 'vcap_redemption_allowed', $deny, 10 );
remove_filter( 'vcap_redemption_target_available', $unavailable, 10 );
vcap_redeem_assert( 'vcap_invalid_request' === vcap_redeem_code( $service->reserve( $filtered->code, array( 'key' => 'short', 'target' => 'x' ) ) ), 'malformed redemption keys are rejected' );

// --- Failed-code rate limit.
update_option( 'vcap_redemption_max_failures', 3 );
wp_set_current_user( $user_b );
for ( $i = 0; $i < 3; $i++ ) {
	$service->check( 'does-not-exist-' . $i );
}
vcap_redeem_assert( 'vcap_rate_limited' === vcap_redeem_code( $service->check( $filtered->code ) ), 'too many invalid codes are rate limited per user' );
delete_transient( 'vcap_rl_redeem_u' . $user_b );
update_option( 'vcap_redemption_max_failures', 10 );

// --- REST: authentication, identity from the session, ownership.
wp_set_current_user( 0 );
list( $rest_voucher ) = vcap_redeem_voucher( 'single', 15.0 );
$key6     = 'it-' . wp_generate_uuid4();
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => $key6, 'target' => 'test:course:1' ) );
vcap_redeem_assert( 401 === $response->get_status(), 'REST reservation requires authentication' );
wp_set_current_user( $user_a );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => $key6, 'target' => 'test:course:1', 'user_id' => $user_b ) );
$data     = $response->get_data();
vcap_redeem_assert( 201 === $response->get_status() && $user_a === (int) $data['user_id'], 'REST reservation uses the authenticated user and ignores a user_id parameter' );
wp_set_current_user( $user_b );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $key6 . '/commit' )->get_status(), 'REST commit by another user is forbidden' );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'GET', '/vcap/v1/redemptions/' . $key6 )->get_status(), 'REST read by another user is forbidden' );
wp_set_current_user( $user_a );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $key6 . '/commit' );
vcap_redeem_assert( 200 === $response->get_status() && 'committed' === $response->get_data()['state'], 'REST commit by the owner completes the redemption' );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/check', array( 'code' => $rest_voucher->code ) );
vcap_redeem_assert( 409 === $response->get_status() && 'vcap_already_redeemed' === $response->get_data()['code'], 'REST check returns the stable error code with an HTTP status' );
vcap_redeem_assert( 404 === vcap_redeem_rest( 'GET', '/vcap/v1/redemptions/it-unknown-key-123' )->get_status(), 'unknown redemption keys return 404' );

// --- Privacy erasure unlinks redemptions from the user.
$erasure = ( new VCAP\Privacy( $repo ) )->erase( get_userdata( $user_a )->user_email );
$linked  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['redemptions'] . ' WHERE user_id = %d', $user_a ) );
vcap_redeem_assert( $erasure['items_removed'] && 0 === $linked, 'privacy erasure removes the user link from redemptions' );

echo "Voucher Captain redemption API checks passed.\n";
