<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Target markets (EU-27, Switzerland, Liechtenstein, United Kingdom, Norway): country data (currency, locales, EU
 * membership, statutory voucher validity, till-receipt duty) kept as configuration – the data file
 * config/markets.php, the filter "vcap_markets" and the setting "Statutory validity per country" – instead of code.
 * VAT rates are never part of it (WooCommerce tax settings).
 */
final class Markets {
	/**
	 * Setting "Statutory validity per country". SET-VC-04: a table – list of rows {country, years, end_of_year, note}
	 * (years 0 = never). Values stored before 1.3.0 are text, one line per country ("AT: 30", "DE: 3 end"); they are
	 * read unchanged (expiry_rows()) and become rows the next time the settings are saved.
	 */
	public const EXPIRY_OPTION = 'vcap_expiry_by_country';

	/**
	 * Setting "Redemption conditions per country". SET-VC-04: a table – list of rows {country, language, text}
	 * (language '' = every language). Values stored before 1.3.0 are text blocks ("AT: …"), read unchanged.
	 */
	public const TERMS_OPTION = 'vcap_terms_by_country';

	/** SET-VC-04: longest note of a validity row (characters). */
	public const NOTE_MAX = 200;

	/** ISO 3166-1 alpha-2 codes (as WooCommerce lists them), used where WooCommerce's country list is not loaded. */
	private const ISO_COUNTRIES = ' '
		. 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS '
		. 'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE '
		. 'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM '
		. 'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC '
		. 'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA '
		. 'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
		. 'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO '
		. 'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW ';

	/** EU member states – only used for market entries (added by the filter "vcap_markets") without an "eu" flag. */
	private const EU_MEMBERS = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $data = null;

	/**
	 * SET-VC-04: the ISO country codes (country select where WooCommerce's country list is not loaded).
	 *
	 * @return string[]
	 */
	public static function country_codes(): array {
		return preg_split( '/\s+/', trim( self::ISO_COUNTRIES ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/** Q12: whether $code is a country code ("AT"), not just two letters ("NB: …" in a text). */
	public static function is_country_code( string $code ): bool {
		$code = strtoupper( trim( $code ) );
		if ( 2 !== strlen( $code ) ) {
			return false;
		}
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$known = (array) WC()->countries->get_countries();
			if ( $known ) {
				return isset( $known[ $code ] );
			}
		}
		return str_contains( self::ISO_COUNTRIES, ' ' . $code . ' ' );
	}

	/**
	 * Q12: lines of the setting "Statutory validity per country" that parse_expiry() cannot read (reported on save
	 * instead of being ignored silently). Empty lines do not count. SET-VC-04: a stored table has none; the settings
	 * page shows these lines of a value from before 1.3.0 above the table, so they are not lost unnoticed.
	 *
	 * @param mixed $raw Stored value (text from before 1.3.0 or rows).
	 * @return string[]
	 */
	public static function unparsed_expiry_lines( $raw ): array {
		if ( ! is_string( $raw ) ) {
			return array(); // SET-VC-04: rows are checked when they are saved (check_expiry_rows()).
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( '' !== trim( $line ) && ! self::parse_expiry( $line ) ) {
				$out[] = trim( $line );
			}
		}
		return $out;
	}

	/** @return array<string, array<string, mixed>> */
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

	/**
	 * Market data of a country, null when the country is not in the market data.
	 *
	 * @return array{currency:string, locale:string, languages:string[], eu:bool, fiscal_receipt:bool, voucher_expiry:?array}|null
	 */
	public static function get( string $country ): ?array {
		$country = strtoupper( trim( $country ) );
		$all     = self::all();
		if ( '' === $country || ! isset( $all[ $country ] ) || ! is_array( $all[ $country ] ) ) {
			return null;
		}
		$m         = $all[ $country ];
		$locale    = (string) ( $m['locale'] ?? '' );
		$languages = array_values( array_filter( array_map( 'strval', (array) ( $m['languages'] ?? array() ) ) ) );
		return array(
			'currency'       => (string) ( $m['currency'] ?? '' ),
			'locale'         => $locale,
			'languages'      => $languages ? $languages : array_filter( array( $locale ) ),
			'eu'             => isset( $m['eu'] ) ? (bool) $m['eu'] : in_array( $country, self::EU_MEMBERS, true ),
			'fiscal_receipt' => ! empty( $m['fiscal_receipt'] ),
			'voucher_expiry' => is_array( $m['voucher_expiry'] ?? null ) ? $m['voucher_expiry'] : null,
		);
	}

	/** SET-VC-03: whether the country is in the market data (EU or not) – use is_eu() only for EU membership. */
	public static function known( string $country ): bool {
		return null !== self::get( $country );
	}

	/** SET-VC-03: whether the country is an EU member state (flag "eu" of the market data). */
	public static function is_eu( string $country ): bool {
		return (bool) ( self::get( $country )['eu'] ?? false );
	}

	/**
	 * SET-VC-10: whether the till receipt documents a sale in this country (market flag "fiscal_receipt": DE, AT),
	 * so the automatic "Receipt no. required" asks for the receipt number. Unknown countries: false.
	 */
	public static function fiscal_receipt( string $country ): bool {
		return (bool) ( self::get( $country )['fiscal_receipt'] ?? false );
	}

	/**
	 * Parses the setting "Statutory validity per country".
	 *
	 * @return array<string, array{years:int, end_of_year:bool}>
	 */
	public static function parse_expiry( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z]{2})\s*[:=]\s*(\d{1,3})\s*(end|eoy|jahresende|year-end)?\s*$/i', $line, $m ) && self::is_country_code( $m[1] ) ) {
				$out[ strtoupper( $m[1] ) ] = array( 'years' => (int) $m[2], 'end_of_year' => ! empty( $m[3] ) );
			}
		}
		return $out;
	}

	/**
	 * SET-VC-04: the rows of the setting "Statutory validity per country" – from a stored table (invalid rows left
	 * out) or from the text of a value from before 1.3.0 (lines parse_expiry() reads, in their order).
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, array{country:string, years:int, end_of_year:bool, note:string}>
	 */
	public static function expiry_rows( $value ): array {
		if ( is_string( $value ) ) {
			$rows = array();
			foreach ( self::parse_expiry( $value ) as $country => $rule ) {
				$rows[] = array(
					'country'     => (string) $country,
					'years'       => $rule['years'],
					'end_of_year' => $rule['end_of_year'],
					'note'        => '',
				);
			}
			return $rows;
		}
		return is_array( $value ) ? self::check_expiry_rows( $value )['rows'] : array();
	}

	/**
	 * SET-VC-04: country => rule of the setting "Statutory validity per country" (table or text from before 1.3.0).
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, array{years:int, end_of_year:bool}>
	 */
	public static function expiry_rules( $value ): array {
		$out = array();
		foreach ( self::expiry_rows( $value ) as $row ) {
			$out[ $row['country'] ] = array(
				'years'       => $row['years'],
				'end_of_year' => $row['end_of_year'],
			);
		}
		return $out;
	}

	/**
	 * SET-VC-04: checks submitted validity rows. Empty rows (no country, no years, no note) are left out; a row with
	 * an unknown country, years that are not a whole number from 0 to 999, or a country that already has a row is
	 * not taken – its error (keyed by the row's position, 0 = first) names the row number (1 = first row) and what was
	 * entered.
	 *
	 * @param array<mixed> $input Rows as submitted (or stored).
	 * @return array{rows: array<int, array{country:string, years:int, end_of_year:bool, note:string}>, errors: array<int, string>}
	 */
	public static function check_expiry_rows( array $input ): array {
		$rows   = array();
		$errors = array();
		$seen   = array();
		$number = 0;
		foreach ( $input as $row ) {
			++$number;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$country = strtoupper( trim( self::scalar( $row['country'] ?? '' ) ) );
			$years   = trim( self::scalar( $row['years'] ?? '' ) );
			$note    = sanitize_text_field( self::scalar( $row['note'] ?? '' ) );
			$end     = $row['end_of_year'] ?? false;
			$end     = true === $end || in_array( strtolower( self::scalar( $end ) ), array( '1', 'yes', 'on', 'true' ), true );
			if ( '' === $country && '' === $years && '' === $note ) {
				continue;
			}
			$label = self::row_label( $number, $country );
			if ( '' === $country || ! self::is_country_code( $country ) ) {
				/* translators: %s: row, e.g. "Row 2 (FR)" */
				$errors[ $number - 1 ] = sprintf( __( '%s: choose a country.', 'voucher-captain' ), $label );
				continue;
			}
			if ( ! preg_match( '/^\d{1,3}$/', $years ) ) {
				/* translators: 1: row, e.g. "Row 2 (FR)", 2: value entered */
				$errors[ $number - 1 ] = sprintf( __( '%1$s: "%2$s" is not a number of years – enter a whole number from 0 (never expires) to 999.', 'voucher-captain' ), $label, $years );
				continue;
			}
			if ( isset( $seen[ $country ] ) ) {
				/* translators: 1: row, e.g. "Row 3 (FR)", 2: number of the row with the same country */
				$errors[ $number - 1 ] = sprintf( __( '%1$s: this country already has a rule in row %2$d.', 'voucher-captain' ), $label, $seen[ $country ] );
				continue;
			}
			$seen[ $country ] = $number;
			$rows[]           = array(
				'country'     => $country,
				'years'       => (int) $years,
				'end_of_year' => $end,
				'note'        => mb_substr( $note, 0, self::NOTE_MAX ),
			);
		}
		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * SET-VC-04: the rows of the setting "Redemption conditions per country" – from a stored table (invalid rows
	 * left out) or from the text blocks of a value from before 1.3.0 ("AT: …", every language).
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, array{country:string, language:string, text:string}>
	 */
	public static function terms_rows( $value ): array {
		if ( is_string( $value ) ) {
			$rows = array();
			foreach ( self::parse_terms_blocks( $value ) as $country => $text ) {
				$rows[] = array(
					'country'  => (string) $country,
					'language' => '',
					'text'     => $text,
				);
			}
			return $rows;
		}
		return is_array( $value ) ? self::check_terms_rows( $value )['rows'] : array();
	}

	/**
	 * Parse "AT: text" blocks (a block runs until the next line starting with a country code and a colon). Q12: only
	 * ISO country codes start a block – "NB: …" or "OK: …" inside the conditions stays part of them. The format of
	 * the setting before 1.3.0 (SET-VC-04).
	 *
	 * @return array<string, string>
	 */
	public static function parse_terms_blocks( string $raw ): array {
		$out     = array();
		$current = '';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z]{2})\s*:\s*(.*)$/', $line, $m ) && self::is_country_code( $m[1] ) ) {
				$current         = strtoupper( $m[1] );
				$out[ $current ] = $m[2];
				continue;
			}
			if ( '' !== $current ) {
				$out[ $current ] .= "\n" . $line;
			}
		}
		return array_filter( array_map( 'trim', $out ), 'strlen' );
	}

	/**
	 * SET-VC-04: checks submitted condition rows. Empty rows (no country, no text) are left out; a row without a
	 * known country or without text, with an invalid language, or with a country and language that already have a
	 * row is not taken – its error names the row number and the country.
	 *
	 * @param array<mixed> $input Rows as submitted (or stored).
	 * @return array{rows: array<int, array{country:string, language:string, text:string}>, errors: array<int, string>}
	 */
	public static function check_terms_rows( array $input ): array {
		$rows   = array();
		$errors = array();
		$seen   = array();
		$number = 0;
		foreach ( $input as $row ) {
			++$number;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$country  = strtoupper( trim( self::scalar( $row['country'] ?? '' ) ) );
			$language = trim( self::scalar( $row['language'] ?? '' ) );
			$text     = trim( wp_kses_post( self::scalar( $row['text'] ?? '' ) ) );
			if ( '' === $country && '' === $text ) {
				continue;
			}
			$label = self::row_label( $number, $country );
			if ( '' === $country || ! self::is_country_code( $country ) ) {
				/* translators: %s: row, e.g. "Row 2 (FR)" */
				$errors[ $number - 1 ] = sprintf( __( '%s: choose a country.', 'voucher-captain' ), $label );
				continue;
			}
			if ( '' === $text ) {
				/* translators: %s: row, e.g. "Row 2 (FR)" */
				$errors[ $number - 1 ] = sprintf( __( '%s: enter the conditions or remove the row.', 'voucher-captain' ), $label );
				continue;
			}
			if ( '' !== $language && ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $language ) ) {
				/* translators: 1: row, e.g. "Row 2 (FR)", 2: value entered */
				$errors[ $number - 1 ] = sprintf( __( '%1$s: "%2$s" is not a language of the shop.', 'voucher-captain' ), $label, $language );
				continue;
			}
			$key = $country . '|' . $language;
			if ( isset( $seen[ $key ] ) ) {
				/* translators: 1: row, e.g. "Row 3 (FR)", 2: number of the row with the same country and language */
				$errors[ $number - 1 ] = sprintf( __( '%1$s: this country already has conditions in this language in row %2$d.', 'voucher-captain' ), $label, $seen[ $key ] );
				continue;
			}
			$seen[ $key ] = $number;
			$rows[]       = array(
				'country'  => $country,
				'language' => $language,
				'text'     => $text,
			);
		}
		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * SET-VC-04: country => conditions for a language: the row in exactly that locale, else the row for every
	 * language, else one in the same language ("de_AT" for "de_DE"), else the country's first row. '' = no
	 * preference (the row for every language, else the first).
	 *
	 * @param array<int, array{country:string, language:string, text:string}> $rows   Rows (terms_rows()).
	 * @param string                                                          $locale Locale, e.g. "de_DE".
	 * @return array<string, string>
	 */
	public static function terms_for_locale( array $rows, string $locale = '' ): array {
		$prefix = strtolower( explode( '_', $locale )[0] );
		$best   = array();
		$out    = array();
		foreach ( $rows as $row ) {
			$language = $row['language'];
			if ( '' !== $locale && $language === $locale ) {
				$rank = 0;
			} elseif ( '' === $language ) {
				$rank = 1;
			} elseif ( '' !== $prefix && strtolower( explode( '_', $language )[0] ) === $prefix ) {
				$rank = 2;
			} else {
				$rank = 3;
			}
			if ( ! isset( $best[ $row['country'] ] ) || $rank < $best[ $row['country'] ] ) {
				$best[ $row['country'] ] = $rank;
				$out[ $row['country'] ]  = $row['text'];
			}
		}
		return $out;
	}

	/**
	 * SET-VC-04: "Row 2 (FR)" for error messages.
	 */
	private static function row_label( int $number, string $country ): string {
		if ( '' === $country ) {
			/* translators: %d: row number */
			return sprintf( __( 'Row %d', 'voucher-captain' ), $number );
		}
		/* translators: 1: row number, 2: country code as entered */
		return sprintf( __( 'Row %1$d (%2$s)', 'voucher-captain' ), $number, $country );
	}

	/**
	 * A submitted value as text ('' for arrays and objects).
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Statutory validity of a country: the shop's own setting first, then the verified market data, else null
	 * (not verified – the caller applies its default).
	 *
	 * @return array{years:int, end_of_year:bool, source:string}|null
	 */
	public static function expiry_rule( string $country ): ?array {
		$country = strtoupper( $country );
		$own     = self::expiry_rules( get_option( self::EXPIRY_OPTION, '' ) );
		if ( isset( $own[ $country ] ) ) {
			return $own[ $country ] + array( 'source' => 'setting' );
		}
		$market = self::get( $country );
		$rule   = is_array( $market['voucher_expiry'] ?? null ) ? $market['voucher_expiry'] : null;
		return $rule ? array( 'years' => (int) $rule['years'], 'end_of_year' => ! empty( $rule['end_of_year'] ), 'source' => (string) ( $rule['source'] ?? 'markets' ) ) : null;
	}

	/**
	 * Countries the shop sells to (WooCommerce "Selling location(s)").
	 *
	 * @return string[]
	 */
	public static function selling_countries(): array {
		$countries = function_exists( 'WC' ) && WC()->countries ? array_keys( (array) WC()->countries->get_allowed_countries() ) : array();
		return array_values( array_unique( array_map( static fn( $c ) => strtoupper( (string) $c ), $countries ) ) );
	}

	/**
	 * SET-VC-03: selling countries without market data (the shop's defaults apply there – not checked at all).
	 *
	 * @param string[]|null $countries Selling countries (default: WooCommerce "Selling location(s)").
	 * @return string[]
	 */
	public static function unknown_selling_countries( ?array $countries = null ): array {
		$out = array_values( array_filter( array_map( 'strval', $countries ?? self::selling_countries() ), static fn( string $c ): bool => ! self::known( $c ) ) );
		sort( $out );
		return $out;
	}

	/**
	 * Countries in the market data the shop sells to (EU and non-EU, e.g. Switzerland), with what is still missing
	 * for each: verified validity, country terms, translation ("untranslated": offered locales of that country
	 * without a translation).
	 *
	 * @param string[]|null $countries Selling countries (default: WooCommerce "Selling location(s)").
	 * @return array<string, array{validity:bool, terms:bool, translation:bool, locale:string, untranslated:string[]}>
	 */
	public static function readiness( ?array $countries = null ): array {
		$countries = $countries ?? self::selling_countries();
		$terms     = ProductConfig::terms_by_country( get_option( self::TERMS_OPTION, '' ) );
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
			$untranslated = array();
			foreach ( $market['languages'] as $locale ) {
				if ( 'en_GB' !== $locale && 'en_US' !== $locale && in_array( $locale, $offered, true ) && ! self::has_translation( $locale ) ) {
					$untranslated[] = $locale;
				}
			}
			$country         = strtoupper( (string) $country );
			$out[ $country ] = array(
				'validity'     => null !== self::expiry_rule( $country ),
				'terms'        => isset( $terms[ $country ] ) || $general,
				'translation'  => ! $untranslated,
				'locale'       => $market['locale'],
				'untranslated' => $untranslated,
			);
		}
		ksort( $out );
		return $out;
	}

	/** Whether the plugin has a translation for $locale – its own file or one of the fallbacks (de_CH → de_DE_formal). */
	private static function has_translation( string $locale ): bool {
		$dirs = array_filter( array( dirname( __DIR__ ) . '/languages', defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR . '/plugins' : '' ) );
		foreach ( I18n::chain( $locale ) as $candidate ) {
			foreach ( $dirs as $dir ) {
				if ( is_readable( $dir . '/voucher-captain-' . $candidate . '.mo' ) || is_readable( $dir . '/voucher-captain-' . $candidate . '.l10n.php' ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
