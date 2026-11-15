<?php
namespace VCAP\Coupons;

use VCAP\Compat;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * M12-13: the balance a gift card holds for this visitor's own unpaid checkout order counts as available in the cart.
 *
 * A checkout reserves the stored balance (Checkout::reserve_order(), priority 51) before the payment gateway runs.
 * When the payment fails or is abandoned and the customer tries again, WooCommerce checks the cart (coupon
 * validation, totals) before the abandoned order gives its reservation back (Checkout::release_previous_checkout_order(),
 * priority 49 of *_checkout_order_processed): without this, the card would be refused as "fully redeemed" or cut down
 * to what is left, and the customer would pay more by card. The reservation of the session's own order (pending,
 * failed or checkout-draft, unpaid, same customer) is therefore added to the balance in cart validation and to the
 * coupon amount of the cart calculation. Release and new reservation stay where they are (49/51).
 */
final class OwnHold {
	/** Session keys naming this visitor's checkout orders: ours, the classic checkout's and the Store API's draft. */
	private const SESSION_KEYS = array( Checkout::SESSION_ORDER, 'order_awaiting_payment', 'store_api_draft_order' );

	/** @var array<string, float> request cache: voucher id|balance|order ids => held amount */
	private static array $cache = array();

	/** @var \WeakMap<\WC_Coupon, true>|null coupon objects whose own amount already includes the hold, or that belong to an order */
	private static ?\WeakMap $skip = null;

	/**
	 * Base-currency amount of the voucher's balance held by an open reservation of this visitor's own unpaid checkout
	 * order (0 when there is none, outside a session or for other vouchers).
	 */
	public static function amount( Voucher $voucher ): float {
		if ( ! $voucher->is_multi_use() || ! $voucher->id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0.0;
		}
		$ids = self::session_order_ids();
		if ( ! $ids ) {
			return 0.0;
		}
		$key = $voucher->id . '|' . $voucher->balance . '|' . implode( ',', $ids );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$code     = wc_format_coupon_code( $voucher->code );
		$balances = new Balances( function_exists( 'vcap' ) && isset( vcap()->vouchers ) ? vcap()->vouchers : new VoucherRepository() );
		$held     = 0.0;
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || $order->is_paid() || ! $order->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) ) {
				continue;
			}
			// The session alone names the order; a customer's order counts only for that customer.
			if ( (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() !== get_current_user_id() ) {
				continue;
			}
			if ( ! isset( OrderCoupons::order_map( $order, '_vcap_reservations' )[ $code ] ) ) {
				continue;
			}
			$held += (float) ( $balances->open_hold( $voucher, $order_id ) ?? 0.0 );
		}
		self::$cache = array_slice( self::$cache, -50, null, true ) + array( $key => round( $held, $voucher->decimals() ) );
		return self::$cache[ $key ];
	}

	/** @return int[] distinct order ids of this visitor's checkout orders kept in the session */
	private static function session_order_ids(): array {
		$ids = array();
		foreach ( self::SESSION_KEYS as $session_key ) {
			$id = absint( WC()->session->get( $session_key ) );
			if ( $id ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/** The voucher behind a Voucher Captain stored-balance coupon (null for other coupons). */
	private static function voucher_for( \WC_Coupon $coupon ): ?Voucher {
		if ( 'yes' !== $coupon->get_meta( '_vcap_voucher' ) || 'fixed_cart' !== $coupon->get_discount_type( 'edit' ) || ! $coupon->get_id() ) {
			return null;
		}
		$repo    = function_exists( 'vcap' ) && isset( vcap()->vouchers ) ? vcap()->vouchers : new VoucherRepository();
		$voucher = $repo->get_by_coupon( $coupon->get_id() );
		return $voucher && $voucher->is_multi_use() ? $voucher : null;
	}

	/** Whether the coupon is applied to the main cart (an order of the same code is calculated elsewhere). */
	private static function in_cart( \WC_Coupon $coupon ): bool {
		return function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart && WC()->cart->has_discount( $coupon->get_code() );
	}

	/** The hold of this coupon's voucher in the units of the coupon's own amount (see in_coupon_units()). */
	private static function for_coupon( \WC_Coupon $coupon ): float {
		$voucher = self::voucher_for( $coupon );
		$hold    = $voucher ? self::amount( $voucher ) : 0.0;
		return $hold > 0 ? self::in_coupon_units( $coupon, $hold ) : 0.0;
	}

	/**
	 * A base-currency amount in the units of the coupon's own amount: currency switchers that convert coupons when
	 * they are loaded (WCML, Aelia) keep the cart currency in the coupon object, the stored coupon meta keeps the base
	 * currency (M12-33).
	 */
	public static function in_coupon_units( \WC_Coupon $coupon, float $base ): float {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		if ( '' === $currency || get_option( 'woocommerce_currency' ) === $currency ) {
			return $base;
		}
		$stored = $coupon->get_id() ? (float) get_post_meta( $coupon->get_id(), 'coupon_amount', true ) : 0.0;
		$own    = (float) $coupon->get_amount( 'edit' );
		if ( $stored > 0 ) {
			// The switcher's own factor: the coupon object holds the converted amount, the meta the base amount.
			return abs( $own - $stored ) < 0.000001 ? $base : $base * $own / $stored;
		}
		if ( in_array( Compat\MultiCurrency::adapter(), array( 'WooCommerce Multilingual', 'Aelia Currency Switcher' ), true ) ) {
			return Compat\MultiCurrency::from_base( $base, $currency ) ?? $base;
		}
		return $base;
	}

	/**
	 * Cart calculation (woocommerce_coupon_get_items_to_apply, priority 11, before the line rules and the gross
	 * balance split read the amount): the coupon object of the main cart gets its own amount raised by the hold.
	 *
	 * @param array              $items     Items the coupon applies to.
	 * @param \WC_Coupon         $coupon    Coupon.
	 * @param \WC_Discounts|null $discounts Running discount calculation.
	 */
	public static function raise_for_cart( $items, $coupon, $discounts = null ) {
		if ( ! $coupon instanceof \WC_Coupon || ! $discounts instanceof \WC_Discounts || ! $discounts->get_object() instanceof \WC_Cart || self::skipped( $coupon ) ) {
			return $items;
		}
		$hold = self::in_cart( $coupon ) ? self::for_coupon( $coupon ) : 0.0;
		self::skip( $coupon );
		if ( $hold > 0 ) {
			$coupon->set_amount( (float) $coupon->get_amount( 'edit' ) + $hold );
		}
		return $items;
	}

	/**
	 * woocommerce_coupon_get_amount (cart context only): the amount read from a cart coupon object (PaymentVouchers,
	 * remaining-balance notes) includes the hold. Coupon objects of orders (order recalculation) and objects already
	 * raised are left alone.
	 *
	 * @param mixed      $amount Amount.
	 * @param \WC_Coupon $coupon Coupon.
	 */
	public static function amount_filter( $amount, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || self::skipped( $coupon ) || ! self::in_cart( $coupon ) ) {
			return $amount;
		}
		$hold = self::for_coupon( $coupon );
		return $hold > 0 ? (float) $amount + $hold : $amount;
	}

	/** A coupon object used for an order (order recalculation): never raised by the cart's hold. */
	public static function skip( \WC_Coupon $coupon ): void {
		self::$skip       ??= new \WeakMap();
		self::$skip[ $coupon ] = true;
	}

	private static function skipped( \WC_Coupon $coupon ): bool {
		return null !== self::$skip && isset( self::$skip[ $coupon ] );
	}

	/** Tests: forget the request cache. */
	public static function reset(): void {
		self::$cache = array();
		self::$skip  = null;
	}
}
