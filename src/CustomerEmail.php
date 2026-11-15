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

	/** Option holding the persistent key of the customer hashes (AP-S3-4). */
	public const KEY_OPTION = 'vcap_customer_key_secret';

	/**
	 * Keyed hash of the normalised address: recognises a customer without storing the address. Prefixed "h:" like
	 * the promotion usage keys. AP-S3-4: keyed with a persistent key of the plugin (KEY_OPTION), not with
	 * wp_salt( 'auth' ) – rotating the WordPress salts (a routine security measure) must not reset every
	 * per-customer limit. Hashes stored before are still found through salt_key() and legacy_key() (keys()).
	 */
	public static function key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', self::normalize( $email ), self::secret() );
	}

	/** The hash of the normalised address as stored before AP-S3-4 (keyed with the site salt), still looked up. */
	public static function salt_key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', self::normalize( $email ), wp_salt( 'auth' ) );
	}

	/** The hash of the address as stored before V4 (only lowered, site salt), still looked up for existing records. */
	public static function legacy_key( string $email ): string {
		return 'h:' . hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Every stored form of an address, current first: key(), salt_key() (before AP-S3-4), legacy_key() (before V4).
	 *
	 * @return string[]
	 */
	public static function keys( string $email ): array {
		return array_values( array_unique( array( self::key( $email ), self::salt_key( $email ), self::legacy_key( $email ) ) ) );
	}

	/**
	 * The persistent key: created once with Settings::insert_once(), which never overwrites (M12-17: add_option()
	 * does when "notoptions" is stale) – a failed read is answered from the database again instead of replacing the key (which would make every stored hash unrecognisable). If it
	 * still cannot be read, a key for this request only is used and nothing is stored.
	 */
	public static function secret(): string {
		static $runtime = '';
		$secret = (string) get_option( self::KEY_OPTION );
		if ( '' !== $secret ) {
			return $secret;
		}
		$stored = Settings::insert_once( self::KEY_OPTION, wp_generate_password( 64, true, true ), true );
		if ( '' !== $stored ) {
			return $stored;
		}
		if ( '' === $runtime ) {
			$runtime = wp_generate_password( 64, true, true );
		}
		return $runtime;
	}
}
