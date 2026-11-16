<?php
/**
 * Public read functions (contract 1.2.0): the state of a voucher by its record id, without code or personal data.
 * Thin wrappers of vcap()->read (VCAP\API\V1\ReadService); see API.md → "Read functions". Loaded by
 * voucher-captain.php once the plugin booted, so function_exists( 'vcap_read_voucher' ) tells they can be used.
 *
 * @package VCAP
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vcap_read_voucher' ) ) {
	/**
	 * State of a voucher, gift card, ticket or store credit account by record id; null for an unknown id.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param int|string $order_id WooCommerce order id.
	 * @return array<string, mixed>|null
	 */
	function vcap_read_order_vouchers( int|string $order_id ): ?array {
		return function_exists( 'vcap' ) && isset( vcap()->read ) ? vcap()->read->order_vouchers( $order_id ) : null;
	}
}
