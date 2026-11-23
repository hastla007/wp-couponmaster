<?php
/**
 * After the browser checkouts: each order issued a voucher for the recipient entered on the product page.
 *
 * The checkouts pay by cash on delivery when the shop offers it (tests/e2e/setup.php enables it). Such an order is
 * "Processing" before any money arrived, so it issues no voucher yet (VCAP\Issuer::payment_ok()); completing the
 * order – the shop received the money on delivery – issues it.
 */
foreach ( array( 'classic' => 'e2e-classic@example.test', 'block' => 'e2e-block@example.test' ) as $flow => $billing ) {
	$orders = wc_get_orders( array( 'billing_email' => $billing, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
	if ( ! $orders ) {
		throw new RuntimeException( "FAIL: no {$flow} checkout order found." );
	}
	$order = $orders[0];
	if ( VCAP\Issuer::offline_payment( $order ) && ! $order->has_status( 'completed' ) ) {
		if ( vcap()->vouchers->for_order( $order->get_id() ) ) {
			throw new RuntimeException( "FAIL: the {$flow} checkout order ({$order->get_payment_method()}) issued a voucher before the payment arrived." );
		}
		echo "PASS: {$flow} checkout ({$order->get_payment_method()}) waits for the payment before issuing.\n";
		$order->update_status( 'completed', 'E2E: paid on delivery.' );
		$order = wc_get_order( $order->get_id() );
	}
	$vouchers = vcap()->vouchers->for_order( $order->get_id() );
	$expected = $flow . '-recipient@example.test';
	if ( 1 !== count( $vouchers ) || $expected !== $vouchers[0]->recipient_email ) {
		throw new RuntimeException( "FAIL: the {$flow} checkout order did not issue a voucher for {$expected}." );
	}
	echo "PASS: {$flow} checkout issued voucher {$vouchers[0]->code} for {$expected} (order status {$order->get_status()}).\n";
}

// Orders paid partly with a 20.00 gift card (goods 30.00, 10.00 left for the gateway): the card's pool in
// tests/e2e/setup.php, the billing email of the spec. Each must total 10, have charged its card 20 exactly once in
// the ledger (one "committed" booking of that order) and left the card at 0 (M12-58). The card is found by the order, not by
// a fixed code, so a retried test (fresh card per attempt, M12-101) is verified with the card it really used.
$state = json_decode( (string) file_get_contents( __DIR__ . '/.state.json' ), true );
$flows = array(
	'block checkout, payment after tax'              => array( 'gift_cards', 'e2e-block-giftcard@example.test' ),
	'block checkout, card as coupon (after tax off)' => array( 'gift_cards_coupon', 'e2e-block-giftcard-coupon@example.test' ),
	'classic checkout, redeem form'                  => array( 'classic_cards', 'e2e-classic-redeem@example.test' ),
);
foreach ( $flows as $flow => list( $pool, $billing ) ) {
	$orders = wc_get_orders( array( 'billing_email' => $billing, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
	if ( ! $orders ) {
		throw new RuntimeException( "FAIL: no order of the {$flow} found ({$billing})." );
	}
	$order   = $orders[0];
	$card    = null;
	$charged = 0.0;
	$count   = 0;
	foreach ( (array) ( $state[ $pool ] ?? array() ) as $code ) {
		$voucher = vcap()->vouchers->get_by_code( wc_format_coupon_code( $code ) ) ?? vcap()->vouchers->get_by_code( $code );
		if ( ! $voucher ) {
			continue;
		}
		$events = array_filter( vcap()->vouchers->events( $voucher->id ), static fn( $e ) => (int) $e['order_id'] === $order->get_id() && 'committed' === $e['event'] ); // The booking that charged the card (after its hold "reserved").
		if ( $events ) {
			$card    = $voucher;
			$charged = array_sum( array_map( static fn( $e ) => (float) $e['amount'], $events ) );
			$count   = count( $events );
		}
	}
	if ( ! $card || abs( 10.0 - (float) $order->get_total() ) > 0.001 || abs( 20.0 - $charged ) > 0.001 || 1 !== $count || abs( (float) $card->balance ) > 0.001 ) {
		throw new RuntimeException( "FAIL: the {$flow} order should total 10 and charge its gift card 20 once (balance 0), got total " . $order->get_total() . ', charged ' . $charged . ', balance ' . ( $card ? $card->balance : 'no card' ) . '.' );
	}
	echo "PASS: {$flow}: order total 10, gift card " . VCAP\Mask::code( $card->code ) . " charged 20 once in the ledger, balance 0.\n";
}

// Payment after tax (vcap_mpv_after_tax=yes): the card is an untaxed payment line of 20 next to the gateway.
$after_tax = wc_get_orders( array( 'billing_email' => 'e2e-block-giftcard@example.test', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) )[0];
$paid      = null;
foreach ( (array) $state['gift_cards'] as $code ) {
	$paid = $paid ?? VCAP\PaymentVouchers::paid_amount( $after_tax, (string) $code );
}
if ( null === $paid || abs( 20.0 - $paid ) > 0.001 ) {
	throw new RuntimeException( 'FAIL: the gift card order after tax should have a payment line of 20, got ' . wp_json_encode( $paid ) . '.' );
}
echo "PASS: block checkout with the gift card after tax: payment line 20, order total 10.\n";
