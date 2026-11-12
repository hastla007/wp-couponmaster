<?php
namespace VCAP\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Usage counting and limits for promotions.
 *
 * Like WooCommerce's own "_used_by" coupon meta, every use is one post meta row on the promotion
 * ("_vcap_used_by" = "u:{user id}" or "e:{billing email}"), so concurrent orders never overwrite each other's count.
 * A use is held when the order is placed (classic and Store API checkout, retried payment), under a named
 * lock per promotion, so parallel or still unpaid orders cannot together exceed a limit. It is confirmed when the
 * order reaches a paid/on-hold status and given back when it is cancelled, failed or set back to pending, or when
 * an unpaid order is abandoned. Each order remembers what it counted, so status repeats never count twice.
 */
final class Usage {
	public const META       = '_vcap_used_by';
	public const ORDER_META = '_vcap_campaign_usage';
	public const ORDER_KEY  = '_vcap_campaign_usage_key';

	public function __construct( private ?Repository $campaigns = null ) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'hold_classic' ), 4, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hold_store_api' ), 4, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'hold_retry' ), 4, 1 );
		add_action( 'vcap_release_stale_order_reservations', array( $this, 'release_abandoned' ), 20 );
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'record' ), 20 );
		}
		foreach ( array( 'cancelled', 'failed', 'pending' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'revert' ), 20 );
		}
	}

	/**
	 * Keys identifying a customer: account, account email and billing email. A guest order with the email of
	 * an account counts for that account too (and the other way round), so checking out as guest is no way
	 * around a per-customer limit.
	 */
	public static function customer_keys( int $user_id, string $email ): array {
		$keys  = array();
		$email = strtolower( trim( $email ) );
		if ( $user_id <= 0 && is_email( $email ) ) {
			$owner   = get_user_by( 'email', $email );
			$user_id = $owner ? (int) $owner->ID : 0;
		}
		if ( $user_id > 0 ) {
			$keys[] = 'u:' . $user_id;
			$user   = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$keys[] = 'e:' . strtolower( (string) $user->user_email );
			}
		}
		if ( is_email( $email ) ) {
			$keys[] = 'e:' . $email;
		}
		return array_values( array_unique( $keys ) );
	}

	public static function count( int $campaign_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $campaign_id, self::META ) );
	}

	public static function count_for( int $campaign_id, array $keys ): int {
		global $wpdb;
		if ( ! $keys ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value IN ($in)", array_merge( array( $campaign_id, self::META ), $keys ) ) );
	}

	/**
	 * Why a promotion cannot be used any more ('' when it can).
	 */
	public static function blocker( array $campaign, int $user_id, string $email ): string {
		$rules = $campaign['rules'];
		if ( $rules['usage_limit'] > 0 && self::count( (int) $campaign['id'] ) >= $rules['usage_limit'] ) {
			return 'usage_limit';
		}
		if ( $rules['usage_limit_per_customer'] > 0 ) {
			$keys = self::customer_keys( $user_id, $email );
			if ( $keys && self::count_for( (int) $campaign['id'], $keys ) >= $rules['usage_limit_per_customer'] ) {
				return 'usage_limit_customer';
			}
		}
		return '';
	}

	public static function add( int $campaign_id, string $key ): void {
		add_post_meta( $campaign_id, self::META, $key );
	}

	public static function remove( int $campaign_id, string $key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value = %s LIMIT 1", $campaign_id, self::META, $key ) );
		wp_cache_delete( $campaign_id, 'post_meta' );
	}

	/** The key a use of this order is stored under (kept on the order, so later email changes do not matter). */
	public static function order_key( \WC_Order $order ): string {
		$key = (string) $order->get_meta( self::ORDER_KEY );
		if ( '' === $key ) {
			$keys = self::customer_keys( (int) $order->get_customer_id(), (string) $order->get_billing_email() );
			$key  = $keys ? $keys[0] : 'o:' . $order->get_id();
			$order->update_meta_data( self::ORDER_KEY, $key );
		}
		return $key;
	}

	/** Promotion ids on an order's coupon lines. */
	public static function campaign_ids( \WC_Order $order ): array {
		$ids = array();
		foreach ( $order->get_coupon_codes() as $code ) {
			$id = CartIntegration::id_from_code( (string) $code );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public function record( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$done = array_map( 'intval', (array) $order->get_meta( self::ORDER_META ) );
		$done = array_values( array_filter( $done ) );
		$new = array_diff( self::campaign_ids( $order ), $done );
		if ( ! $new ) {
			return;
		}
		$key     = self::order_key( $order );
		$claimed = array();
		foreach ( $new as $id ) {
			// A parallel request handling the same status change must not count the use twice.
			if ( ! \VCAP\Claim::take( 'usage:' . $order->get_id() . ':' . $id ) ) {
				continue;
			}
			$claimed[] = $id;
			if ( get_post( $id ) ) {
				self::add( $id, $key );
			}
		}
		if ( ! $claimed ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META, array_values( array_merge( $done, $claimed ) ) );
		$order->save_meta_data();
	}

	/**
	 * Hold the uses of an order being placed. Throws (and holds nothing) when a limit is reached, so the
	 * order is not sent for payment.
	 *
	 * @throws \Exception When a promotion's usage limit is reached.
	 */
	public function hold( \WC_Order $order ): void {
		if ( ! $this->campaigns ) {
			return;
		}
		$done = array_values( array_filter( array_map( 'intval', (array) $order->get_meta( self::ORDER_META ) ) ) );
		$new  = array_values( array_diff( self::campaign_ids( $order ), $done ) );
		if ( ! $new ) {
			return;
		}
		$key  = self::order_key( $order );
		$held = array();
		foreach ( $new as $id ) {
			$campaign = $this->campaigns->get( $id );
			if ( ! $campaign ) {
				continue;
			}
			$rules   = $campaign['rules'] ?? array();
			$limited = ! empty( $rules['usage_limit'] ) || ! empty( $rules['usage_limit_per_customer'] );
			$locked  = $limited ? self::lock( $id ) : false;
			$blocker = $limited ? self::blocker( $campaign, (int) $order->get_customer_id(), (string) $order->get_billing_email() ) : '';
			if ( '' === $blocker && \VCAP\Claim::take( 'usage:' . $order->get_id() . ':' . $id ) ) {
				self::add( $id, $key );
				$held[] = $id;
			}
			if ( $locked ) {
				self::unlock( $id );
			}
			if ( '' !== $blocker ) {
				foreach ( $held as $undo ) {
					self::remove( $undo, $key );
					\VCAP\Claim::release( 'usage:' . $order->get_id() . ':' . $undo );
				}
				throw new \Exception(
					sprintf(
						/* translators: %s: promotion title */
						__( 'The promotion "%s" has reached its usage limit. Please remove it from your cart and try again.', 'voucher-captain' ),
						(string) ( $campaign['title'] ?? '' )
					)
				);
			}
		}
		if ( $held ) {
			$order->update_meta_data( self::ORDER_META, array_values( array_merge( $done, $held ) ) );
			$order->save_meta_data();
		}
	}

	public function hold_classic( $order_id, $posted_data = null, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->hold( $order );
		}
	}

	public function hold_store_api( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		try {
			$this->hold( $order );
		} catch ( \Exception $e ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_promotion_usage_limit', $e->getMessage(), 409 );
			}
			throw $e;
		}
	}

	/** A failed order paid again from the "pay for order" page gave its uses back and holds them again. */
	public function hold_retry( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		try {
			$this->hold( $order );
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Give back the uses held by unpaid orders that were abandoned (same age as voucher reservations).
	 *
	 * @param int|null $now Reference time (tests); default: now.
	 */
	public function release_abandoned( $now = null ): void {
		$hold    = (int) get_option( 'woocommerce_hold_stock_minutes', 0 );
		$minutes = (int) apply_filters( 'vcap_stale_reservation_minutes', $hold > 0 ? $hold : 60 );
		if ( $minutes <= 0 ) {
			return;
		}
		$cutoff = ( is_numeric( $now ) ? (int) $now : time() ) - $minutes * MINUTE_IN_SECONDS;
		$orders = wc_get_orders(
			array(
				'status'     => 'pending',
				'limit'      => 200,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array( array( 'key' => self::ORDER_META, 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || ! $order->get_meta( self::ORDER_META ) ) {
				continue;
			}
			$changed = $order->get_date_modified() ? $order->get_date_modified() : $order->get_date_created();
			if ( $changed && $changed->getTimestamp() <= $cutoff ) {
				$this->revert( $order->get_id() );
			}
		}
	}

	/** Named database lock per promotion (count + add must not interleave with another checkout). */
	private static function lock( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'vcap_usage_' . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function unlock( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'vcap_usage_' . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function revert( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$done = array_values( array_filter( array_map( 'intval', (array) $order->get_meta( self::ORDER_META ) ) ) );
		if ( ! $done ) {
			return;
		}
		$key = (string) $order->get_meta( self::ORDER_KEY );
		foreach ( $done as $id ) {
			self::remove( $id, $key );
			\VCAP\Claim::release( 'usage:' . $order->get_id() . ':' . $id );
		}
		$order->delete_meta_data( self::ORDER_META );
		$order->save_meta_data();
	}
}
