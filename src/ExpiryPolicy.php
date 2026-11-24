<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-06: what happens when the validity of a voucher ends (setting "vcap_expiry_behaviour", filter of the same
 * name; the filter wins):
 *
 * - "expires" (default, behaviour of 1.2.0): the voucher expires on its date; the daily maintenance marks it expired
 *   and books its remaining value off ("expired" with the amount, TaxAccounting / M12-29);
 * - "grace": the voucher stays redeemable for "vcap_expiry_grace_days" more days (cart, Store API, orders edited in
 *   the admin, redemption API, counter); it expires – and its value is booked off – only when the grace period ends;
 * - "never": the date is only shown as a notice; the voucher never expires and nothing is booked off. No expiry
 *   reminder is sent in this mode (owner decision OD-3 of 24.11.2026: automatic and bulk-action reminders; see
 *   reminders_enabled()).
 *
 * The printed expiry date stays the date stored on the voucher; only the moment it counts as expired moves. Vouchers
 * that already expired before the setting was changed stay expired (extend them by hand).
 *
 * "vcap_expiry_staff_extension_days" limits how far staff may extend a validity by hand (0 = no limit, as before).
 *
 * Legal and tax weight (derecognition is income; consumer law of the country): not legally checked (TAX.md 6.1a).
 */
final class ExpiryPolicy {
	public const EXPIRES = 'expires';
	public const GRACE   = 'grace';
	public const NEVER   = 'never';
	/** Values of the setting, in the order of the field. */
	public const BEHAVIOURS = array( self::EXPIRES, self::GRACE, self::NEVER );
	/** SQL date no stored expiry date is before: "nothing counts as expired" in a query. */
	private const NO_CUTOFF = '1000-01-01 00:00:00';

	/** Hooks: WooCommerce's own expiry check of a voucher coupon, and the notice when a past-date voucher is applied. */
	public static function register(): void {
		add_filter( 'woocommerce_coupon_validate_expiry_date', array( self::class, 'coupon_expired' ), 10, 2 );
		add_action( 'woocommerce_applied_coupon', array( self::class, 'applied_notice' ), 20 );
	}

	/** Behaviour in effect: the setting, then the filter; anything unknown is "expires". */
	public static function behaviour(): string {
		$stored    = (string) Settings::value( 'vcap_expiry_behaviour' );
		$stored    = in_array( $stored, self::BEHAVIOURS, true ) ? $stored : self::EXPIRES;
		/**
		 * What happens when the validity of a voucher ends: "expires" (booked off at the date), "grace" (still
		 * redeemable for the grace days, booked off when they end) or "never" (date shown as a notice only). Gets
		 * the setting; the filter wins. Not legally checked – derecognition is income.
		 *
		 * @since 1.3.0
		 * @param string $behaviour "expires", "grace" or "never".
		 */
		$behaviour = (string) apply_filters( 'vcap_expiry_behaviour', $stored );
		return in_array( $behaviour, self::BEHAVIOURS, true ) ? $behaviour : self::EXPIRES;
	}

	/** Days a voucher stays redeemable after its date (0 unless the behaviour is "grace"). */
	public static function grace_days(): int {
		if ( self::GRACE !== self::behaviour() ) {
			return 0;
		}
		/**
		 * Days a voucher stays redeemable after its expiry date with the behaviour "grace". Gets the setting; the
		 * filter wins (at least 0).
		 *
		 * @since 1.3.0
		 * @param int $days Grace days.
		 */
		return max( 0, (int) apply_filters( 'vcap_expiry_grace_days', SettingsRegistry::bounded_int( 'vcap_expiry_grace_days' ) ) );
	}

	/**
	 * Unix time from which a voucher with this expiry date (UTC) counts as expired; null = never (no date, or the
	 * behaviour "never").
	 */
	public static function ends_at( ?string $expires_at ): ?int {
		if ( ! $expires_at ) {
			return null;
		}
		$date = strtotime( $expires_at . ' UTC' );
		return false === $date ? null : self::ends_at_time( $date );
	}

	/** ends_at() for a Unix time (e.g. a WooCommerce coupon's expiry). */
	public static function ends_at_time( int $date ): ?int {
		$behaviour = self::behaviour();
		if ( self::NEVER === $behaviour ) {
			return null;
		}
		return self::GRACE === $behaviour ? $date + self::grace_days() * DAY_IN_SECONDS : $date;
	}

	/** Whether a voucher with this expiry date counts as expired now (Voucher::is_expired()). */
	public static function is_expired( ?string $expires_at ): bool {
		$end = self::ends_at( $expires_at );
		return null !== $end && $end < time();
	}

	/** Whether the printed expiry date has passed, whatever the behaviour. */
	public static function date_passed( ?string $expires_at ): bool {
		return (bool) $expires_at && strtotime( $expires_at . ' UTC' ) < time();
	}

	/** Whether the date of this voucher has passed but it is still redeemable (grace period or "never"). */
	public static function past_date_redeemable( Voucher $voucher ): bool {
		return self::date_passed( $voucher->expires_at ) && ! self::is_expired( $voucher->expires_at );
	}

	/**
	 * UTC date for queries: vouchers whose expiry date is before it count as expired at $at (UTC, "Y-m-d H:i:s").
	 * With "never" a date no voucher has, so "expires_at < cutoff" is never true.
	 */
	public static function sql_cutoff( string $at ): string {
		$behaviour = self::behaviour();
		if ( self::NEVER === $behaviour ) {
			return self::NO_CUTOFF;
		}
		$time = strtotime( $at . ' UTC' );
		return gmdate( 'Y-m-d H:i:s', ( false === $time ? time() : $time ) - self::grace_days() * DAY_IN_SECONDS );
	}

	/** Whether the daily maintenance marks overdue vouchers as expired (and books their value off) at all. */
	public static function expires_ever(): bool {
		return self::NEVER !== self::behaviour();
	}

	/**
	 * OD-3 (owner decision 24.11.2026): whether expiry reminders are sent at all. With "never" a voucher does not
	 * expire, so no reminder is sent (daily maintenance, its query and the bulk action "Send expiry reminder");
	 * "expires" and "grace" keep the reminders unchanged (counted from the printed date, as before).
	 */
	public static function reminders_enabled(): bool {
		return self::NEVER !== self::behaviour();
	}

	/**
	 * Notice for a voucher whose date has passed but that is still redeemable ('' otherwise): the date and, in the
	 * grace period, its last day.
	 */
	public static function notice( Voucher $voucher ): string {
		if ( ! self::past_date_redeemable( $voucher ) ) {
			return '';
		}
		$format = (string) get_option( 'vcap_date_format', '' );
		$format = '' !== $format ? $format : (string) get_option( 'date_format', 'Y-m-d' );
		$date   = wp_date( $format, (int) strtotime( $voucher->expires_at . ' UTC' ) );
		$end    = self::ends_at( $voucher->expires_at );
		if ( null === $end ) {
			/* translators: %s: expiry date printed on the voucher */
			return sprintf( __( 'The validity of this voucher ended on %s. The shop still accepts it.', 'voucher-captain' ), $date );
		}
		/* translators: 1: expiry date printed on the voucher, 2: last day it is still accepted */
		return sprintf( __( 'The validity of this voucher ended on %1$s. The shop still accepts it until %2$s.', 'voucher-captain' ), $date, wp_date( $format, $end ) );
	}

	/**
	 * WooCommerce's own expiry check ("woocommerce_coupon_validate_expiry_date") of a Voucher Captain coupon follows
	 * the behaviour: the coupon carries the printed date, the voucher counts as expired only when ends_at() passed.
	 * Other coupons are left alone.
	 *
	 * @param bool       $expired What WooCommerce decided.
	 * @param \WC_Coupon $coupon  Coupon.
	 */
	public static function coupon_expired( $expired, $coupon ) {
		if ( ! $expired || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) || self::EXPIRES === self::behaviour() ) {
			return $expired;
		}
		$date = $coupon->get_date_expires();
		if ( ! $date ) {
			return $expired;
		}
		$end = self::ends_at_time( $date->getTimestamp() );
		return null !== $end && $end < time();
	}

	/**
	 * Cart notice when a voucher past its date is applied in the grace period or with "never" (instead of the
	 * "expired" error).
	 *
	 * @param string $code Coupon code.
	 */
	public static function applied_notice( $code ): void {
		if ( self::EXPIRES === self::behaviour() || ! function_exists( 'vcap' ) || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}
		$voucher = vcap()->vouchers->get_by_code( (string) $code );
		$notice  = $voucher ? self::notice( $voucher ) : '';
		if ( '' !== $notice ) {
			wc_add_notice( esc_html( $notice ), 'notice' );
		}
	}

	/** Days staff may extend a validity by hand (0 = no limit, the behaviour of 1.2.0): setting, then filter. */
	public static function staff_extension_days(): int {
		/**
		 * Days staff may extend the validity of a voucher by hand (voucher edit and bulk action "Extend validity"),
		 * counted from its current expiry date or today, whichever is later. 0 = no limit. Gets the setting; the
		 * filter wins.
		 *
		 * @since 1.3.0
		 * @param int $days Days, 0 = no limit.
		 */
		return max( 0, (int) apply_filters( 'vcap_expiry_staff_extension_days', SettingsRegistry::bounded_int( 'vcap_expiry_staff_extension_days' ) ) );
	}

	/**
	 * Latest expiry (Unix time) staff may set for this voucher: the later of its current expiry date and now, plus the
	 * extension days, to the end of that day. Null = no limit.
	 */
	public static function staff_extension_limit( Voucher $voucher ): ?int {
		$days = self::staff_extension_days();
		if ( $days <= 0 ) {
			return null;
		}
		$current = $voucher->expires_at ? (int) strtotime( $voucher->expires_at . ' UTC' ) : 0;
		return max( $current, time() ) + $days * DAY_IN_SECONDS + DAY_IN_SECONDS - 1;
	}

	/**
	 * Whether staff may set this new expiry (UTC; null = never expires) on the voucher. Shortening and keeping the date
	 * are always allowed; removing the date of a dated voucher is an unlimited extension.
	 */
	public static function staff_may_set( Voucher $voucher, ?string $new_expires ): bool {
		$limit = self::staff_extension_limit( $voucher );
		if ( null === $limit || ! $voucher->expires_at ) {
			return true; // No limit, or a voucher that never expired gets a date (shorter, never longer).
		}
		if ( null === $new_expires ) {
			return false;
		}
		$new = (int) strtotime( $new_expires . ' UTC' );
		return $new <= $limit || $new <= (int) strtotime( $voucher->expires_at . ' UTC' );
	}
}
