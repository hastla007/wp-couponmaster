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
	public const TYPES = array( 'string', 'text', 'bool', 'int', 'decimal', 'select', 'list', 'post', 'table', 'map' );

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

	/** @var array<string, array<string, mixed>>|null The filtered settings (see all() for when it is kept). */
	private static ?array $cache = null;

	/** Callbacks on "vcap_settings_registry" the cache was built with, null = kept for the whole request (from "init"). */
	private static ?int $cache_hooks = null;

	/** Whether the filter runs right now (a callback reading a setting gets the core register, no recursion). */
	private static bool $filtering = false;

	/** @var array<string, string> PV-P5-4: fields whose value was refused or corrected in this request (key => message). */
	private static array $flagged = array();

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
		self::$cache       = null;
		self::$cache_hooks = null;
	}

	/**
	 * Number of callbacks on "vcap_settings_registry" (-1 when the hook store cannot be read): while plugins load, a
	 * new callback (e.g. an add-on in "vcap_loaded") invalidates the cached register (PV-P6-1).
	 */
	private static function hook_count(): int {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return -1;
		}
		$hook = $GLOBALS['wp_filter']['vcap_settings_registry'] ?? null;
		if ( null === $hook ) {
			return 0;
		}
		$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks ) ? $hook->callbacks : null;
		if ( null === $callbacks ) {
			return -1;
		}
		$count = 0;
		foreach ( $callbacks as $priority ) {
			$count += is_array( $priority ) ? count( $priority ) : 0;
		}
		return $count;
	}

	/**
	 * All settings (option name => attributes, see config/settings.php), with the entries of add-ons.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			if ( null === self::$cache_hooks || ( self::$cache_hooks >= 0 && self::hook_count() === self::$cache_hooks ) ) {
				return self::$cache;
			}
			self::$cache = null;
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
		/*
		 * Add-ons register their filters while plugins load, in "plugins_loaded" or – as documented – in "vcap_loaded"
		 * (PV-P6-1). From "init" on the register is kept for the request; before, it is kept only as long as no
		 * callback was added to the filter (and the plugin forgets it right after "vcap_loaded").
		 */
		if ( did_action( 'init' ) ) {
			self::$cache       = $out;
			self::$cache_hooks = null;
		} else {
			$hooks = self::hook_count();
			if ( $hooks >= 0 ) {
				self::$cache       = $out;
				self::$cache_hooks = $hooks;
			}
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
	 * SET-VC-23: the help of a setting with legal weight is shown as visible text below the field, never only as a
	 * tooltip (a string desc_tip becomes the start of desc; desc_tip true is dropped, so desc stays visible).
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
		if ( $entry['legal'] && array_key_exists( 'desc_tip', $field ) && 'checkbox' !== ( $field['type'] ?? '' ) ) {
			$tip = $field['desc_tip'];
			unset( $field['desc_tip'] );
			if ( is_string( $tip ) && '' !== trim( $tip ) ) {
				$desc          = trim( (string) ( $field['desc'] ?? '' ) );
				$field['desc'] = '' !== $desc ? $tip . ' ' . $desc : $tip;
			}
		}
		if ( isset( self::$flagged[ $key ] ) ) {
			$field = self::mark_invalid( $field, self::$flagged[ $key ] );
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
	 * PV-P5-4: marks a field whose submitted value was refused or corrected in this request. WooCommerce shows the
	 * message at the top of the page (WC_Admin_Settings::add_error()); the field itself then carries
	 * aria-invalid="true" and repeats the message below it (see field()), so it can be found and is read out with it.
	 */
	public static function flag( string $key, string $message ): void {
		if ( '' !== $key && '' !== $message ) {
			self::$flagged[ $key ] = $message;
		}
	}

	/**
	 * Fields flagged in this request (key => message).
	 *
	 * @return array<string, string>
	 */
	public static function flagged(): array {
		return self::$flagged;
	}

	/** Forgets the flagged fields (tests). */
	public static function reset_flags(): void {
		self::$flagged = array();
	}

	/**
	 * A field with aria-invalid and the message as the first part of its description (a description shown only as a
	 * tooltip – desc_tip true – moves to the tooltip text so the message stays visible).
	 *
	 * @param array<string, mixed> $field   Settings field.
	 * @param string               $message Message.
	 * @return array<string, mixed>
	 */
	private static function mark_invalid( array $field, string $message ): array {
		$field['custom_attributes'] = array( 'aria-invalid' => 'true' ) + (array) ( $field['custom_attributes'] ?? array() );
		$desc                       = trim( (string) ( $field['desc'] ?? '' ) );
		if ( true === ( $field['desc_tip'] ?? null ) ) {
			$field['desc_tip'] = '' !== $desc ? $desc : false;
			$desc              = '';
		}
		$field['desc'] = '<strong class="vcap-field-error">' . esc_html( $message ) . '</strong>' . ( '' !== $desc ? '<br>' . $desc : '' );
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
		if ( '' === $key ) {
			return $value;
		}
		$fixed   = self::sanitize( $value, $key );
		$message = self::correction_message( $value, $fixed, $key, (string) ( $option['title'] ?? '' ) );
		if ( '' !== $message && class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( $message );
		}
		self::flag( $key, $message ); // PV-P5-4: the field is marked on the page shown after saving.
		return $fixed;
	}

	/**
	 * SET-VC-23: the message shown when saving changed or refused a number – it names the field and the allowed
	 * range. '' when the value was stored as entered (or is no number setting).
	 *
	 * @param mixed  $value Submitted value.
	 * @param mixed  $fixed What sanitize() returned for it.
	 * @param string $key   Option name.
	 * @param string $label Field label ('' = the option name).
	 */
	public static function correction_message( $value, $fixed, string $key, string $label = '' ): string {
		$entry = self::get( $key );
		if ( null === $value || null === $entry || ! in_array( $entry['type'], array( 'int', 'decimal' ), true ) ) {
			return '';
		}
		$text = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( null !== $fixed && (string) $fixed === $text ) {
			return '';
		}
		$label = '' !== trim( $label ) ? trim( wp_strip_all_tags( $label ) ) : $key;
		$min   = $entry['min'];
		$max   = $entry['max'];
		if ( null !== $min && null !== $max ) {
			/* translators: 1: smallest allowed value, 2: largest allowed value */
			$range = sprintf( __( 'from %1$s to %2$s', 'voucher-captain' ), $min, $max );
		} elseif ( null !== $min ) {
			/* translators: %s: smallest allowed value */
			$range = sprintf( __( '%s or more', 'voucher-captain' ), $min );
		} elseif ( null !== $max ) {
			/* translators: %s: largest allowed value */
			$range = sprintf( __( 'up to %s', 'voucher-captain' ), $max );
		} else {
			$range = __( 'of any size', 'voucher-captain' );
		}
		$whole = 'int' === $entry['type'] ? __( 'whole numbers', 'voucher-captain' ) : __( 'numbers', 'voucher-captain' );
		if ( null === $fixed && '' === $text ) {
			/* translators: 1: field label, 2: "whole numbers" or "numbers", 3: allowed range */
			return sprintf( __( '%1$s must not be empty – allowed are %2$s %3$s. The previous value is kept.', 'voucher-captain' ), $label, $whole, $range );
		}
		if ( null === $fixed ) {
			/* translators: 1: field label, 2: value entered, 3: "whole numbers" or "numbers", 4: allowed range */
			return sprintf( __( '%1$s: "%2$s" was not saved – allowed are %3$s %4$s. The previous value is kept.', 'voucher-captain' ), $label, $text, $whole, $range );
		}
		/* translators: 1: field label, 2: value entered, 3: value saved, 4: "whole numbers" or "numbers", 5: allowed range */
		return sprintf( __( '%1$s: %2$s is not allowed – allowed are %4$s %5$s. %3$s was saved instead.', 'voucher-captain' ), $label, $text, (string) $fixed, $whole, $range );
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
