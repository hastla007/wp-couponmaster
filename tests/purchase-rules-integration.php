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
WC()->cart->empty_cart();
wc_clear_notices();
echo "Voucher Captain purchase rule checks passed.\n";
