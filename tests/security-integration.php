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

// Per-visitor/public balance lookup throttling is enforced before a code lookup.
$bucket = 'security-test-' . strtolower( wp_generate_password( 8, false, false ) );
$allowed = true;
for ( $i = 0; $i < 15; $i++ ) {
	$allowed = $allowed && ! VCAP\Plugin::rate_limited( $bucket, 15, 300, HOUR_IN_SECONDS );
}
$blocked = VCAP\Plugin::rate_limited( $bucket, 15, 300, HOUR_IN_SECONDS );
vcap_security_assert( $allowed && $blocked, 'public balance lookup rate limit rejects the request after the visitor quota' );
// Failure-only mode: checking alone never locks anyone out; only failed attempts count (atomic, fixed window).
$fail_bucket = 'security-fail-' . strtolower( wp_generate_password( 8, false, false ) );
$never = true;
for ( $i = 0; $i < 40; $i++ ) {
	$never = $never && ! VCAP\Plugin::rate_limited( $fail_bucket, 5, 300, HOUR_IN_SECONDS, false );
}
for ( $i = 0; $i < 5; $i++ ) {
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
vcap_security_assert( '' === $door_view['holder'] && '' === $door_view['order'] && '' === $door_view['value_html'] && '' !== $door_view['balance_html'], 'the scanner hides name, order and value from door staff by default (balance stays for redemption)' );
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

echo "Voucher Captain security and privacy checks passed.\n";
