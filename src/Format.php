<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Number formats shown to people.
 */
final class Format {
	/**
	 * A percentage with the shop's decimal separator and the language's spacing: "12.5%" in English, "12,5 %" in
	 * German (the translation of "%s%%" decides).
	 *
	 * @param int|float|string $value
	 */
	public static function percent( $value ): string {
		/* translators: %s: percentage number (e.g. 12.5); "%%" is the percent sign. Use "%s %%" for a space before it. */
		return sprintf( _x( '%s%%', 'percentage', 'voucher-captain' ), wc_format_localized_decimal( (string) (float) $value ) );
	}

	/** @var array<int, string> Order numbers already looked up (one export touches many rows of the same order). */
	private static array $order_numbers = array();

	/**
	 * The order number customers and accounting know (sequential numbers of Germanized & co. via
	 * get_order_number()), the internal ID when the order is gone, '' without an order (U5).
	 */
	public static function order_number( $order_id ): string {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return '';
		}
		if ( ! isset( self::$order_numbers[ $order_id ] ) ) {
			if ( count( self::$order_numbers ) > 2000 ) {
				self::$order_numbers = array();
			}
			$order                            = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			self::$order_numbers[ $order_id ] = $order instanceof \WC_Order ? (string) $order->get_order_number() : (string) $order_id;
		}
		return self::$order_numbers[ $order_id ];
	}

	/**
	 * Escaped HTML: "#number" linked to the order edit screen (U5); without an existing order "#ID", without an
	 * ID $empty.
	 */
	public static function order_link( $order_id, string $empty = '' ): string {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return esc_html( $empty );
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return '#' . esc_html( (string) $order_id );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a>';
	}
}
