<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * V6: identity check of personal vouchers (WooCommerce coupons restricted to email addresses: "buyer", "recipient",
 * "buyer or recipient", referral rewards). WooCommerce itself compares the restriction with the billing email typed
 * at checkout, which anyone who knows the address can enter. A personal voucher is therefore redeemed only by a
 * customer who is logged in with an account whose email matches the restriction.
 *
 * "Verified" address: WordPress has no verification flag. The account email (user_email) counts as verified, because
 * accounts are created through WordPress/WooCommerce registration, which sends the set-password link to that address
 * (filter "vcap_personal_voucher_email_verified" for shops with their own verification, e.g. a double-opt-in plugin).
 * Staff at the counter (scanner, admin) verify the holder's identity themselves and are not restricted by this.
 */
final class PersonalVoucher {
	/**
	 * Lower-cased email restrictions of a coupon (empty: transferable).
	 *
	 * @return string[]
	 */
	public static function restrictions( \WC_Coupon $coupon ): array {
		return array_values( array_filter( array_map( static fn( $e ) => strtolower( trim( (string) $e ) ), (array) $coupon->get_email_restrictions() ) ) );
	}

	/**
	 * Whether an address matches one of the restrictions (WooCommerce's "*" wildcard included).
	 *
	 * @param string[] $restrictions Lower-cased restrictions.
	 */
	public static function matches( string $email, array $restrictions ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		foreach ( $restrictions as $restriction ) {
			$pattern = '/^' . str_replace( '\*', '.*?', preg_quote( (string) $restriction, '/' ) ) . '$/';
			if ( preg_match( $pattern, $email ) ) {
				return true;
			}
		}
		return false;
	}

	/** The verified email of an account ('' for none): its user_email, see the class comment. */
	public static function verified_email( ?\WP_User $user ): string {
		if ( ! $user || ! $user->exists() || ! is_email( (string) $user->user_email ) ) {
			return '';
		}
		/**
		 * Whether the account email of a customer counts as verified for personal vouchers (V6). Default true: the
		 * account was created through WordPress/WooCommerce, which sends the set-password link to that address.
		 *
		 * @since 1.2.0
		 * @param bool     $verified Whether the address is verified.
		 * @param \WP_User $user     Customer account.
		 */
		return apply_filters( 'vcap_personal_voucher_email_verified', true, $user ) ? (string) $user->user_email : '';
	}

	/**
	 * Whether the account may redeem a voucher with these restrictions.
	 *
	 * @param string[] $restrictions Lower-cased restrictions (empty: anyone).
	 */
	public static function allowed_for_user( array $restrictions, ?\WP_User $user ): bool {
		return ! $restrictions || self::matches( self::verified_email( $user ), $restrictions );
	}

	/** Customer-facing hint added to the "Personal – …" texts (voucher, email, PDF, account, product page). */
	public static function login_hint(): string {
		return __( 'Online redemption requires logging in to the customer account with that email address.', 'voucher-captain' );
	}

	/** Staff working on an order in wp-admin (they verify the holder themselves, like at the counter). */
	public static function staff_context(): bool {
		return is_admin() && ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
	}

	/**
	 * Message for a refused personal voucher: whom to log in as (masked addresses only).
	 *
	 * @param string[] $restrictions Lower-cased restrictions.
	 * @param bool     $logged_in    Whether a (non-matching) customer is logged in.
	 */
	public static function refusal( array $restrictions, bool $logged_in ): string {
		$masked = array_values( array_unique( array_map( array( Compat\PassCaptainTickets::class, 'mask_email' ), $restrictions ) ) );
		if ( 1 === count( $masked ) ) {
			return $logged_in
				/* translators: %s: masked email address, e.g. j***@example.com */
				? sprintf( __( 'This personal voucher belongs to another customer account. Please log in with %s to use it.', 'voucher-captain' ), $masked[0] )
				/* translators: %s: masked email address, e.g. j***@example.com */
				: sprintf( __( 'Please log in with %s to use this personal voucher.', 'voucher-captain' ), $masked[0] );
		}
		return $logged_in
			/* translators: %s: list of masked email addresses */
			? sprintf( __( 'This personal voucher belongs to another customer account. Please log in with one of these addresses to use it: %s', 'voucher-captain' ), implode( ', ', $masked ) )
			/* translators: %s: list of masked email addresses */
			: sprintf( __( 'Please log in with one of these addresses to use this personal voucher: %s', 'voucher-captain' ), implode( ', ', $masked ) );
	}
}
