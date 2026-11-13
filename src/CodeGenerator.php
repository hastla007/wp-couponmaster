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
	 * them as $prefix/$suffix) do not count. A single code says nothing about series or check digits: rate the
	 * codes of a file with analyse_batch().
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
	 * Common prefix and suffix of a list of codes, character by character (case-insensitive): "gift2026000" of
	 * "GIFT2026000001"/"GIFT2026000317", "shop-" of "SHOP-1234"/"SHOP-5678". A batch of random codes shares
	 * nothing (the first characters of many random codes do not all match); with only a few codes a character
	 * that matches by chance is taken away, which errs on the safe side. The suffix is looked for after the prefix.
	 *
	 * @param string[] $codes
	 * @return array{0:string,1:string}
	 */
	public static function common_affixes( array $codes ): array {
		$codes = array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $codes ) ) ) );
		if ( count( $codes ) < 2 ) {
			return array( '', '' );
		}
		$prefix = $codes[0];
		foreach ( $codes as $code ) {
			while ( '' !== $prefix && ! str_starts_with( $code, $prefix ) ) {
				$prefix = substr( $prefix, 0, -1 );
			}
		}
		$rests  = array_map( static fn( $code ) => (string) substr( $code, strlen( $prefix ) ), $codes );
		$suffix = $rests[0];
		foreach ( $rests as $rest ) {
			while ( '' !== $suffix && ! str_ends_with( $rest, $suffix ) ) {
				$suffix = substr( $suffix, 1 );
			}
		}
		return array( $prefix, $suffix );
	}

	/** Whether a string of digits ends with a valid Luhn check digit (payment cards, many gift card numbers). */
	public static function luhn_valid( string $digits ): bool {
		if ( strlen( $digits ) < 2 || ! ctype_digit( $digits ) ) {
			return false;
		}
		$sum = 0;
		foreach ( str_split( strrev( $digits ) ) as $i => $d ) {
			$d = (int) $d;
			if ( 1 === $i % 2 ) {
				$d *= 2;
				$d  = $d > 9 ? $d - 9 : $d;
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}

	/** Whether a string of digits ends with a valid GS1/EAN check digit (weights 3 and 1 from the right). */
	public static function gs1_valid( string $digits ): bool {
		if ( strlen( $digits ) < 2 || ! ctype_digit( $digits ) ) {
			return false;
		}
		$sum = 0;
		foreach ( str_split( strrev( substr( $digits, 0, -1 ) ) ) as $i => $d ) {
			$sum += (int) $d * ( 0 === $i % 2 ? 3 : 1 );
		}
		return (int) substr( $digits, -1 ) === ( 10 - $sum % 10 ) % 10;
	}

	/** A batch needs this many all-digit codes before a check digit or a series is recognised. */
	private const MIN_SERIES = 5;

	/**
	 * Strength of a batch of codes (e.g. imported from a file) in bits per code. Besides what estimated_bits()
	 * counts, everything is taken away that makes one code of the batch predictable from another:
	 * - the prefix and suffix all codes share (character by character),
	 * - a check digit (Luhn or GS1/EAN) every code carries – it follows from the other digits,
	 * - numbered series: numbers that follow each other with the same or nearly the same step, or a number much
	 *   closer to another code of the batch than random numbers would be. Such numbers count as 0 bits, only the
	 *   rest of the code (e.g. random letters around it) counts.
	 *
	 * $context are codes already in the batch: for a file with a single code they give the shared structure, and
	 * a new code next to an existing number counts as part of the series. They are not rated themselves.
	 *
	 * @param array<int|string, string> $codes   Codes to rate (keys are kept).
	 * @param string[]                  $context Codes already in the same batch.
	 * @return array{prefix:string, suffix:string, check_digit:bool, sequential:bool, bits:array<int|string, float>}
	 */
	public static function analyse_batch( array $codes, array $context = array(), int $min_bits = self::MIN_BITS ): array {
		$codes   = array_map( 'strval', $codes );
		$context = array_values( array_diff( array_map( 'strval', $context ), $codes ) );
		$basis   = count( array_unique( array_map( 'strtolower', $codes ) ) ) >= 2 ? array_values( $codes ) : array_merge( array_values( $codes ), $context );
		list( $prefix, $suffix ) = self::common_affixes( $basis );

		$rest = static function ( string $code ) use ( $prefix, $suffix ): ?string {
			$code = strtolower( $code );
			if ( ( '' !== $prefix && ! str_starts_with( $code, $prefix ) ) || ( '' !== $suffix && ! str_ends_with( substr( $code, strlen( $prefix ) ), $suffix ) ) ) {
				return null; // Another structure (e.g. an older part of the batch).
			}
			$code = substr( $code, strlen( $prefix ) );
			$code = '' !== $suffix ? substr( $code, 0, strlen( $code ) - strlen( $suffix ) ) : $code;
			return (string) preg_replace( '/[^a-z0-9]/', '', $code );
		};
		$rests     = array_map( $rest, $codes );
		$ctx_rests = array_values( array_filter( array_map( $rest, $context ), static fn( $r ) => null !== $r && '' !== $r ) );

		// Check digit: the number of every code (its longest run of digits, separators ignored – at least
		// MIN_SERIES codes) passes the same check.
		$full = static function ( string $code ): string {
			preg_match_all( '/[0-9]+/', (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $code ) ), $m );
			$run = '';
			foreach ( $m[0] as $candidate ) {
				$run = strlen( $candidate ) > strlen( $run ) ? $candidate : $run;
			}
			return $run;
		};
		$digits      = array_values( array_filter( array_map( $full, count( $codes ) >= 2 ? $codes : array_merge( $codes, $context ) ), static fn( $d ) => strlen( $d ) >= 4 ) );
		$enough      = count( $digits ) >= self::MIN_SERIES;
		$check_digit = $enough && ( count( $digits ) === count( array_filter( $digits, array( self::class, 'luhn_valid' ) ) ) || count( $digits ) === count( array_filter( $digits, array( self::class, 'gs1_valid' ) ) ) );

		// The number of each code (without a check digit) and what is left around it: all-digit codes are a
		// number; in other codes the longest run of digits, if all codes have the same layout of letters and
		// digits (e.g. "K7QM2X9ZPA-000123"). Random letter/digit codes have no common layout and no number.
		$shape  = static fn( ?string $r ): string => null === $r ? '' : (string) preg_replace( array( '/[a-z]/', '/[0-9]/' ), array( 'a', '9' ), $r );
		$shapes = array_unique( array_map( $shape, array_filter( $rests, static fn( $r ) => null !== $r ) ) );
		$layout = 1 === count( $shapes ) ? (string) reset( $shapes ) : null;
		$split  = static function ( ?string $r ) use ( $check_digit, $shape, $layout ): ?array {
			if ( null === $r || '' === $r ) {
				return null;
			}
			if ( ctype_digit( $r ) ) {
				$number = $check_digit ? substr( $r, 0, -1 ) : $r;
				return strlen( $number ) >= 2 ? array( $number, '' ) : null;
			}
			if ( $shape( $r ) !== $layout || ! preg_match_all( '/[0-9]+/', $r, $m ) ) {
				return null;
			}
			$run = '';
			foreach ( $m[0] as $candidate ) {
				$run = strlen( $candidate ) > strlen( $run ) ? $candidate : $run;
			}
			if ( strlen( $run ) < 3 ) {
				return null;
			}
			$pos = (int) strpos( $r, $run );
			return array( $run, substr( $r, 0, $pos ) . substr( $r, $pos + strlen( $run ) ) );
		};
		$numbers = array();
		foreach ( $rests as $key => $r ) {
			$parts = $split( $r );
			if ( $parts ) {
				$numbers[ $key ] = $parts;
			}
		}
		$others = array();
		foreach ( $ctx_rests as $r ) {
			$parts = $split( $r );
			if ( $parts ) {
				$others[] = (float) $parts[0];
			}
		}
		$series = self::series( array_map( static fn( $p ) => $p[0], $numbers ), $others, $min_bits );

		$bits = array();
		foreach ( $codes as $key => $code ) {
			if ( isset( $series[ $key ] ) ) {
				$bits[ $key ] = self::estimated_bits( $numbers[ $key ][1] ); // Only what is around the number counts.
				continue;
			}
			$bits[ $key ] = self::estimated_bits( $code, $prefix, $suffix );
			if ( $check_digit && null !== $rests[ $key ] && ctype_digit( $rests[ $key ] ) ) {
				$bits[ $key ] = max( 0.0, $bits[ $key ] - log( 10, 2 ) );
			}
		}
		return array(
			'prefix'      => $prefix,
			'suffix'      => $suffix,
			'check_digit' => $check_digit,
			'sequential'  => (bool) $series,
			'bits'        => $bits,
		);
	}

	/**
	 * Numbers of a batch that are part of a series: all of them when they follow each other with the same or
	 * nearly the same step (or with gaps that vary much less than between random numbers), and each number that
	 * lies much closer to another number of the batch (or of $others) than chance explains – unless finding it
	 * from there would still take 2^$min_bits attempts.
	 *
	 * @param array<int|string, string> $numbers Strings of digits, keys of the codes.
	 * @param float[]                   $others  Numbers already in the batch.
	 * @return array<int|string, true> keys of the numbers that are part of a series
	 */
	private static function series( array $numbers, array $others, int $min_bits ): array {
		if ( ! $numbers || count( $numbers ) + count( $others ) < 2 ) {
			return array();
		}
		$length = max( array_map( 'strlen', $numbers ) );
		$space  = 10 ** $length;
		$values = array_map( 'floatval', $numbers );
		$found  = array();

		// Same step, nearly the same step, or gaps that vary far less than between random numbers. A few new codes
		// are also checked together with the codes already in the batch (does the new number continue the series?).
		if ( self::even_steps( $values ) || ( count( $values ) < self::MIN_SERIES && $others && self::even_steps( array_merge( $values, $others ) ) ) ) {
			return array_fill_keys( array_keys( $numbers ), true );
		}

		// Neighbours: random numbers of this length are this close to another one with a probability below 1e-6.
		$all = array();
		foreach ( $values as $key => $value ) {
			$all[] = array( $value, $key );
		}
		foreach ( $others as $value ) {
			$all[] = array( $value, null );
		}
		usort( $all, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$m = count( $all ) - 1;
		foreach ( $all as $i => list( $value, $key ) ) {
			if ( null === $key ) {
				continue;
			}
			$near = min( $i > 0 ? $value - $all[ $i - 1 ][0] : INF, $i < $m ? $all[ $i + 1 ][0] - $value : INF );
			$near = max( 1.0, $near ); // Equal numbers (e.g. "007" and "7") are neighbours too.
			if ( 2 * $m * $near < 1e-6 * $space && log( 2 * $near, 2 ) < $min_bits ) {
				$found[ $key ] = true;
			}
		}
		return $found;
	}

	/**
	 * Whether sorted numbers follow each other with the same step (3 numbers or more), nearly the same step
	 * (MIN_SERIES or more) or gaps that vary far less than between random numbers (10 or more).
	 *
	 * @param float[] $values
	 */
	private static function even_steps( array $values ): bool {
		$sorted = array_values( array_unique( $values, SORT_REGULAR ) );
		sort( $sorted );
		$n = count( $sorted );
		if ( $n < 3 ) {
			return false;
		}
		$gaps = array();
		for ( $i = 1; $i < $n; $i++ ) {
			$gaps[] = $sorted[ $i ] - $sorted[ $i - 1 ];
		}
		$mean   = array_sum( $gaps ) / count( $gaps );
		$spread = max( $gaps ) - min( $gaps );
		$exact  = 1e-9 * max( 1.0, (float) end( $sorted ) ); // Rounding of very long numbers.
		$var    = array_sum( array_map( static fn( $g ) => ( $g - $mean ) ** 2, $gaps ) ) / count( $gaps );
		$cv     = $mean > 0 ? sqrt( $var ) / $mean : 0.0;
		// Thresholds: random numbers meet them with a probability below about 1e-5 (simulated).
		return $spread <= $exact || ( $n >= self::MIN_SERIES && $spread <= 0.05 * $mean ) || ( $n >= 10 && $cv < 0.15 ) || ( $n >= 20 && $cv < 0.3 );
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
