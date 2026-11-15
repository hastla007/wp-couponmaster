<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin coordinator: boots every module once WooCommerce is available.
 */
final class Plugin {
	private static ?Plugin $instance = null;

	public Settings $settings;
	public VoucherRepository $vouchers;
	public CodePool $code_pool;
	public CouponManager $coupons;
	public Issuer $issuer;
	public PdfRenderer $pdf;
	public Downloads $downloads;
	public Delivery $delivery;
	public Fields $fields;
	public Tickets $tickets;
	public API\V1\VoucherService $api_v1;
	public API\V1\RedemptionService $redemptions;
	/** Exchange of ticket vouchers for WP Pass Captain tickets (works only while Pass Captain is active). */
	public Compat\PassCaptainTickets $passcaptain_tickets;
	public StoreCredit $store_credit;
	/** Multi-purpose vouchers as a payment after tax (setting "vcap_mpv_after_tax"). */
	public PaymentVouchers $payments;

	/** Oldest WooCommerce version Voucher Captain runs with (header "WC requires at least"). */
	public const MIN_WC_VERSION = '8.9';

	/**
	 * The running plugin. Boots it on the first call; only after requirements_met() (voucher-captain.php checks
	 * it before it defines vcap()). Connectors use vcap() from the action "vcap_loaded" on, or check ready().
	 *
	 * @throws \LogicException When WooCommerce is missing or too old (the plugin does not run then).
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			if ( ! self::requirements_met() ) {
				throw new \LogicException( 'Voucher Captain does not run: WooCommerce ' . esc_html( self::MIN_WC_VERSION ) . ' or newer is required.' );
			}
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/** Whether the plugin has booted (vcap() and its services can be used). */
	public static function ready(): bool {
		return null !== self::$instance;
	}

	/** Whether WooCommerce is active in a supported version. */
	public static function requirements_met(): bool {
		return class_exists( 'WooCommerce' ) && ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, self::MIN_WC_VERSION, '>=' ) );
	}

	private function __construct() {}

	private function boot(): void {
		Install::maybe_upgrade();

		$this->settings  = new Settings();
		$this->vouchers  = new VoucherRepository();
		$this->code_pool = new CodePool();
		$this->coupons   = new CouponManager( $this->settings, $this->vouchers );
		$this->pdf       = new PdfRenderer( $this->settings );
		$this->downloads = new Downloads( $this->settings, $this->vouchers, $this->pdf );
		$this->delivery  = new Delivery( $this->settings, $this->vouchers );
		$this->issuer    = new Issuer( $this->settings, $this->vouchers, $this->coupons, $this->code_pool, $this->delivery );
		$this->fields    = new Fields();
		$this->tickets   = new Tickets( $this->vouchers );
		$this->api_v1    = new API\V1\VoucherService( $this->vouchers, $this->coupons );
		$this->redemptions = new API\V1\RedemptionService( $this->vouchers );
		$this->passcaptain_tickets = new Compat\PassCaptainTickets( $this->redemptions, $this->vouchers );
		$this->store_credit = new StoreCredit( $this->settings, $this->vouchers, $this->coupons );
		$this->payments     = new PaymentVouchers( $this->vouchers );

		( new TemplatePostType() )->register();
		( new ProductSettings() )->register();
		( new CustomAmount() )->register();
		( new Preview( $this->pdf ) )->register();
		( new Emails() )->register();
		( new Privacy( $this->vouchers ) )->register();
		( new Health() )->register();
		( new Attention() )->register();
		( new Diagnostics() )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\Command::register(); // "wp vcap …" (D9).
		}
		( new CatchUp() )->register();
		add_action( Install::MIGRATE_HOOK, array( Install::class, 'run_queued_migration' ) );
		// B4: background bulk runs (code generation, large imports) also run from WP-Cron, outside the admin.
		( new Jobs() )->register();
		Jobs::handle( Campaigns::JOB, array( new Campaigns( $this->code_pool, $this->vouchers ), 'job_step' ) );
		Jobs::handle( CodePool::IMPORT_JOB, array( $this->code_pool, 'import_step' ) );

		( new TopUp( $this->vouchers, $this->coupons ) )->register();
		( new CouponLink( $this->settings ) )->register();
		( new VoucherPurchaseRules( $this->settings ) )->register();
		( new FirstOrderCoupons() )->register();
		( new CouponSkuRules() )->register();
		$referrals = new Referrals( $this->settings, $this->store_credit );
		$referrals->register();
		( new Shortcodes( $this->downloads, $referrals ) )->register();
		$promotions = new Discounts\Repository();
		$promotions->register();
		// Promotions follow their setting at runtime (CartIntegration::enabled()): no new promotion in a cart and
		// no new bonus voucher while switched off; held uses, existing order discounts and withdrawals keep working.
		( new Discounts\Usage( $promotions ) )->register();
		$promotion_cart = new Discounts\CartIntegration( $this->settings, $promotions );
		$promotion_cart->register();
		( new Discounts\Bonus( $promotions, $promotion_cart ) )->register();
		( new Discounts\Admin( $promotions ) )->register();

		$this->settings->register();
		$this->coupons->register();
		$this->downloads->register();
		$this->delivery->register();
		$this->issuer->register();
		$this->fields->register();
		( new NextSteps() )->register();
		$this->tickets->register();
		$this->redemptions->register();
		$this->store_credit->register();
		( new OrderReturn( $this->vouchers, $this->coupons ) )->register();
		$this->payments->register();
		( new CartBlocks( $this->vouchers, $this->payments, $this->store_credit ) )->register();
		( new API\V1\RestController( $this->redemptions ) )->register();

		( new Compat\Subscriptions() )->register();
		( new Compat\Wpml() )->register();
		( new Compat\Multilingual() )->register();
		( new Compat\MultiCurrency() )->register();
		( new Compat\AffiliateCaptain() )->register();
		// Pass Captain: provider (reserve/commit/release/refund), channel, ticket exchange, product settings, ticket
		// vouchers and the shared scanner.
		( new Compat\PassCaptain() )->register();
		$this->passcaptain_tickets->register();
		( new Compat\PassCaptainScanner() )->register();

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
			( new Admin\OrderAdmin( $this->vouchers, $this->issuer ) )->register();
			( new Admin\VoucherAdmin( $this->vouchers ) )->register();
			( new Admin\Import( $this->code_pool ) )->register();
			( new Admin\StoreCreditAdmin( $this->store_credit, $this->vouchers ) )->register();
			( new Admin\CampaignAdmin( new Campaigns( $this->code_pool, $this->vouchers ) ) )->register();
			( new Admin\ReportsAdmin() )->register();
			( new Counter() )->register(); // Own daily counter limits on the user profile (R6).
		}

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( $this, 'ensure_schedules' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( VCAP_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Makes sure the recurring tasks exist: checked once a day (autoloaded marker, no Action Scheduler query on
	 * ordinary requests) and right after activation or an update, which delete the marker.
	 */
	public function ensure_schedules(): void {
		$checked = (int) get_option( Install::SCHEDULES_OPTION, 0 );
		if ( $checked > time() - DAY_IN_SECONDS || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		// The plugin runs, but a deactivation was not followed by an activation: a network deactivation that
		// stopped half-way, or an update by upload after a rollback (no activation hook). Undo it here, unless a
		// deactivation may still be running (within the last 15 minutes): checked again on a later request.
		$deactivated = (int) get_option( Install::DEACTIVATED_OPTION, 0 );
		if ( $deactivated > 0 || Install::has_suspended_coupons() ) {
			if ( $deactivated > time() - 15 * MINUTE_IN_SECONDS || Install::network_deactivation_running() ) {
				return;
			}
			Install::resume();
		}
		update_option( Install::SCHEDULES_OPTION, time(), true );
		$this->delivery->schedule_maintenance();
		$this->coupons->schedule_stale_release();
		if ( 'yes' === get_option( Install::RESTORE_TASKS_OPTION ) ) {
			delete_option( Install::RESTORE_TASKS_OPTION );
			// In Action Scheduler batches, not in this (arbitrary) request.
			$this->delivery->queue_restore_tasks();
		}
		$catch_up = get_option( Install::CATCH_UP_OPTION );
		if ( is_array( $catch_up ) ) {
			delete_option( Install::CATCH_UP_OPTION );
			// Orders of the time the plugin was off: issue, void, reward (Action Scheduler batches).
			CatchUp::queue( (int) ( $catch_up['since'] ?? 0 ), (int) ( $catch_up['until'] ?? 0 ) );
		}
	}

	public function load_textdomain(): void {
		I18n::register(); // de_DE_formal, de_CH, de_AT without own file → German (de_DE).
		load_plugin_textdomain( 'voucher-captain', false, dirname( plugin_basename( VCAP_FILE ) ) . '/languages' );
	}

	/** @param mixed $links What the previous filter returned (AP-Q2-6: a non-array is normalised). */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		$extra = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=vcap' ) ) . '">' . esc_html__( 'Settings', 'voucher-captain' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=vcap-vouchers' ) ) . '">' . esc_html__( 'Vouchers', 'voucher-captain' ) . '</a>',
		);
		return array_merge( $extra, $links );
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! Install::tables_support_transactions() ) {
			// Balance reservations, redemptions and retention need InnoDB; without it they fail closed.
			$tables = Install::tables();
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: comma separated table names */
					__( 'Voucher Captain: the voucher database tables do not support transactions (InnoDB). Gift card balances cannot be redeemed until these tables are converted to InnoDB: %s', 'voucher-captain' ),
					implode( ', ', array( $tables['vouchers'], $tables['events'], $tables['redemptions'] ) )
				)
			) . '</p></div>';
		}
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Voucher Captain: the PDF libraries are missing. Install the release ZIP or run "composer install --no-dev" in the plugin folder.', 'voucher-captain' ) . '</p></div>';
	}

	/** Option name prefix of the rate limit counters before schema 1.7, and of their object cache keys. */
	public const RATE_PREFIX = Install::LEGACY_RATE_PREFIX;
	/** Option with the buckets whose limit was reached: bucket => array{last:int, windows:int} (Site Health). */
	public const RATE_HITS_OPTION = 'vcap_rate_limit_hits';

	/** @var array<string, string> counter name => backend that counted it in this request ('db' | 'cache') */
	private static array $rate_backend = array();

	/**
	 * Fixed-window rate limit for public endpoints (code guessing, expensive PDF previews).
	 *
	 * Counters live in the database by default: one INSERT … ON DUPLICATE KEY UPDATE row per counter and window in
	 * the table vcap_rate_limits (B16; wp_options before schema 1.7), atomic and shared by all web nodes. A persistent object cache is used only on request (filter "vcap_rate_limit_backend"
	 * = 'object_cache'); when it does not count (outage, no atomic increment, evicted key) the database counts.
	 * The window is fixed: hitting the limit does not extend it. Visitors are told apart by their address (IPv6
	 * by its /64 network, which one connection usually owns entirely, and additionally by its /48 with a larger
	 * quota, so a whole allocation cannot rotate through its /64 networks) or by an explicit $visitor key.
	 *
	 * $count = true (expensive actions such as PDF previews): every request counts per visitor and site-wide.
	 *
	 * $count = false (code lookups): the visitor's attempt is counted BEFORE the lookup, so parallel requests
	 * cannot all pass the check together. The caller then reports the outcome: rate_limit_success() gives the
	 * attempt back when the code exists, rate_limit_failure() books an unknown code on the site-wide counter.
	 * Valid codes therefore never use up a quota.
	 *
	 * The site-wide counter never refuses a request by itself: while it is full (guessing or a request flood
	 * from many addresses) it only brakes, i.e. every visitor's quota shrinks to a fifth. Shop managers and
	 * logged-in customers with vouchers of their own keep the full quota. Limits of a bucket can be changed with
	 * the filter "vcap_rate_limit_{$bucket}"; the first visitor over a limit in a window is logged.
	 *
	 * @param string $visitor Visitor key; '' = the client address.
	 * @return bool true when the request must be rejected
	 */
	public static function rate_limited( string $bucket, int $per_visitor, int $site_wide, int $window, bool $count = true, string $visitor = '' ): bool {
		/**
		 * Limits of a rate limit bucket (e.g. "vcap_rate_limit_code", "vcap_rate_limit_preview").
		 *
		 * @param array{per_visitor:int, site_wide:int} $limits per_visitor 0 = no limit.
		 * @param int                                   $window Window in seconds.
		 */
		$limits      = (array) apply_filters( "vcap_rate_limit_{$bucket}", array( 'per_visitor' => $per_visitor, 'site_wide' => $site_wide ), $window );
		$per_visitor = (int) ( $limits['per_visitor'] ?? $per_visitor );
		$site_wide   = (int) ( $limits['site_wide'] ?? $site_wide );
		if ( $per_visitor <= 0 ) {
			return false;
		}
		$used = array();
		foreach ( self::rate_visitor_keys( $bucket, $visitor ) as $key => $factor ) {
			$used[] = array( self::rate_increment( $key, $window ), $factor );
		}
		$over    = static function ( int $limit ) use ( $used ): bool {
			foreach ( $used as $counted ) {
				if ( $counted[0] > $limit * $counted[1] ) {
					return true;
				}
			}
			return false;
		};
		$limited = $over( $per_visitor );
		if ( ! $limited && $site_wide > 0 ) {
			$total = $count ? self::rate_increment( self::rate_site_key( $bucket ), $window ) : self::rate_read( self::rate_site_key( $bucket ), $window ) + 1;
			if ( $total > $site_wide && ! self::rate_brake_exempt() ) {
				/** Quota per visitor while the site-wide counter is full (default: a fifth, at least 1). */
				$limited = $over( (int) apply_filters( 'vcap_rate_limit_brake', max( 1, (int) ceil( $per_visitor / 5 ) ), $bucket, $per_visitor ) );
			}
		}
		if ( $limited ) {
			self::rate_limit_hit( $bucket, $per_visitor, $window );
		}
		return $limited;
	}

	/** The looked-up code exists: gives the attempt counted by rate_limited( …, false ) back. */
	public static function rate_limit_success( string $bucket, int $window, string $visitor = '' ): void {
		foreach ( array_keys( self::rate_visitor_keys( $bucket, $visitor ) ) as $key ) {
			self::rate_decrement( $key, $window );
		}
	}

	/**
	 * Counts a failed attempt (unknown code) of rate_limited( …, false ) site-wide; the visitor's attempt was
	 * already counted before the lookup.
	 */
	public static function rate_limit_failure( string $bucket, int $window ): void {
		self::rate_increment( self::rate_site_key( $bucket ), $window );
	}

	/** Resets a visitor's counter of the current window (support, tests). */
	public static function rate_limit_reset( string $bucket, int $window, string $visitor = '' ): void {
		global $wpdb;
		foreach ( array_keys( self::rate_visitor_keys( $bucket, $visitor ) ) as $key ) {
			$name = self::rate_option( $key, $window );
			wp_cache_delete( $name, 'vcap_rate' );
			if ( Install::counters_ready() ) {
				$wpdb->delete( Install::tables()['rate'], array( 'window_end' => self::rate_window_end( $window ), 'counter_key' => $key ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Site-wide count of a bucket in the current window (failed lookups, or all requests with $count = true). */
	public static function rate_site_count( string $bucket, int $window ): int {
		return self::rate_read( self::rate_site_key( $bucket ), $window );
	}

	/**
	 * Removes the counters of all visitors (Site Health action "Reset rate limits", e.g. after a proxy made every
	 * customer share one counter) and forgets the recorded hits.
	 */
	public static function reset_rate_limits(): void {
		global $wpdb;
		if ( Install::counters_ready() ) {
			$wpdb->query( 'DELETE FROM ' . Install::tables()['rate'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name from Install.
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::RATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'vcap_rate' );
		}
		delete_option( self::RATE_HITS_OPTION );
		self::$rate_backend = array();
	}

	/**
	 * A visitor went over a limit: logged once per bucket and window (without the address) and remembered for
	 * Site Health, so "Too many attempts" for many customers (e.g. behind a proxy) does not go unnoticed.
	 */
	private static function rate_limit_hit( string $bucket, int $per_visitor, int $window ): void {
		if ( 1 !== self::rate_increment( self::rate_key( $bucket . '|hit' ), $window ) ) {
			return;
		}
		Logger::warning( sprintf( 'Rate limit "%1$s" reached: a visitor made more than %2$d attempts within %3$d minutes. If many customers see "Too many attempts", check Tools > Site Health (proxy or CDN without the filter "vcap_client_ip").', $bucket, $per_visitor, (int) ceil( $window / 60 ) ) );
		$hits            = get_option( self::RATE_HITS_OPTION, array() );
		$hits            = is_array( $hits ) ? $hits : array();
		$hits[ $bucket ] = array(
			'last'    => time(),
			'windows' => (int) ( $hits[ $bucket ]['windows'] ?? 0 ) + 1,
		);
		update_option( self::RATE_HITS_OPTION, $hits, false );
		/** Fires the first time a visitor exceeds the limit of a bucket in a window. */
		do_action( 'vcap_rate_limit_hit', $bucket, $per_visitor, $window );
	}

	/**
	 * Visitor address for rate limits: REMOTE_ADDR only, because X-Forwarded-For and similar headers can be
	 * spoofed. Behind a trusted reverse proxy or CDN return the real client address with "vcap_client_ip".
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'vcap_client_ip', $ip );
	}

	/**
	 * Network of an address for rate limits: IPv4 as is, IPv6 by its /64 prefix (a single connection usually
	 * gets a whole /64, so rotating addresses inside it must not reset the counter) or another prefix length
	 * (multiple of 8, e.g. 48 for a site's allocation).
	 */
	public static function client_network( string $ip, int $prefix = 64 ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}
		if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) ); // IPv4-mapped address.
		}
		$bytes = max( 1, min( 16, intdiv( $prefix, 8 ) ) );
		return bin2hex( substr( $packed, 0, $bytes ) ) . '::/' . ( $bytes * 8 );
	}

	/**
	 * Counter keys of a visitor => factor of the quota: an explicit visitor key, or the address (IPv4, IPv6 /64)
	 * plus, for IPv6, its /48 with a larger quota (filter "vcap_rate_limit_ipv6_48_factor", default 4).
	 *
	 * @return array<string, int>
	 */
	private static function rate_visitor_keys( string $bucket, string $visitor ): array {
		if ( '' !== $visitor ) {
			return array( self::rate_key( $bucket . '|' . $visitor ) => 1 );
		}
		$ip   = self::client_ip();
		$keys = array( self::rate_key( $bucket . '|' . self::client_network( $ip ) ) => 1 );
		$wide = self::client_network( $ip, 48 );
		if ( str_ends_with( $wide, '::/48' ) ) {
			$keys[ self::rate_key( $bucket . '|' . $wide ) ] = max( 1, (int) apply_filters( 'vcap_rate_limit_ipv6_48_factor', 4, $bucket ) );
		}
		return $keys;
	}

	private static function rate_site_key( string $bucket ): string {
		return self::rate_key( $bucket . '|all' );
	}

	/**
	 * Counter key: a keyed hash with the site's secret salt, so a stored counter name cannot be turned back into
	 * the address by trying all IPv4 addresses.
	 */
	private static function rate_key( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/** Shop managers and customers with vouchers of their own are not slowed down by the site-wide brake. */
	private static function rate_brake_exempt(): bool {
		static $cache = array();
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$key = get_current_blog_id() . ':' . $user_id; // Per site: vouchers and roles differ between sites.
		if ( ! isset( $cache[ $key ] ) ) {
			global $wpdb;
			$exempt = current_user_can( 'manage_woocommerce' ) || current_user_can( 'vcap_manage_vouchers' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install / WooCommerce.
			if ( ! $exempt ) {
				$table  = Install::tables()['vouchers'];
				$exempt = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					|| ( isset( vcap()->vouchers ) && array() !== vcap()->vouchers->for_customer( $user_id, 1 ) );
			}
			/** Whether the current user keeps the full rate-limit quota while the site-wide brake is on. */
			$cache[ $key ] = (bool) apply_filters( 'vcap_rate_limit_brake_exempt', $exempt, $user_id );
		}
		return $cache[ $key ];
	}

	/** End of the current window of a counter (unix time; the counter belongs to [end - window, end)). */
	private static function rate_window_end( int $window ): int {
		$window = max( 1, $window );
		return ( intdiv( time(), $window ) + 1 ) * $window;
	}

	/**
	 * Name of a counter in the current window: vcap_rl_{window end}_{key}. The object cache key, and the option
	 * name of the counters before schema 1.7 (cleanup compares names).
	 */
	private static function rate_option( string $key, int $window ): string {
		return self::RATE_PREFIX . sprintf( '%010d', self::rate_window_end( $window ) ) . '_' . $key;
	}

	/**
	 * Whether counters go to the persistent object cache: only on request (filter "vcap_rate_limit_backend" =
	 * 'object_cache'), for caches with atomic increments shared by all web nodes and no eviction of these keys.
	 */
	private static function rate_uses_cache(): bool {
		return wp_using_ext_object_cache() && 'object_cache' === apply_filters( 'vcap_rate_limit_backend', 'db' );
	}

	private static function rate_increment( string $key, int $window ): int {
		$name = self::rate_option( $key, $window );
		if ( self::rate_uses_cache() ) {
			wp_cache_add( $name, 0, 'vcap_rate', $window );
			$count = wp_cache_incr( $name, 1, 'vcap_rate' );
			if ( is_numeric( $count ) && (int) $count > 0 ) {
				self::$rate_backend[ $name ] = 'cache';
				return (int) $count;
			}
			// The cache did not count (outage, no atomic increment, evicted key): the database counts instead.
		}
		self::$rate_backend[ $name ] = 'db';
		global $wpdb;
		if ( Install::counters_ready() ) {
			// B16: one row of the table vcap_rate_limits per counter and window, counted atomically.
			$table = Install::tables()['rate'];
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (window_end, counter_key, hits) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", self::rate_window_end( $window ), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::rate_db_read( $key, $window );
	}

	private static function rate_decrement( string $key, int $window ): void {
		$name    = self::rate_option( $key, $window );
		$backend = self::$rate_backend[ $name ] ?? ( self::rate_uses_cache() ? 'cache' : 'db' );
		if ( 'cache' === $backend ) {
			if ( (int) wp_cache_get( $name, 'vcap_rate' ) > 0 ) {
				wp_cache_decr( $name, 1, 'vcap_rate' );
			}
			return;
		}
		global $wpdb;
		if ( Install::counters_ready() ) {
			$table = Install::tables()['rate'];
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits - 1 WHERE window_end = %d AND counter_key = %s AND hits > 0", self::rate_window_end( $window ), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value - 1 WHERE option_name = %s AND option_value > 0", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function rate_read( string $key, int $window ): int {
		if ( self::rate_uses_cache() ) {
			$count = wp_cache_get( self::rate_option( $key, $window ), 'vcap_rate' );
			if ( is_numeric( $count ) && (int) $count > 0 ) {
				return (int) $count;
			}
		}
		return self::rate_db_read( $key, $window );
	}

	private static function rate_db_read( string $key, int $window ): int {
		global $wpdb;
		if ( Install::counters_ready() ) {
			$table = Install::tables()['rate'];
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE window_end = %d AND counter_key = %s", self::rate_window_end( $window ), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::rate_option( $key, $window ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Removes counters of finished windows (daily maintenance), in batches until done or $budget seconds are used:
	 * rows of the table vcap_rate_limits and options of the storage before schema 1.7 (left by a request that still
	 * wrote them during the upgrade, or by an older version after a rollback).
	 *
	 * @return bool true when all finished windows are removed.
	 */
	public static function purge_rate_limits( float $budget = 10.0 ): bool {
		global $wpdb;
		$start = microtime( true );
		$limit = 1000;
		$table = Install::counters_ready() ? Install::tables()['rate'] : '';
		do {
			$deleted = 0;
			if ( '' !== $table ) {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_end <= %d LIMIT %d", time(), $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( false !== $deleted && (int) $deleted < $limit ) {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s LIMIT %d", $wpdb->esc_like( self::RATE_PREFIX ) . '%', self::RATE_PREFIX . sprintf( '%010d', time() ) . '_', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Rate limit counters could not be removed: ' . esc_html( $wpdb->last_error ) );
			}
		} while ( (int) $deleted >= $limit && microtime( true ) - $start < $budget );
		return (int) $deleted < $limit;
	}

	/**
	 * Render a PHP template from /templates, overridable from the theme at woocommerce/voucher-captain/.
	 */
	public static function template( string $name, array $args = array() ): void {
		wc_get_template( $name, $args, WC()->template_path() . 'voucher-captain/', VCAP_PATH . 'templates/' );
	}

	public static function template_html( string $name, array $args = array() ): string {
		return wc_get_template_html( $name, $args, WC()->template_path() . 'voucher-captain/', VCAP_PATH . 'templates/' );
	}
}
