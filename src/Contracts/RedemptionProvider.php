<?php
/**
 * Adapter contract for Voucher Captain and optional external redemption systems.
 *
 * @package VCAP\Contracts
 */

namespace VCAP\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Provider contract for lookup and order-scoped voucher redemption.
 *
 * @since 1.1.0
 */
interface RedemptionProvider {
	/**
	 * Check whether this provider owns the voucher code.
	 *
	 * @param string $code Voucher code.
	 * @return bool Whether the provider supports this code.
	 */
	public function supports( string $code ): bool;

	/**
	 * Return a normalized voucher lookup result.
	 *
	 * @param string $code Voucher code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function lookup( string $code );

	/**
	 * Reserve voucher value against an order.
	 *
	 * @param string    $code Voucher code.
	 * @param float     $amount Amount to reserve.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reserve( string $code, float $amount, \WC_Order $order );

	/**
	 * Commit a prior reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function commit( string $code, \WC_Order $order );

	/**
	 * Release a prior reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release( string $code, \WC_Order $order );
}
