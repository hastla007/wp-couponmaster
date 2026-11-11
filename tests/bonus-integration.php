<?php
/**
 * Bonus voucher promotions: a new coupon after a qualifying paid order.
 *   wp eval-file tests/bonus-integration.php
 */

function vcap_bonus_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_bonus_campaign( string $title, array $rules ): int {
	$id = wp_insert_post( array( 'post_type' => VCAP\Discounts\Repository::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title ) );
	update_post_meta( $id, VCAP\Discounts\Repository::META, wp_slash( wp_json_encode( VCAP\Discounts\Engine::normalize( $rules ) ) ) );
	return (int) $id;
}

function vcap_bonus_order( WC_Product $product, int $qty, string $email ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_billing_email( $email );
	$order->calculate_totals();
	$order->save();
	return $order;
}

foreach ( get_posts( array( 'post_type' => VCAP\Discounts\Repository::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $old ) {
	wp_update_post( array( 'ID' => $old, 'post_status' => 'draft' ) );
}
update_option( 'vcap_campaigns_enabled', 'yes' );

$product = new WC_Product_Simple();
$product->set_name( 'Bonus test product' );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->save();

$bonus = vcap_bonus_campaign( 'Thank-you bonus', array( 'action' => 'bonus', 'bonus_amount' => 5, 'bonus_type' => 'fixed_cart', 'bonus_days' => 30, 'bonus_min_spend' => 25, 'min_subtotal' => 50, 'usage_limit_per_customer' => 1 ) );

$small = vcap_bonus_order( $product, 1, 'bonus-small@example.com' );
$small->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $small->get_id() ) ), 'an order below the minimum value earns no bonus' );

$order = vcap_bonus_order( $product, 2, 'Bonus-Buyer@example.com' );
$order->update_status( 'processing' );
$order  = wc_get_order( $order->get_id() );
$issued = VCAP\Discounts\Bonus::issued( $order );
vcap_bonus_assert( isset( $issued[ $bonus ] ), 'a qualifying paid order earns a bonus voucher' );
$coupon = new WC_Coupon( $issued[ $bonus ] );
vcap_bonus_assert(
	'fixed_cart' === $coupon->get_discount_type() && 5.0 === (float) $coupon->get_amount() && 1 === $coupon->get_usage_limit()
		&& array( 'bonus-buyer@example.com' ) === $coupon->get_email_restrictions() && 25.0 === (float) $coupon->get_minimum_amount(),
	'the bonus coupon has the value, single use, minimum spend and email restriction'
);
$expires = $coupon->get_date_expires();
vcap_bonus_assert( $expires && abs( $expires->getTimestamp() - ( time() + 30 * DAY_IN_SECONDS ) ) < 120, 'the bonus coupon expires after the configured days' );
vcap_bonus_assert( 0 === stripos( $coupon->get_code(), 'bonus-' ), 'the bonus code uses the BONUS prefix' );

$order->update_status( 'completed' );
vcap_bonus_assert( $issued === VCAP\Discounts\Bonus::issued( wc_get_order( $order->get_id() ) ), 'status repeats do not issue a second bonus' );
vcap_bonus_assert( 1 === VCAP\Discounts\Usage::count( $bonus ), 'the bonus counts as one use of the promotion' );

$second = vcap_bonus_order( $product, 2, 'bonus-buyer@example.com' );
$second->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $second->get_id() ) ), 'the per-customer limit stops a second bonus' );

$order = wc_get_order( $order->get_id() );
$order->update_status( 'cancelled' );
vcap_bonus_assert( 'trash' === get_post_status( $issued[ $bonus ] ), 'cancelling the order withdraws the unused bonus' );
vcap_bonus_assert( 0 === VCAP\Discounts\Usage::count( $bonus ), 'the withdrawn bonus gives the use back' );
$order = wc_get_order( $order->get_id() );
$order->update_status( 'processing' );
vcap_bonus_assert( $issued === VCAP\Discounts\Bonus::issued( wc_get_order( $order->get_id() ) ), 'a withdrawn bonus is not issued again' );
wp_update_post( array( 'ID' => $bonus, 'post_status' => 'draft' ) );

$gift = vcap_bonus_campaign( 'Gift card bonus', array( 'action' => 'bonus', 'bonus_amount' => 10, 'bonus_type' => 'percent', 'bonus_trigger' => 'gift_card' ) );
$plain = vcap_bonus_order( $product, 3, 'bonus-plain@example.com' );
$plain->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $plain->get_id() ) ), 'a gift card bonus needs a voucher in the order' );
wp_update_post( array( 'ID' => $gift, 'post_status' => 'draft' ) );

update_option( 'vcap_campaigns_enabled', 'no' );
$off = vcap_bonus_campaign( 'Disabled bonus', array( 'action' => 'bonus', 'bonus_amount' => 5 ) );
$late = vcap_bonus_order( $product, 2, 'bonus-off@example.com' );
$late->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $late->get_id() ) ), 'no bonus while promotions are switched off' );
wp_update_post( array( 'ID' => $off, 'post_status' => 'draft' ) );
echo "Voucher Captain bonus checks passed.\n";
