<?php
/**
 * Command storage in site options under a named database lock.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps replies, daily counters and the protocol of the voucher commands in options of the site (not autoloaded),
 * so the counters are per site and per calendar day. Every command runs under one named lock per site
 * (GET_LOCK, Install::lock_name()): two parallel calls are checked, counted and executed one after the other and can
 * never pass a limit together (R-8.3b). The volume is small by design (at most 50 executed calls per command and day).
 *
 * Options: "vcap_cmd_reply_<sha1 of the key>" (reply and fingerprint, kept 90 days), "vcap_cmd_count_<Y-m-d>"
 * (counters), "vcap_cmd_log_<Y-m-d>" (protocol, at most 500 entries a day, then only counted), "vcap_cmd_days"
 * (index for the clean-up: day => reply options of that day).
 *
 * @since 1.2.0 (contract 1.3.0)
 */
final class OptionCommandStore implements CommandStore {
	/** Days replies (idempotency) are kept. */
	public const REPLY_DAYS = 90;
	/** Days counters and protocol are kept. */
	public const LOG_DAYS = 90;
	/** Protocol entries kept per day; more are counted in "dropped". */
	public const MAX_ENTRIES = 500;
	/** Seconds a call waits for the lock of a parallel one. */
	private const LOCK_WAIT = 10;

	private const INDEX = 'vcap_cmd_days';

	/**
	 * Name of the held lock.
	 *
	 * @var string|null
	 */
	private ?string $held = null;

	/**
	 * Takes the lock under which a command is checked, counted and executed (R-8.3b). False when it is held too long.
	 *
	 * @return bool
	 */
	public function lock(): bool {
		global $wpdb;
		$name = Install::lock_name( 'commands' );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		$this->held = $name;
		return true;
	}

	/** Releases the lock of lock(). */
	public function unlock(): void {
		global $wpdb;
		if ( null !== $this->held ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->held = null;
		}
	}

	/**
	 * The stored reply for an idempotency key: {hash: request fingerprint, result: reply}, null when unknown.
	 *
	 * @param string $key Idempotency key.
	 * @return array{hash:string, result:array<string, mixed>}|null
	 */
	public function reply( string $key ): ?array {
		$value = $this->read( self::reply_option( $key ) );
		return is_array( $value ) && isset( $value['hash'], $value['result'] ) && is_array( $value['result'] )
			? array(
				'hash'   => (string) $value['hash'],
				'result' => $value['result'],
			)
			: null;
	}

	/**
	 * Stores the reply of a key (kept for at least 90 days).
	 *
	 * @param string               $key    Idempotency key.
	 * @param string               $hash   Request fingerprint.
	 * @param array<string, mixed> $result Reply.
	 * @param string               $day    Calendar day of the site (Y-m-d).
	 */
	public function save_reply( string $key, string $hash, array $result, string $day ): void {
		$option = self::reply_option( $key );
		$this->write(
			$option,
			array(
				'hash'   => $hash,
				'result' => $result,
			)
		);
		$index           = $this->read( self::INDEX );
		$index           = is_array( $index ) ? $index : array();
		$index[ $day ][] = $option;
		$this->write( self::INDEX, $this->purge( $index, $day ) );
	}

	/**
	 * Count of a bucket ("voucher.issue", "voucher.issue:recipient:17" …) on a calendar day of the site.
	 *
	 * @param string $day    Y-m-d.
	 * @param string $bucket Bucket.
	 * @return int
	 */
	public function count( string $day, string $bucket ): int {
		$counts = $this->read( 'vcap_cmd_count_' . $day );
		return is_array( $counts ) ? (int) ( $counts[ $bucket ] ?? 0 ) : 0;
	}

	/**
	 * Adds one to buckets of a day.
	 *
	 * @param string   $day     Y-m-d.
	 * @param string[] $buckets Buckets.
	 */
	public function increment( string $day, array $buckets ): void {
		$option = 'vcap_cmd_count_' . $day;
		$counts = $this->read( $option );
		$counts = is_array( $counts ) ? $counts : array();
		foreach ( $buckets as $bucket ) {
			$counts[ $bucket ] = (int) ( $counts[ $bucket ] ?? 0 ) + 1;
		}
		$this->write( $option, $counts );
		$this->touch( $day );
	}

	/**
	 * Appends an entry to the protocol of the day (no codes, no personal data).
	 *
	 * @param string               $day   Y-m-d.
	 * @param array<string, mixed> $entry Entry.
	 */
	public function protocol( string $day, array $entry ): void {
		$option = 'vcap_cmd_log_' . $day;
		$log    = $this->read( $option );
		$log    = is_array( $log ) ? $log + array(
			'entries' => array(),
			'dropped' => 0,
		) : array(
			'entries' => array(),
			'dropped' => 0,
		);
		if ( count( $log['entries'] ) < self::MAX_ENTRIES ) {
			$log['entries'][] = $entry;
		} else {
			++$log['dropped'];
		}
		$this->write( $option, $log );
		$this->touch( $day );
	}

	/**
	 * Protocol entries of a day, oldest first.
	 *
	 * @param string $day Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function entries( string $day ): array {
		$log = $this->read( 'vcap_cmd_log_' . $day );
		return is_array( $log ) && is_array( $log['entries'] ?? null ) ? array_values( $log['entries'] ) : array();
	}

	/**
	 * Option name of the reply of a key (the key itself is not stored in the name).
	 *
	 * @param string $key Idempotency key.
	 * @return string
	 */
	private static function reply_option( string $key ): string {
		return 'vcap_cmd_reply_' . sha1( $key );
	}

	/**
	 * Records that a day has counters or a protocol (for the clean-up).
	 *
	 * @param string $day Y-m-d.
	 */
	private function touch( string $day ): void {
		$index = $this->read( self::INDEX );
		$index = is_array( $index ) ? $index : array();
		if ( ! isset( $index[ $day ] ) ) {
			$index[ $day ] = array();
			$this->write( self::INDEX, $this->purge( $index, $day ) );
		}
	}

	/**
	 * Deletes replies, counters and protocols of days older than the retention and drops them from the index.
	 *
	 * @param array<string, string[]> $index Day => reply options.
	 * @param string                  $today Y-m-d.
	 * @return array<string, string[]>
	 */
	private function purge( array $index, string $today ): array {
		$base = strtotime( $today . ' 00:00:00 UTC' );
		if ( false === $base ) {
			return $index;
		}
		foreach ( $index as $day => $options ) {
			$time = strtotime( $day . ' 00:00:00 UTC' );
			if ( false === $time ) {
				unset( $index[ $day ] );
				continue;
			}
			$age = (int) floor( ( $base - $time ) / DAY_IN_SECONDS );
			if ( $age > self::REPLY_DAYS ) {
				foreach ( (array) $options as $option ) {
					delete_option( (string) $option );
				}
			}
			if ( $age > self::LOG_DAYS ) {
				delete_option( 'vcap_cmd_count_' . $day );
				delete_option( 'vcap_cmd_log_' . $day );
			}
			if ( $age > max( self::REPLY_DAYS, self::LOG_DAYS ) ) {
				unset( $index[ $day ] );
			}
		}
		return $index;
	}

	/**
	 * An option as stored now (the object cache entry is dropped first: another request may have written it).
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	private function read( string $option ): mixed {
		wp_cache_delete( $option, 'options' );
		return get_option( $option, null );
	}

	/**
	 * Writes an option that is not autoloaded.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	private function write( string $option, mixed $value ): void {
		if ( ! add_option( $option, $value, '', false ) ) {
			update_option( $option, $value, false );
		}
	}
}
