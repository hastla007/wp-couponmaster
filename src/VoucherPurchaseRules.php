<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * What may reduce the price of voucher products in the cart.
 *
 * Vouchers are always worth their face value, so by default (like most retailers):
 * - discount codes do not apply to voucher products (otherwise a 50 € voucher bought for 45 € is redeemed for 50 €);
 * - vouchers/gift cards cannot pay for voucher products (prevents exchange loops, e.g. "extending" expiring cards).
 * Both can be allowed under WooCommerce → Settings → Vouchers. Other products in the same cart are unaffected.
 */
final class VoucherPurchaseRules {
	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'items_to_apply' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 30, 3 );
	}

	/**
	 * Whether this coupon may reduce the price of voucher products.
	 */
	public function coupon_allowed_on_vouchers( \WC_Coupon $coupon ): bool {
		$is_voucher = 'yes' === $coupon->get_meta( '_vcap_voucher' );
		$allowed    = $is_voucher ? $this->settings->yes( 'vcap_vouchers_buy_vouchers' ) : $this->settings->yes( 'vcap_discounts_on_vouchers' );
		return (bool) apply_filters( 'vcap_coupon_allowed_on_voucher_products', $allowed, $coupon );
	}

	private static function is_voucher_item( $item ): bool {
		return isset( $item->product ) && $item->product instanceof \WC_Product && ProductConfig::is_voucher( $item->product );
	}

	/**
	 * Remove voucher products from the items a coupon is applied to.
	 */
	public function items_to_apply( $items, $coupon ) {
		if ( ! is_array( $items ) || ! $coupon instanceof \WC_Coupon || $this->coupon_allowed_on_vouchers( $coupon ) ) {
			return $items;
		}
		return array_values( array_filter( $items, static fn( $item ) => ! self::is_voucher_item( $item ) ) );
	}

	/**
	 * Refuse the coupon with a clear message when the cart contains nothing it may apply to.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $discounts instanceof \WC_Discounts || $this->coupon_allowed_on_vouchers( $coupon ) ) {
			return $valid;
		}
		$items = $discounts->get_items_to_validate();
		if ( $items && count( array_filter( $items, array( self::class, 'is_voucher_item' ) ) ) === count( $items ) ) {
			throw new \Exception(
				'yes' === $coupon->get_meta( '_vcap_voucher' )
					? esc_html__( 'Vouchers and gift cards cannot be used to buy vouchers.', 'voucher-captain' )
					: esc_html__( 'Discount codes cannot be used to buy vouchers.', 'voucher-captain' ),
				9010
			);
		}
		return $valid;
	}
}
