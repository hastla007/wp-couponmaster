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

// Usage limits: counted when the order is paid, given back when it is cancelled.
$once = vcap_promo_campaign( 'Once only', array( 'action' => 'percent', 'percent' => 10, 'usage_limit' => 1 ), 0 );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $once ) === $codes, 'a promotion with a total limit applies while unused' );
$paid = wc_create_order();
$paid->add_product( $shirt, 1 );
$paid->set_billing_email( 'promo-limit@example.com' );
$paid->calculate_totals();
$paid->apply_coupon( 'vcap-campaign-' . $once );
$paid->update_status( 'processing' );
$paid->update_status( 'completed' );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $once ), 'a paid order counts one use (status repeats do not count twice)' );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( ! in_array( 'vcap-campaign-' . $once, $codes, true ), 'a used-up promotion is no longer applied' );
$again = wc_create_order();
$again->add_product( $shirt, 1 );
$again->calculate_totals();
vcap_promo_assert( is_wp_error( $again->apply_coupon( 'vcap-campaign-' . $once ) ), 'a used-up promotion is refused on orders' );
$paid = wc_get_order( $paid->get_id() );
$paid->update_status( 'cancelled' );
vcap_promo_assert( 0 === VCAP\Discounts\Usage::count( $once ), 'cancelling the order gives the use back' );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $once ) === $codes, 'the promotion applies again after the cancellation' );
wp_update_post( array( 'ID' => $once, 'post_status' => 'draft' ) );

// Checkout holds the use right away: a second unpaid order cannot take the last use as well.
$held = vcap_promo_campaign( 'Held at checkout', array( 'action' => 'percent', 'percent' => 10, 'usage_limit' => 1 ), 0 );
$usage = new VCAP\Discounts\Usage( new VCAP\Discounts\Repository() );
$first = wc_create_order();
$first->add_product( $shirt, 1 );
$first->set_billing_email( 'hold-a@example.com' );
$first->calculate_totals();
$first->apply_coupon( 'vcap-campaign-' . $held );
$second = wc_create_order();
$second->add_product( $shirt, 1 );
$second->set_billing_email( 'hold-b@example.com' );
$second->calculate_totals();
$second->apply_coupon( 'vcap-campaign-' . $held );
$usage->hold( wc_get_order( $first->get_id() ) );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $held ), 'placing an unpaid order holds the use' );
$usage->reverted_to_pending( $first->get_id(), 'checkout-draft', 'pending' );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $held ), 'the block checkout step checkout-draft → pending keeps the held use' );
$refused = '';
try {
	$usage->hold( wc_get_order( $second->get_id() ) );
} catch ( Exception $e ) {
	$refused = $e->getMessage();
}
vcap_promo_assert( '' !== $refused && 1 === VCAP\Discounts\Usage::count( $held ), 'a parallel order is refused instead of exceeding the limit' );
$first = wc_get_order( $first->get_id() );
$first->update_status( 'processing' );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $held ), 'paying the held order does not count the use twice' );
$first->update_status( 'cancelled' );
vcap_promo_assert( 0 === VCAP\Discounts\Usage::count( $held ), 'cancelling gives the held use back' );
$usage->hold( wc_get_order( $second->get_id() ) );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $held ), 'the other order can hold it afterwards' );
$usage->release_abandoned();
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count( $held ), 'a recent unpaid order keeps its held use' );
$usage->release_abandoned( time() + 2 * HOUR_IN_SECONDS ); // Two hours later the order is abandoned.
vcap_promo_assert( 0 === VCAP\Discounts\Usage::count( $held ), 'an abandoned unpaid order gives its held use back' );
wp_update_post( array( 'ID' => $held, 'post_status' => 'draft' ) );

$per = vcap_promo_campaign( 'Once per customer', array( 'action' => 'percent', 'percent' => 10, 'usage_limit_per_customer' => 1 ), 0 );
$mine = wc_create_order();
$mine->add_product( $shirt, 1 );
$mine->set_billing_email( 'Repeat@Example.com' );
$mine->calculate_totals();
$mine->apply_coupon( 'vcap-campaign-' . $per );
$mine->update_status( 'processing' );
WC()->customer->set_billing_email( 'repeat@example.com' );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( ! in_array( 'vcap-campaign-' . $per, $codes, true ), 'the per-customer limit recognises the billing email' );
WC()->customer->set_billing_email( 'someone-else@example.com' );
list( $codes ) = vcap_promo_cart();
vcap_promo_assert( array( 'vcap-campaign-' . $per ) === $codes, 'other customers can still use it' );
WC()->customer->set_billing_email( '' );
wp_update_post( array( 'ID' => $per, 'post_status' => 'draft' ) );

update_option( 'vcap_campaigns_enabled', 'no' );
WC()->cart->empty_cart();
wc_clear_notices();
// B12: cart checks read the usage counts of a promotion once per request (totals are calculated several times);
// a use added in the request is seen at once, and the checkout hold always reads the database.
global $wpdb;
$b12_id       = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'B12 memo promotion' ) );
$b12_campaign = array( 'id' => $b12_id, 'title' => 'B12', 'rules' => array( 'usage_limit' => 2, 'usage_limit_per_customer' => 1 ) );
$b12_table    = VCAP\Install::tables()['usage'];
$b12_counts   = VCAP\Install::tables()['counts'];
$b12_queries  = array();
$b12_log      = static function ( $q ) use ( &$b12_queries, $b12_table, $b12_counts ) {
	if ( str_contains( $q, $b12_table ) || str_contains( $q, $b12_counts ) ) {
		$b12_queries[] = $q;
	}
	return $q;
};
add_filter( 'query', $b12_log );
for ( $i = 0; $i < 4; $i++ ) {
	VCAP\Discounts\Usage::blocker( $b12_campaign, 0, 'b12@example.com' );
}
$b12_cart  = count( $b12_queries );
$b12_total = array_values( preg_grep( '/^SELECT (COUNT\(\*\)|uses) FROM \S+ WHERE campaign_id = ' . $b12_id . '$/', $b12_queries ) );
VCAP\Discounts\Usage::add( $b12_id, VCAP\Discounts\Usage::email_key( 'b12@example.com' ), 0 );
$b12_after = VCAP\Discounts\Usage::blocker( $b12_campaign, 0, 'b12@example.com' );
$b12_queries = array();
VCAP\Discounts\Usage::blocker( $b12_campaign, 0, 'b12@example.com', true );
$b12_fresh = count( preg_grep( '/^SELECT COUNT/', $b12_queries ) );
remove_filter( 'query', $b12_log );
// V4: per-customer keys compare the mailbox ("+tag", Gmail dots); hashes stored before V4 still count.
$v4_tag = strtolower( wp_generate_password( 6, false, false ) );
VCAP\Discounts\Usage::add( 990004, VCAP\Discounts\Usage::customer_keys( 0, "v4.p{$v4_tag}+a@gmail.com" )[0], 0 );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count_for( 990004, VCAP\Discounts\Usage::customer_keys( 0, "V4P{$v4_tag}@googlemail.com" ) ) && 1 === VCAP\Discounts\Usage::count_for( 990004, VCAP\Discounts\Usage::customer_keys( 0, "v4p{$v4_tag}+b@gmail.com" ) ) && 0 === VCAP\Discounts\Usage::count_for( 990004, VCAP\Discounts\Usage::customer_keys( 0, "v4q{$v4_tag}@gmail.com" ) ), 'V4: a promotion use counts for the same mailbox under another +tag or with Gmail dots' );
VCAP\Discounts\Usage::add( 990004, VCAP\CustomerEmail::legacy_key( "Legacy.{$v4_tag}+x@Example.com" ), 0 );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count_for( 990004, VCAP\Discounts\Usage::customer_keys( 0, "legacy.{$v4_tag}+x@example.com" ) ), 'V4: a guest key stored before V4 (hash of the address only lowered) still counts' );
$v4_user = (int) wp_insert_user( array( 'user_login' => "vcap_v4_{$v4_tag}", 'user_pass' => wp_generate_password( 20 ), 'user_email' => "acct{$v4_tag}@example.com" ) );
VCAP\Discounts\Usage::add( 990004, VCAP\Discounts\Usage::customer_keys( $v4_user, "acct{$v4_tag}@example.com" )[0], 0 );
vcap_promo_assert( 1 === VCAP\Discounts\Usage::count_for( 990004, VCAP\Discounts\Usage::customer_keys( 0, "acct{$v4_tag}+guest@example.com" ) ), 'V4: a guest order with "+tag" of an account email counts for that account' );
$wpdb->delete( VCAP\Install::tables()['usage'], array( 'campaign_id' => 990004 ) );
$wpdb->delete( $b12_counts, array( 'campaign_id' => 990004 ) );
wp_delete_user( $v4_user );
vcap_promo_assert( 2 === $b12_cart, 'B12: four cart checks read the total and the customer count once each (' . $b12_cart . ' queries)' );
vcap_promo_assert( 1 === count( $b12_total ) && str_starts_with( $b12_total[0], "SELECT uses FROM {$b12_counts} " ), 'B12: the total of a promotion is read from its counter row, not counted over all uses' );
vcap_promo_assert( 'usage_limit_customer' === $b12_after, 'B12: a use added in the request is counted at once (memo forgotten)' );
vcap_promo_assert( $b12_fresh >= 1, 'B12: the check that decides under the lock reads the database' );
// B12: counter kept with every add/remove, totals of all limited promotions primed in one query, daily recount.
$b12_row = static fn( int $id ) => $wpdb->get_var( $wpdb->prepare( "SELECT uses FROM {$b12_counts} WHERE campaign_id = %d", $id ) );
$b12_n   = static fn( int $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b12_table} WHERE campaign_id = %d", $id ) );
VCAP\Discounts\Usage::add( $b12_id, 'o:b12-a', 9101 );
VCAP\Discounts\Usage::add( $b12_id, 'o:b12-b', 9102 );
VCAP\Discounts\Usage::remove( $b12_id, 'o:b12-b', 9102 );
VCAP\Discounts\Usage::remove( $b12_id, 'o:b12-x', 9199 ); // Nothing to give back: the counter stays.
vcap_promo_assert( 2 === (int) $b12_row( $b12_id ) && 2 === $b12_n( $b12_id ) && 2 === VCAP\Discounts\Usage::count( $b12_id ), 'B12: the counter follows every use added and given back (and only those)' );
$b12_other = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'B12 second promotion' ) );
VCAP\Discounts\Usage::add( $b12_other, 'o:b12-c', 9103 );
VCAP\Discounts\Usage::forget_counts();
$b12_queries = array();
add_filter( 'query', $b12_log );
VCAP\Discounts\Usage::prime_counts( array( $b12_id, $b12_other ) );
$b12_primed = array( VCAP\Discounts\Usage::blocker( array( 'id' => $b12_id, 'rules' => array( 'usage_limit' => 2, 'usage_limit_per_customer' => 0 ) ), 0, '' ), VCAP\Discounts\Usage::blocker( array( 'id' => $b12_other, 'rules' => array( 'usage_limit' => 5, 'usage_limit_per_customer' => 0 ) ), 0, '' ) );
remove_filter( 'query', $b12_log );
vcap_promo_assert( 1 === count( $b12_queries ) && array( 'usage_limit', '' ) === $b12_primed, 'B12: the cart sync reads the totals of all limited promotions with one query' );
$wpdb->update( $b12_counts, array( 'uses' => 99 ), array( 'campaign_id' => $b12_id ) );
$wpdb->insert( $b12_table, array( 'campaign_id' => $b12_other, 'customer_key' => 'o:b12-hand', 'order_id' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$b12_fixed = VCAP\Discounts\Usage::reconcile();
vcap_promo_assert( $b12_fixed >= 2 && 2 === (int) $b12_row( $b12_id ) && 2 === (int) $b12_row( $b12_other ), 'B12: the daily recount corrects counters that differ from the usage table' );
wp_delete_post( $b12_other, true );
vcap_promo_assert( null === $b12_row( $b12_other ) && 0 === $b12_n( $b12_other ), 'B12: a deleted promotion takes its counter with it' );
wp_delete_post( $b12_id, true );

echo "Voucher Captain promotion checks passed.\n";
