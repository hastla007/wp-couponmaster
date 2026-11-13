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
vcap_ref_assert( $coupon->get_id() === (int) get_user_option( VCAP\Referrals::USER_META, $referrer ) && '' === (string) get_user_meta( $referrer, VCAP\Referrals::USER_META, true ), 'the coupon id is stored per site (user option), not network-wide' );
$code = $coupon->get_code();

// Stored ids are only used after a check: a coupon that is not this customer's referral code is never changed.
$foreign = new WC_Coupon();
$foreign->set_code( 'vcap-ref-foreign-' . $tag );
$foreign->set_discount_type( 'fixed_cart' );
$foreign->set_amount( 50 );
$foreign->update_meta_data( '_vcap_voucher', 'yes' );
$foreign->save();
$other = wp_create_user( 'ref-other-' . $tag, wp_generate_password(), 'ref-other-' . $tag . '@example.com' );
update_user_option( $other, VCAP\Referrals::USER_META, $foreign->get_id() );
$other_coupon = $program->coupon_for( $other );
$foreign      = new WC_Coupon( $foreign->get_id() );
vcap_ref_assert( $other_coupon && $other_coupon->get_id() !== $foreign->get_id() && $other === VCAP\Referrals::owner( $other_coupon ) && $other_coupon->get_id() === (int) get_user_option( VCAP\Referrals::USER_META, $other ), 'a stored id of a foreign coupon is ignored and a new referral code is created' );
vcap_ref_assert( 'fixed_cart' === $foreign->get_discount_type() && 50.0 === (float) $foreign->get_amount() && 0 === (int) $foreign->get_usage_limit_per_user() && '' === (string) $foreign->get_meta( VCAP\Referrals::OWNER ), 'the foreign coupon keeps its type, value and limits' );

if ( ! is_multisite() || is_main_site() ) {
	// Earlier versions stored the values network-wide under the plain key: adopted once after the check, then moved.
	$legacy        = wp_create_user( 'ref-legacy-' . $tag, wp_generate_password(), 'ref-legacy-' . $tag . '@example.com' );
	$legacy_coupon = new WC_Coupon();
	$legacy_coupon->set_code( 'vcap-ref-legacy-' . $tag );
	$legacy_coupon->set_discount_type( 'fixed_cart' );
	$legacy_coupon->set_amount( 10 );
	$legacy_coupon->update_meta_data( VCAP\Referrals::OWNER, $legacy );
	$legacy_coupon->update_meta_data( VCAP\FirstOrderCoupons::META, 'yes' );
	$legacy_coupon->save();
	update_user_meta( $legacy, VCAP\Referrals::USER_META, $legacy_coupon->get_id() );
	update_user_meta( $legacy, VCAP\Referrals::COUNT_META, 3 );
	$adopted = $program->coupon_for( $legacy );
	vcap_ref_assert( $adopted && $legacy_coupon->get_id() === $adopted->get_id() && $legacy_coupon->get_id() === (int) get_user_option( VCAP\Referrals::USER_META, $legacy ) && '' === (string) get_user_meta( $legacy, VCAP\Referrals::USER_META, true ), 'single site: the referral code of an earlier version is kept and moved to the per-site key' );
	vcap_ref_assert( 3 === VCAP\Referrals::count( $legacy ) && 3 === (int) get_user_option( VCAP\Referrals::COUNT_META, $legacy ) && '' === (string) get_user_meta( $legacy, VCAP\Referrals::COUNT_META, true ), 'single site: the referral count of an earlier version is kept and moved to the per-site key' );

	$stale = wp_create_user( 'ref-stale-' . $tag, wp_generate_password(), 'ref-stale-' . $tag . '@example.com' );
	update_user_meta( $stale, VCAP\Referrals::USER_META, $foreign->get_id() );
	$fresh   = $program->coupon_for( $stale );
	$foreign = new WC_Coupon( $foreign->get_id() );
	vcap_ref_assert( $fresh && $fresh->get_id() !== $foreign->get_id() && $stale === VCAP\Referrals::owner( $fresh ) && 50.0 === (float) $foreign->get_amount() && '' === (string) $foreign->get_meta( VCAP\Referrals::OWNER ), 'single site: an old value that names a foreign coupon is not adopted and the coupon is not changed' );
}

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
vcap_ref_assert( 1 === (int) VCAP\Referrals::count( $referrer ), 'status repeats do not reward twice' );

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
vcap_ref_assert( 0.0 === vcap()->store_credit->balance( $referrer ) && 1 === (int) VCAP\Referrals::count( $referrer ), 'the reward is taken back only once' );

// A booking error while taking the reward back is not "already spent": nothing is finished, a retry withdraws it.
list( $third, $ok ) = vcap_ref_order( $product, 'friend4-' . $tag . '@example.com', $code );
$third->update_status( 'completed' );
$count_before = (int) VCAP\Referrals::count( $referrer );
$vouchers_tbl = VCAP\Install::tables()['vouchers'];
$break_update = static fn( $q ) => str_starts_with( ltrim( (string) $q ), 'UPDATE `' . $vouchers_tbl . '`' ) || str_starts_with( ltrim( (string) $q ), 'UPDATE ' . $vouchers_tbl ) ? '' : $q;
add_filter( 'query', $break_update );
$third = wc_get_order( $third->get_id() );
$third->update_status( 'refunded' );
remove_filter( 'query', $break_update );
$pending_reward = wc_get_order( $third->get_id() )->get_meta( VCAP\Referrals::REWARD );
vcap_ref_assert( 15.0 === vcap()->store_credit->balance( $referrer ) && empty( $pending_reward['revoked'] ) && $count_before === (int) VCAP\Referrals::count( $referrer ), 'a failed debit leaves the withdrawal open (not treated as spent)' );
do_action( VCAP\Referrals::RETRY_HOOK, $third->get_id() );
$done_reward = wc_get_order( $third->get_id() )->get_meta( VCAP\Referrals::REWARD );
vcap_ref_assert( 0.0 === vcap()->store_credit->balance( $referrer ) && ! empty( $done_reward['revoked'] ) && $count_before - 1 === (int) VCAP\Referrals::count( $referrer ), 'the retry withdraws the reward' );

$friend_order = wc_get_order( $friend_order->get_id() );
$friend_order->update_status( 'cancelled' );
vcap_ref_assert( 'trash' === get_post_status( $rewards[0] ), 'a cancelled friend order withdraws the unused reward coupon' );

update_option( 'vcap_referral_enabled', 'no' );
list( , $off ) = vcap_ref_order( $product, 'friend3-' . $tag . '@example.com', $code );
vcap_ref_assert( is_wp_error( $off ), 'referral codes are refused while the program is off' );

// Privacy: the referral code, the friend count and the rewards are exported and erased (nothing about friends).
$privacy        = new VCAP\Privacy( vcap()->vouchers );
$referrer_email = 'ref-' . $tag . '@example.com';
$export         = $privacy->export( $referrer_email, 1 );
$referral_items = array_values( array_filter( $export['data'], static fn( $i ) => 'vcap-referrals' === $i['group_id'] ) );
$exported       = wp_json_encode( $referral_items );
vcap_ref_assert( count( $referral_items ) >= 2 && str_contains( $exported, strtoupper( $code ) ) && ! str_contains( $exported, 'friend-' . $tag ), 'the export lists the referral code and rewards, but no friend data' );
$erased = $privacy->erase( $referrer_email, 1 );
$friend_order = wc_get_order( $friend_order->get_id() );
vcap_ref_assert( $erased['items_removed'] && false === get_user_option( VCAP\Referrals::USER_META, $referrer ) && false === get_user_option( VCAP\Referrals::COUNT_META, $referrer ) && null === VCAP\Referrals::count( $referrer ), 'erasure removes the referral code and counter from the account' );
vcap_ref_assert( 'trash' === get_post_status( $coupon->get_id() ) && '' === (string) get_post_meta( $coupon->get_id(), VCAP\Referrals::OWNER, true ), 'the personal referral code is withdrawn and no longer names the customer' );
vcap_ref_assert( 'erased' === $friend_order->get_meta( VCAP\Referrals::REWARDED ) && 0 === (int) ( $friend_order->get_meta( VCAP\Referrals::REWARD )['user'] ?? -1 ), 'friend orders no longer point to the erased customer' );
vcap_ref_assert( ! in_array( $referrer_email, ( new WC_Coupon( $rewards[0] ) )->get_email_restrictions(), true ), 'reward coupons are no longer restricted to the erased address' );
vcap_ref_assert( ! array_filter( $privacy->export( $referrer_email, 1 )['data'], static fn( $i ) => 'vcap-referrals' === $i['group_id'] ), 'nothing is exported after the erasure' );

update_option( 'vcap_store_credit', 'no' );
delete_option( 'vcap_referral_reward_as' );
echo "Voucher Captain referral checks passed.\n";
