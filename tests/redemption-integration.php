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
$p5_committed = $service->reserve( $single->code, array( 'key' => $key1, 'target' => 'test:course:1' ) );
vcap_redeem_assert( ! is_wp_error( $p5_committed ) && 'committed' === $p5_committed['state'] && ! empty( $p5_committed['replayed'] ), 'P5: repeating the reservation of a completed redemption answers it as committed (replayed)' );

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

// --- P5: a repeated reserve() only succeeds while the reservation holds something.
list( $p5_card ) = vcap_redeem_voucher( 'single', 18.0 );
$p5_key          = 'it-' . wp_generate_uuid4();
$service->reserve( $p5_card->code, array( 'key' => $p5_key, 'target' => 'test:course:1' ) );
$p5_again = $service->reserve( $p5_card->code, array( 'key' => $p5_key, 'target' => 'test:course:1' ) );
vcap_redeem_assert( ! is_wp_error( $p5_again ) && 'reserved' === $p5_again['state'] && ! empty( $p5_again['replayed'] ), 'P5: repeating an open reservation answers it, marked as replayed' );
$service->release( $p5_key );
$p5_released = $service->reserve( $p5_card->code, array( 'key' => $p5_key, 'target' => 'test:course:1' ) );
vcap_redeem_assert( 'vcap_redemption_released' === vcap_redeem_code( $p5_released ) && 409 === ( $p5_released->get_error_data()['status'] ?? 0 ) && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $p5_card->id )->status, 'P5: repeating a released reservation is vcap_redemption_released (409), not a success; nothing is reserved' );

// --- C3: an expired reservation is released where it is read, without the background job.
list( $c3_single, $c3_coupon ) = vcap_redeem_voucher( 'single', 22.0 );
$c3_key = 'it-' . wp_generate_uuid4();
$service->reserve( $c3_single->code, array( 'key' => $c3_key, 'target' => 'test:course:1' ) );
$wpdb->update( VCAP\Install::tables()['redemptions'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'redemption_key' => $c3_key ) );
vcap_redeem_assert( ! vcap_redeem_cart_rejects( $c3_coupon ) && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $c3_single->id )->status && 'released' === $service->get( $c3_key )['state'], 'C3: the cart releases an expired external reservation right away and accepts the voucher' );
$c3_key2 = 'it-' . wp_generate_uuid4();
$service->reserve( $c3_single->code, array( 'key' => $c3_key2, 'target' => 'test:course:1' ) );
$wpdb->update( VCAP\Install::tables()['redemptions'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'redemption_key' => $c3_key2 ) );
vcap_redeem_assert( 'vcap_reservation_missing' === vcap_redeem_code( $service->commit( $c3_key2 ) ) && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $c3_single->id )->status, 'C3: an expired reservation cannot be committed any more; it is released' );
list( $c3_card ) = vcap_redeem_voucher( 'balance', 40.0 );
$c3_key3 = 'it-' . wp_generate_uuid4();
$service->reserve( $c3_card->code, array( 'key' => $c3_key3, 'target' => 'test:course:1', 'amount' => 40 ) );
$wpdb->update( VCAP\Install::tables()['redemptions'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'redemption_key' => $c3_key3 ) );
$c3_check = $service->check( $c3_card->code, array( 'amount' => 40 ) );
vcap_redeem_assert( ! is_wp_error( $c3_check ) && 40.0 === (float) $c3_check['balance'], 'C3: check() gives an expired gift card reservation back before answering' );

// --- T9: the coupon amount is written under the row lock from the stored balance, never from a stale object.
list( $t9_card, $t9_coupon ) = vcap_redeem_voucher( 'balance', 70.0 );
$t9_stale = $repo->get( $t9_card->id );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'balance' => 50 ), array( 'id' => $t9_card->id ) ); // A later booking committed 50.
VCAP\CouponManager::sync_coupon_amount( $t9_stale ); // The earlier booking saves its coupon last, holding 70.
vcap_redeem_assert( 50.0 === (float) ( new WC_Coupon( $t9_coupon->get_id() ) )->get_amount( 'edit' ), 'T9: a coupon saved late by an earlier booking still carries the latest balance' );
// P3: an amount edited in WooCommerce is set back to the balance; the cart never grants more than the balance.
$t9_edit = new WC_Coupon( $t9_coupon->get_id() );
$t9_edit->set_amount( 99 );
$t9_edit->save();
vcap_redeem_assert( 50.0 === (float) ( new WC_Coupon( $t9_coupon->get_id() ) )->get_amount( 'edit' ), 'P3: saving another amount on a gift card coupon in WooCommerce restores the balance' );
$t9_cart = new WC_Coupon( $t9_coupon->get_id() );
$t9_cart->set_amount( 99 ); // Out of date, e.g. a failed save.
vcap()->coupons->validate( true, $t9_cart );
vcap_redeem_assert( 50.0 === (float) $t9_cart->get_amount( 'edit' ), 'P3: the cart caps an out-of-date coupon amount at the stored balance' );

// --- P3: a personal voucher whose coupon is gone fails closed; trashing a voucher coupon in wp-admin/REST is refused.
list( $p3_personal, $p3_coupon ) = vcap_redeem_voucher( 'single', 10.0, array( 'emails' => array( 'owner@example.com' ) ) );
$p3_protect = static fn() => true; // As in wp-admin or a REST request.
add_filter( 'vcap_protect_voucher_coupons', $p3_protect );
$p3_trashed = wp_trash_post( $p3_coupon->get_id() );
remove_filter( 'vcap_protect_voucher_coupons', $p3_protect );
vcap_redeem_assert( ! $p3_trashed && 'publish' === get_post_status( $p3_coupon->get_id() ), 'P3: trashing a voucher\'s coupon in wp-admin or over REST is refused' );
wp_delete_post( $p3_coupon->get_id(), true ); // Plugin code / WP-CLI: allowed, but recorded.
vcap_redeem_assert( 'vcap_not_redeemable' === vcap_redeem_code( $service->check( $p3_personal->code ) ) && in_array( 'coupon_removed', array_column( $repo->events( $p3_personal->id ), 'event' ), true ), 'P3: without its coupon a personal voucher is refused by the API (fail closed) and the removal is in its history' );

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
vcap_redeem_assert( 'vcap_not_allowed' === vcap_redeem_code( $service->reserve( $filtered->code, array( 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:forbidden' ) ) ) && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $filtered->id )->status, 'vcap_redemption_allowed refuses a reservation (nothing reserved)' );
remove_filter( 'vcap_redemption_allowed', $deny, 10 );
remove_filter( 'vcap_redemption_target_available', $unavailable, 10 );
// D6: the deciding filters run before the voucher row is locked (no transaction of the plugin is open), and once.
$d6_calls  = array();
$d6_filter = static function ( $allowed ) use ( &$d6_calls ) {
	$d6_calls[] = VCAP\Db::in_transaction();
	return $allowed;
};
add_filter( 'vcap_redemption_allowed', $d6_filter );
add_filter( 'vcap_redemption_target_available', $d6_filter );
$d6_key      = 'it-' . wp_generate_uuid4();
$d6_reserved = $service->reserve( $filtered->code, array( 'key' => $d6_key, 'target' => 'test:course:9' ) );
remove_filter( 'vcap_redemption_allowed', $d6_filter );
remove_filter( 'vcap_redemption_target_available', $d6_filter );
vcap_redeem_assert( ! is_wp_error( $d6_reserved ) && array( false, false ) === $d6_calls, 'D6: vcap_redemption_allowed and vcap_redemption_target_available run once per reservation, before the row lock' );
$service->release( $d6_key );
vcap_redeem_assert( 'vcap_invalid_request' === vcap_redeem_code( $service->reserve( $filtered->code, array( 'key' => 'short', 'target' => 'x' ) ) ), 'malformed redemption keys are rejected' );

// --- Failed-code rate limit.
update_option( 'vcap_redemption_max_failures', 3 );
wp_set_current_user( $user_b );
for ( $i = 0; $i < 3; $i++ ) {
	$service->check( 'does-not-exist-' . $i );
}
vcap_redeem_assert( 'vcap_rate_limited' === vcap_redeem_code( $service->check( $filtered->code ) ), 'too many invalid codes are rate limited per user' );
VCAP\Plugin::rate_limit_reset( 'redemption', 900, 'u' . $user_b );
update_option( 'vcap_redemption_max_failures', 10 );

// --- REST: authentication, identity from the session, ownership.
// Customers redeem over REST only for targets a connector registered (here the test connector "test:").
$test_prefixes = static fn( array $prefixes ) => array_merge( $prefixes, array( 'test:' ) );
add_filter( 'vcap_rest_redemption_target_prefixes', $test_prefixes );
wp_set_current_user( 0 );
list( $rest_voucher ) = vcap_redeem_voucher( 'single', 15.0 );
$key6     = 'it-' . wp_generate_uuid4();
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => $key6, 'target' => 'test:course:1' ) );
vcap_redeem_assert( 401 === $response->get_status(), 'REST reservation requires authentication' );
wp_set_current_user( $user_a );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => $key6, 'target' => 'test:course:1', 'user_id' => $user_b ) );
$data     = $response->get_data();
vcap_redeem_assert( 201 === $response->get_status() && $user_a === (int) $data['user_id'], 'REST reservation uses the authenticated user and ignores a user_id parameter' );
$replay = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => $key6, 'target' => 'test:course:1' ) );
vcap_redeem_assert( 200 === $replay->get_status() && 'reserved' === $replay->get_data()['state'], 'P5: REST answers a repeated reservation with 200 (201 only for a new one)' );
$p13_key = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => 'learnboating:u1:' . wp_generate_password( 10, false, false ), 'target' => 'test:course:1' ) );
vcap_redeem_assert( 400 === $p13_key->get_status(), 'P13: a key of another connector\'s namespace cannot be used over REST' );
$p13_meta = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $rest_voucher->code, 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'test:course:1', 'meta' => array( 'a' => array( 'b' => array( 'c' => array( 'd' => 1 ) ) ) ) ) );
vcap_redeem_assert( 400 === $p13_meta->get_status(), 'P13: metadata deeper than 3 levels is refused' );
wp_set_current_user( $user_b );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $key6 . '/commit' )->get_status(), 'REST commit by another user is forbidden' );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'GET', '/vcap/v1/redemptions/' . $key6 )->get_status(), 'REST read by another user is forbidden' );
wp_set_current_user( $user_a );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $key6 . '/commit' );
vcap_redeem_assert( 200 === $response->get_status() && 'committed' === $response->get_data()['state'], 'REST commit by the owner completes the redemption' );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/check', array( 'code' => $rest_voucher->code ) );
vcap_redeem_assert( 409 === $response->get_status() && 'vcap_already_redeemed' === $response->get_data()['code'], 'REST check returns the stable error code with an HTTP status' );
vcap_redeem_assert( 404 === vcap_redeem_rest( 'GET', '/vcap/v1/redemptions/it-unknown-key-123' )->get_status(), 'unknown redemption keys return 404' );

// --- REST targets: customers only for registered prefixes, nobody for internal ones (Pass Captain).
list( $target_voucher ) = vcap_redeem_voucher( 'single', 12.0 );
wp_set_current_user( $user_a );
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $target_voucher->code, 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'other-plugin:course:1' ) );
vcap_redeem_assert( 403 === $response->get_status() && 'vcap_not_allowed' === $response->get_data()['code'], 'customers cannot redeem for targets no connector registered for them' );
foreach ( array( 'passcaptain:ticket:5', 'pass captain:order' ) as $internal ) {
	$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $target_voucher->code, 'key' => 'it-' . wp_generate_uuid4(), 'target' => $internal ) );
	vcap_redeem_assert( 403 === $response->get_status(), 'customers cannot redeem for internal target ' . $internal );
}
vcap_redeem_assert( 403 === vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/check', array( 'code' => $target_voucher->code, 'target' => 'passcaptain:order' ) )->get_status(), 'internal targets are refused in the REST check as well' );
$manager = wp_insert_user( array( 'user_login' => 'vcap_rd_mgr_' . strtolower( wp_generate_password( 6, false, false ) ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'shop_manager' ) );
wp_set_current_user( $manager );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $target_voucher->code, 'key' => 'it-' . wp_generate_uuid4(), 'target' => 'passcaptain:order' ) )->get_status(), 'shop managers cannot use internal targets over REST either' );
$mgr_key  = 'it-' . wp_generate_uuid4();
$response = vcap_redeem_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $target_voucher->code, 'key' => $mgr_key, 'target' => 'pos:till:1' ) );
vcap_redeem_assert( 201 === $response->get_status(), 'shop managers (connectors with an application password) may use their own targets' );
vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $mgr_key . '/release' );
// A connector reserved on the customer's behalf (PHP): the customer cannot commit it over REST before delivery.
list( $connector_voucher ) = vcap_redeem_voucher( 'single', 9.0 );
$conn_key = 'it-' . wp_generate_uuid4();
$service->reserve( $connector_voucher->code, array( 'key' => $conn_key, 'target' => 'premium:unlock', 'user_id' => $user_a ) );
wp_set_current_user( $user_a );
vcap_redeem_assert( 403 === vcap_redeem_rest( 'POST', '/vcap/v1/redemptions/' . $conn_key . '/commit' )->get_status() && 200 === vcap_redeem_rest( 'GET', '/vcap/v1/redemptions/' . $conn_key )->get_status(), 'customers may read but not commit a connector\'s redemption for an unregistered target' );
$service->release( $conn_key );
remove_filter( 'vcap_rest_redemption_target_prefixes', $test_prefixes );

// --- Privacy erasure unlinks redemptions from the user.
$erasure = ( new VCAP\Privacy( $repo ) )->erase( get_userdata( $user_a )->user_email );
$linked  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['redemptions'] . ' WHERE user_id = %d', $user_a ) );
vcap_redeem_assert( $erasure['items_removed'] && 0 === $linked, 'privacy erasure removes the user link from redemptions' );

// Q5: a reusable voucher (10-visit card) is never redeemed beyond its usage limit, also by two tills at once.
list( $visits, $visits_coupon ) = vcap_redeem_voucher( 'unlimited', 10.0 );
$visits_coupon->set_usage_limit( 2 );
$visits_coupon->save();
$visit_results = array();
for ( $i = 0; $i < 3; $i++ ) {
	$visit_results[] = vcap()->tickets->redeem_single( vcap()->vouchers->get( $visits->id ), 'Visit' );
}
$visit_events = count( array_filter( vcap()->vouchers->events( $visits->id ), static fn( $e ) => 'redeemed' === $e['event'] ) );
vcap_redeem_assert( array( true, true, false ) === $visit_results && 2 === ( new WC_Coupon( $visits_coupon->get_id() ) )->get_usage_count() && 2 === $visit_events, 'Q5: a reusable voucher with 2 uses is redeemed twice; the third scan is refused and not booked' );
// A till that loaded the voucher before the other till booked the last use: the conditional UPDATE decides.
list( $race, $race_coupon ) = vcap_redeem_voucher( 'unlimited', 10.0 );
$race_coupon->set_usage_limit( 1 );
$race_coupon->save();
$stale = vcap()->vouchers->get( $race->id );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = 'usage_count'", $race_coupon->get_id() ) );
wp_cache_delete( $race_coupon->get_id(), 'post_meta' );
vcap_redeem_assert( ! vcap()->tickets->redeem_single( $stale, 'Second till' ) && 1 === ( new WC_Coupon( $race_coupon->get_id() ) )->get_usage_count(), 'Q5: the second till scanning the last use at the same time is refused (no 11th visit)' );
list( $free ) = vcap_redeem_voucher( 'unlimited', 10.0 );
vcap_redeem_assert( vcap()->tickets->redeem_single( $free, 'No limit' ) && vcap()->tickets->redeem_single( vcap()->vouchers->get( $free->id ), 'No limit' ), 'Q5: without a usage limit reusable vouchers can still be redeemed again and again' );

// H15: "Expiring within 30 days" lists only usable vouchers with a remaining value.
$soon_at = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
list( $soon_ok )    = vcap_redeem_voucher( 'balance', 30.0, array( 'expires_at' => $soon_at ) );
list( $soon_used )  = vcap_redeem_voucher( 'single', 30.0, array( 'expires_at' => $soon_at, 'status' => VCAP\Voucher::STATUS_REDEEMED ) );
list( $soon_void )  = vcap_redeem_voucher( 'single', 30.0, array( 'expires_at' => $soon_at, 'status' => VCAP\Voucher::STATUS_VOID ) );
list( $soon_empty ) = vcap_redeem_voucher( 'balance', 30.0, array( 'expires_at' => $soon_at ) );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'balance' => 0 ), array( 'id' => $soon_empty->id ) );
$soon_ids = array_map( static fn( $v ) => $v->id, vcap()->vouchers->query( array( 'expiring' => 'soon', 'per_page' => 1000 ) )['items'] );
vcap_redeem_assert( in_array( $soon_ok->id, $soon_ids, true ) && ! array_intersect( array( $soon_used->id, $soon_void->id, $soon_empty->id ), $soon_ids ), 'H15: the expiry filter (and its export) leaves out redeemed, void and empty vouchers' );

echo "Voucher Captain redemption API checks passed.\n";
