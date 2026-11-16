<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * EU VAT and accounting rules of the 12-expert audit (M12-28, M12-29, M12-68; TAX.md):
 *
 * - single-purpose vouchers are only redeemed for goods with the VAT rate and the country of their sale (cart,
 *   checkout, Store API and orders edited in the admin; WP Pass Captain checks the same itself);
 * - the ledger books the remaining value of a single-use voucher that an order did not use ("forfeited", or kept as
 *   store credit / a new gift card with the product setting "Keep the rest"), the value lost at the expiry
 *   ("expired" with an amount) and its counter-booking when the voucher becomes valid again ("expiry_reversed").
 */
final class TaxAccounting {
	/** Ledger event: rest of a single-use voucher that the redeeming order did not use (lost, M12-29). */
	public const FORFEITED_EVENT = 'forfeited';
	/** Ledger event: that rest kept as store credit or a new gift card (product setting "keep_rest", M12-68). */
	public const REST_CONVERTED_EVENT = 'rest_converted';
	/** Ledger event: the conversion above taken back because the redemption was given back (no amount). */
	public const REST_REVERTED_EVENT = 'rest_reverted';
	/** Ledger event: an expired voucher is valid again – the value booked off at the expiry is booked back. */
	public const EXPIRY_REVERSED_EVENT = 'expiry_reversed';
	/** Ledger event: personal data of the voucher erased on request (M12-66), with the number of notes anonymised. */
	public const ERASED_EVENT = 'privacy_erased';

	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'vcap_voucher_updated', array( $this, 'book_expiry_reversal' ), 20 );
		add_filter( 'vcap_event_labels', array( self::class, 'event_labels' ) );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'single_purpose_items' ), 14, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'single_purpose_valid' ), 25, 3 );
		// WC_Order::apply_coupon() counts the coupon use right after this action, without a status change: the
		// single-use redemption is booked with that order (amount used, rest; M12-29).
		add_action( 'woocommerce_order_applied_coupon', array( self::class, 'remember_applied_order' ), 5, 2 );
	}

	/**
	 * @param \WC_Coupon $coupon Applied coupon.
	 * @param \WC_Order  $order  Order.
	 */
	public static function remember_applied_order( $coupon, $order ): void {
		if ( $order instanceof \WC_Order && $coupon instanceof \WC_Coupon && 'yes' === $coupon->get_meta( '_vcap_voucher' ) && function_exists( 'vcap' ) ) {
			vcap()->coupons->remember_usage_order( $order->get_id() );
		}
	}

	/** @param array<string, string> $labels */
	public static function event_labels( $labels ): array {
		return (array) $labels + array(
			self::FORFEITED_EVENT       => __( 'Remaining value forfeited', 'voucher-captain' ),
			self::REST_CONVERTED_EVENT  => __( 'Remaining value kept as credit', 'voucher-captain' ),
			self::REST_REVERTED_EVENT   => __( 'Kept remaining value taken back', 'voucher-captain' ),
			self::EXPIRY_REVERSED_EVENT => __( 'Expiry booked back', 'voucher-captain' ),
			self::ERASED_EVENT          => __( 'Personal data erased', 'voucher-captain' ),
		);
	}

	/* ---- M12-29: expiry with its value, and its counter-booking ------------------------------------- */

	/**
	 * Value an "expired" booking takes off the voucher: the balance of a gift card or store credit, the value of an
	 * unused single-use value voucher. Null for vouchers without money (percentages, tickets without value,
	 * reusable codes).
	 */
	public static function expiry_amount( Voucher $voucher ): ?float {
		if ( 'percent' === $voucher->discount_type || ! $voucher->has_cash_value() || ! in_array( $voucher->usage_mode, array( 'single', 'balance' ), true ) ) {
			return null;
		}
		return round( max( 0.0, (float) $voucher->balance ), $voucher->decimals() );
	}

	/**
	 * A voucher whose expiry was booked with its value is valid again (validity extended, reactivated by staff,
	 * topped up): the value is booked back once ("expiry_reversed"), so the journal sums to the outstanding value.
	 */
	public function book_expiry_reversal( $voucher ): void {
		if ( ! $voucher instanceof Voucher || $voucher->id <= 0 || $voucher->is_expired() || ! in_array( $voucher->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_SCHEDULED, Voucher::STATUS_RESERVED ), true ) ) {
			return;
		}
		global $wpdb;
		$events = Install::tables()['events'];
		$last   = $wpdb->get_row( $wpdb->prepare( "SELECT event, amount FROM {$events} WHERE voucher_id = %d AND event IN ('expired','expiry_reversed') ORDER BY id DESC LIMIT 1", $voucher->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $last ) || 'expired' !== $last['event'] || null === $last['amount'] || (float) $last['amount'] <= 0 ) {
			return;
		}
		$this->repo->log( $voucher->id, self::EXPIRY_REVERSED_EVENT, (float) $last['amount'], $voucher->is_multi_use() ? (float) $voucher->balance : null, Note::make( 'expiry_reversed' ) );
	}

	/* ---- M12-29 / M12-68: single-use redemption split into used value and rest ---------------------- */

	/**
	 * What a single-use value voucher paid in the order it was redeemed with (base currency): the coupon line's
	 * discount (plus the tax it removed) or its payment line after tax, at most the voucher value. The full value
	 * when it cannot be read (no order, other voucher kinds, unknown exchange rate).
	 */
	public static function used_by_order( Voucher $voucher, int $order_id, Coupons\OrderCoupons $lookup ): float {
		$value = (float) $voucher->initial_amount;
		if ( $order_id <= 0 || 'percent' === $voucher->discount_type || ! $voucher->has_cash_value() || 'credit' === $voucher->type ) {
			return $value;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $value;
		}
		$code = wc_format_coupon_code( (string) $voucher->code );
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon && wc_format_coupon_code( (string) $item->get_code() ) === $code ) {
				$used = $lookup->used_amount( $item );
				if ( null === $used ) {
					return $value;
				}
				$used = round( min( $value, max( 0.0, $used ) ), $voucher->decimals() );
				// Rounding differences of the gross conversion are no rest.
				return $value - $used < 1 / ( 10 ** $voucher->decimals() ) + 0.000001 ? $value : $used;
			}
		}
		return $value;
	}

	/**
	 * Books the rest of a single-use voucher that the order did not use: kept as store credit or a new gift card
	 * (product setting "keep_rest", sold vouchers only) or forfeited. Nothing for a fully used voucher.
	 */
	public static function book_rest( VoucherRepository $repo, Voucher $voucher, float $used, int $order_id ): void {
		$rest = round( (float) $voucher->initial_amount - $used, $voucher->decimals() );
		if ( $rest <= 0 ) {
			return;
		}
		$money = html_entity_decode( wp_strip_all_tags( wc_price( $rest, array( 'currency' => $voucher->currency ) ) ), ENT_QUOTES, 'UTF-8' );
		$kept  = self::keep_rest( $voucher, $rest, $order_id );
		if ( null !== $kept ) {
			$repo->log( $voucher->id, self::REST_CONVERTED_EVENT, $rest, 0, Note::make( 'rest_converted', array( $money, $kept['label'], $kept['voucher_id'] ) ), $order_id );
			return;
		}
		$repo->log( $voucher->id, self::FORFEITED_EVENT, $rest, 0, Note::make( 'forfeited', array( $money ) ), $order_id );
	}

	/** Whether the product of this voucher keeps the rest of a single-use voucher (setting "keep_rest"). */
	public static function keeps_rest( Voucher $voucher ): bool {
		$product = $voucher->product();
		return $product && 'yes' === (string) ( ProductConfig::get( $product )['keep_rest'] ?? 'no' ) && true === ProductConfig::is_sold( $voucher ) && 'single' === $voucher->usage_mode && 'percent' !== $voucher->discount_type && $voucher->has_cash_value();
	}

	/**
	 * M12-68: the rest as store credit of the redeeming customer (account, store credit on) or as a new gift card
	 * mailed to the order's billing address (same product: same VAT treatment, sold).
	 *
	 * @return array{label:string, voucher_id:int}|null Where the rest went ('' label = store credit), null = forfeited.
	 */
	private static function keep_rest( Voucher $voucher, float $rest, int $order_id ): ?array {
		if ( ! self::keeps_rest( $voucher ) || ! function_exists( 'vcap' ) ) {
			return null;
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		/* translators: 1: masked voucher code, 2: order number */
		$reason = sprintf( __( 'Remaining value of voucher %1$s (order #%2$s)', 'voucher-captain' ), Mask::code( (string) $voucher->code ), $order->get_order_number() );
		$user   = (int) $order->get_customer_id();
		if ( $user > 0 && vcap()->store_credit->enabled() ) {
			$result = vcap()->store_credit->adjust( $user, $rest, $reason, $order_id, 'credit' );
			if ( is_array( $result ) ) {
				$account = vcap()->store_credit->account( $user );
				return array( 'label' => '', 'voucher_id' => $account ? (int) $account->id : 0 );
			}
		}
		$email = (string) $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return null;
		}
		$card = vcap()->issuer->issue_standalone(
			array(
				'product_id'      => $voucher->variation_id ?: $voucher->product_id,
				'type'            => 'giftcard',
				'usage'           => 'balance',
				'discount_type'   => 'fixed_cart',
				'amount'          => $rest,
				'paid'            => true, // The rest of a sold voucher.
				'recipient_email' => $email,
				'recipient_name'  => trim( $order->get_formatted_billing_full_name() ),
				'source'          => 'remaining-value',
				'reference'       => 'rest-' . $voucher->id . '-' . $order_id,
			)
		);
		return $card instanceof Voucher ? array( 'label' => Mask::code( (string) $card->code ), 'voucher_id' => (int) $card->id ) : null;
	}

	/**
	 * The redemption of a single-use voucher was given back (order cancelled or refunded): a rest kept as store
	 * credit or gift card is taken back from there as far as it is still there, otherwise the customer would have
	 * the voucher and the rest.
	 */
	public static function revert_rest( VoucherRepository $repo, Voucher $voucher ): void {
		global $wpdb;
		$events = Install::tables()['events'];
		$last   = $wpdb->get_row( $wpdb->prepare( "SELECT event, amount, note, order_id FROM {$events} WHERE voucher_id = %d AND event IN ('rest_converted','rest_reverted','restored','redeemed') ORDER BY id DESC LIMIT 1", $voucher->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $last ) || self::REST_CONVERTED_EVENT !== $last['event'] || ! function_exists( 'vcap' ) ) {
			return;
		}
		$decoded = Note::decode( (string) $last['note'] );
		$target  = $decoded ? $repo->get( (int) ( $decoded['args'][2] ?? 0 ) ) : null;
		$amount  = (float) $last['amount'];
		$taken   = 0.0;
		if ( $target && $target->balance > 0 ) {
			$take = round( min( $amount, (float) $target->balance ), $target->decimals() );
			/* translators: %s: masked voucher code */
			$note = sprintf( __( 'Voucher %s was given back: its kept remaining value is taken back', 'voucher-captain' ), Mask::code( (string) $voucher->code ) );
			if ( 'credit' === $target->type ) {
				$taken = is_array( vcap()->store_credit->adjust( $target->user_id, -$take, $note, (int) $last['order_id'], 'debit' ) ) ? $take : 0.0;
			} else {
				$taken = vcap()->coupons->adjust_balance( $target, -$take, 'debit', $note, (int) $last['order_id'] ) ? $take : 0.0;
			}
		}
		$repo->log( $voucher->id, self::REST_REVERTED_EVENT, null, null, Note::make( 'rest_reverted', array( html_entity_decode( wp_strip_all_tags( wc_price( $taken, array( 'currency' => $voucher->currency ) ) ), ENT_QUOTES, 'UTF-8' ) ) ), (int) $last['order_id'] );
	}

	/* ---- M12-28: single-purpose vouchers only for goods with the VAT of their sale ------------------- */

	/**
	 * VAT of the sale a single-purpose voucher must match, null when the coupon is no single-purpose voucher, the
	 * shop charges no VAT or the sale is unknown (voucher without a sale line).
	 *
	 * @return array{rate_bp:int, country:string}|null
	 */
	private function single_purpose_sale( $coupon ): ?array {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) || ! wc_tax_enabled() ) {
			return null;
		}
		$voucher = $coupon->get_id() ? $this->repo->get_by_coupon( (int) $coupon->get_id() ) : null;
		if ( ! $voucher || 'single' !== ProductConfig::tax_treatment( $voucher ) ) {
			return null;
		}
		$sale = ProductConfig::sale_tax( $voucher );
		return $sale && '' !== $sale['country'] ? $sale : null;
	}

	/**
	 * VAT rate (basis points) and taxing country of a discount item (cart line or order line).
	 *
	 * @return array{rate_bp:int, country:string}|null
	 */
	public static function item_tax( $item, $subject ): ?array {
		$line = is_object( $item ) && isset( $item->object ) ? $item->object : null;
		if ( $line instanceof \WC_Order_Item_Product && $subject instanceof \WC_Order ) {
			return ProductConfig::line_tax( $subject, $line );
		}
		$product = is_object( $item ) && isset( $item->product ) && $item->product instanceof \WC_Product ? $item->product : null;
		if ( ! $product ) {
			return null;
		}
		$customer = function_exists( 'WC' ) && WC()->customer ? WC()->customer : null;
		$location = $customer ? (array) $customer->get_taxable_address() : array();
		$country  = strtoupper( (string) ( $location[0] ?? '' ) );
		if ( ! $product->is_taxable() ) {
			return array( 'rate_bp' => 0, 'country' => $country );
		}
		$rates = \WC_Tax::get_rates( $product->get_tax_class(), $customer );
		$rate  = 0.0;
		foreach ( $rates as $r ) {
			$rate += (float) ( $r['rate'] ?? 0 );
		}
		return array( 'rate_bp' => (int) round( $rate * 100 ), 'country' => $country );
	}

	/** Whether a line's VAT is the VAT of the sale. */
	private static function matches( ?array $line, array $sale ): bool {
		return null !== $line && abs( $line['rate_bp'] - $sale['rate_bp'] ) <= 1 && ( '' === $line['country'] || strtoupper( $line['country'] ) === strtoupper( $sale['country'] ) );
	}

	/**
	 * A single-purpose voucher applies only to lines with the VAT rate and country of its sale.
	 *
	 * @param array              $items     Discount items.
	 * @param \WC_Coupon         $coupon    Coupon.
	 * @param \WC_Discounts|null $discounts Discount calculation.
	 */
	public function single_purpose_items( $items, $coupon, $discounts = null ) {
		$sale = is_array( $items ) ? $this->single_purpose_sale( $coupon ) : null;
		if ( null === $sale ) {
			return $items;
		}
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		return array_filter( $items, static fn( $item ) => self::matches( self::item_tax( $item, $object ), $sale ) );
	}

	/**
	 * Refuses a single-purpose voucher when nothing in the cart or order has the VAT rate and country of its sale.
	 *
	 * @throws \Exception With the reason (WooCommerce shows it).
	 */
	public function single_purpose_valid( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $discounts instanceof \WC_Discounts ) {
			return $valid;
		}
		$sale = $this->single_purpose_sale( $coupon );
		if ( null === $sale ) {
			return $valid;
		}
		$object = $discounts->get_object();
		foreach ( $discounts->get_items_to_validate() as $item ) {
			if ( self::matches( self::item_tax( $item, $object ), $sale ) ) {
				return $valid;
			}
		}
		throw new \Exception( esc_html( self::single_purpose_message( $sale ) ), 9010 );
	}

	/** Why a single-purpose voucher is refused for this cart (M12-28). */
	public static function single_purpose_message( array $sale ): string {
		return sprintf(
			/* translators: 1: VAT rate in percent, 2: country code */
			__( 'This single-purpose voucher was sold with %1$s %% VAT for %2$s and can only be redeemed for goods with the same VAT rate and country. Nothing in your order matches.', 'voucher-captain' ),
			wc_format_localized_decimal( (string) round( $sale['rate_bp'] / 100, 1 ) ), // 1999 bp of a rounded gross price = 20 %.
			(string) $sale['country']
		);
	}
}
