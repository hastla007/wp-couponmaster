<?php
/**
 * Settings register (SET-VC-01).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One source for default, type, bounds, section, legal weight, export and secret of every setting (config/settings.php,
 * filter "vcap_settings_registry"). Settings::get() takes its defaults from here, the settings fields their defaults
 * and number bounds (field()), and saving checks every number on the server (sanitize_option()).
 *
 * @since 1.3.0
 */
final class SettingsRegistry {
	/** Data types of a setting. */
	public const TYPES = array( 'string', 'text', 'bool', 'int', 'decimal', 'select', 'list', 'post' );

	/** Section of settings without a field (created by the plugin, e.g. secrets). */
	public const HIDDEN = 'hidden';

	/** Attributes of an entry without an own value. */
	private const BASE = array(
		'type'    => 'string',
		'default' => '',
		'min'     => null,
		'max'     => null,
		'empty'   => false,
		'choices' => null,
		'section' => self::HIDDEN,
		'legal'   => false,
		'export'  => true,
		'secret'  => false,
	);

	/** @var array<string, mixed>|null config/settings.php as read. */
	private static ?array $config = null;

	/** @var array<string, array<string, mixed>>|null The filtered settings once all plugins are loaded. */
	private static ?array $cache = null;

	/** Whether the filter runs right now (a callback reading a setting gets the core register, no recursion). */
	private static bool $filtering = false;

	/**
	 * config/settings.php (read once).
	 *
	 * @return array<string, mixed>
	 */
	private static function config(): array {
		if ( null === self::$config ) {
			$file         = defined( 'VCAP_PATH' ) ? VCAP_PATH . 'config/settings.php' : dirname( __DIR__ ) . '/config/settings.php';
			$data         = is_readable( $file ) ? include $file : array();
			self::$config = is_array( $data ) ? $data : array();
		}
		return self::$config;
	}

	/** Forgets the cached register (tests, or after an add-on changed its filter late). */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * All settings (option name => attributes, see config/settings.php), with the entries of add-ons.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$core = (array) ( self::config()['settings'] ?? array() );
		if ( self::$filtering ) {
			return array_map( array( self::class, 'normalise' ), $core );
		}
		self::$filtering = true;
		try {
			/**
			 * The settings register (arg: option name => attributes type, default, min, max, empty, choices, section,
			 * legal, export, secret, fresh – see config/settings.php). Add-ons add their own options here (for export
			 * without secrets, the change log and WP-CLI) and may change attributes of core entries; core entries cannot
			 * be removed. A changed default changes what Settings::get() returns for shops that never saved the option.
			 *
			 * @since 1.3.0
			 * @param array<string, array<string, mixed>> $settings Settings register.
			 */
			$filtered = apply_filters( 'vcap_settings_registry', $core );
		} finally {
			self::$filtering = false;
		}
		$filtered = is_array( $filtered ) ? $filtered + $core : $core;
		$out      = array();
		foreach ( $filtered as $key => $entry ) {
			if ( is_string( $key ) && preg_match( '/^[a-z0-9_]{1,191}$/', $key ) && is_array( $entry ) ) {
				$out[ $key ] = self::normalise( $entry );
			}
		}
		// Add-ons register their filters while plugins load; until then nothing is cached.
		if ( did_action( 'plugins_loaded' ) ) {
			self::$cache = $out;
		}
		return $out;
	}

	/**
	 * An entry with all attributes, checked.
	 *
	 * @param array<string, mixed> $entry Entry as given.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $entry ): array {
		$entry            = $entry + self::BASE;
		$entry['type']    = in_array( $entry['type'], self::TYPES, true ) ? $entry['type'] : 'string';
		$entry['min']     = is_numeric( $entry['min'] ) ? $entry['min'] : null;
		$entry['max']     = is_numeric( $entry['max'] ) ? $entry['max'] : null;
		$entry['empty']   = (bool) $entry['empty'];
		$entry['choices'] = is_array( $entry['choices'] ) ? array_values( array_map( 'strval', $entry['choices'] ) ) : null;
		$entry['section'] = is_string( $entry['section'] ) ? $entry['section'] : self::HIDDEN;
		$entry['legal']   = (bool) $entry['legal'];
		$entry['secret']  = (bool) $entry['secret'];
		$entry['export']  = ! $entry['secret'] && (bool) $entry['export'];
		return $entry;
	}

	/** Whether $key is a setting of the register. */
	public static function has( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/**
	 * Attributes of a setting, null when $key is no setting.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Default of a setting (what Settings::get() returns while it was never saved), null for unknown keys.
	 *
	 * @return mixed
	 */
	public static function default( string $key ) {
		return self::all()[ $key ]['default'] ?? null;
	}

	/**
	 * Default of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map( static fn( array $entry ) => $entry['default'], self::all() );
	}

	/**
	 * Value a first installation stores (attribute "fresh", else the default).
	 *
	 * @return mixed
	 */
	public static function fresh_default( string $key ) {
		$entry = self::get( $key );
		return null === $entry ? null : ( $entry['fresh'] ?? $entry['default'] );
	}

	/**
	 * Option names of a section ('' = General, self::HIDDEN = without field), in register order.
	 *
	 * @return string[]
	 */
	public static function in_section( string $section ): array {
		return array_keys( array_filter( self::all(), static fn( array $entry ) => $entry['section'] === $section ) );
	}

	/**
	 * Option names with an attribute set: 'export', 'secret' or 'legal'.
	 *
	 * @return string[]
	 */
	public static function with( string $flag ): array {
		return array_keys( array_filter( self::all(), static fn( array $entry ) => ! empty( $entry[ $flag ] ) ) );
	}

	/**
	 * Settings per user (user meta key => attributes).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function user_meta(): array {
		return array_map( array( self::class, 'normalise' ), (array) ( self::config()['user_meta'] ?? array() ) );
	}

	/**
	 * Options that are no settings (state, counters, cursors, locks, caches), and the prefixes of such options.
	 *
	 * @return array{names: string[], prefixes: string[]}
	 */
	public static function internal(): array {
		$config = self::config();
		return array(
			'names'    => array_values( array_map( 'strval', (array) ( $config['internal'] ?? array() ) ) ),
			'prefixes' => array_values( array_map( 'strval', (array) ( $config['internal_prefixes'] ?? array() ) ) ),
		);
	}

	/** Whether $option is internal state (named or by prefix), not a setting. */
	public static function is_internal( string $option ): bool {
		$internal = self::internal();
		if ( in_array( $option, $internal['names'], true ) ) {
			return true;
		}
		foreach ( $internal['prefixes'] as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $option, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A settings field of a registered option completed from the register: id, default (unless the field sets its
	 * own, e.g. a default computed at runtime) and, for number fields, min and max before the other attributes.
	 *
	 * @param string               $key   Option name.
	 * @param array<string, mixed> $field WooCommerce settings field without id.
	 * @return array<string, mixed>
	 */
	public static function field( string $key, array $field ): array {
		$entry = self::get( $key );
		$field = array( 'id' => $key ) + $field;
		if ( null === $entry ) {
			return $field;
		}
		if ( ! array_key_exists( 'default', $field ) ) {
			$field['default'] = $entry['default'];
		}
		if ( 'number' === ( $field['type'] ?? '' ) ) {
			$bounds = array_filter(
				array(
					'min' => $entry['min'],
					'max' => $entry['max'],
				),
				static fn( $bound ) => null !== $bound
			);
			$field['custom_attributes'] = $bounds + (array) ( $field['custom_attributes'] ?? array() );
		}
		return $field;
	}

	/**
	 * Server-side check of a number setting: a value outside min/max is set to the nearest bound, a whole-number
	 * setting is rounded; anything that is no number (or empty where empty is not allowed) returns null – WooCommerce
	 * then keeps the stored value. A valid value is returned as entered, so the stored format does not change.
	 * Values of other types pass unchanged.
	 *
	 * @param mixed  $value Submitted value.
	 * @param string $key   Option name.
	 * @return mixed
	 */
	public static function sanitize( $value, string $key ) {
		$entry = self::get( $key );
		if ( null === $value || null === $entry || ! in_array( $entry['type'], array( 'int', 'decimal' ), true ) ) {
			return $value;
		}
		$text = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $text ) {
			return $entry['empty'] ? '' : null;
		}
		if ( ! is_numeric( $text ) ) {
			return null;
		}
		$number = 'int' === $entry['type'] ? round( (float) $text ) : (float) $text;
		$fixed  = $number;
		if ( null !== $entry['min'] && $fixed < (float) $entry['min'] ) {
			$fixed = (float) $entry['min'];
		}
		if ( null !== $entry['max'] && $fixed > (float) $entry['max'] ) {
			$fixed = (float) $entry['max'];
		}
		if ( $fixed === (float) $text ) {
			return $text;
		}
		return 'int' === $entry['type'] ? (string) (int) $fixed : (string) $fixed;
	}

	/**
	 * Hooks sanitize_option() for every number setting while the "Vouchers" tab is saved (Settings::save()), after the
	 * option's own sanitizer (priority 20) – so the check covers the entries add-ons registered, too.
	 */
	public static function hook_number_checks(): void {
		foreach ( self::all() as $key => $entry ) {
			if ( in_array( $entry['type'], array( 'int', 'decimal' ), true ) ) {
				add_filter( 'woocommerce_admin_settings_sanitize_option_' . $key, array( self::class, 'sanitize_option' ), 20, 2 );
			}
		}
	}

	/**
	 * WooCommerce filter "woocommerce_admin_settings_sanitize_option_{option}".
	 *
	 * @param mixed                $value  Submitted value (null: an earlier sanitizer refused it).
	 * @param array<string, mixed> $option Settings field.
	 * @return mixed
	 */
	public static function sanitize_option( $value, $option = array() ) {
		$key = is_array( $option ) ? (string) ( $option['id'] ?? '' ) : '';
		return '' !== $key ? self::sanitize( $value, $key ) : $value;
	}

	/**
	 * A whole-number setting as the code uses it (SET-VC-18, SET-VC-31): Settings::value() kept within the register's
	 * min and max, so a value written past the settings page (update_option(), import) cannot switch a protection off.
	 * Callers pass the result to their filter as before; the filter decides.
	 */
	public static function bounded_int( string $key ): int {
		$entry = self::get( $key );
		$value = Settings::value( $key );
		$value = is_numeric( $value ) ? (int) round( (float) $value ) : (int) ( $entry['default'] ?? 0 );
		if ( null !== $entry && null !== $entry['min'] ) {
			$value = max( (int) $entry['min'], $value );
		}
		if ( null !== $entry && null !== $entry['max'] ) {
			$value = min( (int) $entry['max'], $value );
		}
		return $value;
	}
}
