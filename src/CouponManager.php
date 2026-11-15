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
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'prepare_gross_balance' ), 20, 3 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'gross_balance_amount' ), 20, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'order_coupon_object' ), 10, 4 );
		// A code marked "Individual use only" does not push out gift cards, store credit and other multi-purpose
		// vouchers: they are a means of payment, not a discount (classic cart and Store API use both filters).
		add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'keep_payment_means' ), 10, 3 );
		add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'allow_payment_means' ), 10, 2 );
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
		// Right before WooCommerce counts the order's coupons (priority 10): a single-use voucher that was voided or
		// redeemed elsewhere since checkout must not be fulfilled with its discount.
		foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'check_single_use_conflicts' ), 9, 2 );
			// The order whose coupons WooCommerce counts next (priority 10): the redemption of a single-use
			// voucher is recorded with it (D8), the usage hook itself names no order.
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'remember_usage_order' ), 8, 1 );
		}
		// WooCommerce before 9.x keeps the coupon usage of a failed order counted, so the check above skips it: an
		// unpaid order that is paid now is checked once more for vouchers voided meanwhile (before order_status_changed()).
		add_action( 'woocommerce_order_status_changed', array( $this, 'check_single_use_conflicts_on_payment' ), 5, 4 );
		add_action( 'woocommerce_decrease_coupon_usage_count', array( $this, 'usage_decreased' ), 10, 3 );
		// WooCommerce gives coupon uses back on "Cancelled" (priority 10): with "Restore balance on cancellation"
		// off, single-use vouchers stay redeemed like balances.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'keep_single_use_start' ), 1, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'keep_single_use_end' ), 99, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_balance_notice' ) );
		// Free balance holds of unpaid orders that are trashed, deleted or abandoned.
		add_action( 'woocommerce_trash_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'wp_trash_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'before_delete_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'vcap_release_stale_order_reservations', array( $this, 'release_stale_order_reservations' ) );
		// A voucher's coupon follows the voucher row: an amount edited or saved in WooCommerce is set back to the
		// balance, and trashing or deleting it in wp-admin or over the REST API is refused (logged otherwise).
		add_action( 'woocommerce_update_coupon', array( $this, 'coupon_saved' ), 20, 2 );
		add_filter( 'pre_trash_post', array( $this, 'protect_coupon' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'protect_coupon' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'log_coupon_removed' ), 20 );
		add_action( 'before_delete_post', array( $this, 'log_coupon_removed' ), 20 );
	}

	/** @var bool Set while sync_coupon_amount() saves a coupon (its own save hook must not sync again). */
	private static bool $syncing = false;

	/**
	 * Write the voucher's current balance to its WooCommerce coupon. Bookings change the balance under the voucher
	 * row lock but save the coupon after their COMMIT, so two bookings could save in the opposite order and leave an
	 * old amount on the coupon (then every checkout with the card fails until the next booking). The coupon is
	 * therefore saved under the row lock again, with the balance read under it: the last save always carries the
	 * latest balance, whatever the passed (possibly stale) voucher object says.
	 */
	public static function sync_coupon_amount( Voucher $voucher ): bool {
		global $wpdb;
		if ( ! $voucher->coupon_id || self::$syncing || ! class_exists( '\WC_Coupon' ) ) {
			return false;
		}
		$table = Install::tables()['vouchers'];
		$tx    = Install::tables_support_transactions() && Db::begin();
		self::$syncing = true;
		try {
			$lock = $tx ? ' FOR UPDATE' : '';
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT balance, usage_mode, currency FROM {$table} WHERE id = %d{$lock}", $voucher->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok  = false;
			if ( $row && 'balance' === $row['usage_mode'] ) {
				// Read the coupon under the lock, past this request's cache (another process may have saved it).
				wp_cache_delete( $voucher->coupon_id, 'posts' );
				wp_cache_delete( $voucher->coupon_id, 'post_meta' );
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				if ( $coupon->get_id() ) {
					$balance = round( max( 0.0, (float) $row['balance'] ), API\V1\RedemptionService::decimals( (string) $row['currency'] ) );
					if ( abs( (float) $coupon->get_amount( 'edit' ) - $balance ) > 0.000001 ) {
						$coupon->set_amount( $balance );
						$coupon->save();
					}
					$ok = true;
				}
			}
			if ( $tx && ! Db::commit() ) {
				Db::rollback_if_error( 'sync_coupon_amount', array( 'voucher_id' => $voucher->id ) );
			}
			return $ok;
		} catch ( \Throwable $e ) {
			if ( $tx ) {
				Db::rollback_silently();
			}
			throw $e;
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * A voucher coupon was saved in WooCommerce (coupon editor, REST API, another plugin): a stored balance is kept
	 * by the voucher row, so a different amount is set back to it (an edited or failed save would otherwise send
	 * every checkout into a shortfall).
	 *
	 * @param int        $coupon_id Coupon id.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public function coupon_saved( $coupon_id, $coupon = null ): void {
		if ( self::$syncing || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( (int) $coupon_id );
		if ( $voucher && $voucher->is_multi_use() && abs( (float) $coupon->get_amount( 'edit' ) - (float) $voucher->balance ) > 0.000001 ) {
			self::sync_coupon_amount( $voucher );
		}
	}

	/** Voucher whose coupon this post is (null for every other post). */
	private function voucher_of_coupon_post( $post ): ?Voucher {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || 'shop_coupon' !== $post->post_type || 'yes' !== get_post_meta( $post->ID, '_vcap_voucher', true ) ) {
			return null;
		}
		return $this->repo->get_by_coupon( (int) $post->ID );
	}

	/**
	 * Refuse trashing or deleting the coupon of an existing voucher in wp-admin and over the REST API: the voucher
	 * would still look usable (liability, reminders) while its code no longer works, or vice versa. Plugin code
	 * (repairs, rollbacks) may still remove coupons; filter "vcap_protect_voucher_coupons".
	 *
	 * @param mixed    $check Short-circuit value of pre_trash_post / pre_delete_post.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public function protect_coupon( $check, $post = null ) {
		if ( null !== $check ) {
			return $check;
		}
		$voucher = $this->voucher_of_coupon_post( $post );
		if ( ! $voucher ) {
			return $check;
		}
		$interactive = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		/**
		 * Whether trashing/deleting a voucher's WooCommerce coupon is refused (default: in wp-admin and REST requests).
		 *
		 * @param bool    $protect Refuse the removal.
		 * @param Voucher $voucher Voucher.
		 */
		if ( apply_filters( 'vcap_protect_voucher_coupons', $interactive, $voucher ) ) {
			return false;
		}
		return $check;
	}

	/** A voucher coupon that is trashed or deleted anyway is recorded in the voucher's history. */
	public function log_coupon_removed( $post_id ): void {
		$voucher = $this->voucher_of_coupon_post( $post_id );
		if ( $voucher ) {
			$this->repo->log( $voucher->id, 'coupon_removed', null, null, 'wp_trash_post' === current_action() ? __( 'WooCommerce coupon moved to the trash', 'voucher-captain' ) : __( 'WooCommerce coupon deleted', 'voucher-captain' ) );
		}
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
			if ( $modified > $cutoff || ! self::order_map( $order, '_vcap_reservations' ) ) {
				continue;
			}
			$this->release_order_reservations( $order );
			$order->add_order_note( __( 'Unpaid order: voucher balance reservations released.', 'voucher-captain' ) );
		}
		$this->release_orphaned_holds( $cutoff );
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
		return CodeIndex::taken( $code ); // B4: own tables first (indexed), WooCommerce last, misses cached.
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
		$emails                 = self::email_restrictions( $voucher, $restrict );
		$this->restriction_kept = false;
		if ( ! $emails ) {
			// Neither address is usable (erased buyer, placeholder): a personal voucher must never silently become
			// transferable. The previous restriction stays, else an address nobody can have, until an
			// administrator enters a valid address (VoucherAdmin shows a warning).
			$emails                 = $current ? $current : array( 'unassigned-' . $voucher->id . '@invalid.invalid' );
			$this->restriction_kept = true;
		}
		$coupon->set_email_restrictions( $emails );
		$coupon->update_meta_data( '_vcap_email_restrict', $restrict );
		$coupon->save();
		return true;
	}

	/** Whether the last refresh_email_restrictions() kept the old restriction for want of a valid address. */
	public bool $restriction_kept = false;

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
				throw new \Exception( esc_html( self::currency_message( (string) $order->get_currency() ) ) );
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
				throw new \Exception( esc_html__( 'An earlier voucher reservation could not be released. Please contact the store before trying again.', 'voucher-captain' ) );
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
				// Roll back this attempt's holds. A hold that cannot be released stays in the order's map (so the
				// stale-order sweep, a cancellation or deleting the order still finds it) and gets an order note.
				$kept = array();
				if ( ! $this->release_reservation_atomic( $voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) ) ) {
					$held = $this->open_hold( $voucher, $order->get_id() );
					if ( null !== $held ) {
						$kept[ $code ] = $held;
					}
				}
				foreach ( $made as $reserved_code => $reserved_amount ) {
					$reserved_voucher = $this->find_voucher_for_code( $reserved_code );
					if ( ! $reserved_voucher || ! $this->release_reservation_atomic( $reserved_voucher, $order->get_id(), __( 'Checkout reservation rolled back', 'voucher-captain' ) ) ) {
						$kept[ $reserved_code ] = (float) $reserved_amount;
					}
				}
				if ( $kept ) {
					$order->update_meta_data( '_vcap_reservations', $kept );
					$order->add_order_note( __( 'Some voucher reservations could not be released. Review this order.', 'voucher-captain' ) );
				} else {
					$order->delete_meta_data( '_vcap_reservations' );
				}
				$order->save();
				$this->balance_gone = $voucher;
				throw new \Exception( esc_html__( 'A voucher balance changed while checkout was processing. Your order was not sent for payment. Refresh the cart and try again.', 'voucher-captain' ), self::BALANCE_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped, the second argument is the integer exception code.
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
		$this->release_account_orders_with_same_vouchers( $order, $previous_id );
		if ( ! $previous_id || $previous_id === $order->get_id() ) {
			return;
		}
		$previous = wc_get_order( $previous_id );
		if ( ! $previous instanceof \WC_Order || ! $previous->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) || $previous->is_paid() ) {
			return;
		}
		// Same session: the same customer, or the guest who logged in (or the account that logged out) with the
		// same billing email; the session alone is not enough for orders of different people.
		$same_email = '' !== (string) $order->get_billing_email() && 0 === strcasecmp( (string) $previous->get_billing_email(), (string) $order->get_billing_email() );
		if ( (int) $previous->get_customer_id() !== (int) $order->get_customer_id() && ! $same_email ) {
			return;
		}
		$this->release_order_reservations( $previous );
		( new Discounts\Usage() )->revert( $previous->get_id() );
	}

	/**
	 * A customer account's new checkout releases its own unpaid earlier orders that hold a reservation of a voucher
	 * this order uses (e.g. an order placed before logging out, whose session is gone). Paying such an order later
	 * through its pay link reserves again (reserve_retry_order()).
	 */
	private function release_account_orders_with_same_vouchers( \WC_Order $order, int $skip ): void {
		$customer = (int) $order->get_customer_id();
		$codes    = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
		if ( $customer <= 0 || ! $codes ) {
			return;
		}
		$earlier = wc_get_orders(
			array(
				'customer_id' => $customer,
				'status'      => array( 'pending', 'failed', 'checkout-draft' ),
				'exclude'     => array_filter( array( $order->get_id(), $skip ) ),
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		foreach ( $earlier as $previous ) {
			if ( $previous instanceof \WC_Order && ! $previous->is_paid() && array_intersect( array_keys( self::order_map( $previous, '_vcap_reservations' ) ), $codes ) ) {
				$this->release_order_reservations( $previous );
			}
		}
	}

	/**
	 * Convert reservation failures into Store API client errors instead of uncaught server errors.
	 *
	 * The Store API also pays existing orders ("Pay for order" through checkout/{id}): like the classic pay form
	 * (reserve_retry_order()), a single-use voucher of the order that was cancelled, redeemed or reserved elsewhere
	 * since the checkout refuses the payment instead of letting the order be paid with its discount.
	 */
	public function reserve_store_api_order( $order ): void {
		try {
			if ( $order instanceof \WC_Order ) {
				$conflicts = $this->single_use_conflicts( $order, true );
				if ( $conflicts ) {
					throw new \Exception( esc_html( self::single_use_conflict_message( $conflicts[0]['voucher'] ) ) );
				}
			}
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_voucher_reservation_failed', esc_html( $e->getMessage() ), 409 );
			}
			throw $e;
		}
	}

	/** Exception code of reserve_order(): a gift card no longer has the balance the order uses. */
	public const BALANCE_CHANGED = 9102;

	/** The voucher whose balance was missing in the last failed reserve_order() (pay page message and note). */
	private ?Voucher $balance_gone = null;

	/**
	 * Re-reserve voucher balances before a customer retries payment for a failed order.
	 */
	public function reserve_retry_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			foreach ( $this->single_use_conflicts( $order, true ) as $conflict ) {
				wc_add_notice( self::single_use_conflict_message( $conflict['voucher'] ), 'error' );
			}
		}
		try {
			$this->reserve_order( $order );
		} catch ( \Throwable $e ) {
			wc_add_notice( $this->pay_page_message( $e, $order ), 'error' );
		}
	}

	/**
	 * Message for paying an existing order (pay page, Store API "pay for order"): there is no cart to refresh, so a
	 * gift card whose balance was used elsewhere meanwhile gets its own message, and the order a note for the shop.
	 */
	private function pay_page_message( \Throwable $e, $order ): string {
		if ( self::BALANCE_CHANGED !== $e->getCode() || ! $order instanceof \WC_Order ) {
			return $e->getMessage();
		}
		$code = $this->balance_gone ? $this->balance_gone->code : '';
		$order->add_order_note(
			sprintf(
				/* translators: %s: voucher code */
				__( 'Payment attempt refused: voucher %s no longer has the balance this order uses (it was used elsewhere). Contact the customer, or edit or cancel the order.', 'voucher-captain' ),
				$code
			)
		);
		return sprintf(
			/* translators: %s: voucher code */
			__( 'Voucher %s no longer has enough balance for this order (it was used elsewhere meanwhile). This order cannot be paid as it is. Please contact us to complete it.', 'voucher-captain' ),
			$code
		);
	}

	/** Customer message: a single-use voucher of an unpaid order can no longer be used (single_use_conflicts()). */
	private static function single_use_conflict_message( Voucher $voucher ): string {
		/* translators: %s: voucher code */
		return sprintf( __( 'Voucher %s can no longer be used (it was cancelled or redeemed elsewhere). Please contact us to complete this order.', 'voucher-captain' ), $voucher->code );
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
		if ( $order_id <= 0 || $requested <= 0 || ! Install::tables_support_transactions() || ! Db::begin() ) {
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
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
				Db::finish();
				return array( 'amount' => (float) $latest['amount'], 'balance' => (float) $latest['balance_after'], 'state' => 'committed' );
			}
			if ( $latest && 'reserved' === $latest['event'] ) {
				$amount = (float) $latest['amount'];
				Db::finish();
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
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			$fresh->balance = max( 0.0, round( $fresh->balance - $requested, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( ! $this->repo->log( $fresh->id, 'reserved', $requested, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
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
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
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
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( 'committed' !== $latest['event'] && 'redeemed' !== $latest['event'] ) {
				if ( 'reserved' !== $latest['event'] ) {
					Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
				$fresh = $this->repo->get( $voucher->id );
				if ( ! $fresh || ! $this->repo->log( $voucher->id, 'committed', (float) $latest['amount'], $fresh->balance, '', $order_id ) ) {
					Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
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
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
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
				Db::finish();
				return (bool) ( $latest && in_array( $latest['event'], array( 'reservation_released', 'restored' ), true ) );
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$amount         = (float) $latest['amount'];
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! $this->repo->log( $fresh->id, 'reservation_released', $amount, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		$this->sync_balance_coupon( $fresh );
		return true;
	}

	/**
	 * Value of a stored balance that is held right now and not part of the balance: reservations of unpaid orders
	 * (latest ledger event "reserved") and open external reservations. Released holds return to the balance.
	 */
	public function open_holds( Voucher $voucher ): float {
		global $wpdb;
		if ( ! $voucher->is_multi_use() ) {
			return 0.0;
		}
		$t      = Install::tables();
		$orders = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( e.amount ), 0 ) FROM {$t['events']} e WHERE e.voucher_id = %d AND e.event = 'reserved' AND e.order_id > 0 AND NOT EXISTS ( SELECT 1 FROM {$t['events']} x WHERE x.voucher_id = e.voucher_id AND x.order_id = e.order_id AND x.id > e.id )", $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$api    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$t['redemptions']} WHERE voucher_id = %d AND state = %s", $voucher->id, API\V1\RedemptionService::STATE_RESERVED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return round( $orders + $api, $voucher->decimals() );
	}

	/** Amount of the order's hold on the voucher when its latest ledger event is an open reservation, else null. */
	private function open_hold( Voucher $voucher, int $order_id ): ?float {
		global $wpdb;
		$events = Install::tables()['events'];
		$latest = $wpdb->get_row( $wpdb->prepare( "SELECT event, amount FROM {$events} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1", $voucher->id, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $latest && 'reserved' === $latest['event'] ? (float) $latest['amount'] : null;
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
		$days   = max( 1, (int) apply_filters( 'vcap_orphaned_hold_days', 90 ) );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT e.voucher_id, e.order_id FROM {$events} e WHERE e.event = 'reserved' AND e.order_id > 0 AND e.created_at >= %s
					AND NOT EXISTS ( SELECT 1 FROM {$events} x WHERE x.voucher_id = e.voucher_id AND x.order_id = e.order_id AND x.id > e.id )
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
					if ( $modified > $cutoff || isset( self::order_map( $order, '_vcap_reservations' )[ $code ] ) ) {
						continue; // Checkout in progress, or the map-based sweep handles it.
					}
				}
			}
			/* translators: %s: order id */
			$note = sprintf( __( 'Orphaned reservation of order #%s released', 'voucher-captain' ), (int) $row['order_id'] );
			if ( ! $this->release_reservation_atomic( $voucher, (int) $row['order_id'], $note ) ) {
				continue;
			}
			++$released;
			if ( $order instanceof \WC_Order ) {
				$map = self::order_map( $order, '_vcap_reservations' );
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
				$order->add_order_note( sprintf( __( 'A voucher reservation of %s that was left open has been released.', 'voucher-captain' ), Downloads::masked_code( $voucher->code ) ) );
			}
		}
		return $released;
	}

	private function sync_balance_coupon( Voucher $voucher ): void {
		self::sync_coupon_amount( $voucher );
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
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			$fresh = $locked ? $this->repo->get( $voucher->id ) : null;
			if ( ! $fresh ) {
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$before            = (float) $fresh->balance;
			$balance           = max( 0.0, round( (float) $compute( $before ), $fresh->decimals() ) );
			// U6: an admin correction keeps its direction (+ raised, − lowered) so the journal can be summed.
			$amount            = $amount ?? ( 'adjusted' === $event ? round( $balance - $before, $fresh->decimals() ) : abs( $balance - $before ) );
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
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		$this->sync_balance_coupon( $fresh );
		return true;
	}

	/**
	 * Why a stored-value voucher cannot be charged right now ('' = it can): one of
	 * 'not_found', 'void', 'expired', 'reserved', 'scheduled', 'redeemed' (balance used up), 'not_active'
	 * (any other status), 'not_multi_use' (active, but no stored value). Pure, so the counter and the
	 * tests share one verdict.
	 */
	public static function balance_refusal( ?Voucher $voucher ): string {
		if ( ! $voucher ) {
			return 'not_found';
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return 'void';
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			return 'expired';
		}
		if ( Voucher::STATUS_RESERVED === $voucher->status ) {
			return 'reserved';
		}
		if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
			return 'scheduled';
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status ) {
			return 'redeemed';
		}
		if ( Voucher::STATUS_ACTIVE !== $voucher->status ) {
			return 'not_active';
		}
		if ( ! $voucher->is_multi_use() ) {
			return 'not_multi_use';
		}
		return $voucher->balance <= 0 ? 'redeemed' : '';
	}

	/** Amount in minor units (cents), so comparisons never depend on float noise. */
	public static function minor_units( float $amount, int $decimals ): int {
		return (int) round( $amount * ( 10 ** max( 0, $decimals ) ) );
	}

	/**
	 * Atomically deduct stored value under a row lock. For order redemptions, the audit row also
	 * makes retries for the same order idempotent.
	 *
	 * By default a request above the balance deducts the whole balance (orders: the shortfall is flagged by
	 * the caller). With $allow_partial = false (counter/scanner) such a request is refused and nothing is
	 * deducted. $reason receives why nothing was deducted ('' on success): a balance_refusal() code, or
	 * 'exceeds_balance', 'invalid_amount', 'no_transactions', 'db_error'.
	 *
	 * @return array{amount:float,balance:float,duplicate:bool}|null
	 */
	public function deduct_balance_atomic( Voucher $voucher, float $requested, string $note = '', int $order_id = 0, bool $allow_partial = true, ?string &$reason = null ): ?array {
		global $wpdb;
		$reason = '';
		$table  = Install::tables()['vouchers'];
		$events = Install::tables()['events'];
		if ( ! Install::tables_support_transactions() ) {
			$reason = 'no_transactions';
			return null;
		}
		if ( ! Db::begin() ) {
			$reason = 'db_error';
			return null;
		}
		$reason = 'db_error';
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				$reason = 'not_found';
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
					if ( 'reserved' === $prior['event'] ) {
						// The balance read under the lock now, not the one after the reservation (other bookings
						// may have changed it since): the journal and the liability read balance_after.
						$locked_voucher         = $this->repo->get( $voucher->id );
						$prior['balance_after'] = $locked_voucher ? (float) $locked_voucher->balance : (float) $prior['balance_after'];
						if ( ! $this->repo->log( $voucher->id, 'committed', (float) $prior['amount'], (float) $prior['balance_after'], $note, $order_id ) ) {
							Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
							return null;
						}
					}
					if ( ! Db::commit() ) {
						Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
						return null;
					}
					$reason = '';
					return array(
						'amount'    => (float) $prior['amount'],
						'balance'   => (float) $prior['balance_after'],
						'duplicate' => true,
					);
				}
			}

			$fresh   = $this->repo->get( $voucher->id );
			$refusal = self::balance_refusal( $fresh );
			// A used-up balance stays chargeable with 0 for orders (the caller records the shortfall).
			if ( '' !== $refusal && ! ( 'redeemed' === $refusal && $allow_partial && Voucher::STATUS_ACTIVE === $fresh->status ) ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				$reason = $refusal;
				return null;
			}
			$decimals = $fresh->decimals();
			if ( ! $allow_partial ) {
				$wanted = is_finite( $requested ) ? self::minor_units( $requested, $decimals ) : 0;
				if ( $wanted <= 0 ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					$reason = 'invalid_amount';
					return null;
				}
				if ( $wanted > self::minor_units( $fresh->balance, $decimals ) ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					$reason = 'exceeds_balance';
					return null;
				}
			}

			$amount          = min( $fresh->balance, max( 0.0, round( $requested, $decimals ) ) );
			$fresh->balance  = max( 0.0, round( $fresh->balance - $amount, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( $amount > 0 || $order_id > 0 ) {
				if ( ! $this->repo->log( $fresh->id, 'redeemed', $amount, $fresh->balance, $note, $order_id ) ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}

		self::sync_coupon_amount( $fresh );
		$reason = '';
		if ( $amount > 0 ) {
			VoucherRepository::fire_redeemed( $fresh, $amount, $order_id > 0 ? 'shop' : 'counter', $order_id );
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
	 * Reject coupons whose voucher is void, not yet delivered or used up – and Voucher Captain coupons without a
	 * voucher (row deleted, data removed on uninstall, copied coupon): their amount, usage limit and balance are
	 * only right together with the voucher, so they are never redeemed as ordinary coupons.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		if ( 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			if ( '' !== (string) $coupon->get_meta( '_vcap_referral_reward_for' ) ) {
				$this->check_personal( $coupon, $discounts ); // V6: referral rewards belong to the referrer's account.
			}
			return $valid;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher ) {
			throw new \Exception( esc_html__( 'This voucher cannot be redeemed: its voucher record is missing. Please contact the shop.', 'voucher-captain' ), 9007 );
		}
		// External reservations past their lifetime are given back right here, not only by the background job
		// (which may not run: WP-Cron off, loopback blocked, full-page cache).
		if ( function_exists( 'vcap' ) && isset( vcap()->redemptions ) && vcap()->redemptions->release_expired_for( $voucher ) ) {
			$voucher = $this->repo->get( $voucher->id ) ?? $voucher;
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
		if ( $voucher->is_multi_use() && ! ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order ) && (float) $coupon->get_amount( 'edit' ) > (float) $voucher->balance + 0.000001 ) {
			// The cart never grants more than the stored balance, even if the coupon amount is out of date (edited in
			// WooCommerce, failed save): otherwise every checkout with it ends in a shortfall. Orders keep their
			// own amount (order_coupon_object()).
			$coupon->set_amount( $voucher->balance );
		}
		if ( ! Channels::allowed( $voucher, Channels::SHOP ) ) {
			/** Message for a voucher that is not redeemable in the shop (e.g. a ticket voucher: where to use it instead). */
			$message = (string) apply_filters( 'vcap_shop_channel_refused_message', __( 'This voucher cannot be redeemed in the shop.', 'voucher-captain' ), $voucher );
			throw new \Exception( esc_html( $message ), 9006 );
		}
		$this->check_personal( $coupon, $discounts );
		return $valid;
	}

	/**
	 * V6: a personal voucher (coupon restricted to email addresses) is redeemed only by a customer logged in with an
	 * account whose (verified) email matches – never by a guest who types the address at checkout (classic cart,
	 * Store API/blocks). Orders: the order's customer account counts, and staff editing an order in wp-admin or
	 * an order created there pass (they check the holder's identity, like at the counter). See PersonalVoucher.
	 *
	 * @throws \Exception With the "log in with …" message.
	 */
	private function check_personal( \WC_Coupon $coupon, $discounts ): void {
		$restrictions = PersonalVoucher::restrictions( $coupon );
		if ( ! $restrictions ) {
			return;
		}
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( $object instanceof \WC_Order ) {
			$customer = $object->get_customer_id() ? get_userdata( (int) $object->get_customer_id() ) : null;
			if ( PersonalVoucher::staff_context() || 'admin' === $object->get_created_via() || PersonalVoucher::allowed_for_user( $restrictions, $customer ? $customer : null ) ) {
				return;
			}
		}
		$user = is_user_logged_in() ? wp_get_current_user() : null;
		if ( PersonalVoucher::allowed_for_user( $restrictions, $user ) ) {
			return;
		}
		throw new \Exception( esc_html( PersonalVoucher::refusal( $restrictions, null !== $user ) ), 9008 );
	}

	/**
	 * Whether this coupon is a multi-purpose voucher (gift card, store credit, sold value voucher: VAT treatment
	 * "payment", fixed amount), i.e. a means of payment that an "Individual use only" code must not exclude.
	 * Single-purpose vouchers, percentages and promotions stay discounts. Filter vcap_voucher_is_means_of_payment.
	 */
	public function is_payment_means( $coupon ): bool {
		if ( ! $coupon instanceof \WC_Coupon || 'fixed_cart' !== $coupon->get_discount_type() || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return false;
		}
		$voucher = $this->repo->get_by_code( wc_format_coupon_code( $coupon->get_code() ) );
		$means   = $voucher && ! $voucher->is_ticket() && 'payment' === ProductConfig::tax_treatment( $voucher );
		return (bool) apply_filters( 'vcap_voucher_is_means_of_payment', $means, $coupon, $voucher );
	}

	/**
	 * An "Individual use only" code is applied: the multi-purpose vouchers already in the cart stay.
	 *
	 * @param string[]   $keep    Codes to keep.
	 * @param \WC_Coupon $coupon  The individual-use coupon being applied.
	 * @param string[]   $applied Codes in the cart.
	 */
	public function keep_payment_means( $keep, $coupon = null, $applied = array() ) {
		$keep = is_array( $keep ) ? $keep : array();
		foreach ( (array) $applied as $code ) {
			if ( ! in_array( $code, $keep, true ) && $this->is_payment_means( new \WC_Coupon( (string) $code ) ) ) {
				$keep[] = $code;
			}
		}
		return $keep;
	}

	/**
	 * A multi-purpose voucher may join a cart that holds an "Individual use only" code.
	 *
	 * @param bool       $allow  Whether the coupon may be applied.
	 * @param \WC_Coupon $coupon The coupon being applied.
	 */
	public function allow_payment_means( $allow, $coupon = null ) {
		return $allow || $this->is_payment_means( $coupon );
	}

	public function cart_balance_notice(): void {
		if ( ! WC()->cart ) {
			return;
		}
		$payments = function_exists( 'vcap' ) ? vcap()->payments->cart_payments() : array();
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$voucher = $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
			if ( $voucher && $voucher->is_multi_use() ) {
				// A note across the totals table, not a second amount column next to the order total (frontend.css).
				echo '<tr class="vcap-balance"><td colspan="2"><small>' . esc_html( self::remaining_balance_note( $voucher, self::remaining_after_cart( $voucher, WC()->cart, (string) $code, $payments ) ) ) . '</small></td></tr>';
			}
		}
	}

	/**
	 * What remains on a stored-balance voucher once this cart is paid: its balance minus what it pays here (the
	 * payment after tax, or its discount incl. the tax it removes, as used_amount() deducts it at checkout).
	 *
	 * @param array<string, float> $payments PaymentVouchers::cart_payments().
	 */
	public static function remaining_after_cart( Voucher $voucher, \WC_Cart $cart, string $code, array $payments ): float {
		$code = wc_format_coupon_code( $code );
		if ( isset( $payments[ $code ] ) ) {
			$used = (float) $payments[ $code ];
		} else {
			$with_tax = (bool) apply_filters( 'vcap_balance_deduct_tax', wc_prices_include_tax() || apply_filters( 'vcap_balance_covers_tax', true, null ), null );
			$used     = (float) $cart->get_coupon_discount_amount( $code, ! $with_tax );
		}
		$used = Compat\MultiCurrency::to_base( $used, get_woocommerce_currency() ) ?? $used;
		return max( 0.0, round( (float) $voucher->balance - $used, $voucher->decimals() ) );
	}

	/** "Remaining balance of voucher CODE after this order: 20.00" (plain text). */
	public static function remaining_balance_note( Voucher $voucher, float $remaining ): string {
		return sprintf(
			/* translators: 1: voucher code, 2: amount */
			__( 'Remaining balance of voucher %1$s after this order: %2$s', 'voucher-captain' ),
			$voucher->code,
			html_entity_decode( wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $voucher->currency ) ) ), ENT_QUOTES, 'UTF-8' )
		);
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
		$code = wc_format_coupon_code( (string) $code );
		if ( null !== PaymentVouchers::paid_amount( $order, $code ) ) {
			// Paid after tax by its fee line (single-use vouchers too): a recalculation must not turn it into a
			// discount as well.
			$coupon->set_amount( 0 );
			return $coupon;
		}
		$voucher = $this->find_voucher_for_code( $code );
		if ( ! $voucher || ! $voucher->is_multi_use() ) {
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
	 * gross price), so right before WooCommerce distributes the coupon its amount is converted to net: the
	 * largest net amount whose saving (net + the VAT it removes, at each line's rate and on the prices after
	 * earlier coupons) is exactly the balance – GrossBalanceSplit replays WooCommerce's distribution for that
	 * (T8, T15). The customer saves exactly the balance, and exactly that is deducted (net + tax, see
	 * used_amount()). Filter "vcap_balance_covers_tax" (false = old behaviour).
	 *
	 * @param array             $items     Items the coupon applies to (WC_Discounts items).
	 * @param \WC_Coupon        $coupon    Coupon.
	 * @param \WC_Discounts|null $discounts The running discount calculation (WooCommerce 8.8+).
	 */
	public function prepare_gross_balance( $items, $coupon, $discounts = null ) {
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
		$taxed = array_filter(
			(array) $items,
			static fn( $item ) => is_object( $item ) && ( isset( $item->subtotal, $item->subtotal_tax ) || ( isset( $item->object ) && $item->object instanceof \WC_Order_Item_Product ) )
		);
		if ( ! $taxed ) {
			return $items; // Validation-only item lists (no tax data): WooCommerce does not distribute here.
		}
		$split = new GrossBalanceSplit( $coupon, $taxed, $discounts instanceof \WC_Discounts ? $discounts : null );
		if ( $split->has_tax() ) {
			$factor                     = 10 ** wc_get_price_decimals();
			$gross                      = (int) round( (float) $coupon->get_amount( 'edit' ) * $factor );
			$this->net_amounts[ $code ] = $split->net_for( $gross ) / $factor;
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
				if ( 'refunded' === $to ) {
					$this->return_single_use( $order );
				}
			}
		}
		if ( isset( $this->hold_after[ $order->get_id() ] ) ) {
			// A single-use voucher of this order was voided or redeemed elsewhere (check_single_use_conflicts()).
			unset( $this->hold_after[ $order->get_id() ] );
			if ( in_array( $to, array( 'processing', 'completed' ), true ) && self::has_shortfall( $order ) && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold', __( 'A voucher of this order was cancelled or redeemed elsewhere: manual review required before fulfilment.', 'voucher-captain' ) );
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
		if ( ! $shortfall && ! $missing ) {
			$this->write_off_rounding_rest( $order, $applied );
		}
	}

	/** Ledger event: a rest of at most one price unit left on a card by the net-price conversion, booked off (T15). */
	public const WRITTEN_OFF_EVENT = 'rounding_written_off';
	/** Order meta: code => rest booked off by write_off_rounding_rest(); given back with the charge on restore_order(). */
	public const WRITTEN_OFF_META = '_vcap_rounding_written_off';

	/**
	 * T15: in a tax-exclusive shop the net amount of a stored balance is chosen so that net + VAT is exactly the
	 * balance (GrossBalanceSplit). Where the tax rounding makes that impossible (net + 1 cent adds 2 cents gross),
	 * one minor unit stays on the card although the customer paid the rest of the order himself - a rest the card
	 * keeps showing in reports and reminder mails. Such a rest (at most one unit of the shop's price decimals, also
	 * sub-cent rests of older balances) is booked off with its own ledger event; cancelling or refunding the order
	 * gives it back together with the charge.
	 */
	private function write_off_rounding_rest( \WC_Order $order, array $applied ): void {
		if ( ! $applied || ! wc_tax_enabled() || wc_prices_include_tax() || (float) $order->get_total() <= 0 || ! apply_filters( 'vcap_balance_covers_tax', true, null ) ) {
			return; // Only where the conversion runs, and only when the cards did not cover the whole order.
		}
		$done    = self::order_map( $order, self::WRITTEN_OFF_META );
		$unit    = 1 / ( 10 ** wc_get_price_decimals() ) + 0.000001;
		$changed = false;
		foreach ( $applied as $code => $amount ) {
			$code = (string) $code;
			if ( isset( $done[ $code ] ) || (float) $amount <= 0 || null !== PaymentVouchers::paid_amount( $order, $code ) ) {
				continue;
			}
			$voucher = $this->find_voucher_for_code( $code );
			$fresh   = $voucher && $voucher->is_multi_use() ? $this->repo->get( $voucher->id ) : null;
			if ( ! $fresh || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->balance <= 0 || $fresh->balance > $unit || $this->open_holds( $fresh ) > 0 ) {
				continue;
			}
			$rest = (float) $fresh->balance;
			$note = sprintf(
				/* translators: %s: order number */
				__( 'Rounding rest of the net-price conversion in order #%s booked off', 'voucher-captain' ),
				$order->get_order_number()
			);
			// Logged without the order id: the order's own ledger rows (charge, restore) stay the latest per order.
			if ( $this->write_balance( $fresh, static fn( float $current ) => $current <= $unit ? 0.0 : $current, self::WRITTEN_OFF_EVENT, null, $note, 0 ) ) {
				$after = $this->repo->get( $fresh->id );
				if ( $after && $after->balance <= 0 ) {
					$done[ $code ] = $rest;
					$changed       = true;
				}
			}
		}
		if ( $changed ) {
			$order->update_meta_data( self::WRITTEN_OFF_META, $done );
			$order->save();
		}
	}

	/**
	 * @param float $amount  Amount to book back.
	 * @param float $charged Amount the order was charged (null: $amount); differs when part of it was booked back by
	 *                       hand already (event "returned", ignored when looking for the charge).
	 */
	private function restore_balance_atomic( Voucher $voucher, float $amount, int $order_id, string $note, ?float $charged = null ): bool {
		global $wpdb;
		$table   = Install::tables()['vouchers'];
		$events  = Install::tables()['events'];
		$charged = $charged ?? $amount;
		if ( $amount <= 0 && $charged > 0 && $order_id > 0 ) {
			return true; // Booked back completely by hand already.
		}
		if ( $amount <= 0 || $order_id <= 0 || ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event, amount FROM {$events} WHERE voucher_id = %d AND order_id = %d AND event NOT IN (%s, %s) ORDER BY id DESC LIMIT 1",
					$voucher->id,
					$order_id,
					self::RETURNED_EVENT,
					TopUp::EXTENDED_EVENT
				),
				ARRAY_A
			); // phpcs:ignore
			if ( $latest && 'restored' === $latest['event'] ) {
				Db::finish();
				return true;
			}
			if ( ! $latest || ! in_array( $latest['event'], array( 'committed', 'redeemed' ), true ) || abs( (float) $latest['amount'] - $charged ) >= 0.0001 ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, 'restored', $amount, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
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
		// Parts already booked back by hand (partial return, OrderReturn): only the rest goes back now.
		$returned = self::order_map( $order, self::RETURNED_META );
		// A rounding rest booked off after the charge (T15) goes back with it.
		$rests    = self::order_map( $order, self::WRITTEN_OFF_META );
		$restored = 0;
		foreach ( $applied as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			$back    = (float) $amount - (float) ( $returned[ $code ] ?? 0 ) + (float) ( $rests[ $code ] ?? 0 );
			if ( $voucher && $this->restore_balance_atomic(
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
			$order->delete_meta_data( self::WRITTEN_OFF_META );
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
		$days = $void ? 0 : max( 0, (int) apply_filters( 'vcap_restore_extension_days', 0, $card, $order ) );
		/* translators: %s: order number */
		if ( $days > 0 && TopUp::extend_validity( $repo, $card, gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ), sprintf( __( 'Balance of order #%s booked back', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
			/* translators: 1: voucher code, 2: expiry date */
			$order->add_order_note( sprintf( __( 'Voucher %1$s had expired: its validity was extended to %2$s so the booked-back amount can be used.', 'voucher-captain' ), Downloads::masked_code( (string) $card->code ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card->expires_at . ' UTC' ) ) ) );
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
			$card->code
		);
		$order->add_order_note( $text );
		Attention::add( $order->get_id(), 'restored:' . $card->id, $text );
	}

	/**
	 * Fully refunded order: WooCommerce gives coupon uses back only for cancelled orders, so single-use vouchers
	 * of the order are given back here (once per code): usage count lowered, voucher active again
	 * (usage_decreased()).
	 */
	private function return_single_use( \WC_Order $order ): void {
		$data_store = $order->get_data_store();
		if ( method_exists( $data_store, 'get_recorded_coupon_usage_counts' ) && ! $data_store->get_recorded_coupon_usage_counts( $order ) ) {
			return; // WooCommerce counted no use for this order (or gave it back already).
		}
		$done  = self::order_map( $order, '_vcap_single_use_returned' );
		$codes = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = isset( $done[ $code ] ) ? null : $this->find_voucher_for_code( $code );
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
			$order->update_meta_data( '_vcap_single_use_returned', $done );
			$order->save_meta_data();
			/* translators: %s: voucher codes */
			$order->add_order_note( sprintf( __( 'Order refunded: single-use vouchers %s can be used again.', 'voucher-captain' ), implode( ', ', array_map( array( Downloads::class, 'masked_code' ), $codes ) ) ) );
		}
	}

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
				$order->add_order_note( sprintf( __( 'Order cancelled: single-use vouchers %s stay redeemed ("Restore balance on cancellation" is off).', 'voucher-captain' ), implode( ', ', array_map( array( Downloads::class, 'masked_code' ), array_map( 'strval', wp_list_pluck( $this->given_back, 'code' ) ) ) ) ) );
			} else {
				foreach ( $this->given_back as $voucher ) {
					self::check_restored_card( $this->repo, $order, $voucher, (float) $voucher->initial_amount );
				}
			}
		}
		$this->cancelling      = 0;
		$this->keep_single_use = false;
		$this->given_back      = array();
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
		$voucher   = $coupon_id ? $this->repo->get_by_coupon( (int) $coupon_id ) : $this->repo->get_by_code( $code );
		// V10: an order paid with a code that was reissued since still charges/books back the same voucher.
		return $voucher ?? Reissue::voucher_of_previous_code( $this->repo, $code );
	}

	/**
	 * Single-use vouchers: mark redeemed once WooCommerce's usage limit is reached.
	 */
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
			$voucher = $this->find_voucher_for_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
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
		$missing = self::order_map( $order, self::SHORTFALL_AMOUNTS_META );
		if ( isset( $missing[ $code ] ) ) {
			return; // Flagged on an earlier status change.
		}
		$value            = (float) $item->get_discount() + ( wc_prices_include_tax() ? (float) $item->get_discount_tax() : 0.0 );
		$missing[ $code ] = round( $value, API\V1\RedemptionService::decimals( (string) $order->get_currency() ) );
		$order->update_meta_data( self::SHORTFALL_AMOUNTS_META, $missing );
		$order->update_meta_data( self::SHORTFALL_META, 'yes' );
		$order->save();
		$this->hold_after[ $order->get_id() ] = true;
		$this->double_flagged[ $voucher->id ] = true;
		/* translators: %s: order number */
		$this->repo->log( $voucher->id, 'double_use', $voucher->initial_amount, null, sprintf( __( 'Order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() );
		$order->add_order_note(
			sprintf(
				/* translators: 1: voucher code, 2: discount amount */
				__( 'Voucher %1$s was cancelled or redeemed elsewhere before this order was paid, but the order still has its discount of %2$s. The order is held: collect the difference or cancel the order before fulfilment.', 'voucher-captain' ),
				$voucher->code,
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
				$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, (string) $used_by, $order_id );
				VoucherRepository::fire_redeemed( $voucher, (float) $voucher->initial_amount, 'shop', $order_id );
			}
		} elseif ( $limit > 0 && (int) $new_count >= $limit && Voucher::STATUS_ACTIVE !== $voucher->status && ! isset( $this->double_flagged[ $voucher->id ] ) ) {
			// Used in the shop although it was redeemed, reserved or voided elsewhere meanwhile: make it visible
			// (orders paid through a status change were flagged and held already, check_single_use_conflicts()).
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
		if ( $this->cancelling && $this->keep_single_use && Voucher::STATUS_REDEEMED === $voucher->status ) {
			$this->given_back[] = $voucher; // Cancelled while "Restore balance on cancellation" is off: stays redeemed.
			return;
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status && ( 0 === $limit || (int) $new_count < $limit ) ) {
			// An expired voucher stays expired (check_restored_card() tells the shop when an order gave it back).
			$voucher->status  = $voucher->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			$voucher->balance = $voucher->initial_amount;
			$this->repo->update( $voucher );
			$this->repo->log( $voucher->id, 'restored', $voucher->initial_amount, $voucher->balance, (string) $used_by );
			if ( $this->cancelling ) {
				$this->given_back[] = $voucher;
			}
		}
	}
}
