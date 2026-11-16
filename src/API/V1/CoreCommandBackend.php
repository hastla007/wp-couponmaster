<?php
/**
 * Core paths of the voucher commands.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Issuer;
use VCAP\ProductConfig;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * CommandBackend on the plugin's own services: the commands go through the same paths as the admin (issue without an
 * order, void, reactivate) and the redemption API, so the core keeps its checks, history entries, hooks and mails.
 *
 * @since 1.3.0 (contract 1.3.0)
 */
final class CoreCommandBackend implements CommandBackend {
	/**
	 * Construct the backend.
	 *
	 * @param VoucherRepository $vouchers    Repository.
	 * @param Issuer            $issuer      Issuer.
	 * @param RedemptionService $redemptions Redemption API.
	 */
	public function __construct( private VoucherRepository $vouchers, private Issuer $issuer, private RedemptionService $redemptions ) {}

	/**
	 * The voucher with this id, null when unknown.
	 *
	 * @param int $id Voucher id.
	 * @return Voucher|null
	 */
	public function voucher( int $id ): ?Voucher {
		return $id > 0 ? $this->vouchers->get( $id ) : null;
	}

	/**
	 * Whether the WordPress user exists and has the capability (user_can(), never the current user).
	 *
	 * @param int    $user_id    User id.
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function user_can( int $user_id, string $capability ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user instanceof \WP_User && $user->exists() && user_can( $user, $capability );
	}

	/**
	 * Address and display name of a WordPress user, null when the user is unknown or has no valid address.
	 *
	 * @param int $user_id User id.
	 * @return array{email:string, name:string}|null
	 */
	public function recipient( int $user_id ): ?array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User || ! $user->exists() || ! is_email( (string) $user->user_email ) ) {
			return null;
		}
		return array(
			'email' => (string) $user->user_email,
			'name'  => (string) $user->display_name,
		);
	}

	/**
	 * The shop currency (ISO 4217, upper case).
	 *
	 * @return string
	 */
	public function shop_currency(): string {
		return strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
	}

	/**
	 * Calendar day of the site (site time zone), Y-m-d.
	 *
	 * @return string
	 */
	public function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Whether a product is a voucher product that issues a fixed value (no percentage, no ticket): '' when it is,
	 * else a refusal code (invalid_product, percent_not_allowed, unsupported_voucher).
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function product_refusal( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product || ! ProductConfig::is_voucher( $product ) ) {
			return 'invalid_product';
		}
		$config = ProductConfig::get( $product );
		if ( 'percent' === ( $config['discount_type'] ?? '' ) ) {
			return 'percent_not_allowed';
		}
		if ( in_array( (string) ( $config['type'] ?? '' ), array( 'ticket', 'credit' ), true ) ) {
			return 'unsupported_voucher';
		}
		return '';
	}

	/**
	 * Issues a voucher without an order (Issuer::issue_standalone()).
	 *
	 * @param array<string, mixed> $args Arguments of issue_standalone().
	 * @return Voucher|\WP_Error
	 */
	public function issue( array $args ): Voucher|\WP_Error {
		return $this->issuer->issue_standalone( $args );
	}

	/**
	 * Voids a voucher without refund (Issuer::void( $voucher, '', false )).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function void( Voucher $voucher ): bool {
		return $this->issuer->void( $voucher, '', false );
	}

	/**
	 * Reactivates a void voucher, never one voided because of a refund (Issuer::reactivate( $voucher, false )).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function reactivate( Voucher $voucher ): bool {
		return $this->issuer->reactivate( $voucher, false );
	}

	/**
	 * Whether a void voucher was voided because its purchase was refunded or cancelled.
	 *
	 * @param Voucher $voucher Voucher.
	 * @return bool
	 */
	public function voided_after_refund( Voucher $voucher ): bool {
		return $this->issuer->voided_after_refund( $voucher );
	}

	/**
	 * Reserves and commits a redemption in one go (RedemptionService::reserve() + commit()); never refunds.
	 *
	 * @param Voucher $voucher Voucher.
	 * @param float   $amount  Amount in the voucher currency (whole value for single-use vouchers).
	 * @param string  $target  Redemption target.
	 * @param string  $key     Redemption key (derived from the idempotency key).
	 * @return Voucher|\WP_Error The voucher as it is after the redemption.
	 */
	public function redeem( Voucher $voucher, float $amount, string $target, string $key ): Voucher|\WP_Error {
		$reserved = $this->redemptions->reserve(
			(string) $voucher->code, // Inside the core only; the command result never carries it.
			array(
				'key'      => $key,
				'target'   => $target,
				'amount'   => $amount,
				'user_id'  => 0,
				'currency' => $voucher->currency,
			)
		);
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		$committed = $this->redemptions->commit( $key );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		return $this->vouchers->get( $voucher->id ) ?? $voucher;
	}

	/**
	 * Writes a history entry of the voucher (event "command", coded note without code).
	 *
	 * @param int        $voucher_id Voucher id.
	 * @param float|null $amount     Amount of the call (major units) or null.
	 * @param string     $note       Coded note.
	 */
	public function history( int $voucher_id, ?float $amount, string $note ): void {
		$this->vouchers->log( $voucher_id, CommandService::HISTORY_EVENT, $amount, null, $note );
	}
}
