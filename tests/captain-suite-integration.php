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
vcap_cs_assert( is_array( $recipient ) && 'cs-rita@example.com' === $recipient['email'] && 'recipient' === $recipient['role'], 'a manager resolves the recipient of an eligible reference', $recipient );
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
wp_set_current_user( 1 );
vcap_cs_assert( is_wp_error( $requests->confirm( $sub['request_id'], array() ) ) && 0 === $issued, 'the manager must decide sold or free of charge' );
$done = $requests->confirm( $sub['request_id'], array( 'sale' => 'free', 'send' => false ) );
vcap_cs_assert( is_array( $done ) && 'issued' === $done['state'] && 2 === count( $done['vouchers'] ) && 2 === $issued, 'the manager\'s confirmation issues one voucher per recipient', $done );
$repeat = $requests->confirm( $sub['request_id'], array( 'sale' => 'free', 'send' => false ) );
vcap_cs_assert( is_array( $repeat ) && $done['vouchers'] === $repeat['vouchers'] && 2 === $issued, 'confirming again issues nothing more' );
$first = vcap()->vouchers->get( (int) $done['vouchers'][0] );
vcap_cs_assert( $first instanceof Voucher && 'cs-ann@example.com' === $first->recipient_email && 'cs-hr@example.com' === $first->buyer_email, 'the vouchers carry recipient and company as buyer' );
vcap_cs_assert( array() === VoucherRequests::get( $sub['request_id'] )['recipients'], 'the issued request keeps no recipient list of its own' );

echo "Captain Suite integration: all checks passed.\n";
