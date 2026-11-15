<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Turns purchased voucher products into vouchers + WooCommerce coupons.
 *
 * Idempotent: every voucher occupies a unique (order_item_id, item_index) slot, so re-running for the same
 * order (status flapping, manual re-issue, concurrent webhooks) never creates duplicates.
 */
final class Issuer {
	public function __construct(
		private Settings $settings,
		private VoucherRepository $repo,
		private CouponManager $coupons,
		private CodePool $pool,
		private Delivery $delivery
	) {}

	public function register(): void {
		$statuses = array_map( 'sanitize_key', (array) $this->settings->get( 'vcap_issue_statuses' ) );
		foreach ( $statuses as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_status' ), 20, 3 );
		}
		// Offline payments (cash on delivery, bank transfer, cheque) are confirmed later: when the payment is
		// recorded or the order is completed. Both run idempotently (one voucher per item slot).
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'on_pre_payment_complete' ), 5 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20 );
		// payment_complete() on an order that is already "Processing" (cash on delivery) fires only this hook.
		add_action( 'woocommerce_payment_complete_order_status_processing', array( $this, 'on_payment_complete' ), 20 );
		if ( $statuses && ! in_array( 'completed', $statuses, true ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'on_completed' ), 20, 2 );
		}
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_purchase_cancelled' ), 30, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_purchase_cancelled' ), 30, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'guard_refund' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'on_partial_refund' ), 20, 2 );
		add_action( self::ISSUE_RETRY_HOOK, array( $this, 'issue_async' ) );
		add_action( self::VOID_RETRY_HOOK, array( $this, 'retry_void' ), 10, 2 );
		// Vouchers that waited for the payment (e.g. "On hold" as the only issue status, bank transfer): issued
		// when the order reaches a paid status later, also by hand – once per item slot.
		foreach ( array_diff( self::paid_statuses(), $statuses ) as $status ) {
			if ( 'completed' !== $status ) {
				add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_paid_status' ), 20, 3 );
			}
		}
		add_action( 'woocommerce_order_status_changed', array( $this, 'hold_after_transition' ), 30, 4 );
	}

	/** Order meta: the order waits for an exchange rate before its vouchers can be issued (note written once). */
	public const FX_HOLD_META = '_vcap_fx_hold';
	/** Order item meta: issuing was refused because the product's discount setup is invalid (note written once). */
	public const CONFIG_REFUSED_META = '_vcap_config_refused';
	/** Ledger event: the unspent purchased value was taken off a gift card whose purchase was refunded. */
	public const PURCHASE_REFUNDED_EVENT = 'purchase_refunded';

	/** Code of the history note of a voucher voided because its purchase was refunded or cancelled (AP-S2-2). */
	public const REFUND_VOID_NOTE = 'voided_refund';
	/** Action Scheduler hook: retries voiding a voucher whose purchase was refunded or cancelled. */
	public const VOID_RETRY_HOOK = 'vcap_retry_void';
	/** Attempts of the void retry (15 minutes apart, then an hour each). */
	private const VOID_RETRIES = 5;
	/** Action Scheduler hook: issues the vouchers of an order again after a failed attempt (args: order_id). */
	public const ISSUE_RETRY_HOOK = 'vcap_issue_order';
	/** Order meta: failed issuing attempts in a row (array{attempts:int, time:int, reference:string}). */
	public const ISSUE_FAILED_META = '_vcap_issue_failed';
	/** Automatic issuing retries after a failure (15 minutes, doubled each time, at most 6 hours apart). */
	public const ISSUE_RETRIES = 8;
	/** Vouchers that could not be created in the last issue_for_order() run (database or coupon error). */
	public int $last_failures = 0;

	/** Whether issuing runs inside a status transition (status changes wait until it is through). */
	private bool $in_transition = false;
	/** @var array<int, true> Orders to put on hold after their status transition (no exchange rate). */
	private array $fx_hold = array();

	/**
	 * @param int|string     $order_id   Order id.
	 * @param \WC_Order|null $order      Order.
	 * @param array          $transition Status transition (WooCommerce: from, to, note, manual).
	 */
	public function on_status( $order_id, $order = null, $transition = array() ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Payment being recorded right now, or the shop set the order to a paid status by hand.
		$how = self::$confirmed[ $order->get_id() ] ?? ( self::manual_paid_transition( $transition ) ? 'manual' : '' );
		if ( '' !== $how && self::offline_payment( $order ) ) {
			self::confirm_payment( $order, $how );
		}
		if ( ! self::payment_ok( $order ) ) {
			$this->await_payment( $order );
			return;
		}
		if ( ! apply_filters( 'vcap_should_issue_for_order', true, $order ) ) {
			return;
		}
		$this->in_transition = true;
		try {
			$this->issue_for_order( $order );
		} finally {
			$this->in_transition = false;
		}
	}

	/** After the status transition: an order whose vouchers wait for an exchange rate goes on hold. */
	public function hold_after_transition( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! isset( $this->fx_hold[ (int) $order_id ] ) ) {
			return;
		}
		unset( $this->fx_hold[ (int) $order_id ] );
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded', 'failed' ) ) ) {
			$order->update_status( 'on-hold', __( 'Vouchers wait for an exchange rate to the shop currency.', 'voucher-captain' ) );
		}
	}

	/**
	 * Whether a voucher line of the order still to be issued (or a top-up still to be booked) depends on the
	 * order currency: its value is the price paid or the amount entered, not a fixed value or a percentage.
	 */
	private function needs_conversion( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( '' !== (string) $item->get_meta( TopUp::ITEM_META ) ) {
				if ( ! $item->get_meta( TopUp::DONE_META ) ) {
					return true;
				}
				continue;
			}
			if ( ! $product || ! ProductConfig::is_voucher( $product ) || $this->repo->get_slot( (int) $item_id, 0 ) ) {
				continue;
			}
			$config = ProductConfig::get( $product );
			if ( 'percent' === $config['discount_type'] || 'fixed' === $config['amount_source'] || 'ticket' === $config['type'] ) {
				continue; // Fixed value or percentage (tickets without fixed value carry none).
			}
			return true;
		}
		return false;
	}

	/**
	 * Foreign-currency order without exchange rate: nothing is issued (never 1:1), the order goes on hold with a
	 * note and is listed in an admin notice and in Site Health until a rate is known.
	 */
	private function hold_for_rate( \WC_Order $order ): void {
		$currency = (string) $order->get_currency();
		Compat\MultiCurrency::record_unknown( $currency );
		Compat\MultiCurrency::record_held( $order );
		if ( 'yes' !== $order->get_meta( self::FX_HOLD_META ) ) {
			$order->update_meta_data( self::FX_HOLD_META, 'yes' );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: 1: order currency, 2: shop base currency, 3: order meta key */
					__( 'Vouchers not issued: the order was paid in %1$s and no exchange rate to the shop currency %2$s is known (vouchers are never converted 1:1). Set up a supported currency switcher or store the rate on the order (meta "%3$s": %1$s per 1 %2$s), then issue the vouchers from this order screen – or refund the order.', 'voucher-captain' ),
					$currency,
					(string) get_option( 'woocommerce_currency' ),
					Compat\MultiCurrency::RATE_META
				)
			);
		}
		if ( $this->in_transition ) {
			$this->fx_hold[ $order->get_id() ] = true;
		} elseif ( ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded', 'failed' ) ) ) {
			$order->update_status( 'on-hold', __( 'Vouchers wait for an exchange rate to the shop currency.', 'voucher-captain' ) );
		}
	}

	/** @param int|array $order_id Order id (or the action's args array). */
	public function issue_async( $order_id ): void {
		$order_id = is_array( $order_id ) ? (int) ( $order_id['order_id'] ?? 0 ) : (int) $order_id;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( is_array( $order->get_meta( self::ASYNC_META ) ) && $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			// Cancelled while its vouchers were being issued in the background: no further batches.
			$order->delete_meta_data( self::ASYNC_META );
			$order->save_meta_data();
			$order->add_order_note( __( 'Issuing vouchers in the background stopped: the order is no longer paid.', 'voucher-captain' ) );
			return;
		}
		if ( self::payment_ok( $order ) ) {
			$this->in_batch = true;
			try {
				$this->issue_for_order( $order );
			} finally {
				$this->in_batch = false;
			}
		}
	}

	/** Order meta while the vouchers of an order are issued in the background (array{total:int, started:string}). */
	public const ASYNC_META = '_vcap_issue_async';
	/** More vouchers to issue for one order than this: issued in Action Scheduler batches (filter "vcap_async_issue_threshold"). */
	public const ASYNC_THRESHOLD = 20;
	/** Vouchers issued per background batch (filter "vcap_issue_batch_size"). */
	public const BATCH_SIZE = 25;
	/** Whether the last issue_for_order() run handed the work to the background instead of issuing. */
	public bool $last_deferred = false;
	/** Whether issuing runs as a background batch (issue_async()). */
	private bool $in_batch = false;

	/** Vouchers above which an order is issued in the background. */
	public static function async_threshold( \WC_Order $order ): int {
		/**
		 * Vouchers still to be issued for an order above which they are created in Action Scheduler batches, so the
		 * payment request (gateway webhook, checkout) returns quickly (default 20). The order shows a note and its
		 * vouchers are delivered once all of them exist. A very high value issues everything right away.
		 */
		return max( 1, (int) apply_filters( 'vcap_async_issue_threshold', self::ASYNC_THRESHOLD, $order ) );
	}

	/** Vouchers created per background batch. */
	public static function batch_size( \WC_Order $order ): int {
		/** Vouchers created per Action Scheduler batch when an order is issued in the background (default 25). */
		return max( 1, (int) apply_filters( 'vcap_issue_batch_size', self::BATCH_SIZE, $order ) );
	}

	/**
	 * Plans the next issuing action of the order (the same hook and arguments as the retries: one pending action per
	 * order at a time, replaced by every plan).
	 */
	private function schedule_batch( \WC_Order $order, int $delay = 0 ): void {
		$args = array( 'order_id' => $order->get_id() );
		as_unschedule_all_actions( self::ISSUE_RETRY_HOOK, $args, Delivery::GROUP );
		as_schedule_single_action( time() + $delay, self::ISSUE_RETRY_HOOK, $args, Delivery::GROUP );
	}

	/**
	 * Every voucher of a background run exists: the vouchers it created (since it started) are delivered together,
	 * vcap_vouchers_issued fires once for all of them.
	 *
	 * @param array{total:int, started:string} $state
	 */
	private function finish_background( \WC_Order $order, array $state ): void {
		$order->delete_meta_data( self::ASYNC_META );
		$order->save_meta_data();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ISSUE_RETRY_HOOK, array( 'order_id' => $order->get_id() ), Delivery::GROUP );
		}
		$started = (string) ( $state['started'] ?? '' );
		$issued  = array_values(
			array_filter(
				$this->repo->for_order( $order->get_id() ),
				static fn( Voucher $v ) => (string) $v->created_at >= $started && ! $v->delivered_at && in_array( $v->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_SCHEDULED ), true )
			)
		);
		/* translators: %d: number of vouchers */
		$order->add_order_note( sprintf( __( 'All vouchers of this order have been issued (%d in the background); delivery starts now.', 'voucher-captain' ), count( $issued ) ) );
		Logger::debug( 'Background issuing finished.', array( 'order_id' => $order->get_id(), 'vouchers' => count( $issued ) ) );
		if ( $issued ) {
			foreach ( $issued as $voucher ) {
				$this->delivery->schedule( $voucher );
			}
			/** Vouchers of an order were issued (args: Voucher[] $created, WC_Order $order). */
			do_action( 'vcap_vouchers_issued', $issued, $order );
		}
	}

	/** Pause before the next issuing attempt after $attempts failed ones (filter "vcap_issue_retry_delay"). */
	public static function issue_retry_delay( int $attempts ): int {
		$delay = min( 6 * HOUR_IN_SECONDS, 15 * MINUTE_IN_SECONDS * ( 2 ** max( 0, min( 10, $attempts - 1 ) ) ) );
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'vcap_issue_retry_delay', $delay, $attempts ) );
	}

	/**
	 * After a run in which vouchers could not be created (D3): remembers the failure on the order, lists the order
	 * for the shop (Attention: "paid order without voucher") and plans the next attempt with a growing pause – the
	 * order note promises it. After ISSUE_RETRIES attempts only the listing stays.
	 */
	private function issue_failed( \WC_Order $order, int $failed ): void {
		$state    = $order->get_meta( self::ISSUE_FAILED_META );
		$attempts = ( is_array( $state ) ? (int) ( $state['attempts'] ?? 0 ) : 0 ) + 1;
		$order->update_meta_data( self::ISSUE_FAILED_META, array( 'attempts' => $attempts, 'time' => time(), 'reference' => Db::last_reference() ) );
		$order->save_meta_data();
		Attention::add(
			$order->get_id(),
			'issue',
			/* translators: %d: number of vouchers */
			sprintf( _n( 'Paid order without voucher: %d voucher could not be created. It is retried automatically; or use "Generate vouchers" on the order.', 'Paid order without voucher: %d vouchers could not be created. They are retried automatically; or use "Generate vouchers" on the order.', $failed, 'voucher-captain' ), $failed )
		);
		Logger::error( 'Vouchers could not be issued.', array( 'order_id' => $order->get_id(), 'failed' => $failed, 'attempt' => $attempts, 'reference' => Db::last_reference() ) );
		$args = array( 'order_id' => $order->get_id() );
		if ( $attempts > self::ISSUE_RETRIES || ! function_exists( 'as_schedule_single_action' ) ) {
			// Given up (AP-Q2-1): the safety net a background batch planned before it ran goes as well, otherwise
			// it retried every 10 minutes for ever. The listing and "Generate vouchers" stay.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::ISSUE_RETRY_HOOK, $args, Delivery::GROUP );
			}
			return;
		}
		as_unschedule_all_actions( self::ISSUE_RETRY_HOOK, $args, Delivery::GROUP );
		as_schedule_single_action( time() + self::issue_retry_delay( $attempts ), self::ISSUE_RETRY_HOOK, $args, Delivery::GROUP );
	}

	/** All vouchers of the order exist now: the failure state and the listing are cleared. */
	private function issue_recovered( \WC_Order $order ): void {
		if ( '' === $order->get_meta( self::ISSUE_FAILED_META ) && ! Attention::has( $order->get_id(), 'issue' ) ) {
			return;
		}
		$order->delete_meta_data( self::ISSUE_FAILED_META );
		$order->save_meta_data();
		Attention::resolve( $order->get_id(), 'issue' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ISSUE_RETRY_HOOK, array( 'order_id' => $order->get_id() ), Delivery::GROUP );
		}
	}

	/** payment_complete() starts: the status hooks it fires already count the order as paid. */
	public function on_pre_payment_complete( $order_id ): void {
		self::$confirmed[ (int) $order_id ] = 'payment_complete';
	}

	/** WooCommerce recorded the payment (also for an offline payment method, e.g. by a bank import). */
	public function on_payment_complete( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( self::offline_payment( $order ) ) {
			self::confirm_payment( $order, 'payment_complete' );
		}
		$this->issue_when_confirmed( $order );
	}

	/** Completed while "Completed" is not an issue status: issues what waited for an offline payment. */
	public function on_completed( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->in_transition = true; // A status hook: an exchange-rate hold waits until the transition is through.
			try {
				$this->issue_when_confirmed( $order );
			} finally {
				$this->in_transition = false;
			}
		}
	}

	/**
	 * Paid status (e.g. "Processing") that is not an issue status: issues what waited for the payment – an order
	 * that reached an unpaid issue status first (e.g. only "On hold" selected, bank transfer) and is paid now.
	 *
	 * @param array $transition Status transition (WooCommerce: from, to, note, manual).
	 */
	public function on_paid_status( $order_id, $order = null, $transition = array() ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' === (string) $order->get_meta( self::AWAITING_PAYMENT ) ) {
			// Orders from before the payment was remembered: coming straight from an (unpaid) issue status.
			$statuses = array_map( 'sanitize_key', (array) $this->settings->get( 'vcap_issue_statuses' ) );
			if ( ! is_array( $transition ) || ! in_array( (string) ( $transition['from'] ?? '' ), $statuses, true ) || ! $this->has_voucher_items( $order ) ) {
				return;
			}
			$order->update_meta_data( self::AWAITING_PAYMENT, (string) time() );
			$order->save_meta_data();
		}
		if ( self::offline_payment( $order ) && ( isset( self::$confirmed[ $order->get_id() ] ) || self::manual_paid_transition( $transition ) ) ) {
			self::confirm_payment( $order, self::$confirmed[ $order->get_id() ] ?? 'manual' );
		}
		$this->in_transition = true;
		try {
			$this->issue_when_confirmed( $order );
		} finally {
			$this->in_transition = false;
		}
	}

	/** Paid order statuses (WooCommerce default: processing, completed). */
	private static function paid_statuses(): array {
		return function_exists( 'wc_get_is_paid_statuses' ) ? array_map( 'sanitize_key', (array) wc_get_is_paid_statuses() ) : array( 'processing', 'completed' );
	}

	/** Issues when automatic issuing applies to the order: it has an issue status or waited for its payment. */
	private function issue_when_confirmed( \WC_Order $order ): void {
		$statuses = array_map( 'sanitize_key', (array) $this->settings->get( 'vcap_issue_statuses' ) );
		if ( ! $statuses || ( ! $order->has_status( $statuses ) && '' === (string) $order->get_meta( self::AWAITING_PAYMENT ) ) ) {
			return;
		}
		if ( ! self::payment_ok( $order ) || ! apply_filters( 'vcap_should_issue_for_order', true, $order ) ) {
			return;
		}
		$this->issue_for_order( $order );
	}

	/** Order meta: how an offline payment was confirmed ("manual", "payment_complete"). */
	public const PAYMENT_CONFIRMED = '_vcap_payment_confirmed';
	/** Order meta: the order reached an issue status, its vouchers wait for the offline payment. */
	public const AWAITING_PAYMENT = '_vcap_awaiting_payment';

	/** @var array<int, string> Offline payments confirmed in this request (order id => how). */
	private static array $confirmed = array();

	/**
	 * Payment methods that set an order to "Processing" (or let the shop do it) before any money arrived: cash
	 * on delivery, bank transfer, cheque. Filter "vcap_offline_payment_methods" (gateway ids) adds e.g. invoice or
	 * pay-at-pickup gateways.
	 */
	public static function offline_payment( \WC_Order $order ): bool {
		$methods = array_map( 'strval', (array) apply_filters( 'vcap_offline_payment_methods', array( 'cod', 'bacs', 'cheque' ), $order ) );
		return '' !== $order->get_payment_method() && in_array( $order->get_payment_method(), $methods, true );
	}

	/** Whether a status transition was made by hand (order screen, bulk action, REST) to a paid status. */
	public static function manual_paid_transition( $transition ): bool {
		return is_array( $transition ) && ! empty( $transition['manual'] ) && in_array( (string) ( $transition['to'] ?? '' ), wc_get_is_paid_statuses(), true );
	}

	public static function confirm_payment( \WC_Order $order, string $how ): void {
		self::$confirmed[ $order->get_id() ] = $how;
		if ( '' === (string) $order->get_meta( self::PAYMENT_CONFIRMED ) ) {
			$order->update_meta_data( self::PAYMENT_CONFIRMED, $how );
			$order->save_meta_data();
		}
	}

	/**
	 * Remembers (once, with an order note) that the vouchers of an order wait for its payment: an offline payment
	 * method, or an unpaid issue status such as "On hold". They are issued when the payment is recorded or the
	 * order reaches a paid status (on_payment_complete(), on_paid_status(), on_completed()).
	 */
	private function await_payment( \WC_Order $order ): void {
		if ( '' !== (string) $order->get_meta( self::AWAITING_PAYMENT ) || ! $this->has_voucher_items( $order ) ) {
			return;
		}
		$order->update_meta_data( self::AWAITING_PAYMENT, (string) time() );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: %s: payment method title, e.g. "Cash on delivery" */
				__( 'Vouchers are not issued yet: the payment (%s) has not been received. Once the money has arrived, mark the order as completed (or set a paid status by hand) and the vouchers are issued – or issue them now with the order action "Generate vouchers".', 'voucher-captain' ),
				$order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method()
			)
		);
	}

	private function has_voucher_items( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product && ProductConfig::is_voucher( $product ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Automatic issuing waits for the payment: an order counts as paid when its status is a paid status
	 * (processing, completed, or one a plugin adds via "woocommerce_order_is_paid_statuses") or WooCommerce
	 * recorded a payment date. So an issue status such as "On hold" (bank transfer) never sends vouchers before
	 * the money arrived. Filter "vcap_issue_requires_payment" (default true; false = issue on the status alone).
	 *
	 * Offline payment methods (see offline_payment()) are different: cash on delivery sets "Processing" at
	 * checkout and WooCommerce then records a payment date as well, although nothing was paid. Their orders count
	 * as paid only when completed, when WooCommerce recorded the payment ("woocommerce_payment_complete"), or when
	 * the shop set a paid status by hand ($transition, or remembered in the order meta). Bonus vouchers follow
	 * the same rule.
	 *
	 * @param array $transition Status transition of the current hook (WooCommerce: from, to, note, manual).
	 */
	public static function payment_ok( \WC_Order $order, $transition = array() ): bool {
		if ( ! apply_filters( 'vcap_issue_requires_payment', true, $order ) ) {
			return true;
		}
		if ( self::offline_payment( $order ) ) {
			return $order->has_status( 'completed' ) || self::manual_paid_transition( $transition ) || isset( self::$confirmed[ $order->get_id() ] ) || '' !== (string) $order->get_meta( self::PAYMENT_CONFIRMED );
		}
		return $order->is_paid() || null !== $order->get_date_paid();
	}

	/** Whether cash on delivery is offered for virtual products (vouchers would wait for the delivery). */
	public static function cod_for_virtual(): bool {
		$cod = get_option( 'woocommerce_cod_settings', array() );
		return is_array( $cod ) && 'yes' === ( $cod['enabled'] ?? 'no' ) && 'no' !== ( $cod['enable_for_virtual'] ?? 'yes' );
	}

	/**
	 * Creates the missing vouchers of an order (one per free item slot, so every run is idempotent).
	 *
	 * B4: more than async_threshold() missing vouchers are created in the background, batch_size() per Action
	 * Scheduler action (hook ISSUE_RETRY_HOOK, like the retries), so the payment request returns quickly. The run
	 * that hands them over adds an order note and returns no vouchers ($last_deferred); every batch notes the
	 * vouchers it created; the vouchers are delivered together when the last batch is through (finish_background()).
	 *
	 * @param bool $allow_async False: create everything in this request (e.g. WP-CLI), finishing a background run.
	 * @return Voucher[] newly created vouchers
	 */
	public function issue_for_order( \WC_Order $order, bool $manual = false, bool $allow_async = true ): array {
		$created             = array();
		$this->last_failures = 0;
		$this->last_deferred = false;
		if ( ! Compat\MultiCurrency::can_convert( (string) $order->get_currency(), $order ) && $this->needs_conversion( $order ) ) {
			$this->hold_for_rate( $order );
			return $created;
		}
		// Plan: the voucher lines and their free slots (one query per line).
		$lines   = array();
		$missing = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var \WC_Order_Item_Product $item */
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
				continue;
			}
			$config = ProductConfig::get( $product );
			$qty    = max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
			$count  = (int) apply_filters( 'vcap_vouchers_per_item', $qty * $config['per_item'], $item, $config );
			$errors = ProductConfig::discount_errors( $config );
			if ( $count > 0 && $errors && ! $this->repo->get_slot( (int) $item_id, $count - 1 ) ) {
				// Invalid discount setup (e.g. a percentage from the price): never insert a voucher for it.
				$this->refuse_line( $order, $item, $errors );
				continue;
			}
			if ( $count <= 0 ) {
				continue;
			}
			$this->record_line( $order, $item, $config, $count );
			$taken = $this->repo->taken_slots( (int) $item_id );
			$free  = array_values( array_diff( range( 0, $count - 1 ), array_keys( $taken ) ) );
			if ( $free ) {
				$lines[]  = array( $item, $product, $config, $free );
				$missing += count( $free );
			}
		}

		$state = $order->get_meta( self::ASYNC_META );
		$state = is_array( $state ) ? $state : null;
		$async = $allow_async && function_exists( 'as_schedule_single_action' );
		if ( ! $state && $async && $missing > self::async_threshold( $order ) ) {
			$state = array(
				'total'   => $missing,
				'started' => $this->repo->now(),
			);
			$order->update_meta_data( self::ASYNC_META, $state );
			$order->save_meta_data();
			$order->add_order_note(
				/* translators: %d: number of vouchers */
				sprintf( __( '%d vouchers are being issued in the background. They are delivered once all of them have been issued; this order shows a note when that is done.', 'voucher-captain' ), $missing )
			);
			Logger::debug( 'Issuing handed to the background.', array( 'order_id' => $order->get_id(), 'missing' => $missing ) );
		}
		if ( $state && $async && ! $this->in_batch ) {
			// A status hook, the payment callback or "Generate vouchers" while the batches run: they carry on.
			if ( ! as_has_scheduled_action( self::ISSUE_RETRY_HOOK, array( 'order_id' => $order->get_id() ), Delivery::GROUP ) ) {
				$this->schedule_batch( $order );
			}
			$this->last_deferred = true;
			return $created;
		}
		$limit = $state && $async ? self::batch_size( $order ) : PHP_INT_MAX;
		if ( $state && $async ) {
			// Safety net: should this batch be cut off (time limit, fatal error), the next one still runs.
			$this->schedule_batch( $order, 10 * MINUTE_IN_SECONDS );
		}

		$attempts = 0;
		$more     = false;
		foreach ( $lines as list( $item, $product, $config, $free ) ) {
			foreach ( $free as $index ) {
				if ( $attempts >= $limit ) {
					$more = true;
					break 2;
				}
				if ( $this->repo->get_slot( (int) $item->get_id(), $index ) ) {
					continue; // Taken by a concurrent request since the plan.
				}
				++$attempts;
				$voucher = $this->create_voucher( $order, $item, $product, $config, $index );
				if ( $voucher ) {
					$created[] = $voucher;
				} elseif ( ! $this->repo->get_slot( (int) $item->get_id(), $index ) ) {
					++$this->last_failures; // Not a concurrent request that took the slot: really missing.
				}
			}
		}
		Logger::debug( 'Issuing run.', array( 'order_id' => $order->get_id(), 'created' => count( $created ), 'failed' => $this->last_failures, 'manual' => $manual, 'more' => $more ) );
		if ( $this->last_failures ) {
			$this->issue_failed( $order, $this->last_failures ); // Plans the next attempt (also of a background run).
		} else {
			$this->issue_recovered( $order );
			if ( $more ) {
				$this->schedule_batch( $order ); // The next background batch, right away.
			}
		}

		if ( $created ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: count, 2: masked codes (last four characters) */
					_n( '%1$d voucher issued: %2$s', '%1$d vouchers issued: %2$s', count( $created ), 'voucher-captain' ),
					count( $created ),
					// Masked: order notes reach ERP/CRM/shipping connectors (REST) and every member of staff; the
					// full code is shown on the voucher's detail page only.
					implode( ', ', array_map( static fn( $v ) => Mask::code( (string) $v->code ), $created ) )
				) . ( $manual ? ' ' . __( '(manual)', 'voucher-captain' ) : '' )
			);
		}
		if ( $state ) {
			// Background run: delivery waits until every voucher of the order exists.
			if ( ! $more && ! $this->last_failures ) {
				$this->finish_background( $order, $state );
			}
			return $created;
		}
		if ( $created ) {
			foreach ( $created as $voucher ) {
				$this->delivery->schedule( $voucher );
			}
			/** Vouchers of an order were issued (args: Voucher[] $created, WC_Order $order). */
			do_action( 'vcap_vouchers_issued', $created, $order );
		}
		return $created;
	}

	/** Order note (once per line) for a voucher line that is not issued because of its product's discount setup. */
	private function refuse_line( \WC_Order $order, \WC_Order_Item_Product $item, array $errors ): void {
		if ( 'yes' === $item->get_meta( self::CONFIG_REFUSED_META ) ) {
			return;
		}
		$item->update_meta_data( self::CONFIG_REFUSED_META, 'yes' );
		$item->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: 1: product name, 2: reasons */
				__( 'No voucher issued for "%1$s": the product\'s discount setup is invalid (%2$s). Correct the product and issue the vouchers from this order screen, or refund the line.', 'voucher-captain' ),
				wp_strip_all_tags( $item->get_name() ),
				implode( ' ', $errors )
			)
		);
	}

	public const LINE_META = '_vcap_line';

	/**
	 * Snapshot of the voucher line at issue time: face value and currency of the vouchers, what was paid for the
	 * line (net, tax, after discounts) in the order currency, and the tax setup. Written once per line, so later
	 * product or tax changes never alter what was sold.
	 */
	private function record_line( \WC_Order $order, \WC_Order_Item_Product $item, array $config, int $count ): void {
		if ( is_array( $item->get_meta( self::LINE_META ) ) ) {
			return;
		}
		$product  = $item->get_product();
		$sale_tax = ProductConfig::line_tax( $order, $item );
		$core     = array(
			'version'            => 1,
			'voucher_type'       => (string) $config['type'],
			'discount_type'      => (string) $config['discount_type'],
			'amount_source'      => (string) $config['amount_source'],
			'face_value'         => $this->voucher_amount( $order, $item, $config ),
			// Base currency: switchers (WCML, Aelia, …) change get_woocommerce_currency() during the request.
			'voucher_currency'   => (string) get_option( 'woocommerce_currency' ),
			'vouchers'           => $count,
			'quantity'           => (int) $item->get_quantity(),
			'order_currency'     => $order->get_currency(),
			'subtotal'           => (float) $item->get_subtotal(),
			'subtotal_tax'       => (float) $item->get_subtotal_tax(),
			'total'              => (float) $item->get_total(),
			'total_tax'          => (float) $item->get_total_tax(),
			'tax_class'          => (string) $item->get_tax_class(),
			'tax_status'         => $product ? (string) $product->get_tax_status() : '',
			'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			// VAT of the sale (single-purpose vouchers: Pass Captain compares it with the goods it is redeemed for).
			'tax_rate_bp'        => $sale_tax['rate_bp'],
			'tax_country'        => $sale_tax['country'],
			// Kept as configured, even for channels whose plugin is not active right now (never widen access).
			'channels'           => array_values( array_filter( array_map( 'sanitize_key', (array) ( $config['channels'] ?? array() ) ) ) ),
			'recorded_at'        => time(),
		);
		/** Extra snapshot keys of connectors (e.g. the ticket a voucher is exchanged for); core keys always win. */
		$item->update_meta_data( self::LINE_META, $core + (array) apply_filters( 'vcap_line_snapshot', array(), $item, $config, $order ) );
		$item->save_meta_data();
	}

	/**
	 * The stored line snapshot (empty array for lines without vouchers).
	 */
	public static function line_data( \WC_Order_Item_Product $item ): array {
		$data = $item->get_meta( self::LINE_META );
		return is_array( $data ) ? $data : array();
	}

	private function voucher_amount( \WC_Order $order, \WC_Order_Item_Product $item, array $config ): float {
		if ( 'ticket' === $config['type'] && 'fixed' !== $config['amount_source'] ) {
			// Tickets are admission passes; they only carry a cash value when one is set explicitly.
			return 0.0;
		}
		$qty  = max( 1, (int) $item->get_quantity() );
		// Coupon amounts follow the shop's price mode: gross where prices include tax, net otherwise.
		$paid = (float) $item->get_subtotal() + ( wc_prices_include_tax() ? (float) $item->get_subtotal_tax() : 0.0 );
		switch ( $config['amount_source'] ) {
			case 'fixed':
				$amount = (float) wc_format_decimal( $config['amount'] );
				break;
			case 'custom':
				$custom = $item->get_meta( '_vcap_custom_amount' );
				$amount = '' !== (string) $custom ? (float) $custom : $paid / $qty;
				break;
			default:
				$amount = $paid / $qty / max( 1, (int) $config['per_item'] );
		}
		if ( 'fixed' !== $config['amount_source'] && self::balance_sold_with_vat( $item, $config ) ) {
			// T7: a stored balance sold WITH VAT (single-purpose, not a multi-purpose voucher) in a tax-exclusive
			// shop is worth what the customer paid, gross: 50 + 19 % VAT = 59.50, and as a stored balance it
			// pays 59.50 of the gross price (CouponManager::prepare_gross_balance()), not only 50.
			$amount *= ( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() ) / (float) $item->get_subtotal();
		}
		if ( 'percent' !== $config['discount_type'] && 'fixed' !== $config['amount_source'] ) {
			// Paid / entered in the order currency; coupons are stored in the shop base currency.
			$amount = (float) apply_filters( 'vcap_amount_to_base_currency', $amount, $order->get_currency(), $order );
		}
		// Vouchers are kept in the shop base currency, booked with its ISO 4217 decimals.
		return round( (float) apply_filters( 'vcap_voucher_amount', $amount, $item, $config, $order ), API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
	}

	/**
	 * T7: a stored-value voucher (balance) whose sale was taxed in a tax-exclusive shop and that is not classified as
	 * a multi-purpose voucher (VAT on redemption): its value is the gross amount paid. Multi-purpose vouchers keep
	 * the net value (they are sold without VAT when set up correctly, TAX.md 3). Single-use vouchers are not
	 * affected: their fixed amount reduces net prices and the VAT on top, i.e. they already pay net + VAT.
	 */
	public static function balance_sold_with_vat( \WC_Order_Item_Product $item, array $config ): bool {
		if ( wc_prices_include_tax() || 'percent' === ( $config['discount_type'] ?? '' ) || 'balance' !== ( $config['usage'] ?? '' ) ) {
			return false;
		}
		if ( (float) $item->get_subtotal() <= 0 || (float) $item->get_subtotal_tax() <= 0 ) {
			return false; // Not taxed at sale (multi-purpose set up as "not taxable", small business, exempt buyer).
		}
		$probe                = new Voucher();
		$probe->product_id    = (int) $item->get_product_id();
		$probe->variation_id  = (int) $item->get_variation_id();
		$probe->order_item_id = (int) $item->get_id();
		$probe->type          = (string) ( $config['type'] ?? 'voucher' );
		$probe->usage_mode    = 'balance';
		$probe->discount_type = 'fixed_cart';
		return 'payment' !== ProductConfig::tax_treatment( $probe );
	}

	private function create_voucher( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product, array $config, int $index ): ?Voucher {
		$voucher                = new Voucher();
		$voucher->type          = isset( Voucher::types()[ $config['type'] ] ) && 'credit' !== $config['type'] ? $config['type'] : 'voucher';
		$voucher->order_id      = $order->get_id();
		$voucher->order_item_id = (int) $item->get_id();
		$voucher->item_index    = $index;
		$voucher->product_id    = (int) $item->get_product_id();
		$voucher->variation_id  = (int) $item->get_variation_id();
		$voucher->template_id   = (int) $config['template'];
		$voucher->discount_type = $config['discount_type'];
		$voucher->usage_mode    = 'percent' === $config['discount_type'] && 'balance' === $config['usage'] ? 'single' : $config['usage'];
		if ( 'balance' === $voucher->usage_mode ) {
			$voucher->discount_type = 'fixed_cart';
		}
		$voucher->currency      = (string) get_option( 'woocommerce_currency' ); // Amounts are in base currency.
		$voucher->initial_amount = $this->voucher_amount( $order, $item, $config );
		$voucher->balance       = $voucher->initial_amount;
		// Statutory default validity by the buyer's country (consumer contract), not the shop's.
		$voucher->expires_at    = ProductConfig::expiry_for( $config, $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(), ProductConfig::country_of( $order ) );
		/** Expiry (UTC "Y-m-d H:i:s" or null) of a voucher being issued, e.g. no later than the event it is for. */
		$voucher->expires_at    = apply_filters( 'vcap_voucher_expires_at', $voucher->expires_at, $item, $config, $order );
		$voucher->buyer_name    = trim( $order->get_formatted_billing_full_name() ); // Name order of the shop language.
		$voucher->buyer_email   = (string) $order->get_billing_email();
		$voucher->max_checkins  = (int) $config['max_checkins'];
		$voucher->transferable  = 'no' !== $config['transferable'];
		// In the order language: the payment may be confirmed in another one (admin, gateway callback).
		$voucher->terms         = Compat\Multilingual::in_order_language( $order, static fn() => ProductConfig::terms( $config, ProductConfig::country_of( $order ) ) );

		$fields                   = is_array( $item->get_meta( '_vcap_fields' ) ) ? $item->get_meta( '_vcap_fields' ) : array();
		$voucher->recipient_name  = (string) ( $fields['recipient_name']['value'] ?? '' );
		$voucher->recipient_email = sanitize_email( (string) ( $fields['recipient_email']['value'] ?? '' ) );
		$voucher->message         = (string) ( $fields['message']['value'] ?? '' );
		$voucher->fields          = $fields;

		$voucher->send_to     = $voucher->recipient_email && 'buyer' !== $config['send_to'] ? $config['send_to'] : 'buyer';
		// V7: a new customer's first order above the hold value is delivered (and redeemable) after the hold.
		$voucher->delivery_at = Delivery::with_hold( Delivery::compute_time( $config, $fields, $order ), $order, true );
		$voucher->status      = $voucher->delivery_at && strtotime( $voucher->delivery_at . ' UTC' ) > time() ? Voucher::STATUS_SCHEDULED : Voucher::STATUS_ACTIVE;

		// Code: imported batch first, generated fallback.
		$code      = '';
		$from_pool = false;
		if ( '' !== (string) $config['code_batch'] ) {
			$code      = (string) $this->pool->claim( (string) $config['code_batch'] );
			$from_pool = '' !== $code;
			if ( ! $from_pool ) {
				$order->add_order_note(
					/* translators: %s: batch name */
					sprintf( __( 'Voucher code batch "%s" is empty, a random code was generated instead.', 'voucher-captain' ), $config['code_batch'] )
				);
			}
		}
		if ( '' === $code ) {
			$code = CodeIndex::unique(
				array(
					'prefix'    => '' !== (string) $config['code_prefix'] ? $config['code_prefix'] : $this->settings->get( 'vcap_code_prefix' ),
					'suffix'    => '' !== (string) $config['code_suffix'] ? $config['code_suffix'] : $this->settings->get( 'vcap_code_suffix' ),
					'length'    => (int) ( $config['code_length'] ?: $this->settings->get( 'vcap_code_length' ) ),
					'charset'   => $this->settings->get( 'vcap_code_charset' ),
					'separator' => $this->settings->get( 'vcap_code_separator' ),
					'order_ref' => $this->settings->yes( 'vcap_code_order_ref' ) ? $order->get_order_number() : '',
					/** Minimum guessing space of generated voucher codes in bits (default 50: 10 letters/digits or 16 digits). */
					'min_bits'  => (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS ),
				)
			);
		}
		$voucher->code = (string) apply_filters( 'vcap_voucher_code', $code, $order, $item, $index );

		if ( ! $this->repo->insert( $voucher ) ) {
			// Slot taken by a concurrent request or code collision – or a database error (logged with a reference).
			if ( $from_pool ) {
				$this->pool->release( $code );
			}
			if ( 'db_error' === $this->repo->last_insert_error ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: product name, 2: reference id in the WooCommerce log */
						__( 'A voucher for "%1$s" could not be saved (database error, reference %2$s). It is retried automatically.', 'voucher-captain' ),
						wp_strip_all_tags( $item->get_name() ),
						Db::last_reference()
					)
				);
			}
			return null;
		}
		if ( $from_pool && ! $this->pool->attach( $code, $voucher->id ) ) {
			// The code is taken (used_at set) but not linked: the pool list would show it unassigned. Say so.
			$this->repo->log( $voucher->id, 'note', null, null, __( 'Imported code could not be linked to this voucher in the code pool.', 'voucher-captain' ) );
		}

		$create_coupon = 'ticket' !== $voucher->type || $voucher->initial_amount > 0;
		if ( apply_filters( 'vcap_create_coupon', $create_coupon, $voucher, $config ) ) {
			$error = '';
			try {
				$voucher->coupon_id = $this->coupons->create_coupon( $voucher, $config );
			} catch ( \Throwable $e ) {
				// E.g. WC_Data_Exception for an amount WooCommerce refuses: roll the voucher back like a failed save.
				$voucher->coupon_id = 0;
				$error              = wp_strip_all_tags( $e->getMessage() );
			}
			if ( '' !== $error ) {
				/* translators: 1: product name, 2: error message */
				$order->add_order_note( sprintf( __( 'WooCommerce refused the coupon of a voucher for "%1$s": %2$s', 'voucher-captain' ), $item->get_name(), $error ) );
			}
			if ( ! $voucher->coupon_id || ! $this->repo->update( $voucher ) ) {
				// Never leave a voucher without its WooCommerce coupon (the code would be sent but not work): undo
				// this slot so the next issuing run creates it again, and tell the shop.
				if ( $voucher->coupon_id ) {
					wp_delete_post( $voucher->coupon_id, true );
				}
				$this->repo->delete( $voucher->id );
				if ( $from_pool && ! $this->pool->release( $code ) ) {
					/* translators: %s: code */
					$order->add_order_note( sprintf( __( 'Imported code %s could not be returned to the code pool.', 'voucher-captain' ), Mask::code( $code ) ) );
				}
				/* translators: %s: product name */
				$order->add_order_note( sprintf( __( 'A voucher for "%s" could not be created (WooCommerce coupon not saved). It will be issued on the next attempt.', 'voucher-captain' ), $item->get_name() ) );
				return null;
			}
			if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
				// Not redeemable before the gift is delivered.
				$this->coupons->set_coupon_enabled( $voucher, false );
			}
		}
		if ( ! $this->repo->log( $voucher->id, 'issued', $voucher->initial_amount, $voucher->balance, '', $order->get_id() ) ) {
			Db::fail( 'log_issued', array( 'voucher_id' => $voucher->id, 'order_id' => $order->get_id() ) );
			$order->add_order_note( sprintf( /* translators: %s: code */ __( 'Voucher %s was issued, but its history entry could not be written.', 'voucher-captain' ), Mask::code( (string) $voucher->code ) ) );
		}
		return $voucher;
	}

	/* ---- Vouchers without an order (R6) ------------------------------------------------------------- */

	/** Ledger note code of a voucher issued without an order (args: source, reference). */
	public const STANDALONE_NOTE = 'issued_standalone';
	/** First slot index of vouchers without an order (order item 0; store credit uses the user id below it). */
	private const STANDALONE_SLOT_BASE = 2147483648;

	/**
	 * Issues one voucher without a WooCommerce order – e.g. a company order with a list of recipients, a goodwill
	 * voucher or a prize – with the same code generation, WooCommerce coupon, "issued" ledger entry and email
	 * delivery as a purchased one. Public PHP API (see API.md, "Issuing vouchers without an order").
	 *
	 * The caller is responsible for the permission and the context: this method checks no capability (it is
	 * also meant for background tasks), so call it only from code that has checked who asked for it (e.g.
	 * current_user_can( 'vcap_manage_vouchers' ) and a nonce for an admin form).
	 *
	 * @param array $args {
	 *     @type int         $product_id      Voucher product whose settings to use (type, usage, template, validity,
	 *                                        restrictions, terms). Optional.
	 *     @type float       $amount          Value (percent for percentage vouchers). Default: the product's fixed
	 *                                        value or price. Required without a product.
	 *     @type string      $type            voucher|giftcard|ticket (default: the product's, else "voucher").
	 *     @type string      $usage           single|balance|unlimited (default: the product's, else "single").
	 *     @type string      $discount_type   fixed_cart|percent (default: the product's, else "fixed_cart").
	 *     @type int         $template_id     PDF template (default: the product's, else the default template).
	 *     @type string|null $expires_at      Expiry, UTC "Y-m-d H:i:s"; '' or null = never. Default: the product's
	 *                                        validity rule, else the global default.
	 *     @type int         $expiry_days     Alternative to expires_at: valid for this many days.
	 *     @type string      $recipient_name  Recipient name (on the PDF and in the email).
	 *     @type string      $recipient_email Recipient address: the voucher is mailed there.
	 *     @type string      $buyer_name      Name of the ordering party (e.g. the company).
	 *     @type string      $buyer_email     Address of the ordering party (gets the voucher when there is no
	 *                                        recipient address, or a copy with buyer_copy).
	 *     @type bool        $buyer_copy      Also mail the ordering party (default false).
	 *     @type string      $message         Personal message.
	 *     @type bool        $send            Deliver by email now (default true). false: the voucher counts as
	 *                                        handed over (no email; "Send again" in the admin still works).
	 *     @type string      $delivery_at     Deliver (and activate) later, UTC "Y-m-d H:i:s".
	 *     @type string      $code            Own code (must be unused and as hard to guess as a generated one,
	 *                                        CodeGenerator::analyse_batch(), else "vcap_weak_code"); default: a
	 *                                        generated code.
	 *     @type bool        $allow_weak      Accept an own code below the minimum strength (default false).
	 *     @type string      $source          Free label of the caller, e.g. "company-order" (in the history).
	 *     @type string      $reference       Idempotency key (up to 100 characters), e.g. "order-4711/3": a
	 *                                        second call with the same reference issues nothing and returns
	 *                                        the error "vcap_reference_used" with the voucher id in its data.
	 * }
	 * @return Voucher|\WP_Error
	 */
	public function issue_standalone( array $args ) {
		$product = null;
		$config  = ProductConfig::DEFAULTS;
		if ( ! empty( $args['product_id'] ) ) {
			$product = wc_get_product( (int) $args['product_id'] );
			if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
				return new \WP_Error( 'vcap_invalid_product', __( 'This product is not a voucher product.', 'voucher-captain' ), array( 'status' => 400 ) );
			}
			$config = ProductConfig::get( $product );
		}
		foreach ( array( 'type' => 'type', 'usage' => 'usage', 'discount_type' => 'discount_type', 'template_id' => 'template' ) as $arg => $key ) {
			if ( isset( $args[ $arg ] ) && '' !== (string) $args[ $arg ] ) {
				$config[ $key ] = 'template' === $key ? (int) $args[ $arg ] : sanitize_key( (string) $args[ $arg ] );
			}
		}
		if ( ! isset( Voucher::types()[ $config['type'] ] ) || 'credit' === $config['type'] || ! in_array( $config['usage'], array( 'single', 'balance', 'unlimited' ), true ) || ! in_array( $config['discount_type'], array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ) {
			return new \WP_Error( 'vcap_invalid_args', __( 'Unknown voucher type, usage or discount type.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		if ( isset( $args['amount'] ) ) {
			$amount = (float) $args['amount'];
		} elseif ( $product ) {
			$amount = 'fixed' === $config['amount_source'] && '' !== (string) $config['amount'] ? (float) wc_format_decimal( (string) $config['amount'] ) : (float) $product->get_price();
		} else {
			$amount = 0.0;
		}
		$currency = (string) get_option( 'woocommerce_currency' );
		$amount   = round( $amount, API\V1\RedemptionService::decimals( $currency ) );
		if ( ! is_finite( $amount ) || $amount < 0 || ( $amount <= 0 && 'ticket' !== $config['type'] ) ) {
			return new \WP_Error( 'vcap_invalid_amount', __( 'Please give a voucher value greater than zero.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		// Same discount rules as for products: the value is a fixed value here.
		$errors = ProductConfig::discount_errors( array_merge( $config, array( 'amount_source' => 'fixed', 'amount' => (string) $amount ) ) );
		if ( $errors ) {
			return new \WP_Error( 'vcap_invalid_args', esc_html( implode( ' ', $errors ) ), array( 'status' => 400 ) );
		}
		$recipient_email = sanitize_email( (string) ( $args['recipient_email'] ?? '' ) );
		$buyer_email     = sanitize_email( (string) ( $args['buyer_email'] ?? '' ) );
		$send            = ! array_key_exists( 'send', $args ) || (bool) $args['send'];
		if ( $send && '' === $recipient_email && '' === $buyer_email ) {
			return new \WP_Error( 'vcap_no_address', __( 'An email address of the recipient or the ordering party is needed to send the voucher.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$code = isset( $args['code'] ) ? wc_format_coupon_code( (string) $args['code'] ) : '';
		if ( '' !== $code && $this->coupons->code_exists( $code ) ) {
			return new \WP_Error( 'vcap_code_taken', __( 'This code is already used by another voucher or coupon.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		// M12-64: an own code must be as hard to guess as a generated one, unless the caller accepts it knowingly.
		$min_bits = (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS );
		$strength = '' !== $code && empty( $args['allow_weak'] ) ? CodeGenerator::analyse_batch( array( $code ), array(), $min_bits )['bits'][0] : null;
		if ( null !== $strength && $strength < $min_bits ) {
			return new \WP_Error(
				'vcap_weak_code',
				sprintf(
					/* translators: 1: estimated bits of the code, 2: minimum bits */
					__( 'This code is too easy to guess (about %1$d bits, at least %2$d needed). Use a longer random code, or pass allow_weak to accept it knowingly.', 'voucher-captain' ),
					(int) floor( $strength ),
					$min_bits
				),
				array( 'status' => 400 )
			);
		}
		$source    = mb_substr( sanitize_text_field( (string) ( $args['source'] ?? '' ) ), 0, 60 );
		$reference = mb_substr( sanitize_text_field( (string) ( $args['reference'] ?? '' ) ), 0, 100 );
		$note      = Note::make( self::STANDALONE_NOTE, array( $source, $reference ) );
		if ( '' !== $reference && ! Claim::take( 'standalone:' . md5( $reference ) ) ) {
			// AP-P1-3: the claim (kept for good, released only when the insert failed) decides; every reference
			// issued since standalone issuing exists holds one. Only a used reference looks up its voucher, from
			// the time the claim was taken on (created_at index) instead of reading every "issued" entry per call.
			$existing = $this->standalone_by_note( $note, Claim::taken_at( 'standalone:' . md5( $reference ) ) );
			return new \WP_Error( 'vcap_reference_used', __( 'A voucher was already issued for this reference.', 'voucher-captain' ), array( 'status' => 409, 'voucher_id' => $existing ) );
		}
		$voucher = $this->insert_standalone( $args, $config, $amount, $currency, $code, $send, $product );
		if ( is_wp_error( $voucher ) ) {
			if ( '' !== $reference ) {
				Claim::release( 'standalone:' . md5( $reference ) );
			}
			return $voucher;
		}
		if ( ! $this->repo->log( $voucher->id, 'issued', $voucher->initial_amount, $voucher->balance, $note ) ) {
			Db::fail( 'log_issued', array( 'voucher_id' => $voucher->id ) );
		}
		if ( $send ) {
			$this->delivery->schedule( $voucher );
		}
		/** A voucher was issued without an order (args: Voucher, the arguments of Issuer::issue_standalone()). */
		do_action( 'vcap_voucher_issued_standalone', $voucher, $args );
		return $voucher;
	}

	/**
	 * Id of the voucher whose "issued" entry carries this standalone note (0 = none). With $since (when the
	 * reference was claimed) only entries written from then on are read, by the created_at index.
	 */
	private function standalone_by_note( string $note, ?string $since = null ): int {
		global $wpdb;
		$events = Install::tables()['events'];
		if ( null !== $since && false !== strtotime( $since . ' UTC' ) ) {
			$from = gmdate( 'Y-m-d H:i:s', (int) strtotime( $since . ' UTC' ) - MINUTE_IN_SECONDS );
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT voucher_id FROM {$events} WHERE created_at >= %s AND event = 'issued' AND order_id = 0 AND note = %s ORDER BY created_at, id LIMIT 1", $from, $note ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT voucher_id FROM {$events} WHERE event = 'issued' AND order_id = 0 AND note = %s ORDER BY id LIMIT 1", $note ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Voucher row and WooCommerce coupon of issue_standalone() (nothing is left behind on failure).
	 *
	 * @return Voucher|\WP_Error
	 */
	private function insert_standalone( array $args, array $config, float $amount, string $currency, string $code, bool $send, ?\WC_Product $product ) {
		$voucher                 = new Voucher();
		$voucher->type           = $config['type'];
		$voucher->product_id     = $product ? (int) ( $product->get_parent_id() ?: $product->get_id() ) : 0;
		$voucher->variation_id   = $product && $product->get_parent_id() ? (int) $product->get_id() : 0;
		$voucher->template_id    = (int) $config['template'] ?: (int) $this->settings->get( 'vcap_default_template' );
		$voucher->discount_type  = $config['discount_type'];
		$voucher->usage_mode     = 'percent' === $config['discount_type'] && 'balance' === $config['usage'] ? 'single' : $config['usage'];
		if ( 'balance' === $voucher->usage_mode ) {
			$voucher->discount_type = 'fixed_cart';
		}
		$voucher->currency       = $currency;
		$voucher->initial_amount = $amount;
		$voucher->balance        = $amount;
		if ( array_key_exists( 'expires_at', $args ) ) {
			$ts                  = '' !== (string) $args['expires_at'] ? strtotime( (string) $args['expires_at'] . ' UTC' ) : false;
			$voucher->expires_at = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		} elseif ( ! empty( $args['expiry_days'] ) ) {
			$voucher->expires_at = ProductConfig::expiry_for( array_merge( $config, array( 'expiry_mode' => 'days', 'expiry_days' => (int) $args['expiry_days'] ) ) );
		} else {
			$voucher->expires_at = ProductConfig::expiry_for( $config );
		}
		$voucher->recipient_name  = sanitize_text_field( (string) ( $args['recipient_name'] ?? '' ) );
		$voucher->recipient_email = sanitize_email( (string) ( $args['recipient_email'] ?? '' ) );
		$voucher->buyer_name      = sanitize_text_field( (string) ( $args['buyer_name'] ?? '' ) );
		$voucher->buyer_email     = sanitize_email( (string) ( $args['buyer_email'] ?? '' ) );
		$voucher->message         = sanitize_textarea_field( (string) ( $args['message'] ?? '' ) );
		$voucher->fields          = array();
		if ( '' !== $voucher->recipient_email ) {
			$voucher->send_to = ! empty( $args['buyer_copy'] ) && '' !== $voucher->buyer_email ? 'both' : 'recipient';
		} else {
			$voucher->send_to = 'buyer';
		}
		$voucher->max_checkins = max( 1, (int) $config['max_checkins'] );
		$voucher->transferable = 'no' !== $config['transferable'];
		$voucher->terms        = ProductConfig::terms( $config );
		$delivery              = ! empty( $args['delivery_at'] ) ? strtotime( (string) $args['delivery_at'] . ' UTC' ) : false;
		$voucher->delivery_at  = $delivery && $delivery > time() ? gmdate( 'Y-m-d H:i:s', $delivery ) : null;
		$voucher->status       = $voucher->delivery_at ? Voucher::STATUS_SCHEDULED : Voucher::STATUS_ACTIVE;
		if ( ! $send && ! $voucher->delivery_at ) {
			$voucher->delivered_at = $this->repo->now(); // Handed over by the caller: no email, no safety-net delivery.
		}
		$voucher->code = '' !== $code ? $code : CodeGenerator::unique(
			array(
				'prefix'    => '' !== (string) $config['code_prefix'] ? $config['code_prefix'] : $this->settings->get( 'vcap_code_prefix' ),
				'suffix'    => '' !== (string) $config['code_suffix'] ? $config['code_suffix'] : $this->settings->get( 'vcap_code_suffix' ),
				'length'    => (int) ( $config['code_length'] ?: $this->settings->get( 'vcap_code_length' ) ),
				'charset'   => $this->settings->get( 'vcap_code_charset' ),
				'separator' => $this->settings->get( 'vcap_code_separator' ),
				'min_bits'  => (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS ),
			),
			array( $this->coupons, 'code_exists' )
		);
		// Unique slot: order item 0 with an index above every user id (store credit accounts use the user id).
		$voucher->order_item_id = 0;
		for ( $try = 0; $try < 5 && ! $voucher->id; $try++ ) {
			$voucher->item_index = self::STANDALONE_SLOT_BASE + wp_rand( 0, 2147483646 );
			$this->repo->insert( $voucher );
			if ( 'duplicate' !== $this->repo->last_insert_error ) {
				break;
			}
		}
		if ( ! $voucher->id ) {
			return new \WP_Error( 'vcap_issue_failed', __( 'The voucher could not be saved. Please try again.', 'voucher-captain' ), array( 'status' => 500 ) );
		}
		$create_coupon = 'ticket' !== $voucher->type || $voucher->initial_amount > 0;
		if ( apply_filters( 'vcap_create_coupon', $create_coupon, $voucher, $config ) ) {
			/* translators: %s: voucher type */
			$description = sprintf( __( '%s issued without order (Voucher Captain)', 'voucher-captain' ), Voucher::types()[ $voucher->type ] ?? $voucher->type );
			try {
				$voucher->coupon_id = $this->coupons->create_coupon( $voucher, $config, $description );
			} catch ( \Throwable $e ) {
				$voucher->coupon_id = 0;
			}
			if ( ! $voucher->coupon_id || ! $this->repo->update( $voucher ) ) {
				if ( $voucher->coupon_id ) {
					wp_delete_post( $voucher->coupon_id, true );
				}
				$this->repo->delete( $voucher->id );
				return new \WP_Error( 'vcap_issue_failed', __( 'The voucher could not be created (WooCommerce coupon not saved). Please try again.', 'voucher-captain' ), array( 'status' => 500 ) );
			}
			if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
				$this->coupons->set_coupon_enabled( $voucher, false );
			}
		}
		return $voucher;
	}

	/**
	 * Purchase order cancelled/refunded → void its vouchers.
	 *
	 * "Refunded": the money went back (refunds of value already spent are refused, guard_refund()), so every
	 * voucher is voided. "Cancelled" by hand records no refund: only unused vouchers are voided; vouchers already
	 * used (redeemed in the shop or by a connector, partly spent, being redeemed) stay valid, and an order note
	 * names them so the shop decides (void by hand after refunding the rest).
	 */
	public function on_purchase_cancelled( $order_id, $order = null ): void {
		if ( ! $this->settings->yes( 'vcap_void_on_refund' ) ) {
			return;
		}
		$order    = wc_get_order( (int) $order_id );
		$order    = $order instanceof \WC_Order ? $order : null;
		$refunded = $order && $order->has_status( 'refunded' );
		$kept     = array();
		foreach ( $this->repo->for_order( (int) $order_id ) as $voucher ) {
			if ( ! $refunded && Voucher::STATUS_VOID !== $voucher->status && $this->in_use( $voucher ) ) {
				$kept[] = Mask::code( $voucher->code ); // AP-S3-3: order notes show codes masked.
				continue;
			}
			$this->void_purchase( $voucher, __( 'Purchase order cancelled or refunded', 'voucher-captain' ), $order );
		}
		if ( $kept && $order ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: voucher codes */
					__( 'Order cancelled without a refund: vouchers already used (%s) stay valid, the unused ones were voided. If money is paid back for them, void them by hand on the voucher screen.', 'voucher-captain' ),
					implode( ', ', $kept )
				)
			);
		}
	}

	/** Whether a voucher was used up completely: a redeemed single-use voucher, a gift card without balance. */
	private function used_up( Voucher $voucher ): bool {
		if ( Voucher::STATUS_RESERVED === $voucher->status || Voucher::STATUS_VOID === $voucher->status ) {
			return false;
		}
		return $voucher->is_multi_use() ? (float) $voucher->balance <= 0 && self::spent_value( $voucher, $this->repo ) > 0 : self::spent_value( $voucher, $this->repo ) > 0;
	}

	/** Whether a voucher was (partly) used or is being redeemed right now. */
	private function in_use( Voucher $voucher ): bool {
		return self::spent_value( $voucher, $this->repo ) > 0 || Voucher::STATUS_RESERVED === $voucher->status;
	}

	/**
	 * Purchased value of a voucher that was already used (base currency) – what a refund of its purchase can no
	 * longer pay back.
	 *  - Gift cards: spent = purchased value + net top-ups − balance (ledger), spent from the purchased value first
	 *    (TopUp::shares()); so a refund of the purchase and refunds of top-ups together never pay out more than
	 *    the balance. Purchased value already taken off the card by a refund does not count again.
	 *  - Single-use vouchers: the whole value once redeemed, or while an unpaid order (pending, failed, on hold)
	 *    still carries the code – it can be paid later with the discount (CouponManager::open_orders_with()).
	 */
	public static function spent_value( Voucher $voucher, ?VoucherRepository $repo = null ): float {
		if ( $voucher->is_multi_use() ) {
			return TopUp::card_shares( $voucher, $repo ?? vcap()->vouchers )['purchase_spent'];
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return 0.0;
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status ) {
			return (float) $voucher->initial_amount;
		}
		return CouponManager::open_orders_with( $voucher ) ? (float) $voucher->initial_amount : 0.0;
	}

	/**
	 * Before a refund of a voucher purchase is saved: refunding more than the order minus the value already
	 * spent from its vouchers would pay back goods the customer already received. Such refunds are refused
	 * with the maximum amount (filter "vcap_allow_refund_of_spent_vouchers" to allow them knowingly).
	 * Gift card top-ups of the order count as well (always, also without "void on refund"): what was spent from
	 * the card since cannot be taken back (TopUp::spent_value(); the limit per top-up line is in TopUp).
	 *
	 * @param \WC_Order_Refund $refund Refund about to be saved.
	 * @param array            $args   wc_create_refund() arguments.
	 * @throws \Exception To stop the refund (WooCommerce shows the message).
	 */
	public function guard_refund( $refund, $args ): void {
		$order = wc_get_order( (int) ( $args['order_id'] ?? ( $refund instanceof \WC_Order_Refund ? $refund->get_parent_id() : 0 ) ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$spent = 0.0;
		$codes = array();
		if ( $this->settings->yes( 'vcap_void_on_refund' ) ) {
			foreach ( $this->repo->for_order( $order->get_id() ) as $voucher ) {
				$used = self::spent_value( $voucher, $this->repo );
				if ( $used > 0 ) {
					$spent  += $used;
					$codes[] = $voucher->code;
				}
			}
		}
		list( $topup_spent, $topup_codes ) = TopUp::spent_value( $order, $this->repo );
		$spent += $topup_spent;
		$codes  = array_merge( $codes, $topup_codes );
		if ( $spent <= 0 ) {
			return;
		}
		// Balances are kept in the shop base currency: compare in the base currency, report in the order currency.
		// WooCommerce has already saved this refund (wc_create_refund() recalculates its taxes before the hook),
		// so only earlier refunds count as refunded.
		$current_id = $refund instanceof \WC_Order_Refund ? (int) $refund->get_id() : 0;
		$refunded   = 0.0;
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1 ) ) as $earlier ) {
			if ( (int) $earlier->get_id() !== $current_id ) {
				$refunded += abs( (float) $earlier->get_amount() );
			}
		}
		$remaining = (float) $order->get_total() - $refunded;
		$amount    = (float) ( $args['amount'] ?? 0 );
		$to_base   = static function ( float $value ) use ( $order ): ?float {
			return Compat\MultiCurrency::to_base( $value, (string) $order->get_currency(), $order );
		};
		$remaining_base = $to_base( $remaining );
		if ( null === $remaining_base || null === $to_base( $amount ) ) {
			// Foreign currency without exchange rate: the spent base-currency value cannot be compared (never 1:1).
			if ( apply_filters( 'vcap_allow_refund_of_spent_vouchers', false, $order, $amount, $spent ) ) {
				return;
			}
			throw new \Exception(
				sprintf(
					/* translators: 1: voucher codes, 2: order currency */
					esc_html__( 'Value from this order was already spent (%1$s), and no exchange rate for %2$s is known to work out how much can still be refunded. Set up the exchange rate first.', 'voucher-captain' ),
					esc_html( implode( ', ', $codes ) ),
					esc_html( (string) $order->get_currency() )
				)
			);
		}
		$max_base       = $remaining_base - $spent;
		$decimals       = API\V1\RedemptionService::decimals( (string) $order->get_currency() );
		$tolerance      = 0.5 / ( 10 ** $decimals );
		if ( $to_base( $amount ) <= $max_base + $tolerance || apply_filters( 'vcap_allow_refund_of_spent_vouchers', false, $order, $amount, $spent ) ) {
			return;
		}
		$max   = $remaining_base > 0 ? round( $remaining * max( 0.0, $max_base ) / $remaining_base, $decimals ) : 0.0;
		$spent = $remaining_base > 0 ? $remaining * $spent / $remaining_base : $spent;
		throw new \Exception(
			sprintf(
				$topup_spent > 0
					/* translators: 1: voucher codes and masked gift card codes, 2: spent value, 3: maximum refund */
					? esc_html__( 'Value from this order was already spent (%1$s, value used: %2$s). At most %3$s can be refunded; unused vouchers are voided and unspent top-ups are taken back from the gift card.', 'voucher-captain' )
					/* translators: 1: voucher codes, 2: spent value, 3: maximum refund */
					: esc_html__( 'Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided.', 'voucher-captain' ),
				esc_html( implode( ', ', $codes ) ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $spent, array( 'currency' => $order->get_currency() ) ) ) ) ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( max( 0, $max ), array( 'currency' => $order->get_currency() ) ) ) ) )
			)
		);
	}

	/**
	 * Partial refund of voucher lines: as many vouchers as the refunded quantity covers are voided, unused
	 * ones first (newest first). Partly used gift cards follow when no unused voucher is left for that line (their
	 * unspent value is what the refund pays back). Vouchers used up completely (redeemed single-use vouchers,
	 * gift cards without balance) are never voided by a partial refund: their value was not refundable
	 * (guard_refund()), and a connector would revoke a service already delivered – an order note names them.
	 */
	public function on_partial_refund( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->settings->yes( 'vcap_void_on_refund' ) ) {
			return;
		}
		$by_item = array();
		foreach ( $this->repo->for_order( $order->get_id() ) as $voucher ) {
			$by_item[ (int) $voucher->order_item_id ][] = $voucher;
		}
		if ( ! $by_item ) {
			return;
		}
		// Refunded quantities straight from the refund documents: the order's cached refund list was read by
		// wc_create_refund() before the new refund was saved.
		$refunded = array();
		$refunds  = wc_get_orders(
			array(
				'type'   => 'shop_order_refund',
				'parent' => $order->get_id(),
				'limit'  => -1,
			)
		);
		foreach ( $refunds as $refund ) {
			foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
				$original = (int) $refund_item->get_meta( '_refunded_item_id' );
				if ( $original ) {
					$refunded[ $original ] = ( $refunded[ $original ] ?? 0 ) + abs( (int) $refund_item->get_quantity() );
				}
			}
		}
		foreach ( $by_item as $item_id => $vouchers ) {
			$item = $order->get_item( $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product || $item->get_quantity() < 1 ) {
				continue;
			}
			$refunded_qty = (int) ( $refunded[ $item_id ] ?? 0 );
			$per_unit     = max( 1, (int) round( count( $vouchers ) / $item->get_quantity() ) );
			$void_target  = min( count( $vouchers ), $refunded_qty * $per_unit );
			$handled      = array_filter( $vouchers, fn( Voucher $v ) => $this->purchase_handled( $v ) );
			$voided       = count( $handled );
			if ( $voided >= $void_target ) {
				continue;
			}
			$open = array_values( array_diff_key( $vouchers, $handled ) );
			usort(
				$open,
				fn( Voucher $a, Voucher $b ) => array( $this->in_use( $a ) ? 1 : 0, -$a->item_index ) <=> array( $this->in_use( $b ) ? 1 : 0, -$b->item_index )
			);
			foreach ( array_slice( $open, 0, $void_target - $voided ) as $voucher ) {
				if ( $this->used_up( $voucher ) ) {
					if ( 'yes' !== (string) $order->get_meta( '_vcap_used_kept_' . $voucher->id ) ) {
						$order->update_meta_data( '_vcap_used_kept_' . $voucher->id, 'yes' );
						$order->save_meta_data();
						$order->add_order_note(
							sprintf(
								/* translators: %s: voucher code */
								__( 'Voucher %s was already used up and stays as it is (partial refund): its value was not refundable. Void it by hand only if the service it was used for is cancelled as well.', 'voucher-captain' ),
								Mask::code( $voucher->code )
							)
						);
					}
					continue;
				}
				$ok = true;
				/* translators: %d: refund id */
				$kept = $this->void_purchase( $voucher, sprintf( __( 'Partial refund #%d of the purchase order', 'voucher-captain' ), (int) $refund_id ), $order, $ok );
				if ( ! $ok ) {
					continue; // void_or_retry() wrote the note and scheduled a retry.
				}
				if ( Voucher::STATUS_VOID === $voucher->status ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: voucher code */
							__( 'Voucher %s voided (refunded).', 'voucher-captain' ),
							Mask::code( $voucher->code )
						)
					);
				} elseif ( $kept > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: gift card code, 2: topped-up value that stays on the card */
							__( 'Gift card %1$s: the purchased value was taken off the card (refunded); the topped-up value of %2$s stays on it.', 'voucher-captain' ),
							Mask::code( $voucher->code ),
							wp_strip_all_tags( wc_price( $kept, array( 'currency' => $voucher->currency ) ) )
						)
					);
				}
			}
		}
	}

	/** Whether the purchase of this voucher was refunded already (voided, or its purchased value taken off). */
	private function purchase_handled( Voucher $voucher ): bool {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return true;
		}
		if ( ! $voucher->is_multi_use() ) {
			return false;
		}
		foreach ( $this->repo->events( $voucher->id ) as $event ) {
			if ( self::PURCHASE_REFUNDED_EVENT === ( $event['event'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The purchase of a voucher was refunded (cancelled, fully or by quantity). A gift card that was topped up
	 * keeps its topped-up value: only the unspent purchased value is taken off the balance (ledger event
	 * "purchase_refunded") and the card stays usable. Without top-up value left the voucher is voided as before.
	 *
	 * @return float Topped-up value that stays on the card (0 when the voucher was voided or nothing changed).
	 */
	public function void_purchase( Voucher $voucher, string $note = '', ?\WC_Order $order = null, ?bool &$ok = null ): float {
		$ok = true;
		if ( Voucher::STATUS_VOID === $voucher->status || ! $voucher->is_multi_use() ) {
			$ok = $this->void_or_retry( $voucher, $note, $order );
			return 0.0;
		}
		$fresh = $this->repo->get( $voucher->id ) ?? $voucher;
		if ( $this->purchase_handled( $fresh ) ) {
			return (float) $fresh->balance; // Purchased value already taken off; the rest is topped-up value.
		}
		$shares = TopUp::card_shares( $fresh, $this->repo );
		if ( $shares['topup_unspent'] <= 0.5 / ( 10 ** $fresh->decimals() ) ) {
			$ok = $this->void_or_retry( $voucher, $note, $order );
			return 0.0;
		}
		$take     = round( $shares['purchase_unspent'], $fresh->decimals() );
		$order_id = $order ? $order->get_id() : (int) $voucher->order_id;
		$text     = trim( $note . ' ' . __( '(purchased value taken off, topped-up value kept)', 'voucher-captain' ) );
		$done     = $take > 0
			? $this->coupons->adjust_balance( $fresh, -$take, self::PURCHASE_REFUNDED_EVENT, $text, $order_id )
			: $this->repo->log( $fresh->id, self::PURCHASE_REFUNDED_EVENT, 0.0, (float) $fresh->balance, $text, $order_id );
		if ( ! $done ) {
			// The balance could not be changed: lock the whole card rather than pay out the purchased value twice.
			$ok = $this->void_or_retry( $voucher, $note, $order );
			return 0.0;
		}
		$after            = $this->repo->get( $voucher->id ) ?? $fresh;
		$voucher->balance = $after->balance;
		$voucher->status  = $after->status;
		return (float) $after->balance;
	}

	/**
	 * Voids the voucher of a refunded or cancelled purchase. When that fails (database error, coupon not saved),
	 * the refund is not undone: the order gets a note, the case is listed for the shop (Attention) and the void is
	 * retried in the background until it works.
	 */
	private function void_or_retry( Voucher $voucher, string $note, ?\WC_Order $order, int $attempt = 0 ): bool {
		if ( $this->void( $voucher, $note, true ) ) {
			return true;
		}
		$order_id = $order ? $order->get_id() : (int) $voucher->order_id;
		$order    = $order ?? ( $order_id ? wc_get_order( $order_id ) : null );
		if ( 0 === $attempt && $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: voucher code */
					__( 'Voucher %s could NOT be voided after the refund or cancellation – it may still be redeemable. It is tried again automatically; until then disable it by hand on the voucher screen.', 'voucher-captain' ),
					Mask::code( $voucher->code )
				)
			);
		}
		Attention::add(
			$order_id,
			'void:' . $voucher->id,
			/* translators: %s: voucher code */
			sprintf( __( 'Voucher %s could not be voided after its purchase was refunded or cancelled. Disable it on the voucher screen.', 'voucher-captain' ), Mask::code( $voucher->code ) )
		);
		if ( $attempt < self::VOID_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + ( $attempt < 2 ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS ), self::VOID_RETRY_HOOK, array( 'voucher_id' => (int) $voucher->id, 'attempt' => $attempt + 1 ), 'vcap' );
		}
		return false;
	}

	/** Action Scheduler: void a voucher again whose void failed after a refund (void_or_retry()). */
	public function retry_void( $voucher_id, $attempt = 1 ): void {
		$voucher = $this->repo->get( (int) $voucher_id );
		if ( ! $voucher || ! Attention::has( (int) $voucher->order_id, 'void:' . $voucher->id ) ) {
			return; // Gone, or the shop marked the case as done (e.g. decided to keep the voucher).
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			// Voided, but its coupon stayed enabled: disable it now (shop checkout refuses void vouchers anyway).
			if ( ! $this->coupons->set_coupon_enabled( $voucher, false ) ) {
				if ( (int) $attempt < self::VOID_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + HOUR_IN_SECONDS, self::VOID_RETRY_HOOK, array( 'voucher_id' => (int) $voucher->id, 'attempt' => (int) $attempt + 1 ), 'vcap' );
				}
				return;
			}
		} elseif ( ! $this->void_or_retry( $voucher, __( 'Purchase order cancelled or refunded (retried)', 'voucher-captain' ), null, max( 1, (int) $attempt ) ) ) {
			return;
		}
		Attention::resolve( (int) $voucher->order_id, 'void:' . $voucher->id );
		$order = wc_get_order( (int) $voucher->order_id );
		if ( $order instanceof \WC_Order ) {
			/* translators: %s: voucher code */
			$order->add_order_note( sprintf( __( 'Voucher %s voided (retried after the refund or cancellation).', 'voucher-captain' ), Mask::code( (string) $voucher->code ) ) );
		}
	}

	/**
	 * @param bool $refund Voided because its purchase was refunded or cancelled: the history note is stored as coded
	 *                     note "voided_refund" so that a later reactivation can tell (voided_after_refund()).
	 * @return bool False when the voucher could not be voided (nothing logged, the coupon stays as it was).
	 */
	public function void( Voucher $voucher, string $note = '', bool $refund = false ): bool {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return true;
		}
		if ( ! $this->repo->update_status( $voucher->id, Voucher::STATUS_VOID ) ) {
			return false;
		}
		$voucher->status = Voucher::STATUS_VOID;
		$text            = static fn( string $t ): string => $refund ? Note::make( self::REFUND_VOID_NOTE, array( $t ) ) : $t;
		if ( ! $this->coupons->set_coupon_enabled( $voucher, false ) ) {
			// The voucher is void but its coupon still works: say so loudly instead of pretending success.
			$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, $text( trim( $note . ' ' . __( '(WooCommerce coupon could not be disabled – disable it manually)', 'voucher-captain' ) ) ) );
			return false;
		}
		$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, $text( $note ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $voucher->id ), 'vcap' );
		}
		return true;
	}

	/**
	 * AP-S2-2: whether the voucher was voided because its purchase was refunded or cancelled – reactivating it gives
	 * back value the buyer got paid back. The newest "voided" entry decides (coded note "voided_refund"); for entries
	 * written before that note existed, a purchase order that is refunded or cancelled counts as well.
	 */
	public function voided_after_refund( Voucher $voucher ): bool {
		if ( Voucher::STATUS_VOID !== $voucher->status ) {
			return false;
		}
		$voided = null;
		foreach ( array_reverse( $this->repo->events( $voucher->id ) ) as $event ) {
			if ( 'voided' === ( $event['event'] ?? '' ) ) {
				$voided = $event;
				break;
			}
		}
		$decoded = $voided ? Note::decode( (string) $voided['note'] ) : null;
		if ( $decoded && self::REFUND_VOID_NOTE === $decoded['code'] ) {
			return true;
		}
		if ( $decoded || (int) $voucher->order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( (int) $voucher->order_id );
		return $order instanceof \WC_Order && $order->has_status( array( 'refunded', 'cancelled' ) );
	}

	/**
	 * Makes a void voucher usable again. Restoring value needs the right to raise balances (AP-S2-2,
	 * VoucherAdmin::can_increase()) when a user is logged in; system contexts (cron, WP-CLI) are not limited. A
	 * voucher voided because of a refund or cancellation is only reactivated with $after_refund (explicit confirmation).
	 *
	 * @return bool False when the voucher is not void, the right or confirmation is missing, or the status or the
	 *              coupon could not be restored.
	 */
	public function reactivate( Voucher $voucher, bool $after_refund = false ): bool {
		$fresh = $this->repo->get( $voucher->id ) ?? $voucher;
		if ( Voucher::STATUS_VOID !== $fresh->status ) {
			return false;
		}
		if ( is_user_logged_in() && ! Admin\VoucherAdmin::can_increase() ) {
			return false;
		}
		if ( ! $after_refund && $this->voided_after_refund( $fresh ) ) {
			return false;
		}
		$coupon  = $fresh->coupon_id ? new \WC_Coupon( $fresh->coupon_id ) : null;
		$used_up = $fresh->is_multi_use()
			? $fresh->balance <= 0
			: ( $coupon && $coupon->get_id() && $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() );
		// A single-use voucher that was already redeemed (shop or externally) stays redeemed.
		$status = $used_up ? Voucher::STATUS_REDEEMED : Voucher::STATUS_ACTIVE;
		if ( ! $this->repo->update_status( $voucher->id, $status ) ) {
			return false;
		}
		$voucher->status  = $status;
		$voucher->balance = $fresh->balance;
		$enabled          = $this->coupons->set_coupon_enabled( $voucher, true );
		$this->repo->log( $voucher->id, 'reactivated', null, $voucher->balance, $enabled ? '' : __( 'WooCommerce coupon could not be enabled – enable it manually', 'voucher-captain' ) );
		return $enabled;
	}
}
