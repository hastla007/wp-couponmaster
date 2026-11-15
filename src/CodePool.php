<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-generated (imported) codes, organised in batches. Products can draw codes from a batch.
 */
final class CodePool {
	/** Code pool table of the current site (never kept: switch_to_blog() changes it). */
	private function table(): string {
		return Install::tables()['pool'];
	}

	/**
	 * Claim a free code of a batch atomically, in random order: the file order (often numbered cards) does not
	 * tell a buyer which codes the next customers get. A random id between the lowest and highest free id is the
	 * starting point; one UPDATE takes the next free code from there (ORDER BY id LIMIT 1) and records its id
	 * with LAST_INSERT_ID(id), read back on the same connection. Parallel claims never fail on each other: InnoDB
	 * re-checks "used_at IS NULL" after waiting for a row another claim locked and moves on to the next free
	 * code, so a batch only reports empty when it is (works on every MySQL and MariaDB version).
	 */
	public function claim( string $batch, int $voucher_id = 0 ): ?string {
		global $wpdb;
		// A statement that failed (deadlock of two claims scanning towards each other) is tried again.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$bounds = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(id) AS lo, MAX(id) AS hi FROM {$this->table()} WHERE batch = %s AND voucher_id = 0", $batch ), ARRAY_A ); // phpcs:ignore
			if ( ! $bounds || null === $bounds['lo'] ) {
				return null;
			}
			$pivot  = random_int( (int) $bounds['lo'], max( (int) $bounds['lo'], (int) $bounds['hi'] ) );
			$now    = gmdate( 'Y-m-d H:i:s' );
			$failed = false;
			// From the pivot upwards, then (none left above) downwards.
			foreach ( array( 'id >= %d ORDER BY id ASC', 'id < %d ORDER BY id DESC' ) as $range ) {
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET used_at = %s, voucher_id = %d, id = LAST_INSERT_ID(id) WHERE batch = %s AND voucher_id = 0 AND used_at IS NULL AND {$range} LIMIT 1", $now, $voucher_id, $batch, $pivot ) ); // phpcs:ignore
				if ( false === $updated ) {
					$failed = true;
					break;
				}
				if ( 1 === (int) $updated ) {
					ProductChecks::batch_touched( $batch );
					$id = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return $id > 0 ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$this->table()} WHERE id = %d", $id ) ) : null; // phpcs:ignore
				}
			}
			if ( ! $failed ) {
				return null; // Both directions found no free code: the batch is empty.
			}
		}
		return null;
	}

	/**
	 * Codes already in a batch (the newest $limit), to rate the structure of codes added to it.
	 *
	 * @return string[]
	 */
	public function sample( string $batch, int $limit = 2000 ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$this->table()} WHERE batch = %s ORDER BY id DESC LIMIT %d", $batch, $limit ) ) ); // phpcs:ignore
	}

	public function attach( string $code, int $voucher_id ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), array( 'voucher_id' => $voucher_id ), array( 'code' => $code ) ); // phpcs:ignore
	}

	public function release( string $code ): bool {
		global $wpdb;
		ProductChecks::batch_touched( (string) $wpdb->get_var( $wpdb->prepare( "SELECT batch FROM {$this->table()} WHERE code = %s", $code ) ) ); // phpcs:ignore
		return false !== $wpdb->update( $this->table(), array( 'voucher_id' => 0, 'used_at' => null ), array( 'code' => $code ) ); // phpcs:ignore
	}

	public function exists( string $code ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE code = %s", $code ) ); // phpcs:ignore
	}

	public function add( string $code, string $batch ): bool {
		global $wpdb;
		ProductChecks::flush();
		ProductChecks::batch_touched( $batch );
		return (bool) $wpdb->insert( // phpcs:ignore
			$this->table(),
			array(
				'code'        => $code,
				'batch'       => $batch,
				'imported_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** Job type of imports run in the background (Jobs). */
	public const IMPORT_JOB = 'import';

	/**
	 * Adds checked codes of an import to a batch (B4): whether a voucher, a pool entry or a coupon uses them is
	 * asked in batches (CodeIndex::claim_free()), not three lookups per code. Updates the report: "imported",
	 * "dupes_existing" and the capped list "not_saved" (Jobs::add_error()).
	 *
	 * @param array<int, string> $codes  Row number => code.
	 * @param array              $report Import report (Admin\Import::empty_report()).
	 */
	public function import_codes( array $codes, string $batch, array &$report ): void {
		global $wpdb;
		$free                      = CodeIndex::claim_free( $codes );
		$report['dupes_existing'] += count( $codes ) - count( $free );
		foreach ( $free as $row => $code ) {
			if ( $this->add( $code, $batch ) ) {
				++$report['imported'];
				continue;
			}
			// Not a duplicate (checked above): the database refused the row. Say why.
			$reason = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'the database did not save it', 'voucher-captain' );
			/* translators: 1: row number, 2: value, 3: reason */
			Jobs::add_error( $report, 'not_saved', sprintf( __( 'Row %1$d: "%2$s" – %3$s', 'voucher-captain' ), (int) $row, mb_substr( $code, 0, 80 ), $reason ) );
		}
	}

	/** One chunk of a background import (Jobs handler). */
	public function import_step( array $job ): array {
		$n      = (int) $job['next'];
		$chunk  = Jobs::chunk( $job, $n );
		$report = (array) $job['report'];
		if ( null !== $chunk ) {
			$this->import_codes( $chunk, (string) $report['batch'], $report );
			$job['done'] = (int) $job['done'] + count( $chunk );
		}
		$job['report'] = $report;
		$job['next']   = $n + 1;
		Jobs::drop_chunk( $job, $n );
		if ( $job['next'] >= (int) $job['chunks'] ) {
			$job['status'] = 'done';
			$job['done']   = (int) $job['total'];
		}
		return $job;
	}

	/** Free codes of one batch, counted on the (batch, used_at) index (no scan of the other batches). */
	public function free_count( string $batch ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE batch = %s AND used_at IS NULL", $batch ) ); // phpcs:ignore
	}

	/** @return array<int, array{batch:string,total:int,free:int}> */
	public function batches(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT batch, COUNT(*) AS total, SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) AS free FROM {$this->table()} GROUP BY batch ORDER BY batch", ARRAY_A ); // phpcs:ignore
		return array_map(
			static fn( $r ) => array(
				'batch' => (string) $r['batch'],
				'total' => (int) $r['total'],
				'free'  => (int) $r['free'],
			),
			$rows ?: array()
		);
	}

	public function delete_free( string $batch ): int {
		global $wpdb;
		ProductChecks::flush();
		ProductChecks::batch_touched( $batch );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE batch = %s AND used_at IS NULL", $batch ) ); // phpcs:ignore
	}
}
