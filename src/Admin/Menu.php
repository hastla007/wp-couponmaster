<?php
namespace WCVS\Admin;

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
		return current_user_can( 'wcvs_manage_vouchers' ) ? 'wcvs_manage_vouchers' : 'manage_woocommerce';
	}

	public static function scan_cap(): string {
		return current_user_can( 'wcvs_scan_tickets' ) ? 'wcvs_scan_tickets' : 'manage_woocommerce';
	}

	public function menu(): void {
		$voucher_admin = new VoucherAdmin( wcvs()->vouchers );
		add_menu_page(
			__( 'Vouchers', 'voucher-captain' ),
			__( 'Vouchers', 'voucher-captain' ),
			self::cap(),
			'wcvs-vouchers',
			array( $voucher_admin, 'page' ),
			'dashicons-tickets-alt',
			56
		);
		add_submenu_page( 'wcvs-vouchers', __( 'Issued vouchers', 'voucher-captain' ), __( 'Issued vouchers', 'voucher-captain' ), self::cap(), 'wcvs-vouchers', array( $voucher_admin, 'page' ) );
		add_submenu_page( 'wcvs-vouchers', __( 'Import codes', 'voucher-captain' ), __( 'Import codes', 'voucher-captain' ), self::cap(), 'wcvs-import', array( new Import( wcvs()->code_pool ), 'page' ) );
		add_submenu_page( 'wcvs-vouchers', __( 'Scanner', 'voucher-captain' ), __( 'Scanner', 'voucher-captain' ), self::scan_cap(), 'wcvs-scanner', array( new Scanner(), 'page' ) );
	}

	public function settings_link(): void {
		add_submenu_page( 'wcvs-vouchers', __( 'Settings', 'voucher-captain' ), __( 'Settings', 'voucher-captain' ), 'manage_woocommerce', 'admin.php?page=wc-settings&tab=wcvs' );
	}

	public function parent_file( $parent ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( \WCVS\TemplatePostType::POST_TYPE, \WCVS\Fields::POST_TYPE ), true ) ) {
			$submenu_file = 'edit.php?post_type=' . $screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'wcvs-vouchers';
		}
		return $parent;
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'wcvs' ) ) {
			return;
		}
		wp_enqueue_style( 'wcvs-admin', WCVS_URL . 'assets/css/admin.css', array(), WCVS_VERSION );
		wp_enqueue_script( 'wcvs-admin', WCVS_URL . 'assets/js/admin.js', array( 'jquery' ), WCVS_VERSION, true );
	}
}
