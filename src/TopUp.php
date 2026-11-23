<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Top-up of existing gift cards: a gift card product marked "Top up an existing gift card" asks for the card's
 * code instead of issuing a new card. When the order is paid (the moment new vouchers would be issued), the paid
 * value is added to that card's balance – once per order line. A partial refund takes the refunded share of a
 * top-up line back, a cancelled or fully refunded order the rest – as far as the balance still allows; refunds
 * that would pay out top-up value already spent from the card are refused (guard_refund()).
 */
final class TopUp {
	public const PRODUCT_META  = '_vcap_topup';
	public const ITEM_META     = '_vcap_topup_code';
	/** Order item meta: id of the topped-up card (AP-Q1-3), the code alone is lost when the card is reissued. */
	public const CARD_META     = '_vcap_topup_voucher_id';
	public const DONE_META     = '_vcap_topup_done';
	/** Top-up value of the line already taken back by refunds or cancellation (base currency). */
	public const REVERSED_META = '_vcap_topup_reversed';
	/** A top-up line without card code that was held instead of issuing a new card. */
	public const MISSING_META  = '_vcap_topup_missing';
	/** A top-up line that could not be booked (card no longer valid, no value): held for the shop, not retried. */
	public const HELD_META     = '_vcap_topup_held';
	/** M12-09: a top-up line waiting for the V7 security hold of its order (value: hold end, Unix time). */
	public const PENDING_META  = '_vcap_topup_pending';
	/** M12-09: Action Scheduler hook that books a held top-up line once its order's hold is over. */
	public const AFTER_HOLD_HOOK = 'vcap_topup_after_hold';
	/** Default minimum remaining validity (days) of a card to be topped up (setting and filter "vcap_topup_min_remaining_days"). */
	public const MIN_REMAINING_DAYS = 30;
	private const FIELD        = 'vcap_topup_code';

	public function __construct( private VoucherRepository $repo, private CouponManager $coupons ) {}

	public function register(): void {
		$this->register_lifecycle();
		$this->register_features();
	}

	/** New sales: product setting, code field, add to cart. */
	public function register_features(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_field' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'code_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		// Store API (cart/checkout blocks, product collection buttons): the code comes as "vcap_topup_code".
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_validate' ), 10, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again' ), 10, 3 );
	}

	/**
	 * Top-ups already in carts and orders: the paid value is booked onto the card (never a new card),
	 * cancellations and refunds take it back.
	 */
	public function register_lifecycle(): void {
		// A top-up line without a card code can never be ordered (it would issue a new card).
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item_meta' ), 10, 3 );
		add_filter( 'vcap_vouchers_per_item', array( $this, 'top_up_instead' ), 5, 3 );
		add_action( self::AFTER_HOLD_HOOK, array( $this, 'after_hold' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'hold_after_transition' ), 30, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke' ), 20 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'reverse_refunded' ), 20, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'guard_refund' ), 11, 2 );
		add_filter( 'vcap_event_labels', static fn( array $l ) => $l + array( 'topup' => __( 'Topped up', 'voucher-captain' ), 'topup_reversed' => __( 'Top-up reversed', 'voucher-captain' ), Issuer::PURCHASE_REFUNDED_EVENT => __( 'Purchase refunded: purchased value taken off', 'voucher-captain' ), Issuer::PURCHASE_REFUND_REVERSED_EVENT => __( 'Purchase refund not recorded: purchased value booked back', 'voucher-captain' ), Issuer::SUSPENDED_EVENT => __( 'Suspended: payment of the order reversed or held', 'voucher-captain' ), self::EXTENDED_EVENT => __( 'Validity extended', 'voucher-captain' ) ) );
		add_filter( 'vcap_ledger_money_events', static fn( array $e ) => array_merge( $e, array( 'topup', 'topup_reversed', Issuer::PURCHASE_REFUNDED_EVENT, Issuer::PURCHASE_REFUND_REVERSED_EVENT ) ) );
	}

	public static function is_topup( $product ): bool {
		$product = $product instanceof \WC_Product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		return $product instanceof \WC_Product && 'yes' === $product->get_meta( self::PRODUCT_META );
	}

	/* ---- Product settings -------------------------------------------------------------------------- */

	public function product_field( $product ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Top up', 'voucher-captain' ),
				'value'       => $product instanceof \WC_Product && 'yes' === $product->get_meta( self::PRODUCT_META ) ? 'yes' : 'no',
				'description' => __( 'Top up an existing gift card instead of issuing a new one (the customer enters the card code).', 'voucher-captain' ),
			)
		);
	}

	public function product_save( \WC_Product $product, $post ): void {
		if ( is_array( $post ) && array_key_exists( '_vcap_type', $post ) ) {
			$product->update_meta_data( self::PRODUCT_META, ! empty( $post[ self::PRODUCT_META ] ) ? 'yes' : 'no' );
		}
	}

	/* ---- Product page and cart --------------------------------------------------------------------- */

	public function code_field(): void {
		global $product;
		if ( ! self::is_topup( $product ) ) {
			return;
		}
		$value = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- refills the field after a failed add-to-cart.
		$id    = Shortcodes::unique_id( 'vcap-topup-code' ); // Unique on the page (two product forms, quick views).
		echo '<p class="form-row vcap-topup-field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Gift card code to top up', 'voucher-captain' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr></label>';
		echo '<input type="text" class="input-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::FIELD ) . '" value="' . Shortcodes::esc_attr_text( $value ) . '" required autocomplete="off" maxlength="100" /></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_attr_text().
		echo CodeLookup::pin_hint(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in pin_hint().
	}

	/** @return Voucher|string The gift card that can be topped up, or why not. */
	public function card( string $raw ) {
		$code = wc_format_coupon_code( CodeLookup::code_input( sanitize_text_field( $raw ) ) ); // M12-99: code#PIN.
		$card = '' !== $code ? ( $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) ) ) : null;
		if ( ! $card ) {
			return __( 'This gift card code was not found.', 'voucher-captain' );
		}
		if ( 'giftcard' !== $card->type || ! $card->is_multi_use() ) {
			return __( 'Only gift cards with a balance can be topped up.', 'voucher-captain' );
		}
		if ( Voucher::STATUS_VOID === $card->status || $card->is_expired() || Voucher::STATUS_EXPIRED === $card->status ) {
			return __( 'This gift card is no longer valid and cannot be topped up.', 'voucher-captain' );
		}
		if ( $card->currency && $card->currency !== get_option( 'woocommerce_currency' ) ) {
			return __( 'This gift card is in another currency.', 'voucher-captain' );
		}
		return $card;
	}

	/** @var string|null top-up code of the current Store API add-to-cart request, used instead of $_POST */
	private ?string $override = null;

	/** @var array<string, true|string> codes checked in the current Store API request (checked and counted once) */
	private array $checked = array();

	/** The code to top up: from cart item data ("order again"), the Store API request or the product form. */
	private function posted_code( $cart_item_data = array() ): string {
		if ( is_array( $cart_item_data ) && isset( $cart_item_data[ self::FIELD ] ) && is_scalar( $cart_item_data[ self::FIELD ] ) ) {
			return (string) $cart_item_data[ self::FIELD ];
		}
		if ( null !== $this->override ) {
			return $this->override;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce add-to-cart; sanitized in card().
		return isset( $_POST[ self::FIELD ] ) && is_scalar( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : '';
	}

	/** @return true|string True when the code can be topped up, otherwise why not (failures count for the rate limit). */
	private function check( string $raw, ?\WC_Product $product = null ) {
		$memo = null !== $this->override ? wc_format_coupon_code( sanitize_text_field( $raw ) ) . ':' . ( $product ? $product->get_id() : 0 ) : null;
		if ( null !== $memo && isset( $this->checked[ $memo ] ) ) {
			return $this->checked[ $memo ]; // Store API runs the legacy and its own validation for the same request.
		}
		if ( '' === trim( sanitize_text_field( $raw ) ) ) {
			$result = __( 'Please enter the code of the gift card to top up.', 'voucher-captain' ); // Not a guess: no attempt counted.
		} elseif ( CodeLookup::begin() ) {
			$result = CodeLookup::limited_message();
		} else {
			// One answer for every code that cannot be topped up (unknown, another kind of voucher, void, expired,
			// other currency): the form does not tell which codes exist. Only a card that can be topped up gives
			// the attempt back; the order note of a held line keeps the exact reason (card()).
			$card = $this->card( $raw );
			$card = $card instanceof Voucher && ! CodeLookup::pin_ok( (string) $card->code ) ? null : $card; // M12-99: PIN cards need code#PIN.
			CodeLookup::finish( $card instanceof Voucher );
			$result = $card instanceof Voucher ? self::remaining_validity_error( $card ) : __( 'This code is not a gift card that can be topped up. Please check the code.', 'voucher-captain' );
			if ( '' === $result && $card instanceof Voucher && $product ) {
				$result = self::treatment_mismatch( $card, self::product_treatment( $product ) );
			}
			$result = '' === $result ? true : $result;
		}
		if ( null !== $memo ) {
			$this->checked[ $memo ] = $result;
		}
		return $result;
	}

	/**
	 * A card that expires soon is not topped up (the new value would expire with it): '' when the card is valid
	 * for at least the minimum remaining days (filter "vcap_topup_min_remaining_days", default 30; 0 = any valid
	 * card), otherwise the message for the customer.
	 */
	public static function remaining_validity_error( Voucher $card, ?int $now = null ): string {
		if ( ! $card->expires_at ) {
			return '';
		}
		$days = max( 0, (int) apply_filters( 'vcap_topup_min_remaining_days', SettingsRegistry::bounded_int( 'vcap_topup_min_remaining_days' ), $card ) );
		$ends = strtotime( $card->expires_at . ' UTC' );
		if ( $ends - ( $now ?? time() ) >= $days * DAY_IN_SECONDS ) {
			return '';
		}
		return sprintf(
			/* translators: 1: expiry date, 2: number of days */
			_n( 'This gift card expires on %1$s and can no longer be topped up (a top-up needs at least %2$d day of remaining validity). Please buy a new gift card instead.', 'This gift card expires on %1$s and can no longer be topped up (a top-up needs at least %2$d days of remaining validity). Please buy a new gift card instead.', $days, 'voucher-captain' ),
			wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ends ),
			$days
		);
	}

	public function validate( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed || ! self::is_topup( wc_get_product( (int) ( $variation_id ?: $product_id ) ) ) ) {
			return $passed;
		}
		$product = wc_get_product( (int) ( $variation_id ?: $product_id ) );
		$result  = $this->check( $this->posted_code( $cart_item_data ), $product instanceof \WC_Product ? $product : null );
		if ( true !== $result ) {
			wc_add_notice( $result, 'error' );
			return false;
		}
		return $passed;
	}

	/** Store API add-item: takes the code from the request parameter "vcap_topup_code" into the cart item data. */
	public function store_api_data( $data, $request ) {
		$this->override = null;
		$this->checked  = array();
		if ( ! is_array( $data ) || ! self::is_topup( wc_get_product( (int) ( $data['id'] ?? 0 ) ) ) ) {
			return $data;
		}
		$raw            = $request instanceof \WP_REST_Request ? $request->get_param( self::FIELD ) : null;
		$this->override = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		if ( '' !== $this->override ) {
			$data['cart_item_data']                = (array) ( $data['cart_item_data'] ?? array() );
			$data['cart_item_data'][ self::FIELD ] = wc_format_coupon_code( CodeLookup::split_pin( $this->override )[0] ); // Never the PIN.
		}
		return $data;
	}

	/**
	 * Store API add-item: a top-up needs a valid gift card code, like the product form.
	 *
	 * @param \WC_Product                   $product Product being added.
	 * @param \WP_REST_Request|array<mixed> $request Request or the add-to-cart data.
	 */
	public function store_api_validate( $product, $request ): void {
		if ( ! self::is_topup( $product ) ) {
			return;
		}
		if ( $request instanceof \WP_REST_Request ) {
			$raw            = $request->get_param( self::FIELD );
			$this->override = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		}
		$result = $this->check( $this->posted_code( is_array( $request ) ? ( $request['cart_item_data'] ?? array() ) : array() ), $product instanceof \WC_Product ? $product : null );
		if ( true !== $result && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_invalid_topup_code', esc_html( $result ), 400 );
		}
	}

	public function cart_item_data( $data, $product_id ) {
		if ( self::is_topup( wc_get_product( (int) $product_id ) ) && isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart.
			$data[ self::FIELD ] = wc_format_coupon_code( CodeLookup::split_pin( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) )[0] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- never the PIN (M12-99).
		}
		return $data;
	}

	/** "Order again" tops up the same card again (validated like the product form). */
	public function order_again( $data, $item, $order = null ) {
		$code = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta( self::ITEM_META ) : '';
		if ( '' !== $code ) {
			$data[ self::FIELD ] = $code;
		}
		return $data;
	}

	/** A top-up line without a card code (added by other means) cannot be ordered: it would buy nothing. */
	public function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $values ) {
			if ( empty( $values[ self::FIELD ] ) && isset( $values['data'] ) && self::is_topup( $values['data'] ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: product name */
						__( '"%s" needs the code of the gift card to top up. Please remove it from the cart and add it again on the product page.', 'voucher-captain' ),
						wp_strip_all_tags( $values['data']->get_name() )
					),
					'error'
				);
			}
		}
	}

	/** Cart and checkout show the card only masked (the code is a means of payment) and how long it is valid. */
	public function item_data( $data, $cart_item ) {
		if ( ! empty( $cart_item[ self::FIELD ] ) ) {
			$code   = strtoupper( (string) $cart_item[ self::FIELD ] );
			$card   = $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
			$data[] = array(
				'key'   => __( 'Tops up gift card', 'voucher-captain' ),
				'value' => Mask::code( $code ),
			);
			if ( $card instanceof Voucher && $card->expires_at ) {
				$data[] = array(
					'key'   => __( 'Gift card valid until', 'voucher-captain' ),
					'value' => wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card->expires_at . ' UTC' ) ),
				);
			}
		}
		return $data;
	}

	public function order_item_meta( $item, $cart_item_key, $values ): void {
		if ( ! empty( $values[ self::FIELD ] ) && $item instanceof \WC_Order_Item_Product ) {
			$item->add_meta_data( self::ITEM_META, (string) $values[ self::FIELD ], true );
			$card = $this->card( (string) $values[ self::FIELD ] );
			if ( $card instanceof Voucher && $card->id ) {
				$item->add_meta_data( self::CARD_META, (int) $card->id, true );
			}
		}
	}

	/**
	 * The card a top-up line belongs to (AP-Q1-3): by the stored card id, else by the code entered, else by a code
	 * the card had before it was reissued (Reissue::voucher_of_previous_code()).
	 */
	public static function card_of_item( \WC_Order_Item_Product $item, VoucherRepository $repo ): ?Voucher {
		$id = (int) $item->get_meta( self::CARD_META );
		if ( $id > 0 ) {
			$card = $repo->get( $id );
			if ( $card ) {
				return $card;
			}
		}
		$code = trim( (string) $item->get_meta( self::ITEM_META ) );
		if ( '' === $code ) {
			return null;
		}
		$formatted = wc_format_coupon_code( $code );
		return $repo->get_by_code( $code )
			?? ( '' !== $formatted ? $repo->get_by_code( $formatted ) : null )
			?? $repo->get_by_code( strtoupper( $code ) )
			?? Reissue::voucher_of_previous_code( $repo, $code );
	}

	/* ---- Order lifecycle --------------------------------------------------------------------------- */

	/**
	 * Called where the order's vouchers are issued: a top-up line issues none and adds its value to the card
	 * instead (once per line). The value follows the same rule as a new gift card (line_value()): its face value,
	 * not what was paid – a top-up paid with another gift card or a discount still adds its full value.
	 * A line that cannot be booked (card no longer valid, no value) is held: order on hold, note to the customer,
	 * listed for the shop (Attention) – never retried silently.
	 */
	public function top_up_instead( $count, $item, $config = array() ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $count;
		}
		if ( '' === (string) $item->get_meta( self::ITEM_META ) ) {
			return self::is_topup( $item->get_product() ) ? $this->hold_without_code( $item ) : $count;
		}
		if ( $item->get_meta( self::DONE_META ) || $item->get_meta( self::HELD_META ) || ! Claim::take( 'topup:' . $item->get_id() ) ) {
			return 0;
		}
		$order = $item->get_order();
		if ( ! $order instanceof \WC_Order ) {
			Claim::release( 'topup:' . $item->get_id() );
			return 0;
		}
		// M12-09: the V7 security hold of a new customer's order applies to top-ups too – booked when it is over.
		$hold    = Delivery::hold_until( $order, true );
		$pending = '' !== (string) $item->get_meta( self::PENDING_META );
		if ( ( $hold && $hold > time() ) || ( $pending && self::withdrawn( $order ) ) ) {
			Claim::release( 'topup:' . $item->get_id() );
			if ( $hold && $hold > time() ) {
				$this->wait_for_hold( $order, $item, $hold );
			}
			return 0;
		}
		$card = $this->card( (string) $item->get_meta( self::ITEM_META ) );
		if ( ! $card instanceof Voucher ) {
			$this->hold_line( $order, $item, is_string( $card ) ? $card : '' );
			return 0;
		}
		$config    = is_array( $config ) && $config ? $config + ProductConfig::DEFAULTS : ( $item->get_product() ? ProductConfig::get( $item->get_product() ) : ProductConfig::DEFAULTS );
		$treatment = Issuer::line_treatment( $item, $config );
		$mismatch  = self::treatment_mismatch( $card, $treatment );
		if ( '' !== $mismatch ) {
			$this->hold_line( $order, $item, $mismatch ); // M12-12: never booked onto a card with another VAT treatment.
			return 0;
		}
		list( $value, $in_base ) = self::line_value(
			$config,
			(int) $item->get_quantity(),
			(float) $item->get_subtotal(),
			(float) $item->get_subtotal_tax(),
			function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax(),
			(string) $item->get_meta( '_vcap_custom_amount' ),
			$treatment,
			(string) $item->get_meta( CustomAmount::CURRENCY_META ),
			(string) $order->get_currency()
		);
		$base = $in_base ? $value : Compat\MultiCurrency::to_base( $value, (string) $order->get_currency(), $order );
		if ( null === $base ) {
			// Never booked 1:1 from a foreign currency (Issuer holds such orders before it gets here).
			Claim::release( 'topup:' . $item->get_id() );
			/* translators: %s: order currency */
			$order->add_order_note( sprintf( __( 'Gift card top-up not booked: no exchange rate for %s is known. It is booked when the order is processed again with a known rate.', 'voucher-captain' ), $order->get_currency() ) );
			return 0;
		}
		$amount = round( $base, $card->decimals() );
		/** Value added to the card for a top-up line (default: its face value like a new gift card, in the base currency). */
		$amount = round( (float) apply_filters( 'vcap_topup_amount', $amount, $item, $card, $order ), $card->decimals() );
		if ( $amount <= 0 ) {
			$this->hold_line( $order, $item, __( 'The line has no value to add to the card.', 'voucher-captain' ) );
			return 0;
		}
		/* translators: %s: order number */
		if ( ! $this->coupons->adjust_balance( $card, $amount, 'topup', sprintf( __( 'Topped up with order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
			Claim::release( 'topup:' . $item->get_id() );
			$order->add_order_note( __( 'Gift card top-up could not be booked; it is tried again when the order is processed again.', 'voucher-captain' ) );
			Attention::add( $order->get_id(), 'topup:' . $item->get_id(), __( 'A gift card top-up could not be booked (database error). Process the order again (order action "Generate vouchers") or top up the card by hand.', 'voucher-captain' ) );
			return 0;
		}
		Attention::resolve( $order->get_id(), 'topup:' . $item->get_id() );
		$item->update_meta_data( self::DONE_META, $amount );
		$item->update_meta_data( self::CARD_META, (int) $card->id );
		$item->delete_meta_data( self::PENDING_META );
		$item->save_meta_data();
		if ( $pending && $order->get_total_refunded() > 0 ) {
			$this->reverse( $order->get_id(), false ); // Refunded during the hold: the refunded share is taken back.
		}
		$card = $this->repo->get( $card->id ) ?? $card;
		$this->extend_after_top_up( $card, $order, $item );
		/* translators: 1: amount, 2: masked card code */
		$order->add_order_note( sprintf( __( 'Gift card …%2$s topped up by %1$s.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ), Mask::code_tail( $card->code ) ) );
		/** Whether the customer gets an order note (email) confirming the top-up (default true). */
		if ( apply_filters( 'vcap_topup_customer_note', true, $card, $order, $item ) ) {
			$order->add_order_note( self::confirmation( $card, $amount ), 1 );
		}
		/** A gift card was topped up by an order (args: Voucher $card, float $amount, WC_Order $order, order item). */
		Db::action( 'vcap_gift_card_topped_up', $card, $amount, $order, $item );
		return 0;
	}

	/**
	 * M12-09: a top-up line of an order under the V7 security hold is booked when the hold is over (Action
	 * Scheduler AFTER_HOLD_HOOK), not at payment – otherwise a first order paid with a stolen card would put its
	 * value on a card that can be spent at once. Scheduled and noted once per line.
	 */
	private function wait_for_hold( \WC_Order $order, \WC_Order_Item_Product $item, int $hold ): void {
		$args = array(
			'order_id' => $order->get_id(),
			'item_id'  => $item->get_id(),
		);
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::AFTER_HOLD_HOOK, $args, Delivery::GROUP ) ) {
			as_schedule_single_action( $hold + MINUTE_IN_SECONDS, self::AFTER_HOLD_HOOK, $args, Delivery::GROUP );
		}
		if ( (int) $item->get_meta( self::PENDING_META ) === $hold ) {
			return;
		}
		$item->update_meta_data( self::PENDING_META, (string) $hold );
		$item->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: 1: masked gift card code, 2: date and time */
				__( 'Security hold: the top-up of gift card …%1$s is booked on %2$s, unless the order is cancelled or refunded before then.', 'voucher-captain' ),
				Mask::code_tail( (string) $item->get_meta( self::ITEM_META ) ),
				LocalDate::from_utc( gmdate( 'Y-m-d H:i:s', $hold ), vcap()->settings->date_format() . ' H:i' )
			)
		);
	}

	/** The order was cancelled, failed or refunded in full: a held top-up is never booked. */
	private static function withdrawn( \WC_Order $order ): bool {
		$total = (float) $order->get_total();
		return $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash', 'pending', 'checkout-draft' ) ) || ( $total > 0 && (float) $order->get_total_refunded() >= $total - 0.001 );
	}

	/**
	 * M12-09: the security hold of the order is over – books the held top-up line, after checking again that the
	 * order was not cancelled or refunded meanwhile (a partial refund takes its share back right after booking).
	 * An order that is not paid (any more) is booked when it is processed again.
	 *
	 * @param int|string $order_id Order id.
	 * @param int|string $item_id  Order item id of the top-up line.
	 */
	public function after_hold( $order_id, $item_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		$item  = $order instanceof \WC_Order ? $order->get_item( (int) $item_id ) : null;
		if ( ! $order instanceof \WC_Order || ! $item instanceof \WC_Order_Item_Product || $item->get_meta( self::DONE_META ) || $item->get_meta( self::HELD_META ) ) {
			return;
		}
		$hold = Delivery::hold_until( $order );
		if ( $hold && $hold > time() ) {
			$this->wait_for_hold( $order, $item, $hold ); // Hold moved (filter): wait again.
			return;
		}
		if ( self::withdrawn( $order ) ) {
			if ( '' !== (string) $item->get_meta( self::PENDING_META ) ) {
				$item->delete_meta_data( self::PENDING_META );
				$item->save_meta_data();
				$order->add_order_note( __( 'Gift card top-up not booked: the order was cancelled or refunded during the security hold.', 'voucher-captain' ) );
			}
			return;
		}
		$statuses = array_map( 'sanitize_key', (array) Settings::value( 'vcap_issue_statuses' ) );
		if ( ! $order->is_paid() && ! in_array( 'wc-' . $order->get_status(), $statuses, true ) && ! in_array( $order->get_status(), $statuses, true ) ) {
			return;
		}
		$this->top_up_instead( 1, $item );
	}

	/** Customer confirmation of a top-up: masked card, amount, new balance and validity. */
	public static function confirmation( Voucher $card, float $amount ): string {
		$money = static fn( float $v ): string => html_entity_decode( wp_strip_all_tags( wc_price( $v, array( 'currency' => $card->currency ) ) ), ENT_QUOTES, 'UTF-8' );
		$text  = sprintf(
			/* translators: 1: masked gift card code, 2: amount added, 3: new balance */
			__( 'Your gift card …%1$s was topped up by %2$s. New balance: %3$s.', 'voucher-captain' ),
			Mask::code_tail( $card->code ),
			$money( $amount ),
			$money( (float) $card->balance )
		);
		if ( $card->expires_at ) {
			/* translators: %s: expiry date */
			$text .= ' ' . sprintf( __( 'Valid until %s.', 'voucher-captain' ), wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) strtotime( $card->expires_at . ' UTC' ) ) );
		}
		return $text;
	}

	/**
	 * Value of a top-up line in the order currency – the rule of Issuer::voucher_amount() for a new gift card:
	 * a fixed product amount (already in the base currency), the amount the customer entered, or the line price
	 * before discounts (gross where shop prices include tax, net otherwise). Never what was paid after coupons.
	 * M12-12: in a tax-exclusive shop a top-up sold with VAT that is not a multi-purpose voucher ($treatment) is
	 * worth the gross amount paid, like a new card (T7, Issuer::sold_with_vat_value()).
	 *
	 * @param string $treatment VAT treatment of the top-up line (Issuer::line_treatment()).
	 * @return array{0: float, 1: bool} value, whether it is in the base currency already
	 */
	public static function line_value( array $config, int $qty, float $subtotal, float $subtotal_tax, bool $prices_include_tax, string $custom = '', string $treatment = 'payment', string $custom_currency = '', string $order_currency = '' ): array {
		$qty   = max( 1, $qty );
		$units = $qty * max( 1, (int) ( $config['per_item'] ?? 1 ) );
		if ( 'fixed' === ( $config['amount_source'] ?? '' ) ) {
			return array( (float) wc_format_decimal( (string) ( $config['amount'] ?? 0 ) ) * $units, true );
		}
		$paid = $subtotal + ( $prices_include_tax ? $subtotal_tax : 0.0 );
		if ( 'custom' === ( $config['amount_source'] ?? '' ) && '' !== $custom ) {
			// S4: never more than was paid, and only in the currency the amount was entered in (like a new gift card).
			$value = Issuer::custom_face_value( $custom, $custom_currency, $order_currency, $paid / $units, wc_get_price_decimals() )[0] * $units;
		} else {
			$value = $paid;
		}
		// A top-up always adds to a stored balance.
		return array( Issuer::sold_with_vat_value( $value, array_merge( $config, array( 'usage' => 'balance' ) ), $subtotal, $subtotal_tax, $treatment, $prices_include_tax ), false );
	}

	/**
	 * M12-12: a top-up must have the VAT treatment of the card it tops up: a multi-purpose top-up on a
	 * single-purpose card would never be taxed, the other way round taxed twice. '' when they match, else the
	 * message for the customer.
	 */
	public static function treatment_mismatch( Voucher $card, string $topup_treatment ): string {
		if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
			return ''; // No VAT in the shop (e.g. small business): the classification has no effect.
		}
		$card_treatment = ProductConfig::tax_treatment( $card );
		if ( $card_treatment === $topup_treatment ) {
			return '';
		}
		return __( 'This gift card cannot be topped up with this product: the card and the top-up are treated differently for VAT (multi-purpose or single-purpose voucher). Please choose the top-up product that belongs to your gift card, or buy a new gift card.', 'voucher-captain' );
	}

	/** VAT treatment of a top-up product: the treatment its gift card balance gets (Issuer::line_treatment()). */
	public static function product_treatment( \WC_Product $product ): string {
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		return Issuer::line_treatment( $item, ProductConfig::get( $product ) );
	}

	/** Ledger event: the validity of a gift card was extended (top-up, balance booked back). */
	public const EXTENDED_EVENT = 'validity_extended';

	/**
	 * After a top-up: the card's validity may be extended (filter "vcap_topup_expires_at": new expiry in UTC
	 * "Y-m-d H:i:s", default unchanged – rules differ by country, see LEGAL_TEXTS.md). Never shortened.
	 */
	private function extend_after_top_up( Voucher $card, \WC_Order $order, \WC_Order_Item_Product $item ): void {
		$until = apply_filters( 'vcap_topup_expires_at', $card->expires_at, $card, $order, $item );
		if ( is_string( $until ) && '' !== $until && $until !== $card->expires_at ) {
			/* translators: %s: order number */
			self::extend_validity( $this->repo, $card, $until, sprintf( __( 'Topped up with order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() );
		}
	}

	/**
	 * Extends a voucher's validity to $until (UTC "Y-m-d H:i:s"; never shortens it): column, status (expired →
	 * active), WooCommerce coupon expiry and a ledger entry.
	 */
	public static function extend_validity( VoucherRepository $repo, Voucher $voucher, string $until, string $note = '', int $order_id = 0 ): bool {
		$ends = strtotime( $until . ' UTC' );
		if ( ! $ends || ( $voucher->expires_at && $ends <= strtotime( $voucher->expires_at . ' UTC' ) ) ) {
			return false;
		}
		$from               = $voucher->status;
		$voucher->expires_at = gmdate( 'Y-m-d H:i:s', $ends );
		if ( Voucher::STATUS_EXPIRED === $voucher->status && $ends > time() ) {
			$voucher->status = Voucher::STATUS_ACTIVE;
		}
		if ( ! $repo->update_columns( $voucher, array( 'expires_at' ), $from ) ) {
			return false;
		}
		CouponManager::sync_coupon( $voucher, array( 'expiry' ) );
		$repo->log( $voucher->id, self::EXTENDED_EVENT, null, null, trim( $note . ' → ' . $voucher->expires_at ), $order_id );
		return true;
	}

	/** @var array<int, true> Orders to put on hold once their status transition is through. */
	private array $hold = array();

	/**
	 * A top-up line that could not be booked: held (not retried), the order goes on hold with a note, the customer
	 * is told by an order note (email), and the shop sees it in the admin notice and Site Health.
	 */
	private function hold_line( \WC_Order $order, \WC_Order_Item_Product $item, string $reason ): void {
		$item->update_meta_data( self::HELD_META, 'yes' );
		$item->save_meta_data();
		/* translators: %s: reason */
		$order->add_order_note( sprintf( __( 'Gift card top-up not booked: %s Please refund or top up by hand.', 'voucher-captain' ), $reason ) );
		$order->add_order_note( __( 'Your gift card could not be topped up. We will contact you and refund the amount or top up the card by hand.', 'voucher-captain' ), 1 );
		Attention::add(
			$order->get_id(),
			'topup:' . $item->get_id(),
			/* translators: %s: reason */
			sprintf( __( 'Gift card top-up not booked: %s Refund the line or top up the card by hand.', 'voucher-captain' ), $reason )
		);
		do_action( 'vcap_topup_held', $item, $reason );
		if ( function_exists( 'doing_action' ) && doing_action( 'woocommerce_order_status_' . $order->get_status() ) ) {
			$this->hold[ $order->get_id() ] = true; // Inside a status transition: change the status once it is through.
		} elseif ( ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded', 'failed' ) ) ) {
			$order->update_status( 'on-hold', __( 'A gift card top-up could not be booked.', 'voucher-captain' ) );
		}
	}

	/** After the status transition: an order with a held top-up line goes on hold. */
	public function hold_after_transition( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! isset( $this->hold[ (int) $order_id ] ) ) {
			return;
		}
		unset( $this->hold[ (int) $order_id ] );
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded', 'failed' ) ) ) {
			$order->update_status( 'on-hold', __( 'A gift card top-up could not be booked.', 'voucher-captain' ) );
		}
	}

	/**
	 * A top-up line without a card code (e.g. added to the cart around the product form) never issues a new card:
	 * the line is held (MISSING_META), the order gets a note once and the merchant books the top-up by hand.
	 */
	private function hold_without_code( \WC_Order_Item_Product $item ): int {
		if ( ! $item->get_meta( self::MISSING_META ) && Claim::take( 'topup-missing:' . $item->get_id() ) ) {
			$item->update_meta_data( self::MISSING_META, 'yes' );
			$item->save_meta_data();
			$order = $item->get_order();
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: reason */
						__( 'Gift card top-up not booked: %s Please refund or top up by hand.', 'voucher-captain' ),
						/* translators: %s: product name */
						sprintf( __( 'The line "%s" has no gift card code, so no new gift card was issued for it.', 'voucher-captain' ), wp_strip_all_tags( $item->get_name() ) )
					)
				);
			}
			/** A top-up line without card code was held (no card issued, no top-up booked). */
			do_action( 'vcap_topup_without_code', $item );
		}
		return 0;
	}

	/** Cancelled or fully refunded order: take the rest of each top-up back, as far as the balance is still there. */
	public function revoke( $order_id ): void {
		$this->reverse( $order_id, true );
	}

	/** Partial refund: take back the refunded share of each top-up line (refund_shares()). */
	public function reverse_refunded( $order_id, $refund_id = 0 ): void {
		$this->reverse( $order_id, false );
	}

	/**
	 * M12-21: takes back the share of the refunds saved so far (the refund being created included) before the
	 * gateway pays it out, so the card cannot be spent meanwhile. Returns what was booked, for undo_reversal()
	 * when the refund is not recorded after all.
	 *
	 * @return array<int, array{t:int, h:float, b:float}> top-up line, REVERSED_META before, amount taken back
	 */
	public function reverse_for_refund( \WC_Order $order ): array {
		return $this->reverse( $order->get_id(), false );
	}

	/**
	 * M12-21: books a reversal of reverse_for_refund() back (the refund was not recorded): the amount goes back
	 * onto the card as a top-up and the line's REVERSED_META is set back.
	 *
	 * @param array{t:int, h:float, b:float} $booking
	 */
	public function undo_reversal( \WC_Order $order, array $booking ): bool {
		$item = $order->get_item( (int) $booking['t'] );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return false;
		}
		$card = self::card_of_item( $item, $this->repo );
		if ( ! $card ) {
			return false;
		}
		if ( (float) $booking['b'] > 0 ) {
			/* translators: %s: order number */
			$note = sprintf( __( 'Top-up of order #%s booked again: the refund was not recorded', 'voucher-captain' ), $order->get_order_number() );
			if ( ! $this->coupons->adjust_balance( $card, (float) $booking['b'], 'topup', $note, $order->get_id() ) ) {
				return false;
			}
		}
		wc_update_order_item_meta( $item->get_id(), self::REVERSED_META, (float) $booking['h'] );
		return true;
	}

	/**
	 * M12-36/M12-37: what was taken back of a top-up line is more than the order's refunds cover now (a refund
	 * was deleted, or the cancelled order was paid again): the difference is booked onto the card again. Nothing
	 * happens while the order is cancelled, refunded or failed.
	 */
	public function rebook( \WC_Order $order, string $why ): void {
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}
		$shares = self::refund_shares( $order );
		foreach ( $order->get_items() as $item ) {
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			if ( $done <= 0 ) {
				continue;
			}
			$handled = (float) wc_get_order_item_meta( $item->get_id(), self::REVERSED_META, true );
			$card    = $handled > 0 ? self::card_of_item( $item, $this->repo ) : null;
			if ( ! $card ) {
				continue;
			}
			$target = min( $done, round( $done * max( 0.0, min( 1.0, (float) ( $shares[ $item->get_id() ] ?? 0.0 ) ) ), $card->decimals() ) );
			$back   = round( $handled - $target, $card->decimals() );
			if ( $back <= 0 ) {
				continue;
			}
			$booked = 0.0;
			/* translators: 1: order number, 2: why (e.g. "refund deleted") */
			if ( ! $this->coupons->adjust_balance( $card, $back, 'topup', sprintf( __( 'Top-up of order #%1$s booked again (%2$s)', 'voucher-captain' ), $order->get_order_number(), $why ), $order->get_id(), $booked ) ) {
				/* translators: %s: masked gift card code */
				Attention::add( $order->get_id(), 'topup-rebook:' . $item->get_id(), sprintf( __( 'The top-up of gift card %s that was taken back could not be booked again. Top up the card by hand.', 'voucher-captain' ), Mask::code( $card->code ) ) );
				continue;
			}
			wc_update_order_item_meta( $item->get_id(), self::REVERSED_META, $target );
			/* translators: 1: masked gift card code, 2: amount */
			$order->add_order_note( sprintf( __( 'Gift card %1$s: the top-up taken back before (%2$s) was booked onto the card again.', 'voucher-captain' ), Mask::code( $card->code ), wp_strip_all_tags( wc_price( $booked ) ) ) );
		}
	}

	/**
	 * Takes back what the refunds of the order cover of its top-up lines (all of it for a cancelled or fully
	 * refunded order). Each line keeps what was handled in REVERSED_META, so repeated refunds and status changes
	 * take back only the difference; the card gives back at most its balance.
	 *
	 * @return array<int, array{t:int, h:float, b:float}> what was booked (see reverse_for_refund())
	 */
	private function reverse( $order_id, bool $all ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}
		$shares   = $all ? array() : self::refund_shares( $order );
		$bookings = array();
		foreach ( $order->get_items() as $item ) {
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			if ( $done <= 0 || ( ! $all && empty( $shares[ $item->get_id() ] ) ) ) {
				continue;
			}
			$lock = 'topup-reverse-lock:' . $item->get_id();
			if ( ! Claim::take( $lock ) ) {
				continue; // Another request is taking this top-up back right now.
			}
			try {
				$booking = $this->reverse_line( $order, $item, $done, $all ? 1.0 : (float) $shares[ $item->get_id() ], $all );
				if ( $booking ) {
					$bookings[] = $booking;
				}
			} finally {
				Claim::release( $lock );
			}
		}
		return $bookings;
	}

	/** @return array{t:int, h:float, b:float}|null What was booked (null: nothing). */
	private function reverse_line( \WC_Order $order, \WC_Order_Item_Product $item, float $done, float $share, bool $all ): ?array {
		// Fresh from the database: the item object can be older than a reversal booked meanwhile (M12-21).
		$handled = (float) wc_get_order_item_meta( $item->get_id(), self::REVERSED_META, true );
		if ( $all && $handled <= 0 && ! Claim::take( 'topup-revoke:' . $item->get_id() ) ) {
			// Taken back completely by an earlier version (one claim per line, before REVERSED_META existed).
			$item->update_meta_data( self::REVERSED_META, $done );
			$item->save_meta_data();
			return null;
		}
		$card = self::card_of_item( $item, $this->repo );
		if ( ! $card ) {
			if ( $all && $handled <= 0 ) {
				Claim::release( 'topup-revoke:' . $item->get_id() );
			}
			$order->add_order_note( __( 'The gift card top-up could not be reversed: the gift card was not found. Please check the card balance.', 'voucher-captain' ) );
			Attention::add( $order->get_id(), 'topup-reverse:' . $item->get_id(), __( 'A gift card top-up could not be reversed because the gift card was not found. Check the card and take the top-up back by hand.', 'voucher-captain' ) );
			return null;
		}
		$target = min( $done, round( $done * max( 0.0, min( 1.0, $share ) ), $card->decimals() ) );
		$want   = round( $target - $handled, $card->decimals() );
		if ( $want <= 0 ) {
			return null;
		}
		$back   = min( $want, self::card_shares( $card, $this->repo )['topup_unspent'] );
		$booked = 0.0;
		/* translators: %s: order number */
		if ( $back > 0 && ! $this->coupons->adjust_balance( $card, -$back, 'topup_reversed', sprintf( __( 'Top-up of order #%s reversed', 'voucher-captain' ), $order->get_order_number() ), $order->get_id(), $booked ) ) {
			if ( $all && $handled <= 0 ) {
				Claim::release( 'topup-revoke:' . $item->get_id() );
			}
			$order->add_order_note( __( 'The gift card top-up could not be reversed. Please check the card balance.', 'voucher-captain' ) );
			return null;
		}
		// M12-60: the note reports what the locked booking really took (the balance may have dropped meanwhile).
		$back = abs( $booked );
		$item->update_meta_data( self::REVERSED_META, $target );
		$item->save_meta_data();
		$order->add_order_note(
			$back + 0.0001 < $want
				/* translators: 1: amount taken back, 2: amount already spent */
				? sprintf( __( 'Gift card top-up reversed: %1$s taken back, %2$s had already been spent.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ), wp_strip_all_tags( wc_price( $want - $back ) ) )
				/* translators: %s: amount */
				: sprintf( __( 'Gift card top-up reversed: %s taken back.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ) )
		);
		return array(
			't' => (int) $item->get_id(),
			'h' => $handled,
			'b' => $back,
		);
	}

	/**
	 * Share (0–1) of each booked top-up line and each line that issued vouchers (M12-07) that the order's refunds
	 * pay back. Refund lines count for their line (_refunded_item_id); an amount refunded without lines pays back the
	 * rest of the order first and only the part beyond it is spread over the top-up and voucher lines.
	 *
	 * @param int[] $voucher_lines Further lines holding vouchers of the order (lines issued before the line snapshot).
	 * @return array<int, float> order item id => refunded share
	 */
	public static function refund_shares( \WC_Order $order, array $voucher_lines = array() ): array {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && ( (float) $item->get_meta( self::DONE_META ) > 0 || Issuer::line_data( $item ) || in_array( (int) $item_id, $voucher_lines, true ) ) ) {
				$lines[ (int) $item_id ] = (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}
		if ( ! $lines ) {
			return array();
		}
		$by_line    = array();
		$attributed = 0.0;
		$refunded   = 0.0;
		// Straight from the refund documents: the order's cached refund list can be older than this refund.
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1 ) ) as $refund ) {
			if ( ! method_exists( $refund, 'get_amount' ) ) {
				continue;
			}
			$refunded += abs( (float) $refund->get_amount() );
			foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $refund_item ) {
				if ( ! method_exists( $refund_item, 'get_total' ) || ! method_exists( $refund_item, 'get_total_tax' ) ) {
					continue;
				}
				$amount      = abs( (float) $refund_item->get_total() ) + abs( (float) $refund_item->get_total_tax() );
				$attributed += $amount;
				$original    = (int) $refund_item->get_meta( '_refunded_item_id' );
				if ( isset( $lines[ $original ] ) ) {
					$by_line[ $original ] = ( $by_line[ $original ] ?? 0.0 ) + $amount;
				}
			}
		}
		$other_open = max( 0.0, (float) $order->get_total() - array_sum( $lines ) - ( $attributed - array_sum( $by_line ) ) );
		$excess     = max( 0.0, $refunded - $attributed - $other_open );
		$open       = array();
		foreach ( $lines as $item_id => $gross ) {
			$open[ $item_id ] = max( 0.0, $gross - ( $by_line[ $item_id ] ?? 0.0 ) );
		}
		$open_sum = array_sum( $open );
		$shares   = array();
		foreach ( $lines as $item_id => $gross ) {
			$extra              = $open_sum > 0 ? min( $open[ $item_id ], $excess * $open[ $item_id ] / $open_sum ) : 0.0;
			$shares[ $item_id ] = $gross > 0 ? min( 1.0, ( ( $by_line[ $item_id ] ?? 0.0 ) + $extra ) / $gross ) : 0.0;
		}
		return $shares;
	}

	/**
	 * Shares of a gift card's balance (all base currency). The purchased value is spent first, then the top-ups:
	 * a refund of the purchase can pay back the unspent purchased value, refunds of top-ups the rest of the balance,
	 * and both together never more than the balance. A voided card keeps its balance locked: there the purchased
	 * value still on the books is assumed to be on the card first (its refund may already have been paid without
	 * taking it off), so top-ups can only claim the balance above it.
	 *
	 * @param float $initial Purchased value (initial amount).
	 * @param float $removed Purchased value taken off by refunds (ledger "purchase_refunded").
	 * @param float $topups  Net top-ups (ledger "topup" − "topup_reversed").
	 * @param float $balance Current balance.
	 * @return array{purchase_spent: float, purchase_unspent: float, topup_unspent: float, spent: float}
	 */
	public static function shares( float $initial, float $removed, float $topups, float $balance, bool $void = false ): array {
		$purchase = max( 0.0, $initial - $removed );
		$topups   = max( 0.0, $topups );
		$balance  = max( 0.0, $balance );
		$spent    = max( 0.0, $purchase + $topups - $balance );
		$p_spent  = min( $purchase, $spent );
		if ( $void ) {
			$topup_unspent = min( $topups, max( 0.0, $balance - $purchase ) );
		} else {
			$topup_unspent = min( $topups, max( 0.0, $balance - ( $purchase - $p_spent ) ) );
		}
		return array(
			'purchase_spent'   => $p_spent,
			'purchase_unspent' => max( 0.0, min( $balance, $purchase - $p_spent ) ),
			'topup_unspent'    => $topup_unspent,
			'spent'            => $spent,
		);
	}

	/**
	 * shares() of a gift card from its ledger.
	 *
	 * @return array{purchase_spent: float, purchase_unspent: float, topup_unspent: float, spent: float}
	 */
	public static function card_shares( Voucher $card, VoucherRepository $repo ): array {
		$topups  = 0.0;
		$removed = 0.0;
		foreach ( $card->id ? $repo->events( $card->id ) : array() as $event ) {
			$amount = abs( (float) ( $event['amount'] ?? 0 ) );
			switch ( (string) ( $event['event'] ?? '' ) ) {
				case 'topup':
					$topups += $amount;
					break;
				case 'topup_reversed':
					$topups -= $amount;
					break;
				case Issuer::PURCHASE_REFUNDED_EVENT:
					$removed += $amount;
					break;
				case Issuer::PURCHASE_REFUND_REVERSED_EVENT:
					$removed -= $amount;
					break;
			}
		}
		return self::shares( (float) $card->initial_amount, $removed, $topups, (float) $card->balance, Voucher::STATUS_VOID === $card->status );
	}

	/**
	 * Top-up value of the order that a refund can no longer take back because it was spent from the card
	 * (base currency): per card, what is still booked from this order minus what the card's top-ups can still
	 * claim of its balance (card_shares()).
	 *
	 * @return array{0: float, 1: string[]} value, masked card codes
	 */
	public static function spent_value( \WC_Order $order, VoucherRepository $repo ): array {
		$open  = array(); // card id => open amount
		$cards = array();
		foreach ( $order->get_items() as $item ) {
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			$card = $done > 0 ? self::card_of_item( $item, $repo ) : null;
			if ( $card ) {
				$cards[ $card->id ] = $card;
				$open[ $card->id ]  = ( $open[ $card->id ] ?? 0.0 ) + max( 0.0, $done - (float) $item->get_meta( self::REVERSED_META ) );
			}
		}
		$spent = 0.0;
		$codes = array();
		foreach ( $open as $id => $amount ) {
			$used = max( 0.0, $amount - self::card_shares( $cards[ $id ], $repo )['topup_unspent'] );
			if ( $used > 0.0001 ) {
				$spent  += $used;
				$codes[] = Mask::code( $cards[ $id ]->code );
			}
		}
		return array( $spent, $codes );
	}

	/**
	 * Before a refund is saved: a refund that would take back more of a top-up than is left on the card would pay
	 * out value the customer already spent. Such refunds are refused with the maximum amount (filter
	 * "vcap_allow_refund_of_spent_top_ups" to allow them knowingly). The order-wide limit is in Issuer::guard_refund().
	 *
	 * @param \WC_Order_Refund $refund Refund about to be saved (already stored with its lines).
	 * @param array            $args   wc_create_refund() arguments.
	 * @throws \Exception To stop the refund (WooCommerce shows the message).
	 */
	public function guard_refund( $refund, $args ): void {
		$order = wc_get_order( (int) ( $args['order_id'] ?? ( $refund instanceof \WC_Order_Refund ? $refund->get_parent_id() : 0 ) ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$shares  = self::refund_shares( $order );
		$need    = array(); // card id => [ amount to take back (base currency), order currency per base unit ]
		$cards   = array();
		$unknown = array(); // masked codes of top-ups whose card cannot be found
		$lost    = 0.0;
		foreach ( $shares as $item_id => $share ) {
			$item = $order->get_item( $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$done = (float) $item->get_meta( self::DONE_META );
			if ( $done <= 0 ) {
				continue;
			}
			$want = max( 0.0, $done * $share - (float) $item->get_meta( self::REVERSED_META ) );
			$rate = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $done;
			$card = self::card_of_item( $item, $this->repo );
			if ( ! $card ) {
				// AP-Q1-3: the top-up cannot be taken back from a card nobody finds – the refund would pay it out.
				if ( $want > 0.0001 ) {
					$lost     += $want * $rate;
					$unknown[] = Mask::code( (string) $item->get_meta( self::ITEM_META ) );
				}
				continue;
			}
			$cards[ $card->id ]   = $card;
			$need[ $card->id ][0] = ( $need[ $card->id ][0] ?? 0.0 ) + $want;
			$need[ $card->id ][1] = $rate;
		}
		$over  = 0.0;
		$codes = array();
		foreach ( $need as $id => list( $want, $rate ) ) {
			$missing = $want - self::card_shares( $cards[ $id ], $this->repo )['topup_unspent'];
			if ( $missing > 0.5 / ( 10 ** $cards[ $id ]->decimals() ) ) {
				$over   += $missing * $rate;
				$codes[] = Mask::code( $cards[ $id ]->code );
			}
		}
		$amount = (float) ( $args['amount'] ?? 0 );
		if ( ( ! $codes && ! $unknown ) || apply_filters( 'vcap_allow_refund_of_spent_top_ups', false, $order, $amount, $over + $lost ) ) {
			return;
		}
		if ( $unknown ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: masked gift card codes */
					esc_html__( 'The gift card topped up by this order cannot be found (%s), so its top-up cannot be taken back. Check the card before refunding the top-up.', 'voucher-captain' ),
					esc_html( implode( ', ', array_unique( $unknown ) ) )
				)
			);
		}
		$max = max( 0.0, round( $amount - $over, wc_get_price_decimals() ) );
		throw new \Exception(
			sprintf(
				/* translators: 1: masked gift card codes, 2: maximum refund */
				esc_html__( 'The gift card top-up of this order was already spent from the card (%1$s). At most %2$s can be refunded now.', 'voucher-captain' ),
				esc_html( implode( ', ', $codes ) ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $max, array( 'currency' => $order->get_currency() ) ) ) ) )
			)
		);
	}
}
