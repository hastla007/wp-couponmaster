<?php
/**
 * Refer a friend.
 *   wp eval-file tests/referral-integration.php
 */

function vcap_ref_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_ref_order( WC_Product $product, string $email, string $code, int $customer = 0 ) {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->calculate_totals();
	$order->save();
	$result = $order->apply_coupon( $code );
	return array( wc_get_order( $order->get_id() ), $result );
}

$tag = strtolower( wp_generate_password( 6, false ) );
update_option( 'vcap_referral_enabled', 'yes' );
update_option( 'vcap_referral_friend_amount', 10 );
update_option( 'vcap_referral_reward_amount', 15 );
update_option( 'vcap_referral_reward_as', 'coupon' );
update_option( 'vcap_store_credit', 'no' );

$referrer = wp_create_user( 'ref-' . $tag, wp_generate_password(), 'ref-' . $tag . '@example.com' );
$program  = new VCAP\Referrals( vcap()->settings, vcap()->store_credit );
$coupon   = $program->coupon_for( $referrer );
vcap_ref_assert( $coupon && $referrer === VCAP\Referrals::owner( $coupon ) && 'yes' === $coupon->get_meta( VCAP\FirstOrderCoupons::META ), 'each customer gets a personal first-order referral code' );
vcap_ref_assert( $coupon->get_id() === $program->coupon_for( $referrer )->get_id(), 'the code stays the same' );
$code = $coupon->get_code();

$product = new WC_Product_Simple();
$product->set_name( 'Referral product' );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->save();

list( , $own ) = vcap_ref_order( $product, 'ref-' . $tag . '@example.com', $code, $referrer );
vcap_ref_assert( is_wp_error( $own ), 'customers cannot use their own code' );
list( , $alias ) = vcap_ref_order( $product, 'Ref-' . $tag . '+second@example.com', $code );
vcap_ref_assert( is_wp_error( $alias ), 'customers cannot use their own code as guest with a +alias of their email' );
vcap_ref_assert( 'johndoe@gmail.com' === VCAP\Referrals::normalize_email( 'John.Doe+shop@GoogleMail.com' ), 'Gmail dots and +tags are ignored when comparing emails' );

list( $friend_order, $ok ) = vcap_ref_order( $product, 'friend-' . $tag . '@example.com', $code );
vcap_ref_assert( true === $ok && 10.0 === (float) $friend_order->get_discount_total(), 'a new friend gets the referral discount' );
$friend_order->update_status( 'processing' );
vcap_ref_assert( '' === (string) wc_get_order( $friend_order->get_id() )->get_meta( VCAP\Referrals::REWARDED ), 'no reward before the order is completed' );
$friend_order->update_status( 'completed' );
$friend_order = wc_get_order( $friend_order->get_id() );
vcap_ref_assert( $referrer === (int) $friend_order->get_meta( VCAP\Referrals::REWARDED ), 'the completed order rewards the referrer' );
$rewards = get_posts( array( 'post_type' => 'shop_coupon', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_vcap_referral_reward_for', 'meta_value' => $friend_order->get_id() ) ); // phpcs:ignore
vcap_ref_assert( 1 === count( $rewards ), 'one reward coupon is created' );
$reward = new WC_Coupon( $rewards[0] );
vcap_ref_assert( 15.0 === (float) $reward->get_amount() && array( 'ref-' . $tag . '@example.com' ) === $reward->get_email_restrictions() && 1 === $reward->get_usage_limit(), 'the reward coupon has the value, single use and the referrer email' );

$friend_order->update_status( 'processing' );
$friend_order->update_status( 'completed' );
vcap_ref_assert( 1 === (int) get_user_meta( $referrer, VCAP\Referrals::COUNT_META, true ), 'status repeats do not reward twice' );

list( , $again ) = vcap_ref_order( $product, 'friend-' . $tag . '@example.com', $code );
vcap_ref_assert( is_wp_error( $again ), 'the friend cannot use a referral code again after the first order' );

update_option( 'vcap_store_credit', 'yes' );
update_option( 'vcap_referral_reward_as', 'credit' );
list( $second, $ok ) = vcap_ref_order( $product, 'friend2-' . $tag . '@example.com', $code );
$second->update_status( 'completed' );
vcap_ref_assert( 15.0 === vcap()->store_credit->balance( $referrer ), 'the reward can be paid as store credit' );

$rows = ( new VCAP\Reports() )->collect( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
$ref  = array_values( array_filter( $rows, static fn( $r ) => 'referral' === $r['type'] && false !== strpos( $r['name'], 'ref-' . $tag ) ) );
vcap_ref_assert( 1 === count( $ref ) && 2 === $ref[0]['orders'], 'referrals appear per referrer in the report' );

$second = wc_get_order( $second->get_id() );
$second->update_status( 'refunded' );
vcap_ref_assert( 0.0 === vcap()->store_credit->balance( $referrer ), 'a refunded friend order takes the store credit reward back' );
$second = wc_get_order( $second->get_id() );
$second->update_status( 'cancelled' );
vcap_ref_assert( 0.0 === vcap()->store_credit->balance( $referrer ) && 1 === (int) get_user_meta( $referrer, VCAP\Referrals::COUNT_META, true ), 'the reward is taken back only once' );

// A booking error while taking the reward back is not "already spent": nothing is finished, a retry withdraws it.
list( $third, $ok ) = vcap_ref_order( $product, 'friend4-' . $tag . '@example.com', $code );
$third->update_status( 'completed' );
$count_before = (int) get_user_meta( $referrer, VCAP\Referrals::COUNT_META, true );
$vouchers_tbl = VCAP\Install::tables()['vouchers'];
$break_update = static fn( $q ) => str_starts_with( ltrim( (string) $q ), 'UPDATE `' . $vouchers_tbl . '`' ) || str_starts_with( ltrim( (string) $q ), 'UPDATE ' . $vouchers_tbl ) ? '' : $q;
add_filter( 'query', $break_update );
$third = wc_get_order( $third->get_id() );
$third->update_status( 'refunded' );
remove_filter( 'query', $break_update );
$pending_reward = wc_get_order( $third->get_id() )->get_meta( VCAP\Referrals::REWARD );
vcap_ref_assert( 15.0 === vcap()->store_credit->balance( $referrer ) && empty( $pending_reward['revoked'] ) && $count_before === (int) get_user_meta( $referrer, VCAP\Referrals::COUNT_META, true ), 'a failed debit leaves the withdrawal open (not treated as spent)' );
do_action( VCAP\Referrals::RETRY_HOOK, $third->get_id() );
$done_reward = wc_get_order( $third->get_id() )->get_meta( VCAP\Referrals::REWARD );
vcap_ref_assert( 0.0 === vcap()->store_credit->balance( $referrer ) && ! empty( $done_reward['revoked'] ) && $count_before - 1 === (int) get_user_meta( $referrer, VCAP\Referrals::COUNT_META, true ), 'the retry withdraws the reward' );

$friend_order = wc_get_order( $friend_order->get_id() );
$friend_order->update_status( 'cancelled' );
vcap_ref_assert( 'trash' === get_post_status( $rewards[0] ), 'a cancelled friend order withdraws the unused reward coupon' );

update_option( 'vcap_referral_enabled', 'no' );
list( , $off ) = vcap_ref_order( $product, 'friend3-' . $tag . '@example.com', $code );
vcap_ref_assert( is_wp_error( $off ), 'referral codes are refused while the program is off' );

update_option( 'vcap_store_credit', 'no' );
delete_option( 'vcap_referral_reward_as' );
echo "Voucher Captain referral checks passed.\n";
