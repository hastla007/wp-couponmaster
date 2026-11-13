<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-purpose vouchers as a payment after tax (setting "Multi-purpose vouchers as payment", option
 * vcap_mpv_after_tax, off by default; TAX.md 4.1).
 *
 * A multi-purpose voucher is a means of payment: the VAT is due on the full price of the goods. A WooCommerce coupon
 * lowers the price and with it the VAT, so with the setting on such a voucher stays applied as a coupon (code
 * entry, validation, usage limits) but discounts nothing; instead it pays with a negative, non-taxable fee line
 * "Voucher CODE" after all taxes – the order keeps the full VAT and the customer pays the rest. Reservation,
 * deduction, cancellation and refunds read the paid amount from that line (CouponManager::used_amount()).
 * Single-purpose vouchers and promotions stay price reductions.
 */
final class PaymentVouchers {
	public const OPTION     = 'vcap_mpv_after_tax';
	public const FEE_PREFIX = 'vcap-pay-';
	public const FEE_META   = '_vcap_payment_code';

	/** @var array<string, string> fee id => coupon code of this request */
	private array $fee_codes = array();

	public function __construct( private VoucherRepository $repo ) {}

	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'no_discount' ), 50, 5 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_payments' ), 100 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee_item' ), 10, 4 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 30, 2 );
	}

	/** Is this coupon a voucher that pays after tax (setting on, fixed amount, VAT treatment "payment")? */
	public function applies( $coupon ): bool {
		if ( ! self::enabled() || ! $coupon instanceof \WC_Coupon || 'fixed_cart' !== $coupon->get_discount_type() ) {
			return false;
		}
		$voucher = $this->repo->get_by_code( wc_format_coupon_code( $coupon->get_code() ) );
		$applies = $voucher && ! $voucher->is_ticket() && 'payment' === ProductConfig::tax_treatment( $voucher );
		return (bool) apply_filters( 'vcap_voucher_pays_after_tax', $applies, $coupon, $voucher );
	}

	/** The coupon itself lowers no line (the payment line does the work). */
	public function no_discount( $discount, $price = 0, $item = null, $single = false, $coupon = null ) {
		return $this->applies( $coupon ) ? 0.0 : $discount;
	}

	/** Negative, non-taxable fee per paying voucher, at most what is still due after taxes, shipping and other fees. */
	public function add_payments( $cart ): void {
		if ( ! self::enabled() || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$due = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( 0 !== strpos( (string) $fee->id, self::FEE_PREFIX ) ) {
				$due += (float) $fee->amount;
			}
		}
		$decimals = wc_get_price_decimals();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( ! $this->applies( $coupon ) ) {
				continue;
			}
			$amount = round( min( max( 0.0, $due ), (float) $coupon->get_amount() ), $decimals );
			if ( $amount <= 0 ) {
				continue;
			}
			$id                     = self::FEE_PREFIX . md5( wc_format_coupon_code( $code ) );
			$this->fee_codes[ $id ] = wc_format_coupon_code( $code );
			$cart->fees_api()->add_fee(
				array(
					'id'      => $id,
					/* translators: %s: voucher code */
					'name'    => sprintf( __( 'Voucher %s', 'voucher-captain' ), strtoupper( (string) $code ) ),
					'amount'  => -$amount,
					'taxable' => false,
				)
			);
			$due -= $amount;
		}
	}

	/** Marks the order fee line with the voucher code it paid with. */
	public function tag_fee_item( $item, $fee_key, $fee, $order ): void {
		$id = is_object( $fee ) ? (string) ( $fee->id ?? '' ) : '';
		if ( $item instanceof \WC_Order_Item_Fee && isset( $this->fee_codes[ $id ] ) ) {
			$item->add_meta_data( self::FEE_META, $this->fee_codes[ $id ], true );
		}
	}

	/** Cart totals: the coupon row says that the voucher pays below instead of showing a 0 discount. */
	public function coupon_html( $html, $coupon ) {
		if ( ! $this->applies( $coupon ) ) {
			return $html;
		}
		$remove = ( false !== strpos( (string) $html, 'woocommerce-remove-coupon' ) && preg_match( '#<a [^>]*woocommerce-remove-coupon.*?</a>#s', (string) $html, $m ) ) ? ' ' . $m[0] : '';
		return esc_html__( 'pays as voucher payment (see below)', 'voucher-captain' ) . $remove;
	}

	/** Amount an order paid with the voucher of this code after tax (its fee line), null when it paid as a coupon. */
	public static function paid_amount( \WC_Order $order, string $code ): ?float {
		$code = wc_format_coupon_code( $code );
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $code === (string) $fee->get_meta( self::FEE_META ) ) {
				return abs( (float) $fee->get_total() );
			}
		}
		return null;
	}
}
