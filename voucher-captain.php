<?php
/**
 * Plugin Name:       Voucher Captain
 * Plugin URI:        https://github.com/hastla007/wp-couponmaster
 * Description:       Sell personalised PDF vouchers, gift cards and store credit with WooCommerce: visual PDF designer, recipient fields, scheduled delivery, balance-tracked gift cards, code import and redemption in the shop, by API or in WP Pass Captain.
 * Version:           1.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.2.2
 * WC tested up to:   11.3
 * Author:            CouponMaster
 * Text Domain:       voucher-captain
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// Owner decision 16.11.2026 (same for all Captain plugins): PHP 8.5 or newer. On an older PHP the plugin loads nothing
// and only says why – WordPress itself refuses the activation there ("Requires PHP" above).
if ( version_compare( PHP_VERSION, '8.5', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Voucher Captain requires PHP 8.5 or newer.', 'voucher-captain' ) . '</p></div>';
		}
	);
	return;
}

define( 'VCAP_VERSION', '1.2.0' );
define( 'VCAP_DB_VERSION', '1.8.0' );
define( 'VCAP_FILE', __FILE__ );
define( 'VCAP_PATH', plugin_dir_path( __FILE__ ) );
define( 'VCAP_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( VCAP_PATH . 'vendor/autoload.php' ) ) {
	require_once VCAP_PATH . 'vendor/autoload.php';
}

// Own PSR-4 autoloader so the plugin core loads even without Composer's optimised classmap.
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'VCAP\\' ) ) {
			return;
		}
		$file = VCAP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Optional Form Captain adapter (voucher requests); stays inactive without Form Captain.
\VCAP\Integrations\FormCaptain\Bootstrap::register();

register_activation_hook( __FILE__, array( '\VCAP\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\VCAP\Install', 'deactivate' ) );
// Multisite: deleting a site drops its voucher tables too (they hold buyer and recipient data).
add_filter( 'wpmu_drop_tables', array( '\VCAP\Install', 'drop_site_tables' ), 10, 2 );

// Declare HPOS + block cart/checkout compatibility; the new product editor is not supported.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			// The new product editor has no voucher settings (classic product data tabs only): declared incompatible.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, false );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Voucher Captain requires WooCommerce to be installed and active.', 'voucher-captain' ) . '</p></div>';
					}
				}
			);
			return;
		}
		if ( ! \VCAP\Plugin::requirements_met() ) {
			// The plugin does not run: nothing would deduct gift card balances, so their coupons are refused.
			add_filter( 'woocommerce_coupon_is_valid', array( '\VCAP\Install', 'refuse_balance_coupon' ), 1000, 2 );
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						/* translators: %s: installed WooCommerce version */
						echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Voucher Captain requires WooCommerce 11.2.2 or newer (installed: %s). Please update WooCommerce.', 'voucher-captain' ), WC_VERSION ) ) . ' ' . esc_html__( 'Until then, gift cards and store credit are refused at checkout, because their balance cannot be deducted.', 'voucher-captain' ) . '</p></div>';
					}
				}
			);
			return;
		}
		if ( ! function_exists( 'vcap' ) ) {
			/**
			 * Convenience accessor of the running plugin. Defined only once WooCommerce passed the check above, so
			 * connectors can rely on function_exists( 'vcap' ) – or better, hook into "vcap_loaded".
			 */
			function vcap(): \VCAP\Plugin {
				return \VCAP\Plugin::instance();
			}
		}
		$vcap_plugin = \VCAP\Plugin::instance();
		/**
		 * Voucher Captain has booted: vcap() and its services (api_v1, redemptions …) can be used from here on.
		 *
		 * @since 1.2.0
		 * @param \VCAP\Plugin $vcap_plugin The running plugin.
		 */
		do_action( 'vcap_loaded', $vcap_plugin );
	}
);
