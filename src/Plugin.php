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

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function boot(): void {
		Install::maybe_upgrade();
		if ( Licensing\Entitlements::is_premium() ) {
			// Free and development builds have no premium updater (and no license page).
			( new Licensing\PremiumUpdater() )->register_hooks();
		}

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
		( new Diagnostics() )->register();
		Licensing\Entitlements::register();

		// Premium features (Licensing\Entitlements): a locked feature (free edition, premium without an active
		// license) only stops what is new – configuration, sales, customer screens. Lifecycle hooks of what was
		// sold earlier (top-up booking and reversal, reward and bonus withdrawal, promotion holds, Pass Captain
		// reservations, refunds and ticket exchanges, coupon types) are always registered.
		$topup = new TopUp( $this->vouchers, $this->coupons );
		$topup->register_lifecycle();
		if ( Licensing\Entitlements::allows( 'topup' ) ) {
			$topup->register_features();
		} else {
			$topup->register_locked();
		}
		( new CouponLink( $this->settings ) )->register();
		( new VoucherPurchaseRules( $this->settings ) )->register();
		( new FirstOrderCoupons() )->register();
		( new CouponSkuRules() )->register();
		$referrals = new Referrals( $this->settings, $this->store_credit );
		$referrals->register_lifecycle();
		if ( Licensing\Entitlements::allows( 'referrals' ) ) {
			$referrals->register_features();
		}
		( new Shortcodes( $this->downloads, $referrals ) )->register();
		$promotions = new Discounts\Repository();
		$promotions->register();
		// Promotions check the license at runtime (CartIntegration::enabled()): no new promotion in a cart and no
		// new bonus voucher while locked; held uses, existing order discounts and withdrawals keep working.
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
		$this->tickets->register();
		$this->redemptions->register();
		$this->store_credit->register();
		$this->payments->register();
		( new CartBlocks( $this->vouchers, $this->payments, $this->store_credit ) )->register();
		( new API\V1\RestController( $this->redemptions ) )->register();

		( new Compat\Subscriptions() )->register();
		( new Compat\Wpml() )->register();
		( new Compat\MultiCurrency() )->register();
		( new Compat\AffiliateCaptain() )->register();
		// Pass Captain: provider (reserve/commit/release/refund), channel and ticket exchange always; product
		// settings, new ticket-voucher sales and the shared scanner only with the right.
		( new Compat\PassCaptain() )->register();
		$this->passcaptain_tickets->register_lifecycle();
		if ( Licensing\Entitlements::allows( 'passcaptain' ) ) {
			$this->passcaptain_tickets->register_features();
			( new Compat\PassCaptainScanner() )->register();
		} else {
			$this->passcaptain_tickets->register_locked();
		}

		if ( is_admin() ) {
			if ( Licensing\Entitlements::is_premium() ) {
				( new Licensing\AdminPage() )->register();
			}
			( new Admin\Menu() )->register();
			( new Admin\OrderAdmin( $this->vouchers, $this->issuer ) )->register();
			( new Admin\VoucherAdmin( $this->vouchers ) )->register();
			( new Admin\Import( $this->code_pool ) )->register();
			( new Admin\StoreCreditAdmin( $this->store_credit, $this->vouchers ) )->register();
			( new Admin\CampaignAdmin( new Campaigns( $this->code_pool, $this->vouchers ) ) )->register();
			if ( Licensing\Entitlements::allows( 'reports' ) ) {
				( new Admin\ReportsAdmin() )->register();
			} else {
				// A bookmarked CSV link explains the lock instead of ending on a blank admin-post page.
				add_action( 'admin_post_vcap_report_csv', static fn() => wp_die( esc_html( Licensing\Entitlements::locked_message( 'reports' ) ), '', array( 'response' => 403, 'back_link' => true ) ) );
			}
		}

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( VCAP_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function load_textdomain(): void {
		I18n::register(); // de_DE_formal, de_CH, de_AT without own file → German (de_DE).
		load_plugin_textdomain( 'voucher-captain', false, dirname( plugin_basename( VCAP_FILE ) ) . '/languages' );
	}

	public function action_links( array $links ): array {
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

	/**
	 * Fixed-window rate limit for public endpoints (code guessing, expensive PDF previews).
	 *
	 * Counters live per visitor and site-wide in an atomic store (object cache increment, else one
	 * INSERT … ON DUPLICATE KEY UPDATE row in wp_options), so parallel requests and several web nodes count
	 * correctly. The window is fixed: hitting the limit does not extend it. With $count = false only the check
	 * runs – callers then count failed attempts with rate_limit_failure(), so customers who enter valid codes
	 * are never locked out and the site-wide cap only reacts to guessing.
	 *
	 * @return bool true when the request must be rejected
	 */
	public static function rate_limited( string $bucket, int $per_visitor, int $site_wide, int $window, bool $count = true ): bool {
		$blocked = false;
		foreach ( self::rate_keys( $bucket, $per_visitor, $site_wide ) as $key => $limit ) {
			$current = $count ? self::rate_increment( $key, $window ) - 1 : self::rate_read( $key, $window );
			if ( $current >= $limit ) {
				$blocked = true;
			}
		}
		return $blocked;
	}

	/** Counts a failed attempt (unknown code, wrong value) for rate_limited( …, false ). */
	public static function rate_limit_failure( string $bucket, int $window ): void {
		foreach ( array_keys( self::rate_keys( $bucket, 0, 0 ) ) as $key ) {
			self::rate_increment( $key, $window );
		}
	}

	/**
	 * Visitor address for rate limits: REMOTE_ADDR only, because X-Forwarded-For and similar headers can be
	 * spoofed. Behind a trusted reverse proxy or CDN return the real client address with "vcap_client_ip".
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'vcap_client_ip', $ip );
	}

	/** @return array<string,int> counter key => limit */
	private static function rate_keys( string $bucket, int $per_visitor, int $site_wide ): array {
		return array(
			md5( $bucket . '|' . self::client_ip() ) => $per_visitor,
			md5( $bucket . '|all' )                  => $site_wide,
		);
	}

	/** Option name of a counter in the current window: vcap_rl_{window end}_{key} (cleanup compares names). */
	private static function rate_option( string $key, int $window ): string {
		$window = max( 1, $window );
		$end    = ( intdiv( time(), $window ) + 1 ) * $window;
		return 'vcap_rl_' . sprintf( '%010d', $end ) . '_' . $key;
	}

	private static function rate_increment( string $key, int $window ): int {
		$name = self::rate_option( $key, $window );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, 'vcap_rate', $window );
			return (int) wp_cache_incr( $name, 1, 'vcap_rate' );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::rate_read( $key, $window );
	}

	private static function rate_read( string $key, int $window ): int {
		$name = self::rate_option( $key, $window );
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $name, 'vcap_rate' );
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Removes counters of finished windows (daily maintenance). */
	public static function purge_rate_limits(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s", $wpdb->esc_like( 'vcap_rl_' ) . '%', 'vcap_rl_' . sprintf( '%010d', time() ) . '_' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
