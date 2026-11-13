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
	 * Pays from the gross cart total (after taxes, shipping and fees): each paying voucher at most what is still due
	 * and at most the gross amount of what it may pay for (see payments_for()).
	 *
	 * Only the main cart (WC()->cart) pays and keeps its payments: other cart instances – WooCommerce Subscriptions
	 * calculates its recurring carts as clones of the main cart right after it – keep their total and never
	 * overwrite the main cart's payments. A voucher pays the order it is applied to, never a renewal.
	 *
	 * @param float|string $total Cart total.
	 */
	public function pay_from_total( $total, $cart = null ) {
		if ( ! self::enabled() || ! $cart instanceof \WC_Cart || ! self::is_main_cart( $cart ) ) {
			return $total;
		}
		$this->payments = $this->payments_for( $cart, (float) $total );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'vcap_voucher_payments', $this->payments );
		}
		return max( 0.0, round( (float) $total - array_sum( $this->payments ), wc_get_price_decimals() ) );
	}

	/** The cart whose payments count: WC()->cart, or the given cart where WooCommerce has none (CLI, cron). */
	private static function is_main_cart( \WC_Cart $cart ): bool {
		return ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart || WC()->cart === $cart;
	}

	/**
	 * What each paying voucher of this cart pays from the gross amount due.
	 *
	 * A voucher pays only the gross amount (line total + its tax, after other coupons) of the lines it may apply to:
	 * the lines WooCommerce would apply the coupon to (filter woocommerce_coupon_get_items_to_apply, e.g. "vouchers
	 * do not buy vouchers or top-ups" and the SKU rules) that also pass the coupon's own product, category, exclusion
	 * and sale restrictions (WC_Coupon::is_valid_for_product(), which WooCommerce skips for cart-wide coupons).
	 * Shipping and fees are paid only by a voucher that is not limited to certain products or categories (filter
	 * vcap_voucher_pays_shipping_and_fees). Several vouchers never pay the same amount twice.
	 *
	 * @return array<string, float> code => amount
	 */
	public function payments_for( \WC_Cart $cart, float $due ): array {
		$decimals = wc_get_price_decimals();
		$payments = array();
		$open     = null;
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( ! $this->applies( $coupon ) ) {
				continue;
			}
			$open    ??= self::open_amounts( $cart );
			$eligible  = $this->eligible_parts( $coupon, $cart, array_keys( $open ) );
			$covered   = array_sum( array_intersect_key( $open, array_flip( $eligible ) ) );
			$amount    = round( min( max( 0.0, $due ), (float) $coupon->get_amount(), $covered ), $decimals );
			if ( $amount <= 0 ) {
				continue;
			}
			$left = $amount;
			foreach ( $eligible as $part ) {
				$take          = min( $left, $open[ $part ] );
				$open[ $part ] = $open[ $part ] - $take;
				$left         -= $take;
				if ( $left <= 0 ) {
					break;
				}
			}
			$payments[ wc_format_coupon_code( $code ) ] = $amount;
			$due = round( $due - $amount, $decimals );
		}
		return $payments;
	}

	/** @return array<string, float> part ("item:KEY", "shipping", "fee:ID") => gross amount still open in the cart */
	private static function open_amounts( \WC_Cart $cart ): array {
		$open = array();
		foreach ( $cart->get_cart() as $key => $values ) {
			$open[ 'item:' . $key ] = max( 0.0, (float) ( $values['line_total'] ?? 0 ) + (float) ( $values['line_tax'] ?? 0 ) );
		}
		$open['shipping'] = max( 0.0, (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() );
		foreach ( $cart->get_fees() as $fee ) {
			$open[ 'fee:' . $fee->id ] = max( 0.0, (float) $fee->total + (float) $fee->tax );
		}
		return $open;
	}

	/**
	 * @param string[] $parts All parts of the cart (open_amounts() keys).
	 * @return string[] The parts this voucher may pay, in cart order.
	 */
	private function eligible_parts( \WC_Coupon $coupon, \WC_Cart $cart, array $parts ): array {
		$discounts = new \WC_Discounts( $cart );
		$items     = apply_filters( 'woocommerce_coupon_get_items_to_apply', $discounts->get_items_to_validate(), $coupon, $discounts );
		// WooCommerce checks product restrictions only for product coupon types: ask the same question as one.
		$probe = clone $coupon;
		$probe->set_discount_type( 'fixed_product' );
		$allowed = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( isset( $item->key, $item->product ) && $item->product instanceof \WC_Product && $probe->is_valid_for_product( $item->product, $item->object ?? array() ) ) {
				$allowed[ 'item:' . $item->key ] = true;
			}
		}
		$restricted = $coupon->get_product_ids() || $coupon->get_product_categories();
		/** Whether a voucher paying after tax may also pay shipping and fees (default: when it is not limited to products or categories). */
		$extras = (bool) apply_filters( 'vcap_voucher_pays_shipping_and_fees', ! $restricted, $coupon, $cart );
		return array_values(
			array_filter(
				$parts,
				static fn( string $part ) => isset( $allowed[ $part ] ) || ( $extras && ! str_starts_with( $part, 'item:' ) )
			)
		);
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
	 *
	 * The payments are calculated again from the main cart the order is created from (never taken over from the
	 * session or from another cart calculation). The order is not saved here: the lines are added and the total
	 * is set from them, the checkout saves the order afterwards (classic and Store API).
	 */
	public function add_order_payments( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}
		$removed = false;
		foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
			if ( '' !== (string) $fee->get_meta( self::FEE_META ) ) {
				$order->remove_item( $item_id );
				$removed = true;
			}
		}
		$cart = function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart && ! WC()->cart->is_empty() ? WC()->cart : null;
		if ( $cart ) {
			$gross    = self::cart_gross( $cart );
			$payments = $this->payments_for( $cart, $gross );
		} else {
			$gross    = self::order_gross( $order );
			$payments = $this->payments;
			if ( ! $payments && function_exists( 'WC' ) && WC()->session ) {
				$payments = (array) WC()->session->get( 'vcap_voucher_payments', array() );
			}
		}
		// Only vouchers that are on this order pay for it.
		$payments = array_intersect_key( $payments, array_flip( array_map( 'wc_format_coupon_code', $order->get_coupon_codes() ) ) );
		if ( ! $payments && ! $removed ) {
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
		$order->set_total( (string) max( 0.0, round( $gross - array_sum( array_map( 'floatval', $payments ) ), wc_get_price_decimals() ) ) );
	}

	/** Gross cart total before voucher payments (what WooCommerce passes to woocommerce_calculated_total). */
	private static function cart_gross( \WC_Cart $cart ): float {
		return round( (float) $cart->get_cart_contents_total() + (float) $cart->get_fee_total() + (float) $cart->get_shipping_total() + (float) $cart->get_total_tax(), wc_get_price_decimals() );
	}

	/** Gross order total from its lines, without voucher payment lines (as WC_Order::calculate_totals() adds it up). */
	private static function order_gross( \WC_Order $order ): float {
		$gross = 0.0;
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Fee && '' !== (string) $item->get_meta( self::FEE_META ) ) {
				continue;
			}
			$gross += (float) $item->get_total() + (float) $item->get_total_tax();
		}
		return round( $gross, wc_get_price_decimals() );
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
