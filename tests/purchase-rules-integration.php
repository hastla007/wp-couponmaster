<?php
/**
 * Discount codes and gift cards on voucher products (blocked by default).
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/purchase-rules-integration.php
 */

function vcap_rules_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_rules_product( string $name, string $price, bool $voucher ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->set_virtual( true );
	if ( $voucher ) {
		$p->update_meta_data( '_vcap_enabled', 'yes' );
	}
	$p->save();
	return $p;
}

function vcap_rules_coupon( string $type, float $amount, bool $vcap ): WC_Coupon {
	$c = new WC_Coupon();
	$c->set_code( 'vcap-rule-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$c->set_discount_type( $type );
	$c->set_amount( $amount );
	if ( $vcap ) {
		$c->update_meta_data( '_vcap_voucher', 'yes' );
	}
	$c->save();
	if ( $vcap ) {
		$v                 = new VCAP\Voucher();
		$v->code           = wc_format_coupon_code( $c->get_code() );
		$v->coupon_id      = $c->get_id();
		$v->order_item_id  = 40000000 + $c->get_id();
		$v->type           = 'giftcard';
		$v->usage_mode     = 'balance';
		$v->initial_amount = $amount;
		$v->balance        = $amount;
		$v->currency       = get_woocommerce_currency();
		$v->token          = VCAP\VoucherRepository::new_token();
		vcap()->vouchers->insert( $v );
	}
	return $c;
}

wc_load_cart();
delete_option( 'vcap_discounts_on_vouchers' );
delete_option( 'vcap_vouchers_buy_vouchers' );

$gift_product = vcap_rules_product( 'Rules voucher', '50', true );
$normal       = vcap_rules_product( 'Rules product', '20', false );
$promo        = vcap_rules_coupon( 'percent', 10, false );
$card         = vcap_rules_coupon( 'fixed_cart', 30, true );

// Voucher only in the cart: both are refused with a message.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $gift_product->get_id(), 1 );
vcap_rules_assert( ! WC()->cart->apply_coupon( $promo->get_code() ), 'a discount code is refused when only vouchers are in the cart' );
vcap_rules_assert( ! WC()->cart->apply_coupon( $card->get_code() ), 'a gift card cannot pay for a voucher' );
wc_clear_notices();

// Mixed cart: the discount applies to the other product only.
WC()->cart->add_to_cart( $normal->get_id(), 1 );
vcap_rules_assert( WC()->cart->apply_coupon( $promo->get_code() ), 'the discount code is accepted in a mixed cart' );
WC()->cart->calculate_totals();
vcap_rules_assert( abs( 2.0 - (float) WC()->cart->get_discount_total() ) < 0.01, '10 % applies to the 20 € product only, not to the 50 € voucher' );
WC()->cart->remove_coupon( $promo->get_code() );
vcap_rules_assert( WC()->cart->apply_coupon( $card->get_code() ), 'the gift card is accepted in a mixed cart' );
WC()->cart->calculate_totals();
vcap_rules_assert( abs( 20.0 - (float) WC()->cart->get_discount_total() ) < 0.01, 'the gift card pays only the 20 € product, not the voucher' );
WC()->cart->remove_coupon( $card->get_code() );
wc_clear_notices();

// Shops can allow both.
update_option( 'vcap_discounts_on_vouchers', 'yes' );
update_option( 'vcap_vouchers_buy_vouchers', 'yes' );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $gift_product->get_id(), 1 );
vcap_rules_assert( WC()->cart->apply_coupon( $promo->get_code() ), 'discount codes on vouchers can be allowed' );
WC()->cart->calculate_totals();
vcap_rules_assert( abs( 5.0 - (float) WC()->cart->get_discount_total() ) < 0.01, 'the allowed discount reduces the voucher price' );
WC()->cart->remove_coupon( $promo->get_code() );
vcap_rules_assert( WC()->cart->apply_coupon( $card->get_code() ), 'paying vouchers with gift cards can be allowed' );

delete_option( 'vcap_discounts_on_vouchers' );
delete_option( 'vcap_vouchers_buy_vouchers' );

// Minimum spend of a discount code: voucher products do not count ("20 off from 50" with a 50 voucher + a 20
// product would otherwise be unlocked by a voucher whose purchase can be withdrawn).
$min_code = vcap_rules_coupon( 'fixed_cart', 10, false );
$min_code->set_minimum_amount( 50 );
$min_code->save();
WC()->cart->empty_cart();
wc_clear_notices();
WC()->cart->add_to_cart( $gift_product->get_id(), 1 );
WC()->cart->add_to_cart( $normal->get_id(), 1 );
WC()->cart->calculate_totals();
vcap_rules_assert( ! WC()->cart->apply_coupon( $min_code->get_code() ), 'the minimum spend of a discount code ignores voucher products (50 voucher + 20 product < 50)' );
WC()->cart->empty_cart();
wc_clear_notices();
WC()->cart->add_to_cart( $normal->get_id(), 3 );
WC()->cart->calculate_totals();
vcap_rules_assert( WC()->cart->apply_coupon( $min_code->get_code() ), 'the minimum spend is met by other products (60)' );
$min_order = wc_create_order();
$min_order->add_product( $gift_product, 1 );
$min_order->add_product( $normal, 1 );
$min_order->calculate_totals();
vcap_rules_assert( is_wp_error( $min_order->apply_coupon( $min_code->get_code() ) ), 'the same minimum applies to an order edited in the admin' );

// P12 · V7: at most 1000 of vouchers per customer (account or normalised billing email) within 24 hours.
$v7_tag   = strtolower( wp_generate_password( 6, false, false ) );
$v7_limit = static function ( int $qty ) use ( $gift_product ): string {
	WC()->cart->empty_cart();
	wc_clear_notices();
	WC()->cart->add_to_cart( $gift_product->get_id(), $qty );
	WC()->cart->calculate_totals();
	do_action( 'woocommerce_check_cart_items' );
	$errors = implode( ' ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
	wc_clear_notices();
	return $errors;
};
WC()->customer->set_billing_email( "velo-{$v7_tag}@example.com" );
vcap_rules_assert( '' === $v7_limit( 20 ), 'V7: vouchers for 1000 in the cart can be ordered (default limit 1000)' );
vcap_rules_assert( str_contains( $v7_limit( 21 ), '24 hours' ), 'V7: vouchers for 1050 in the cart are refused at checkout' );
$v7_earlier = wc_create_order();
$v7_earlier->add_product( $gift_product, 15 );
$v7_earlier->set_billing_email( "Velo-{$v7_tag}+second@example.com" );
$v7_earlier->calculate_totals();
$v7_earlier->save();
$v7_earlier->update_status( 'on-hold' );
vcap_rules_assert( str_contains( $v7_limit( 6 ), '24 hours' ), 'V7: an order of 750 in the last 24 hours under an alias of the address counts (750 + 300 > 1000)' );
vcap_rules_assert( '' === $v7_limit( 5 ), 'V7: 750 + 250 = 1000 is still allowed' );
update_option( 'vcap_customer_daily_limit', 0 );
vcap_rules_assert( '' === $v7_limit( 40 ), 'V7: 0 switches the limit off' );
delete_option( 'vcap_customer_daily_limit' );

// M12-52: refused checkouts are counted (once per customer session and day) and shown in Site Health.
delete_option( VCAP\VoucherPurchaseRules::REFUSALS_OPTION );
WC()->session->set( 'vcap_limit_refused', null );
$v7_limit( 21 );
$v7_limit( 22 );
$m52_once = VCAP\VoucherPurchaseRules::refusals();
WC()->session->set( 'vcap_limit_refused', null ); // Another customer.
$v7_limit( 21 );
$m52_health = ( new VCAP\Health() )->test_purchase_limits();
vcap_rules_assert( 1 === $m52_once && 2 === VCAP\VoucherPurchaseRules::refusals() && 'recommended' === $m52_health['status'] && str_contains( $m52_health['description'], '2 checkouts' ), 'M12-52: checkouts refused by the daily limit are counted once per session and reported in Site Health' );
delete_option( VCAP\VoucherPurchaseRules::REFUSALS_OPTION );
vcap_rules_assert( 'good' === ( new VCAP\Health() )->test_purchase_limits()['status'], 'M12-52: without refusals Site Health reports nothing to do' );
// M12-52: the security hold of first orders above 250 is named on the product page and in the cart.
$m52_cfg = VCAP\ProductConfig::get( $gift_product );
vcap_rules_assert( '' === VCAP\NextSteps::hold_hint( $m52_cfg, 50.0 ) && str_contains( VCAP\NextSteps::hold_hint( $m52_cfg, 300.0 ), '24 hours' ) && str_contains( VCAP\NextSteps::hold_hint( array_merge( $m52_cfg, array( 'amount_source' => 'custom', 'custom_max' => '' ) ), 0.0 ), 'first order' ), 'M12-52: the product page names the hold when a voucher can be worth more than the threshold (price or custom amount)' );
WC()->customer->set_billing_email( "m52-new-{$v7_tag}@example.com" );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $gift_product->get_id(), 6 );
WC()->cart->calculate_totals();
ob_start();
( new VCAP\NextSteps() )->cart_hint();
$m52_cart = (string) ob_get_clean();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $gift_product->get_id(), 2 );
WC()->cart->calculate_totals();
ob_start();
( new VCAP\NextSteps() )->cart_hint();
$m52_small = (string) ob_get_clean();
WC()->cart->empty_cart();
vcap_rules_assert( str_contains( $m52_cart, 'For your security' ) && '' === $m52_small, 'M12-52: the cart of a new customer above 250 names the hold, a smaller cart does not', array( $m52_cart, $m52_small ) );

// AP-P1-1: the 24-hour limit reads one day of orders (created_after in SQL on both order stores), once per request.
$p11_old = wc_create_order();
$p11_old->add_product( $gift_product, 2 );
$p11_old->set_billing_email( "velo-{$v7_tag}@example.com" );
$p11_old->calculate_totals();
$p11_old->set_date_created( time() - 3 * DAY_IN_SECONDS );
$p11_old->save();
$p11_old->update_status( 'completed' );
$p11_key  = VCAP\CustomerEmail::key( "velo-{$v7_tag}@example.com" );
$p11_all  = VCAP\OrderLookup::ids_with_meta( VCAP\VoucherPurchaseRules::CUSTOMER_KEY_META, $p11_key, array( 'limit' => 50 ) );
$p11_day  = VCAP\OrderLookup::ids_with_meta( VCAP\VoucherPurchaseRules::CUSTOMER_KEY_META, $p11_key, array( 'limit' => 50, 'created_after' => time() - DAY_IN_SECONDS ) );
$p11_till = VCAP\OrderLookup::ids_with_meta( VCAP\VoucherPurchaseRules::CUSTOMER_KEY_META, $p11_key, array( 'limit' => 50, 'created_before' => time() - DAY_IN_SECONDS ) );
vcap_rules_assert( in_array( $p11_old->get_id(), $p11_all, true ) && in_array( $v7_earlier->get_id(), $p11_day, true ) && ! in_array( $p11_old->get_id(), $p11_day, true ) && array( $p11_old->get_id() ) === $p11_till, 'AP-P1-1: OrderLookup bounds orders by their creation date (created_after / created_before) in SQL' );
$p11_queries = array();
$p11_log     = static function ( $q ) use ( &$p11_queries ) {
	$p11_queries[] = $q;
	return $q;
};
add_filter( 'query', $p11_log );
$p11_first = VCAP\VoucherPurchaseRules::ordered_last_day( 0, "velo-{$v7_tag}@example.com" );
$p11_count = count( $p11_queries );
$p11_again = VCAP\VoucherPurchaseRules::ordered_last_day( 0, "velo-{$v7_tag}@example.com" );
remove_filter( 'query', $p11_log );
$p11_meta = preg_grep( '/' . preg_quote( VCAP\VoucherPurchaseRules::CUSTOMER_KEY_META, '/' ) . '/', $p11_queries );
vcap_rules_assert( 750.0 === $p11_first && $p11_first === $p11_again && count( $p11_queries ) === $p11_count && $p11_meta && str_contains( (string) reset( $p11_meta ), '>= ' ), 'AP-P1-1: ordered_last_day() counts only the last 24 hours, bounds the lookup by date and is remembered for the request', array( $p11_first, $p11_again, count( $p11_queries ) - $p11_count ) );
$p11_new = wc_create_order();
$p11_new->add_product( $gift_product, 1 );
$p11_new->set_billing_email( "velo-{$v7_tag}@example.com" );
$p11_new->calculate_totals();
$p11_new->save();
$p11_new->update_status( 'processing' );
vcap_rules_assert( 800.0 === VCAP\VoucherPurchaseRules::ordered_last_day( 0, "velo-{$v7_tag}@example.com" ), 'AP-P1-1: a new order of the customer is counted at once (the request memo is forgotten)' );
WC()->customer->set_billing_email( '' );

WC()->cart->empty_cart();
wc_clear_notices();
echo "Voucher Captain purchase rule checks passed.\n";
