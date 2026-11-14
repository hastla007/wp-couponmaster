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
		add_action( 'vcap_issue_order', array( $this, 'issue_async' ) );
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
	/** Action Scheduler hook: retries voiding a voucher whose purchase was refunded or cancelled. */
	public const VOID_RETRY_HOOK = 'vcap_retry_void';
	/** Attempts of the void retry (15 minutes apart, then an hour each). */
	private const VOID_RETRIES = 5;

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

	public function issue_async( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order && self::payment_ok( $order ) ) {
			$this->issue_for_order( $order );
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
	 * @return Voucher[] newly created vouchers
	 */
	public function issue_for_order( \WC_Order $order, bool $manual = false ): array {
		$created = array();
		if ( ! Compat\MultiCurrency::can_convert( (string) $order->get_currency(), $order ) && $this->needs_conversion( $order ) ) {
			$this->hold_for_rate( $order );
			return $created;
		}
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
			if ( $count > 0 ) {
				$this->record_line( $order, $item, $config, $count );
			}

			for ( $index = 0; $index < $count; $index++ ) {
				if ( $this->repo->get_slot( (int) $item_id, $index ) ) {
					continue;
				}
				$voucher = $this->create_voucher( $order, $item, $product, $config, $index );
				if ( $voucher ) {
					$created[] = $voucher;
				}
			}
		}

		if ( $created ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: count, 2: codes */
					_n( '%1$d voucher issued: %2$s', '%1$d vouchers issued: %2$s', count( $created ), 'voucher-captain' ),
					count( $created ),
					implode( ', ', wp_list_pluck( $created, 'code' ) )
				) . ( $manual ? ' ' . __( '(manual)', 'voucher-captain' ) : '' )
			);
			foreach ( $created as $voucher ) {
				$this->delivery->schedule( $voucher );
			}
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
		if ( 'percent' !== $config['discount_type'] && 'fixed' !== $config['amount_source'] ) {
			// Paid / entered in the order currency; coupons are stored in the shop base currency.
			$amount = (float) apply_filters( 'vcap_amount_to_base_currency', $amount, $order->get_currency(), $order );
		}
		// Vouchers are kept in the shop base currency, booked with its ISO 4217 decimals.
		return round( (float) apply_filters( 'vcap_voucher_amount', $amount, $item, $config, $order ), API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
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
		$voucher->delivery_at = Delivery::compute_time( $config, $fields, $order );
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
			$code = CodeGenerator::unique(
				array(
					'prefix'    => '' !== (string) $config['code_prefix'] ? $config['code_prefix'] : $this->settings->get( 'vcap_code_prefix' ),
					'suffix'    => '' !== (string) $config['code_suffix'] ? $config['code_suffix'] : $this->settings->get( 'vcap_code_suffix' ),
					'length'    => (int) ( $config['code_length'] ?: $this->settings->get( 'vcap_code_length' ) ),
					'charset'   => $this->settings->get( 'vcap_code_charset' ),
					'separator' => $this->settings->get( 'vcap_code_separator' ),
					'order_ref' => $this->settings->yes( 'vcap_code_order_ref' ) ? $order->get_order_number() : '',
					/** Minimum guessing space of generated voucher codes in bits (default 50: 10 letters/digits or 16 digits). */
					'min_bits'  => (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS ),
				),
				array( $this->coupons, 'code_exists' )
			);
		}
		$voucher->code = (string) apply_filters( 'vcap_voucher_code', $code, $order, $item, $index );

		if ( ! $this->repo->insert( $voucher ) ) {
			// Slot taken by a concurrent request or code collision.
			if ( $from_pool ) {
				$this->pool->release( $code );
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
					$order->add_order_note( sprintf( __( 'Imported code %s could not be returned to the code pool.', 'voucher-captain' ), $code ) );
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
			$order->add_order_note( sprintf( /* translators: %s: code */ __( 'Voucher %s was issued, but its history entry could not be written.', 'voucher-captain' ), $voucher->code ) );
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
				$kept[] = $voucher->code;
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
					__( 'Value from this order was already spent (%1$s), and no exchange rate for %2$s is known to work out how much can still be refunded. Set up the exchange rate first.', 'voucher-captain' ),
					implode( ', ', $codes ),
					(string) $order->get_currency()
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
					? __( 'Value from this order was already spent (%1$s, value used: %2$s). At most %3$s can be refunded; unused vouchers are voided and unspent top-ups are taken back from the gift card.', 'voucher-captain' )
					/* translators: 1: voucher codes, 2: spent value, 3: maximum refund */
					: __( 'Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided.', 'voucher-captain' ),
				implode( ', ', $codes ),
				html_entity_decode( wp_strip_all_tags( wc_price( $spent, array( 'currency' => $order->get_currency() ) ) ) ),
				html_entity_decode( wp_strip_all_tags( wc_price( max( 0, $max ), array( 'currency' => $order->get_currency() ) ) ) )
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
								$voucher->code
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
							$voucher->code
						)
					);
				} elseif ( $kept > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: gift card code, 2: topped-up value that stays on the card */
							__( 'Gift card %1$s: the purchased value was taken off the card (refunded); the topped-up value of %2$s stays on it.', 'voucher-captain' ),
							$voucher->code,
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
		if ( $this->void( $voucher, $note ) ) {
			return true;
		}
		$order_id = $order ? $order->get_id() : (int) $voucher->order_id;
		$order    = $order ?? ( $order_id ? wc_get_order( $order_id ) : null );
		if ( 0 === $attempt && $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: voucher code */
					__( 'Voucher %s could NOT be voided after the refund or cancellation – it may still be redeemable. It is tried again automatically; until then disable it by hand on the voucher screen.', 'voucher-captain' ),
					$voucher->code
				)
			);
		}
		Attention::add(
			$order_id,
			'void:' . $voucher->id,
			/* translators: %s: voucher code */
			sprintf( __( 'Voucher %s could not be voided after its purchase was refunded or cancelled. Disable it on the voucher screen.', 'voucher-captain' ), $voucher->code )
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
			$order->add_order_note( sprintf( __( 'Voucher %s voided (retried after the refund or cancellation).', 'voucher-captain' ), $voucher->code ) );
		}
	}

	/** @return bool False when the voucher could not be voided (nothing logged, the coupon stays as it was). */
	public function void( Voucher $voucher, string $note = '' ): bool {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return true;
		}
		if ( ! $this->repo->update_status( $voucher->id, Voucher::STATUS_VOID ) ) {
			return false;
		}
		$voucher->status = Voucher::STATUS_VOID;
		if ( ! $this->coupons->set_coupon_enabled( $voucher, false ) ) {
			// The voucher is void but its coupon still works: say so loudly instead of pretending success.
			$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, trim( $note . ' ' . __( '(WooCommerce coupon could not be disabled – disable it manually)', 'voucher-captain' ) ) );
			return false;
		}
		$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, $note );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $voucher->id ), 'vcap' );
		}
		return true;
	}

	/** @return bool False when the status or the coupon could not be restored. */
	public function reactivate( Voucher $voucher ): bool {
		$fresh   = $this->repo->get( $voucher->id ) ?? $voucher;
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
