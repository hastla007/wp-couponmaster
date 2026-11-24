<?php
/**
 * Plugin Name:       Voucher Captain
 * Plugin URI:        https://github.com/hastla007/wp-couponmaster
 * Description:       Sell personalised PDF vouchers, gift cards and store credit with WooCommerce: visual PDF designer, recipient fields, scheduled delivery, balance-tracked gift cards, code import and redemption in the shop, by API or in Pass Captain.
 * Version:           1.3.0
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.2.2
 * WC tested up to:   11.3
 * Author:            Captain Suite
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

define( 'VCAP_VERSION', '1.3.0' );
define( 'VCAP_DB_VERSION', '1.8.0' );
/*
 * Version of the public extension contract (hooks, vcap()->api_v1, vcap()->redemptions, REST vcap/v1,
 * VCAP\Contracts\*) – independent of the plugin version. Major = API generation (1 = v1), minor rises with every
 * release that adds hooks, methods or arguments. Add-ons and connectors check this instead of single hooks
 * (CONNECTORS.md, section 6; docs/HOOKS.md). The constant exists since plugin 1.3.0; 1.0.0 (plugin 1.1.0) and 1.1.0
 * (plugin 1.2.0) were assigned afterwards and cannot be read on those versions. 1.2.0 = plugin 1.3.0 with
 * the read functions vcap_read_*() / vcap()->read (API.md → "Read functions"), 1.3.0 = plugin 1.3.0 with the
 * voucher commands vcap_command_*() / vcap()->commands (API.md → "Voucher commands"), 1.4.0 = plugin 1.3.0 with the
 * connector access of vcap()->api_v1 (voucher(), voucher_by_code(), log_event(), admin_url(), line_snapshot();
 * API.md → "Connector access"), 1.5.0 = plugin 1.3.0 with contact and consent of a voucher id, off by default
 * (vcap()->contact, vcap_read_voucher_contact(); API.md → "Contact and consent"), 1.6.0 = plugin 1.3.0 with the
 * read function of the command limits vcap_read_command_limits() / vcap()->commands->limits() (SET-WC-15), 1.7.0 =
 * plugin 1.3.0 with journal_id in the context of vcap_store_credit_changed and vcap_gift_card_topped_up (VC-12), 1.8.0
 * = plugin 1.3.0 with the settings register and the extension filters of the settings (vcap_settings_registry,
 * vcap_settings_validate_value, vcap_settings_hints, vcap_pro_hints_hidden, vcap_country_profiles …) and the Form
 * Captain hooks vcap_form_captain_* (CONNECTORS.md, section 6; PV-P6-2).
 */
define( 'VCAP_CONTRACT_VERSION', '1.8.0' );
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
		// Public read functions vcap_read_*() (contract 1.2.0) and commands vcap_command_*() (contract 1.3.0), API.md.
		require_once VCAP_PATH . 'src/functions.php';
		/**
		 * Voucher Captain has booted: vcap() and its services (api_v1, redemptions …) can be used from here on.
		 *
		 * @since 1.2.0
		 * @param \VCAP\Plugin $vcap_plugin The running plugin.
		 */
		do_action( 'vcap_loaded', $vcap_plugin );
		// Settings entries that add-ons registered in "vcap_loaded" count from here on (PV-P6-1).
		\VCAP\SettingsRegistry::reset();
	}
);
