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
	private static ?bool $transactional_tables = null;

	/** @var array<string, callable-string> */
	private const MIGRATIONS = array(
		// '1.1.0' => 'migrate_1_1_0',
	);

	public static function activate(): void {
		self::create_tables();
		self::add_caps();
		self::ensure_upload_dir();
		update_option( self::OPTION_DB_VERSION, VCAP_DB_VERSION );
		if ( ! get_option( 'vcap_secret' ) ) {
			update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
		}
		add_option( 'vcap_needs_default_template', 'yes' );
		update_option( 'vcap_flush_rewrite', 'yes' );
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_daily_maintenance' );
		}
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION_DB_VERSION );
		if ( VCAP_DB_VERSION === $installed ) {
			return;
		}
		self::create_tables();
		self::add_caps();
		self::ensure_upload_dir();
		foreach ( self::MIGRATIONS as $version => $method ) {
			if ( $installed && version_compare( $installed, $version, '<' ) ) {
				self::$method();
			}
		}
		if ( ! get_option( 'vcap_secret' ) ) {
			update_option( 'vcap_secret', wp_generate_password( 64, true, true ), false );
		}
		update_option( self::OPTION_DB_VERSION, VCAP_DB_VERSION );
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

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$t       = self::tables();

		dbDelta(
			"CREATE TABLE {$t['vouchers']} (
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
				recipient_name varchar(200) NOT NULL DEFAULT '',
				recipient_email varchar(200) NOT NULL DEFAULT '',
				message text NULL,
				fields longtext NULL,
				send_to varchar(20) NOT NULL DEFAULT 'buyer',
				delivery_at datetime NULL DEFAULT NULL,
				delivered_at datetime NULL DEFAULT NULL,
				token varchar(64) NOT NULL,
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
				KEY buyer_email (buyer_email(100))
			) ENGINE=InnoDB $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['events']} (
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
			) ENGINE=InnoDB $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['pool']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(100) NOT NULL,
				batch varchar(100) NOT NULL DEFAULT '',
				voucher_id bigint(20) unsigned NOT NULL DEFAULT 0,
				imported_at datetime NOT NULL,
				used_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY batch_free (batch,voucher_id)
			) ENGINE=InnoDB $collate;"
		);
		// External (order-less) redemptions, see API\V1\RedemptionService. redemption_key is the caller's idempotency key.
		dbDelta(
			"CREATE TABLE {$t['redemptions']} (
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
			) ENGINE=InnoDB $collate;"
		);
		self::ensure_transactional_tables( $t );
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

	public static function ensure_upload_dir(): void {
		$dir = self::upload_dir();
		foreach ( array( $dir, $dir . '/fonts', $dir . '/tmp' ) as $d ) {
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
			}
			if ( ! file_exists( $d . '/index.html' ) ) {
				@file_put_contents( $d . '/index.html', '' ); // phpcs:ignore
			}
			if ( ! file_exists( $d . '/.htaccess' ) ) {
				@file_put_contents( $d . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
			}
		}
	}
}
