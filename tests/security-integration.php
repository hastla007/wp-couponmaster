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

// Per-visitor/public balance lookup throttling is enforced before a code lookup.
$bucket = 'security-test-' . strtolower( wp_generate_password( 8, false, false ) );
$allowed = true;
for ( $i = 0; $i < 15; $i++ ) {
	$allowed = $allowed && ! VCAP\Plugin::rate_limited( $bucket, 15, 300, 60 );
}
$blocked = VCAP\Plugin::rate_limited( $bucket, 15, 300, 60 );
vcap_security_assert( $allowed && $blocked, 'public balance lookup rate limit rejects the request after the visitor quota' );

// Signed guest downloads bind the voucher token and expiry to the site secret.
$download_voucher = new VCAP\Voucher();
$download_voucher->token = VCAP\VoucherRepository::new_token();
$url = vcap()->downloads->signed_url( $download_voucher, true );
wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
$sign_method = new ReflectionMethod( VCAP\Downloads::class, 'sign' );
$expected_sig = $sign_method->invoke( vcap()->downloads, $query['vcap_pdf'], (int) $query['exp'] );
vcap_security_assert( hash_equals( $expected_sig, $query['sig'] ) && (int) $query['exp'] > time(), 'guest PDF URL signs the token and expiry' );
vcap_security_assert( ! hash_equals( $expected_sig, hash_hmac( 'sha256', $query['vcap_pdf'] . '|' . ( (int) $query['exp'] + 1 ), vcap()->settings->secret() ) ), 'changing a signed guest-link expiry invalidates its signature' );

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

echo "Voucher Captain security and privacy checks passed.\n";
