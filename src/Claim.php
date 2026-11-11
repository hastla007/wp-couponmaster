<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One-time claims for side effects of order status hooks (usage counts, bonus vouchers, referral rewards).
 *
 * Two requests can process the same status change at the same time (gateway webhook and thank-you page,
 * admin and Action Scheduler). Order meta is read before either request saved, so both would act. A claim
 * is a row with a unique key: only the request whose INSERT created it may continue.
 */
final class Claim {
	private const PREFIX = '_vcap_claim_';

	/** True for exactly one caller per name until released. */
	public static function take( string $name ): bool {
		global $wpdb;
		$option = self::option( $name );
		// INSERT IGNORE (not add_option(), which updates an existing row and reports success).
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$taken = 1 === (int) $wpdb->rows_affected;
		if ( $taken ) {
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $option, 'options' );
		}
		return $taken;
	}

	/** Allow the action again (e.g. a use is counted again after a cancelled order is paid later). */
	public static function release( string $name ): void {
		delete_option( self::option( $name ) );
	}

	private static function option( string $name ): string {
		return self::PREFIX . substr( md5( $name ), 0, 24 );
	}
}
