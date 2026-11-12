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
// Recalculating an order with a reserved gift card keeps the gift card amount (not the remaining balance 0).
$recalc_card  = vcap_acc_card( 100 );
$recalc_order = wc_create_order();
$recalc_order->add_product( $product, 8 ); // 160
$recalc_order->set_billing_email( 'acceptance-recalc@example.com' );
$recalc_order->calculate_totals();
$recalc_order->apply_coupon( $recalc_card->code );
$recalc_order = wc_get_order( $recalc_order->get_id() );
vcap()->coupons->reserve_order( $recalc_order );
$recalc_order = wc_get_order( $recalc_order->get_id() );
$recalc_order->recalculate_coupons();
$recalc_order = wc_get_order( $recalc_order->get_id() );
vcap_acc_assert( 100.0 === (float) $recalc_order->get_discount_total() && 60.0 === (float) $recalc_order->get_total(), 'recalculating the order keeps the reserved gift card amount' );
$recalc_order->payment_complete( 'txn-recalc' );
vcap_acc_assert( 0.0 === vcap()->vouchers->get( $recalc_card->id )->balance, 'payment deducts exactly the reserved 100' );

// Partial refund of a voucher purchase: the refunded unit voids an unused voucher; refunding value already spent is refused.
$gc_product = new WC_Product_Simple();
$gc_product->set_name( 'Gift card 50' );
$gc_product->set_regular_price( '50' );
$gc_product->set_virtual( true );
$gc_product->update_meta_data( '_vcap_enabled', 'yes' );
$gc_product->update_meta_data( '_vcap_type', 'giftcard' );
$gc_product->update_meta_data( '_vcap_usage', 'balance' );
$gc_product->save();
$purchase = wc_create_order();
$purchase->add_product( $gc_product, 2 );
$purchase->set_billing_email( 'gift-buyer@example.com' );
$purchase->calculate_totals();
$purchase->set_status( 'processing' );
$purchase->save();
vcap()->issuer->issue_for_order( $purchase, true );
$bought = vcap()->vouchers->for_order( $purchase->get_id() );
vcap_acc_assert( 2 === count( $bought ), 'two gift cards are issued for the purchase' );
$spent_card = $bought[0];
$spent_card->balance = 20.0; // 30 of 50 already used in the shop.
vcap()->vouchers->update( $spent_card );
$line_id = array_key_first( $purchase->get_items() );
$partial = wc_create_refund( array( 'order_id' => $purchase->get_id(), 'amount' => 50, 'line_items' => array( $line_id => array( 'qty' => 1, 'refund_total' => 50 ) ) ) );
$after   = array_map( static fn( $v ) => vcap()->vouchers->get( $v->id )->status, $bought );
vcap_acc_assert( ! is_wp_error( $partial ) && VCAP\Voucher::STATUS_VOID !== $after[0] && VCAP\Voucher::STATUS_VOID === $after[1], 'refunding one of two gift cards voids the unused one, not the one in use' );
$too_much = wc_create_refund( array( 'order_id' => $purchase->get_id(), 'amount' => 50 ) );
vcap_acc_assert( is_wp_error( $too_much ) && str_contains( $too_much->get_error_message(), $spent_card->code ), 'refunding value that was already spent from a gift card is refused with the reason' );
$allowed = wc_create_refund( array( 'order_id' => $purchase->get_id(), 'amount' => 20 ) );
vcap_acc_assert( ! is_wp_error( $allowed ), 'refunding up to the unused value is possible' );

// Shortfall: the gift card was emptied elsewhere before the late payment arrived. Nothing is fulfilled
// (no voucher issued) until an administrator accepts the shortfall.
update_option( 'vcap_vouchers_buy_vouchers', 'yes' ); // Gift cards may pay for gift cards here (default: no).
$short_card  = vcap_acc_card( 30 );
$short_order = wc_create_order();
$short_order->add_product( $gc_product, 1 ); // Buys a 50 gift card.
$short_order->set_billing_email( 'acceptance-shortfall@example.com' );
$short_order->calculate_totals();
$short_applied = $short_order->apply_coupon( $short_card->code );
vcap_acc_assert( true === $short_applied, 'the gift card is applied to the gift card purchase (setting allows it)' );
$emptied          = vcap()->vouchers->get( $short_card->id );
$emptied->balance = 0.0; // Spent in another order meanwhile (no reservation held for this one).
vcap()->vouchers->update( $emptied );
wc_get_order( $short_order->get_id() )->payment_complete( 'txn-short' );
$short_order = wc_get_order( $short_order->get_id() );
$short_diag = sprintf( ' [status=%s shortfall=%s issued=%d balance=%s redemptions=%s notes=%s]', $short_order->get_status(), $short_order->get_meta( VCAP\CouponManager::SHORTFALL_META ), count( vcap()->vouchers->for_order( $short_order->get_id() ) ), vcap()->vouchers->get( $short_card->id )->balance, wp_json_encode( $short_order->get_meta( '_vcap_redemptions' ) ), wp_json_encode( array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $short_order->get_id() ) ) ) ) );
vcap_acc_assert( 'on-hold' === $short_order->get_status() && VCAP\CouponManager::has_shortfall( $short_order ) && ! vcap()->vouchers->for_order( $short_order->get_id() ), 'a gift card that can no longer be charged holds the order and nothing is issued' . ( 'on-hold' === $short_order->get_status() && VCAP\CouponManager::has_shortfall( $short_order ) && ! vcap()->vouchers->for_order( $short_order->get_id() ) ? '' : $short_diag ) );
wp_set_current_user( 1 ); // The order action needs a shop manager.
( new VCAP\Admin\OrderAdmin( vcap()->vouchers, vcap()->issuer ) )->accept_shortfall( $short_order );
wp_set_current_user( 0 );
$short_order = wc_get_order( $short_order->get_id() );
vcap_acc_assert( 'processing' === $short_order->get_status() && ! VCAP\CouponManager::has_shortfall( $short_order ) && 1 === count( vcap()->vouchers->for_order( $short_order->get_id() ) ), 'after the shortfall is accepted the order is fulfilled (voucher issued)' );

// Partial shortfall: only 10 of the 30 can be charged. A second payment confirmation (e.g. a late webhook
// from on-hold) must not clear the hold by itself.
$part_card  = vcap_acc_card( 30 );
$part_order = wc_create_order();
$part_order->add_product( $gc_product, 1 );
$part_order->set_billing_email( 'acceptance-partial@example.com' );
$part_order->calculate_totals();
$part_order->apply_coupon( $part_card->code );
$part_left          = vcap()->vouchers->get( $part_card->id );
$part_left->balance = 10.0;
vcap()->vouchers->update( $part_left );
wc_get_order( $part_order->get_id() )->payment_complete( 'txn-part-1' );
wc_get_order( $part_order->get_id() )->payment_complete( 'txn-part-2' );
$part_order = wc_get_order( $part_order->get_id() );
vcap_acc_assert( VCAP\CouponManager::has_shortfall( $part_order ) && 'on-hold' === $part_order->get_status() && ! vcap()->vouchers->for_order( $part_order->get_id() ) && 0.0 === (float) vcap()->vouchers->get( $part_card->id )->balance, 'a partial shortfall stays on hold after a second payment confirmation (nothing issued)' );
update_option( 'vcap_vouchers_buy_vouchers', 'no' );

// Tax-exclusive shop: a 60 gift card pays 60 of the gross price (not 60 net + 20 % tax) and 60 is deducted.
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
$net_product = new WC_Product_Simple();
$net_product->set_name( 'Net price product' );
$net_product->set_regular_price( '100' );
$net_product->set_virtual( true );
$net_product->save();
$gross_card = vcap_acc_card( 60 );
$taxed      = wc_create_order();
$taxed->add_product( $net_product, 1 );
$taxed->set_billing_email( 'acceptance-tax@example.com' );
$taxed->set_billing_country( 'AT' );
$taxed->calculate_totals();
$taxed->apply_coupon( $gross_card->code );
$taxed = wc_get_order( $taxed->get_id() );
vcap_acc_assert( abs( 60.0 - ( (float) $taxed->get_discount_total() + (float) $taxed->get_discount_tax() ) ) < 0.02 && abs( 60.0 - (float) $taxed->get_total() ) < 0.02, 'in a tax-exclusive shop a 60 gift card pays 60 of the 120 gross total' );
vcap()->coupons->reserve_order( $taxed );
$taxed->payment_complete( 'txn-tax' );
vcap_acc_assert( vcap()->vouchers->get( $gross_card->id )->balance < 0.02, 'the gift card is used up by exactly what it paid' );
WC_Tax::_delete_tax_rate( $rate_id );
update_option( 'woocommerce_calc_taxes', 'no' );

// A checkout refused after the reservation (e.g. by another plugin) and retried as a new order of the same
// session gives the first order's reservation back right away.
wc_load_cart();
$retry_card  = vcap_acc_card( 25 );
$first_try   = wc_create_order();
$first_try->add_product( $product, 1 );
$first_try->set_billing_email( 'acceptance-retry@example.com' );
$first_try->calculate_totals();
$first_try->apply_coupon( $retry_card->code );
$first_try = wc_get_order( $first_try->get_id() );
vcap()->coupons->release_previous_checkout_order( $first_try );
vcap()->coupons->reserve_order( $first_try->get_id(), array(), $first_try );
$reserved_balance = (float) vcap()->vouchers->get( $retry_card->id )->balance;
$second_try = wc_create_order();
$second_try->add_product( $product, 1 );
$second_try->set_billing_email( 'acceptance-retry@example.com' );
$second_try->save();
vcap()->coupons->release_previous_checkout_order( $second_try );
vcap_acc_assert( 5.0 === $reserved_balance && 25.0 === (float) vcap()->vouchers->get( $retry_card->id )->balance, 'a new checkout order of the session releases the abandoned order\'s reservation (gift card usable again at once)' );

// Deactivation: without Voucher Captain nobody deducts balances, so balance coupons must not work.
$idle_card = vcap_acc_card( 40 );
VCAP\Install::suspend_balance_coupons();
vcap_acc_assert( 0 === wc_get_coupon_id_by_code( $idle_card->code ) && 'draft' === get_post_status( $idle_card->coupon_id ), 'deactivating suspends gift card coupons (WooCommerce no longer finds the code)' );
VCAP\Install::restore_balance_coupons();
vcap_acc_assert( $idle_card->coupon_id === wc_get_coupon_id_by_code( $idle_card->code ) && 'publish' === get_post_status( $idle_card->coupon_id ), 'reactivating publishes exactly the suspended coupons again' );

echo "Voucher Captain acceptance checks passed.\n";
