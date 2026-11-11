<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * "First order only" option for any WooCommerce coupon (Marketing → Coupons → Usage restriction).
 *
 * A customer qualifies when no earlier order of theirs (by account or billing email) was paid or is awaiting
 * fulfilment. Guests are checked by billing email as soon as WooCommerce knows it (cart, checkout, Store API).
 */
final class FirstOrderCoupons {
	public const META = '_vcap_first_order';

	public function register(): void {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 25, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );
	}

	public function field( $coupon_id, $coupon ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'First order only', 'voucher-captain' ),
				'description' => __( 'Only customers without an earlier order (by account or billing email) can use this coupon.', 'voucher-captain' ),
				'value'       => $coupon instanceof \WC_Coupon ? $coupon->get_meta( self::META ) : '',
			)
		);
	}

	public function save( $coupon_id, $coupon ): void {
		// Nonce verified by WooCommerce before woocommerce_coupon_options_save.
		$coupon->update_meta_data( self::META, isset( $_POST[ self::META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$coupon->save();
	}

	/**
	 * Whether the customer (user id and/or email) already has an order other than $exclude_order_id.
	 */
	public static function has_previous_order( int $user_id, string $email, int $exclude_order_id = 0, int $before = 0 ): bool {
		$statuses = apply_filters( 'vcap_first_order_counting_statuses', array( 'processing', 'completed', 'on-hold' ) );
		foreach ( array_filter( array( $user_id ?: null, is_email( $email ) ? strtolower( $email ) : null ) ) as $customer ) {
			$query = array(
				'customer' => $customer,
				'status'   => $statuses,
				'limit'    => 2,
				'return'   => 'ids',
				'type'     => 'shop_order',
			);
			if ( $before > 0 ) {
				$query['date_created'] = '<' . $before; // Only orders placed before the one being recalculated.
			}
			$ids = wc_get_orders( $query );
			if ( array_diff( array_map( 'intval', $ids ), array( $exclude_order_id ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Customer identity for the current validation context (cart or order).
	 *
	 * @return array{0:int,1:string,2:int} user id, email, order id to exclude
	 */
	private static function context( $discounts ): array {
		if ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order ) {
			$order = $discounts->get_object();
			return array( (int) $order->get_customer_id(), (string) $order->get_billing_email(), (int) $order->get_id() );
		}
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		$user  = get_current_user_id();
		if ( '' === $email && $user ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		$awaiting = function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( 'order_awaiting_payment' ) : 0;
		return array( $user, $email, $awaiting );
	}

	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( self::META ) ) {
			return $valid;
		}
		list( $user, $email, $exclude ) = self::context( $discounts );
		if ( self::has_previous_order( $user, $email, $exclude ) ) {
			throw new \Exception( esc_html__( 'This coupon is only valid for your first order.', 'voucher-captain' ), 9020 );
		}
		return $valid;
	}

	/**
	 * Classic checkout: re-check with the billing email the guest just entered.
	 */
	public function checkout_validation( $data, $errors ): void {
		if ( ! WC()->cart || ! $errors instanceof \WP_Error ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( 'yes' === $coupon->get_meta( self::META ) && self::has_previous_order( get_current_user_id(), (string) ( $data['billing_email'] ?? '' ) ) ) {
				/* translators: %s: coupon code */
				$errors->add( 'vcap_first_order', sprintf( __( 'The coupon %s is only valid for your first order.', 'voucher-captain' ), strtoupper( $code ) ) );
			}
		}
	}
}
