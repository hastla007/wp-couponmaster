<?php
namespace VCAP\Coupons;

use VCAP\API;
use VCAP\Mask;
use VCAP\Settings;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Single-use vouchers in the shop (D14, split from CouponManager): redeemed when WooCommerce counts the use, given
 * back when it gives the use back (unless kept on cancellation), and a voucher voided or redeemed elsewhere before
 * its order was paid is flagged as a double use.
 */
final class SingleUse {
	public function __construct( private Settings $settings, private VoucherRepository $repo, private OrderCoupons $lookup ) {}

	/** Order meta: single-use codes whose use a full refund gave back already (code => 1, return_single_use()). */
	public const SINGLE_USE_RETURNED_META = '_vcap_single_use_returned';

	/** @var int Order being cancelled right now (WooCommerce gives its coupon uses back), 0 = none. */
	private int $cancelling = 0;

	/** @var bool Whether single-use vouchers of that order stay redeemed ("Restore balance on cancellation" off). */
	private bool $keep_single_use = false;

	/** @var Voucher[] Single-use vouchers given back (or kept) in that cancellation. */
	private array $given_back = array();

	public function keep_single_use_start( $order_id ): void {
		$this->cancelling      = (int) $order_id;
		$this->keep_single_use = ! $this->settings->yes( 'vcap_restore_on_cancel' );
		$this->given_back      = array();
	}

	public function keep_single_use_end( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $this->given_back && $order instanceof \WC_Order ) {
			if ( $this->keep_single_use ) {
				/* translators: %s: voucher codes */
				$order->add_order_note( sprintf( __( 'Order cancelled: single-use vouchers %s stay redeemed ("Restore balance on cancellation" is off).', 'voucher-captain' ), implode( ', ', array_map( array( Mask::class, 'code' ), array_map( 'strval', wp_list_pluck( $this->given_back, 'code' ) ) ) ) ) );
			} else {
				foreach ( $this->given_back as $voucher ) {
					BookBack::check_restored_card( $this->repo, $order, $voucher, (float) $voucher->initial_amount );
				}
			}
		}
		$this->cancelling      = 0;
		$this->keep_single_use = false;
		$this->given_back      = array();
	}

	/**
	 * Whether a single-use voucher is held by a shop order that is not paid yet: WooCommerce's tentative coupon
	 * usage right after checkout, and – because that hold lasts only the "hold stock" minutes – any unpaid order
	 * (pending, failed, on hold; open_orders_with()) that carries its code and can still be paid later with the
	 * discount. Such a voucher must not be redeemed elsewhere (counter, API) at the same time.
	 */
	public static function held_by_pending_order( Voucher $voucher ): bool {
		if ( $voucher->is_multi_use() || ! $voucher->coupon_id ) {
			return false;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		$store  = $coupon->get_id() ? $coupon->get_data_store() : null;
		if ( $store && $store->has_callable( 'get_tentative_usage_count' ) && (int) $store->get_tentative_usage_count( $coupon->get_id() ) > 0 ) {
			return true;
		}
		return (bool) self::open_orders_with( $voucher );
	}

	/** @var array<int, true> Orders to put on hold once their status transition is through. */
	private array $hold_after = array();

	/** @var array<int, true> Vouchers whose double use was recorded with its order in this request. */
	private array $double_flagged = array();

	/**
	 * Orders that are not paid (pending, failed, on hold) and carry the voucher's code as a coupon line. Such an
	 * order can still be paid later (pay link, bank transfer) with the voucher's discount, so its voucher counts
	 * as used (e.g. for the refund guard of the voucher purchase). Filter "vcap_open_order_statuses".
	 *
	 * @return int[] order ids
	 */
	public static function open_orders_with( Voucher $voucher ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || '' === (string) $voucher->code || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'coupon' AND order_item_name IN (%s, %s) ORDER BY order_id DESC LIMIT 100",
				$voucher->code,
				wc_format_coupon_code( $voucher->code )
			)
		);
		$statuses = (array) apply_filters( 'vcap_open_order_statuses', array( 'pending', 'failed', 'on-hold' ) );
		$open     = array();
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund && $order->has_status( $statuses ) && ! $order->is_paid() ) {
				$open[] = (int) $id;
			}
		}
		return $open;
	}

	/**
	 * Single-use vouchers on an order that has not counted its coupons yet (unpaid, failed, or paid right now)
	 * which were voided, redeemed or reserved elsewhere since the checkout.
	 *
	 * @param bool $unpaid The order was not paid yet (pay form, or paid right now from pending/failed). Older
	 *                     WooCommerce versions keep the coupon usage of a failed order counted, so such an order
	 *                     has its vouchers counted although it never paid: a voided voucher still conflicts.
	 * @return array<int, array{voucher: Voucher, item: \WC_Order_Item_Coupon}>
	 */
	public function single_use_conflicts( \WC_Order $order, bool $unpaid = false ): array {
		if ( $order instanceof \WC_Order_Refund ) {
			return array();
		}
		$store    = $order->get_data_store();
		$recorded = ! $store->has_callable( 'get_recorded_coupon_usage_counts' ) || $store->get_recorded_coupon_usage_counts( $order );
		if ( $recorded && ! $unpaid ) {
			return array(); // Counted already: the voucher was (or is still) redeemed by this order.
		}
		// Counted by this unpaid order: it holds the voucher itself (redeemed or reserved by it), only a voided
		// voucher is gone.
		$statuses = $recorded ? array( Voucher::STATUS_VOID ) : array( Voucher::STATUS_VOID, Voucher::STATUS_REDEEMED, Voucher::STATUS_RESERVED );
		$out      = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var \WC_Order_Item_Coupon $item */
			$code = wc_format_coupon_code( $item->get_code() );
			// A voided voucher's coupon is a draft that WooCommerce no longer finds by code: look up the voucher.
			$voucher = $this->lookup->find_voucher_for_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
			if ( $voucher && ! $voucher->is_multi_use() && in_array( $voucher->status, $statuses, true ) ) {
				$out[] = array( 'voucher' => $voucher, 'item' => $item );
			}
		}
		return $out;
	}

	/**
	 * Status hook, priority 9 (right before WooCommerce counts the coupons): an order that is paid (or put on
	 * hold) with a single-use voucher that was voided or redeemed elsewhere meanwhile gets the shortfall marker,
	 * an order note and – after the status change – the status "On hold", like a gift card that cannot be charged.
	 */
	public function check_single_use_conflicts( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $this->single_use_conflicts( $order ) as $conflict ) {
			$this->flag_double_use( $order, $conflict['voucher'], $conflict['item'] );
		}
	}

	/**
	 * An unpaid order (pending, failed) that is paid now: vouchers voided meanwhile, also where WooCommerce kept the
	 * order's coupon usage counted (check_single_use_conflicts() skips those). flag_double_use() runs once per code.
	 */
	public function check_single_use_conflicts_on_payment( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( $from, array( 'pending', 'failed', 'checkout-draft' ), true ) || ! in_array( $to, array( 'on-hold', 'processing', 'completed' ), true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $this->single_use_conflicts( $order, true ) as $conflict ) {
			$this->flag_double_use( $order, $conflict['voucher'], $conflict['item'] );
		}
	}

	private function flag_double_use( \WC_Order $order, Voucher $voucher, \WC_Order_Item_Coupon $item ): void {
		$code    = wc_format_coupon_code( $item->get_code() );
		$missing = OrderCoupons::order_map( $order, Settlement::SHORTFALL_AMOUNTS_META );
		if ( isset( $missing[ $code ] ) ) {
			return; // Flagged on an earlier status change.
		}
		$value            = (float) $item->get_discount() + ( wc_prices_include_tax() ? (float) $item->get_discount_tax() : 0.0 );
		$missing[ $code ] = round( $value, API\V1\RedemptionService::decimals( (string) $order->get_currency() ) );
		$order->update_meta_data( Settlement::SHORTFALL_AMOUNTS_META, $missing );
		$order->update_meta_data( Settlement::SHORTFALL_META, 'yes' );
		$order->save();
		$this->hold_after[ $order->get_id() ] = true;
		$this->double_flagged[ $voucher->id ] = true;
		/* translators: %s: order number */
		$this->repo->log( $voucher->id, 'double_use', $voucher->initial_amount, null, sprintf( __( 'Order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() );
		$order->add_order_note(
			sprintf(
				/* translators: 1: voucher code, 2: discount amount */
				__( 'Voucher %1$s was cancelled or redeemed elsewhere before this order was paid, but the order still has its discount of %2$s. The order is held: collect the difference or cancel the order before fulfilment.', 'voucher-captain' ),
				Mask::code( $voucher->code ),
				wp_strip_all_tags( wc_price( $value, array( 'currency' => $order->get_currency() ) ) )
			)
		);
		do_action( 'vcap_voucher_double_use', $voucher, new \WC_Coupon( $voucher->coupon_id ), $order->get_customer_id() ? $order->get_customer_id() : $order->get_billing_email() );
	}

	/** Order whose coupon uses WooCommerce counts in this request (see remember_usage_order()). */
	private int $usage_order = 0;

	/** @param int|string $order_id */
	public function remember_usage_order( $order_id ): void {
		$this->usage_order = (int) $order_id;
	}

	/** The order a single-use voucher was just used in: the one being counted, if it carries the code. */
	private function usage_order_for( Voucher $voucher ): int {
		if ( $this->usage_order <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$order = wc_get_order( $this->usage_order );
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}
		$codes = array_map( 'wc_format_coupon_code', (array) $order->get_coupon_codes() );
		return in_array( wc_format_coupon_code( (string) $voucher->code ), $codes, true ) ? (int) $order->get_id() : 0;
	}

	/**
	 * Single-use vouchers: mark redeemed once WooCommerce's usage limit is reached.
	 */
	public function usage_increased( $coupon, $new_count, $used_by ): void {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher || $voucher->is_multi_use() ) {
			return;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( $limit > 0 && (int) $new_count >= $limit && Voucher::STATUS_ACTIVE === $voucher->status ) {
			if ( $this->repo->redeem_single_if_active( $voucher->id ) ) {
				$order_id = $this->usage_order_for( $voucher );
				// M12-29: "redeemed" books what the order used, the rest is forfeited (or kept, M12-68) separately.
				$used = \VCAP\TaxAccounting::used_by_order( $voucher, $order_id, $this->lookup );
				$this->repo->log( $voucher->id, 'redeemed', $used, 0, (string) $used_by, $order_id );
				\VCAP\TaxAccounting::book_rest( $this->repo, $voucher, $used, $order_id );
				VoucherRepository::fire_redeemed( $voucher, (float) $voucher->initial_amount, 'shop', $order_id );
			}
		} elseif ( $limit > 0 && (int) $new_count >= $limit && Voucher::STATUS_ACTIVE !== $voucher->status && ! isset( $this->double_flagged[ $voucher->id ] ) ) {
			// Used in the shop although it was redeemed, reserved or voided elsewhere meanwhile: make it visible
			// (orders paid through a status change were flagged and held already, check_single_use_conflicts()).
			$this->repo->log( $voucher->id, 'double_use', $voucher->initial_amount, null, (string) $used_by );
			do_action( 'vcap_voucher_double_use', $voucher, $coupon, $used_by );
		}
	}

	/** Whether the order being cancelled right now gave this voucher's use back already (full refund before). */
	private function returned_by_order( Voucher $voucher ): bool {
		$order = $this->cancelling > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $this->cancelling ) : null;
		return $order instanceof \WC_Order && isset( OrderCoupons::order_map( $order, self::SINGLE_USE_RETURNED_META )[ wc_format_coupon_code( (string) $voucher->code ) ] );
	}

	public function usage_decreased( $coupon, $new_count, $used_by ): void {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher || $voucher->is_multi_use() ) {
			return;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( $this->cancelling && $this->keep_single_use && Voucher::STATUS_REDEEMED === $voucher->status ) {
			$this->given_back[] = $voucher; // Cancelled while "Restore balance on cancellation" is off: stays redeemed.
			return;
		}
		if ( $this->returned_by_order( $voucher ) ) {
			return; // AP-Q1-4: this order gave the use back already (full refund); a later use elsewhere stays redeemed.
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status && ( 0 === $limit || (int) $new_count < $limit ) ) {
			// An expired voucher stays expired (check_restored_card() tells the shop when an order gave it back).
			$voucher->status  = $voucher->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			$voucher->balance = $voucher->initial_amount;
			$this->repo->update( $voucher );
			\VCAP\TaxAccounting::revert_rest( $this->repo, $voucher ); // M12-68: a kept rest goes back with it.
			$this->repo->log( $voucher->id, 'restored', $voucher->initial_amount, $voucher->balance, (string) $used_by );
			if ( $this->cancelling ) {
				$this->given_back[] = $voucher;
			}
		}
	}

	/** Whether the order is to be put on hold once its status transition is through (flag_double_use()); clears the mark. */
	public function take_hold_after( int $order_id ): bool {
		if ( ! isset( $this->hold_after[ $order_id ] ) ) {
			return false;
		}
		unset( $this->hold_after[ $order_id ] );
		return true;
	}
}
