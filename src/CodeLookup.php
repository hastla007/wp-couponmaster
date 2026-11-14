<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One rate limit and one answer for every place where a visitor enters a voucher or coupon code: the redeem form,
 * coupon links, WooCommerce's own coupon entry (CouponGuard), the balance check, the gift card top-up and – by
 * address – the redemption API.
 *
 * All of them count in ONE bucket per visitor (address; IPv6 by /64 and /48, see Plugin::rate_limited()), so the
 * quotas of the different forms do not add up. An attempt counts before the lookup (begin()); an existing code
 * gives it back (found()), an unknown code is booked as a failure (failed()). A code the visitor may not use
 * – another customer's store credit – counts and reads like an unknown code (confirms(), visible()), so no
 * surface confirms that it exists.
 */
final class CodeLookup {
	public const BUCKET      = 'code';
	public const PER_VISITOR = 15;
	public const SITE_WIDE   = 300;
	public const WINDOW      = 900;

	/** Counts a lookup of the current visitor before the code is looked up; true = refused, too many attempts. */
	public static function begin(): bool {
		return Plugin::rate_limited( self::BUCKET, self::PER_VISITOR, self::SITE_WIDE, self::WINDOW, false );
	}

	/** The code exists (and may be shown to this visitor): the attempt is given back. */
	public static function found(): void {
		Plugin::rate_limit_success( self::BUCKET, self::WINDOW );
	}

	/** Unknown code: booked on the site-wide failure counter. */
	public static function failed(): void {
		Plugin::rate_limit_failure( self::BUCKET, self::WINDOW );
	}

	/** Books the outcome of a lookup started with begin(). */
	public static function finish( bool $found ): void {
		if ( $found ) {
			self::found();
		} else {
			self::failed();
		}
	}

	public static function limited_message(): string {
		return __( 'Too many attempts. Please try again later.', 'voucher-captain' );
	}

	public static function not_found_message(): string {
		return __( 'Voucher code not found.', 'voucher-captain' );
	}

	/**
	 * Whether a voucher may be confirmed to the current visitor: everything except another customer's store
	 * credit (which only its owner can use or look up).
	 *
	 * @param int|null $user_id Visitor's user id; null = the current user.
	 */
	public static function visible( ?Voucher $voucher, ?int $user_id = null ): bool {
		if ( ! $voucher ) {
			return false;
		}
		$user_id = $user_id ?? get_current_user_id();
		return 'credit' !== $voucher->type || ( $voucher->user_id > 0 && $user_id === (int) $voucher->user_id );
	}

	/**
	 * Whether a WooCommerce coupon code may be confirmed to the current visitor: it exists and is not another
	 * customer's store credit.
	 */
	public static function confirms( string $code ): bool {
		$id = '' !== $code && strlen( $code ) <= 100 ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		if ( $id <= 0 ) {
			return false;
		}
		$voucher = isset( vcap()->vouchers ) ? vcap()->vouchers->get_by_coupon( $id ) : null;
		return ! $voucher || self::visible( $voucher );
	}
}
