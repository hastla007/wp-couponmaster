<?php
/**
 * Workflow provider contract v1 (WORKFLOW.md, VOUCH-WF-01 … 06) with WordPress and WooCommerce:
 * - events reach a listener of "vcap_workflow_event" after the COMMIT, with the contract envelope and without the
 *   code or an address; replay() answers the same event ids;
 * - services: issue, resend, void and reactivate act once per idempotency key (a repeat is "replayed", nothing is
 *   booked or sent again), a key reused for another request is a conflict; redeem never redeems twice on a retry;
 *   callers without the capability are refused;
 * - hand-over (WF-04): with the switch on and a compatible workflow plugin, the expiry reminder is recorded instead
 *   of sent (once); without a compatible plugin (missing, other major, licence expired) Voucher Captain sends it.
 *
 *   wp eval-file tests/workflow-integration.php
 */

use VCAP\Voucher;

function vcap_wf_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

/** Ledger events of a voucher with this name. */
function vcap_wf_count( int $voucher_id, string $event ): int {
	return count( array_filter( vcap()->vouchers->events( $voucher_id ), static fn( $e ) => $event === $e['event'] ) );
}

$tag      = strtolower( wp_generate_password( 6, false, false ) );
$mails    = array();
$catch    = static function ( $return, $atts ) use ( &$mails ) {
	$mails[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
	return true;
};
$events   = array();
$listen   = static function ( $envelope ) use ( &$events ) {
	$events[] = $envelope;
};
$consumer = array(
	'id'             => 'workflow-test',
	'name'           => 'Workflow Test',
	'version'        => '0.1.0',
	'contract'       => '1.0.0',
	'edition'        => 'pro',
	'entitlement'    => 'active',
	'communications' => array( 'expiry_reminder' ),
);
$announce = static function () use ( &$consumer ) {
	return $consumer;
};
$saved_days = get_option( 'vcap_expiry_reminder_days', null );
add_filter( 'pre_wp_mail', $catch, 10, 2 );
add_action( 'vcap_workflow_event', $listen );
$provider = vcap()->workflow;

try {
	// WF-01: provider registered and reachable; without a workflow plugin nothing is handed over.
	vcap_wf_assert( $provider instanceof VCAP\Workflow\Provider && apply_filters( 'vcap_workflow_provider', null ) === $provider && '1.0.0' === VCAP\Workflow\Provider::descriptor()['contract_version'], 'WF-01: the provider is available as vcap()->workflow and through the filter vcap_workflow_provider' );
	vcap_wf_assert( 'missing' === $provider->status()['compatibility'], 'WF-05: without a workflow plugin the status says "missing"' );

	// R1: a language plugin (WPML style) filters home_url() and option_home per language – the instance must not change.
	$r1_instance  = VCAP\Workflow\Provider::instance();
	$r1_signature = VCAP\Workflow\Provider::site_signature();
	$r1_lang      = static fn( $url ) => rtrim( (string) $url, '/' ) . '/de';
	add_filter( 'home_url', $r1_lang );
	add_filter( 'option_home', $r1_lang );
	VCAP\Workflow\Provider::forget_stored_home();
	$r1_same = VCAP\Workflow\Provider::site_signature() === $r1_signature && VCAP\Workflow\Provider::instance() === $r1_instance && null === VCAP\Workflow\Provider::instance_change();
	remove_filter( 'home_url', $r1_lang );
	remove_filter( 'option_home', $r1_lang );
	VCAP\Workflow\Provider::forget_stored_home();
	vcap_wf_assert( $r1_same, 'R1: a language plugin that filters home_url()/option_home per language keeps the workflow instance and its event ids' );

	// WF-03: permission.
	wp_set_current_user( 0 );
	$denied = $provider->issue( array( 'amount' => 20, 'send' => false, 'idempotency_key' => "wf-{$tag}:issue:denied" ) );
	vcap_wf_assert( is_wp_error( $denied ) && 'vcap_not_allowed' === $denied->get_error_code(), 'WF-03: a caller without capability cannot issue' );
	wp_set_current_user( 1 );

	// WF-03: issue once per key; the event carries no code.
	$args   = array(
		'type'            => 'giftcard',
		'usage'           => 'balance',
		'amount'          => 50,
		'buyer_name'      => 'Workflow Buyer',
		'buyer_email'     => "wf-buyer-{$tag}@example.com",
		'send'            => false,
		'reason'          => 'workflow test',
		'idempotency_key' => "wf-{$tag}:issue:1",
	);
	$events = array();
	$first  = $provider->issue( $args );
	vcap_wf_assert( is_array( $first ) && $first['voucher_id'] > 0 && false === $first['replayed'] && ! isset( $first['code'] ), 'WF-03: issue creates a voucher and answers its id, never its code', $first );
	$card   = vcap()->vouchers->get( (int) $first['voucher_id'] );
	$again  = $provider->issue( $args );
	vcap_wf_assert( is_array( $again ) && $again['voucher_id'] === $first['voucher_id'] && true === $again['replayed'], 'WF-03: the same key and request issue nothing more (replayed, same voucher)', $again );
	$conflict = $provider->issue( array( 'amount' => 60 ) + $args );
	vcap_wf_assert( is_wp_error( $conflict ) && 'vcap_idempotency_conflict' === $conflict->get_error_code(), 'WF-03: the same key with another amount is a conflict' );
	$created = array_values( array_filter( $events, static fn( $e ) => 'voucher.created' === $e['type'] && (int) $e['source_id'] === (int) $card->id ) );
	$json    = (string) wp_json_encode( $events );
	vcap_wf_assert( 1 === count( $created ) && 'workflow' === $created[0]['data']['origin'] && 5000 === $created[0]['data']['value_minor'] && ! str_contains( strtolower( $json ), strtolower( $card->code ) ) && ! str_contains( $json, '@' ) && ! str_contains( $json, 'Workflow Buyer' ), 'WF-02: one "voucher.created" event with origin "workflow", amounts in minor units, no code, address or name', $created );
	vcap_wf_assert( 1 === vcap_wf_count( (int) $card->id, 'workflow_command' ) && 1 === vcap_wf_count( (int) $card->id, 'issued' ), 'WF-03: the issue is audited once in the voucher history' );

	// WF-03: redeem once per key (gift card, partial amount).
	$redeem = array(
		'code'            => $card->code,
		'target'          => "test-{$tag}",
		'amount'          => 10,
		'user_id'         => 0,
		'idempotency_key' => "wf-{$tag}:redeem:1",
	);
	$r1 = $provider->redeem( $redeem );
	$r2 = $provider->redeem( $redeem );
	$after = vcap()->vouchers->get( (int) $card->id );
	vcap_wf_assert( is_array( $r1 ) && 'committed' === $r1['state'] && 1000 === $r1['amount_minor'] && is_array( $r2 ) && true === $r2['replayed'] && abs( 40.0 - (float) $after->balance ) < 0.001 && 1 === vcap_wf_count( (int) $card->id, 'api_committed' ), 'WF-03: redeem books 10 once – a retry with the same key redeems nothing more', array( $r1, $r2, $after->balance ) );
	vcap_wf_assert( str_starts_with( (string) $r1['target'], 'workflow:' ) && ! isset( $r1['code'] ), 'WF-03: the redemption target is in the reserved "workflow:" namespace; the result holds no code' );
	$redeemed = array_values( array_filter( $events, static fn( $e ) => 'voucher.redeemed' === $e['type'] && (int) $e['source_id'] === (int) $card->id ) );
	vcap_wf_assert( 1 === count( $redeemed ) && 'external' === $redeemed[0]['data']['channel'] && 1000 === $redeemed[0]['data']['amount_minor'] && 4000 === $redeemed[0]['data']['balance_minor'], 'WF-02: one "voucher.redeemed" event (channel external) with amount and balance', $redeemed );

	// WF-03: resend once per key.
	$mails = array();
	$s1    = $provider->resend( array( 'voucher_id' => $card->id, 'idempotency_key' => "wf-{$tag}:resend:1" ) );
	$s2    = $provider->resend( array( 'voucher_id' => $card->id, 'idempotency_key' => "wf-{$tag}:resend:1" ) );
	$mine  = array_values( array_filter( $mails, static fn( $to ) => str_contains( $to, $tag ) ) );
	vcap_wf_assert( is_array( $s1 ) && false === $s1['replayed'] && is_array( $s2 ) && true === $s2['replayed'] && 1 === count( $mine ) && 1 === vcap_wf_count( (int) $card->id, 'resent' ), 'WF-03: resend mails once – the repeat with the same key sends nothing', array( $s1, $s2, $mine ) );

	// WF-03: void and reactivate once per key.
	$v1 = $provider->void( array( 'voucher_id' => $card->id, 'reason' => 'test', 'idempotency_key' => "wf-{$tag}:void:1" ) );
	$v2 = $provider->void( array( 'voucher_id' => $card->id, 'reason' => 'test', 'idempotency_key' => "wf-{$tag}:void:1" ) );
	vcap_wf_assert( is_array( $v1 ) && 'void' === $v1['status'] && true === $v2['replayed'] && 1 === vcap_wf_count( (int) $card->id, 'voided' ), 'WF-03: void acts once per key' );
	$a1 = $provider->reactivate( array( 'voucher_id' => $card->id, 'idempotency_key' => "wf-{$tag}:react:1" ) );
	$a2 = $provider->reactivate( array( 'voucher_id' => $card->id, 'idempotency_key' => "wf-{$tag}:react:1" ) );
	vcap_wf_assert( is_array( $a1 ) && 'active' === $a1['status'] && true === $a2['replayed'] && 1 === vcap_wf_count( (int) $card->id, 'reactivated' ), 'WF-03: reactivate acts once per key', array( $a1, $a2 ) );
	$types = array_column( array_filter( $events, static fn( $e ) => (int) $e['source_id'] === (int) $card->id ), 'type' );
	vcap_wf_assert( in_array( 'voucher.voided', $types, true ) && in_array( 'voucher.reactivated', $types, true ) && in_array( 'voucher.delivered', $types, true ), 'WF-02: void, reactivation and resending fire their events', $types );

	// WF-02/06: replay gives the same ids.
	$live   = array_column( array_filter( $events, static fn( $e ) => (int) $e['source_id'] === (int) $card->id ), 'event_id' );
	$from   = (int) min( array_column( array_filter( $events, static fn( $e ) => (int) $e['source_id'] === (int) $card->id ), 'sequence' ) ) - 1;
	$replay = $provider->replay( $from, 200 );
	$again  = array_column( array_filter( $replay['events'], static fn( $e ) => (int) $e['source_id'] === (int) $card->id ), 'event_id' );
	vcap_wf_assert( array() === array_diff( $live, $again ) && ! in_array( false, array_column( $replay['events'], 'replayed' ), true ), 'WF-06: replay answers the same event ids as the live events', array( $live, $again ) );
	vcap_wf_assert( '' !== $provider->status()['last_event_id'], 'WF-05: the status shows the last event id' );

	// WF-04: hand-over of the expiry reminder.
	$due = static function ( string $email ) {
		$v                 = new Voucher();
		$v->code           = 'vcap-wf-' . strtolower( wp_generate_password( 12, false, false ) );
		$v->order_item_id  = 64000000 + random_int( 1, 9999999 );
		$v->type           = 'giftcard';
		$v->usage_mode     = 'balance';
		$v->initial_amount = 50;
		$v->balance        = 20;
		$v->currency       = get_woocommerce_currency();
		$v->status         = Voucher::STATUS_ACTIVE;
		$v->expires_at     = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$v->delivered_at   = gmdate( 'Y-m-d H:i:s' );
		$v->buyer_email    = $email;
		$v->send_to        = 'buyer';
		$v->token          = VCAP\VoucherRepository::new_token();
		$v->id             = vcap()->vouchers->insert( $v );
		return $v;
	};
	$expiry_email = WC()->mailer()->get_emails()['VCAP_Email_Voucher_Expiry'] ?? null;
	vcap_wf_assert( null !== $expiry_email, 'the expiry reminder email is registered' );
	$expiry_email->enabled = 'yes'; // This request only (earlier suites restore the stored setting).
	update_option( 'vcap_expiry_reminder_days', 7 );
	update_option( 'vcap_workflow_handover_expiry_reminder', 'yes' );
	$handed = $due( "wf-handed-{$tag}@example.com" );
	add_filter( 'vcap_workflow_consumer', $announce );
	$mails  = array();
	$events = array();
	vcap()->delivery->send_expiry_reminders();
	vcap()->delivery->send_expiry_reminders();
	$mine = array_values( array_filter( $mails, static fn( $to ) => str_contains( $to, $tag ) ) );
	$over = array_values( array_filter( $events, static fn( $e ) => 'voucher.communication_handed_over' === $e['type'] && (int) $e['source_id'] === (int) $handed->id ) );
	vcap_wf_assert( array() === $mine && 1 === vcap_wf_count( (int) $handed->id, 'workflow_handover' ) && 0 === vcap_wf_count( (int) $handed->id, 'expiry_reminder' ) && 1 === count( $over ) && 7 === $over[0]['data']['days'] && 'buyer' === $over[0]['data']['role'], 'WF-04: with the switch on and a compatible workflow plugin the reminder is handed over once, not mailed', array( $mine, $over ) );
	$contact = $provider->contact( array( 'voucher_id' => $handed->id, 'communication' => 'expiry_reminder' ) );
	vcap_wf_assert( is_array( $contact ) && "wf-handed-{$tag}@example.com" === $contact['email'] && ! str_contains( (string) wp_json_encode( $contact ), $handed->code ), 'WF-04: the workflow plugin gets the address of a handed-over communication, not the code', $contact );
	// Decision D9 (16.11.2026): every read of contact data is in the history and the privacy export, never an event.
	$reads = vcap()->vouchers->events_of( (int) $handed->id, VCAP\Workflow\Services::CONTACT_EVENT );
	vcap_wf_assert( 1 === count( $reads ) && null === $reads[0]['amount'] && (int) get_current_user_id() === (int) $reads[0]['user_id'] && ! str_contains( (string) $reads[0]['note'], '@example.com' ) && ! str_contains( (string) $reads[0]['note'], $handed->code ), 'D9: contact() writes one history entry workflow_contact_read without address or code', $reads );
	vcap_wf_assert( array() === array_filter( $events, static fn( $e ) => (int) $e['source_id'] === (int) $handed->id && 'voucher.communication_handed_over' !== $e['type'] ), 'D9: reading the contact data fires no workflow event', $events );
	$export = ( new VCAP\Privacy( vcap()->vouchers ) )->export( "wf-handed-{$tag}@example.com" );
	vcap_wf_assert( in_array( 'vcap-workflow-contact', array_column( $export['data'], 'group_id' ), true ), 'D9: the privacy export of the voucher lists the read of its contact data', $export['data'] );

	foreach ( array( 'missing' => null, 'major' => '2.0.0', 'expired' => 'expired', 'unlicensed' => 'missing' ) as $case => $change ) { // R10: "unlicensed" = entitlement missing.
		$native = $due( "wf-native-{$case}-{$tag}@example.com" );
		$saved  = $consumer;
		if ( 'missing' === $case ) {
			remove_filter( 'vcap_workflow_consumer', $announce );
		} elseif ( 'major' === $case ) {
			$consumer['contract'] = $change;
		} else {
			$consumer['entitlement'] = $change;
		}
		$mails = array();
		vcap()->delivery->send_expiry_reminders();
		$consumer = $saved;
		add_filter( 'vcap_workflow_consumer', $announce );
		$mine = array_values( array_filter( $mails, static fn( $to ) => str_contains( $to, $tag ) ) );
		vcap_wf_assert( array( "wf-native-{$case}-{$tag}@example.com" ) === $mine && 0 === vcap_wf_count( (int) $native->id, 'workflow_handover' ) && 1 === vcap_wf_count( (int) $native->id, 'expiry_reminder' ) && 0 === vcap_wf_count( (int) $handed->id, 'expiry_reminder' ), "WF-06: workflow plugin {$case} – Voucher Captain sends the reminder itself (and not again for the handed-over voucher)", $mine );
	}

	// R3: two repeats that both find the same stale claim – only one may take it over.
	$r3_name = "workflow:r3-{$tag}";
	VCAP\Claim::take( $r3_name );
	$r3_old = gmdate( 'Y-m-d H:i:s', time() - 600 );
	$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . VCAP\Install::tables()['claims'] . ' SET created_at = %s WHERE claim_key = %s', $r3_old, substr( md5( $r3_name ), 0, 24 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$r3_at = VCAP\Claim::taken_at( $r3_name );
	vcap_wf_assert( $r3_old === $r3_at && VCAP\Claim::take_over( $r3_name, $r3_at ) && ! VCAP\Claim::take_over( $r3_name, $r3_at ) && ! VCAP\Claim::take( $r3_name ), 'R3: a stale claim is taken over by exactly one caller', $r3_at );
	VCAP\Claim::release( $r3_name );

	// R2: a registered workflow plugin built for another major contract gets no events at all (status and WORKFLOW.md).
	$saved                = $consumer;
	$consumer['contract'] = '2.0.0';
	$events               = array();
	$r2                   = $provider->issue( array( 'idempotency_key' => "wf-{$tag}:r2" ) + $args );
	$consumer             = $saved;
	vcap_wf_assert( is_array( $r2 ) && $r2['voucher_id'] > 0 && array() === $events, 'R2: an incompatible workflow plugin receives no events (the voucher is still issued)', $events );
	$events = array();
	$r2b    = $provider->issue( array( 'idempotency_key' => "wf-{$tag}:r2b" ) + $args );
	vcap_wf_assert( is_array( $r2b ) && array() !== array_filter( $events, static fn( $e ) => 'voucher.created' === $e['type'] ), 'R2: a compatible workflow plugin receives the events again', $events );
} finally {
	remove_filter( 'pre_wp_mail', $catch, 10 );
	remove_action( 'vcap_workflow_event', $listen );
	remove_filter( 'vcap_workflow_consumer', $announce );
	delete_option( 'vcap_workflow_handover_expiry_reminder' );
	if ( null === $saved_days ) {
		delete_option( 'vcap_expiry_reminder_days' );
	} else {
		update_option( 'vcap_expiry_reminder_days', $saved_days );
	}
	wp_set_current_user( 0 );
}
