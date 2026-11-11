<?php
namespace VCAP\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter contract for Voucher Captain and optional external redemption systems.
 *
 * @since 1.1.0
 */
interface RedemptionProvider {
	public function supports( string $code ): bool;

	/** @return array<string, mixed>|\WP_Error */
	public function lookup( string $code );

	/** @return array<string, mixed>|\WP_Error */
	public function reserve( string $code, float $amount, \WC_Order $order );

	/** @return array<string, mixed>|\WP_Error */
	public function commit( string $code, \WC_Order $order );

	/** @return array<string, mixed>|\WP_Error */
	public function release( string $code, \WC_Order $order );
}
