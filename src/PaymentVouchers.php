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
	/**
	 * M12-30: order meta code => amount paid after tax, for exports and accounting (DATEV, invoices, analytics):
	 * the payment lines are negative fees, which those tools would book as a 0 % revenue reduction. Kept in step
	 * with the payment lines (order_payments(), filter "vcap_order_voucher_payments", REST field
	 * "vcap_voucher_payments"; TAX.md "Kontierung").
	 */
	public const PAYMENTS_META = '_vcap_voucher_payments';

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
		// A voucher added to an existing order (admin "Apply coupon", WC_Order::apply_coupon()) pays after tax too.
		add_action( 'woocommerce_order_applied_coupon', array( $this, 'add_applied_payment' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 30, 2 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( self::class, 'rest_order_payments' ), 10, 2 );
	}

	/**
	 * M12-30: the voucher payments of an order (code => amount in the order currency): the stored meta, else read
	 * from the payment lines. Filter "vcap_order_voucher_payments" ( $payments, $order ) for exports.
	 *
	 * @return array<string, float>
	 */
	public static function order_payments( \WC_Order $order ): array {
		$payments = self::payments_of_lines( $order );
		/** Voucher payments after tax of an order (code => amount, order currency), e.g. for an accounting export. */
		return (array) apply_filters( 'vcap_order_voucher_payments', $payments, $order );
	}

	/** @return array<string, float> code => amount of the payment lines of the order */
	private static function payments_of_lines( \WC_Order $order ): array {
		$payments = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$code = $fee instanceof \WC_Order_Item_Fee ? (string) $fee->get_meta( self::FEE_META ) : '';
			if ( '' !== $code ) {
				$payments[ $code ] = round( ( $payments[ $code ] ?? 0.0 ) + abs( (float) $fee->get_total() ), wc_get_price_decimals() );
			}
		}
		return $payments;
	}

	/** Keeps the order meta PAYMENTS_META in step with the payment lines (saved with the order). */
	public static function sync_payments_meta( \WC_Order $order ): void {
		$payments = self::payments_of_lines( $order );
		if ( $payments ) {
			$order->update_meta_data( self::PAYMENTS_META, $payments );
		} elseif ( $order->meta_exists( self::PAYMENTS_META ) ) {
			$order->delete_meta_data( self::PAYMENTS_META );
		}
	}

	/**
	 * WooCommerce REST API (orders): "vcap_voucher_payments" lists the voucher payments, so exports can book them as
	 * a payment instead of a fee.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WC_Order         $order    Order.
	 */
	public static function rest_order_payments( $response, $order ) {
		if ( $response instanceof \WP_REST_Response && $order instanceof \WC_Order ) {
			$data                          = (array) $response->get_data();
			$data['vcap_voucher_payments'] = array();
			foreach ( self::order_payments( $order ) as $code => $amount ) {
				$data['vcap_voucher_payments'][] = array( 'code' => Mask::code( (string) $code ), 'amount' => wc_format_decimal( $amount, wc_get_price_decimals() ) );
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Is this coupon a voucher that pays after tax (setting on, fixed amount, VAT treatment "payment")?
	 *
	 * Called per coupon, cart line and totals calculation (woocommerce_coupon_get_discount_amount): coupons that
	 * are no Voucher Captain coupons are answered from their meta, and the voucher and its VAT treatment are
	 * looked up once per request and code (forgotten when the voucher changes).
	 */
	public function applies( $coupon ): bool {
		if ( ! self::enabled() || ! $coupon instanceof \WC_Coupon || 'fixed_cart' !== $coupon->get_discount_type() || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return false;
		}
		$code = wc_format_coupon_code( $coupon->get_code() );
		if ( ! isset( $this->treatments[ $code ] ) ) {
			$voucher = $this->repo->get_by_code( $code );
			if ( ! $voucher ) {
				return (bool) apply_filters( 'vcap_voucher_pays_after_tax', false, $coupon, null );
			}
			if ( ! $this->forget_hooked ) {
				$this->forget_hooked = true;
				add_action( 'vcap_voucher_updated', array( $this, 'forget' ) );
			}
			$this->treatments[ $code ] = array( $voucher, $voucher->has_cash_value() && 'payment' === ProductConfig::tax_treatment( $voucher ) );
		}
		list( $voucher, $applies ) = $this->treatments[ $code ];
		return (bool) apply_filters( 'vcap_voucher_pays_after_tax', $applies, $coupon, $voucher );
	}

	/** @var array<string, array{0: Voucher, 1: bool}> code => voucher and whether it pays after tax (this request) */
	private array $treatments = array();

	private bool $forget_hooked = false;

	/** A voucher changed (status, product, type): look it up again. */
	public function forget( $voucher = null ): void {
		if ( $voucher instanceof Voucher ) {
			unset( $this->treatments[ wc_format_coupon_code( $voucher->code ) ] );
		} else {
			$this->treatments = array();
		}
	}

	/**
	 * Voucher products whose vouchers are not settled as a payment after tax with the setting on (they keep
	 * lowering price and VAT), with the reason: VAT treatment single-purpose or promotion, a discount type other
	 * than "Fixed cart discount", or ticket vouchers. Shown under the setting (Settings).
	 *
	 * @return array<int, array{product: \WC_Product, reason: string}>
	 */
	public static function not_settled_products( int $limit = 200 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => '_vcap_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$config    = ProductConfig::get( $product );
			$treatment = ProductConfig::product_treatment( $product );
			$type      = 'balance' === (string) $config['usage'] ? 'fixed_cart' : (string) $config['discount_type'];
			$reason    = '';
			if ( 'ticket' === (string) $config['type'] && 'fixed' !== (string) $config['amount_source'] ) {
				$reason = __( 'ticket voucher', 'voucher-captain' ); // Without a fixed value: no cash value (M12-10).
			} elseif ( 'single' === $treatment ) {
				$reason = __( 'VAT treatment "Single-purpose voucher"', 'voucher-captain' );
			} elseif ( 'discount' === $treatment ) {
				$reason = __( 'VAT treatment "Promotion" or a percentage', 'voucher-captain' );
			} elseif ( 'fixed_cart' !== $type ) {
				$reason = __( 'discount type other than "Fixed cart discount"', 'voucher-captain' );
			}
			if ( '' !== $reason ) {
				$out[] = array(
					'product' => $product,
					'reason'  => $reason,
				);
			}
		}
		return $out;
	}

	/** Note under the setting: how many voucher products are not settled as a payment, with links (HTML). */
	public static function not_settled_note(): string {
		$products = self::not_settled_products();
		if ( ! $products ) {
			return '';
		}
		$links = array();
		foreach ( array_slice( $products, 0, 10 ) as $row ) {
			$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $row['product']->get_id(), 'raw' ) ) . '">' . esc_html( $row['product']->get_name() ) . '</a> (' . esc_html( $row['reason'] ) . ')';
		}
		return sprintf(
			/* translators: 1: number of products, 2: list of product links */
			_n( 'Not settled as payment (keeps lowering price and VAT): %1$d voucher product – %2$s', 'Not settled as payment (keep lowering price and VAT): %1$d voucher products – %2$s', count( $products ), 'voucher-captain' ),
			count( $products ),
			implode( ', ', $links ) . ( count( $products ) > 10 ? ' …' : '' )
		);
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
		foreach ( self::sorted_coupons( $cart->get_applied_coupons() ) as $code => $coupon ) {
			if ( ! $this->applies( $coupon ) ) {
				continue;
			}
			$value = self::value_in( $coupon, (string) get_woocommerce_currency() );
			if ( null === $value ) {
				continue;
			}
			$open  ??= self::open_amounts( $cart );
			$amount  = $this->take_payment( $coupon, $cart, $open, $due, $value );
			if ( $amount <= 0 ) {
				continue;
			}
			$payments[ wc_format_coupon_code( (string) $code ) ] = $amount;
			$due = round( $due - $amount, $decimals );
		}
		return $payments;
	}

	/**
	 * M12-70: the applied coupons in the order WooCommerce applies them (filter woocommerce_coupon_sort, e.g. store
	 * credit before or after gift cards, CartCoupons::sort()); equal priorities keep the order they were entered in.
	 *
	 * @param string[] $codes Applied coupon codes.
	 * @return array<string, \WC_Coupon>
	 */
	public static function sorted_coupons( array $codes ): array {
		$coupons = array();
		$sort    = array();
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			$type   = array(
				'fixed_product' => 1,
				'percent'       => 2,
				'fixed_cart'    => 3,
			);
			$coupons[ (string) $code ] = $coupon;
			$sort[ (string) $code ]    = (int) apply_filters( 'woocommerce_coupon_sort', $type[ $coupon->get_discount_type() ] ?? 0, $coupon ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own sort filter.
		}
		uksort( $coupons, static fn( $a, $b ) => $sort[ $a ] <=> $sort[ $b ] ); // Stable since PHP 8.0.
		return $coupons;
	}

	/**
	 * What the voucher is worth in $currency (cart or order currency). Coupon amounts are kept in the shop base
	 * currency; in another currency the stored amount is converted with a known exchange rate
	 * (Compat\MultiCurrency), never taken 1:1 and independently of whether a currency switcher converts coupon
	 * amounts itself in this request (WCML does so only where it loaded its frontend filters). Null without a rate.
	 *
	 * @param \WC_Order|null $order Order whose stored exchange rate counts.
	 */
	private static function value_in( \WC_Coupon $coupon, string $currency, $order = null ): ?float {
		if ( '' === $currency || get_option( 'woocommerce_currency' ) === $currency ) {
			return (float) $coupon->get_amount();
		}
		$stored = $coupon->get_id() ? get_post_meta( $coupon->get_id(), 'coupon_amount', true ) : '';
		$base   = '' !== (string) $stored ? (float) $stored : (float) $coupon->get_amount( 'edit' );
		$probe  = 1000.0; // Base value of 1000 units: keeps a switcher's rounding out of the rate.
		$unit   = Compat\MultiCurrency::to_base( $probe, $currency, $order );
		if ( null === $unit || $unit <= 0 ) {
			return null;
		}
		return $base * $probe / $unit;
	}

	/**
	 * What one paying voucher pays: at most $due and its $value, at most the open gross amount of the parts it may
	 * pay for. Takes that amount out of $open, so the next voucher never pays the same part again.
	 *
	 * @param \WC_Cart|\WC_Order   $subject Cart or order.
	 * @param array<string, float> $open   open_amounts() of the object, reduced by earlier payments.
	 */
	private function take_payment( \WC_Coupon $coupon, $subject, array &$open, float $due, float $value ): float {
		$eligible = $this->eligible_parts( $coupon, $subject, array_keys( $open ) );
		$covered  = array_sum( array_intersect_key( $open, array_flip( $eligible ) ) );
		$amount   = round( min( max( 0.0, $due ), max( 0.0, $value ), $covered ), wc_get_price_decimals() );
		$left     = $amount;
		foreach ( $eligible as $part ) {
			if ( $left <= 0 ) {
				break;
			}
			$take          = min( $left, $open[ $part ] );
			$open[ $part ] = $open[ $part ] - $take;
			$left         -= $take;
		}
		return $amount;
	}

	/**
	 * @param \WC_Cart|\WC_Order $subject Cart or order (voucher payment lines of an order do not count).
	 * @return array<string, float> part ("item:KEY", "shipping", "fee:ID") => gross amount still open
	 */
	private static function open_amounts( $subject ): array {
		$open = array();
		if ( $subject instanceof \WC_Order ) {
			$open['shipping'] = 0.0;
			foreach ( $subject->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $id => $item ) {
				$gross = max( 0.0, (float) $item->get_total() + (float) $item->get_total_tax() );
				if ( $item instanceof \WC_Order_Item_Product ) {
					$open[ 'item:' . $id ] = $gross;
				} elseif ( $item instanceof \WC_Order_Item_Shipping ) {
					$open['shipping'] += $gross;
				} elseif ( '' === (string) $item->get_meta( self::FEE_META ) ) {
					$open[ 'fee:' . $id ] = $gross;
				}
			}
			return $open;
		}
		foreach ( $subject->get_cart() as $key => $values ) {
			$open[ 'item:' . $key ] = max( 0.0, (float) ( $values['line_total'] ?? 0 ) + (float) ( $values['line_tax'] ?? 0 ) );
		}
		$open['shipping'] = max( 0.0, (float) $subject->get_shipping_total() + (float) $subject->get_shipping_tax() );
		foreach ( $subject->get_fees() as $fee ) {
			$open[ 'fee:' . $fee->id ] = max( 0.0, (float) $fee->total + (float) $fee->tax );
		}
		return $open;
	}

	/**
	 * @param \WC_Cart|\WC_Order $subject Cart or order.
	 * @param string[]           $parts  All parts of the cart or order (open_amounts() keys).
	 * @return string[] The parts this voucher may pay, in cart order.
	 */
	private function eligible_parts( \WC_Coupon $coupon, $subject, array $parts ): array {
		$discounts = new \WC_Discounts( $subject );
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
		$extras = (bool) apply_filters( 'vcap_voucher_pays_shipping_and_fees', ! $restricted, $coupon, $subject );
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
			$order->add_item( self::payment_line( (string) $code, (float) $amount ) );
		}
		$order->set_total( (string) max( 0.0, round( $gross - array_sum( array_map( 'floatval', $payments ) ), wc_get_price_decimals() ) ) );
		self::sync_payments_meta( $order );
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
	 * A paying voucher added to an existing order (admin "Apply coupon", WC_Order::apply_coupon()) gets its payment
	 * line as at checkout, right before WooCommerce recalculates the coupons: order_coupon_object() then sees the
	 * line and lets the coupon discount 0, so the VAT stays on the full price. It pays at most its value and what the
	 * order still owes for the parts it may pay for (as payments_for() in the cart). WC_Order::apply_coupon() saves
	 * the order right after this action.
	 *
	 * @param \WC_Coupon $coupon Applied coupon.
	 * @param \WC_Order  $order  Order.
	 */
	public function add_applied_payment( $coupon, $order ): void {
		if ( ! $order instanceof \WC_Order || ! $this->applies( $coupon ) ) {
			return;
		}
		$code = wc_format_coupon_code( $coupon->get_code() );
		if ( null !== self::paid_amount( $order, $code ) ) {
			return;
		}
		$value = self::value_in( $coupon, (string) $order->get_currency(), $order );
		if ( null === $value ) {
			return;
		}
		$due = self::order_gross( $order );
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( '' !== (string) $fee->get_meta( self::FEE_META ) ) {
				$due -= abs( (float) $fee->get_total() ); // What other vouchers already pay.
			}
		}
		$open   = self::open_amounts( $order );
		$amount = $this->take_payment( $coupon, $order, $open, round( $due, wc_get_price_decimals() ), $value );
		if ( $amount > 0 ) {
			$order->add_item( self::payment_line( $code, $amount ) );
			self::sync_payments_meta( $order );
		}
	}

	/**
	 * The non-taxable, negative payment line of the voucher with this code. Its name shows the code masked
	 * ("Voucher …7KQ2"): the line appears in order emails, My Account, invoices and order tracking, and whoever sees
	 * it could otherwise spend the rest of a gift card. The full code is kept in FEE_META only.
	 */
	private static function payment_line( string $code, float $amount ): \WC_Order_Item_Fee {
		$fee = new \WC_Order_Item_Fee();
		/* translators: %s: voucher code */
		$fee->set_name( sprintf( __( 'Voucher %s', 'voucher-captain' ), Mask::code( $code ) ) );
		$fee->set_tax_status( 'none' );
		$fee->set_tax_class( '' );
		$fee->set_total( (string) -abs( $amount ) );
		$fee->set_taxes( false );
		$fee->add_meta_data( self::FEE_META, wc_format_coupon_code( $code ), true );
		$fee->add_meta_data( self::AMOUNT_META, (string) abs( $amount ), true );
		return $fee;
	}

	/**
	 * WooCommerce caps negative fee lines at the net total when it recalculates an order, so a voucher that pays the
	 * whole gross amount would shrink to the net amount. The payment line keeps what was paid, the total follows.
	 * A payment line whose voucher is no longer on the order (admin removed the coupon) goes: the voucher no longer
	 * pays, so the customer owes that amount again.
	 */
	public function restore_payment_lines( $and_taxes, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$diff    = 0.0;
		$applied = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
		foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
			$code = (string) $fee->get_meta( self::FEE_META );
			if ( '' !== $code && ! in_array( wc_format_coupon_code( $code ), $applied, true ) ) {
				$diff -= (float) $fee->get_total();
				$order->remove_item( $item_id );
				continue;
			}
			$paid = (string) $fee->get_meta( self::AMOUNT_META );
			if ( '' === $code || '' === $paid ) {
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
		if ( $order->meta_exists( self::PAYMENTS_META ) || self::payments_of_lines( $order ) ) {
			self::sync_payments_meta( $order );
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
