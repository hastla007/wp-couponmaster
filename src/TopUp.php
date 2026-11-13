<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Top-up of existing gift cards: a gift card product marked "Top up an existing gift card" asks for the card's
 * code instead of issuing a new card. When the order is paid (the moment new vouchers would be issued), the paid
 * value is added to that card's balance – once per order line. A cancelled or fully refunded order takes the
 * top-up back as far as the balance still allows.
 */
final class TopUp {
	public const PRODUCT_META = '_vcap_topup';
	public const ITEM_META    = '_vcap_topup_code';
	public const DONE_META    = '_vcap_topup_done';
	private const FIELD       = 'vcap_topup_code';

	public function __construct( private VoucherRepository $repo, private CouponManager $coupons ) {}

	public function register(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_field' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'code_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item_meta' ), 10, 3 );
		add_filter( 'vcap_vouchers_per_item', array( $this, 'top_up_instead' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke' ), 20 );
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

	public function validate( $passed, $product_id ) {
		if ( ! $passed || ! self::is_topup( wc_get_product( (int) $product_id ) ) ) {
			return $passed;
		}
		if ( Plugin::rate_limited( 'topup', 15, 300, 15 * MINUTE_IN_SECONDS, false ) ) {
			wc_add_notice( __( 'Too many attempts. Please try again later.', 'voucher-captain' ), 'error' );
			return false;
		}
		$card = $this->card( isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce add-to-cart; sanitized in card().
		if ( ! $card instanceof Voucher ) {
			Plugin::rate_limit_failure( 'topup', 15 * MINUTE_IN_SECONDS );
			wc_add_notice( $card, 'error' );
			return false;
		}
		Plugin::rate_limit_success( 'topup', 15 * MINUTE_IN_SECONDS );
		return $passed;
	}

	public function cart_item_data( $data, $product_id ) {
		if ( self::is_topup( wc_get_product( (int) $product_id ) ) && isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart.
			$data[ self::FIELD ] = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $data;
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
		if ( ! $item instanceof \WC_Order_Item_Product || '' === (string) $item->get_meta( self::ITEM_META ) ) {
			return $count;
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

	/** Cancelled or refunded order: take the top-up back, as far as the balance is still there. */
	public function revoke( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$done = $item instanceof \WC_Order_Item_Product ? (float) $item->get_meta( self::DONE_META ) : 0.0;
			if ( $done <= 0 || ! Claim::take( 'topup-revoke:' . $item->get_id() ) ) {
				continue;
			}
			$card = $this->repo->get_by_code( (string) $item->get_meta( self::ITEM_META ) );
			if ( ! $card ) {
				continue;
			}
			$back = min( $done, max( 0.0, (float) $card->balance ) );
			/* translators: %s: order number */
			if ( $back > 0 && ! $this->coupons->adjust_balance( $card, -$back, 'topup_reversed', sprintf( __( 'Top-up of order #%s reversed', 'voucher-captain' ), $order->get_order_number() ), $order->get_id() ) ) {
				Claim::release( 'topup-revoke:' . $item->get_id() );
				$order->add_order_note( __( 'The gift card top-up could not be reversed. Please check the card balance.', 'voucher-captain' ) );
				continue;
			}
			$order->add_order_note(
				$back + 0.0001 < $done
					/* translators: 1: amount taken back, 2: amount already spent */
					? sprintf( __( 'Gift card top-up reversed: %1$s taken back, %2$s had already been spent.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ), wp_strip_all_tags( wc_price( $done - $back ) ) )
					/* translators: %s: amount */
					: sprintf( __( 'Gift card top-up reversed: %s taken back.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $back ) ) )
			);
		}
	}
}
