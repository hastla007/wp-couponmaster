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
// The flow below is the immediate one; the V5 waiting period (default 14 days) is checked further down.
update_option( 'vcap_bonus_wait_days', 0 );

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

$third = vcap_bonus_order( $product, 2, 'bonus-buyer@example.com' );
$third->update_status( 'processing' );
vcap_bonus_assert( 1 === count( VCAP\Discounts\Bonus::issued( wc_get_order( $third->get_id() ) ) ) && 1 === VCAP\Discounts\Usage::count( $bonus ), 'after a withdrawal the customer can earn the bonus with a new order' );
$order = wc_get_order( $order->get_id() );
$order->update_status( 'refunded' );
vcap_bonus_assert( 1 === VCAP\Discounts\Usage::count( $bonus ), 'withdrawing the same order twice never gives back the use of another order' );

$tag    = strtolower( wp_generate_password( 6, false ) );
$member = wp_create_user( 'bonus-member-' . $tag, wp_generate_password(), 'bonus-member-' . $tag . '@example.com' );
$own    = wc_create_order( array( 'customer_id' => $member ) );
$own->add_product( $product, 2 );
$own->set_billing_email( 'bonus-member-' . $tag . '@example.com' );
$own->calculate_totals();
$own->save();
$own->update_status( 'processing' );
vcap_bonus_assert( 1 === count( VCAP\Discounts\Bonus::issued( wc_get_order( $own->get_id() ) ) ), 'a customer account earns the bonus' );
$guest = vcap_bonus_order( $product, 2, 'Bonus-Member-' . $tag . '@example.com' );
$guest->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $guest->get_id() ) ), 'a guest order with the account email counts against the per-customer limit' );

$code = new WC_Coupon();
$code->set_code( 'bonus-minus-' . $tag );
$code->set_discount_type( 'fixed_cart' );
$code->set_amount( 20 );
$code->save();
$cheap = vcap_bonus_order( $product, 2, 'bonus-cheap-' . $tag . '@example.com' );
$cheap->apply_coupon( 'bonus-minus-' . $tag );
$cheap = wc_get_order( $cheap->get_id() );
$cheap->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $cheap->get_id() ) ), 'a discount code counts: 60 minus 20 paid does not reach the minimum of 50' );
$cod = vcap_bonus_order( $product, 2, 'bonus-cod-' . $tag . '@example.com' );
$cod->set_payment_method( 'cod' );
$cod->save();
$cod->update_status( 'processing', 'Payment to be made upon delivery.' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $cod->get_id() ) ), 'cash on delivery: no bonus while the order is processing but not paid' );
wc_get_order( $cod->get_id() )->update_status( 'completed' );
vcap_bonus_assert( 1 === count( VCAP\Discounts\Bonus::issued( wc_get_order( $cod->get_id() ) ) ), 'cash on delivery: the bonus follows when the order is completed' );
$transfer = vcap_bonus_order( $product, 2, 'bonus-bacs-' . $tag . '@example.com' );
$transfer->set_payment_method( 'bacs' );
$transfer->save();
$transfer->update_status( 'on-hold' );
wc_get_order( $transfer->get_id() )->payment_complete( 'bonus-bacs-' . $tag );
vcap_bonus_assert( 1 === count( VCAP\Discounts\Bonus::issued( wc_get_order( $transfer->get_id() ) ) ), 'bank transfer: the bonus is issued once the payment is recorded' );
wp_update_post( array( 'ID' => $bonus, 'post_status' => 'draft' ) );

$gift = vcap_bonus_campaign( 'Gift card bonus', array( 'action' => 'bonus', 'bonus_amount' => 10, 'bonus_type' => 'percent', 'bonus_trigger' => 'gift_card' ) );
$plain = vcap_bonus_order( $product, 3, 'bonus-plain@example.com' );
$plain->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $plain->get_id() ) ), 'a gift card bonus needs a voucher in the order' );
wp_update_post( array( 'ID' => $gift, 'post_status' => 'draft' ) );

// P12 · V5: refund guard and withdrawal below the threshold (no waiting period here).
$guarded = vcap_bonus_campaign( 'Guarded bonus', array( 'action' => 'bonus', 'bonus_amount' => 5, 'bonus_type' => 'fixed_cart', 'min_subtotal' => 50 ) );
$used_order = vcap_bonus_order( $product, 2, 'bonus-guard-' . $tag . '@example.com' );
$used_order->update_status( 'processing' );
$used_bonus = new WC_Coupon( VCAP\Discounts\Bonus::issued( wc_get_order( $used_order->get_id() ) )[ $guarded ] ?? 0 );
vcap_bonus_assert( $used_bonus->get_id() > 0 && 'publish' === get_post_status( $used_bonus->get_id() ), 'V5: without waiting period the bonus is valid at once' );
$used_bonus->set_usage_count( 1 );
$used_bonus->save();
$refused = wc_create_refund( array( 'order_id' => $used_order->get_id(), 'amount' => 60, 'reason' => 'test' ) );
vcap_bonus_assert( is_wp_error( $refused ) && str_contains( $refused->get_error_message(), 'bonus voucher' ) && str_contains( html_entity_decode( $refused->get_error_message() ), '55' ), 'V5: a refund below the threshold while the bonus is used is reduced by the used bonus (at most 55 of 60)' );
$allowed = wc_create_refund( array( 'order_id' => $used_order->get_id(), 'amount' => 55, 'reason' => 'test' ) );
vcap_bonus_assert( $allowed instanceof WC_Order_Refund && 'publish' === get_post_status( $used_bonus->get_id() ), 'V5: the reduced refund goes through; the used bonus stays' );
add_filter( 'vcap_allow_refund_of_used_bonus', '__return_true' );
$forced = wc_create_refund( array( 'order_id' => $used_order->get_id(), 'amount' => 5, 'reason' => 'test' ) );
remove_filter( 'vcap_allow_refund_of_used_bonus', '__return_true' );
vcap_bonus_assert( $forced instanceof WC_Order_Refund, 'V5: the filter allows the full refund' );

$unused_order = vcap_bonus_order( $product, 2, 'bonus-unused-' . $tag . '@example.com' );
$unused_order->update_status( 'processing' );
$unused_id = VCAP\Discounts\Bonus::issued( wc_get_order( $unused_order->get_id() ) )[ $guarded ] ?? 0;
$small_refund = wc_create_refund( array( 'order_id' => $unused_order->get_id(), 'amount' => 5, 'reason' => 'test' ) );
vcap_bonus_assert( $small_refund instanceof WC_Order_Refund && 'publish' === get_post_status( $unused_id ), 'V5: a partial refund that keeps the threshold (55 of 50) keeps the bonus' );
$big_refund = wc_create_refund( array( 'order_id' => $unused_order->get_id(), 'amount' => 20, 'reason' => 'test' ) );
vcap_bonus_assert( $big_refund instanceof WC_Order_Refund && 'trash' === get_post_status( $unused_id ), 'V5: a partial refund below the threshold withdraws the unused bonus' );

// P12 · V5: waiting period (default 14 days): created as a draft, valid and sent only afterwards.
delete_option( 'vcap_bonus_wait_days' );
$waiting = vcap_bonus_order( $product, 2, 'bonus-wait-' . $tag . '@example.com' );
$waiting->update_status( 'processing' );
$wait_id = VCAP\Discounts\Bonus::issued( wc_get_order( $waiting->get_id() ) )[ $guarded ] ?? 0;
$wait_coupon = new WC_Coupon( $wait_id );
$valid_from  = (int) $wait_coupon->get_meta( VCAP\Discounts\Bonus::VALID_FROM );
vcap_bonus_assert( $wait_id > 0 && 'draft' === get_post_status( $wait_id ) && 0 === wc_get_coupon_id_by_code( $wait_coupon->get_code() ), 'V5: the bonus is created as a draft – not usable yet' );
vcap_bonus_assert( abs( $valid_from - ( time() + 14 * DAY_IN_SECONDS ) ) < 120 && as_has_scheduled_action( VCAP\Discounts\Bonus::ACTIVATE_HOOK, array( $wait_id ), VCAP\Delivery::GROUP ), 'V5: it becomes valid after 14 days (scheduled)' );
$waiting->update_status( 'cancelled' );
vcap_bonus_assert( 'trash' === get_post_status( $wait_id ) && ! as_has_scheduled_action( VCAP\Discounts\Bonus::ACTIVATE_HOOK, array( $wait_id ), VCAP\Delivery::GROUP ), 'V5: cancelling within the waiting period withdraws it and drops the activation' );

$patient = vcap_bonus_order( $product, 2, 'bonus-patient-' . $tag . '@example.com' );
$patient->update_status( 'processing' );
$patient_id = VCAP\Discounts\Bonus::issued( wc_get_order( $patient->get_id() ) )[ $guarded ] ?? 0;
update_post_meta( $patient_id, VCAP\Discounts\Bonus::VALID_FROM, time() - 10 ); // The waiting period is over.
do_action( VCAP\Discounts\Bonus::ACTIVATE_HOOK, $patient_id );
$patient_coupon = new WC_Coupon( $patient_id );
vcap_bonus_assert( 'publish' === get_post_status( $patient_id ) && $patient_id === wc_get_coupon_id_by_code( $patient_coupon->get_code() ), 'V5: after the waiting period the bonus is published and usable' );
update_option( 'vcap_bonus_wait_days', 0 );
wp_update_post( array( 'ID' => $guarded, 'post_status' => 'draft' ) );

update_option( 'vcap_campaigns_enabled', 'no' );
$off =vcap_bonus_campaign( 'Disabled bonus', array( 'action' => 'bonus', 'bonus_amount' => 5 ) );
$late = vcap_bonus_order( $product, 2, 'bonus-off@example.com' );
$late->update_status( 'processing' );
vcap_bonus_assert( array() === VCAP\Discounts\Bonus::issued( wc_get_order( $late->get_id() ) ), 'no bonus while promotions are switched off' );
wp_update_post( array( 'ID' => $off, 'post_status' => 'draft' ) );
echo "Voucher Captain bonus checks passed.\n";
