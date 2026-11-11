<?php
/**
 * Plugin Name:       Voucher Captain
 * Plugin URI:        https://github.com/hastla007/wp-couponmaster
 * Description:       Sell personalised PDF vouchers, gift cards and event tickets with WooCommerce: visual PDF designer, recipient fields, scheduled delivery, balance-tracked gift cards, QR ticket check-in and code import.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.3
 * Author:            CouponMaster
 * Text Domain:       voucher-captain
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WCVS_VERSION', '1.0.0' );
define( 'WCVS_DB_VERSION', '1.0.0' );
define( 'WCVS_FILE', __FILE__ );
define( 'WCVS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCVS_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( WCVS_PATH . 'vendor/autoload.php' ) ) {
	require_once WCVS_PATH . 'vendor/autoload.php';
}

// Own PSR-4 autoloader so the plugin core loads even without Composer's optimised classmap.
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WCVS\\' ) ) {
			return;
		}
		$file = WCVS_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\WCVS\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WCVS\Install', 'deactivate' ) );

// Declare HPOS + block cart/checkout compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
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
		\WCVS\Plugin::instance();
	}
);

/**
 * Convenience accessor.
 */
function wcvs(): \WCVS\Plugin {
	return \WCVS\Plugin::instance();
}
