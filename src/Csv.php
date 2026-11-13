<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * CSV exports for spreadsheets: UTF-8 with BOM, streamed row by row, spreadsheet formula injection neutralised.
 *
 * The format follows the administrator's language: German locales get a semicolon separator and a decimal
 * comma (what German Excel opens in columns), all others a comma and a decimal point. Filter "vcap_csv_format"
 * changes it, e.g. array( 'separator' => ';', 'decimal' => ',' ). Dates are local shop time.
 */
final class Csv {
	/** @var array{separator:string, decimal:string}|null */
	private static ?array $format = null;

	/** @return array{separator:string, decimal:string} */
	public static function format(): array {
		if ( null === self::$format ) {
			$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
			$german = str_starts_with( strtolower( $locale ), 'de' );
			$format = array(
				'separator' => $german ? ';' : ',',
				'decimal'   => $german && 'de_CH' !== $locale ? ',' : '.',
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
