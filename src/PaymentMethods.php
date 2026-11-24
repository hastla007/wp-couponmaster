<?php
/**
 * Payment methods for buying vouchers (SET-VC-19).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-19: payment methods a shop does not want for voucher purchases ("Do not sell vouchers with …", default
 * none) disappear while a voucher product is in the cart – in the classic and the block checkout (both ask
 * WooCommerce for the available gateways, which also refuses a hidden gateway posted anyway) and when an existing
 * order with voucher items is paid. Carts without vouchers keep every payment method.
 *
 * Also the list of payment methods paid later ("offline": cash on delivery, bank transfer, cheque by default), whose
 * vouchers wait for the confirmed payment (Issuer::offline_payment()). Both lists end in a filter, which wins.
 *
 * @since 1.3.0
 */
final class PaymentMethods {
	/** Option: gateway ids not offered while the cart contains a voucher product. */
	public const EXCLUDED_OPTION = 'vcap_voucher_excluded_gateways';
	/** Option: gateway ids that set an order to "Processing" before the money arrived. */
	public const OFFLINE_OPTION = 'vcap_offline_payment_methods';

	public function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'available_gateways' ), 20 );
	}

	/**
	 * WooCommerce filter "woocommerce_available_payment_gateways".
	 *
	 * @param mixed $gateways Gateway id => gateway.
	 * @return mixed
	 */
	public function available_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || array() === $gateways || array() === self::excluded() ) {
			return $gateways;
		}
		return self::without_excluded( $gateways, self::voucher_in_checkout() );
	}

	/**
	 * The gateways without the excluded ones when $voucher (a voucher product is bought), else unchanged.
	 *
	 * @param array<string|int, mixed> $gateways Gateway id => gateway.
	 * @return array<string|int, mixed>
	 */
	public static function without_excluded( array $gateways, bool $voucher ): array {
		if ( ! $voucher ) {
			return $gateways;
		}
		foreach ( self::excluded() as $id ) {
			unset( $gateways[ $id ] );
		}
		return $gateways;
	}

	/**
	 * Gateway ids not offered for voucher purchases: the setting, then the filter.
	 *
	 * @return string[]
	 */
	public static function excluded(): array {
		/**
		 * Payment gateway ids that are not offered while the cart (or the order being paid) contains a voucher
		 * product. Default: the setting "Do not sell vouchers with" (empty). The filter wins over the setting.
		 *
		 * @since 1.3.0
		 * @param string[] $ids Gateway ids.
		 */
		$ids = apply_filters( 'vcap_voucher_excluded_gateways', self::ids( Settings::value( self::EXCLUDED_OPTION ) ) );
		return self::ids( $ids );
	}

	/**
	 * Gateway ids paid later (setting "Payment methods paid later"; the filter "vcap_offline_payment_methods" in
	 * Issuer::offline_payment() wins).
	 *
	 * @return string[]
	 */
	public static function offline(): array {
		return self::ids( Settings::value( self::OFFLINE_OPTION ) );
	}

	/**
	 * A stored or filtered list as gateway ids (strings, no empty entries, no duplicates).
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	private static function ids( $value ): array {
		$ids = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $id ) {
			$id = is_scalar( $id ) ? trim( (string) $id ) : '';
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Whether a voucher product is being bought: in the cart, or in the order paid on the "Pay for order" page. */
	public static function voucher_in_checkout(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( ! $order instanceof \WC_Order ) {
				return false;
			}
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && self::is_voucher( $item->get_product() ) ) {
					return true;
				}
			}
			return false;
		}
		$cart = function_exists( 'WC' ) && isset( WC()->cart ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return false;
		}
		foreach ( $cart->get_cart() as $values ) {
			if ( self::is_voucher( $values['data'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param mixed $product */
	private static function is_voucher( $product ): bool {
		return $product instanceof \WC_Product && ProductConfig::is_voucher( $product );
	}

	/**
	 * Gateways to choose from (id => name): the shop's gateways plus ids saved or in the default list that are not
	 * installed (so saving keeps them).
	 *
	 * @return array<string, string>
	 */
	private static function gateway_choices(): array {
		$choices = array();
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'payment_gateways' ) ) ) {
			foreach ( (array) WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( is_object( $gateway ) ) {
					$title          = method_exists( $gateway, 'get_method_title' ) ? (string) $gateway->get_method_title() : '';
					$title          = '' !== $title ? $title : ( method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : '' );
					$choices[ (string) $id ] = wp_strip_all_tags( '' !== $title ? $title : (string) $id );
				}
			}
		}
		$known = array_merge( self::ids( SettingsRegistry::default( self::OFFLINE_OPTION ) ), self::offline(), self::ids( Settings::value( self::EXCLUDED_OPTION ) ) );
		foreach ( $known as $id ) {
			if ( ! isset( $choices[ $id ] ) ) {
				/* translators: %s: payment gateway id */
				$choices[ $id ] = sprintf( __( '%s (not installed)', 'voucher-captain' ), $id );
			}
		}
		return $choices;
	}

	/**
	 * Block "Payment methods for vouchers" of the section "General" (Settings::general_fields()).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		$choices = self::gateway_choices();
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Payment methods for vouchers', 'voucher-captain' ),
				'id'    => 'vcap_payment_methods',
			),
			SettingsRegistry::field(
				self::EXCLUDED_OPTION,
				array(
					'title'   => __( 'Do not sell vouchers with', 'voucher-captain' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'options' => $choices,
					'desc'    => __( 'These payment methods are not offered in the classic and the block checkout (nor when an existing order is paid) while the cart contains a voucher product. Orders without vouchers keep every payment method. Empty = vouchers can be paid with every payment method.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OFFLINE_OPTION,
				array(
					'title'   => __( 'Payment methods paid later', 'voucher-captain' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'options' => $choices,
					'desc'    => __( 'Payment methods that set an order to "Processing" before the money arrived (default: cash on delivery, bank transfer, cheque; add e.g. invoice or pay at pickup). Vouchers of such orders are issued only once the payment is confirmed: the order is completed, the payment is recorded, or a paid status is set by hand.', 'voucher-captain' ),
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vcap_payment_methods',
			),
		);
	}
}
