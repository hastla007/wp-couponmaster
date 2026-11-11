<?php
/**
 * Expiry reminder checks. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/reminder-integration.php
 */

function vcap_rem_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_rem_voucher( string $email, int $expires_in_days, array $props = array() ): VCAP\Voucher {
	$v                 = new VCAP\Voucher();
	$v->code           = 'vcap-rem-' . strtolower( wp_generate_password( 10, false, false ) );
	$v->order_item_id  = 60000000 + random_int( 1, 9999999 );
	$v->type           = 'giftcard';
	$v->usage_mode     = $props['usage_mode'] ?? 'balance';
	$v->initial_amount = 50;
	$v->balance        = $props['balance'] ?? 20;
	$v->currency       = get_woocommerce_currency();
	$v->status         = $props['status'] ?? VCAP\Voucher::STATUS_ACTIVE;
	$v->expires_at     = gmdate( 'Y-m-d H:i:s', time() + $expires_in_days * DAY_IN_SECONDS );
	$v->delivered_at   = array_key_exists( 'delivered_at', $props ) ? $props['delivered_at'] : gmdate( 'Y-m-d H:i:s' );
	$v->buyer_email    = $email;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

$mails = array();
$catch = static function ( $return, $atts ) use ( &$mails ) {
	$mails[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
	return true;
};
add_filter( 'pre_wp_mail', $catch, 10, 2 );

$tag     = strtolower( wp_generate_password( 6, false, false ) );
$soon    = vcap_rem_voucher( "soon-{$tag}@example.com", 7 );
$later   = vcap_rem_voucher( "later-{$tag}@example.com", 40 );
$used_up = vcap_rem_voucher( "used-{$tag}@example.com", 7, array( 'balance' => 0, 'status' => VCAP\Voucher::STATUS_REDEEMED ) );
$pending = vcap_rem_voucher( "pending-{$tag}@example.com", 7, array( 'delivered_at' => null ) );
$muted   = vcap_rem_voucher( "muted-{$tag}@example.com", 7 );
vcap()->vouchers->log( $muted->id, 'expiry_reminder', null, null, 'Suppressed by admin' );

delete_option( 'vcap_expiry_reminder_days' );
vcap_rem_assert( 0 === vcap()->delivery->send_expiry_reminders(), 'reminders are off by default' );

update_option( 'vcap_expiry_reminder_days', 14 );
vcap()->delivery->send_expiry_reminders();
// Only this run's addresses matter (other fixtures in the database may be reminded too).
$mine = array_values( array_filter( $mails, static fn( $to ) => str_contains( $to, $tag ) ) );
vcap_rem_assert( array( "soon-{$tag}@example.com" ) === $mine, 'only the delivered voucher with remaining value expiring within 14 days is reminded' );
vcap_rem_assert( in_array( 'expiry_reminder', array_column( vcap()->vouchers->events( $soon->id ), 'event' ), true ), 'the reminder is recorded in the voucher history' );

$mails = array();
vcap()->delivery->send_expiry_reminders();
vcap_rem_assert( array() === array_values( array_filter( $mails, static fn( $to ) => str_contains( $to, $tag ) ) ), 'each voucher is reminded only once' );

update_option( 'vcap_expiry_reminder_days', 0 );
remove_filter( 'pre_wp_mail', $catch, 10 );
echo "Voucher Captain expiry reminder checks passed.\n";
