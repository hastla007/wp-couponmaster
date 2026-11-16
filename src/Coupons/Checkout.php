<?php
namespace VCAP\Coupons;

use VCAP\Discounts;
use VCAP\Mask;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout (D14, split from CouponManager): the stored balances an order uses are reserved before it goes to the
 * payment gateway (classic checkout, Store API, pay page), and an abandoned earlier checkout gives its holds back.
 */
final class Checkout {
	public function __construct( private Balances $balances, private Reservations $reservations, private SingleUse $single_use, private OrderCoupons $lookup ) {}

	/**
	 * Reserve all balance vouchers on an order before WooCommerce sends it to the payment gateway.
	 * If any balance changed since checkout totals were calculated, release reservations made by
	 * this attempt and abort payment so the customer can refresh the cart.
	 *
	 * @param int|\WC_Order $order_or_id Order ID or order object.
	 */
	public function reserve_order( $order_or_id, $posted_data = null, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : ( $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id ) );
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			return;
		}

		$redemptions  = OrderCoupons::order_map( $order, '_vcap_redemptions' );
		$reservations = OrderCoupons::order_map( $order, '_vcap_reservations' );
		$coupons      = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $redemptions[ $code ] ) ) {
				continue;
			}
			$voucher = $this->lookup->find_voucher_for_code( $code );
			if ( ! $voucher && $this->lookup->is_reissued_code( $code ) ) {
				// AP-Q1-2: the code was replaced (reissued) – e.g. reported stolen – after this order was placed.
				throw new \Exception( esc_html( OrderCoupons::reissued_code_message( Mask::code( $code ) ) ) );
			}
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$amount = $this->lookup->used_amount( $item );
			if ( null === $amount ) {
				throw new \Exception( esc_html( CartCoupons::currency_message( (string) $order->get_currency() ) ) );
			}
			if ( $amount > 0 ) {
				$coupons[ $code ] = array( 'voucher' => $voucher, 'amount' => $amount );
			}
		}

		// Keep matching reservations and release removed/changed coupon lines before rebuilding the map.
		$made = array();
		foreach ( $reservations as $code => $reserved_amount ) {
			if ( isset( $redemptions[ $code ] ) ) {
				unset( $reservations[ $code ] );
				$order->update_meta_data( '_vcap_reservations', $reservations );
				$order->save();
				continue;
			}
			if ( isset( $coupons[ $code ] ) && abs( (float) $reserved_amount - $coupons[ $code ]['amount'] ) < 0.0001 ) {
				$made[ $code ] = (float) $reserved_amount;
				continue;
			}
			$reserved_voucher = $this->lookup->find_voucher_for_code( (string) $code, true );
			if ( ! $reserved_voucher || ! $this->balances->release_reservation_atomic( $reserved_voucher, $order->get_id(), __( 'Checkout reservation reconciled', 'voucher-captain' ) ) ) {
				throw new \Exception( esc_html__( 'An earlier voucher reservation could not be released. Please contact the store before trying again.', 'voucher-captain' ) );
			}
			unset( $reservations[ $code ] );
			$order->update_meta_data( '_vcap_reservations', $reservations );
			$order->save();
		}

		foreach ( $coupons as $code => $coupon_data ) {
			if ( isset( $made[ $code ] ) ) {
				continue;
			}
			$voucher = $coupon_data['voucher'];
			$amount  = $coupon_data['amount'];
			$reservation = $this->balances->reserve_balance_atomic(
				$voucher,
				$amount,
				$order->get_id(),
				sprintf( /* translators: %s: order number */ __( 'Reserved for order #%s', 'voucher-captain' ), $order->get_order_number() )
			);
			// The reservation may be a few cents below the discount when currency round trips exceed the balance
			// (same tolerance as reserve_balance_atomic()): that is the full balance, not a changed one.
			$short_by = $reservation ? $amount - (float) $reservation['amount'] : 0.0;
			$tolerant = $reservation && $short_by > -0.0001 && ( $short_by < 0.0001 || $short_by <= Balances::rounding_tolerance( $order, $amount, $voucher ) );
			if ( ! $reservation || 'reserved' !== $reservation['state'] || ! $tolerant ) {
				// Roll back this attempt's holds. A hold that cannot be released stays in the order's map (so the
				// stale-order sweep, a cancellation or deleting the order still finds it) and gets an order note.
				$kept = array();
				if ( ! $this->balances->release_reservation_atomic( $voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) ) ) {
					$held = $this->balances->open_hold( $voucher, $order->get_id() );
					if ( null !== $held ) {
						$kept[ $code ] = $held;
					}
				}
				foreach ( $made as $reserved_code => $reserved_amount ) {
					$reserved_voucher = $this->lookup->find_voucher_for_code( $reserved_code, true );
					if ( ! $reserved_voucher || ! $this->balances->release_reservation_atomic( $reserved_voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) ) ) {
						$kept[ $reserved_code ] = (float) $reserved_amount;
					}
				}
				if ( $kept ) {
					$order->update_meta_data( '_vcap_reservations', $kept );
					$order->add_order_note( __( 'Some voucher reservations could not be released. Review this order.', 'voucher-captain' ) );
				} else {
					$order->delete_meta_data( '_vcap_reservations' );
				}
				$order->save();
				$this->balance_gone = $voucher;
				throw new \Exception( esc_html__( 'A voucher balance changed while checkout was processing. Your order was not sent for payment. Refresh the cart and try again.', 'voucher-captain' ), self::BALANCE_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped, the second argument is the integer exception code.
			}
			$made[ $code ] = $reservation['amount'];
			// Persist after each successful reserve so an interrupted checkout can resume safely.
			$order->update_meta_data( '_vcap_reservations', $made );
			$order->save();
		}
	}

	public const SESSION_ORDER = 'vcap_checkout_order';

	/**
	 * A new checkout order of a session whose earlier checkout order is still unpaid (pending/failed): that
	 * earlier attempt was abandoned (e.g. refused by another plugin after our reservation, or the cart changed),
	 * so its balance reservations and promotion holds are released instead of blocking the customer for an hour.
	 * Paying the old order later through its pay link reserves again (reserve_retry_order).
	 *
	 * @param int|\WC_Order $order_or_id
	 */
	public function release_previous_checkout_order( $order_or_id ): void {
		$order   = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( ! $order instanceof \WC_Order || ! $session ) {
			return;
		}
		$previous_id = absint( $session->get( self::SESSION_ORDER ) );
		$session->set( self::SESSION_ORDER, $order->get_id() );
		$this->release_account_orders_with_same_vouchers( $order, $previous_id );
		if ( ! $previous_id || $previous_id === $order->get_id() ) {
			return;
		}
		$previous = wc_get_order( $previous_id );
		if ( ! $previous instanceof \WC_Order || ! $previous->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) || $previous->is_paid() ) {
			return;
		}
		// Same session: the same customer, or the guest who logged in (or the account that logged out) with the
		// same billing email; the session alone is not enough for orders of different people.
		$same_email = '' !== (string) $order->get_billing_email() && 0 === strcasecmp( (string) $previous->get_billing_email(), (string) $order->get_billing_email() );
		if ( (int) $previous->get_customer_id() !== (int) $order->get_customer_id() && ! $same_email ) {
			return;
		}
		$this->reservations->release_order_reservations( $previous );
		( new Discounts\Usage() )->revert( $previous->get_id() );
	}

	/**
	 * A customer account's new checkout releases its own unpaid earlier orders that hold a reservation of a voucher
	 * this order uses (e.g. an order placed before logging out, whose session is gone). Paying such an order later
	 * through its pay link reserves again (reserve_retry_order()).
	 */
	private function release_account_orders_with_same_vouchers( \WC_Order $order, int $skip ): void {
		$customer = (int) $order->get_customer_id();
		$codes    = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
		if ( $customer <= 0 || ! $codes ) {
			return;
		}
		$earlier = wc_get_orders(
			array(
				'customer_id' => $customer,
				'status'      => array( 'pending', 'failed', 'checkout-draft' ),
				'exclude'     => array_filter( array( $order->get_id(), $skip ) ),
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		foreach ( $earlier as $previous ) {
			if ( $previous instanceof \WC_Order && ! $previous->is_paid() && array_intersect( array_keys( OrderCoupons::order_map( $previous, '_vcap_reservations' ) ), $codes ) ) {
				$this->reservations->release_order_reservations( $previous );
			}
		}
	}

	/**
	 * Convert reservation failures into Store API client errors instead of uncaught server errors.
	 *
	 * The Store API also pays existing orders ("Pay for order" through checkout/{id}): like the classic pay form
	 * (reserve_retry_order()), a single-use voucher of the order that was cancelled, redeemed or reserved elsewhere
	 * since the checkout refuses the payment instead of letting the order be paid with its discount.
	 */
	public function reserve_store_api_order( $order ): void {
		try {
			if ( $order instanceof \WC_Order ) {
				$conflicts = $this->single_use->single_use_conflicts( $order, true );
				if ( $conflicts ) {
					throw new \Exception( esc_html( self::single_use_conflict_message( $conflicts[0]['voucher'] ) ) );
				}
			}
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_voucher_reservation_failed', esc_html( $e->getMessage() ), 409 );
			}
			throw $e;
		}
	}

	/** Exception code of reserve_order(): a gift card no longer has the balance the order uses. */
	public const BALANCE_CHANGED = 9102;

	/** The voucher whose balance was missing in the last failed reserve_order() (pay page message and note). */
	private ?Voucher $balance_gone = null;

	/**
	 * Re-reserve voucher balances before a customer retries payment for a failed order.
	 */
	public function reserve_retry_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			foreach ( $this->single_use->single_use_conflicts( $order, true ) as $conflict ) {
				wc_add_notice( self::single_use_conflict_message( $conflict['voucher'] ), 'error' );
			}
		}
		try {
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			wc_add_notice( $this->pay_page_message( $e, $order ), 'error' );
		}
	}

	/**
	 * Message for paying an existing order (pay page, Store API "pay for order"): there is no cart to refresh, so a
	 * gift card whose balance was used elsewhere meanwhile gets its own message, and the order a note for the shop.
	 */
	private function pay_page_message( \Throwable $e, $order ): string {
		if ( self::BALANCE_CHANGED !== $e->getCode() || ! $order instanceof \WC_Order ) {
			return $e->getMessage();
		}
		$code = $this->balance_gone ? $this->balance_gone->code : '';
		$order->add_order_note(
			sprintf(
				/* translators: %s: voucher code */
				__( 'Payment attempt refused: voucher %s no longer has the balance this order uses (it was used elsewhere). Contact the customer, or edit or cancel the order.', 'voucher-captain' ),
				Mask::code( $code )
			)
		);
		return sprintf(
			/* translators: %s: voucher code */
			__( 'Voucher %s no longer has enough balance for this order (it was used elsewhere meanwhile). This order cannot be paid as it is. Please contact us to complete it.', 'voucher-captain' ),
			$code
		);
	}

	/** Customer message: a single-use voucher of an unpaid order can no longer be used (single_use_conflicts()). */
	private static function single_use_conflict_message( Voucher $voucher ): string {
		/* translators: %s: voucher code */
		return sprintf( __( 'Voucher %s can no longer be used (it was cancelled or redeemed elsewhere). Please contact us to complete this order.', 'voucher-captain' ), $voucher->code );
	}
}
