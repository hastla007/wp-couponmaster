<?php
/**
 * Acceptance: repeated payment webhooks, cart limits and partial redemption of gift cards.
 *   wp eval-file tests/acceptance-integration.php
 */

function vcap_acc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_acc_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-acc-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 97000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
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

$product = new WC_Product_Simple();
$product->set_name( 'Acceptance product' );
$product->set_regular_price( '20' );
$product->set_virtual( true );
$product->save();

// Cart limit: a 50 gift card on a 20 order only pays 20; the rest stays on the card.
$card  = vcap_acc_card( 50 );
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_email( 'acceptance@example.com' );
$order->calculate_totals();
vcap_acc_assert( true === $order->apply_coupon( $card->code ), 'the gift card is applied' );
$order = wc_get_order( $order->get_id() );
vcap_acc_assert( 0.0 === (float) $order->get_total() && 20.0 === (float) $order->get_discount_total(), 'the gift card pays at most the order total' );
vcap()->coupons->reserve_order( $order );

// The payment provider sends its webhook three times; the order also goes through processing → completed → processing.
$order->payment_complete( 'txn-1' );
wc_get_order( $order->get_id() )->payment_complete( 'txn-1' );
wc_get_order( $order->get_id() )->update_status( 'completed' );
wc_get_order( $order->get_id() )->update_status( 'processing' );
wc_get_order( $order->get_id() )->payment_complete( 'txn-1' );

$fresh  = vcap()->vouchers->get( $card->id );
$events = array_values( array_filter( vcap()->vouchers->events( $card->id ), static fn( $e ) => (int) $e['order_id'] === $order->get_id() && in_array( $e['event'], array( 'reserved', 'committed', 'redeemed' ), true ) ) );
vcap_acc_assert( 30.0 === $fresh->balance, 'repeated webhooks and status changes deduct the gift card exactly once (50 − 20 = 30)' );
$kinds = array_count_values( array_column( $events, 'event' ) );
vcap_acc_assert( 1 === ( $kinds['reserved'] ?? 0 ) && 1 === ( $kinds['committed'] ?? 0 ) + ( $kinds['redeemed'] ?? 0 ), 'the ledger shows one reservation and one final booking for the order' );

// Partial redemption continues on the next order.
$next = wc_create_order();
$next->add_product( $product, 2 );
$next->set_billing_email( 'acceptance@example.com' );
$next->calculate_totals();
$next->apply_coupon( $card->code );
$next = wc_get_order( $next->get_id() );
vcap_acc_assert( 30.0 === (float) $next->get_discount_total() && 10.0 === (float) $next->get_total(), 'the remaining 30 pays part of a 40 order' );
vcap()->coupons->reserve_order( $next );
$next->payment_complete( 'txn-2' );
vcap_acc_assert( 0.0 === vcap()->vouchers->get( $card->id )->balance && VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $card->id )->status, 'the used-up gift card is marked as redeemed' );

// A full refund of the second order gives its 30 back.
$next = wc_get_order( $next->get_id() );
$next->update_status( 'refunded' );
vcap_acc_assert( 30.0 === vcap()->vouchers->get( $card->id )->balance && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $card->id )->status, 'a full refund restores the used balance and reactivates the card' );
echo "Voucher Captain acceptance checks passed.\n";
