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
	/** Kinds whose outcome is also stored on the order, order item or in the ledger (see purge()). */
	private const PURGEABLE = array( 'usage', 'bonus', 'referral', 'referral-revoke', 'topup', 'expiry-reminder' );

	/** True for exactly one caller per name until released. */
	public static function take( string $name ): bool {
		global $wpdb;
		if ( ! Install::storage_ready() ) {
			return self::take_option( $name );
		}
		$table = Install::tables()['claims'];
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (claim_key, kind, created_at) VALUES (%s, %s, %s)", self::key( $name ), self::kind( $name ), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}
		// A claim taken just before the storage switched may still be an option.
		return ! ( Install::legacy_rows() && self::option_exists( $name ) );
	}

	/** Allow the action again (e.g. a use is counted again after a cancelled order is paid later). */
	public static function release( string $name ): void {
		global $wpdb;
		if ( Install::storage_ready() ) {
			$table = Install::tables()['claims'];
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE claim_key = %s", self::key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! Install::legacy_rows() ) {
				return;
			}
		}
		delete_option( self::PREFIX . self::key( $name ) );
	}

	/**
	 * Removes claims of the purgeable kinds older than the retention period (filter "vcap_claim_retention_days",
	 * default 180 days; 0 keeps everything).
	 *
	 * @return int Claims removed.
	 */
	public static function purge(): int {
		global $wpdb;
		$days = (int) apply_filters( 'vcap_claim_retention_days', 180 );
		if ( $days <= 0 || ! Install::storage_ready() ) {
			return 0;
		}
		$kinds = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'vcap_purgeable_claim_kinds', self::PURGEABLE ) ) ) );
		if ( ! $kinds ) {
			return 0;
		}
		$table = Install::tables()['claims'];
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind IN ({$in}) AND created_at < %s LIMIT 5000", array_merge( $kinds, array( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ) ) );
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
