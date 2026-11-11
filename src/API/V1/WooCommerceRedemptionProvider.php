<?php
namespace VCAP\API\V1;

use VCAP\Contracts\RedemptionProvider;
use VCAP\CouponManager;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce coupon-backed balance provider.
 */
final class WooCommerceRedemptionProvider implements RedemptionProvider {
	public function __construct( private VoucherRepository $vouchers, private CouponManager $coupons ) {}

	public function supports( string $code ): bool {
		return null !== $this->find( $code );
	}

	public function lookup( string $code ) {
		$voucher = $this->find( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		return array(
			'code'       => $voucher->code,
			'type'       => $voucher->type,
			'status'     => $voucher->status,
			'available'  => $voucher->is_usable(),
			'usage_mode' => $voucher->usage_mode,
			'balance'    => $voucher->balance,
			'currency'   => $voucher->currency,
			'expires_at' => $voucher->expires_at,
		);
	}

	public function reserve( string $code, float $amount, \WC_Order $order ) {
		$voucher = $this->find( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( ! $order->get_id() || $amount <= 0 ) {
			return new \WP_Error( 'vcap_invalid_redemption', __( 'A saved order and a positive amount are required.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		if ( ! $voucher->is_multi_use() ) {
			return new \WP_Error( 'vcap_balance_only', __( 'This operation requires a stored-balance voucher.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		$result = $this->coupons->reserve_voucher_balance( $voucher, $amount, $order, __( 'Reserved by an integration', 'voucher-captain' ) );
		if ( ! $result ) {
			return new \WP_Error( 'vcap_balance_conflict', __( 'Voucher balance is unavailable or has changed.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		if ( 'committed' === $result['state'] ) {
			$redemptions          = (array) $order->get_meta( '_vcap_redemptions' );
			$redemptions[$voucher->code] = $result['amount'];
			$order->update_meta_data( '_vcap_redemptions', $redemptions );
		} else {
			$reservations = (array) $order->get_meta( '_vcap_reservations' );
			$reservations[$voucher->code] = $result['amount'];
			$order->update_meta_data( '_vcap_reservations', $reservations );
		}
		$order->save();
		return array(
			'code'              => $voucher->code,
			'state'             => $result['state'],
			'reserved_amount'   => $result['amount'],
			'balance_after'     => $result['balance'],
			'idempotency_key'   => 'order:' . $order->get_id() . ':voucher:' . $voucher->id,
		);
	}

	public function commit( string $code, \WC_Order $order ) {
		$voucher = $this->find( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$result = $this->coupons->commit_voucher_reservation( $voucher, $order );
		if ( ! $result ) {
			return new \WP_Error( 'vcap_reservation_missing', __( 'No matching voucher reservation could be committed.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		$redemptions          = (array) $order->get_meta( '_vcap_redemptions' );
		$redemptions[$voucher->code] = $result['amount'];
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
		unset( $reservations[$voucher->code] );
		$order->update_meta_data( '_vcap_redemptions', $redemptions );
		if ( $reservations ) {
			$order->update_meta_data( '_vcap_reservations', $reservations );
		} else {
			$order->delete_meta_data( '_vcap_reservations' );
		}
		$order->save();
		return array( 'code' => $voucher->code, 'state' => 'committed', 'amount' => $result['amount'], 'balance_after' => $result['balance'] );
	}

	public function release( string $code, \WC_Order $order ) {
		$voucher = $this->find( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( ! $this->coupons->release_voucher_reservation( $voucher, $order, __( 'Released by an integration', 'voucher-captain' ) ) ) {
			return new \WP_Error( 'vcap_reservation_missing', __( 'No matching voucher reservation could be released.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		$reservations = (array) $order->get_meta( '_vcap_reservations' );
		unset( $reservations[$voucher->code] );
		if ( $reservations ) {
			$order->update_meta_data( '_vcap_reservations', $reservations );
		} else {
			$order->delete_meta_data( '_vcap_reservations' );
		}
		$order->save();
		return array( 'code' => $voucher->code, 'state' => 'released' );
	}

	private function find( string $code ): ?Voucher {
		$code = wc_format_coupon_code( sanitize_text_field( $code ) );
		return $this->vouchers->get_by_code( $code ) ?? $this->vouchers->get_by_code( strtoupper( $code ) );
	}
}
