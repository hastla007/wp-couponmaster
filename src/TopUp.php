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
	public const DONE_META     = '_vcap_topup_done';
	/** Top-up value of the line already taken back by refunds or cancellation (base currency). */
	public const REVERSED_META = '_vcap_topup_reversed';
	/** A top-up line without card code that was held instead of issuing a new card. */
	public const MISSING_META  = '_vcap_topup_missing';
	private const FIELD        = 'vcap_topup_code';

	public function __construct( private VoucherRepository $repo, private CouponManager $coupons ) {}

	public function register(): void {
		$this->register_lifecycle();
		$this->register_features();
	}

	/** New sales: product setting, code field, add to cart (only with the premium right "topup"). */
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

	/** Without the right: top-up products are not sold (they would otherwise issue new gift cards). */
	public function register_locked(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'not_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'not_purchasable' ), 20, 2 );
	}

	public function not_purchasable( $purchasable, $product ) {
		return $purchasable && self::is_topup( $product ) ? false : $purchasable;
	}

	/**
	 * Top-ups already in carts and orders: always registered, also while the premium right is locked – the paid
	 * value is booked onto the card (never a new card), cancellations and refunds take it back.
	 */
	public function register_lifecycle(): void {
		// A top-up line without a card code can never be ordered (it would issue a new card).
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item_meta' ), 10, 3 );
		add_filter( 'vcap_vouchers_per_item', array( $this, 'top_up_instead' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke' ), 20 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'reverse_refunded' ), 20, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'guard_refund' ), 11, 2 );
		add_filter( 'vcap_event_labels', static fn( array $l ) => $l + array( 'topup' => __( 'Topped up', 'voucher-captain' ), 'topup_reversed' => __( 'Top-up reversed', 'voucher-captain' ) ) );
		add_filter( 'vcap_ledger_money_events', static fn( array $e ) => array_merge( $e, array( 'topup', 'topup_reversed' ) ) );
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
		echo '<p class="form-row vcap-topup-field"><label for="vcap-topup-code">' . esc_html__( 'Gift card code to top up', 'voucher-captain' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr></label>';
		echo '<input type="text" class="input-text" id="vcap-topup-code" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( $value ) . '" required autocomplete="off" maxlength="100" /></p>';
	}

	/** @return Voucher|string The gift card that can be topped up, or why not. */
	public function card( string $raw ) {
		$code = wc_format_coupon_code( sanitize_text_field( $raw ) );
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
	private function check( string $raw ) {
		$memo = null !== $this->override ? wc_format_coupon_code( sanitize_text_field( $raw ) ) : null;
		if ( null !== $memo && isset( $this->checked[ $memo ] ) ) {
			return $this->checked[ $memo ]; // Store API runs the legacy and its own validation for the same request.
		}
		if ( '' === trim( sanitize_text_field( $raw ) ) ) {
			$result = __( 'Please enter the code of the gift card to top up.', 'voucher-captain' ); // Not a guess: no attempt counted.
		} elseif ( Plugin::rate_limited( 'topup', 15, 300, 15 * MINUTE_IN_SECONDS, false ) ) {
			$result = __( 'Too many attempts. Please try again later.', 'voucher-captain' );
		} else {
			$card   = $this->card( $raw );
			$result = $card instanceof Voucher ? true : $card;
			if ( true !== $result ) {
				Plugin::rate_limit_failure( 'topup', 15 * MINUTE_IN_SECONDS );
			}
		}
		if ( null !== $memo ) {
			$this->checked[ $memo ] = $result;
		}
		return $result;
	}

	public function validate( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed || ! self::is_topup( wc_get_product( (int) ( $variation_id ?: $product_id ) ) ) ) {
			return $passed;
		}
		$result = $this->check( $this->posted_code( $cart_item_data ) );
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
			$data['cart_item_data'][ self::FIELD ] = wc_format_coupon_code( $this->override );
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
		$result = $this->check( $this->posted_code( is_array( $request ) ? ( $request['cart_item_data'] ?? array() ) : array() ) );
		if ( true !== $result && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_invalid_topup_code', esc_html( $result ), 400 );
		}
	}

	public function cart_item_data( $data, $product_id ) {
		if ( self::is_topup( wc_get_product( (int) $product_id ) ) && isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart.
			$data[ self::FIELD ] = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

	/** Cart and checkout show the card only masked (the code is a means of payment). */
	public function item_data( $data, $cart_item ) {
		if ( ! empty( $cart_item[ self::FIELD ] ) ) {
			$code   = strtoupper( (string) $cart_item[ self::FIELD ] );
			$data[] = array(
				'key'   => __( 'Tops up gift card', 'voucher-captain' ),
				'value' => '…' . substr( $code, -4 ),
			);
		}
		return $data;
	}

	public function order_item_meta( $item, $cart_item_key, $values ): void {
		if ( ! empty( $values[ self::FIELD ] ) && $item instanceof \WC_Order_Item_Product ) {
			$item->add_meta_data( self::ITEM_META, (string) $values[ self::FIELD ], true );
		}
	}

	/* ---- Order lifecycle --------------------------------------------------------------------------- */

	/**
	 * Called where the order's vouchers are issued: a top-up line issues none and adds its paid value to the
	 * card instead (once per line).
	 */
	public function top_up_instead( $count, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $count;
		}
		if ( '' === (string) $item->get_meta( self::ITEM_META ) ) {
			return self::is_topup( $item->get_product() ) ? $this->hold_without_code( $item ) : $count;
		}
		if ( $item->get_meta( self::DONE_META ) || ! Claim::take( 'topup:' . $item->get_id() ) ) {
			return 0;
		}
		$order = $item->get_order();
		$card  = $this->card( (string) $item->get_meta( self::ITEM_META ) );
		if ( ! $order instanceof \WC_Order || ! $card instanceof Voucher ) {
			if ( $order instanceof \WC_Order ) {
				/* translators: %s: reason */
				$order->add_order_note( sprintf( __( 'Gift card top-up not booked: %s Please refund or top up by hand.', 'voucher-captain' ), is_string( $card ) ? $card : '' ) );
			}
			return 0;
		}
		$paid   = (float) $item->get_total() + (float) $item->get_total_tax();
		$amount = round( (float) apply_filters( 'vcap_amount_to_base_currency', $paid, $order->get_currency(), $order ), $card->decimals() );
		/** Value added to the card for a top-up line (default: what was paid, converted to the base currency). */
		$amount = (float) apply_filters( 'vcap_topup_amount', $amount, $item, $card, $order );
		/* translators: %s: order number */
		if ( $amount <= 0 || ! $this->coupons->adjust_balance( $card, $amount, 'topup', sprintf( __( 'Topped up with order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
			Claim::release( 'topup:' . $item->get_id() );
			$order->add_order_note( __( 'Gift card top-up could not be booked; it is tried again when the order is processed again.', 'voucher-captain' ) );
			return 0;
		}
		$item->update_meta_data( self::DONE_META, $amount );
		$item->save_meta_data();
		/* translators: 1: amount, 2: masked card code */
		$order->add_order_note( sprintf( __( 'Gift card …%2$s topped up by %1$s.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ), substr( strtoupper( $card->code ), -4 ) ) );
		do_action( 'vcap_gift_card_topped_up', $card, $amount, $order, $item );
		return 0;
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
	 * Takes back what the refunds of the order cover of its top-up lines (all of it for a cancelled or fully
	 * refunded order). Each line keeps what was handled in REVERSED_META, so repeated refunds and status changes
	 * take back only the difference; the card gives back at most its balance.
	 */
	private function reverse( $order_id, bool $all ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$shares = $all ? array() : self::refund_shares( $order );
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
				$this->reverse_line( $order, $item, $done, $all ? 1.0 : (float) $shares[ $item->get_id() ], $all );
			} finally {
				Claim::release( $lock );
			}
		}
	}

	private function reverse_line( \WC_Order $order, \WC_Order_Item_Product $item, float $done, float $share, bool $all ): void {
		$handled = (float) wc_get_order_item_meta( $item->get_id(), self::REVERSED_META, true );
		if ( $all && $handled <= 0 && ! Claim::take( 'topup-revoke:' . $item->get_id() ) ) {
			// Taken back completely by an earlier version (one claim per line, before REVERSED_META existed).
			$item->update_meta_data( self::REVERSED_META, $done );
			$item->save_meta_data();
			return;
		}
		$card = $this->repo->get_by_code( (string) $item->get_meta( self::ITEM_META ) );
		if ( ! $card ) {
			return;
		}
		$target = min( $done, round( $done * max( 0.0, min( 1.0, $share ) ), $card->decimals() ) );
		$want   = round( $target - $handled, $card->decimals() );
		if ( $want <= 0 ) {
			return;
		}
		$back = min( $want, max( 0.0, (float) $card->balance ) );
		/* translators: %s: order number */
		if ( $back > 0 && ! $this->coupons->adjust_balance( $card, -$back, 'topup_reversed', sprintf( __( 'Top-up of order #%s reversed', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
			if ( $all && $handled <= 0 ) {
				Claim::release( 'topup-revoke:' . $item->get_id() );
			}
			$order->add_order_note( __( 'The gift card top-up could not be reversed. Please check the card balance.', 'voucher-captain' ) );
			return;
		}
		$item->update_meta_data( self::REVERSED_META, $target );
		$item->save_meta_data();
		$order->add_order_note(
			$back + 0.0001 < $want
				/* translators: 1: amount taken back, 2: amount already spent */
				? sprintf( __( 'Gift card top-up reversed: %1$s taken back, %2$s had already been spent.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ), wp_strip_all_tags( wc_price( $want - $back ) ) )
				/* translators: %s: amount */
				: sprintf( __( 'Gift card top-up reversed: %s taken back.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ) )
		);
	}

	/**
	 * Share (0–1) of each booked top-up line that the order's refunds pay back. Refund lines count for their line
	 * (_refunded_item_id); an amount refunded without lines pays back the rest of the order first and only the part
	 * beyond it is spread over the top-up lines.
	 *
	 * @return array<int, float> order item id => refunded share
	 */
	public static function refund_shares( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (float) $item->get_meta( self::DONE_META ) > 0 ) {
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
			$refunded += abs( (float) $refund->get_amount() );
			foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $refund_item ) {
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
	 * Top-up value of the order that a refund can no longer take back because it was spent from the card
	 * (base currency): per card, what is still booked from this order minus the card's balance.
	 *
	 * @return array{0: float, 1: string[]} value, masked card codes
	 */
	public static function spent_value( \WC_Order $order, VoucherRepository $repo ): array {
		$open = array();
		foreach ( $order->get_items() as $item ) {
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			if ( $done > 0 ) {
				$code          = (string) $item->get_meta( self::ITEM_META );
				$open[ $code ] = ( $open[ $code ] ?? 0.0 ) + max( 0.0, $done - (float) $item->get_meta( self::REVERSED_META ) );
			}
		}
		$spent = 0.0;
		$codes = array();
		foreach ( $open as $code => $amount ) {
			$card = $repo->get_by_code( $code );
			$used = $card ? max( 0.0, $amount - max( 0.0, (float) $card->balance ) ) : 0.0;
			if ( $used > 0.0001 ) {
				$spent  += $used;
				$codes[] = '…' . substr( strtoupper( $code ), -4 );
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
		$shares = self::refund_shares( $order );
		$need   = array(); // card code => [ amount to take back (base currency), order currency per base unit ]
		foreach ( $shares as $item_id => $share ) {
			$item = $order->get_item( $item_id );
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			if ( $done <= 0 ) {
				continue;
			}
			$code             = (string) $item->get_meta( self::ITEM_META );
			$want             = max( 0.0, $done * $share - (float) $item->get_meta( self::REVERSED_META ) );
			$need[ $code ][0] = ( $need[ $code ][0] ?? 0.0 ) + $want;
			$need[ $code ][1] = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $done;
		}
		$over  = 0.0;
		$codes = array();
		foreach ( $need as $code => list( $want, $rate ) ) {
			$card    = $this->repo->get_by_code( $code );
			$missing = $card ? $want - max( 0.0, (float) $card->balance ) : 0.0;
			if ( $card && $missing > 0.5 / ( 10 ** $card->decimals() ) ) {
				$over   += $missing * $rate;
				$codes[] = '…' . substr( strtoupper( $code ), -4 );
			}
		}
		$amount = (float) ( $args['amount'] ?? 0 );
		if ( ! $codes || apply_filters( 'vcap_allow_refund_of_spent_top_ups', false, $order, $amount, $over ) ) {
			return;
		}
		$max = max( 0.0, round( $amount - $over, wc_get_price_decimals() ) );
		throw new \Exception(
			sprintf(
				/* translators: 1: masked gift card codes, 2: maximum refund */
				__( 'The gift card top-up of this order was already spent from the card (%1$s). At most %2$s can be refunded now.', 'voucher-captain' ),
				implode( ', ', $codes ),
				html_entity_decode( wp_strip_all_tags( wc_price( $max, array( 'currency' => $order->get_currency() ) ) ) )
			)
		);
	}
}
