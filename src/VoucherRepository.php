<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for issued vouchers and their audit trail.
 */
final class VoucherRepository {
	private string $table;
	private string $events;

	public function __construct() {
		$t            = Install::tables();
		$this->table  = $t['vouchers'];
		$this->events = $t['events'];
	}

	public function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public function insert( Voucher $voucher ): int {
		global $wpdb;
		$voucher->created_at = $voucher->created_at ?: $this->now();
		$voucher->updated_at = $this->now();
		if ( '' === $voucher->token ) {
			$voucher->token = self::new_token();
		}
		$ok = $wpdb->insert( $this->table, $voucher->to_row() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok ) {
			return 0;
		}
		$voucher->id = (int) $wpdb->insert_id;
		do_action( 'vcap_voucher_created', $voucher );
		return $voucher->id;
	}

	public function update( Voucher $voucher ): bool {
		global $wpdb;
		$voucher->updated_at = $this->now();
		$ok                  = false !== $wpdb->update( $this->table, $voucher->to_row(), array( 'id' => $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			do_action( 'vcap_voucher_updated', $voucher );
		}
		return $ok;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->events, array( 'voucher_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	private function get_by( string $column, $value ): ?Voucher {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE {$column} = %s LIMIT 1", $value ), ARRAY_A ); // phpcs:ignore
		return $row ? Voucher::from_row( $row ) : null;
	}

	public function get( int $id ): ?Voucher {
		return $id ? $this->get_by( 'id', $id ) : null;
	}

	public function get_by_code( string $code ): ?Voucher {
		return '' !== $code ? $this->get_by( 'code', $code ) : null;
	}

	public function get_by_token( string $token ): ?Voucher {
		return preg_match( '/^[a-f0-9]{48}$/', $token ) ? $this->get_by( 'token', $token ) : null;
	}

	public function get_by_coupon( int $coupon_id ): ?Voucher {
		return $coupon_id ? $this->get_by( 'coupon_id', $coupon_id ) : null;
	}

	public function get_slot( int $order_item_id, int $index ): ?Voucher {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_item_id = %d AND item_index = %d", $order_item_id, $index ), ARRAY_A ); // phpcs:ignore
		return $row ? Voucher::from_row( $row ) : null;
	}

	/** @return Voucher[] */
	public function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY order_item_id, item_index", $order_id ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/** @return Voucher[] */
	public function for_email( string $email, int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE buyer_email = %s OR recipient_email = %s ORDER BY id DESC LIMIT %d", $email, $email, $limit ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/** @return Voucher[] */
	public function for_customer( int $user_id, string $email, int $limit = 200 ): array {
		global $wpdb;
		$order_ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 500,
				'return'      => 'ids',
			)
		);
		$order_ids = array_map( 'intval', $order_ids ?: array( 0 ) );
		$in        = implode( ',', $order_ids );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id IN ($in) OR recipient_email = %s ORDER BY id DESC LIMIT %d", $email, $limit ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * @return array{items: Voucher[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'type'     => '',
				'product'  => 0,
				'expiring' => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);
		$where = array( '1=1' );
		$vals  = array();
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(code LIKE %s OR buyer_email LIKE %s OR recipient_email LIKE %s OR buyer_name LIKE %s OR recipient_name LIKE %s OR order_id = %d)';
			array_push( $vals, $like, $like, $like, $like, $like, (int) $args['search'] );
		}
		if ( 'expired' === $args['status'] ) {
			$where[] = "(status = 'expired' OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < %s))";
			$vals[]  = $this->now();
		} elseif ( 'active' === $args['status'] ) {
			$where[] = "status = 'active' AND (expires_at IS NULL OR expires_at >= %s)";
			$vals[]  = $this->now();
		} elseif ( '' !== $args['status'] ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( '' !== $args['type'] ) {
			$where[] = 'type = %s';
			$vals[]  = $args['type'];
		}
		if ( $args['product'] ) {
			$where[] = '(product_id = %d OR variation_id = %d)';
			array_push( $vals, (int) $args['product'], (int) $args['product'] );
		}
		if ( 'soon' === $args['expiring'] ) {
			$where[] = 'expires_at BETWEEN %s AND %s';
			array_push( $vals, $this->now(), gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
		}
		$allowed = array( 'id', 'code', 'balance', 'initial_amount', 'expires_at', 'created_at', 'status', 'order_id' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = implode( ' AND ', $where );
		$limit   = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );

		$count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE $sql";
		$list_sql  = "SELECT * FROM {$this->table} WHERE $sql ORDER BY $orderby $order LIMIT $limit OFFSET $offset";
		if ( $vals ) {
			$count_sql = $wpdb->prepare( $count_sql, $vals ); // phpcs:ignore
			$list_sql  = $wpdb->prepare( $list_sql, $vals ); // phpcs:ignore
		}
		$rows = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore
		return array(
			'items' => array_map( array( Voucher::class, 'from_row' ), $rows ?: array() ),
			'total' => (int) $wpdb->get_var( $count_sql ), // phpcs:ignore
		);
	}

	public function code_exists( string $code ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE code = %s", $code ) ); // phpcs:ignore
	}

	/** @return int[] voucher ids whose delivery is due */
	public function due_for_delivery( int $limit = 50 ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE delivered_at IS NULL AND status IN ('scheduled','active') AND delivery_at IS NOT NULL AND delivery_at <= %s LIMIT %d", $this->now(), $limit ) ) // phpcs:ignore
		);
	}

	public function expire_overdue(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'expired', updated_at = %s WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", $this->now(), $this->now() ) ); // phpcs:ignore
	}

	/** Erase personal data of vouchers that ended more than $days ago. */
	public function anonymise_older_than( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"UPDATE {$this->table} SET recipient_name = '', recipient_email = '', message = '', fields = '[]', updated_at = %s
				WHERE recipient_email <> '' AND ( (status IN ('expired','redeemed','void') AND updated_at < %s) OR (expires_at IS NOT NULL AND expires_at < %s) )",
				$this->now(),
				$cutoff,
				$cutoff
			)
		);
	}

	public function log( int $voucher_id, string $event, ?float $amount = null, ?float $balance_after = null, string $note = '', int $order_id = 0 ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->events,
			array(
				'voucher_id'    => $voucher_id,
				'event'         => $event,
				'amount'        => $amount,
				'balance_after' => $balance_after,
				'order_id'      => $order_id,
				'user_id'       => get_current_user_id(),
				'note'          => $note,
				'created_at'    => $this->now(),
			)
		);
		do_action( 'vcap_voucher_event', $voucher_id, $event, $amount, $balance_after, $note, $order_id );
	}

	public function events( int $voucher_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->events} WHERE voucher_id = %d ORDER BY id ASC", $voucher_id ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	public static function event_labels(): array {
		return array(
			'issued'     => __( 'Issued', 'voucher-captain' ),
			'scheduled'  => __( 'Delivery scheduled', 'voucher-captain' ),
			'delivered'  => __( 'Delivered', 'voucher-captain' ),
			'resent'     => __( 'Email resent', 'voucher-captain' ),
			'redeemed'   => __( 'Redeemed', 'voucher-captain' ),
			'restored'   => __( 'Balance restored', 'voucher-captain' ),
			'adjusted'   => __( 'Balance adjusted', 'voucher-captain' ),
			'checkin'    => __( 'Checked in', 'voucher-captain' ),
			'checkin_undo' => __( 'Check-in reverted', 'voucher-captain' ),
			'voided'     => __( 'Voided', 'voucher-captain' ),
			'reactivated'=> __( 'Reactivated', 'voucher-captain' ),
			'expired'    => __( 'Expired', 'voucher-captain' ),
			'edited'     => __( 'Edited', 'voucher-captain' ),
			'downloaded' => __( 'Downloaded', 'voucher-captain' ),
		);
	}
}
