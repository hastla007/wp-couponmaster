<?php
namespace VCAP\Coupons;

use VCAP\API;
use VCAP\Channels;
use VCAP\Compat;
use VCAP\GrossBalanceSplit;
use VCAP\PaymentVouchers;
use VCAP\PersonalVoucher;
use VCAP\ProductConfig;
use VCAP\Settings;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Vouchers in the cart and the discount calculation (D14, split from CouponManager): validation, the order in which
 * coupons apply, gift cards as a means of payment next to "Individual use only" codes, the gross amount of a stored
 * balance in tax-exclusive shops, order recalculation and the remaining-balance note.
 */
final class CartCoupons {
	public function __construct( private Settings $settings, private VoucherRepository $repo, private OrderCoupons $lookup ) {}

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
			throw new \Exception( esc_html( self::not_active_message( $voucher ) ), 9004 );
		}
		if ( Voucher::STATUS_RESERVED === $voucher->status ) {
			// Being redeemed outside the shop right now (API reservation); released again if that fails.
			throw new \Exception( esc_html__( 'This voucher is currently being redeemed. Please try again in a few minutes.', 'voucher-captain' ), 9005 );
		}
		// M12-13: what this visitor's own unpaid checkout order holds is still theirs to use (cart only).
		$in_order = $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order;
		$own_hold = $in_order ? 0.0 : OwnHold::amount( $voucher );
		if ( ( Voucher::STATUS_REDEEMED === $voucher->status || ( $voucher->is_multi_use() && $voucher->balance <= 0 ) ) && $own_hold <= 0 ) {
			throw new \Exception( esc_html__( 'This voucher has already been fully redeemed.', 'voucher-captain' ), 9002 );
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			throw new \Exception( esc_html( self::expired_message( $voucher ) ), 9003 );
		}
		if ( 'percent' !== $coupon->get_discount_type() && self::currency_mismatch( $voucher ) ) {
			// M12-33: a fixed value kept in a currency that is no longer the base currency is never taken 1:1.
			throw new \Exception( esc_html( self::currency_mismatch_message( $voucher ) ), 9007 );
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
		if ( $voucher->is_multi_use() && ! $in_order ) {
			// The cart never grants more than the stored balance, even if the coupon amount is out of date (edited in
			// WooCommerce, failed save): otherwise every checkout with it ends in a shortfall. Orders keep their
			// own amount (order_coupon_object()). M12-33: compared in the units of the coupon object (a switcher
			// such as WCML converts it into the cart currency), never base balance against a converted amount.
			$available = OwnHold::in_coupon_units( $coupon, (float) $voucher->balance + $own_hold );
			if ( (float) $coupon->get_amount( 'edit' ) > $available + 0.000001 ) {
				$coupon->set_amount( $available );
			}
		}
		LineRules::check( $coupon, $discounts ); // M12-14: at least one line the voucher may pay for.
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
		$means   = $voucher && $voucher->has_cash_value() && 'payment' === ProductConfig::tax_treatment( $voucher ); // M12-10
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
			if ( ! in_array( $code, $keep, true ) && $this->is_payment_means( CouponFactory::for_code( (string) $code ) ) ) {
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
		$coupons  = WC()->cart->get_coupons();
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = $coupons[ $code ] ?? null;
			if ( $coupon instanceof \WC_Coupon && 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
				continue; // AP-P1-5: ordinary coupons are never looked up.
			}
			$voucher = $coupon instanceof \WC_Coupon && $coupon->get_id() ? $this->repo->get_by_coupon( $coupon->get_id() ) : null;
			$voucher = $voucher ?? $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
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
		return max( 0.0, round( (float) $voucher->balance + OwnHold::amount( $voucher ) - $used, $voucher->decimals() ) );
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

	/** The plugin's PaymentVouchers service (AP-P1-5: one instance per request), or a new one without the plugin. */
	private static function payments( VoucherRepository $repo ): PaymentVouchers {
		return function_exists( 'vcap' ) && isset( vcap()->payments ) ? vcap()->payments : new PaymentVouchers( $repo );
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
		OwnHold::skip( $coupon ); // M12-13: an order's coupon never gets the cart's own hold added.
		$code = wc_format_coupon_code( (string) $code );
		if ( null !== PaymentVouchers::paid_amount( $order, $code ) ) {
			// Paid after tax by its fee line (single-use vouchers too): a recalculation must not turn it into a
			// discount as well.
			$coupon->set_amount( 0 );
			return $coupon;
		}
		$redeemed = (float) ( OrderCoupons::order_map( $order, '_vcap_redemptions' )[ $code ] ?? 0 );
		$voucher  = $this->lookup->find_voucher_for_code( $code, $redeemed > 0 ); // An earlier code: only what was charged.
		if ( ! $voucher || ! $voucher->is_multi_use() ) {
			return $coupon;
		}
		$reserved = (float) ( OrderCoupons::order_map( $order, '_vcap_reservations' )[ $code ] ?? 0 );
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
		if ( ! wc_tax_enabled() || wc_prices_include_tax() || 'fixed_cart' !== $coupon->get_discount_type() || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) || ! apply_filters( 'vcap_balance_covers_tax', true, $coupon ) ) {
			return $items; // AP-P1-5: ordinary coupons are never looked up.
		}
		$voucher = $coupon->get_id() ? $this->repo->get_by_coupon( $coupon->get_id() ) : null;
		$voucher = $voucher ?? $this->repo->get_by_code( $code );
		// M12-27: a single-use multi-purpose voucher is money as well (pays 100 gross, not 100 net + VAT).
		$money = $voucher && ( $voucher->is_multi_use() || ( $voucher->has_cash_value() && 'payment' === ProductConfig::tax_treatment( $voucher ) ) );
		if ( ! $money || ( PaymentVouchers::enabled() && self::payments( $this->repo )->applies( $coupon ) ) ) {
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

	/** M12-93: "This voucher is active from 1 May 2027." (without a known date: "… not active yet."). */
	public static function not_active_message( Voucher $voucher ): string {
		$from = $voucher->delivery_at ? strtotime( $voucher->delivery_at . ' UTC' ) : false;
		if ( ! $from ) {
			return __( 'This voucher is not active yet.', 'voucher-captain' );
		}
		/* translators: %s: date from which the voucher can be used */
		return sprintf( __( 'This voucher is active from %s.', 'voucher-captain' ), wp_date( self::date_format(), $from ) );
	}

	/** M12-93: "This voucher expired on 1 May 2027." (without a known date: "… has expired."). */
	public static function expired_message( Voucher $voucher ): string {
		$ended = $voucher->expires_at ? strtotime( $voucher->expires_at . ' UTC' ) : false;
		if ( ! $ended ) {
			return __( 'This voucher has expired.', 'voucher-captain' );
		}
		/* translators: %s: expiry date */
		return sprintf( __( 'This voucher expired on %s.', 'voucher-captain' ), wp_date( self::date_format(), $ended ) );
	}

	private static function date_format(): string {
		$format = (string) get_option( 'vcap_date_format', '' );
		return '' !== $format ? $format : (string) get_option( 'date_format', 'Y-m-d' );
	}

	/**
	 * M12-33: a fixed-value voucher kept in another currency than the shop base currency (the base currency was
	 * changed after it was issued): its amount would be read as base currency, e.g. 50 EUR as 50 CHF.
	 */
	public static function currency_mismatch( Voucher $voucher ): bool {
		return '' !== $voucher->currency && 'percent' !== $voucher->discount_type && (string) get_option( 'woocommerce_currency' ) !== $voucher->currency;
	}

	public static function currency_mismatch_message( Voucher $voucher ): string {
		return sprintf(
			/* translators: 1: currency of the voucher, 2: shop base currency */
			__( 'This voucher is kept in %1$s, but the shop now uses %2$s. Please contact the shop to have it converted.', 'voucher-captain' ),
			$voucher->currency,
			(string) get_option( 'woocommerce_currency' )
		);
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
}
