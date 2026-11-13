<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Accounting views of the voucher ledger (events table):
 *
 * - liability( $date ): outstanding voucher value at the end of a day – gift cards and store credit with their
 *   balance then, single-use value vouchers with their value while not yet redeemed or voided. Expired value is
 *   listed separately. Percentage and ticket vouchers carry no monetary liability and are left out.
 * - journal( $from, $to ): every booking that moved value (redemptions, refunds, credits, adjustments) in a period.
 *
 * Dates are the shop's local days; the ledger stores UTC. CSV exports are streamed (no row limit).
 */
final class Ledger {
	/** Events that move monetary value. */
	public const MONEY_EVENTS = array( 'issued', 'redeemed', 'committed', 'restored', 'adjusted', 'credit', 'debit', 'refund_credit', 'refund_credit_reversed', 'api_committed', 'api_refunded', 'passcaptain_tickets_restored', 'topup', 'topup_reversed' );
	/** Events after which a single-use voucher no longer carries value (until restored or reactivated). */
	private const USED_EVENTS = array( 'redeemed', 'committed', 'api_committed', 'voided' );
	private const BACK_EVENTS = array( 'restored', 'api_refunded', 'reactivated', 'passcaptain_tickets_restored' );

	public function __construct( private VoucherRepository $repo ) {}

	/** UTC bounds of local days. @return array{0:string,1:string} */
	public static function utc_range( string $from, string $to ): array {
		return array( get_gmt_from_date( $from . ' 00:00:00' ), get_gmt_from_date( $to . ' 23:59:59' ) );
	}

	/**
	 * Outstanding value at the end of $date (Y-m-d, local), summed in the database (no voucher is loaded into
	 * memory). The per-voucher detail is liability_rows().
	 *
	 * @return array{totals: array<string, array<string, array{count:int,amount:float,expired_count:int,expired_amount:float}>>}
	 */
	public function liability( string $date ): array {
		global $wpdb;
		$t     = Install::tables();
		$until = self::utc_range( $date, $date )[1];
		$used  = self::in_list( self::USED_EVENTS );
		$both  = self::in_list( array_merge( self::USED_EVENTS, self::BACK_EVENTS ) );
		// Same rules as value_at(): voided (until reactivated) = 0; gift cards: last balance at that time;
		// single-use: full value unless the last use/give-back event was a use.
		$sql  = "SELECT currency, type, expired, COUNT(*) AS cnt, SUM(outstanding) AS amount FROM (
				SELECT v.currency, v.type, ( v.expires_at IS NOT NULL AND v.expires_at <= %s ) AS expired,
					CASE
						WHEN 'voided' = ( SELECT e.event FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.event IN ('voided','reactivated') ORDER BY e.id DESC LIMIT 1 ) THEN 0
						WHEN v.usage_mode = 'balance' THEN GREATEST( 0, COALESCE( ( SELECT e.balance_after FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.balance_after IS NOT NULL ORDER BY e.id DESC LIMIT 1 ), v.initial_amount ) )
						WHEN ( SELECT e.event FROM {$t['events']} e WHERE e.voucher_id = v.id AND e.created_at <= %s AND e.event IN ({$both}) ORDER BY e.id DESC LIMIT 1 ) IN ({$used}) THEN 0
						ELSE v.initial_amount
					END AS outstanding
				FROM {$t['vouchers']} v
				WHERE v.created_at <= %s AND v.discount_type <> 'percent' AND v.type <> 'ticket' AND v.usage_mode IN ('single','balance')
			) x WHERE outstanding > 0.00001 GROUP BY currency, type, expired";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $until, $until, $until, $until, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$totals = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$currency = '' !== (string) $r['currency'] ? (string) $r['currency'] : (string) get_option( 'woocommerce_currency' );
			$expired  = (bool) (int) $r['expired'];
			$bucket   = &$totals[ $currency ][ (string) $r['type'] ];
			$bucket   = $bucket ?? array( 'count' => 0, 'amount' => 0.0, 'expired_count' => 0, 'expired_amount' => 0.0 );
			$bucket[ $expired ? 'expired_count' : 'count' ] += (int) $r['cnt'];
			$bucket[ $expired ? 'expired_amount' : 'amount' ] = round( $bucket[ $expired ? 'expired_amount' : 'amount' ] + (float) $r['amount'], API\V1\RedemptionService::decimals( $currency ) );
			unset( $bucket );
		}
		return array( 'totals' => $totals );
	}

	/**
	 * Vouchers with outstanding value at the end of $date, one row each, read in batches of 500.
	 *
	 * @return \Generator<array{code:string,type:string,currency:string,issued:string,expires:string,order_id:int,outstanding:float,expired:bool,decimals:int}>
	 */
	public function liability_rows( string $date ): \Generator {
		global $wpdb;
		$tables = Install::tables();
		$until  = self::utc_range( $date, $date )[1];
		$last   = 0;
		do {
			$vouchers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['vouchers']} WHERE id > %d AND created_at <= %s AND discount_type <> 'percent' AND type <> 'ticket' AND usage_mode IN ('single','balance') ORDER BY id LIMIT 500", $last, $until ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $vouchers ) {
				break;
			}
			$ids    = array_map( 'intval', array_column( $vouchers, 'id' ) );
			$last   = end( $ids );
			$events = array();
			$in     = implode( ',', $ids );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT voucher_id, event, balance_after FROM {$tables['events']} WHERE voucher_id IN ({$in}) AND created_at <= %s ORDER BY id", $until ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$events[ (int) $e['voucher_id'] ][] = $e;
			}
			foreach ( $vouchers as $row ) {
				$v     = Voucher::from_row( $row );
				$value = $this->value_at( $v, $events[ $v->id ] ?? array() );
				if ( $value <= 0.00001 ) {
					continue;
				}
				yield array(
					'code'        => $v->code,
					'type'        => $v->type,
					'currency'    => $v->currency ?: (string) get_option( 'woocommerce_currency' ),
					'issued'      => $v->created_at,
					'expires'     => (string) $v->expires_at,
					'order_id'    => $v->order_id,
					'outstanding' => $value,
					'expired'     => $v->expires_at && $v->expires_at <= $until,
					'decimals'    => $v->decimals(),
				);
			}
		} while ( count( $vouchers ) === 500 );
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
			foreach ( $events as $e ) {
				if ( null !== $e['balance_after'] ) {
					$balance = (float) $e['balance_after'];
				}
			}
			return max( 0.0, null === $balance ? (float) $v->initial_amount : $balance );
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
	public function journal( string $from, string $to, int $limit = 50000, int $after_id = 0 ): array {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$events = self::in_list( (array) apply_filters( 'vcap_ledger_money_events', self::MONEY_EVENTS ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.created_at, e.event, e.amount, e.balance_after, e.order_id, e.note, v.code, v.type, v.currency FROM {$tables['events']} e INNER JOIN {$tables['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at BETWEEN %s AND %s AND e.event IN ({$events}) AND e.amount IS NOT NULL AND e.id > %d ORDER BY e.id LIMIT %d", $start, $end, max( 0, $after_id ), max( 1, $limit ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Number of bookings in the period (shown when the page lists only the first ones). */
	public function journal_count( string $from, string $to ): int {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$events = self::in_list( (array) apply_filters( 'vcap_ledger_money_events', self::MONEY_EVENTS ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['events']} e WHERE e.created_at BETWEEN %s AND %s AND e.event IN ({$events}) AND e.amount IS NOT NULL", $start, $end ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every booking of the period, read in batches (for the CSV export – no row limit).
	 *
	 * @return \Generator<array<string, mixed>>
	 */
	public function journal_all( string $from, string $to, int $batch = 2000 ): \Generator {
		$after = 0;
		do {
			$rows = $this->journal( $from, $to, $batch, $after );
			foreach ( $rows as $row ) {
				yield $row;
			}
			$after = $rows ? (int) end( $rows )['id'] : 0;
		} while ( count( $rows ) === $batch );
	}

	/** Column headers of the liability CSV. */
	public static function liability_header(): array {
		return array( __( 'Date', 'voucher-captain' ), __( 'Code', 'voucher-captain' ), __( 'Type', 'voucher-captain' ), __( 'Currency', 'voucher-captain' ), __( 'Issued', 'voucher-captain' ), __( 'Expires', 'voucher-captain' ), __( 'Order', 'voucher-captain' ), __( 'Outstanding value', 'voucher-captain' ), __( 'Expired', 'voucher-captain' ) );
	}

	/** One CSV line of liability_rows(). */
	public static function liability_line( array $r, string $date ): array {
		$types = Voucher::types();
		return array( $date, $r['code'], $types[ $r['type'] ] ?? $r['type'], $r['currency'], Csv::date( $r['issued'] ), Csv::date( $r['expires'], false ), $r['order_id'] ?: '', Csv::number( $r['outstanding'], (int) ( $r['decimals'] ?? 2 ) ), $r['expired'] ? __( 'Yes', 'voucher-captain' ) : __( 'No', 'voucher-captain' ) );
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

	/** Column headers of the journal CSV. */
	public static function journal_header(): array {
		return array( __( 'Date', 'voucher-captain' ), __( 'Code', 'voucher-captain' ), __( 'Type', 'voucher-captain' ), __( 'Currency', 'voucher-captain' ), __( 'Booking', 'voucher-captain' ), __( 'Amount', 'voucher-captain' ), __( 'Balance after', 'voucher-captain' ), __( 'Order', 'voucher-captain' ), __( 'Note', 'voucher-captain' ) );
	}

	/** One CSV line of a journal booking. */
	public static function journal_line( array $r ): array {
		static $labels = null, $types = null;
		$labels   = $labels ?? VoucherRepository::event_labels();
		$types    = $types ?? Voucher::types();
		$decimals = API\V1\RedemptionService::decimals( (string) ( $r['currency'] ?: get_option( 'woocommerce_currency' ) ) );
		return array( Csv::date( $r['created_at'] ), $r['code'], $types[ $r['type'] ] ?? $r['type'], $r['currency'], $labels[ $r['event'] ] ?? $r['event'], Csv::number( (float) $r['amount'], $decimals ), null === $r['balance_after'] ? '' : Csv::number( (float) $r['balance_after'], $decimals ), $r['order_id'] ?: '', (string) $r['note'] );
	}

	/**
	 * CSV rows of the journal (header first).
	 *
	 * @param iterable $journal Bookings of journal() or journal_all().
	 */
	public static function journal_csv( iterable $journal ): array {
		$rows = array( self::journal_header() );
		foreach ( $journal as $r ) {
			$rows[] = self::journal_line( $r );
		}
		return $rows;
	}
}
