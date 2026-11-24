<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Accounting views of the voucher ledger (events table):
 *
 * - liability( $date ): outstanding voucher value at the end of a day – gift cards and store credit with their
 *   balance then plus the holds still open (reserved for unpaid orders or external redemptions), single-use value vouchers with their value while not yet redeemed or voided. Expired value is
 *   listed separately. Percentage and ticket vouchers carry no monetary liability and are left out.
 * - journal( $from, $to ): every booking that moved value (redemptions, refunds, credits, adjustments) in a period.
 *
 * Dates are the shop's local days; the ledger stores UTC. CSV exports are streamed (no row limit).
 */
final class Ledger {
	/** Events that move monetary value. */
	public const MONEY_EVENTS = array( 'issued', 'redeemed', 'committed', 'restored', 'adjusted', 'credit', 'debit', 'refund_credit', 'refund_credit_reversed', 'api_committed', 'api_refunded', 'passcaptain_tickets_restored', 'topup', 'topup_reversed', 'redeem_reversed', CouponManager::WRITTEN_OFF_EVENT, 'expired', TaxAccounting::EXPIRY_REVERSED_EVENT, TaxAccounting::FORFEITED_EVENT, TaxAccounting::REST_CONVERTED_EVENT, 'moved_to_credit' );
	/** Events after which a single-use voucher no longer carries value (until restored or reactivated). */
	private const USED_EVENTS = array( 'redeemed', 'committed', 'api_committed', 'voided', TaxAccounting::FORFEITED_EVENT, TaxAccounting::REST_CONVERTED_EVENT );
	private const BACK_EVENTS = array( 'restored', 'api_refunded', 'reactivated', 'passcaptain_tickets_restored', 'redeem_reversed' );
	/**
	 * U6: bookings that take value off a voucher (shown negative in the journal); 'adjusted' carries its own sign.
	 * M12-29: the value lost at the expiry ('expired', booked back by 'expiry_reversed') and the rest of a single-use
	 * voucher its order did not use ('forfeited', or 'rest_converted' into store credit / a new gift card).
	 */
	public const OUT_EVENTS = array( 'redeemed', 'committed', 'debit', 'refund_credit_reversed', 'api_committed', 'topup_reversed', CouponManager::WRITTEN_OFF_EVENT, 'expired', TaxAccounting::FORFEITED_EVENT, TaxAccounting::REST_CONVERTED_EVENT, 'moved_to_credit' );
	/**
	 * Vouchers whose bookings are money (same set as the liability): the "amount" of a percentage voucher is its
	 * percentage, a reusable discount code's use or a ticket's check-in moves no value – they stay in the voucher
	 * history but never appear in the journal as redeemed money.
	 */
	public const VALUE_VOUCHERS = "v.discount_type <> 'percent' AND ( v.type <> 'ticket' OR v.initial_amount > 0 ) AND v.usage_mode IN ('single','balance')";
	/**
	 * Holds on a stored balance: they lower balance_after but the value is still owed until the hold is charged.
	 * Each hold event is followed by at most one closing event with the same amount, so the open holds at a point
	 * in time are the sum of the holds minus the sum of the closings up to then.
	 */
	private const HOLD_EVENTS  = array( 'reserved', 'api_reserved' );
	private const CLOSE_EVENTS = array( 'committed', 'reservation_released', 'api_committed', 'api_released' );

	public function __construct( private VoucherRepository $repo ) {}

	/** UTC bounds of local days. @return array{0:string,1:string} */
	public static function utc_range( string $from, string $to ): array {
		return array( get_gmt_from_date( $from . ' 00:00:00' ), get_gmt_from_date( $to . ' 23:59:59' ) );
	}

	/**
	 * Outstanding value at the end of $date (Y-m-d, local), summed in the database (no voucher is loaded into
	 * memory). The per-voucher detail is liability_rows().
	 *
	 * M12-42: free_count/free_amount are the part of count/amount issued without a sale (goodwill, prizes:
	 * issue_standalone() without "paid") - no consideration was received for them.
	 *
	 * @return array{totals: array<string, array<string, array{count:int,amount:float,expired_count:int,expired_amount:float,free_count:int,free_amount:float}>>}
	 */
	public function liability( string $date ): array {
		global $wpdb;
		$t     = Install::tables();
		$until = self::utc_range( $date, $date )[1];
		// Cached per day: every change of value or validity writes a ledger row (edits included), so the newest
		// ledger and voucher ids are part of the key.
		$state     = $wpdb->get_row( $wpdb->prepare( 'SELECT ( SELECT MAX(id) FROM %i ) AS e, ( SELECT MAX(id) FROM %i ) AS v', $t['events'], $t['vouchers'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cache_key = 'vcap_liability_v2_' . md5( (string) wp_json_encode( array( $until, $state, get_option( 'woocommerce_currency' ), ExpiryPolicy::sql_cutoff( $until ) ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['totals'] ) ) {
			return $cached;
		}
		$used  = self::in_list( self::USED_EVENTS );
		$both  = self::in_list( array_merge( self::USED_EVENTS, self::BACK_EVENTS ) );
		$holds = self::in_list( self::HOLD_EVENTS );
		$all   = self::in_list( array_merge( self::HOLD_EVENTS, self::CLOSE_EVENTS ) );
		$value = self::VALUE_VOUCHERS;
		// Same rules as value_at(): voided (until reactivated) = 0; gift cards: last balance at that time plus the
		// holds still open then (reserved for an unpaid order or an external redemption = still owed);
		// single-use: full value unless the last use/give-back event was a use.
		$free = self::free_sql();
		$sql  = "SELECT currency, type, expired, free, COUNT(*) AS cnt, SUM(outstanding) AS amount FROM (
				SELECT v.currency, v.type, ( v.expires_at IS NOT NULL AND v.expires_at <= %s ) AS expired, {$free} AS free,
					CASE
						WHEN 'voided' = ( SELECT e.event FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.event IN ('voided','reactivated') ORDER BY e.id DESC LIMIT 1 ) THEN 0
						WHEN v.usage_mode = 'balance' THEN GREATEST( 0, COALESCE( ( SELECT e.balance_after FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.balance_after IS NOT NULL ORDER BY e.id DESC LIMIT 1 ), v.initial_amount ) )
							+ GREATEST( 0, COALESCE( ( SELECT SUM( CASE WHEN e.event IN ({$holds}) THEN e.amount ELSE -e.amount END ) FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.event IN ({$all}) AND e.amount IS NOT NULL ), 0 ) )
						WHEN ( SELECT e.event FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.event IN ({$both}) ORDER BY e.id DESC LIMIT 1 ) IN ({$used}) THEN 0
						ELSE v.initial_amount
					END AS outstanding
				FROM {$t['vouchers']} v
				WHERE v.created_at <= %s AND {$value}
			) x WHERE outstanding > 0.00001 GROUP BY currency, type, expired, free";
		// SET-VC-06: a voucher in its grace period (or with "never expires") is still open, not expired.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ExpiryPolicy::sql_cutoff( $until ), self::free_pattern(), $until, $until, $until, $until, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		/** @var array<string, array<string, array{count:int,amount:float,expired_count:int,expired_amount:float,free_count:int,free_amount:float}>> $totals */
		$totals = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$currency = '' !== (string) $r['currency'] ? (string) $r['currency'] : (string) get_option( 'woocommerce_currency' );
			$expired  = (bool) (int) $r['expired'];
			$type     = (string) $r['type'];
			if ( ! isset( $totals[ $currency ][ $type ] ) ) {
				$totals[ $currency ][ $type ] = array( 'count' => 0, 'amount' => 0.0, 'expired_count' => 0, 'expired_amount' => 0.0, 'free_count' => 0, 'free_amount' => 0.0 );
			}
			$bucket = &$totals[ $currency ][ $type ];
			$bucket[ $expired ? 'expired_count' : 'count' ] += (int) $r['cnt'];
			$bucket[ $expired ? 'expired_amount' : 'amount' ] = round( $bucket[ $expired ? 'expired_amount' : 'amount' ] + (float) $r['amount'], API\V1\RedemptionService::decimals( $currency ) );
			if ( ! $expired && (int) $r['free'] ) {
				$bucket['free_count'] += (int) $r['cnt'];
				$bucket['free_amount'] = round( $bucket['free_amount'] + (float) $r['amount'], API\V1\RedemptionService::decimals( $currency ) );
			}
			unset( $bucket );
		}
		set_transient( $cache_key, array( 'totals' => $totals ), HOUR_IN_SECONDS );
		return array( 'totals' => $totals );
	}

	/**
	 * Vouchers with outstanding value at the end of $date, one row each, read in batches of 500.
	 *
	 * @return \Generator<array{code:string,type:string,currency:string,issued:string,expires:string,order_id:int,outstanding:float,expired:bool,decimals:int,sold:bool}>
	 */
	public function liability_rows( string $date ): \Generator {
		global $wpdb;
		$tables = Install::tables();
		$until  = self::utc_range( $date, $date )[1];
		$cutoff = ExpiryPolicy::sql_cutoff( $until ); // SET-VC-06: grace period / never expires.
		$last   = 0;
		$value  = self::VALUE_VOUCHERS;
		do {
			$vouchers = $wpdb->get_results( $wpdb->prepare( "SELECT v.* FROM %i v WHERE v.id > %d AND v.created_at <= %s AND {$value} ORDER BY v.id LIMIT 500", $tables['vouchers'], $last, $until ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $vouchers ) {
				break;
			}
			$ids    = array_map( 'intval', array_column( $vouchers, 'id' ) );
			$last   = end( $ids );
			$events = array();
			$in     = implode( ',', $ids );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT voucher_id, event, amount, balance_after FROM {$tables['events']} WHERE voucher_id IN ({$in}) AND created_at <= %s ORDER BY id", $until ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$events[ (int) $e['voucher_id'] ][] = $e;
			}
			Format::prime_order_numbers( array_column( $vouchers, 'order_id' ) ); // AP-P1-2: one order query per batch.
			foreach ( $vouchers as $row ) {
				$v     = Voucher::from_row( $row );
				$outstanding = $this->value_at( $v, $events[ $v->id ] ?? array() );
				if ( $outstanding <= 0.00001 ) {
					continue;
				}
				yield array(
					'code'        => $v->code,
					'type'        => $v->type,
					'currency'    => $v->currency ?: (string) get_option( 'woocommerce_currency' ),
					'issued'      => $v->created_at,
					'expires'     => (string) $v->expires_at,
					'order_id'    => $v->order_id,
					'outstanding' => $outstanding,
					'expired'     => $v->expires_at && $v->expires_at <= $cutoff,
					'decimals'    => $v->decimals(),
					// M12-42: issued without a sale (goodwill, prize) = false; unknown (older vouchers) counts as sold.
					'sold'        => false !== ProductConfig::is_sold( $v ),
				);
			}
			unset( $events );
			self::flush_runtime_cache();
			$more = count( $vouchers ) === 500;
		} while ( $more );
	}

	/** Attention kind prefix of a balance that differs from its ledger (M12-63), followed by the voucher id. */
	public const VERIFY_KIND = 'ledger-';

	/** Balance vouchers compared per query of verify(). */
	private const VERIFY_BATCH = 1000;

	/**
	 * M12-63: reconciles every stored balance (gift cards, store credit) with its ledger. Each booking writes the
	 * new balance and a ledger row with balance_after in one transaction, so the balance stored on the voucher must
	 * equal the balance_after of its newest ledger row that carries one (the face value while there is none – the
	 * same reading as liability()). A difference means the balance was written without a booking (direct SQL, a
	 * lost update, an import) and the liability report no longer matches the cards.
	 *
	 * Read in batches through the primary key, until done or $budget seconds are used (0 = no limit).
	 *
	 * @return array{rows: list<array{voucher_id:int,code:string,currency:string,balance:float,ledger:float,event_id:int}>, checked:int, complete:bool}
	 */
	public static function verify( float $budget = 0.0 ): array {
		global $wpdb;
		$t        = Install::tables();
		$start    = microtime( true );
		$after    = 0;
		$checked  = 0;
		$rows     = array();
		$complete = true;
		do {
			$last  = "( SELECT e.%s FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.balance_after IS NOT NULL ORDER BY e.id DESC LIMIT 1 )";
			$sql   = 'SELECT v.id, v.code, v.currency, v.balance, v.initial_amount, ' . sprintf( $last, 'id' ) . ' AS event_id, ' . sprintf( $last, 'balance_after' ) . " AS ledger FROM {$t['vouchers']} v WHERE v.id > %d AND v.usage_mode = 'balance' ORDER BY v.id LIMIT %d";
			$batch = $wpdb->get_results( $wpdb->prepare( $sql, $after, self::VERIFY_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table names of this plugin, values as placeholders.
			$batch = is_array( $batch ) ? $batch : array();
			$size  = count( $batch );
			$after = $batch ? (int) end( $batch )['id'] : $after;
			foreach ( $batch as $r ) {
				++$checked;
				$event    = (int) $r['event_id'];
				$ledger   = null !== $r['ledger'] ? (float) $r['ledger'] : (float) $r['initial_amount'];
				$currency = '' !== (string) $r['currency'] ? (string) $r['currency'] : (string) get_option( 'woocommerce_currency' );
				$decimals = API\V1\RedemptionService::decimals( $currency );
				if ( Coupons\Balances::minor_units( (float) $r['balance'], $decimals ) !== Coupons\Balances::minor_units( $ledger, $decimals ) ) {
					$rows[] = array(
						'voucher_id' => (int) $r['id'],
						'code'       => (string) $r['code'],
						'currency'   => $currency,
						'balance'    => round( (float) $r['balance'], $decimals ),
						'ledger'     => round( $ledger, $decimals ),
						'event_id'   => $event,
					);
				}
			}
			if ( self::VERIFY_BATCH === $size && $budget > 0 && microtime( true ) - $start >= $budget ) {
				$complete = false;
				break;
			}
		} while ( self::VERIFY_BATCH === $size );
		return array( 'rows' => $rows, 'checked' => $checked, 'complete' => $complete );
	}

	/**
	 * M12-63: verify() with its findings kept as Attention cases (one per voucher, order 0, kind "ledger-{id}"); a
	 * case whose voucher matches its ledger again is resolved when the whole table was checked.
	 *
	 * @return array{rows: list<array{voucher_id:int,code:string,currency:string,balance:float,ledger:float,event_id:int}>, checked:int, complete:bool}
	 */
	public static function reconcile( float $budget = 0.0 ): array {
		$result = self::verify( $budget );
		$found  = array();
		foreach ( $result['rows'] as $row ) {
			$kind           = self::VERIFY_KIND . $row['voucher_id'];
			$found[ $kind ] = true;
			$decimals       = API\V1\RedemptionService::decimals( $row['currency'] );
			Attention::add(
				0,
				$kind,
				sprintf(
					/* translators: 1: voucher code, 2: stored balance, 3: balance after the last ledger entry, 4: currency code */
					__( 'Voucher %1$s: the stored balance (%2$s %4$s) differs from its history (%3$s %4$s after the last booking). Check its history and correct the balance; wp vcap ledger verify lists every difference.', 'voucher-captain' ),
					$row['code'],
					number_format( $row['balance'], $decimals, '.', '' ),
					number_format( $row['ledger'], $decimals, '.', '' ),
					$row['currency']
				)
			);
		}
		if ( $result['complete'] ) {
			foreach ( Attention::all() as $case ) {
				if ( 0 === (int) $case['order_id'] && str_starts_with( (string) $case['kind'], self::VERIFY_KIND ) && ! isset( $found[ (string) $case['kind'] ] ) ) {
					Attention::resolve( 0, (string) $case['kind'] );
				}
			}
		}
		return $result;
	}

	/**
	 * M12-42: SQL condition (voucher alias v) of a voucher issued without a sale: no order, no store credit account
	 * and its "issued" entry records "not sold" (ProductConfig::parse_snapshot()). One placeholder: free_pattern().
	 */
	private static function free_sql(): string {
		$events = Install::tables()['events'];
		return "( v.order_id = 0 AND v.order_item_id = 0 AND v.type <> 'credit' AND COALESCE( ( SELECT i.note FROM {$events} i WHERE i.voucher_id = v.id AND i.event = 'issued' ORDER BY i.id LIMIT 1 ), '' ) LIKE %s )";
	}

	/** LIKE pattern of free_sql(): a standalone "issued" note whose 4th argument (sold) is 0. */
	private static function free_pattern(): string {
		global $wpdb;
		return '%' . $wpdb->esc_like( '"c":"' . Issuer::STANDALONE_NOTE . '"' ) . '%' . $wpdb->esc_like( ',0,"' ) . '%'; // 4th argument (sold) 0, followed by the reason (M12-19).
	}

	/** SQL list of quoted event names. */
	private static function in_list( array $events ): string {
		return implode( ',', array_map( static fn( $e ) => "'" . esc_sql( (string) $e ) . "'", $events ) );
	}

	/** Value a voucher carried after the given events (in order). */
	private function value_at( Voucher $v, array $events ): float {
		$status = null;
		foreach ( $events as $e ) {
			if ( 'voided' === $e['event'] ) {
				$status = 'void';
			} elseif ( 'reactivated' === $e['event'] ) {
				$status = null;
			}
		}
		if ( 'void' === $status ) {
			return 0.0;
		}
		if ( $v->is_multi_use() ) {
			$balance = null;
			$held    = 0.0;
			foreach ( $events as $e ) {
				if ( null !== $e['balance_after'] ) {
					$balance = (float) $e['balance_after'];
				}
				if ( isset( $e['amount'] ) && null !== $e['amount'] ) {
					if ( in_array( $e['event'], self::HOLD_EVENTS, true ) ) {
						$held += (float) $e['amount'];
					} elseif ( in_array( $e['event'], self::CLOSE_EVENTS, true ) ) {
						$held -= (float) $e['amount'];
					}
				}
			}
			return round( max( 0.0, null === $balance ? (float) $v->initial_amount : $balance ) + max( 0.0, $held ), $v->decimals() );
		}
		$used = false;
		foreach ( $events as $e ) {
			if ( in_array( $e['event'], self::USED_EVENTS, true ) ) {
				$used = true;
			} elseif ( in_array( $e['event'], self::BACK_EVENTS, true ) ) {
				$used = false;
			}
		}
		return $used ? 0.0 : (float) $v->initial_amount;
	}

	/**
	 * Bookings that moved value between $from and $to (local days), oldest first.
	 *
	 * @param int $after_id Only bookings after this ledger id (paging).
	 * @return array<int, array<string, mixed>>
	 */
	public function journal( string $from, string $to, int $limit = 50000, int $after_id = 0, ?array $bounds = null ): array {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		// The id range of the period (from the created_at index, once per export), so each batch is a primary-key
		// range read in order instead of a filesort of the whole period.
		list( $lo, $hi ) = $bounds ?? $this->journal_bounds( $from, $to );
		if ( $hi <= 0 || $hi <= $after_id ) {
			return array();
		}
		$events = self::in_list( (array) apply_filters( 'vcap_ledger_money_events', self::MONEY_EVENTS ) );
		$value_vouchers = self::VALUE_VOUCHERS;
		// R6: till/branch and receipt number of counter bookings (columns of schema 1.8.0; '' before).
		$till = Install::event_till_ready() ? 'e.till, e.receipt' : "'' AS till, '' AS receipt";
		// M12-29: the voucher columns for its VAT treatment and the "issued" entry that holds the treatment at issue.
		return $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.created_at, e.event, e.amount, e.balance_after, e.order_id, e.note, {$till}, v.code, v.type, v.currency, v.id AS voucher_id, v.product_id, v.variation_id, v.order_id AS voucher_order_id, v.order_item_id, v.usage_mode, v.discount_type, v.initial_amount, ( SELECT i.note FROM {$tables['events']} i WHERE i.voucher_id = v.id AND i.event = 'issued' ORDER BY i.id LIMIT 1 ) AS issued_note FROM {$tables['events']} e INNER JOIN {$tables['vouchers']} v ON v.id = e.voucher_id WHERE e.id > %d AND e.id <= %d AND e.created_at BETWEEN %s AND %s AND e.event IN ({$events}) AND e.amount IS NOT NULL AND {$value_vouchers} ORDER BY e.id LIMIT %d", max( $lo - 1, $after_id, 0 ), $hi, $start, $end, max( 1, $limit ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Lowest and highest ledger id written in the period (local days), read from the created_at index.
	 *
	 * @return array{0:int, 1:int} [0, 0] when the period has no rows.
	 */
	public function journal_bounds( string $from, string $to ): array {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(id) AS lo, MAX(id) AS hi FROM %i WHERE created_at BETWEEN %s AND %s', $tables['events'], $start, $end ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array( (int) ( $row['lo'] ?? 0 ), (int) ( $row['hi'] ?? 0 ) );
	}

	/** Number of bookings in the period (shown when the page lists only the first ones). */
	public function journal_count( string $from, string $to ): int {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$events = self::in_list( (array) apply_filters( 'vcap_ledger_money_events', self::MONEY_EVENTS ) );
		$value_vouchers = self::VALUE_VOUCHERS;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['events']} e INNER JOIN {$tables['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at BETWEEN %s AND %s AND e.event IN ({$events}) AND e.amount IS NOT NULL AND {$value_vouchers}", $start, $end ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every booking of the period, read in batches (for the CSV export – no row limit).
	 *
	 * @return \Generator<array<string, mixed>>
	 */
	public function journal_all( string $from, string $to, int $batch = 2000 ): \Generator {
		$after  = 0;
		$bounds = $this->journal_bounds( $from, $to );
		do {
			$rows = $this->journal( $from, $to, $batch, $after, $bounds );
			// AP-P1-2: one order query per batch for the order numbers; nothing read for the rows is kept.
			Format::prime_order_numbers( array_column( $rows, 'order_id' ) );
			foreach ( $rows as $row ) {
				yield $row;
			}
			$after = $rows ? (int) end( $rows )['id'] : 0;
			self::flush_runtime_cache();
			$more = count( $rows ) === $batch;
		} while ( $more );
	}

	/**
	 * CSV rows of the journal export: header, every booking of the period, "End of export: N" (AP-P1-2).
	 *
	 * @return \Generator<array>
	 */
	public function journal_export( string $from, string $to, int $batch = 2000 ): \Generator {
		$accounts = AccountingExport::journal_accounts(); // SET-VC-20: account columns only when switched on.
		yield self::journal_header( $accounts );
		$written = 0;
		foreach ( $this->journal_all( $from, $to, $batch ) as $row ) {
			yield self::journal_line( $row, $accounts );
			++$written;
		}
		yield Csv::end_row( $written );
	}

	/**
	 * CSV rows of the liability export: header, one line per voucher with value, "End of export: N" (AP-P1-2).
	 *
	 * @return \Generator<array>
	 */
	public function liability_export( string $date ): \Generator {
		return Csv::with_end_row( self::liability_csv( $this->liability_rows( $date ), $date ) );
	}

	/**
	 * CSV rows of the counter export: header, the totals, "End of export: N" (AP-P1-2).
	 *
	 * @return \Generator<array>
	 */
	public function counter_export( string $from, string $to, string $group = 'till' ): \Generator {
		yield self::counter_header( $group );
		$rows = $this->counter_totals( $from, $to, $group );
		foreach ( $rows as $row ) {
			yield self::counter_line( $row, $group );
		}
		yield Csv::end_row( count( $rows ) );
	}

	/** @var int Runtime cache flushes of exports (tests: memory stays flat on large exports). */
	public static int $flushes = 0;

	/**
	 * After an export batch: orders, products and options read for its rows are not kept for the whole export
	 * (used by every export: journal, vouchers, coupons of a promotion). Object cache drop-ins without support for
	 * flush_runtime would only raise a notice there, so: runtime flush where supported, a full flush of the plain
	 * in-memory cache otherwise, nothing for a persistent cache that cannot flush only the runtime (M12-88).
	 */
	public static function flush_runtime_cache(): void {
		$mode = self::runtime_flush_mode(
			function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ),
			(bool) wp_using_ext_object_cache()
		);
		if ( 'runtime' === $mode ) {
			wp_cache_flush_runtime();
		} elseif ( 'full' === $mode ) {
			wp_cache_flush();
		}
		++self::$flushes;
	}

	/** How flush_runtime_cache() empties the cache: 'runtime', 'full' (non-persistent cache only) or 'none'. */
	public static function runtime_flush_mode( bool $supports_runtime, bool $external_cache ): string {
		if ( $supports_runtime ) {
			return 'runtime';
		}
		return $external_cache ? 'none' : 'full';
	}

	/** R6: groupings of the counter report => ledger columns (vetted, interpolated into the query). */
	private const COUNTER_GROUPS = array(
		'till' => array( 'e.till' ),
		'user' => array( 'e.user_id' ),
		'both' => array( 'e.till', 'e.user_id' ),
	);

	/**
	 * R6: counter redemptions (scanner, till, "Mark as redeemed (store)": "redeemed" without an order) and their
	 * cancellations in a period (local days), per till/branch, staff member or both and per currency. Amounts are
	 * value vouchers only (as in the journal); percentage, ticket and reusable vouchers count as redemptions.
	 * Cancellations count at the till of the redemption they cancel, by the staff member who cancelled.
	 *
	 * @param string $group 'till', 'user' or 'both'.
	 * @return array<int, array{till:string, user_id:int, currency:string, count:int, amount:float, reversed_count:int, reversed_amount:float, net:float}>
	 */
	public function counter_totals( string $from, string $to, string $group = 'till' ): array {
		global $wpdb;
		$tables              = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$columns             = self::COUNTER_GROUPS[ $group ] ?? self::COUNTER_GROUPS['till'];
		if ( ! Install::event_till_ready() ) {
			$columns = array_values( array_diff( $columns, array( 'e.till' ) ) );
		}
		$by     = $columns ? implode( ', ', $columns ) . ', ' : '';
		$value  = self::VALUE_VOUCHERS;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT {$by}v.currency, SUM( e.event = 'redeemed' ) AS cnt, SUM( CASE WHEN e.event = 'redeemed' AND {$value} THEN e.amount ELSE 0 END ) AS amount, SUM( e.event = 'redeem_reversed' ) AS rcnt, SUM( CASE WHEN e.event = 'redeem_reversed' AND {$value} THEN e.amount ELSE 0 END ) AS ramount FROM {$tables['events']} e INNER JOIN {$tables['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at BETWEEN %s AND %s AND ( ( e.event = 'redeemed' AND e.order_id = 0 ) OR e.event = 'redeem_reversed' ) GROUP BY {$by}v.currency ORDER BY {$by}v.currency", $start, $end ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns from COUNTER_GROUPS, $value a constant.
		$totals = array();
		foreach ( $rows as $r ) {
			$currency = '' !== (string) $r['currency'] ? (string) $r['currency'] : (string) get_option( 'woocommerce_currency' );
			$decimals = API\V1\RedemptionService::decimals( $currency );
			$amount   = round( (float) $r['amount'], $decimals );
			$reversed = round( (float) $r['ramount'], $decimals );
			$totals[] = array(
				'till'            => (string) ( $r['till'] ?? '' ),
				'user_id'         => (int) ( $r['user_id'] ?? 0 ),
				'currency'        => $currency,
				'count'           => (int) $r['cnt'],
				'amount'          => $amount,
				'reversed_count'  => (int) $r['rcnt'],
				'reversed_amount' => $reversed,
				'net'             => round( $amount - $reversed, $decimals ),
			);
		}
		return $totals;
	}

	/** R6: column headers of the counter report CSV. */
	public static function counter_header( string $group = 'till' ): array {
		$head = array();
		if ( 'user' !== $group ) {
			$head[] = __( 'Till / branch', 'voucher-captain' );
		}
		if ( 'till' !== $group ) {
			$head[] = __( 'Staff member', 'voucher-captain' );
		}
		return array_merge( $head, array( __( 'Currency', 'voucher-captain' ), __( 'Redemptions', 'voucher-captain' ), __( 'Amount redeemed', 'voucher-captain' ), __( 'Cancellations', 'voucher-captain' ), __( 'Amount cancelled', 'voucher-captain' ), __( 'Net amount', 'voucher-captain' ) ) );
	}

	/** R6: one CSV line of counter_totals(). */
	public static function counter_line( array $r, string $group = 'till' ): array {
		$line = array();
		if ( 'user' !== $group ) {
			$line[] = '' !== $r['till'] ? $r['till'] : __( '(no till set)', 'voucher-captain' );
		}
		if ( 'till' !== $group ) {
			$line[] = self::staff_name( (int) $r['user_id'] );
		}
		$decimals = API\V1\RedemptionService::decimals( (string) $r['currency'] );
		return array_merge( $line, array( $r['currency'], $r['count'], Csv::number( $r['amount'], $decimals ), $r['reversed_count'], Csv::number( $r['reversed_amount'], $decimals ), Csv::number( $r['net'], $decimals ) ) );
	}

	/** Display name of a staff member of the counter report ("—" without, "#id" for a deleted account). */
	public static function staff_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '—';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/**
	 * U6: the booking amount with its direction – value taken off a voucher is negative, value added positive, so
	 * the journal column sums to the change of the outstanding value. The ledger stores redemptions etc. as
	 * positive amounts; "adjusted" is stored with its sign (older adjustments were stored unsigned and stay so).
	 */
	public static function signed_amount( string $event, $amount ): float {
		$amount = (float) $amount;
		if ( 'adjusted' === $event ) {
			return $amount;
		}
		return in_array( $event, self::OUT_EVENTS, true ) ? -abs( $amount ) : abs( $amount );
	}

	/** Column headers of the liability CSV. */
	public static function liability_header(): array {
		return array( __( 'Date', 'voucher-captain' ), __( 'Code', 'voucher-captain' ), __( 'Type', 'voucher-captain' ), __( 'Currency', 'voucher-captain' ), __( 'Issued', 'voucher-captain' ), __( 'Expires', 'voucher-captain' ), __( 'Order number', 'voucher-captain' ), __( 'Order ID', 'voucher-captain' ), __( 'Outstanding value', 'voucher-captain' ), __( 'Expired', 'voucher-captain' ), __( 'Sold', 'voucher-captain' ) );
	}

	/** One CSV line of liability_rows(). */
	public static function liability_line( array $r, string $date ): array {
		$types = Voucher::types();
		return array( $date, $r['code'], $types[ $r['type'] ] ?? $r['type'], $r['currency'], Csv::date( $r['issued'] ), Csv::date( $r['expires'], false ), Format::order_number( $r['order_id'] ), $r['order_id'] ?: '', Csv::number( $r['outstanding'], (int) ( $r['decimals'] ?? 2 ) ), $r['expired'] ? __( 'Yes', 'voucher-captain' ) : __( 'No', 'voucher-captain' ), ( $r['sold'] ?? true ) ? __( 'Yes', 'voucher-captain' ) : __( 'No', 'voucher-captain' ) );
	}

	/**
	 * CSV rows of the liability detail (header first).
	 *
	 * @param iterable $rows Rows of liability_rows().
	 * @return \Generator<array>
	 */
	public static function liability_csv( iterable $rows, string $date ): \Generator {
		yield self::liability_header();
		foreach ( $rows as $r ) {
			yield self::liability_line( $r, $date );
		}
	}

	/**
	 * Column headers of the journal CSV; with $accounts (SET-VC-20) plus "Debit account" and "Credit account".
	 *
	 * @param array{liability:string, clearing:string, income:string}|null $accounts AccountingExport::journal_accounts().
	 */
	public static function journal_header( ?array $accounts = null ): array {
		$columns = array( __( 'Date', 'voucher-captain' ), __( 'Code', 'voucher-captain' ), __( 'Type', 'voucher-captain' ), __( 'Currency', 'voucher-captain' ), __( 'Booking', 'voucher-captain' ), __( 'Amount', 'voucher-captain' ), __( 'Balance after', 'voucher-captain' ), __( 'Order number', 'voucher-captain' ), __( 'Order ID', 'voucher-captain' ), __( 'Till / branch', 'voucher-captain' ), __( 'Receipt no.', 'voucher-captain' ), __( 'Note', 'voucher-captain' ), __( 'VAT treatment', 'voucher-captain' ), __( 'Order amount', 'voucher-captain' ) );
		return null === $accounts ? $columns : array_merge( $columns, AccountingExport::journal_columns() );
	}

	/**
	 * One CSV line of a journal booking; with $accounts (SET-VC-20) plus its debit and credit account.
	 *
	 * @param array<string, mixed>                                        $r        Journal row.
	 * @param array{liability:string, clearing:string, income:string}|null $accounts AccountingExport::journal_accounts().
	 */
	public static function journal_line( array $r, ?array $accounts = null ): array {
		static $labels = null, $types = null;
		$labels   = $labels ?? VoucherRepository::event_labels();
		$types    = $types ?? Voucher::types();
		$decimals = API\V1\RedemptionService::decimals( (string) ( $r['currency'] ?: get_option( 'woocommerce_currency' ) ) );
		$total    = Format::order_total( $r['order_id'] );
		$line     = array( Csv::date( $r['created_at'] ), $r['code'], $types[ $r['type'] ] ?? $r['type'], $r['currency'], $labels[ $r['event'] ] ?? $r['event'], Csv::number( self::signed_amount( (string) $r['event'], $r['amount'] ), $decimals ), null === $r['balance_after'] ? '' : Csv::number( (float) $r['balance_after'], $decimals ), Format::order_number( $r['order_id'] ), $r['order_id'] ?: '', (string) ( $r['till'] ?? '' ), (string) ( $r['receipt'] ?? '' ), Note::render( (string) $r['note'] ), ProductConfig::treatment_label( self::row_treatment( $r ) ), null === $total ? '' : Csv::number( $total[0], API\V1\RedemptionService::decimals( $total[1] ) ) );
		return null === $accounts ? $line : array_merge( $line, AccountingExport::journal_cells( $r, $accounts ) );
	}

	/**
	 * M12-29: VAT treatment of the voucher of a journal row - as recorded at issue ("issued" entry), for vouchers
	 * issued before that from its current settings. '' when the row carries no voucher columns.
	 */
	public static function row_treatment( array $r ): string {
		static $cache = array();
		$snapshot = ProductConfig::parse_snapshot( (string) ( $r['issued_note'] ?? '' ) );
		if ( null !== $snapshot && '' !== $snapshot['treatment'] ) {
			return $snapshot['treatment'];
		}
		$id = (int) ( $r['voucher_id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}
		if ( ! isset( $cache[ $id ] ) ) {
			if ( count( $cache ) > 2000 ) {
				$cache = array();
			}
			$voucher = Voucher::from_row(
				array(
					'id'             => $id,
					'type'           => $r['type'] ?? 'voucher',
					'product_id'     => $r['product_id'] ?? 0,
					'variation_id'   => $r['variation_id'] ?? 0,
					'order_id'       => $r['voucher_order_id'] ?? 0,
					'order_item_id'  => $r['order_item_id'] ?? 0,
					'usage_mode'     => $r['usage_mode'] ?? 'single',
					'discount_type'  => $r['discount_type'] ?? 'fixed_cart',
					'initial_amount' => $r['initial_amount'] ?? 0,
				)
			);
			$cache[ $id ] = ProductConfig::tax_treatment( $voucher );
		}
		return $cache[ $id ];
	}

	/**
	 * CSV rows of the journal (header first).
	 *
	 * @param iterable $journal Bookings of journal() or journal_all().
	 */
	public static function journal_csv( iterable $journal ): array {
		$accounts = AccountingExport::journal_accounts();
		$rows     = array( self::journal_header( $accounts ) );
		foreach ( $journal as $r ) {
			$rows[] = self::journal_line( $r, $accounts );
		}
		return $rows;
	}
}
