<?php
/**
 * Gift card top-ups: refunds (partial, full, top-up partly or fully spent), the Store API (blocks), product
 * lists, "order again" and top-up lines without a card code.
 *   wp eval-file tests/topup-integration.php
 */

use VCAP\TopUp;

function vcap_topup_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_topup_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-topup-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 98000000 + $coupon->get_id();
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

function vcap_topup_balance( VCAP\Voucher $card ): float {
	return round( (float) vcap()->vouchers->get( $card->id )->balance, 2 );
}

update_option( 'woocommerce_calc_taxes', 'no' );
$topup_product = new WC_Product_Simple();
$topup_product->set_name( 'Gift card top-up' );
$topup_product->set_regular_price( '100' );
$topup_product->set_virtual( true );
$topup_product->update_meta_data( '_vcap_enabled', 'yes' );
$topup_product->update_meta_data( '_vcap_type', 'giftcard' );
$topup_product->update_meta_data( '_vcap_usage', 'balance' );
$topup_product->update_meta_data( TopUp::PRODUCT_META, 'yes' );
$topup_product->save();
$goods = new WC_Product_Simple();
$goods->set_name( 'Top-up test goods' );
$goods->set_regular_price( '50' );
$goods->set_virtual( true );
$goods->save();

/**
 * A paid order topping up the card by $amount (plus other goods of $goods_value), booked like at payment.
 *
 * @return array{0: WC_Order, 1: int} order, top-up line id
 */
function vcap_topup_order( WC_Product $topup_product, VCAP\Voucher $card, float $amount, ?WC_Product $goods = null, float $goods_value = 0 ): array {
	$order   = wc_create_order();
	$line_id = $order->add_product( $topup_product, 1, array( 'subtotal' => $amount, 'total' => $amount ) );
	$line    = $order->get_item( $line_id );
	$line->add_meta_data( TopUp::ITEM_META, $card->code, true );
	$line->save();
	if ( $goods ) {
		$order->add_product( $goods, 1, array( 'subtotal' => $goods_value, 'total' => $goods_value ) );
	}
	$order->set_billing_email( 'topup-refund@example.com' );
	$order->calculate_totals( false );
	$order->set_status( 'processing' );
	$order->save();
	vcap()->issuer->issue_for_order( wc_get_order( $order->get_id() ), true );
	return array( wc_get_order( $order->get_id() ), $line_id );
}

function vcap_topup_refund( WC_Order $order, float $amount, array $lines = array() ) {
	return wc_create_refund(
		array(
			'order_id'   => $order->get_id(),
			'amount'     => $amount,
			'line_items' => $lines,
		)
	);
}

/* ---- 1. Refunds of an unspent top-up: partial, then the rest ------------------------------------------ */

$card = vcap_topup_card( 20 );
list( $order, $line ) = vcap_topup_order( $topup_product, $card, 100 );
vcap_topup_assert( 120.0 === vcap_topup_balance( $card ), 'a top-up of 100 is booked onto the card (20 → 120)' );
$partial = vcap_topup_refund( $order, 40, array( $line => array( 'qty' => 0, 'refund_total' => 40 ) ) );
vcap_topup_assert( ! is_wp_error( $partial ) && 80.0 === vcap_topup_balance( $card ), 'a partial refund of 40 on the top-up line takes 40 back from the card', is_wp_error( $partial ) ? $partial->get_error_message() : vcap_topup_balance( $card ) );
vcap_topup_assert( 40.0 === (float) wc_get_order( $order->get_id() )->get_item( $line )->get_meta( TopUp::REVERSED_META ), 'the line remembers what was taken back' );
do_action( 'woocommerce_order_partially_refunded', $order->get_id(), 0 );
vcap_topup_assert( 80.0 === vcap_topup_balance( $card ), 'handling the same refunds again takes nothing more back' );
$rest = vcap_topup_refund( wc_get_order( $order->get_id() ), 60, array( $line => array( 'qty' => 1, 'refund_total' => 60 ) ) );
vcap_topup_assert( ! is_wp_error( $rest ) && 'refunded' === wc_get_order( $order->get_id() )->get_status() && 20.0 === vcap_topup_balance( $card ), 'refunding the rest refunds the order and takes the remaining 60 back (card back at 20)', is_wp_error( $rest ) ? $rest->get_error_message() : vcap_topup_balance( $card ) );

/* ---- 2. Top-up partly spent ------------------------------------------------------------------------- */

$card = vcap_topup_card( 10 );
list( $order, $line ) = vcap_topup_order( $topup_product, $card, 100 );
vcap()->coupons->adjust_balance( vcap()->vouchers->get( $card->id ), -80, 'redeemed', 'spent in the shop' );
vcap_topup_assert( 30.0 === vcap_topup_balance( $card ), 'the card (10 + top-up 100) is spent down to 30' );
$too_much = vcap_topup_refund( $order, 100, array( $line => array( 'qty' => 1, 'refund_total' => 100 ) ) );
vcap_topup_assert( is_wp_error( $too_much ) && 30.0 === vcap_topup_balance( $card ) && 'processing' === wc_get_order( $order->get_id() )->get_status(), 'a full refund of a partly spent top-up is refused (it would pay out spent value)', is_wp_error( $too_much ) ? $too_much->get_error_message() : 'refund created' );
vcap_topup_assert( false !== strpos( $too_much->get_error_message(), '30' ), 'the message names the maximum of 30', $too_much->get_error_message() );
$fits = vcap_topup_refund( wc_get_order( $order->get_id() ), 30, array( $line => array( 'qty' => 0, 'refund_total' => 30 ) ) );
vcap_topup_assert( ! is_wp_error( $fits ) && 0.0 === vcap_topup_balance( $card ), 'refunding what is left on the card (30) is allowed and takes it back', is_wp_error( $fits ) ? $fits->get_error_message() : vcap_topup_balance( $card ) );
$more = vcap_topup_refund( wc_get_order( $order->get_id() ), 10 );
vcap_topup_assert( is_wp_error( $more ), 'a further refund (amount only) is refused: the rest of the top-up was spent' );

/* ---- 3. Top-up fully spent -------------------------------------------------------------------------- */

$card = vcap_topup_card( 0.01 );
list( $order, $line ) = vcap_topup_order( $topup_product, $card, 50 );
vcap()->coupons->adjust_balance( vcap()->vouchers->get( $card->id ), -50.01, 'redeemed', 'spent in the shop' );
$none = vcap_topup_refund( $order, 1 );
vcap_topup_assert( is_wp_error( $none ), 'nothing of a fully spent top-up can be refunded (not even 1 as an amount)' );
$none_line = vcap_topup_refund( wc_get_order( $order->get_id() ), 5, array( $line => array( 'qty' => 0, 'refund_total' => 5 ) ) );
vcap_topup_assert( is_wp_error( $none_line ), 'nothing of a fully spent top-up can be refunded on its line' );
add_filter( 'vcap_allow_refund_of_spent_vouchers', '__return_true' );
add_filter( 'vcap_allow_refund_of_spent_top_ups', '__return_true' );
$knowingly = vcap_topup_refund( wc_get_order( $order->get_id() ), 50, array( $line => array( 'qty' => 1, 'refund_total' => 50 ) ) );
remove_filter( 'vcap_allow_refund_of_spent_vouchers', '__return_true' );
remove_filter( 'vcap_allow_refund_of_spent_top_ups', '__return_true' );
$notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
vcap_topup_assert( ! is_wp_error( $knowingly ) && 0.0 === vcap_topup_balance( $card ) && false !== strpos( $notes, 'already been spent' ), 'a refund allowed knowingly (filters) takes back nothing and notes that the top-up was spent', $notes );

/* ---- 4. Top-up and other goods: an amount refund pays back the goods first --------------------------- */

$card = vcap_topup_card( 0.01 );
list( $order, $line ) = vcap_topup_order( $topup_product, $card, 100, $goods, 50 );
vcap()->coupons->adjust_balance( vcap()->vouchers->get( $card->id ), -60.01, 'redeemed', 'spent in the shop' );
vcap_topup_assert( 150.0 === (float) $order->get_total() && 40.0 === vcap_topup_balance( $card ), 'order: top-up 100 + goods 50; 60 of the top-up spent (40 left)' );
$goods_back = vcap_topup_refund( $order, 50 );
vcap_topup_assert( ! is_wp_error( $goods_back ) && 40.0 === vcap_topup_balance( $card ), 'refunding 50 as an amount pays back the goods and leaves the card alone' );
$topup_back = vcap_topup_refund( wc_get_order( $order->get_id() ), 40 );
vcap_topup_assert( ! is_wp_error( $topup_back ) && 0.0 === vcap_topup_balance( $card ), 'refunding another 40 takes the unspent 40 of the top-up back', is_wp_error( $topup_back ) ? $topup_back->get_error_message() : vcap_topup_balance( $card ) );
vcap_topup_assert( is_wp_error( vcap_topup_refund( wc_get_order( $order->get_id() ), 10 ) ), 'the spent 60 of the top-up cannot be refunded' );

/* ---- 5. Top-up line without a card code: no new card ------------------------------------------------- */

$no_code = wc_create_order();
$no_line = $no_code->add_product( $topup_product, 1 );
$no_code->set_billing_email( 'topup-missing@example.com' );
$no_code->calculate_totals( false );
$no_code->set_status( 'processing' );
$no_code->save();
vcap()->issuer->issue_for_order( wc_get_order( $no_code->get_id() ), true );
$no_notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $no_code->get_id() ) ), 'content' ) );
vcap_topup_assert( array() === vcap()->vouchers->for_order( $no_code->get_id() ) && 'yes' === wc_get_order( $no_code->get_id() )->get_item( $no_line )->get_meta( TopUp::MISSING_META ) && false !== strpos( $no_notes, 'no gift card code' ), 'a top-up line without card code issues no new card; the line is held with an order note', $no_notes );

/* ---- 6. Product lists, Store API (blocks), order again, cart check ----------------------------------- */

$topup_product = wc_get_product( $topup_product->get_id() );
vcap_topup_assert( $topup_product->get_permalink() === $topup_product->add_to_cart_url() && ! $topup_product->supports( 'ajax_add_to_cart' ), 'product lists send top-up products to the product page (no AJAX/Store API add-to-cart button)' );

add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
wc_load_cart();
WC()->cart->empty_cart();
$store_card = vcap_topup_card( 5 );
$add        = static function ( array $params ) use ( $topup_product ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'id', $topup_product->get_id() );
	$request->set_param( 'quantity', 1 );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$missing = $add( array() );
vcap_topup_assert( $missing->is_error() && 0 === count( WC()->cart->get_cart() ), 'Store API: a top-up without card code is refused' );
$unknown = $add( array( 'vcap_topup_code' => 'no-such-card-' . wp_generate_password( 6, false, false ) ) );
vcap_topup_assert( $unknown->is_error() && 0 === count( WC()->cart->get_cart() ), 'Store API: an unknown card code is refused' );
$ok = $add( array( 'vcap_topup_code' => strtoupper( $store_card->code ) ) );
$items = array_values( WC()->cart->get_cart() );
vcap_topup_assert( ! $ok->is_error() && 1 === count( $items ) && $store_card->code === ( $items[0]['vcap_topup_code'] ?? '' ), 'Store API: the top-up is added with its card code as cart item data', $ok->is_error() ? $ok->get_data() : $items );
$cart_data = wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data()['items'][0]['item_data'] ?? array() );
vcap_topup_assert( false !== strpos( (string) $cart_data, substr( strtoupper( $store_card->code ), -4 ) ) && false === strpos( (string) $cart_data, strtoupper( $store_card->code ) ), 'the cart block shows the card only masked' );

$again_order = wc_create_order();
$again_line  = $again_order->get_item( $again_order->add_product( $topup_product, 1 ) );
$again_line->add_meta_data( TopUp::ITEM_META, $store_card->code, true );
$again_line->save();
$again_data = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $again_line, $again_order );
vcap_topup_assert( $store_card->code === ( $again_data['vcap_topup_code'] ?? '' ) && true === apply_filters( 'woocommerce_add_to_cart_validation', true, $topup_product->get_id(), 1, 0, array(), $again_data ), '"order again" tops up the same card again and passes validation' );

WC()->cart->empty_cart();
wc_clear_notices();
WC()->cart->add_to_cart( $topup_product->get_id(), 1 );
do_action( 'woocommerce_check_cart_items' );
vcap_topup_assert( wc_notice_count( 'error' ) > 0, 'a top-up line that got into the cart without card code cannot be ordered' );
wc_clear_notices();
WC()->cart->empty_cart();

echo "Voucher Captain top-up checks passed.\n";
