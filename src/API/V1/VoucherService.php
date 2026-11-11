<?php
/**
 * Stable PHP facade for voucher integrations.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Contracts\RedemptionProvider;
use VCAP\CouponManager;
use VCAP\Issuer;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stable PHP facade for voucher integrations. Order ids scope idempotent reserve/commit retries.
 *
 * @since 1.1.0
 */
final class VoucherService {
	/**
	 * Construct the voucher service.
	 *
	 * @param VoucherRepository $vouchers Voucher repository.
	 * @param CouponManager     $coupons Coupon manager.
	 */
	public function __construct( private VoucherRepository $vouchers, private CouponManager $coupons ) {}

	/**
	 * Look up a voucher through the configured provider.
	 *
	 * @param string $code Voucher code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function lookup( string $code ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->lookup( $code );
	}

	/**
	 * Reserve a balance amount for a saved order.
	 *
	 * @param string    $code Voucher code.
	 * @param float     $amount Amount to reserve.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reserve( string $code, float $amount, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->reserve( $code, $amount, $order );
	}

	/**
	 * Commit a saved order's reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function commit( string $code, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->commit( $code, $order );
	}

	/**
	 * Release a saved order's reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release( string $code, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->release( $code, $order );
	}

	/**
	 * Order and refund data per order line, for connector plugins (e.g. to grant or revoke access per line).
	 *
	 * Each product line contains its quantities, totals and refunds in the order currency, the voucher line
	 * snapshot (face value, voucher currency, tax data, allowed channels) and the vouchers issued for it.
	 *
	 * @since 1.1.0
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>> Lines keyed by order item id.
	 */
	public function order_lines( \WC_Order $order ): array {
		$vouchers = array();
		foreach ( $this->vouchers->for_order( $order->get_id() ) as $voucher ) {
			$vouchers[ $voucher->order_item_id ][] = array(
				'id'       => $voucher->id,
				'code'     => $voucher->code,
				'type'     => $voucher->type,
				'status'   => $voucher->status,
				'value'    => $voucher->initial_amount,
				'balance'  => $voucher->balance,
				'currency' => $voucher->currency,
				'expires'  => $voucher->expires_at,
			);
		}
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$lines[ (int) $item_id ] = array(
				'item_id'           => (int) $item_id,
				'product_id'        => (int) $item->get_product_id(),
				'variation_id'      => (int) $item->get_variation_id(),
				'name'              => $item->get_name(),
				'quantity'          => (int) $item->get_quantity(),
				'refunded_quantity' => abs( (int) $order->get_qty_refunded_for_item( $item_id ) ),
				'subtotal'          => (float) $item->get_subtotal(),
				'subtotal_tax'      => (float) $item->get_subtotal_tax(),
				'total'             => (float) $item->get_total(),
				'total_tax'         => (float) $item->get_total_tax(),
				'refunded_total'    => (float) $order->get_total_refunded_for_item( $item_id ),
				'currency'          => $order->get_currency(),
				'voucher_line'      => Issuer::line_data( $item ),
				'vouchers'          => $vouchers[ (int) $item_id ] ?? array(),
			);
		}
		/**
		 * Filters the per-line order data handed to connector plugins.
		 *
		 * @since 1.1.0
		 * @param array<int, array<string, mixed>> $lines Lines keyed by order item id.
		 * @param \WC_Order                        $order Order.
		 */
		return (array) apply_filters( 'vcap_order_lines', $lines, $order );
	}

	/**
	 * Find the first provider that supports the voucher code.
	 *
	 * @param string $code Voucher code.
	 * @return RedemptionProvider|\WP_Error
	 */
	private function provider( string $code ) {
		/**
		 * Filters the redemption providers available for a voucher code.
		 *
		 * @since 1.1.0
		 * @param RedemptionProvider[] $providers Registered provider adapters.
		 * @param string               $code      Voucher code being resolved.
		 * @param VoucherService       $service   Voucher service instance.
		 */
		$providers = apply_filters(
			'vcap_redemption_providers',
			array( new WooCommerceRedemptionProvider( $this->vouchers, $this->coupons ) ),
			$code,
			$this
		);
		foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
			if ( $provider instanceof RedemptionProvider && $provider->supports( $code ) ) {
				return $provider;
			}
		}
		return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
	}
}
