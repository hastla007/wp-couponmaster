<?php
namespace VCAP\Coupons;

use VCAP\Compat;
use VCAP\Mask;
use VCAP\PaymentVouchers;
use VCAP\Settings;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (D14, split from CouponManager): stored balances are charged before anything is fulfilled, a
 * shortfall holds the order for a review, and cancelled, refunded or failed orders release or book back what they
 * held.
 */
final class Settlement {
	public function __construct( private Settings $settings, private VoucherRepository $repo, private Balances $balances, private Reservations $reservations, private BookBack $book_back, private SingleUse $single_use, private OrderCoupons $lookup ) {}

	/**
	 * Deduct stored balances when an order using balance vouchers is paid; restore on cancellation.
	 */
	public const SHORTFALL_META = '_vcap_shortfall';

	/** Code => amount that could not be charged although the code was booked (partial shortfall). */
	public const SHORTFALL_AMOUNTS_META = '_vcap_shortfall_amounts';

	/** @var array<int, bool> Orders settled by settle_before_fulfilment() in this request. */
	private array $settled = array();

	/** Whether a stored balance of the order could not be charged and fulfilment waits for a review. */
	public static function has_shortfall( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::SHORTFALL_META );
	}

	public static function deduct_statuses(): array {
		return array_map( 'sanitize_key', (array) apply_filters( 'vcap_balance_deduct_statuses', array( 'processing', 'completed' ) ) );
	}

	/** Status hook, priority 1: commit and deduct before vouchers, bonus coupons or tickets are issued. */
	public function settle_before_fulfilment( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! in_array( $order->get_status(), self::deduct_statuses(), true ) ) {
			return;
		}
		$this->settled[ $order->get_id() ] = true;
		$this->reservations->commit_order_reservations( $order );
		// The order is in the middle of its status transition: the on-hold switch follows in order_status_changed().
		$this->deduct_order( $order, false );
	}

	public function issue_unless_shortfall( $issue, $order ) {
		return $issue && ! ( $order instanceof \WC_Order && self::has_shortfall( $order ) );
	}

	public function hold_on_shortfall( $hold, $order ) {
		return $hold || ( $order instanceof \WC_Order && self::has_shortfall( $order ) );
	}

	/**
	 * Admin decision for a shortfall: accept that the missing amount is not charged from the voucher (e.g.
	 * collected otherwise). Failed vouchers are booked with 0, the flag is cleared and fulfilment can follow.
	 */
	public function accept_shortfall( \WC_Order $order ): void {
		$applied      = OrderCoupons::order_map( $order, '_vcap_redemptions' );
		$reservations = OrderCoupons::order_map( $order, '_vcap_reservations' );
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = $this->lookup->find_voucher_for_code( $code, true ); // Books 0 (charges nothing): a reissued code too.
			if ( ! $voucher || ! $voucher->is_multi_use() || isset( $applied[ $code ] ) ) {
				continue;
			}
			$applied[ $code ] = 0.0;
			unset( $reservations[ $code ] );
		}
		$order->update_meta_data( '_vcap_redemptions', $applied );
		if ( $reservations ) {
			$order->update_meta_data( '_vcap_reservations', $reservations );
		} else {
			$order->delete_meta_data( '_vcap_reservations' );
		}
		$order->delete_meta_data( self::SHORTFALL_META );
		$order->delete_meta_data( self::SHORTFALL_AMOUNTS_META );
		$order->save();
		$order->add_order_note( __( 'Voucher shortfall accepted by an administrator; the order can be fulfilled.', 'voucher-captain' ), false, true );
	}

	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$deduct_statuses  = self::deduct_statuses();
		$restore_statuses = apply_filters( 'vcap_balance_restore_statuses', array( 'cancelled', 'refunded', 'failed' ) );

		if ( in_array( $to, $deduct_statuses, true ) ) {
			if ( empty( $this->settled[ $order->get_id() ] ) ) {
				$this->reservations->commit_order_reservations( $order );
				$this->deduct_order( $order );
			} elseif ( self::has_shortfall( $order ) && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'Voucher balance shortfall requires manual review before fulfilment.', 'voucher-captain' ) );
			}
			unset( $this->settled[ $order->get_id() ] );
		} elseif ( in_array( $to, $restore_statuses, true ) ) {
			$this->reservations->release_order_reservations( $order );
			if ( $this->settings->yes( 'vcap_restore_on_cancel' ) ) {
				$this->book_back->restore_order( $order );
				if ( 'refunded' === $to ) {
					$this->book_back->return_single_use( $order );
				}
			}
		}
		if ( $this->single_use->take_hold_after( $order->get_id() ) ) {
			// A single-use voucher of this order was voided or redeemed elsewhere (check_single_use_conflicts()).
			if ( in_array( $to, array( 'processing', 'completed' ), true ) && self::has_shortfall( $order ) && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'A voucher of this order was cancelled or redeemed elsewhere: manual review required before fulfilment.', 'voucher-captain' ) );
			}
		}
	}

	public function deduct_order( \WC_Order $order, bool $hold = true ): void {
		$applied      = OrderCoupons::order_map( $order, '_vcap_redemptions' );
		$reservations = OrderCoupons::order_map( $order, '_vcap_reservations' );
		// Codes charged with less than their discount (code => missing amount): such a gap stays open until an
		// administrator accepts it, even though the code is already booked.
		$missing      = OrderCoupons::order_map( $order, self::SHORTFALL_AMOUNTS_META );
		$changed      = false;
		$shortfall    = false;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var \WC_Order_Item_Coupon $item */
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $applied[ $code ] ) ) {
				continue;
			}
			$voucher = $this->lookup->find_voucher_for_code( $code );
			if ( ! $voucher && $this->lookup->is_reissued_code( $code ) ) {
				// AP-Q1-2: never charged to the voucher under its old code, never fulfilled with the free discount.
				$shortfall = true;
				$changed   = true;
				$order->add_order_note( OrderCoupons::reissued_code_message( Mask::code( $code ), true ) );
				continue;
			}
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$requested = $this->lookup->used_amount( $item );
			if ( null === $requested ) {
				// Foreign currency without exchange rate: never charge the balance 1:1; hold for a review.
				$shortfall = true;
				$changed   = true;
				$order->add_order_note(
					/* translators: 1: voucher code, 2: order currency */
					sprintf( __( 'Voucher %1$s: the discount could not be deducted from its balance because no exchange rate for %2$s is known. Review this order and adjust the charge before fulfilment.', 'voucher-captain' ), Mask::code( $voucher->code ), $order->get_currency() )
				);
				continue;
			}
			$deduction = $this->balances->deduct_balance_atomic(
				$voucher,
				$requested,
				/* translators: %s: order number */
				sprintf( __( 'Used in order #%s', 'voucher-captain' ), $order->get_order_number() ),
				$order->get_id()
			);
			if ( ! $deduction ) {
				// The discount was granted but nothing could be deducted (balance used up meanwhile, voucher voided or
				// expired, database without transactions): never let such an order through unnoticed.
				$shortfall = true;
				$changed   = true;
				$order->add_order_note(
					/* translators: %s: voucher code */
					sprintf( __( 'Voucher %s: the discount could not be deducted from its balance. Review this order and adjust the charge before fulfilment.', 'voucher-captain' ), Mask::code( $voucher->code ) )
				);
				continue;
			}
			unset( $reservations[ $code ] );
			$amount           = $deduction['amount'];
			$voucher->balance = $deduction['balance'];
			if ( $amount + 0.0001 < $requested ) {
				$shortfall        = true;
				$missing[ $code ] = round( $requested - $amount, $voucher->decimals() );
				$order->add_order_note( __( 'A stored-value voucher balance changed during checkout. Review this order and adjust the charge before fulfilment.', 'voucher-captain' ) );
			}
			$applied[ $code ] = $amount;
			$changed          = true;
			$order->add_order_note(
				sprintf(
					/* translators: 1: code, 2: amount, 3: remaining balance */
					__( 'Voucher %1$s: %2$s deducted, remaining balance %3$s.', 'voucher-captain' ),
					Mask::code( $voucher->code ),
					wc_price( $amount, array( 'currency' => $voucher->currency ) ),
					wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) )
				)
			);
		}
		if ( $missing ) {
			$order->update_meta_data( self::SHORTFALL_AMOUNTS_META, $missing );
		}
		if ( $shortfall || $missing ) {
			if ( ! self::has_shortfall( $order ) && class_exists( '\\PassCaptain\\Integrations\\WooCommerce\\OrderAdapter' ) && ! Compat\PassCaptain::supports_hold_fulfilment() ) {
				// Tickets in this order would be issued although the gift card could not be charged in full.
				$order->add_order_note( __( 'Gift card shortfall: the installed WP Pass Captain cannot hold ticket issuance. If this order contains tickets, check them – and update WP Pass Captain.', 'voucher-captain' ) );
			}
			$order->update_meta_data( self::SHORTFALL_META, 'yes' );
		} elseif ( self::has_shortfall( $order ) && ! $this->lookup->open_balance_coupons( $order, $applied ) ) {
			$order->delete_meta_data( self::SHORTFALL_META ); // Resolved (balance restored and charged on a later run).
			$changed = true;
		}
		if ( $changed ) {
			$order->update_meta_data( '_vcap_redemptions', $applied );
			if ( $reservations ) {
				$order->update_meta_data( '_vcap_reservations', $reservations );
			} else {
				$order->delete_meta_data( '_vcap_reservations' );
			}
			$order->save();
			if ( $hold && $shortfall && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'Voucher balance shortfall requires manual review before fulfilment.', 'voucher-captain' ) );
			}
		}
		if ( ! $shortfall && ! $missing ) {
			$this->write_off_rounding_rest( $order, $applied );
		}
	}

	/** Ledger event: a rest of at most one price unit left on a card by the net-price conversion, booked off (T15). */
	public const WRITTEN_OFF_EVENT = 'rounding_written_off';

	/** Order meta: code => rest booked off by write_off_rounding_rest(); given back with the charge on restore_order(). */
	public const WRITTEN_OFF_META = '_vcap_rounding_written_off';

	/**
	 * T15: in a tax-exclusive shop the net amount of a stored balance is chosen so that net + VAT is exactly the
	 * balance (GrossBalanceSplit). Where the tax rounding makes that impossible (net + 1 cent adds 2 cents gross),
	 * one minor unit stays on the card although the customer paid the rest of the order himself - a rest the card
	 * keeps showing in reports and reminder mails. Such a rest (at most one unit of the shop's price decimals, also
	 * sub-cent rests of older balances) is booked off with its own ledger event; cancelling or refunding the order
	 * gives it back together with the charge.
	 */
	private function write_off_rounding_rest( \WC_Order $order, array $applied ): void {
		if ( ! $applied || ! wc_tax_enabled() || wc_prices_include_tax() || (float) $order->get_total() <= 0 || ! apply_filters( 'vcap_balance_covers_tax', true, null ) ) {
			return; // Only where the conversion runs, and only when the cards did not cover the whole order.
		}
		$done    = OrderCoupons::order_map( $order, self::WRITTEN_OFF_META );
		$unit    = 1 / ( 10 ** wc_get_price_decimals() ) + 0.000001;
		$changed = false;
		foreach ( $applied as $code => $amount ) {
			$code = (string) $code;
			if ( isset( $done[ $code ] ) || (float) $amount <= 0 || null !== PaymentVouchers::paid_amount( $order, $code ) ) {
				continue;
			}
			$voucher = $this->lookup->find_voucher_for_code( $code );
			$fresh   = $voucher && $voucher->is_multi_use() ? $this->repo->get( $voucher->id ) : null;
			if ( ! $fresh || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->balance <= 0 || $fresh->balance > $unit || $this->balances->open_holds( $fresh ) > 0 ) {
				continue;
			}
			$rest = (float) $fresh->balance;
			$note = sprintf(
				/* translators: %s: order number */
				__( 'Rounding rest of the net-price conversion in order #%s booked off', 'voucher-captain' ),
				$order->get_order_number()
			);
			// Logged without the order id: the order's own ledger rows (charge, restore) stay the latest per order.
			if ( $this->balances->write_balance( $fresh, static fn( float $current ) => $current <= $unit ? 0.0 : $current, self::WRITTEN_OFF_EVENT, null, $note, 0 ) ) {
				$after = $this->repo->get( $fresh->id );
				if ( $after && $after->balance <= 0 ) {
					$done[ $code ] = $rest;
					$changed       = true;
				}
			}
		}
		if ( $changed ) {
			$order->update_meta_data( self::WRITTEN_OFF_META, $done );
			$order->save();
		}
	}
}
