<?php
/**
 * Discount campaigns (promotions) in the real WooCommerce cart and orders.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/promotion-integration.php
 */

function vcap_promo_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_promo_product( string $name, string $price, array $props = array() ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->set_virtual( true );
	if ( ! empty( $props['voucher'] ) ) {
		$p->update_meta_data( '_vcap_enabled', 'yes' );
	}
	$p->save();
	return $p;
}

function vcap_promo_campaign( string $title, array $rules, int $priority = 10, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => VCAP\Discounts\Repository::POST_TYPE,
			'post_status' => $status,
			'post_title'  => $title,
			'menu_order'  => $priority,
		)
	);
	update_post_meta( $id, VCAP\Discounts\Repository::META, wp_slash( wp_json_encode( VCAP\Discounts\Engine::normalize( $rules ) ) ) );
	return (int) $id;
}

function vcap_promo_cart(): array {
	WC()->cart->calculate_totals();
	$codes = array_values( array_filter( WC()->cart->get_applied_coupons(), static fn( $c ) => str_starts_with( $c, 'vcap-campaign-' ) ) );
	return array( $codes, round( (float) WC()->cart->get_discount_total(), 2 ) );
}

// Isolate from other test data: only this file's promotions are published.
foreach ( get_posts( array( 'post_type' => VCAP\Discounts\Repository::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $old ) {
	wp_update_post( array( 'ID' => $old, 'post_status' => 'draft' ) );
}

wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();
update_option( 'vcap_campaigns_enabled', 'yes' );
delete_option( 'vcap_discounts_on_vouchers' );

$shirt   = vcap_promo_product( 'Promo shirt', '20' );
$cap     = vcap_promo_product( 'Promo cap', '10' );
$voucher = vcap_promo_product( 'Promo voucher', '50', array( 'voucher' => true ) );

$ten = vcap_promo_campaign( 'Ten percent off', array( 'action' => 'percent', 'percent' => 10 ), 10 );
WC()->cart->add_to_cart( $shirt->get_id(), 2 );
WC()->cart->add_to_cart( $cap->get_id(), 1 );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $ten ) === $codes && 5.0 === $discount, 'a published promotion is applied automatically (10 % of 50 = 5)' );

WC()->cart->add_to_cart( $voucher->get_id(), 1 );
list( , $discount ) = vcap_promo_cart();
vcap_promo_assert( 5.0 === $discount, 'voucher products in the cart are not discounted' );

$bogo = vcap_promo_campaign( 'Buy 2 get 1 free', array( 'action' => 'bxgy', 'buy_qty' => 2, 'get_qty' => 1, 'get_percent' => 100, 'products' => array( $shirt->get_id(), $cap->get_id() ) ), 5 );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $bogo ) === $codes && 10.0 === $discount, 'the exclusive promotion with the best priority applies alone (cheapest item free)' );

wp_update_post( array( 'ID' => $bogo, 'post_status' => 'draft' ) );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $ten ) === $codes && 5.0 === $discount, 'a promotion set to draft is removed from the cart' );

$stack = vcap_promo_campaign( 'Five euro off caps', array( 'action' => 'fixed', 'amount' => 5, 'products' => array( $cap->get_id() ), 'stackable' => true ), 20 );
update_post_meta( $ten, VCAP\Discounts\Repository::META, wp_slash( wp_json_encode( VCAP\Discounts\Engine::normalize( array( 'action' => 'percent', 'percent' => 10, 'stackable' => true ) ) ) ) );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( 2 === count( $codes ) && 10.0 === $discount, 'stackable promotions combine (5 + 5)' );
wp_update_post( array( 'ID' => $stack, 'post_status' => 'draft' ) );

$tiered = vcap_promo_campaign( 'Tiered shirts', array( 'action' => 'tiered', 'tiers' => array( array( 'min_qty' => 3, 'percent' => 20 ) ), 'products' => array( $shirt->get_id() ) ), 1 );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( ! in_array( 'vcap-campaign-' . $tiered, $codes, true ), 'a tier that is not reached does not apply' );
WC()->cart->add_to_cart( $shirt->get_id(), 1 );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $tiered ) === $codes && 12.0 === $discount, 'reaching the tier applies it (3 shirts × 20 € × 20 %)' );
wp_update_post( array( 'ID' => $tiered, 'post_status' => 'draft' ) );

$members = vcap_promo_campaign( 'Wholesale', array( 'action' => 'percent', 'percent' => 30, 'customer' => 'roles', 'roles' => array( 'administrator' ) ), 1 );
$future  = vcap_promo_campaign( 'Next week', array( 'action' => 'percent', 'percent' => 50, 'date_from' => time() + WEEK_IN_SECONDS ), 1 );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( ! in_array( 'vcap-campaign-' . $members, $codes, true ) && ! in_array( 'vcap-campaign-' . $future, $codes, true ), 'customer groups and future start dates are respected' );
wp_update_post( array( 'ID' => $members, 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $future, 'post_status' => 'draft' ) );

update_option( 'vcap_campaigns_enabled', 'no' );
list( $codes, $discount ) = vcap_promo_cart();
vcap_promo_assert( array() === $codes && 0.0 === $discount, 'switching promotions off removes them from the cart' );
update_option( 'vcap_campaigns_enabled', 'yes' );

// Orders (e.g. edited in the admin) are calculated by the same rules.
$order = wc_create_order();
$order->add_product( $shirt, 2 );
$order->calculate_totals();
$applied = $order->apply_coupon( 'vcap-campaign-' . $ten );
$order   = wc_get_order( $order->get_id() );
vcap_promo_assert( true === $applied && 4.0 === round( (float) $order->get_discount_total(), 2 ), 'promotions calculate correctly on orders (10 % of 40)' );

wp_update_post( array( 'ID' => $ten, 'post_status' => 'draft' ) );
update_option( 'vcap_campaigns_enabled', 'no' );
WC()->cart->empty_cart();
wc_clear_notices();
echo "Voucher Captain promotion checks passed.\n";
