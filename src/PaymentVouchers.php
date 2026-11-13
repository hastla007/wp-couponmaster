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
 * deduction, cancellation and refunds read the paid amount from that line (CouponManager::used_amount()). The cart
 * pays from its gross total (filter woocommerce_calculated_total), because WooCommerce taxes negative cart fees and
 * caps them at the net total; the order gets the payment line when it is created.
 * Single-purpose vouchers and promotions stay price reductions.
 */
final class PaymentVouchers {
	public const OPTION     = 'vcap_mpv_after_tax';
	public const FEE_META   = '_vcap_payment_code';
	public const AMOUNT_META = '_vcap_payment_amount';

	/** @var array<string, float> coupon code => amount paid after tax in the current cart calculation */
	private array $payments = array();

	public function __construct( private VoucherRepository $repo ) {}

	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'no_discount' ), 50, 5 );
		// After every tax, shipping and fee: WooCommerce would tax a negative fee and cap it at the net total.
		add_filter( 'woocommerce_calculated_total', array( $this, 'pay_from_total' ), 100, 2 );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'payment_rows' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'payment_rows' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'add_order_payments' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_order_payments' ), 20 );
		add_action( 'woocommerce_order_item_fee_after_calculate_taxes', array( $this, 'untaxed_payment_line' ) );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'restore_payment_lines' ), 10, 2 );
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

	/** The coupon itself lowers no line (the payment after tax does the work). */
	public function no_discount( $discount, $price = 0, $item = null, $single = false, $coupon = null ) {
		return $this->applies( $coupon ) ? 0.0 : $discount;
	}

	/**
	 * Pays from the gross cart total (after taxes, shipping and fees): each paying voucher at most what is still due.
	 *
	 * @param float|string $total Cart total.
	 */
	public function pay_from_total( $total, $cart = null ) {
		$this->payments = array();
		if ( ! self::enabled() || ! $cart instanceof \WC_Cart ) {
			return $total;
		}
		$due      = (float) $total;
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
			$this->payments[ wc_format_coupon_code( $code ) ] = $amount;
			$due = round( $due - $amount, $decimals );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'vcap_voucher_payments', $this->payments );
		}
		return max( 0.0, $due );
	}

	/** @return array<string, float> code => amount the vouchers pay in the current cart */
	public function cart_payments(): array {
		return $this->payments;
	}

	/** Classic cart and checkout: one row per paying voucher above the order total. */
	public function payment_rows(): void {
		foreach ( $this->payments as $code => $amount ) {
			/* translators: %s: voucher code */
			echo '<tr class="vcap-voucher-payment"><th>' . esc_html( sprintf( __( 'Voucher %s', 'voucher-captain' ), strtoupper( (string) $code ) ) ) . '</th><td data-title="' . esc_attr__( 'Voucher payment', 'voucher-captain' ) . '">' . wp_kses_post( wc_price( -$amount ) ) . '</td></tr>';
		}
	}

	/**
	 * The new order gets one non-taxable payment line per voucher ("Voucher CODE", negative, no taxes), so the
	 * order total, its lines and the voucher booking agree. Idempotent (Store API updates the same order).
	 */
	public function add_order_payments( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}
		$payments = $this->payments;
		if ( ! $payments && function_exists( 'WC' ) && WC()->session ) {
			$payments = (array) WC()->session->get( 'vcap_voucher_payments', array() );
		}
		foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
			if ( '' !== (string) $fee->get_meta( self::FEE_META ) ) {
				$order->remove_item( $item_id );
			}
		}
		if ( ! $payments ) {
			return;
		}
		foreach ( $payments as $code => $amount ) {
			$fee = new \WC_Order_Item_Fee();
			/* translators: %s: voucher code */
			$fee->set_name( sprintf( __( 'Voucher %s', 'voucher-captain' ), strtoupper( (string) $code ) ) );
			$fee->set_tax_status( 'none' );
			$fee->set_tax_class( '' );
			$fee->set_total( (string) -abs( (float) $amount ) );
			$fee->set_taxes( false );
			$fee->add_meta_data( self::FEE_META, wc_format_coupon_code( (string) $code ), true );
			$fee->add_meta_data( self::AMOUNT_META, (string) abs( (float) $amount ), true );
			$order->add_item( $fee );
		}
		$order->calculate_totals( false );
	}

	/** A recalculation never taxes a voucher payment line (WooCommerce spreads taxes over negative fees). */
	public function untaxed_payment_line( $item ): void {
		if ( $item instanceof \WC_Order_Item_Fee && '' !== (string) $item->get_meta( self::FEE_META ) ) {
			$item->set_taxes( false );
		}
	}

	/**
	 * WooCommerce caps negative fee lines at the net total when it recalculates an order, so a voucher that pays the
	 * whole gross amount would shrink to the net amount. The payment line keeps what was paid, the total follows.
	 */
	public function restore_payment_lines( $and_taxes, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$diff = 0.0;
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$paid = (string) $fee->get_meta( self::AMOUNT_META );
			if ( '' === (string) $fee->get_meta( self::FEE_META ) || '' === $paid ) {
				continue;
			}
			$want = -abs( (float) $paid );
			if ( abs( (float) $fee->get_total() - $want ) > 0.0001 ) {
				$diff += $want - (float) $fee->get_total();
				$fee->set_total( (string) $want );
				$fee->set_taxes( false );
			}
		}
		if ( 0.0 !== $diff ) {
			$order->set_total( (string) max( 0.0, round( (float) $order->get_total() + $diff, wc_get_price_decimals() ) ) );
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
