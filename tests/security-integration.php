<?php
/**
 * Security, privacy and personal-data lifecycle checks. Run through WP-CLI.
 */

global $wpdb;

function vcap_security_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_security_create_voucher( string $email, int $index ): VCAP\Voucher {
	$voucher                  = new VCAP\Voucher();
	$voucher->code            = 'vcap-privacy-' . $index . '-' . strtolower( wp_generate_password( 8, false, false ) );
	$voucher->order_item_id   = 92000000 + $index;
	$voucher->type            = 'giftcard';
	$voucher->usage_mode      = 'balance';
	$voucher->initial_amount  = 25;
	$voucher->balance         = 25;
	$voucher->currency        = get_woocommerce_currency();
	$voucher->buyer_name      = 'Privacy Test Buyer';
	$voucher->buyer_email     = $email;
	$voucher->recipient_name  = 'Privacy Test Recipient';
	$voucher->recipient_email = $email;
	$voucher->message         = 'Private test message';
	$voucher->fields          = array( 'extra' => array( 'label' => 'Extra', 'value' => 'Private field' ) );
	$voucher->token           = VCAP\VoucherRepository::new_token();
	$voucher->id              = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert privacy test voucher.' );
	}
	return $voucher;
}

function vcap_security_method_source( string $class, string $method ): string {
	$reflection = new ReflectionMethod( $class, $method );
	$lines      = file( $reflection->getFileName() );
	return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
}

// Exporter and eraser must page through more than the 500-item WordPress privacy batch.
$email   = 'privacy-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
$privacy = new VCAP\Privacy( vcap()->vouchers );
for ( $i = 0; $i < 501; $i++ ) {
	$v = vcap_security_create_voucher( $email, $i );
	if ( 0 === $i ) {
		vcap()->vouchers->log( $v->id, 'delivery_failed', null, null, 'Failed for ' . $email );
	}
}
$first_page  = $privacy->export( $email, 1 );
$second_page = $privacy->export( $email, 2 );
vcap_security_assert( 500 === count( $first_page['data'] ) && ! $first_page['done'] && 1 === count( $second_page['data'] ) && $second_page['done'], 'privacy export paginates all 501 matching vouchers' );
$erase_first  = $privacy->erase( $email, 1 );
$erase_second = $privacy->erase( $email, 2 );
vcap_security_assert( $erase_first['items_removed'] && ! $erase_first['done'] && $erase_second['items_removed'] && $erase_second['done'], 'privacy erasure continues across batches larger than 500' );
vcap_security_assert( array() === vcap()->vouchers->for_email( $email, 10 ), 'privacy erasure removes buyer and recipient email lookups' );
$all_privacy_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VCAP\Install::tables()['vouchers'] . ' WHERE code LIKE %s', 'vcap-privacy-%' ) );
$still_personal   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['vouchers'] . ' WHERE buyer_email = %s OR recipient_email = %s', $email, $email ) );
vcap_security_assert( 0 === $still_personal && $all_privacy_ids, 'erasure leaves voucher records but removes direct identifiers' );
$privacy_event = $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM ' . VCAP\Install::tables()['events'] . ' WHERE voucher_id = %d ORDER BY id DESC LIMIT 1', (int) $all_privacy_ids[0] ) );
vcap_security_assert( '[personal data removed]' === $privacy_event, 'erasure redacts free-text audit notes while preserving event rows' );

// A customer who used someone else's voucher is named in its history ("used by"): exported and erased too.
$user_email = 'privacy-user-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
$foreign    = vcap_security_create_voucher( 'owner-' . $user_email, 900100 );
vcap()->vouchers->log( $foreign->id, 'redeemed', 10.0, 0.0, strtoupper( $user_email ) );
$use_export = $privacy->export( $user_email, 1 );
vcap_security_assert( in_array( 'vcap-voucher-uses', array_column( $use_export['data'], 'group_id' ), true ), 'the export lists uses of other people\'s vouchers by this customer' );
$privacy->erase( $user_email, 1 );
$use_note = $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM ' . VCAP\Install::tables()['events'] . ' WHERE voucher_id = %d ORDER BY id DESC LIMIT 1', $foreign->id ) );
vcap_security_assert( '[personal data removed]' === $use_note, 'erasure removes the customer from the history of other people\'s vouchers' );

// External redemptions are paged as well: more than one batch, and none of the customer's own vouchers.
$redeemer_email = 'privacy-redeemer-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
$redeemer       = wp_create_user( 'privacy-redeemer-' . wp_generate_password( 6, false, false ), wp_generate_password(), $redeemer_email );
$redemption_tbl = VCAP\Install::tables()['redemptions'];
for ( $i = 0; $i < 501; $i++ ) {
	$wpdb->insert( $redemption_tbl, array( 'redemption_key' => 'privacy-' . $redeemer . '-' . $i, 'voucher_id' => 999999, 'user_id' => $redeemer, 'target' => 'test', 'amount' => 1, 'state' => 'committed', 'request_hash' => str_repeat( 'a', 64 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
}
$red_first  = $privacy->export( $redeemer_email, 1 );
$red_second = $privacy->export( $redeemer_email, 2 );
vcap_security_assert( 500 === count( $red_first['data'] ) && ! $red_first['done'] && 1 === count( $red_second['data'] ) && $red_second['done'], 'the export pages through all 501 external redemptions (no own vouchers)' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$redemption_tbl} WHERE user_id = %d", $redeemer ) );

// Erasing the only allowed address must not turn a personal voucher into one anybody can use.
$personal_email = 'privacy-personal-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
$personal       = vcap_security_create_voucher( $personal_email, 900200 );
$personal_wc    = new WC_Coupon();
$personal_wc->set_code( $personal->code );
$personal_wc->set_discount_type( 'fixed_cart' );
$personal_wc->set_amount( 10 );
$personal_wc->set_email_restrictions( array( $personal_email ) );
$personal_wc->save();
$personal->coupon_id = $personal_wc->get_id();
vcap()->vouchers->update( $personal );
$privacy->erase( $personal_email, 1 );
$left = ( new WC_Coupon( $personal_wc->get_id() ) )->get_email_restrictions();
vcap_security_assert( $left && ! in_array( $personal_email, $left, true ), 'erasing the only allowed address keeps the personal voucher restricted (no open coupon)' );

// Retention clears voucher fields and audit free text in one transaction.
$retention_voucher = vcap_security_create_voucher( 'retention@example.test', 900001 );
vcap()->vouchers->log( $retention_voucher->id, 'delivery_failed', null, null, 'Transport failed for retention@example.test' );
$old_date = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'status' => 'expired', 'expires_at' => $old_date, 'updated_at' => $old_date ), array( 'id' => $retention_voucher->id ) );
$anonymised = vcap()->vouchers->anonymise_older_than( 30 );
$after_retention = vcap()->vouchers->get( $retention_voucher->id );
$retention_note  = $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM ' . VCAP\Install::tables()['events'] . ' WHERE voucher_id = %d ORDER BY id DESC LIMIT 1', $retention_voucher->id ) );
vcap_security_assert( $anonymised > 0 && '' === $after_retention->buyer_email && array() === $after_retention->fields, 'retention anonymises expired voucher personalisation' );
vcap_security_assert( '[personal data removed by retention]' === $retention_note, 'retention redacts free-text audit notes without deleting the audit trail' );

// Scanner routes must reject unauthenticated lookups and mutations.
wp_set_current_user( 0 );
$server = rest_get_server();
do_action( 'rest_api_init', $server );
foreach ( array( array( 'GET', '/vcap/v1/lookup' ), array( 'POST', '/vcap/v1/checkin' ), array( 'POST', '/vcap/v1/redeem' ) ) as $route ) {
	$request = new WP_REST_Request( $route[0], $route[1] );
	$request->set_param( 'q', 'not-a-real-code' );
	$response = rest_do_request( $request );
	vcap_security_assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'unauthenticated scanner route ' . $route[1] . ' is denied' );
}

// Counter redemption with an idempotency key: a retry after a timeout replays the result, never deducts twice.
$scan_card = vcap_security_create_voucher( 'scan-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 930000 + random_int( 1, 99999 ) );
$scan_req  = static function ( float $amount, string $key ) use ( $scan_card ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
	$req->set_param( 'q', $scan_card->code );
	$req->set_param( 'amount', $amount );
	$req->set_param( 'idempotency_key', $key );
	$res = vcap()->tickets->rest_redeem( $req );
	return is_wp_error( $res ) ? $res : $res->get_data();
};
$scan_key = wp_generate_uuid4();
$first    = $scan_req( 5, $scan_key );
$again    = $scan_req( 5, $scan_key );
vcap_security_assert( is_array( $first ) && ! empty( $first['success'] ) && is_array( $again ) && ! empty( $again['replayed'] ) && 20.0 === (float) vcap()->vouchers->get( $scan_card->id )->balance, 'a repeated counter redemption with the same key is answered from the first one (deducted once)' );
$misused = $scan_req( 7, $scan_key );
vcap_security_assert( is_wp_error( $misused ) && 'vcap_idempotency_conflict' === $misused->get_error_code(), 'a key reused for another amount is refused' );
$second = $scan_req( 5, wp_generate_uuid4() );
vcap_security_assert( is_array( $second ) && ! empty( $second['success'] ) && 15.0 === (float) vcap()->vouchers->get( $scan_card->id )->balance, 'a new key deducts again' );
vcap_security_assert( 5.0 === (float) $second['booked'] && 15.0 === (float) $second['balance_after'] && empty( $second['partial'] ) && str_contains( (string) $second['message'], (string) $second['booked_html'] ), 'the answer names the amount actually booked and the balance after it' );

// Counter amount above the balance: refused without booking anything; only allow_partial books the remainder.
$scan_with    = static function ( VCAP\Voucher $card, array $params ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
	$req->set_param( 'q', $card->code );
	$req->set_param( 'idempotency_key', wp_generate_uuid4() );
	foreach ( $params as $name => $value ) {
		$req->set_param( $name, $value );
	}
	$res = vcap()->tickets->rest_redeem( $req );
	return is_wp_error( $res ) ? $res : $res->get_data();
};
$events_table = VCAP\Install::tables()['events'];
$event_count  = static fn( int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE voucher_id = %d", $id ) ); // phpcs:ignore
$events_before = $event_count( $scan_card->id );
$over          = $scan_with( $scan_card, array( 'amount' => 20 ) );
vcap_security_assert(
	is_array( $over ) && false === $over['success'] && 'exceeds_balance' === $over['reason'] && 0.0 === (float) $over['booked'] && 15.0 === (float) $over['balance_after']
	&& 15.0 === (float) vcap()->vouchers->get( $scan_card->id )->balance && $events_before === $event_count( $scan_card->id ),
	'amount > balance is refused, nothing booked (no silent booking of the remaining balance)'
);
vcap_security_assert( str_contains( (string) $over['message'], (string) $over['balance_html'] ) && str_contains( (string) $over['message'], (string) $over['requested_html'] ), 'the refusal names the entered amount and the remaining balance' );
$atomic_reason = '';
$atomic        = vcap()->coupons->deduct_balance_atomic( vcap()->vouchers->get( $scan_card->id ), 20, 'over balance', 0, false, $atomic_reason );
vcap_security_assert( null === $atomic && 'exceeds_balance' === $atomic_reason && 15.0 === (float) vcap()->vouchers->get( $scan_card->id )->balance, 'the row-locked deduction refuses amount > balance by itself when partial bookings are not allowed' );
$precise = $scan_with( $scan_card, array( 'amount' => 1.234 ) );
$zero    = $scan_with( $scan_card, array( 'amount' => 0 ) );
vcap_security_assert( is_wp_error( $precise ) && 'vcap_amount_precision' === $precise->get_error_code() && is_wp_error( $zero ) && 'vcap_amount' === $zero->get_error_code() && 15.0 === (float) vcap()->vouchers->get( $scan_card->id )->balance, 'implausible amounts (more decimals than the currency, zero) are refused without booking' );
$partial = $scan_with( $scan_card, array( 'amount' => 20, 'allow_partial' => true ) );
$after   = vcap()->vouchers->get( $scan_card->id );
vcap_security_assert(
	is_array( $partial ) && true === $partial['success'] && true === $partial['partial'] && 15.0 === (float) $partial['booked'] && 0.0 === (float) $partial['balance_after'] && 5.0 === (float) $partial['uncovered']
	&& 'warning' === $partial['state'] && 0.0 === (float) $after->balance && VCAP\Voucher::STATUS_REDEEMED === $after->status,
	'allow_partial books the remaining balance and says so (booked, still to be paid)'
);
$used_up = $scan_with( $scan_card, array( 'amount' => 1 ) );
vcap_security_assert( is_array( $used_up ) && false === $used_up['success'] && 'redeemed' === $used_up['reason'], 'a used-up card is refused as used up, not as "amount exceeds the balance"' );

// Cancelling a counter redemption (wrong card): only with a reason, by the cashier shortly after or a manager;
// the amount goes back, the card is active again and the same booking cannot be cancelled twice.
$cashier = wp_insert_user( array( 'user_login' => 'vcap-till-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
( new WP_User( $cashier ) )->add_cap( 'vcap_scan_tickets' );
wp_set_current_user( $cashier );
$rev_card   = vcap_security_create_voucher( 'reverse-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 970000 + random_int( 1, 9999 ) );
$rev_booked = $scan_with( $rev_card, array( 'amount' => 25, 'receipt' => 'R-4711', 'till' => 'Till 2' ) );
$rev_event  = (int) ( $rev_booked['event_id'] ?? 0 );
$no_reason  = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $rev_card->id ), $rev_event, ' ' );
$reversed   = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $rev_card->id ), $rev_event, 'wrong card' );
$twice      = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $rev_card->id ), $rev_event, 'again' );
$rev_after  = vcap()->vouchers->get( $rev_card->id );
vcap_security_assert( $rev_event > 0 && is_wp_error( $no_reason ) && 25.0 === $reversed && is_wp_error( $twice ) && 'vcap_reversal_reversed' === $twice->get_error_code() && 25.0 === (float) $rev_after->balance && VCAP\Voucher::STATUS_ACTIVE === $rev_after->status, 'a counter redemption is cancelled once, with a reason, and the amount booked back' );
$rev_notes = $wpdb->get_col( $wpdb->prepare( "SELECT note FROM {$events_table} WHERE voucher_id = %d AND event IN ('redeemed', 'redeem_reversed') ORDER BY id", $rev_card->id ) ); // phpcs:ignore
vcap_security_assert( 2 === count( $rev_notes ) && str_contains( (string) $rev_notes[0], 'R-4711' ) && str_contains( (string) $rev_notes[0], 'Till 2' ) && str_contains( (string) $rev_notes[1], '#' . $rev_event ) && str_contains( (string) $rev_notes[1], 'wrong card' ), 'the redemption keeps receipt and till, the cancellation names the redemption and the reason' );
$other = wp_insert_user( array( 'user_login' => 'vcap-till2-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
( new WP_User( $other ) )->add_cap( 'vcap_scan_tickets' );
$rev_second = $scan_with( $rev_card, array( 'amount' => 5 ) );
wp_set_current_user( $other );
$foreign = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $rev_card->id ), (int) $rev_second['event_id'], 'not mine' );
vcap_security_assert( is_wp_error( $foreign ) && 'vcap_reversal_forbidden' === $foreign->get_error_code(), 'another cashier cannot cancel someone else\'s redemption' );
wp_set_current_user( 0 );

// Refusal reasons are told apart (cancelled, not yet delivered, reserved) instead of one blanket message.
$reason_card = static function ( string $status, int $index ) use ( $wpdb ) {
	$card = vcap_security_create_voucher( 'reason-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', $index );
	$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'status' => $status ), array( 'id' => $card->id ) ); // Status only, no lifecycle hooks.
	return vcap()->vouchers->get( $card->id );
};
$void_card      = $reason_card( VCAP\Voucher::STATUS_VOID, 940000 + random_int( 1, 9999 ) );
$scheduled_card = $reason_card( VCAP\Voucher::STATUS_SCHEDULED, 950000 + random_int( 1, 9999 ) );
$reserved_card  = $reason_card( VCAP\Voucher::STATUS_RESERVED, 960000 + random_int( 1, 9999 ) );
$void_answer      = $scan_with( $void_card, array( 'amount' => 5 ) );
$scheduled_answer = $scan_with( $scheduled_card, array( 'amount' => 5 ) );
vcap_security_assert( is_array( $void_answer ) && false === $void_answer['success'] && 'void' === $void_answer['reason'] && 25.0 === (float) vcap()->vouchers->get( $void_card->id )->balance, 'a cancelled card is refused as cancelled' );
vcap_security_assert( is_array( $scheduled_answer ) && false === $scheduled_answer['success'] && 'scheduled' === $scheduled_answer['reason'] && 25.0 === (float) vcap()->vouchers->get( $scheduled_card->id )->balance, 'a card not delivered yet is not redeemable at the counter' );
$reasons = array();
foreach ( array( $void_card, $reserved_card ) as $card ) {
	$why = '';
	vcap()->coupons->deduct_balance_atomic( $card, 5, 'reason test', 0, false, $why );
	$reasons[] = $why;
}
vcap_security_assert( array( 'void', 'reserved' ) === $reasons && VCAP\Tickets::refusal_message( 'void' ) !== VCAP\Tickets::refusal_message( 'reserved' ), 'the deduction reports why nothing was booked (cancelled, reserved)' );

// Per-visitor/public balance lookup throttling is enforced before a code lookup.
$bucket = 'security-test-' . strtolower( wp_generate_password( 8, false, false ) );
$allowed = true;
for ( $i = 0; $i < 15; $i++ ) {
	$allowed = $allowed && ! VCAP\Plugin::rate_limited( $bucket, 15, 300, HOUR_IN_SECONDS );
}
$blocked = VCAP\Plugin::rate_limited( $bucket, 15, 300, HOUR_IN_SECONDS );
vcap_security_assert( $allowed && $blocked, 'public balance lookup rate limit rejects the request after the visitor quota' );
// Lookup mode: each attempt counts before the lookup (atomic); existing codes give it back, so only failed
// attempts use up the quota (fixed window). More cases in tests/rate-limit-integration.php.
$fail_bucket = 'security-fail-' . strtolower( wp_generate_password( 8, false, false ) );
$never = true;
for ( $i = 0; $i < 40; $i++ ) {
	$never = $never && ! VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false );
	VCAP\Plugin::rate_limit_success( $fail_bucket, HOUR_IN_SECONDS );
}
for ( $i = 0; $i < 5; $i++ ) {
	VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false );
	VCAP\Plugin::rate_limit_failure( $fail_bucket, HOUR_IN_SECONDS );
}
vcap_security_assert( $never && VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false ), 'valid lookups are never throttled; repeated failed codes are' );
$spoof = static fn() => '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . random_int( 1, 254 );
vcap_security_assert( VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false ), 'a forged X-Forwarded-For header does not reset the visitor counter' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
add_filter( 'vcap_client_ip', $spoof );
vcap_security_assert( ! VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false ), 'behind a trusted proxy the real client address can be supplied (vcap_client_ip)' );
remove_filter( 'vcap_client_ip', $spoof );

// Signed guest downloads bind the voucher token and expiry to the site secret.
$download_voucher = new VCAP\Voucher();
$download_voucher->token = VCAP\VoucherRepository::new_token();
$url = vcap()->downloads->signed_url( $download_voucher );
wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
$valid_link = new ReflectionMethod( VCAP\Downloads::class, 'valid_guest_link' );
$signer     = new ReflectionMethod( VCAP\Downloads::class, 'sign' );
$expired_at = time() - 1;
$expired_sig = $signer->invoke( vcap()->downloads, $query['vcap_pdf'], $expired_at );
vcap_security_assert( $valid_link->invoke( vcap()->downloads, $query['vcap_pdf'], (int) $query['exp'], $query['sig'] ) && (int) $query['exp'] > time(), 'guest PDF URL signs the token and expiry' );
vcap_security_assert( ! $valid_link->invoke( vcap()->downloads, $query['vcap_pdf'], $expired_at, $expired_sig ), 'expired guest PDF URLs are rejected' );
vcap_security_assert( ! $valid_link->invoke( vcap()->downloads, $query['vcap_pdf'], (int) $query['exp'], str_repeat( '0', 64 ) ), 'invalid guest PDF signatures are rejected' );

// Versioned PHP facade: stable DTOs, order-scoped idempotency and stable error codes.
$api_voucher = vcap_security_create_voucher( 'api@example.test', 900002 );
$order       = wc_create_order();
$order->set_status( 'pending' );
$order->save();
$lookup = vcap()->api_v1->lookup( $api_voucher->code );
vcap_security_assert( is_array( $lookup ) && 25.0 === (float) $lookup['balance'] && ! isset( $lookup['buyer_email'] ), 'public voucher service returns a safe lookup DTO' );
$reservation_one = vcap()->api_v1->reserve( $api_voucher->code, 10.0, $order );
$reservation_two = vcap()->api_v1->reserve( $api_voucher->code, 10.0, $order );
vcap_security_assert( is_array( $reservation_one ) && is_array( $reservation_two ) && 15.0 === (float) vcap()->vouchers->get( $api_voucher->id )->balance && $reservation_one['idempotency_key'] === $reservation_two['idempotency_key'], 'reserve retries for one order do not deduct twice' );
$commit_one = vcap()->api_v1->commit( $api_voucher->code, $order );
$commit_two = vcap()->api_v1->commit( $api_voucher->code, $order );
vcap_security_assert( is_array( $commit_one ) && is_array( $commit_two ) && 15.0 === (float) vcap()->vouchers->get( $api_voucher->id )->balance, 'commit retries are idempotent and preserve the single deduction' );
$missing = vcap()->api_v1->lookup( 'definitely-not-a-voucher' );
vcap_security_assert( is_wp_error( $missing ) && 'vcap_not_found' === $missing->get_error_code(), 'unknown vouchers return a stable error code' );

// Admin write handlers are expected to verify both a nonce and a capability.
$admin_checks = array(
	array( VCAP\Admin\Import::class, 'handle', 'vcap_import' ),
	array( VCAP\Admin\Import::class, 'delete_batch', 'vcap_delete_batch' ),
	array( VCAP\Admin\VoucherAdmin::class, 'handle_action', 'vcap_voucher_' ),
	array( VCAP\Admin\VoucherAdmin::class, 'handle_bulk', 'vcap_bulk' ),
	array( VCAP\Admin\VoucherAdmin::class, 'export', 'vcap_export' ),
	array( VCAP\Downloads::class, 'admin_download', 'vcap_admin_pdf_' ),
);
foreach ( $admin_checks as $check ) {
	$source = vcap_security_method_source( $check[0], $check[1] );
	vcap_security_assert( str_contains( $source, 'check_admin_referer' ) && str_contains( $source, $check[2] ), $check[0] . '::' . $check[1] . ' verifies its nonce' );
	vcap_security_assert( str_contains( $source, 'current_user_can' ) || str_contains( $source, '$this->can()' ), $check[0] . '::' . $check[1] . ' checks capabilities' );
}

// Scanner privacy: door staff (scan right only) see neither the name nor order number or original value by default.
$scan_voucher = vcap_security_create_voucher( 'scanner-privacy@example.com', 900 );
$door_user    = wp_insert_user( array( 'user_login' => 'vcap-door-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
( new WP_User( $door_user ) )->add_cap( 'vcap_scan_tickets' );
wp_set_current_user( $door_user );
$door_view = vcap()->tickets->describe( vcap()->vouchers->get( $scan_voucher->id ) );
vcap_security_assert( '' === $door_view['holder'] && '' === $door_view['order'] && '' === $door_view['admin_url'] && '' !== $door_view['value_html'] && '' !== $door_view['balance_html'], 'the scanner hides name, order and admin link from door staff by default (value and balance stay for redemption)' );
update_option( 'vcap_scanner_show_holder', 'yes' );
vcap_security_assert( 'Privacy Test Recipient' === vcap()->tickets->describe( vcap()->vouchers->get( $scan_voucher->id ) )['holder'], 'the holder name is shown when enabled' );
delete_option( 'vcap_scanner_show_holder' );
wp_set_current_user( 0 );

// Order details hook (also used by the order tracking page): full codes and PDF links only for the order's customer.
$owner_id    = wp_insert_user( array( 'user_login' => 'vcap-owner-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
$stranger_id = wp_insert_user( array( 'user_login' => 'vcap-stranger-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
$tracked     = wc_create_order( array( 'customer_id' => $owner_id ) );
$tracked->set_billing_email( 'tracked@example.test' );
$tracked->set_status( 'completed' );
$tracked->save();
$tracked_voucher           = vcap_security_create_voucher( 'tracked@example.test', 900300 + random_int( 1, 9999 ) );
$tracked_voucher->order_id = $tracked->get_id();
vcap()->vouchers->update( $tracked_voucher );
$render_order = static function () use ( $tracked ): string {
	ob_start();
	vcap()->downloads->order_downloads( wc_get_order( $tracked->get_id() ) );
	return (string) ob_get_clean();
};
wp_set_current_user( 0 );
$guest_html = $render_order();
vcap_security_assert( ! str_contains( $guest_html, $tracked_voucher->code ) && ! str_contains( $guest_html, 'vcap_pdf=' ) && str_contains( $guest_html, VCAP\Downloads::masked_code( $tracked_voucher->code ) ), 'order tracking (guest) shows only masked codes without PDF links' );
wp_set_current_user( $stranger_id );
$stranger_html = $render_order();
vcap_security_assert( ! str_contains( $stranger_html, $tracked_voucher->code ) && ! str_contains( $stranger_html, 'vcap_pdf=' ), 'another logged-in customer sees only masked codes' );
wp_set_current_user( $owner_id );
$owner_html = $render_order();
vcap_security_assert( str_contains( $owner_html, $tracked_voucher->code ) && str_contains( $owner_html, 'vcap_pdf=' ), 'the order\'s customer sees the codes and PDF links' );
wp_set_current_user( 0 );

// Failed-mail log (Site Health): only Voucher Captain mails, no personal data, pruned, included in the erasure.
delete_option( VCAP\Health::MAIL_LOG );
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.', array( 'to' => array( 'someone@example.test' ), 'subject' => 'Password reset' ) ) );
vcap_security_assert( array() === VCAP\Health::mail_failures(), 'failed mails of other plugins are not recorded' );
VCAP\Health::mail_begin( 'Voucher to recipient' );
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: The following recipients failed: jane.roe@example.test', array( 'to' => array( 'jane.roe@example.test' ), 'subject' => 'Jane Roe sends you a voucher' ) ) );
VCAP\Health::mail_end();
$mail_log = VCAP\Health::mail_failures();
$mail_raw = wp_json_encode( get_option( VCAP\Health::MAIL_LOG ) );
vcap_security_assert( 1 === count( $mail_log ) && ! str_contains( $mail_raw, 'jane.roe' ) && ! str_contains( $mail_raw, 'Jane Roe' ) && str_contains( $mail_log[0]['error'], 'j***@example.test' ) && 'Voucher to recipient' === $mail_log[0]['email'], 'a failed voucher mail is recorded without addresses (also in the error text) or the subject' );
update_option( VCAP\Health::MAIL_LOG, array_merge( $mail_log, array( array( 'time' => time() - 8 * DAY_IN_SECONDS, 'to' => 'o***@example.test', 'error' => 'old' ) ) ), false );
VCAP\Health::prune_mail_failures();
vcap_security_assert( 1 === count( (array) get_option( VCAP\Health::MAIL_LOG ) ), 'entries older than 7 days are removed by the daily maintenance' );
$privacy->erase( 'jane.roe@example.test', 1 );
vcap_security_assert( array() === VCAP\Health::mail_failures(), 'the privacy eraser removes the failed-mail entries of the address' );
delete_option( VCAP\Health::MAIL_LOG );

/** A single-use voucher with its WooCommerce coupon (usage limit 1). */
function vcap_security_single( float $value = 20.0, string $type = 'voucher' ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-sec-single-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 1 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 93000000 + $coupon->get_id();
	$v->type           = $type;
	$v->usage_mode     = 'single';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}
$sec_admin = (int) wp_insert_user( array( 'user_login' => 'vcap-sec-admin-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'shop_manager' ) );

// U10: "Mark as redeemed (store)" books a single-use voucher like the scanner (history, journal, coupon used up).
wp_set_current_user( $sec_admin );
$u10_admin   = new VCAP\Admin\VoucherAdmin( vcap()->vouchers );
$u10_voucher = vcap_security_single();
$u10_result  = $u10_admin->redeem_store( vcap()->vouchers->get( $u10_voucher->id ) );
$u10_after   = vcap()->vouchers->get( $u10_voucher->id );
$u10_coupon  = new WC_Coupon( $u10_voucher->coupon_id );
$u10_event   = VCAP\Tickets::last_event( vcap()->vouchers->events( $u10_voucher->id ), array( 'redeemed' ) );
vcap_security_assert( 'redeemed_store' === $u10_result && VCAP\Voucher::STATUS_REDEEMED === $u10_after->status && 1 === $u10_coupon->get_usage_count() && 1 === $u10_coupon->get_usage_limit(), 'U10: "Mark as redeemed (store)" redeems a single-use voucher and uses up its coupon' );
vcap_security_assert( $u10_event && 20.0 === (float) $u10_event['amount'] && $sec_admin === (int) $u10_event['user_id'] && in_array( 'redeemed', VCAP\Ledger::MONEY_EVENTS, true ), 'U10: the store redemption is a "redeemed" event with the value, by the admin (redemption journal)' );
vcap_security_assert( 'redeem_failed' === $u10_admin->redeem_store( vcap()->vouchers->get( $u10_voucher->id ) ), 'U10: a redeemed voucher cannot be marked again' );
$u10_credit = vcap_security_single( 20.0, 'credit' );
$u10_admin->redeem_store( vcap()->vouchers->get( $u10_credit->id ) );
vcap_security_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $u10_credit->id )->status, 'U10: the counter refusal rules apply (store credit is not marked as redeemed)' );

// F11: every row checkbox of the voucher list has a hidden label naming the voucher (like the core lists).
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
set_current_screen( 'toplevel_page_vcap-vouchers' );
$f11_table = new VCAP\Admin\VouchersListTable( vcap()->vouchers );
$f11_cb    = new ReflectionMethod( $f11_table, 'column_cb' );
$f11_cb->setAccessible( true );
$f11_html = (string) $f11_cb->invoke( $f11_table, $u10_after );
vcap_security_assert( (bool) preg_match( '/^<label class="screen-reader-text" for="cb-select-' . $u10_after->id . '">Select voucher ' . preg_quote( $u10_after->code, '/' ) . '<\/label><input type="checkbox" id="cb-select-' . $u10_after->id . '" name="voucher_ids\[\]"/', $f11_html ), 'F11: the row checkbox has a screen-reader label "Select voucher CODE" linked by id' );

// O3: a counter redemption of a single-use voucher is cancelled: active again, coupon counter back to 0 (limit 1).
$o3_cashier = (int) wp_insert_user( array( 'user_login' => 'vcap-o3-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => VCAP\Install::CASHIER_ROLE ) );
wp_set_current_user( $o3_cashier );
$o3_voucher = vcap_security_single();
$o3_booked  = $scan_with( $o3_voucher, array( 'receipt' => 'R-O3' ) );
$o3_coupon  = new WC_Coupon( $o3_voucher->coupon_id );
vcap_security_assert( is_array( $o3_booked ) && ! empty( $o3_booked['success'] ) && VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $o3_voucher->id )->status && 1 === $o3_coupon->get_usage_count(), 'O3: the cashier redeems the single-use voucher at the counter' );
$o3_reversed = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $o3_voucher->id ), (int) $o3_booked['event_id'], 'wrong voucher scanned' );
$o3_after    = vcap()->vouchers->get( $o3_voucher->id );
$o3_coupon   = new WC_Coupon( $o3_voucher->coupon_id );
vcap_security_assert( 20.0 === $o3_reversed && VCAP\Voucher::STATUS_ACTIVE === $o3_after->status && 20.0 === (float) $o3_after->balance && 0 === $o3_coupon->get_usage_count() && 1 === $o3_coupon->get_usage_limit(), 'O3: cancelling it makes the voucher active again and puts the coupon counter back (0 of 1)' );
vcap_security_assert( true === vcap()->coupons->validate( true, $o3_coupon ) && in_array( 'redeem_reversed', array_column( vcap()->vouchers->events( $o3_voucher->id ), 'event' ), true ), 'O3: the shop accepts the code again; the cancellation is a redeem_reversed event' );

// O5: role "Voucher cashier" – scanner only, admitted to wp-admin; raising balances and refunds need own rights.
$o5_role = get_role( VCAP\Install::CASHIER_ROLE );
vcap_security_assert( $o5_role && ! empty( $o5_role->capabilities['vcap_scan_tickets'] ) && ! empty( $o5_role->capabilities['read'] ) && empty( $o5_role->capabilities['manage_woocommerce'] ) && empty( $o5_role->capabilities['edit_shop_orders'] ) && empty( $o5_role->capabilities['vcap_manage_vouchers'] ), 'O5: the role "Voucher cashier" has the scanner right only (no orders, customers or voucher management)' );
vcap_security_assert( false === VCAP\Tickets::allow_scanner_admin( true ) && ! VCAP\Tickets::is_manager(), 'O5: WooCommerce does not send the cashier away from wp-admin (scanner page); no manager view' );
$o5_view = vcap()->tickets->describe( vcap()->vouchers->get( $o3_voucher->id ) );
vcap_security_assert( '' !== $o5_view['value_html'] && '' === $o5_view['admin_url'] && '' === $o5_view['order'], 'O5: the cashier sees the value of a single-use voucher, not the admin link or order' );
$o5_manager = (int) wp_insert_user( array( 'user_login' => 'vcap-o5-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
( new WP_User( $o5_manager ) )->add_cap( 'vcap_manage_vouchers' );
wp_set_current_user( $o5_manager );
$o5_card = vcap_security_create_voucher( 'o5-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 980000 + random_int( 1, 9999 ) );
$_POST   = array( 'amount' => '10', 'mode' => 'add', 'note' => 'O5' );
$u10_admin->adjust( vcap()->vouchers->get( $o5_card->id ) );
$o5_after_add = (float) vcap()->vouchers->get( $o5_card->id )->balance;
$_POST        = array( 'amount' => '5', 'mode' => 'subtract', 'note' => 'O5' );
$u10_admin->adjust( vcap()->vouchers->get( $o5_card->id ) );
$_POST = array();
vcap_security_assert( ! VCAP\Admin\VoucherAdmin::can_increase() && 25.0 === $o5_after_add && 20.0 === (float) vcap()->vouchers->get( $o5_card->id )->balance, 'O5: without vcap_increase_balance a voucher manager may lower a remaining value but not raise it' );
( new WP_User( $o5_manager ) )->add_cap( 'vcap_increase_balance' );
wp_set_current_user( 0 );
wp_set_current_user( $o5_manager );
$_POST = array( 'amount' => '3', 'mode' => 'add', 'note' => 'O5' );
$u10_admin->adjust( vcap()->vouchers->get( $o5_card->id ) );
$_POST = array();
vcap_security_assert( VCAP\Admin\VoucherAdmin::can_increase() && 23.0 === (float) vcap()->vouchers->get( $o5_card->id )->balance, 'O5: with vcap_increase_balance the remaining value can be raised (up to the face value)' );
$o5_refund = new ReflectionMethod( VCAP\Admin\StoreCreditAdmin::class, 'can_refund' );
$o5_refund->setAccessible( true );
$o5_credit_admin = new VCAP\Admin\StoreCreditAdmin( vcap()->store_credit, vcap()->vouchers );
$o5_without      = $o5_refund->invoke( $o5_credit_admin );
( new WP_User( $o5_manager ) )->add_cap( 'edit_shop_orders' );
wp_set_current_user( 0 );
wp_set_current_user( $o5_manager );
$o5_with = $o5_refund->invoke( $o5_credit_admin );
vcap_security_assert( false === $o5_without && true === $o5_with, 'O5: "Refund as store credit" needs edit_shop_orders besides the voucher right' );
wp_set_current_user( 0 );

// O6: an idempotency claim left without an answer (PHP stopped after or before booking) is resolved from the history.
wp_set_current_user( $o3_cashier );
$o6_card  = vcap_security_create_voucher( 'o6-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 990000 + random_int( 1, 9999 ) );
$o6_mark  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$events_table} WHERE voucher_id = %d", $o6_card->id ) ); // phpcs:ignore
$o6_claim = static function ( string $key, float $amount ) use ( $wpdb, $o6_card, $events_table ) {
	$o6_mark = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$events_table} WHERE voucher_id = %d", $o6_card->id ) ); // phpcs:ignore -- newest event when the claim was taken.
	$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( $key ), 'option_value' => ( time() - 300 ) . '|' . md5( 'redeem|' . $o6_card->id . '|' . round( $amount, 4 ) ) . '#' . $o6_mark . '|', 'autoload' => 'no' ) );
};
$o6_redeem = static function ( string $key, float $amount ) use ( $o6_card ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
	$req->set_param( 'q', $o6_card->code );
	$req->set_param( 'amount', $amount );
	$req->set_param( 'idempotency_key', $key );
	$res = vcap()->tickets->rest_redeem( $req );
	return is_wp_error( $res ) ? $res : $res->get_data();
};
// Booked, but the answer was never stored: the repeat finds the booking and does not deduct again.
$o6_key_booked = wp_generate_uuid4();
$o6_claim( $o6_key_booked, 5 );
vcap()->coupons->adjust_balance( vcap()->vouchers->get( $o6_card->id ), -5, 'redeemed', 'booked before the request died' );
$o6_again = $o6_redeem( $o6_key_booked, 5 );
vcap_security_assert( is_array( $o6_again ) && ! empty( $o6_again['recovered'] ) && ! empty( $o6_again['success'] ) && 20.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance, 'O6: an abandoned claim whose booking is in the history is answered as booked (no second deduction)' );
// Nothing booked: the repeat takes the abandoned claim over and books once.
$o6_key_lost = wp_generate_uuid4();
$o6_claim( $o6_key_lost, 4 );
$o6_retry = $o6_redeem( $o6_key_lost, 4 );
vcap_security_assert( is_array( $o6_retry ) && ! empty( $o6_retry['success'] ) && empty( $o6_retry['recovered'] ) && 16.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance, 'O6: an abandoned claim without a booking is taken over and the redemption is booked once' );
// A fresh claim (another request still running) is answered with 409, not a red error.
$o6_key_busy = wp_generate_uuid4();
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( $o6_key_busy ), 'option_value' => time() . '|' . md5( 'redeem|' . $o6_card->id . '|3' ) . '#' . $o6_mark . '|', 'autoload' => 'no' ) );
$o6_busy = $o6_redeem( $o6_key_busy, 3 );
vcap_security_assert( is_wp_error( $o6_busy ) && 'vcap_in_progress' === $o6_busy->get_error_code() && 409 === ( $o6_busy->get_error_data()['status'] ?? 0 ) && 16.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance, 'O6: a claim still in progress is answered with 409 and books nothing' );
wp_set_current_user( 0 );

// O8: the lookup applies the counter refusal rules – no green "Valid" for what the redemption would refuse.
wp_set_current_user( $o3_cashier );
$o8_credit = vcap()->tickets->describe( vcap()->vouchers->get( $u10_credit->id ) );
vcap_security_assert( 'warning' === $o8_credit['state'] && false === ( $o8_credit['redeemable'] ?? null ) && 'Store credit can only be used in the shop.' === $o8_credit['message'], 'O8: store credit is shown as not redeemable at the counter, with the reason' );
$o8_shop_only = vcap_security_single();
$o8_channel   = static fn( $allowed, $voucher, $channel ) => ( (int) $voucher->id === (int) $o8_shop_only->id && VCAP\Channels::COUNTER === $channel ) ? false : $allowed;
add_filter( 'vcap_redemption_channel_allowed', $o8_channel, 10, 3 );
$o8_view = vcap()->tickets->describe( vcap()->vouchers->get( $o8_shop_only->id ) );
remove_filter( 'vcap_redemption_channel_allowed', $o8_channel, 10 );
vcap_security_assert( 'warning' === $o8_view['state'] && false === ( $o8_view['redeemable'] ?? null ) && 'This voucher cannot be redeemed at the counter.' === $o8_view['message'], 'O8: a voucher restricted to other channels is not shown as valid at the counter' );
$o8_pending_voucher = vcap_security_single();
// Checkout holds the coupon for the unpaid order (WooCommerce's tentative usage) until it is paid or released.
$o8_hold = add_post_meta( $o8_pending_voucher->coupon_id, '_coupon_held_' . ( time() + 600 ) . '_o8test', '' ); // As check_and_hold_coupon() stores it.
$o8_held = vcap()->tickets->describe( vcap()->vouchers->get( $o8_pending_voucher->id ) );
vcap_security_assert( $o8_hold && VCAP\CouponManager::held_by_pending_order( vcap()->vouchers->get( $o8_pending_voucher->id ) ) && 'warning' === $o8_held['state'] && false === ( $o8_held['redeemable'] ?? null ), 'O8: a single-use voucher held by an unpaid shop order (checkout) is shown as not redeemable' );
$o8_plain = vcap()->tickets->describe( vcap()->vouchers->get( vcap_security_single()->id ) );
vcap_security_assert( 'ok' === $o8_plain['state'] && ! array_key_exists( 'redeemable', $o8_plain ), 'O8: an unrestricted voucher stays "Valid"' );
wp_set_current_user( 0 );
// Q11: arrays on the public ticket redeem form are refused, not a TypeError (fatal error, recovery-mode mail).
$q11_voucher             = vcap_security_create_voucher( 'q11-ticket@example.test', 990000 + random_int( 1, 9999 ) );
$q11_voucher->usage_mode = 'single';
vcap()->vouchers->update_columns( $q11_voucher, array( 'usage_mode' ) );
$q11_target = static fn( $target, $v ) => (int) $v->id === (int) $q11_voucher->id ? array( 'ticket_type_id' => 1, 'quantity' => 1 ) : $target;
add_filter( 'vcap_passcaptain_ticket_target', $q11_target, 10, 2 );
$q11_user = get_current_user_id();
wp_set_current_user( 0 );
foreach ( array( array( 'vcap_ticket_email' => array( 'x' ) ), array( 'vcap_ticket_reset' => array( 'date' ) ) ) as $q11_post ) {
	$_POST = $q11_post;
	$q11_error = '';
	try {
		$q11_step = vcap()->passcaptain_tickets->redeem_page( null, $q11_voucher->code );
	} catch ( \TypeError $e ) {
		$q11_error = $e->getMessage();
		$q11_step  = null;
	}
	$_POST = array();
	vcap_security_assert( '' === $q11_error && 'choose' === $q11_step, 'Q11: an array posted as "' . key( $q11_post ) . '" on the public ticket redeem form is refused instead of a fatal error' . ( '' !== $q11_error ? ' (' . $q11_error . ')' : '' ) );
}
if ( function_exists( 'wc_clear_notices' ) && function_exists( 'WC' ) && WC()->session ) {
	wc_clear_notices();
}
wp_set_current_user( $q11_user );
remove_filter( 'vcap_passcaptain_ticket_target', $q11_target, 10 );

echo "Voucher Captain security and privacy checks passed.\n";
