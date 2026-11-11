<?php
/**
 * SKU rules for WooCommerce coupons.
 *   wp eval-file tests/sku-rules-integration.php
 */

function vcap_sku_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_sku_product( string $name, string $sku, string $price ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku . '-' . wp_generate_password( 6, false ) );
	$p->set_regular_price( $price );
	$p->set_virtual( true );
	$p->save();
	return $p;
}

function vcap_sku_coupon( string $type, string $amount, string $skus, string $exclude = '' ): string {
	$code   = 'sku-' . strtolower( wp_generate_password( 8, false ) );
	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );
	$coupon->update_meta_data( VCAP\CouponSkuRules::META, $skus );
	$coupon->update_meta_data( VCAP\CouponSkuRules::META_EXCLUDE, $exclude );
	$coupon->save();
	return $code;
}

function vcap_sku_discount( string $code ): array {
	WC()->cart->remove_coupons();
	wc_clear_notices();
	$ok = WC()->cart->apply_coupon( $code );
	WC()->cart->calculate_totals();
	return array( $ok, round( (float) WC()->cart->get_discount_total(), 2 ) );
}

vcap_sku_assert( VCAP\CouponSkuRules::matches( 'SHIRT-RED', array( 'shirt-*' ) ) && ! VCAP\CouponSkuRules::matches( 'MUG-1', array( 'shirt-*' ) ) && VCAP\CouponSkuRules::matches( 'mug-1', array( 'mug-1' ) ), 'patterns match exactly or by prefix, case-insensitive' );

update_option( 'vcap_campaigns_enabled', 'no' );
wc_load_cart();
WC()->cart->empty_cart();
$shirt = vcap_sku_product( 'SKU shirt', 'SHIRT', '20' );
$mug   = vcap_sku_product( 'SKU mug', 'MUG', '10' );
WC()->cart->add_to_cart( $shirt->get_id(), 1 );
WC()->cart->add_to_cart( $mug->get_id(), 1 );

list( $ok, $discount ) = vcap_sku_discount( vcap_sku_coupon( 'percent', '10', 'shirt-*' ) );
vcap_sku_assert( $ok && 2.0 === $discount, 'a percentage coupon applies only to matching SKUs (10 % of 20)' );

list( $ok, $discount ) = vcap_sku_discount( vcap_sku_coupon( 'fixed_cart', '15', '', 'MUG-*' ) );
vcap_sku_assert( $ok && 15.0 === $discount, 'a fixed cart coupon skips excluded SKUs' );

list( $ok, $discount ) = vcap_sku_discount( vcap_sku_coupon( 'fixed_cart', '25', '', 'MUG-*' ) );
vcap_sku_assert( $ok && 20.0 === $discount, 'the excluded product is not reduced even when the coupon is larger' );

list( $ok ) = vcap_sku_discount( vcap_sku_coupon( 'percent', '10', 'NOPE-*' ) );
vcap_sku_assert( ! $ok, 'a coupon is refused when no product in the cart matches its SKUs' );

list( $ok, $discount ) = vcap_sku_discount( vcap_sku_coupon( 'percent', '10', '' ) );
vcap_sku_assert( $ok && 3.0 === $discount, 'coupons without SKU rules are unchanged' );

WC()->cart->empty_cart();
wc_clear_notices();
echo "Voucher Captain SKU rule checks passed.\n";
