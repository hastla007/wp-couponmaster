<?php
/**
 * Install, upgrade and lifecycle checks: upgrade lock, downgrade guard, schema 1.4 storage (promotion usage,
 * claims, ledger indexes), the fail-safe for balance coupons, deactivation/reactivation of tasks, scheduling
 * marker, uninstall (coupons suspended or trashed), voucher coupons without voucher, page-cache rules, the
 * asynchronous Site Health checks and the free core (no license page, no Update URI, license data of earlier builds
 * removed on upgrade and uninstall).
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

// A4: the tables are created and verified once per upgrade step; without the migration budget (shop requests)
// the version stays and the data migration continues in the vcap_migrate action.
$inst_ddl = 0;
$inst_count_ddl = static function ( $queries ) use ( &$inst_ddl ) {
	++$inst_ddl;
	return $queries;
};
add_filter( 'dbdelta_queries', $inst_count_ddl );
update_option( VCAP\Install::OPTION_DB_VERSION, '1.3.0' );
delete_option( VCAP\Install::SCHEMA_STEP_OPTION );
delete_transient( 'vcap_schema_retry' );
delete_transient( 'vcap_migrate_queued' );
$inst_keep_legacy = get_option( VCAP\Install::LEGACY_OPTION, false );
$inst_upgrade     = new ReflectionMethod( VCAP\Install::class, 'upgrade_schema' );
$inst_upgrade->setAccessible( true );
$inst_first = $inst_upgrade->invoke( null, '1.3.0', false );
$inst_ddl_first = $inst_ddl;
vcap_inst_assert( false === $inst_first && $inst_ddl_first > 0 && '1.3.0>' . VCAP_DB_VERSION === get_option( VCAP\Install::SCHEMA_STEP_OPTION ) && '1.3.0' === get_option( VCAP\Install::OPTION_DB_VERSION ), 'A4: the first upgrade run creates and verifies the tables once and remembers the step; without migration budget the version stays' );
$inst_second = $inst_upgrade->invoke( null, '1.3.0', false );
vcap_inst_assert( false === $inst_second && $inst_ddl_first === $inst_ddl, 'A4: further runs of the same step run no DDL (no dbDelta) and no migration batch' );
$inst_queue = new ReflectionMethod( VCAP\Install::class, 'queue_migration' );
$inst_queue->setAccessible( true );
$inst_queue->invoke( null );
vcap_inst_assert( as_has_scheduled_action( VCAP\Install::MIGRATE_HOOK, array(), VCAP\Delivery::GROUP ) && get_transient( 'vcap_migrate_queued' ), 'A4: the data migration is queued as Action Scheduler action vcap_migrate (once per five minutes)' );
VCAP\Install::run_queued_migration();
vcap_inst_assert( VCAP_DB_VERSION === get_option( VCAP\Install::OPTION_DB_VERSION ) && false === get_option( VCAP\Install::SCHEMA_STEP_OPTION ) && $inst_ddl_first === $inst_ddl, 'A4: the vcap_migrate action finishes the migration without running the DDL again' );
remove_filter( 'dbdelta_queries', $inst_count_ddl );
as_unschedule_all_actions( VCAP\Install::MIGRATE_HOOK, array(), VCAP\Delivery::GROUP );
delete_transient( 'vcap_migrate_queued' );
false === $inst_keep_legacy ? delete_option( VCAP\Install::LEGACY_OPTION ) : update_option( VCAP\Install::LEGACY_OPTION, $inst_keep_legacy );

// L3: a first installation whose schema check failed (empty version, schema error) retries only the schema –
// no rewrite flush, no task or coupon restore on every attempt.
delete_option( VCAP\Install::OPTION_DB_VERSION );
update_option( VCAP\Install::SCHEMA_ERROR_OPTION, 'test: missing index', false );
delete_option( VCAP\Install::SCHEMA_STEP_OPTION );
delete_option( 'vcap_flush_rewrite' );
delete_option( VCAP\Install::RESTORE_TASKS_OPTION );
delete_transient( 'vcap_schema_retry' );
VCAP\Install::maybe_upgrade();
vcap_inst_assert( VCAP_DB_VERSION === get_option( VCAP\Install::OPTION_DB_VERSION ) && false === get_option( VCAP\Install::SCHEMA_ERROR_OPTION ), 'L3: a failed first installation retries the schema and stores the version once it is complete' );
vcap_inst_assert( false === get_option( 'vcap_flush_rewrite' ) && false === get_option( VCAP\Install::RESTORE_TASKS_OPTION ), 'L3: the retry runs no activation (no rewrite flush, no task restore)' );

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

// L11: the daily maintenance moves old rows only under the upgrade lock (never alongside the migration).
add_post_meta( $promo, '_vcap_used_by', 'e:locked@example.com' );
update_option( VCAP\Install::LEGACY_OPTION, time() );
$wpdb->replace( $wpdb->options, array( 'option_name' => VCAP\Install::UPGRADE_LOCK, 'option_value' => (string) ( time() + 300 ), 'autoload' => 'no' ) );
VCAP\Install::move_legacy_rows_locked( 5.0 );
vcap_inst_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_vcap_used_by'", $promo ) ) && 3 === VCAP\Discounts\Usage::count( $promo ), 'L11: while another request holds the upgrade lock the maintenance moves nothing' );
$wpdb->delete( $wpdb->options, array( 'option_name' => VCAP\Install::UPGRADE_LOCK ) );
wp_cache_delete( VCAP\Install::UPGRADE_LOCK, 'options' );
VCAP\Install::move_legacy_rows_locked( 5.0 );
vcap_inst_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_vcap_used_by'", $promo ) ) && 3 === VCAP\Discounts\Usage::count( $promo ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", VCAP\Install::UPGRADE_LOCK ) ), 'L11: with the lock free the row is moved once and the lock is given back' );
VCAP\Discounts\Usage::remove( $promo, 'e:locked@example.com', 556 );
delete_option( VCAP\Install::LEGACY_OPTION );

// B12/B16: schema 1.7 – promotion counters filled from the usage table, rate limit counters and scanner keys moved
// out of wp_options (before the switch both still use the old storage).
$r1_tables  = VCAP\Install::tables();
$r1_storage = get_option( VCAP\Install::STORAGE_OPTION );
$r1_flag    = get_option( VCAP\Install::LEGACY_THROTTLE_OPTION, false );
$r1_promo   = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'R1 counter promotion' ) );
update_option( VCAP\Install::STORAGE_OPTION, '1.6.0' );
VCAP\Discounts\Usage::add( $r1_promo, 'o:r1-a', 7001 );
VCAP\Discounts\Usage::add( $r1_promo, 'o:r1-b', 7002 );
VCAP\Discounts\Usage::add( $r1_promo, 'o:r1-c', 7003 );
VCAP\Discounts\Usage::remove( $r1_promo, 'o:r1-c', 7003 );
$wpdb->replace( $r1_tables['counts'], array( 'campaign_id' => $r1_promo, 'uses' => 50 ) ); // Stale row (e.g. a rollback in between).
$r1_old_count = VCAP\Discounts\Usage::count( $r1_promo );
$r1_bucket    = 'r1-' . wp_generate_password( 6, false, false );
add_filter( 'vcap_client_ip', $r1_ip = static fn() => '203.0.113.171' );
VCAP\Plugin::rate_limited( $r1_bucket, 10, 0, HOUR_IN_SECONDS );
VCAP\Plugin::rate_limited( $r1_bucket, 10, 0, HOUR_IN_SECONDS );
$r1_rate_options = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_rl_' ) . '%' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'vcap_rl_' . sprintf( '%010d', time() - 60 ) . '_' . str_repeat( 'c', 64 ), 'option_value' => '9', 'autoload' => 'no' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( 'r1-fresh' ), 'option_value' => time() . '|' . md5( 'sig' ) . '#42|{"success":true}', 'autoload' => 'no' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( 'r1-running' ), 'option_value' => time() . '|' . md5( 'sig' ) . '|', 'autoload' => 'no' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_vcap_scan_' . md5( 'r1-old' ), 'option_value' => ( time() - 3 * DAY_IN_SECONDS ) . '|' . md5( 'sig' ) . '#1|{}', 'autoload' => 'no' ) );
vcap_inst_assert( 2 === $r1_old_count && 0 < $r1_rate_options, 'B12/B16: before the 1.7 switch uses are counted from the usage table and rate limits still count in wp_options' );
$r1_migrate = new ReflectionMethod( VCAP\Install::class, 'migrate_1_7_0' );
$r1_migrate->setAccessible( true );
vcap_inst_assert( true === $r1_migrate->invoke( null ) && VCAP\Install::counters_ready(), 'B12/B16: the 1.7.0 migration switches to the counter tables' );
vcap_inst_assert( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT uses FROM {$r1_tables['counts']} WHERE campaign_id = %d", $r1_promo ) ) && 2 === VCAP\Discounts\Usage::count( $r1_promo ), 'B12: the migration fills the promotion counters from the usage table (a stale counter is replaced)' );
$r1_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'vcap_rl_' ) . '%', $wpdb->esc_like( '_vcap_scan_' ) . '%' ) );
$r1_scan  = $wpdb->get_results( "SELECT key_hash, event_mark, response FROM {$r1_tables['scan']}", OBJECT_K );
vcap_inst_assert( 0 === $r1_left && ! $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$r1_tables['rate']} WHERE counter_key = %s", str_repeat( 'c', 64 ) ) ), 'B16: rate limit and scanner key options are moved (finished windows dropped), none is left in wp_options' );
vcap_inst_assert( isset( $r1_scan[ md5( 'r1-fresh' ) ], $r1_scan[ md5( 'r1-running' ) ] ) && '{"success":true}' === $r1_scan[ md5( 'r1-fresh' ) ]->response && 42 === (int) $r1_scan[ md5( 'r1-fresh' ) ]->event_mark && null === $r1_scan[ md5( 'r1-running' ) ]->response && null === $r1_scan[ md5( 'r1-running' ) ]->event_mark && ! isset( $r1_scan[ md5( 'r1-old' ) ] ), 'B16: scanner keys keep their answer, event mark and running state; keys older than two days are not moved' );
$r1_third = VCAP\Plugin::rate_limited( $r1_bucket, 2, 0, HOUR_IN_SECONDS );
vcap_inst_assert( true === $r1_third, 'B16: a running window keeps its count across the move (third attempt over a limit of 2 is refused)' );
remove_filter( 'vcap_client_ip', $r1_ip );
wp_delete_post( $r1_promo, true );
$wpdb->delete( $r1_tables['scan'], array( 'key_hash' => md5( 'r1-fresh' ) ) );
$wpdb->delete( $r1_tables['scan'], array( 'key_hash' => md5( 'r1-running' ) ) );
VCAP\Plugin::reset_rate_limits();
update_option( VCAP\Install::STORAGE_OPTION, $r1_storage );
false === $r1_flag ? delete_option( VCAP\Install::LEGACY_THROTTLE_OPTION ) : update_option( VCAP\Install::LEGACY_THROTTLE_OPTION, $r1_flag );

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
wp_schedule_single_event( time() + HOUR_IN_SECONDS, VCAP\Install::LEGACY_LICENSE_HOOK );
VCAP\Install::deactivate_site();
vcap_inst_assert( 'draft' === get_post_status( $card->coupon_id ) && 'publish' === get_post_status( $single->coupon_id ), 'deactivation suspends balance coupons only' );
vcap_inst_assert( ! as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $future->id ), 'vcap' ) && ! wp_next_scheduled( VCAP\Install::LEGACY_LICENSE_HOOK ), 'deactivation removes the voucher tasks and a license refresh of earlier builds' );
vcap_inst_assert( (int) get_option( VCAP\Install::DEACTIVATED_OPTION ) > time() - MINUTE_IN_SECONDS && '1' === get_post_meta( $card->coupon_id, VCAP\Install::SUSPENDED_META, true ), 'deactivation remembers its time and marks the suspended coupon' );
VCAP\Install::activate_site();
vcap_inst_assert( is_array( get_option( VCAP\Install::CATCH_UP_OPTION ) ) && false === get_option( VCAP\Install::DEACTIVATED_OPTION ), 'activation plans the catch-up of the orders since the deactivation' );
vcap()->ensure_schedules();
vcap_inst_assert( 'publish' === get_post_status( $card->coupon_id ) && '' === get_post_meta( $card->coupon_id, VCAP\Install::SUSPENDED_META, true ), 'activation publishes the suspended coupons again' );
vcap_inst_assert( as_has_scheduled_action( VCAP\CatchUp::HOOK, null, 'vcap' ) && false === get_option( VCAP\Install::CATCH_UP_OPTION ), 'the catch-up runs as Action Scheduler action' );
as_unschedule_all_actions( VCAP\CatchUp::HOOK, null, 'vcap' );

// L3: deactivation stops only on sites without tables – an empty version (failed first installation) still
// suspends the coupons.
delete_option( VCAP\Install::OPTION_DB_VERSION );
VCAP\Install::deactivate_site();
vcap_inst_assert( 'draft' === get_post_status( $card->coupon_id ) && (int) get_option( VCAP\Install::DEACTIVATED_OPTION ) > 0, 'L3: deactivation with an empty database version still suspends balance coupons' );
VCAP\Install::activate_site();
vcap()->ensure_schedules();
vcap_inst_assert( 'publish' === get_post_status( $card->coupon_id ) && VCAP_DB_VERSION === get_option( VCAP\Install::OPTION_DB_VERSION ), 'L3: activation afterwards completes the version and publishes the coupons again' );
as_unschedule_all_actions( VCAP\CatchUp::HOOK, null, 'vcap' );

// Coupons with rules only Voucher Captain checks are suspended as well (first order only, referral, SKU rules).
$first_order = new WC_Coupon();
$first_order->set_code( 'vcap-inst-first-' . strtolower( wp_generate_password( 8, false, false ) ) );
$first_order->set_amount( 10 );
$first_order->update_meta_data( VCAP\FirstOrderCoupons::META, 'yes' );
$first_order->save();
// A deactivation that never got its activation (network deactivation stopped, update by upload after a rollback):
// the running plugin heals itself once the deactivation is older than 15 minutes.
VCAP\Install::deactivate_site();
vcap_inst_assert( 'draft' === get_post_status( $first_order->get_id() ) && 'draft' === get_post_status( $card->coupon_id ), 'deactivation suspends first-order coupons too' );
vcap()->ensure_schedules();
vcap_inst_assert( 'draft' === get_post_status( $card->coupon_id ), 'a deactivation that may still be running is left alone' );
update_option( VCAP\Install::DEACTIVATED_OPTION, time() - HOUR_IN_SECONDS );
vcap()->ensure_schedules();
vcap_inst_assert( 'publish' === get_post_status( $card->coupon_id ) && 'publish' === get_post_status( $first_order->get_id() ) && as_has_scheduled_action( 'vcap_daily_maintenance', array(), 'vcap' ), 'an active plugin publishes coupons of an unfinished deactivation again and schedules its tasks' );
wp_delete_post( $first_order->get_id(), true );
as_unschedule_all_actions( VCAP\CatchUp::HOOK, null, 'vcap' );
vcap_inst_assert( as_has_scheduled_action( VCAP\Delivery::RESTORE_HOOK, array( 'phase' => 'vouchers', 'after' => 0 ), 'vcap' ), 'the removed tasks are restored by an Action Scheduler action, not in the request' );
// What that action does, batch by batch (500 vouchers each), until the vouchers are done.
$restore = array( 'vouchers', 0 );
while ( $restore && 'vouchers' === $restore[0] ) {
	$restore = vcap()->delivery->restore_tasks( $restore[0], $restore[1] );
}
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
vcap_inst_assert( '1' === get_post_meta( $u_card->coupon_id, VCAP\Install::SUSPENDED_META, true ) && isset( $u_changed[ $u_card->coupon_id ] ) && ! isset( $u_changed[ $u_single->coupon_id ] ), 'the suspended coupons are marked for a reinstall' );
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
	delete_post_meta( (int) $u_id, VCAP\Install::SUSPENDED_META );
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
$inst_no_cache = did_action( 'vcap_no_cache' );
vcap_inst_assert( ! str_contains( do_shortcode( '[vcap_redeem]' ), 'vcap_redeem_nonce' ) && did_action( 'vcap_no_cache' ) === $inst_no_cache, 'the guest redeem form carries no nonce and keeps the page cacheable' );
wc_load_cart();
WC()->session->set( VCAP\Shortcodes::PREFILL_KEY, 'INST-CODE' );
do_shortcode( '[vcap_redeem]' );
WC()->session->set( VCAP\Shortcodes::PREFILL_KEY, null );
vcap_inst_assert( did_action( 'vcap_no_cache' ) > $inst_no_cache && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'the redeem form with a code of this visitor marks the page as not cacheable' );
$form = do_shortcode( '[vcap_balance]' );
vcap_inst_assert( ! str_contains( $form, 'vcap_balance_nonce' ), 'the guest balance form carries no nonce (cacheable)' );
$_POST = array( 'vcap_balance_code' => $card->code );
$html  = do_shortcode( '[vcap_balance]' );
$_POST = array();
vcap_inst_assert( str_contains( $html, 'vcap-balance-result' ), 'a guest balance check works without a nonce' );
vcap_inst_assert( wp_style_is( 'vcap-frontend', 'enqueued' ), 'shortcodes enqueue the storefront style where they render' );

// Blocks come from block.json with apiVersion 3. The storefront style is the editor style only: on the front end the
// render callbacks (the shortcodes) enqueue it where a block renders (checked above), not on every page.
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'vcap/redeem' );
vcap_inst_assert( $block && 3 === (int) $block->api_version && in_array( 'vcap-frontend', (array) $block->editor_style_handles, true ) && ! in_array( 'vcap-frontend', (array) $block->style_handles, true ), 'blocks are registered from block.json (apiVersion 3, storefront style in the editor)' );

// Site Health: the product checks run asynchronously through REST.
$health = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
vcap_inst_assert( isset( $health['async']['vcap_validity'], $health['async']['vcap_tax_status'] ) && ! isset( $health['direct']['vcap_validity'] ) && ! empty( $health['async']['vcap_validity']['has_rest'] ), 'the product checks are asynchronous Site Health tests' );
vcap_inst_assert( isset( rest_get_server()->get_routes()['/vcap/v1/site-health/validity'] ), 'the asynchronous checks have their REST route' );
vcap_inst_assert( isset( $health['async']['vcap_voucher_products'], rest_get_server()->get_routes()['/vcap/v1/site-health/voucher-products'] ) && ! isset( $health['direct']['vcap_voucher_products'] ) && ! empty( $health['async']['vcap_voucher_products']['has_rest'] ), 'A8: the voucher product test (shop problems) runs asynchronously through REST' );

// Free core (docs/FREE-PREMIUM.md): no license page or update handler, every feature on, no "Update URI" header.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$inst_header = get_plugin_data( VCAP_FILE, false, false );
vcap_inst_assert( '' === (string) ( $inst_header['UpdateURI'] ?? '' ) && ! defined( 'VCAP_EDITION' ) && ! defined( 'VCAP_LICENSE_REQUIRED' ), 'the plugin has no "Update URI" header and no edition constants' );
$inst_own = static function ( string $hook ): array {
	global $wp_filter;
	$found = array();
	foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && str_starts_with( is_object( $callback['function'][0] ) ? get_class( $callback['function'][0] ) : (string) $callback['function'][0], 'VCAP\\' ) ) {
				$found[] = $callback['function'];
			}
		}
	}
	return $found;
};
vcap_inst_assert( ! $inst_own( 'pre_set_site_transient_update_plugins' ) && ! $inst_own( 'plugins_api' ), 'no update handler of its own' );
vcap_inst_assert( (bool) $inst_own( 'vcap_account_vouchers_top' ) && count( $inst_own( 'vcap_product_settings_fields' ) ) >= 2 && (bool) $inst_own( 'vcap_scanner_lookup' ), 'referrals, top-up, ticket vouchers and the shared scanner are registered' );
vcap_inst_assert( ! method_exists( 'VCAP\\Admin\\ReportsAdmin', 'locked' ) && ! class_exists( 'VCAP\\Licensing\\Entitlements', false ) && ! is_dir( VCAP_PATH . 'src/Licensing' ), 'no locked report view and no license gate' );
$inst_locked = array();
foreach ( array( '', 'redemption', 'promotions', 'codes', 'delivery', 'privacy', 'api' ) as $inst_section ) {
	foreach ( vcap()->settings->fields( $inst_section ) as $inst_field ) {
		if ( isset( $inst_field['id'] ) && ( isset( $inst_field['custom_attributes']['disabled'] ) || ( array_key_exists( 'is_option', $inst_field ) && false === $inst_field['is_option'] ) ) ) {
			$inst_locked[] = $inst_field['id'];
		}
	}
}
vcap_inst_assert( ! array_filter( $inst_locked, static fn( $id ) => (bool) preg_match( '/^vcap_(campaigns_enabled|referral|passcaptain)/', $id ) ), 'no setting of promotions, referrals or Pass Captain is locked' );

// Upgrade from a licensed build: its options (site and network), WP-Cron event and Action Scheduler action are
// removed once; voucher data stays.
foreach ( VCAP\Install::LEGACY_LICENSE_OPTIONS as $inst_option ) {
	update_option( $inst_option, array( 'activation_id' => 7 ), false );
}
if ( is_multisite() ) {
	update_site_option( 'vcap_wplc_credentials', array( 'activation_id' => 8 ) );
}
set_site_transient( 'vcap_update_check', array( 'x' => 1 ), HOUR_IN_SECONDS );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', VCAP\Install::LEGACY_LICENSE_HOOK );
as_schedule_recurring_action( time() + 600, 12 * HOUR_IN_SECONDS, VCAP\Install::LEGACY_LICENSE_HOOK, array(), 'vcap' );
delete_option( VCAP\Install::LICENSE_CLEANUP_OPTION );
$inst_vouchers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['vouchers']}" ); // phpcs:ignore
VCAP\Install::maybe_upgrade();
$inst_left = array_filter( VCAP\Install::LEGACY_LICENSE_OPTIONS, static fn( $o ) => false !== get_option( $o ) );
vcap_inst_assert( ! $inst_left && ( ! is_multisite() || false === get_site_option( 'vcap_wplc_credentials' ) ) && false === get_site_transient( 'vcap_update_check' ), 'the upgrade removes the license options of earlier builds' );
vcap_inst_assert( ! wp_next_scheduled( VCAP\Install::LEGACY_LICENSE_HOOK ) && ! as_has_scheduled_action( VCAP\Install::LEGACY_LICENSE_HOOK ), 'the upgrade removes the license check event and action' );
vcap_inst_assert( (int) get_option( VCAP\Install::LICENSE_CLEANUP_OPTION ) > 0 && $inst_vouchers === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['vouchers']}" ), 'the cleanup runs once and keeps the vouchers' ); // phpcs:ignore
// Uninstall removes them as well, also when the data is kept.
update_option( 'vcap_wplc_credentials', array( 'activation_id' => 9 ), false );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', VCAP\Install::LEGACY_LICENSE_HOOK );
vcap_uninstall_license_data();
vcap_inst_assert( false === get_option( 'vcap_wplc_credentials' ) && ! wp_next_scheduled( VCAP\Install::LEGACY_LICENSE_HOOK ) && $inst_vouchers === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['vouchers']}" ), 'uninstall removes leftover license data and keeps the vouchers' ); // phpcs:ignore
update_option( VCAP\Install::LICENSE_CLEANUP_OPTION, time(), true );

// ---------------------------------------------------------------- P6: database and performance at scale.

// Schema 1.5.0: indexes for the daily maintenance, the delivery queries and the ledger; replaced indexes dropped.
$p6_voucher_idx = array_map( 'strtolower', array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$tables['vouchers']}", 2 ) ) ); // phpcs:ignore
$p6_event_idx   = array_map( 'strtolower', array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$tables['events']}", 2 ) ) ); // phpcs:ignore
$p6_pool_idx    = array_map( 'strtolower', array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$tables['pool']}", 2 ) ) ); // phpcs:ignore
vcap_inst_assert( ! array_diff( array( 'status_expires', 'status_updated', 'expires_at', 'delivery_due' ), $p6_voucher_idx ) && ! in_array( 'status', $p6_voucher_idx, true ), 'B6/B2: vouchers have the (status, expires_at), (status, updated_at), expires_at and (delivered_at, status, delivery_at) indexes; the plain status index is gone' );
vcap_inst_assert( ! array_diff( array( 'voucher_ledger', 'actor_email_hash' ), $p6_event_idx ) && ! in_array( 'voucher_id', $p6_event_idx, true ), 'B14/A16: the ledger has the covering (voucher_id, id, created_at, event, balance_after) index instead of the redundant voucher_id index, and the actor_email_hash index' );
vcap_inst_assert( in_array( 'batch_used', $p6_pool_idx, true ), 'A8: the code pool has the (batch, used_at) index' );
// The replaced index is dropped again on the next schema run (idempotent), never before its replacement exists.
$wpdb->query( "ALTER TABLE {$tables['events']} ADD INDEX voucher_id (voucher_id)" ); // phpcs:ignore
VCAP\Install::create_tables();
$p6_event_idx = array_map( 'strtolower', array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$tables['events']}", 2 ) ) ); // phpcs:ignore
vcap_inst_assert( ! in_array( 'voucher_id', $p6_event_idx, true ) && in_array( 'voucher_ledger', $p6_event_idx, true ) && array() === VCAP\Install::verify_schema(), 'A16: a schema run drops the replaced events.voucher_id index again and the schema stays complete' );
// The queries use them (possible keys of the optimizer).
$p6_explain = static function ( string $sql ) use ( $wpdb ): string {
	return strtolower( (string) implode( ',', array_column( (array) $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ), 'possible_keys' ) ) ); // phpcs:ignore
};
vcap_inst_assert( str_contains( $p6_explain( $wpdb->prepare( "SELECT id FROM {$tables['vouchers']} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s ORDER BY expires_at LIMIT 500", gmdate( 'Y-m-d H:i:s' ) ) ), 'status_expires' ), 'B6: EXPLAIN of the expiry batch query offers the (status, expires_at) index' ); // phpcs:ignore
vcap_inst_assert( str_contains( $p6_explain( $wpdb->prepare( "SELECT id FROM {$tables['events']} WHERE actor_email_hash = %s", hash( 'sha256', 'x@example.com' ) ) ), 'actor_email_hash' ), 'B5: EXPLAIN of the lookup by address hash offers the actor_email_hash index' ); // phpcs:ignore

// B6: overdue vouchers expire in batches of ids (no UPDATE over the whole active range).
$p6_overdue = array();
for ( $i = 0; $i < 3; $i++ ) {
	$p6_v             = vcap_inst_voucher( 'balance' );
	$p6_v->expires_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	vcap()->vouchers->update_columns( $p6_v, array( 'expires_at' ) );
	$p6_overdue[] = $p6_v->id;
}
$p6_queries = array();
$p6_log     = static function ( $q ) use ( &$p6_queries ) {
	$p6_queries[] = $q;
	return $q;
};
add_filter( 'query', $p6_log );
vcap()->vouchers->expire_overdue();
remove_filter( 'query', $p6_log );
$p6_updates = array_values( array_filter( $p6_queries, static fn( $q ) => str_starts_with( ltrim( $q ), 'UPDATE' ) && str_contains( $q, $tables['vouchers'] ) ) );
vcap_inst_assert( 'expired' === vcap()->vouchers->get( $p6_overdue[0] )->status && 'expired' === vcap()->vouchers->get( $p6_overdue[2] )->status, 'B6: overdue vouchers are expired' );
vcap_inst_assert( $p6_updates && ! array_filter( $p6_updates, static fn( $q ) => ! str_contains( $q, 'WHERE id IN (' ) ), 'B6: every expiry UPDATE is limited to a batch of ids (' . count( $p6_updates ) . ' statements)' );

// B2: retention anonymises in short batches by id and reports a failure instead of returning 0 silently.
$p6_old = vcap_inst_voucher( 'single' );
$wpdb->update( $tables['vouchers'], array( 'buyer_email' => 'p6-old@example.com', 'buyer_name' => 'Old Buyer', 'status' => 'redeemed', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $p6_old->id ) );
vcap()->vouchers->log( $p6_old->id, 'redeemed', 10, null, 'p6-old@example.com' );
$p6_new = vcap_inst_voucher( 'single' );
$wpdb->update( $tables['vouchers'], array( 'buyer_email' => 'p6-new@example.com' ), array( 'id' => $p6_new->id ) );
$p6_queries = array();
add_filter( 'query', $p6_log );
$p6_count = vcap()->vouchers->anonymise_older_than( 365 );
remove_filter( 'query', $p6_log );
$p6_old = vcap()->vouchers->get( $p6_old->id );
vcap_inst_assert( $p6_count >= 1 && '' === $p6_old->buyer_email && '' === $p6_old->buyer_name && 'p6-new@example.com' === vcap()->vouchers->get( $p6_new->id )->buyer_email, 'B2: retention removes the personal data of ended vouchers only' );
vcap_inst_assert( '[personal data removed by retention]' === $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$tables['events']} WHERE voucher_id = %d AND event = 'redeemed'", $p6_old->id ) ), 'B2: and redacts their ledger notes' ); // phpcs:ignore
$p6_updates = array_values( array_filter( $p6_queries, static fn( $q ) => str_starts_with( ltrim( $q ), 'UPDATE' ) ) );
vcap_inst_assert( $p6_updates && ! array_filter( $p6_updates, static fn( $q ) => ! preg_match( '/WHERE (v\.)?(voucher_)?id IN \(/', $q ) || str_contains( $q, 'INNER JOIN' ) ), 'B2: every retention UPDATE is limited to a batch of voucher ids (no UPDATE … JOIN over the whole tables)' );
$p6_fail = static function ( $q ) use ( $tables ) {
	return str_contains( $q, 'UPDATE ' . $tables['events'] ) ? 'UPDATE vcap_p6_missing_table SET x = 1' : $q;
};
$p6_victim = vcap_inst_voucher( 'single' );
$wpdb->update( $tables['vouchers'], array( 'buyer_email' => 'p6-fail@example.com', 'status' => 'void', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $p6_victim->id ) );
add_filter( 'query', $p6_fail );
$p6_suppress = $wpdb->suppress_errors( true );
$p6_thrown   = false;
try {
	vcap()->vouchers->anonymise_older_than( 365 );
} catch ( RuntimeException $e ) {
	$p6_thrown = true;
}
$wpdb->suppress_errors( $p6_suppress );
remove_filter( 'query', $p6_fail );
vcap_inst_assert( $p6_thrown && 'p6-fail@example.com' === vcap()->vouchers->get( $p6_victim->id )->buyer_email, 'B2: a failed batch is rolled back and thrown (recorded by the maintenance step), not reported as 0 anonymised' );
vcap()->vouchers->anonymise_older_than( 365 );

// B5: notes naming an address are found and erased through actor_email_hash; rows after the hash cursor (written
// without the hash, e.g. by an older version after a rollback) by their note.
$p6_mail = 'p6-user-' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.com';
$p6_card = vcap_inst_voucher( 'balance' );
vcap()->vouchers->log( $p6_card->id, 'redeemed', 5, 15, strtoupper( $p6_mail ) );
vcap_inst_assert( hash( 'sha256', $p6_mail ) === $wpdb->get_var( $wpdb->prepare( "SELECT actor_email_hash FROM {$tables['events']} WHERE voucher_id = %d AND event = 'redeemed'", $p6_card->id ) ), 'B5: log() stores the hash of a note that is an e-mail address' ); // phpcs:ignore
VCAP\Install::hash_event_notes( 5.0 );
$wpdb->insert( $tables['events'], array( 'voucher_id' => $p6_card->id, 'event' => 'redeemed', 'amount' => 5, 'balance_after' => 10, 'note' => $p6_mail, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$p6_raw     = (int) $wpdb->insert_id;
$p6_queries = array();
add_filter( 'query', $p6_log );
$p6_found = vcap()->vouchers->events_noting( $p6_mail );
remove_filter( 'query', $p6_log );
$p6_select = implode( "\n", array_filter( $p6_queries, static fn( $q ) => str_contains( $q, 'LOWER(note)' ) ) );
vcap_inst_assert( 2 === count( $p6_found ) && in_array( (string) $p6_raw, array_column( $p6_found, 'id' ), true ), 'B5: the export finds the hashed note and the note written without hash after the cursor' );
vcap_inst_assert( str_contains( $p6_select, 'actor_email_hash = ' ) && preg_match( '/id > \d+ AND LOWER\(note\)/', $p6_select ), 'B5: the note itself is compared only after the hash cursor (primary-key range), never over the whole ledger' );
VCAP\Install::hash_event_notes( 5.0 );
vcap_inst_assert( hash( 'sha256', $p6_mail ) === $wpdb->get_var( $wpdb->prepare( "SELECT actor_email_hash FROM {$tables['events']} WHERE id = %d", $p6_raw ) ) && (int) get_option( VCAP\Install::EVENT_HASH_CURSOR ) >= $p6_raw, 'B5: the maintenance fills the hash of rows written without it and moves the cursor' ); // phpcs:ignore
vcap_inst_assert( vcap()->vouchers->erase_event_notes( $p6_mail ) && array() === vcap()->vouchers->events_noting( $p6_mail ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['events']} WHERE actor_email_hash = %s", hash( 'sha256', $p6_mail ) ) ), 'B5: erasure redacts the notes and their hash' ); // phpcs:ignore

// B15: the newest entries of a history only.
for ( $i = 1; $i <= 30; $i++ ) {
	vcap()->vouchers->log( $p6_card->id, 'adjusted', 1, (float) $i, '' );
}
$p6_latest = vcap()->vouchers->events( $p6_card->id, 25 );
vcap_inst_assert( 25 === count( $p6_latest ) && 30.0 === (float) end( $p6_latest )['balance_after'] && (int) $p6_latest[0]['id'] < (int) $p6_latest[1]['id'], 'B15: events( id, 25 ) returns the newest 25 entries, oldest first' );
$p6_page1 = vcap()->vouchers->events_page( $p6_card->id, 10, 1 );
$p6_page2 = vcap()->vouchers->events_page( $p6_card->id, 10, 2 );
vcap_inst_assert( 10 === count( $p6_page1 ) && 30.0 === (float) $p6_page1[0]['balance_after'] && 20.0 === (float) $p6_page2[0]['balance_after'] && (int) $p6_page1[9]['id'] > (int) $p6_page2[0]['id'], 'B15: events_page() pages through the history, newest first' );
vcap_inst_assert( vcap()->vouchers->has_event( $p6_card->id, 'adjusted' ) && ! vcap()->vouchers->has_event( $p6_card->id, 'expiry_reminder' ), 'B15: has_event() answers without loading the history' );

// A5: in the migration window post meta is counted before the table: a batch moved in between is counted twice
// (limit reached early), never not at all.
$p6_promo = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'P6 race promotion' ) );
add_post_meta( $p6_promo, '_vcap_used_by', 'e:race@example.com' );
update_option( VCAP\Install::LEGACY_OPTION, time() );
$p6_counts = 0;
$p6_move   = static function ( $q ) use ( &$p6_counts, $p6_promo ) {
	if ( preg_match( '/^SELECT (COUNT\(\*\)|uses) FROM \S+ WHERE (post_id|campaign_id) = ' . $p6_promo . '\b/', $q ) && 2 === ++$p6_counts ) {
		// The migration moves the row after the first count, before the second.
		$batch = new ReflectionMethod( VCAP\Install::class, 'move_usage_batch' );
		$batch->setAccessible( true );
		$batch->invoke( null, 500 );
	}
	return $q;
};
add_filter( 'query', $p6_move );
$p6_race = VCAP\Discounts\Usage::count( $p6_promo );
remove_filter( 'query', $p6_move );
vcap_inst_assert( $p6_race >= 1 && 1 === VCAP\Discounts\Usage::count( $p6_promo ), 'A5: a use moved by the migration between the two counts is still counted (' . $p6_race . ')' );
delete_option( VCAP\Install::LEGACY_OPTION );

// A16: claims moved from options ("legacy") are purged after their own, longer period.
$p6_claims = $tables['claims'];
$wpdb->insert( $p6_claims, array( 'claim_key' => substr( md5( 'p6-legacy-old' ), 0, 24 ), 'kind' => 'legacy', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
$wpdb->insert( $p6_claims, array( 'claim_key' => substr( md5( 'p6-legacy-recent' ), 0, 24 ), 'kind' => 'legacy', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) );
VCAP\Claim::purge();
$p6_left = (array) $wpdb->get_col( $wpdb->prepare( "SELECT claim_key FROM {$p6_claims} WHERE claim_key IN (%s, %s)", substr( md5( 'p6-legacy-old' ), 0, 24 ), substr( md5( 'p6-legacy-recent' ), 0, 24 ) ) ); // phpcs:ignore
vcap_inst_assert( array( substr( md5( 'p6-legacy-recent' ), 0, 24 ) ) === $p6_left, 'A16: legacy claims older than a year are purged, younger ones kept' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p6_claims} WHERE claim_key = %s", substr( md5( 'p6-legacy-recent' ), 0, 24 ) ) ); // phpcs:ignore

// K2: orders by meta come from the order store in use; the classic store (posts) is searched by its meta, so
// more than 1000 old pending orders without the meta never hide the one that has it.
$p6_now    = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
$p6_values = array();
for ( $i = 0; $i < 1100; $i++ ) {
	$p6_values[] = $wpdb->prepare( "(%s, %s, 'shop_order', 'wc-pending', %s, %s, %s, %s, '', '', '', '', '')", 'P6 order', 'p6-order-' . $i, $p6_now, $p6_now, $p6_now, $p6_now );
}
$wpdb->query( "INSERT INTO {$wpdb->posts} (post_title, post_name, post_type, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_content, post_excerpt, to_ping, pinged, post_content_filtered) VALUES " . implode( ',', $p6_values ) ); // phpcs:ignore
$p6_first = (int) $wpdb->insert_id;
$wpdb->insert( $wpdb->posts, array( 'post_title' => 'P6 order with hold', 'post_name' => 'p6-order-hold', 'post_type' => 'shop_order', 'post_status' => 'wc-pending', 'post_date' => $p6_now, 'post_date_gmt' => $p6_now, 'post_modified' => $p6_now, 'post_modified_gmt' => $p6_now, 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
$p6_hold = (int) $wpdb->insert_id;
add_post_meta( $p6_hold, '_vcap_reservations', array( 'X' => 1 ) );
$p6_ids = VCAP\OrderLookup::ids_with_meta( '_vcap_reservations', null, array( 'status' => 'pending', 'modified_before' => time() - HOUR_IN_SECONDS, 'hpos' => false ) );
vcap_inst_assert( in_array( $p6_hold, $p6_ids, true ) && ! array_intersect( range( $p6_first, $p6_first + 1099 ), $p6_ids ), 'K2: in the classic order store the pending order with the hold is found behind 1100 older pending orders without it' );
vcap_inst_assert( array() === VCAP\OrderLookup::ids_with_meta( '_vcap_reservations', null, array( 'status' => 'pending', 'modified_before' => time() - 4 * HOUR_IN_SECONDS, 'hpos' => false ) ), 'K2: orders changed after the cutoff are left out' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $p6_hold ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID BETWEEN %d AND %d", $p6_first, $p6_hold ) ); // phpcs:ignore
if ( VCAP\OrderLookup::hpos() ) {
	$p6_order = wc_create_order();
	$p6_order->update_meta_data( '_vcap_reservations', array( 'Y' => 1 ) );
	$p6_order->save();
	vcap_inst_assert( in_array( $p6_order->get_id(), VCAP\OrderLookup::ids_with_meta( '_vcap_reservations', null, array( 'status' => 'pending' ) ), true ), 'K2: with HPOS the order tables are searched' );
	$p6_order->delete( true );
}

// B9: a word never matches vouchers without an order (order_id = 0); an address is searched by its start.
$p6_tag    = strtolower( wp_generate_password( 8, false, false ) );
$p6_plain  = vcap_inst_voucher( 'balance' ); // order_id 0
$p6_mailer = vcap_inst_voucher( 'single' );
$wpdb->update( $tables['vouchers'], array( 'buyer_email' => "mueller-{$p6_tag}@example.com", 'order_id' => 4711 ), array( 'id' => $p6_mailer->id ) );
$p6_hits = array_map( static fn( $v ) => $v->id, vcap()->vouchers->query( array( 'search' => "mueller-{$p6_tag}@example.com", 'per_page' => 100 ) )['items'] );
vcap_inst_assert( array( $p6_mailer->id ) === $p6_hits, 'B9: searching an address finds its voucher and no voucher without order' );
$p6_hits = array_map( static fn( $v ) => $v->id, vcap()->vouchers->query( array( 'search' => "mueller-{$p6_tag}", 'per_page' => 100 ) )['items'] );
vcap_inst_assert( array( $p6_mailer->id ) === $p6_hits && ! in_array( $p6_plain->id, $p6_hits, true ), 'B9: a word (not a number) does not list every voucher without order' );
vcap_inst_assert( in_array( $p6_mailer->id, array_map( static fn( $v ) => $v->id, vcap()->vouchers->query( array( 'search' => '4711', 'per_page' => 100 ) )['items'] ), true ), 'B9: a number still finds the vouchers of that order' );

// B8: the export reads keyset batches (id < last id, no OFFSET, no COUNT per batch) and sorts with id as tie-breaker.
$p6_queries = array();
add_filter( 'query', $p6_log );
$p6_exported = iterator_to_array( vcap()->vouchers->export( array(), 2 ), false );
remove_filter( 'query', $p6_log );
$p6_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['vouchers']}" ); // phpcs:ignore
$p6_ids   = array_map( static fn( $v ) => $v->id, $p6_exported );
$p6_desc  = $p6_ids;
rsort( $p6_desc );
vcap_inst_assert( $p6_total === count( $p6_ids ) && count( array_unique( $p6_ids ) ) === $p6_total && $p6_desc === $p6_ids, 'B8: the export lists every voucher exactly once, newest first (' . $p6_total . ')' );
$p6_selects = array_values( array_filter( $p6_queries, static fn( $q ) => str_contains( $q, "FROM {$tables['vouchers']}" ) ) );
vcap_inst_assert( ! preg_grep( '/OFFSET [1-9]/', $p6_selects ) && ! preg_grep( '/COUNT\(\*\)/', $p6_selects ) && preg_grep( '/id < \d+/', $p6_selects ), 'B8: export batches use id < last id, never OFFSET or a COUNT per batch' );
$p6_queries = array();
add_filter( 'query', $p6_log );
vcap()->vouchers->query( array( 'orderby' => 'balance', 'order' => 'ASC', 'per_page' => 5 ) );
remove_filter( 'query', $p6_log );
vcap_inst_assert( (bool) preg_grep( '/ORDER BY balance ASC, id ASC/', $p6_queries ), 'B8: list pages are sorted with id as tie-breaker (no row on two pages or none)' );

// AP-W1-1: the cashier role is stored in English (add_caps() runs too early to translate) and translated on display.
$w11_roles = wp_roles();
$w11_roles->roles[ VCAP\Install::CASHIER_ROLE ]['name'] = 'Gutschein-Kasse'; // Stored translated by an earlier version.
update_option( $w11_roles->role_key, $w11_roles->roles );
VCAP\Install::add_caps();
$w11_stored = get_option( $w11_roles->role_key )[ VCAP\Install::CASHIER_ROLE ]['name'] ?? '';
vcap_inst_assert( 'Voucher cashier' === $w11_stored && 'Voucher cashier' === wp_roles()->role_names[ VCAP\Install::CASHIER_ROLE ], 'AP-W1-1: add_caps() stores the cashier role with its English name, also over a name stored translated before' );
$w11_de = static fn( $translation, $text, $context ) => 'Voucher cashier' === $text && 'User role' === $context ? 'Gutschein-Kasse' : $translation;
add_filter( 'gettext_with_context_voucher-captain', $w11_de, 10, 3 );
$w11_shown = translate_user_role( $w11_stored );
remove_filter( 'gettext_with_context_voucher-captain', $w11_de, 10 );
vcap_inst_assert( 'Gutschein-Kasse' === $w11_shown && 'Shop manager' === translate_user_role( 'Shop manager' ), 'AP-W1-1: translate_user_role() shows the cashier role in the plugin translation (other roles untouched)' );

// AP-W1-2 / AP-Q2-7: an outdated theme copy of a voucher template (no @version, e.g. without the check-in key)
// is reported in Site Health; a current copy is not.
$w12_dir  = get_stylesheet_directory() . '/woocommerce/voucher-captain';
$w12_file = $w12_dir . '/ticket-status.php';
$w12_had  = file_exists( $w12_file );
if ( ! $w12_had ) {
	wp_mkdir_p( $w12_dir );
	file_put_contents( $w12_file, "<?php\n/**\n * Old copy.\n */\n" ); // phpcs:ignore
	$w12_list   = VCAP\Plugin::template_overrides();
	$w12_health = ( new VCAP\Health() )->test_template_overrides();
	vcap_inst_assert( 1 === count( $w12_list ) && 'ticket-status.php' === $w12_list[0]['file'] && $w12_list[0]['outdated'] && '1.2.0' === $w12_list[0]['core_version'] && 'recommended' === $w12_health['status'] && str_contains( $w12_health['description'], 'ticket-status.php' ), 'AP-W1-2: a theme copy of ticket-status.php without @version is reported as outdated in Site Health' );
	file_put_contents( $w12_file, "<?php\n/**\n * Current copy.\n *\n * @version 1.2.0\n */\n" ); // phpcs:ignore
	$w12_list = VCAP\Plugin::template_overrides();
	vcap_inst_assert( 1 === count( $w12_list ) && ! $w12_list[0]['outdated'] && 'good' === ( new VCAP\Health() )->test_template_overrides()['status'], 'AP-W1-2: a theme copy with the current @version is not reported' );
	wp_delete_file( $w12_file );
	@rmdir( $w12_dir ); // phpcs:ignore
	@rmdir( dirname( $w12_dir ) ); // phpcs:ignore
}
$w12_paths = (array) apply_filters( 'woocommerce_template_overrides_scan_paths', array() );
vcap_inst_assert( VCAP_PATH . 'templates/' === ( $w12_paths['Voucher Captain'] ?? '' ), 'AP-W1-2: woocommerce_template_overrides_scan_paths lists the plugin templates' );

echo "Voucher Captain install checks passed.\n";
