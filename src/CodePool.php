<?php
namespace WCVS;

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
	 * Claim the next free code of a batch atomically.
	 */
	public function claim( string $batch, int $voucher_id = 0 ): ?string {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, code FROM {$this->table} WHERE batch = %s AND voucher_id = 0 AND used_at IS NULL ORDER BY id ASC LIMIT 1", $batch ), ARRAY_A ); // phpcs:ignore
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

	public function attach( string $code, int $voucher_id ): void {
		global $wpdb;
		$wpdb->update( $this->table, array( 'voucher_id' => $voucher_id ), array( 'code' => $code ) ); // phpcs:ignore
	}

	public function release( string $code ): void {
		global $wpdb;
		$wpdb->update( $this->table, array( 'voucher_id' => 0, 'used_at' => null ), array( 'code' => $code ) ); // phpcs:ignore
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
