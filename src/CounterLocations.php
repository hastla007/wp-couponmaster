<?php
/**
 * Managed list of tills/branches as a table (SET-VC-22).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * The setting "Tills / branches" (option Counter::LOCATIONS, name unchanged) as a table: name, key, active – plus the
 * columns add-ons add with the filter "vcap_counter_location_fields".
 *
 * Stored format: a JSON list of rows ({"name": "Shop 1", "key": "shop-1", "active": "yes", …}) in the same text
 * option; '' = no managed list (free text on the scanner device). The format of 1.2.0 – one name per line – is still
 * read (every line an active till, key from its name) and converted the next time the section is saved. The scanner,
 * the user profile and the daily limits keep working with the till NAME (ledger column "till"), as before; only
 * active tills are offered. Saving goes through WooCommerce's settings form (its nonce and manage_woocommerce).
 *
 * @since 1.3.0
 */
final class CounterLocations {
	/** Field type of the table (WooCommerce action "woocommerce_admin_field_{type}"). */
	public const FIELD_TYPE = 'vcap_counter_locations';

	/** Empty rows the table offers for new tills. */
	public const BLANK_ROWS = 3;

	/** Longest key of a till. */
	private const KEY_LENGTH = 64;

	/** Column types an add-on may use. */
	private const COLUMN_TYPES = array( 'text', 'checkbox', 'select' );

	/** Hooks: the table and the conversion of the posted rows when the section is saved. */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Counter::LOCATIONS, array( self::class, 'sanitize_option' ), 10, 3 );
	}

	/**
	 * Columns of the table (column id => label, type, default, options): name, key and active of the core, then those
	 * of add-ons. Core columns cannot be removed or changed in type.
	 *
	 * @return array<string, array{label: string, type: string, default: string, options: array<string, string>}>
	 */
	public static function columns(): array {
		$core = array(
			'name'   => array(
				'label'   => __( 'Name', 'voucher-captain' ),
				'type'    => 'text',
				'default' => '',
				'options' => array(),
			),
			'key'    => array(
				'label'   => __( 'Identifier', 'voucher-captain' ),
				'type'    => 'text',
				'default' => '',
				'options' => array(),
			),
			'active' => array(
				'label'   => __( 'Active', 'voucher-captain' ),
				'type'    => 'checkbox',
				'default' => 'yes',
				'options' => array(),
			),
		);
		/**
		 * Columns of the table "Tills / branches" (section "Counter & locations"). Add-ons add a column as
		 * id => array( 'label' => …, 'type' => 'text'|'checkbox'|'select', 'default' => …, 'options' => array( value =>
		 * label ) for a select ); its value is stored with the row and read with CounterLocations::rows(). The core
		 * columns name, key and active cannot be removed or changed.
		 *
		 * @since 1.3.0
		 * @param array<string, array<string, mixed>> $columns Column id => definition.
		 */
		$extra = apply_filters( 'vcap_counter_location_fields', $core );
		$out   = $core;
		foreach ( is_array( $extra ) ? $extra : array() as $id => $column ) {
			$id = is_string( $id ) ? substr( sanitize_key( $id ), 0, 40 ) : '';
			if ( '' === $id || isset( $core[ $id ] ) || ! is_array( $column ) ) {
				continue;
			}
			$type    = in_array( $column['type'] ?? 'text', self::COLUMN_TYPES, true ) ? (string) $column['type'] : 'text';
			$options = array();
			foreach ( (array) ( $column['options'] ?? array() ) as $value => $label ) {
				$options[ (string) $value ] = (string) $label;
			}
			if ( 'select' === $type && ! $options ) {
				$type = 'text';
			}
			$label = trim( wp_strip_all_tags( (string) ( $column['label'] ?? '' ) ) );
			$out[ $id ] = array(
				'label'   => '' !== $label ? $label : $id,
				'type'    => $type,
				'default' => is_scalar( $column['default'] ?? '' ) ? (string) ( $column['default'] ?? '' ) : '',
				'options' => $options,
			);
		}
		return $out;
	}

	/**
	 * The stored rows: the JSON list of 1.3.0, the free text of 1.2.0 (one name per line) or an array written by code.
	 * Rows without a name are dropped, names are unique (first wins), keys are unique and filled from the name.
	 *
	 * @param mixed $stored Value of the option.
	 * @return array<int, array<string, string>>
	 */
	public static function parse( $stored ): array {
		if ( is_string( $stored ) ) {
			$text = trim( $stored );
			if ( '' === $text ) {
				return array();
			}
			$json = str_starts_with( $text, '[' ) ? json_decode( $text, true ) : null;
			$stored = is_array( $json ) ? $json : (array) preg_split( '/\r\n|\r|\n/', $text );
		}
		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Rows of the setting (see parse()).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function rows(): array {
		return self::parse( Settings::value( Counter::LOCATIONS ) );
	}

	/**
	 * Names of the active tills, in table order (what the scanner offers).
	 *
	 * @return string[]
	 */
	public static function active_names(): array {
		$names = array();
		foreach ( self::rows() as $row ) {
			if ( 'yes' === $row['active'] ) {
				$names[] = $row['name'];
			}
		}
		return $names;
	}

	/**
	 * Rows to store: each row checked, unknown fields kept as text (e.g. the column of an add-on that is inactive).
	 *
	 * @param array<mixed, mixed> $rows Rows (arrays) or names (strings).
	 * @return array<int, array<string, string>>
	 */
	public static function normalise( array $rows ): array {
		$columns = self::columns();
		$out     = array();
		$names   = array();
		$keys    = array();
		foreach ( $rows as $row ) {
			$row  = is_array( $row ) ? $row : array( 'name' => is_scalar( $row ) ? (string) $row : '' );
			$name = Counter::clean_till( is_scalar( $row['name'] ?? null ) ? (string) $row['name'] : '' );
			if ( '' === $name || isset( $names[ $name ] ) ) {
				continue;
			}
			$names[ $name ] = true;
			$clean          = array();
			foreach ( $row as $id => $value ) {
				if ( is_string( $id ) && ! isset( $columns[ $id ] ) && is_scalar( $value ) && '' !== sanitize_key( $id ) ) {
					$clean[ substr( sanitize_key( $id ), 0, 40 ) ] = sanitize_text_field( (string) $value );
				}
			}
			foreach ( $columns as $id => $column ) {
				$value = array_key_exists( $id, $row ) && is_scalar( $row[ $id ] ) ? (string) $row[ $id ] : $column['default'];
				if ( 'checkbox' === $column['type'] ) {
					$value = in_array( strtolower( trim( $value ) ), array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
				} elseif ( 'select' === $column['type'] ) {
					$value = array_key_exists( $value, $column['options'] ) ? $value : ( array_key_exists( $column['default'], $column['options'] ) ? $column['default'] : (string) array_key_first( $column['options'] ) );
				} else {
					$value = sanitize_text_field( $value );
				}
				$clean[ $id ] = $value;
			}
			$clean['name'] = $name;
			$key           = self::clean_key( $clean['key'] );
			$key           = '' !== $key ? $key : self::clean_key( sanitize_title( $name ) );
			$key           = '' !== $key ? $key : 'till';
			$base          = $key;
			for ( $n = 2; isset( $keys[ $key ] ); $n++ ) {
				$key = substr( $base, 0, self::KEY_LENGTH - 1 - strlen( (string) $n ) ) . '-' . $n;
			}
			$keys[ $key ]  = true;
			$clean['key']  = $key;
			$out[]         = $clean;
		}
		return $out;
	}

	/** A till key: lower-case letters, digits, "-" and "_", at most 64 characters. */
	public static function clean_key( string $key ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( $key ) ) ), 0, self::KEY_LENGTH );
	}

	/**
	 * Value of the option for rows: '' without rows (free text on the device), else the JSON list.
	 *
	 * @param array<int, array<string, string>> $rows Checked rows (normalise()).
	 */
	public static function encode( array $rows ): string {
		return $rows ? (string) wp_json_encode( array_values( $rows ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	}

	/**
	 * Rows posted by the table (index => column => value). A checkbox that is not ticked is not posted: "no". Fields of
	 * columns the form does not show (an inactive add-on) are taken from the stored row with the same key.
	 *
	 * @param array<mixed, mixed>               $posted Posted rows.
	 * @param array<int, array<string, string>> $stored Rows stored before.
	 * @return array<int, array<string, string>>
	 */
	public static function from_post( array $posted, array $stored = array() ): array {
		$columns = self::columns();
		$by_key  = array();
		foreach ( $stored as $row ) {
			$by_key[ $row['key'] ] = $row;
		}
		$rows = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $columns as $id => $column ) {
				if ( 'checkbox' === $column['type'] && ! isset( $row[ $id ] ) ) {
					$row[ $id ] = 'no';
				}
			}
			$key = self::clean_key( is_scalar( $row['key'] ?? null ) ? (string) $row['key'] : '' );
			if ( '' !== $key && isset( $by_key[ $key ] ) ) {
				$row += array_diff_key( $by_key[ $key ], $columns );
			}
			$rows[] = $row;
		}
		return self::normalise( $rows );
	}

	/**
	 * WooCommerce filter "woocommerce_admin_settings_sanitize_option_vcap_counter_locations": the posted table (or a
	 * text, e.g. the former free-text field) becomes the stored JSON list; null (field not posted) keeps the value.
	 *
	 * @param mixed                $value  Value cleaned by WooCommerce.
	 * @param array<string, mixed> $option Settings field.
	 * @param mixed                $raw    Posted value.
	 * @return string|null
	 */
	public static function sanitize_option( $value, $option = array(), $raw = null ) {
		$raw = null !== $raw ? $raw : $value;
		if ( is_array( $raw ) ) {
			return self::encode( self::from_post( $raw, self::rows() ) );
		}
		if ( is_string( $raw ) ) {
			return self::encode( self::parse( $raw ) );
		}
		return null;
	}

	/**
	 * Settings field of the table (registered option, default from the register).
	 *
	 * @return array<string, mixed>
	 */
	public static function field(): array {
		return SettingsRegistry::field(
			Counter::LOCATIONS,
			array(
				'title' => __( 'Tills / branches', 'voucher-captain' ),
				'type'  => self::FIELD_TYPE,
				'desc'  => __( 'One till or branch per row; empty rows are ignored, clear the name to remove a till. When the list has an active till, the scanner offers the active ones instead of a free text field, and a staff member can be fixed to one on their user profile. The identifier is a short key for other systems (filled from the name when empty). The scanner works online only: without a connection nothing is booked (on purpose – an offline scanner could redeem the same code twice).', 'voucher-captain' ),
			)
		);
	}

	/**
	 * The table: one row per till, plus empty rows for new ones.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render( $field ): void {
		$field   = is_array( $field ) ? $field : array();
		$columns = self::columns();
		$rows    = self::rows();
		$id      = (string) ( $field['id'] ?? Counter::LOCATIONS );
		$blank   = array();
		foreach ( $columns as $column_id => $column ) {
			$blank[ $column_id ] = 'key' === $column_id ? '' : $column['default'];
		}
		for ( $i = 0; $i < self::BLANK_ROWS; $i++ ) {
			$rows[] = $blank;
		}
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( (string) ( $field['title'] ?? '' ) ) . '</th><td class="forminp">';
		echo '<table class="widefat striped vcap-counter-locations" style="max-width:720px"><caption class="screen-reader-text">' . esc_html__( 'Tills and branches', 'voucher-captain' ) . '</caption><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_values( $rows ) as $index => $row ) {
			echo '<tr>';
			foreach ( $columns as $column_id => $column ) {
				$name  = $id . '[' . $index . '][' . $column_id . ']';
				$value = (string) ( $row[ $column_id ] ?? $column['default'] );
				/* translators: 1: column label, 2: row number */
				$aria = sprintf( __( '%1$s, row %2$d', 'voucher-captain' ), $column['label'], $index + 1 );
				echo '<td>';
				if ( 'checkbox' === $column['type'] ) {
					echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" aria-label="' . esc_attr( $aria ) . '"' . ( 'yes' === $value ? ' checked' : '' ) . '>';
				} elseif ( 'select' === $column['type'] ) {
					echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $aria ) . '">';
					foreach ( $column['options'] as $option => $label ) {
						echo '<option value="' . esc_attr( (string) $option ) . '"' . ( (string) $option === $value ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
				} else {
					$size = 'key' === $column_id ? ' maxlength="' . self::KEY_LENGTH . '"' : ( 'name' === $column_id ? ' maxlength="100"' : '' );
					echo '<input type="text" class="regular-text" style="width:100%" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $aria ) . '"' . $size . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $size is a fixed attribute.
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . wp_kses_post( (string) $field['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}
}
