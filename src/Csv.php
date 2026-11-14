<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * CSV exports for spreadsheets: UTF-8 with BOM, streamed row by row, spreadsheet formula injection neutralised.
 *
 * The format follows the administrator's language: locales with a decimal comma (WordPress' number format,
 * e.g. de_DE, fr_FR, nl_NL, pl_PL; not de_CH) get a semicolon separator and a decimal comma (what Excel there
 * opens in columns), all others a comma and a decimal point. Filter "vcap_csv_format"
 * changes it, e.g. array( 'separator' => ';', 'decimal' => ',' ). Dates are local shop time.
 */
final class Csv {
	/** @var array{separator:string, decimal:string}|null */
	private static ?array $format = null;

	/** @return array{separator:string, decimal:string} */
	public static function format(): array {
		if ( null === self::$format ) {
			$locale  = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
			$decimal = self::locale_decimal( $locale );
			$format  = array(
				// Excel in a decimal-comma locale splits columns at ";", not at ",".
				'separator' => ',' === $decimal ? ';' : ',',
				'decimal'   => $decimal,
			);
			/**
			 * CSV format of the exports.
			 *
			 * @param array{separator:string, decimal:string} $format Field separator and decimal separator.
			 * @param string                                  $locale Locale of the exporting user.
			 */
			$format       = (array) apply_filters( 'vcap_csv_format', $format, $locale );
			self::$format = array(
				'separator' => 1 === strlen( (string) ( $format['separator'] ?? '' ) ) ? (string) $format['separator'] : ',',
				'decimal'   => '' !== (string) ( $format['decimal'] ?? '' ) ? (string) $format['decimal'] : '.',
			);
		}
		return self::$format;
	}

	/**
	 * Decimal separator of a locale: a comma when the locale's language writes one (CLDR: fr, it, es, nl, pl, de
	 * … but not de_CH/de_LI, "de_CH_informal" included) or when WordPress' translated number format of the
	 * current locale says so ($wp_locale); otherwise a point. (An untranslated site reports "." for every
	 * locale, so the language decides first.)
	 */
	public static function locale_decimal( string $locale ): string {
		global $wp_locale;
		if ( self::comma_language( $locale ) ) {
			return ',';
		}
		$current = function_exists( 'determine_locale' ) ? (string) determine_locale() : $locale;
		$point   = is_object( $wp_locale ) && isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '';
		return ',' === $point && $current === $locale && ! preg_match( '/^(de|it)_(ch|li)/i', $locale ) ? ',' : '.';
	}

	/** Languages/regions that write a decimal comma (CLDR), when WordPress does not say otherwise. */
	private static function comma_language( string $locale ): bool {
		$parts  = explode( '_', strtolower( $locale ) );
		$lang   = $parts[0];
		$region = $parts[1] ?? '';
		if ( 'de' === $lang && in_array( $region, array( 'ch', 'li' ), true ) ) {
			return false;
		}
		if ( 'it' === $lang && 'ch' === $region ) {
			return false;
		}
		if ( 'es' === $lang && in_array( $region, array( 'mx', 'us', 'pr', 'do', 'gt', 'hn', 'ni', 'pa', 'sv' ), true ) ) {
			return false;
		}
		if ( 'pt' === $lang && '' === $region ) {
			return true;
		}
		return in_array( $lang, array( 'de', 'fr', 'it', 'es', 'nl', 'pl', 'pt', 'cs', 'sk', 'sl', 'hr', 'hu', 'ro', 'bg', 'el', 'da', 'fi', 'sv', 'nb', 'nn', 'et', 'lv', 'lt', 'ru', 'uk', 'tr', 'id', 'ca', 'gl', 'eu', 'is', 'sr', 'bs', 'mk', 'sq', 'az', 'kk', 'be', 'vi' ), true );
	}

	/** Forget the cached format (tests, locale switches). */
	public static function reset(): void {
		self::$format = null;
	}

	/** A number with the decimal separator of the format, without thousands separators. */
	public static function number( $value, int $decimals = 2 ): string {
		$text = number_format( (float) $value, max( 0, $decimals ), '.', '' );
		return '.' === self::format()['decimal'] ? $text : str_replace( '.', self::format()['decimal'], $text );
	}

	/**
	 * A UTC database date (Y-m-d H:i:s) in the shop's local time, '' for none.
	 *
	 * @param bool $time With the time of day.
	 */
	public static function date( ?string $utc, bool $time = true ): string {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return '';
		}
		$ts = strtotime( $utc . ' UTC' );
		return false === $ts ? '' : wp_date( $time ? 'Y-m-d H:i' : 'Y-m-d', $ts );
	}

	/** Neutralises spreadsheet formulas (=, +, -, @, tab, CR at the start); plain numbers stay numbers. */
	public static function safe_row( array $row ): array {
		return array_map(
			static fn( $c ) => is_string( $c ) && preg_match( '/^[=+\-@\t\r]/', $c ) && ! preg_match( '/^-\d+(?:[.,]\d+)?$/', $c ) ? "'" . $c : $c,
			$row
		);
	}

	/**
	 * Sends the download headers and the BOM.
	 *
	 * @return resource Output stream for write().
	 */
	public static function open( string $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM for Excel.
		return $out;
	}

	/** Writes one row (explicit escape parameter: no deprecation notice on PHP 8.4). */
	public static function write( $out, array $row ): void {
		fputcsv( $out, self::safe_row( array_values( $row ) ), self::format()['separator'], '"', '' );
	}

	/**
	 * Streams rows as a CSV download.
	 *
	 * @param iterable<array> $rows Header row first; may be a generator.
	 */
	public static function send( string $filename, iterable $rows ): void {
		$out = self::open( $filename );
		foreach ( $rows as $row ) {
			self::write( $out, (array) $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
