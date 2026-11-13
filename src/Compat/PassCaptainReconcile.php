<?php
namespace VCAP\Compat;

use VCAP\API\V1\RedemptionService;
use VCAP\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciliation of Voucher Captain's redemptions with WP Pass Captain's orders (same database):
 *
 * - ticket checkout: every Pass Captain order paid (partly) by a voucher keeps a snapshot with the redemption
 *   key, amount, state and refunded share – compared with the redemption here;
 * - ticket exchange: every free "paid by voucher" order carries the redemption key – a voucher still counted
 *   as redeemed while all its tickets were cancelled (or the other way round) is reported;
 * - redemptions here that point to Pass Captain but have no order there.
 *
 * The orders come from Pass Captain's versioned reconciliation API (Reconciliation::orders(), contract v1,
 * Pass Captain 1.4+). Older Pass Captain versions without it: its order table is read directly as a fallback
 * (guarded by a column check) and the report says so.
 *
 * Only differences are listed. Nothing is changed.
 */
final class PassCaptainReconcile {
	public function __construct( private RedemptionService $redemptions ) {}

	/** Pass Captain's reconciliation API (contract v1). */
	public const API = 'PassCaptain\\Integrations\\Vouchers\\Reconciliation';

	/** Most rows per API call (Reconciliation::MAX_LIMIT of contract v1). */
	private const API_PAGE = 500;

	/** Columns of Pass Captain's order table the fallback reads (Pass Captain without the API). */
	private const COLUMNS = array( 'order_id', 'public_id', 'status', 'payment_status', 'payment_provider', 'provider_reference', 'currency', 'voucher_snapshot' );

	/**
	 * Whether Pass Captain is active (the report tab is shown). Whether its data can be read is unsupported_reason().
	 */
	public static function available(): bool {
		return PassCaptain::active();
	}

	/** Whether the active Pass Captain offers the versioned reconciliation API (contract v1 or later). */
	public static function supports_api(): bool {
		$constant = self::API . '::VERSION';
		return PassCaptain::active() && defined( $constant ) && (int) constant( $constant ) >= 1 && is_callable( array( self::API, 'orders' ) );
	}

	/**
	 * Where the orders come from: "filter" (rows from vcap_passcaptain_reconcile_orders), "api" (Pass Captain's
	 * reconciliation API) or "table" (fallback for older Pass Captain versions: its order table).
	 */
	public static function source(): string {
		if ( has_filter( 'vcap_passcaptain_reconcile_orders' ) ) {
			return 'filter';
		}
		return self::supports_api() ? 'api' : 'table';
	}

	/**
	 * Why the reconciliation cannot run ('' when it can). With Pass Captain's reconciliation API (or rows from
	 * the filter "vcap_passcaptain_reconcile_orders") it always can. Older Pass Captain versions are read from
	 * their order table, only when it has every column used here (checked once per Pass Captain schema version).
	 */
	public static function unsupported_reason(): string {
		if ( ! PassCaptain::active() ) {
			return __( 'WP Pass Captain is not active.', 'voucher-captain' );
		}
		if ( 'table' !== self::source() ) {
			return '';
		}
		$schema = (string) get_option( 'wpcap_db_version', '' );
		$cache  = get_transient( 'vcap_pc_reconcile_schema' );
		if ( is_array( $cache ) && ( $cache['schema'] ?? null ) === $schema ) {
			return (string) $cache['reason'];
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'wpcap_orders';
		$reason = '';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$reason = __( 'This version of WP Pass Captain does not provide the order data needed for the reconciliation.', 'voucher-captain' );
		} else {
			$have    = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing = array_diff( self::COLUMNS, $have );
			if ( $missing ) {
				/* translators: %s: missing columns */
				$reason = sprintf( __( 'This version of WP Pass Captain stores its orders differently (missing: %s); the reconciliation is not available.', 'voucher-captain' ), implode( ', ', $missing ) );
			}
		}
		set_transient( 'vcap_pc_reconcile_schema', array( 'schema' => $schema, 'reason' => $reason ), DAY_IN_SECONDS );
		return $reason;
	}

	/** Pass Captain orders paid by voucher or created by a voucher exchange, newest first. */
	private function orders( int $limit ): array {
		/**
		 * Rows of Pass Captain orders with a voucher (objects with the columns of self::COLUMNS; voucher_snapshot
		 * as JSON or array). Null uses Pass Captain's reconciliation API, or its order table on older versions.
		 */
		$rows = apply_filters( 'vcap_passcaptain_reconcile_orders', null, $limit );
		if ( is_array( $rows ) ) {
			return array_map( static fn( $r ) => (object) $r, $rows );
		}
		if ( self::supports_api() ) {
			return $this->api_orders( max( 1, $limit ) );
		}
		// Fallback for Pass Captain versions without the reconciliation API.
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		return $wpdb->get_results( $wpdb->prepare( "SELECT order_id, public_id, status, payment_status, payment_provider, provider_reference, currency, voucher_snapshot FROM {$orders} WHERE (voucher_snapshot IS NOT NULL AND voucher_snapshot <> '') OR provider_reference LIKE %s ORDER BY order_id DESC LIMIT %d", $wpdb->esc_like( 'voucher:' ) . '%', max( 1, $limit ) ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Newest orders from Pass Captain's reconciliation API, page by page, in the shape of the table rows. */
	private function api_orders( int $limit ): array {
		$rows   = array();
		$before = 0;
		while ( count( $rows ) < $limit ) {
			$args = array(
				'order' => 'desc',
				'limit' => min( self::API_PAGE, $limit - count( $rows ) ),
			);
			if ( $before > 0 ) {
				$args['before_id'] = $before;
			}
			$page = call_user_func( array( self::API, 'orders' ), $args );
			$page = is_array( $page ) ? array_values( $page ) : array();
			foreach ( $page as $r ) {
				$rows[] = self::from_api( (array) $r );
			}
			$before = $page ? (int) ( ( (array) end( $page ) )['order_id'] ?? 0 ) : 0;
			if ( count( $page ) < $args['limit'] || $before < 2 ) {
				break;
			}
		}
		return $rows;
	}

	/** One API row (contract v1) as a table row; ticket-service orders keep no reservation key, as in the table. */
	private static function from_api( array $r ): object {
		$voucher  = is_array( $r['voucher'] ?? null ) ? $r['voucher'] : null;
		$snapshot = array();
		if ( $voucher ) {
			$snapshot = array(
				'status'         => (string) ( $voucher['status'] ?? '' ),
				'amount_minor'   => (int) ( $voucher['amount_minor'] ?? 0 ),
				'refunded_minor' => (int) ( $voucher['refunded_minor'] ?? 0 ),
			);
			if ( 'checkout' === ( $voucher['source'] ?? '' ) && '' !== (string) ( $voucher['redemption_key'] ?? '' ) ) {
				$snapshot['reservation'] = array( 'redemption_key' => (string) $voucher['redemption_key'] );
			}
		}
		return (object) array(
			'order_id'           => (int) ( $r['order_id'] ?? 0 ),
			'public_id'          => (string) ( $r['public_id'] ?? '' ),
			'status'             => (string) ( $r['status'] ?? '' ),
			'payment_status'     => (string) ( $r['payment_status'] ?? '' ),
			'payment_provider'   => (string) ( $r['payment_provider'] ?? '' ),
			'provider_reference' => (string) ( $r['provider_reference'] ?? '' ),
			'currency'           => (string) ( $r['currency'] ?? '' ),
			'voucher_snapshot'   => $snapshot,
		);
	}

	/**
	 * @return array{checked:int, issues: array<int, array{kind:string, order:string, key:string, problem:string}>, source:string, unavailable?:string}
	 */
	public function run( int $limit = 2000 ): array {
		global $wpdb;
		$reason = self::unsupported_reason();
		if ( '' !== $reason ) {
			return array( 'checked' => 0, 'issues' => array(), 'source' => self::source(), 'unavailable' => $reason );
		}
		$issues = array();
		$seen   = array();
		$rows   = $this->orders( $limit );
		foreach ( $rows as $o ) {
			$ref = (string) $o->public_id;
			if ( 0 === strpos( (string) $o->provider_reference, 'voucher:' ) ) {
				$key          = substr( (string) $o->provider_reference, strlen( 'voucher:' ) );
				$seen[ $key ] = true;
				$this->check_exchange( $o, $key, $issues );
			}
			$snapshot = is_array( $o->voucher_snapshot ) ? $o->voucher_snapshot : ( $o->voucher_snapshot ? (array) json_decode( (string) $o->voucher_snapshot, true ) : array() );
			$key      = (string) ( $snapshot['reservation']['redemption_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$seen[ $key ] = true;
			$row          = $this->redemptions->get( $key );
			if ( is_wp_error( $row ) ) {
				$issues[] = self::issue( 'checkout', $ref, $key, __( 'Pass Captain booked a voucher payment that Voucher Captain does not know.', 'voucher-captain' ) );
				continue;
			}
			$pc_state = (string) ( $snapshot['status'] ?? '' );
			$expected = array( 'committed' => RedemptionService::STATE_COMMITTED, 'released' => RedemptionService::STATE_RELEASED, 'reserved' => RedemptionService::STATE_RESERVED );
			if ( 'commit_failed' === $pc_state ) {
				$issues[] = self::issue( 'checkout', $ref, $key, __( 'Pass Captain could not confirm the voucher payment (commit failed) – check the order.', 'voucher-captain' ) );
			} elseif ( isset( $expected[ $pc_state ] ) && $expected[ $pc_state ] !== $row['state'] ) {
				/* translators: 1: state in Pass Captain, 2: state in Voucher Captain */
				$issues[] = self::issue( 'checkout', $ref, $key, sprintf( __( 'State differs: Pass Captain "%1$s", Voucher Captain "%2$s".', 'voucher-captain' ), $pc_state, $row['state'] ) );
			}
			$decimals = RedemptionService::decimals( (string) ( $o->currency ?: $row['currency'] ) );
			// amount_minor is the voucher's share in both treatments (amount_paid_by_voucher is 0 for single-purpose vouchers).
			$pc_paid  = (int) ( $snapshot['amount_minor'] ?? 0 ) / ( 10 ** $decimals );
			$tolerance = 0.5 / ( 10 ** $decimals );
			// A single-use voucher is used up as a whole even when the order cost less: only less than credited differs.
			$single    = null === $row['balance_after'];
			$differs   = $single ? (float) $row['amount'] + $tolerance < $pc_paid : abs( $pc_paid - (float) $row['amount'] ) > $tolerance;
			if ( RedemptionService::STATE_COMMITTED === $row['state'] && $differs ) {
				/* translators: 1: amount in Pass Captain, 2: amount in Voucher Captain */
				$issues[] = self::issue( 'checkout', $ref, $key, sprintf( __( 'Amount differs: Pass Captain %1$s, Voucher Captain %2$s.', 'voucher-captain' ), wc_format_decimal( $pc_paid, $decimals ), wc_format_decimal( (float) $row['amount'], $decimals ) ) );
			}
			$pc_refunded = (int) ( $snapshot['refunded_minor'] ?? 0 ) / ( 10 ** $decimals );
			$vc_refunded = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
			// Single-use: Pass Captain refunds its credited share, Voucher Captain gives the whole voucher back (or
			// nothing for a partial refund) – only a full refund without the voucher back differs.
			$refund_differs = $single
				? $pc_refunded + $tolerance >= $pc_paid && $pc_paid > 0 && $vc_refunded <= $tolerance
				: abs( $pc_refunded - $vc_refunded ) > $tolerance;
			if ( $refund_differs ) {
				/* translators: 1: refunded in Pass Captain, 2: given back in Voucher Captain */
				$issues[] = self::issue( 'checkout', $ref, $key, sprintf( __( 'Refunds differ: Pass Captain refunded %1$s of the voucher share, Voucher Captain gave back %2$s.', 'voucher-captain' ), wc_format_decimal( $pc_refunded, $decimals ), wc_format_decimal( $vc_refunded, $decimals ) ) );
			}
		}
		// Redemptions here that point to Pass Captain without an order there (reserved or committed only).
		$table = Install::tables()['redemptions'];
		$keys  = $wpdb->get_col( $wpdb->prepare( "SELECT redemption_key FROM {$table} WHERE target LIKE %s AND state IN ('reserved','committed') AND created_at < %s ORDER BY id DESC LIMIT %d", $wpdb->esc_like( 'passcaptain:' ) . '%', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), max( 1, $limit ) ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $keys as $key ) {
			if ( empty( $seen[ $key ] ) && ! $this->order_exists( (string) $key ) ) {
				$issues[] = self::issue( 'orphan', '–', (string) $key, __( 'Voucher Captain holds a redemption for Pass Captain without a matching Pass Captain order.', 'voucher-captain' ) );
			}
		}
		return array( 'checked' => count( $rows ), 'issues' => $issues, 'source' => self::source() );
	}

	private function check_exchange( object $o, string $key, array &$issues ): void {
		$row = $this->redemptions->get( $key );
		if ( is_wp_error( $row ) ) {
			return;
		}
		$voucher = vcap()->vouchers->get( (int) $row['voucher_id'] );
		if ( ! $voucher || \VCAP\Voucher::STATUS_VOID === $voucher->status ) {
			return; // A voided voucher (purchase refunded) revokes its tickets on purpose.
		}
		$cancelled = in_array( (string) $o->status, array( 'cancelled', 'refunded' ), true );
		$refunded  = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
		$given_back = RedemptionService::STATE_RELEASED === $row['state'] || ( (float) $row['amount'] > 0 && $refunded + 0.0001 >= (float) $row['amount'] );
		if ( $cancelled && RedemptionService::STATE_COMMITTED === $row['state'] && ! $given_back ) {
			$issues[] = self::issue( 'exchange', (string) $o->public_id, $key, __( 'The tickets were cancelled in Pass Captain, but the voucher still counts as exchanged.', 'voucher-captain' ) );
		}
	}

	/**
	 * Whether Pass Captain has an order for the key beyond the rows checked. The API looks up checkout and
	 * exchange orders; the table fallback only exchange orders (provider reference "voucher:<key>").
	 */
	private function order_exists( string $key ): bool {
		global $wpdb;
		$source = self::source();
		if ( 'filter' === $source ) {
			return false; // Only the supplied rows are known.
		}
		if ( 'api' === $source ) {
			$found = call_user_func( array( self::API, 'orders' ), array( 'redemption_key' => $key, 'limit' => 1 ) );
			return is_array( $found ) && array() !== $found;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE provider_reference = %s LIMIT 1", 'voucher:' . $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function issue( string $kind, string $order, string $key, string $problem ): array {
		return array( 'kind' => $kind, 'order' => $order, 'key' => $key, 'problem' => $problem );
	}
}
