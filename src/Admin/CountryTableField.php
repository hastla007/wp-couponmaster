<?php
/**
 * Table fields of the country settings (SET-VC-04).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

use VCAP\Compat\Multilingual;
use VCAP\Markets;

defined( 'ABSPATH' ) || exit;

/**
 * "Statutory validity per country" and "Redemption conditions per country" as tables instead of free text: one row
 * per country (validity: country, years, to the end of the year, source/note; conditions: country, language, text).
 *
 * - Accessible: a real table with column headers and a caption; every control has its own label ("Country, row 2");
 *   "Add row" and "Remove" are buttons (assets/js/country-table.js moves the focus and announces the change).
 * - Without JavaScript: one empty row is always shown for a new entry, and a row whose fields are emptied is removed.
 * - Saving: rows are checked on the server (Markets::check_expiry_rows() / check_terms_rows()); an invalid row is not
 *   saved, a settings error names it ("Row 2 (FR): …"), and the row is shown again marked as invalid so nothing
 *   typed is lost. Valid rows are saved.
 * - Values stored before 1.3.0 (text) are read and shown as rows; text lines that cannot become a row are listed
 *   above the table until the settings are saved.
 *
 * The values are not legally checked by the plugin (description "Have … checked legally"; Health shows countries
 * whose validity is not verified).
 *
 * @since 1.3.0
 */
final class CountryTableField {
	/** Field type of the validity table. */
	public const EXPIRY_TYPE = 'vcap_expiry_table';

	/** Field type of the conditions table. */
	public const TERMS_TYPE = 'vcap_terms_table';

	/** Script handle of the add/remove buttons. */
	public const SCRIPT = 'vcap-country-table';

	/** Transient prefix of rows not saved (shown again once, per user and option). */
	private const REJECTED = 'vcap_rejected_rows_';

	/**
	 * Hooks: both field types and their server-side check on save.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::EXPIRY_TYPE, array( self::class, 'render' ) );
		add_action( 'woocommerce_admin_field_' . self::TERMS_TYPE, array( self::class, 'render' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Markets::EXPIRY_OPTION, array( self::class, 'sanitize' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Markets::TERMS_OPTION, array( self::class, 'sanitize' ), 10, 3 );
	}

	/**
	 * Settings page save: the submitted rows, checked. Invalid rows are left out with an error naming them and kept
	 * for the next page view; null (WooCommerce keeps the stored value) when the table was not part of the form.
	 *
	 * @param mixed                $value  Value as cleaned by WooCommerce (not used: it drops line breaks).
	 * @param array<string, mixed> $option Settings field.
	 * @param mixed                $raw    Submitted value (unslashed).
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function sanitize( $value, $option = array(), $raw = null ): ?array {
		unset( $value );
		$id = (string) ( $option['id'] ?? '' );
		if ( is_string( $raw ) ) {
			// Text as before 1.3.0 (e.g. another form): converted like a stored value; unused lines are reported.
			$lines = Markets::EXPIRY_OPTION === $id ? Markets::unparsed_expiry_lines( $raw ) : \VCAP\ProductConfig::unassigned_terms_lines( $raw );
			self::errors( $option, array_map( static fn( string $line ) => '"' . $line . '"', $lines ) );
			return Markets::EXPIRY_OPTION === $id ? Markets::expiry_rows( $raw ) : Markets::terms_rows( $raw );
		}
		if ( ! is_array( $raw ) || ! isset( $raw['submitted'] ) ) {
			return null;
		}
		$rows  = is_array( $raw['rows'] ?? null ) ? array_values( $raw['rows'] ) : array();
		$check = self::check( $id, $rows );
		$kept  = self::keep_stored( $id, $rows, $check );
		self::errors( $option, $check['errors'] );
		$user = get_current_user_id();
		if ( $check['errors'] && $user > 0 ) {
			set_transient( self::REJECTED . $id . '_' . $user, self::rejected( $id, $rows, $check['errors'], $kept ), 10 * MINUTE_IN_SECONDS );
		}
		return $check['rows'];
	}

	/**
	 * PV-P3-3: an invalid edited row does not delete what was saved before. When a row is not saved and the stored
	 * value has a row for the same country (conditions: country and language) that the submitted rows do not contain,
	 * the stored row is kept and the error says so. Returns the positions of these rows.
	 *
	 * @param string                                                                     $id    Option name.
	 * @param array<mixed>                                                               $rows  Submitted rows.
	 * @param array{rows: array<int, array<string, mixed>>, errors: array<int, string>} $check Check result (changed).
	 * @return array<int, true>
	 */
	private static function keep_stored( string $id, array $rows, array &$check ): array {
		if ( ! $check['errors'] ) {
			return array();
		}
		$expiry = Markets::EXPIRY_OPTION === $id;
		$stored = get_option( $id, '' );
		$stored = $expiry ? Markets::expiry_rows( $stored ) : Markets::terms_rows( $stored );
		$key    = static function ( $row ) use ( $expiry ): string {
			$text    = static fn( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '';
			$country = strtoupper( $text( $row['country'] ?? '' ) );
			return $expiry ? $country : $country . '|' . $text( $row['language'] ?? '' );
		};
		$taken = array();
		foreach ( $check['rows'] as $row ) {
			$taken[ $key( $row ) ] = true;
		}
		$kept = array();
		foreach ( array_keys( $check['errors'] ) as $position ) {
			$row = $rows[ $position ] ?? null;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$wanted = $key( $row );
			if ( isset( $taken[ $wanted ] ) || str_starts_with( $wanted, '|' ) || '' === $wanted ) {
				continue;
			}
			foreach ( $stored as $old ) {
				if ( $key( $old ) === $wanted ) {
					$check['rows'][]               = $old;
					$taken[ $wanted ]              = true;
					$kept[ $position ]             = true;
					$check['errors'][ $position ] .= ' ' . __( 'The previously saved row is kept.', 'voucher-captain' );
					break;
				}
			}
		}
		return $kept;
	}

	/**
	 * Import and WP-CLI (settings register type "table"): rows, or text as before 1.3.0; a WP_Error naming the
	 * invalid rows (nothing is changed then).
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function validate( string $key, $value ) {
		if ( ! in_array( $key, array( Markets::EXPIRY_OPTION, Markets::TERMS_OPTION ), true ) ) {
			if ( is_array( $value ) ) {
				return array_values( array_filter( $value, 'is_array' ) );
			}
			/* translators: %s: option name */
			return new \WP_Error( 'vcap_setting_invalid', sprintf( __( '%s: invalid value, expected a table.', 'voucher-captain' ), $key ) );
		}
		if ( is_string( $value ) ) {
			$lines = Markets::EXPIRY_OPTION === $key ? Markets::unparsed_expiry_lines( $value ) : \VCAP\ProductConfig::unassigned_terms_lines( $value );
			if ( $lines ) {
				/* translators: 1: option name, 2: lines that cannot be read */
				return new \WP_Error( 'vcap_setting_invalid', sprintf( __( '%1$s: these lines cannot be read: %2$s', 'voucher-captain' ), $key, implode( ' | ', array_slice( $lines, 0, 5 ) ) ) );
			}
			return Markets::EXPIRY_OPTION === $key ? Markets::expiry_rows( $value ) : Markets::terms_rows( $value );
		}
		if ( ! is_array( $value ) ) {
			/* translators: %s: option name */
			return new \WP_Error( 'vcap_setting_invalid', sprintf( __( '%s: invalid value, expected a table.', 'voucher-captain' ), $key ) );
		}
		$check = self::check( $key, array_values( $value ) );
		return $check['errors'] ? new \WP_Error( 'vcap_setting_invalid', $key . ': ' . implode( ' ', $check['errors'] ) ) : $check['rows'];
	}

	/**
	 * Renders a table field (both types).
	 *
	 * @param array<string, mixed> $field Settings field.
	 */
	public static function render( $field ): void {
		$field   = is_array( $field ) ? $field : array();
		$id      = (string) ( $field['id'] ?? '' );
		$expiry  = Markets::EXPIRY_OPTION === $id;
		$stored  = get_option( $id, '' );
		$rows    = $expiry ? Markets::expiry_rows( $stored ) : Markets::terms_rows( $stored );
		$legacy  = is_string( $stored ) ? ( $expiry ? Markets::unparsed_expiry_lines( $stored ) : \VCAP\ProductConfig::unassigned_terms_lines( $stored ) ) : array();
		$user    = get_current_user_id();
		$invalid = $user > 0 ? get_transient( self::REJECTED . $id . '_' . $user ) : false;
		$invalid = is_array( $invalid ) ? $invalid : array();
		if ( $invalid ) {
			delete_transient( self::REJECTED . $id . '_' . $user );
		}
		$title = (string) ( $field['title'] ?? '' );
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( self::SCRIPT, VCAP_URL . 'assets/js/country-table.js', array( 'wp-a11y' ), VCAP_VERSION, true );
		}
		$columns = $expiry
			? array( __( 'Country', 'voucher-captain' ), __( 'Years', 'voucher-captain' ), __( 'To the end of the year', 'voucher-captain' ), __( 'Source / note', 'voucher-captain' ) )
			: array( __( 'Country', 'voucher-captain' ), __( 'Language', 'voucher-captain' ), __( 'Conditions', 'voucher-captain' ) );

		echo '<tr class="vcap-country-table-row"><th scope="row" class="titledesc"><span id="' . esc_attr( $id ) . '-label">' . esc_html( $title ) . '</span></th><td class="forminp">';
		echo '<input type="hidden" name="' . esc_attr( $id ) . '[submitted]" value="1" />';
		if ( $legacy ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These lines of the previous text setting could not be converted into rows and are removed when you save. Enter them as rows if they are still needed:', 'voucher-captain' ) . '</p><ul>';
			foreach ( $legacy as $line ) {
				echo '<li><code>' . esc_html( $line ) . '</code></li>';
			}
			echo '</ul></div>';
		}
		echo '<table class="widefat striped vcap-country-table" data-vcap-country-table data-added="' . esc_attr__( 'Row added.', 'voucher-captain' ) . '" data-removed="' . esc_attr__( 'Row removed.', 'voucher-captain' ) . '">';
		echo '<caption class="screen-reader-text">' . esc_html( $title ) . '</caption><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'voucher-captain' ) . '</span></th></tr></thead><tbody>';
		$index = 0;
		foreach ( self::without_kept( $rows, $invalid, $expiry ) as $row ) {
			echo self::row( $id, (string) $index, $row, $expiry ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row().
			++$index;
		}
		foreach ( $invalid as $row ) {
			echo self::row( $id, (string) $index, $row, $expiry, (string) ( $row['error'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row().
			++$index;
		}
		// One empty row for a new entry (also without JavaScript).
		echo self::row( $id, (string) $index, array(), $expiry ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row().
		echo '</tbody></table>';
		echo '<template class="vcap-row-template">' . self::row( $id, '__index__', array(), $expiry ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row().
		echo '<p><button type="button" class="button vcap-row-add" hidden>' . esc_html__( 'Add row', 'voucher-captain' ) . '</button></p>';
		$desc = (string) ( $field['desc'] ?? '' );
		if ( '' !== $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * One table row with a label for each control ("Country, row 2"; "%d" is replaced by the script on changes).
	 *
	 * @param string               $id     Option name.
	 * @param string               $index  Row key in the form.
	 * @param array<string, mixed> $row    Values.
	 * @param bool                 $expiry Validity (else conditions).
	 * @param string               $error  Why the row was not saved ('' = valid).
	 */
	private static function row( string $id, string $index, array $row, bool $expiry, string $error = '' ): string {
		$number = is_numeric( $index ) ? (string) ( (int) $index + 1 ) : '%d';
		$base   = $id . '[rows][' . $index . ']';
		$dom    = $id . '-' . $index;
		$aria   = '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $dom . '-error' ) . '"' : '';
		/* translators: 1: column, e.g. "Country", 2: row number */
		$pattern = __( '%1$s, row %2$s', 'voucher-captain' );
		$label   = static fn( string $column, string $for ) => '<label class="screen-reader-text" for="' . esc_attr( $for ) . '" data-label="' . esc_attr( sprintf( $pattern, $column, '%d' ) ) . '">' . esc_html( sprintf( $pattern, $column, $number ) ) . '</label>';
		$country = strtoupper( (string) ( $row['country'] ?? '' ) );
		$out     = '<tr' . ( '' !== $error ? ' class="vcap-row-invalid"' : '' ) . '><td>' . $label( __( 'Country', 'voucher-captain' ), $dom . '-country' )
			. '<select id="' . esc_attr( $dom . '-country' ) . '" name="' . esc_attr( $base . '[country]' ) . '"' . $aria . '>' . self::country_options( $country ) . '</select>';
		if ( '' !== $error ) {
			$out .= '<p class="vcap-row-error" id="' . esc_attr( $dom . '-error' ) . '" style="color:#d63638;">' . esc_html( $error ) . '</p>';
		}
		$out .= '</td>';
		if ( $expiry ) {
			$years = isset( $row['years'] ) && is_scalar( $row['years'] ) ? (string) $row['years'] : '';
			$out  .= '<td>' . $label( __( 'Years', 'voucher-captain' ), $dom . '-years' ) . '<input type="number" min="0" max="999" step="1" class="small-text" id="' . esc_attr( $dom . '-years' ) . '" name="' . esc_attr( $base . '[years]' ) . '" value="' . esc_attr( $years ) . '"' . $aria . ' /></td>'
				. '<td>' . $label( __( 'To the end of the year', 'voucher-captain' ), $dom . '-end' ) . '<input type="checkbox" value="1" id="' . esc_attr( $dom . '-end' ) . '" name="' . esc_attr( $base . '[end_of_year]' ) . '"' . ( ! empty( $row['end_of_year'] ) ? ' checked="checked"' : '' ) . ' /></td>'
				. '<td>' . $label( __( 'Source / note', 'voucher-captain' ), $dom . '-note' ) . '<input type="text" class="regular-text" maxlength="' . esc_attr( (string) Markets::NOTE_MAX ) . '" id="' . esc_attr( $dom . '-note' ) . '" name="' . esc_attr( $base . '[note]' ) . '" value="' . esc_attr( (string) ( $row['note'] ?? '' ) ) . '" /></td>';
		} else {
			$out .= '<td>' . $label( __( 'Language', 'voucher-captain' ), $dom . '-language' ) . '<select id="' . esc_attr( $dom . '-language' ) . '" name="' . esc_attr( $base . '[language]' ) . '">' . self::language_options( (string) ( $row['language'] ?? '' ) ) . '</select></td>'
				. '<td>' . $label( __( 'Conditions', 'voucher-captain' ), $dom . '-text' ) . '<textarea rows="3" class="large-text" id="' . esc_attr( $dom . '-text' ) . '" name="' . esc_attr( $base . '[text]' ) . '"' . $aria . '>' . esc_textarea( (string) ( $row['text'] ?? '' ) ) . '</textarea></td>';
		}
		/* translators: %s: row number */
		$which = __( 'row %s', 'voucher-captain' );
		$out  .= '<td><button type="button" class="button-link vcap-row-remove" hidden>' . esc_html__( 'Remove', 'voucher-captain' ) . ' <span class="screen-reader-text" data-label="' . esc_attr( sprintf( $which, '%d' ) ) . '">' . esc_html( sprintf( $which, $number ) ) . '</span></button></td></tr>';
		return $out;
	}

	/** @var string|null Options of the country select without a selection, built once per request (PV-P6-5). */
	private static ?string $country_options = null;

	/** @var array<string, true> Country codes of the memoised options. */
	private static array $country_codes = array();

	/**
	 * Options of the country select (WooCommerce's country list, else the ISO codes); a stored code that is not in
	 * the list stays selectable. The list (~250 options) is built once per request and only the selection is set
	 * per row (PV-P6-5).
	 */
	private static function country_options( string $selected ): string {
		if ( null === self::$country_options ) {
			$countries = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_countries() : array();
			if ( ! $countries ) {
				foreach ( Markets::country_codes() as $code ) {
					$countries[ $code ] = $code;
				}
			}
			$out                 = '<option value="">' . esc_html__( '— Choose a country —', 'voucher-captain' ) . '</option>';
			self::$country_codes = array();
			foreach ( $countries as $code => $name ) {
				self::$country_codes[ (string) $code ] = true;
				$out                                  .= self::country_option( (string) $code, (string) $name );
			}
			self::$country_options = $out;
		}
		if ( '' === $selected ) {
			return self::$country_options;
		}
		if ( ! isset( self::$country_codes[ $selected ] ) ) {
			return self::$country_options . str_replace( '">', '" selected="selected">', self::country_option( $selected, $selected ) );
		}
		$plain = '<option value="' . esc_attr( $selected ) . '">';
		$pos   = strpos( self::$country_options, $plain );
		return false === $pos ? self::$country_options : substr_replace( self::$country_options, '<option value="' . esc_attr( $selected ) . '" selected="selected">', $pos, strlen( $plain ) );
	}

	/** One option of the country select (without selection). */
	private static function country_option( string $code, string $name ): string {
		return '<option value="' . esc_attr( $code ) . '">' . esc_html( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) . ( $name !== $code ? ' (' . $code . ')' : '' ) ) . '</option>';
	}

	/** Forgets the memoised country options (tests; another locale). */
	public static function reset_options(): void {
		self::$country_options = null;
		self::$country_codes   = array();
	}

	/**
	 * Options of the language select: every language, then the shop's languages (site language, WPML/Polylang); a
	 * stored language that is no longer offered stays selectable.
	 */
	private static function language_options( string $selected ): string {
		$locales = Multilingual::shop_locales();
		if ( '' !== $selected && ! in_array( $selected, $locales, true ) ) {
			$locales[] = $selected;
		}
		$out = '<option value="">' . esc_html__( 'All languages', 'voucher-captain' ) . '</option>';
		foreach ( $locales as $locale ) {
			$out .= '<option value="' . esc_attr( $locale ) . '"' . ( $locale === $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $locale ) . '</option>';
		}
		return $out;
	}

	/**
	 * PV-P3-3: a stored row kept because its edited version was invalid is shown once – as the invalid row with its
	 * error (saving it again unchanged keeps the stored row again).
	 *
	 * @param array<int, array<string, mixed>> $rows    Stored rows.
	 * @param array<mixed>                     $invalid Rows not saved.
	 * @param bool                             $expiry  Validity (else conditions).
	 * @return array<int, array<string, mixed>>
	 */
	private static function without_kept( array $rows, array $invalid, bool $expiry ): array {
		$key  = static fn( array $row ): string => strtoupper( (string) ( $row['country'] ?? '' ) ) . ( $expiry ? '' : '|' . (string) ( $row['language'] ?? '' ) );
		$skip = array();
		foreach ( $invalid as $row ) {
			if ( is_array( $row ) && ! empty( $row['kept'] ) ) {
				$skip[ $key( $row ) ] = true;
			}
		}
		return $skip ? array_values( array_filter( $rows, static fn( array $row ): bool => ! isset( $skip[ $key( $row ) ] ) ) ) : $rows;
	}

	/**
	 * Checks rows of one of the two settings.
	 *
	 * @param string       $id   Option name.
	 * @param array<mixed> $rows Rows.
	 * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
	 */
	private static function check( string $id, array $rows ): array {
		return Markets::EXPIRY_OPTION === $id ? Markets::check_expiry_rows( $rows ) : Markets::check_terms_rows( $rows );
	}

	/**
	 * Settings errors on top of the page, one per row not saved.
	 *
	 * @param array<string, mixed> $option Settings field.
	 * @param string[]             $errors Messages.
	 */
	private static function errors( array $option, array $errors ): void {
		if ( ! $errors || ! class_exists( '\WC_Admin_Settings' ) ) {
			return;
		}
		$title = (string) ( $option['title'] ?? $option['id'] ?? '' );
		foreach ( $errors as $error ) {
			/* translators: 1: setting name, 2: what is wrong with the row, e.g. "Row 2 (FR): choose a country." */
			\WC_Admin_Settings::add_error( sprintf( __( '%1$s – not saved: %2$s', 'voucher-captain' ), $title, $error ) );
		}
	}

	/**
	 * The submitted rows that were not saved, cleaned, each with its error – shown again once.
	 *
	 * @param string             $id     Option name.
	 * @param array<mixed>       $rows   Submitted rows (in form order).
	 * @param array<int, string> $errors Errors by position of the row.
	 * @param array<int, true>   $kept   Positions whose stored row was kept (PV-P3-3: shown instead of that row).
	 * @return array<int, array<string, mixed>>
	 */
	private static function rejected( string $id, array $rows, array $errors, array $kept = array() ): array {
		$text = static fn( $value ): string => is_scalar( $value ) ? (string) $value : '';
		$out  = array();
		foreach ( $errors as $position => $error ) {
			$row = $rows[ $position ] ?? null;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array(
				'country' => strtoupper( sanitize_key( $text( $row['country'] ?? '' ) ) ),
				// Without "Row 2 (FR):" – the row is shown again at another position.
				'error'   => (string) preg_replace( '/^[^:]*:\s*/', '', $error ),
			);
			if ( isset( $kept[ $position ] ) ) {
				$clean['kept'] = true;
			}
			if ( Markets::EXPIRY_OPTION === $id ) {
				$clean['years']       = sanitize_text_field( $text( $row['years'] ?? '' ) );
				$clean['end_of_year'] = ! empty( $row['end_of_year'] );
				$clean['note']        = sanitize_text_field( $text( $row['note'] ?? '' ) );
			} else {
				$clean['language'] = sanitize_text_field( $text( $row['language'] ?? '' ) );
				$clean['text']     = wp_kses_post( $text( $row['text'] ?? '' ) );
			}
			$out[] = $clean;
		}
		return $out;
	}
}
