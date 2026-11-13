<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Cryptographically random, collision-checked coupon codes.
 */
final class CodeGenerator {
	public const CHARSETS = array(
		'alnum_upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', // No 0/O/1/I.
		'alpha_upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
		'numeric'     => '0123456789',
		'alnum'       => 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
	);

	/**
	 * Minimum guessing space of codes that carry money (vouchers, gift cards, code pools): 2^50 combinations.
	 * With the rate limits (a few hundred failed attempts per quarter hour) nobody finds a code by guessing.
	 */
	public const MIN_BITS = 50;

	/** Bits of a random code: length × log2(alphabet size). */
	public static function bits( int $length, string $charset = 'alnum_upper' ): float {
		$chars = self::CHARSETS[ $charset ] ?? self::CHARSETS['alnum_upper'];
		return $length * log( strlen( $chars ), 2 );
	}

	/** Shortest random part of a charset that reaches $bits (e.g. 10 letters/digits, 16 digits for 2^50). */
	public static function min_length( string $charset = 'alnum_upper', int $bits = self::MIN_BITS ): int {
		$chars = self::CHARSETS[ $charset ] ?? self::CHARSETS['alnum_upper'];
		return (int) ceil( $bits / log( strlen( $chars ), 2 ) - 1e-9 );
	}

	/**
	 * Estimated guessing space of a given code (e.g. imported from a file), in bits: letters and digits it uses,
	 * case-insensitive like WooCommerce codes; separators and the parts every code of a batch shares (pass
	 * them as $prefix/$suffix) do not count.
	 */
	public static function estimated_bits( string $code, string $prefix = '', string $suffix = '' ): float {
		$code = strtolower( $code );
		if ( '' !== $prefix && str_starts_with( $code, strtolower( $prefix ) ) ) {
			$code = substr( $code, strlen( $prefix ) );
		}
		if ( '' !== $suffix && strlen( $code ) >= strlen( $suffix ) && str_ends_with( $code, strtolower( $suffix ) ) ) {
			$code = substr( $code, 0, strlen( $code ) - strlen( $suffix ) );
		}
		$code     = (string) preg_replace( '/[^a-z0-9]/', '', $code );
		$alphabet = ( preg_match( '/[0-9]/', $code ) ? 10 : 0 ) + ( preg_match( '/[a-z]/', $code ) ? 26 : 0 );
		return $alphabet > 1 ? strlen( $code ) * log( $alphabet, 2 ) : 0.0;
	}

	/**
	 * Common prefix and suffix of a list of codes up to a separator (case-insensitive), e.g. "shop-" of
	 * "SHOP-1234"/"SHOP-5678". Only whole segments count, so random codes that start alike by chance keep
	 * their full length.
	 *
	 * @param string[] $codes
	 * @return array{0:string,1:string}
	 */
	public static function common_affixes( array $codes ): array {
		$codes = array_values( array_map( 'strtolower', array_map( 'strval', $codes ) ) );
		if ( count( $codes ) < 2 ) {
			return array( '', '' );
		}
		$prefix = $codes[0];
		$suffix = $codes[0];
		foreach ( $codes as $code ) {
			while ( '' !== $prefix && ! str_starts_with( $code, $prefix ) ) {
				$prefix = substr( $prefix, 0, -1 );
			}
			while ( '' !== $suffix && ! str_ends_with( $code, $suffix ) ) {
				$suffix = substr( $suffix, 1 );
			}
		}
		$prefix = (string) preg_replace( '/[a-z0-9]+$/', '', $prefix );
		$suffix = (string) preg_replace( '/^[a-z0-9]+/', '', $suffix );
		return array( $prefix, $suffix );
	}

	public static function random( int $length, string $charset = 'alnum_upper' ): string {
		$chars = self::CHARSETS[ $charset ] ?? self::CHARSETS['alnum_upper'];
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * @param array{prefix?:string,suffix?:string,length?:int,charset?:string,separator?:string,order_ref?:string,min_bits?:int} $opts
	 *        min_bits: the random part is made long enough for this guessing space (codes that carry money).
	 */
	public static function build( array $opts ): string {
		$sep     = (string) ( $opts['separator'] ?? '-' );
		$charset = (string) ( $opts['charset'] ?? 'alnum_upper' );
		$length  = max( 4, min( 40, (int) ( $opts['length'] ?? 12 ) ) );
		if ( ! empty( $opts['min_bits'] ) ) {
			$length = max( $length, min( 40, self::min_length( $charset, (int) $opts['min_bits'] ) ) );
		}
		$parts = array_filter(
			array(
				trim( (string) ( $opts['prefix'] ?? '' ) ),
				trim( (string) ( $opts['order_ref'] ?? '' ) ),
				self::random( $length, $charset ),
				trim( (string) ( $opts['suffix'] ?? '' ) ),
			),
			'strlen'
		);
		return implode( $sep, $parts );
	}

	/**
	 * Generate a code that is unused by vouchers and WooCommerce coupons.
	 *
	 * @param callable(string):bool $exists
	 */
	public static function unique( array $opts, callable $exists, int $attempts = 20 ): string {
		for ( $i = 0; $i < $attempts; $i++ ) {
			$code = self::build( $opts );
			if ( ! $exists( $code ) ) {
				return $code;
			}
			$opts['length'] = (int) ( $opts['length'] ?? 10 ) + ( $i > 5 ? 1 : 0 );
		}
		throw new \RuntimeException( 'Unable to generate a unique voucher code.' );
	}
}
