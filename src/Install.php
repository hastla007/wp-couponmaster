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
	private const LOCK_SECONDS = 300;
	private static ?bool $transactional_tables = null;
	private static ?string $lock_value = null;

	/** @var array<string, callable-string> */
	private const MIGRATIONS = array(
		// '1.1.0' => 'migrate_1_1_0',
	);

	/**
	 * Activation hook. Network activation sets up every site (each site has its own tables, options and
	 * coupons); sites added later, and sites beyond the first 500, are set up on their first request by
	 * maybe_upgrade().
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'activate_site' ), 500 );
			return;
		}
		self::activate_site();
	}

	/**
	 * Deactivation hook. A network deactivation must reach every site: without the plugin, balance coupons of a
	 * site that was skipped would stay usable for their full balance on every order (see suspend_balance_coupons()).
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions -- large networks, best effort.
			}
			self::for_each_site( array( self::class, 'deactivate_site' ) );
			return;
		}
		self::deactivate_site();
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
					self::create_tables();
					self::add_caps();
					self::ensure_upload_dir();
					if ( self::run_migrations( $installed ) ) {
						self::finish_schema();
					}
				}
			}
			if ( ! get_option( 'vcap_secret' ) ) {
				update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
			}
			add_option( 'vcap_needs_default_template', 'yes' );
			update_option( 'vcap_flush_rewrite', 'yes' );
			self::restore_balance_coupons();
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}
	}

	public static function deactivate_site(): void {
		if ( ! get_option( self::OPTION_DB_VERSION ) ) {
			return; // Never set up on this site (network activation reaches other sites lazily): no coupons, no tasks.
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_daily_maintenance' );
			as_unschedule_all_actions( 'vcap_release_stale_order_reservations' );
		}
		self::suspend_balance_coupons();
	}

	public const SUSPENDED_OPTION = 'vcap_suspended_coupons';

	/**
	 * Gift cards and store credit are WooCommerce coupons without a usage limit whose amount is the remaining
	 * balance; only Voucher Captain deducts that balance. Without the plugin every such code would take its
	 * full balance off every order, so they are set to draft (WooCommerce refuses them) until reactivation.
	 */
	public static function suspend_balance_coupons(): void {
		global $wpdb;
		$tables = self::tables();
		$ids    = array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$tables['vouchers']} v ON v.coupon_id = p.ID WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND v.usage_mode = 'balance'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $ids ) {
			return;
		}
		$known = array_map( 'intval', (array) get_option( self::SUSPENDED_OPTION, array() ) );
		update_option( self::SUSPENDED_OPTION, array_values( array_unique( array_merge( $known, $ids ) ) ), false );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE ID IN (" . implode( ',', $chunk ) . ") AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			foreach ( $chunk as $id ) {
				clean_post_cache( $id );
			}
		}
		self::flush_coupon_cache();
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

	/** Publishes the coupons suspended on deactivation again (only those, never coupons drafted by hand). */
	public static function restore_balance_coupons(): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) get_option( self::SUSPENDED_OPTION, array() ) ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE ID IN (" . implode( ',', $chunk ) . ") AND post_type = 'shop_coupon' AND post_status = 'draft'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			foreach ( $chunk as $id ) {
				clean_post_cache( $id );
			}
		}
		delete_option( self::SUSPENDED_OPTION );
		self::flush_coupon_cache();
	}

	/**
	 * Brings the schema of this site to VCAP_DB_VERSION. Runs on every request until the stored version matches,
	 * but only one request at a time does the work (upgrade lock); the others carry on with the old schema.
	 * A newer stored version (the plugin was downgraded) is never lowered: migrations would otherwise run twice
	 * after the next update. Administrators see a notice instead.
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
		if ( get_transient( 'vcap_schema_retry' ) || ! self::lock() ) {
			return;
		}
		try {
			// The request that held the lock before may have finished the upgrade meanwhile.
			$installed = self::stored_version();
			if ( VCAP_DB_VERSION === $installed || ( '' !== $installed && version_compare( $installed, VCAP_DB_VERSION, '>' ) ) ) {
				return;
			}
			if ( '' === $installed ) {
				// Never activated on this site (a network-activated plugin on a new or not yet visited site).
				self::unlock();
				self::activate_site();
				return;
			}
			self::create_tables();
			self::add_caps();
			self::ensure_upload_dir();
			if ( ! get_option( 'vcap_secret' ) ) {
				update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
			}
			// A data migration that stopped after its time budget continues on the next request.
			if ( self::run_migrations( $installed ) ) {
				self::finish_schema();
			}
			// Recurring tasks are checked again after an update (see Plugin::ensure_schedules()).
			delete_option( self::SCHEDULES_OPTION );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Runs the data migrations newer than $installed, in order.
	 *
	 * @return bool False while a migration still has work left (it continues on the next request).
	 */
	private static function run_migrations( string $installed ): bool {
		if ( '' === $installed ) {
			return true; // New installation: nothing to migrate.
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

	public static function tables(): array {
		global $wpdb;
		return array(
			'vouchers' => $wpdb->prefix . 'vcap_vouchers',
			'events'   => $wpdb->prefix . 'vcap_events',
			'pool'     => $wpdb->prefix . 'vcap_code_pool',
			'redemptions' => $wpdb->prefix . 'vcap_redemptions',
		);
	}

	public static function tables_support_transactions(): bool {
		if ( null !== self::$transactional_tables ) {
			return self::$transactional_tables;
		}
		global $wpdb;
		$tables = self::tables();
		foreach ( array( 'vouchers', 'events', 'redemptions' ) as $key ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$tables[ $key ]
				)
			);
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return self::$transactional_tables = false;
			}
		}
		return self::$transactional_tables = true;
	}

	private static function ensure_transactional_tables( array $tables ): void {
		global $wpdb;
		self::$transactional_tables = null;
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
					KEY event (event)
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
	 * Checks the installed schema against schema(): every table, column and unique key exists and the ledger
	 * tables are InnoDB (transactions). Returns the problems found ([] = complete).
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
			preg_match_all( '/UNIQUE KEY ([a-z_]+)/', $sql, $uniques );
			foreach ( array_diff( $uniques[1], $indexes ) as $missing ) {
				$problems[] = sprintf( 'unique key %s.%s is missing', $table, $missing );
			}
		}
		self::$transactional_tables = null;
		if ( ! $problems && ! self::tables_support_transactions() ) {
			$problems[] = 'the voucher tables are not InnoDB (transactions unavailable)';
		}
		return $problems;
	}

	/**
	 * Records the schema state: the version option is only set when the schema is complete, so a failed or
	 * partial upgrade is retried (at most every five minutes) and shown to administrators.
	 */
	private static function finish_schema(): bool {
		$problems = self::verify_schema();
		if ( $problems ) {
			update_option( self::SCHEMA_ERROR_OPTION, implode( '; ', $problems ), false );
			set_transient( 'vcap_schema_retry', 1, 5 * MINUTE_IN_SECONDS );
			return false;
		}
		delete_option( self::SCHEMA_ERROR_OPTION );
		$installed = self::stored_version();
		if ( '' === $installed || version_compare( $installed, VCAP_DB_VERSION, '<' ) ) {
			// Never lowered (downgrade), see maybe_upgrade().
			update_option( self::OPTION_DB_VERSION, VCAP_DB_VERSION );
		}
		return true;
	}

	public static function add_caps(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'vcap_manage_vouchers' );
				$role->add_cap( 'vcap_scan_tickets' );
			}
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
