<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Database failures that support can trace (D2): the error of the database (lock wait timeout, deadlock, missing
 * column …) is written to the WooCommerce log with the voucher/order it concerns and a short reference id; the
 * WP_Error handed back carries a reason code and the reference, so an admin notice can name it and the log entry
 * is found by searching for it.
 *
 * D6: every transaction of the plugin runs through begin()/commit()/rollback() (or transaction()). Side-effect
 * actions fired while a transaction is open (action(), after_commit()) are buffered and dispatched only after
 * the outermost COMMIT succeeded; a ROLLBACK discards them. Nested calls use savepoints, so a helper that opens
 * its own transaction inside another one no longer commits the outer one implicitly. Outside a transaction the
 * actions fire immediately, as before. See CONNECTORS.md, "Event timing".
 */
final class Db {
	/** Reference of the last failure (for messages that are built later, e.g. after a redirect). */
	private static string $last_reference = '';

	/** Open transaction levels of this request (0 = none; 2+ = savepoints inside the outer transaction). */
	private static int $depth = 0;

	/** @var callable[] Work deferred until the outermost COMMIT (dispatched in order, discarded on ROLLBACK). */
	private static array $deferred = array();

	/** @var int[] Length of $deferred when each savepoint level was opened (index = level - 1). */
	private static array $marks = array();

	/**
	 * Opens a transaction, or a savepoint when one is already open. False when the database refused it (then
	 * nothing is open and the caller must not commit or roll back).
	 */
	public static function begin(): bool {
		global $wpdb;
		$sql = 0 === self::$depth ? 'START TRANSACTION' : 'SAVEPOINT vcap_sp_' . self::$depth;
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- constant SQL and an integer.
			return false;
		}
		self::$marks[] = count( self::$deferred );
		++self::$depth;
		return true;
	}

	/**
	 * Commits the innermost level: the outermost one with COMMIT (then the buffered actions are dispatched),
	 * an inner one by releasing its savepoint (its actions wait for the outer COMMIT). False when the database
	 * refused it: the level stays open and the caller rolls it back (rollback(), rollback_if_error()).
	 */
	public static function commit(): bool {
		global $wpdb;
		if ( self::$depth <= 0 ) {
			return false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$sql = 1 === self::$depth ? 'COMMIT' : 'RELEASE SAVEPOINT vcap_sp_' . ( self::$depth - 1 );
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- constant SQL and an integer.
			return false;
		}
		array_pop( self::$marks );
		--self::$depth;
		if ( 0 === self::$depth ) {
			self::dispatch();
		}
		return true;
	}

	/**
	 * Ends a level that wrote nothing (only read under a lock): committed, or rolled back when the COMMIT is
	 * refused, so the level is closed either way.
	 */
	public static function finish(): void {
		if ( ! self::commit() ) {
			self::undo();
		}
	}

	/** Whether a transaction opened through begin() is open in this request. */
	public static function in_transaction(): bool {
		return self::$depth > 0;
	}

	/**
	 * Fires an action now, or – while a transaction is open – after its successful COMMIT (never after a
	 * ROLLBACK). The arguments are taken as they are now: a Voucher is passed as a copy of its state at this
	 * point (the caller may change the object before the COMMIT).
	 *
	 * @param string $hook Action name.
	 * @param mixed  ...$args Arguments.
	 */
	public static function action( string $hook, ...$args ): void {
		if ( 0 === self::$depth ) {
			do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
			return;
		}
		$args = self::snapshot( $args );
		self::$deferred[] = static fn() => do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
	}

	/**
	 * Like action(), with the arguments as an array (do_action_ref_array(), e.g. for one array argument that
	 * do_action() would unwrap).
	 */
	public static function action_ref_array( string $hook, array $args ): void {
		if ( 0 === self::$depth ) {
			do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
			return;
		}
		$args = self::snapshot( $args );
		self::$deferred[] = static fn() => do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
	}

	/** Copies Voucher objects (also inside one level of arrays) so a deferred listener sees the state of the call. */
	private static function snapshot( array $args ): array {
		foreach ( $args as $i => $arg ) {
			if ( $arg instanceof Voucher ) {
				$args[ $i ] = clone $arg;
			} elseif ( is_array( $arg ) ) {
				foreach ( $arg as $k => $item ) {
					if ( $item instanceof Voucher ) {
						$arg[ $k ] = clone $item;
					}
				}
				$args[ $i ] = $arg;
			}
		}
		return $args;
	}

	/** Runs $callback now, or after the successful COMMIT of the open transaction (dropped on ROLLBACK). */
	public static function after_commit( callable $callback ): void {
		if ( 0 === self::$depth ) {
			$callback();
			return;
		}
		self::$deferred[] = $callback;
	}

	/**
	 * Runs $work in a transaction (a savepoint when one is open): committed when it returns true, rolled back
	 * otherwise. A database error is logged with $context and a reference (D2); an exception is logged, rolled
	 * back and thrown again. False when the transaction could not be started or committed.
	 *
	 * @param callable $work    Returns true to commit.
	 * @param string   $context What runs, for the log.
	 * @param array    $ids     Ids that help support.
	 */
	public static function transaction( callable $work, string $context = 'transaction', array $ids = array() ): bool {
		if ( ! self::begin() ) {
			self::fail( $context . '_begin', $ids );
			return false;
		}
		try {
			$ok = (bool) $work();
		} catch ( \Throwable $e ) {
			self::rollback( $context, $ids, $e );
			throw $e;
		}
		if ( $ok && self::commit() ) {
			return true;
		}
		self::rollback_if_error( $context, $ids );
		return false;
	}

	/** Rolls back the innermost level without logging (a business reason, the error is handled by the caller). */
	public static function rollback_silently(): void {
		self::undo();
	}

	/** Test helper: forgets open levels and buffered actions (e.g. after a test that left one open). */
	public static function reset(): void {
		self::$depth    = 0;
		self::$deferred = array();
		self::$marks    = array();
	}

	/**
	 * Rolls back the innermost level: ROLLBACK for the outermost one (all buffered actions are dropped), ROLLBACK
	 * TO SAVEPOINT for an inner one (only the actions buffered since that savepoint are dropped). Without an open
	 * level a plain ROLLBACK is sent, as before D6.
	 */
	private static function undo(): void {
		global $wpdb;
		if ( self::$depth <= 0 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$mark = (int) array_pop( self::$marks );
		--self::$depth;
		if ( 0 === self::$depth ) {
			self::$deferred = array();
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		self::$deferred = array_slice( self::$deferred, 0, $mark );
		$wpdb->query( 'ROLLBACK TO SAVEPOINT vcap_sp_' . self::$depth ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- integer.
	}

	/**
	 * Dispatches the buffered work after the outermost COMMIT. The data is committed at this point, so a listener
	 * that throws is logged and the remaining ones still run (the caller's operation succeeded). A listener may
	 * open its own transaction: its actions are buffered and dispatched with that one.
	 */
	private static function dispatch(): void {
		$queue          = self::$deferred;
		self::$deferred = array();
		foreach ( $queue as $callback ) {
			try {
				$callback();
			} catch ( \Throwable $e ) {
				Logger::error( 'An action listener failed after COMMIT: ' . Mask::addresses( get_class( $e ) . ': ' . $e->getMessage() ) );
			}
		}
	}

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
			sprintf( 'Database error [%s] in %s: %s', $reference, $context, '' !== $error ? Mask::addresses( $error ) : 'no error text (no row changed)' ),
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
		if ( $e ) {
			$ids['exception'] = Mask::addresses( get_class( $e ) . ': ' . $e->getMessage() );
		}
		$error = self::fail( $context, $ids );
		self::undo();
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
		self::undo();
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
