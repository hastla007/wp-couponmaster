<?php
namespace VCAP\Coupons;

use VCAP\API;
use VCAP\Compat;
use VCAP\PaymentVouchers;
use VCAP\Reissue;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The vouchers behind an order's coupon lines (D14, split from CouponManager): looking a code up (also an
 * earlier code of a reissued voucher, only to book value back), what a coupon line used, and the order meta maps.
 */
final class OrderCoupons {
	public function __construct( private VoucherRepository $repo ) {}

	/**
	 * Read a code => amount map from order meta. A missing meta value is '' and (array) '' would be [ '' ].
	 *
	 * @return array<string, float>
	 */
	public static function order_map( \WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_filter( $value, static fn( $amount, $code ) => '' !== (string) $code && is_numeric( $amount ), ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * AP-Q1-2: a code on an unpaid order that was replaced by a new one (reissued) since: the customer message
	 * (payment refused) or the order note for the shop ($note), for the masked code.
	 */
	public static function reissued_code_message( string $masked, bool $note = false ): string {
		return $note
			/* translators: %s: masked voucher code */
			? sprintf( __( 'Voucher %s: this code was replaced by a new one (reissued) before the order was paid, so nothing was charged to the voucher for its discount. Review this order before fulfilment.', 'voucher-captain' ), $masked )
			/* translators: %s: masked voucher code */
			: sprintf( __( 'Voucher %s has been replaced by a new code and can no longer be used. This order cannot be paid as it is. Please contact us to complete it.', 'voucher-captain' ), $masked );
	}

	/** What the coupon line used, in the base currency; null when the order is in a currency without known rate. */
	public function used_amount( \WC_Order_Item_Coupon $item ): ?float {
		$order = $item->get_order();
		// Multi-purpose voucher paid after tax (PaymentVouchers): the fee line holds the gross amount paid.
		$paid = $order instanceof \WC_Order ? PaymentVouchers::paid_amount( $order, (string) $item->get_code() ) : null;
		if ( null !== $paid ) {
			$paid = Compat\MultiCurrency::to_base( (float) $paid, (string) $order->get_currency(), $order );
			return null === $paid ? null : round( $paid, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
		}
		$amount = (float) $item->get_discount();
		// The value the customer received is the discount plus the tax it removed – in tax-inclusive shops
		// (coupons apply to gross prices) and, for stored balances, in tax-exclusive shops (see prepare_gross_balance()).
		if ( apply_filters( 'vcap_balance_deduct_tax', wc_prices_include_tax() || apply_filters( 'vcap_balance_covers_tax', true, null ), $item ) ) {
			$amount += (float) $item->get_discount_tax();
		}
		// Discounts are in the order currency, balances in the shop base currency (never converted 1:1).
		if ( $order instanceof \WC_Order ) {
			$amount = Compat\MultiCurrency::to_base( $amount, (string) $order->get_currency(), $order );
			if ( null === $amount ) {
				return null;
			}
		}
		return round( $amount, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
	}

	/** Whether balance vouchers of the order are still not charged. */
	public function open_balance_coupons( \WC_Order $order, array $applied ): bool {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$code    = wc_format_coupon_code( $item->get_code() );
			$voucher = $this->find_voucher_for_code( $code );
			if ( $voucher && $voucher->is_multi_use() && ! isset( $applied[ $code ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The voucher of a coupon code on an order: by its coupon, else by its code. With $book_back an earlier code of
	 * a reissued voucher (V10) finds the voucher too – only to book value back (restore, release, return), never to
	 * reserve, commit or charge it (AP-Q1-2: the old code must not pay an order after the reissue).
	 */
	public function find_voucher_for_code( string $code, bool $book_back = false ): ?Voucher {
		$coupon_id = wc_get_coupon_id_by_code( $code );
		$voucher   = $coupon_id ? $this->repo->get_by_coupon( (int) $coupon_id ) : $this->repo->get_by_code( $code );
		return $voucher ?? ( $book_back ? Reissue::voucher_of_previous_code( $this->repo, $code ) : null );
	}

	/** Whether $code is an earlier code of a reissued voucher (no coupon or voucher carries it any more). */
	public function is_reissued_code( string $code ): bool {
		$coupon_id = (int) wc_get_coupon_id_by_code( $code );
		if ( $coupon_id && 'publish' === get_post_status( $coupon_id ) && 'yes' !== get_post_meta( $coupon_id, '_vcap_voucher', true ) ) {
			return false; // An ordinary coupon (no extra query: post and meta are cached).
		}
		// Also when a stale cache still names the deleted coupon of the old code.
		return null === $this->repo->get_by_code( $code ) && null !== Reissue::voucher_of_previous_code( $this->repo, $code );
	}
}
