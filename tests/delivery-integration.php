<?php
/**
 * Delivery robustness (fourth/sixth expert review B1, D1, H2):
 * - a failed delivery is retried by an own Action Scheduler action with growing pauses and a per-voucher attempt
 *   counter (from the ledger); after the last attempt it is given up, recorded and shown to the shop;
 * - a new delivery (reschedule) starts the attempts anew; the daily safety net takes never-attempted vouchers first,
 *   skips given-up ones and vouchers with a pending action;
 * - the daily maintenance runs each step isolated: a failing step is logged and remembered, the others still run;
 *   an expiry reminder that throws gives its claim back;
 * - restore after a reactivation runs in Action Scheduler batches, also for overdue scheduled vouchers;
 * - a voucher that expired before its delivery date is not mailed; the order gets a note.
 *
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/delivery-integration.php
 */

use VCAP\Delivery;
use VCAP\Voucher;

function vcap_dlv_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_dlv_voucher( string $email, array $props = array() ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-dlv-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 25 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = (int) ( $props['order_item_id'] ?? 83000000 + $coupon->get_id() );
	$v->item_index     = (int) ( $props['item_index'] ?? 0 );
	$v->recipient_email = (string) ( $props['recipient_email'] ?? '' );
	$v->recipient_name  = (string) ( $props['recipient_name'] ?? '' );
	$v->order_id       = (int) ( $props['order_id'] ?? 0 );
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = 25;
	$v->balance        = 25;
	$v->currency       = get_woocommerce_currency();
	$v->status         = $props['status'] ?? Voucher::STATUS_ACTIVE;
	$v->delivery_at    = $props['delivery_at'] ?? null;
	$v->expires_at     = $props['expires_at'] ?? null;
	$v->created_at     = $props['created_at'] ?? gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
	$v->buyer_email    = $email;
	$v->send_to        = (string) ( $props['send_to'] ?? 'buyer' );
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	if ( ! $v->id ) {
		throw new RuntimeException( 'Could not create delivery test voucher.' );
	}
	return $v;
}

function vcap_dlv_order(): WC_Order {
	$order = wc_create_order();
	$order->set_billing_email( 'dlv-order@example.com' );
	$order->save();
	return $order;
}

function vcap_dlv_notes( int $order_id ): string {
	return implode( "\n", array_map( static fn( $n ) => (string) $n->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) ) );
}

function vcap_dlv_events( int $voucher_id, string $event ): int {
	return count( array_filter( vcap()->vouchers->events( $voucher_id ), static fn( $e ) => $event === $e['event'] ) );
}

global $wpdb;
$repo  = vcap()->vouchers;
$tag   = strtolower( wp_generate_password( 6, false, false ) );
$mails = array();
$fail  = static fn() => false;
$catch = static function ( $pre, $atts ) use ( &$mails ) {
	$mails[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
	return true;
};
$retry_at = static function ( int $voucher_id ): int {
	$next = as_next_scheduled_action( Delivery::RETRY_HOOK, array( 'voucher_id' => $voucher_id ), Delivery::GROUP );
	return is_int( $next ) ? $next : 0;
};

/* ---- 1. Retry with growing pauses ------------------------------------------------------------------------------ */

$order   = vcap_dlv_order();
$failing = vcap_dlv_voucher( "fail-{$tag}@example.com", array( 'order_id' => $order->get_id() ) );
add_filter( 'pre_wp_mail', $fail, 999 );
vcap()->delivery->deliver( $failing->id );
$first = $retry_at( $failing->id );
vcap_dlv_assert( null === $repo->get( $failing->id )->delivered_at && 1 === $repo->delivery_attempts( $failing->id )['failures'], 'a failed delivery is recorded as the first attempt and not marked delivered' );
vcap_dlv_assert( $first >= time() + 14 * MINUTE_IN_SECONDS && $first <= time() + 16 * MINUTE_IN_SECONDS, 'the first retry is planned in 15 minutes (own Action Scheduler action)', $first - time() );
vcap()->delivery->deliver( $failing->id );
$second  = $retry_at( $failing->id );
$pending = as_get_scheduled_actions( array( 'hook' => Delivery::RETRY_HOOK, 'args' => array( 'voucher_id' => $failing->id ), 'group' => Delivery::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
vcap_dlv_assert( $second >= time() + 29 * MINUTE_IN_SECONDS && $second <= time() + 31 * MINUTE_IN_SECONDS && 1 === count( $pending ), 'the pause doubles after the second failure and only one retry is pending', array( $second - time(), count( $pending ) ) );

/* ---- 2. Give up after the last attempt -------------------------------------------------------------------------- */

$three = static fn() => 3;
add_filter( 'vcap_delivery_max_attempts', $three );
vcap()->delivery->deliver( $failing->id );
remove_filter( 'vcap_delivery_max_attempts', $three );
$state = $repo->delivery_attempts( $failing->id );
vcap_dlv_assert( $state['given_up'] && 3 === $state['failures'] && 1 === vcap_dlv_events( $failing->id, 'delivery_given_up' ), 'after the last failed attempt the delivery is given up (ledger event)', $state );
vcap_dlv_assert( 0 === $retry_at( $failing->id ), 'a given-up delivery has no pending retry' );
vcap_dlv_assert( str_contains( vcap_dlv_notes( $order->get_id() ), VCAP\Mask::code( $failing->code ) ) && ! str_contains( strtoupper( vcap_dlv_notes( $order->get_id() ) ), strtoupper( $failing->code ) ), 'the order gets a note that the voucher is no longer sent automatically (code masked, AP-S3-3)' );
delete_transient( 'vcap_given_up_count' );
vcap_dlv_assert( $repo->given_up_count() >= 1 && ! in_array( $failing->id, $repo->due_for_delivery( 500 ), true ), 'given-up deliveries are counted for the admin notice and skipped by the safety net' );
vcap()->delivery->deliver( $failing->id ); // A late action (e.g. queued before) neither retries nor gives up twice.
vcap_dlv_assert( 0 === $retry_at( $failing->id ) && 1 === vcap_dlv_events( $failing->id, 'delivery_given_up' ), 'a further failure of a given-up delivery plans nothing new' );
remove_filter( 'pre_wp_mail', $fail, 999 );

/* ---- 3. A new delivery starts anew ---------------------------------------------------------------------------- */

vcap()->delivery->reschedule( $repo->get( $failing->id ), null );
$state = $repo->delivery_attempts( $failing->id );
vcap_dlv_assert( ! $state['given_up'] && 0 === $state['failures'], 'rescheduling the delivery resets the attempts', $state );
add_filter( 'pre_wp_mail', $catch, 999, 2 );
vcap()->delivery->deliver( $failing->id );
vcap_dlv_assert( null !== $repo->get( $failing->id )->delivered_at, 'after the reset the voucher is delivered' );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $failing->id ), Delivery::GROUP );

/* ---- 4. Safety net: order, pending actions, no daily cap ---------------------------------------------------- */

$attempted = vcap_dlv_voucher( "net-old-{$tag}@example.com" );
$repo->log( $attempted->id, 'delivery_failed', null, null, 'test' );
$fresh     = vcap_dlv_voucher( "net-new-{$tag}@example.com" );
$queued    = vcap_dlv_voucher( "net-queued-{$tag}@example.com" );
as_schedule_single_action( time() + HOUR_IN_SECONDS, 'vcap_deliver_voucher', array( 'voucher_id' => $queued->id ), Delivery::GROUP );
$due = $repo->due_for_delivery( 1000 );
vcap_dlv_assert( false !== array_search( $fresh->id, $due, true ) && array_search( $fresh->id, $due, true ) < array_search( $attempted->id, $due, true ), 'the safety net takes never-attempted vouchers before ones that failed already' );
vcap_dlv_assert( ! in_array( $fresh->id, $repo->due_for_delivery( 1000, array( $fresh->id ) ), true ), 'vouchers handled in this run are not fetched again' );
$mails = array();
vcap_dlv_assert( vcap()->delivery->deliver_due( 60.0 ), 'the safety net works through all due vouchers within its time budget' );
vcap_dlv_assert( null !== $repo->get( $fresh->id )->delivered_at && null !== $repo->get( $attempted->id )->delivered_at, 'the safety net delivers lost deliveries' );
vcap_dlv_assert( null === $repo->get( $queued->id )->delivered_at && ! in_array( "net-queued-{$tag}@example.com", $mails, true ), 'a voucher with a pending delivery action is left to that action' );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $queued->id ), Delivery::GROUP );

/* ---- 5. Maintenance: one step fails, the others run ------------------------------------------------------------ */

$expired_active = vcap_dlv_voucher( "maint-{$tag}@example.com", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'delivered_at' => gmdate( 'Y-m-d H:i:s', time() - 59 * DAY_IN_SECONDS ) ), array( 'id' => $expired_active->id ) );
$boom = static function () {
	throw new RuntimeException( 'claims step exploded' );
};
add_filter( 'vcap_claim_retention_days', $boom );
$results = array();
foreach ( Delivery::MAINTENANCE_STEPS as $step ) {
	$results[ $step ] = vcap()->delivery->run_maintenance_step( $step );
}
remove_filter( 'vcap_claim_retention_days', $boom );
$runs = Delivery::last_runs();
vcap_dlv_assert( false === $results['claims'] && false === $runs['claims']['ok'] && str_contains( $runs['claims']['note'], 'claims step exploded' ), 'a failing maintenance step is caught and remembered with its error', $runs['claims'] );
vcap_dlv_assert( $results['expire_overdue'] && $runs['expire_overdue']['ok'] && Voucher::STATUS_EXPIRED === $repo->get( $expired_active->id )->status, 'the steps after the failing one still run (overdue vouchers expire)' );
vcap_dlv_assert( $runs['expiry_reminders']['ok'] && $runs['expiry_reminders']['time'] >= time() - MINUTE_IN_SECONDS, 'the last step ran and its last run is recorded for the diagnostics' );
$info = ( new VCAP\Diagnostics() )->fields();
vcap_dlv_assert( str_contains( $info['maintenance']['value'], 'claims:' ) && str_contains( $info['maintenance']['value'], 'failed' ) && isset( $info['deliveries_given_up'] ), 'Site Health info shows the last run per step and the given-up deliveries' );
as_unschedule_all_actions( Delivery::STEP_HOOK );
vcap()->delivery->maintenance();
vcap_dlv_assert( as_has_scheduled_action( Delivery::STEP_HOOK, array( 'step' => 'tmp_pdfs' ), Delivery::GROUP ) && as_has_scheduled_action( Delivery::STEP_HOOK, array( 'step' => 'expiry_reminders' ), Delivery::GROUP ), 'the daily maintenance queues one action per step' );
as_unschedule_all_actions( Delivery::STEP_HOOK );

// An expiry reminder that throws gives its claim back; the other vouchers are still reminded.
$keep_days = get_option( 'vcap_expiry_reminder_days', false );
update_option( 'vcap_expiry_reminder_days', 14 );
$broken = vcap_dlv_voucher( "broken-{$tag}@example.com", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
$fine   = vcap_dlv_voucher( "fine-{$tag}@example.com", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 6 * DAY_IN_SECONDS ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . VCAP\Install::tables()['vouchers'] . ' SET delivered_at = %s WHERE id IN (%d, %d)', gmdate( 'Y-m-d H:i:s' ), $broken->id, $fine->id ) );
$throw_for = static function ( $recipient ) use ( $tag ) {
	if ( str_contains( (string) $recipient, "broken-{$tag}" ) ) {
		throw new RuntimeException( 'template error' );
	}
	return $recipient;
};
add_filter( 'woocommerce_email_recipient_vcap_voucher_expiry', $throw_for, 999 );
$mails = array();
vcap()->delivery->send_expiry_reminders();
remove_filter( 'woocommerce_email_recipient_vcap_voucher_expiry', $throw_for, 999 );
vcap_dlv_assert( in_array( "fine-{$tag}@example.com", $mails, true ), 'an exception in one reminder does not stop the others' );
vcap_dlv_assert( VCAP\Claim::take( 'expiry-reminder:' . $broken->id ), 'the claim of the failed reminder is released (it is retried next time)' );
VCAP\Claim::release( 'expiry-reminder:' . $broken->id );
false === $keep_days ? delete_option( 'vcap_expiry_reminder_days' ) : update_option( 'vcap_expiry_reminder_days', $keep_days );

/* ---- 6. Restore in batches, overdue scheduled vouchers included ----------------------------------------------- */

$overdue = vcap_dlv_voucher( "restore-{$tag}@example.com", array( 'status' => Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) ) );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $overdue->id ), Delivery::GROUP );
$next = array( 'vouchers', 0 );
while ( $next && 'vouchers' === $next[0] ) {
	$next = vcap()->delivery->restore_tasks( $next[0], $next[1] );
}
vcap_dlv_assert( array( 'redemptions', 0 ) === $next && as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $overdue->id ), Delivery::GROUP ), 'restore queues the delivery of a scheduled voucher that became due long ago, then goes on with the next phase', $next );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $overdue->id ), Delivery::GROUP );
as_unschedule_all_actions( Delivery::RESTORE_HOOK );

/* ---- 7. Expired before its delivery date: not sent ----------------------------------------------------------- */

$late_order = vcap_dlv_order();
$late       = vcap_dlv_voucher(
	"late-{$tag}@example.com",
	array(
		'order_id'    => $late_order->get_id(),
		'status'      => Voucher::STATUS_SCHEDULED,
		'delivery_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
		'expires_at'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	)
);
vcap()->coupons->set_coupon_enabled( $late, false );
$mails = array();
vcap()->delivery->deliver( $late->id );
$stored = $repo->get( $late->id );
vcap_dlv_assert( ! in_array( "late-{$tag}@example.com", $mails, true ) && null === $stored->delivered_at, 'a voucher that expired before its delivery date is not mailed' );
vcap_dlv_assert( Voucher::STATUS_EXPIRED === $stored->status && 'publish' !== get_post_status( $late->coupon_id ) && 1 === vcap_dlv_events( $late->id, 'delivery_expired' ), 'it is marked expired (coupon stays off) and the ledger says why' );
vcap_dlv_assert( str_contains( vcap_dlv_notes( $late_order->get_id() ), VCAP\Mask::code( $late->code ) ) && ! str_contains( strtoupper( vcap_dlv_notes( $late_order->get_id() ) ), strtoupper( $late->code ) ), 'the order gets a note for the shop (code masked, AP-S3-3)' );
vcap_dlv_assert( ! in_array( $late->id, $repo->due_for_delivery( 1000 ), true ), 'the safety net does not pick it up again' );

// U12: one routing choice fixed on the voucher – the old "Buyer copy" flag of the product no longer reaches sold vouchers.
$u12_product = new WC_Product_Simple();
$u12_product->set_name( 'U12 gift' );
$u12_product->set_regular_price( '25' );
$u12_product->set_virtual( true );
$u12_product->update_meta_data( '_vcap_enabled', 'yes' );
$u12_product->update_meta_data( '_vcap_send_to', 'recipient' );
$u12_product->save();
vcap_dlv_assert( 'both' === VCAP\ProductConfig::get( $u12_product )['send_to'], 'U12: "recipient" with the old default buyer copy reads as "Recipient and buyer"' );
$u12_route = static function ( string $send_to, string $created ) use ( $u12_product, $tag ) {
	$v = vcap_dlv_voucher( "u12-buyer-{$tag}@example.com", array( 'created_at' => $created ) );
	$v->product_id      = $u12_product->get_id();
	$v->recipient_email = "u12-recipient-{$tag}@example.com";
	$v->send_to         = $send_to;
	vcap()->vouchers->update( $v );
	return vcap()->vouchers->get( $v->id );
};
$u12_only = $u12_route( 'recipient', gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) );
$mails    = array();
vcap()->delivery->send( $u12_only );
vcap_dlv_assert( array( "u12-recipient-{$tag}@example.com" ) === $mails, 'U12: a voucher issued for "recipient only" is not copied to the buyer although the product still has the old buyer copy flag', $mails );
$mails = array();
vcap()->delivery->send( $u12_route( 'both', gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ) ) );
vcap_dlv_assert( 2 === count( $mails ), 'U12: "Recipient and buyer" on the voucher mails both', $mails );
$mails = array();
vcap()->delivery->send( $u12_route( 'recipient', '2020-01-01 00:00:00' ) );
vcap_dlv_assert( 2 === count( $mails ), 'U12: vouchers issued before the change keep the buyer copy of their product', $mails );

/* ---- 8. P7: failure reasons, PDF trace, expiry and redemption hooks, issuing retry, manual sends --------------- */

$p7_last_note = static function ( int $voucher_id, string $event ) use ( $repo ): array {
	$rows = array_values( array_filter( $repo->events( $voucher_id ), static fn( $e ) => $event === $e['event'] ) );
	$row  = $rows ? end( $rows ) : array();
	return array( (string) ( $row['note'] ?? '' ), $row );
};

// D4: the reason of a failed delivery is kept per role (mail system vs. email disabled).
$d4 = vcap_dlv_voucher( "d4-{$tag}@example.com" );
add_filter( 'pre_wp_mail', $fail, 1000 );
vcap()->delivery->deliver( $d4->id );
remove_filter( 'pre_wp_mail', $fail, 1000 );
list( $d4_note ) = $p7_last_note( $d4->id, 'delivery_failed' );
vcap_dlv_assert( VCAP\Note::is_coded( $d4_note ) && str_contains( $d4_note, 'mail_failed' ) && str_contains( VCAP\Note::render( $d4_note ), 'the mail system did not accept the email' ), 'D4: a refused mail is recorded as "mail system" failure, not as "no valid address"', VCAP\Note::render( $d4_note ) );
$d4_off = static fn() => false;
add_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $d4_off, 999 );
vcap()->delivery->deliver( $d4->id );
remove_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $d4_off, 999 );
list( $d4_note ) = $p7_last_note( $d4->id, 'delivery_failed' );
vcap_dlv_assert( str_contains( VCAP\Note::render( $d4_note ), 'Buyer: email disabled' ), 'D4: a disabled email is recorded as such', VCAP\Note::render( $d4_note ) );
as_unschedule_all_actions( Delivery::RETRY_HOOK, array( 'voucher_id' => $d4->id ), Delivery::GROUP );

// D5: a mail that goes out without its PDF says so in the history.
if ( class_exists( '\Dompdf\Dompdf' ) ) {
	$d5       = vcap_dlv_voucher( "d5-{$tag}@example.com" );
	$d5_throw = static function () {
		throw new RuntimeException( 'pdf template broken' );
	};
	add_filter( 'vcap_pdf_html', $d5_throw );
	vcap()->delivery->deliver( $d5->id );
	remove_filter( 'vcap_pdf_html', $d5_throw );
	vcap_dlv_assert( null !== $repo->get( $d5->id )->delivered_at && 1 === vcap_dlv_events( $d5->id, 'delivered_without_pdf' ), 'D5: a voucher mailed without its PDF is delivered and the history records "delivered without PDF"' );
}

// D7: expiry writes an "expired" entry per voucher and fires vcap_voucher_expired once per batch.
$d7         = vcap_dlv_voucher( "d7-{$tag}@example.com", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
$d7_expired = array();
$d7_listen  = static function ( array $vouchers ) use ( &$d7_expired ) {
	$d7_expired = array_merge( $d7_expired, array_map( static fn( $v ) => (int) $v->id, $vouchers ) );
};
add_action( 'vcap_voucher_expired', $d7_listen );
$repo->expire_overdue();
remove_action( 'vcap_voucher_expired', $d7_listen );
vcap_dlv_assert( Voucher::STATUS_EXPIRED === $repo->get( $d7->id )->status && 1 === vcap_dlv_events( $d7->id, 'expired' ) && in_array( $d7->id, $d7_expired, true ), 'D7: an overdue voucher expires with an "expired" history entry and vcap_voucher_expired' );

// D7 / D8: counter and shop redemptions fire vcap_voucher_redeemed with their channel; the shop one keeps its order.
$redeemed  = array();
$d7_hook   = static function ( array $data ) use ( &$redeemed ) {
	$redeemed[] = $data;
};
add_action( 'vcap_voucher_redeemed', $d7_hook );
$d7_single = vcap_dlv_voucher( "d7s-{$tag}@example.com" );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'usage_mode' => 'single' ), array( 'id' => $d7_single->id ) );
vcap()->tickets->redeem_single( $repo->get( $d7_single->id ), 'till 1' );
$d8_shop   = vcap_dlv_voucher( "d8s-{$tag}@example.com" );
$wpdb->update( VCAP\Install::tables()['vouchers'], array( 'usage_mode' => 'single' ), array( 'id' => $d8_shop->id ) );
$d8_coupon = new WC_Coupon( $d8_shop->coupon_id );
$d8_coupon->set_usage_limit( 1 );
$d8_coupon->save();
$d8_order  = vcap_dlv_order();
$d8_item = new WC_Order_Item_Coupon();
$d8_item->set_code( $d8_shop->code );
$d8_order->add_item( $d8_item );
$d8_order->save();
vcap()->coupons->remember_usage_order( $d8_order->get_id() );
$d8_coupon = new WC_Coupon( $d8_shop->coupon_id );
$d8_coupon->increase_usage_count( 'dlv-order@example.com', $d8_order );
remove_action( 'vcap_voucher_redeemed', $d7_hook );
$d7_channels = array_column( $redeemed, 'channel', 'voucher_id' );
list( , $d8_row ) = $p7_last_note( $d8_shop->id, 'redeemed' );
vcap_dlv_assert( 'counter' === ( $d7_channels[ $d7_single->id ] ?? '' ) && 'shop' === ( $d7_channels[ $d8_shop->id ] ?? '' ), 'D7: vcap_voucher_redeemed fires for counter and shop redemptions with their channel', $d7_channels );
vcap_dlv_assert( (int) ( $d8_row['order_id'] ?? 0 ) === $d8_order->get_id(), 'D8: a single-use voucher redeemed in the shop is recorded with its order', $d8_row );
vcap_dlv_assert( 'cli' === ( $d8_row['source'] ?? '' ), 'D8: the history entry records its source (here WP-CLI)', $d8_row );

// D3: a voucher whose coupon cannot be saved is undone with vcap_voucher_deleted, the order is listed and retried.
$d3_product = new WC_Product_Simple();
$d3_product->set_name( 'D3 voucher' );
$d3_product->set_regular_price( '20' );
$d3_product->set_virtual( true );
$d3_product->update_meta_data( '_vcap_enabled', 'yes' );
$d3_product->update_meta_data( '_vcap_type', 'voucher' );
$d3_product->save();
$d3_order = wc_create_order();
$d3_order->add_product( $d3_product, 1 );
$d3_order->set_billing_email( "d3-{$tag}@example.com" );
$d3_order->calculate_totals( false );
$d3_deleted = array();
$d3_listen  = static function ( $voucher ) use ( &$d3_deleted ) {
	$d3_deleted[] = (int) $voucher->id;
};
$d3_break   = static function ( $data ) {
	if ( $data instanceof WC_Coupon && ! $data->get_id() ) {
		throw new RuntimeException( 'coupon save refused' );
	}
};
add_action( 'vcap_voucher_deleted', $d3_listen );
add_action( 'woocommerce_before_data_object_save', $d3_break );
$d3_order->set_status( 'processing' ); // Issues through the status hook.
$d3_order->save();
remove_action( 'woocommerce_before_data_object_save', $d3_break );
remove_action( 'vcap_voucher_deleted', $d3_listen );
$d3_created = $repo->for_order( $d3_order->get_id() );
$d3_next = as_next_scheduled_action( VCAP\Issuer::ISSUE_RETRY_HOOK, array( 'order_id' => $d3_order->get_id() ), Delivery::GROUP );
vcap_dlv_assert( ! $d3_created && 1 === vcap()->issuer->last_failures && 1 === count( $d3_deleted ), 'D3: a voucher undone after its coupon failed fires vcap_voucher_deleted', array( count( $d3_created ), vcap()->issuer->last_failures, $d3_deleted ) );
vcap_dlv_assert( is_int( $d3_next ) && $d3_next > time() && VCAP\Attention::has( $d3_order->get_id(), 'issue' ), 'D3: the order is listed as paid without voucher and the promised retry is scheduled', $d3_next );
vcap()->issuer->issue_async( array( 'order_id' => $d3_order->get_id() ) );
vcap_dlv_assert( 1 === count( $repo->for_order( $d3_order->get_id() ) ) && ! VCAP\Attention::has( $d3_order->get_id(), 'issue' ) && ! as_next_scheduled_action( VCAP\Issuer::ISSUE_RETRY_HOOK, array( 'order_id' => $d3_order->get_id() ), Delivery::GROUP ), 'D3: the retry issues the voucher and clears the listing' );
foreach ( $repo->for_order( $d3_order->get_id() ) as $d3_voucher ) {
	as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $d3_voucher->id ), Delivery::GROUP );
}

// H7: manual sends respect the status; "Send now" brings a scheduled gift forward; a first manual send counts.
$h7_admin = new VCAP\Admin\VoucherAdmin( $repo );
$h7_sched = vcap_dlv_voucher( "h7s-{$tag}@example.com", array( 'status' => Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
vcap()->coupons->set_coupon_enabled( $h7_sched, false );
$mails = array();
vcap_dlv_assert( false === $h7_admin->resend( $repo->get( $h7_sched->id ), 'buyer' ) && ! in_array( "h7s-{$tag}@example.com", $mails, true ), 'H7: "Email buyer" does not send a scheduled gift early' );
vcap_dlv_assert( vcap()->delivery->deliver_now( $repo->get( $h7_sched->id ) ) && Voucher::STATUS_ACTIVE === $repo->get( $h7_sched->id )->status && null !== $repo->get( $h7_sched->id )->delivered_at && 'publish' === get_post_status( $h7_sched->coupon_id ), 'H7: "Send now" activates, mails and marks the scheduled voucher delivered' );
$h7_void = vcap_dlv_voucher( "h7v-{$tag}@example.com", array( 'status' => Voucher::STATUS_VOID ) );
$mails   = array();
vcap_dlv_assert( false === $h7_admin->resend( $repo->get( $h7_void->id ), 'buyer' ) && ! $mails, 'H7: a void voucher is not mailed by hand' );
$h7_first = vcap_dlv_voucher( "h7f-{$tag}@example.com" );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $h7_first->id ), Delivery::GROUP );
vcap_dlv_assert( $h7_admin->resend( $repo->get( $h7_first->id ), 'buyer' ) && null !== $repo->get( $h7_first->id )->delivered_at && ! in_array( $h7_first->id, $repo->due_for_delivery( 1000 ), true ), 'H7: a first delivery by hand marks the voucher delivered (the safety net does not send it again)' );
list( $h7_note ) = $p7_last_note( $h7_first->id, 'resent' );
vcap_dlv_assert( ! str_contains( $h7_note, "h7f-{$tag}@example.com" ) && str_contains( VCAP\Note::render( $h7_note ), 'h***@example.com' ), 'D8: the resend note masks the address', VCAP\Note::render( $h7_note ) );
vcap()->delivery->reschedule( $repo->get( $h7_first->id ), gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
vcap_dlv_assert( Voucher::STATUS_ACTIVE === $repo->get( $h7_first->id )->status && 'publish' === get_post_status( $h7_first->coupon_id ), 'H7: planning the email of a delivered voucher again does not lock its card' );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $h7_first->id ), Delivery::GROUP );

// Z12: a corrected recipient address on the order item is used for the voucher.
$z12_order = wc_create_order();
$z12_order->add_product( $d3_product, 1 );
$z12_order->save();
$z12_item = current( $z12_order->get_items() );
$z12_item->add_meta_data( '_vcap_fields', array( 'recipient_email' => array( 'label' => 'Recipient email', 'value' => 'anna@gmial.com' ) ), true );
$z12_item->add_meta_data( 'Recipient email', 'anna@gmial.com', true );
$z12_item->save();
$z12_item = new WC_Order_Item_Product( $z12_item->get_id() );
$z12_item->update_meta_data( 'Recipient email', 'anna@gmail.com' );
$z12_item->save();
$z12_fields = ( new WC_Order_Item_Product( $z12_item->get_id() ) )->get_meta( '_vcap_fields' );
vcap_dlv_assert( 'anna@gmail.com' === ( $z12_fields['recipient_email']['value'] ?? '' ), 'Z12: the corrected label-keyed (pre-M10 order) visible "Recipient email" is taken over into the fields vouchers are issued from', $z12_fields );

// Z12 with M10: orders placed since M10 keep the visible copy under the stable key "vcap_<field_key>".
$z12m_order = wc_create_order();
$z12m_order->add_product( $d3_product, 1 );
$z12m_order->save();
$z12m_item = current( $z12m_order->get_items() );
$z12m_item->add_meta_data( '_vcap_fields', array( 'recipient_email' => array( 'label' => 'Recipient email', 'value' => 'ben@gmial.com' ) ), true );
$z12m_item->add_meta_data( VCAP\Fields::item_meta_key( 'recipient_email' ), 'ben@gmial.com', true );
$z12m_item->save();
$z12m_item = new WC_Order_Item_Product( $z12m_item->get_id() );
$z12m_item->update_meta_data( VCAP\Fields::item_meta_key( 'recipient_email' ), 'ben@gmail.com' );
$z12m_item->save();
$z12m_fields = ( new WC_Order_Item_Product( $z12m_item->get_id() ) )->get_meta( '_vcap_fields' );
vcap_dlv_assert( 'ben@gmail.com' === ( $z12m_fields['recipient_email']['value'] ?? '' ), 'Z12/M10: a corrected value under the stable key vcap_recipient_email is taken over as well', $z12m_fields );
$z12m_item->update_meta_data( VCAP\Fields::item_meta_key( 'recipient_email' ), 'not-an-address' );
$z12m_item->save();
$z12m_fields = ( new WC_Order_Item_Product( $z12m_item->get_id() ) )->get_meta( '_vcap_fields' );
vcap_dlv_assert( 'ben@gmail.com' === ( $z12m_fields['recipient_email']['value'] ?? '' ), 'Z12: an invalid address typed into the visible copy is not taken over', $z12m_fields );

// P12 · V7: vouchers of a new customer's first order above 250 are held for 24 hours (scheduled delivery).
$v7_product = new WC_Product_Simple();
$v7_product->set_name( 'V7 hold voucher' );
$v7_product->set_regular_price( '300' );
$v7_product->set_virtual( true );
$v7_product->update_meta_data( '_vcap_enabled', 'yes' );
$v7_product->save();
$v7_small = new WC_Product_Simple();
$v7_small->set_name( 'V7 small voucher' );
$v7_small->set_regular_price( '100' );
$v7_small->set_virtual( true );
$v7_small->update_meta_data( '_vcap_enabled', 'yes' );
$v7_small->save();
$v7_order = static function ( WC_Product $product, string $email, int $created = 0 ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	if ( $created ) {
		$order->set_date_created( $created );
	}
	$order->calculate_totals();
	$order->save();
	$order->update_status( 'processing' );
	return wc_get_order( $order->get_id() );
};
$v7_held = $v7_order( $v7_product, "v7-new-{$tag}@example.com" );
$v7_vouchers = $repo->for_order( $v7_held->get_id() );
$v7_paid     = $v7_held->get_date_paid() ? $v7_held->get_date_paid()->getTimestamp() : time();
vcap_dlv_assert( 1 === count( $v7_vouchers ) && Voucher::STATUS_SCHEDULED === $v7_vouchers[0]->status && abs( strtotime( $v7_vouchers[0]->delivery_at . ' UTC' ) - ( $v7_paid + DAY_IN_SECONDS ) ) < 120, 'V7: a new customer\'s first order above 250 is issued, but delivered 24 hours after the payment', $v7_vouchers );
vcap_dlv_assert( 'draft' === get_post_status( $v7_vouchers[0]->coupon_id ) && Delivery::is_held( $v7_vouchers[0] ) && (int) $v7_held->get_meta( Delivery::HOLD_META ) > time() && as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $v7_vouchers[0]->id ), Delivery::GROUP ), 'V7: the held voucher is not redeemable until then and its delivery is scheduled' );
$v7_next = implode( ' ', wp_list_pluck( VCAP\NextSteps::for_order( $v7_held ), 'text' ) );
vcap_dlv_assert( str_contains( $v7_next, 'short waiting period' ) && str_contains( $v7_next, VCAP\LocalDate::from_utc( $v7_vouchers[0]->delivery_at, vcap()->settings->date_format() ) ), 'V7: the thank-you page and order emails say when the held voucher comes', $v7_next );
$v7_notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $v7_held->get_id() ) ), 'content' ) );
vcap_dlv_assert( str_contains( $v7_notes, 'Security hold' ), 'V7: the order note explains the hold', $v7_notes );
ob_start();
VCAP\Plugin::template( 'myaccount/vouchers-table.php', array( 'vouchers' => $v7_vouchers, 'downloads' => vcap()->downloads, 'title' => '' ) );
$v7_table = (string) ob_get_clean();
vcap_dlv_assert( ! str_contains( $v7_table, $v7_vouchers[0]->code ) && str_contains( $v7_table, 'Security check' ) && ! str_contains( $v7_table, 'vcap-download-button' ), 'V7: the voucher list shows neither the code nor the PDF of a held voucher' );
vcap()->delivery->deliver_now( $repo->get( $v7_vouchers[0]->id ) );
vcap_dlv_assert( Voucher::STATUS_ACTIVE === $repo->get( $v7_vouchers[0]->id )->status && ! Delivery::is_held( $repo->get( $v7_vouchers[0]->id ) ), 'V7: "Deliver now" releases a held voucher early' );

$v7_low = $v7_order( $v7_small, "v7-low-{$tag}@example.com" );
vcap_dlv_assert( Voucher::STATUS_ACTIVE === ( $repo->for_order( $v7_low->get_id() )[0]->status ?? '' ), 'V7: a first order up to 250 is delivered at once' );
$v7_order( $v7_small, "v7-known-{$tag}@example.com", time() - 3 * DAY_IN_SECONDS );
$v7_known = $v7_order( $v7_product, "V7-Known-{$tag}+shop@example.com" );
vcap_dlv_assert( Voucher::STATUS_ACTIVE === ( $repo->for_order( $v7_known->get_id() )[0]->status ?? '' ) && '0' === (string) $v7_known->get_meta( Delivery::HOLD_META ), 'V7: a customer with an earlier order (also under an alias of the address) is not held' );
$v7_quick_first = $v7_order( $v7_small, "v7-quick-{$tag}@example.com" );
$v7_quick       = $v7_order( $v7_product, "v7-quick-{$tag}@example.com" );
vcap_dlv_assert( Voucher::STATUS_SCHEDULED === ( $repo->for_order( $v7_quick->get_id() )[0]->status ?? '' ), 'V7: an earlier order of minutes ago does not make a customer known' );
update_option( 'vcap_hold_new_customer_above', 0 );
$v7_off = $v7_order( $v7_product, "v7-off-{$tag}@example.com" );
delete_option( 'vcap_hold_new_customer_above' );
vcap_dlv_assert( Voucher::STATUS_ACTIVE === ( $repo->for_order( $v7_off->get_id() )[0]->status ?? '' ), 'V7: 0 switches the hold off' );

/* ---- Z6: bundled delivery of large quantities ------------------------------------------------------------------ */

$z6_mails   = array();
$z6_capture = static function ( $pre, $atts ) use ( &$z6_mails ) {
	$pages = array();
	foreach ( (array) ( $atts['attachments'] ?? array() ) as $file ) {
		$bytes   = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$pages[] = preg_match_all( '#/Type\s*/Page[^s]#', $bytes );
	}
	$z6_mails[] = array(
		'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
		'subject' => (string) $atts['subject'],
		'message' => (string) $atts['message'],
		'pages'   => $pages,
	);
	return $pre;
};
add_filter( 'pre_wp_mail', $z6_capture, 1, 2 );
$z6_to      = static function ( string $address ) use ( &$z6_mails ): int {
	return count( array_filter( $z6_mails, static fn( $m ) => str_contains( $m['to'], $address ) ) );
};
$z6_attach  = get_option( 'vcap_attach_pdf', 'yes' );
update_option( 'vcap_attach_pdf', 'yes' );
$z6_batch = static function ( int $count, array $props ) use ( $tag ): array {
	static $line = 0;
	$line   += 1;
	$item_id = 84000000 + wp_rand( 0, 999999 ) * 10 + $line;
	$out     = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = vcap_dlv_voucher( (string) ( $props['buyer'] ?? "z6-buyer-{$tag}@example.com" ), array_merge( $props, array( 'order_item_id' => $item_id, 'item_index' => $i ) ) );
	}
	return $out;
};

// 1. Six vouchers of one line for the buyer: one email, one PDF with six pages, every voucher recorded on its own.
$z6_order = vcap_dlv_order();
$z6_own   = $z6_batch( 6, array( 'order_id' => $z6_order->get_id(), 'buyer' => "z6-own-{$tag}@example.com" ) );
$z6_mails = array();
foreach ( $z6_own as $z6_v ) {
	vcap()->delivery->deliver( $z6_v->id ); // Every voucher keeps its own action: the first one sends the bundle.
}
vcap_dlv_assert( 1 === count( $z6_mails ) && array( 6 ) === $z6_mails[0]['pages'], 'Z6: six vouchers of one order line for the same buyer go out in one email with one six-page PDF', array_map( static fn( $m ) => array( $m['to'], $m['pages'] ), $z6_mails ) );
$z6_codes = array_filter( $z6_own, static fn( $v ) => str_contains( $z6_mails[0]['message'] ?? '', $v->code ) );
vcap_dlv_assert( 6 === count( $z6_codes ) && str_contains( $z6_mails[0]['subject'], '6' ), 'Z6: the email lists every code in its table and names the number of vouchers', $z6_mails[0]['subject'] ?? '' );
$z6_state = array_map( static fn( $v ) => array( null !== $repo->get( $v->id )->delivered_at, 1 === vcap_dlv_events( $v->id, 'delivered' ) ), $z6_own );
vcap_dlv_assert( array_fill( 0, 6, array( true, true ) ) === $z6_state, 'Z6: each voucher of the bundle is marked delivered and has its own "delivered" history entry', $z6_state );
$z6_note = VCAP\Note::render( (string) ( array_values( array_filter( $repo->events( $z6_own[2]->id ), static fn( $e ) => 'delivered' === $e['event'] ) )[0]['note'] ?? '' ) );
vcap_dlv_assert( str_contains( $z6_note, 'together with 5 other vouchers' ), 'Z6: the history says the voucher went out in one email with the others', $z6_note );

// AP-P1-8: after the bundle the pending delivery actions of the other members are removed (no N-1 no-op actions).
$p18_order = vcap_dlv_order();
$p18       = $z6_batch( 5, array( 'order_id' => $p18_order->get_id(), 'buyer' => "p18-{$tag}@example.com" ) );
foreach ( $p18 as $p18_v ) {
	as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'vcap_deliver_voucher', array( 'voucher_id' => $p18_v->id ), Delivery::GROUP );
}
$z6_mails = array();
vcap()->delivery->deliver( $p18[0]->id );
$p18_left = array_values( array_filter( array_slice( $p18, 1 ), static fn( $v ) => as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $v->id ), Delivery::GROUP ) ) );
vcap_dlv_assert( 1 === count( $z6_mails ) && null !== $repo->get( $p18[4]->id )->delivered_at && ! $p18_left, 'AP-P1-8: the bundled email delivers all five and removes the pending actions of the four other members', array( count( $z6_mails ), count( $p18_left ) ) );
as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $p18[0]->id ), Delivery::GROUP );

// AP-Q2-3: the buyer copy throws after the recipient was mailed – the recipient is recorded and not mailed again.
$q23       = vcap_dlv_voucher( "q23-buyer-{$tag}@example.com", array( 'send_to' => 'both', 'recipient_email' => "q23-rec-{$tag}@example.com", 'recipient_name' => 'Q23' ) );
$q23_roles = static function ( int $voucher_id ): array {
	$read = new ReflectionMethod( vcap()->delivery, 'delivered_roles' );
	$read->setAccessible( true );
	return array_values( (array) $read->invoke( vcap()->delivery, vcap()->vouchers->get( $voucher_id ) ) );
};
$q23_throw = static function ( $recipient ) {
	throw new RuntimeException( 'buyer copy template error' );
};
add_filter( 'woocommerce_email_recipient_vcap_voucher_buyer', $q23_throw, 999 );
$z6_mails = array();
$q23_error = '';
try {
	vcap()->delivery->deliver( $q23->id );
} catch ( Throwable $e ) {
	$q23_error = $e->getMessage();
}
remove_filter( 'woocommerce_email_recipient_vcap_voucher_buyer', $q23_throw, 999 );
vcap_dlv_assert( '' === $q23_error && array( 'recipient' ) === $q23_roles( $q23->id ) && null === $repo->get( $q23->id )->delivered_at && $retry_at( $q23->id ) > time(), 'AP-Q2-3: a throwing buyer copy leaves the recipient email recorded, the voucher undelivered and a retry planned', array( $q23_error, $q23_roles( $q23->id ) ) );
vcap()->delivery->deliver( $q23->id );
vcap_dlv_assert( 1 === $z6_to( "q23-rec-{$tag}@" ) && 1 === $z6_to( "q23-buyer-{$tag}@" ) && null !== $repo->get( $q23->id )->delivered_at, 'AP-Q2-3: the retry mails only the buyer copy – the recipient is not mailed twice', array_column( $z6_mails, 'to' ) );
as_unschedule_all_actions( Delivery::RETRY_HOOK, array( 'voucher_id' => $q23->id ), Delivery::GROUP );

// 2. Gifts with buyer copy: one email to the recipient, exactly one copy to the buyer.
$z6_gift_order = vcap_dlv_order();
$z6_gifts      = $z6_batch( 5, array( 'order_id' => $z6_gift_order->get_id(), 'buyer' => "z6-giver-{$tag}@example.com", 'send_to' => 'both', 'recipient_email' => "z6-lea-{$tag}@example.com", 'recipient_name' => 'Lea' ) );
$z6_mails      = array();
vcap()->delivery->deliver( $z6_gifts[0]->id );
vcap_dlv_assert( 1 === $z6_to( "z6-lea-{$tag}@" ) && 1 === $z6_to( "z6-giver-{$tag}@" ) && 2 === count( $z6_mails ), 'Z6: five gifts with buyer copy: one email to the recipient and exactly one copy to the buyer (not five each)', array_column( $z6_mails, 'to' ) );
$z6_table = VCAP\Install::tables()['vouchers'];
$z6_roles = array_map( static fn( $v ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivered_roles FROM {$z6_table} WHERE id = %d", $v->id ) ), $z6_gifts ); // phpcs:ignore
vcap_dlv_assert( array_fill( 0, 5, 'recipient,buyer' ) === $z6_roles && null !== $repo->get( $z6_gifts[4]->id )->delivered_at, 'Z6: every gift records both roles and is delivered', $z6_roles );

// 3. The recipient's email fails: only that part is retried, per voucher, without a second buyer copy.
$z6_part_order = vcap_dlv_order();
$z6_part       = $z6_batch( 5, array( 'order_id' => $z6_part_order->get_id(), 'buyer' => "z6-pb-{$tag}@example.com", 'send_to' => 'both', 'recipient_email' => "z6-pr-{$tag}@example.com" ) );
$z6_refuse     = static fn( $pre, $atts ) => str_contains( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'], "z6-pr-{$tag}@" ) ? false : $pre;
add_filter( 'pre_wp_mail', $z6_refuse, 2000, 2 );
$z6_mails = array();
vcap()->delivery->deliver( $z6_part[0]->id );
remove_filter( 'pre_wp_mail', $z6_refuse, 2000 );
$z6_open = array_filter( $z6_part, static fn( $v ) => null === $repo->get( $v->id )->delivered_at && 1 === $repo->delivery_attempts( $v->id )['failures'] && $retry_at( $v->id ) > time() );
vcap_dlv_assert( 5 === count( $z6_open ) && 1 === $z6_to( "z6-pb-{$tag}@" ), 'Z6: a failed recipient email leaves every voucher of the bundle undelivered with its own failed attempt and retry; the buyer copy went out once', array( count( $z6_open ), array_column( $z6_mails, 'to' ) ) );
$z6_mails = array();
vcap()->delivery->deliver( $z6_part[3]->id ); // The retry of any voucher of the bundle sends what is missing.
$z6_done = array_filter( $z6_part, static fn( $v ) => null !== $repo->get( $v->id )->delivered_at );
vcap_dlv_assert( 1 === count( $z6_mails ) && 1 === $z6_to( "z6-pr-{$tag}@" ) && 5 === count( $z6_done ), 'Z6: the retry sends only the missing recipient email (no second buyer copy) and delivers all five', array_column( $z6_mails, 'to' ) );

// 4. Only vouchers due together are bundled: scheduled ones wait for their own date.
$z6_sched_order = vcap_dlv_order();
$z6_due         = $z6_batch( 5, array( 'order_id' => $z6_sched_order->get_id(), 'buyer' => "z6-due-{$tag}@example.com" ) );
$z6_later_item  = $z6_due[0]->order_item_id;
$z6_later       = array();
foreach ( array( 5, 6 ) as $z6_idx ) {
	$z6_later[] = vcap_dlv_voucher( "z6-due-{$tag}@example.com", array( 'order_id' => $z6_sched_order->get_id(), 'order_item_id' => $z6_later_item, 'item_index' => $z6_idx, 'status' => Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ) );
}
$z6_mails = array();
vcap()->delivery->deliver( $z6_due[0]->id );
vcap_dlv_assert( 1 === count( $z6_mails ) && array( 5 ) === $z6_mails[0]['pages'] && null === $repo->get( $z6_later[0]->id )->delivered_at && Voucher::STATUS_SCHEDULED === $repo->get( $z6_later[1]->id )->status, 'Z6: the five due vouchers go out together, the two scheduled for later stay scheduled', array_map( static fn( $m ) => $m['pages'], $z6_mails ) );

// 5. Above the PDF limit: no attachment, a download link per voucher instead.
$z6_limit = static fn() => 3;
add_filter( 'vcap_bundle_pdf_limit', $z6_limit );
$z6_big_order = vcap_dlv_order();
$z6_big       = $z6_batch( 5, array( 'order_id' => $z6_big_order->get_id(), 'buyer' => "z6-big-{$tag}@example.com" ) );
$z6_mails     = array();
vcap()->delivery->deliver( $z6_big[0]->id );
remove_filter( 'vcap_bundle_pdf_limit', $z6_limit );
vcap_dlv_assert( 1 === count( $z6_mails ) && array() === $z6_mails[0]['pages'] && 5 === substr_count( $z6_mails[0]['message'], 'vcap_pdf=' ), 'Z6: above the PDF limit the bundled email carries no PDF but a download link per voucher', array( $z6_mails[0]['pages'] ?? null, substr_count( $z6_mails[0]['message'] ?? '', 'vcap_pdf=' ) ) );

// 6. A resend of one voucher of the bundle still sends that voucher alone.
$z6_mails = array();
vcap()->delivery->send( $repo->get( $z6_own[1]->id ) );
vcap_dlv_assert( 1 === count( $z6_mails ) && array( 1 ) === $z6_mails[0]['pages'] && str_contains( $z6_mails[0]['message'], $z6_own[1]->code ) && ! str_contains( $z6_mails[0]['message'], $z6_own[0]->code ), 'Z6: resending one voucher sends one email with its own one-page PDF', $z6_mails[0]['pages'] ?? null );

// 7. Below the threshold, with bundling off (0) or the bundled email switched off: one email per voucher.
$z6_small = $z6_batch( 4, array( 'order_id' => vcap_dlv_order()->get_id(), 'buyer' => "z6-small-{$tag}@example.com" ) );
$z6_mails = array();
foreach ( $z6_small as $z6_v ) {
	vcap()->delivery->deliver( $z6_v->id );
}
vcap_dlv_assert( 4 === count( $z6_mails ), 'Z6: four vouchers (below the default of 5) get one email each, as before', count( $z6_mails ) );
update_option( 'vcap_bundle_threshold', 0 );
$z6_off   = $z6_batch( 5, array( 'order_id' => vcap_dlv_order()->get_id(), 'buyer' => "z6-off-{$tag}@example.com" ) );
$z6_mails = array();
foreach ( $z6_off as $z6_v ) {
	vcap()->delivery->deliver( $z6_v->id );
}
delete_option( 'vcap_bundle_threshold' );
vcap_dlv_assert( 5 === count( $z6_mails ), 'Z6: setting 0 sends one email per voucher', count( $z6_mails ) );
$z6_bundle_email = WC()->mailer()->get_emails()['VCAP_Email_Voucher_Bundle'];
$z6_bundle_email->enabled = 'no';
$z6_dis   = $z6_batch( 5, array( 'order_id' => vcap_dlv_order()->get_id(), 'buyer' => "z6-dis-{$tag}@example.com" ) );
$z6_mails = array();
foreach ( $z6_dis as $z6_v ) {
	vcap()->delivery->deliver( $z6_v->id );
}
$z6_bundle_email->enabled = 'yes';
vcap_dlv_assert( 5 === count( $z6_mails ), 'Z6: with the bundled email switched off every voucher gets its own email again', count( $z6_mails ) );

// 8. "Deliver now" on one scheduled voucher of a large line delivers that voucher alone.
$z6_now_order = vcap_dlv_order();
$z6_now       = $z6_batch( 6, array( 'order_id' => $z6_now_order->get_id(), 'buyer' => "z6-now-{$tag}@example.com", 'status' => Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
$z6_mails     = array();
vcap()->delivery->deliver_now( $repo->get( $z6_now[0]->id ) );
vcap_dlv_assert( 1 === count( $z6_mails ) && null !== $repo->get( $z6_now[0]->id )->delivered_at && Voucher::STATUS_SCHEDULED === $repo->get( $z6_now[1]->id )->status, 'Z6: "Deliver now" on one voucher sends only that voucher', count( $z6_mails ) );

remove_filter( 'pre_wp_mail', $z6_capture, 1 );
update_option( 'vcap_attach_pdf', $z6_attach );

// D10: the diagnostics name the counters support needs.
$d10 = ( new VCAP\Diagnostics() )->fields();
vcap_dlv_assert( ! array_diff( array( 'undelivered', 'failed_actions', 'open_reservations', 'attention', 'shortfalls', 'innodb', 'legacy_rows', 'adapters', 'issuing', 'retention', 'verbose_log' ), array_keys( $d10 ) ), 'D10: the diagnostics list failed tasks, undelivered vouchers, reservations, open cases, shortfalls, InnoDB, adapters, issuing and retention', array_keys( $d10 ) );

remove_filter( 'pre_wp_mail', $catch, 999 );
echo "Voucher Captain delivery checks passed.\n";
