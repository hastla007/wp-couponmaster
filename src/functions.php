<?php
/**
 * Public read functions (contract 1.2.0): the state of a voucher by its record id, without code or personal data.
 * Thin wrappers of vcap()->read (VCAP\API\V1\ReadService); see API.md → "Read functions". Loaded by
 * voucher-captain.php once the plugin booted, so function_exists( 'vcap_read_voucher' ) tells they can be used.
 *
 * Voucher commands (contract 1.3.0): vcap_command_issue(), vcap_command_void(), vcap_command_reactivate(),
 * vcap_command_redeem() – thin wrappers of vcap()->commands (VCAP\API\V1\CommandService); see API.md → "Voucher
 * commands".
 *
 * @package VCAP
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vcap_read_voucher' ) ) {
	/**
	 * State of a voucher, gift card, ticket or store credit account by record id; null for an unknown id.
	 *
	 * @since 1.3.0
	 * @param int|string $id Voucher id.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_voucher( int|string $id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->voucher( $id ) : null;
	}
}

if ( ! function_exists( 'vcap_read_gift_card' ) ) {
	/**
	 * State of a gift card by record id, with its top-up counts; null when no gift card has this id.
	 *
	 * @since 1.3.0
	 * @param int|string $id Voucher id of the gift card.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_gift_card( int|string $id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->gift_card( $id ) : null;
	}
}

if ( ! function_exists( 'vcap_read_store_credit' ) ) {
	/**
	 * Store credit account of a customer by WordPress user id; null when the user has none.
	 *
	 * @since 1.3.0
	 * @param int|string $user_id WordPress user id.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_store_credit( int|string $user_id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->store_credit( $user_id ) : null;
	}
}

if ( ! function_exists( 'vcap_read_redemption' ) ) {
	/**
	 * An external redemption by its record id; null for an unknown id.
	 *
	 * @since 1.3.0
	 * @param int|string $id Redemption record id.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_redemption( int|string $id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->redemption( $id ) : null;
	}
}

if ( ! function_exists( 'vcap_read_redemptions' ) ) {
	/**
	 * External redemptions of a voucher, newest first; null when no voucher has this id.
	 *
	 * @since 1.3.0
	 * @param int|string $voucher_id Voucher id.
	 * @return array<int, array<string, mixed>>|null
	 */
	function vcap_read_redemptions( int|string $voucher_id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->redemptions( $voucher_id ) : null;
	}
}

if ( ! function_exists( 'vcap_read_order_vouchers' ) ) {
	/**
	 * Number and record ids of the vouchers of a WooCommerce order; null when the order has none.
	 *
	 * @since 1.3.0
	 * @param int|string $order_id WooCommerce order id.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_order_vouchers( int|string $order_id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->order_vouchers( $order_id ) : null;
	}
}

if ( ! function_exists( 'vcap_command_unavailable' ) ) {
	/**
	 * Reply of a command when the plugin has not booted (vcap()->commands missing).
	 *
	 * @param string $command Command.
	 * @return array<string, mixed>
	 */
	function vcap_command_unavailable( string $command ): array {
		$out = array_fill_keys( \VCAP\API\V1\CommandService::RESULT_KEYS, null );
		return array_merge(
			$out,
			array(
				'status'   => 'failed',
				'code'     => 'unavailable',
				'command'  => $command,
				'final'    => false,
				'replayed' => false,
				'at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}
}

if ( ! function_exists( 'vcap_command_issue' ) ) {
	/**
	 * Command voucher.issue: issues a voucher with a fixed value to a WordPress user (suite spec 8.3.2).
	 *
	 * @since 1.3.0 (contract 1.3.0)
	 * @param array<string, mixed> $args idempotency_key, reason, workflow_id, run_id, step_id, authorized_by_user_id,
	 *                                   recipient_user_id, amount_minor, currency, product_id (optional).
	 * @return array<string, mixed> Reply (CommandService::RESULT_KEYS).
	 */
	function vcap_command_issue( array $args ): array {
		return function_exists( 'vcap' ) && isset( vcap()->commands ) ? vcap()->commands->issue( $args ) : vcap_command_unavailable( 'voucher.issue' );
	}
}

if ( ! function_exists( 'vcap_command_void' ) ) {
	/**
	 * Command voucher.void: voids a voucher by its id, without refund (suite spec 8.3.3).
	 *
	 * @since 1.3.0 (contract 1.3.0)
	 * @param array<string, mixed> $args Common fields plus voucher_id.
	 * @return array<string, mixed> Reply (CommandService::RESULT_KEYS).
	 */
	function vcap_command_void( array $args ): array {
		return function_exists( 'vcap' ) && isset( vcap()->commands ) ? vcap()->commands->void( $args ) : vcap_command_unavailable( 'voucher.void' );
	}
}

if ( ! function_exists( 'vcap_command_reactivate' ) ) {
	/**
	 * Command voucher.reactivate: reactivates a void voucher not voided because of a refund (suite spec 8.3.3).
	 *
	 * @since 1.3.0 (contract 1.3.0)
	 * @param array<string, mixed> $args Common fields plus voucher_id.
	 * @return array<string, mixed> Reply (CommandService::RESULT_KEYS).
	 */
	function vcap_command_reactivate( array $args ): array {
		return function_exists( 'vcap' ) && isset( vcap()->commands ) ? vcap()->commands->reactivate( $args ) : vcap_command_unavailable( 'voucher.reactivate' );
	}
}

if ( ! function_exists( 'vcap_command_redeem' ) ) {
	/**
	 * Command voucher.redeem: redeems a voucher by its id, wholly or partly (suite spec 8.3.4).
	 *
	 * @since 1.3.0 (contract 1.3.0)
	 * @param array<string, mixed> $args Common fields plus voucher_id, amount_minor, currency, target.
	 * @return array<string, mixed> Reply (CommandService::RESULT_KEYS).
	 */
	function vcap_command_redeem( array $args ): array {
		return function_exists( 'vcap' ) && isset( vcap()->commands ) ? vcap()->commands->redeem( $args ) : vcap_command_unavailable( 'voucher.redeem' );
	}
}
