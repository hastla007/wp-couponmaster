<?php
/**
 * Captain Suite contracts against a real WordPress/WooCommerce (WP Form Captain and WP Review Captain are not
 * needed: the test plays their part through the documented hooks and services):
 * - review source v1: counter redemption of a service voucher → eligible (once), cancellation → revoked; external
 *   redemption for a registered target → eligible, refund → revoked; no listener (missing connector) → nothing;
 * - voucher requests v1: a form request issues nothing; only the manager's confirmation issues, once.
 *   wp eval-file tests/captain-suite-integration.php
 */

use VCAP\Integrations\ReviewSource;
use VCAP\Integrations\VoucherRequests;
use VCAP\Voucher;

function vcap_cs_assert( bool $condition, string $message, $context = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null !== $context ? ' — ' . wp_json_encode( $context ) : '' ) );
	}
	echo "PASS: {$message}\n";
}

/** A voucher product marked (or not) as "Redemption confirms a service". */
function vcap_cs_product( bool $service ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Captain Suite spa day ' . wp_generate_password( 6, false, false ) );
	$product->set_regular_price( '80' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->update_meta_data( ReviewSource::PRODUCT_META, $service ? 'yes' : 'no' );
	return (int) $product->save();
}

/** A voucher with its coupon (as issued), not linked to an order. */
function vcap_cs_voucher( int $product_id, string $mode = 'single', float $value = 80.0 ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-cs-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$voucher                  = new Voucher();
	$voucher->code            = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id       = $coupon->get_id();
	$voucher->order_item_id   = 83000000 + $coupon->get_id();
	$voucher->product_id      = $product_id;
	$voucher->type            = 'balance' === $mode ? 'giftcard' : 'voucher';
	$voucher->usage_mode      = $mode;
	$voucher->discount_type   = 'fixed_cart';
	$voucher->initial_amount  = $value;
	$voucher->balance         = $value;
	$voucher->currency        = get_woocommerce_currency();
	$voucher->status          = Voucher::STATUS_ACTIVE;
	$voucher->send_to         = 'recipient';
	$voucher->buyer_email     = 'cs-buyer@example.com';
	$voucher->recipient_name  = 'Rita';
	$voucher->recipient_email = 'cs-rita@example.com';
	$voucher->id              = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return $voucher;
}

/** Id of the newest "redeemed" history entry of a voucher. */
function vcap_cs_last_redeemed( int $voucher_id ): int {
	$id = 0;
	foreach ( vcap()->vouchers->events( $voucher_id ) as $e ) {
		if ( 'redeemed' === $e['event'] ) {
			$id = (int) $e['id'];
		}
	}
	return $id;
}

wp_set_current_user( 1 );
$source = apply_filters( 'vcap_review_source_v1', null );
vcap_cs_assert( $source instanceof ReviewSource && '1' === strtok( $source->contract(), '.' ), 'review source contract v1 is offered through vcap_review_source_v1' );

// Missing connector: nobody listens, a service redemption records nothing.
$service = vcap_cs_product( true );
$plain   = vcap_cs_product( false );
$lonely  = vcap_cs_voucher( $service );
vcap_cs_assert( ! ReviewSource::active() && vcap()->tickets->redeem_single( $lonely, 'Spa' ), 'counter redemption without a review plugin works as before' );
vcap_cs_assert( null === $source->get( ReviewSource::reference( 'counter:' . vcap_cs_last_redeemed( $lonely->id ) ) ), 'without a listening review plugin no reference is recorded' );

$events = array();
$listen = static function ( $payload ) use ( &$events ) {
	$events[] = $payload;
};
add_action( ReviewSource::EVENT_ELIGIBLE, $listen );
add_action( ReviewSource::EVENT_REVOKED, $listen );

// Counter: eligible once, revoked by the cancellation.
$spa = vcap_cs_voucher( $service );
vcap_cs_assert( vcap()->tickets->redeem_single( vcap()->vouchers->get( $spa->id ), 'Spa' ), 'service voucher redeemed at the counter' );
$event_id  = vcap_cs_last_redeemed( $spa->id );
$reference = ReviewSource::reference( 'counter:' . $event_id );
vcap_cs_assert( 1 === count( $events ) && $reference === $events[0]['reference'] && 'eligible' === $events[0]['event'] && ReviewSource::PAYLOAD_KEYS === array_keys( $events[0] ), 'the counter redemption is eligible with the reference of its history entry', $events );
vcap_cs_assert( ! str_contains( (string) wp_json_encode( $events ), $spa->code ) && ! str_contains( (string) wp_json_encode( $events ), '@example.com' ), 'the payload holds neither code nor address' );
$source->on_redeemed( array( 'channel' => 'counter', 'voucher_id' => $spa->id, 'event_id' => $event_id ) );
vcap_cs_assert( 1 === count( $events ), 'a repeated event for the same redemption is not sent again' );
$recipient = $source->recipient( $reference );
vcap_cs_assert( is_array( $recipient ) && 'cs-rita@example.com' === $recipient['email'] && 'recipient' === $recipient['role'] && get_locale() === $recipient['locale'], 'a manager resolves the recipient of an eligible reference (with the invitation locale: no order, no account → site language)', $recipient );
$reversed = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $spa->id ), $event_id, 'Captain Suite test' );
vcap_cs_assert( ! is_wp_error( $reversed ) && 2 === count( $events ) && 'revoked' === $events[1]['event'] && 'redeem_reversed' === $events[1]['reason'] && $reference === $events[1]['reference'], 'the cancelled counter redemption is revoked', $events );
vcap_cs_assert( is_wp_error( $source->recipient( $reference ) ), 'a revoked reference gives no address' );

// A voucher of an unmarked product never counts.
$normal = vcap_cs_voucher( $plain );
vcap()->tickets->redeem_single( $normal, 'Shop counter' );
vcap_cs_assert( 2 === count( $events ), 'redemptions of products without "Redemption confirms a service" are no review source' );

// External redemption for a registered service target: eligible, refunded → revoked.
add_filter( 'vcap_review_channels', static fn() => array( 'counter', 'external' ) );
add_filter( 'vcap_review_external_target_prefixes', static fn() => array( 'spa:' ) );
$card = vcap_cs_voucher( $service, 'balance', 100.0 );
$key  = 'spa:cs:' . strtolower( wp_generate_password( 10, false, false ) );
$res  = vcap()->redemptions->reserve( $card->code, array( 'key' => $key, 'target' => 'spa:room-1', 'amount' => 30.0, 'user_id' => 1 ) );
$com  = is_wp_error( $res ) ? $res : vcap()->redemptions->commit( $key );
vcap_cs_assert( ! is_wp_error( $com ) && 3 === count( $events ) && ReviewSource::reference( 'external:' . $key ) === $events[2]['reference'] && 'external' === $events[2]['channel'], 'an external redemption for a registered target is eligible', is_wp_error( $com ) ? $com->get_error_code() : $events );
$ref = vcap()->redemptions->refund( $key, 10.0, 'spa:cs-refund:' . strtolower( wp_generate_password( 8, false, false ) ) );
vcap_cs_assert( ! is_wp_error( $ref ) && 4 === count( $events ) && 'refunded' === $events[3]['reason'], 'a refund of the external redemption revokes it', $events );
// Decision D4 (16.11.2026): one service = one request – after the revoked first redemption the next one counts,
// a further redemption of the same gift card does not.
$key2 = 'spa:cs:' . strtolower( wp_generate_password( 10, false, false ) );
$res2 = vcap()->redemptions->reserve( $card->code, array( 'key' => $key2, 'target' => 'spa:room-1', 'amount' => 20.0, 'user_id' => 1 ) );
$com2 = is_wp_error( $res2 ) ? $res2 : vcap()->redemptions->commit( $key2 );
vcap_cs_assert( ! is_wp_error( $com2 ) && 5 === count( $events ) && ReviewSource::reference( 'external:' . $key2 ) === $events[4]['reference'] && 'eligible' === $events[4]['event'], 'after the first redemption was revoked, the next redemption of the gift card is eligible again', $events );
$key3 = 'spa:cs:' . strtolower( wp_generate_password( 10, false, false ) );
$res3 = vcap()->redemptions->reserve( $card->code, array( 'key' => $key3, 'target' => 'spa:room-2', 'amount' => 20.0, 'user_id' => 1 ) );
$com3 = is_wp_error( $res3 ) ? $res3 : vcap()->redemptions->commit( $key3 );
vcap_cs_assert( ! is_wp_error( $com3 ) && 5 === count( $events ) && null === $source->get( ReviewSource::reference( 'external:' . $key3 ) ), 'a further redemption of the same gift card creates no event (first redemption only)', $events );
remove_action( ReviewSource::EVENT_ELIGIBLE, $listen );
remove_action( ReviewSource::EVENT_REVOKED, $listen );

// Voucher requests: the form issues nothing, the manager's confirmation issues once.
$issued = 0;
add_action(
	'vcap_voucher_issued_standalone',
	static function () use ( &$issued ) {
		++$issued;
	}
);
$requests = apply_filters( 'vcap_voucher_request_service_v1', null );
vcap_cs_assert( $requests instanceof VoucherRequests && '1' === strtok( $requests->contract(), '.' ), 'voucher request contract v1 is offered through vcap_voucher_request_service_v1' );
$entry = 'formcaptain:entry:cs-' . strtolower( wp_generate_password( 8, false, false ) );
wp_set_current_user( 0 );
$sub = $requests->submit(
	array(
		'reference'  => $entry,
		'kind'       => 'company_order',
		'amount'     => '15',
		'contact'    => array( 'company' => 'ACME GmbH', 'email' => 'cs-hr@example.com' ),
		'recipients' => array( array( 'name' => 'Ann', 'email' => 'cs-ann@example.com' ), array( 'name' => 'Ben', 'email' => 'cs-ben@example.com' ) ),
		'paid'       => true,
	)
);
vcap_cs_assert( is_array( $sub ) && 'open' === $sub['state'] && 0 === $issued, 'a form request is stored as open and issues no voucher', $sub );
$again = $requests->submit( array( 'reference' => $entry, 'contact' => array( 'email' => 'cs-hr@example.com' ) ) );
vcap_cs_assert( is_array( $again ) && $again['replayed'] && $sub['request_id'] === $again['request_id'], 'the same form entry is not stored twice' );
vcap_cs_assert( is_wp_error( $requests->confirm( $sub['request_id'], array( 'sale' => 'free' ) ) ) && 0 === $issued, 'a visitor cannot confirm a request' );
$export = $requests->export( 'CS-Ann@example.com' );
vcap_cs_assert( 1 === count( $export['data'] ) && 'vcap-request-' . $sub['request_id'] === $export['data'][0]['item_id'], 'the privacy exporter finds the open request by a recipient address', $export );

wp_set_current_user( 1 );
vcap_cs_assert( is_wp_error( $requests->confirm( $sub['request_id'], array() ) ) && 0 === $issued, 'the manager must decide sold or free of charge' );
$done = $requests->confirm( $sub['request_id'], array( 'sale' => 'free', 'send' => false ) );
vcap_cs_assert( is_array( $done ) && 'issued' === $done['state'] && 2 === count( $done['vouchers'] ) && 2 === $issued, 'the manager\'s confirmation issues one voucher per recipient', $done );
$repeat = $requests->confirm( $sub['request_id'], array( 'sale' => 'free', 'send' => false ) );
vcap_cs_assert( is_array( $repeat ) && $done['vouchers'] === $repeat['vouchers'] && 2 === $issued, 'confirming again issues nothing more' );
$first = vcap()->vouchers->get( (int) $done['vouchers'][0] );
vcap_cs_assert( $first instanceof Voucher && 'cs-ann@example.com' === $first->recipient_email && 'cs-hr@example.com' === $first->buyer_email, 'the vouchers carry recipient and company as buyer' );
vcap_cs_assert( array() === VoucherRequests::get( $sub['request_id'] )['recipients'], 'the issued request keeps no recipient list of its own' );
vcap_cs_assert( array() === $requests->export( 'cs-ann@example.com' )['data'], 'after issuing, the request no longer holds the recipient (exported with the voucher instead)' );
$erased = $requests->erase( 'cs-hr@example.com' );
vcap_cs_assert( $erased['items_removed'] && '' === VoucherRequests::get( $sub['request_id'] )['contact']['email'] && array() === $requests->export( 'cs-hr@example.com' )['data'], 'the privacy eraser removes the contact from the request' );


// Decision D5 (16.11.2026): hand-over to a company order of an add-on (played here by two filters).
$calls     = 0;
$available = static fn( $yes, $request_id ) => true;
$takeover  = static function ( $result, $handover ) use ( &$calls ) {
	++$calls;
	return array(
		'url'      => admin_url( 'admin.php?page=vcap-pro-bulk&order=4242' ),
		'order_id' => 4242,
	);
};
$ho_entry = 'formcaptain:entry:cs-ho-' . strtolower( wp_generate_password( 8, false, false ) );
wp_set_current_user( 0 );
$ho = $requests->submit( array( 'reference' => $ho_entry, 'amount' => '20', 'contact' => array( 'company' => 'ACME GmbH', 'email' => 'cs-ho@example.com' ), 'recipients' => array( array( 'name' => 'Ann', 'email' => 'cs-ho-ann@example.com' ), array( 'name' => 'Ben', 'email' => 'cs-ho-ben@example.com' ) ) ) );
wp_set_current_user( 1 );
$ho_id = (int) $ho['request_id'];
vcap_cs_assert( ! $requests->handover_available( VoucherRequests::get( $ho_id ) ) && is_wp_error( $requests->hand_over( $ho_id ) ), 'without an add-on no hand-over is offered or possible' );
add_filter( 'vcap_voucher_request_handover_available', $available, 10, 2 );
add_filter( 'vcap_voucher_request_handover', $takeover, 10, 2 );
vcap_cs_assert( $requests->handover_available( VoucherRequests::get( $ho_id ) ), 'with an add-on the manager is offered the hand-over of an open request with two recipients' );
$ho_done = $requests->hand_over( $ho_id );
vcap_cs_assert( is_array( $ho_done ) && 4242 === $ho_done['order_id'] && 'handed_over' === VoucherRequests::get( $ho_id )['state'] && array() === VoucherRequests::get( $ho_id )['recipients'] && 1 === $calls, 'the hand-over stores the company order and removes the recipients', $ho_done );
$ho_again = $requests->hand_over( $ho_id );
vcap_cs_assert( is_array( $ho_again ) && $ho_again['replayed'] && 4242 === $ho_again['order_id'] && 1 === $calls, 'a second hand-over answers the same company order' );
$ho_issue = $requests->confirm( $ho_id, array( 'sale' => 'free', 'send' => false ) );
vcap_cs_assert( is_wp_error( $ho_issue ) && 'vcap_handed_over' === $ho_issue->get_error_code(), 'a handed-over request cannot be issued' );
vcap_cs_assert( array() === $requests->export( 'cs-ho-ann@example.com' )['data'] && VoucherRequests::state_label( VoucherRequests::STATE_HANDED_OVER ) === $requests->export( 'cs-ho@example.com' )['data'][0]['data'][2]['value'], 'privacy export: the recipients are gone from the request, the contact sees the state "handed over" (readable label, R11)' );
remove_filter( 'vcap_voucher_request_handover_available', $available, 10 );
remove_filter( 'vcap_voucher_request_handover', $takeover, 10 );

// Decision D10 (16.11.2026): retention of requests in the daily maintenance.
global $wpdb;
$make = static function ( string $suffix ) use ( $requests ): int {
	$r = $requests->submit( array( 'reference' => 'formcaptain:entry:cs-ret-' . $suffix . '-' . strtolower( wp_generate_password( 6, false, false ) ), 'contact' => array( 'email' => 'cs-ret@example.com' ), 'recipients' => array( array( 'name' => 'Ann', 'email' => 'cs-ret-ann@example.com' ) ) ) );
	return (int) $r['request_id'];
};
$old_open   = $make( 'old-open' );
$new_open   = $make( 'new-open' );
$old_issued = $make( 'old-issued' );
$mid_issued = $make( 'mid-issued' );
$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months' ) ) ), array( 'ID' => $old_open ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( $old_issued => '-25 months', $mid_issued => '-12 months' ) as $rid => $age ) {
	$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 months' ) ) ), array( 'ID' => $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	update_post_meta( $rid, VoucherRequests::STATE_META, VoucherRequests::STATE_ISSUED );
	update_post_meta( $rid, VoucherRequests::UPDATED_META, gmdate( 'Y-m-d H:i:s', strtotime( $age ) ) );
}
clean_post_cache( $old_open );
vcap_cs_assert( in_array( $old_open, VoucherRequests::expired_ids( array( 'open', 'partial' ), gmdate( 'Y-m-d H:i:s', strtotime( '-6 months' ) ), 50 ), true ), 'an open request older than 6 months is found by the retention query' );
vcap_cs_assert( $requests->purge_expired( 20.0 ), 'the retention purge finishes within its budget' );
vcap_cs_assert( null === VoucherRequests::get( $old_open ) && null === VoucherRequests::get( $old_issued ) && null !== VoucherRequests::get( $new_open ) && null !== VoucherRequests::get( $mid_issued ) && null !== VoucherRequests::get( $ho_id ), 'open requests older than 6 months and issued ones older than 24 months (since issuing) are deleted, younger ones kept' );
vcap_cs_assert( vcap()->delivery->run_maintenance_step( 'voucher_requests' ), 'the maintenance step voucher_requests runs' );

// R8: claims of form references – a rejected entry stays answered as rejected (and is purged after the retention),
// an interrupted submit (claim without id) is repaired from the request, a claim without any request is taken over.
$r8_user = get_current_user_id();
wp_set_current_user( 1 );
$r8_ref = 'formcaptain:entry:cs-r8-' . strtolower( wp_generate_password( 6, false, false ) );
$r8_sub = $requests->submit( array( 'reference' => $r8_ref, 'contact' => array( 'email' => 'cs-r8@example.com' ), 'recipients' => array( array( 'name' => 'Ann', 'email' => 'cs-r8-ann@example.com' ) ) ) );
$r8_rej = $requests->reject( (int) $r8_sub['request_id'] );
$r8_rep = $requests->submit( array( 'reference' => $r8_ref, 'contact' => array( 'email' => 'cs-r8@example.com' ) ) );
vcap_cs_assert( true === $r8_rej && is_array( $r8_rep ) && 'rejected' === $r8_rep['state'] && 0 === $r8_rep['request_id'] && $r8_rep['replayed'], 'R8: a repeat of a rejected form entry is answered as rejected, no new request', $r8_rep );
update_option( VoucherRequests::CLAIM_PREFIX . md5( $r8_ref ), VoucherRequests::REJECTED_CLAIM . gmdate( 'Y-m-d H:i:s', strtotime( '-25 months' ) ), false );
VoucherRequests::purge_rejected_claims( gmdate( 'Y-m-d H:i:s', strtotime( '-24 months' ) ) );
vcap_cs_assert( false === get_option( VoucherRequests::CLAIM_PREFIX . md5( $r8_ref ), false ), 'R8: the claim of a rejected entry is removed after the retention for closed requests' );
$r8_ref2 = 'formcaptain:entry:cs-r8b-' . strtolower( wp_generate_password( 6, false, false ) );
$r8_sub2 = $requests->submit( array( 'reference' => $r8_ref2, 'contact' => array( 'email' => 'cs-r8@example.com' ) ) );
update_option( VoucherRequests::CLAIM_PREFIX . md5( $r8_ref2 ), '0:' . time(), false ); // As if the first call stopped before completing the claim.
$r8_rep2 = $requests->submit( array( 'reference' => $r8_ref2, 'contact' => array( 'email' => 'cs-r8@example.com' ) ) );
vcap_cs_assert( is_array( $r8_rep2 ) && $r8_rep2['request_id'] === $r8_sub2['request_id'] && (int) get_option( VoucherRequests::CLAIM_PREFIX . md5( $r8_ref2 ) ) === (int) $r8_sub2['request_id'], 'R8: an interrupted submit is repaired – the repeat answers the stored request and completes the claim', $r8_rep2 );
$r8_ref3 = 'formcaptain:entry:cs-r8c-' . strtolower( wp_generate_password( 6, false, false ) );
add_option( VoucherRequests::CLAIM_PREFIX . md5( $r8_ref3 ), '0:' . ( time() - 600 ), '', false ); // Claimed, nothing stored, long ago.
$r8_rep3 = $requests->submit( array( 'reference' => $r8_ref3, 'contact' => array( 'email' => 'cs-r8@example.com' ) ) );
vcap_cs_assert( is_array( $r8_rep3 ) && $r8_rep3['request_id'] > 0 && ! $r8_rep3['replayed'] && 'open' === $r8_rep3['state'], 'R8: a stale claim without a stored request is taken over and the entry stored', $r8_rep3 );
foreach ( array( $r8_sub2, $r8_rep3 ) as $r8_done ) {
	wp_delete_post( (int) $r8_done['request_id'], true );
}
wp_set_current_user( $r8_user );

// R9: while another manager changes the same request (its lock is held by another connection), confirm, hand-over
// and rejection wait and then answer vcap_in_progress instead of acting twice.
$r9_user = get_current_user_id();
wp_set_current_user( 1 );
$r9_req  = $requests->submit( array( 'reference' => 'formcaptain:entry:cs-r9-' . strtolower( wp_generate_password( 6, false, false ) ), 'contact' => array( 'email' => 'cs-r9@example.com' ), 'recipients' => array( array( 'name' => 'Ann', 'email' => 'cs-r9-ann@example.com' ) ) ) );
$r9_db   = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$r9_name = VCAP\Install::lock_name( 'voucher_request_' . (int) $r9_req['request_id'] );
$r9_db->query( "SELECT GET_LOCK('" . $r9_db->real_escape_string( $r9_name ) . "', 0)" );
$r9_wait = static fn() => 0;
add_filter( 'vcap_voucher_request_lock_wait', $r9_wait );
$r9_busy = array( $requests->confirm( (int) $r9_req['request_id'], array( 'sale' => 'free' ) ), $requests->reject( (int) $r9_req['request_id'] ) );
remove_filter( 'vcap_voucher_request_lock_wait', $r9_wait );
$r9_db->query( "SELECT RELEASE_LOCK('" . $r9_db->real_escape_string( $r9_name ) . "')" );
$r9_db->close();
$r9_free = $requests->reject( (int) $r9_req['request_id'] );
wp_set_current_user( $r9_user );
vcap_cs_assert( is_wp_error( $r9_busy[0] ) && 'vcap_in_progress' === $r9_busy[0]->get_error_code() && is_wp_error( $r9_busy[1] ) && 'vcap_in_progress' === $r9_busy[1]->get_error_code() && true === $r9_free, 'R9: a state change waits for the lock of the request; while another holds it nothing happens, afterwards it works', array( $r9_busy, $r9_free ) );

// R5: the admin list pages and filters by state (default "to do" = open/partial).
$r5_todo = array_column( VoucherRequests::page( array( VoucherRequests::STATE_OPEN, VoucherRequests::STATE_PARTIAL ), 1, 200 )['items'], 'id' );
$r5_done = array_column( VoucherRequests::page( array( VoucherRequests::STATE_ISSUED, VoucherRequests::STATE_HANDED_OVER ), 1, 200 )['items'], 'id' );
$r5_one  = VoucherRequests::page( array(), 1, 1 );
vcap_cs_assert( in_array( $new_open, $r5_todo, true ) && ! in_array( $mid_issued, $r5_todo, true ) && in_array( $mid_issued, $r5_done, true ) && in_array( $ho_id, $r5_done, true ) && 1 === count( $r5_one['items'] ) && $r5_one['total'] >= 3 && $r5_one['pages'] === $r5_one['total'], 'R5: requests are listed page by page and filtered by state', array( $r5_todo, $r5_done, $r5_one['total'] ) );

// R4: retention of the review-source reference records (revoked after 90 days, all after 24 months).
$r4_refs = array();
foreach ( array( 'old_revoked' => array( 'revoked', '-200 days', '-100 days' ), 'new_revoked' => array( 'revoked', '-20 days', '-10 days' ), 'eligible' => array( 'eligible', '-30 days', '' ), 'ancient' => array( 'eligible', '-25 months', '' ) ) as $r4_case => $r4_spec ) {
	$r4_refs[ $r4_case ] = VCAP\Integrations\ReviewSource::reference( 'r4-' . $r4_case . '-' . wp_generate_password( 8, false ) );
	add_option(
		VCAP\Integrations\ReviewSource::OPTION_PREFIX . $r4_refs[ $r4_case ],
		array( 'v' => 1, 'state' => $r4_spec[0], 'voucher_id' => 0, 'experience_id' => 0, 'channel' => 'counter', 'at' => gmdate( 'Y-m-d H:i:s', strtotime( $r4_spec[1] ) ), 'revoked_at' => '' !== $r4_spec[2] ? gmdate( 'Y-m-d H:i:s', strtotime( $r4_spec[2] ) ) : '', 'reason' => '' ),
		'',
		false
	);
}
vcap_cs_assert( vcap()->delivery->run_maintenance_step( 'review_refs' ), 'R4: the maintenance step review_refs runs' );
$r4_left = array_map( static fn( $ref ) => null !== vcap()->review_source->get( $ref ), $r4_refs );
vcap_cs_assert( array( 'old_revoked' => false, 'new_revoked' => true, 'eligible' => true, 'ancient' => false ) === $r4_left, 'R4: revoked references older than 90 days and all references older than 24 months are deleted, younger ones kept', $r4_left );
foreach ( $r4_refs as $r4_ref ) {
	delete_option( VCAP\Integrations\ReviewSource::OPTION_PREFIX . $r4_ref );
}
foreach ( array( $new_open, $mid_issued, $ho_id ) as $rid ) {
	wp_delete_post( $rid, true );
}

echo "Captain Suite integration: all checks passed.\n";
