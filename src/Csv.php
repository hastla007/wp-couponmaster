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
 *
 * SET-VC-20: separator, decimal separator, character set and date format are settings (block "Accounting export",
 * AccountingExport); their defaults give the output described above (automatic separators, UTF-8 with BOM,
 * Y-m-d). The filter "vcap_csv_format" still gets the separators last and wins.
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
			// SET-VC-20: a separator chosen in the settings replaces the automatic one ('' = automatic).
			$separator = (string) Settings::value( AccountingExport::OPTION_SEPARATOR );
			$chosen    = (string) Settings::value( AccountingExport::OPTION_DECIMAL );
			if ( in_array( $chosen, array( ',', '.' ), true ) ) {
				$format['decimal'] = $chosen;
			}
			if ( in_array( $separator, array( ';', ',', 'tab' ), true ) ) {
				$format['separator'] = 'tab' === $separator ? "\t" : $separator;
			}
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

	/** SET-VC-20: character set of the files – 'utf-8-bom' (default), 'utf-8' or 'windows-1252'. */
	public static function charset(): string {
		$charset = (string) Settings::value( AccountingExport::OPTION_CHARSET );
		return in_array( $charset, array( 'utf-8-bom', 'utf-8', 'windows-1252' ), true ) ? $charset : 'utf-8-bom';
	}

	/** SET-VC-20: PHP date format of the date part (default Y-m-d). */
	public static function date_format(): string {
		$format = (string) Settings::value( AccountingExport::OPTION_DATE_FORMAT );
		return in_array( $format, AccountingExport::DATE_FORMATS, true ) ? $format : 'Y-m-d';
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
		return false === $ts ? '' : wp_date( self::date_format() . ( $time ? ' H:i' : '' ), $ts );
	}

	/** Neutralises spreadsheet formulas (=, +, -, @, tab, CR at the start); plain numbers stay numbers. */
	public static function safe_row( array $row ): array {
		return array_map(
			static fn( $c ) => is_string( $c ) && preg_match( '/^[=+\-@\t\r]/', $c ) && ! preg_match( '/^-\d+(?:[.,]\d+)?$/', $c ) ? "'" . $c : $c,
			$row
		);
	}

	/**
	 * Download headers of a CSV file: an attachment, never sniffed as HTML or script by the browser (AP-W1-4).
	 *
	 * @return string[]
	 */
	public static function headers( string $filename ): array {
		return array(
			'Content-Type: text/csv; charset=' . ( 'windows-1252' === self::charset() ? 'windows-1252' : 'utf-8' ),
			'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"',
			'X-Content-Type-Options: nosniff',
		);
	}

	/**
	 * Sends the download headers and the BOM (UTF-8 with BOM only, the default).
	 *
	 * @return resource Output stream for write().
	 */
	public static function open( string $filename ) {
		nocache_headers();
		foreach ( self::headers( $filename ) as $line ) {
			header( $line );
		}
		$out = fopen( 'php://output', 'w' );
		if ( 'utf-8-bom' === self::charset() ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM for Excel.
		}
		return $out;
	}

	/** Writes one row (explicit escape parameter: no deprecation notice on PHP 8.4). */
	public static function write( $out, array $row ): void {
		fputcsv( $out, self::encode_row( self::safe_row( array_values( $row ) ) ), self::format()['separator'], '"', '' );
	}

	/** SET-VC-20: the texts of a row in the character set of the files (Windows-1252: unknown characters "?"). */
	public static function encode_row( array $row ): array {
		if ( 'windows-1252' !== self::charset() || ! function_exists( 'mb_convert_encoding' ) ) {
			return $row;
		}
		return array_map( static fn( $c ) => is_string( $c ) ? (string) mb_convert_encoding( $c, 'Windows-1252', 'UTF-8' ) : $c, $row );
	}

	/**
	 * AP-P1-2: the closing line of an export ("End of export: N rows", N without the header). A file cut off by a
	 * timeout or memory error after the headers were sent lacks it, so a truncated export is recognisable.
	 */
	public static function end_row( int $rows ): array {
		/* translators: %d: number of data rows in the CSV file */
		return array( sprintf( __( 'End of export: %d rows', 'voucher-captain' ), $rows ) );
	}

	/**
	 * Passes the rows through and adds end_row() after the last one.
	 *
	 * @param iterable<array> $rows Header row first (not counted); may be a generator.
	 * @return \Generator<array>
	 */
	public static function with_end_row( iterable $rows, bool $header = true ): \Generator {
		$count = 0;
		foreach ( $rows as $row ) {
			yield $row;
			++$count;
		}
		yield self::end_row( max( 0, $count - ( $header ? 1 : 0 ) ) );
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
