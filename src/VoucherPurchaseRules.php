<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * What may reduce the price of voucher products in the cart.
 *
 * Vouchers are always worth their face value, so by default (like most retailers):
 * - discount codes do not apply to voucher products (otherwise a 50 € voucher bought for 45 € is redeemed for 50 €);
 * - vouchers/gift cards cannot pay for voucher products (prevents exchange loops, e.g. "extending" expiring cards).
 * Both can be allowed under WooCommerce → Settings → Vouchers. Other products in the same cart are unaffected.
 */
final class VoucherPurchaseRules {
	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'items_to_apply' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 30, 3 );
		// V7: the same customer behind several addresses (aliases) is found again by the normalised email.
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'stamp_customer_key' ) );
		// V7: velocity limit at checkout (classic and block checkout both run this hook after taking the address).
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_daily_limit' ) );
	}

	/** Order meta: keyed hash of the normalised billing email (CustomerEmail::key()). */
	public const CUSTOMER_KEY_META = '_vcap_customer_key';
	/** Order statuses whose vouchers count for the 24-hour limit and as earlier orders (unpaid "pending" does not). */
	private const COUNTED_STATUSES = array( 'wc-on-hold', 'wc-processing', 'wc-completed' );

	/** Keeps CUSTOMER_KEY_META in step with the billing email of every order (any way it is created). */
	public static function stamp_customer_key( $order ): void {
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}
		$email = (string) $order->get_billing_email();
		$key   = '' !== trim( $email ) ? CustomerEmail::key( $email ) : '';
		if ( (string) $order->get_meta( self::CUSTOMER_KEY_META ) !== $key ) {
			$order->update_meta_data( self::CUSTOMER_KEY_META, $key );
		}
	}

	/**
	 * Value of the voucher products of an order: what the lines cost before discounts (with tax where prices
	 * include tax), in the order currency.
	 */
	public static function voucher_value( \WC_Order $order ): float {
		$value = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product() && ProductConfig::is_voucher( $item->get_product() ) ) {
				$value += (float) $item->get_subtotal() + ( $order->get_prices_include_tax() ? (float) $item->get_subtotal_tax() : 0.0 );
			}
		}
		return $value;
	}

	/**
	 * Orders of one customer (account, normalised billing email, or the exact address for orders saved before
	 * the key existed) with a counted status, created at or after $since (0 = any time) and before $until.
	 *
	 * @return int[] order ids
	 */
	public static function customer_orders( int $user_id, string $email, int $since = 0, int $until = 0, int $exclude = 0 ): array {
		$base = array(
			'type'   => 'shop_order',
			'status' => self::COUNTED_STATUSES,
			'limit'  => 200,
			'return' => 'ids',
		);
		if ( $since > 0 || $until > 0 ) {
			$base['date_created'] = $until > 0 ? ( $since > 0 ? $since . '...' . $until : '<' . $until ) : '>=' . $since;
		}
		$queries = array();
		if ( $user_id > 0 ) {
			$queries[] = $base + array( 'customer_id' => $user_id );
		}
		$ids = array();
		if ( '' !== trim( $email ) ) {
			$queries[] = $base + array( 'billing_email' => strtolower( trim( $email ) ) );
			// The normalised mailbox key is read with OrderLookup: wc_get_orders() ignores meta_query on the classic
			// (posts) order store and would return every order. The date range is checked on the loaded orders.
			// AP-S3-4: orders stamped before the plugin's own key still carry the salt-keyed hash.
			$keyed = array();
			foreach ( array( CustomerEmail::key( $email ), CustomerEmail::salt_key( $email ) ) as $hash ) {
				$keyed = array_merge(
					$keyed,
					OrderLookup::ids_with_meta(
						self::CUSTOMER_KEY_META,
						$hash,
						array(
							'status' => self::COUNTED_STATUSES,
							'order'  => 'DESC',
							'limit'  => 200,
						)
					)
				);
			}
			foreach ( array_unique( array_map( 'intval', $keyed ) ) as $id ) {
				$order   = (int) $id !== $exclude ? wc_get_order( (int) $id ) : null;
				$created = $order instanceof \WC_Order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
				if ( $created && ( $since <= 0 || $created >= $since ) && ( $until <= 0 || $created < $until ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		foreach ( $queries as $query ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) wc_get_orders( $query ) ) );
		}
		return array_values( array_diff( array_unique( $ids ), array( $exclude ) ) );
	}

	/**
	 * V7: whether the order is a new customer's: no other order of the same account or email that was placed
	 * at least $established seconds before it and is still on hold, processing or completed. Two quick orders
	 * of a new customer are both new.
	 */
	public static function is_new_customer( \WC_Order $order, int $established ): bool {
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$earlier = self::customer_orders( (int) $order->get_customer_id(), (string) $order->get_billing_email(), 0, $created - max( 0, $established ), $order->get_id() );
		return (bool) apply_filters( 'vcap_is_new_customer', ! $earlier, $order );
	}

	/** Voucher value of the cart (line subtotals of voucher products, as displayed). */
	private static function cart_voucher_value( \WC_Cart $cart ): float {
		$value = 0.0;
		foreach ( $cart->get_cart() as $values ) {
			$product = $values['data'] ?? null;
			if ( $product instanceof \WC_Product && ProductConfig::is_voucher( $product ) ) {
				$value += (float) ( $values['line_subtotal'] ?? 0 ) + ( wc_prices_include_tax() ? (float) ( $values['line_subtotal_tax'] ?? 0 ) : 0.0 );
			}
		}
		return $value;
	}

	/**
	 * Voucher value one customer ordered in the last 24 hours (on hold, processing, completed; refunded lines
	 * no longer count).
	 */
	public static function ordered_last_day( int $user_id, string $email ): float {
		$value = 0.0;
		foreach ( self::customer_orders( $user_id, $email, time() - DAY_IN_SECONDS ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$share  = (float) $order->get_total() > 0 ? max( 0.0, 1 - (float) $order->get_total_refunded() / (float) $order->get_total() ) : 1.0;
				$value += self::voucher_value( $order ) * $share;
			}
		}
		return $value;
	}

	/**
	 * V7: "maximum voucher value per customer and 24 hours" (vcap_customer_daily_limit, default 1000, 0 = off):
	 * the vouchers in the cart plus those the same account or billing email (normalised) ordered in the last
	 * 24 hours. The order cannot be placed above it.
	 */
	public function check_daily_limit(): void {
		$limit = (float) $this->settings->get( 'vcap_customer_daily_limit' );
		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		if ( $limit <= 0 || ! $cart ) {
			return;
		}
		$in_cart = self::cart_voucher_value( $cart );
		if ( $in_cart <= 0 ) {
			return;
		}
		$email = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		$total = $in_cart + self::ordered_last_day( get_current_user_id(), $email );
		$limit = (float) apply_filters( 'vcap_customer_daily_limit', $limit, $total, $in_cart );
		if ( $limit > 0 && $total > $limit + 0.00001 ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: maximum voucher value */
					__( 'Vouchers can be ordered for at most %s per customer within 24 hours. Please reduce the voucher amount or contact us.', 'voucher-captain' ),
					wp_strip_all_tags( wc_price( $limit ) )
				),
				'error'
			);
		}
	}

	/**
	 * Whether this coupon may reduce the price of voucher products.
	 */
	public function coupon_allowed_on_vouchers( \WC_Coupon $coupon ): bool {
		$is_voucher = 'yes' === $coupon->get_meta( '_vcap_voucher' );
		$allowed    = $is_voucher ? $this->settings->yes( 'vcap_vouchers_buy_vouchers' ) : $this->settings->yes( 'vcap_discounts_on_vouchers' );
		return (bool) apply_filters( 'vcap_coupon_allowed_on_voucher_products', $allowed, $coupon );
	}

	private static function is_voucher_item( $item ): bool {
		return isset( $item->product ) && $item->product instanceof \WC_Product && ProductConfig::is_voucher( $item->product );
	}

	/**
	 * Remove voucher products from the items a coupon is applied to.
	 */
	public function items_to_apply( $items, $coupon ) {
		if ( ! is_array( $items ) || ! $coupon instanceof \WC_Coupon || $this->coupon_allowed_on_vouchers( $coupon ) ) {
			return $items;
		}
		return array_values( array_filter( $items, static fn( $item ) => ! self::is_voucher_item( $item ) ) );
	}

	/**
	 * Refuse the coupon with a clear message when the cart contains nothing it may apply to.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $discounts instanceof \WC_Discounts || $this->coupon_allowed_on_vouchers( $coupon ) ) {
			return $valid;
		}
		$items = $discounts->get_items_to_validate();
		if ( $items && count( array_filter( $items, array( self::class, 'is_voucher_item' ) ) ) === count( $items ) ) {
			throw new \Exception(
				'yes' === $coupon->get_meta( '_vcap_voucher' )
					? esc_html__( 'Vouchers and gift cards cannot be used to buy vouchers.', 'voucher-captain' )
					: esc_html__( 'Discount codes cannot be used to buy vouchers.', 'voucher-captain' ),
				9010
			);
		}
		// Minimum spend: voucher products do not count (a code "20 off from 50" must not be unlocked by a 40 gift
		// card whose purchase is then withdrawn). WooCommerce checked the full subtotal already; the maximum spend
		// keeps counting them (stricter, so nothing is unlocked by a voucher purchase either).
		$minimum = (float) $coupon->get_minimum_amount();
		if ( $minimum > 0 ) {
			$subject   = $discounts->get_object();
			$subtotal = self::subtotal_without_vouchers( $subject );
			if ( null !== $subtotal && $minimum > $subtotal + 0.00001 ) {
				throw new \Exception(
					sprintf(
						/* translators: 1: coupon code, 2: minimum amount */
						esc_html__( 'The minimum spend for coupon "%1$s" is %2$s. Voucher products in the cart do not count towards it.', 'voucher-captain' ),
						esc_html( $coupon->get_code() ),
						wp_kses_post( wc_price( $minimum ) )
					),
					108
				);
			}
		}
		return $valid;
	}

	/**
	 * The subtotal WooCommerce compares with a coupon's minimum spend (WC_Discounts::get_object_subtotal()),
	 * without voucher products; null for other objects.
	 *
	 * @param mixed $subject Cart or order.
	 */
	private static function subtotal_without_vouchers( $subject ): ?float {
		$voucher = static fn( $product ) => $product instanceof \WC_Product && ProductConfig::is_voucher( $product );
		if ( $subject instanceof \WC_Cart ) {
			$subtotal = (float) $subject->get_displayed_subtotal();
			foreach ( $subject->get_cart() as $values ) {
				if ( $voucher( $values['data'] ?? null ) ) {
					$subtotal -= (float) ( $values['line_subtotal'] ?? 0 ) + ( $subject->display_prices_including_tax() ? (float) ( $values['line_subtotal_tax'] ?? 0 ) : 0.0 );
				}
			}
			return $subtotal;
		}
		if ( $subject instanceof \WC_Order ) {
			$subtotal = (float) $subject->get_subtotal() + ( $subject->get_prices_include_tax() ? round( (float) $subject->get_total_tax(), wc_get_price_decimals() ) : 0.0 );
			foreach ( $subject->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && $voucher( $item->get_product() ) ) {
					$subtotal -= (float) $item->get_subtotal() + ( $subject->get_prices_include_tax() ? (float) $item->get_subtotal_tax() : 0.0 );
				}
			}
			return $subtotal;
		}
		return null;
	}
}
