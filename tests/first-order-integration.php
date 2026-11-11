<?php
/**
 * First-order coupons and catalog prices. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/first-order-integration.php
 */

function vcap_first_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$product = new WC_Product_Simple();
$product->set_name( 'First order product' );
$product->set_regular_price( '40' );
$product->set_status( 'publish' );
$product->save();

$coupon = new WC_Coupon();
$coupon->set_code( 'vcap-first-' . strtolower( wp_generate_password( 8, false, false ) ) );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->update_meta_data( VCAP\FirstOrderCoupons::META, 'yes' );
$coupon->save();

function vcap_first_order( WC_Product $product, int $user, string $email, string $status ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $user ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();
	return $order;
}

function vcap_first_valid( WC_Coupon $coupon, WC_Order $order ): bool {
	return true === ( new WC_Discounts( $order ) )->is_coupon_valid( new WC_Coupon( $coupon->get_id() ) );
}

$tag      = strtolower( wp_generate_password( 6, false, false ) );
$regular  = (int) wp_insert_user( array( 'user_login' => "vcap_first_r_{$tag}", 'user_pass' => wp_generate_password( 20 ), 'user_email' => "r-{$tag}@example.com" ) );
$newcomer = (int) wp_insert_user( array( 'user_login' => "vcap_first_n_{$tag}", 'user_pass' => wp_generate_password( 20 ), 'user_email' => "n-{$tag}@example.com" ) );

vcap_first_order( $product, $regular, "r-{$tag}@example.com", 'completed' );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, $regular, "r-{$tag}@example.com", 'pending' ) ), 'a customer with an earlier order cannot use a first-order coupon' );
vcap_first_assert( vcap_first_valid( $coupon, vcap_first_order( $product, $newcomer, "n-{$tag}@example.com", 'pending' ) ), 'a new customer can use it' );

vcap_first_order( $product, 0, "guest-{$tag}@example.com", 'processing' );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "guest-{$tag}@example.com", 'pending' ) ), 'guests are recognised by their billing email' );
vcap_first_order( $product, 0, "cancelled-{$tag}@example.com", 'cancelled' );
vcap_first_assert( vcap_first_valid( $coupon, vcap_first_order( $product, 0, "cancelled-{$tag}@example.com", 'pending' ) ), 'cancelled earlier orders do not count' );

// Coupons never change catalog prices; the discount only appears in the cart.
wc_load_cart();
WC()->cart->empty_cart();
$fresh  = wc_get_product( $product->get_id() );
$before = array( $fresh->get_price(), $fresh->get_price_html(), $fresh->is_on_sale() );
$plain  = new WC_Coupon();
$plain->set_code( 'vcap-cat-' . strtolower( wp_generate_password( 8, false, false ) ) );
$plain->set_discount_type( 'percent' );
$plain->set_amount( 20 );
$plain->save();
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->apply_coupon( $plain->get_code() );
WC()->cart->calculate_totals();
$after = wc_get_product( $product->get_id() );
vcap_first_assert( $before === array( $after->get_price(), $after->get_price_html(), $after->is_on_sale() ) && WC()->cart->get_discount_total() > 0, 'coupons do not create catalog sale prices; the discount appears only in the cart' );
WC()->cart->empty_cart();
wc_clear_notices();

echo "Voucher Captain first-order checks passed.\n";
