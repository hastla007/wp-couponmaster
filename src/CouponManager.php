<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates WooCommerce coupons for vouchers and keeps redemption state (single use / stored balance) in sync.
 */
final class CouponManager {
	public function __construct( private Settings $settings, private VoucherRepository $repo ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 20, 3 );
		add_filter( 'woocommerce_coupon_sort', array( $this, 'sort' ), 20, 2 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'prepare_gross_balance' ), 20, 2 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'gross_balance_amount' ), 20, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'order_coupon_object' ), 10, 4 );
		// Late priority: other plugins (e.g. Pass Captain's capacity hold at 10) validate first, so a checkout they
		// refuse never leaves a balance reservation behind. A previous unpaid checkout order of the same session
		// gives its holds back first (classic checkout creates a new order after a failed attempt).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'release_previous_checkout_order' ), 49, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'release_previous_checkout_order' ), 49, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reserve_order' ), 51, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reserve_store_api_order' ), 51, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'reserve_retry_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
		// Charge stored balances before anything is fulfilled (vouchers, bonus coupons, Pass Captain tickets run
		// on the same status hooks with later priorities) and hold fulfilment while a shortfall is unresolved.
		// Registered on init so a theme's or late plugin's `vcap_balance_deduct_statuses` filter is respected.
		if ( did_action( 'init' ) ) {
			$this->register_settle_hooks();
		} else {
			add_action( 'init', array( $this, 'register_settle_hooks' ), 0 );
		}
		add_filter( 'vcap_should_issue_for_order', array( $this, 'issue_unless_shortfall' ), 5, 2 );
		add_filter( 'wpcap_woocommerce_hold_fulfilment', array( $this, 'hold_on_shortfall' ), 10, 2 );
		add_action( 'woocommerce_increase_coupon_usage_count', array( $this, 'usage_increased' ), 10, 3 );
		add_action( 'woocommerce_decrease_coupon_usage_count', array( $this, 'usage_decreased' ), 10, 3 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_balance_notice' ) );
		// Free balance holds of unpaid orders that are trashed, deleted or abandoned.
		add_action( 'woocommerce_trash_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'wp_trash_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'before_delete_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'vcap_release_stale_order_reservations', array( $this, 'release_stale_order_reservations' ) );
	}

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
		$order_ids = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$ids = wc_get_orders(
				array(
					'status'        => 'pending',
					'limit'         => 200,
					'paged'         => $page,
					'orderby'       => 'ID',
					'order'         => 'ASC',
					'return'        => 'ids',
					'date_modified' => '<' . $cutoff,
					'meta_query'    => array( array( 'key' => '_vcap_reservations', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$order_ids = array_merge( $order_ids, (array) $ids );
			if ( count( (array) $ids ) < 200 ) {
				break;
			}
		}
		foreach ( array_map( 'intval', $order_ids ? $order_ids : array() ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || 'pending' !== $order->get_status() ) {
				continue;
			}
			$modified = $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0;
			if ( $modified > $cutoff || ! self::order_map( $order, '_vcap_reservations' ) ) {
				continue;
			}
			$this->release_order_reservations( $order );
			$order->add_order_note( __( 'Unpaid order: voucher balance reservations released.', 'voucher-captain' ) );
		}
	}

	/**
	 * Read a code => amount map from order meta. A missing meta value is '' and (array) '' would be [ '' ].
	 *
	 * @return array<string, float>
	 */
	public static function order_map( \WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_filter( $value, static fn( $amount, $code ) => '' !== (string) $code && is_numeric( $amount ), ARRAY_FILTER_USE_BOTH );
	}

	public function code_exists( string $code ): bool {
		return $this->repo->code_exists( $code ) || wc_get_coupon_id_by_code( $code ) > 0;
	}

	/**
	 * Creates the WooCommerce coupon of a voucher. A failure (e.g. WC_Data_Exception for an invalid amount) removes
	 * a coupon that was saved already and is passed on, so the caller can roll the voucher back.
	 *
	 * @throws \Throwable When WooCommerce refuses the coupon data.
	 */
	public function create_coupon( Voucher $voucher, array $config ): int {
		$coupon = new \WC_Coupon();
		try {
			return $this->build_coupon( $coupon, $voucher, $config );
		} catch ( \Throwable $e ) {
			if ( $coupon->get_id() ) {
				wp_delete_post( $coupon->get_id(), true );
			}
			throw $e;
		}
	}

	private function build_coupon( \WC_Coupon $coupon, Voucher $voucher, array $config ): int {
		$coupon->set_code( $voucher->code );
		$type = in_array( $voucher->discount_type, array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ? $voucher->discount_type : 'fixed_cart';
		if ( $voucher->is_multi_use() ) {
			$type = 'fixed_cart'; // A stored balance is one amount for the whole cart, never per item.
		}
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $voucher->is_multi_use() ? $voucher->balance : $voucher->initial_amount );
		$coupon->set_description(
			sprintf(
				/* translators: 1: voucher type, 2: order number */
				__( '%1$s from order #%2$s (Voucher Captain)', 'voucher-captain' ),
				Voucher::types()[ $voucher->type ] ?? $voucher->type,
				$voucher->order() ? $voucher->order()->get_order_number() : $voucher->order_id
			)
		);
		if ( $voucher->expires_at ) {
			$coupon->set_date_expires( strtotime( $voucher->expires_at . ' UTC' ) );
		}
		switch ( $voucher->usage_mode ) {
			case 'balance':
				$coupon->set_usage_limit( 0 );
				break;
			case 'unlimited':
				$coupon->set_usage_limit( (int) $config['usage_limit'] > 0 ? (int) $config['usage_limit'] : 0 );
				break;
			default:
				$coupon->set_usage_limit( 1 );
		}
		$coupon->set_individual_use( 'yes' === $config['individual_use'] );
		$coupon->set_exclude_sale_items( 'yes' === $config['exclude_sale'] );
		$coupon->set_free_shipping( 'yes' === $config['free_shipping'] );
		if ( '' !== (string) $config['min_spend'] ) {
			$coupon->set_minimum_amount( wc_format_decimal( $config['min_spend'] ) );
		}
		if ( '' !== (string) $config['max_spend'] ) {
			$coupon->set_maximum_amount( wc_format_decimal( $config['max_spend'] ) );
		}
		$coupon->set_product_ids( array_map( 'intval', (array) $config['product_ids'] ) );
		$coupon->set_excluded_product_ids( array_map( 'intval', (array) $config['exclude_product_ids'] ) );
		$coupon->set_product_categories( array_map( 'intval', (array) $config['category_ids'] ) );
		$coupon->set_excluded_product_categories( array_map( 'intval', (array) $config['exclude_category_ids'] ) );

		$restrict = (string) $config['email_restrict'];
		if ( ! $voucher->transferable && 'none' === $restrict ) {
			$restrict = 'both'; // Personal voucher: only buyer and named recipient.
		}
		$config['email_restrict'] = $restrict;
		$coupon->set_email_restrictions( self::email_restrictions( $voucher, $restrict ) );
		$coupon->update_meta_data( '_vcap_email_restrict', $restrict );
		if ( 'recipient' === $restrict && ! is_email( $voucher->recipient_email ) && $voucher->id ) {
			$this->repo->log( $voucher->id, 'note', null, null, __( 'No recipient email was given: redemption is limited to the buyer email instead.', 'voucher-captain' ) );
		}

		$coupon->update_meta_data( '_vcap_voucher', 'yes' );
		$coupon->update_meta_data( '_vcap_voucher_code', $voucher->code );
		if ( $voucher->is_ticket() ) {
			// Tickets are admission passes: do not give a cart discount unless an amount was configured.
			$coupon->update_meta_data( '_vcap_ticket', 'yes' );
		}

		$coupon = apply_filters( 'vcap_coupon_before_save', $coupon, $voucher, $config );
		$coupon->save();
		return (int) $coupon->get_id();
	}

	/**
	 * Email addresses a voucher's coupon is limited to for a "who may redeem" setting (none, recipient, buyer,
	 * both). "recipient" without a valid recipient address falls back to the buyer – never silently unrestricted.
	 *
	 * @return string[]
	 */
	public static function email_restrictions( Voucher $voucher, string $restrict ): array {
		$emails = array();
		if ( 'recipient' === $restrict ) {
			$emails[] = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		} elseif ( 'buyer' === $restrict ) {
			$emails[] = $voucher->buyer_email;
		} elseif ( 'both' === $restrict ) {
			$emails = array( $voucher->buyer_email, $voucher->recipient_email );
		}
		return array_values( array_unique( array_filter( $emails, 'is_email' ) ) );
	}

	/**
	 * Recompute the email restriction of a voucher's coupon after buyer or recipient addresses changed. The
	 * setting comes from the coupon (meta "_vcap_email_restrict"); older coupons are read from their addresses.
	 *
	 * @param string $old_recipient Recipient email before the change.
	 */
	public function refresh_email_restrictions( Voucher $voucher, string $old_recipient = '' ): bool {
		if ( ! $voucher->coupon_id ) {
			return true;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		if ( ! $coupon->get_id() ) {
			return false;
		}
		$current  = array_map( 'strtolower', (array) $coupon->get_email_restrictions() );
		$restrict = (string) $coupon->get_meta( '_vcap_email_restrict' );
		if ( '' === $restrict ) {
			if ( ! $current ) {
				return true; // Never restricted: stays transferable.
			}
			$has_buyer     = in_array( strtolower( $voucher->buyer_email ), $current, true );
			$has_recipient = '' !== $old_recipient && in_array( strtolower( $old_recipient ), $current, true );
			$restrict      = $has_buyer && $has_recipient ? 'both' : ( $has_recipient ? 'recipient' : ( $has_buyer ? 'buyer' : '' ) );
			if ( '' === $restrict ) {
				return true; // Addresses set by hand: leave them alone.
			}
		}
		$coupon->set_email_restrictions( self::email_restrictions( $voucher, $restrict ) );
		$coupon->update_meta_data( '_vcap_email_restrict', $restrict );
		$coupon->save();
		return true;
	}

	public function set_coupon_enabled( Voucher $voucher, bool $enabled ): bool {
		if ( ! $voucher->coupon_id ) {
			return true;
		}
		$updated = wp_update_post(
			array(
				'ID'          => $voucher->coupon_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			),
			true
		);
		// WooCommerce caches code → ID lookups; drop it so a persistent object cache cannot keep a disabled coupon alive.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . wc_format_coupon_code( $voucher->code ), 'coupons' );
		}
		return ! is_wp_error( $updated ) && $updated;
	}

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

		$redemptions  = self::order_map( $order, '_vcap_redemptions' );
		$reservations = self::order_map( $order, '_vcap_reservations' );
		$coupons      = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $redemptions[ $code ] ) ) {
				continue;
			}
			$voucher = $this->find_voucher_for_code( $code );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$amount = $this->used_amount( $item );
			if ( null === $amount ) {
				throw new \Exception( self::currency_message( (string) $order->get_currency() ) );
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
			$reserved_voucher = $this->find_voucher_for_code( (string) $code );
			if ( ! $reserved_voucher || ! $this->release_reservation_atomic( $reserved_voucher, $order->get_id(), __( 'Checkout reservation reconciled', 'voucher-captain' ) ) ) {
				throw new \Exception( __( 'An earlier voucher reservation could not be released. Please contact the store before trying again.', 'voucher-captain' ) );
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
			$reservation = $this->reserve_balance_atomic(
				$voucher,
				$amount,
				$order->get_id(),
				sprintf( /* translators: %s: order number */ __( 'Reserved for order #%s', 'voucher-captain' ), $order->get_order_number() )
			);
			// The reservation may be a few cents below the discount when currency round trips exceed the balance
			// (same tolerance as reserve_balance_atomic()): that is the full balance, not a changed one.
			$short_by = $reservation ? $amount - (float) $reservation['amount'] : 0.0;
			$tolerant = $reservation && $short_by > -0.0001 && $short_by <= (float) apply_filters( 'vcap_balance_rounding_tolerance', max( 0.05, $amount * 0.001 ), $voucher );
			if ( ! $reservation || 'reserved' !== $reservation['state'] || ! $tolerant ) {
				$this->release_reservation_atomic( $voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) );
				foreach ( $made as $reserved_code => $reserved_amount ) {
					$reserved_voucher = $this->find_voucher_for_code( $reserved_code );
					if ( $reserved_voucher ) {
						$this->release_reservation_atomic( $reserved_voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) );
					}
				}
				$order->delete_meta_data( '_vcap_reservations' );
				$order->save();
				throw new \Exception( __( 'A voucher balance changed while checkout was processing. Your order was not sent for payment. Refresh the cart and try again.', 'voucher-captain' ) );
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
		if ( ! $previous_id || $previous_id === $order->get_id() ) {
			return;
		}
		$previous = wc_get_order( $previous_id );
		if ( ! $previous instanceof \WC_Order || ! $previous->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) || $previous->is_paid() ) {
			return;
		}
		if ( (int) $previous->get_customer_id() !== (int) $order->get_customer_id() ) {
			return;
		}
		$this->release_order_reservations( $previous );
		( new Discounts\Usage() )->revert( $previous->get_id() );
	}

	/**
	 * Convert reservation failures into Store API client errors instead of uncaught server errors.
	 */
	public function reserve_store_api_order( $order ): void {
		try {
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_voucher_reservation_failed', $e->getMessage(), 409 );
			}
			throw $e;
		}
	}

	/**
	 * Re-reserve voucher balances before a customer retries payment for a failed order.
	 */
	public function reserve_retry_order( $order ): void {
		try {
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}
	/**
	 * Atomically reserve stored balance for a specific order.
	 *
	 * @return array{amount:float,balance:float,state:string}|null
	 */
	/**
	 * Public order-scoped operations for the versioned integration service.
	 * The order id is the idempotency scope used by the event ledger.
	 */
	public function reserve_voucher_balance( Voucher $voucher, float $amount, \WC_Order $order, string $note = '' ): ?array {
		return $this->reserve_balance_atomic( $voucher, $amount, (int) $order->get_id(), $note );
	}

	/** @return array{amount:float,balance:float}|null */
	public function commit_voucher_reservation( Voucher $voucher, \WC_Order $order ): ?array {
		return $this->commit_reservation_atomic( $voucher, (int) $order->get_id() );
	}

	public function release_voucher_reservation( Voucher $voucher, \WC_Order $order, string $note = '' ): bool {
		return $this->release_reservation_atomic( $voucher, (int) $order->get_id(), $note );
	}

	private function reserve_balance_atomic( Voucher $voucher, float $requested, int $order_id, string $note ): ?array {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( $order_id <= 0 || $requested <= 0 || ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event, amount, balance_after FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1",
					$voucher->id,
					$order_id
				),
				ARRAY_A
			); // phpcs:ignore
			if ( $latest && in_array( $latest['event'], array( 'committed', 'redeemed' ), true ) ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return array( 'amount' => (float) $latest['amount'], 'balance' => (float) $latest['balance_after'], 'state' => 'committed' );
			}
			if ( $latest && 'reserved' === $latest['event'] ) {
				$amount = (float) $latest['amount'];
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return abs( $amount - $requested ) < 0.0001
					? array( 'amount' => $amount, 'balance' => (float) $latest['balance_after'], 'state' => 'reserved' )
					: null;
			}

			$fresh = $this->repo->get( $voucher->id );
			$requested = round( $requested, $voucher->decimals() );
			if ( $fresh && $fresh->balance < $requested && $requested - $fresh->balance <= (float) apply_filters( 'vcap_balance_rounding_tolerance', max( 0.05, $fresh->balance * 0.001 ), $fresh ) ) {
				// Currency round trips (order currency → base currency) can exceed the full balance by a few cents.
				$requested = $fresh->balance;
			}
			if ( ! $fresh || ! $fresh->is_multi_use() || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->is_expired() || $fresh->balance < $requested ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$fresh->balance = max( 0.0, round( $fresh->balance - $requested, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			if ( ! $this->repo->log( $fresh->id, 'reserved', $requested, $fresh->balance, $note, $order_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		$this->sync_balance_coupon( $fresh );
		return array( 'amount' => $requested, 'balance' => $fresh->balance, 'state' => 'reserved' );
	}

	/**
	 * Commit a previously reserved balance without deducting it a second time.
	 *
	 * @return array{amount:float,balance:float}|null
	 */
	private function commit_reservation_atomic( Voucher $voucher, int $order_id ): ?array {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event, amount, balance_after FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1",
					$voucher->id,
					$order_id
				),
				ARRAY_A
			); // phpcs:ignore
			if ( ! $latest ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			if ( 'committed' !== $latest['event'] && 'redeemed' !== $latest['event'] ) {
				if ( 'reserved' !== $latest['event'] ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return null;
				}
				$fresh = $this->repo->get( $voucher->id );
				if ( ! $fresh || ! $this->repo->log( $voucher->id, 'committed', (float) $latest['amount'], $fresh->balance, '', $order_id ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return null;
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		$fresh = $this->repo->get( $voucher->id );
		return array( 'amount' => (float) $latest['amount'], 'balance' => $fresh ? $fresh->balance : (float) $latest['balance_after'] );
	}

	/**
	 * Release an unpaid order's reservation and return the balance to the voucher atomically.
	 */
	private function release_reservation_atomic( Voucher $voucher, int $order_id, string $note = '' ): bool {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event, amount FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1",
					$voucher->id,
					$order_id
				),
				ARRAY_A
			); // phpcs:ignore
			if ( ! $latest || 'reserved' !== $latest['event'] ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (bool) ( $latest && in_array( $latest['event'], array( 'reservation_released', 'restored' ), true ) );
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$amount         = (float) $latest['amount'];
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			if ( ! $this->repo->log( $fresh->id, 'reservation_released', $amount, $fresh->balance, $note, $order_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		$this->sync_balance_coupon( $fresh );
		return true;
	}

	private function sync_balance_coupon( Voucher $voucher ): void {
		if ( ! $voucher->coupon_id ) {
			return;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		if ( $coupon->get_id() ) {
			$coupon->set_amount( $voucher->balance );
			$coupon->save();
		}
	}

	/**
	 * Move reservations to the committed redemption list when payment succeeds.
	 */
	private function commit_order_reservations( \WC_Order $order ): void {
		$reservations = self::order_map( $order, '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$redemptions = self::order_map( $order, '_vcap_redemptions' );
		// The order may have been edited after checkout: only coupons still on it, with the same amount, are committed.
		$current = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$current[ wc_format_coupon_code( $item->get_code() ) ] = $this->used_amount( $item ) ?? -1.0; // No rate: never matches.
		}
		$failed = false;
		foreach ( $reservations as $code => $amount ) {
			$code    = (string) $code;
			$voucher = $this->find_voucher_for_code( $code );
			$key     = wc_format_coupon_code( $code );
			// Currency round trips can make the used amount a few cents larger than the (full-balance) hold.
			$excess = isset( $current[ $key ] ) ? (float) $current[ $key ] - (float) $amount : 0.0;
			$same   = isset( $current[ $key ] ) && ( abs( $excess ) < 0.0001 || ( $excess > 0 && $excess <= (float) apply_filters( 'vcap_balance_rounding_tolerance', max( 0.05, (float) $amount * 0.001 ), $voucher ) ) );
			if ( ! $same ) {
				// Removed or changed coupon line: give the hold back; deduct_order() charges the current amount.
				if ( $voucher && $this->release_reservation_atomic( $voucher, $order->get_id(), __( 'Coupon removed or changed on the order', 'voucher-captain' ) ) ) {
					unset( $reservations[ $code ] );
				} else {
					$failed = true;
				}
				continue;
			}
			$result = $voucher ? $this->commit_reservation_atomic( $voucher, $order->get_id() ) : null;
			if ( ! $result ) {
				$failed = true; // Stays in _vcap_reservations; deduct_order() retries it through the ledger.
				continue;
			}
			$redemptions[ $key ] = $result['amount'];
			unset( $reservations[ $code ] );
		}
		// Persist whatever succeeded, so refunds restore exactly what was committed.
		$order->update_meta_data( '_vcap_redemptions', $redemptions );
		if ( $reservations ) {
			$order->update_meta_data( '_vcap_reservations', $reservations );
		} else {
			$order->delete_meta_data( '_vcap_reservations' );
		}
		$order->save();
		if ( $failed ) {
			$order->add_order_note( __( 'Some voucher reservations could not be committed. Review this order before fulfilment.', 'voucher-captain' ) );
		}
	}

	/**
	 * Release every reservation on an order that failed or was cancelled before payment.
	 */
	private function release_order_reservations( \WC_Order $order ): void {
		$reservations = self::order_map( $order, '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$released = 0;
		foreach ( $reservations as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			if ( $voucher && $this->release_reservation_atomic( $voucher, $order->get_id(), sprintf( /* translators: %s: order number */ __( 'Released from order #%s', 'voucher-captain' ), $order->get_order_number() ) ) ) {
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

	/**
	 * Apply a new balance to voucher + coupon.
	 */
	public function set_balance( Voucher $voucher, float $balance, string $event, ?float $amount = null, string $note = '', int $order_id = 0 ): bool {
		return $this->write_balance( $voucher, static fn() => $balance, $event, $amount, $note, $order_id );
	}

	/**
	 * Add (positive) or remove (negative) value relative to the balance stored right now, under the
	 * row lock. Admin edits use this so a redemption that lands while the edit form is open is not
	 * overwritten by a balance computed from the stale form value.
	 */
	public function adjust_balance( Voucher $voucher, float $delta, string $event, string $note = '', int $order_id = 0 ): bool {
		return $this->write_balance( $voucher, static fn( float $current ) => $current + $delta, $event, null, $note, $order_id );
	}

	/** @param callable(float):float $compute New balance from the locked, current balance. */
	private function write_balance( Voucher $voucher, callable $compute, string $event, ?float $amount, string $note, int $order_id ): bool {
		global $wpdb;
		$table = Install::tables()['vouchers'];
		if ( ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			$fresh = $locked ? $this->repo->get( $voucher->id ) : null;
			if ( ! $fresh ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$before            = (float) $fresh->balance;
			$balance           = max( 0.0, round( (float) $compute( $before ), $fresh->decimals() ) );
			$amount            = $amount ?? abs( $balance - $before );
			$fresh->usage_mode = $voucher->usage_mode;
			$fresh->balance    = $balance;
			if ( $fresh->is_multi_use() ) {
				if ( $balance <= 0 && Voucher::STATUS_ACTIVE === $fresh->status ) {
					$fresh->status = Voucher::STATUS_REDEEMED;
				} elseif ( $balance > 0 && Voucher::STATUS_REDEEMED === $fresh->status ) {
					$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
				}
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, $event, $amount, $balance, $note, $order_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		$this->sync_balance_coupon( $fresh );
		return true;
	}
	/**
	 * Atomically deduct stored value under a row lock. For order redemptions, the audit row also
	 * makes retries for the same order idempotent.
	 *
	 * @return array{amount:float,balance:float,duplicate:bool}|null
	 */
	public function deduct_balance_atomic( Voucher $voucher, float $requested, string $note = '', int $order_id = 0 ): ?array {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}

			if ( $order_id > 0 ) {
				$prior = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT event, amount, balance_after FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1",
						$voucher->id,
						$order_id
					),
					ARRAY_A
				); // phpcs:ignore
				if ( $prior && in_array( $prior['event'], array( 'reserved', 'committed', 'redeemed' ), true ) ) {
					if ( 'reserved' === $prior['event'] && ! $this->repo->log( $voucher->id, 'committed', (float) $prior['amount'], (float) $prior['balance_after'], $note, $order_id ) ) {
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						return null;
					}
					if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						return null;
					}
					return array(
						'amount'    => (float) $prior['amount'],
						'balance'   => (float) $prior['balance_after'],
						'duplicate' => true,
					);
				}
			}

			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh || ! $fresh->is_multi_use() || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->is_expired() ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}

			$amount          = min( $fresh->balance, max( 0.0, round( $requested, $fresh->decimals() ) ) );
			$fresh->balance  = max( 0.0, round( $fresh->balance - $amount, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			if ( $amount > 0 || $order_id > 0 ) {
				if ( ! $this->repo->log( $fresh->id, 'redeemed', $amount, $fresh->balance, $note, $order_id ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return null;
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		if ( $fresh->coupon_id ) {
			$coupon = new \WC_Coupon( $fresh->coupon_id );
			if ( $coupon->get_id() ) {
				$coupon->set_amount( $fresh->balance );
				$coupon->save();
			}
		}
		return array(
			'amount'    => $amount,
			'balance'   => $fresh->balance,
			'duplicate' => false,
		);
	}

	/**
	 * Order in which WooCommerce applies coupons: discounts first (WooCommerce: product 1, percent 2, cart 3),
	 * then stored-value gift cards and store credit, which pay what is left like a means of payment. Store credit
	 * comes after gift cards unless "Use store credit first" is set.
	 */
	public function sort( $sort, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) || ! $coupon->is_type( 'fixed_cart' ) ) {
			return $sort;
		}
		$voucher = $coupon->get_id() ? $this->repo->get_by_coupon( $coupon->get_id() ) : null;
		if ( ! $voucher || ! $voucher->is_multi_use() ) {
			return $sort;
		}
		if ( 'credit' === $voucher->type ) {
			return 'first' === $this->settings->get( 'vcap_credit_apply_order' ) ? 90 : 110;
		}
		return 100;
	}

	/**
	 * Reject coupons whose voucher is void, not yet delivered or used up.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return $valid;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher ) {
			return $valid;
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			throw new \Exception( esc_html__( 'This voucher has been cancelled.', 'voucher-captain' ), 9001 );
		}
		if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
			throw new \Exception( esc_html__( 'This voucher is not active yet.', 'voucher-captain' ), 9004 );
		}
		if ( Voucher::STATUS_RESERVED === $voucher->status ) {
			// Being redeemed outside the shop right now (API reservation); released again if that fails.
			throw new \Exception( esc_html__( 'This voucher is currently being redeemed. Please try again in a few minutes.', 'voucher-captain' ), 9005 );
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status || ( $voucher->is_multi_use() && $voucher->balance <= 0 ) ) {
			throw new \Exception( esc_html__( 'This voucher has already been fully redeemed.', 'voucher-captain' ), 9002 );
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			throw new \Exception( esc_html__( 'This voucher has expired.', 'voucher-captain' ), 9003 );
		}
		if ( 'percent' !== $coupon->get_discount_type() ) {
			// Fixed values are in the shop base currency: never taken 1:1 in a cart or order in another currency.
			$object   = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
			$order    = $object instanceof \WC_Order ? $object : null;
			$currency = $order ? (string) $order->get_currency() : ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '' );
			if ( ! Compat\MultiCurrency::can_convert( $currency, $order ) ) {
				Compat\MultiCurrency::record_unknown( $currency );
				throw new \Exception( esc_html( self::currency_message( $currency ) ), 9007 );
			}
		}
		if ( ! Channels::allowed( $voucher, Channels::SHOP ) ) {
			/** Message for a voucher that is not redeemable in the shop (e.g. a ticket voucher: where to use it instead). */
			$message = (string) apply_filters( 'vcap_shop_channel_refused_message', __( 'This voucher cannot be redeemed in the shop.', 'voucher-captain' ), $voucher );
			throw new \Exception( esc_html( $message ), 9006 );
		}
		return $valid;
	}

	public function cart_balance_notice(): void {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$voucher = $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
			if ( $voucher && $voucher->is_multi_use() ) {
				echo '<tr class="vcap-balance"><th>' . esc_html(
					sprintf(
						/* translators: %s: voucher code */
						__( 'Voucher %s balance', 'voucher-captain' ),
						$voucher->code
					)
				) . '</th><td>' . wp_kses_post( wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) . '</td></tr>';
			}
		}
	}

	/**
	 * Recalculating an order (admin adds a coupon, changes items …) reads the coupon amount again, which for a
	 * stored balance is the *remaining* balance – after the reservation often 0, so the gift card line would
	 * shrink and the customer would be charged more. In order context the amount is what this order holds:
	 * the deducted amount of a paid order, or the reserved amount plus the free balance of an open order.
	 *
	 * @param \WC_Coupon $coupon Coupon object used for the recalculation.
	 * @param string     $code   Coupon code.
	 * @param mixed      $item   Coupon line item.
	 * @param \WC_Order  $order  Order.
	 */
	public function order_coupon_object( $coupon, $code, $item = null, $order = null ) {
		if ( ! $coupon instanceof \WC_Coupon || ! $order instanceof \WC_Order ) {
			return $coupon;
		}
		$code    = wc_format_coupon_code( (string) $code );
		$voucher = $this->find_voucher_for_code( $code );
		if ( ! $voucher || ! $voucher->is_multi_use() ) {
			return $coupon;
		}
		if ( null !== PaymentVouchers::paid_amount( $order, $code ) ) {
			// Paid after tax by its fee line: a recalculation must not turn it into a discount as well.
			$coupon->set_amount( 0 );
			return $coupon;
		}
		$redeemed = (float) ( self::order_map( $order, '_vcap_redemptions' )[ $code ] ?? 0 );
		$reserved = (float) ( self::order_map( $order, '_vcap_reservations' )[ $code ] ?? 0 );
		if ( $redeemed > 0 ) {
			$amount = $redeemed; // Already deducted: never more than the order was charged.
		} elseif ( $reserved > 0 ) {
			$fresh  = $this->repo->get( $voucher->id ) ?? $voucher;
			$amount = $reserved + max( 0.0, (float) $fresh->balance );
		} else {
			return $coupon;
		}
		$decimals = $voucher->decimals();
		$currency = (string) $order->get_currency();
		if ( '' !== $currency && $currency !== get_option( 'woocommerce_currency' ) ) {
			// Balances are kept in the base currency, the recalculated order is in its own currency.
			$per_unit = Compat\MultiCurrency::can_convert( $currency, $order ) ? (float) apply_filters( 'vcap_amount_to_base_currency', 1.0, $currency, $order ) : 0.0;
			if ( $per_unit > 0 ) {
				$amount = $amount / $per_unit;
			} elseif ( $item instanceof \WC_Order_Item_Coupon ) {
				// No exchange rate known: keep what the order already granted instead of reading base as order currency.
				$amount = (float) $item->get_discount();
				if ( apply_filters( 'vcap_balance_deduct_tax', wc_prices_include_tax() || apply_filters( 'vcap_balance_covers_tax', true, null ), $item ) ) {
					$amount += (float) $item->get_discount_tax();
				}
			} else {
				return $coupon;
			}
			$decimals = API\V1\RedemptionService::decimals( $currency );
		}
		$coupon->set_amount( round( $amount, $decimals ) );
		return $coupon;
	}

	/** @var array<string, float> Net coupon amounts for the next discount calculation (tax-exclusive shops). */
	private array $net_amounts = array();

	/**
	 * Tax-exclusive shops apply a fixed cart coupon to net prices and then reduce the tax as well, so a
	 * balance of 50 would pay 50 net + tax. A stored balance is money (a multi-purpose voucher pays the
	 * gross price), so right before WooCommerce distributes the coupon its amount is converted to net with
	 * the tax ratio of the discounted lines: the customer saves exactly the balance, and exactly that is
	 * deducted (net + tax, see used_amount()). Filter "vcap_balance_covers_tax" (false = old behaviour).
	 *
	 * @param array      $items  Items the coupon applies to (WC_Discounts items).
	 * @param \WC_Coupon $coupon Coupon.
	 */
	public function prepare_gross_balance( $items, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $items;
		}
		$code = wc_format_coupon_code( $coupon->get_code() );
		unset( $this->net_amounts[ $code ] );
		if ( ! wc_tax_enabled() || wc_prices_include_tax() || 'fixed_cart' !== $coupon->get_discount_type() || ! apply_filters( 'vcap_balance_covers_tax', true, $coupon ) ) {
			return $items;
		}
		$voucher = $this->repo->get_by_code( $code );
		if ( ! $voucher || ! $voucher->is_multi_use() || ( PaymentVouchers::enabled() && ( new PaymentVouchers( $this->repo ) )->applies( $coupon ) ) ) {
			return $items; // Paid after tax as a fee line: nothing to convert.
		}
		$net = 0.0;
		$tax = 0.0;
		foreach ( (array) $items as $item ) {
			if ( isset( $item->subtotal, $item->subtotal_tax ) ) { // Cart totals items (same precision for both).
				$net += (float) $item->subtotal;
				$tax += (float) $item->subtotal_tax;
			} elseif ( isset( $item->object ) && $item->object instanceof \WC_Order_Item_Product ) {
				$net += (float) $item->object->get_subtotal();
				$tax += (float) $item->object->get_subtotal_tax();
			}
		}
		if ( $net > 0 && $tax > 0 ) {
			$factor                     = 10 ** wc_get_price_decimals();
			$this->net_amounts[ $code ] = floor( (float) $coupon->get_amount( 'edit' ) / ( 1 + $tax / $net ) * $factor ) / $factor;
		}
		return $items;
	}

	/** One-shot: the net amount prepared above, read by WooCommerce right after the items filter. */
	public function gross_balance_amount( $amount, $coupon ) {
		$code = $coupon instanceof \WC_Coupon ? wc_format_coupon_code( $coupon->get_code() ) : '';
		if ( '' !== $code && isset( $this->net_amounts[ $code ] ) ) {
			$net = $this->net_amounts[ $code ];
			unset( $this->net_amounts[ $code ] );
			return $net;
		}
		return $amount;
	}

	/** What the coupon line used, in the base currency; null when the order is in a currency without known rate. */
	private function used_amount( \WC_Order_Item_Coupon $item ): ?float {
		$order = $item->get_order();
		// Multi-purpose voucher paid after tax (PaymentVouchers): the fee line holds the gross amount paid.
		$paid = $order instanceof \WC_Order ? PaymentVouchers::paid_amount( $order, (string) $item->get_code() ) : null;
		if ( null !== $paid ) {
			$paid = Compat\MultiCurrency::to_base( (float) $paid, (string) $order->get_currency(), $order );
			return null === $paid ? null : round( $paid, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
		}
		$amount = (float) $item->get_discount();
		// The value the customer received is the discount plus the tax it removed – in tax-inclusive shops
		// (coupons apply to gross prices) and, for stored balances, in tax-exclusive shops (see prepare_gross_balance()).
		if ( apply_filters( 'vcap_balance_deduct_tax', wc_prices_include_tax() || apply_filters( 'vcap_balance_covers_tax', true, null ), $item ) ) {
			$amount += (float) $item->get_discount_tax();
		}
		// Discounts are in the order currency, balances in the shop base currency (never converted 1:1).
		if ( $order instanceof \WC_Order ) {
			$amount = Compat\MultiCurrency::to_base( $amount, (string) $order->get_currency(), $order );
			if ( null === $amount ) {
				return null;
			}
		}
		return round( $amount, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
	}

	/** Why a fixed-value voucher cannot be used in this currency (no exchange rate to the base currency). */
	public static function currency_message( string $currency ): string {
		return sprintf(
			/* translators: 1: shop base currency, 2: currency of the cart or order */
			__( 'This voucher is kept in %1$s and cannot be used for a payment in %2$s, because no exchange rate is known. Please pay in %1$s to use it.', 'voucher-captain' ),
			(string) get_option( 'woocommerce_currency' ),
			$currency
		);
	}

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

	public function register_settle_hooks(): void {
		foreach ( self::deduct_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'settle_before_fulfilment' ), 1, 2 );
		}
	}

	/** Status hook, priority 1: commit and deduct before vouchers, bonus coupons or tickets are issued. */
	public function settle_before_fulfilment( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! in_array( $order->get_status(), self::deduct_statuses(), true ) ) {
			return;
		}
		$this->settled[ $order->get_id() ] = true;
		$this->commit_order_reservations( $order );
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
		$applied      = self::order_map( $order, '_vcap_redemptions' );
		$reservations = self::order_map( $order, '_vcap_reservations' );
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = $this->find_voucher_for_code( $code );
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
				$this->commit_order_reservations( $order );
				$this->deduct_order( $order );
			} elseif ( self::has_shortfall( $order ) && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'Voucher balance shortfall requires manual review before fulfilment.', 'voucher-captain' ) );
			}
			unset( $this->settled[ $order->get_id() ] );
		} elseif ( in_array( $to, $restore_statuses, true ) ) {
			$this->release_order_reservations( $order );
			if ( $this->settings->yes( 'vcap_restore_on_cancel' ) ) {
				$this->restore_order( $order );
			}
		}
	}

	public function deduct_order( \WC_Order $order, bool $hold = true ): void {
		$applied      = self::order_map( $order, '_vcap_redemptions' );
		$reservations = self::order_map( $order, '_vcap_reservations' );
		// Codes charged with less than their discount (code => missing amount): such a gap stays open until an
		// administrator accepts it, even though the code is already booked.
		$missing      = self::order_map( $order, self::SHORTFALL_AMOUNTS_META );
		$changed      = false;
		$shortfall    = false;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var \WC_Order_Item_Coupon $item */
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $applied[ $code ] ) ) {
				continue;
			}
			$voucher = $this->find_voucher_for_code( $code );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$requested = $this->used_amount( $item );
			if ( null === $requested ) {
				// Foreign currency without exchange rate: never charge the balance 1:1; hold for a review.
				$shortfall = true;
				$changed   = true;
				$order->add_order_note(
					/* translators: 1: voucher code, 2: order currency */
					sprintf( __( 'Voucher %1$s: the discount could not be deducted from its balance because no exchange rate for %2$s is known. Review this order and adjust the charge before fulfilment.', 'voucher-captain' ), $voucher->code, $order->get_currency() )
				);
				continue;
			}
			$deduction = $this->deduct_balance_atomic(
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
					sprintf( __( 'Voucher %s: the discount could not be deducted from its balance. Review this order and adjust the charge before fulfilment.', 'voucher-captain' ), $voucher->code )
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
					$voucher->code,
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
		} elseif ( self::has_shortfall( $order ) && ! $this->open_balance_coupons( $order, $applied ) ) {
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
	}

	private function restore_balance_atomic( Voucher $voucher, float $amount, int $order_id, string $note ): bool {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( $amount <= 0 || $order_id <= 0 || ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event, amount FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1",
					$voucher->id,
					$order_id
				),
				ARRAY_A
			); // phpcs:ignore
			if ( $latest && 'restored' === $latest['event'] ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return true;
			}
			if ( ! $latest || ! in_array( $latest['event'], array( 'committed', 'redeemed' ), true ) || abs( (float) $latest['amount'] - $amount ) >= 0.0001 ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, 'restored', $amount, $fresh->balance, $note, $order_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		$this->sync_balance_coupon( $fresh );
		return true;
	}

	public function restore_order( \WC_Order $order ): void {
		$applied = self::order_map( $order, '_vcap_redemptions' );
		if ( ! $applied ) {
			return;
		}
		$restored = 0;
		foreach ( $applied as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			if ( $voucher && $this->restore_balance_atomic(
				$voucher,
				(float) $amount,
				$order->get_id(),
				sprintf( /* translators: %s: order number */ __( 'Order #%s cancelled or refunded', 'voucher-captain' ), $order->get_order_number() )
			) ) {
				++$restored;
			}
		}
		if ( $restored === count( $applied ) ) {
			$order->delete_meta_data( '_vcap_redemptions' );
			$order->save();
		} else {
			$order->add_order_note( __( 'Some voucher balances could not be restored. Review this order and retry the refund handling.', 'voucher-captain' ) );
		}
	}
	/** Whether balance vouchers of the order are still not charged. */
	private function open_balance_coupons( \WC_Order $order, array $applied ): bool {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = $this->find_voucher_for_code( $code );
			if ( $voucher && $voucher->is_multi_use() && ! isset( $applied[ $code ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function find_voucher_for_code( string $code ): ?Voucher {
		$coupon_id = wc_get_coupon_id_by_code( $code );
		return $coupon_id ? $this->repo->get_by_coupon( (int) $coupon_id ) : $this->repo->get_by_code( $code );
	}

	/**
	 * Single-use vouchers: mark redeemed once WooCommerce's usage limit is reached.
	 */
	/**
	 * Whether a single-use voucher is held by a shop order that is not paid yet (WooCommerce's tentative coupon
	 * usage). Such a voucher must not be redeemed elsewhere at the same time.
	 */
	public static function held_by_pending_order( Voucher $voucher ): bool {
		if ( $voucher->is_multi_use() || ! $voucher->coupon_id ) {
			return false;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		$store  = $coupon->get_id() ? $coupon->get_data_store() : null;
		return $store && method_exists( $store, 'get_tentative_usage_count' ) && (int) $store->get_tentative_usage_count( $coupon->get_id() ) > 0;
	}

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
				$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, (string) $used_by );
			}
		} elseif ( $limit > 0 && (int) $new_count >= $limit && Voucher::STATUS_ACTIVE !== $voucher->status ) {
			// Used in the shop although it was redeemed, reserved or voided elsewhere meanwhile: make it visible.
			$this->repo->log( $voucher->id, 'double_use', $voucher->initial_amount, null, (string) $used_by );
			do_action( 'vcap_voucher_double_use', $voucher, $coupon, $used_by );
		}
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
		if ( Voucher::STATUS_REDEEMED === $voucher->status && ( 0 === $limit || (int) $new_count < $limit ) ) {
			$voucher->status  = Voucher::STATUS_ACTIVE;
			$voucher->balance = $voucher->initial_amount;
			$this->repo->update( $voucher );
			$this->repo->log( $voucher->id, 'restored', $voucher->initial_amount, $voucher->balance, (string) $used_by );
		}
	}
}
