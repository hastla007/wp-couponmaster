<?php
/**
 * Install, upgrade and lifecycle checks: upgrade lock, downgrade guard, schema 1.4 storage (promotion usage,
 * claims, ledger indexes), the fail-safe for balance coupons, deactivation/reactivation of tasks, scheduling
 * marker, uninstall (coupons suspended or trashed), voucher coupons without voucher, page-cache rules and the
 * asynchronous Site Health checks.
 *   wp eval-file tests/install-integration.php
 */

function vcap_inst_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

global $wpdb;
$tables = VCAP\Install::tables();

function vcap_inst_voucher( string $mode, array $props = array() ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-inst-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 20 );
	$coupon->set_usage_limit( 'balance' === $mode ? 0 : 1 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 96000000 + $coupon->get_id();
	$v->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
	$v->usage_mode     = $mode;
	$v->initial_amount = 20;
	$v->balance        = 20;
	$v->currency       = get_woocommerce_currency();
	$v->status         = $props['status'] ?? VCAP\Voucher::STATUS_ACTIVE;
	$v->delivery_at    = $props['delivery_at'] ?? null;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

// Schema 1.4: new tables, the delivery state column and the ledger indexes.
vcap_inst_assert( array() === VCAP\Install::verify_schema() && VCAP\Install::storage_ready(), 'the 1.4 schema is complete and its storage is in use' );
$indexes = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW INDEX FROM {$tables['events']}", 2 ) );
vcap_inst_assert( in_array( 'voucher_order', $indexes, true ) && in_array( 'created_at', $indexes, true ), 'the ledger has the (voucher, order, id) and created_at indexes' );
vcap_inst_assert( in_array( get_option( VCAP\Install::INNODB_OPTION ), array( 'yes' ), true ) && VCAP\Install::tables_support_transactions(), 'the InnoDB state is stored in an option' );

// Upgrade lock: while another request holds it nothing runs; an expired lock is taken over.
update_option( VCAP\Install::OPTION_DB_VERSION, '1.3.0' );
delete_transient( 'vcap_schema_retry' );
$wpdb->replace( $wpdb->options, array( 'option_name' => VCAP\Install::UPGRADE_LOCK, 'option_value' => (string) ( time() + 300 ), 'autoload' => 'no' ) );
VCAP\Install::maybe_upgrade();
vcap_inst_assert( '1.3.0' === get_option( VCAP\Install::OPTION_DB_VERSION ), 'a held upgrade lock keeps parallel requests out of the upgrade' );
$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 10 ) ), array( 'option_name' => VCAP\Install::UPGRADE_LOCK ) );
VCAP\Install::maybe_upgrade();
vcap_inst_assert( VCAP_DB_VERSION === get_option( VCAP\Install::OPTION_DB_VERSION ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", VCAP\Install::UPGRADE_LOCK ) ), 'an expired lock is taken over, the upgrade finishes and gives the lock back' );

// Downgrade: a newer stored version is never lowered.
update_option( VCAP\Install::OPTION_DB_VERSION, '99.0.0' );
VCAP\Install::maybe_upgrade();
VCAP\Install::activate_site();
vcap_inst_assert( '99.0.0' === get_option( VCAP\Install::OPTION_DB_VERSION ) && has_action( 'admin_notices', array( VCAP\Install::class, 'downgrade_notice' ) ), 'a downgrade keeps the newer database version and warns administrators' );
update_option( VCAP\Install::OPTION_DB_VERSION, VCAP_DB_VERSION );

// Promotion usage: own table, a use is given back by its order only.
$promo = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'Install test promotion' ) );
VCAP\Discounts\Usage::add( $promo, 'e:inst@example.com', 101 );
VCAP\Discounts\Usage::add( $promo, 'e:inst@example.com', 102 );
VCAP\Discounts\Usage::remove( $promo, 'e:inst@example.com', 102 );
VCAP\Discounts\Usage::remove( $promo, 'e:inst@example.com', 103 );
vcap_inst_assert( 1 === VCAP\Discounts\Usage::count( $promo ) && 1 === VCAP\Discounts\Usage::count_for( $promo, array( 'e:inst@example.com' ) ), 'uses live in the usage table and an order never gives back another order\'s use' );
vcap_inst_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_vcap_used_by'", $promo ) ), 'no usage rows are written to post meta any more' );

// Old storage: post meta uses and claim options are read until moved, then moved in batches.
add_post_meta( $promo, '_vcap_used_by', 'e:legacy@example.com' );
add_post_meta( $promo, '_vcap_used_by', 'e:legacy@example.com' );
$legacy_claim = 'legacy-test:' . wp_generate_password( 8, false, false );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_claim_' . substr( md5( $legacy_claim ), 0, 24 ), 'option_value' => (string) time(), 'autoload' => 'no' ) );
update_option( VCAP\Install::LEGACY_OPTION, time() );
vcap_inst_assert( 3 === VCAP\Discounts\Usage::count( $promo ) && 2 === VCAP\Discounts\Usage::count_for( $promo, array( 'e:legacy@example.com' ) ), 'uses still in post meta count until they are moved' );
vcap_inst_assert( ! VCAP\Claim::take( $legacy_claim ), 'a claim still stored as an option stays taken' );
vcap_inst_assert( VCAP\Install::move_legacy_rows( 5.0 ), 'the old rows are moved' );
vcap_inst_assert( 3 === VCAP\Discounts\Usage::count( $promo ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_vcap_used_by'", $promo ) ), 'moved uses keep the count and leave post meta' );
VCAP\Discounts\Usage::remove( $promo, 'e:legacy@example.com', 555 );
vcap_inst_assert( 2 === VCAP\Discounts\Usage::count( $promo ), 'a moved use (without order) is given back for its customer' );
vcap_inst_assert( ! VCAP\Claim::take( $legacy_claim ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_vcap_claim_' ) . '%' ) ), 'moved claims stay taken and no claim options are left' );
delete_option( VCAP\Install::LEGACY_OPTION );

// Claims: table rows, released again, finished kinds purged after the retention period.
$claim = 'usage:' . wp_generate_password( 8, false, false );
vcap_inst_assert( VCAP\Claim::take( $claim ) && ! VCAP\Claim::take( $claim ), 'a claim is taken exactly once' );
VCAP\Claim::release( $claim );
vcap_inst_assert( VCAP\Claim::take( $claim ), 'a released claim can be taken again' );
$wpdb->insert( $tables['claims'], array( 'claim_key' => 'old-usage-' . wp_generate_password( 8, false, false ), 'kind' => 'usage', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
$wpdb->insert( $tables['claims'], array( 'claim_key' => 'old-revoke-' . wp_generate_password( 8, false, false ), 'kind' => 'topup-revoke', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
VCAP\Claim::purge();
$old = $wpdb->get_col( $wpdb->prepare( "SELECT kind FROM {$tables['claims']} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 300 * DAY_IN_SECONDS ) ) );
vcap_inst_assert( ! in_array( 'usage', $old, true ) && in_array( 'topup-revoke', $old, true ), 'old claims of finished kinds are purged, claims without another record are kept' );

// Fail-safe of the version gate: gift card coupons are refused, single-use vouchers are not.
$card   = vcap_inst_voucher( 'balance' );
$single = vcap_inst_voucher( 'single' );
$refused = false;
try {
	VCAP\Install::refuse_balance_coupon( true, new WC_Coupon( $card->coupon_id ) );
} catch ( Exception $e ) {
	$refused = 9090 === $e->getCode();
}
vcap_inst_assert( $refused && true === VCAP\Install::refuse_balance_coupon( true, new WC_Coupon( $single->coupon_id ) ), 'without a running plugin balance coupons are refused, single-use coupons still work' );

// Deactivation suspends balance coupons and removes all tasks; activation restores both.
$future = vcap_inst_voucher( 'single', array( 'status' => VCAP\Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
as_schedule_single_action( time() + 5 * DAY_IN_SECONDS, 'vcap_deliver_voucher', array( 'voucher_id' => $future->id ), 'vcap' );
wp_schedule_single_event( time() + HOUR_IN_SECONDS, VCAP\Licensing\Entitlements::REFRESH_HOOK );
VCAP\Install::deactivate_site();
vcap_inst_assert( 'draft' === get_post_status( $card->coupon_id ) && 'publish' === get_post_status( $single->coupon_id ), 'deactivation suspends balance coupons only' );
vcap_inst_assert( ! as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $future->id ), 'vcap' ) && ! wp_next_scheduled( VCAP\Licensing\Entitlements::REFRESH_HOOK ), 'deactivation removes the voucher tasks and the license refresh' );
VCAP\Install::activate_site();
vcap()->ensure_schedules();
vcap_inst_assert( 'publish' === get_post_status( $card->coupon_id ), 'activation publishes the suspended coupons again' );
vcap_inst_assert( as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $future->id ), 'vcap' ) && as_has_scheduled_action( 'vcap_daily_maintenance', array(), 'vcap' ) && as_has_scheduled_action( 'vcap_release_stale_order_reservations', array(), 'vcap' ), 'activation schedules the delivery and the recurring tasks again' );
$checked = (int) get_option( VCAP\Install::SCHEDULES_OPTION );
vcap_inst_assert( $checked > time() - MINUTE_IN_SECONDS && false === get_option( VCAP\Install::RESTORE_TASKS_OPTION ), 'the schedule check is remembered (no Action Scheduler queries on every request)' );

// Uninstall (uninstall.php, functions only): balance coupons are always suspended, with data deletion every
// Voucher Captain coupon goes to the trash; other coupons are kept.
define( 'VCAP_UNINSTALL_FUNCTIONS_ONLY', true );
require VCAP_PATH . 'uninstall.php';
$u_card   = vcap_inst_voucher( 'balance' );
$u_single = vcap_inst_voucher( 'single' );
$u_orphan = new WC_Coupon();
$u_orphan->set_code( 'vcap-inst-orphan-' . strtolower( wp_generate_password( 8, false, false ) ) );
$u_orphan->set_discount_type( 'fixed_cart' );
$u_orphan->set_amount( 30 );
$u_orphan->update_meta_data( '_vcap_voucher', 'yes' );
$u_orphan->save();
$u_plain = new WC_Coupon();
$u_plain->set_code( 'vcap-inst-plain-' . strtolower( wp_generate_password( 8, false, false ) ) );
$u_plain->set_discount_type( 'percent' );
$u_plain->set_amount( 10 );
$u_plain->save();
$u_changed = vcap_uninstall_coupons( false );
vcap_inst_assert( 'draft' === get_post_status( $u_card->coupon_id ) && 'publish' === get_post_status( $u_single->coupon_id ) && 'publish' === get_post_status( $u_orphan->get_id() ) && 'publish' === get_post_status( $u_plain->get_id() ), 'uninstall without data deletion suspends balance coupons only' );
vcap_inst_assert( in_array( $u_card->coupon_id, array_map( 'intval', (array) get_option( VCAP\Install::SUSPENDED_OPTION ) ), true ) && isset( $u_changed[ $u_card->coupon_id ] ) && ! isset( $u_changed[ $u_single->coupon_id ] ), 'the suspended coupons are remembered for a reinstall' );
VCAP\Install::restore_balance_coupons();
vcap_inst_assert( 'publish' === get_post_status( $u_card->coupon_id ), 'a reinstall publishes the suspended coupons again' );
$u_changed = vcap_uninstall_coupons( true );
vcap_inst_assert( 'trash' === get_post_status( $u_card->coupon_id ) && 'trash' === get_post_status( $u_single->coupon_id ) && 'trash' === get_post_status( $u_orphan->get_id() ) && 'publish' === get_post_status( $u_plain->get_id() ), 'uninstall with data deletion moves every voucher coupon to the trash and keeps other coupons' );
vcap_inst_assert( 'publish' === get_post_meta( $u_card->coupon_id, '_wp_trash_meta_status', true ) && 'publish' === get_post_meta( $u_single->coupon_id, '_wp_trash_meta_status', true ) && ! wc_get_coupon_id_by_code( $u_single->code ), 'trashed voucher coupons can be restored with their status and are no longer found by code' );
// Undo for the following checks and suites (this run did not really uninstall).
foreach ( $u_changed as $u_id => $u_status ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => $u_status ), array( 'ID' => (int) $u_id ) );
	delete_post_meta( (int) $u_id, '_wp_trash_meta_status' );
	delete_post_meta( (int) $u_id, '_wp_trash_meta_time' );
	clean_post_cache( (int) $u_id );
}
WC_Cache_Helper::invalidate_cache_group( 'coupons' );
vcap_inst_assert( 'publish' === get_post_status( $u_card->coupon_id ) && 'publish' === get_post_status( $u_orphan->get_id() ), '(coupons restored after the uninstall check)' );

// A voucher coupon without its voucher (row deleted, data removed) is never redeemed as an ordinary coupon.
$u_refused = 0;
try {
	vcap()->coupons->validate( true, new WC_Coupon( $u_orphan->get_id() ) );
} catch ( Exception $e ) {
	$u_refused = $e->getCode();
}
vcap_inst_assert( 9007 === $u_refused && true === vcap()->coupons->validate( true, new WC_Coupon( $u_single->coupon_id ) ) && true === vcap()->coupons->validate( true, new WC_Coupon( $u_plain->get_id() ) ), 'a voucher coupon without voucher row is refused, vouchers and ordinary coupons are not' );
wp_delete_post( $u_orphan->get_id(), true );
wp_delete_post( $u_plain->get_id(), true );

// Page caches: the redeem form keeps the page out of caches; guests check balances without a nonce.
wp_set_current_user( 0 );
do_shortcode( '[vcap_redeem]' );
vcap_inst_assert( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'the redeem form marks the page as not cacheable' );
$form = do_shortcode( '[vcap_balance]' );
vcap_inst_assert( ! str_contains( $form, 'vcap_balance_nonce' ), 'the guest balance form carries no nonce (cacheable)' );
$_POST = array( 'vcap_balance_code' => $card->code );
$html  = do_shortcode( '[vcap_balance]' );
$_POST = array();
vcap_inst_assert( str_contains( $html, 'vcap-balance-result' ), 'a guest balance check works without a nonce' );
vcap_inst_assert( wp_style_is( 'vcap-frontend', 'enqueued' ), 'shortcodes enqueue the storefront style where they render' );

// Blocks come from block.json with apiVersion 3.
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vcap/redeem' );
vcap_inst_assert( $block && 3 === (int) $block->api_version && in_array( 'vcap-frontend', (array) $block->style_handles, true ), 'blocks are registered from block.json (apiVersion 3, storefront style)' );

// Site Health: the product checks run asynchronously through REST.
$health = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
vcap_inst_assert( isset( $health['async']['vcap_validity'], $health['async']['vcap_tax_status'] ) && ! isset( $health['direct']['vcap_validity'] ) && ! empty( $health['async']['vcap_validity']['has_rest'] ), 'the product checks are asynchronous Site Health tests' );
vcap_inst_assert( isset( rest_get_server()->get_routes()['/vcap/v1/site-health/validity'] ), 'the asynchronous checks have their REST route' );

echo "Voucher Captain install checks passed.\n";
