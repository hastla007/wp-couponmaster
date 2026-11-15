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

	/** @var array<int, array{0: float, 1: string}|null> M12-29: order total and currency per order id (looked up with the number). */
	private static array $order_totals = array();

	/**
	 * The order number customers and accounting know (sequential numbers of Germanized & co. via
	 * get_order_number()), the internal ID when the order is gone, '' without an order (U5).
	 */
	/**
	 * AP-P1-2: looks up the order numbers of one export batch with one order query instead of one per row; the
	 * orders themselves are not kept here (only their numbers, at most a few thousand); callers flush the runtime
	 * cache after each batch.
	 *
	 * @param array<int|string> $order_ids
	 */
	public static function prime_order_numbers( array $order_ids ): void {
		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ), array_keys( self::$order_numbers ) ) );
		if ( ! $ids || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		if ( count( self::$order_numbers ) + count( $ids ) > 2000 ) {
			self::$order_numbers = array();
			self::$order_totals  = array();
		}
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$found = array();
			foreach ( (array) wc_get_orders( array( 'post__in' => $chunk, 'limit' => count( $chunk ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
				if ( $order instanceof \WC_Order ) {
					$found[ $order->get_id() ]               = (string) $order->get_order_number();
					self::$order_totals[ $order->get_id() ] = array( (float) $order->get_total(), (string) $order->get_currency() );
				}
			}
			foreach ( $found as $id => $number ) {
				self::$order_numbers[ $id ] = $number;
			}
			// Not found by the query (gone, trashed, another status): order_number() looks them up one by one.
		}
	}

	/**
	 * M12-29: total and currency of an order (journal column "Order amount"), null without an order or when it is
	 * gone. Read with the order numbers of an export batch (prime_order_numbers()).
	 *
	 * @param int|string $order_id
	 * @return array{0: float, 1: string}|null
	 */
	public static function order_total( $order_id ): ?array {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $order_id, self::$order_totals ) ) {
			if ( count( self::$order_totals ) > 2000 ) {
				self::$order_totals = array();
			}
			$order                           = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			self::$order_totals[ $order_id ] = $order instanceof \WC_Order ? array( (float) $order->get_total(), (string) $order->get_currency() ) : null;
		}
		return self::$order_totals[ $order_id ];
	}

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
