<?php
/**
 * What the voucher commands do in the core: read, check rights, issue, void, reactivate, redeem, write history.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * The core paths CommandService calls. The production backend is CoreCommandBackend (Issuer, RedemptionService,
 * VoucherRepository); tests use a fake.
 *
 * @since 1.3.0 (contract 1.3.0)
 */
interface CommandBackend {
	/**
	 * The voucher with this id, null when unknown.
	 *
	 * @param int $id Voucher id.
	 * @return Voucher|null
	 */
	public function voucher( int $id ): ?Voucher;

	/**
	 * Whether the WordPress user exists and has the capability (user_can(), never the current user).
	 *
	 * @param int    $user_id    User id.
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function user_can( int $user_id, string $capability ): bool;

	/**
	 * Address and display name of a WordPress user, null when the user is unknown or has no valid address.
	 *
	 * @param int $user_id User id.
	 * @return array{email:string, name:string}|null
	 */
	public function recipient( int $user_id ): ?array;

	/**
	 * The shop currency (ISO 4217, upper case).
	 *
	 * @return string
	 */
	public function shop_currency(): string;

	/**
	 * Calendar day of the site (site time zone), Y-m-d.
	 *
	 * @return string
	 */
	public function today(): string;

	/**
	 * Whether a product is a voucher product that issues a fixed value (no percentage, no ticket): '' when it is,
	 * else a refusal code (invalid_product, percent_not_allowed, unsupported_voucher).
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function product_refusal( int $product_id ): string;

	/**
	 * Issues a voucher without an order (Issuer::issue_standalone()).
	 *
	 * @param array<string, mixed> $args Arguments of issue_standalone().
	 * @return Voucher|\WP_Error
	 */
	public function issue( array $args ): Voucher|\WP_Error;

	/**
	 * Voids a voucher without refund (Issuer::void( $voucher, '', false )).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function void( Voucher $voucher ): bool;

	/**
	 * Reactivates a void voucher, never one voided because of a refund (Issuer::reactivate( $voucher, false )).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function reactivate( Voucher $voucher ): bool;

	/**
	 * Whether a void voucher was voided because its purchase was refunded or cancelled.
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function voided_after_refund( Voucher $voucher ): bool;

	/**
	 * Reserves and commits a redemption in one go (RedemptionService::reserve() + commit()); never refunds.
	 *
	 * @param Voucher $voucher Voucher.
	 * @param float   $amount  Amount in the voucher currency (whole value for single-use vouchers).
	 * @param string  $target  Redemption target.
	 * @param string  $key     Redemption key (derived from the idempotency key).
	 * @return Voucher|\WP_Error The voucher as it is after the redemption.
	 */
	public function redeem( Voucher $voucher, float $amount, string $target, string $key ): Voucher|\WP_Error;

	/**
	 * Writes a history entry of the voucher (event "command", coded note without code).
	 *
	 * @param int        $voucher_id Voucher id.
	 * @param float|null $amount     Amount of the call (major units) or null.
	 * @param string     $note       Coded note.
	 */
	public function history( int $voucher_id, ?float $amount, string $note ): void;
}
