<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Database schema, capabilities and versioned migrations.
 *
 * Migration strategy: VCAP_DB_VERSION is bumped whenever the schema changes; dbDelta() is idempotent
 * and adds new columns/indexes. Data migrations are registered in self::MIGRATIONS keyed by the version
 * that introduced them and run exactly once, in order.
 */
final class Install {
	public const OPTION_DB_VERSION = 'vcap_db_version';
	/** Set while the database schema is incomplete (cleared once an upgrade verified it). */
	public const SCHEMA_ERROR_OPTION = 'vcap_schema_error';
	/** Upgrade lock (options row holding its expiry time). */
	public const UPGRADE_LOCK = 'vcap_upgrade_lock';
	/** Marker of Plugin::ensure_schedules() (deleted to have the recurring tasks checked again). */
	public const SCHEDULES_OPTION = 'vcap_schedules_checked';
	/** Whether the ledger tables are InnoDB ('yes'/'no'), stored by verify_schema(). */
	public const INNODB_OPTION = 'vcap_tables_innodb';
	/** Set on activation: tasks removed on deactivation are scheduled again (Plugin::ensure_schedules()). */
	public const RESTORE_TASKS_OPTION = 'vcap_restore_tasks';
	/** Time of the deactivation of this site (cleared on activation; orders since then are caught up, see CatchUp). */
	public const DEACTIVATED_OPTION = 'vcap_deactivated_at';
	/** Orders to catch up after a reactivation: array{since:int, until:int} (queued by Plugin::ensure_schedules()). */
	public const CATCH_UP_OPTION = 'vcap_catch_up';
	/** Network option: time a network deactivation last worked on a site (no self-healing while it runs). */
	public const NETWORK_DEACTIVATING_OPTION = 'vcap_network_deactivating';
	/** Marker (post meta) of a coupon set to draft by suspend_balance_coupons(); only these are published again. */
	public const SUSPENDED_META = '_vcap_suspended';
	/** Schema step whose tables were created and verified ("{from}>{to}"); the data migrations continue without DDL. */
	public const SCHEMA_STEP_OPTION = 'vcap_schema_step';
	/** Action Scheduler action that continues an upgrade outside shop requests. */
	public const MIGRATE_HOOK = 'vcap_migrate';
	/** Sites a network activation sets up at once; the others are set up on their first request (maybe_upgrade()). */
	public const NETWORK_ACTIVATION_SITES = 25;
	/** PHP extensions without which Voucher Captain cannot work (PDF rendering, text handling). */
	public const REQUIRED_EXTENSIONS = array( 'mbstring', 'dom' );
	/** PHP extensions some features need: QR codes, barcodes and images in PDFs (gd), spreadsheet import (zip). */
	public const RECOMMENDED_EXTENSIONS = array( 'gd', 'zip', 'iconv' );
	private const LOCK_SECONDS = 300;
	/** @var array<int, bool> InnoDB state per site (switch_to_blog() changes the tables). */
	private static array $transactional_tables = array();
	private static ?string $lock_value = null;

	/**
	 * Data migrations by the schema version that introduced them. A migration returns false while work is left
	 * (time budget), so it continues on the next request before the version is stored.
	 *
	 * @var array<string, callable-string>
	 */
	private const MIGRATIONS = array(
		'1.4.0' => 'migrate_1_4_0',
	);
	/** Schema version of the own storage in use (promotion usage, claims, delivery state per role). */
	public const STORAGE_OPTION = 'vcap_storage_version';
	/** Time the storage switched while rows were still in post meta / options (cleared once they all moved). */
	public const LEGACY_OPTION = 'vcap_legacy_rows';

	/**
	 * Activation hook. Network activation sets up the first NETWORK_ACTIVATION_SITES sites at once (each site has
	 * its own tables, options and coupons; more would risk a timeout of the activation request); the other sites,
	 * and sites added later, are set up on their first request by maybe_upgrade().
	 */
	public static function activate( $network_wide = false ): void {
		$missing = self::missing_extensions( self::REQUIRED_EXTENSIONS );
		if ( $missing ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: comma separated PHP extension names */
						__( 'Voucher Captain needs the PHP extensions %s, which are not available on this server. Please ask your host to enable them, then activate the plugin again.', 'voucher-captain' ),
						implode( ', ', $missing )
					)
				),
				esc_html__( 'Plugin could not be activated', 'voucher-captain' ),
				array( 'back_link' => true )
			);
		}
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'activate_site' ), self::NETWORK_ACTIVATION_SITES );
			return;
		}
		self::activate_site();
	}

	/**
	 * PHP extensions of $extensions that are not loaded.
	 *
	 * @param string[] $extensions Extension names.
	 * @return string[]
	 */
	public static function missing_extensions( array $extensions ): array {
		return array_values( array_filter( $extensions, static fn( string $ext ): bool => ! extension_loaded( $ext ) ) );
	}

	/**
	 * Deactivation hook. A network deactivation must reach every site: without the plugin, balance coupons of a
	 * site that was skipped would stay usable for their full balance on every order (see suspend_balance_coupons()).
	 * Each site is idempotent, so a network deactivation that stopped (time-out) can simply be run again; a site it
	 * already handled heals itself while the plugin stays active (Plugin::ensure_schedules(), resume()).
	 */
	public static function deactivate( $network_wide = false ): void {
		// Cached update check of the premium updater (network-wide; Licensing\PremiumUpdater cache).
		delete_site_transient( 'vcap_update_check' );
		if ( is_multisite() && $network_wide ) {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions -- large networks, best effort.
			}
			self::for_each_site(
				static function (): void {
					// Heartbeat: sites handled already do not heal themselves while this request still runs.
					update_site_option( self::NETWORK_DEACTIVATING_OPTION, time() );
					self::deactivate_site();
				}
			);
			delete_site_option( self::NETWORK_DEACTIVATING_OPTION );
			return;
		}
		self::deactivate_site();
	}

	/** Whether a network deactivation worked on a site in the last 15 minutes (it may still be running). */
	public static function network_deactivation_running(): bool {
		return is_multisite() && (int) get_site_option( self::NETWORK_DEACTIVATING_OPTION, 0 ) > time() - 15 * MINUTE_IN_SECONDS;
	}

	/**
	 * Runs a callback in the context of each site of the network, in batches of 100 sites.
	 *
	 * @param int $limit Maximum number of sites (0 = all).
	 */
	private static function for_each_site( callable $callback, int $limit = 0 ): void {
		$batch  = 100;
		$offset = 0;
		do {
			$number = $limit > 0 ? min( $batch, $limit - $offset ) : $batch;
			if ( $number <= 0 ) {
				break;
			}
			$ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => $number,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'order'                  => 'ASC',
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			$offset += count( $ids );
		} while ( count( $ids ) === $number );
	}

	public static function activate_site(): void {
		// Schema work only under the upgrade lock; when another request holds it, that request finishes the schema.
		$locked = self::lock();
		try {
			if ( $locked ) {
				$installed = self::stored_version();
				// After a downgrade the newer schema stays as it is (see maybe_upgrade()).
				if ( '' === $installed || version_compare( $installed, VCAP_DB_VERSION, '<=' ) ) {
					delete_option( self::SCHEMA_STEP_OPTION ); // Activation always checks the tables again.
					self::upgrade_schema( $installed );
				}
			}
			if ( ! get_option( 'vcap_secret' ) ) {
				update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
			}
			add_option( 'vcap_needs_default_template', 'yes' );
			update_option( 'vcap_flush_rewrite', 'yes' );
			self::resume();
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}
	}

	/**
	 * Undoes a deactivation of this site: publishes the suspended coupons, has the removed tasks scheduled again
	 * and the orders of the time in between caught up (both on the next request, Plugin::ensure_schedules()).
	 * Called on activation, and on a request of an active plugin that finds a deactivation which never completed
	 * (network deactivation stopped half-way) or was never followed by an activation hook (update by upload after
	 * a rollback): Plugin::ensure_schedules().
	 */
	public static function resume(): void {
		$since = (int) get_option( self::DEACTIVATED_OPTION, 0 );
		if ( $since > 0 ) {
			update_option( self::CATCH_UP_OPTION, array( 'since' => $since, 'until' => time() ), false );
			delete_option( self::DEACTIVATED_OPTION );
		}
		// Recurring tasks and the tasks removed on deactivation are scheduled on the next request.
		delete_option( self::SCHEDULES_OPTION );
		update_option( self::RESTORE_TASKS_OPTION, 'yes', false );
		self::restore_balance_coupons();
	}

	/** Whether coupons suspended by a deactivation (or an uninstall that kept the data) are still drafts. */
	public static function has_suspended_coupons(): bool {
		global $wpdb;
		return (bool) get_option( self::SUSPENDED_OPTION ) || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::SUSPENDED_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
	}

	public static function deactivate_site(): void {
		if ( ! self::table_exists( self::tables()['vouchers'] ) ) {
			return; // Never set up on this site (network activation reaches other sites lazily): no coupons, no tasks.
		}
		// Pending tasks would fail without the plugin: remove the whole group (deliveries, retries, reservation
		// releases, recurring maintenance); activation schedules them again (Delivery::restore_tasks()).
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Delivery::GROUP );
			as_unschedule_all_actions( 'vcap_daily_maintenance' );
			as_unschedule_all_actions( 'vcap_release_stale_order_reservations' );
		}
		wp_clear_scheduled_hook( Licensing\Entitlements::REFRESH_HOOK );
		delete_option( self::SCHEDULES_OPTION );
		// The first deactivation counts (a network deactivation run again keeps it): orders since then are caught up.
		add_option( self::DEACTIVATED_OPTION, time() );
		self::suspend_balance_coupons();
	}

	/** Whether a table exists (one SHOW TABLES). */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Ids of coupons drafted by releases before the per-coupon marker (and their uninstall), read on restore only. */
	public const SUSPENDED_OPTION = 'vcap_suspended_coupons';

	/**
	 * Takes the coupons out of use that would be wrong without the plugin, by setting them to draft (WooCommerce
	 * refuses them) until reactivation:
	 * - gift cards and store credit: coupons without a usage limit whose amount is the remaining balance; only
	 *   Voucher Captain deducts that balance, without it every code would take its full balance off every order;
	 * - vouchers that may not be redeemed in the shop (redemption channels of their order line);
	 * - coupons with rules only Voucher Captain checks: "First order only", referral codes (not with their owner),
	 *   SKU rules.
	 * Each coupon gets the marker SUSPENDED_META first; only a coupon whose marker was written is set to draft, so a
	 * reactivation finds every one of them (no list in one option that could grow beyond what MySQL accepts).
	 *
	 * @param int[]|null $ids Coupons to suspend (default: suspendable_coupon_ids()).
	 * @return int Coupons suspended.
	 */
	public static function suspend_balance_coupons( ?array $ids = null ): int {
		global $wpdb;
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ?? self::suspendable_coupon_ids() ) ) ) );
		$done = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in     = implode( ',', $chunk );
			$values = implode( ',', array_map( static fn( int $id ): string => $wpdb->prepare( '(%d, %s, %s)', $id, self::SUSPENDED_META, '1' ), $chunk ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key = %s", self::SUSPENDED_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			if ( false === $wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES {$values}" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- prepared rows.
				Logger::error( 'Deactivation: coupons could not be marked as suspended and stay published.', array( 'coupons' => count( $chunk ) ) );
				continue;
			}
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE ID IN ({$in}) AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			foreach ( $chunk as $id ) {
				clean_post_cache( $id );
			}
			$done += count( $chunk );
		}
		if ( $done ) {
			self::flush_coupon_cache();
		}
		return $done;
	}

	/**
	 * Published coupons that suspend_balance_coupons() takes out of use (see there).
	 *
	 * @return int[]
	 */
	public static function suspendable_coupon_ids(): array {
		global $wpdb;
		$vouchers = self::tables()['vouchers'];
		$ids      = array();
		$suppress = $wpdb->suppress_errors( true );
		if ( self::table_exists( $vouchers ) ) {
			$ids = (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$vouchers} v ON v.coupon_id = p.ID WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND v.usage_mode = 'balance'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Vouchers with redemption channels (order line "_vcap_line"): suspended unless the shop is one of them.
			$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
			$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, im.meta_value FROM {$wpdb->posts} p INNER JOIN {$vouchers} v ON v.coupon_id = p.ID INNER JOIN {$itemmeta} im ON im.order_item_id = v.order_item_id AND im.meta_key = %s WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND v.order_item_id > 0 AND v.usage_mode <> 'balance' AND im.meta_value LIKE %s", Issuer::LINE_META, '%' . $wpdb->esc_like( 's:8:"channels";a:' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$line = maybe_unserialize( (string) $row['meta_value'] );
				if ( is_array( $line ) && ! empty( $line['channels'] ) && ! in_array( Channels::SHOP, (array) $line['channels'], true ) ) {
					$ids[] = $row['ID'];
				}
			}
		}
		$ids = array_merge(
			$ids,
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND ( ( m.meta_key = %s AND m.meta_value = 'yes' ) OR ( m.meta_key = %s AND m.meta_value NOT IN ('', '0') ) OR ( m.meta_key IN (%s, %s) AND m.meta_value <> '' ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					FirstOrderCoupons::META,
					Referrals::OWNER,
					CouponSkuRules::META,
					CouponSkuRules::META_EXCLUDE
				)
			)
		);
		$wpdb->suppress_errors( $suppress );
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Fail-safe while the plugin is active but does not boot (e.g. WooCommerce older than required): balance
	 * coupons are refused at checkout, because nothing would deduct their balance. Registered by the version gate
	 * in voucher-captain.php only; with the plugin running, CouponManager validates these coupons instead.
	 *
	 * @param bool       $valid  Validity so far.
	 * @param \WC_Coupon $coupon Coupon.
	 * @throws \Exception For a gift card or store credit coupon (WooCommerce shows the message).
	 */
	public static function refuse_balance_coupon( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $coupon->get_id() || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return $valid;
		}
		if ( self::is_balance_coupon( (int) $coupon->get_id() ) ) {
			throw new \Exception( esc_html__( 'Gift cards and store credit cannot be redeemed at the moment. Please try again later or contact the shop.', 'voucher-captain' ), 9090 );
		}
		return $valid;
	}

	/** Whether a coupon belongs to a gift card or store credit account (one indexed lookup). */
	public static function is_balance_coupon( int $coupon_id ): bool {
		global $wpdb;
		$table    = self::tables()['vouchers'];
		$suppress = $wpdb->suppress_errors( true );
		$mode     = $wpdb->get_var( $wpdb->prepare( "SELECT usage_mode FROM {$table} WHERE coupon_id = %d LIMIT 1", $coupon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		return 'balance' === $mode;
	}

	/** WooCommerce caches "code → coupon id"; a cached id would keep a suspended coupon usable. */
	private static function flush_coupon_cache(): void {
		if ( class_exists( '\WC_Cache_Helper' ) && method_exists( '\WC_Cache_Helper', 'invalidate_cache_group' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'coupons' );
		}
	}

	/**
	 * Publishes the coupons suspended on deactivation again: only those with the marker SUSPENDED_META, never
	 * coupons drafted by hand. The id list of earlier releases ("vcap_suspended_coupons") is read as well, but
	 * only ids that still belong to a gift card or store credit coupon count – a list left over in an object cache
	 * after an uninstall must not publish coupons whose voucher is gone.
	 */
	public static function restore_balance_coupons(): void {
		global $wpdb;
		$changed = false;
		$legacy  = array_values( array_filter( array_map( 'intval', (array) get_option( self::SUSPENDED_OPTION, array() ) ) ) );
		if ( $legacy && self::table_exists( self::tables()['vouchers'] ) ) {
			$vouchers = self::tables()['vouchers'];
			foreach ( array_chunk( $legacy, 500 ) as $chunk ) {
				$valid = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT coupon_id FROM {$vouchers} WHERE usage_mode = 'balance' AND coupon_id IN (" . implode( ',', $chunk ) . ')' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
				$changed = self::publish_drafts( $valid ) || $changed;
			}
		}
		delete_option( self::SUSPENDED_OPTION );
		$after = 0;
		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id LIMIT 500", self::SUSPENDED_META, $after ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
			if ( ! $ids ) {
				break;
			}
			$changed = self::publish_drafts( $ids ) || $changed;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key = %s', self::SUSPENDED_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
			$after = (int) end( $ids );
		} while ( 500 === count( $ids ) );
		if ( $changed ) {
			self::flush_coupon_cache();
		}
	}

	/**
	 * Publishes the given coupons that are drafts.
	 *
	 * @param int[] $ids Coupon ids.
	 */
	private static function publish_drafts( array $ids ): bool {
		global $wpdb;
		if ( ! $ids ) {
			return false;
		}
		$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE ID IN (" . implode( ',', $ids ) . ") AND post_type = 'shop_coupon' AND post_status = 'draft'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		return true;
	}

	/**
	 * Brings the schema of this site to VCAP_DB_VERSION. Runs on every request until the stored version matches,
	 * but only one request at a time does the work (upgrade lock); the others carry on with the old schema.
	 * The tables are created and verified once per upgrade (SCHEMA_STEP_OPTION; the new code needs them at once),
	 * the data migrations then continue in batches without DDL – only in admin, cron and WP-CLI requests and the
	 * Action Scheduler action MIGRATE_HOOK, which shop requests (front end, REST) queue instead of migrating. A newer stored version (the plugin was downgraded) is never lowered:
	 * migrations would otherwise run twice after the next update. Administrators see a notice instead.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION );
		if ( VCAP_DB_VERSION === $installed ) {
			return;
		}
		if ( '' !== $installed && version_compare( $installed, VCAP_DB_VERSION, '>' ) ) {
			add_action( 'admin_notices', array( self::class, 'downgrade_notice' ) );
			return;
		}
		if ( get_transient( 'vcap_schema_retry' ) ) {
			return;
		}
		$background = self::background_context();
		if ( '' !== $installed && ! $background && get_option( self::SCHEMA_STEP_OPTION ) === $installed . '>' . VCAP_DB_VERSION ) {
			self::queue_migration(); // Tables are ready; the data migration continues in the background.
			return;
		}
		if ( ! self::lock() ) {
			return;
		}
		try {
			// The request that held the lock before may have finished the upgrade meanwhile.
			$installed = self::stored_version();
			if ( VCAP_DB_VERSION === $installed || ( '' !== $installed && version_compare( $installed, VCAP_DB_VERSION, '>' ) ) ) {
				return;
			}
			if ( '' === $installed && ! get_option( self::SCHEMA_ERROR_OPTION ) ) {
				// Never activated on this site (a network-activated plugin on a new or not yet visited site).
				self::unlock();
				self::activate_site();
				return;
			}
			// An upgrade, or a first installation whose tables could not be completed: only the schema is retried
			// (no rewrite flush, no task or coupon restore on every attempt).
			if ( ! self::upgrade_schema( $installed, '' === $installed || $background ) && '' !== $installed ) {
				self::queue_migration();
			}
		} finally {
			self::unlock();
		}
	}

	/** Whether this request may do schema and migration work (admin, cron, Action Scheduler, WP-CLI). */
	private static function background_context(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/** Has the upgrade continued by an Action Scheduler action (queued at most every five minutes). */
	private static function queue_migration(): void {
		if ( get_transient( 'vcap_migrate_queued' ) ) {
			return;
		}
		$queue = static function (): void {
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::MIGRATE_HOOK, array(), Delivery::GROUP ) ) {
				as_enqueue_async_action( self::MIGRATE_HOOK, array(), Delivery::GROUP );
			}
			set_transient( 'vcap_migrate_queued', 1, 5 * MINUTE_IN_SECONDS );
		};
		// Action Scheduler accepts actions once its data store is initialised (init).
		did_action( 'init' ) ? $queue() : add_action( 'init', $queue, 30 );
	}

	/** Action MIGRATE_HOOK: continues the upgrade; plans itself again (in a minute) while work is left. */
	public static function run_queued_migration(): void {
		delete_transient( 'vcap_migrate_queued' );
		self::maybe_upgrade();
		if ( VCAP_DB_VERSION !== self::stored_version() && ! get_transient( 'vcap_schema_retry' ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::MIGRATE_HOOK, array(), Delivery::GROUP );
			set_transient( 'vcap_migrate_queued', 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Schema work of an activation or upgrade (under the upgrade lock): tables created and verified once per
	 * upgrade step, then the data migrations; the version is stored when both are complete.
	 *
	 * @param bool $migrate Whether this request runs the data migrations (not in shop requests).
	 * @return bool Whether the schema is complete now.
	 */
	private static function upgrade_schema( string $installed, bool $migrate = true ): bool {
		$step = $installed . '>' . VCAP_DB_VERSION;
		if ( get_option( self::SCHEMA_STEP_OPTION ) !== $step ) {
			self::create_tables();
			self::add_caps();
			self::ensure_upload_dir();
			if ( ! get_option( 'vcap_secret' ) ) {
				update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
			}
			// Recurring tasks are checked again after an update (see Plugin::ensure_schedules()).
			delete_option( self::SCHEDULES_OPTION );
			$problems = self::verify_schema();
			if ( $problems ) {
				// Reported to administrators and retried in five minutes; the version stays, so nothing is skipped.
				update_option( self::SCHEMA_ERROR_OPTION, implode( '; ', $problems ), false );
				set_transient( 'vcap_schema_retry', 1, 5 * MINUTE_IN_SECONDS );
				return false;
			}
			delete_option( self::SCHEMA_ERROR_OPTION );
			update_option( self::SCHEMA_STEP_OPTION, $step, false );
		}
		// A data migration that stopped after its time budget continues on the next run.
		$pending = '' !== $installed && array_filter( array_keys( self::MIGRATIONS ), static fn( $version ): bool => version_compare( $installed, (string) $version, '<' ) );
		if ( ( $pending && ! $migrate ) || ! self::run_migrations( $installed ) ) {
			return false;
		}
		$stored = self::stored_version();
		if ( '' === $stored || version_compare( $stored, VCAP_DB_VERSION, '<' ) ) {
			// Never lowered (downgrade), see maybe_upgrade().
			update_option( self::OPTION_DB_VERSION, VCAP_DB_VERSION );
		}
		delete_option( self::SCHEMA_STEP_OPTION );
		return true;
	}

	/**
	 * Runs the data migrations newer than $installed, in order.
	 *
	 * @return bool False while a migration still has work left (it continues on the next request).
	 */
	private static function run_migrations( string $installed ): bool {
		if ( '' === $installed ) {
			// New installation: nothing to migrate, the tables of the current schema are used right away.
			update_option( self::STORAGE_OPTION, VCAP_DB_VERSION );
			return true;
		}
		foreach ( self::MIGRATIONS as $version => $method ) {
			if ( version_compare( $installed, $version, '<' ) && false === self::$method() ) {
				return false;
			}
		}
		return true;
	}

	/** The stored schema version, read from the database (the options cache can be stale under the lock). */
	private static function stored_version(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_DB_VERSION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Upgrade lock: one options row created with INSERT IGNORE (only one request succeeds), holding its expiry
	 * time. A lock left behind by a request that died is taken over once it has expired.
	 */
	private static function lock(): bool {
		global $wpdb;
		$now    = time();
		$expiry = (string) ( $now + self::LOCK_SECONDS );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::UPGRADE_LOCK, $expiry ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$taken = 1 === (int) $wpdb->rows_affected;
		if ( ! $taken ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $expiry, self::UPGRADE_LOCK, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$taken = 1 === (int) $wpdb->rows_affected;
		}
		if ( $taken ) {
			self::$lock_value = $expiry;
			wp_cache_delete( self::UPGRADE_LOCK, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return $taken;
	}

	/** Gives the upgrade lock back (only the lock this request holds). */
	private static function unlock(): bool {
		global $wpdb;
		if ( null === self::$lock_value ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::UPGRADE_LOCK, self::$lock_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::$lock_value = null;
		wp_cache_delete( self::UPGRADE_LOCK, 'options' );
		return true;
	}

	/** The database was updated by a newer Voucher Captain than the one running now. */
	public static function downgrade_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html(
			sprintf(
				/* translators: 1: database version in use, 2: database version of the running plugin */
				__( 'Voucher Captain: the voucher database was updated by a newer plugin version (database version %1$s; this version expects %2$s). The data is left unchanged. Install the newer version again, or restore the backup taken before the update together with this version.', 'voucher-captain' ),
				(string) get_option( self::OPTION_DB_VERSION ),
				VCAP_DB_VERSION
			)
		) . '</p></div>';
	}

	/**
	 * Table names of the current site (read on every call, never kept: switch_to_blog() changes the prefix).
	 *
	 * @param string|null $prefix Table prefix of another site (default: the current one).
	 */
	public static function tables( ?string $prefix = null ): array {
		global $wpdb;
		$prefix = $prefix ?? $wpdb->prefix;
		return array(
			'vouchers' => $prefix . 'vcap_vouchers',
			'events'   => $prefix . 'vcap_events',
			'pool'     => $prefix . 'vcap_code_pool',
			'redemptions' => $prefix . 'vcap_redemptions',
			'usage'       => $prefix . 'vcap_promotion_usage',
			'claims'      => $prefix . 'vcap_claims',
		);
	}

	/**
	 * Multisite: a deleted site takes its voucher tables with it (filter "wpmu_drop_tables").
	 *
	 * @param string[] $tables  Tables WordPress drops.
	 * @param int      $site_id Site being deleted.
	 * @return string[]
	 */
	public static function drop_site_tables( $tables, $site_id = 0 ): array {
		global $wpdb;
		$tables = (array) $tables;
		if ( (int) $site_id > 0 ) {
			$tables = array_values( array_unique( array_merge( $tables, array_values( self::tables( $wpdb->get_blog_prefix( (int) $site_id ) ) ) ) ) );
		}
		return $tables;
	}

	/**
	 * Name of a MySQL named lock (GET_LOCK) for this site: named locks are server-wide, so the database name and the
	 * table prefix keep sites of a network and shops sharing a MySQL server apart. At most 64 characters.
	 */
	public static function lock_name( string $name ): string {
		global $wpdb;
		$scope = substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 12 );
		return substr( 'vcap_' . $scope . '_' . $name, 0, 64 );
	}

	/**
	 * Whether the tables of schema 1.4 hold promotion usage, claims and the delivery state (set when they exist;
	 * before that, e.g. while another request still runs the upgrade, the old storage is used).
	 */
	public static function storage_ready(): bool {
		return version_compare( (string) get_option( self::STORAGE_OPTION, '0' ), '1.4.0', '>=' );
	}

	/** Whether rows of the old storage may still exist (read as well until moved). */
	public static function legacy_rows(): bool {
		return (int) get_option( self::LEGACY_OPTION, 0 ) > 0;
	}

	/**
	 * 1.4.0: promotion uses ("_vcap_used_by" post meta, one row per use) and claims ("_vcap_claim_*" options) move
	 * into their own tables. New rows go to the tables from now on; the old rows are moved in batches.
	 */
	private static function migrate_1_4_0(): bool {
		global $wpdb;
		$tables = self::tables();
		foreach ( array( 'usage', 'claims' ) as $key ) {
			if ( $tables[ $key ] !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tables[ $key ] ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false; // Never switch to tables that could not be created (upgrade_schema() reports them).
			}
		}
		// Also after a rollback to an older version, which wrote the old way again: read and move those rows.
		if ( ! self::legacy_rows() ) {
			update_option( self::LEGACY_OPTION, time() );
		}
		if ( ! self::storage_ready() ) {
			update_option( self::STORAGE_OPTION, '1.4.0' );
			delete_option( 'vcap_stale_release_cursor' ); // The hourly sweep reads order meta now, not the ledger.
		}
		// Storefront requests spend little time on it; admin, cron and WP-CLI more.
		return self::move_legacy_rows( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ? 8.0 : 2.0 );
	}

	/**
	 * Moves rows of the old storage into the tables until none are left or the time budget is used up. The
	 * daily maintenance calls it again, so rows a request wrote just before the switch are moved as well.
	 *
	 * @param float $budget Seconds.
	 * @return bool True when nothing is left.
	 */
	public static function move_legacy_rows( float $budget ): bool {
		if ( ! self::storage_ready() || ! self::legacy_rows() ) {
			return true;
		}
		$start = microtime( true );
		do {
			$usage  = self::move_usage_batch( 500 );
			$claims = self::move_claim_batch( 500 );
			if ( $usage < 0 || $claims < 0 ) {
				return false;
			}
			if ( 0 === $usage && 0 === $claims ) {
				// Requests that started before the switch may still write the old way: keep reading it for an hour.
				if ( time() - (int) get_option( self::LEGACY_OPTION, 0 ) > HOUR_IN_SECONDS ) {
					delete_option( self::LEGACY_OPTION );
				}
				return true;
			}
		} while ( microtime( true ) - $start < $budget );
		return false;
	}

	/**
	 * move_legacy_rows() for the daily maintenance: only under the upgrade lock, so it never runs alongside the
	 * migration (rows moved twice would count promotion uses twice). Skipped while another request holds the lock.
	 */
	public static function move_legacy_rows_locked( float $budget ): bool {
		if ( ! self::storage_ready() || ! self::legacy_rows() ) {
			return true;
		}
		if ( ! self::lock() ) {
			return true; // The request holding the lock moves them.
		}
		try {
			return self::move_legacy_rows( $budget );
		} finally {
			self::unlock();
		}
	}

	/** @return int Rows moved, -1 on a database error. */
	private static function move_usage_batch( int $limit ): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id LIMIT %d", '_vcap_used_by', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
		if ( ! $rows ) {
			return 0;
		}
		$now    = gmdate( 'Y-m-d H:i:s' );
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$values[] = '(%d, %s, 0, %s)';
			array_push( $args, (int) $row['post_id'], substr( (string) $row['meta_value'], 0, 200 ), $now );
		}
		$ids   = implode( ',', array_map( 'intval', array_column( $rows, 'meta_id' ) ) );
		$table = self::tables()['usage'];
		$ok    = self::in_transaction(
			static fn() => false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (campaign_id, customer_key, order_id, created_at) VALUES " . implode( ',', $values ), $args ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				&& false !== $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids})" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		);
		foreach ( array_unique( array_map( 'intval', array_column( $rows, 'post_id' ) ) ) as $post_id ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
		return $ok ? count( $rows ) : -1;
	}

	/** @return int Rows moved, -1 on a database error. */
	private static function move_claim_batch( int $limit ): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT %d", $wpdb->esc_like( '_vcap_claim_' ) . '%', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $rows ) {
			return 0;
		}
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$time     = (int) $row['option_value'];
			$values[] = '(%s, %s, %s)';
			array_push( $args, substr( (string) $row['option_name'], strlen( '_vcap_claim_' ), 32 ), 'legacy', gmdate( 'Y-m-d H:i:s', $time > 0 ? $time : time() ) );
		}
		$ids   = implode( ',', array_map( 'intval', array_column( $rows, 'option_id' ) ) );
		$table = self::tables()['claims'];
		$ok    = self::in_transaction(
			static fn() => false !== $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (claim_key, kind, created_at) VALUES " . implode( ',', $values ), $args ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				&& false !== $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_id IN ({$ids})" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		);
		foreach ( array_column( $rows, 'option_name' ) as $name ) {
			wp_cache_delete( (string) $name, 'options' );
		}
		return $ok ? count( $rows ) : -1;
	}

	/** Runs $work in a transaction: committed when it returns true, rolled back otherwise. */
	private static function in_transaction( callable $work ): bool {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$ok = (bool) $work();
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		if ( $ok && false !== $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		}
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false;
	}

	/**
	 * Whether the ledger tables are InnoDB (transactions). The answer is stored in an option by verify_schema()
	 * (activation, upgrade), so checkouts and admin pages do not query information_schema; a stored "no" is
	 * checked again at most every ten minutes, so tables converted by hand are noticed.
	 */
	public static function tables_support_transactions(): bool {
		if ( isset( self::$transactional_tables[ get_current_blog_id() ] ) ) {
			return self::$transactional_tables[ get_current_blog_id() ];
		}
		$stored = get_option( self::INNODB_OPTION );
		if ( 'yes' === $stored || ( 'no' === $stored && get_transient( 'vcap_innodb_recheck' ) ) ) {
			return self::$transactional_tables[ get_current_blog_id() ] = 'yes' === $stored;
		}
		return self::store_engine_state();
	}

	/** Reads the table engines from information_schema and stores the result. */
	private static function store_engine_state(): bool {
		global $wpdb;
		$tables = self::tables();
		$ok     = true;
		foreach ( array( 'vouchers', 'events', 'redemptions' ) as $key ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$tables[ $key ]
				)
			);
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				$ok = false;
				break;
			}
		}
		update_option( self::INNODB_OPTION, $ok ? 'yes' : 'no', true );
		if ( ! $ok ) {
			set_transient( 'vcap_innodb_recheck', 1, 10 * MINUTE_IN_SECONDS );
		}
		return self::$transactional_tables[ get_current_blog_id() ] = $ok;
	}

	private static function ensure_transactional_tables( array $tables ): void {
		global $wpdb;
		unset( self::$transactional_tables[ get_current_blog_id() ] );
		foreach ( array( 'vouchers', 'events', 'redemptions' ) as $key ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$tables[ $key ]
				)
			);
			if ( $engine && 'innodb' !== strtolower( (string) $engine ) ) {
				$wpdb->query( "ALTER TABLE {$tables[ $key ]} ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		delete_option( self::INNODB_OPTION );
	}

	/** CREATE TABLE statements (dbDelta format) per table key. */
	private static function schema(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$t       = self::tables();
		// redemptions: external (order-less) redemptions, see API\V1\RedemptionService; redemption_key is the caller's idempotency key.
		return array(
			'vouchers' => "CREATE TABLE {$t['vouchers']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					code varchar(100) NOT NULL,
					coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
					type varchar(20) NOT NULL DEFAULT 'voucher',
					status varchar(20) NOT NULL DEFAULT 'active',
					order_id bigint(20) unsigned NOT NULL DEFAULT 0,
					order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
					item_index int(11) unsigned NOT NULL DEFAULT 0,
					product_id bigint(20) unsigned NOT NULL DEFAULT 0,
					variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
					template_id bigint(20) unsigned NOT NULL DEFAULT 0,
					usage_mode varchar(20) NOT NULL DEFAULT 'single',
					discount_type varchar(20) NOT NULL DEFAULT 'fixed_cart',
					initial_amount decimal(19,4) NOT NULL DEFAULT 0,
					balance decimal(19,4) NOT NULL DEFAULT 0,
					currency varchar(10) NOT NULL DEFAULT '',
					expires_at datetime NULL DEFAULT NULL,
					buyer_name varchar(200) NOT NULL DEFAULT '',
					buyer_email varchar(200) NOT NULL DEFAULT '',
					user_id bigint(20) unsigned NOT NULL DEFAULT 0,
					recipient_name varchar(200) NOT NULL DEFAULT '',
					recipient_email varchar(200) NOT NULL DEFAULT '',
					message text NULL,
					fields longtext NULL,
					send_to varchar(20) NOT NULL DEFAULT 'buyer',
					delivery_at datetime NULL DEFAULT NULL,
					delivered_at datetime NULL DEFAULT NULL,
					token varchar(64) NOT NULL,
					transferable tinyint(1) NOT NULL DEFAULT 1,
					terms text NULL,
					max_checkins int(11) unsigned NOT NULL DEFAULT 1,
					checkins int(11) unsigned NOT NULL DEFAULT 0,
					last_checkin_at datetime NULL DEFAULT NULL,
					delivered_roles varchar(30) NOT NULL DEFAULT '',
					created_at datetime NOT NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY code (code),
					UNIQUE KEY token (token),
					UNIQUE KEY order_item_slot (order_item_id,item_index),
					KEY order_id (order_id),
					KEY coupon_id (coupon_id),
					KEY status (status),
					KEY product_id (product_id),
					KEY recipient_email (recipient_email(100)),
					KEY buyer_email (buyer_email(100)),
					KEY user_type (user_id,type)
				) ENGINE=InnoDB $collate;",
			'events' => "CREATE TABLE {$t['events']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					voucher_id bigint(20) unsigned NOT NULL,
					event varchar(30) NOT NULL,
					amount decimal(19,4) NULL DEFAULT NULL,
					balance_after decimal(19,4) NULL DEFAULT NULL,
					order_id bigint(20) unsigned NOT NULL DEFAULT 0,
					user_id bigint(20) unsigned NOT NULL DEFAULT 0,
					note text NULL,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY voucher_id (voucher_id),
					KEY voucher_order (voucher_id,order_id,id),
					KEY event (event),
					KEY created_at (created_at)
				) ENGINE=InnoDB $collate;",
			'pool' => "CREATE TABLE {$t['pool']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					code varchar(100) NOT NULL,
					batch varchar(100) NOT NULL DEFAULT '',
					voucher_id bigint(20) unsigned NOT NULL DEFAULT 0,
					imported_at datetime NOT NULL,
					used_at datetime NULL DEFAULT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY code (code),
					KEY batch_free (batch,voucher_id)
				) ENGINE=InnoDB $collate;",
			'redemptions' => "CREATE TABLE {$t['redemptions']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					redemption_key varchar(64) NOT NULL,
					voucher_id bigint(20) unsigned NOT NULL,
					user_id bigint(20) unsigned NOT NULL DEFAULT 0,
					target varchar(191) NOT NULL DEFAULT '',
					amount decimal(19,4) NOT NULL DEFAULT 0,
					state varchar(20) NOT NULL DEFAULT 'reserved',
					request_hash char(64) NOT NULL,
					meta longtext NULL,
					expires_at datetime NULL DEFAULT NULL,
					created_at datetime NOT NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY redemption_key (redemption_key),
					KEY voucher_id (voucher_id),
					KEY user_id (user_id),
					KEY state_expires (state,expires_at)
				) ENGINE=InnoDB $collate;",
			// usage: one row per use of a promotion (Discounts\Usage); customer_key "u:{user id}", "e:{email}" or "o:{order id}".
			'usage' => "CREATE TABLE {$t['usage']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					campaign_id bigint(20) unsigned NOT NULL,
					customer_key varchar(200) NOT NULL DEFAULT '',
					order_id bigint(20) unsigned NOT NULL DEFAULT 0,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY campaign_customer (campaign_id,customer_key(100)),
					KEY order_campaign (order_id,campaign_id)
				) ENGINE=InnoDB $collate;",
			// claims: one-time claims of side effects (Claim); claim_key is a hash of the claim name.
			'claims' => "CREATE TABLE {$t['claims']} (
					claim_key char(32) NOT NULL,
					kind varchar(40) NOT NULL DEFAULT '',
					created_at datetime NOT NULL,
					PRIMARY KEY  (claim_key),
					KEY kind_created (kind,created_at)
				) ENGINE=InnoDB $collate;",
		);
	}

	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}
		self::ensure_transactional_tables( self::tables() );
	}

	/**
	 * Checks the installed schema against schema(): every table, column, unique key and index exists and the
	 * ledger tables are InnoDB (transactions). Returns the problems found ([] = complete).
	 *
	 * @return string[]
	 */
	public static function verify_schema(): array {
		global $wpdb;
		$problems = array();
		$tables   = self::tables();
		foreach ( self::schema() as $key => $sql ) {
			$table = $tables[ $key ];
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$problems[] = sprintf( 'table %s is missing', $table );
				continue;
			}
			$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			preg_match_all( '/^\s*([a-z_]+) (?:bigint|int|varchar|char|decimal|datetime|text|longtext|tinyint)/m', $sql, $cols );
			foreach ( array_diff( $cols[1], $have ) as $missing ) {
				$problems[] = sprintf( 'column %s.%s is missing', $table, $missing );
			}
			$indexes = array_map( 'strtolower', array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			preg_match_all( '/(UNIQUE )?KEY ([a-z_]+) \(/', $sql, $keys, PREG_SET_ORDER );
			foreach ( $keys as $key ) {
				if ( ! in_array( $key[2], $indexes, true ) ) {
					$problems[] = sprintf( '' !== $key[1] ? 'unique key %s.%s is missing' : 'index %s.%s is missing', $table, $key[2] );
				}
			}
		}
		unset( self::$transactional_tables[ get_current_blog_id() ] );
		if ( ! self::store_engine_state() && ! $problems ) {
			$problems[] = 'the voucher tables are not InnoDB (transactions unavailable)';
		}
		return $problems;
	}

	/** Role for till and entrance staff: the scanner only (no orders, customers or settings). */
	public const CASHIER_ROLE = 'vcap_cashier';

	public static function add_caps(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'vcap_manage_vouchers' );
				$role->add_cap( 'vcap_scan_tickets' );
				// Raising a remaining value ("Add", "Set to" above it) creates value: its own right.
				$role->add_cap( 'vcap_increase_balance' );
			}
		}
		if ( ! get_role( self::CASHIER_ROLE ) ) {
			add_role(
				self::CASHIER_ROLE,
				__( 'Voucher cashier', 'voucher-captain' ),
				array(
					'read'              => true,
					'vcap_scan_tickets' => true,
				)
			);
		}
	}

	public static function upload_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'vcap';
	}

	/**
	 * Creates the private upload folders (PDFs, fonts, temp files) with their protection files.
	 *
	 * @return bool False when a folder or protection file could not be written (remembered for an admin notice).
	 */
	public static function ensure_upload_dir(): bool {
		$dir = self::upload_dir();
		$ok  = true;
		foreach ( array( $dir, $dir . '/fonts', $dir . '/tmp' ) as $d ) {
			if ( ! is_dir( $d ) && ! wp_mkdir_p( $d ) ) {
				$ok = false;
				continue;
			}
			if ( ! file_exists( $d . '/index.html' ) && false === @file_put_contents( $d . '/index.html', '' ) ) { // phpcs:ignore
				$ok = false;
			}
			if ( ! file_exists( $d . '/.htaccess' ) && false === @file_put_contents( $d . '/.htaccess', "Deny from all\n" ) ) { // phpcs:ignore
				$ok = false;
			}
		}
		if ( $ok ) {
			delete_option( 'vcap_upload_dir_error' );
		} else {
			update_option( 'vcap_upload_dir_error', $dir, false );
		}
		return $ok;
	}
}
