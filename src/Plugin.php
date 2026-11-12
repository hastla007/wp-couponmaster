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

		( new TemplatePostType() )->register();
		( new ProductSettings() )->register();
		( new CustomAmount() )->register();
		( new Preview( $this->pdf ) )->register();
		( new Emails() )->register();
		( new Privacy( $this->vouchers ) )->register();
		( new CouponLink( $this->settings ) )->register();
		( new VoucherPurchaseRules( $this->settings ) )->register();
		( new FirstOrderCoupons() )->register();
		( new CouponSkuRules() )->register();
		$referrals = new Referrals( $this->settings, $this->store_credit );
		$referrals->register();
		( new Shortcodes( $this->downloads, $referrals ) )->register();
		$promotions = new Discounts\Repository();
		$promotions->register();
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
		( new API\V1\RestController( $this->redemptions ) )->register();

		( new Compat\Subscriptions() )->register();
		( new Compat\Wpml() )->register();
		( new Compat\MultiCurrency() )->register();
		( new Compat\AffiliateCaptain() )->register();
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
		}

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( VCAP_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function load_textdomain(): void {
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
	 * Simple fixed-window rate limit for public endpoints. The per-visitor bucket can be evaded by spoofing
	 * proxy headers, so a site-wide bucket caps the total as well.
	 *
	 * @return bool true when the request must be rejected
	 */
	public static function rate_limited( string $bucket, int $per_visitor, int $site_wide, int $window ): bool {
		$ip   = (string) \WC_Geolocation::get_ip_address();
		$keys = array(
			'vcap_rl_' . $bucket . '_' . md5( $ip ) => $per_visitor,
			'vcap_rl_' . $bucket . '_all'            => $site_wide,
		);
		$blocked = false;
		foreach ( $keys as $key => $limit ) {
			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				$blocked = true;
			}
			set_transient( $key, $count + 1, $window );
		}
		return $blocked;
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
