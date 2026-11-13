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
