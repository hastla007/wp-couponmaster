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

	/**
	 * S14: order meta of an order placed with a "first order only" coupon, set at checkout under the customer's
	 * lock. While such an order is open (pending, within the hold window) it counts as earlier order, so two
	 * parallel checkouts of the same person cannot both use the first-order discount.
	 */
	public const HOLD_META = '_vcap_first_order_hold';

	public function register(): void {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 25, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );
		// S14: the order being placed holds the first-order discount under the customer's lock (as Usage::hold).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'hold_classic' ), 49, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hold_store_api' ), 49, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'hold' ), 4, 1 );
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
		// OrderLookup: wc_get_orders() ignores the meta_query on the classic (posts) store and would re-stamp
		// arbitrary orders every day instead of the unstamped ones.
		$ids    = OrderLookup::ids_with_meta(
			self::CUSTOMER_META,
			null,
			array(
				'missing' => true,
				'status'  => array_keys( wc_get_order_statuses() ),
				'limit'   => $limit,
			)
		);
		$orders = array_filter( array_map( 'wc_get_order', $ids ) );
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
			$ids   = array();
			// AP-S3-4: orders stamped before the plugin's own key still carry the salt-keyed hash.
			foreach ( array( CustomerEmail::key( $email ), CustomerEmail::salt_key( $email ) ) as $hash ) {
				$ids = array_merge(
					$ids,
					OrderLookup::ids_with_meta(
						self::CUSTOMER_META,
						$hash,
						array(
							'status'         => array_merge( $plain, $undone ),
							'order'          => 'DESC',
							'limit'          => 50,
							'created_before' => max( 0, $before ), // AP-P1-1: newer orders never fill the 50.
						)
					)
				);
			}
			foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
				$order = (int) $id !== $exclude_order_id ? wc_get_order( (int) $id ) : null;
				if ( ! $order instanceof \WC_Order || ( $before > 0 && $order->get_date_created() && $order->get_date_created()->getTimestamp() >= $before ) ) {
					continue;
				}
				if ( $order->has_status( $plain ) || ( $order->has_status( $undone ) && self::undone_counts( $order ) ) ) {
					return true;
				}
			}
		}
		if ( self::open_order_holds( $user_id, $email, $exclude_order_id, $before ) ) {
			return true;
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
	 * S14: whether an open order of the customer (pending, placed within the hold window) holds the first-order
	 * discount (HOLD_META): a parallel checkout of the same person, by account, email or mailbox (V4).
	 */
	private static function open_order_holds( int $user_id, string $email, int $exclude_order_id, int $before ): bool {
		$ids = OrderLookup::ids_with_meta(
			self::HOLD_META,
			'yes',
			array(
				'status'         => array( 'pending' ),
				'created_after'  => time() - self::hold_minutes() * MINUTE_IN_SECONDS,
				'created_before' => max( 0, $before ),
				'order'          => 'DESC',
				'limit'          => 200,
			)
		);
		$mailbox = is_email( $email ) ? CustomerEmail::normalize( $email ) : '';
		foreach ( $ids as $id ) {
			$order = (int) $id !== $exclude_order_id ? wc_get_order( (int) $id ) : null;
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$billing = (string) $order->get_billing_email();
			if ( ( $user_id > 0 && (int) $order->get_customer_id() === $user_id ) || ( '' !== $mailbox && is_email( $billing ) && CustomerEmail::normalize( $billing ) === $mailbox ) ) {
				return true;
			}
		}
		return false;
	}

	/** S14: how long an unpaid order holds its first-order discount (the shop's stock hold, else 60 minutes). */
	private static function hold_minutes(): int {
		$hold = (int) get_option( 'woocommerce_hold_stock_minutes', 0 );
		return max( 1, (int) apply_filters( 'vcap_stale_reservation_minutes', $hold > 0 ? $hold : 60 ) );
	}

	/**
	 * S14: an order placed with a "first order only" coupon holds it: under a lock per customer (mailbox and
	 * account) no other order of the same person may already be paid, awaiting fulfilment or hold it open.
	 * Throws (the order is not sent for payment) when it does or the lock is not available.
	 *
	 * @param mixed $order Order being placed or paid.
	 * @throws \Exception When the coupon is not valid for this order.
	 */
	public function hold( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$codes = array_values( array_filter( $order->get_coupon_codes(), static fn( $code ) => 'yes' === ( new \WC_Coupon( $code ) )->get_meta( self::META ) ) );
		if ( ! $codes ) {
			return;
		}
		$user  = (int) $order->get_customer_id();
		$email = (string) $order->get_billing_email();
		$locks = array_values( array_filter( array( is_email( $email ) ? 'first_order_' . substr( CustomerEmail::key( $email ), 0, 32 ) : '', $user > 0 ? 'first_order_u' . $user : '' ) ) );
		$held  = array();
		global $wpdb;
		foreach ( $locks as $lock ) {
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', Install::lock_name( $lock ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::unlock( $held );
				throw new \Exception( esc_html__( 'The coupon could not be checked right now. Please try again in a moment.', 'voucher-captain' ) );
			}
			$held[] = $lock;
		}
		try {
			self::forget();
			if ( self::has_previous_order( $user, $email, (int) $order->get_id() ) ) {
				$order->delete_meta_data( self::HOLD_META );
				$order->save_meta_data();
				/* translators: %s: coupon code */
				throw new \Exception( esc_html( sprintf( __( 'The coupon %s is only valid for your first order.', 'voucher-captain' ), strtoupper( $codes[0] ) ) ) );
			}
			$order->update_meta_data( self::HOLD_META, 'yes' );
			$order->save_meta_data();
		} finally {
			self::unlock( $held );
			self::forget();
		}
	}

	/** @param string[] $locks Lock names taken by hold(). */
	private static function unlock( array $locks ): void {
		global $wpdb;
		foreach ( array_reverse( $locks ) as $lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', Install::lock_name( $lock ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public function hold_classic( $order_id, $posted_data = null, $order = null ): void {
		$this->hold( $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id ) );
	}

	public function hold_store_api( $order ): void {
		try {
			$this->hold( $order );
		} catch ( \Exception $e ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_first_order', esc_html( $e->getMessage() ), 409 );
			}
			throw $e;
		}
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
			// The order of an earlier attempt of this checkout (awaiting payment) is not an earlier order.
			$awaiting = WC()->session ? (int) WC()->session->get( 'order_awaiting_payment' ) : 0;
			if ( 'yes' === $coupon->get_meta( self::META ) && self::has_previous_order( get_current_user_id(), (string) ( $data['billing_email'] ?? '' ), $awaiting ) ) {
				/* translators: %s: coupon code */
				$errors->add( 'vcap_first_order', sprintf( __( 'The coupon %s is only valid for your first order.', 'voucher-captain' ), strtoupper( $code ) ) );
			}
		}
	}
}
