<?php
/**
 * After the browser checkouts: each order issued a voucher for the recipient entered on the product page.
 */
foreach ( array( 'classic' => 'e2e-classic@example.test', 'block' => 'e2e-block@example.test' ) as $flow => $billing ) {
	$orders = wc_get_orders( array( 'billing_email' => $billing, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
	if ( ! $orders ) {
		throw new RuntimeException( "FAIL: no {$flow} checkout order found." );
	}
	$order    = $orders[0];
	$vouchers = vcap()->vouchers->for_order( $order->get_id() );
	$expected = $flow . '-recipient@example.test';
	if ( 1 !== count( $vouchers ) || $expected !== $vouchers[0]->recipient_email ) {
		throw new RuntimeException( "FAIL: the {$flow} checkout order did not issue a voucher for {$expected}." );
	}
	echo "PASS: {$flow} checkout issued voucher {$vouchers[0]->code} for {$expected} (order status {$order->get_status()}).\n";
}
