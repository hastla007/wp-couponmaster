<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Strict parsing of typed amounts (admin balance adjustments, store credit, customer amounts, presets).
 *
 * The same rules as the counter scanner (assets/js/scanner-amount.js), so the server never guesses either:
 * digits with at most one decimal separator ("," or ".") and optional thousands separators (".", ",", space,
 * apostrophe) in valid positions only. "1.500" / "1,500" (one separator, three digits after it) is ambiguous
 * and refused, unless the currency has three decimals and the separator is the shop's decimal separator.
 * "1e3", "12,50; 25", "--5" are invalid; a minus sign (also "−", "–", "—") is "negative" unless allowed.
 */
final class Amount {
	private const SEPARATORS = '/[.,\'’\s\x{00A0}\x{202F}]/u';
	private const MINUS      = '/^[-−‐‑‒–—]/u';
	private const SPACE      = '[\s\x{00A0}\x{202F}]';

	/**
	 * @param mixed                $raw  What was typed.
	 * @param array<string, mixed> $opts decimals (int, default: shop decimals), decimal_sep (default: shop),
	 *                                   strip (string[] currency symbols/codes allowed at the start or end;
	 *                                   default: shop currency symbol and code), allow_negative (bool: a leading
	 *                                   "+" or minus sign is accepted), allow_zero (bool).
	 * @return array{ok:bool, value:float, minor:int, error:string}
	 *         error: 'empty', 'negative', 'invalid', 'ambiguous', 'decimals', 'zero'
	 */
	public static function parse( $raw, array $opts = array() ): array {
		$decimals = isset( $opts['decimals'] ) ? (int) $opts['decimals'] : self::shop_decimals();
		$decimals = $decimals < 0 ? 2 : min( $decimals, 4 );
		$dec_sep  = (string) ( $opts['decimal_sep'] ?? ( function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.' ) );
		$strip    = $opts['strip'] ?? self::shop_tokens();
		$fail     = static fn( string $error ): array => array( 'ok' => false, 'value' => 0.0, 'minor' => 0, 'error' => $error );
		if ( ! is_scalar( $raw ) && null !== $raw ) {
			return $fail( 'invalid' );
		}
		$s = self::strip_edges( (string) $raw, (array) $strip );
		if ( '' === $s ) {
			return $fail( 'empty' );
		}
		$negative = false;
		if ( preg_match( self::MINUS, $s ) || str_starts_with( $s, '+' ) ) {
			$negative = ! str_starts_with( $s, '+' );
			if ( empty( $opts['allow_negative'] ) ) {
				return $fail( $negative ? 'negative' : 'invalid' );
			}
			$s = self::strip_edges( (string) preg_replace( '/^(?:\+|[-−‐‑‒–—])/u', '', $s ), (array) $strip );
		}
		if ( ! preg_match( '/^[0-9.,\'’\s\x{00A0}\x{202F}]+$/u', $s ) || ! preg_match( '/^\d/', $s ) || ! preg_match( '/\d$/', $s ) ) {
			return $fail( 'invalid' );
		}
		$groups = preg_split( self::SEPARATORS, $s );
		preg_match_all( self::SEPARATORS, $s, $m );
		$seps = array_map( array( self::class, 'normalise_separator' ), $m[0] );
		if ( in_array( '', $groups, true ) ) {
			return $fail( 'invalid' ); // Two separators in a row.
		}
		$decimal = '';
		$last    = $seps ? $seps[ count( $seps ) - 1 ] : '';
		if ( ( '.' === $last || ',' === $last ) && 1 === count( array_keys( $seps, $last, true ) ) ) {
			$tail       = $groups[ count( $groups ) - 1 ];
			$other_mark = (bool) array_intersect( array_slice( $seps, 0, -1 ), array( '.', ',' ) );
			if ( 3 !== strlen( $tail ) || $other_mark || ( '0' === $groups[0] && 2 === count( $groups ) ) ) {
				$decimal = $last;
			} elseif ( $decimals >= 3 && $last === $dec_sep ) {
				$decimal = $last;
			} else {
				return $fail( 'ambiguous' );
			}
		}
		$int_groups = '' !== $decimal ? array_slice( $groups, 0, -1 ) : $groups;
		$thousands  = '' !== $decimal ? array_slice( $seps, 0, -1 ) : $seps;
		$frac       = '' !== $decimal ? $groups[ count( $groups ) - 1 ] : '';
		if ( $thousands ) {
			if ( count( array_unique( $thousands ) ) > 1 || ! preg_match( '/^[1-9]\d{0,2}$/', $int_groups[0] ) ) {
				return $fail( 'invalid' );
			}
			foreach ( array_slice( $int_groups, 1 ) as $group ) {
				if ( 3 !== strlen( $group ) ) {
					return $fail( 'invalid' );
				}
			}
		}
		if ( strlen( $frac ) > $decimals ) {
			return $fail( 'decimals' );
		}
		$digits = (string) preg_replace( '/^0+(?=\d)/', '', implode( '', $int_groups ) );
		if ( strlen( $digits ) > 12 ) {
			return $fail( 'invalid' ); // Implausibly large; also keeps the integer arithmetic exact.
		}
		$minor = (int) ( $digits . str_pad( $frac, $decimals, '0' ) );
		if ( 0 === $minor && empty( $opts['allow_zero'] ) ) {
			return $fail( 'zero' );
		}
		$minor = $negative ? -$minor : $minor;
		return array(
			'ok'    => true,
			'value' => round( $minor / ( 10 ** $decimals ), $decimals ),
			'minor' => $minor,
			'error' => '',
		);
	}

	/** Why an amount was refused, for the person who typed it. */
	public static function message( string $error, int $decimals = -1 ): string {
		$decimals = $decimals < 0 ? self::shop_decimals() : $decimals;
		$example  = self::example( $decimals );
		switch ( $error ) {
			case 'empty':
				return __( 'Please enter an amount.', 'voucher-captain' );
			case 'zero':
				return __( 'Please enter an amount greater than zero.', 'voucher-captain' );
			case 'negative':
				return __( 'Please enter the amount without a minus sign.', 'voucher-captain' );
			case 'ambiguous':
				/* translators: %s: example amount, e.g. 12,50 */
				return sprintf( __( 'The amount is ambiguous (thousands or decimal separator?). Please enter it without thousands separators, e.g. %s.', 'voucher-captain' ), $example );
			case 'decimals':
				/* translators: %d: number of decimal places */
				return $decimals ? sprintf( __( 'This currency has at most %d decimal places.', 'voucher-captain' ), $decimals ) : __( 'This currency has no decimal places.', 'voucher-captain' );
		}
		/* translators: %s: example amount, e.g. 12,50 */
		return sprintf( __( 'Please enter only the amount as a number, e.g. %s.', 'voucher-captain' ), $example );
	}

	/**
	 * Amounts of a list such as the customer amount presets: separated by ";" or line breaks only, so "12,50"
	 * stays one amount. Within an entry, commas still separate a legacy list when there is more than one comma or
	 * a comma followed by a space ("10, 25, 50", "10,25,50"); a single comma ("12,50") is a decimal comma.
	 *
	 * @return array{values: float[], invalid: string[]}
	 */
	public static function parse_list( string $raw, array $opts = array() ): array {
		$values  = array();
		$invalid = array();
		foreach ( preg_split( '/[;\r\n]+/', $raw ) ?: array() as $part ) {
			$part = trim( (string) $part );
			// Legacy "10, 25, 50" / "10,25,50": more than one comma, or a comma followed by a space.
			$entries = substr_count( $part, ',' ) > 1 || preg_match( '/,\s/', $part ) ? explode( ',', $part ) : array( $part );
			foreach ( $entries as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				$parsed = self::parse( $entry, $opts );
				if ( $parsed['ok'] ) {
					$values[] = $parsed['value'];
				} else {
					$invalid[] = $entry;
				}
			}
		}
		return array( 'values' => $values, 'invalid' => $invalid );
	}

	/** "12,50" with the shop's decimal separator (example in messages). */
	public static function example( int $decimals ): string {
		$sep = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		return $decimals > 0 ? '12' . $sep . str_pad( '5', $decimals, '0' ) : '12';
	}

	private static function shop_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/** @return string[] the shop currency's symbol and code */
	private static function shop_tokens(): array {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return array();
		}
		$code = (string) get_woocommerce_currency();
		return array( $code, html_entity_decode( (string) get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Removes currency symbols/codes and spaces, but only at the start and the end ("€ 12,50", "12,50 EUR"). */
	private static function strip_edges( string $s, array $tokens ): string {
		$list = array_values( array_filter( array_map( static fn( $t ) => mb_strtoupper( (string) $t ), $tokens ), 'strlen' ) );
		usort( $list, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$trim    = static fn( string $x ): string => (string) preg_replace( '/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $x );
		$s       = $trim( $s );
		$changed = true;
		while ( $changed && '' !== $s ) {
			$changed = false;
			foreach ( $list as $tok ) {
				$len = mb_strlen( $tok );
				if ( mb_strlen( $s ) >= $len && mb_strtoupper( mb_substr( $s, 0, $len ) ) === $tok ) {
					$s       = $trim( mb_substr( $s, $len ) );
					$changed = true;
				} elseif ( mb_strlen( $s ) >= $len && mb_strtoupper( mb_substr( $s, -$len ) ) === $tok ) {
					$s       = $trim( mb_substr( $s, 0, mb_strlen( $s ) - $len ) );
					$changed = true;
				}
			}
		}
		return $s;
	}

	private static function normalise_separator( string $c ): string {
		if ( '.' === $c || ',' === $c ) {
			return $c;
		}
		return preg_match( '/^' . self::SPACE . '$/u', $c ) ? ' ' : "'";
	}
}
