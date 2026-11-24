<?php
/**
 * Country profiles (SET-VC-02).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Suggested settings per country (config/country-profiles.php, filter "vcap_country_profiles"). A profile is only a
 * suggestion (owner decision E4 = a): preview() lists every value "current → suggested", the admin picks the rows to
 * take, apply() checks them like an import (SettingsTransfer::validate()), saves a backup of all settings first and
 * records each change in the change log with the source "country profile". Nothing is changed without that
 * confirmation, and afterwards every value stays freely editable. Values with legal weight carry the mark "Legal check
 * pending" (Rechtsprüfung) while the profile is not checked (owner rule S8).
 *
 * @since 1.3.0
 */
final class CountryProfiles {
	/** Internal option: the profile applied last (array{id, time, user}); the setup checklist reads it. */
	public const APPLIED_OPTION = 'vcap_country_profile';

	/** Id of the row "statutory validity" in a preview (it changes one row of vcap_expiry_by_country). */
	public const EXPIRY_ITEM = 'expiry_row';

	/** Settings a profile only suggests: never preselected (owner decision E10 on the link parameter is open). */
	public const OPT_IN = array( 'vcap_coupon_link_param' );

	/** Settings with a label in the preview, in this order (a filter may add other exportable settings). */
	private const ORDER = array( 'vcap_default_expiry_rule', self::EXPIRY_ITEM, 'vcap_counter_require_receipt', 'vcap_retention_days', 'vcap_date_format', 'vcap_coupon_link_param' );

	/** @var array<string, array<string, mixed>>|null Filtered profiles. */
	private static ?array $cache = null;

	/** Forgets the cached profiles (tests, late filters). */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * All profiles (id => profile), checked: unknown settings, secrets and settings that are never exported are left out.
	 *
	 * @return array<string, array{country: string, checked: bool, settings: array<string, mixed>, expiry_row: mixed, tax: string, accounting: string, retention_hint: string, label: string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$file = defined( 'VCAP_PATH' ) ? VCAP_PATH . 'config/country-profiles.php' : dirname( __DIR__ ) . '/config/country-profiles.php';
		$core = is_readable( $file ) ? include $file : array();
		/**
		 * Country profiles (arg: id => array{country, checked, settings, expiry_row, tax, accounting, label?} – see
		 * config/country-profiles.php). Add profiles for further countries or change suggested values; a profile is
		 * always shown as a preview and applied only after the admin confirms it. Set "checked" to true only after
		 * the legal and tax check of the values for that country.
		 *
		 * @since 1.3.0
		 * @param array<string, array<string, mixed>> $profiles Country profiles.
		 */
		$profiles = apply_filters( 'vcap_country_profiles', is_array( $core ) ? $core : array() );
		$out      = array();
		foreach ( is_array( $profiles ) ? $profiles : array() as $id => $profile ) {
			$id = strtoupper( (string) $id );
			if ( ! preg_match( '/^[A-Z][A-Z0-9_]{1,19}$/', $id ) || ! is_array( $profile ) ) {
				continue;
			}
			$country  = strtoupper( (string) ( $profile['country'] ?? '' ) );
			$settings = array();
			foreach ( (array) ( $profile['settings'] ?? array() ) as $key => $value ) {
				if ( null !== $value && self::allowed_key( (string) $key ) ) {
					$settings[ (string) $key ] = $value;
				}
			}
			$out[ $id ] = array(
				'country'        => Markets::is_country_code( $country ) ? $country : '',
				'checked'        => ! empty( $profile['checked'] ),
				'settings'       => $settings,
				'expiry_row'     => $profile['expiry_row'] ?? null,
				'tax'            => sanitize_key( (string) ( $profile['tax'] ?? '' ) ),
				'accounting'     => sanitize_key( (string) ( $profile['accounting'] ?? '' ) ),
				// SET-VC-09: a key ('de', 'at', 'neutral') or an own text from the filter; missing = neutral.
				'retention_hint' => sanitize_text_field( (string) ( $profile['retention_hint'] ?? '' ) ),
				'label'          => sanitize_text_field( (string) ( $profile['label'] ?? '' ) ),
			);
		}
		self::$cache = $out;
		return $out;
	}

	/** Whether a profile may suggest this setting: an exportable setting of the register, never the data removal switch. */
	private static function allowed_key( string $key ): bool {
		return Markets::EXPIRY_OPTION !== $key && Admin\DataRemoval::OPTION !== $key && in_array( $key, SettingsTransfer::exportable(), true );
	}

	/**
	 * A profile, null when unknown.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ strtoupper( $id ) ] ?? null;
	}

	/** Name of a profile ("Germany", "EU (other member states)", …). */
	public static function label( string $id ): string {
		$id      = strtoupper( $id );
		$profile = self::get( $id );
		$labels  = array(
			'DE'    => __( 'Germany', 'voucher-captain' ),
			'AT'    => __( 'Austria', 'voucher-captain' ),
			'CH'    => __( 'Switzerland', 'voucher-captain' ),
			'EU'    => __( 'EU (other member states)', 'voucher-captain' ),
			'OTHER' => __( 'Other countries', 'voucher-captain' ),
		);
		if ( null !== $profile && '' !== $profile['label'] ) {
			return $profile['label'];
		}
		return $labels[ $id ] ?? $id;
	}

	/** Profile that fits the shop's base country: its own profile, else EU for member states, else OTHER. */
	public static function for_base_country(): string {
		$country = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );
		foreach ( self::all() as $id => $profile ) {
			if ( '' !== $country && $profile['country'] === $country ) {
				return $id;
			}
		}
		if ( '' !== $country && Markets::is_eu( $country ) && null !== self::get( 'EU' ) ) {
			return 'EU';
		}
		return null !== self::get( 'OTHER' ) ? 'OTHER' : (string) array_key_first( self::all() );
	}

	/** Mark of a value with legal weight while the profile is not checked (owner rule S8). */
	public static function review_mark(): string {
		return __( 'Legal check pending', 'voucher-captain' );
	}

	/** Status line of a profile: "legal and tax check pending" (DE/AT/CH) or "neutral values, not checked" (EU, OTHER). */
	public static function status( string $id ): string {
		$profile = self::get( $id );
		if ( null === $profile ) {
			return '';
		}
		if ( $profile['checked'] ) {
			return __( 'Checked for this country.', 'voucher-captain' );
		}
		return '' !== $profile['country']
			? __( 'Legal check pending: the suggested values have not been legally or tax checked for this country yet. Have them checked before you rely on them.', 'voucher-captain' )
			: __( 'Not checked: neutral values only – nothing with legal weight is suggested. Set validity, receipt duty and retention for your countries yourself.', 'voucher-captain' );
	}

	/** Whether a setting has legal weight (register attribute "legal"; the validity row always has). */
	private static function legal( string $item ): bool {
		return self::EXPIRY_ITEM === $item || ! empty( SettingsRegistry::get( $item )['legal'] );
	}

	/** Label of a row of the preview. */
	private static function item_label( string $item, string $country ): string {
		switch ( $item ) {
			case 'vcap_default_expiry_rule':
				return __( 'Validity of products without their own expiry', 'voucher-captain' );
			case self::EXPIRY_ITEM:
				/* translators: %s: country code, e.g. DE */
				return sprintf( __( 'Statutory validity per country: row %s', 'voucher-captain' ), $country );
			case 'vcap_counter_require_receipt':
				return __( 'Receipt no. required', 'voucher-captain' );
			case 'vcap_retention_days':
				return __( 'Anonymise recipient data after (days)', 'voucher-captain' );
			case 'vcap_date_format':
				return __( 'Date format', 'voucher-captain' );
			case 'vcap_coupon_link_param':
				return __( 'Link parameter', 'voucher-captain' );
		}
		return $item;
	}

	/**
	 * A value as the preview shows it.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function display( string $item, $value ): string {
		switch ( $item ) {
			case 'vcap_default_expiry_rule':
				if ( '' === (string) $value ) {
					$value = ProductConfig::default_expiry_rule();
				}
				return 'statutory' === $value ? __( 'statutory validity', 'voucher-captain' ) : __( 'fixed number of days', 'voucher-captain' );
			case self::EXPIRY_ITEM:
				if ( ! is_array( $value ) ) {
					return __( 'no row (market data or default applies)', 'voucher-captain' );
				}
				$years = (int) $value['years'];
				$text  = 0 === $years
					? __( 'never expires', 'voucher-captain' )
					/* translators: %d: number of years */
					: sprintf( _n( '%d year', '%d years', $years, 'voucher-captain' ), $years );
				if ( ! empty( $value['end_of_year'] ) ) {
					$text .= ' ' . __( 'to the end of the year', 'voucher-captain' );
				}
				return '' !== (string) ( $value['note'] ?? '' ) ? $text . ' (' . $value['note'] . ')' : $text;
			case 'vcap_counter_require_receipt':
				$labels = array(
					'auto' => __( 'Automatic (required for shops based in Germany or Austria)', 'voucher-captain' ),
					'yes'  => __( 'Required', 'voucher-captain' ),
					'no'   => __( 'Optional', 'voucher-captain' ),
				);
				return $labels[ (string) $value ] ?? (string) $value;
			case 'vcap_retention_days':
				return 0 === (int) $value ? __( '0 (keep)', 'voucher-captain' ) : (string) (int) $value;
			case 'vcap_date_format':
				return '' === (string) $value ? __( 'WordPress date format', 'voucher-captain' ) : (string) $value . ' (' . gmdate( (string) $value, 1795392000 ) . ')';
		}
		return SettingsTransfer::display( $value );
	}

	/**
	 * Suggested statutory validity row of a profile (null when the profile suggests none).
	 *
	 * @return array{country: string, years: int, end_of_year: bool, note: string}|null
	 */
	public static function expiry_row( string $id ): ?array {
		$profile = self::get( $id );
		if ( null === $profile || '' === $profile['country'] ) {
			return null;
		}
		$row = $profile['expiry_row'];
		if ( 'market' === $row ) {
			$market = Markets::get( $profile['country'] );
			$rule   = is_array( $market['voucher_expiry'] ?? null ) ? $market['voucher_expiry'] : null;
			if ( null === $rule ) {
				return null;
			}
			$row = array(
				'years'       => (int) $rule['years'],
				'end_of_year' => ! empty( $rule['end_of_year'] ),
				'note'        => (string) ( $rule['source'] ?? '' ),
			);
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( ! $profile['checked'] ) {
			// The stored row keeps the mark until the admin edits the note (owner rule S8).
			$note        = trim( (string) ( $row['note'] ?? '' ) );
			$row['note'] = '' !== $note ? $note . ' – ' . self::review_mark() : self::review_mark();
		}
		$checked = Markets::check_expiry_rows( array( array( 'country' => $profile['country'] ) + $row ) );
		return $checked['rows'][0] ?? null;
	}

	/**
	 * The table "Statutory validity per country" with the profile's row added, or replacing the row of that country.
	 * Other rows stay as they are.
	 *
	 * @param array{country: string, years: int, end_of_year: bool, note: string} $row Row of the profile.
	 * @return array<int, array{country: string, years: int, end_of_year: bool, note: string}>
	 */
	private static function rows_with( array $row ): array {
		$rows     = Markets::expiry_rows( get_option( Markets::EXPIRY_OPTION, array() ) );
		$replaced = false;
		foreach ( $rows as $i => $existing ) {
			if ( $existing['country'] === $row['country'] ) {
				$rows[ $i ] = $row;
				$replaced   = true;
			}
		}
		if ( ! $replaced ) {
			$rows[] = $row;
		}
		return Markets::check_expiry_rows( $rows )['rows'];
	}

	/**
	 * Suggested value of a setting, resolved ('market' for the receipt number: DE/AT from the market flag
	 * "fiscal_receipt"; null = no suggestion).
	 *
	 * @param array<string, mixed> $profile Profile.
	 * @param mixed                $value   Value in the profile.
	 * @return mixed
	 */
	private static function resolve( array $profile, string $key, $value ) {
		if ( 'vcap_counter_require_receipt' === $key && 'market' === $value ) {
			if ( '' === $profile['country'] || ! Markets::fiscal_receipt( $profile['country'] ) ) {
				return null; // Other countries: not legally checked, so no "no duty" is suggested.
			}
			$base = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );
			// "automatic" already asks for the receipt number in a shop based in that kind of country.
			return 'auto' === (string) SettingsTransfer::stored( $key ) && Markets::fiscal_receipt( $base ) ? 'auto' : 'yes';
		}
		return $value;
	}

	/**
	 * Preview of a profile: one row per suggested value with the current and the suggested value. A row is
	 * preselected when it changes something, is not opt-in (link parameter) and the shop never set an own value
	 * (stored value = default; for the validity row: no row for that country yet). An invalid suggestion (e.g. from a
	 * filter) is shown with its error and cannot be applied.
	 *
	 * @return array<string, array{item: string, key: string, label: string, from: string, to: string, value: mixed, changed: bool, legal: bool, review: string, opt_in: bool, preselected: bool, own: bool, error: string}>|\WP_Error
	 */
	public static function preview( string $id ) {
		$profile = self::get( $id );
		if ( null === $profile ) {
			/* translators: %s: profile id */
			return new \WP_Error( 'vcap_profile_unknown', sprintf( __( 'There is no country profile "%s".', 'voucher-captain' ), $id ) );
		}
		$review = $profile['checked'] ? '' : self::review_mark();
		$items  = array();
		foreach ( $profile['settings'] as $key => $value ) {
			$value = self::resolve( $profile, $key, $value );
			if ( null === $value ) {
				continue;
			}
			$valid   = SettingsTransfer::validate( $key, $value );
			$current = SettingsTransfer::stored( $key );
			$error   = SettingsRegistry::locked( $key ) ? SettingsRegistry::locked_message( $key ) : ( is_wp_error( $valid ) ? wp_strip_all_tags( $valid->get_error_message() ) : '' ); // SET-VC-34: skipped.
			// An unsaved validity rule is compared by the rule that applies (ProductConfig::default_expiry_rule()).
			$compare = 'vcap_default_expiry_rule' === $key && '' === (string) $current ? ProductConfig::default_expiry_rule() : $current;
			$changed = '' === $error && ! SettingsTransfer::same( $compare, $valid );
			$own     = false !== get_option( $key, false ) && ! SettingsTransfer::same( $current, SettingsRegistry::default( $key ) );
			// A shop that saved its own number of days but never the rule uses "days" (default_expiry_rule()): own value.
			if ( 'vcap_default_expiry_rule' === $key && false === get_option( $key, false ) && false !== get_option( 'vcap_default_expiry_days', false ) ) {
				$own = true;
			}
			$opt_in  = in_array( $key, self::OPT_IN, true );
			$legal   = self::legal( $key );

			$items[ $key ] = array(
				'item'        => $key,
				'key'         => $key,
				'label'       => self::item_label( $key, $profile['country'] ),
				'from'        => self::display( $key, $current ),
				'to'          => '' === $error ? self::display( $key, $valid ) : SettingsTransfer::display( $value ),
				'value'       => '' === $error ? $valid : null,
				'changed'     => $changed,
				'legal'       => $legal,
				'review'      => $legal ? $review : '',
				'opt_in'      => $opt_in,
				'preselected' => $changed && ! $opt_in && ! $own,
				'own'         => $own,
				'error'       => $error,
			);
		}
		$row = self::expiry_row( $id );
		if ( null !== $row ) {
			$current = null;
			foreach ( Markets::expiry_rows( get_option( Markets::EXPIRY_OPTION, array() ) ) as $existing ) {
				if ( $existing['country'] === $row['country'] ) {
					$current = $existing;
				}
			}
			$table   = self::rows_with( $row );
			$valid   = SettingsTransfer::validate( Markets::EXPIRY_OPTION, $table );
			$error   = SettingsRegistry::locked( Markets::EXPIRY_OPTION ) ? SettingsRegistry::locked_message( Markets::EXPIRY_OPTION ) : ( is_wp_error( $valid ) ? wp_strip_all_tags( $valid->get_error_message() ) : '' );
			$changed = '' === $error && ( null === $current || $current !== $row );

			$items[ self::EXPIRY_ITEM ] = array(
				'item'        => self::EXPIRY_ITEM,
				'key'         => Markets::EXPIRY_OPTION,
				'label'       => self::item_label( self::EXPIRY_ITEM, $row['country'] ),
				'from'        => self::display( self::EXPIRY_ITEM, $current ),
				'to'          => self::display( self::EXPIRY_ITEM, $row ),
				'value'       => '' === $error ? $valid : null,
				'changed'     => $changed,
				'legal'       => true,
				'review'      => $review,
				'opt_in'      => false,
				'preselected' => $changed && null === $current,
				'own'         => null !== $current,
				'error'       => $error,
			);
		}
		uksort(
			$items,
			static function ( $a, $b ): int {
				$pa = array_search( $a, self::ORDER, true );
				$pb = array_search( $b, self::ORDER, true );
				return ( false === $pa ? 99 : (int) $pa ) <=> ( false === $pb ? 99 : (int) $pb );
			}
		);
		return $items;
	}

	/**
	 * Hints of a profile that change no setting: default tax type (legal weight) and accounting format.
	 *
	 * @return array<string, array{label: string, text: string, review: string}>
	 */
	public static function hints( string $id ): array {
		$profile = self::get( $id );
		if ( null === $profile ) {
			return array();
		}
		$tax = array(
			'de'    => __( 'Germany: multi-purpose vouchers (gift cards, value vouchers) are taxed when they are redeemed, single-purpose vouchers when they are sold (§ 3 (13)–(15) UStG). Usual setup: setting "Multi-purpose vouchers as payment" on and gift cards sold with the tax status "None". See TAX.md and have it confirmed by your tax adviser.', 'voucher-captain' ),
			'at'    => __( 'Austria: the EU voucher rules apply – multi-purpose vouchers are taxed when they are redeemed, single-purpose vouchers when they are sold. Usual setup: setting "Multi-purpose vouchers as payment" on and gift cards sold with the tax status "None". See TAX.md and have it confirmed by your tax adviser.', 'voucher-captain' ),
			'ch'    => __( 'Switzerland: Swiss VAT law applies, not the EU voucher rules. Have the treatment of value vouchers and the setting "Multi-purpose vouchers as payment" checked by your tax adviser; see TAX.md.', 'voucher-captain' ),
			'eu'    => __( 'EU: the EU voucher rules apply (Directive (EU) 2016/1065: single-purpose vouchers are taxed when they are sold, multi-purpose vouchers when they are redeemed). Tax rates come from WooCommerce. See TAX.md and have it confirmed by your tax adviser.', 'voucher-captain' ),
			'other' => __( 'Outside the EU the tax treatment of vouchers depends on the country\'s law. Have it checked by your tax adviser; tax rates come from WooCommerce.', 'voucher-captain' ),
		);
		$accounting = array(
			'datev' => __( 'DATEV booking batch for your tax adviser (Integrations (API) → DATEV export), plus the redemption journal as CSV.', 'voucher-captain' ),
			'at'    => __( 'DATEV is a German format: use the redemption journal as CSV for your accounting software or your tax adviser.', 'voucher-captain' ),
			'ch'    => __( 'Use the redemption journal as CSV for your accounting software; the DATEV export is meant for Germany.', 'voucher-captain' ),
			'csv'   => __( 'Redemption journal as CSV (Vouchers → Reports) for your accounting software.', 'voucher-captain' ),
		);
		$out = array();
		if ( isset( $tax[ $profile['tax'] ] ) ) {
			$out['tax'] = array(
				'label'  => __( 'Default tax type', 'voucher-captain' ),
				'text'   => $tax[ $profile['tax'] ],
				'review' => $profile['checked'] ? '' : self::review_mark(),
			);
		}
		if ( isset( $accounting[ $profile['accounting'] ] ) ) {
			$out['accounting'] = array(
				'label'  => __( 'Accounting format', 'voucher-captain' ),
				'text'   => $accounting[ $profile['accounting'] ],
				'review' => '',
			);
		}
		return $out;
	}

	/**
	 * SET-VC-09: the profile that decides country-dependent hints – the profile applied last (option
	 * "vcap_country_profile"), else the one that fits the shop's base country (for_base_country()).
	 */
	public static function current(): string {
		$applied = self::applied();
		if ( null !== $applied && null !== self::get( $applied['id'] ) ) {
			return strtoupper( $applied['id'] );
		}
		return self::for_base_country();
	}

	/**
	 * SET-VC-09: hint on the retention of bookkeeping records (journal) for a profile (default: current()). DE and AT
	 * name their paragraphs and carry the mark "Legal check pending" while the profile is not checked; CH, EU, OTHER
	 * and unknown profiles get the neutral sentence without any paragraph. A filter may give an own text.
	 */
	public static function retention_hint( ?string $id = null ): string {
		$id      = null === $id ? self::current() : strtoupper( $id );
		$profile = self::get( $id );
		$key     = null !== $profile ? $profile['retention_hint'] : '';
		$texts   = array(
			'de' => __( 'Retention periods for bookkeeping records in Germany: 8 or 10 years (§ 147 AO, § 257 HGB).', 'voucher-captain' ),
			'at' => __( 'Retention period for bookkeeping records in Austria: 7 years (§ 132 BAO).', 'voucher-captain' ),
		);
		if ( '' === $key || 'neutral' === $key ) {
			return __( 'Accounting records are subject to statutory retention periods.', 'voucher-captain' );
		}
		$text = $texts[ $key ] ?? $key;
		return null !== $profile && ! $profile['checked'] ? $text . ' (' . self::review_mark() . ')' : $text;
	}

	/**
	 * Applies the chosen rows of a profile. Every chosen value is checked first like an import; one invalid value
	 * refuses all and nothing changes. Then a backup of all settings is saved and the values are stored through
	 * SettingsTransfer::apply() (change log source "country profile"). Rows not chosen, and rows that change nothing,
	 * are left alone. The profile is remembered as applied (setup checklist).
	 *
	 * @param string   $id       Profile id.
	 * @param string[] $selected Items of preview() to apply (option names, or "expiry_row").
	 * @return array{changed: string[], backup: int}|\WP_Error
	 */
	public static function apply( string $id, array $selected ) {
		$preview = self::preview( $id );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$selected = array_values( array_intersect( array_map( 'strval', $selected ), array_keys( $preview ) ) );
		$values   = array();
		$errors   = array();
		foreach ( $selected as $item ) {
			$row = $preview[ $item ];
			if ( '' !== $row['error'] ) {
				$errors[] = $row['error'];
				continue;
			}
			if ( $row['changed'] ) {
				$values[ $row['key'] ] = $row['value'];
			}
		}
		if ( $errors ) {
			return new \WP_Error( 'vcap_profile_invalid', implode( ' ', $errors ) . ' ' . __( 'Nothing was changed.', 'voucher-captain' ), $errors );
		}
		$backup  = $values ? SettingsTransfer::backup( SettingsLog::VIA_PROFILE ) : 0;
		$changed = $values ? SettingsTransfer::apply( $values, SettingsLog::VIA_PROFILE ) : array();
		update_option(
			self::APPLIED_OPTION,
			array(
				'id'   => strtoupper( $id ),
				'time' => time(),
				'user' => get_current_user_id(),
			),
			false
		);
		return array(
			'changed' => $changed,
			'backup'  => $backup,
		);
	}

	/**
	 * The profile applied last, null when none was applied.
	 *
	 * @return array{id: string, time: int, user: int}|null
	 */
	public static function applied(): ?array {
		$applied = get_option( self::APPLIED_OPTION, false );
		if ( ! is_array( $applied ) || '' === (string) ( $applied['id'] ?? '' ) ) {
			return null;
		}
		return array(
			'id'   => (string) $applied['id'],
			'time' => (int) ( $applied['time'] ?? 0 ),
			'user' => (int) ( $applied['user'] ?? 0 ),
		);
	}
}
