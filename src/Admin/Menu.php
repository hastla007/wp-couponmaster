<?php
namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu: Vouchers (list/detail), PDF templates, Field groups, Import codes, Scanner, Settings.
 */
final class Menu {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'settings_link' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'parent_file', array( $this, 'parent_file' ) );
	}

	public static function cap(): string {
		return current_user_can( 'vcap_manage_vouchers' ) ? 'vcap_manage_vouchers' : 'manage_woocommerce';
	}

	public static function scan_cap(): string {
		return current_user_can( 'vcap_scan_tickets' ) ? 'vcap_scan_tickets' : 'manage_woocommerce';
	}

	public function menu(): void {
		$voucher_admin = new VoucherAdmin( vcap()->vouchers );
		add_menu_page(
			__( 'Vouchers', 'voucher-captain' ),
			__( 'Vouchers', 'voucher-captain' ),
			self::cap(),
			'vcap-vouchers',
			array( $voucher_admin, 'page' ),
			'dashicons-tickets-alt',
			56
		);
		add_submenu_page( 'vcap-vouchers', __( 'Issued vouchers', 'voucher-captain' ), __( 'Issued vouchers', 'voucher-captain' ), self::cap(), 'vcap-vouchers', array( $voucher_admin, 'page' ) );
		add_submenu_page( 'vcap-vouchers', __( 'Import codes', 'voucher-captain' ), __( 'Import codes', 'voucher-captain' ), self::cap(), 'vcap-import', array( new Import( vcap()->code_pool ), 'page' ) );
		add_submenu_page( 'vcap-vouchers', __( 'Generate codes', 'voucher-captain' ), __( 'Generate codes', 'voucher-captain' ), self::cap(), 'vcap-generate', array( new CampaignAdmin( new \VCAP\Campaigns( vcap()->code_pool, vcap()->vouchers ) ), 'page' ) );
		$reports = \VCAP\Licensing\Entitlements::allows( 'reports' ) ? array( new ReportsAdmin(), 'page' ) : array( $this, 'reports_locked' );
		add_submenu_page( 'vcap-vouchers', __( 'Reports', 'voucher-captain' ), __( 'Reports', 'voucher-captain' ), self::cap(), 'vcap-reports', $reports );
		add_submenu_page( 'vcap-vouchers', __( 'Scanner', 'voucher-captain' ), __( 'Scanner', 'voucher-captain' ), self::scan_cap(), 'vcap-scanner', array( new Scanner(), 'page' ) );
		if ( \VCAP\Compat\PassCaptain::active() ) {
			// Link into Pass Captain's own menu (both plugins keep their menus).
			add_submenu_page( 'vcap-vouchers', __( 'WP Pass Captain', 'voucher-captain' ), __( 'WP Pass Captain ↗', 'voucher-captain' ), 'wpcap_checkin', 'admin.php?page=wp-pass-captain' );
		}
	}

	/** Reports without the premium right: explain why instead of showing the reports. */
	public function reports_locked(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Reports', 'voucher-captain' ) . '</h1>' . \VCAP\Licensing\Entitlements::locked_notice( 'reports' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in locked_notice().
	}

	public function settings_link(): void {
		add_submenu_page( 'vcap-vouchers', __( 'Settings', 'voucher-captain' ), __( 'Settings', 'voucher-captain' ), 'manage_woocommerce', 'admin.php?page=wc-settings&tab=vcap' );
	}

	public function parent_file( $parent ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( \VCAP\TemplatePostType::POST_TYPE, \VCAP\Fields::POST_TYPE ), true ) ) {
			$submenu_file = 'edit.php?post_type=' . $screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'vcap-vouchers';
		}
		return $parent;
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'vcap' ) ) {
			return;
		}
		wp_enqueue_style( 'vcap-admin', VCAP_URL . 'assets/css/admin.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-admin', VCAP_URL . 'assets/js/admin.js', array( 'jquery' ), VCAP_VERSION, true );
	}
}
