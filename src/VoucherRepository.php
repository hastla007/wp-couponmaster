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
		self::forget();
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
		Db::action( 'vcap_voucher_created', $voucher );
		return $voucher->id;
	}

	public function update( Voucher $voucher ): bool {
		global $wpdb;
		self::forget();
		$voucher->updated_at = $this->now();
		$ok                  = false !== $wpdb->update( $this->table(), $voucher->to_row(), array( 'id' => $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			/** A voucher was saved (args: Voucher as stored, see CONNECTORS.md "Event timing"). */
			Db::action( 'vcap_voucher_updated', $voucher );
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
		self::forget();
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
				Db::action( 'vcap_voucher_updated', $fresh );
			}
		}
		return $ok;
	}

	/** Mark a single-use voucher redeemed only if it is still active (atomic; false when someone else won). */
	public function redeem_single_if_active( int $id ): bool {
		global $wpdb;
		self::forget();
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = %s, balance = 0, updated_at = %s WHERE id = %d AND status = %s", Voucher::STATUS_REDEEMED, $this->now(), $id, Voucher::STATUS_ACTIVE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 === $done ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				Db::action( 'vcap_voucher_updated', $fresh );
			}
		}
		return 1 === $done;
	}

	/**
	 * M12-22: give a redeemed single-use voucher its use back – only if it is still redeemed (atomic; false when
	 * it was voided, given back or changed otherwise in the meantime). Its balance becomes its face value again.
	 *
	 * @param string $status Status it gets (active, or expired when past its validity).
	 */
	public function restore_single_if_redeemed( int $id, string $status ): bool {
		global $wpdb;
		self::forget();
		$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = %s, balance = initial_amount, updated_at = %s WHERE id = %d AND status = %s", $status, $this->now(), $id, Voucher::STATUS_REDEEMED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 === $done ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				Db::action( 'vcap_voucher_updated', $fresh );
			}
		}
		return 1 === $done;
	}

	/** Change only the status column (never overwrites a balance changed concurrently by a transaction). */
	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		self::forget();
		$ok = false !== $wpdb->update( $this->table(), array( 'status' => $status, 'updated_at' => $this->now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok ) {
			$fresh = $this->get( $id );
			if ( $fresh ) {
				Db::action( 'vcap_voucher_updated', $fresh );
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
		self::forget();
		$voucher = $this->get( $id );
		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->events_table(), array( 'voucher_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $voucher ) {
			/**
			 * A voucher was removed again (its issuing failed after "vcap_voucher_created").
			 *
			 * @param Voucher $voucher The voucher as it was stored.
			 */
			Db::action( 'vcap_voucher_deleted', $voucher );
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

	/**
	 * M12-04: the voucher row read with its row lock (SELECT * … FOR UPDATE), for bookings inside a transaction.
	 * A locking read always sees the latest committed row; a plain get() after locking would read the snapshot of
	 * an outer transaction that read before (REPEATABLE READ), e.g. a connector calling reserve() in its own
	 * Db::transaction(), and book from a stale balance. Null when the row does not exist (or the read failed).
	 */
	public function get_for_update( int $id ): ?Voucher {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		Db::classic_locking_reads(); // MariaDB 11.6+: also inside a transaction another plugin opened.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d FOR UPDATE", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name of this plugin.
		return is_array( $row ) ? Voucher::from_row( $row ) : null;
	}

	public function get_by_code( string $code ): ?Voucher {
		return '' !== $code ? $this->memo_get( 'code', $code ) : null;
	}

	public function get_by_token( string $token ): ?Voucher {
		return preg_match( '/^[a-f0-9]{48}$/', $token ) ? $this->get_by( 'token', $token ) : null;
	}

	public function get_by_coupon( int $coupon_id ): ?Voucher {
		return $coupon_id ? $this->memo_get( 'coupon_id', $coupon_id ) : null;
	}

	/** @var array<string, Voucher|null> AP-P1-5: request memo of get_by_code()/get_by_coupon(), misses included. */
	private static array $memo = array();

	/** Whether the listeners that forget the memo are registered. */
	private static bool $watching = false;

	/** Most memo entries kept (a long CLI run reading many vouchers must not grow it without end). */
	private const MEMO_MAX = 200;

	/**
	 * AP-P1-5: a cart calculation reads the same voucher by code or coupon 4–6 times (validation, sorting, the gross
	 * split, notices). Those reads are memoised for the request – misses too (ordinary coupons are looked up as
	 * well). Never inside a transaction: reads under a row lock (FOR UPDATE) and everything an atomic booking decides
	 * on always come from the database (get() is never memoised either). The memo is forgotten on every write of
	 * this repository, on every write query of the request (filter "query": also direct SQL of other classes), when
	 * a transaction opens, commits or rolls back (Db), and on "vcap_voucher_updated".
	 */
	private function memo_get( string $column, $value ): ?Voucher {
		if ( Db::in_transaction() ) {
			return $this->get_by( $column, $value );
		}
		$key = $this->table() . '|' . $column . '|' . $value;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ] ? clone self::$memo[ $key ] : null;
		}
		$voucher = $this->get_by( $column, $value );
		self::watch();
		if ( count( self::$memo ) >= self::MEMO_MAX ) {
			self::$memo = array();
		}
		self::$memo[ $key ] = $voucher ? clone $voucher : null;
		return $voucher;
	}

	/** Forgets the request memo of get_by_code()/get_by_coupon() (AP-P1-5). */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * Filter "query": any statement other than a plain SELECT (UPDATE, INSERT, DELETE, START TRANSACTION …) forgets
	 * the memo, so a voucher row changed by direct SQL is never read stale afterwards.
	 *
	 * @param string $query SQL.
	 */
	public static function forget_on_write( $query ) {
		if ( self::$memo && 0 !== strncasecmp( ltrim( (string) $query ), 'SELECT', 6 ) ) {
			self::$memo = array();
		}
		return $query;
	}

	private static function watch(): void {
		if ( self::$watching || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$watching = true;
		add_filter( 'query', array( self::class, 'forget_on_write' ), PHP_INT_MAX );
		add_action( 'vcap_voucher_updated', array( self::class, 'forget' ), 0 );
		add_action( 'vcap_voucher_deleted', array( self::class, 'forget' ), 0 );
		// Long-running workers (Action Scheduler, WP-CLI): each task starts with fresh reads.
		add_action( 'action_scheduler_before_execute', array( self::class, 'forget' ), 0 );
		add_action( 'switch_blog', array( self::class, 'forget' ), 0 );
	}

	public function get_slot( int $order_item_id, int $index ): ?Voucher {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_item_id = %d AND item_index = %d", $order_item_id, $index ), ARRAY_A ); // phpcs:ignore
		return $row ? Voucher::from_row( $row ) : null;
	}

	/**
	 * Item indexes of an order item that hold a voucher: one query for the whole line instead of one per slot (B4).
	 *
	 * @return array<int, true>
	 */
	public function taken_slots( int $order_item_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT item_index FROM {$this->table()} WHERE order_item_id = %d", $order_item_id ) ); // phpcs:ignore
		return array_fill_keys( array_map( 'intval', $rows ), true );
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

	/**
	 * Vouchers bought by a customer. Deliberately NOT matched by recipient email: WooCommerce account emails are
	 * unverified, so that would let anyone list gifts addressed to an email they merely claim.
	 *
	 * @return Voucher[]
	 */
	public function for_customer( int $user_id, int $limit = 200, int $offset = 0, string $verified_email = '' ): array {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return array();
		}
		// B15: a page of the list ($offset vouchers skipped, newest first).
		$offset = max( 0, $offset );
		$limit += $offset;
		// M12-18: guest orders placed with the account's verified address (PersonalVoucher::verified_email()) belong
		// to the buyer as well – only with a proven mailbox, never with a merely claimed account email.
		$guest = array();
		$ids   = is_email( $verified_email ) ? $this->guest_order_ids( $verified_email ) : array();
		if ( $ids ) {
			$in    = implode( ',', $ids );
			$guest = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id IN ($in) ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array(); // phpcs:ignore
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
			$batch = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id IN ($in) ORDER BY id DESC LIMIT %d", $limit - count( $rows ) ), ARRAY_A ); // phpcs:ignore
			$rows  = array_merge( $rows, $batch ?: array() );
			if ( count( $order_ids ) < 500 ) {
				break;
			}
		}
		if ( $guest ) {
			$rows = array_merge( $rows, $guest );
			usort( $rows, static fn( $a, $b ) => (int) $b['id'] <=> (int) $a['id'] );
			$rows = array_slice( $rows, 0, $limit );
		}
		return array_map( array( Voucher::class, 'from_row' ), array_slice( $rows, $offset ) );
	}

	/**
	 * M12-54: what an admin search term can mean besides names and addresses: the exact code in its typed or scanned
	 * forms (Tickets::code_candidates(): without separators, with spaces, from a link, Y/Z swapped), the start of a
	 * code without the "…" a masked code is shown with, the end of a code (4–6 letters/digits, e.g. "…M9XA" from an
	 * email or a receipt) and the orders of an order number ("#1234", also numbers of sequential-number plugins).
	 *
	 * @return array{codes: string[], prefix: string, plain: string, tail: string, order_ids: int[]}
	 */
	public static function search_terms( string $search ): array {
		$clean   = trim( str_replace( array( "\u{2026}", '...' ), '', $search ) );
		$compact = (string) preg_replace( '/\s+/u', '', $clean );
		$alnum   = (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $clean );
		$len     = function_exists( 'mb_strlen' ) ? mb_strlen( $alnum ) : strlen( $alnum );
		$terms   = array(
			'codes'     => '' !== $clean ? Tickets::code_candidates( $clean ) : array(),
			'prefix'    => wc_format_coupon_code( $compact ),
			// Letters and numbers only, from 6 characters (shorter ones are code ends or words).
			'plain'     => $len >= 6 ? wc_format_coupon_code( $alnum ) : '',
			// A code end: only when the term is short and was not a word of a name (it has a digit or was masked).
			'tail'      => $len >= 4 && $len <= 6 && ( preg_match( '/\d/', $alnum ) || $clean !== trim( $search ) ) ? wc_format_coupon_code( $alnum ) : '',
			'order_ids' => array(),
		);
		$number = ltrim( $compact, '#' );
		if ( '' !== $number && ctype_digit( $number ) && (int) $number > 0 ) {
			$ids = array( (int) $number );
			// Sequential order numbers (plugins answer the order tracking filter, or store "_order_number").
			$ids[] = (int) apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
			if ( class_exists( OrderLookup::class ) ) {
				$ids = array_merge( $ids, OrderLookup::ids_with_meta( '_order_number', $number, array( 'limit' => 20 ) ) );
			}
			$terms['order_ids'] = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		}
		return $terms;
	}

	/**
	 * M12-18: ids of guest orders (no customer account) whose vouchers were bought with this address (at most 500,
	 * newest first).
	 *
	 * @return int[]
	 */
	private function guest_order_ids( string $email ): array {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$this->table()} WHERE buyer_email = %s AND order_id > 0 ORDER BY order_id DESC LIMIT 500", $email ) ) ); // phpcs:ignore
		if ( ! $ids || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$guest = array();
		foreach ( (array) wc_get_orders( array( 'post__in' => $ids, 'limit' => count( $ids ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			if ( $order instanceof \WC_Order && 0 === (int) $order->get_customer_id() && 0 === strcasecmp( trim( (string) $order->get_billing_email() ), trim( $email ) ) ) {
				$guest[] = (int) $order->get_id();
			}
		}
		return $guest;
	}

	/**
	 * M12-18: vouchers addressed to a customer as gift recipient – by the account's verified address only
	 * (PersonalVoucher::verified_email(); an unproven account email would list gifts of a claimed address). Not the
	 * customer's own orders (listed as bought), no store credit and no gift that is still scheduled (a surprise is
	 * not spoiled before its delivery date). Newest first.
	 *
	 * @return Voucher[]
	 */
	public function received_by( string $verified_email, int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		if ( ! is_email( $verified_email ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE recipient_email = %s AND send_to IN ('recipient','both') AND type <> 'credit' AND status <> 'scheduled' AND buyer_email <> %s ORDER BY id DESC LIMIT %d OFFSET %d", $verified_email, $verified_email, max( 1, $limit ) + 50, max( 0, $offset ) ), ARRAY_A ) ?: array(); // phpcs:ignore
		$list = array_map( array( Voucher::class, 'from_row' ), $rows );
		Voucher::prime_orders( $list );
		$out = array();
		foreach ( $list as $voucher ) {
			$order = $voucher->order();
			if ( $user_id > 0 && $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id ) {
				continue; // Bought with the account: in the list of bought vouchers.
			}
			$out[] = $voucher;
			if ( count( $out ) >= max( 1, $limit ) ) {
				break;
			}
		}
		Voucher::forget_orders();
		return $out;
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
				// M12-54: the code also exactly in its typed or scanned forms (without separators, with spaces, as
				// a link), by its end ("…M9XA", 4–6 characters) and orders by their number (sequential numbers).
				$terms   = self::search_terms( $search );
				$like    = '%' . $wpdb->esc_like( $search ) . '%';
				$parts   = array( 'code LIKE %s', 'buyer_email LIKE %s', 'recipient_email LIKE %s', 'buyer_name LIKE %s', 'recipient_name LIKE %s' );
				array_push( $vals, $wpdb->esc_like( $terms['prefix'] ) . '%', $prefix, $prefix, $like, $like );
				if ( $terms['codes'] ) {
					$parts[] = 'code IN (' . implode( ',', array_fill( 0, count( $terms['codes'] ), '%s' ) ) . ')';
					array_push( $vals, ...$terms['codes'] );
				}
				if ( '' !== $terms['plain'] ) {
					// Typed without the separators the code is stored with ("ABCD1234" for "ABCD-1234").
					$parts[] = "REPLACE(REPLACE(REPLACE(code, '-', ''), '_', ''), '.', '') LIKE %s";
					$vals[]  = $wpdb->esc_like( $terms['plain'] ) . '%';
				}
				if ( '' !== $terms['tail'] ) {
					$parts[] = "REPLACE(REPLACE(REPLACE(code, '-', ''), '_', ''), '.', '') LIKE %s";
					$vals[]  = '%' . $wpdb->esc_like( $terms['tail'] );
				}
				if ( $terms['order_ids'] ) {
					$parts[] = 'order_id IN (' . implode( ',', array_map( 'intval', $terms['order_ids'] ) ) . ')';
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
			Ledger::flush_runtime_cache(); // Products and orders read for the rows are not kept for the whole export.
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
					ORDER BY ( SELECT MAX(f.id) FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event IN ('delivery_failed','delivery_attempt') ) IS NOT NULL,
					( SELECT MAX(f.id) FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event IN ('delivery_failed','delivery_attempt') ) ASC,
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
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event, created_at FROM {$this->events_table()} WHERE voucher_id = %d AND event IN ('delivery_failed','delivery_given_up','delivery_attempt','resent'," . self::DELIVERY_RESET_EVENTS . ') ORDER BY id DESC LIMIT 500', $voucher_id ), ARRAY_A ); // phpcs:ignore
		$state = array( 'failures' => 0, 'last_attempt' => null, 'given_up' => false );
		// M12-48: a "delivery_attempt" is written before an attempt renders and sends; its outcome (a failure, a
		// resend, the delivery) pairs it. An attempt without an outcome crashed and counts as a failure.
		$outcome = false;
		foreach ( $rows ? $rows : array() as $row ) {
			if ( 'delivery_attempt' === $row['event'] ) {
				if ( ! $outcome ) {
					++$state['failures'];
					$state['last_attempt'] = $state['last_attempt'] ?? (string) $row['created_at'];
				}
				$outcome = false;
			} elseif ( 'resent' === $row['event'] ) {
				$outcome = true;
			} elseif ( 'delivery_failed' === $row['event'] ) {
				++$state['failures'];
				$state['last_attempt'] = $state['last_attempt'] ?? (string) $row['created_at'];
				$outcome               = true;
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
	public function due_for_expiry_reminder( int $days, int $limit = 200, array $exclude = array(), int $after_days = 0 ): array {
		global $wpdb;
		$exclude = array_values( array_filter( array_map( 'intval', $exclude ) ) );
		$not_in  = $exclude ? ' AND v.id NOT IN (' . implode( ',', $exclude ) . ')' : '';
		$rows    = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT v.* FROM {$this->table()} v
				WHERE v.status = 'active' AND v.expires_at > %s AND v.expires_at <= %s
				AND v.delivered_at IS NOT NULL AND v.type <> 'ticket'
				AND ( v.usage_mode <> 'balance' OR v.balance > 0 ){$not_in}
				AND NOT EXISTS ( SELECT 1 FROM {$this->events_table()} e WHERE e.voucher_id = v.id AND e.event = 'expiry_reminder' AND ( e.note = %s OR e.note IS NULL OR LEFT( e.note, 12 ) <> 'days_before=' ) )
				AND NOT EXISTS ( SELECT 1 FROM {$this->events_table()} f WHERE f.voucher_id = v.id AND f.event = 'delivery_failed' AND f.created_at > %s )
				ORDER BY v.expires_at ASC, v.id ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() + max( 0, $after_days ) * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				'days_before=' . $days,
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
					// M12-29: the value lost at the expiry is booked (journal); TaxAccounting books it back if it becomes valid again.
					$this->log( (int) $voucher->id, 'expired', TaxAccounting::expiry_amount( $voucher ), (float) $voucher->balance, Note::make( 'expired' ) );
					Db::action( 'vcap_voucher_updated', $voucher );
				}
				if ( $expired ) {
					/**
					 * Vouchers whose validity ended were marked as expired (one call per batch of the daily maintenance).
					 *
					 * @param Voucher[] $expired
					 */
					Db::action_ref_array( 'vcap_voucher_expired', array( $expired ) ); // Not do_action(): one voucher would be unwrapped from the array.
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
		 *                    committed_at (UTC), source (see Source), till and receipt (counter redemptions, else '').
		 */
		$counter = 'counter' === $channel ? Counter::context() : null;
		Db::action(
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
				'till'         => $counter ? $counter['till'] : '',
				'receipt'      => $counter ? $counter['receipt'] : '',
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
			// AP-S3-1/AP-S3-2: the copies outside the voucher row first (a failure below leaves the row to be retried,
			// and these steps are repeatable): coupon restriction and guest "used by" addresses, order line copies.
			$copies = (array) $wpdb->get_results( $wpdb->prepare( "SELECT v.id, v.coupon_id, v.order_item_id FROM {$this->table()} v WHERE v.id IN ({$in}) AND {$eligibility}", $cutoff, $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- integer ids and constant SQL (the placeholders are in $eligibility).
			foreach ( $copies as $copy ) {
				self::erase_coupon_addresses( (int) $copy['coupon_id'], (int) $copy['id'] );
				Fields::erase_item_data( Fields::voucher_item( (int) $copy['order_item_id'] ), 'all' );
			}
			if ( $transaction && ! Db::begin() ) {
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
				if ( false === $updated || ( $transaction && ! Db::commit() ) ) {
					throw new \RuntimeException( 'Retention: personal data could not be removed: ' . esc_html( $wpdb->last_error ) );
				}
			} catch ( \Throwable $e ) {
				if ( $transaction ) {
					Db::rollback_silently();
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
	 * AP-S3-2: retention of a voucher's coupon – an email restriction is replaced by the placeholder the privacy
	 * eraser uses (an address nobody can have: a personal voucher must not become usable by anyone), and the
	 * addresses of guests in "used by" (WooCommerce records a user id or the billing email per use) by the same
	 * placeholder, so the per-customer usage count stays as it was.
	 */
	public static function erase_coupon_addresses( int $coupon_id, int $voucher_id ): void {
		if ( $coupon_id <= 0 || ! class_exists( '\WC_Coupon' ) ) {
			return;
		}
		$coupon = new \WC_Coupon( $coupon_id );
		if ( ! $coupon->get_id() ) {
			return;
		}
		$placeholder  = self::erased_address( $voucher_id );
		$restrictions = array_values( array_filter( (array) $coupon->get_email_restrictions() ) );
		if ( $restrictions && array( $placeholder ) !== $restrictions ) {
			$coupon->set_email_restrictions( array( $placeholder ) );
			$coupon->save();
		}
		foreach ( array_unique( array_map( 'strval', (array) get_post_meta( $coupon_id, '_used_by', false ) ) ) as $used_by ) {
			if ( $used_by !== $placeholder && is_email( $used_by ) ) {
				update_post_meta( $coupon_id, '_used_by', $placeholder, $used_by ); // Every row of that address.
			}
		}
	}

	/** The address a personal voucher's coupon is restricted to once its holder's data is erased (eraser, retention). */
	public static function erased_address( int $voucher_id ): string {
		return 'erased-' . $voucher_id . '@invalid.invalid';
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

	/**
	 * GDPR erasure of a voucher's history (M12-66, GoBD): only free-text notes are replaced – they may hold names or
	 * addresses typed by staff or written by older versions. Coded notes (Note::make(): correction reasons,
	 * delivery results, VAT snapshot) carry no free personal data and are kept, as are amounts and dates. The
	 * erasure itself is logged (TaxAccounting::ERASED_EVENT with the number of notes replaced).
	 */
	public function anonymise_event_notes( int $voucher_id ): bool {
		global $wpdb;
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$this->events_table()} SET note = '[personal data removed]', actor_email_hash = '' WHERE voucher_id = %d AND note <> '' AND note <> '[personal data removed]' AND note NOT LIKE %s",
				$voucher_id,
				$wpdb->esc_like( Note::PREFIX ) . '%'
			)
		);
		if ( false === $changed ) {
			return false;
		}
		// The hash of an address found in a note is personal data of its own (coded notes keep their text).
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$this->events_table()} SET actor_email_hash = '' WHERE voucher_id = %d AND actor_email_hash <> ''", $voucher_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}
		return $this->log( $voucher_id, TaxAccounting::ERASED_EVENT, null, null, Note::make( 'privacy_erased', array( (int) $changed ) ) );
	}

	/**
	 * Writes a history entry. $note is free text or a coded note (Note::make(), translated when shown). The
	 * source of the change (Source::current(): admin, cron, rest, scanner, checkout, cli, web) is stored with it.
	 */
	public function log( int $voucher_id, string $event, ?float $amount = null, ?float $balance_after = null, string $note = '', int $order_id = 0 ): bool {
		global $wpdb;
		self::forget(); // Nearly every change of a voucher row (also by direct SQL elsewhere) is logged right after it.
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
		$source = Source::current();
		if ( Install::event_source_ready() ) {
			$row['source'] = $source;
		}
		// R6: till/branch and receipt number of a counter redemption and its cancellation (Counter::with()).
		$counter = Counter::context();
		// M12-78/M12-46: check-ins (and their reverts) and POS bookings over the API (meta.till/receipt) keep them too.
		if ( $counter && in_array( $event, array( 'redeemed', 'redeem_reversed', 'checkin', 'checkin_undo', 'api_committed', 'api_refunded' ), true ) && 0 === $order_id && Install::event_till_ready() ) {
			$row['till']    = $counter['till'];
			$row['receipt'] = $counter['receipt'];
		}
		$inserted = false !== $wpdb->insert( $this->events_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $inserted ) {
			// Listeners get the note as text (a coded note in the site language).
			/**
			 * A history entry was written (args: voucher id, event, amount, balance after, note as text, order id,
			 * source (Source::current(): admin, cron, rest, scanner, checkout, cli, web), acting user id (0 = none)).
			 * The last two were added later: register with 8 accepted args to get them.
			 */
			Db::action( 'vcap_voucher_event', $voucher_id, $event, $amount, $balance_after, Note::render( $note ), $order_id, $source, (int) $row['user_id'] );
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

	/** Number of ledger rows of one kind of a voucher (e.g. "reissued": the link version, see Downloads). */
	public function count_event( int $voucher_id, string $event ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->events_table()} WHERE voucher_id = %d AND event = %s", $voucher_id, $event ) ); // phpcs:ignore
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
			'reissued'   => __( 'Reissued with a new code', 'voucher-captain' ),
			'imported'   => __( 'Imported (existing card)', 'voucher-captain' ),
			'moved_to_credit' => __( 'Moved to store credit', 'voucher-captain' ),
			'activated'  => __( 'Activated', 'voucher-captain' ),
			Downloads::FIX_EVENT => __( 'Recipient corrected by the buyer', 'voucher-captain' ),
			Delivery::ATTEMPT_EVENT => __( 'Delivery attempt', 'voucher-captain' ),
			Emails::USED_NOTICE_EVENT => __( 'Buyer told that the gift was used', 'voucher-captain' ),
		);
		/** Labels of connector events in the voucher history. */
		return (array) apply_filters( 'vcap_event_labels', $labels );
	}
}
