<?php
/**
 * Change log of the settings (SET-VC-16).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Records every change of a setting of the register (config/settings.php): who, when, which setting, from which
 * value to which, and the source (settings page, import, reset, WP-CLI, …). Secrets are recorded with masked values.
 * Kept for "vcap_settings_log_days" days (default 365, 0 = until the entry limit) and cleaned up by the daily
 * maintenance (step "settings_log"); entries of a user are part of that user's personal data export.
 *
 * Stored in one option that is never autoloaded, limited to MAX_ENTRIES (newest kept).
 *
 * @since 1.3.0
 */
final class SettingsLog {
	/** Internal option with the entries (oldest first). */
	public const OPTION = 'vcap_settings_log';

	/** Setting: days an entry is kept (0 = until MAX_ENTRIES). */
	public const DAYS_OPTION = 'vcap_settings_log_days';

	/** Entries kept at most (technical limit). */
	public const MAX_ENTRIES = 2000;

	/** Shown instead of the value of a secret. */
	public const MASK = '********';

	/** How a change was made, besides the source (Source::current()). */
	public const VIA_IMPORT  = 'import';
	public const VIA_RESET   = 'reset';
	public const VIA_PROFILE = 'profile';
	public const VIA_CLI     = 'cli-set';

	/** @var string[] Active "via" contexts, innermost last. */
	private static array $via = array();

	/** Hooks: option changes, personal data export. */
	public function register(): void {
		add_action( 'added_option', array( self::class, 'added' ), 10, 2 );
		add_action( 'updated_option', array( self::class, 'updated' ), 10, 3 );
		add_action( 'delete_option', array( self::class, 'deleting' ), 10, 1 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
	}

	/**
	 * Runs $work with a "via" context (import, reset, …) recorded with each change.
	 *
	 * @return mixed What $work returns.
	 */
	public static function with( string $via, callable $work ) {
		self::$via[] = sanitize_key( $via );
		try {
			return $work();
		} finally {
			array_pop( self::$via );
		}
	}

	/**
	 * WordPress "added_option": a setting saved for the first time changes from its default.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 */
	public static function added( $option, $value ): void {
		self::record( (string) $option, SettingsRegistry::default( (string) $option ), $value );
	}

	/**
	 * WordPress "updated_option".
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $value  New value.
	 */
	public static function updated( $option, $old, $value ): void {
		self::record( (string) $option, $old, $value );
	}

	/**
	 * WordPress "delete_option" (before the deletion): the setting returns to its default.
	 *
	 * @param string $option Option name.
	 */
	public static function deleting( $option ): void {
		$option = (string) $option;
		if ( SettingsRegistry::has( $option ) ) {
			self::record( $option, get_option( $option, SettingsRegistry::default( $option ) ), SettingsRegistry::default( $option ) );
		}
	}

	/**
	 * Records a change of a registered setting (unchanged values and other options are ignored).
	 *
	 * @param string $key Option name.
	 * @param mixed  $old Old value.
	 * @param mixed  $new New value.
	 */
	public static function record( string $key, $old, $new ): void {
		$entry = SettingsRegistry::get( $key );
		if ( null === $entry || SettingsTransfer::same( $old, $new ) ) {
			return;
		}
		$secret = $entry['secret'];
		$via    = self::$via ? (string) end( self::$via ) : '';
		$log    = self::entries();
		$log[]  = array(
			'time'   => time(),
			'user'   => get_current_user_id(),
			'key'    => $key,
			'from'   => $secret ? self::MASK : SettingsTransfer::display( $old, 500 ),
			'to'     => $secret ? self::MASK : SettingsTransfer::display( $new, 500 ),
			'source' => Source::current(),
			'via'    => $via,
		);
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * All entries, oldest first.
	 *
	 * @return array<int, array{time: int, user: int, key: string, from: string, to: string, source: string, via: string}>
	 */
	public static function entries(): array {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['key'], $row['time'] ) ) {
				$out[] = array(
					'time'   => (int) $row['time'],
					'user'   => (int) ( $row['user'] ?? 0 ),
					'key'    => (string) $row['key'],
					'from'   => (string) ( $row['from'] ?? '' ),
					'to'     => (string) ( $row['to'] ?? '' ),
					'source' => (string) ( $row['source'] ?? '' ),
					'via'    => (string) ( $row['via'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * The newest entries first.
	 *
	 * @return array<int, array{time: int, user: int, key: string, from: string, to: string, source: string, via: string}>
	 */
	public static function latest( int $limit = 50 ): array {
		return array_slice( array_reverse( self::entries() ), 0, max( 1, $limit ) );
	}

	/**
	 * Removes entries older than $days days (daily maintenance); 0 keeps them (MAX_ENTRIES still applies).
	 *
	 * @return int Entries removed.
	 */
	public static function prune( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$log    = self::entries();
		$kept   = array_values( array_filter( $log, static fn( array $row ) => $row['time'] >= $cutoff ) );
		$gone   = count( $log ) - count( $kept );
		if ( $gone > 0 ) {
			update_option( self::OPTION, $kept, false );
		}
		return $gone;
	}

	/** Label of the source of an entry ("Admin", "WP-CLI", plus import/reset/…). */
	public static function source_label( array $row ): string {
		$labels = Source::labels();
		$source = (string) ( $labels[ $row['source'] ?? '' ] ?? ( $row['source'] ?? '' ) );
		$vias   = array(
			self::VIA_IMPORT  => __( 'import', 'voucher-captain' ),
			self::VIA_RESET   => __( 'reset to defaults', 'voucher-captain' ),
			self::VIA_PROFILE => __( 'country profile', 'voucher-captain' ),
			self::VIA_CLI     => __( 'settings command', 'voucher-captain' ),
		);
		$via = (string) ( $row['via'] ?? '' );
		return '' !== $via ? $source . ' (' . ( $vias[ $via ] ?? $via ) . ')' : $source;
	}

	/**
	 * Personal data exporter of the change log.
	 *
	 * @param mixed $exporters What the previous filter returned.
	 * @return array<string, mixed>
	 */
	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['voucher-captain-settings-log'] = array(
			'exporter_friendly_name' => __( 'Voucher settings changes', 'voucher-captain' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * The changes a user made (personal data export by email address).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (all entries fit on one).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( $email, $page = 1 ): array {
		$user  = function_exists( 'get_user_by' ) ? get_user_by( 'email', (string) $email ) : false;
		$id    = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		$items = array();
		if ( $id > 0 ) {
			foreach ( self::entries() as $i => $row ) {
				if ( $row['user'] !== $id ) {
					continue;
				}
				$items[] = array(
					'group_id'    => 'vcap-settings-log',
					'group_label' => __( 'Voucher settings changes', 'voucher-captain' ),
					'item_id'     => 'vcap-settings-log-' . $row['time'] . '-' . $i,
					'data'        => array(
						array(
							'name'  => __( 'Date', 'voucher-captain' ),
							'value' => wp_date( 'Y-m-d H:i:s', $row['time'] ),
						),
						array(
							'name'  => __( 'Setting', 'voucher-captain' ),
							'value' => $row['key'],
						),
						array(
							'name'  => __( 'From', 'voucher-captain' ),
							'value' => $row['from'],
						),
						array(
							'name'  => __( 'To', 'voucher-captain' ),
							'value' => $row['to'],
						),
						array(
							'name'  => __( 'Source', 'voucher-captain' ),
							'value' => self::source_label( $row ),
						),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}
}
