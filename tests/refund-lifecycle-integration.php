<?php
/**
 * Order lifecycle of voucher purchases and voucher payments: partial returns, refunds by value, refunds that are
 * not recorded or deleted, payment reversals, paid-again orders, refunds as store credit or gift card (M12 package B).
 *   wp eval-file tests/refund-lifecycle-integration.php
 */

use VCAP\Attention;
use VCAP\Issuer;
use VCAP\TopUp;
use VCAP\Voucher;

function vcap_rl_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_rl_product( string $name, string $price, array $meta = array() ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_virtual( true );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( $key, $value );
	}
	$product->save();
	return $product;
}

/** A gift card (stored value) not bought in an order, e.g. to pay with. */
function vcap_rl_card( float $value ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-rl-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 97000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = get_woocommerce_currency();
	$v->status         = Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

function vcap_rl_balance( Voucher $card ): float {
	return round( (float) vcap()->vouchers->get( $card->id )->balance, 2 );
}

function vcap_rl_status( Voucher $voucher ): string {
	return (string) vcap()->vouchers->get( $voucher->id )->status;
}

function vcap_rl_notes( int $order_id ): string {
	return implode( "\n", array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) ) );
}

function vcap_rl_events( Voucher $voucher ): array {
	return array_column( vcap()->vouchers->events( $voucher->id ), 'event' );
}

/**
 * A paid purchase of $qty vouchers of $product (issued like at payment).
 *
 * @return array{0: WC_Order, 1: int, 2: Voucher[]}
 */
function vcap_rl_purchase( WC_Product $product, int $qty = 1, string $method = 'stripe' ): array {
	$order = wc_create_order();
	$line  = $order->add_product( $product, $qty );
	// A fresh buyer per run: the M12-23 security hold sums one address's voucher orders over 24 hours.
	static $email = null;
	$email ??= 'refund-lifecycle-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.com';
	$order->set_billing_email( $email );
	$order->set_payment_method( $method );
	$order->calculate_totals( false );
	$order->set_status( 'processing' );
	$order->save();
	vcap()->issuer->issue_for_order( wc_get_order( $order->get_id() ), true );
	return array( wc_get_order( $order->get_id() ), $line, vcap()->vouchers->for_order( $order->get_id() ) );
}

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'vcap_store_credit', 'yes' );
$giftcard = vcap_rl_product( 'RL gift card 50', '50', array( '_vcap_enabled' => 'yes', '_vcap_type' => 'giftcard', '_vcap_usage' => 'balance' ) );
$single   = vcap_rl_product( 'RL voucher 30', '30', array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_usage' => 'single' ) );
$goods    = vcap_rl_product( 'RL goods 20', '20' );

/* ---- M12-60: write_balance() reports what it booked ------------------------------------------------------ */

$m60      = vcap_rl_card( 30 );
$m60_done = null;
$m60_ok   = vcap()->coupons->adjust_balance( $m60, -50, 'adjusted', 'more than the balance', 0, $m60_done );
vcap_rl_assert( $m60_ok && -30.0 === $m60_done && 0.0 === vcap_rl_balance( $m60 ), 'M12-60: taking 50 off a balance of 30 reports the 30 really booked', $m60_done );

/* ---- M12-02: partial return of an order paid with a card and money ---------------------------------------- */

$m02_card  = vcap_rl_card( 80 );
$m02_order = wc_create_order();
$m02_line  = $m02_order->add_product( $goods, 5 );
$m02_order->set_billing_email( 'mixed-payment@example.com' );
$m02_order->set_payment_method( 'stripe' );
$m02_order->calculate_totals( false );
vcap_rl_assert( true === $m02_order->apply_coupon( $m02_card->code ), 'M12-02: the card of 80 pays for 5 items of 20' );
$m02_order = wc_get_order( $m02_order->get_id() );
vcap()->coupons->reserve_order( $m02_order );
$m02_order->payment_complete( 'txn-m02' );
$m02_order = wc_get_order( $m02_order->get_id() );
vcap_rl_assert( 20.0 === (float) $m02_order->get_total() && 0.0 === vcap_rl_balance( $m02_card ), 'M12-02: card 80 + money 20, the card is charged', array( $m02_order->get_total(), vcap_rl_balance( $m02_card ) ) );
$m02_refund = wc_create_refund( array( 'order_id' => $m02_order->get_id(), 'amount' => 20, 'line_items' => array( $m02_line => array( 'qty' => 1, 'refund_total' => 20 ) ) ) );
$m02_order  = wc_get_order( $m02_order->get_id() );
vcap_rl_assert( ! is_wp_error( $m02_refund ) && ! $m02_order->has_status( 'refunded' ), 'M12-02: returning one item refunds all the money, but the order is not set to "Refunded"', $m02_order->get_status() );
vcap_rl_assert( 0.0 === vcap_rl_balance( $m02_card ), 'M12-02: 80 card + 20 money, partial return – the card balance stays unchanged (no +80)', vcap_rl_balance( $m02_card ) );
vcap_rl_assert( Attention::has( $m02_order->get_id(), 'partial-return' ) && str_contains( vcap_rl_notes( $m02_order->get_id() ), 'Book back onto voucher' ), 'M12-02: an order note and a notice point to "Book back onto voucher"' );
$m02_order->update_status( 'refunded' );
vcap_rl_assert( 80.0 === vcap_rl_balance( $m02_card ), 'M12-02: set to "Refunded" by hand (everything returned) the card gets its 80 back', vcap_rl_balance( $m02_card ) );

/* ---- M12-07: purchase refunds by value -------------------------------------------------------------------- */

// An amount refunded without lines (gateway dashboard) takes the value off the card instead of nothing.
list( $m07, $m07_line, $m07_cards ) = vcap_rl_purchase( $giftcard, 1 );
$m07_card                           = $m07_cards[0];
$m07_refund                         = wc_create_refund( array( 'order_id' => $m07->get_id(), 'amount' => 30 ) );
vcap_rl_assert( ! is_wp_error( $m07_refund ) && 20.0 === vcap_rl_balance( $m07_card ) && Voucher::STATUS_ACTIVE === vcap_rl_status( $m07_card ), 'M12-07: 30 of 50 refunded without lines – 30 are taken off the card, it stays usable with 20', array( vcap_rl_balance( $m07_card ), vcap_rl_status( $m07_card ) ) );
vcap_rl_assert( in_array( Issuer::PURCHASE_REFUNDED_EVENT, vcap_rl_events( $m07_card ), true ), 'M12-07: the ledger records "purchase_refunded"' );
vcap_rl_assert( Attention::has( $m07->get_id(), 'refund-value:' . $m07_refund->get_id() ), 'M12-07: a refund without lines that wrote off vouchers is listed for the shop (webhook refunds)' );
$m07_rest = wc_create_refund( array( 'order_id' => $m07->get_id(), 'amount' => 20 ) );
vcap_rl_assert( ! is_wp_error( $m07_rest ) && Voucher::STATUS_VOID === vcap_rl_status( $m07_card ), 'M12-07: refunding the rest voids the card (nothing left on it)', vcap_rl_status( $m07_card ) );

// A small refund with a quantity no longer voids a partly used card with a larger rest.
list( $m07b, $m07b_line, $m07b_cards ) = vcap_rl_purchase( $giftcard, 1 );
$m07b_card                             = $m07b_cards[0];
vcap()->coupons->adjust_balance( $m07b_card, -20, 'redeemed', 'spent in the shop' );
$m07b_refund = wc_create_refund( array( 'order_id' => $m07b->get_id(), 'amount' => 10, 'line_items' => array( $m07b_line => array( 'qty' => 1, 'refund_total' => 10 ) ) ) );
vcap_rl_assert( ! is_wp_error( $m07b_refund ) && 20.0 === vcap_rl_balance( $m07b_card ) && Voucher::STATUS_ACTIVE === vcap_rl_status( $m07b_card ), 'M12-07: quantity 1 with 10 refunded takes 10 off a card with 30 left (20 stay usable, not voided)', array( vcap_rl_balance( $m07b_card ), vcap_rl_status( $m07b_card ) ) );

// Single-use vouchers: voided only once the refunded value covers the voucher.
list( $m07s, $m07s_line, $m07s_vouchers ) = vcap_rl_purchase( $single, 2 );
$m07s_first                              = wc_create_refund( array( 'order_id' => $m07s->get_id(), 'amount' => 20 ) );
$m07s_void                               = array_filter( $m07s_vouchers, static fn( Voucher $v ) => Voucher::STATUS_VOID === vcap_rl_status( $v ) );
vcap_rl_assert( ! is_wp_error( $m07s_first ) && ! $m07s_void, 'M12-07: 20 refunded of two single-use vouchers of 30 voids none of them' );
$m07s_second = wc_create_refund( array( 'order_id' => $m07s->get_id(), 'amount' => 10 ) );
$m07s_void   = array_filter( $m07s_vouchers, static fn( Voucher $v ) => Voucher::STATUS_VOID === vcap_rl_status( $v ) );
vcap_rl_assert( ! is_wp_error( $m07s_second ) && 1 === count( $m07s_void ), 'M12-07: another 10 (30 in all) voids one voucher', count( $m07s_void ) );

/* ---- M12-21: a refund that is not recorded books its write-off back --------------------------------------- */

list( $m21, $m21_line, $m21_cards ) = vcap_rl_purchase( $giftcard, 1, 'bacs' );
$m21_card                           = $m21_cards[0];
$m21_seen                           = null;
$m21_spy                            = static function ( $order ) use ( &$m21_seen, $m21_card ) {
	$m21_seen = vcap_rl_balance( $m21_card ); // During the gateway call.
	return $order;
};
add_filter( 'woocommerce_order_get_payment_method', $m21_spy );
$m21_refund = wc_create_refund( array( 'order_id' => $m21->get_id(), 'amount' => 50, 'refund_payment' => true, 'line_items' => array( $m21_line => array( 'qty' => 1, 'refund_total' => 50 ) ) ) );
remove_filter( 'woocommerce_order_get_payment_method', $m21_spy );
vcap_rl_assert( is_wp_error( $m21_refund ), 'M12-21: the gateway (bank transfer) cannot refund – WooCommerce records no refund' );
vcap_rl_assert( 0.0 === $m21_seen, 'M12-21: while the gateway is asked, the refunded card is already locked (written off)', $m21_seen );
vcap()->issuer->check_pending_refunds(); // End of the request.
vcap_rl_assert( 50.0 === vcap_rl_balance( $m21_card ) && Voucher::STATUS_ACTIVE === vcap_rl_status( $m21_card ), 'M12-21: the refund was not recorded – the card is booked back and active again', array( vcap_rl_balance( $m21_card ), vcap_rl_status( $m21_card ) ) );
vcap_rl_assert( in_array( Issuer::PURCHASE_REFUND_REVERSED_EVENT, vcap_rl_events( $m21_card ), true ) && ! wc_get_order( $m21->get_id() )->get_meta( Issuer::PENDING_REFUND_META ), 'M12-21: the ledger records the booking back, nothing stays pending' );
$m21_ok = wc_create_refund( array( 'order_id' => $m21->get_id(), 'amount' => 50, 'line_items' => array( $m21_line => array( 'qty' => 1, 'refund_total' => 50 ) ) ) );
vcap_rl_assert( ! is_wp_error( $m21_ok ) && Voucher::STATUS_VOID === vcap_rl_status( $m21_card ) && ! wc_get_order( $m21->get_id() )->get_meta( Issuer::PENDING_REFUND_META ), 'M12-21: a recorded refund keeps its write-off (confirmed)' );
vcap_rl_assert( ! as_next_scheduled_action( Issuer::REFUND_CHECK_HOOK, array( 'order_id' => $m21->get_id(), 'refund_id' => $m21_ok->get_id() ), 'vcap' ), 'M12-21: the background check of a confirmed refund is dropped' );

/* ---- M12-34: "Refunded" by hand without a refund of the total ---------------------------------------------- */

list( $m34, $m34_line, $m34_vouchers ) = vcap_rl_purchase( $single, 2 );
vcap()->vouchers->update_status( $m34_vouchers[0]->id, Voucher::STATUS_REDEEMED );
wc_get_order( $m34->get_id() )->update_status( 'refunded' ); // The automatic refund of 60 is refused (30 were used).
vcap_rl_assert( 0.0 === (float) wc_get_order( $m34->get_id() )->get_total_refunded(), 'M12-34: nothing was refunded' );
vcap_rl_assert( Voucher::STATUS_REDEEMED === vcap_rl_status( $m34_vouchers[0] ) && Voucher::STATUS_VOID === vcap_rl_status( $m34_vouchers[1] ), 'M12-34: handled as a cancellation – the used voucher stays valid, the unused one is voided' );
vcap_rl_assert( Attention::has( $m34->get_id(), 'refunded-status' ), 'M12-34: the shop gets a notice' );

/* ---- M12-35: payment reversed → vouchers suspended, paid again → usable ------------------------------------ */

list( $m35, $m35_line, $m35_cards ) = vcap_rl_purchase( $giftcard, 2 );
vcap()->coupons->adjust_balance( $m35_cards[0], -10, 'redeemed', 'spent in the shop' );
wc_get_order( $m35->get_id() )->update_status( 'on-hold', 'Dispute opened.' );
vcap_rl_assert( Voucher::STATUS_ACTIVE === vcap_rl_status( $m35_cards[0] ) && Voucher::STATUS_VOID === vcap_rl_status( $m35_cards[1] ), 'M12-35: on hold after payment – the unused card is suspended, the used one stays', array( vcap_rl_status( $m35_cards[0] ), vcap_rl_status( $m35_cards[1] ) ) );
vcap_rl_assert( in_array( Issuer::SUSPENDED_EVENT, vcap_rl_events( $m35_cards[1] ), true ) && 'draft' === get_post_status( $m35_cards[1]->coupon_id ) && Attention::has( $m35->get_id(), 'suspended' ), 'M12-35: ledger "suspended_payment", coupon disabled, notice for the shop' );
wc_get_order( $m35->get_id() )->update_status( 'processing', 'Dispute won.' );
vcap_rl_assert( Voucher::STATUS_ACTIVE === vcap_rl_status( $m35_cards[1] ) && 'publish' === get_post_status( $m35_cards[1]->coupon_id ) && ! Attention::has( $m35->get_id(), 'suspended' ), 'M12-35: paid again – the suspended card is usable again' );

/* ---- M12-08: a held voucher is not activated while its order is unpaid ------------------------------------- */

list( $m08, $m08_line, $m08_cards ) = vcap_rl_purchase( $giftcard, 1 );
$m08_card                           = vcap()->vouchers->get( $m08_cards[0]->id );
$m08_card->status                   = Voucher::STATUS_SCHEDULED;
$m08_card->delivery_at              = gmdate( 'Y-m-d H:i:s', time() - 60 ); // The security hold has just ended.
$m08_card->delivered_at             = null;
vcap()->vouchers->update( $m08_card );
vcap()->coupons->set_coupon_enabled( $m08_card, false );
$m08_held = wc_get_order( $m08->get_id() );
$m08_held->set_status( 'on-hold' ); // Dispute (the scheduled card is not suspended: it waits anyway).
$m08_held->save();
vcap()->delivery->deliver( $m08_card->id );
vcap_rl_assert( Voucher::STATUS_SCHEDULED === vcap_rl_status( $m08_card ) && 'draft' === get_post_status( $m08_card->coupon_id ), 'M12-08: the hold has ended, but the order is on hold – the voucher is not activated', vcap_rl_status( $m08_card ) );
vcap_rl_assert( Attention::has( $m08->get_id(), 'delivery-unpaid' ) && str_contains( vcap_rl_notes( $m08->get_id() ), 'not paid' ), 'M12-08: order note and notice' );
vcap_rl_assert( (bool) as_next_scheduled_action( VCAP\Delivery::RETRY_HOOK, array( 'voucher_id' => (int) $m08_card->id ), 'vcap' ), 'M12-08: tried again later' );
$m08_paid = wc_get_order( $m08->get_id() );
$m08_paid->set_status( 'processing' );
$m08_paid->save();
vcap()->delivery->deliver( $m08_card->id );
vcap_rl_assert( Voucher::STATUS_ACTIVE === vcap_rl_status( $m08_card ) && ! Attention::has( $m08->get_id(), 'delivery-unpaid' ), 'M12-08: paid again – the voucher is activated and delivered' );
as_unschedule_all_actions( VCAP\Delivery::RETRY_HOOK, null, 'vcap' );

/* ---- M12-36: paid again after a cancellation ---------------------------------------------------------------- */

list( $m36, $m36_line, $m36_cards ) = vcap_rl_purchase( $giftcard, 1 );
wc_get_order( $m36->get_id() )->update_status( 'cancelled' );
vcap_rl_assert( Voucher::STATUS_VOID === vcap_rl_status( $m36_cards[0] ), 'M12-36: cancelling voids the bought card' );
wc_get_order( $m36->get_id() )->update_status( 'processing' );
$m36_diag = array( Attention::all(), vcap_rl_notes( $m36->get_id() ), vcap_rl_events( $m36_cards[0] ) );
vcap_rl_assert( Attention::has( $m36->get_id(), 'paid-again' ) && str_contains( vcap_rl_notes( $m36->get_id() ), 'page=vcap-vouchers&#038;voucher=' . $m36_cards[0]->id . '"' ), 'M12-36: paid again – notice and an order note with the link to reactivate the card', $m36_diag );

$topup_product = vcap_rl_product( 'RL top-up', '40', array( '_vcap_enabled' => 'yes', '_vcap_type' => 'giftcard', '_vcap_usage' => 'balance', TopUp::PRODUCT_META => 'yes' ) );
$m36_target    = vcap_rl_card( 10 );
$m36_top       = wc_create_order();
$m36_top_line  = $m36_top->add_product( $topup_product, 1 );
$rl_item = $m36_top->get_item( $m36_top_line );
$rl_item->add_meta_data( TopUp::ITEM_META, $m36_target->code, true );
$rl_item->save();
$m36_top->set_billing_email( 'topup-again@example.com' );
$m36_top->calculate_totals( false );
$m36_top->set_status( 'processing' );
$m36_top->save();
vcap()->issuer->issue_for_order( wc_get_order( $m36_top->get_id() ), true );
vcap_rl_assert( 50.0 === vcap_rl_balance( $m36_target ), 'M12-36: the top-up of 40 is booked (10 → 50)', array( vcap_rl_balance( $m36_target ), vcap_rl_notes( $m36_top->get_id() ) ) );
wc_get_order( $m36_top->get_id() )->update_status( 'cancelled' );
vcap_rl_assert( 10.0 === vcap_rl_balance( $m36_target ), 'M12-36: cancelling takes the top-up back' );
wc_get_order( $m36_top->get_id() )->update_status( 'processing' );
vcap_rl_assert( 50.0 === vcap_rl_balance( $m36_target ), 'M12-36: paid again – the top-up is booked again', vcap_rl_balance( $m36_target ) );

/* ---- M12-11 / M12-57: refund as store credit or gift card with lines and VAT -------------------------------- */

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
$m11_rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'RL VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
$m11_user = wp_insert_user( array( 'user_login' => 'rl-credit-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'rl-credit-' . wp_generate_password( 6, false, false ) . '@example.com' ) );
$m11      = wc_create_order( array( 'customer_id' => $m11_user ) );
$m11->add_product( $goods, 3 ); // 60 net + 12 VAT.
$m11->set_billing_email( 'rl-credit@example.com' );
$m11->calculate_totals( true );
$m11->set_status( 'completed' );
$m11->save();
vcap_rl_assert( 72.0 === (float) $m11->get_total() && 12.0 === (float) $m11->get_total_tax(), 'M12-11: 60 net + 12 VAT', array( $m11->get_total(), $m11->get_total_tax() ) );
$m11_result = vcap()->store_credit->refund_as_credit( wc_get_order( $m11->get_id() ), 36, 'Customer chose credit', array( 'channel' => 'email' ) );
$m11_refund = wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $m11->get_id(), 'limit' => 1 ) )[0] ?? null;
vcap_rl_assert( ! is_wp_error( $m11_result ) && $m11_refund instanceof WC_Order_Refund && 6.0 === round( abs( (float) $m11_refund->get_total_tax() ), 2 ) && 1 === count( $m11_refund->get_items() ), 'M12-11: refund as store credit carries its lines and VAT (36 = 30 + 6 VAT)', $m11_refund ? array( $m11_refund->get_total_tax(), count( $m11_refund->get_items() ) ) : null );
vcap_rl_assert( 36.0 === round( abs( (float) $m11_refund->get_amount() ), 2 ), 'M12-11: the refund amount is unchanged' );

// M12-11 + M12-07: refunded as credit, a bought gift card is written off consistently.
update_option( 'woocommerce_calc_taxes', 'no' );
WC_Tax::_delete_tax_rate( $m11_rate ); // Leaves no rate behind for suites that run later (e.g. tax).
$m11b = wc_create_order( array( 'customer_id' => $m11_user ) );
$m11b->add_product( $giftcard, 1 );
$m11b->set_billing_email( 'rl-credit@example.com' );
$m11b->calculate_totals( false );
$m11b->set_status( 'processing' );
$m11b->save();
vcap()->issuer->issue_for_order( wc_get_order( $m11b->get_id() ), true );
$m11b_card = vcap()->vouchers->for_order( $m11b->get_id() )[0];
$m11b_back = vcap()->store_credit->refund_as_credit( wc_get_order( $m11b->get_id() ), 20, 'Half back as credit', array( 'channel' => 'email' ) );
vcap_rl_assert( ! is_wp_error( $m11b_back ) && 30.0 === vcap_rl_balance( $m11b_card ), 'M12-11: 20 of a 50 gift card refunded as store credit take 20 off the card', vcap_rl_balance( $m11b_card ) );

// M12-57: a guest order is refunded as a new gift card.
$m57 = wc_create_order();
$m57->add_product( $goods, 2 );
$m57->set_billing_email( 'rl-guest@example.com' );
$m57->calculate_totals( false );
$m57->set_status( 'completed' );
$m57->save();
$m57_result = vcap()->store_credit->refund_as_credit( wc_get_order( $m57->get_id() ), 15, 'Guest chose a gift card', array( 'channel' => 'phone' ) );
$m57_card   = ! is_wp_error( $m57_result ) && ! empty( $m57_result['voucher_id'] ) ? vcap()->vouchers->get( (int) $m57_result['voucher_id'] ) : null;
vcap_rl_assert( $m57_card instanceof Voucher && 15.0 === (float) $m57_card->balance && 'rl-guest@example.com' === $m57_card->buyer_email && Voucher::STATUS_ACTIVE === $m57_card->status, 'M12-57: a guest order is refunded as a new gift card of 15 for the buyer', is_wp_error( $m57_result ) ? $m57_result->get_error_message() : $m57_result );
$m57_refund   = wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $m57->get_id(), 'limit' => 1 ) )[0] ?? null;
$m57_consents = wc_get_order( $m57->get_id() )->get_meta( '_vcap_credit_consents' );
vcap_rl_assert( $m57_refund instanceof WC_Order_Refund && 1 === count( $m57_refund->get_items() ) && (int) ( $m57_consents[0]['voucher_id'] ?? 0 ) === (int) $m57_card->id && 'phone' === ( $m57_consents[0]['channel'] ?? '' ), 'M12-57: the refund has its lines and the consent is recorded with the gift card' );

/* ---- M12-37: deleted refunds ------------------------------------------------------------------------------- */

$m37_before = vcap()->store_credit->balance( $m11_user );
$m37_refund = wc_get_order( $m11_refund->get_id() );
$m37_refund->delete( true );
do_action( 'woocommerce_refund_deleted', $m11_refund->get_id(), $m11->get_id() );
vcap_rl_assert( round( $m37_before - 36, 2 ) === round( vcap()->store_credit->balance( $m11_user ), 2 ), 'M12-37: deleting a refund as store credit takes the credit back', array( $m37_before, vcap()->store_credit->balance( $m11_user ) ) );
vcap_rl_assert( in_array( 'refund_credit_reversed', vcap_rl_events( vcap()->store_credit->account( $m11_user ) ), true ), 'M12-37: ledger "refund_credit_reversed"' );
$m37_guest = wc_get_order( $m57_refund->get_id() );
$m37_guest->delete( true );
do_action( 'woocommerce_refund_deleted', $m57_refund->get_id(), $m57->get_id() );
vcap_rl_assert( Voucher::STATUS_VOID === vcap_rl_status( $m57_card ), 'M12-37: deleting a guest refund voids its unused gift card' );

list( $m37v, $m37v_line, $m37v_cards ) = vcap_rl_purchase( $giftcard, 1 );
$m37v_refund                           = wc_create_refund( array( 'order_id' => $m37v->get_id(), 'amount' => 50, 'line_items' => array( $m37v_line => array( 'qty' => 1, 'refund_total' => 50 ) ) ) );
vcap_rl_assert( Voucher::STATUS_VOID === vcap_rl_status( $m37v_cards[0] ), 'M12-37: the refunded card is voided' );
$m37v_id = $m37v_refund->get_id();
$m37v_refund->delete( true );
do_action( 'woocommerce_refund_deleted', $m37v_id, $m37v->get_id() );
vcap_rl_assert( Attention::has( $m37v->get_id(), 'refund-deleted:' . $m37v_id ), 'M12-37: deleting the refund lists the voided card for the shop' );

$m37_target = vcap_rl_card( 5 );
$m37_top    = wc_create_order();
$m37_line   = $m37_top->add_product( $topup_product, 1 );
$rl_item = $m37_top->get_item( $m37_line );
$rl_item->add_meta_data( TopUp::ITEM_META, $m37_target->code, true );
$rl_item->save();
$m37_top->set_billing_email( 'topup-deleted@example.com' );
$m37_top->calculate_totals( false );
$m37_top->set_status( 'processing' );
$m37_top->save();
vcap()->issuer->issue_for_order( wc_get_order( $m37_top->get_id() ), true );
$m37_top_refund = wc_create_refund( array( 'order_id' => $m37_top->get_id(), 'amount' => 40, 'line_items' => array( $m37_line => array( 'qty' => 0, 'refund_total' => 40 ) ) ) );
vcap_rl_assert( 5.0 === vcap_rl_balance( $m37_target ), 'M12-37: the refunded top-up is taken back (45 → 5)' );
wc_get_order( $m37_top->get_id() )->update_status( 'processing' ); // Back from "Refunded" while the refund is deleted.
$m37_top_id = $m37_top_refund->get_id();
$m37_top_refund->delete( true );
do_action( 'woocommerce_refund_deleted', $m37_top_id, $m37_top->get_id() );
vcap_rl_assert( 45.0 === vcap_rl_balance( $m37_target ), 'M12-37: deleting the refund books the top-up again', vcap_rl_balance( $m37_target ) );

/* ---- M12-38: subscriptions (functions of WooCommerce Subscriptions stubbed) -------------------------------- */

if ( ! function_exists( 'wcs_order_contains_switch' ) && ! class_exists( 'WC_Subscriptions_Product' ) ) {
	$GLOBALS['vcap_rl_wcs'] = array();
	function wcs_order_contains_renewal( $order ) {
		return 'renewal' === ( $GLOBALS['vcap_rl_wcs'][ $order->get_id() ] ?? '' );
	}
	function wcs_order_contains_switch( $order ) {
		return 'switch' === ( $GLOBALS['vcap_rl_wcs'][ $order->get_id() ] ?? '' );
	}
	function wcs_order_contains_resubscribe( $order ) {
		return 'resubscribe' === ( $GLOBALS['vcap_rl_wcs'][ $order->get_id() ] ?? '' );
	}
	function wcs_order_contains_subscription( $order ) {
		return in_array( $GLOBALS['vcap_rl_wcs'][ $order->get_id() ] ?? '', array( 'trial', 'signup' ), true );
	}
	function wcs_get_subscriptions_for_renewal_order( $order ) {
		$parent = $GLOBALS['vcap_rl_wcs'][ 'parent_' . $order->get_id() ] ?? 0;
		return $parent ? array( new class( $parent ) {
			public function __construct( private int $parent ) {}
			public function get_parent() {
				return wc_get_order( $this->parent );
			}
		} ) : array();
	}
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Subscriptions_Product {
		public static function get_trial_length( $product ) {
			return 7;
		}
	}
	$subs   = new VCAP\Compat\Subscriptions();
	$order  = static function ( float $total, string $kind ): WC_Order {
		$o = wc_create_order();
		$o->set_total( $total );
		$o->save();
		$GLOBALS['vcap_rl_wcs'][ $o->get_id() ] = $kind;
		return $o;
	};
	$switch = $order( 30, 'switch' );
	vcap_rl_assert( false === $subs->should_issue( true, $switch ), 'M12-38: a switch order issues no voucher' );
	vcap_rl_assert( false === $subs->should_issue( true, $order( 30, 'resubscribe' ) ), 'M12-38: a resubscribe order issues no voucher' );
	add_filter( 'vcap_subscription_issue_on_switch', '__return_true' );
	vcap_rl_assert( true === $subs->should_issue( true, $switch ), 'M12-38: the filter allows it' );
	remove_filter( 'vcap_subscription_issue_on_switch', '__return_true' );
	$trial = wc_create_order();
	$trial->add_product( $giftcard, 1, array( 'subtotal' => 0, 'total' => 0 ) );
	$trial->calculate_totals( false );
	$trial->save();
	$GLOBALS['vcap_rl_wcs'][ $trial->get_id() ] = 'trial';
	vcap_rl_assert( false === $subs->should_issue( true, wc_get_order( $trial->get_id() ) ) && 'yes' === wc_get_order( $trial->get_id() )->get_meta( VCAP\Compat\Subscriptions::TRIAL_META ), 'M12-38: a free trial sign-up (total 0) waits for the first paid renewal' );
	$renewal                                            = $order( 50, 'renewal' );
	$GLOBALS['vcap_rl_wcs'][ 'parent_' . $renewal->get_id() ] = $trial->get_id();
	vcap_rl_assert( true === $subs->should_issue( true, $renewal ) && true === $subs->should_issue( true, $renewal ), 'M12-38: the first paid renewal issues (also without the renewal setting, asked twice)' );
	$renewal2                                            = $order( 50, 'renewal' );
	$GLOBALS['vcap_rl_wcs'][ 'parent_' . $renewal2->get_id() ] = $trial->get_id();
	vcap_rl_assert( false === $subs->should_issue( true, $renewal2 ), 'M12-38: later renewals follow the renewal setting (off)' );
}

delete_option( 'vcap_store_credit' );
update_option( 'woocommerce_calc_taxes', 'no' );
echo "Refund lifecycle checks passed.\n";
