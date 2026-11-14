<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Database failures that support can trace (D2): the error of the database (lock wait timeout, deadlock, missing
 * column …) is written to the WooCommerce log with the voucher/order it concerns and a short reference id; the
 * WP_Error handed back carries a reason code and the reference, so an admin notice can name it and the log entry
 * is found by searching for it.
 */
final class Db {
	/** Reference of the last failure (for messages that are built later, e.g. after a redirect). */
	private static string $last_reference = '';

	/**
	 * Logs the current database error and returns it as WP_Error ("vcap_db_error", data: reference, context,
	 * db_error, ids).
	 *
	 * @param string $context What failed, e.g. "issue_voucher" (technical, for the log).
	 * @param array  $ids     Ids that help support, e.g. array( 'voucher_id' => 12, 'order_id' => 99 ).
	 */
	public static function fail( string $context, array $ids = array() ): \WP_Error {
		global $wpdb;
		$error     = is_object( $wpdb ) && isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		$reference = self::reference();
		Logger::error(
			sprintf( 'Database error [%s] in %s: %s', $reference, $context, '' !== $error ? Health::mask_addresses( $error ) : 'no error text (no row changed)' ),
			array( 'reference' => $reference, 'context' => $context ) + $ids
		);
		return new \WP_Error(
			'vcap_db_error',
			/* translators: %s: reference id, searchable in WooCommerce → Status → Logs */
			sprintf( __( 'A database error occurred (reference %s, see WooCommerce → Status → Logs).', 'voucher-captain' ), $reference ),
			array( 'reference' => $reference, 'context' => $context, 'db_error' => $error, 'ids' => $ids )
		);
	}

	/**
	 * D2: rolls back the open transaction after logging why (the database error is read before the ROLLBACK,
	 * which clears it). With $e the exception text is logged as well.
	 */
	public static function rollback( string $context, array $ids = array(), ?\Throwable $e = null ): \WP_Error {
		global $wpdb;
		if ( $e ) {
			$ids['exception'] = Health::mask_addresses( get_class( $e ) . ': ' . $e->getMessage() );
		}
		$error = self::fail( $context, $ids );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $error;
	}

	/**
	 * D2: rolls back the open transaction; logged (with a reference) only when the database reported an error –
	 * a rollback for a business reason (balance too low, state changed) is no failure. Returns the error or null.
	 */
	public static function rollback_if_error( string $context, array $ids = array() ): ?\WP_Error {
		global $wpdb;
		if ( is_object( $wpdb ) && '' !== (string) $wpdb->last_error ) {
			return self::rollback( $context, $ids );
		}
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null;
	}

	/** Reference id of the last failure logged by fail() in this request ('' if none). */
	public static function last_reference(): string {
		return self::$last_reference;
	}

	/** Short random reference, e.g. "VC-3F9A2C". */
	private static function reference(): string {
		self::$last_reference = 'VC-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
		return self::$last_reference;
	}
}
