<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * EU target market: country data (currency, locale, statutory voucher validity) kept as configuration – the data
 * file config/markets.php, the filter "vcap_markets" and the setting "Statutory validity per country" – instead of
 * code. VAT rates are never part of it (WooCommerce tax settings).
 */
final class Markets {
	/** Setting: one line per country, "AT: 30" or "DE: 3 end" (years, optional "end" = to the end of the year; 0 = never). */
	public const EXPIRY_OPTION = 'vcap_expiry_by_country';

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $data = null;

	/** @return array<string, array{currency:string, locale:string, voucher_expiry:?array}> */
	public static function all(): array {
		if ( null === self::$data ) {
			$file       = dirname( __DIR__ ) . '/config/markets.php';
			$data       = is_readable( $file ) ? (array) include $file : array();
			self::$data = (array) apply_filters( 'vcap_markets', $data );
		}
		return self::$data;
	}

	public static function reset(): void {
		self::$data = null;
	}

	/** @return array{currency:string, locale:string, voucher_expiry:?array}|null */
	public static function get( string $country ): ?array {
		$country = strtoupper( $country );
		$all     = self::all();
		return isset( $all[ $country ] ) ? $all[ $country ] + array( 'currency' => '', 'locale' => '', 'voucher_expiry' => null ) : null;
	}

	public static function is_eu( string $country ): bool {
		return null !== self::get( $country );
	}

	/**
	 * Parses the setting "Statutory validity per country".
	 *
	 * @return array<string, array{years:int, end_of_year:bool}>
	 */
	public static function parse_expiry( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z]{2})\s*[:=]\s*(\d{1,3})\s*(end|eoy|jahresende|year-end)?\s*$/i', $line, $m ) ) {
				$out[ strtoupper( $m[1] ) ] = array( 'years' => (int) $m[2], 'end_of_year' => ! empty( $m[3] ) );
			}
		}
		return $out;
	}

	/**
	 * Statutory validity of a country: the shop's own setting first, then the verified market data, else null
	 * (not verified – the caller applies its default).
	 *
	 * @return array{years:int, end_of_year:bool, source:string}|null
	 */
	public static function expiry_rule( string $country ): ?array {
		$country = strtoupper( $country );
		$own     = self::parse_expiry( (string) get_option( self::EXPIRY_OPTION, '' ) );
		if ( isset( $own[ $country ] ) ) {
			return $own[ $country ] + array( 'source' => 'setting' );
		}
		$market = self::get( $country );
		$rule   = is_array( $market['voucher_expiry'] ?? null ) ? $market['voucher_expiry'] : null;
		return $rule ? array( 'years' => (int) $rule['years'], 'end_of_year' => ! empty( $rule['end_of_year'] ), 'source' => (string) ( $rule['source'] ?? 'markets' ) ) : null;
	}

	/**
	 * EU countries the shop sells to (WooCommerce "Selling location(s)"), with what is still missing for each:
	 * verified validity, country terms, translation.
	 *
	 * @return array<string, array{validity:bool, terms:bool, translation:bool, locale:string}>
	 */
	public static function readiness(): array {
		$countries = function_exists( 'WC' ) && WC()->countries ? array_keys( (array) WC()->countries->get_allowed_countries() ) : array();
		$terms     = ProductConfig::terms_by_country( (string) get_option( 'vcap_terms_by_country', '' ) );
		$general   = '' !== trim( (string) get_option( 'vcap_default_terms', '' ) );
		// Customers get the shop's languages (site language, WPML/Polylang): a translation for a country's locale
		// only matters when the shop offers that language.
		$offered   = Compat\Multilingual::shop_locales();
		$out       = array();
		foreach ( $countries as $country ) {
			$market = self::get( (string) $country );
			if ( ! $market ) {
				continue;
			}
			$locale = (string) $market['locale'];
			$out[ (string) $country ] = array(
				'validity'    => null !== self::expiry_rule( (string) $country ),
				'terms'       => isset( $terms[ strtoupper( (string) $country ) ] ) || $general,
				'translation' => 'en_GB' === $locale || 'en_US' === $locale || ! in_array( $locale, $offered, true ) || self::has_translation( $locale ),
				'locale'      => $locale,
			);
		}
		ksort( $out );
		return $out;
	}

	private static function has_translation( string $locale ): bool {
		$dirs = array( dirname( __DIR__ ) . '/languages', defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR . '/plugins' : '' );
		foreach ( array_filter( $dirs ) as $dir ) {
			if ( is_readable( $dir . '/voucher-captain-' . $locale . '.mo' ) || is_readable( $dir . '/voucher-captain-' . $locale . '.l10n.php' ) ) {
				return true;
			}
		}
		return false;
	}
}
