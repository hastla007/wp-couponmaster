<?php
/**
 * Refer a friend on a multisite network: one customer, two sites. The referral coupon id and the reward counter
 * belong to each site; an id of one site never selects (and never changes) a coupon of another site, and old
 * network-wide values are only adopted by the main site. Each step runs as its own process on its site:
 *   wp eval-file tests/multisite-referral-integration.php site-a                          (main site)
 *   wp --url=http://localhost/before/ eval-file tests/multisite-referral-integration.php site-b
 *   wp eval-file tests/multisite-referral-integration.php verify                          (main site)
 */

function vcap_msref_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

/** The per-site user meta key of another site (what update_user_option() writes there). */
function vcap_msref_key( int $blog_id, string $key ): string {
	global $wpdb;
	return $wpdb->get_blog_prefix( $blog_id ) . $key;
}

function vcap_msref_enable( float $friend_amount ): void {
	update_option( 'vcap_referral_enabled', 'yes' );
	update_option( 'vcap_referral_friend_type', 'fixed_cart' );
	update_option( 'vcap_referral_friend_amount', $friend_amount );
	update_option( 'vcap_referral_reward_amount', 15 );
	update_option( 'vcap_referral_reward_as', 'coupon' );
	update_option( 'vcap_store_credit', 'no' );
}

$state_option = 'vcap_multisite_referral_test';
$mode         = $args[0] ?? '';

if ( ! is_multisite() ) {
	throw new RuntimeException( 'FAIL: this check needs a multisite network' );
}

if ( 'site-a' === $mode ) {
	vcap_msref_assert( is_main_site(), 'site A is the main site' );
	vcap_msref_enable( 10 );
	$tag  = strtolower( wp_generate_password( 6, false ) );
	$user = wp_create_user( 'msref-' . $tag, wp_generate_password(), 'msref-' . $tag . '@example.com' );
	// The next post id of site A is free on every site, so site B can get a coupon with the same id.
	global $wpdb;
	$max = 0;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		$max = max( $max, (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->get_blog_prefix( (int) $site_id )}posts" ) ); // phpcs:ignore
	}
	$wpdb->query( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = " . ( $max + 100 ) ); // phpcs:ignore
	$program = new VCAP\Referrals( vcap()->settings, vcap()->store_credit );
	$coupon  = $program->coupon_for( $user );
	vcap_msref_assert( $coupon && $coupon->get_id() && $user === VCAP\Referrals::owner( $coupon ), 'site A: the customer gets a referral code' );
	vcap_msref_assert( $coupon->get_id() === (int) get_user_meta( $user, vcap_msref_key( get_current_blog_id(), VCAP\Referrals::USER_META ), true ) && '' === (string) get_user_meta( $user, VCAP\Referrals::USER_META, true ), 'site A: the coupon id is stored for site A only' );
	update_site_option( $state_option, array( 'user' => $user, 'tag' => $tag, 'blog_a' => get_current_blog_id(), 'coupon_a' => $coupon->get_id() ) );
	echo "Site A done.\n";
	return;
}

$state = get_site_option( $state_option );
if ( ! is_array( $state ) || empty( $state['user'] ) ) {
	throw new RuntimeException( 'FAIL: run the step "site-a" first' );
}
$user     = (int) $state['user'];
$tag      = (string) $state['tag'];
$blog_a   = (int) $state['blog_a'];
$coupon_a = (int) $state['coupon_a'];

if ( 'site-b' === $mode ) {
	vcap_msref_assert( ! is_main_site() && get_current_blog_id() !== $blog_a, 'site B is another site of the network' );
	vcap_msref_enable( 7 );
	// Site B has a coupon of its own with the id of the customer's coupon on site A (e.g. a gift card).
	if ( ! get_post( $coupon_a ) ) {
		wp_insert_post( array( 'import_id' => $coupon_a, 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_title' => 'vcap-msref-gift-' . $tag ) );
	} elseif ( 'shop_coupon' !== get_post_type( $coupon_a ) ) {
		throw new RuntimeException( 'FAIL: site B already uses post id ' . $coupon_a . ' for something else' );
	}
	$foreign = new WC_Coupon( $coupon_a );
	vcap_msref_assert( $coupon_a === $foreign->get_id() && 'shop_coupon' === get_post_type( $coupon_a ), 'site B: a foreign coupon has the id of the referral coupon of site A' );
	$foreign->set_discount_type( 'fixed_cart' );
	$foreign->set_amount( 50 );
	$foreign->set_minimum_amount( '' );
	$foreign->set_usage_limit_per_user( 0 );
	$foreign->update_meta_data( '_vcap_voucher', 'yes' );
	$foreign->save();
	// An earlier version left the id network-wide: a subsite must not take it over.
	update_user_meta( $user, VCAP\Referrals::USER_META, $coupon_a );

	$program = new VCAP\Referrals( vcap()->settings, vcap()->store_credit );
	$coupon  = $program->coupon_for( $user );
	$foreign = new WC_Coupon( $coupon_a );
	vcap_msref_assert( $coupon && $coupon->get_id() && $coupon->get_id() !== $coupon_a && $user === VCAP\Referrals::owner( $coupon ) && 7.0 === (float) $coupon->get_amount(), 'site B: the customer gets an own referral code of site B' );
	vcap_msref_assert( 'fixed_cart' === $foreign->get_discount_type() && 50.0 === (float) $foreign->get_amount() && 0 === (int) $foreign->get_usage_limit_per_user() && '' === (string) $foreign->get_meta( VCAP\Referrals::OWNER ), 'site B: the coupon with the id of site A is not changed' );
	vcap_msref_assert( $coupon->get_id() === (int) get_user_meta( $user, vcap_msref_key( get_current_blog_id(), VCAP\Referrals::USER_META ), true ) && $coupon_a === (int) get_user_meta( $user, vcap_msref_key( $blog_a, VCAP\Referrals::USER_META ), true ), 'each site keeps its own coupon id' );
	vcap_msref_assert( $coupon_a === (int) get_user_meta( $user, VCAP\Referrals::USER_META, true ), 'site B does not take over or remove the old network-wide value' );
	vcap_msref_assert( $coupon->get_id() === $program->coupon_for( $user )->get_id(), 'site B: the code stays the same' );

	// A friend's completed order on site B counts on site B only.
	$product = new WC_Product_Simple();
	$product->set_name( 'Referral product B' );
	$product->set_regular_price( '30' );
	$product->set_virtual( true );
	$product->save();
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'msref-friend-' . $tag . '@example.com' );
	$order->calculate_totals();
	$order->save();
	$applied = $order->apply_coupon( $coupon->get_code() );
	vcap_msref_assert( true === $applied, 'site B: a friend can use the referral code of site B' );
	$order = wc_get_order( $order->get_id() );
	$order->update_status( 'completed' );
	vcap_msref_assert( $user === (int) wc_get_order( $order->get_id() )->get_meta( VCAP\Referrals::REWARDED ), 'site B: the completed order rewards the customer' );
	vcap_msref_assert( 1 === VCAP\Referrals::count( $user ) && '' === (string) get_user_meta( $user, vcap_msref_key( $blog_a, VCAP\Referrals::COUNT_META ), true ) && '' === (string) get_user_meta( $user, VCAP\Referrals::COUNT_META, true ), 'the reward counter counts on site B only' );

	// Erasure on site B removes site B's referral data, not that of site A.
	$privacy = new VCAP\Privacy( vcap()->vouchers );
	$privacy->erase( 'msref-' . $tag . '@example.com', 1 );
	vcap_msref_assert( 'trash' === get_post_status( $coupon->get_id() ) && '' === (string) get_user_meta( $user, vcap_msref_key( get_current_blog_id(), VCAP\Referrals::USER_META ), true ) && null === VCAP\Referrals::count( $user ), 'site B: erasure removes the referral code and counter of site B' );
	vcap_msref_assert( 'publish' === get_post_status( $coupon_a ) && 50.0 === (float) ( new WC_Coupon( $coupon_a ) )->get_amount(), 'site B: erasure leaves the foreign coupon alone' );
	vcap_msref_assert( $coupon_a === (int) get_user_meta( $user, vcap_msref_key( $blog_a, VCAP\Referrals::USER_META ), true ), 'site B: erasure keeps the data of site A' );
	echo "Site B done.\n";
	return;
}

if ( 'verify' === $mode ) {
	vcap_msref_assert( is_main_site() && get_current_blog_id() === $blog_a, 'back on site A' );
	vcap_msref_enable( 10 );
	$program = new VCAP\Referrals( vcap()->settings, vcap()->store_credit );
	$coupon  = $program->coupon_for( $user );
	vcap_msref_assert( $coupon && $coupon_a === $coupon->get_id() && 10.0 === (float) $coupon->get_amount() && 'publish' === get_post_status( $coupon_a ), 'site A: the customer keeps the referral code of site A' );
	vcap_msref_assert( 0 === (int) VCAP\Referrals::count( $user ), 'site A: rewards on site B are not counted here' );
	delete_user_meta( $user, VCAP\Referrals::USER_META );
	delete_site_option( $state_option );
	echo "Voucher Captain multisite referral checks passed.\n";
	return;
}

throw new RuntimeException( 'FAIL: unknown step "' . $mode . '" (site-a, site-b, verify)' );
