<?php
/**
 * Scanner, counter and POS (audit M12 package A2): own check-in undo, personal vouchers at the counter, VAT data
 * and receipt number, the redemption slip, reversible bookings, the POS capability and refund route, daily limit
 * with the requested amount, signed PDF links, failed-lookup limit and counter, managed tills. Run through WP-CLI
 * after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/pos-integration.php
 */

use VCAP\Counter;
use VCAP\Install;
use VCAP\Voucher;

function vcap_pos_assert( bool $condition, string $message, $context = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null !== $context ? ' — ' . wp_json_encode( $context ) : '' ) );
	}
	echo "PASS: {$message}\n";
}

/** A voucher with its coupon (as issued), not linked to an order. */
function vcap_pos_voucher( string $mode = 'balance', float $value = 100.0, array $emails = array() ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-pos-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->set_email_restrictions( $emails );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$voucher                 = new Voucher();
	$voucher->code           = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 82000000 + $coupon->get_id();
	$voucher->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
	$voucher->usage_mode     = $mode;
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $value;
	$voucher->balance        = $value;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->status         = Voucher::STATUS_ACTIVE;
	$voucher->send_to        = 'recipient';
	$voucher->buyer_name     = 'Bob Buyer';
	$voucher->buyer_email    = 'bob.buyer@example.com';
	$voucher->recipient_name = 'Anna Recipient';
	$voucher->recipient_email = 'anna.recipient@example.com';
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return $voucher;
}

function vcap_pos_ticket( int $admissions ): Voucher {
	$ticket                 = new Voucher();
	$ticket->code           = 'vcap-pos-t-' . strtolower( wp_generate_password( 10, false, false ) );
	$ticket->order_item_id  = 83000000 + wp_rand( 1, 999999 );
	$ticket->type           = 'ticket';
	$ticket->usage_mode     = 'single';
	$ticket->discount_type  = 'fixed_cart';
	$ticket->initial_amount = 0;
	$ticket->balance        = 0;
	$ticket->currency       = get_woocommerce_currency();
	$ticket->status         = Voucher::STATUS_ACTIVE;
	$ticket->max_checkins   = $admissions;
	$ticket->id             = vcap()->vouchers->insert( $ticket );
	return vcap()->vouchers->get( $ticket->id );
}

function vcap_pos_user( string $role ): int {
	$login = 'vcap_pos_' . $role . '_' . strtolower( wp_generate_password( 6, false, false ) );
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => $role, 'display_name' => 'Cashier ' . $login ) );
}

/** A scanner request (route => Tickets callback); WP_Error or the response data. */
function vcap_pos_scan( string $route, array $params ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/' . $route );
	$req->set_param( 'idempotency_key', wp_generate_uuid4() );
	foreach ( $params as $name => $value ) {
		$req->set_param( $name, $value );
	}
	$callbacks = array( 'lookup' => 'rest_lookup', 'checkin' => 'rest_checkin', 'redeem' => 'rest_redeem', 'redeem/reverse' => 'rest_reverse' );
	$res       = VCAP\Source::with( VCAP\Source::SCANNER, static fn() => vcap()->tickets->{$callbacks[ $route ]}( $req ) );
	return is_wp_error( $res ) ? $res : $res->get_data();
}

/** A full REST request through the server (permission callbacks included). */
function vcap_pos_rest( string $method, string $route, array $body = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $body as $name => $value ) {
		$req->set_param( $name, $value );
	}
	return rest_do_request( $req );
}

global $wpdb;
$events = Install::tables()['events'];
foreach ( array( Counter::USER_AMOUNT, Counter::USER_COUNT, Counter::TILL_AMOUNT, Counter::TILL_COUNT, Counter::LOCATIONS, Counter::FAILED_LOOKUPS ) as $opt ) {
	delete_option( $opt );
}
update_option( Counter::REQUIRE_RECEIPT, 'no' );
$cashier = vcap_pos_user( Install::CASHIER_ROLE );
$other   = vcap_pos_user( Install::CASHIER_ROLE );

// M12-15: own undo reverts only the admissions of one's own last check-in.
$pass = vcap_pos_ticket( 3 );
wp_set_current_user( $other );
$first = vcap_pos_scan( 'checkin', array( 'q' => $pass->code, 'count' => 2 ) );
wp_set_current_user( $cashier );
$mine  = vcap_pos_scan( 'checkin', array( 'q' => $pass->code, 'count' => 1, 'till' => 'Entrance North' ) );
$undo1 = vcap_pos_scan( 'checkin', array( 'q' => $pass->code, 'undo' => true ) );
$undo2 = vcap_pos_scan( 'checkin', array( 'q' => $pass->code, 'undo' => true ) );
vcap_pos_assert( ! empty( $first['success'] ) && ! empty( $mine['success'] ) && ! empty( $undo1['success'] ), 'M12-15: check-ins of 2 (other cashier) and 1 (own), own undo works once', array( $first['message'] ?? $first, $undo1['message'] ?? $undo1 ) );
vcap_pos_assert( is_wp_error( $undo2 ) && 'vcap_undo_not_allowed' === $undo2->get_error_code() && 403 === $undo2->get_error_data()['status'] && 2 === vcap()->vouchers->get( $pass->id )->checkins, 'M12-15: check in 1, undo twice → the second undo is refused with 403 (the other cashier\'s admissions stay)', is_wp_error( $undo2 ) ? $undo2->get_error_code() : $undo2 );
vcap_pos_assert( '' === ( vcap_pos_scan( 'lookup', array( 'q' => $pass->code ) )['undo'] ?? 'x' ), 'M12-15: the lookup offers no further own undo' );
$checkin_till = $wpdb->get_var( $wpdb->prepare( "SELECT till FROM {$events} WHERE voucher_id = %d AND event = 'checkin' AND user_id = %d", $pass->id, $cashier ) ); // phpcs:ignore
vcap_pos_assert( 'Entrance North' === $checkin_till, 'M12-78: a check-in keeps the till/branch of the scanner', $checkin_till );

// M12-24: personal vouchers – names and masked addresses, ID check required.
$personal = vcap_pos_voucher( 'balance', 50, array( 'anna.recipient@example.com' ) );
$look     = vcap_pos_scan( 'lookup', array( 'q' => $personal->code ) );
vcap_pos_assert( is_array( $look['personal'] ?? null ) && array( 'Anna Recipient' ) === $look['personal']['names'] && array( 'a***@example.com' ) === $look['personal']['emails'], 'M12-24: the lookup names the person of a personal voucher, address masked', $look['personal'] ?? null );
vcap_pos_assert( null === vcap_pos_scan( 'lookup', array( 'q' => vcap_pos_voucher()->code ) )['personal'], 'M12-24: transferable vouchers carry no personal block' );
$no_id = vcap_pos_scan( 'redeem', array( 'q' => $personal->code, 'amount' => 10 ) );
vcap_pos_assert( is_wp_error( $no_id ) && 'vcap_id_check_required' === $no_id->get_error_code() && 409 === $no_id->get_error_data()['status'] && 50.0 === (float) vcap()->vouchers->get( $personal->id )->balance, 'M12-24: without id_checked a personal voucher is refused with 409 (nothing booked)' );
$with_id = vcap_pos_scan( 'redeem', array( 'q' => $personal->code, 'amount' => 10, 'id_checked' => true ) );
$id_note = (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$events} WHERE voucher_id = %d AND event = 'redeemed' ORDER BY id DESC LIMIT 1", $personal->id ) ); // phpcs:ignore
vcap_pos_assert( ! empty( $with_id['success'] ) && str_contains( $id_note, 'ID checked' ), 'M12-24: with id_checked it is redeemed and the note records the ID check', $id_note );

// M12-31: VAT data in the scanner and the API, receipt number required, the slip with masked code.
$card = vcap_pos_voucher( 'balance', 100 );
$look = vcap_pos_scan( 'lookup', array( 'q' => $card->code ) );
vcap_pos_assert( 'multi' === $look['purpose'] && 'payment' === $look['treatment'] && array_key_exists( 'sale_tax', $look ) && '' !== $look['treatment_label'], 'M12-31: the scanner answer carries purpose, treatment and sale_tax', array( $look['purpose'] ?? null, $look['treatment'] ?? null ) );
wp_set_current_user( 1 );
$api = vcap()->redemptions->check( $card->code );
vcap_pos_assert( is_array( $api ) && 'multi' === $api['purpose'] && 'payment' === $api['treatment'] && array_key_exists( 'sale_tax', $api ), 'M12-31: RedemptionService::check() (voucher_data) carries purpose, treatment and sale_tax', $api );
wp_set_current_user( $cashier );
update_option( Counter::REQUIRE_RECEIPT, 'yes' );
$no_rcpt = vcap_pos_scan( 'redeem', array( 'q' => $card->code, 'amount' => 5 ) );
vcap_pos_assert( is_wp_error( $no_rcpt ) && 'vcap_receipt_required' === $no_rcpt->get_error_code() && 100.0 === (float) vcap()->vouchers->get( $card->id )->balance, 'M12-31: "Receipt no. required" is checked on the server' );
vcap_pos_assert( true === vcap_pos_scan( 'lookup', array( 'q' => $card->code ) )['receipt_required'], 'M12-31: the lookup tells the scanner that the receipt number is required' );
update_option( Counter::REQUIRE_RECEIPT, 'auto' );
$country = get_option( 'woocommerce_default_country' );
update_option( 'woocommerce_default_country', 'AT' );
$auto_at = Counter::receipt_required();
update_option( 'woocommerce_default_country', 'US:CA' );
$auto_us = Counter::receipt_required();
update_option( 'woocommerce_default_country', $country );
vcap_pos_assert( $auto_at && ! $auto_us, 'M12-31: automatic = required for shops in Austria/Germany only' );
update_option( Counter::REQUIRE_RECEIPT, 'yes' );
$booked = vcap_pos_scan( 'redeem', array( 'q' => $card->code, 'amount' => 5, 'receipt' => 'R-77', 'till' => 'Shop 1' ) );
$slip   = $booked['slip'] ?? array();
vcap_pos_assert( ! empty( $booked['success'] ) && 'R-77' === $slip['receipt'] && 'Shop 1' === $slip['till'] && (int) $slip['booking'] === (int) $booked['event_id'] && ! str_contains( wp_json_encode( $slip ), strtoupper( $card->code ) ) && ! str_contains( wp_json_encode( $slip ), $card->code ) && str_starts_with( $slip['code'], '…' ) && '' !== $slip['staff'] && '' !== $slip['treatment'], 'M12-31: the slip carries masked code, time, staff, till, receipt and booking number', $slip );
update_option( Counter::REQUIRE_RECEIPT, 'no' );

// M12-45: own reversible bookings are listed on the next lookup.
$listed = vcap_pos_scan( 'lookup', array( 'q' => $card->code ) )['reversible'];
vcap_pos_assert( 1 === count( $listed ) && (int) $booked['event_id'] === $listed[0]['event_id'] && 'R-77' === $listed[0]['receipt'], 'M12-45: the lookup lists the cashier\'s own booking that can still be cancelled', $listed );
wp_set_current_user( $other );
vcap_pos_assert( array() === vcap_pos_scan( 'lookup', array( 'q' => $card->code ) )['reversible'], 'M12-45: another cashier does not see it' );
wp_set_current_user( $cashier );
$rev = vcap_pos_scan( 'redeem/reverse', array( 'q' => $card->code, 'event_id' => $listed[0]['event_id'], 'reason' => 'wrong card' ) );
vcap_pos_assert( ! empty( $rev['success'] ) && array() === $rev['reversible'], 'M12-45: once cancelled it is no longer listed' );

// M12-61: the daily limit checks the requested amount (a partial booking may deduct up to it).
update_option( Counter::USER_AMOUNT, 50 );
$small   = vcap_pos_voucher( 'balance', 30 );
$partial = vcap_pos_scan( 'redeem', array( 'q' => $small->code, 'amount' => 80, 'allow_partial' => true ) );
vcap_pos_assert( empty( $partial['success'] ) && 'daily_limit' === ( $partial['reason'] ?? '' ) && 30.0 === (float) vcap()->vouchers->get( $small->id )->balance, 'M12-61: a partial booking asking for more than the daily limit is refused (checked with 80, not with the balance of 30)', $partial['reason'] ?? $partial );
delete_option( Counter::USER_AMOUNT );

// M12-65: PDF links only with the shop's signature.
$signed = vcap()->downloads->signed_url( $card, true );
$forged = add_query_arg( 'sig', str_repeat( 'a', 64 ), $signed );
$bare   = remove_query_arg( array( 'sig', 'exp' ), $signed );
vcap_pos_assert( $card->id === ( vcap()->tickets->resolve( $signed )->id ?? 0 ) && null === vcap()->tickets->resolve( $forged ) && null === vcap()->tickets->resolve( $bare ), 'M12-65: a scanned PDF link counts only with a valid signature' );

// M12-78: failed lookups are limited per staff member and counted for the report.
$limit = static fn() => array( 'per_visitor' => 3, 'site_wide' => 0 );
add_filter( 'vcap_rate_limit_scanner_lookup', $limit );
VCAP\Plugin::rate_limit_reset( 'scanner_lookup', 900, 'u' . $cashier );
$codes = array();
for ( $i = 0; $i < 4; $i++ ) {
	$res     = vcap_pos_scan( 'lookup', array( 'q' => 'NOPE-' . wp_generate_password( 8, false, false ) ) );
	$codes[] = is_wp_error( $res ) ? $res->get_error_code() : 'found';
}
$found = vcap_pos_scan( 'lookup', array( 'q' => $card->code ) );
remove_filter( 'vcap_rate_limit_scanner_lookup', $limit );
VCAP\Plugin::rate_limit_reset( 'scanner_lookup', 900, 'u' . $cashier );
vcap_pos_assert( array( 'vcap_not_found', 'vcap_not_found', 'vcap_not_found', 'vcap_rate_limited' ) === $codes && is_wp_error( $found ), 'M12-78: after the limit of unknown codes the scanner of that staff member pauses (429)', $codes );
$today = wp_date( 'Y-m-d' );
vcap_pos_assert( 3 === ( Counter::failed_lookups( $today, $today )[ $cashier ] ?? 0 ), 'M12-78: failed lookups are counted per staff member for the report', Counter::failed_lookups( $today, $today ) );

// M12-81: managed tills, fixed per staff member.
update_option( Counter::LOCATIONS, "Shop A\nShop B\n" );
$loc_card = vcap_pos_voucher( 'balance', 100 );
$unknown  = vcap_pos_scan( 'redeem', array( 'q' => $loc_card->code, 'amount' => 1, 'till' => 'Somewhere' ) );
$listed   = vcap_pos_scan( 'redeem', array( 'q' => $loc_card->code, 'amount' => 1, 'till' => 'Shop A' ) );
vcap_pos_assert( is_wp_error( $unknown ) && 'vcap_till_unknown' === $unknown->get_error_code() && ! empty( $listed['success'] ), 'M12-81: with a managed list only its tills are accepted' );
update_user_option( $cashier, Counter::META_LOCATION, 'Shop B' );
$fixed     = vcap_pos_scan( 'redeem', array( 'q' => $loc_card->code, 'amount' => 1, 'till' => 'Shop A' ) );
$fixed_row = $wpdb->get_var( $wpdb->prepare( "SELECT till FROM {$events} WHERE voucher_id = %d AND event = 'redeemed' ORDER BY id DESC LIMIT 1", $loc_card->id ) ); // phpcs:ignore
vcap_pos_assert( ! empty( $fixed['success'] ) && 'Shop B' === $fixed_row && 'Shop B' === Counter::fixed_location( $cashier ), 'M12-81: a staff member fixed to a till always books there', $fixed_row );
delete_user_option( $cashier, Counter::META_LOCATION );
delete_option( Counter::LOCATIONS );

// M12-46: POS capability, refund route, till/receipt from meta.
$pos = vcap_pos_user( 'customer' );
wp_set_current_user( $pos );
$pos_card = vcap_pos_voucher( 'balance', 60 );
$denied   = vcap_pos_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $pos_card->code, 'key' => 'pos-' . wp_generate_password( 10, false, false ), 'target' => 'pos:till-1', 'amount' => 10 ) );
vcap_pos_assert( 403 === $denied->get_status(), 'M12-46: without vcap_redeem_api a customer account cannot redeem for a POS target', $denied->get_data() );
wp_get_current_user()->add_cap( VCAP\API\V1\RestController::POS_CAP );
wp_set_current_user( $pos );
$key     = 'pos-' . wp_generate_password( 10, false, false );
$reserve = vcap_pos_rest( 'POST', '/vcap/v1/redemptions', array( 'code' => $pos_card->code, 'key' => $key, 'target' => 'pos:till-1', 'amount' => 20, 'meta' => array( 'till' => 'POS 7', 'receipt' => 'B-123' ) ) );
$commit  = vcap_pos_rest( 'POST', '/vcap/v1/redemptions/' . $key . '/commit' );
$api_row = $wpdb->get_row( $wpdb->prepare( "SELECT till, receipt, source FROM {$events} WHERE voucher_id = %d AND event = 'api_committed' ORDER BY id DESC LIMIT 1", $pos_card->id ), ARRAY_A ); // phpcs:ignore
vcap_pos_assert( 201 === $reserve->get_status() && 200 === $commit->get_status() && 'committed' === $commit->get_data()['state'] && 'POS 7' === $api_row['till'] && 'B-123' === $api_row['receipt'], 'M12-46: a "Voucher POS" account (vcap_redeem_api) reserves and commits; meta.till/receipt are booked like at the scanner', array( $reserve->get_data(), $api_row ) );
$refund = vcap_pos_rest( 'POST', '/vcap/v1/redemptions/' . $key . '/refund', array( 'amount' => 5, 'refund_key' => 'ref-' . wp_generate_password( 10, false, false ) ) );
$ref_row = $wpdb->get_row( $wpdb->prepare( "SELECT till FROM {$events} WHERE voucher_id = %d AND event = 'api_refunded' ORDER BY id DESC LIMIT 1", $pos_card->id ), ARRAY_A ); // phpcs:ignore
vcap_pos_assert( 200 === $refund->get_status() && 5.0 === (float) $refund->get_data()['refunded'] && 45.0 === (float) vcap()->vouchers->get( $pos_card->id )->balance && 'POS 7' === $ref_row['till'], 'M12-46: POST /redemptions/{key}/refund gives the amount back', $refund->get_data() );
wp_set_current_user( $other );
$foreign = vcap_pos_rest( 'POST', '/vcap/v1/redemptions/' . $key . '/refund', array( 'amount' => 5, 'refund_key' => 'ref-' . wp_generate_password( 10, false, false ) ) );
vcap_pos_assert( 404 === $foreign->get_status() && 45.0 === (float) vcap()->vouchers->get( $pos_card->id )->balance, 'M12-46: the refund route has the access rules of commit (another user: 404 like an unknown key, S9)', $foreign->get_data() );

wp_set_current_user( 0 );
delete_option( Counter::REQUIRE_RECEIPT );
delete_option( Counter::FAILED_LOOKUPS );
echo "pos: all checks passed\n";
