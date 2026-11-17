<?php
/**
 * Cart, coupon and checkout (audit M12, package D): own reservations, per-line rules of gift cards, currencies,
 * voucher payment order, notes and messages, the gift card field.
 *   wp eval-file tests/cart-coupons-integration.php
 */

function vcap_cc_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_cc_card( float $value, array $props = array() ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-cc-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	foreach ( $props as $prop => $prop_value ) {
		$coupon->{'set_' . $prop}( $prop_value );
	}
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 96000000 + $coupon->get_id();
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

function vcap_cc_product( string $name, string $price, string $sale = '' ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	if ( '' !== $sale ) {
		$product->set_sale_price( $sale );
	}
	$product->set_virtual( true );
	$product->save();
	return $product;
}

/** Discount of the code in the main cart (excl. tax, taxes are off in this suite). */
function vcap_cc_discount( string $code ): float {
	return round( (float) WC()->cart->get_coupon_discount_amount( wc_format_coupon_code( $code ), true ), 2 );
}

/** Error notices WooCommerce collected (plain text), cleared afterwards. */
function vcap_cc_errors(): array {
	$errors = array_map( static fn( $n ) => wp_strip_all_tags( is_array( $n ) ? (string) $n['notice'] : (string) $n ), wc_get_notices( 'error' ) );
	wc_clear_notices();
	return $errors;
}

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( VCAP\PaymentVouchers::OPTION, 'no' );
wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();

/* ---- M12-13: the reservation of this visitor's own unpaid checkout order stays usable ---------------- */

$goods = vcap_cc_product( 'CC goods 20', '20' );
$card  = vcap_cc_card( 20 );
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $card->code );
WC()->cart->calculate_totals();
$before = vcap_cc_discount( $card->code );
// Checkout: the order is created from the cart and its balance reserved; then the customer comes back from the
// payment page without paying (the order stays pending; a failed order releases its holds itself).
$attempt = wc_create_order();
$attempt->add_product( $goods, 1 );
$attempt->calculate_totals();
$attempt->apply_coupon( $card->code );
$attempt = wc_get_order( $attempt->get_id() );
WC()->session->set( VCAP\CouponManager::SESSION_ORDER, $attempt->get_id() );
vcap()->coupons->reserve_order( $attempt );
$reserved = vcap()->vouchers->get( $card->id );
vcap_cc_assert( 20.0 === $before && 0.0 === (float) $reserved->balance && VCAP\Voucher::STATUS_REDEEMED === $reserved->status, 'M12-13 setup: the checkout reserved the whole 20 card (balance 0, status redeemed)' );
VCAP\Coupons\OwnHold::reset();
do_action( 'woocommerce_check_cart_items' );
$errors = vcap_cc_errors();
WC()->cart->calculate_totals();
vcap_cc_assert( WC()->cart->has_discount( $card->code ) && ! $errors && 20.0 === vcap_cc_discount( $card->code ), 'M12-13: after an abandoned payment the next check_cart_items keeps the card and calculate_totals keeps the 20 discount', array( $errors, vcap_cc_discount( $card->code ) ) );
// Not this visitor's order (another session): the card is fully used up for this cart.
WC()->session->set( VCAP\CouponManager::SESSION_ORDER, 0 );
VCAP\Coupons\OwnHold::reset();
do_action( 'woocommerce_check_cart_items' );
$errors = vcap_cc_errors();
vcap_cc_assert( ! WC()->cart->has_discount( $card->code ) && $errors, 'M12-13: the hold of an order that is not the session\'s own does not count (card removed as redeemed)', $errors );
// The next checkout order of the session gives the old hold back (priority 49) and reserves again (51).
WC()->session->set( VCAP\CouponManager::SESSION_ORDER, $attempt->get_id() );
VCAP\Coupons\OwnHold::reset();
WC()->cart->apply_coupon( $card->code );
WC()->cart->calculate_totals();
$retry = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => 'cc-retry@example.com', 'payment_method' => 'bacs' ) ) );
vcap()->coupons->release_previous_checkout_order( $retry );
vcap()->coupons->reserve_order( $retry );
$retry_map = VCAP\Coupons\OrderCoupons::order_map( wc_get_order( $retry->get_id() ), '_vcap_reservations' );
vcap_cc_assert( 20.0 === vcap_cc_discount( $card->code ) && 20.0 === (float) ( $retry_map[ $card->code ] ?? 0 ) && ! VCAP\Coupons\OrderCoupons::order_map( wc_get_order( $attempt->get_id() ), '_vcap_reservations' ), 'M12-13: the retry keeps the full discount, releases the failed order\'s hold and reserves the 20 for the new order', $retry_map );

// A partly reserved card: the cart may still use the reserved part plus the free balance.
WC()->cart->empty_cart();
$partial_goods = vcap_cc_product( 'CC goods 30', '30' );
$partial       = vcap_cc_card( 50 );
$half          = wc_create_order();
$half->add_product( $partial_goods, 1 );
$half->calculate_totals();
$half->apply_coupon( $partial->code );
$half = wc_get_order( $half->get_id() );
WC()->session->set( VCAP\CouponManager::SESSION_ORDER, $half->get_id() );
vcap()->coupons->reserve_order( $half );
WC()->cart->add_to_cart( $partial_goods->get_id(), 2 );
VCAP\Coupons\OwnHold::reset();
WC()->cart->apply_coupon( $partial->code );
do_action( 'woocommerce_check_cart_items' );
vcap_cc_errors();
WC()->cart->calculate_totals();
$remaining = VCAP\Coupons\CartCoupons::remaining_after_cart( vcap()->vouchers->get( $partial->id ), WC()->cart, $partial->code, array() );
vcap_cc_assert( 20.0 === (float) vcap()->vouchers->get( $partial->id )->balance && 50.0 === vcap_cc_discount( $partial->code ) && 0.0 === $remaining, 'M12-13: a card with 30 of 50 held by the own order pays 50 in the cart (not cut to the free 20)', array( vcap_cc_discount( $partial->code ), $remaining ) );
// An order recalculation never gets the cart's hold added.
$recalc_order = wc_get_order( $half->get_id() );
$recalc_order->calculate_totals();
vcap_cc_assert( 30.0 === round( (float) wc_get_order( $half->get_id() )->get_discount_total(), 2 ), 'M12-13: recalculating the own order keeps its 30 (the hold is added only in the cart)', wc_get_order( $half->get_id() )->get_discount_total() );
WC()->session->set( VCAP\CouponManager::SESSION_ORDER, 0 );
WC()->session->set( 'order_awaiting_payment', 0 );
VCAP\Coupons\OwnHold::reset();
WC()->cart->empty_cart();
WC()->cart->remove_coupons();

/* ---- M12-14: product and exclusion rules of fixed-cart gift cards apply per line (coupon mode) ------- */

$term      = wp_insert_term( 'CC excluded ' . wp_generate_password( 6, false, false ), 'product_cat' );
$plain     = vcap_cc_product( 'CC plain 30', '30' );
$sale      = vcap_cc_product( 'CC sale 40/20', '40', '20' );
$other     = vcap_cc_product( 'CC other 25', '25' );
$excluded  = vcap_cc_product( 'CC excluded cat 15', '15' );
wp_set_object_terms( $excluded->get_id(), array( (int) $term['term_id'] ), 'product_cat' );
$no_sale   = vcap_cc_card( 100, array( 'exclude_sale_items' => true ) );
$only      = vcap_cc_card( 100, array( 'product_ids' => array( $plain->get_id() ) ) );
$no_cat    = vcap_cc_card( 100, array( 'excluded_product_categories' => array( (int) $term['term_id'] ) ) );
$no_prod   = vcap_cc_card( 100, array( 'excluded_product_ids' => array( $other->get_id() ) ) );

WC()->cart->add_to_cart( $plain->get_id(), 1 );
$sale_key = WC()->cart->add_to_cart( $sale->get_id(), 1 );
$applied  = WC()->cart->apply_coupon( $no_sale->code );
$errors   = vcap_cc_errors();
WC()->cart->calculate_totals();
$sale_line = WC()->cart->get_cart()[ $sale_key ];
vcap_cc_assert( $applied && ! $errors && 30.0 === vcap_cc_discount( $no_sale->code ) && 20.0 === round( (float) $sale_line['line_total'], 2 ), 'M12-14: "exclude sale items" no longer refuses the whole card; it pays the 30 of the regular line and leaves the sale line at 20', array( $errors, vcap_cc_discount( $no_sale->code ) ) );
WC()->cart->remove_coupons();

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $plain->get_id(), 1 );
$other_key = WC()->cart->add_to_cart( $other->get_id(), 1 );
WC()->cart->apply_coupon( $only->code );
WC()->cart->calculate_totals();
vcap_cc_assert( 30.0 === vcap_cc_discount( $only->code ) && 25.0 === round( (float) WC()->cart->get_cart()[ $other_key ]['line_total'], 2 ), 'M12-14: a card limited to one product pays only that product (30), not the other line (25)', vcap_cc_discount( $only->code ) );
WC()->cart->remove_coupons();
WC()->cart->apply_coupon( $no_prod->code );
$errors = vcap_cc_errors();
WC()->cart->calculate_totals();
vcap_cc_assert( ! $errors && 30.0 === vcap_cc_discount( $no_prod->code ), 'M12-14: an excluded product in the cart no longer refuses the card; the other line is paid', array( $errors, vcap_cc_discount( $no_prod->code ) ) );
WC()->cart->remove_coupons();

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $excluded->get_id(), 1 );
$refused = WC()->cart->apply_coupon( $no_cat->code );
$errors  = vcap_cc_errors();
vcap_cc_assert( ! $refused && $errors && str_contains( implode( ' ', $errors ), 'cannot be used for the products in your cart' ), 'M12-14: a card whose rules leave no line of the cart is refused', $errors );
WC()->cart->add_to_cart( $plain->get_id(), 1 );
WC()->cart->apply_coupon( $no_cat->code );
WC()->cart->calculate_totals();
vcap_cc_assert( 30.0 === vcap_cc_discount( $no_cat->code ), 'M12-14: with a qualifying line the card pays only that line, not the excluded category', vcap_cc_discount( $no_cat->code ) );
$stored = new WC_Coupon( $no_cat->coupon_id );
vcap_cc_assert( array( (int) $term['term_id'] ) === $stored->get_excluded_product_categories( 'edit' ) && array() === $stored->get_excluded_product_categories(), 'M12-14: the stored exclusion stays (edit context, wp-admin), WooCommerce\'s whole-cart check sees none (view context)' );
WC()->cart->remove_coupons();
WC()->cart->empty_cart();

/* ---- M12-70: voucher payments after tax follow the coupon order (store credit first / last) ---------- */

update_option( VCAP\PaymentVouchers::OPTION, 'yes' );
add_filter( 'vcap_voucher_pays_after_tax', '__return_true' );
$pay_first  = vcap_cc_card( 50 );
$pay_second = vcap_cc_card( 50 );
$sort_probe = static fn( $sort, $coupon ) => $coupon instanceof WC_Coupon && wc_format_coupon_code( $coupon->get_code() ) === $pay_second->code ? 90 : $sort;
add_filter( 'woocommerce_coupon_sort', $sort_probe, 30, 2 );
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $pay_first->code );
WC()->cart->apply_coupon( $pay_second->code );
WC()->cart->calculate_totals();
$paid = vcap()->payments->cart_payments();
vcap_cc_assert( array( $pay_second->code => 20.0 ) === $paid, 'M12-70: in payment mode the voucher sorted first (e.g. "use store credit first") pays, not the one entered first', $paid );
remove_filter( 'woocommerce_coupon_sort', $sort_probe, 30 );
remove_filter( 'vcap_voucher_pays_after_tax', '__return_true' );
update_option( VCAP\PaymentVouchers::OPTION, 'no' );
WC()->cart->remove_coupons();
WC()->cart->empty_cart();

/* ---- M12-71: the classic checkout review shows the remaining balance ------------------------------- */

$review_card = vcap_cc_card( 50 );
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $review_card->code );
WC()->cart->calculate_totals();
ob_start();
do_action( 'woocommerce_review_order_after_order_total' );
$review = (string) ob_get_clean();
vcap_cc_assert( str_contains( $review, 'vcap-balance' ) && str_contains( $review, $review_card->code ), 'M12-71: the classic checkout order review shows "Remaining balance of voucher … after this order"', $review );
WC()->cart->remove_coupons();
WC()->cart->empty_cart();

/* ---- M12-93: cart messages name the dates --------------------------------------------------------- */

$later = vcap_cc_card( 10 );
$later->status      = VCAP\Voucher::STATUS_SCHEDULED;
$later->delivery_at = gmdate( 'Y-m-d H:i:s', strtotime( '+10 days' ) );
vcap()->vouchers->update( $later );
$gone = vcap_cc_card( 10 );
$gone->expires_at = gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) );
vcap()->vouchers->update( $gone );
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $later->code );
$later_errors = vcap_cc_errors();
WC()->cart->apply_coupon( $gone->code );
$gone_errors = vcap_cc_errors();
$fmt = get_option( 'date_format' );
vcap_cc_assert( str_contains( implode( ' ', $later_errors ), wp_date( $fmt, strtotime( $later->delivery_at . ' UTC' ) ) ) && str_contains( implode( ' ', $later_errors ), 'active from' ), 'M12-93: a scheduled card says from which date it is active', $later_errors );
vcap_cc_assert( str_contains( implode( ' ', $gone_errors ), wp_date( $fmt, strtotime( $gone->expires_at . ' UTC' ) ) ) && str_contains( implode( ' ', $gone_errors ), 'expired on' ), 'M12-93: an expired card says on which date it expired', $gone_errors );
WC()->cart->empty_cart();

/* ---- M12-33: foreign currencies (WCML-like switcher simulated) ------------------------------------- */

$base_currency = (string) get_option( 'woocommerce_currency' );
// A WCML stand-in that knows DKK (7.46 per base unit) and returns other currencies unchanged, as WCML does.
$GLOBALS['woocommerce_wpml'] = (object) array(
	'multi_currency' => (object) array(
		'prices' => new class() {
			public function unconvert_price_amount( $amount, $currency ) {
				return 'DKK' === $currency ? (float) $amount / 7.46 : (float) $amount;
			}
		},
	),
);
// A WOOCS stand-in that publishes a rate for SEK.
$GLOBALS['WOOCS'] = new class() {
	public function get_currencies() {
		return array( 'SEK' => array( 'rate' => 11 ) );
	}
};
$sek = (float) apply_filters( 'vcap_amount_to_base_currency', 110.0, 'SEK', null );
vcap_cc_assert( abs( 10.0 - $sek ) < 0.0001, 'M12-33: convert() does not take the unchanged WCML answer for a currency WCML does not know; the published WOOCS rate converts 110 SEK to 10', $sek );
unset( $GLOBALS['WOOCS'] );

// The cart is in DKK; WCML converts coupon amounts when they are loaded.
$dkk         = static fn() => 'DKK';
$wcml_coupon = static function ( $coupon ) {
	if ( $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta( '_vcap_voucher' ) ) {
		$coupon->set_amount( round( (float) $coupon->get_amount( 'edit' ) * 7.46, 2 ) );
	}
};
$dkk_goods = vcap_cc_product( 'CC goods 1000 DKK', '1000' );
$dkk_card  = vcap_cc_card( 100 ); // Kept in the base currency.
add_filter( 'woocommerce_currency', $dkk, 99 );
add_action( 'woocommerce_coupon_loaded', $wcml_coupon );
WC()->cart->add_to_cart( $dkk_goods->get_id(), 1 );
WC()->cart->apply_coupon( $dkk_card->code );
$errors = vcap_cc_errors();
WC()->cart->calculate_totals();
vcap_cc_assert( ! $errors && 746.0 === vcap_cc_discount( $dkk_card->code ), 'M12-33: a 100 card in a DKK cart (WCML) pays 746 DKK, not 100 DKK (balance converted before the cap)', array( $errors, vcap_cc_discount( $dkk_card->code ) ) );
WC()->cart->remove_coupons();
WC()->cart->empty_cart();
remove_filter( 'woocommerce_currency', $dkk, 99 );
remove_action( 'woocommerce_coupon_loaded', $wcml_coupon );
unset( $GLOBALS['woocommerce_wpml'] );

// A card issued before the base currency was changed: never 50 of the new currency.
$old_currency      = 'CHF' === $base_currency ? 'EUR' : 'CHF';
$foreign           = vcap_cc_card( 50 );
$foreign->currency = $old_currency;
vcap()->vouchers->update( $foreign );
WC()->cart->add_to_cart( $goods->get_id(), 1 );
$applied = WC()->cart->apply_coupon( $foreign->code );
$errors  = vcap_cc_errors();
vcap_cc_assert( ! $applied && str_contains( implode( ' ', $errors ), $old_currency ), 'M12-33: the cart refuses a card kept in another currency than the base currency', $errors );
WC()->cart->empty_cart();
$api_default = vcap()->redemptions->check( $foreign->code, array( 'amount' => 10 ) );
$api_named   = vcap()->redemptions->check( $foreign->code, array( 'amount' => 10, 'currency' => strtolower( $old_currency ) ) );
$api_reserve = vcap()->redemptions->reserve( $foreign->code, array( 'key' => 'cc-currency-' . wp_generate_password( 8, false, false ), 'target' => 'test:cc', 'amount' => 10 ) );
vcap_cc_assert( is_wp_error( $api_default ) && 'vcap_currency_mismatch' === $api_default->get_error_code() && ! is_wp_error( $api_named ), 'M12-33: the API check refuses it in the base currency (vcap_currency_mismatch) and accepts it when the caller names its currency', array( is_wp_error( $api_default ) ? $api_default->get_error_code() : $api_default, is_wp_error( $api_named ) ? $api_named->get_error_code() : 'ok' ) );
vcap_cc_assert( is_wp_error( $api_reserve ) && 'vcap_currency_mismatch' === $api_reserve->get_error_code(), 'M12-33: the API reservation refuses it as well', is_wp_error( $api_reserve ) ? $api_reserve->get_error_code() : $api_reserve );
$health = ( new VCAP\Health() )->test_currency();
vcap_cc_assert( 'critical' === $health['status'] && str_contains( wp_strip_all_tags( $health['description'] ), $old_currency . ' (' ), 'M12-33: Site Health names the vouchers kept in another currency', $health );
$foreign->status = VCAP\Voucher::STATUS_VOID;
vcap()->vouchers->update( $foreign );

/* ---- M12-20: Store API cart/add-item takes the customer-chosen amount ----------------------------- */

add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
WC()->cart->empty_cart();
function vcap_cc_custom_product( bool $topup ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $topup ? 'CC top-up, any amount' : 'CC gift card, any amount' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->update_meta_data( '_vcap_type', 'giftcard' );
	$product->update_meta_data( '_vcap_usage', 'balance' );
	$product->update_meta_data( '_vcap_amount_source', 'custom' );
	$product->update_meta_data( '_vcap_custom_min', '10' );
	$product->update_meta_data( '_vcap_custom_max', '200' );
	$product->update_meta_data( '_vcap_custom_presets', '25; 50' );
	if ( $topup ) {
		$product->update_meta_data( VCAP\TopUp::PRODUCT_META, 'yes' );
	}
	$product->save();
	return $product;
}
function vcap_cc_store_add( int $id, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'id', $id );
	$request->set_param( 'quantity', 1 );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}
$any        = vcap_cc_custom_product( false );
$_REQUEST['vcap_amount'] = '77'; // Must never be read for a Store API request.
$none       = vcap_cc_store_add( $any->get_id(), array() );
unset( $_REQUEST['vcap_amount'] );
vcap_cc_assert( $none->is_error() && 400 === $none->get_status() && 0 === count( WC()->cart->get_cart() ), 'M12-20: a Store API add-item without an amount is refused with 400 (no $_REQUEST fallback)', $none->get_data() );
$typed = vcap_cc_store_add( $any->get_id(), array( 'vcap_amount' => '30' ) );
$items = array_values( WC()->cart->get_cart() );
vcap_cc_assert( ! $typed->is_error() && 1 === count( $items ) && 30.0 === (float) ( $items[0]['vcap_amount'] ?? 0 ), 'M12-20: the typed amount "vcap_amount" is taken into the cart line', $typed->is_error() ? $typed->get_data() : $items[0]['vcap_amount'] ?? null );
vcap_cc_assert( get_woocommerce_currency() === ( $items[0]['vcap_currency'] ?? '' ), 'S4: the cart line keeps the currency the amount was entered in', $items[0] ?? null );
$preset = vcap_cc_store_add( $any->get_id(), array( 'vcap_amount_preset' => '50' ) );
$amounts = array_map( static fn( $item ) => (float) ( $item['vcap_amount'] ?? 0 ), array_values( WC()->cart->get_cart() ) );
sort( $amounts );
vcap_cc_assert( ! $preset->is_error() && array( 30.0, 50.0 ) === $amounts, 'M12-20: a suggested amount "vcap_amount_preset" makes its own cart line', $amounts );
$too_much = vcap_cc_store_add( $any->get_id(), array( 'vcap_amount' => '500' ) );
vcap_cc_assert( $too_much->is_error() && 'vcap_invalid_amount' === ( $too_much->get_data()['code'] ?? '' ), 'M12-20: an amount above the maximum is refused with the reason', $too_much->get_data() );
WC()->cart->empty_cart();
$topup_any  = vcap_cc_custom_product( true );
$topup_card = vcap_cc_card( 5 );
$topped     = vcap_cc_store_add( $topup_any->get_id(), array( 'vcap_topup_code' => $topup_card->code, 'vcap_amount' => '40' ) );
$items      = array_values( WC()->cart->get_cart() );
WC()->cart->calculate_totals();
vcap_cc_assert( ! $topped->is_error() && 1 === count( $items ) && 40.0 === (float) ( $items[0]['vcap_amount'] ?? 0 ) && $topup_card->code === ( $items[0]['vcap_topup_code'] ?? '' ) && 40.0 === round( (float) WC()->cart->get_subtotal(), 2 ), 'M12-20: a top-up with a custom amount is added through the Store API with card code and amount 40', $topped->is_error() ? $topped->get_data() : $items );
WC()->cart->empty_cart();

/* ---- M12-59: "order again" drops the delivery date and checks the fields again --------------------- */

$personal = new WC_Product_Simple();
$personal->set_name( 'CC personal voucher' );
$personal->set_regular_price( '30' );
$personal->set_virtual( true );
$personal->update_meta_data( '_vcap_enabled', 'yes' );
$personal->update_meta_data( '_vcap_builtin_fields', array( 'recipient_email_required', 'message', 'delivery_date' ) );
$personal->save();
$past       = wp_date( 'Y-m-d', strtotime( '-20 days' ) );
$old_fields = array(
	'recipient_email' => array( 'label' => 'Recipient email', 'value' => 'friend@example.com' ),
	'delivery_date'   => array( 'label' => 'Delivery date', 'value' => $past ),
);
$again_order = wc_create_order();
$again_line  = $again_order->get_item( $again_order->add_product( $personal, 1 ) );
$again_line->add_meta_data( '_vcap_fields', $old_fields, true );
$again_line->save();
$again_data = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $again_line, $again_order );
wc_clear_notices();
$again_ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $personal->get_id(), 1, 0, array(), $again_data );
vcap_cc_assert( ! isset( $again_data['vcap_fields']['delivery_date'] ) && 'friend@example.com' === ( $again_data['vcap_fields']['recipient_email']['value'] ?? '' ) && true === $again_ok && ! vcap_cc_errors(), 'M12-59: "order again" keeps the recipient but not the old delivery date, and passes the field check', $again_data );
// A field that became required since the first order: "order again" asks for it like the form.
$personal->update_meta_data( '_vcap_builtin_fields', array( 'recipient_email_required', 'message_required', 'delivery_date' ) );
$personal->save();
$again_now = apply_filters( 'woocommerce_add_to_cart_validation', true, $personal->get_id(), 1, 0, array(), $again_data );
$errors    = vcap_cc_errors();
vcap_cc_assert( false === $again_now && $errors, 'M12-59: "order again" runs the values through the current field rules (a newly required field refuses it)', $errors );
// A delivery date that has passed while the voucher waited in the cart stops the checkout.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $personal->get_id(), 1, 0, array(), array( 'vcap_fields' => $old_fields, 'vcap_key' => md5( wp_json_encode( $old_fields ) ) ) );
wc_clear_notices();
do_action( 'woocommerce_check_cart_items' );
$errors = vcap_cc_errors();
vcap_cc_assert( $errors && str_contains( implode( ' ', $errors ), 'delivery date' ), 'M12-59: the cart check refuses a delivery date in the past', $errors );
WC()->cart->empty_cart();

/* ---- M12-93: the balance check names the code and offers "Redeem" --------------------------------- */

$checked = vcap_cc_card( 35 );
$_POST['vcap_balance_code'] = $checked->code;
$_POST['vcap_balance_form'] = '';
vcap()->downloads->handle_balance();
$balance_html = vcap()->downloads->balance_shortcode();
unset( $_POST['vcap_balance_code'], $_POST['vcap_balance_form'] );
vcap_cc_assert( str_contains( $balance_html, 'Code …' . VCAP\Mask::code_tail( $checked->code ) ) && str_contains( $balance_html, 'name="' . VCAP\CouponLink::REDEEM_FIELD . '"' ), 'M12-93: the balance result names the code ("Code …XXXX") and has a "Redeem" button', wp_strip_all_tags( $balance_html ) );
// The button puts the code into the cart (here: the cart has a product, so it is applied at once).
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
$_POST[ VCAP\CouponLink::REDEEM_FIELD ] = $checked->code;
$_POST['_vcap_redeem_nonce']             = wp_create_nonce( VCAP\CouponLink::REDEEM_NONCE );
$stop_redirect                           = static function ( $location ) {
	throw new RuntimeException( 'redirect:' . $location );
};
add_filter( 'wp_redirect', $stop_redirect );
$redirected = '';
try {
	( new VCAP\CouponLink( vcap()->settings ) )->handle_redeem_form();
} catch ( RuntimeException $e ) {
	$redirected = $e->getMessage();
}
remove_filter( 'wp_redirect', $stop_redirect );
unset( $_POST[ VCAP\CouponLink::REDEEM_FIELD ], $_POST['_vcap_redeem_nonce'] );
vcap_cc_assert( str_starts_with( $redirected, 'redirect:' . wc_get_cart_url() ) && WC()->cart->has_discount( $checked->code ), 'M12-93: "Redeem" applies the code to the cart and shows the cart', $redirected );
WC()->cart->remove_coupons();
WC()->cart->empty_cart();
wc_clear_notices();

/* ---- M12-95: gift card field, store credit without reload, mini cart, gift toggle, delivery date ---- */

function vcap_cc_extension( array $payload ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/extensions' );
	$request->set_param( 'namespace', 'voucher-captain' );
	$request->set_param( 'data', $payload );
	return rest_do_request( $request );
}
function vcap_cc_block_data(): array {
	$cart = json_decode( (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data() ), true );
	return (array) ( $cart['extensions']['voucher-captain'] ?? array() );
}
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
// Off by default: nothing changes for existing shops.
ob_start();
do_action( 'woocommerce_before_cart_collaterals' );
do_action( 'woocommerce_widget_shopping_cart_before_buttons' );
$off_html = (string) ob_get_clean();
$off_api  = vcap_cc_extension( array( 'action' => 'apply_code', 'code' => 'x' ) );
vcap_cc_assert( 'no' === get_option( 'vcap_giftcard_field', 'no' ) && ! str_contains( $off_html, 'vcap-giftcard' ) && $off_api->is_error() && null === ( vcap_cc_block_data()['giftcard_field'] ?? null ), 'M12-95: with the settings off there is no gift card field (classic, blocks, update callback)', $off_api->get_status() );
update_option( 'vcap_giftcard_field', 'yes' );
update_option( 'vcap_minicart_balance', 'yes' );
ob_start();
do_action( 'woocommerce_before_cart_collaterals' );
$field_html = (string) ob_get_clean();
vcap_cc_assert( str_contains( $field_html, 'name="' . VCAP\GiftCardField::FIELD . '"' ) && wp_script_is( VCAP\GiftCardField::SCRIPT, 'enqueued' ), 'M12-95: the classic cart shows the "Redeem a gift card" field with its script', $field_html );
$block_texts = vcap_cc_block_data()['giftcard_field'] ?? null;
vcap_cc_assert( is_array( $block_texts ) && '' !== (string) ( $block_texts['label'] ?? '' ), 'M12-95: the blocks get the field texts in the Store API cart data', $block_texts );
$field_card = vcap_cc_card( 12 );
$bad        = vcap_cc_extension( array( 'action' => 'apply_code', 'code' => 'no-such-card-' . wp_generate_password( 6, false, false ) ) );
$good       = vcap_cc_extension( array( 'action' => 'apply_code', 'code' => strtoupper( $field_card->code ) ) );
vcap_cc_assert( $bad->is_error() && 400 === $bad->get_status() && ! $good->is_error() && WC()->cart->has_discount( $field_card->code ) && ! wc_get_notices( 'error' ), 'M12-95: the block field applies a gift card through the Store API update callback and refuses an unknown code with 400', array( $bad->get_status(), $good->get_status() ) );
WC()->cart->calculate_totals();
ob_start();
do_action( 'woocommerce_widget_shopping_cart_before_buttons' );
$mini = (string) ob_get_clean();
vcap_cc_assert( str_contains( $mini, 'vcap-minicart-balance' ) && str_contains( $mini, VCAP\Mask::code( $field_card->code ) ) && ! str_contains( $mini, $field_card->code ), 'M12-95: the mini cart shows the remaining balance with the masked code', $mini );
WC()->cart->remove_coupons();
$classic_card = vcap_cc_card( 8 );
$field        = new VCAP\GiftCardField( vcap()->vouchers, vcap()->payments, vcap()->store_credit );
vcap_cc_assert( $field->apply( $classic_card->code ) && WC()->cart->has_discount( $classic_card->code ), 'M12-95: the classic field (AJAX and form) applies the code to the cart' );
wc_clear_notices();
WC()->cart->remove_coupons();
// Store credit without reload (blocks).
update_option( 'vcap_store_credit', 'yes' );
$credit_user = wp_insert_user( array( 'user_login' => 'vcap-cc-' . wp_generate_password( 6, false, false ), 'user_email' => 'cc-credit-' . wp_generate_password( 6, false, false ) . '@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
wp_set_current_user( $credit_user );
vcap()->store_credit->adjust( $credit_user, 15.0, 'cc test' );
$credit_data = vcap_cc_block_data()['store_credit'] ?? null;
$credit_api  = vcap_cc_extension( array( 'action' => 'apply_credit' ) );
$account     = vcap()->store_credit->account( $credit_user );
vcap_cc_assert( is_array( $credit_data ) && true === ( $credit_data['inline'] ?? null ) && ! $credit_api->is_error() && $account && WC()->cart->has_discount( $account->code ), 'M12-95: store credit is applied through the update callback (no page reload)', $credit_api->is_error() ? $credit_api->get_data() : $credit_data );
WC()->cart->remove_coupons();
wp_set_current_user( 0 );
update_option( 'vcap_store_credit', 'no' );
update_option( 'vcap_giftcard_field', 'no' );
update_option( 'vcap_minicart_balance', 'no' );
WC()->cart->empty_cart();
wc_clear_notices();

// "As a gift / For me".
$gift_product = new WC_Product_Simple();
$gift_product->set_name( 'CC gift toggle' );
$gift_product->set_regular_price( '20' );
$gift_product->set_virtual( true );
$gift_product->update_meta_data( '_vcap_enabled', 'yes' );
$gift_product->update_meta_data( '_vcap_builtin_fields', array( 'recipient_name', 'recipient_email_required', 'message' ) );
$gift_product->save();
$self_off = vcap_cc_store_add( $gift_product->get_id(), array( 'vcap_fields' => array( 'gift_mode' => 'self' ) ) );
update_option( 'vcap_gift_toggle', 'yes' );
$self_on = vcap_cc_store_add( $gift_product->get_id(), array( 'vcap_fields' => array( 'gift_mode' => 'self', 'recipient_email' => 'ignored@example.com' ) ) );
$items   = array_values( WC()->cart->get_cart() );
vcap_cc_assert( $self_off->is_error() && ! $self_on->is_error() && 1 === count( $items ) && ! isset( $items[0]['vcap_fields']['recipient_email'] ), 'M12-95: "For me" skips the (required) recipient fields and stores none; without the setting the field stays required', $self_on->is_error() ? $self_on->get_data() : $items );
$GLOBALS['product'] = wc_get_product( $gift_product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
ob_start();
vcap()->fields->render( 'before_button' );
$toggle_html = (string) ob_get_clean();
vcap_cc_assert( str_contains( $toggle_html, 'name="vcap_fields[gift_mode]"' ) && str_contains( $toggle_html, 'data-vcap-key="recipient_email"' ), 'M12-95: the product page shows "As a gift / For me" before the recipient fields', substr( wp_strip_all_tags( $toggle_html ), 0, 200 ) );
update_option( 'vcap_gift_toggle', 'no' );
WC()->cart->empty_cart();

// Delivery date on by default for new voucher products only.
update_option( 'vcap_new_product_delivery', 'yes' );
$fresh_product = new WC_Product_Simple();
$known_product = new WC_Product_Simple();
$known_product->update_meta_data( '_vcap_enabled', 'no' );
$with_date = VCAP\ProductSettings::default_builtin_fields( $fresh_product );
$kept      = VCAP\ProductSettings::default_builtin_fields( $known_product );
update_option( 'vcap_new_product_delivery', 'no' );
$off_date = VCAP\ProductSettings::default_builtin_fields( new WC_Product_Simple() );
vcap_cc_assert( in_array( 'delivery_date', $with_date, true ) && ! in_array( 'delivery_date', $kept, true ) && ! in_array( 'delivery_date', $off_date, true ), 'M12-95: new voucher products start with the delivery date field (setting on); existing products and the setting off do not', array( $with_date, $kept, $off_date ) );

echo "Cart coupons integration checks passed.\n";
