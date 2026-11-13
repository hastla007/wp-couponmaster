<?php
namespace VCAP\Compat;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/js/cart-blocks.js with the cart and checkout blocks (registered by VCAP\CartBlocks only once the
 * WooCommerce blocks integration registry exists, so the interface is always there).
 */
final class CartBlocksIntegration implements IntegrationInterface {
	public const HANDLE = 'vcap-cart-blocks';

	public function get_name() {
		return 'voucher-captain';
	}

	public function initialize() {
		wp_register_script( self::HANDLE, VCAP_URL . 'assets/js/cart-blocks.js', array( 'wc-blocks-checkout', 'wp-element', 'wp-plugins', 'wp-i18n' ), VCAP_VERSION, true );
		wp_set_script_translations( self::HANDLE, 'voucher-captain', VCAP_PATH . 'languages' );
	}

	public function get_script_handles() {
		return array( self::HANDLE );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		return array();
	}
}
