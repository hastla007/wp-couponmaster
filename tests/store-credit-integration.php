<?php
/**
 * Store credit checks. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/store-credit-integration.php
 */

function vcap_sc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_sc_user( string $name ): int {
	$login = 'vcap_sc_' . $name . '_' . strtolower( wp_generate_password( 6, false, false ) );
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'user_email' => $login . '@example.com', 'role' => 'customer' ) );
}

function vcap_sc_order( WC_Product $product, int $customer ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $customer ? get_userdata( $customer )->user_email : 'guest-sc@example.com' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

update_option( 'vcap_store_credit', 'yes' );
$credit  = vcap()->store_credit;
$alice   = vcap_sc_user( 'alice' );
$bob     = vcap_sc_user( 'bob' );
$product = new WC_Product_Simple();
$product->set_name( 'Store credit product' );
$product->set_regular_price( '20' );
$product->set_status( 'publish' );
$product->save();

vcap_sc_assert( null === $credit->account( $alice ) && 0.0 === $credit->balance( $alice ), 'customers have no account until credit is added' );
$added = $credit->adjust( $alice, 30, 'Goodwill' );
$account = $credit->account( $alice );
vcap_sc_assert( ! is_wp_error( $added ) && 30.0 === $added['balance'] && 'credit' === $account->type && $alice === $account->user_id, 'adding credit opens the account' );
vcap_sc_assert( 30.0 === (float) ( new WC_Coupon( $account->coupon_id ) )->get_amount(), 'the account coupon follows the balance' );
vcap_sc_assert( 25.0 === $credit->adjust( $alice, -5, 'Correction' )['balance'], 'credit can be removed' );
vcap_sc_assert( is_wp_error( $credit->adjust( $alice, -100, 'Too much' ) ), 'the balance cannot go below zero' );
vcap_sc_assert( is_wp_error( $credit->adjust( $alice, 10, '' ) ), 'a reason is required' );
$events = array_column( vcap()->vouchers->events( $account->id ), 'note', 'event' );
vcap_sc_assert( 'Goodwill' === ( $events['credit'] ?? '' ) && 'Correction' === ( $events['debit'] ?? '' ), 'credit changes are journaled with their reason' );

// A missing account coupon is created again (balance kept); a failed coupon save does not leave a broken account.
wp_delete_post( $account->coupon_id, true );
$repaired = $credit->account( $alice );
vcap_sc_assert( $repaired && $repaired->coupon_id && 'publish' === get_post_status( $repaired->coupon_id ) && 25.0 === (float) $repaired->balance, 'an account whose coupon was deleted gets a new one and keeps its balance' );
$account  = $repaired;
$carol    = vcap_sc_user( 'carol' );
global $wpdb;
$posts_tb = $wpdb->posts;
$no_coupon = static fn( $q ) => ( str_starts_with( ltrim( (string) $q ), 'INSERT INTO `' . $posts_tb . '`' ) && str_contains( (string) $q, 'shop_coupon' ) ) ? '' : $q;
add_filter( 'query', $no_coupon );
$failed = $credit->adjust( $carol, 10, 'Goodwill' );
remove_filter( 'query', $no_coupon );
vcap_sc_assert( is_wp_error( $failed ) && null === $credit->account( $carol ), 'a store credit account whose coupon cannot be saved is not opened' );
$retry = $credit->adjust( $carol, 10, 'Goodwill' );
vcap_sc_assert( ! is_wp_error( $retry ) && 10.0 === $credit->balance( $carol ), 'the next attempt opens the account' );

// Paying with store credit: only the owner, partial use, deducted at payment.
$foreign = vcap_sc_order( $product, $bob );
vcap_sc_assert( is_wp_error( $foreign->apply_coupon( $account->code ) ), 'another customer cannot use the store credit' );
$order = vcap_sc_order( $product, $alice );
vcap_sc_assert( true === $order->apply_coupon( $account->code ), 'the owner can use the store credit' );
$order = wc_get_order( $order->get_id() );
vcap_sc_assert( 0.0 === (float) $order->get_total(), 'the 20 product is fully paid with 25 credit' );
vcap()->coupons->reserve_order( $order );
$order->payment_complete();
vcap_sc_assert( 5.0 === $credit->balance( $alice ), 'the used credit is deducted and the rest remains' );

// Refund as store credit: explicit, no money back, credited to the account.
$paid = vcap_sc_order( $product, $alice );
$paid->set_status( 'completed' );
$paid->save();
$refunded = $credit->refund_as_credit( $paid, 8, 'Customer chose credit' );
$paid     = wc_get_order( $paid->get_id() );
vcap_sc_assert( ! is_wp_error( $refunded ) && 13.0 === $credit->balance( $alice ), 'a refund as store credit adds to the balance' );
vcap_sc_assert( 8.0 === (float) $paid->get_total_refunded() && 12.0 === (float) $paid->get_remaining_refund_amount(), 'WooCommerce records the refund' );
vcap_sc_assert( is_wp_error( $credit->refund_as_credit( $paid, 50, 'Too much' ) ), 'refunds above the refundable total are refused' );
$guest = vcap_sc_order( $product, 0 );
$guest->set_status( 'completed' );
$guest->save();
vcap_sc_assert( is_wp_error( $credit->refund_as_credit( $guest, 5, 'Guest' ) ), 'guest orders cannot be refunded as credit' );

// Discounts are calculated first, store credit pays the rest (even when the credit was applied first).
$credit->adjust( $alice, 30, 'Top-up for ordering test' );
$five = new WC_Coupon();
$five->set_code( 'sc-five-' . strtolower( wp_generate_password( 6, false ) ) );
$five->set_discount_type( 'fixed_cart' );
$five->set_amount( '5' );
$five->save();
wp_set_current_user( $alice );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->apply_coupon( $account->code );
WC()->cart->apply_coupon( $five->get_code() );
WC()->cart->calculate_totals();
vcap_sc_assert( 15.0 === round( (float) WC()->cart->get_coupon_discount_amount( wc_format_coupon_code( $account->code ), false ), 2 ), 'the discount code is applied before the store credit (credit pays 15, not 20)' );
update_option( 'vcap_credit_with_coupons', 'no' );
$check = ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $account->code ) );
vcap_sc_assert( is_wp_error( $check ), 'store credit can be kept apart from discount codes' );
delete_option( 'vcap_credit_with_coupons' );
WC()->cart->empty_cart();
wp_set_current_user( 0 );

// Disabled feature: credit coupons stop working.
update_option( 'vcap_store_credit', 'no' );
$late = vcap_sc_order( $product, $alice );
vcap_sc_assert( is_wp_error( $late->apply_coupon( $account->code ) ), 'store credit cannot be used while the feature is disabled' );
delete_option( 'vcap_store_credit' );

echo "Voucher Captain store credit checks passed.\n";
