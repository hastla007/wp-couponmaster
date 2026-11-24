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
 * maintenance (step "settings_log"); entries of a user are part of that user's personal data export. OD-2 (owner
 * decision 24.11.2026): the personal data eraser anonymises the user in the entries and in the automatic settings
 * backups (user id 0, shown as "unknown"); what was changed is kept.
 *
 * Stored in one option that is never autoloaded, limited to MAX_ENTRIES (newest kept). PV-P1-5/P6-3: changes made
 * inside with() (import, reset, profile, WP-CLI) or while the settings tab is saved are collected and written once
 * at the end (shutdown as fallback) instead of rewriting the whole log for every option. Role rights changed on the
 * settings page are recorded too, as "role:<role>:<right>" (PV-P4-2).
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

	/** Prefix of the pseudo key of a role right (PV-P4-2). */
	public const ROLE_PREFIX = 'role:';

	/** @var string[] Active "via" contexts, innermost last. */
	private static array $via = array();

	/** @var int Open batches (with(), the save of the settings tab); entries are buffered while > 0. */
	private static int $depth = 0;

	/** @var bool Whether the save of the settings tab opened a batch. */
	private static bool $page_batch = false;

	/** @var array<int, array{time: int, user: int, key: string, from: string, to: string, source: string, via: string}> Entries not written yet. */
	private static array $buffer = array();

	/** Hooks: option changes, personal data export and erasure. */
	public function register(): void {
		add_action( 'added_option', array( self::class, 'added' ), 10, 2 );
		add_action( 'updated_option', array( self::class, 'updated' ), 10, 3 );
		add_action( 'delete_option', array( self::class, 'deleting' ), 10, 1 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'woocommerce_settings_save_vcap', array( self::class, 'begin_page' ), 0 );
		add_action( 'woocommerce_update_options', array( self::class, 'end_page' ), PHP_INT_MAX );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/** WooCommerce "woocommerce_settings_save_vcap" (first): changes of this save are written once. */
	public static function begin_page(): void {
		if ( ! self::$page_batch ) {
			self::$page_batch = true;
			++self::$depth;
		}
	}

	/** WooCommerce "woocommerce_update_options" (last): writes the changes of the save. */
	public static function end_page(): void {
		if ( self::$page_batch ) {
			self::$page_batch = false;
			self::end();
		}
	}

	/** Closes a batch; the last one writes the buffered entries. */
	private static function end(): void {
		self::$depth = max( 0, self::$depth - 1 );
		if ( 0 === self::$depth ) {
			self::flush();
		}
	}

	/** Writes the buffered entries with one update of the option (also on shutdown). */
	public static function flush(): void {
		if ( ! self::$buffer ) {
			return;
		}
		$log          = array_merge( self::stored_entries(), self::$buffer );
		self::$buffer = array();
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Runs $work with a "via" context (import, reset, …) recorded with each change.
	 *
	 * @return mixed What $work returns.
	 */
	public static function with( string $via, callable $work ) {
		self::$via[] = sanitize_key( $via );
		++self::$depth;
		try {
			return $work();
		} finally {
			array_pop( self::$via );
			self::end();
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
		self::add( $key, $secret ? self::MASK : SettingsTransfer::display( $old, 500 ), $secret ? self::MASK : SettingsTransfer::display( $new, 500 ) );
	}

	/**
	 * PV-P4-2: records a right granted to or removed from a role on the settings page ("role:<role>:<right>").
	 *
	 * @param string $role    Role key.
	 * @param string $cap     Right.
	 * @param bool   $granted Whether the right was granted (else removed).
	 */
	public static function record_role( string $role, string $cap, bool $granted ): void {
		self::add( self::ROLE_PREFIX . sanitize_key( $role ) . ':' . sanitize_key( $cap ), $granted ? 'no' : 'yes', $granted ? 'yes' : 'no' );
	}

	/** Adds an entry: buffered inside a batch, else written at once. */
	private static function add( string $key, string $from, string $to ): void {
		self::$buffer[] = array(
			'time'   => time(),
			'user'   => get_current_user_id(),
			'key'    => $key,
			'from'   => $from,
			'to'     => $to,
			'source' => Source::current(),
			'via'    => self::$via ? (string) end( self::$via ) : '',
		);
		if ( 0 === self::$depth ) {
			self::flush();
		}
	}

	/**
	 * All entries, oldest first (also those not written yet).
	 *
	 * @return array<int, array{time: int, user: int, key: string, from: string, to: string, source: string, via: string}>
	 */
	public static function entries(): array {
		return self::$buffer ? array_merge( self::stored_entries(), self::$buffer ) : self::stored_entries();
	}

	/**
	 * The written entries, oldest first.
	 *
	 * @return array<int, array{time: int, user: int, key: string, from: string, to: string, source: string, via: string}>
	 */
	private static function stored_entries(): array {
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
		self::flush();
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$log    = self::stored_entries();
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
	 * OD-2: personal data eraser of the change log and the automatic settings backups, next to the exporter.
	 *
	 * @param mixed $erasers What the previous filter returned.
	 * @return array<string, mixed>
	 */
	public static function erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['voucher-captain-settings-log'] = array(
			'eraser_friendly_name' => __( 'Voucher settings changes', 'voucher-captain' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * OD-2: anonymises a user (found by email address) in the change log and the automatic settings backups – the user
	 * id becomes 0 (shown as "unknown"); time, setting, values and source stay.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (everything is done at once).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_personal_data( $email, $page = 1 ): array {
		$user    = function_exists( 'get_user_by' ) ? get_user_by( 'email', (string) $email ) : false;
		$id      = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		$removed = $id > 0 && self::anonymise_user( $id ) + SettingsTransfer::anonymise_backups( $id ) > 0;
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * OD-2: sets the user of a user's entries to 0 (written and buffered ones).
	 *
	 * @return int Entries anonymised.
	 */
	public static function anonymise_user( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		self::flush();
		$log   = self::stored_entries();
		$count = 0;
		foreach ( $log as $i => $row ) {
			if ( $row['user'] === $user_id ) {
				$log[ $i ]['user'] = 0;
				++$count;
			}
		}
		if ( $count > 0 ) {
			update_option( self::OPTION, $log, false );
		}
		return $count;
	}

	/** Name of the user of an entry or backup for the screen: display name, "#id" of a deleted account, "unknown" for 0. */
	public static function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'unknown', 'voucher-captain' );
		}
		$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		return $user instanceof \WP_User ? $user->display_name : '#' . $user_id;
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
