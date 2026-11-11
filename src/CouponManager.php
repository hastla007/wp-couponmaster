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
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reserve_order' ), 5, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reserve_store_api_order' ), 5, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'reserve_retry_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
		add_action( 'woocommerce_increase_coupon_usage_count', array( $this, 'usage_increased' ), 10, 3 );
		add_action( 'woocommerce_decrease_coupon_usage_count', array( $this, 'usage_decreased' ), 10, 3 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_balance_notice' ) );
	}

	public function code_exists( string $code ): bool {
		return $this->repo->code_exists( $code ) || wc_get_coupon_id_by_code( $code ) > 0;
	}

	public function create_coupon( Voucher $voucher, array $config ): int {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $voucher->code );
		$coupon->set_discount_type( in_array( $voucher->discount_type, array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ? $voucher->discount_type : 'fixed_cart' );
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

		$emails = array();
		if ( 'recipient' === $config['email_restrict'] && is_email( $voucher->recipient_email ) ) {
			$emails[] = $voucher->recipient_email;
		} elseif ( 'buyer' === $config['email_restrict'] && is_email( $voucher->buyer_email ) ) {
			$emails[] = $voucher->buyer_email;
		} elseif ( 'both' === $config['email_restrict'] ) {
			$emails = array_filter( array( $voucher->buyer_email, $voucher->recipient_email ), 'is_email' );
		}
		$coupon->set_email_restrictions( array_values( array_unique( $emails ) ) );

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

	public function set_coupon_enabled( Voucher $voucher, bool $enabled ): void {
		if ( ! $voucher->coupon_id ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $voucher->coupon_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			)
		);
		// WooCommerce caches code → ID lookups; drop it so a persistent object cache cannot keep a disabled coupon alive.
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . wc_format_coupon_code( $voucher->code ), 'coupons' );
		}
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

		$redemptions  = (array) $order->get_meta( '_vcap_redemptions' );
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
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
			if ( $amount > 0 ) {
				$coupons[ $code ] = array( 'voucher' => $voucher, 'amount' => $amount );
			}
		}

		// Keep matching reservations and release removed/changed coupon lines before rebuilding the map.
		$made = array();
		foreach ( $reservations as $code => $reserved_amount ) {
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
				sprintf( __( 'Reserved for order #%s', 'voucher-captain' ), $order->get_order_number() )
			);
			if ( ! $reservation || 'reserved' !== $reservation['state'] || abs( $reservation['amount'] - $amount ) >= 0.0001 ) {
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
			$requested = round( $requested, wc_get_price_decimals() );
			if ( ! $fresh || ! $fresh->is_multi_use() || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->is_expired() || $fresh->balance < $requested ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$fresh->balance = max( 0.0, round( $fresh->balance - $requested, wc_get_price_decimals() ) );
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
			$fresh->balance = round( $fresh->balance + $amount, wc_get_price_decimals() );
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
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$redemptions = (array) $order->get_meta( '_vcap_redemptions' );
		$committed   = array();
		foreach ( $reservations as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			$result  = $voucher ? $this->commit_reservation_atomic( $voucher, $order->get_id() ) : null;
			if ( ! $result ) {
				continue;
			}
			$redemptions[ $code ] = $result['amount'];
			$committed[ $code ]  = $result['amount'];
		}
		if ( count( $committed ) !== count( $reservations ) ) {
			$order->add_order_note( __( 'Some voucher reservations could not be committed. Review this order before fulfilment.', 'voucher-captain' ) );
			return;
		}
		$order->update_meta_data( '_vcap_redemptions', $redemptions );
		$order->delete_meta_data( '_vcap_reservations' );
		$order->save();
	}

	/**
	 * Release every reservation on an order that failed or was cancelled before payment.
	 */
	private function release_order_reservations( \WC_Order $order ): void {
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
		if ( ! $reservations ) {
			return;
		}
		$released = 0;
		foreach ( $reservations as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			if ( $voucher && $this->release_reservation_atomic( $voucher, $order->get_id(), sprintf( __( 'Released from order #%s', 'voucher-captain' ), $order->get_order_number() ) ) ) {
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
			$balance           = max( 0.0, round( $balance, wc_get_price_decimals() ) );
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

			$amount          = min( $fresh->balance, max( 0.0, round( $requested, wc_get_price_decimals() ) ) );
			$fresh->balance  = max( 0.0, round( $fresh->balance - $amount, wc_get_price_decimals() ) );
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
		if ( Voucher::STATUS_REDEEMED === $voucher->status || ( $voucher->is_multi_use() && $voucher->balance <= 0 ) ) {
			throw new \Exception( esc_html__( 'This voucher has already been fully redeemed.', 'voucher-captain' ), 9002 );
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			throw new \Exception( esc_html__( 'This voucher has expired.', 'voucher-captain' ), 9003 );
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

	private function used_amount( \WC_Order_Item_Coupon $item ): float {
		$amount = (float) $item->get_discount();
		// Shops with tax-inclusive prices apply coupons to gross prices, so the tax part belongs to the redeemed
		// value. In tax-exclusive shops coupon amounts are net, so only the net discount is deducted.
		if ( apply_filters( 'vcap_balance_deduct_tax', wc_prices_include_tax(), $item ) ) {
			$amount += (float) $item->get_discount_tax();
		}
		return $amount;
	}

	/**
	 * Deduct stored balances when an order using balance vouchers is paid; restore on cancellation.
	 */
	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$deduct_statuses  = apply_filters( 'vcap_balance_deduct_statuses', array( 'processing', 'completed' ) );
		$restore_statuses = apply_filters( 'vcap_balance_restore_statuses', array( 'cancelled', 'refunded', 'failed' ) );

		if ( in_array( $to, $deduct_statuses, true ) ) {
			$this->commit_order_reservations( $order );
			$this->deduct_order( $order );
		} elseif ( in_array( $to, $restore_statuses, true ) ) {
			$this->release_order_reservations( $order );
			if ( $this->settings->yes( 'vcap_restore_on_cancel' ) ) {
				$this->restore_order( $order );
			}
		}
	}

	public function deduct_order( \WC_Order $order ): void {
		$applied      = (array) $order->get_meta( '_vcap_redemptions' );
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
		$changed      = false;
		$shortfall    = false;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var \WC_Order_Item_Coupon $item */
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $applied[ $code ] ) || isset( $reservations[ $code ] ) ) {
				continue;
			}
			$voucher = $this->find_voucher_for_code( $code );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$requested = $this->used_amount( $item );
			$deduction = $this->deduct_balance_atomic(
				$voucher,
				$requested,
				/* translators: %s: order number */
				sprintf( __( 'Used in order #%s', 'voucher-captain' ), $order->get_order_number() ),
				$order->get_id()
			);
			if ( ! $deduction ) {
				continue;
			}
			$amount           = $deduction['amount'];
			$voucher->balance = $deduction['balance'];
			if ( $amount + 0.0001 < $requested ) {
				$shortfall = true;
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
		if ( $changed ) {
			$order->update_meta_data( '_vcap_redemptions', $applied );
			$order->save();
			if ( $shortfall && 'on-hold' !== $order->get_status() ) {
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
			$fresh->balance = round( $fresh->balance + $amount, wc_get_price_decimals() );
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
		$applied = (array) $order->get_meta( '_vcap_redemptions' );
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
				sprintf( __( 'Order #%s cancelled or refunded', 'voucher-captain' ), $order->get_order_number() )
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
	private function find_voucher_for_code( string $code ): ?Voucher {
		$coupon_id = wc_get_coupon_id_by_code( $code );
		return $coupon_id ? $this->repo->get_by_coupon( (int) $coupon_id ) : $this->repo->get_by_code( $code );
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
			$voucher->status  = Voucher::STATUS_REDEEMED;
			$voucher->balance = 0;
			$this->repo->update( $voucher );
			$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, (string) $used_by );
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
