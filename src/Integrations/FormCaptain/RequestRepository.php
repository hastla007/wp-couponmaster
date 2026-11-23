<?php
/**
 * Storage of voucher requests handed over by Form Captain.
 *
 * @package VCAP\Integrations\FormCaptain
 */

namespace VCAP\Integrations\FormCaptain;

defined( 'ABSPATH' ) || exit;

/**
 * Own table of Voucher Captain ({prefix}vcap_fc_requests). A request is only a record "to be reviewed": it never
 * holds a voucher code, a coupon or a payment. The idempotency key (site, form, entry) is UNIQUE, so a repeated
 * delivery can never store a second request.
 *
 * The table has its own schema version (option `vcap_fc_requests_db_version`), so it is created only on sites
 * that use the Form Captain adapter and the core migrations stay untouched.
 *
 * @since 1.3.0
 */
final class RequestRepository {
	public const DB_VERSION        = '1.0.0';
	public const OPTION_DB_VERSION = 'vcap_fc_requests_db_version';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_APPROVING = 'approving';
	public const STATUS_APPROVED  = 'approved';
	public const STATUS_REJECTED  = 'rejected';
	public const STATUS_WITHDRAWN = 'withdrawn';

	/** Personal data columns, emptied when an entry is erased or its consent withdrawn. */
	public const PERSONAL_COLUMNS = array( 'company', 'contact_name', 'email', 'personalization' );

	/**
	 * Tables known to exist in this request (per site on multisite).
	 *
	 * @var array<string, bool>
	 */
	private static array $ready = array();

	/**
	 * Table name of the current site.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vcap_fc_requests';
	}

	/**
	 * Creates or updates the table when its schema version changed.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Creates or updates the table (dbDelta is idempotent).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key varchar(191) NOT NULL,
				job_key varchar(191) NOT NULL DEFAULT '',
				form_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				company varchar(200) NOT NULL DEFAULT '',
				contact_name varchar(200) NOT NULL DEFAULT '',
				email varchar(200) NOT NULL DEFAULT '',
				quantity int(11) unsigned NOT NULL DEFAULT 1,
				value decimal(19,4) NOT NULL DEFAULT 0,
				personalization text NULL,
				consent tinyint(1) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vouchers int(11) unsigned NOT NULL DEFAULT 0,
				anonymized tinyint(1) unsigned NOT NULL DEFAULT 0,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY entry_id (entry_id),
				KEY status (status)
			) ENGINE=InnoDB {$collate};"
		);
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		self::$ready = array();
	}

	/**
	 * Whether the table exists (the adapter is in use on this site).
	 */
	public static function ready(): bool {
		global $wpdb;
		$table = self::table();
		if ( empty( self::$ready[ $table ] ) ) {
			self::$ready[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$ready[ $table ];
	}

	/**
	 * Stores a new request. Returns the id of the existing request when the idempotency key is known already
	 * (duplicate delivery, also when two workers race: the UNIQUE key decides).
	 *
	 * @param array<string, mixed> $data Column values (sanitised by the caller).
	 * @return array{id:int, created:bool}|null Null when the row could not be stored.
	 */
	public function insert_once( array $data ): ?array {
		global $wpdb;
		$existing = $this->find_by_key( (string) $data['idempotency_key'] );
		if ( $existing ) {
			return array(
				'id'      => (int) $existing['id'],
				'created' => false,
			);
		}
		$now  = current_time( 'mysql', true );
		$row  = array_merge(
			array(
				'status'     => self::STATUS_PENDING,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		$done = $wpdb->query( $this->insert_ignore_sql( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared in insert_ignore_sql().
		if ( $done ) {
			return array(
				'id'      => (int) $wpdb->insert_id,
				'created' => true,
			);
		}
		$existing = $this->find_by_key( (string) $data['idempotency_key'] );
		return $existing ? array(
			'id'      => (int) $existing['id'],
			'created' => false,
		) : null;
	}

	/**
	 * INSERT IGNORE statement for a row.
	 *
	 * @param array<string, mixed> $row Column values.
	 */
	private function insert_ignore_sql( array $row ): string {
		global $wpdb;
		$columns = array();
		$formats = array();
		foreach ( $row as $column => $value ) {
			$columns[] = '`' . preg_replace( '/[^a-z_]/', '', (string) $column ) . '`';
			$formats[] = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
		}
		$table = self::table();
		return $wpdb->prepare( "INSERT IGNORE INTO {$table} (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $formats ) . ')', array_values( $row ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- column names are filtered, values are placeholders.
	}

	/**
	 * One request.
	 *
	 * @param int $id Request id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Request by idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Requests of a Form Captain entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_entry( int $entry_id ): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d", $entry_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Newest requests first.
	 *
	 * @param string $status Status filter ('' = all).
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table();
		if ( '' === $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of requests per status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Updates columns of a request.
	 *
	 * @param int                  $id   Request id.
	 * @param array<string, mixed> $data Column values.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomic status change: only one caller wins (double click, two managers).
	 *
	 * @param int    $id   Request id.
	 * @param string $from Expected current status.
	 * @param string $to   New status.
	 * @return bool True when this call changed the status.
	 */
	public function transition( int $id, string $from, string $to ): bool {
		global $wpdb;
		$table = self::table();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s", $to, current_time( 'mysql', true ), $id, $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes a request.
	 *
	 * @param int $id Request id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
