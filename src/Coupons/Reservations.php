<?php
namespace VCAP\Coupons;

use VCAP\Install;
use VCAP\Mask;
use VCAP\OrderLookup;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Balance holds of orders (D14, split from CouponManager): committed when the order is paid, released when it is
 * abandoned, trashed or deleted, and swept by the stale-order and orphaned-hold reconciliation.
 */
final class Reservations {
	public function __construct( private VoucherRepository $repo, private Balances $balances, private OrderCoupons $lookup ) {}

	/**
	 * A trashed or deleted order must not keep stored balance locked. Committed redemptions of paid orders
	 * stay untouched (refunds handle those).
	 *
	 * @param int|\WC_Order $order_or_id Order or post id.
	 */
	public function release_deleted_order( $order_or_id ): void {
		if ( ! $order_or_id instanceof \WC_Order ) {
			$post_type = get_post_type( (int) $order_or_id );
			// wp_trash_post / before_delete_post fire for every post type; HPOS passes order ids without posts.
			if ( $post_type && ! in_array( $post_type, wc_get_order_types(), true ) ) {
				return;
			}
		}
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund && $order->get_meta( '_vcap_reservations' ) ) {
			$this->release_order_reservations( $order );
		}
	}

	/** Called by Plugin::ensure_schedules() (once a day and after activation or an update, not on every request). */
	public function schedule_stale_release(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'vcap_release_stale_order_reservations', array(), 'vcap' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'vcap_release_stale_order_reservations', array(), 'vcap' );
		}
	}

	/**
	 * Release balance holds of orders left unpaid (customer abandoned the payment page). Uses WooCommerce's
	 * "hold stock" duration when set, otherwise 60 minutes. A late payment is still deducted when it arrives;
	 * if the balance was spent meanwhile the order is put on hold for review (see deduct_order()).
	 */
	public function release_stale_order_reservations(): void {
		$hold    = (int) get_option( 'woocommerce_hold_stock_minutes', 0 );
		$minutes = (int) apply_filters( 'vcap_stale_reservation_minutes', $hold > 0 ? $hold : 60 );
		if ( $minutes <= 0 ) {
			return;
		}
		// Open holds are the "_vcap_reservations" map of unpaid orders (removed once the hold is charged or
		// released): pending orders not changed since the cutoff, found through the order meta index (HPOS and the
		// legacy order store alike) instead of scanning the ledger. Pages of 200, so holds that cannot be
		// released right now never block the orders behind them.
		$cutoff    = time() - $minutes * MINUTE_IN_SECONDS;
		// Read from the order store in use (OrderLookup: wc_get_orders() ignores meta_query for the classic store).
		$order_ids = array();
		$after     = 0;
		for ( $page = 1; $page <= 5; $page++ ) {
			$ids = OrderLookup::ids_with_meta(
				'_vcap_reservations',
				null,
				array(
					'status'          => 'pending',
					'limit'           => 200,
					'after_id'        => $after,
					'modified_before' => $cutoff,
				)
			);
			$order_ids = array_merge( $order_ids, $ids );
			if ( count( $ids ) < 200 ) {
				break;
			}
			$after = (int) end( $ids );
		}
		foreach ( array_map( 'intval', $order_ids ? $order_ids : array() ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || 'pending' !== $order->get_status() ) {
				continue;
			}
			$modified = $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0;
			if ( $modified > $cutoff || ! OrderCoupons::order_map( $order, '_vcap_reservations' ) ) {
				continue;
			}
			$this->release_order_reservations( $order );
			$order->add_order_note( __( 'Unpaid order: voucher balance reservations released.', 'voucher-captain' ) );
		}
		$this->release_orphaned_holds( $cutoff );
	}

	/**
	 * Ledger-based reconciliation: holds whose latest event is still "reserved" although their order is gone,
	 * cancelled, refunded or failed, or still pending past the cutoff without the hold in its "_vcap_reservations"
	 * map (e.g. a crash between the reservation's COMMIT and the order save). The map-based sweep cannot see those,
	 * so they would lower the balance for good. Paid orders (processing, completed, on hold) are left alone: their
	 * holds are charged by deduct_order(). Looks back 90 days (filter "vcap_orphaned_hold_days").
	 *
	 * @return int number of holds released
	 */
	public function release_orphaned_holds( int $cutoff ): int {
		global $wpdb;
		$events = Install::tables()['events'];
		$in     = Balances::ORDER_CHARGE_EVENTS_SQL;
		$days   = max( 1, (int) apply_filters( 'vcap_orphaned_hold_days', 90 ) );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT e.voucher_id, e.order_id FROM {$events} e WHERE e.event = 'reserved' AND e.order_id > 0 AND e.created_at >= %s
					AND NOT EXISTS ( SELECT 1 FROM {$events} x WHERE x.voucher_id = e.voucher_id AND x.order_id = e.order_id AND x.id > e.id AND x.event IN ({$in}) )
					ORDER BY e.id LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$released = 0;
		foreach ( $rows ? $rows : array() as $row ) {
			$voucher = $this->repo->get( (int) $row['voucher_id'] );
			$order   = wc_get_order( (int) $row['order_id'] );
			if ( ! $voucher ) {
				continue;
			}
			$code = wc_format_coupon_code( $voucher->code );
			if ( $order instanceof \WC_Order ) {
				if ( $order instanceof \WC_Order_Refund || $order->is_paid() || $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
					continue;
				}
				if ( $order->has_status( array( 'pending', 'checkout-draft' ) ) ) {
					$modified = $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0;
					if ( $modified > $cutoff || isset( OrderCoupons::order_map( $order, '_vcap_reservations' )[ $code ] ) ) {
						continue; // Checkout in progress, or the map-based sweep handles it.
					}
				}
			}
			/* translators: %s: order id */
			$note = sprintf( __( 'Orphaned reservation of order #%s released', 'voucher-captain' ), (int) $row['order_id'] );
			if ( ! $this->balances->release_reservation_atomic( $voucher, (int) $row['order_id'], $note ) ) {
				continue;
			}
			++$released;
			if ( $order instanceof \WC_Order ) {
				$map = OrderCoupons::order_map( $order, '_vcap_reservations' );
				if ( isset( $map[ $code ] ) ) {
					unset( $map[ $code ] );
					if ( $map ) {
						$order->update_meta_data( '_vcap_reservations', $map );
					} else {
						$order->delete_meta_data( '_vcap_reservations' );
					}
					$order->save();
				}
				/* translators: %s: voucher code */
				$order->add_order_note( sprintf( __( 'A voucher reservation of %s that was left open has been released.', 'voucher-captain' ), Mask::code( $voucher->code ) ) );
			}
		}
		return $released;
	}

	/**
	 * Move reservations to the committed redemption list when payment succeeds.
	 */
	public function commit_order_reservations( \WC_Order $order ): void {
		$reservations = OrderCoupons::order_map( $order, '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$redemptions = OrderCoupons::order_map( $order, '_vcap_redemptions' );
		// The order may have been edited after checkout: only coupons still on it, with the same amount, are committed.
		$current = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$current[ wc_format_coupon_code( $item->get_code() ) ] = $this->lookup->used_amount( $item ) ?? -1.0; // No rate: never matches.
		}
		$failed   = false;
		$rounding = OrderCoupons::order_map( $order, self::ROUNDING_SHORTFALL_META );
		$gaps     = array();
		foreach ( $reservations as $code => $amount ) {
			$code    = (string) $code;
			$voucher = $this->lookup->find_voucher_for_code( $code );
			$key     = wc_format_coupon_code( $code );
			// Currency round trips can make the used amount a few cents larger than the (full-balance) hold.
			$excess = isset( $current[ $key ] ) ? (float) $current[ $key ] - (float) $amount : 0.0;
			$same   = isset( $current[ $key ] ) && ( abs( $excess ) < 0.0001 || ( $excess > 0 && $excess <= Balances::rounding_tolerance( $order, (float) $current[ $key ], $voucher ) ) );
			if ( $same && $excess >= 0.0001 && $voucher ) {
				// AP-Q1-5: the discount used a few minor units more than the card held – recorded, never silent.
				$rounding[ $key ] = round( $excess, $voucher->decimals() );
				$gaps[ $key ]     = $voucher;
			}
			if ( ! $same ) {
				// Removed or changed coupon line: give the hold back; deduct_order() charges the current amount.
				if ( $voucher && $this->balances->release_reservation_atomic( $voucher, $order->get_id(), __( 'Coupon removed or changed on the order', 'voucher-captain' ) ) ) {
					unset( $reservations[ $code ] );
				} else {
					$failed = true;
				}
				continue;
			}
			$result = $voucher ? $this->balances->commit_reservation_atomic( $voucher, $order->get_id() ) : null;
			if ( ! $result ) {
				$failed = true; // Stays in _vcap_reservations; deduct_order() retries it through the ledger.
				continue;
			}
			$redemptions[ $key ] = $result['amount'];
			unset( $reservations[ $code ] );
		}
		// Persist whatever succeeded, so refunds restore exactly what was committed.
		$order->update_meta_data( '_vcap_redemptions', $redemptions );
		if ( $gaps ) {
			$order->update_meta_data( self::ROUNDING_SHORTFALL_META, $rounding );
		}
		if ( $reservations ) {
			$order->update_meta_data( '_vcap_reservations', $reservations );
		} else {
			$order->delete_meta_data( '_vcap_reservations' );
		}
		$order->save();
		if ( $failed ) {
			$order->add_order_note( __( 'Some voucher reservations could not be committed. Review this order before fulfilment.', 'voucher-captain' ) );
		}
		foreach ( $gaps as $key => $voucher ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: masked voucher code, 2: amount */
					__( 'Voucher %1$s: currency rounding made the discount %2$s larger than the balance it held; the full balance was charged and the uncovered difference is recorded on this order.', 'voucher-captain' ),
					Mask::code( (string) $key ),
					html_entity_decode( wp_strip_all_tags( wc_price( (float) $rounding[ $key ], array( 'currency' => $voucher->currency ) ) ), ENT_QUOTES, 'UTF-8' )
				)
			);
		}
	}

	/** Order meta (AP-Q1-5): code => part of a discount that currency rounding let exceed the card's balance (base currency). */
	public const ROUNDING_SHORTFALL_META = '_vcap_rounding_shortfall';

	/**
	 * Release every reservation on an order that failed or was cancelled before payment.
	 */
	public function release_order_reservations( \WC_Order $order ): void {
		$reservations = OrderCoupons::order_map( $order, '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$released = 0;
		foreach ( $reservations as $code => $amount ) {
			$voucher = $this->lookup->find_voucher_for_code( (string) $code, true );
			if ( $voucher && $this->balances->release_reservation_atomic( $voucher, $order->get_id(), sprintf( /* translators: %s: order number */ __( 'Released from order #%s', 'voucher-captain' ), $order->get_order_number() ) ) ) {
				++$released;
			}
		}
		if ( $released === count( $reservations ) ) {
			$order->delete_meta_data( '_vcap_reservations' );
			$order->save();
		} else {
			$order->add_order_note( __( 'Some voucher reservations could not be released. Review this order.', 'voucher-captain' ) );
		}
	}
}
