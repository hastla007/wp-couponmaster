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

// Block checkout paid partly with a gift card after tax (03-block-checkout.spec.js, vcap_mpv_after_tax=yes): goods
// 30, card 20 as an untaxed payment line, 10 left for the gateway.
$orders = wc_get_orders( array( 'billing_email' => 'e2e-block-giftcard@example.test', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
$state  = json_decode( (string) file_get_contents( __DIR__ . '/.state.json' ), true );
if ( ! $orders ) {
	throw new RuntimeException( 'FAIL: no block checkout order paid with the gift card found.' );
}
$paid = VCAP\PaymentVouchers::paid_amount( $orders[0], (string) $state['gift_card'] );
if ( abs( 10.0 - (float) $orders[0]->get_total() ) > 0.001 || null === $paid || abs( 20.0 - $paid ) > 0.001 ) {
	throw new RuntimeException( 'FAIL: the gift card order should total 10 with a payment line of 20, got total ' . $orders[0]->get_total() . ' and payment ' . wp_json_encode( $paid ) . '.' );
}
echo "PASS: block checkout with the gift card: payment line 20, order total 10.\n";
