<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-generated (imported) codes, organised in batches. Products can draw codes from a batch.
 */
final class CodePool {
	private string $table;

	public function __construct() {
		$this->table = Install::tables()['pool'];
	}

	/**
	 * Claim a free code of a batch atomically, in random order: the file order (often numbered cards) does not
	 * tell a buyer which codes the next customers get. A random id between the lowest and highest free id picks
	 * the next free code from there (two index lookups, no full scan); the optimistic UPDATE below makes sure
	 * that only one process gets a code, a process that lost the race tries another random position.
	 */
	public function claim( string $batch, int $voucher_id = 0 ): ?string {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$row = $this->random_free( $batch );
			if ( ! $row ) {
				return null;
			}
			// Optimistic lock: only one process can flip used_at from NULL.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET used_at = %s, voucher_id = %d WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $voucher_id, $row['id'] ) ); // phpcs:ignore
			if ( 1 === (int) $updated ) {
				return (string) $row['code'];
			}
		}
		return null;
	}

	/** @return array{id:string|int,code:string}|null a free code of the batch at a random position */
	private function random_free( string $batch ): ?array {
		global $wpdb;
		// Bounds from the (batch, voucher_id) index only; claimed codes in between are skipped by the lookup.
		$bounds = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(id) AS lo, MAX(id) AS hi FROM {$this->table} WHERE batch = %s AND voucher_id = 0", $batch ), ARRAY_A ); // phpcs:ignore
		if ( ! $bounds || null === $bounds['lo'] ) {
			return null;
		}
		$pivot = random_int( (int) $bounds['lo'], max( (int) $bounds['lo'], (int) $bounds['hi'] ) );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, code FROM {$this->table} WHERE batch = %s AND voucher_id = 0 AND used_at IS NULL AND id >= %d ORDER BY id ASC LIMIT 1", $batch, $pivot ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, code FROM {$this->table} WHERE batch = %s AND voucher_id = 0 AND used_at IS NULL AND id < %d ORDER BY id DESC LIMIT 1", $batch, $pivot ), ARRAY_A ); // phpcs:ignore
		}
		return $row ?: null;
	}

	/**
	 * Codes already in a batch (the newest $limit), to rate the structure of codes added to it.
	 *
	 * @return string[]
	 */
	public function sample( string $batch, int $limit = 2000 ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$this->table} WHERE batch = %s ORDER BY id DESC LIMIT %d", $batch, $limit ) ) ); // phpcs:ignore
	}

	public function attach( string $code, int $voucher_id ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table, array( 'voucher_id' => $voucher_id ), array( 'code' => $code ) ); // phpcs:ignore
	}

	public function release( string $code ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table, array( 'voucher_id' => 0, 'used_at' => null ), array( 'code' => $code ) ); // phpcs:ignore
	}

	public function exists( string $code ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE code = %s", $code ) ); // phpcs:ignore
	}

	public function add( string $code, string $batch ): bool {
		global $wpdb;
		return (bool) $wpdb->insert( // phpcs:ignore
			$this->table,
			array(
				'code'        => $code,
				'batch'       => $batch,
				'imported_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** @return array<int, array{batch:string,total:int,free:int}> */
	public function batches(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT batch, COUNT(*) AS total, SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) AS free FROM {$this->table} GROUP BY batch ORDER BY batch", ARRAY_A ); // phpcs:ignore
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
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE batch = %s AND used_at IS NULL", $batch ) ); // phpcs:ignore
	}
}
