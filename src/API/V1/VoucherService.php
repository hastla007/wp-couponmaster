<?php
namespace VCAP\API\V1;

use VCAP\Contracts\RedemptionProvider;
use VCAP\CouponManager;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stable PHP facade for voucher integrations. Order ids scope idempotent reserve/commit retries.
 *
 * @since 1.1.0
 */
final class VoucherService {
	public function __construct( private VoucherRepository $vouchers, private CouponManager $coupons ) {}

	/** @return array<string, mixed>|\WP_Error */
	public function lookup( string $code ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->lookup( $code );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function reserve( string $code, float $amount, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->reserve( $code, $amount, $order );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function commit( string $code, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->commit( $code, $order );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function release( string $code, \WC_Order $order ) {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : $provider->release( $code, $order );
	}

	/** @return RedemptionProvider|\WP_Error */
	private function provider( string $code ) {
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
