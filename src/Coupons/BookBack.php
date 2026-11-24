<?php
namespace VCAP\Coupons;

use VCAP\Attention;
use VCAP\Mask;
use VCAP\OrderReturn;
use VCAP\TopUp;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Booking an order's voucher charges back (D14, split from CouponManager): stored balances on cancellation or
 * refund (under the same named lock as a return booked by hand, AP-Q1-6), single-use vouchers of a fully refunded
 * order, and the notice when the card that gets the value back is expired or cancelled.
 */
final class BookBack {
	public function __construct( private VoucherRepository $repo, private Balances $balances, private OrderCoupons $lookup ) {}

	public function restore_order( \WC_Order $order ): void {
		global $wpdb;
		if ( ! OrderCoupons::order_map( $order, '_vcap_redemptions' ) ) {
			return;
		}
		// AP-Q1-6: the same named lock as a booking back by hand (OrderReturn::book_back()), so a return and the
		// cancellation never both book the same part back.
		$lock = OrderReturn::lock_name( $order->get_id() );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$order->add_order_note( __( 'Some voucher balances could not be restored. Review this order and retry the refund handling.', 'voucher-captain' ) );
			return;
		}
		try {
			$this->restore_order_locked( $order );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private function restore_order_locked( \WC_Order $order ): void {
		// Read under the lock: a return booked meanwhile is in the stored order, not in this object.
		$stored  = wc_get_order( $order->get_id() );
		$stored  = $stored instanceof \WC_Order && ! $stored instanceof \WC_Order_Refund ? $stored : $order;
		$applied = OrderCoupons::order_map( $order, '_vcap_redemptions' );
		// Parts already booked back by hand (partial return, OrderReturn): only the rest goes back now.
		$returned = OrderCoupons::order_map( $stored, self::RETURNED_META ) + OrderCoupons::order_map( $order, self::RETURNED_META );
		// A rounding rest booked off after the charge (T15) goes back with it.
		$rests    = OrderCoupons::order_map( $order, Settlement::WRITTEN_OFF_META );
		$restored = 0;
		foreach ( $applied as $code => $amount ) {
			$voucher = $this->lookup->find_voucher_for_code( (string) $code, true );
			$back    = (float) $amount - (float) ( $returned[ $code ] ?? 0 ) + (float) ( $rests[ $code ] ?? 0 );
			if ( $voucher && $this->balances->restore_balance_atomic(
				$voucher,
				$back,
				$order->get_id(),
				sprintf( /* translators: %s: order number */ __( 'Order #%s cancelled or refunded', 'voucher-captain' ), $order->get_order_number() ),
				(float) $amount
			) ) {
				++$restored;
				if ( $back > 0 ) {
					self::check_restored_card( $this->repo, $order, $voucher, $back );
				}
			}
		}
		if ( $restored === count( $applied ) ) {
			$order->delete_meta_data( '_vcap_redemptions' );
			$order->delete_meta_data( Settlement::WRITTEN_OFF_META );
			// AP-Q1-6: the returns belonged to this charge; a new charge after reopening the order starts afresh.
			$order->delete_meta_data( self::RETURNED_META );
			$order->save();
		} else {
			$order->add_order_note( __( 'Some voucher balances could not be restored. Review this order and retry the refund handling.', 'voucher-captain' ) );
		}
	}

	/** Order meta: amount per code booked back onto vouchers by hand (OrderReturn), code => amount. */
	public const RETURNED_META = '_vcap_returned_to_voucher';

	/** Ledger event: part of an order's charge booked back onto the voucher by hand (partial return). */
	public const RETURNED_EVENT = 'returned';

	/**
	 * Value booked back onto a card that has expired or was cancelled: the customer cannot use it, so it must not
	 * go there silently. Filter "vcap_restore_extension_days" (default 0) extends an expired card by that many days
	 * from now; otherwise – and always for a cancelled card – the order gets a note and the shop a notice
	 * (Attention): extend the validity on the voucher screen, or pay the amount back (money or store credit).
	 */
	public static function check_restored_card( VoucherRepository $repo, \WC_Order $order, Voucher $voucher, float $amount ): void {
		$card = $repo->get( $voucher->id ) ?? $voucher;
		$void = Voucher::STATUS_VOID === $card->status;
		if ( ! $void && ! $card->is_expired() && Voucher::STATUS_EXPIRED !== $card->status ) {
			return;
		}
		$days = $void ? 0 : max( 0, (int) apply_filters( 'vcap_restore_extension_days', \VCAP\SettingsRegistry::bounded_int( 'vcap_restore_extension_days' ), $card, $order ) );
		/* translators: %s: order number */
		if ( $days > 0 && TopUp::extend_validity( $repo, $card, gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ), sprintf( __( 'Balance of order #%s booked back', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
			/* translators: 1: voucher code, 2: expiry date */
			$order->add_order_note( sprintf( __( 'Voucher %1$s had expired: its validity was extended to %2$s so the booked-back amount can be used.', 'voucher-captain' ), Mask::code( (string) $card->code ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card->expires_at . ' UTC' ) ) ) );
			return;
		}
		$money = wp_strip_all_tags( wc_price( $amount, array( 'currency' => $card->currency ) ) );
		$text  = sprintf(
			$void
				/* translators: 1: amount, 2: voucher code */
				? __( '%1$s were booked back onto voucher %2$s, but it is cancelled: the customer cannot use it. Reactivate it, or pay the amount back as money or store credit.', 'voucher-captain' )
				/* translators: 1: amount, 2: voucher code */
				: __( '%1$s were booked back onto voucher %2$s, but it has expired: the customer cannot use it. Extend its validity on the voucher screen, or pay the amount back as money or store credit.', 'voucher-captain' ),
			$money,
			Mask::code( (string) $card->code )
		);
		$order->add_order_note( $text );
		Attention::add( $order->get_id(), 'restored:' . $card->id, $text );
	}

	/**
	 * Fully refunded order: WooCommerce gives coupon uses back only for cancelled orders, so single-use vouchers
	 * of the order are given back here (once per code): usage count lowered, voucher active again
	 * (usage_decreased()).
	 */
	public function return_single_use( \WC_Order $order ): void {
		$data_store = $order->get_data_store();
		if ( $data_store->has_callable( 'get_recorded_coupon_usage_counts' ) && ! $data_store->get_recorded_coupon_usage_counts( $order ) ) {
			return; // WooCommerce counted no use for this order (or gave it back already).
		}
		$done  = OrderCoupons::order_map( $order, SingleUse::SINGLE_USE_RETURNED_META );
		$codes = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = isset( $done[ $code ] ) ? null : $this->lookup->find_voucher_for_code( $code, true );
			if ( ! $voucher || $voucher->is_multi_use() || ! $voucher->coupon_id ) {
				continue;
			}
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			if ( ! $coupon->get_id() || $coupon->get_usage_count() < 1 ) {
				continue;
			}
			$coupon->decrease_usage_count( $order->get_customer_id() ? (string) $order->get_customer_id() : (string) $order->get_billing_email() );
			$done[ $code ] = 1;
			$codes[]       = $voucher->code;
			if ( Voucher::STATUS_VOID !== $voucher->status ) {
				self::check_restored_card( $this->repo, $order, $voucher, (float) $voucher->initial_amount );
			}
		}
		if ( $codes ) {
			$order->update_meta_data( SingleUse::SINGLE_USE_RETURNED_META, $done );
			$order->save_meta_data();
			// AP-Q1-4: the uses are given back now – WooCommerce must not lower the counts a second time when the
			// refunded order is cancelled later (it does so while its "recorded" flag is set).
			if ( $data_store->has_callable( 'set_recorded_coupon_usage_counts' ) ) {
				$data_store->set_recorded_coupon_usage_counts( $order, false );
			}
			/* translators: %s: voucher codes */
			$order->add_order_note( sprintf( __( 'Order refunded: single-use vouchers %s can be used again.', 'voucher-captain' ), implode( ', ', array_map( array( Mask::class, 'code' ), $codes ) ) ) );
		}
	}
}
