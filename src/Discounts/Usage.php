<?php
namespace VCAP\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Usage counting and limits for promotions.
 *
 * Every use is one row of the table vcap_promotion_usage (promotion, customer key "u:{user id}" or
 * "h:{keyed hash of the billing email}", order; rows of earlier versions stored "e:{email}" in plain text and are
 * converted by the daily maintenance and on erasure), so concurrent orders never overwrite each other's count and carts read only counts
 * (until schema 1.4 it was one "_vcap_used_by" post meta row per use, which every cart loaded; those rows are
 * moved by Install and still counted until they are gone).
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
		// After other plugins' validation (see CouponManager), before the balance reservation (51).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'hold_classic' ), 50, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hold_store_api' ), 50, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'hold_retry' ), 4, 1 );
		add_action( 'vcap_release_stale_order_reservations', array( $this, 'release_abandoned' ), 20 );
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'record' ), 20 );
		}
		foreach ( array( 'cancelled', 'failed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'revert' ), 20 );
		}
		// Back to pending only from a paid/on-hold state (not checkout-draft → pending in the block checkout,
		// which would give back the use that was just held).
		add_action( 'woocommerce_order_status_changed', array( $this, 'reverted_to_pending' ), 20, 3 );
		add_action( 'vcap_daily_maintenance', array( self::class, 'hash_legacy_keys' ), 30, 0 );
		add_action( 'deleted_post', array( self::class, 'forget_campaign' ), 10, 2 );
	}

	/** A deleted promotion takes its usage records (customer keys) with it. */
	public static function forget_campaign( $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post || Repository::POST_TYPE !== $post->post_type || ! \VCAP\Install::storage_ready() ) {
			return;
		}
		global $wpdb;
		self::forget_counts( (int) $post_id );
		$wpdb->delete( \VCAP\Install::tables()['usage'], array( 'campaign_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$emails = array();
		if ( $user_id > 0 ) {
			$keys[] = 'u:' . $user_id;
			$user   = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = strtolower( (string) $user->user_email );
			}
		}
		if ( is_email( $email ) ) {
			$emails[] = $email;
		}
		$emails = array_values( array_unique( $emails ) );
		foreach ( $emails as $address ) {
			$keys[] = self::email_key( $address );
		}
		// Plain-text keys of earlier versions, still counted until the maintenance has converted them (never
		// used to store a new use: the first key is).
		foreach ( $emails as $address ) {
			$keys[] = 'e:' . $address;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Customer key of an email address: a keyed hash (site salt), so the usage table holds no addresses but a
	 * customer is still recognised by the same address.
	 */
	public static function email_key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/** The stored form of a key: plain-text email keys of earlier versions become hashed keys. */
	public static function stored_key( string $key ): string {
		return str_starts_with( $key, 'e:' ) ? self::email_key( substr( $key, 2 ) ) : $key;
	}

	/**
	 * Converts plain-text email keys of earlier versions into hashed keys (daily maintenance, erasure), in
	 * batches. $email limits it to one address.
	 *
	 * @return bool true when no plain-text key is left (of the address)
	 */
	public static function hash_legacy_keys( string $email = '', int $limit = 500 ): bool {
		global $wpdb;
		$done = true;
		if ( \VCAP\Install::storage_ready() ) {
			$table = \VCAP\Install::tables()['usage'];
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Install.
			$rows = '' !== $email
				? $wpdb->get_results( $wpdb->prepare( "SELECT id, customer_key FROM {$table} WHERE customer_key = %s LIMIT %d", 'e:' . strtolower( trim( $email ) ), $limit ) )
				: $wpdb->get_results( $wpdb->prepare( "SELECT id, customer_key FROM {$table} WHERE customer_key LIKE %s LIMIT %d", 'e:%', $limit ) );
			foreach ( (array) $rows as $row ) {
				$wpdb->update( $table, array( 'customer_key' => self::stored_key( (string) $row->customer_key ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			}
			// phpcs:enable
			$done = count( (array) $rows ) < $limit;
		}
		if ( '' !== $email ) {
			// Uses still kept as post meta (before schema 1.4).
			$legacy = 'e:' . strtolower( trim( $email ) );
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => self::email_key( $email ) ), array( 'meta_key' => self::META, 'meta_value' => $legacy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
		}
		return $done;
	}

	/**
	 * Uses of a promotion. While rows of the old storage may exist, post meta is counted BEFORE the table: a batch
	 * the migration moves in between is then counted twice (a limit is reached early, the safe error) instead of
	 * not at all (a limit could be exceeded).
	 */
	public static function count( int $campaign_id ): int {
		global $wpdb;
		$ready  = \VCAP\Install::storage_ready();
		$legacy = ! $ready || \VCAP\Install::legacy_rows()
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $campaign_id, self::META ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			: 0;
		if ( ! $ready ) {
			return $legacy;
		}
		$table = \VCAP\Install::tables()['usage'];
		return $legacy + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d", $campaign_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_for( int $campaign_id, array $keys ): int {
		global $wpdb;
		if ( ! $keys ) {
			return 0;
		}
		// Plain-text email keys (earlier versions, callers) also count in their stored, hashed form.
		$keys  = array_values( array_unique( array_merge( array_map( 'strval', $keys ), array_map( static fn( $k ) => self::stored_key( (string) $k ), $keys ) ) ) );
		$keys  = array_values( array_map( static fn( $k ) => substr( $k, 0, 200 ), $keys ) );
		$in     = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ready  = \VCAP\Install::storage_ready();
		$legacy = 0;
		if ( ! $ready || \VCAP\Install::legacy_rows() ) {
			// Post meta first, then the table (see count()).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$legacy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value IN ($in)", array_merge( array( $campaign_id, self::META ), $keys ) ) );
		}
		if ( ! $ready ) {
			return $legacy;
		}
		$table = \VCAP\Install::tables()['usage'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		return $legacy + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND customer_key IN ($in)", array_merge( array( $campaign_id ), $keys ) ) );
	}

	/** @var array<int, array<string, int>> Counts read in this request, per promotion (see blocker()). */
	private static array $memo = array();

	/** A count of this request, read once (cart totals are calculated several times per request). */
	private static function memo( int $campaign_id, string $key, callable $read ): int {
		if ( ! isset( self::$memo[ $campaign_id ][ $key ] ) ) {
			self::$memo[ $campaign_id ][ $key ] = (int) $read();
		}
		return self::$memo[ $campaign_id ][ $key ];
	}

	/** Forgets the counts of this request (all, or of one promotion after a use was added or given back). */
	public static function forget_counts( int $campaign_id = 0 ): void {
		if ( $campaign_id > 0 ) {
			unset( self::$memo[ $campaign_id ] );
		} else {
			self::$memo = array();
		}
	}

	/**
	 * Why a promotion cannot be used any more ('' when it can). Cart checks read each count once per request
	 * (memo, forgotten when this request adds or gives back a use); $fresh reads the database – for the checks
	 * that decide, under the promotion's lock, whether a use may be taken (hold(), bonus).
	 */
	public static function blocker( array $campaign, int $user_id, string $email, bool $fresh = false ): string {
		$rules = $campaign['rules'];
		$id    = (int) $campaign['id'];
		if ( $rules['usage_limit'] > 0 ) {
			$used = $fresh ? self::count( $id ) : self::memo( $id, 'all', static fn() => self::count( $id ) );
			if ( $used >= $rules['usage_limit'] ) {
				return 'usage_limit';
			}
		}
		if ( $rules['usage_limit_per_customer'] > 0 ) {
			$keys = self::customer_keys( $user_id, $email );
			if ( $keys ) {
				$used = $fresh ? self::count_for( $id, $keys ) : self::memo( $id, 'k:' . md5( implode( '|', $keys ) ), static fn() => self::count_for( $id, $keys ) );
				if ( $used >= $rules['usage_limit_per_customer'] ) {
					return 'usage_limit_customer';
				}
			}
		}
		return '';
	}

	/**
	 * Records one use.
	 *
	 * @param int $order_id Order that used the promotion (0 when unknown).
	 */
	public static function add( int $campaign_id, string $key, int $order_id = 0 ): void {
		self::forget_counts( $campaign_id );
		$key = self::stored_key( $key );
		if ( ! \VCAP\Install::storage_ready() ) {
			add_post_meta( $campaign_id, self::META, $key );
			return;
		}
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\VCAP\Install::tables()['usage'],
			array(
				'campaign_id'  => $campaign_id,
				'customer_key' => substr( $key, 0, 200 ),
				'order_id'     => max( 0, $order_id ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Gives one use back: the use of this order, else a use recorded without order (moved from post meta) under
	 * the same key – never the use of another order.
	 *
	 * @param int $order_id Order whose use is given back (0: any use of the key).
	 */
	public static function remove( int $campaign_id, string $key, int $order_id = 0 ): void {
		global $wpdb;
		self::forget_counts( $campaign_id );
		if ( \VCAP\Install::storage_ready() ) {
			$table = \VCAP\Install::tables()['usage'];
			if ( $order_id > 0 && $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE campaign_id = %d AND order_id = %d LIMIT 1", $campaign_id, $order_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return;
			}
			$scope = $order_id > 0 ? ' AND order_id = 0' : '';
			foreach ( array_unique( array( self::stored_key( $key ), $key ) ) as $form ) {
				if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE campaign_id = %d AND customer_key = %s{$scope} LIMIT 1", $campaign_id, substr( $form, 0, 200 ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					return;
				}
			}
			if ( ! \VCAP\Install::legacy_rows() ) {
				return;
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value IN (%s, %s) LIMIT 1", $campaign_id, self::META, $key, self::stored_key( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $campaign_id, 'post_meta' );
	}

	/** The key a use of this order is stored under (kept on the order, so later email changes do not matter). */
	public static function order_key( \WC_Order $order ): string {
		$key = (string) $order->get_meta( self::ORDER_KEY );
		if ( '' === $key || str_starts_with( $key, 'e:' ) ) {
			$keys = self::customer_keys( (int) $order->get_customer_id(), (string) $order->get_billing_email() );
			$key  = '' !== $key ? self::stored_key( $key ) : ( $keys ? $keys[0] : 'o:' . $order->get_id() );
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
				self::add( $id, $key, (int) $order->get_id() );
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
			if ( $limited && ! $locked ) {
				// Without the lock two checkouts could take the last use together: refuse rather than oversell.
				foreach ( $held as $undo ) {
					self::remove( $undo, $key, (int) $order->get_id() );
					\VCAP\Claim::release( 'usage:' . $order->get_id() . ':' . $undo );
				}
				throw new \Exception( esc_html__( 'The promotion could not be checked right now. Please try again in a moment.', 'voucher-captain' ) );
			}
			$blocker = $limited ? self::blocker( $campaign, (int) $order->get_customer_id(), (string) $order->get_billing_email(), true ) : '';
			if ( '' === $blocker && \VCAP\Claim::take( 'usage:' . $order->get_id() . ':' . $id ) ) {
				self::add( $id, $key, (int) $order->get_id() );
				$held[] = $id;
			}
			if ( $locked ) {
				self::unlock( $id );
			}
			if ( '' !== $blocker ) {
				foreach ( $held as $undo ) {
					self::remove( $undo, $key, (int) $order->get_id() );
					\VCAP\Claim::release( 'usage:' . $order->get_id() . ':' . $undo );
				}
				throw new \Exception(
					sprintf(
						/* translators: %s: promotion title */
						esc_html__( 'The promotion "%s" has reached its usage limit. Please remove it from your cart and try again.', 'voucher-captain' ),
						esc_html( (string) ( $campaign['title'] ?? '' ) )
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
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_promotion_usage_limit', esc_html( $e->getMessage() ), 409 );
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
		// Only unpaid orders that hold uses and were not changed since the cutoff, read from the order store in use
		// (wc_get_orders() ignores meta_query for the classic store), in keyset batches of 200 (at most 1000 a run).
		$ids   = array();
		$after = 0;
		for ( $page = 1; $page <= 5; $page++ ) {
			$batch = \VCAP\OrderLookup::ids_with_meta(
				self::ORDER_META,
				null,
				array(
					'status'          => 'pending',
					'limit'           => 200,
					'after_id'        => $after,
					'modified_before' => $cutoff + 1,
				)
			);
			$ids = array_merge( $ids, $batch );
			if ( count( $batch ) < 200 ) {
				break;
			}
			$after = (int) end( $batch );
		}
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || 'pending' !== $order->get_status() || ! $order->get_meta( self::ORDER_META ) ) {
				continue;
			}
			$changed = $order->get_date_modified() ? $order->get_date_modified() : $order->get_date_created();
			if ( $changed && $changed->getTimestamp() <= $cutoff ) {
				$this->revert( $order->get_id() );
			}
		}
	}

	/** Named database lock per promotion (count + add must not interleave with another checkout); per site, see Install::lock_name(). */
	private static function lock( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', \VCAP\Install::lock_name( 'usage_' . $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function unlock( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', \VCAP\Install::lock_name( 'usage_' . $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function reverted_to_pending( $order_id, $from, $to ): void {
		if ( 'pending' === $to && in_array( $from, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			$this->revert( $order_id );
		}
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
			self::remove( $id, $key, (int) $order->get_id() );
			\VCAP\Claim::release( 'usage:' . $order->get_id() . ':' . $id );
		}
		$order->delete_meta_data( self::ORDER_META );
		$order->save_meta_data();
	}
}
