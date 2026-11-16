<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One-time claims for side effects of order status hooks (usage counts, bonus vouchers, referral rewards).
 *
 * Two requests can process the same status change at the same time (gateway webhook and thank-you page,
 * admin and Action Scheduler). Order meta is read before either request saved, so both would act. A claim
 * is a row with a unique key: only the request whose INSERT created it may continue.
 *
 * Claims live in the table vcap_claims (schema 1.4; before, one "_vcap_claim_*" option each, moved by Install).
 * Claims of kinds whose result is also recorded durably elsewhere (order or item meta, ledger events) are
 * removed by the daily maintenance after a retention period; all other claims are kept.
 */
final class Claim {
	private const PREFIX = '_vcap_claim_';
	/** Kinds whose outcome is also stored on the order, order item or in the ledger (see purge()); "form": one-time admin form tokens (FormToken); "workflow": keys of the workflow services (audit entry in the ledger). */
	private const PURGEABLE = array( 'usage', 'bonus', 'referral', 'referral-revoke', 'topup', 'expiry-reminder', 'form', 'workflow' );

	/** True for exactly one caller per name until released. */
	public static function take( string $name ): bool {
		global $wpdb;
		if ( ! Install::storage_ready() ) {
			return self::take_option( $name );
		}
		$table = Install::tables()['claims'];
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (claim_key, kind, created_at) VALUES (%s, %s, %s)', $table, self::key( $name ), self::kind( $name ), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}
		// A claim taken just before the storage switched may still be an option.
		return ! ( Install::legacy_rows() && self::option_exists( $name ) );
	}

	/** When the claim was taken (UTC "Y-m-d H:i:s"), null when it is not held or not in the claims table. */
	public static function taken_at( string $name ): ?string {
		global $wpdb;
		if ( ! Install::storage_ready() ) {
			return null;
		}
		$table = Install::tables()['claims'];
		$at    = $wpdb->get_var( $wpdb->prepare( 'SELECT created_at FROM %i WHERE claim_key = %s', $table, self::key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $at ? null : (string) $at;
	}

	/**
	 * Takes over a claim that was taken at $taken_at (a stale one): true for exactly one caller – the UPDATE only
	 * matches while the row still carries the old time, so a second caller that read the same time loses.
	 */
	public static function take_over( string $name, string $taken_at ): bool {
		global $wpdb;
		if ( ! Install::storage_ready() ) {
			return false;
		}
		$table = Install::tables()['claims'];
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE claim_key = %s AND created_at = %s', $table, gmdate( 'Y-m-d H:i:s' ), self::key( $name ), $taken_at ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Allow the action again (e.g. a use is counted again after a cancelled order is paid later). */
	public static function release( string $name ): void {
		global $wpdb;
		if ( Install::storage_ready() ) {
			$table = Install::tables()['claims'];
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE claim_key = %s', $table, self::key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! Install::legacy_rows() ) {
				return;
			}
		}
		delete_option( self::PREFIX . self::key( $name ) );
	}

	/**
	 * Removes claims of the purgeable kinds older than the retention period (filter "vcap_claim_retention_days",
	 * default 180 days; 0 keeps everything), in batches until none is left or $budget seconds are used.
	 *
	 * @return int Claims removed.
	 */
	public static function purge( float $budget = 10.0 ): int {
		$days = (int) apply_filters( 'vcap_claim_retention_days', 180 );
		if ( $days <= 0 || ! Install::storage_ready() ) {
			return 0;
		}
		$kinds = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'vcap_purgeable_claim_kinds', self::PURGEABLE ) ) ) );
		if ( ! $kinds ) {
			return 0;
		}
		$start   = microtime( true );
		$removed = self::delete_older( $kinds, $days, $budget, $start );
		// Claims moved from the options of schema < 1.4 ("legacy") no longer tell their kind: they guarded order
		// hooks that ran before the update, so they are removed after a longer period (filter
		// "vcap_legacy_claim_retention_days", default 365 days and never shorter than the period above; 0 keeps them).
		$legacy = (int) apply_filters( 'vcap_legacy_claim_retention_days', max( 365, $days ) );
		if ( $legacy > 0 && microtime( true ) - $start < $budget ) {
			$removed += self::delete_older( array( 'legacy' ), max( $legacy, $days ), $budget, $start );
		}
		return $removed;
	}

	/**
	 * Deletes claims of $kinds older than $days, 1000 rows per statement (index kind_created), until none is left
	 * or the budget since $start is used up.
	 *
	 * @param string[] $kinds
	 */
	private static function delete_older( array $kinds, int $days, float $budget, float $start ): int {
		global $wpdb;
		$table   = Install::tables()['claims'];
		$in      = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$args    = array_merge( $kinds, array( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), 1000 ) );
		$removed = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind IN ({$in}) AND created_at < %s LIMIT %d", $args ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Claims could not be purged: ' . esc_html( $wpdb->last_error ) );
			}
			$removed += (int) $deleted;
		} while ( (int) $deleted >= 1000 && microtime( true ) - $start < $budget );
		return $removed;
	}

	/** Claim before schema 1.4: an options row. */
	private static function take_option( string $name ): bool {
		global $wpdb;
		$option = self::PREFIX . self::key( $name );
		// INSERT IGNORE (not add_option(), which updates an existing row and reports success).
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$taken = 1 === (int) $wpdb->rows_affected;
		if ( $taken ) {
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $option, 'options' );
		}
		return $taken;
	}

	private static function option_exists( string $name ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . self::key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Same hash as the option names before schema 1.4, so moved claims keep working. */
	private static function key( string $name ): string {
		return substr( md5( $name ), 0, 24 );
	}

	/** Kind of a claim: the part of the name before the first colon ("usage:12:34" → "usage"). */
	private static function kind( string $name ): string {
		$kind = strstr( $name, ':', true );
		return substr( sanitize_key( false === $kind ? $name : $kind ), 0, 40 );
	}
}
