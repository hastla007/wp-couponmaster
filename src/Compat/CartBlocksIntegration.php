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
		// All texts come translated from PHP (Store API data, get_script_data()): no script translations to load.
		wp_register_script( self::HANDLE, VCAP_URL . 'assets/js/cart-blocks.js', array( 'wc-blocks-checkout', 'wp-element', 'wp-plugins' ), VCAP_VERSION, true );
	}

	public function get_script_handles() {
		return array( self::HANDLE );
	}

	/** G12: the same script in the editor, so the cart and checkout blocks show the voucher rows there too. */
	public function get_editor_script_handles() {
		return array( self::HANDLE );
	}

	/** Sample voucher rows for the editor (wcSettings "voucher-captain_data"); nothing on the storefront. */
	public function get_script_data() {
		return array( 'preview' => is_admin() ? \VCAP\CartBlocks::preview_data() : null );
	}
}
