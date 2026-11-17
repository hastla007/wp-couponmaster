<?php
namespace VCAP\Coupons;

defined( 'ABSPATH' ) || exit;

/**
 * M12-14: product, category, exclusion and "exclude sale items" rules of Voucher Captain vouchers with the discount
 * type "Fixed cart discount" (gift cards, stored balances, fixed-value vouchers) apply per cart line.
 *
 * WooCommerce applies a cart-wide coupon to every line as soon as one line matches its products or categories, and
 * refuses the whole coupon as soon as one line is excluded (sale item, excluded product or category). For these
 * vouchers the rules are read from the coupon as configured (WooCommerce fields, no migration) and applied here
 * instead:
 *  - the coupon is distributed only over the lines that pass the rules (woocommerce_coupon_get_items_to_apply,
 *    priority 12: after "vouchers do not buy vouchers" at 10, before the SKU rules at 15 and the gross balance
 *    split at 20), asked the way WooCommerce asks a product coupon (WC_Coupon::is_valid_for_product() on a
 *    "fixed_product" probe);
 *  - WooCommerce's whole-cart exclusion checks no longer see the exclusions of these coupons (the getters in "view"
 *    context answer "none"; wp-admin and the REST API read the stored values in "edit" context);
 *  - the coupon is valid as long as at least one line passes (CartCoupons::validate()).
 */
final class LineRules {
	/** Whether the coupon is a Voucher Captain "Fixed cart discount" coupon (whose rules apply per line). */
	public static function applies( $coupon ): bool {
		return $coupon instanceof \WC_Coupon && 'fixed_cart' === $coupon->get_discount_type( 'edit' ) && 'yes' === $coupon->get_meta( '_vcap_voucher' );
	}

	/** Whether the coupon limits the lines it pays for at all. */
	public static function restricts( \WC_Coupon $coupon ): bool {
		return (bool) ( $coupon->get_product_ids( 'edit' ) || $coupon->get_product_categories( 'edit' ) || $coupon->get_excluded_product_ids( 'edit' ) || $coupon->get_excluded_product_categories( 'edit' ) || $coupon->get_exclude_sale_items( 'edit' ) );
	}

	/** The question WooCommerce asks a product coupon, with this coupon's rules. */
	private static function probe( \WC_Coupon $coupon ): \WC_Coupon {
		$probe = clone $coupon;
		$probe->set_discount_type( 'fixed_product' );
		return $probe;
	}

	/**
	 * The lines (WC_Discounts items) the coupon may pay for.
	 *
	 * @param array<int|string, mixed> $items Items.
	 * @return array<int|string, mixed>
	 */
	public static function qualifying( \WC_Coupon $coupon, array $items ): array {
		$probe = self::probe( $coupon );
		return array_filter(
			$items,
			static fn( $item ) => is_object( $item ) && isset( $item->product ) && $item->product instanceof \WC_Product && $probe->is_valid_for_product( $item->product, $item->object ?? array() )
		);
	}

	/**
	 * woocommerce_coupon_get_items_to_apply (priority 12): only the qualifying lines.
	 *
	 * @param mixed      $items  Items the coupon applies to.
	 * @param \WC_Coupon $coupon Coupon.
	 */
	public static function items_to_apply( $items, $coupon ) {
		if ( ! is_array( $items ) || ! self::applies( $coupon ) || ! self::restricts( $coupon ) ) {
			return $items;
		}
		return array_values( self::qualifying( $coupon, $items ) );
	}

	/**
	 * woocommerce_coupon_get_exclude_sale_items / _excluded_product_ids / _excluded_product_categories ("view"
	 * context): none for these coupons, so WooCommerce does not refuse the whole coupon for one excluded line.
	 *
	 * @param mixed      $value  Stored value.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public static function no_whole_cart_exclusion( $value, $coupon ) {
		if ( ! self::applies( $coupon ) ) {
			return $value;
		}
		return is_bool( $value ) ? false : array();
	}

	/**
	 * Refuses the coupon when no line of the cart or order passes its rules.
	 *
	 * @throws \Exception Coupon error 109 (not applicable to the selected products).
	 */
	public static function check( \WC_Coupon $coupon, $discounts ): void {
		if ( ! self::applies( $coupon ) || ! self::restricts( $coupon ) || ! $discounts instanceof \WC_Discounts ) {
			return;
		}
		$items = $discounts->get_items_to_validate();
		if ( ! $items || self::qualifying( $coupon, $items ) ) {
			return;
		}
		throw new \Exception( esc_html__( 'This voucher cannot be used for the products in your cart.', 'voucher-captain' ), 109 );
	}
}
