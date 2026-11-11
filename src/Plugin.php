<?php
namespace WCVS;

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

		( new TemplatePostType() )->register();
		( new ProductSettings() )->register();
		( new CustomAmount() )->register();
		( new Preview( $this->pdf ) )->register();
		( new Emails() )->register();
		( new Privacy( $this->vouchers ) )->register();

		$this->settings->register();
		$this->coupons->register();
		$this->downloads->register();
		$this->delivery->register();
		$this->issuer->register();
		$this->fields->register();
		$this->tickets->register();

		( new Compat\Subscriptions() )->register();
		( new Compat\Wpml() )->register();
		( new Compat\MultiCurrency() )->register();

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
			( new Admin\OrderAdmin( $this->vouchers, $this->issuer ) )->register();
			( new Admin\VoucherAdmin( $this->vouchers ) )->register();
			( new Admin\Import( $this->code_pool ) )->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( WCVS_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wc-voucher-studio', false, dirname( plugin_basename( WCVS_FILE ) ) . '/languages' );
	}

	public function action_links( array $links ): array {
		$extra = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=wcvs' ) ) . '">' . esc_html__( 'Settings', 'wc-voucher-studio' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wcvs-vouchers' ) ) . '">' . esc_html__( 'Vouchers', 'wc-voucher-studio' ) . '</a>',
		);
		return array_merge( $extra, $links );
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || class_exists( '\Dompdf\Dompdf' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Voucher Studio: the PDF libraries are missing. Install the release ZIP or run "composer install --no-dev" in the plugin folder.', 'wc-voucher-studio' ) . '</p></div>';
	}

	/**
	 * Render a PHP template from /templates, overridable from the theme at woocommerce/wc-voucher-studio/.
	 */
	public static function template( string $name, array $args = array() ): void {
		wc_get_template( $name, $args, 'wc-voucher-studio/', WCVS_PATH . 'templates/' );
	}

	public static function template_html( string $name, array $args = array() ): string {
		return wc_get_template_html( $name, $args, 'wc-voucher-studio/', WCVS_PATH . 'templates/' );
	}
}
