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

	/**
	 * Write only the given columns, so an edit of e.g. the recipient cannot overwrite a balance or
	 * status that a redemption changed in the meantime. A status change is applied only when the
	 * stored status is still the one the edit started from ($from_status).
	 *
	 * @param string[] $columns Column names from Voucher::to_row() (except status).
	 */
	public function update_columns( Voucher $voucher, array $columns, ?string $from_status = null ): bool {
		global $wpdb;
		$voucher->updated_at = $this->now();
		$row                 = $voucher->to_row();
		$data                = array_intersect_key( $row, array_flip( array_diff( $columns, array( 'status' ) ) ) );
		$data['updated_at']  = $voucher->updated_at;
		$ok                  = false !== $wpdb->update( $this->table, $data, array( 'id' => $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok && null !== $from_status && $from_status !== $voucher->status ) {
			$wpdb->update( $this->table, array( 'status' => $voucher->status ), array( 'id' => $voucher->id, 'status' => $from_status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( $ok ) {
			$fresh = $this->get( $voucher->id );
			if ( $fresh ) {
				foreach ( get_object_vars( $fresh ) as $key => $value ) {
					$voucher->$key = $value;
				}
				do_action( 'vcap_voucher_updated', $fresh );
			}
		}
		return $ok;
	}

	/** Mark a single-use voucher redeemed only if it is still active (atomic; false when someone else won). */
	public function redeem_single_if_active( int $id ): bool {
		global $wpdb;
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, balance = 0, updated_at = %s WHERE id = %d AND status = %s", Voucher::STATUS_REDEEMED, $this->now(), $id, Voucher::STATUS_ACTIVE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 === $done ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				do_action( 'vcap_voucher_updated', $fresh );
			}
		}
		return 1 === $done;
	}

	/** Change only the status column (never overwrites a balance changed concurrently by a transaction). */
	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		$ok = false !== $wpdb->update( $this->table, array( 'status' => $status, 'updated_at' => $this->now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				do_action( 'vcap_voucher_updated', $fresh );
			}
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
	public function for_email( string $email, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE buyer_email = %s OR recipient_email = %s ORDER BY id DESC LIMIT %d OFFSET %d", $email, $email, $limit, $offset ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/** @return Voucher[] */
	/**
	 * Vouchers bought by a customer. Deliberately NOT matched by recipient email: WooCommerce account emails are
	 * unverified, so that would let anyone list gifts addressed to an email they merely claim.
	 */
	public function for_customer( int $user_id, int $limit = 200 ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		// Page through all of the customer's orders (newest first) until enough vouchers are found: customers
		// with many orders (e.g. regulars) must still see vouchers bought long ago.
		$rows = array();
		for ( $page = 1; $page <= 200 && count( $rows ) < $limit; $page++ ) {
			$order_ids = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 500,
					'paged'       => $page,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'ids',
				)
			);
			if ( ! $order_ids ) {
				break;
			}
			$in    = implode( ',', array_map( 'intval', $order_ids ) );
			$batch = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id IN ($in) ORDER BY id DESC LIMIT %d", $limit - count( $rows ) ), ARRAY_A ); // phpcs:ignore
			$rows  = array_merge( $rows, $batch ?: array() );
			if ( count( $order_ids ) < 500 ) {
				break;
			}
		}
		return array_map( array( Voucher::class, 'from_row' ), $rows );
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
				'undelivered' => '',
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
		if ( 'yes' === ( $args['undelivered'] ?? '' ) ) {
			// Due for more than an hour but never delivered (mail failed, no valid address, email disabled).
			$where[] = "delivered_at IS NULL AND status IN ('scheduled','active') AND COALESCE(delivery_at, created_at) <= %s";
			$vals[]  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
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

	/** Number of vouchers that are due but were never delivered (cached for ten minutes). */
	public function undelivered_count(): int {
		$count = get_transient( 'vcap_undelivered_count' );
		if ( false === $count ) {
			$count = $this->query( array( 'undelivered' => 'yes', 'per_page' => 1 ) )['total'];
			set_transient( 'vcap_undelivered_count', (int) $count, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Undelivered vouchers whose delivery is due (for an hour, when without date), at most 30 days ago, and that
	 * were not given up (delivery_attempts()): never attempted first, then the oldest last attempt, then the
	 * oldest due date. $exclude: ids already handled in this run.
	 *
	 * @param int[] $exclude
	 * @return int[] voucher ids
	 */
	public function due_for_delivery( int $limit = 50, array $exclude = array() ): array {
		global $wpdb;
		$exclude = array_values( array_filter( array_map( 'intval', $exclude ) ) );
		$not_in  = $exclude ? ' AND v.id NOT IN (' . implode( ',', $exclude ) . ')' : '';
		$since   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					"SELECT v.id FROM {$this->table} v
					WHERE v.delivered_at IS NULL AND v.status IN ('scheduled','active')
					AND ( ( v.delivery_at IS NOT NULL AND v.delivery_at <= %s AND v.delivery_at >= %s ) OR ( v.delivery_at IS NULL AND v.created_at <= %s AND v.created_at >= %s ) )
					AND NOT EXISTS ( " . $this->given_up_sql( 'v' ) . " ){$not_in}
					ORDER BY ( SELECT MAX(f.id) FROM {$this->events} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' ) IS NOT NULL,
					( SELECT MAX(f.id) FROM {$this->events} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' ) ASC,
					COALESCE( v.delivery_at, v.created_at ) ASC, v.id ASC
					LIMIT %d",
					$this->now(),
					$since,
					gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					$since,
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * Ledger events after which the delivery attempts of a voucher are counted anew (an admin resend is not one: it
	 * does not mark the voucher delivered, so a given-up delivery must not start over and mail it a second time).
	 */
	private const DELIVERY_RESET_EVENTS = "'scheduled','delivered'";

	/** Subquery (for EXISTS): a "delivery_given_up" of voucher alias $v after its last reset event. */
	private function given_up_sql( string $v ): string {
		return "SELECT 1 FROM {$this->events} g WHERE g.voucher_id = {$v}.id AND g.event = 'delivery_given_up'
			AND g.id > COALESCE( ( SELECT MAX(r.id) FROM {$this->events} r WHERE r.voucher_id = {$v}.id AND r.event IN (" . self::DELIVERY_RESET_EVENTS . ') ), 0 )';
	}

	/**
	 * Delivery attempts of a voucher, counted from the ledger since its delivery was last (re)scheduled or delivered:
	 * failed attempts, the time of the last one and whether the delivery was given up.
	 *
	 * @return array{failures:int, last_attempt:?string, given_up:bool}
	 */
	public function delivery_attempts( int $voucher_id ): array {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event, created_at FROM {$this->events} WHERE voucher_id = %d AND event IN ('delivery_failed','delivery_given_up'," . self::DELIVERY_RESET_EVENTS . ') ORDER BY id DESC LIMIT 500', $voucher_id ), ARRAY_A ); // phpcs:ignore
		$state = array( 'failures' => 0, 'last_attempt' => null, 'given_up' => false );
		foreach ( $rows ? $rows : array() as $row ) {
			if ( 'delivery_failed' === $row['event'] ) {
				++$state['failures'];
				$state['last_attempt'] = $state['last_attempt'] ?? (string) $row['created_at'];
			} elseif ( 'delivery_given_up' === $row['event'] ) {
				$state['given_up'] = true;
			} else {
				break; // A reset event: earlier attempts belong to an earlier delivery.
			}
		}
		return $state;
	}

	/** Undelivered vouchers whose delivery was given up after repeated failures (cached for ten minutes). */
	public function given_up_count(): int {
		$count = get_transient( 'vcap_given_up_count' );
		if ( false === $count ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} v WHERE v.delivered_at IS NULL AND v.status IN ('scheduled','active') AND EXISTS ( " . $this->given_up_sql( 'v' ) . ' )' ); // phpcs:ignore
			set_transient( 'vcap_given_up_count', $count, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Delivered, usable vouchers with remaining value that expire within $days and were not reminded yet.
	 *
	 * @return Voucher[]
	 */
	public function due_for_expiry_reminder( int $days, int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT v.* FROM {$this->table} v
				WHERE v.status = 'active' AND v.delivered_at IS NOT NULL AND v.type <> 'ticket'
				AND v.expires_at IS NOT NULL AND v.expires_at > %s AND v.expires_at <= %s
				AND ( v.usage_mode <> 'balance' OR v.balance > 0 )
				AND NOT EXISTS ( SELECT 1 FROM {$this->events} e WHERE e.voucher_id = v.id AND e.event = 'expiry_reminder' )
				ORDER BY v.expires_at ASC LIMIT %d",
				$this->now(),
				gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				$limit
			),
			ARRAY_A
		);
		return array_map( array( Voucher::class, 'from_row' ), $rows ? $rows : array() );
	}

	public function expire_overdue(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'expired', updated_at = %s WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", $this->now(), $this->now() ) ); // phpcs:ignore
	}

	/** Erase personal data of vouchers that ended more than $days ago. */
	public function anonymise_older_than( int $days ): int {
		global $wpdb;
		if ( $days <= 0 || ! Install::tables_support_transactions() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$eligibility = "( (v.status IN ('expired','redeemed','void') AND v.updated_at < %s) OR (v.expires_at IS NOT NULL AND v.expires_at < %s) )";
		try {
			$notes = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$this->events} AS e INNER JOIN {$this->table} AS v ON v.id = e.voucher_id
					SET e.note = '[personal data removed by retention]'
					WHERE (v.buyer_email <> '' OR v.recipient_email <> '') AND {$eligibility} AND e.note <> ''",
					$cutoff,
					$cutoff
				)
			);
			if ( false === $notes ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return 0;
			}
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$this->table} AS v SET buyer_name = '', buyer_email = '', recipient_name = '', recipient_email = '', message = '', fields = '[]', updated_at = %s
					WHERE (v.buyer_email <> '' OR v.recipient_email <> '') AND {$eligibility}",
					$this->now(),
					$cutoff,
					$cutoff
				)
			);
			if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return 0;
			}
			return (int) $updated;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
	}

	/** Redact free-text audit notes while retaining event type, amount, balance and timestamp. */
	/** History entries whose note is exactly this e-mail address (WooCommerce "used by"). */
	public function events_noting( string $email, int $limit = 1000, int $offset = 0 ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, voucher_id, event, amount, created_at FROM {$this->events} WHERE LOWER(note) = %s ORDER BY id LIMIT %d OFFSET %d", $email, max( 1, $limit ), max( 0, $offset ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function erase_event_notes( string $email ): bool {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		return is_email( $email ) && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->events} SET note = '[personal data removed]' WHERE LOWER(note) = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function anonymise_event_notes( int $voucher_id ): bool {
		global $wpdb;
		return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$this->events} SET note = '[personal data removed]' WHERE voucher_id = %d AND note <> ''",
				$voucher_id
			)
		);
	}

	public function log( int $voucher_id, string $event, ?float $amount = null, ?float $balance_after = null, string $note = '', int $order_id = 0 ): bool {
		global $wpdb;
		$inserted = false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		if ( $inserted ) {
			do_action( 'vcap_voucher_event', $voucher_id, $event, $amount, $balance_after, $note, $order_id );
		}
		return $inserted;
	}

	public function events( int $voucher_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->events} WHERE voucher_id = %d ORDER BY id ASC", $voucher_id ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	public static function event_labels(): array {
		$labels = array(
			'issued'     => __( 'Issued', 'voucher-captain' ),
			'reserved'   => __( 'Balance reserved for payment', 'voucher-captain' ),
			'double_use' => __( 'Used in a shop order although already redeemed elsewhere – check the order', 'voucher-captain' ),
			'committed'  => __( 'Balance reservation committed', 'voucher-captain' ),
			'reservation_released' => __( 'Balance reservation released', 'voucher-captain' ),
			'api_reserved'  => __( 'Reserved for external redemption', 'voucher-captain' ),
			'api_committed' => __( 'Redeemed externally', 'voucher-captain' ),
			'api_released'  => __( 'External reservation released', 'voucher-captain' ),
			'api_refunded'  => __( 'External redemption refunded', 'voucher-captain' ),
			'scheduled'  => __( 'Delivery scheduled', 'voucher-captain' ),
			'delivered'  => __( 'Delivered', 'voucher-captain' ),
			'delivery_failed' => __( 'Delivery failed', 'voucher-captain' ),
			'delivery_given_up' => __( 'Delivery given up (no more automatic attempts)', 'voucher-captain' ),
			'delivery_expired'  => __( 'Not delivered: already expired', 'voucher-captain' ),
			'expiry_reminder' => __( 'Expiry reminder', 'voucher-captain' ),
			'credit'        => __( 'Credit added', 'voucher-captain' ),
			'debit'         => __( 'Credit removed', 'voucher-captain' ),
			'refund_credit' => __( 'Refunded as store credit', 'voucher-captain' ),
			'refund_credit_reversed' => __( 'Store credit refund reversed', 'voucher-captain' ),
			'resent'     => __( 'Email resent', 'voucher-captain' ),
			'redeemed'   => __( 'Redeemed', 'voucher-captain' ),
			'redeem_reversed' => __( 'Redemption cancelled (value booked back)', 'voucher-captain' ),
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
		/** Labels of connector events in the voucher history. */
		return (array) apply_filters( 'vcap_event_labels', $labels );
	}
}
