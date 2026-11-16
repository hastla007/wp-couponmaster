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

// S14: two parallel checkouts of the same person (also with a plus address) cannot both use the first-order
// coupon: the first order holds it under the customer's lock while it is open; a cancelled one gives it back.
$s14_a = vcap_first_order( $product, 0, "par-{$tag}@example.com", 'pending' );
$s14_b = vcap_first_order( $product, 0, "par-{$tag}+2@example.com", 'pending' );
foreach ( array( $s14_a, $s14_b ) as $s14_order ) {
	$s14_order->apply_coupon( $coupon->get_code() );
	$s14_order->save();
}
$s14_first  = new VCAP\FirstOrderCoupons();
$s14_errors = array();
foreach ( array( 'a' => $s14_a, 'b' => $s14_b ) as $s14_name => $s14_order ) {
	try {
		$s14_first->hold( wc_get_order( $s14_order->get_id() ) );
		$s14_errors[ $s14_name ] = '';
	} catch ( Exception $e ) {
		$s14_errors[ $s14_name ] = $e->getMessage();
	}
}
VCAP\FirstOrderCoupons::forget();
$s14_third = vcap_first_order( $product, 0, "par-{$tag}@example.com", 'pending' );
vcap_first_assert( '' === $s14_errors['a'] && str_contains( $s14_errors['b'], 'only valid for your first order' ) && 'yes' === wc_get_order( $s14_a->get_id() )->get_meta( VCAP\FirstOrderCoupons::HOLD_META ) && ! vcap_first_valid( $coupon, $s14_third ), 'S14: an open order that holds the first-order coupon counts as earlier order – the parallel checkout (plus address) is refused at checkout and in the cart' );
$s14_a = wc_get_order( $s14_a->get_id() );
$s14_a->set_status( 'cancelled' );
$s14_a->save();
VCAP\FirstOrderCoupons::forget();
$s14_retry = '';
try {
	$s14_first->hold( wc_get_order( $s14_b->get_id() ) );
} catch ( Exception $e ) {
	$s14_retry = $e->getMessage();
}
vcap_first_assert( '' === $s14_retry && vcap_first_valid( $coupon, $s14_third ) === false, 'S14: once the holding order is cancelled unpaid, the other checkout may use the coupon (and then holds it itself)' );

// B13: repeated validations of the request (cart totals run several times) query the orders once; an order
// created in the request is seen at once.
$b13_orders = 0;
$b13_count  = static function ( $query ) use ( &$b13_orders ) {
	++$b13_orders;
	return $query;
};
add_filter( 'woocommerce_order_query_args', $b13_count );
$b13_answers = array();
for ( $i = 0; $i < 4; $i++ ) {
	$b13_answers[] = VCAP\FirstOrderCoupons::has_previous_order( $newcomer, "n-{$tag}@example.com", 0 );
}
$b13_queried = $b13_orders;
vcap_first_order( $product, $newcomer, "n-{$tag}@example.com", 'completed' );
$b13_after = VCAP\FirstOrderCoupons::has_previous_order( $newcomer, "n-{$tag}@example.com", 0 );
remove_filter( 'woocommerce_order_query_args', $b13_count );
vcap_first_assert( array( false, false, false, false ) === $b13_answers && 2 === $b13_queried, 'B13: four checks of the same customer run the order queries once (account/emails for counting and refunded/cancelled orders via wc_get_orders; the mailbox hash via OrderLookup, which works on both order stores) once (' . $b13_queried . ')' );
vcap_first_assert( true === $b13_after, 'B13: an order completed in the request is counted at once' );

// V4: the email is compared as mailbox – "+tags" and Gmail dots do not make a new customer.
vcap_first_order( $product, 0, "v4.max{$tag}+shop@gmail.com", 'processing' );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "V4Max{$tag}@GoogleMail.com", 'pending' ) ), 'V4: a Gmail address without dots and +tag is the same customer as "v4.max+shop@gmail.com"' );
vcap_first_order( $product, 0, "plus-{$tag}+a@example.com", 'completed' );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "plus-{$tag}+b@example.com", 'pending' ) ), 'V4: "name+b@…" is the same customer as "name+a@…"' );
vcap_first_assert( vcap_first_valid( $coupon, vcap_first_order( $product, 0, "plus-other-{$tag}@example.com", 'pending' ) ), 'V4: a different mailbox is still a new customer' );
// An order from before V4 (no mailbox hash yet) is found by its normalised address, and by the hash once the daily
// run stamped it.
$v4_old = vcap_first_order( $product, 0, "old-{$tag}+x@example.com", 'completed' );
$v4_old->delete_meta_data( VCAP\FirstOrderCoupons::CUSTOMER_META );
$v4_old->save_meta_data();
VCAP\FirstOrderCoupons::forget();
vcap_first_assert( '' === (string) wc_get_order( $v4_old->get_id() )->get_meta( VCAP\FirstOrderCoupons::CUSTOMER_META ) && vcap_first_valid( $coupon, vcap_first_order( $product, 0, "old-{$tag}+y@example.com", 'pending' ) ), 'V4: (setup) an unstamped older order is not found under a different +tag' );
while ( VCAP\FirstOrderCoupons::stamp_older_orders( 200 ) >= 200 ) {
	// All older orders of the test shop.
}
VCAP\FirstOrderCoupons::forget();
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "old-{$tag}+z@example.com", 'pending' ) ), 'V4: once the daily run stamped older orders, their +tag twins are recognised' );
// The daily run picks only unstamped orders – also on the classic store, where wc_get_orders() ignores meta_query.
$v4_again = vcap_first_order( $product, 0, "again-{$tag}@example.com", 'completed' );
$v4_again->delete_meta_data( VCAP\FirstOrderCoupons::CUSTOMER_META );
$v4_again->save_meta_data();
$v4_count = VCAP\FirstOrderCoupons::stamp_older_orders( 50 );
vcap_first_assert( 1 === $v4_count && '' !== (string) wc_get_order( $v4_again->get_id() )->get_meta( VCAP\FirstOrderCoupons::CUSTOMER_META ) && 0 === VCAP\FirstOrderCoupons::stamp_older_orders( 50 ), 'V4: the daily run stamps only the orders without a mailbox hash (' . $v4_count . ')' );
// AP-S3-4: the mailbox hash is keyed with the plugin's own persistent key, not wp_salt( 'auth' ): rotating the salts
// keeps every per-customer key; orders stamped with the salt-keyed hash before are still found.
$s34_email = "salt-{$tag}@example.com";
$s34_key   = VCAP\CustomerEmail::key( $s34_email );
$s34_salt  = static fn() => 'rotated-salt-' . wp_generate_password( 12, false, false );
add_filter( 'salt', $s34_salt );
$s34_rotated = VCAP\CustomerEmail::key( $s34_email );
remove_filter( 'salt', $s34_salt );
vcap_first_assert( $s34_key === $s34_rotated && 'h:' . hash_hmac( 'sha256', $s34_email, wp_salt( 'auth' ) ) !== $s34_key && '' !== (string) get_option( VCAP\CustomerEmail::KEY_OPTION ), 'AP-S3-4: the customer key does not change when the WordPress salts are rotated (own persistent key)' );
remove_action( 'woocommerce_before_order_object_save', array( VCAP\FirstOrderCoupons::class, 'stamp' ) );
$s34_old = vcap_first_order( $product, 0, "saltold-{$tag}+a@example.com", 'completed' );
$s34_old->update_meta_data( VCAP\FirstOrderCoupons::CUSTOMER_META, VCAP\CustomerEmail::salt_key( "saltold-{$tag}+a@example.com" ) );
$s34_old->save();
add_action( 'woocommerce_before_order_object_save', array( VCAP\FirstOrderCoupons::class, 'stamp' ) );
VCAP\FirstOrderCoupons::forget();
vcap_first_assert( VCAP\CustomerEmail::salt_key( "saltold-{$tag}+a@example.com" ) === (string) wc_get_order( $s34_old->get_id() )->get_meta( VCAP\FirstOrderCoupons::CUSTOMER_META ) && ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "saltold-{$tag}+b@example.com", 'pending' ) ), 'AP-S3-4: an order stamped with the salt-keyed hash before is still found (legacy key looked up)' );
// Refunded/cancelled orders that used a discount count as earlier orders.
$v4_discount = new WC_Coupon();
$v4_discount->set_code( 'vcap-v4-' . strtolower( wp_generate_password( 8, false, false ) ) );
$v4_discount->set_discount_type( 'percent' );
$v4_discount->set_amount( 10 );
$v4_discount->save();
$v4_discounted = static function ( string $email, string $status, bool $paid ) use ( $product, $v4_discount ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => 0 ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->apply_coupon( $v4_discount->get_code() );
	$order->calculate_totals();
	if ( $paid ) {
		$order->set_date_paid( time() - 60 );
	}
	$order->set_status( $status );
	$order->save();
	return $order;
};
$v4_discounted( "refund-{$tag}@example.com", 'refunded', true );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "refund-{$tag}+again@example.com", 'pending' ) ), 'V4: a refunded earlier order that used a discount counts – a refund does not make the customer new again' );
$v4_discounted( "paidcancel-{$tag}@example.com", 'cancelled', true );
vcap_first_assert( ! vcap_first_valid( $coupon, vcap_first_order( $product, 0, "paidcancel-{$tag}@example.com", 'pending' ) ), 'V4: a paid order with a discount that was cancelled later counts' );
$v4_discounted( "abandoned-{$tag}@example.com", 'cancelled', false );
vcap_first_assert( vcap_first_valid( $coupon, vcap_first_order( $product, 0, "abandoned-{$tag}@example.com", 'pending' ) ), 'V4: a cancelled order that was never paid (abandoned payment) does not count' );
vcap_first_order( $product, 0, "refundplain-{$tag}@example.com", 'refunded' );
vcap_first_assert( vcap_first_valid( $coupon, vcap_first_order( $product, 0, "refundplain-{$tag}@example.com", 'pending' ) ), 'V4: a refunded order without any discount does not count' );

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
