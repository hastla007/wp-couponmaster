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
	$v->order_item_id  = 83000000 + $coupon->get_id();
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
	$v->send_to        = 'buyer';
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
vcap_dlv_assert( str_contains( vcap_dlv_notes( $order->get_id() ), $failing->code ), 'the order gets a note that the voucher is no longer sent automatically' );
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
vcap_dlv_assert( str_contains( vcap_dlv_notes( $late_order->get_id() ), $late->code ), 'the order gets a note for the shop' );
vcap_dlv_assert( ! in_array( $late->id, $repo->due_for_delivery( 1000 ), true ), 'the safety net does not pick it up again' );

remove_filter( 'pre_wp_mail', $catch, 999 );
echo "Voucher Captain delivery checks passed.\n";
