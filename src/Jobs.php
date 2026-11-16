<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Background runs of bulk admin work (B4): generating many campaign or code pool codes, importing large files.
 *
 * One job per user and kind (id "generate_12", "import_12"). Its record is an option (not autoloaded) with the
 * progress, the counts and at most MAX_ERRORS messages per error list (the full count is kept); work items (the
 * codes of an import) wait in chunk options of CodeIndex::CHUNK entries and are removed once done – no single
 * option or transient grows with the size of the file. Each Action Scheduler action works for at most
 * STEP_SECONDS and queues the next one; the progress screen advances the job as well (sites whose WP-Cron does
 * not run), a MySQL named lock keeps two runners off the same job. The handler of a job type does one step and
 * returns the job, with status "done" (or "failed" and an error) when it is through.
 */
final class Jobs {
	/** Action Scheduler hook (args: id). */
	public const HOOK = 'vcap_bulk_job';
	/** Messages kept per error list of a job or report. */
	public const MAX_ERRORS = 100;
	/** Seconds an Action Scheduler action works on a job before it queues the next one. */
	public const STEP_SECONDS = 10;
	private const OPTION = 'vcap_job_';

	/** @var array<string, callable(array):array> Step handlers per job type. */
	private static array $handlers = array();

	public function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** @param callable(array):array $handler Does one step of a job and returns it. */
	public static function handle( string $type, callable $handler ): void {
		self::$handlers[ $type ] = $handler;
	}

	public static function id( string $kind, int $user_id ): string {
		return sanitize_key( $kind ) . '_' . $user_id;
	}

	private static function chunk_option( string $id, int $n ): string {
		return self::OPTION . $id . '_c' . $n;
	}

	/** The job record, or null. */
	public static function get( string $id ): ?array {
		wp_cache_delete( self::OPTION . $id, 'options' ); // Another request (the background runner) updates it.
		$job = get_option( self::OPTION . $id, null );
		return is_array( $job ) ? $job : null;
	}

	public static function running( ?array $job ): bool {
		return is_array( $job ) && in_array( $job['status'] ?? '', array( 'queued', 'running' ), true );
	}

	/**
	 * Starts a job (refused while one of the same id is still running).
	 *
	 * @param array             $job    type, total and the handler's own keys (params, report, …).
	 * @param array<int, array> $chunks Work items, saved one option per chunk.
	 */
	public static function start( string $id, array $job, array $chunks = array() ): bool {
		if ( self::running( self::get( $id ) ) ) {
			return false;
		}
		self::finish( $id ); // A finished job not looked at: replaced.
		foreach ( array_values( $chunks ) as $n => $chunk ) {
			add_option( self::chunk_option( $id, $n ), $chunk, '', 'no' );
		}
		$job = array(
			'id'      => $id,
			'status'  => 'running',
			'created' => time(),
			'updated' => time(),
			'done'    => 0,
			'chunks'  => count( $chunks ),
			'next'    => 0,
			'error'   => '',
		) + $job + array( 'total' => 0 );
		update_option( self::OPTION . $id, $job, false );
		self::schedule( $id );
		return true;
	}

	/** Work items of chunk $n (null when gone). */
	public static function chunk( array $job, int $n ): ?array {
		wp_cache_delete( self::chunk_option( (string) $job['id'], $n ), 'options' );
		$chunk = get_option( self::chunk_option( (string) $job['id'], $n ), null );
		return is_array( $chunk ) ? $chunk : null;
	}

	public static function drop_chunk( array $job, int $n ): void {
		delete_option( self::chunk_option( (string) $job['id'], $n ) );
	}

	/** Removes a job and what is left of its chunks. */
	public static function finish( string $id ): void {
		$job = self::get( $id );
		if ( $job ) {
			for ( $n = (int) ( $job['next'] ?? 0 ); $n < (int) ( $job['chunks'] ?? 0 ); $n++ ) {
				delete_option( self::chunk_option( $id, $n ) );
			}
		}
		delete_option( self::OPTION . $id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( 'id' => $id ), Delivery::GROUP );
		}
	}

	/**
	 * Adds a message to a capped list of a report: at most MAX_ERRORS are kept, "<name>_count" counts all.
	 *
	 * @param array $report Report with the list (and its count).
	 */
	public static function add_error( array &$report, string $name, string $message ): void {
		$report[ $name ]            = isset( $report[ $name ] ) && is_array( $report[ $name ] ) ? $report[ $name ] : array();
		$report[ $name . '_count' ] = self::count( $report, $name ) + 1;
		if ( count( $report[ $name ] ) < self::MAX_ERRORS ) {
			$report[ $name ][] = $message;
		}
	}

	/** All entries of a capped list (its count, or the length of a list from before the cap). */
	public static function count( array $report, string $name ): int {
		return isset( $report[ $name . '_count' ] ) ? (int) $report[ $name . '_count' ] : count( (array) ( $report[ $name ] ?? array() ) );
	}

	/** Action Scheduler: works on the job for STEP_SECONDS, then queues the next action. @param string|array $id */
	public static function run( $id ): void {
		$id = is_array( $id ) ? (string) ( $id['id'] ?? '' ) : (string) $id;
		if ( '' === $id ) {
			return;
		}
		/** Seconds one background action works on a bulk job (code generation, import) before it queues the next. */
		$running = self::advance( $id, (float) apply_filters( 'vcap_job_step_seconds', self::STEP_SECONDS ) );
		if ( null === $running ) {
			self::schedule( $id, 15 ); // Another runner (the progress screen) is at it: look again shortly.
		} elseif ( $running ) {
			self::schedule( $id );
		}
	}

	/**
	 * Works on a job for up to $seconds (at least one step). Returns whether it is still running, null when another
	 * runner holds the job right now.
	 */
	public static function advance( string $id, float $seconds ): ?bool {
		global $wpdb;
		$lock = Install::lock_name( 'job_' . $id );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return self::running( self::get( $id ) ) ? null : false;
		}
		try {
			$start = microtime( true );
			$job   = self::get( $id );
			while ( self::running( $job ) ) {
				$handler = self::$handlers[ (string) ( $job['type'] ?? '' ) ] ?? null;
				if ( ! $handler ) {
					$job['status'] = 'failed';
					$job['error']  = __( 'The background run could not be continued.', 'voucher-captain' );
				} else {
					try {
						$job = $handler( $job );
					} catch ( \Throwable $e ) {
						Logger::error( 'Background job failed.', array( 'job' => $id, 'error' => $e->getMessage() ) );
						$job['status'] = 'failed';
						$job['error']  = wp_strip_all_tags( $e->getMessage() );
					}
				}
				$job['updated'] = time();
				update_option( self::OPTION . $id, $job, false );
				if ( microtime( true ) - $start >= $seconds ) {
					break;
				}
			}
			return self::running( $job );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * After a reactivation (AP-Q2-2, Delivery::restore_tasks()): running jobs whose action the deactivation removed
	 * are queued again. Chunk options ("<id>_c<n>") are skipped by name: a job id always ends in "_<user id>".
	 *
	 * @return string[] ids of the jobs queued again
	 */
	public static function reschedule_running(): array {
		global $wpdb;
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return array();
		}
		$names  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT REGEXP %s ORDER BY option_id LIMIT 500", $wpdb->esc_like( self::OPTION ) . '%', '_c[0-9]+$' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$queued = array();
		foreach ( $names as $name ) {
			$id = substr( (string) $name, strlen( self::OPTION ) );
			if ( preg_match( '/_c\d+$/', $id ) ) {
				continue;
			}
			$job = self::get( $id );
			if ( ! self::running( $job ) || (string) ( $job['id'] ?? '' ) !== $id || as_has_scheduled_action( self::HOOK, array( 'id' => $id ), Delivery::GROUP ) ) {
				continue;
			}
			self::schedule( $id );
			$queued[] = $id;
		}
		return $queued;
	}

	private static function schedule( string $id, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( self::HOOK, array( 'id' => $id ), Delivery::GROUP );
			as_schedule_single_action( time() + $delay, self::HOOK, array( 'id' => $id ), Delivery::GROUP );
		}
	}

	/** Progress in percent (0–100). */
	public static function percent( array $job ): int {
		$total = max( 1, (int) ( $job['total'] ?? 0 ) );
		return (int) min( 100, floor( 100 * (int) ( $job['done'] ?? 0 ) / $total ) );
	}
}
