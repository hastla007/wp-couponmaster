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
 * Dates are the shop's local days; the ledger stores UTC.
 */
final class Ledger {
	/** Events that move monetary value. */
	public const MONEY_EVENTS = array( 'issued', 'redeemed', 'committed', 'restored', 'adjusted', 'credit', 'debit', 'refund_credit', 'refund_credit_reversed', 'api_committed', 'api_refunded', 'passcaptain_tickets_restored' );
	/** Events after which a single-use voucher no longer carries value (until restored or reactivated). */
	private const USED_EVENTS = array( 'redeemed', 'committed', 'api_committed', 'voided' );
	private const BACK_EVENTS = array( 'restored', 'api_refunded', 'reactivated', 'passcaptain_tickets_restored' );

	public function __construct( private VoucherRepository $repo ) {}

	/** UTC bounds of local days. @return array{0:string,1:string} */
	public static function utc_range( string $from, string $to ): array {
		return array( get_gmt_from_date( $from . ' 00:00:00' ), get_gmt_from_date( $to . ' 23:59:59' ) );
	}

	/**
	 * Outstanding value at the end of $date (Y-m-d, local).
	 *
	 * @return array{totals: array<string, array<string, array{count:int,amount:float,expired_count:int,expired_amount:float}>>, rows: array<int, array<string, mixed>>}
	 */
	public function liability( string $date ): array {
		global $wpdb;
		$tables = Install::tables();
		$until  = self::utc_range( $date, $date )[1];
		$totals = array();
		$rows   = array();
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
				$v      = Voucher::from_row( $row );
				$value  = $this->value_at( $v, $events[ $v->id ] ?? array() );
				if ( $value <= 0.00001 ) {
					continue;
				}
				$expired  = $v->expires_at && $v->expires_at <= $until;
				$currency = $v->currency ?: (string) get_option( 'woocommerce_currency' );
				$bucket   = &$totals[ $currency ][ $v->type ];
				$bucket   = $bucket ?? array( 'count' => 0, 'amount' => 0.0, 'expired_count' => 0, 'expired_amount' => 0.0 );
				$bucket[ $expired ? 'expired_count' : 'count' ]++;
				$bucket[ $expired ? 'expired_amount' : 'amount' ] = round( $bucket[ $expired ? 'expired_amount' : 'amount' ] + $value, $v->decimals() );
				unset( $bucket );
				$rows[] = array(
					'code'       => $v->code,
					'type'       => $v->type,
					'currency'   => $currency,
					'issued'     => $v->created_at,
					'expires'    => (string) $v->expires_at,
					'order_id'   => $v->order_id,
					'outstanding'=> $value,
					'expired'    => $expired,
				);
			}
		} while ( count( $vouchers ) === 500 );
		return array( 'totals' => $totals, 'rows' => $rows );
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
	 * @return array<int, array<string, mixed>>
	 */
	public function journal( string $from, string $to, int $limit = 50000 ): array {
		global $wpdb;
		$tables = Install::tables();
		list( $start, $end ) = self::utc_range( $from, $to );
		$events = implode( ',', array_map( static fn( $e ) => "'" . esc_sql( $e ) . "'", (array) apply_filters( 'vcap_ledger_money_events', self::MONEY_EVENTS ) ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.created_at, e.event, e.amount, e.balance_after, e.order_id, e.note, v.code, v.type, v.currency FROM {$tables['events']} e INNER JOIN {$tables['vouchers']} v ON v.id = e.voucher_id WHERE e.created_at BETWEEN %s AND %s AND e.event IN ({$events}) AND e.amount IS NOT NULL ORDER BY e.id LIMIT %d", $start, $end, max( 1, $limit ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** CSV rows of the liability detail. */
	public static function liability_csv( array $report, string $date ): array {
		$rows = array( array( 'date', 'code', 'type', 'currency', 'issued_utc', 'expires_utc', 'order', 'outstanding', 'expired' ) );
		foreach ( $report['rows'] as $r ) {
			$rows[] = array( $date, $r['code'], $r['type'], $r['currency'], $r['issued'], $r['expires'], $r['order_id'] ?: '', wc_format_decimal( $r['outstanding'] ), $r['expired'] ? 'yes' : 'no' );
		}
		return $rows;
	}

	/** CSV rows of the journal. */
	public static function journal_csv( array $journal ): array {
		$labels = VoucherRepository::event_labels();
		$rows   = array( array( 'date_utc', 'code', 'type', 'currency', 'event', 'event_label', 'amount', 'balance_after', 'order', 'note' ) );
		foreach ( $journal as $r ) {
			$rows[] = array( $r['created_at'], $r['code'], $r['type'], $r['currency'], $r['event'], $labels[ $r['event'] ] ?? $r['event'], wc_format_decimal( (float) $r['amount'] ), null === $r['balance_after'] ? '' : wc_format_decimal( (float) $r['balance_after'] ), $r['order_id'] ?: '', (string) $r['note'] );
		}
		return $rows;
	}
}
