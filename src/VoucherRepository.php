<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for issued vouchers and their audit trail.
 */
final class VoucherRepository {
	/** Voucher table of the current site (never kept: switch_to_blog() changes it). */
	private function table(): string {
		return Install::tables()['vouchers'];
	}

	/** Ledger table of the current site. */
	private function events_table(): string {
		return Install::tables()['events'];
	}

	public function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Why the last insert() failed (D3): 'duplicate' (slot or code taken – a concurrent request won), 'db_error'
	 * (logged with a reference, see Db::fail()), '' after success.
	 */
	public string $last_insert_error = '';

	public function insert( Voucher $voucher ): int {
		global $wpdb;
		$this->last_insert_error = '';
		$voucher->created_at = $voucher->created_at ?: $this->now();
		$voucher->updated_at = $this->now();
		if ( '' === $voucher->token ) {
			$voucher->token = self::new_token();
		}
		$ok = $wpdb->insert( $this->table(), $voucher->to_row() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok ) {
			$error = (string) $wpdb->last_error;
			if ( str_contains( $error, 'Duplicate entry' ) || ( $voucher->order_item_id && $this->get_slot( (int) $voucher->order_item_id, (int) $voucher->item_index ) ) ) {
				$this->last_insert_error = 'duplicate';
			} else {
				$this->last_insert_error = 'db_error';
				Db::fail( 'insert_voucher', array( 'order_id' => (int) $voucher->order_id, 'order_item_id' => (int) $voucher->order_item_id ) );
			}
			return 0;
		}
		$voucher->id = (int) $wpdb->insert_id;
		/** A voucher row was inserted (args: Voucher). If its issuing fails afterwards, vcap_voucher_deleted follows. */
		do_action( 'vcap_voucher_created', $voucher );
		return $voucher->id;
	}

	public function update( Voucher $voucher ): bool {
		global $wpdb;
		$voucher->updated_at = $this->now();
		$ok                  = false !== $wpdb->update( $this->table(), $voucher->to_row(), array( 'id' => $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			/** A voucher was saved (args: Voucher as stored). May fire inside a database transaction, see CONNECTORS.md. */
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
		$ok                  = false !== $wpdb->update( $this->table(), $data, array( 'id' => $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok && null !== $from_status && $from_status !== $voucher->status ) {
			$wpdb->update( $this->table(), array( 'status' => $voucher->status ), array( 'id' => $voucher->id, 'status' => $from_status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = %s, balance = 0, updated_at = %s WHERE id = %d AND status = %s", Voucher::STATUS_REDEEMED, $this->now(), $id, Voucher::STATUS_ACTIVE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		$ok = false !== $wpdb->update( $this->table(), array( 'status' => $status, 'updated_at' => $this->now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				do_action( 'vcap_voucher_updated', $fresh );
			}
		}
		return $ok;
	}

	/**
	 * Removes a voucher and its history (only used to undo a voucher whose issuing failed). Listeners that reacted
	 * to "vcap_voucher_created" are told with "vcap_voucher_deleted" (D3), so they keep no ghost voucher.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$voucher = $this->get( $id );
		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->events_table(), array( 'voucher_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $voucher ) {
			/**
			 * A voucher was removed again (its issuing failed after "vcap_voucher_created").
			 *
			 * @param Voucher $voucher The voucher as it was stored.
			 */
			do_action( 'vcap_voucher_deleted', $voucher );
		}
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	private function get_by( string $column, $value ): ?Voucher {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$column} = %s LIMIT 1", $value ), ARRAY_A ); // phpcs:ignore
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
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_item_id = %d AND item_index = %d", $order_item_id, $index ), ARRAY_A ); // phpcs:ignore
		return $row ? Voucher::from_row( $row ) : null;
	}

	/** @return Voucher[] */
	public function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id = %d ORDER BY order_item_id, item_index", $order_id ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/** @return Voucher[] */
	public function for_email( string $email, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE buyer_email = %s OR recipient_email = %s ORDER BY id DESC LIMIT %d OFFSET %d", $email, $email, $limit, $offset ), ARRAY_A ); // phpcs:ignore
		return array_map( array( Voucher::class, 'from_row' ), $rows ?: array() );
	}

	/** @return Voucher[] */
	/**
	 * Vouchers bought by a customer. Deliberately NOT matched by recipient email: WooCommerce account emails are
	 * unverified, so that would let anyone list gifts addressed to an email they merely claim.
	 */
	public function for_customer( int $user_id, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		// B15: a page of the list ($offset vouchers skipped, newest first).
		$offset = max( 0, $offset );
		$limit += $offset;
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
			$batch = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id IN ($in) ORDER BY id DESC LIMIT %d", $limit - count( $rows ) ), ARRAY_A ); // phpcs:ignore
			$rows  = array_merge( $rows, $batch ?: array() );
			if ( count( $order_ids ) < 500 ) {
				break;
			}
		}
		return array_map( array( Voucher::class, 'from_row' ), array_slice( $rows, $offset ) );
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
		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$prefix = $wpdb->esc_like( $search ) . '%';
			if ( str_contains( $search, '@' ) ) {
				// An address: its start on the email columns (indexed), nothing else.
				$where[] = '(buyer_email LIKE %s OR recipient_email LIKE %s)';
				array_push( $vals, $prefix, $prefix );
			} else {
				// Code by its start (UNIQUE code index), email addresses by their start, names anywhere; an order
				// number only for a number (a word must never match the vouchers without order, order_id = 0).
				$like    = '%' . $wpdb->esc_like( $search ) . '%';
				$parts   = array( 'code LIKE %s', 'buyer_email LIKE %s', 'recipient_email LIKE %s', 'buyer_name LIKE %s', 'recipient_name LIKE %s' );
				array_push( $vals, $prefix, $prefix, $prefix, $like, $like );
				if ( ctype_digit( $search ) && (int) $search > 0 ) {
					$parts[] = 'order_id = %d';
					$vals[]  = (int) $search;
				}
				$where[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}
		if ( ! empty( $args['before_id'] ) ) {
			// Keyset paging of exports (ORDER BY id DESC): no OFFSET, no rows skipped or repeated.
			$where[] = 'id < %d';
			$vals[]  = (int) $args['before_id'];
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
			// Only vouchers that can still be used and are worth something (the list to write to before they expire):
			// active or scheduled, with a remaining value or, for tickets, admissions left.
			$where[] = "expires_at BETWEEN %s AND %s AND status IN ('active','scheduled') AND ( balance > 0 OR ( type = 'ticket' AND checkins < max_checkins ) )";
			array_push( $vals, $this->now(), gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
		}
		$allowed = array( 'id', 'code', 'balance', 'initial_amount', 'expires_at', 'created_at', 'status', 'order_id' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = implode( ' AND ', $where );
		$limit   = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );

		// id as tie-breaker: rows with the same value keep a stable order across pages.
		$tie       = 'id' === $orderby ? '' : ", id $order";
		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE $sql";
		$list_sql  = "SELECT * FROM {$this->table()} WHERE $sql ORDER BY $orderby $order{$tie} LIMIT $limit OFFSET $offset";
		if ( $vals ) {
			$count_sql = $wpdb->prepare( $count_sql, $vals ); // phpcs:ignore
			$list_sql  = $wpdb->prepare( $list_sql, $vals ); // phpcs:ignore
		}
		$rows = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore
		return array(
			'items' => array_map( array( Voucher::class, 'from_row' ), $rows ?: array() ),
			'total' => empty( $args['no_count'] ) ? (int) $wpdb->get_var( $count_sql ) : -1, // phpcs:ignore
		);
	}

	/**
	 * Every voucher matching the filters of query(), newest first, read in keyset batches (id < last id; no
	 * OFFSET, no COUNT per batch), for CSV exports of any size.
	 *
	 * @return \Generator<Voucher>
	 */
	public function export( array $args, int $batch = 500 ): \Generator {
		$before = 0;
		do {
			$page = $this->query( array_merge( $args, array( 'orderby' => 'id', 'order' => 'DESC', 'per_page' => $batch, 'page' => 1, 'before_id' => $before, 'no_count' => true ) ) );
			foreach ( $page['items'] as $voucher ) {
				$before = $voucher->id;
				yield $voucher;
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime(); // Products and orders read for the rows are not kept for the whole export.
			}
		} while ( count( $page['items'] ) === $batch );
	}

	public function code_exists( string $code ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE code = %s", $code ) ); // phpcs:ignore
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
		$given_up = $this->given_up_sql( 'v' ); // Internal SQL without user input (table names and constants only).
		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					"SELECT v.id FROM {$this->table()} v
					WHERE v.delivered_at IS NULL AND v.status IN ('scheduled','active')
					AND ( ( v.delivery_at IS NOT NULL AND v.delivery_at <= %s AND v.delivery_at >= %s ) OR ( v.delivery_at IS NULL AND v.created_at <= %s AND v.created_at >= %s ) )
					AND NOT EXISTS ( {$given_up} ){$not_in}
					ORDER BY ( SELECT MAX(f.id) FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' ) IS NOT NULL,
					( SELECT MAX(f.id) FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' ) ASC,
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
		return "SELECT 1 FROM {$this->events_table()} g WHERE g.voucher_id = {$v}.id AND g.event = 'delivery_given_up'
			AND g.id > COALESCE( ( SELECT MAX(r.id) FROM {$this->events_table()} r WHERE r.voucher_id = {$v}.id AND r.event IN (" . self::DELIVERY_RESET_EVENTS . ') ), 0 )';
	}

	/**
	 * Delivery attempts of a voucher, counted from the ledger since its delivery was last (re)scheduled or delivered:
	 * failed attempts, the time of the last one and whether the delivery was given up.
	 *
	 * @return array{failures:int, last_attempt:?string, given_up:bool}
	 */
	public function delivery_attempts( int $voucher_id ): array {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event, created_at FROM {$this->events_table()} WHERE voucher_id = %d AND event IN ('delivery_failed','delivery_given_up'," . self::DELIVERY_RESET_EVENTS . ') ORDER BY id DESC LIMIT 500', $voucher_id ), ARRAY_A ); // phpcs:ignore
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
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} v WHERE v.delivered_at IS NULL AND v.status IN ('scheduled','active') AND EXISTS ( " . $this->given_up_sql( 'v' ) . ' )' ); // phpcs:ignore
			set_transient( 'vcap_given_up_count', $count, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Delivered, usable vouchers with remaining value that expire within $days and were not reminded yet, found
	 * through the (status, expires_at) index. A voucher whose reminder (or another mail) failed within the last
	 * day waits a day (backoff), so failures never crowd out the others; $exclude: ids already handled in this run.
	 *
	 * @param int[] $exclude
	 * @return Voucher[]
	 */
	public function due_for_expiry_reminder( int $days, int $limit = 200, array $exclude = array() ): array {
		global $wpdb;
		$exclude = array_values( array_filter( array_map( 'intval', $exclude ) ) );
		$not_in  = $exclude ? ' AND v.id NOT IN (' . implode( ',', $exclude ) . ')' : '';
		$rows    = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT v.* FROM {$this->table()} v
				WHERE v.status = 'active' AND v.expires_at > %s AND v.expires_at <= %s
				AND v.delivered_at IS NOT NULL AND v.type <> 'ticket'
				AND ( v.usage_mode <> 'balance' OR v.balance > 0 ){$not_in}
				AND NOT EXISTS ( SELECT 1 FROM {$this->events_table()} e WHERE e.voucher_id = v.id AND e.event = 'expiry_reminder' )
				AND NOT EXISTS ( SELECT 1 FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' AND f.created_at > %s )
				ORDER BY v.expires_at ASC, v.id ASC LIMIT %d",
				$this->now(),
				gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return array_map( array( Voucher::class, 'from_row' ), $rows ? $rows : array() );
	}

	/** Vouchers one batch of the daily maintenance changes at most (one short statement each, see expire_overdue()). */
	public const MAINTENANCE_BATCH = 500;

	/**
	 * Marks active vouchers past their expiry as expired, in batches of MAINTENANCE_BATCH ids found through the
	 * (status, expires_at) index, so a run never locks the whole active range (balance checkouts and new vouchers
	 * keep working meanwhile). Each expired voucher gets an "expired" history entry and "vcap_voucher_updated";
	 * "vcap_voucher_expired" is fired once per batch with all of them (D7).
	 *
	 * @param float    $budget   Seconds; 0 = until done.
	 * @param bool|null $finished Set to false when the budget ran out with vouchers left.
	 * @return int Vouchers expired.
	 */
	public function expire_overdue( float $budget = 0.0, ?bool &$finished = null ): int {
		global $wpdb;
		$start    = microtime( true );
		$total    = 0;
		$finished = true;
		do {
			$now = $this->now();
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s ORDER BY expires_at LIMIT %d", $now, self::MAINTENANCE_BATCH ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $ids ) {
				break;
			}
			$in   = implode( ',', $ids );
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'expired', updated_at = %s WHERE id IN ({$in}) AND status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $done ) {
				throw new \RuntimeException( 'Expired vouchers could not be updated: ' . esc_html( $wpdb->last_error ) );
			}
			$total += (int) $done;
			if ( $done ) {
				// The vouchers this batch changed (a concurrent change of one of them is not counted as expiry).
				$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id IN ({$in}) AND status = 'expired' AND updated_at = %s", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$expired = array_map( array( Voucher::class, 'from_row' ), $rows ? $rows : array() );
				foreach ( $expired as $voucher ) {
					$this->log( (int) $voucher->id, 'expired', null, (float) $voucher->balance, Note::make( 'expired' ) );
					do_action( 'vcap_voucher_updated', $voucher );
				}
				if ( $expired ) {
					/**
					 * Vouchers whose validity ended were marked as expired (one call per batch of the daily maintenance).
					 *
					 * @param Voucher[] $expired
					 */
					do_action_ref_array( 'vcap_voucher_expired', array( $expired ) ); // Not do_action(): one voucher would be unwrapped from the array.
				}
			}
			if ( $budget > 0 && count( $ids ) === self::MAINTENANCE_BATCH && microtime( true ) - $start >= $budget ) {
				$finished = false;
				break;
			}
		} while ( count( $ids ) === self::MAINTENANCE_BATCH );
		return $total;
	}

	/**
	 * One hook for every completed redemption, whatever the channel (D7): "shop" (order), "counter" (scanner,
	 * till, admin) and "external" (redemption API, fired by RedemptionService with its own data).
	 */
	public static function fire_redeemed( Voucher $voucher, float $amount, string $channel, int $order_id = 0 ): void {
		/**
		 * Fires once per completed redemption.
		 *
		 * @param array $data voucher_id, code, user_id, amount, currency, order_id, channel (shop|counter|external),
		 *                    committed_at (UTC), source (see Source).
		 */
		do_action(
			'vcap_voucher_redeemed',
			array(
				'redemption_key' => '',
				'target'         => '',
				'voucher_id'   => (int) $voucher->id,
				'code'         => (string) $voucher->code,
				'user_id'      => get_current_user_id(),
				'amount'       => $amount,
				'currency'     => (string) $voucher->currency,
				'order_id'     => $order_id,
				'channel'      => $channel,
				'committed_at' => gmdate( 'Y-m-d H:i:s' ),
				'source'       => Source::current(),
			)
		);
	}

	/**
	 * Erase personal data of vouchers that ended more than $days ago: candidates found through the indexes
	 * (status, updated_at) and expires_at, MAINTENANCE_BATCH ids per short transaction (notes, then the voucher
	 * row), so checkouts never wait for one long lock over the whole tables. A database error is thrown (the
	 * maintenance step records it, see Delivery::run_maintenance_step()), never swallowed.
	 *
	 * @param float     $budget   Seconds; 0 = until done.
	 * @param bool|null $finished Set to false when the budget ran out with vouchers left.
	 * @return int Vouchers anonymised.
	 * @throws \RuntimeException When a batch could not be written.
	 */
	public function anonymise_older_than( int $days, float $budget = 0.0, ?bool &$finished = null ): int {
		global $wpdb;
		$finished = true;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$eligibility = "( (v.status IN ('expired','redeemed','void') AND v.updated_at < %s) OR (v.expires_at IS NOT NULL AND v.expires_at < %s) )";
		$personal    = "(v.buyer_email <> '' OR v.recipient_email <> '' OR v.buyer_name <> '' OR v.recipient_name <> '')";
		$transaction = Install::tables_support_transactions();
		$start       = microtime( true );
		$total       = 0;
		$after       = 0;
		do {
			// Two index ranges (ended by status, past expiry), each with personal data left; merged by id.
			$ended   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT v.id FROM {$this->table()} v WHERE v.status IN ('expired','redeemed','void') AND v.updated_at < %s AND v.id > %d AND {$personal} ORDER BY v.id LIMIT %d", $cutoff, $after, self::MAINTENANCE_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$expired = (array) $wpdb->get_col( $wpdb->prepare( "SELECT v.id FROM {$this->table()} v WHERE v.expires_at < %s AND v.id > %d AND {$personal} ORDER BY v.id LIMIT %d", $cutoff, $after, self::MAINTENANCE_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids     = array_unique( array_map( 'intval', array_merge( $ended, $expired ) ) );
			sort( $ids );
			$ids = array_slice( $ids, 0, self::MAINTENANCE_BATCH );
			if ( ! $ids ) {
				break;
			}
			$after = (int) end( $ids );
			$in    = implode( ',', $ids );
			if ( $transaction && false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				throw new \RuntimeException( 'Retention: transaction could not be started: ' . esc_html( $wpdb->last_error ) );
			}
			try {
				$notes   = $wpdb->query( "UPDATE {$this->events_table()} SET note = '[personal data removed by retention]', actor_email_hash = '' WHERE voucher_id IN ({$in}) AND note <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
				$updated = false === $notes ? false : $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$this->table()} AS v SET buyer_name = '', buyer_email = '', recipient_name = '', recipient_email = '', message = '', fields = '[]', updated_at = %s
						WHERE v.id IN ({$in}) AND {$eligibility}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids and constant SQL.
						$this->now(),
						$cutoff,
						$cutoff
					)
				);
				if ( false === $updated || ( $transaction && false === $wpdb->query( 'COMMIT' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$error = $wpdb->last_error;
					if ( $transaction ) {
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
					throw new \RuntimeException( 'Retention: personal data could not be removed: ' . esc_html( $error ) );
				}
			} catch ( \Throwable $e ) {
				if ( $transaction ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				throw $e;
			}
			$total += (int) $updated;
			if ( $budget > 0 && microtime( true ) - $start >= $budget ) {
				$finished = false;
				break;
			}
		} while ( true );
		return $total;
	}

	/**
	 * Hash of a ledger note that is exactly an e-mail address (WooCommerce "used by"), stored in actor_email_hash
	 * so exports and erasures find the notes of an address through an index; '' for any other note.
	 */
	public static function note_hash( string $note ): string {
		$note = strtolower( trim( $note ) );
		return '' !== $note && strlen( $note ) <= 200 && is_email( $note ) ? hash( 'sha256', $note ) : '';
	}

	/**
	 * Ledger rows naming an address: by actor_email_hash (index), and after the cursor of the hash migration
	 * (Install::hash_event_notes()) also by the note itself – rows written before the hash was set, e.g. by an
	 * older version after a rollback; a primary-key range of at most a day. Two index lookups, never a scan of
	 * every note.
	 *
	 * @param string $columns Columns to select.
	 * @param int    $after   Only rows after this id.
	 * @return array{0:string, 1:array} SQL of a derived table and its values.
	 */
	private function noting_rows( string $email, string $columns, int $after = 0 ): array {
		$cursor = max( $after, (int) get_option( Install::EVENT_HASH_CURSOR, 0 ) );
		$table  = $this->events_table();
		return array(
			"( SELECT {$columns} FROM {$table} WHERE actor_email_hash = %s AND id > %d ) UNION ( SELECT {$columns} FROM {$table} WHERE id > %d AND LOWER(note) = %s )",
			array( hash( 'sha256', $email ), $after, $cursor, $email ),
		);
	}

	/** History entries whose note is exactly this e-mail address (WooCommerce "used by"). */
	public function events_noting( string $email, int $limit = 1000, int $offset = 0 ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		list( $rows, $args ) = $this->noting_rows( $email, 'id, voucher_id, event, amount, created_at' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM ( {$rows} ) x ORDER BY id LIMIT %d OFFSET %d", array_merge( $args, array( max( 1, $limit ), max( 0, $offset ) ) ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Redacts the notes naming this address, in batches of 500 ids (short statements, no lock over the ledger). */
	public function erase_event_notes( string $email ): bool {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$after = 0;
		do {
			list( $rows, $args ) = $this->noting_rows( $email, 'id', $after );
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM ( {$rows} ) x ORDER BY id LIMIT 500", $args ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $ids ) {
				return true;
			}
			$after = (int) end( $ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$this->events_table()} SET note = '[personal data removed]', actor_email_hash = '' WHERE id IN ({$placeholders})", array_map( 'intval', $ids ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
				return false;
			}
		} while ( count( $ids ) === 500 );
		return true;
	}

	public function anonymise_event_notes( int $voucher_id ): bool {
		global $wpdb;
		return false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$this->events_table()} SET note = '[personal data removed]', actor_email_hash = '' WHERE voucher_id = %d AND note <> ''",
				$voucher_id
			)
		);
	}

	/**
	 * Writes a history entry. $note is free text or a coded note (Note::make(), translated when shown). The
	 * source of the change (Source::current(): admin, cron, rest, scanner, checkout, cli, web) is stored with it.
	 */
	public function log( int $voucher_id, string $event, ?float $amount = null, ?float $balance_after = null, string $note = '', int $order_id = 0 ): bool {
		global $wpdb;
		$row = array(
			'voucher_id'       => $voucher_id,
			'event'            => $event,
			'amount'           => $amount,
			'balance_after'    => $balance_after,
			'order_id'         => $order_id,
			'user_id'          => get_current_user_id(),
			'note'             => $note,
			'actor_email_hash' => self::note_hash( $note ),
			'created_at'       => $this->now(),
		);
		if ( Install::event_source_ready() ) {
			$row['source'] = Source::current();
		}
		$inserted = false !== $wpdb->insert( $this->events_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $inserted ) {
			// Listeners get the note as text (a coded note in the site language).
			/** A history entry was written (args: voucher id, event, amount, balance after, note as text, order id). */
			do_action( 'vcap_voucher_event', $voucher_id, $event, $amount, $balance_after, Note::render( $note ), $order_id );
		}
		return $inserted;
	}

	/**
	 * Ledger rows of a voucher, oldest first. With $limit only the newest $limit rows (still oldest first), read
	 * through the (voucher_id, id) index – for histories that show the latest entries only.
	 */
	public function events( int $voucher_id, int $limit = 0 ): array {
		global $wpdb;
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->events_table()} WHERE voucher_id = %d ORDER BY id DESC LIMIT %d", $voucher_id, $limit ), ARRAY_A ) ?: array(); // phpcs:ignore
			return array_reverse( $rows );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->events_table()} WHERE voucher_id = %d ORDER BY id ASC", $voucher_id ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	/**
	 * B15: one page of a voucher's history, newest first ($page from 1), through the (voucher_id, id) index.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function events_page( int $voucher_id, int $per_page, int $page = 1 ): array {
		global $wpdb;
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->events_table()} WHERE voucher_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $voucher_id, $per_page, $offset ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	/** B15: whether the voucher's history has an entry of this kind (without loading the history). */
	public function has_event( int $voucher_id, string $event ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->events_table()} WHERE voucher_id = %d AND event = %s LIMIT 1", $voucher_id, $event ) ); // phpcs:ignore
	}

	/** Number of ledger rows of a voucher. */
	public function event_count( int $voucher_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->events_table()} WHERE voucher_id = %d", $voucher_id ) ); // phpcs:ignore
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
			'delivered_without_pdf' => __( 'Delivered without PDF', 'voucher-captain' ),
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
			CouponManager::WRITTEN_OFF_EVENT => __( 'Rounding rest booked off', 'voucher-captain' ),
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
