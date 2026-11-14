<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * V4: one mailbox, one customer. Every per-customer key (first order, promotion uses, bonus, referral owner)
 * compares the normalised address, so "max+1@…" or "m.ax@gmail.com" are the same customer as "max@…".
 */
final class CustomerEmail {
	/** Domains that ignore dots in the local part (and are one mailbox). */
	private const DOTLESS = array( 'gmail.com', 'googlemail.com' );

	/**
	 * The mailbox behind an address: lower case, without a "+tag", Gmail without dots and on gmail.com.
	 * Anything that is no address is only trimmed and lowered.
	 */
	public static function normalize( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! str_contains( $email, '@' ) ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		$local = explode( '+', $local, 2 )[0];
		if ( in_array( $domain, self::DOTLESS, true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/**
	 * Keyed hash (site salt) of the normalised address: recognises a customer without storing the address.
	 * Prefixed "h:" like the promotion usage keys.
	 */
	public static function key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', self::normalize( $email ), wp_salt( 'auth' ) );
	}

	/** The hash of the address as stored before V4 (only lowered), still looked up for existing records. */
	public static function legacy_key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}
}
