<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SKU rules for any WooCommerce coupon (Marketing → Coupons → Usage restriction): "SKUs" limits the coupon to
 * matching products, "Exclude SKUs" keeps it off matching products. Patterns are comma separated, case-insensitive,
 * and a trailing * matches a prefix (e.g. "SHIRT-*"). Variations also match their parent's SKU.
 */
final class CouponSkuRules {
	public const META         = '_vcap_skus';
	public const META_EXCLUDE = '_vcap_exclude_skus';

	public function register(): void {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'fields' ), 20, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 20, 3 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'items_to_apply' ), 15, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 27, 3 );
	}

	/** @return string[] lower-case patterns */
	public static function parse( string $value ): array {
		$out = array();
		foreach ( explode( ',', $value ) as $part ) {
			$part = strtolower( trim( wc_clean( $part ) ) );
			if ( '' !== $part && '*' !== $part ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function matches( string $sku, array $patterns ): bool {
		$sku = strtolower( trim( $sku ) );
		if ( '' === $sku ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			if ( str_ends_with( $pattern, '*' ) ? str_starts_with( $sku, substr( $pattern, 0, -1 ) ) : $sku === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/** SKUs of a product: its own and, for variations, the parent's. */
	private static function skus( \WC_Product $product ): array {
		$skus = array( (string) $product->get_sku( 'edit' ) );
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$skus[] = (string) $parent->get_sku( 'edit' );
			}
		}
		return array_values( array_filter( $skus, 'strlen' ) );
	}

	/** Whether the SKU rules of the coupon allow this product (true when the coupon has no SKU rules). */
	public static function allows( \WC_Coupon $coupon, $product ): bool {
		$include = self::parse( (string) $coupon->get_meta( self::META ) );
		$exclude = self::parse( (string) $coupon->get_meta( self::META_EXCLUDE ) );
		if ( ! $include && ! $exclude ) {
			return true;
		}
		if ( ! $product instanceof \WC_Product ) {
			return ! $include;
		}
		$skus = self::skus( $product );
		foreach ( $skus as $sku ) {
			if ( self::matches( $sku, $exclude ) ) {
				return false;
			}
		}
		if ( ! $include ) {
			return true;
		}
		foreach ( $skus as $sku ) {
			if ( self::matches( $sku, $include ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_rules( \WC_Coupon $coupon ): bool {
		return '' !== trim( (string) $coupon->get_meta( self::META ) ) || '' !== trim( (string) $coupon->get_meta( self::META_EXCLUDE ) );
	}

	public function fields( $coupon_id, $coupon ): void {
		$coupon = $coupon instanceof \WC_Coupon ? $coupon : new \WC_Coupon( $coupon_id );
		woocommerce_wp_text_input(
			array(
				'id'          => self::META,
				'label'       => __( 'SKUs', 'voucher-captain' ),
				'placeholder' => 'SHIRT-*, MUG-01',
				'description' => __( 'Only products with these SKUs (comma separated, * at the end matches the beginning of a SKU).', 'voucher-captain' ),
				'desc_tip'    => true,
				'value'       => (string) $coupon->get_meta( self::META ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_EXCLUDE,
				'label'       => __( 'Exclude SKUs', 'voucher-captain' ),
				'placeholder' => 'SALE-*',
				'description' => __( 'Never discount products with these SKUs (comma separated, * at the end matches the beginning of a SKU).', 'voucher-captain' ),
				'desc_tip'    => true,
				'value'       => (string) $coupon->get_meta( self::META_EXCLUDE ),
			)
		);
	}

	public function save( $coupon_id, $coupon ): void {
		// Nonce verified by WooCommerce before woocommerce_coupon_options_save.
		foreach ( array( self::META, self::META_EXCLUDE ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$coupon->update_meta_data( $key, implode( ', ', array_map( 'strtoupper', self::parse( $value ) ) ) );
		}
		$coupon->save();
	}

	public function valid_for_product( $valid, $product, $coupon ) {
		return $valid && $coupon instanceof \WC_Coupon && ! self::allows( $coupon, $product ) ? false : $valid;
	}

	/** Cart-wide coupons (fixed cart) ignore product checks in WooCommerce, so remove the items here. */
	public function items_to_apply( $items, $coupon ) {
		if ( ! is_array( $items ) || ! $coupon instanceof \WC_Coupon || ! self::has_rules( $coupon ) ) {
			return $items;
		}
		return array_values( array_filter( $items, static fn( $item ) => self::allows( $coupon, $item->product ?? null ) ) );
	}

	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $discounts instanceof \WC_Discounts || ! self::has_rules( $coupon ) ) {
			return $valid;
		}
		foreach ( $discounts->get_items_to_validate() as $item ) {
			if ( self::allows( $coupon, $item->product ?? null ) ) {
				return $valid;
			}
		}
		throw new \Exception( esc_html__( 'Sorry, this coupon is not applicable to the products in your cart.', 'voucher-captain' ), 9050 );
	}
}
