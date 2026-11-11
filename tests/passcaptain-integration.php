<?php
/**
 * Voucher Captain as voucher provider of WP Pass Captain (both plugins active).
 *   wp eval-file tests/passcaptain-integration.php
 */

use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout;
use PassCaptain\Orders;

function vcap_pc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_pc_voucher( string $mode, float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'pc-' . $mode . '-' . strtolower( wp_generate_password( 8, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 99000000 + $coupon->get_id();
	$v->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
	$v->usage_mode     = $mode;
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

function vcap_pc_ticket_type( int $price_minor, string $currency = 'EUR' ): int {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Voucher Konzert', 'slug' => 'vc' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price_minor, 'currency' => $currency, 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}

function vcap_pc_order( int $type, int $qty ): array {
	return Orders::create( array( 'email' => 'pc-' . wp_generate_password( 6, false ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'pc-' . wp_generate_uuid4() ) );
}

function vcap_pc_redemption( int $order_id ): array {
	$snapshot = json_decode( (string) Orders::get( $order_id )->voucher_snapshot, true );
	$key      = (string) ( $snapshot['reservation']['redemption_key'] ?? '' );
	return '' === $key ? array() : vcap()->redemptions->get( $key );
}

wp_set_current_user( 1 ); // Pass Captain refunds need a user allowed to refund.
update_option( 'woocommerce_currency', 'EUR' );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

vcap_pc_assert( Bridge::available() && Bridge::provider() instanceof VCAP\Compat\PassCaptainProvider, 'Voucher Captain is the voucher provider of Pass Captain' );
vcap_pc_assert( isset( VCAP\Channels::all()['passcaptain'] ), 'the Pass Captain redemption channel is offered on voucher products' );

$type = vcap_pc_ticket_type( 2000 );

$bad = Checkout::apply( vcap_pc_order( $type, 1 )['order_id'], 'NO-SUCH-CODE' );
vcap_pc_assert( is_wp_error( $bad ), 'unknown codes are refused in the ticket checkout' );

// Gift card (50 €) on 3 tickets (60 €): 50 € reserved, 10 € left to pay.
$card  = vcap_pc_voucher( 'balance', 50 );
$order = vcap_pc_order( $type, 3 );
$applied = Checkout::apply( $order['order_id'], strtoupper( $card->code ) );
vcap_pc_assert( ! is_wp_error( $applied ) && 5000 === $applied['applied_minor'] && 1000 === $applied['total_minor'], 'a gift card pays 50 of a 60 ticket order' );
vcap_pc_assert( 0.0 === vcap()->vouchers->get( $card->id )->balance && 'reserved' === vcap_pc_redemption( $order['order_id'] )['state'], 'the gift card balance is reserved before payment' );
$held_until = strtotime( vcap_pc_redemption( $order['order_id'] )['expires_at'] . ' UTC' );
$manual     = (int) PassCaptain\Settings::get( 'manual_hold_hours' ) * HOUR_IN_SECONDS;
vcap_pc_assert( $held_until > time() + max( $manual, 15 * MINUTE_IN_SECONDS ), 'the reservation outlives Pass Captain\'s payment window (no release before a late bank transfer)' );

Orders::confirm_manual_payment( $order['order_id'] );
Checkout::commit( $order['order_id'] );
Checkout::commit( $order['order_id'] );
$redemption = vcap_pc_redemption( $order['order_id'] );
vcap_pc_assert( 'committed' === $redemption['state'], 'payment commits the redemption (repeats are harmless)' );
$events = array_count_values( array_column( vcap()->vouchers->events( $card->id ), 'event' ) );
vcap_pc_assert( 1 === ( $events['api_reserved'] ?? 0 ) && 1 === ( $events['api_committed'] ?? 0 ), 'the voucher ledger shows one reservation and one commit' );

// Refund one of three tickets: one third of the voucher share goes back to the gift card.
$tickets = Orders::tickets_for_order( $order['order_id'] );
$refund  = PassCaptain\Refunds::request( $order['order_id'], array( (int) $tickets[0]->ticket_id ), 'Storno', 'pc-r1' );
vcap_pc_assert( is_array( $refund ) && ! empty( $refund['refund_id'] ), 'Pass Captain records the ticket refund' );
Checkout::refund( $refund['refund_id'], $order['order_id'] );
Checkout::refund( $refund['refund_id'], $order['order_id'] );
vcap_pc_assert( 16.66 === vcap()->vouchers->get( $card->id )->balance, 'the voucher share of the refunded ticket returns to the gift card exactly once (16.66)' );

// Single-use voucher (15 €) on a cancelled order: released and usable again.
$single = vcap_pc_voucher( 'single', 15 );
$order2 = vcap_pc_order( $type, 1 );
$applied2 = Checkout::apply( $order2['order_id'], $single->code );
vcap_pc_assert( ! is_wp_error( $applied2 ) && 1500 === $applied2['applied_minor'] && VCAP\Voucher::STATUS_RESERVED === vcap()->vouchers->get( $single->id )->status, 'a single-use voucher is reserved for the ticket order' );
Orders::cancel_order( $order2['order_id'], 'test' );
Checkout::release( $order2['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $single->id )->status && 'released' === vcap_pc_redemption( $order2['order_id'] )['state'], 'cancelling the ticket order releases the voucher' );

// The same voucher pays a whole order; a full refund makes it usable again.
$order3 = vcap_pc_order( $type, 1 );
$order3_total = (int) Orders::get( $order3['order_id'] )->total_minor;
$big = vcap_pc_voucher( 'single', 30 );
$applied3 = Checkout::apply( $order3['order_id'], $big->code );
vcap_pc_assert( ! is_wp_error( $applied3 ) && 0 === $applied3['total_minor'] && 'paid' === Orders::get( $order3['order_id'] )->payment_status, 'a voucher covering the whole order confirms it' );
Checkout::commit( $order3['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $big->id )->status, 'the single-use voucher is redeemed after the free order' );
$tickets3 = Orders::tickets_for_order( $order3['order_id'] );
$refund3  = PassCaptain\Refunds::request( $order3['order_id'], array( (int) $tickets3[0]->ticket_id ), 'Storno', 'pc-r3' );
Checkout::refund( $refund3['refund_id'], $order3['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $big->id )->status, 'a full ticket refund makes the single-use voucher usable again' );
vcap_pc_assert( true === vcap()->coupons->validate( true, new WC_Coupon( $big->coupon_id ) ), 'the WooCommerce coupon accepts the restored voucher again' );

// Vouchers in another currency are refused.
$usd   = vcap_pc_ticket_type( 2000, 'USD' );
$other = Checkout::apply( vcap_pc_order( $usd, 1 )['order_id'], vcap_pc_voucher( 'balance', 20 )->code );
vcap_pc_assert( is_wp_error( $other ), 'vouchers are refused for ticket orders in another currency' );

// Shop-only vouchers stay in the shop.
$shop = vcap_pc_voucher( 'balance', 20 );
add_filter( 'vcap_redemption_channel_allowed', $only_shop = static fn( $allowed, $voucher, $channel ) => $voucher->id === $shop->id ? 'shop' === $channel : $allowed, 10, 3 );
vcap_pc_assert( is_wp_error( Checkout::apply( vcap_pc_order( $type, 1 )['order_id'], $shop->code ) ), 'vouchers without the Pass Captain channel are refused' );
remove_filter( 'vcap_redemption_channel_allowed', $only_shop, 10 );

// Minor units follow ISO 4217 (as Pass Captain does), not the shop's price display setting.
$decimals = static fn() => 0;
add_filter( 'wc_get_price_decimals', $decimals );
vcap_pc_assert( 2 === VCAP\Compat\PassCaptainProvider::decimals( get_woocommerce_currency() ) && 0 === VCAP\Compat\PassCaptainProvider::decimals( 'JPY' ), 'minor units use ISO 4217 decimals, whatever the shop displays' );
remove_filter( 'wc_get_price_decimals', $decimals );

echo "Voucher Captain + Pass Captain checks passed.\n";
