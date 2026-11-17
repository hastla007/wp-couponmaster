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
	 * M12-04: the database rolled back the whole transaction while a savepoint was open (deadlock 1213, or a lock
	 * wait timeout with innodb_rollback_on_timeout): every savepoint is gone. The levels the callers still hold are
	 * closed one by one as usual, but nothing can be committed any more: commit() refuses, begin() opens nothing,
	 * and the buffered actions of all levels are dropped. The outermost undo() ends this state.
	 */
	private static bool $lost = false;

	/** Whether the server is MariaDB (null = not checked yet; false also when the setting below is unknown there). */
	private static ?bool $snapshot_setting = null;

	/**
	 * MariaDB 11.6.2+ turns innodb_snapshot_isolation on by default: a locking read (SELECT … FOR UPDATE) of a row
	 * another request changed after this transaction's first read then fails with 1020 "Record has changed since
	 * last read" and the whole transaction is rolled back. The bookings rely on the classic InnoDB behaviour (the
	 * locking read sees the latest committed row, M12-04) – as on MySQL and older MariaDB – so it is switched off for
	 * this connection before each transaction and each locking read (it applies from the next statement on, also
	 * inside a transaction another plugin opened). No effect on MySQL.
	 */
	public static function classic_locking_reads(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}
		if ( null === self::$snapshot_setting ) {
			$info                   = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			self::$snapshot_setting = false !== stripos( $info, 'mariadb' );
		}
		if ( ! self::$snapshot_setting ) {
			return;
		}
		$suppress = $wpdb->suppress_errors( true );
		if ( false === $wpdb->query( 'SET SESSION innodb_snapshot_isolation = OFF' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$snapshot_setting = false; // A MariaDB version without the setting: nothing to switch off.
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Opens a transaction, or a savepoint when one is already open. False when the database refused it (then
	 * nothing is open and the caller must not commit or roll back).
	 */
	public static function begin(): bool {
		global $wpdb;
		VoucherRepository::forget(); // AP-P1-5: nothing read before the transaction is reused in it or after it.
		if ( self::$lost ) {
			return false; // M12-04: the outer transaction is gone; the caller must not commit or roll back.
		}
		self::classic_locking_reads();
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
		if ( self::$lost ) {
			return false; // M12-04: rolled back by the database already; the caller rolls its level back.
		}
		$sql = 1 === self::$depth ? 'COMMIT' : 'RELEASE SAVEPOINT vcap_sp_' . ( self::$depth - 1 );
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- constant SQL and an integer.
			return false;
		}
		array_pop( self::$marks );
		--self::$depth;
		VoucherRepository::forget();
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
	 * ROLLBACK). Either way the booking that fires it is done: a listener that throws is logged and never reaches
	 * the caller (M12-05), so a failing listener cannot make a committed booking look failed. The arguments are taken as they are now: a Voucher is passed as a copy of its state at this
	 * point (the caller may change the object before the COMMIT).
	 *
	 * @param string $hook Action name.
	 * @param mixed  ...$args Arguments.
	 */
	public static function action( string $hook, ...$args ): void {
		if ( 0 === self::$depth ) {
			self::run( static fn() => do_action( $hook, ...$args ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
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
			self::run( static fn() => do_action_ref_array( $hook, $args ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass vcap_* hooks.
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
			self::run( $callback );
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
		self::$lost     = false;
	}

	/**
	 * Rolls back the innermost level: ROLLBACK for the outermost one (all buffered actions are dropped), ROLLBACK
	 * TO SAVEPOINT for an inner one (only the actions buffered since that savepoint are dropped). Without an open
	 * level a plain ROLLBACK is sent, as before D6.
	 */
	private static function undo(): void {
		global $wpdb;
		VoucherRepository::forget(); // AP-P1-5: rolled back rows must not be served from the memo.
		if ( self::$depth <= 0 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$deadlock = self::transaction_lost();
		$mark     = (int) array_pop( self::$marks );
		--self::$depth;
		if ( 0 === self::$depth ) {
			self::$deferred = array();
			self::$lost     = false;
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		if ( ! self::$lost ) {
			self::$deferred = array_slice( self::$deferred, 0, $mark );
			if ( ! $deadlock && false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT vcap_sp_' . self::$depth ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- integer.
				return;
			}
		}
		// M12-04: the database rolled back the whole transaction (deadlock) – the savepoint is gone. The outer levels
		// stay open for their callers but can only be rolled back; their buffered actions are dropped now. A new
		// transaction keeps whatever the callers still write until then out of autocommit (it is rolled back too).
		self::$lost     = true;
		self::$deferred = array();
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether the last statement failed with a deadlock (1213), which rolls back the whole transaction in InnoDB.
	 * Read from the error text; when it is gone already (another statement ran since), the refused ROLLBACK TO
	 * SAVEPOINT tells the same.
	 */
	private static function transaction_lost(): bool {
		global $wpdb;
		return is_object( $wpdb ) && isset( $wpdb->last_error ) && str_contains( (string) $wpdb->last_error, 'Deadlock found' );
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
			self::run( $callback );
		}
	}

	/** Runs a listener of a booking that is done; an exception is logged (with the other listeners still run). */
	private static function run( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			Logger::error( 'An action listener failed after COMMIT: ' . Mask::addresses( get_class( $e ) . ': ' . $e->getMessage() ) );
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
