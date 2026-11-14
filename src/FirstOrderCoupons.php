<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * "First order only" option for any WooCommerce coupon (Marketing → Coupons → Usage restriction).
 *
 * A customer qualifies when no earlier order of theirs (by account or billing email) was paid or is awaiting
 * fulfilment. Guests are checked by billing email as soon as WooCommerce knows it (cart, checkout, Store API).
 * V4: the email is compared as mailbox (CustomerEmail::normalize(): "max+1@…", "m.ax@gmail.com"), through a keyed
 * hash every order carries (CUSTOMER_META); a refunded order, or a paid order cancelled later, that used a discount
 * counts as an earlier order too (an unpaid order that was cancelled does not: nothing was bought).
 */
final class FirstOrderCoupons {
	public const META = '_vcap_first_order';

	/** Order meta: CustomerEmail::key() of the billing email (V4), to find earlier orders of the same mailbox. */
	public const CUSTOMER_META = '_vcap_customer_email_key';

	public function register(): void {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 25, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );
		// An order created or changed in this request may change the answer of has_previous_order().
		foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_status_changed', 'woocommerce_delete_order', 'woocommerce_trash_order' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget' ) );
		}
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'stamp' ) );
		add_action( 'vcap_daily_maintenance', array( self::class, 'stamp_older_orders' ), 45, 0 );
	}

	/** V4: an order remembers the hashed mailbox of its billing email (set whenever the email is set or changed). */
	public static function stamp( $order ): void {
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}
		$email = (string) $order->get_billing_email();
		$key   = is_email( $email ) ? CustomerEmail::key( $email ) : '-';
		if ( (string) $order->get_meta( self::CUSTOMER_META ) !== $key ) {
			$order->update_meta_data( self::CUSTOMER_META, $key );
		}
	}

	/**
	 * V4: daily, orders from before V4 get their hashed mailbox in batches, so their "max+1@…" twins are found too
	 * (until then they are found by their exact and their normalised address).
	 *
	 * @return int orders stamped
	 */
	public static function stamp_older_orders( int $limit = 200 ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'limit'      => $limit,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily maintenance, batches.
					array(
						'key'     => self::CUSTOMER_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$done = 0;
		foreach ( (array) $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				self::stamp( $order );
				$order->save_meta_data();
				++$done;
			}
		}
		return $done;
	}

	/** @var array<string, bool> Answers of has_previous_order() in this request. */
	private static array $memo = array();

	/** Forgets the answers of this request (an order was created or changed). */
	public static function forget(): void {
		self::$memo = array();
	}

	public function field( $coupon_id, $coupon ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'First order only', 'voucher-captain' ),
				'description' => __( 'Only customers without an earlier order (by account or billing email) can use this coupon.', 'voucher-captain' ),
				'value'       => $coupon instanceof \WC_Coupon ? $coupon->get_meta( self::META ) : '',
			)
		);
	}

	public function save( $coupon_id, $coupon ): void {
		// Nonce verified by WooCommerce before woocommerce_coupon_options_save.
		$coupon->update_meta_data( self::META, isset( $_POST[ self::META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$coupon->save();
	}

	/**
	 * Whether the customer (user id and/or email) already has an order other than $exclude_order_id.
	 */
	public static function has_previous_order( int $user_id, string $email, int $exclude_order_id = 0, int $before = 0 ): bool {
		$statuses = apply_filters( 'vcap_first_order_counting_statuses', array( 'processing', 'completed', 'on-hold' ) );
		// Cart totals are calculated several times per request, each validating the coupon: the order queries run
		// once per customer, order and statuses (forgotten when an order is created or changes, see register()).
		$memo = md5( (string) wp_json_encode( array( $user_id, CustomerEmail::normalize( $email ), $exclude_order_id, $before, $statuses ) ) );
		if ( ! isset( self::$memo[ $memo ] ) ) {
			self::$memo[ $memo ] = self::find_previous_order( $user_id, $email, $exclude_order_id, $before, (array) $statuses );
		}
		return self::$memo[ $memo ];
	}

	/**
	 * has_previous_order() without the memo: by account, exact and normalised billing email, then by the hashed
	 * mailbox orders carry (V4). Refunded/cancelled orders count when they used a discount (undone_counts()).
	 */
	private static function find_previous_order( int $user_id, string $email, int $exclude_order_id, int $before, array $statuses ): bool {
		$criteria = array();
		$customer = array_values( array_unique( array_filter( array( $user_id ?: null, is_email( $email ) ? strtolower( trim( $email ) ) : null, is_email( $email ) ? CustomerEmail::normalize( $email ) : null ) ) ) );
		if ( $customer ) {
			$criteria[] = array( 'customer' => $customer );
		}
		$undone = array_values( array_diff( array( 'refunded', 'cancelled' ), array_map( static fn( $s ) => str_replace( 'wc-', '', (string) $s ), $statuses ) ) );
		// The hashed mailbox (V4) is read with OrderLookup: wc_get_orders() ignores meta_query on the classic
		// (posts) order store and would return any order (K2).
		if ( is_email( $email ) ) {
			$plain = array_map( static fn( $s ) => str_replace( 'wc-', '', (string) $s ), $statuses );
			$ids   = OrderLookup::ids_with_meta(
				self::CUSTOMER_META,
				CustomerEmail::key( $email ),
				array(
					'status' => array_merge( $plain, $undone ),
					'order'  => 'DESC',
					'limit'  => 50,
				)
			);
			foreach ( $ids as $id ) {
				$order = (int) $id !== $exclude_order_id ? wc_get_order( (int) $id ) : null;
				if ( ! $order instanceof \WC_Order || ( $before > 0 && $order->get_date_created() && $order->get_date_created()->getTimestamp() >= $before ) ) {
					continue;
				}
				if ( $order->has_status( $plain ) || ( $order->has_status( $undone ) && self::undone_counts( $order ) ) ) {
					return true;
				}
			}
		}
		foreach ( $criteria as $criterion ) {
			$query = $criterion + array(
				'status' => $statuses,
				'limit'  => 2,
				'return' => 'ids',
				'type'   => 'shop_order',
			);
			if ( $before > 0 ) {
				$query['date_created'] = '<' . $before; // Only orders placed before the one being recalculated.
			}
			$ids = wc_get_orders( $query );
			if ( array_diff( array_map( 'intval', $ids ), array( $exclude_order_id ) ) ) {
				return true;
			}
			if ( ! $undone ) {
				continue;
			}
			$query['status'] = $undone;
			$query['limit']  = 20;
			$query['return'] = 'objects';
			foreach ( (array) wc_get_orders( $query ) as $order ) {
				if ( $order instanceof \WC_Order && $order->get_id() !== $exclude_order_id && self::undone_counts( $order ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * V4: a refunded order, or one cancelled after it was paid, counts as earlier order when it used a discount –
	 * otherwise a refund would make the customer "new" again. A cancelled order that was never paid (abandoned
	 * payment) does not count.
	 */
	public static function undone_counts( \WC_Order $order ): bool {
		$discounted = (bool) $order->get_coupon_codes() || (float) $order->get_discount_total() > 0;
		$bought     = $order->has_status( 'refunded' ) || null !== $order->get_date_paid();
		/** Whether a refunded/cancelled order counts as earlier order for "first order only" (bool, WC_Order). */
		return (bool) apply_filters( 'vcap_first_order_counts_undone_order', $discounted && $bought, $order );
	}

	/**
	 * Customer identity for the current validation context (cart or order).
	 *
	 * @return array{0:int,1:string,2:int} user id, email, order id to exclude
	 */
	private static function context( $discounts ): array {
		if ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order ) {
			$order = $discounts->get_object();
			return array( (int) $order->get_customer_id(), (string) $order->get_billing_email(), (int) $order->get_id() );
		}
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		$user  = get_current_user_id();
		if ( '' === $email && $user ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		$awaiting = function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( 'order_awaiting_payment' ) : 0;
		return array( $user, $email, $awaiting );
	}

	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( self::META ) ) {
			return $valid;
		}
		list( $user, $email, $exclude ) = self::context( $discounts );
		if ( self::has_previous_order( $user, $email, $exclude ) ) {
			throw new \Exception( esc_html__( 'This coupon is only valid for your first order.', 'voucher-captain' ), 9020 );
		}
		return $valid;
	}

	/**
	 * Classic checkout: re-check with the billing email the guest just entered.
	 */
	public function checkout_validation( $data, $errors ): void {
		if ( ! WC()->cart || ! $errors instanceof \WP_Error ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( 'yes' === $coupon->get_meta( self::META ) && self::has_previous_order( get_current_user_id(), (string) ( $data['billing_email'] ?? '' ) ) ) {
				/* translators: %s: coupon code */
				$errors->add( 'vcap_first_order', sprintf( __( 'The coupon %s is only valid for your first order.', 'voucher-captain' ), strtoupper( $code ) ) );
			}
		}
	}
}
