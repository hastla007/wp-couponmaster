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
$privacy_event = $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM ' . VCAP\Install::tables()['events'] . " WHERE voucher_id = %d AND event <> 'privacy_erased' ORDER BY id DESC LIMIT 1", (int) $all_privacy_ids[0] ) );
vcap_security_assert( '[personal data removed]' === $privacy_event, 'erasure redacts free-text audit notes while preserving event rows' );
// M12-66: the erasure itself is logged (no personal data in it).
$privacy_last = $wpdb->get_var( $wpdb->prepare( 'SELECT event FROM ' . VCAP\Install::tables()['events'] . ' WHERE voucher_id = %d ORDER BY id DESC LIMIT 1', (int) $all_privacy_ids[0] ) );
vcap_security_assert( VCAP\TaxAccounting::ERASED_EVENT === $privacy_last, 'M12-66: the erasure is logged as its own history event' );

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

// AP-S3-1: the personalisation is also copied into the order line ("_vcap_fields" and the visible "vcap_…" meta).
$ap_tag     = strtolower( wp_generate_password( 6, false, false ) );
$ap_product = new WC_Product_Simple();
$ap_product->set_name( 'AP-S3 voucher ' . $ap_tag );
$ap_product->set_regular_price( '25' );
$ap_product->save();
$ap_line = static function ( WC_Order $order, string $recipient ) use ( $ap_product ): WC_Order_Item_Product {
	$item_id = $order->add_product( $ap_product, 1 );
	$item    = $order->get_item( $item_id );
	$fields  = array(
		'recipient_name'  => array( 'label' => 'Recipient name', 'value' => 'Rita Recipient' ),
		'recipient_email' => array( 'label' => 'Recipient email', 'value' => $recipient ),
		'message'         => array( 'label' => 'Personal message', 'value' => 'Happy birthday, Rita' ),
		'delivery_date'   => array( 'label' => 'Delivery date', 'value' => '2026-12-24' ),
		'sender'          => array( 'label' => 'Your name', 'value' => 'Bert Buyer' ),
	);
	$item->add_meta_data( '_vcap_fields', $fields, true );
	foreach ( $fields as $key => $field ) {
		$item->add_meta_data( VCAP\Fields::item_meta_key( $key ), $field['value'], true );
	}
	$item->save();
	return $item;
};
$ap_values = static function ( int $item_id ): array {
	$item   = WC_Order_Factory::get_order_item( $item_id );
	$fields = $item->get_meta( '_vcap_fields' );
	$out    = array();
	foreach ( array( 'recipient_name', 'recipient_email', 'message', 'delivery_date', 'sender' ) as $key ) {
		$out[ $key ] = (string) ( $fields[ $key ]['value'] ?? '' ) . '|' . (string) $item->get_meta( VCAP\Fields::item_meta_key( $key ) );
	}
	return $out;
};
$ap_buyer   = "ap-buyer-{$ap_tag}@example.test";
$ap_rcpt    = "ap-rcpt-{$ap_tag}@example.test";
$ap_order   = wc_create_order();
$ap_order->set_billing_email( $ap_buyer );
$ap_item_r  = $ap_line( $ap_order, $ap_rcpt );
$ap_item_b  = $ap_line( $ap_order, "ap-other-{$ap_tag}@example.test" );
$ap_item_u  = $ap_line( $ap_order, "ap-unissued-{$ap_tag}@example.test" ); // No voucher (e.g. unpaid order).
$ap_order->save();
$ap_link = static function ( VCAP\Voucher $voucher, int $item_id, string $buyer, string $recipient ) {
	$voucher->order_item_id   = $item_id;
	$voucher->buyer_email     = $buyer;
	$voucher->recipient_email = $recipient;
	vcap()->vouchers->update( $voucher );
};
$ap_link( vcap_security_create_voucher( $ap_rcpt, 910001 ), $ap_item_r->get_id(), "ap-someone-{$ap_tag}@example.test", $ap_rcpt );
$ap_link( vcap_security_create_voucher( $ap_buyer, 910002 ), $ap_item_b->get_id(), $ap_buyer, "ap-other-{$ap_tag}@example.test" );
$privacy->erase( $ap_rcpt, 1 );
$ap_after = $ap_values( $ap_item_r->get_id() );
vcap_security_assert( array( 'recipient_name' => '|', 'recipient_email' => '|', 'message' => '|', 'delivery_date' => '2026-12-24|2026-12-24', 'sender' => '|' ) === $ap_after, 'AP-S3-1: erasing the recipient also clears the order line copies (_vcap_fields values and vcap_… meta; the delivery date stays)' );
$privacy->erase( $ap_buyer, 1 );
$ap_after_b = $ap_values( $ap_item_b->get_id() );
vcap_security_assert( '|' === $ap_after_b['sender'] && 'Rita Recipient|Rita Recipient' === $ap_after_b['recipient_name'], 'AP-S3-1: erasing the buyer clears the buyer\'s own fields on the line (the recipient\'s stay for the recipient)' );
$privacy->erase( "ap-unissued-{$ap_tag}@example.test", 1 );
vcap_security_assert( '|' === $ap_values( $ap_item_u->get_id() )['recipient_email'] && '|' === $ap_values( $ap_item_u->get_id() )['message'], 'AP-S3-1: a line naming the recipient without a voucher (unpaid order) is cleared as well' );
// WooCommerce's own order anonymisation (erasure with "Remove personal data from orders", WooCommerce retention).
$ap_order2 = wc_create_order();
$ap_item_w = $ap_line( $ap_order2, "ap-wc-{$ap_tag}@example.test" );
$ap_order2->save();
WC_Privacy_Erasers::remove_order_personal_data( wc_get_order( $ap_order2->get_id() ) );
$ap_after_w = $ap_values( $ap_item_w->get_id() );
vcap_security_assert( '|' === $ap_after_w['recipient_name'] && '|' === $ap_after_w['recipient_email'] && '|' === $ap_after_w['message'] && '|' === $ap_after_w['sender'], 'AP-S3-1: WooCommerce\'s order anonymisation (woocommerce_privacy_remove_order_personal_data) clears the personalisation copies too' );
// AP-S3-1/AP-S3-2: retention clears the line copies, the coupon's email restriction and guest addresses in "used by".
$ap_order3  = wc_create_order();
$ap_item_t  = $ap_line( $ap_order3, "ap-ret-{$ap_tag}@example.test" );
$ap_order3->save();
$ap_ret     = vcap_security_create_voucher( "ap-ret-{$ap_tag}@example.test", 910003 );
$ap_link( $ap_ret, $ap_item_t->get_id(), "ap-ret-buyer-{$ap_tag}@example.test", "ap-ret-{$ap_tag}@example.test" );
$ap_ret_wc  = new WC_Coupon();
$ap_ret_wc->set_code( $ap_ret->code );
$ap_ret_wc->set_discount_type( 'fixed_cart' );
$ap_ret_wc->set_amount( 10 );
$ap_ret_wc->set_email_restrictions( array( "ap-ret-{$ap_tag}@example.test", "ap-ret-buyer-{$ap_tag}@example.test" ) );
$ap_ret_wc->save();
add_post_meta( $ap_ret_wc->get_id(), '_used_by', "ap-ret-buyer-{$ap_tag}@example.test" );
add_post_meta( $ap_ret_wc->get_id(), '_used_by', "ap-ret-buyer-{$ap_tag}@example.test" );
add_post_meta( $ap_ret_wc->get_id(), '_used_by', '12345' );
$ap_ret->coupon_id = $ap_ret_wc->get_id();
vcap()->vouchers->update( $ap_ret );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'status' => 'redeemed', 'updated_at' => $old_date ), array( 'id' => $ap_ret->id ) );
vcap()->vouchers->anonymise_older_than( 30 );
$ap_after_t = $ap_values( $ap_item_t->get_id() );
vcap_security_assert( '|' === $ap_after_t['recipient_name'] && '|' === $ap_after_t['recipient_email'] && '|' === $ap_after_t['message'] && '|' === $ap_after_t['sender'], 'AP-S3-1: retention clears the order line copies of the voucher' );
$ap_used_by = array_map( 'strval', get_post_meta( $ap_ret_wc->get_id(), '_used_by' ) );
sort( $ap_used_by );
$ap_placeholder = VCAP\VoucherRepository::erased_address( $ap_ret->id );
vcap_security_assert( array( $ap_placeholder ) === array_values( ( new WC_Coupon( $ap_ret_wc->get_id() ) )->get_email_restrictions() ) && array( '12345', $ap_placeholder, $ap_placeholder ) === $ap_used_by, 'AP-S3-2: retention replaces the coupon\'s email restriction and guest addresses in "used by" with the eraser\'s placeholder (user ids and the number of uses stay)' );

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

// M12-101: cancelling a counter redemption over REST (/vcap/v1/redeem/reverse): anonymous 401, customer 403,
// cashier without (or with a blank) reason 400 – nothing booked back in any of these cases.
$m101_card = vcap_security_create_voucher( 'reverse-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 940000 + random_int( 1, 9999 ) );
$m101_tag  = strtolower( wp_generate_password( 6, false, false ) );
$m101_cash = wp_insert_user( array( 'user_login' => 'sec-cashier-' . $m101_tag, 'user_pass' => wp_generate_password(), 'user_email' => 'sec-cashier-' . $m101_tag . '@example.test', 'role' => 'vcap_cashier' ) );
$m101_cust = wp_insert_user( array( 'user_login' => 'sec-customer-' . $m101_tag, 'user_pass' => wp_generate_password(), 'user_email' => 'sec-customer-' . $m101_tag . '@example.test', 'role' => 'customer' ) );
wp_set_current_user( $m101_cash );
$m101_req    = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
$m101_req->set_param( 'q', $m101_card->code );
$m101_req->set_param( 'amount', 5 );
$m101_req->set_param( 'idempotency_key', wp_generate_uuid4() );
$m101_booked = rest_do_request( $m101_req );
$m101_event  = 0;
foreach ( vcap()->vouchers->events( $m101_card->id ) as $m101_e ) {
	if ( 'redeemed' === $m101_e['event'] ) {
		$m101_event = (int) $m101_e['id'];
	}
}
$m101_balance = (float) vcap()->vouchers->get( $m101_card->id )->balance;
$m101_reverse = static function ( int $user, array $params ) use ( $m101_card, $m101_event ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem/reverse' );
	$req->set_param( 'q', $m101_card->code );
	$req->set_param( 'event_id', $m101_event );
	$req->set_param( 'idempotency_key', wp_generate_uuid4() );
	foreach ( $params as $name => $value ) {
		$req->set_param( $name, $value );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
};
$m101_anon   = $m101_reverse( 0, array( 'reason' => 'test' ) );
$m101_denied = $m101_reverse( $m101_cust, array( 'reason' => 'test' ) );
$m101_none   = $m101_reverse( $m101_cash, array() );
$m101_blank  = $m101_reverse( $m101_cash, array( 'reason' => '   ' ) );
vcap_security_assert( 200 === $m101_booked->get_status() && $m101_event > 0, 'M12-101: (a cashier booked a counter redemption to cancel)', array( $m101_booked->get_status(), $m101_event ) );
vcap_security_assert( 401 === $m101_anon->get_status(), 'M12-101: /redeem/reverse without login is answered with 401', $m101_anon->get_status() );
vcap_security_assert( 403 === $m101_denied->get_status(), 'M12-101: /redeem/reverse by a customer is answered with 403', $m101_denied->get_status() );
vcap_security_assert( 400 === $m101_none->get_status() && 400 === $m101_blank->get_status() && 'vcap_reversal_reason' === ( $m101_blank->get_data()['code'] ?? '' ), 'M12-101: /redeem/reverse by a cashier without a reason (missing or blank) is answered with 400', array( $m101_none->get_status(), $m101_blank->get_status(), $m101_blank->get_data() ) );
vcap_security_assert( abs( $m101_balance - (float) vcap()->vouchers->get( $m101_card->id )->balance ) < 0.0001, 'M12-101: none of the refused cancellations booked anything back' );
$m101_ok = $m101_reverse( $m101_cash, array( 'reason' => 'typo at the till' ) );
vcap_security_assert( 200 === $m101_ok->get_status() && abs( $m101_balance + 5 - (float) vcap()->vouchers->get( $m101_card->id )->balance ) < 0.0001, 'M12-101: with a reason the cashier cancels the redemption (5 booked back)', $m101_ok->get_data() );

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
	array( VCAP\ProductChecks::class, 'handle_expired_product', 'vcap_expired_product_' ),
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

// U4: the bulk void counts failures and names the codes whose WooCommerce coupon could not be disabled.
$u4_ok     = vcap_security_single();
$u4_broken = vcap_security_single();
$u4_broken->coupon_id = 987654321; // No such post: disabling the coupon fails.
vcap()->vouchers->update( $u4_broken );
delete_transient( 'vcap_flash_' . get_current_user_id() );
$u4_result = $u10_admin->bulk( 'void', array( $u4_ok->id, $u4_broken->id ) );
$u4_flash  = VCAP\Admin\Flash::pending();
$u4_errors = implode( ' ', wp_list_pluck( wp_list_filter( $u4_flash, array( 'type' => 'error' ) ), 'message' ) );
vcap_security_assert( 1 === $u4_result['done'] && array( $u4_broken->code ) === $u4_result['failed'] && str_contains( $u4_errors, $u4_broken->code ) && ! str_contains( $u4_errors, $u4_ok->code ), 'U4: a bulk void reports the voucher whose WooCommerce code could not be disabled as an error with its code' );
vcap_security_assert( (bool) wp_list_filter( $u4_flash, array( 'type' => 'success' ) ), 'U4: the vouchers that were voided are reported as a success count' );
delete_transient( 'vcap_flash_' . get_current_user_id() );

// U11: the voucher detail names the WooCommerce code by its code, not by the internal post ID ("#4567").
$_GET['voucher'] = $u4_ok->id;
ob_start();
$u10_admin->page();
$u11_html = (string) ob_get_clean();
unset( $_GET['voucher'] );
vcap_security_assert( str_contains( $u11_html, '<code>' . esc_html( strtoupper( $u4_ok->code ) ) . '</code></a>' ) && ! str_contains( $u11_html, '>#' . $u4_ok->coupon_id . '</a>' ), 'U11: the voucher detail shows the WooCommerce code itself, not its post ID' );

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

// AP-P1-4: the list loads the orders and coupons of its page together, not one by one per row.
$p14_tag = 'p14-' . strtolower( wp_generate_password( 8, false, false ) );
for ( $i = 0; $i < 3; $i++ ) {
	$p14_order = wc_create_order();
	$p14_order->save();
	$p14_v = vcap_security_single();
	$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'order_id' => $p14_order->get_id(), 'buyer_email' => $p14_tag . '-' . $i . '@example.com' ), array( 'id' => $p14_v->id ) );
}
$_REQUEST['s'] = $p14_tag;
wp_cache_flush();
$f11_table->prepare_items();
unset( $_REQUEST['s'] );
$p14_queries = array();
$p14_log     = static function ( $q ) use ( &$p14_queries ) {
	$p14_queries[] = $q;
	return $q;
};
$p14_order_col = new ReflectionMethod( $f11_table, 'column_order' );
$p14_code_col  = new ReflectionMethod( $f11_table, 'column_code' );
$p14_order_col->setAccessible( true );
$p14_code_col->setAccessible( true );
add_filter( 'query', $p14_log );
$p14_html = '';
foreach ( $f11_table->items as $p14_item ) {
	$p14_html .= $p14_order_col->invoke( $f11_table, $p14_item ) . $p14_code_col->invoke( $f11_table, $p14_item );
}
remove_filter( 'query', $p14_log );
$p14_row_reads = preg_grep( '/wc_orders|' . preg_quote( $wpdb->posts, '/' ) . '|' . preg_quote( $wpdb->postmeta, '/' ) . '/', $p14_queries );
vcap_security_assert( 3 === count( $f11_table->items ) && 3 === substr_count( $p14_html, '>#' ) && ! $p14_row_reads, 'AP-P1-4: the rows of the voucher list read no order, post or meta row on their own (primed by prepare_items())', array_values( $p14_row_reads ) );
VCAP\Voucher::forget_orders();

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
// AP-S2-1: store credit "+amount" on a user profile creates value: it needs vcap_increase_balance, and nobody credits
// their own account without manage_woocommerce – checked on validation and again when the pending booking is made.
$s21_option = get_option( 'vcap_store_credit' );
update_option( 'vcap_store_credit', 'yes' );
$s21_manager  = (int) wp_insert_user( array( 'user_login' => 'vcap-s21-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
$s21_customer = (int) wp_insert_user( array( 'user_login' => 'vcap-s21c-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 's21-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 'role' => 'customer' ) );
( new WP_User( $s21_manager ) )->add_cap( 'vcap_manage_vouchers' );
wp_set_current_user( $s21_manager );
$s21_admin   = new VCAP\Admin\StoreCreditAdmin( vcap()->store_credit, vcap()->vouchers );
$s21_profile = static function ( int $user_id, string $amount ) use ( $s21_admin ) {
	$_POST = array(
		'vcap_credit_nonce'         => wp_create_nonce( 'vcap_credit_' . $user_id ),
		'vcap_credit_amount'        => $amount,
		'vcap_credit_reason'        => 'AP-S2-1',
		VCAP\Admin\FormToken::FIELD => VCAP\Admin\FormToken::generate(),
	);
	$s21_admin->save_profile( $user_id );
	$errors = new WP_Error();
	$s21_admin->profile_errors( $errors, true, get_userdata( $user_id ) );
	$booked = $s21_admin->book_profile_credit( $user_id );
	$_POST  = array();
	return array( $errors->has_errors(), $booked );
};
[ $s21_err_other ] = $s21_profile( $s21_customer, '10' );
[ $s21_err_self ]  = $s21_profile( $s21_manager, '10' );
vcap_security_assert( $s21_err_other && $s21_err_self && 0.0 === vcap()->store_credit->balance( $s21_customer ) && 0.0 === vcap()->store_credit->balance( $s21_manager ), 'AP-S2-1: without vcap_increase_balance a voucher manager cannot add store credit on a profile (own or other)' );
// A pending booking that bypassed the validation (replayed) is refused when it is booked.
$s21_pending = new ReflectionProperty( VCAP\Admin\StoreCreditAdmin::class, 'pending' );
$s21_pending->setAccessible( true );
$s21_pending->setValue( $s21_admin, array( $s21_manager => array( 'amount' => 10.0, 'reason' => 'replay', 'error' => '' ) ) );
$s21_replay = $s21_admin->book_profile_credit( $s21_manager );
vcap_security_assert( is_wp_error( $s21_replay ) && 'vcap_credit_forbidden' === $s21_replay->get_error_code() && 0.0 === vcap()->store_credit->balance( $s21_manager ), 'AP-S2-1: book_profile_credit() checks the rights again (a replayed pending credit is not booked)' );
( new WP_User( $s21_manager ) )->add_cap( 'vcap_increase_balance' );
wp_set_current_user( 0 );
wp_set_current_user( $s21_manager );
[ $s21_err_self2 ]           = $s21_profile( $s21_manager, '10' );
[ $s21_err_cust, $s21_book ] = $s21_profile( $s21_customer, '10' );
vcap_security_assert( $s21_err_self2 && 0.0 === vcap()->store_credit->balance( $s21_manager ) && ! $s21_err_cust && is_array( $s21_book ) && 10.0 === vcap()->store_credit->balance( $s21_customer ), 'AP-S2-1: with vcap_increase_balance a manager credits customers, but never their own account without manage_woocommerce' );
[ $s21_err_debit ] = $s21_profile( $s21_customer, '-4' );
vcap_security_assert( ! $s21_err_debit && 6.0 === vcap()->store_credit->balance( $s21_customer ) && '' === VCAP\Admin\StoreCreditAdmin::credit_refusal( $s21_manager, -1.0 ), 'AP-S2-1: removing store credit needs the voucher right only' );
update_option( 'vcap_store_credit', $s21_option );
delete_transient( 'vcap_flash_' . get_current_user_id() );

// AP-S2-2 / AP-S2-3: other value-raising admin paths need vcap_increase_balance.
$s22_manager = (int) wp_insert_user( array( 'user_login' => 'vcap-s22-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ) );
( new WP_User( $s22_manager ) )->add_cap( 'vcap_manage_vouchers' );
wp_set_current_user( $s22_manager );
$s22_admin = new VCAP\Admin\VoucherAdmin( vcap()->vouchers );
// Reviving an expired voucher with a new expiry date.
$s22_expired             = vcap_security_create_voucher( 's22-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 970000 + random_int( 1, 9999 ) );
$s22_expired->expires_at = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
$s22_expired->status     = VCAP\Voucher::STATUS_EXPIRED;
vcap()->vouchers->update( $s22_expired );
$s22_save = static function ( VCAP\Voucher $v, array $post ) use ( $s22_admin ) {
	$fresh = vcap()->vouchers->get( $v->id );
	$_POST = $post + array(
		'expires_at'      => $fresh->expires_at ? get_date_from_gmt( $fresh->expires_at, 'Y-m-d' ) : '',
		'recipient_name'  => $fresh->recipient_name,
		'recipient_email' => $fresh->recipient_email,
		'message'         => $fresh->message,
		'send_to'         => $fresh->send_to,
	);
	$ok    = $s22_admin->save( $fresh );
	$_POST = array();
	return $ok;
};
$s22_future = wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
$s22_revive = $s22_save( $s22_expired, array( 'expires_at' => $s22_future ) );
vcap_security_assert( false === $s22_revive && VCAP\Voucher::STATUS_EXPIRED === vcap()->vouchers->get( $s22_expired->id )->status && vcap()->vouchers->get( $s22_expired->id )->is_expired(), 'AP-S2-2: without vcap_increase_balance a new expiry date does not revive an expired voucher' );
$s22_edit = $s22_save( $s22_expired, array( 'recipient_name' => 'Renamed' ) );
vcap_security_assert( true === $s22_edit && 'Renamed' === vcap()->vouchers->get( $s22_expired->id )->recipient_name, 'AP-S2-2: other fields of an expired voucher can still be edited without the right' );
// Raising the check-ins of a ticket.
$s22_ticket = vcap_security_create_voucher( 's22t-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 960000 + random_int( 1, 9999 ) );
$s22_more   = $s22_save( $s22_ticket, array( 'max_checkins' => '5' ) );
vcap_security_assert( false === $s22_more && 5 !== (int) vcap()->vouchers->get( $s22_ticket->id )->max_checkins, 'AP-S2-2: without vcap_increase_balance the check-ins of a ticket cannot be raised' );
// Reactivating: only void vouchers (AP-S2-3), only with the right, and a refund void only with confirmation.
$s22_void = vcap_security_create_voucher( 's22v-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 950000 + random_int( 1, 9999 ) );
vcap()->issuer->void( vcap()->vouchers->get( $s22_void->id ), 'Purchase order cancelled or refunded', true );
$s22_no_right = $s22_admin->reactivate( vcap()->vouchers->get( $s22_void->id ), true );
$s22_bulk     = $s22_admin->bulk( 'reactivate', array( $s22_void->id ) );
vcap_security_assert( '' === $s22_no_right && false === vcap()->issuer->reactivate( vcap()->vouchers->get( $s22_void->id ), true ) && 1 === $s22_bulk['skipped'] && VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $s22_void->id )->status, 'AP-S2-2: without vcap_increase_balance a void voucher is not reactivated (single, bulk and Issuer)' );
( new WP_User( $s22_manager ) )->add_cap( 'vcap_increase_balance' );
wp_set_current_user( 0 );
wp_set_current_user( $s22_manager );
$s22_unconfirmed = $s22_admin->reactivate( vcap()->vouchers->get( $s22_void->id ) );
$s22_bulk2       = $s22_admin->bulk( 'reactivate', array( $s22_void->id ) );
vcap_security_assert( vcap()->issuer->voided_after_refund( vcap()->vouchers->get( $s22_void->id ) ) && '' === $s22_unconfirmed && 1 === $s22_bulk2['skipped'] && VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $s22_void->id )->status, 'AP-S2-2: a voucher voided because of a refund is not reactivated silently (single action without confirmation, bulk)' );
vcap_security_assert( 'reactivated' === $s22_admin->reactivate( vcap()->vouchers->get( $s22_void->id ), true ) && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $s22_void->id )->status, 'AP-S2-2: with the right and the explicit confirmation the refund void is reactivated' );
$s22_admin_void = vcap_security_create_voucher( 's22a-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 940000 + random_int( 1, 9999 ) );
vcap()->issuer->void( vcap()->vouchers->get( $s22_admin_void->id ), 'Voided by admin: test' );
vcap_security_assert( ! vcap()->issuer->voided_after_refund( vcap()->vouchers->get( $s22_admin_void->id ) ) && 'reactivated' === $s22_admin->reactivate( vcap()->vouchers->get( $s22_admin_void->id ) ), 'AP-S2-2: a voucher voided by staff is reactivated without the refund confirmation' );
$s22_active = vcap_security_create_voucher( 's22s-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 930000 + random_int( 1, 9999 ) );
vcap()->vouchers->update_status( $s22_active->id, VCAP\Voucher::STATUS_SCHEDULED );
vcap_security_assert( '' === $s22_admin->reactivate( vcap()->vouchers->get( $s22_active->id ) ) && false === vcap()->issuer->reactivate( vcap()->vouchers->get( $s22_active->id ) ) && VCAP\Voucher::STATUS_SCHEDULED === vcap()->vouchers->get( $s22_active->id )->status, 'AP-S2-3: "Reactivate" refuses a voucher that is not void (a scheduled voucher stays scheduled)' );
// Now with the right: the check-ins can be raised and the expired voucher revived.
vcap_security_assert( true === $s22_save( $s22_ticket, array( 'max_checkins' => '5' ) ) && 5 === (int) vcap()->vouchers->get( $s22_ticket->id )->max_checkins && true === $s22_save( $s22_expired, array( 'expires_at' => $s22_future ) ) && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $s22_expired->id )->status, 'AP-S2-2: with vcap_increase_balance check-ins can be raised and an expired voucher revived' );
// "Book back onto voucher": voucher right + edit_shop_orders + vcap_increase_balance; strict amount parsing.
wp_set_current_user( $s21_manager ); // Voucher right and increase right, no edit_shop_orders.
$s22_return_without = VCAP\OrderReturn::can();
( new WP_User( $s21_manager ) )->add_cap( 'edit_shop_orders' );
( new WP_User( $s21_manager ) )->remove_cap( 'vcap_increase_balance' );
wp_set_current_user( 0 );
wp_set_current_user( $s21_manager );
$s22_return_no_increase = VCAP\OrderReturn::can();
( new WP_User( $s21_manager ) )->add_cap( 'vcap_increase_balance' );
wp_set_current_user( 0 );
wp_set_current_user( $s21_manager );
$s22_return_with = VCAP\OrderReturn::can();
$s22_handle      = vcap_security_method_source( VCAP\OrderReturn::class, 'handle' );
vcap_security_assert( false === $s22_return_without && false === $s22_return_no_increase && true === $s22_return_with && str_contains( $s22_handle, 'StoreCreditAdmin::parse_amount(' ) && ! str_contains( $s22_handle, 'wc_format_decimal' ), 'AP-S2-2: "Book back onto voucher" needs edit_shop_orders and vcap_increase_balance and parses the amount strictly' );
// AP-S2-4: generating WooCommerce coupons from a template needs publish_shop_coupons.
$s24_without = VCAP\Admin\CampaignAdmin::can_generate( 'coupons' );
( new WP_User( $s21_manager ) )->add_cap( 'publish_shop_coupons' );
wp_set_current_user( 0 );
wp_set_current_user( $s21_manager );
vcap_security_assert( false === $s24_without && VCAP\Admin\CampaignAdmin::can_generate( 'pool' ) && VCAP\Admin\CampaignAdmin::can_generate( 'coupons' ), 'AP-S2-4: "Generate codes" for WooCommerce coupons needs publish_shop_coupons (code pool batches do not)' );
// AP-Q2-4: an exception during "Send now" becomes a notice and a history entry, not a white screen.
$q24_voucher = vcap_security_create_voucher( 'q24-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 920000 + random_int( 1, 9999 ) );
vcap()->vouchers->update_status( $q24_voucher->id, VCAP\Voucher::STATUS_SCHEDULED );
$q24_throw = static function ( $voucher_id, $event ) use ( $q24_voucher ) {
	if ( (int) $voucher_id === (int) $q24_voucher->id && 'scheduled' === $event ) {
		throw new RuntimeException( 'Mail system exploded for q24@example.test' );
	}
};
add_action( 'vcap_voucher_event', $q24_throw, 10, 2 );
delete_transient( 'vcap_flash_' . get_current_user_id() );
$q24_threw = null;
$q24_sent  = null;
try {
	$q24_sent = $s22_admin->deliver_now( vcap()->vouchers->get( $q24_voucher->id ) );
} catch ( Throwable $e ) {
	$q24_threw = $e;
}
remove_action( 'vcap_voucher_event', $q24_throw, 10 );
$q24_flash  = get_transient( 'vcap_flash_' . get_current_user_id() );
$q24_events = vcap()->vouchers->events( $q24_voucher->id );
$q24_last   = end( $q24_events );
vcap_security_assert( null === $q24_threw && false === $q24_sent && 'delivery_failed' === $q24_last['event'] && ! str_contains( (string) $q24_last['note'], 'q24@example.test' ) && str_contains( (string) wp_json_encode( $q24_flash ), '"type":"error"' ), 'AP-Q2-4: an exception in "Send now" is caught, logged (address masked) and shown as an error notice' );
delete_transient( 'vcap_flash_' . get_current_user_id() );
wp_set_current_user( 0 );

// O6: an idempotency claim left without an answer (PHP stopped after or before booking) is resolved from the history.
wp_set_current_user( $o3_cashier );
$o6_card  = vcap_security_create_voucher( 'o6-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test', 990000 + random_int( 1, 9999 ) );
$o6_mark  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$events_table} WHERE voucher_id = %d", $o6_card->id ) ); // phpcs:ignore
$o6_claim = static function ( string $key, float $amount ) use ( $wpdb, $o6_card, $events_table ) {
	$o6_mark = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$events_table} WHERE voucher_id = %d", $o6_card->id ) ); // phpcs:ignore -- newest event when the claim was taken.
	$wpdb->insert( VCAP\Install::tables()['scan'], array( 'key_hash' => md5( $key ), 'signature' => md5( 'redeem|' . $o6_card->id . '|' . round( $amount, 4 ) ), 'event_mark' => $o6_mark, 'claimed_at' => time() - 300 ) );
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
$wpdb->insert( VCAP\Install::tables()['scan'], array( 'key_hash' => md5( $o6_key_busy ), 'signature' => md5( 'redeem|' . $o6_card->id . '|3' ), 'event_mark' => $o6_mark, 'claimed_at' => time() ) );
$o6_busy = $o6_redeem( $o6_key_busy, 3 );
vcap_security_assert( is_wp_error( $o6_busy ) && 'vcap_in_progress' === $o6_busy->get_error_code() && 409 === ( $o6_busy->get_error_data()['status'] ?? 0 ) && 16.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance, 'O6: a claim still in progress is answered with 409 and books nothing' );
// B16: scanner keys are rows of their own table, not wp_options; options of the old storage are still recognised.
$b16_scan    = VCAP\Install::tables()['scan'];
$b16_options = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_vcap_scan_' ) . '%' ) );
$b16_before  = $b16_options();
$b16_key     = wp_generate_uuid4();
$b16_first   = $o6_redeem( $b16_key, 2 );
$b16_again   = $o6_redeem( $b16_key, 2 );
vcap_security_assert( ! empty( $b16_first['success'] ) && ! empty( $b16_again['replayed'] ) && 14.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance && $b16_before === $b16_options() && null !== $wpdb->get_var( $wpdb->prepare( "SELECT response FROM {$b16_scan} WHERE key_hash = %s", md5( $b16_key ) ) ), 'B16: a scanner key and its answer are stored in vcap_scan_keys (no option row), the repeat is replayed and books once' );
$b16_legacy_key = wp_generate_uuid4();
$b16_keep_flag  = get_option( VCAP\Install::LEGACY_THROTTLE_OPTION, false );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( $b16_legacy_key ), 'option_value' => time() . '|' . md5( 'redeem|' . $o6_card->id . '|1' ) . '#' . $o6_mark . '|', 'autoload' => 'no' ) );
update_option( VCAP\Install::LEGACY_THROTTLE_OPTION, time() );
$b16_busy = $o6_redeem( $b16_legacy_key, 1 );
vcap_security_assert( is_wp_error( $b16_busy ) && 'vcap_in_progress' === $b16_busy->get_error_code() && 14.0 === (float) vcap()->vouchers->get( $o6_card->id )->balance && $b16_before === $b16_options() && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b16_scan} WHERE key_hash = %s", md5( $b16_legacy_key ) ) ), 'B16: a key claimed as option just before the switch is moved into the table and still answered with 409 (no second booking)' );
false === $b16_keep_flag ? delete_option( VCAP\Install::LEGACY_THROTTLE_OPTION ) : update_option( VCAP\Install::LEGACY_THROTTLE_OPTION, $b16_keep_flag );
$wpdb->update( $b16_scan, array( 'claimed_at' => time() - 3 * DAY_IN_SECONDS ), array( 'key_hash' => md5( $b16_key ) ) );
VCAP\Tickets::purge_scan_keys( 5.0 );
vcap_security_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT key_hash FROM {$b16_scan} WHERE key_hash = %s", md5( $b16_key ) ) ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT key_hash FROM {$b16_scan} WHERE key_hash = %s", md5( $b16_legacy_key ) ) ), 'B16: the daily purge removes scanner keys older than two days from the table, newer ones stay' );
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
// O2: after WooCommerce's short hold has run out, the unpaid order itself still holds the voucher (pay link).
$o2_voucher = vcap_security_single();
$o2_order   = wc_create_order();
$o2_line    = new WC_Order_Item_Coupon();
$o2_line->set_code( $o2_voucher->code );
$o2_line->set_discount( 20 );
$o2_order->add_item( $o2_line );
$o2_order->set_status( 'pending' );
$o2_order->save();
$o2_fresh = vcap()->vouchers->get( $o2_voucher->id );
$o2_api   = vcap()->redemptions->check( $o2_fresh->code, array( 'user_id' => 0 ) );
vcap_security_assert( '' !== vcap()->tickets->counter_refusal_reason( $o2_fresh ) && is_wp_error( $o2_api ) && 'vcap_reserved' === $o2_api->get_error_code(), 'O2: a single-use voucher in an unpaid order (no WooCommerce hold any more) is refused at the counter and by the API' );
$o2_order->update_status( 'cancelled' );
vcap_security_assert( '' === vcap()->tickets->counter_refusal_reason( vcap()->vouchers->get( $o2_voucher->id ) ), 'O2: once that order is cancelled the counter may redeem the voucher' );
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
// --- V13: customer preview – amount within the product's range, QR codes as placeholder, covering watermark.
$preview_product = new WC_Product_Simple();
$preview_product->set_name( 'Preview gift card' );
$preview_product->set_regular_price( '10' );
$preview_product->set_virtual( true );
$preview_product->set_status( 'publish' );
foreach ( array( 'enabled' => 'yes', 'type' => 'giftcard', 'amount_source' => 'custom', 'custom_min' => '10', 'custom_max' => '200', 'custom_step' => '', 'preview' => 'yes' ) as $key => $value ) {
	$preview_product->update_meta_data( VCAP\ProductConfig::meta_key( $key ), $value );
}
$preview_product->save();
$foreign_qr = static function ( $design ) {
	$design['elements'][] = array( 'id' => 'evil-qr', 'type' => 'qrcode', 'x' => 1, 'y' => 1, 'w' => 20, 'h' => 20, 'qrContent' => 'custom', 'qrValue' => 'https://phish.example/{message}' );
	$design['elements'][] = array( 'id' => 'evil-bar', 'type' => 'barcode', 'x' => 1, 'y' => 30, 'w' => 40, 'h' => 15, 'qrValue' => '{message}' );
	return $design;
};
add_filter( 'vcap_template_design', $foreign_qr );
$previewer          = new VCAP\Preview( vcap()->pdf );
list( $pv, $design ) = $previewer->preview( $preview_product, '10000', false );
list( $ok_v )        = $previewer->preview( $preview_product, '50', false );
remove_filter( 'vcap_template_design', $foreign_qr );
$codes      = array_filter( $design['elements'], static fn( $el ) => in_array( $el['type'] ?? '', array( 'qrcode', 'barcode' ), true ) );
$watermarks = array_filter( $design['elements'], static fn( $el ) => str_starts_with( (string) ( $el['id'] ?? '' ), 'vcap-watermark' ) );
vcap_security_assert( 10.0 === (float) $pv->initial_amount && 50.0 === (float) $ok_v->initial_amount, 'customer preview: an amount above the product maximum is shown as the minimum, an allowed amount as entered' );
vcap_security_assert( count( $codes ) >= 3 && array() === array_filter( $codes, static fn( $el ) => 'PREVIEW' !== ( $el['qrValue'] ?? '' ) || 'custom' !== ( $el['qrContent'] ?? '' ) ), 'customer preview: every QR code and barcode carries the fixed placeholder (no foreign link)' );
vcap_security_assert( count( $watermarks ) >= 3 && array() === array_filter( $watermarks, static fn( $el ) => (float) $el['opacity'] < 0.5 ), 'customer preview: the watermark covers the page in several rows' );
// S13: no customer preview for a password-protected product (password not entered) or one hidden from the catalog.
$s13_die = static fn() => static function ( $message, $title = '', $args = array() ) {
	throw new RuntimeException( 'die:' . (int) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
};
$s13_user = get_current_user_id();
wp_set_current_user( 0 );
add_filter( 'wp_die_handler', $s13_die );
add_filter( 'vcap_client_ip', static fn() => '203.0.113.' . wp_rand( 160, 250 ) );
$s13_results = array();
foreach ( array( 'password' => array( 'post_password' => 'secret-' . wp_generate_password( 6, false, false ) ), 'hidden' => array() ) as $s13_case => $s13_post ) {
	wp_update_post( array( 'ID' => $preview_product->get_id(), 'post_password' => $s13_post['post_password'] ?? '' ) );
	$s13_product = wc_get_product( $preview_product->get_id() );
	$s13_product->set_catalog_visibility( 'hidden' === $s13_case ? 'hidden' : 'visible' );
	$s13_product->save();
	$_REQUEST = array( 'product_id' => $preview_product->get_id(), 'vcap_amount' => '50' );
	try {
		( new VCAP\Preview( vcap()->pdf ) )->product_preview();
		$s13_results[ $s13_case ] = 'rendered';
	} catch ( RuntimeException $e ) {
		$s13_results[ $s13_case ] = $e->getMessage();
	}
}
$_REQUEST = array();
remove_filter( 'wp_die_handler', $s13_die );
remove_all_filters( 'vcap_client_ip' );
wp_set_current_user( $s13_user );
vcap_security_assert( array( 'password' => 'die:400', 'hidden' => 'die:400' ) === $s13_results, 'S13: the customer preview refuses a password-protected product and a product hidden from the catalog (' . wp_json_encode( $s13_results ) . ')' );

// --- C13: a failed read of the site secret never replaces it (all PDF links already sent stay valid).
$secret_before = vcap()->settings->secret();
add_filter( 'pre_option_vcap_secret', '__return_empty_string' );
$secret_glitch = vcap()->settings->secret();
remove_filter( 'pre_option_vcap_secret', '__return_empty_string' );
wp_cache_delete( 'vcap_secret', 'options' );
vcap_security_assert( $secret_before === $secret_glitch && $secret_before === (string) get_option( 'vcap_secret' ), 'the site secret is read again, not overwritten, when a read returns nothing' );

// --- V12: guest PDF downloads – rate limited, "downloaded" at most hourly, rendered PDF cached per voucher state.
$dl_voucher = vcap_security_create_voucher( 'download-' . wp_generate_password( 6, false, false ) . '@example.test', 990 );
$downloads  = vcap()->downloads;
vcap_security_assert( ! $downloads->downloaded_recently( $dl_voucher->id ), 'no download logged yet' );
vcap()->vouchers->log( $dl_voucher->id, 'downloaded' );
vcap_security_assert( $downloads->downloaded_recently( $dl_voucher->id ), 'a download within the last hour is known (no second "downloaded" event)' );
$file_a              = $downloads->cached_pdf_file( $dl_voucher );
$dl_voucher->balance = 5;
$file_b              = $downloads->cached_pdf_file( $dl_voucher );
vcap_security_assert( $file_a !== $file_b && str_starts_with( $file_a, VCAP\Install::upload_dir() . '/tmp/dl-' ) && ! str_contains( $file_a, $dl_voucher->token ), 'the cached PDF is private, does not name the token and changes with the voucher' );
$die_handler = static fn() => static function ( $message, $title = '', $args = array() ) {
	throw new RuntimeException( 'die:' . (int) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
};
add_filter( 'wp_die_handler', $die_handler );
add_filter( 'vcap_client_ip', static fn() => '203.0.113.150' );
$_GET     = array( 'vcap_pdf' => str_repeat( 'a', 48 ), 'exp' => '0', 'sig' => 'bad' );
$statuses = array();
for ( $i = 0; $i < 31; $i++ ) {
	try {
		$downloads->maybe_serve();
	} catch ( RuntimeException $e ) {
		$statuses[] = $e->getMessage();
	}
}
$_GET = array();
remove_filter( 'wp_die_handler', $die_handler );
remove_all_filters( 'vcap_client_ip' );
vcap_security_assert( 'die:403' === $statuses[0] && 'die:429' === $statuses[30], 'guest PDF links are rate limited per visitor (30 per 10 minutes)' );

// --- C9: a logged-in customer with an expired nonce (cached page) gets an answer instead of nothing.
$c9_user = wp_insert_user( array( 'user_login' => 'vcap-c9-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ) );
wp_set_current_user( $c9_user );
$_POST         = array( 'vcap_balance_code' => $dl_voucher->code, 'vcap_balance_nonce' => 'expired' );
$c9_downloads  = new VCAP\Downloads( vcap()->settings, vcap()->vouchers, vcap()->pdf );
$c9_downloads->handle_balance();
$_POST = array();
$c9_answer = $c9_downloads->balance_shortcode();
wp_set_current_user( 0 );
vcap_security_assert( str_contains( $c9_answer, 'vcap-balance-result' ), 'balance check: a logged-in customer whose nonce expired is answered like a guest' );

// --- R6: promotion limits per customer keep no plain-text email addresses.
$r6_email = 'r6-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.test';
$r6_keys  = VCAP\Discounts\Usage::customer_keys( 0, $r6_email );
vcap_security_assert( str_starts_with( $r6_keys[0], 'h:' ) && ! str_contains( $r6_keys[0], $r6_email ), 'the stored customer key of a guest is a keyed hash, not the address' );
VCAP\Discounts\Usage::add( 990001, $r6_keys[0], 0 );
$usage_table = VCAP\Install::tables()['usage'];
$wpdb->insert( $usage_table, array( 'campaign_id' => 990001, 'customer_key' => 'e:' . $r6_email, 'order_id' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
vcap_security_assert( 2 === VCAP\Discounts\Usage::count_for( 990001, $r6_keys ), 'uses under the hashed key and plain-text keys of earlier versions both count' );
$privacy->erase( $r6_email, 1 );
$plain = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage_table} WHERE customer_key LIKE %s", '%' . $wpdb->esc_like( $r6_email ) . '%' ) );
vcap_security_assert( 0 === $plain && 2 === VCAP\Discounts\Usage::count_for( 990001, $r6_keys ), 'the eraser converts plain-text keys: the address is gone, the limit still counts' );
$wpdb->insert( $usage_table, array( 'campaign_id' => 990001, 'customer_key' => 'e:other-' . $r6_email, 'order_id' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
VCAP\Discounts\Usage::hash_legacy_keys();
vcap_security_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage_table} WHERE customer_key LIKE %s", 'e:%' ) ), 'the daily maintenance converts all plain-text keys' );
$wpdb->delete( $usage_table, array( 'campaign_id' => 990001 ) );

// M12-17: a stale "notoptions" cache (object cache race, failed SELECT) never makes the plugin overwrite a secret
// or a "since" option – add_option() would skip its existence check and run INSERT … ON DUPLICATE KEY UPDATE.
$m17_raw = static fn( string $name ) => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
$m17_poison = static function ( string $name ) {
	wp_cache_delete( $name, 'options' );
	$alloptions = wp_load_alloptions();
	unset( $alloptions[ $name ] );
	wp_cache_set( 'alloptions', $alloptions, 'options' );
	$notoptions          = (array) wp_cache_get( 'notoptions', 'options' );
	$notoptions[ $name ] = true;
	wp_cache_set( 'notoptions', $notoptions, 'options' );
};
vcap()->settings->secret();
VCAP\CustomerEmail::secret();
VCAP\PersonalVoucher::since();
$m17_before = array();
foreach ( array( 'vcap_secret', VCAP\CustomerEmail::KEY_OPTION, VCAP\PersonalVoucher::OPTION_SINCE, VCAP\Delivery::ROUTING_SINCE_OPTION ) as $m17_name ) {
	$m17_before[ $m17_name ] = (string) $m17_raw( $m17_name );
}
$m17_poison( 'vcap_secret' );
$m17_secret = vcap()->settings->secret();
$m17_poison( VCAP\CustomerEmail::KEY_OPTION );
$m17_key = VCAP\CustomerEmail::secret();
$m17_poison( VCAP\PersonalVoucher::OPTION_SINCE );
$m17_since = VCAP\PersonalVoucher::since();
$m17_poison( VCAP\Delivery::ROUTING_SINCE_OPTION );
( new VCAP\Delivery( vcap()->settings, vcap()->vouchers ) )->register();
vcap_security_assert( '' !== $m17_before['vcap_secret'] && $m17_secret === $m17_before['vcap_secret'] && $m17_raw( 'vcap_secret' ) === $m17_before['vcap_secret'], 'M12-17: with "vcap_secret" pre-seeded in notoptions the link secret is read back, never overwritten' );
vcap_security_assert( '' !== $m17_before[ VCAP\CustomerEmail::KEY_OPTION ] && $m17_key === $m17_before[ VCAP\CustomerEmail::KEY_OPTION ] && $m17_raw( VCAP\CustomerEmail::KEY_OPTION ) === $m17_before[ VCAP\CustomerEmail::KEY_OPTION ], 'M12-17: the customer key secret is never overwritten either' );
vcap_security_assert( (int) $m17_before[ VCAP\PersonalVoucher::OPTION_SINCE ] > 0 && $m17_since === (int) $m17_before[ VCAP\PersonalVoucher::OPTION_SINCE ] && $m17_raw( VCAP\PersonalVoucher::OPTION_SINCE ) === $m17_before[ VCAP\PersonalVoucher::OPTION_SINCE ], 'M12-17: the email-proof "since" date is never overwritten' );
vcap_security_assert( '' !== $m17_before[ VCAP\Delivery::ROUTING_SINCE_OPTION ] && $m17_raw( VCAP\Delivery::ROUTING_SINCE_OPTION ) === $m17_before[ VCAP\Delivery::ROUTING_SINCE_OPTION ], 'M12-17: the routing "since" date is never overwritten' );
// A missing option is created once; an empty row is filled; nothing stored = '' (the caller then uses a runtime value).
$m17_name = 'vcap_m17_probe_' . wp_generate_password( 6, false, false );
vcap_security_assert( 'first' === VCAP\Settings::insert_once( $m17_name, 'first', false ) && 'first' === VCAP\Settings::insert_once( $m17_name, 'second', false ), 'M12-17: insert_once() stores a value once and then returns the stored one' );
update_option( $m17_name, '' );
vcap_security_assert( 'third' === VCAP\Settings::insert_once( $m17_name, 'third', false ), 'M12-17: an empty row is filled (no link can have been signed with it)' );
delete_option( $m17_name );
$wpdb->suppress_errors( true );
$m17_prefix     = $wpdb->options;
$wpdb->options  = $wpdb->prefix . 'vcap_no_such_table';
$m17_none       = VCAP\Settings::insert_once( $m17_name, 'x', false );
$wpdb->options  = $m17_prefix;
$wpdb->suppress_errors( false );
vcap_security_assert( '' === $m17_none, 'M12-17: when nothing can be stored insert_once() returns "" (runtime value, nothing overwritten)' );

// S17: a role that may edit products but does not manage vouchers cannot change the voucher value of a product,
// nor PDF templates or field groups.
add_role( 'vcap_s17_vendor', 'S17 vendor', array( 'read' => true, 'edit_products' => true, 'edit_others_products' => true, 'edit_published_products' => true, 'publish_products' => true ) );
$s17_vendor  = wp_insert_user( array( 'user_login' => 'vcap_s17_' . strtolower( wp_generate_password( 6, false, false ) ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'vcap_s17_vendor' ) );
$s17_product = new WC_Product_Simple();
$s17_product->set_name( 'S17 voucher' );
$s17_product->set_regular_price( '5' );
$s17_product->update_meta_data( '_vcap_enabled', 'yes' );
$s17_product->update_meta_data( '_vcap_amount_source', 'price' );
$s17_product->update_meta_data( '_vcap_amount', '' );
$s17_product->save();
$s17_user = get_current_user_id();
wp_set_current_user( $s17_vendor );
$_POST = array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_amount_source' => 'fixed', '_vcap_amount' => '500' );
( new VCAP\ProductSettings() )->save( $s17_product );
$_POST = array();
$s17_template = (int) VCAP\TemplatePostType::first_template_id();
$s17_vendor_template = $s17_template && current_user_can( 'edit_post', $s17_template );
wp_set_current_user( $s17_user );
vcap_security_assert( 'price' === (string) $s17_product->get_meta( '_vcap_amount_source' ) && '' === (string) $s17_product->get_meta( '_vcap_amount' ), 'S17: without vcap_manage_vouchers/manage_woocommerce the voucher value and its source stay unchanged' );
vcap_security_assert( ! $s17_vendor_template && ! user_can( $s17_vendor, get_post_type_object( VCAP\TemplatePostType::POST_TYPE )->cap->edit_posts ) && ! user_can( $s17_vendor, get_post_type_object( VCAP\Fields::POST_TYPE )->cap->create_posts ), 'S17: PDF templates and field groups need the voucher managers\' right, not edit_products' );
wp_delete_user( $s17_vendor );
remove_role( 'vcap_s17_vendor' );

echo "Voucher Captain security and privacy checks passed.\n";
