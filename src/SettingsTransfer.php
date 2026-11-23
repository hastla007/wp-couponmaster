<?php
/**
 * Export, import and reset of the settings (SET-VC-14, SET-VC-15), shared by the admin page and WP-CLI (SET-VC-17).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One service for the settings register (config/settings.php):
 *
 * - export(): every global setting marked "export" (never a secret, never product defaults or database state) as a
 *   JSON document with format name and version;
 * - parse(): checks a JSON document as a whole – one invalid value refuses the whole file; keys this site does not
 *   know (e.g. of an add-on that is not installed) are skipped and reported;
 * - validate(): checks one value against the register like the settings page does (type, bounds, choices, code
 *   affixes, reminder days, command amounts) – also used by "wp vcap settings set";
 * - diff() / apply(): preview and the change itself (written to the change log with its source, SettingsLog);
 * - reset(): back to the defaults per section or for all sections, after an automatic backup; secrets, settings
 *   that are not exported and database state stay untouched.
 *
 * @since 1.3.0
 */
final class SettingsTransfer {
	/** Format name of an export file. */
	public const FORMAT = 'voucher-captain-settings';

	/** Version of the export format this plugin writes and reads (older versions are read, newer ones refused). */
	public const VERSION = 1;

	/** Internal option with the automatic backups taken before a reset or import (newest first). */
	public const BACKUP_OPTION = 'vcap_settings_backups';

	/** Backups kept (technical limit). */
	public const BACKUPS_KEPT = 5;

	/** Largest file accepted for an import, in bytes (technical limit). */
	public const MAX_BYTES = 1048576;

	/**
	 * Exportable settings, in register order (optionally of one section).
	 *
	 * @param string|null $section Section id ('' = General), null = all sections.
	 * @return string[]
	 */
	public static function exportable( ?string $section = null ): array {
		$out = array();
		foreach ( SettingsRegistry::all() as $key => $entry ) {
			if ( $entry['export'] && ! $entry['secret'] && ( null === $section || $entry['section'] === $section ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Stored value of a setting (the default while it was never saved), without the filter "vcap_setting_{key}": an
	 * export carries what the shop saved, not what a filter of this site makes of it.
	 *
	 * @return mixed
	 */
	public static function stored( string $key ) {
		return get_option( $key, SettingsRegistry::default( $key ) );
	}

	/**
	 * The export document.
	 *
	 * @return array{format: string, version: int, plugin_version: string, exported_at: string, settings: array<string, mixed>}
	 */
	public static function export(): array {
		$settings = array();
		foreach ( self::exportable() as $key ) {
			$settings[ $key ] = self::stored( $key );
		}
		return array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'plugin_version' => defined( 'VCAP_VERSION' ) ? (string) VCAP_VERSION : '',
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'settings'       => $settings,
		);
	}

	/** The export document as pretty-printed JSON. */
	public static function export_json(): string {
		return (string) wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** File name of a download (site host and date). */
	public static function file_name(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = sanitize_file_name( '' !== $host ? $host : 'site' );
		return 'voucher-captain-settings-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json';
	}

	/**
	 * Checks an export document. Refused as a whole (WP_Error with every problem) when it is no JSON object of this
	 * format, its version is missing or newer, it holds a secret or a setting that is never exported, or any value
	 * is invalid. Keys this site does not know are skipped and listed in "unknown".
	 *
	 * @param string $json File content.
	 * @return array{settings: array<string, mixed>, unknown: string[], version: int, plugin_version: string}|\WP_Error
	 */
	public static function parse( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'vcap_settings_file_size', __( 'The file is too large for a settings export.', 'voucher-captain' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			return new \WP_Error( 'vcap_settings_file_format', __( 'This is no settings export of Voucher Captain (JSON with "format": "voucher-captain-settings" expected).', 'voucher-captain' ) );
		}
		$version = $data['version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			return new \WP_Error( 'vcap_settings_file_version', __( 'The settings export has no valid version field.', 'voucher-captain' ) );
		}
		if ( $version > self::VERSION ) {
			return new \WP_Error(
				'vcap_settings_file_version',
				/* translators: 1: version of the file, 2: version this plugin reads */
				sprintf( __( 'The settings export has format version %1$d; this version of Voucher Captain reads up to version %2$d. Update the plugin first.', 'voucher-captain' ), $version, self::VERSION )
			);
		}
		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || ( array() !== $data['settings'] && array_is_list( $data['settings'] ) ) ) {
			return new \WP_Error( 'vcap_settings_file_settings', __( 'The settings export has no "settings" object.', 'voucher-captain' ) );
		}
		$errors   = array();
		$settings = array();
		$unknown  = array();
		foreach ( $data['settings'] as $key => $value ) {
			$key   = (string) $key;
			$entry = SettingsRegistry::get( $key );
			if ( null === $entry ) {
				$unknown[] = sanitize_key( $key );
				continue;
			}
			if ( $entry['secret'] || ! $entry['export'] ) {
				/* translators: %s: option name */
				$errors[] = sprintf( __( '%s is never part of a settings export.', 'voucher-captain' ), $key );
				continue;
			}
			$valid = self::validate( $key, $value );
			if ( is_wp_error( $valid ) ) {
				$errors[] = $valid->get_error_message();
				continue;
			}
			$settings[ $key ] = $valid;
		}
		if ( $errors ) {
			return new \WP_Error( 'vcap_settings_file_values', implode( ' ', array_slice( $errors, 0, 10 ) ), $errors );
		}
		return array(
			'settings'       => $settings,
			'unknown'        => $unknown,
			'version'        => $version,
			'plugin_version' => is_scalar( $data['plugin_version'] ?? null ) ? sanitize_text_field( (string) $data['plugin_version'] ) : '',
		);
	}

	/**
	 * Checks one value like the settings page and returns it in the stored format, or a WP_Error that names the
	 * setting and what is expected. Secrets are refused.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value (from a file, a form or the command line).
	 * @return mixed|\WP_Error
	 */
	public static function validate( string $key, $value ) {
		$entry = SettingsRegistry::get( $key );
		if ( null === $entry ) {
			/* translators: %s: option name */
			return new \WP_Error( 'vcap_setting_unknown', sprintf( __( '%s is no setting of Voucher Captain.', 'voucher-captain' ), $key ) );
		}
		if ( $entry['secret'] ) {
			/* translators: %s: option name */
			return new \WP_Error( 'vcap_setting_secret', sprintf( __( '%s is a secret and cannot be read or changed here.', 'voucher-captain' ), $key ) );
		}
		$result = self::check_type( $key, $entry, $value );
		/**
		 * Checks a value before it is imported or set with WP-CLI (args: value in the stored format or WP_Error,
		 * option name, register entry). Add-ons check their own settings here; return a WP_Error to refuse.
		 *
		 * @since 1.3.0
		 * @param mixed                $result Value in the stored format, or WP_Error.
		 * @param string               $key    Option name.
		 * @param array<string, mixed> $entry  Register entry.
		 */
		return apply_filters( 'vcap_settings_validate_value', $result, $key, $entry );
	}

	/**
	 * Type check of validate().
	 *
	 * @param string               $key   Option name.
	 * @param array<string, mixed> $entry Register entry.
	 * @param mixed                $value Value.
	 * @return mixed|\WP_Error
	 */
	private static function check_type( string $key, array $entry, $value ) {
		/* translators: 1: option name, 2: what is expected */
		$invalid = static fn( string $expected ) => new \WP_Error( 'vcap_setting_invalid', sprintf( __( '%1$s: invalid value, expected %2$s.', 'voucher-captain' ), $key, $expected ) );
		switch ( $entry['type'] ) {
			case 'bool':
				$text = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '' );
				if ( in_array( $text, array( 'yes', 'on', '1', 'true' ), true ) ) {
					return 'yes';
				}
				if ( in_array( $text, array( 'no', 'off', '0', 'false' ), true ) ) {
					return 'no';
				}
				return $invalid( '"yes" or "no"' );
			case 'int':
			case 'decimal':
				return self::check_number( $key, $entry, $value, $invalid );
			case 'select':
				$text = is_scalar( $value ) ? trim( (string) $value ) : null;
				if ( null !== $text && ( in_array( $text, (array) $entry['choices'], true ) || ( '' === $text && '' === $entry['default'] ) ) ) {
					return $text;
				}
				return $invalid( implode( ', ', (array) $entry['choices'] ) );
			case 'list':
				if ( is_string( $value ) ) {
					$value = preg_split( '/\s*,\s*/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
				}
				if ( ! is_array( $value ) || array() !== array_filter( $value, static fn( $item ) => ! is_scalar( $item ) ) ) {
					return $invalid( __( 'a list of values', 'voucher-captain' ) );
				}
				return array_values( array_filter( array_map( static fn( $item ) => sanitize_text_field( (string) $item ), $value ), static fn( $item ) => '' !== $item ) );
			case 'post':
				if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', trim( (string) $value ) ) ) {
					return $invalid( __( 'a page or post ID', 'voucher-captain' ) );
				}
				return (int) $value;
			case 'text':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : $invalid( __( 'text', 'voucher-captain' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return $invalid( __( 'text', 'voucher-captain' ) );
		}
		$text = sanitize_text_field( (string) $value );
		if ( in_array( $key, array( 'vcap_code_prefix', 'vcap_code_suffix', 'vcap_code_separator' ), true ) && '' !== $text && ! CodeGenerator::valid_affix( $text, 'vcap_code_separator' === $key ) ) {
			return $invalid( __( 'letters, digits, dot, hyphen and underscore (up to 20 characters, separator up to 3)', 'voucher-captain' ) );
		}
		if ( 'vcap_expiry_reminder_days' === $key ) {
			// Stored normalised like the settings page (M12-84): largest first, anything else as 0.
			$days = Delivery::expiry_reminder_offsets( $text );
			if ( ! $days && '0' !== $text && '' !== $text ) {
				return $invalid( __( 'days between 1 and 365 separated by commas, or 0', 'voucher-captain' ) );
			}
			return $days ? implode( ', ', $days ) : '0';
		}
		return $text;
	}

	/**
	 * Number check: a number inside min/max (whole number for "int"); empty only where the register allows it.
	 * Unlike the settings page, a value outside the bounds is refused instead of set to the bound.
	 *
	 * @param string               $key     Option name.
	 * @param array<string, mixed> $entry   Register entry.
	 * @param mixed                $value   Value.
	 * @param callable             $invalid Error factory.
	 * @return mixed|\WP_Error
	 */
	private static function check_number( string $key, array $entry, $value, callable $invalid ) {
		$text = is_int( $value ) || is_float( $value ) ? (string) $value : ( is_string( $value ) ? trim( $value ) : null );
		if ( null === $text || ( '' === $text && ! $entry['empty'] ) || ( '' !== $text && ! is_numeric( $text ) ) ) {
			return $invalid( 'int' === $entry['type'] ? __( 'a whole number', 'voucher-captain' ) : __( 'a number', 'voucher-captain' ) );
		}
		if ( '' === $text ) {
			return '';
		}
		if ( 'int' === $entry['type'] && (float) $text !== round( (float) $text ) ) {
			return $invalid( __( 'a whole number', 'voucher-captain' ) );
		}
		$fixed = SettingsRegistry::sanitize( $text, $key );
		if ( null === $fixed || (float) $fixed !== (float) $text ) {
			return $invalid(
				sprintf(
					/* translators: 1: smallest value, 2: largest value */
					__( 'a value from %1$s to %2$s', 'voucher-captain' ),
					null !== $entry['min'] ? (string) $entry['min'] : '-∞',
					null !== $entry['max'] ? (string) $entry['max'] : '∞'
				)
			);
		}
		if ( in_array( $key, API\V1\CommandLimits::setting_options()['amounts'], true ) ) {
			$amount = API\V1\CommandLimits::sanitize_amount( $key, $text );
			if ( (float) $amount !== (float) $text ) {
				/* translators: %s: highest amount */
				return $invalid( sprintf( __( 'an amount up to %s', 'voucher-captain' ), $amount ) );
			}
		}
		return $text;
	}

	/** Whether two stored values are the same setting (WooCommerce stores numbers as text: 365 equals "365"). */
	public static function same( $a, $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return is_array( $a ) && is_array( $b ) && array_values( array_map( 'strval', $a ) ) === array_values( array_map( 'strval', $b ) );
		}
		return ( is_scalar( $a ) || null === $a ) && ( is_scalar( $b ) || null === $b ) && (string) $a === (string) $b;
	}

	/**
	 * Changes an import would make: option name => array( from, to ), unchanged values left out.
	 *
	 * @param array<string, mixed> $settings Checked values (parse()).
	 * @return array<string, array{from: mixed, to: mixed}>
	 */
	public static function diff( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			$current = self::stored( (string) $key );
			if ( ! self::same( $current, $value ) ) {
				$out[ (string) $key ] = array(
					'from' => $current,
					'to'   => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Stores checked values (only those that differ), recorded in the change log with $via.
	 *
	 * @param array<string, mixed> $settings Checked values (parse() or validate()).
	 * @param string               $via      SettingsLog::VIA_* of the change log.
	 * @return string[] Option names that changed.
	 */
	public static function apply( array $settings, string $via ): array {
		$changes = self::diff( $settings );
		SettingsLog::with(
			$via,
			static function () use ( $changes ): void {
				foreach ( $changes as $key => $change ) {
					if ( Logger::VERBOSE_OPTION === $key ) {
						$change['to'] = Logger::sanitize_verbose( $change['to'] );
					}
					update_option( $key, $change['to'] );
				}
			}
		);
		return array_keys( $changes );
	}

	/**
	 * Settings a reset changes: the exportable settings of a section (or of all sections) – a secret, a setting that
	 * is never exported (e.g. "Remove all data") and database state are never reset.
	 *
	 * @param string|null $section Section id ('' = General), null = all.
	 * @return string[]
	 */
	public static function resettable( ?string $section = null ): array {
		return array_values( array_filter( self::exportable( $section ), static fn( string $key ) => SettingsRegistry::HIDDEN !== SettingsRegistry::get( $key )['section'] ) );
	}

	/**
	 * Resets settings to what a new installation has, after an automatic backup of all settings. Settings a new
	 * installation stores (attribute "fresh", Settings::REQUEST_SETTINGS) are stored with that value; all others are
	 * deleted, so Settings::get() returns the register default and code that tells "never saved" apart (e.g. the
	 * default validity rule) behaves like in a new shop.
	 *
	 * @param string|null $section Section id ('' = General), null = all.
	 * @return array{changed: string[], backup: int} Option names that changed, time of the backup.
	 */
	public static function reset( ?string $section = null ): array {
		$backup  = self::backup( SettingsLog::VIA_RESET );
		$changed = array();
		SettingsLog::with(
			SettingsLog::VIA_RESET,
			static function () use ( $section, &$changed ): void {
				foreach ( self::resettable( $section ) as $key ) {
					$entry = (array) SettingsRegistry::get( $key );
					$store = array_key_exists( 'fresh', $entry ) || in_array( $key, Settings::REQUEST_SETTINGS, true );
					$value = SettingsRegistry::fresh_default( $key );
					$saved = get_option( $key, null );
					if ( $store ) {
						if ( null === $saved || ! self::same( $saved, $value ) ) {
							update_option( $key, $value );
							if ( ! self::same( null === $saved ? SettingsRegistry::default( $key ) : $saved, $value ) ) {
								$changed[] = $key;
							}
						}
					} elseif ( null !== $saved ) {
						delete_option( $key );
						if ( ! self::same( $saved, SettingsRegistry::default( $key ) ) ) {
							$changed[] = $key;
						}
					}
				}
			}
		);
		return array(
			'changed' => $changed,
			'backup'  => $backup,
		);
	}

	/**
	 * Stores an export of all settings as automatic backup (newest first, BACKUPS_KEPT kept, never autoloaded).
	 *
	 * @param string $reason SettingsLog::VIA_RESET or SettingsLog::VIA_IMPORT.
	 * @return int Time of the backup (key for backup_json()).
	 */
	public static function backup( string $reason ): int {
		$backups = self::backups();
		$time    = time();
		while ( isset( $backups[ $time ] ) ) {
			++$time;
		}
		$backups = array(
			$time => array(
				'reason' => sanitize_key( $reason ),
				'user'   => get_current_user_id(),
				'json'   => self::export_json(),
			),
		) + $backups;
		update_option( self::BACKUP_OPTION, array_slice( $backups, 0, self::BACKUPS_KEPT, true ), false );
		return $time;
	}

	/**
	 * Automatic backups: time => array( reason, user, json ), newest first.
	 *
	 * @return array<int, array{reason: string, user: int, json: string}>
	 */
	public static function backups(): array {
		$out = array();
		foreach ( (array) get_option( self::BACKUP_OPTION, array() ) as $time => $backup ) {
			if ( is_array( $backup ) && is_string( $backup['json'] ?? null ) ) {
				$out[ (int) $time ] = array(
					'reason' => (string) ( $backup['reason'] ?? '' ),
					'user'   => (int) ( $backup['user'] ?? 0 ),
					'json'   => $backup['json'],
				);
			}
		}
		krsort( $out );
		return $out;
	}

	/** JSON of a backup ('' when there is none; 0 = the newest). */
	public static function backup_json( int $time = 0 ): string {
		$backups = self::backups();
		if ( 0 === $time ) {
			$first = reset( $backups );
			return false === $first ? '' : $first['json'];
		}
		return $backups[ $time ]['json'] ?? '';
	}

	/**
	 * A value as short text for a preview, the change log or WP-CLI (lists joined with commas, long text cut).
	 *
	 * @param mixed $value Value.
	 */
	public static function display( $value, int $max = 120 ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( static fn( $item ) => is_scalar( $item ) ? (string) $item : '…', $value ) );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? 'yes' : 'no';
		} elseif ( ! is_scalar( $value ) ) {
			$value = '';
		}
		$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) ?? '' );
		return mb_strlen( $value ) > $max ? mb_substr( $value, 0, $max - 1 ) . '…' : $value;
	}
}
