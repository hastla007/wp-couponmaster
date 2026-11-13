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
 * Only differences are listed. Nothing is changed.
 */
final class PassCaptainReconcile {
	public function __construct( private RedemptionService $redemptions ) {}

	public static function available(): bool {
		global $wpdb;
		return PassCaptain::active() && (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpcap_orders' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array{checked:int, issues: array<int, array{kind:string, order:string, key:string, problem:string}>}
	 */
	public function run( int $limit = 2000 ): array {
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		$issues = array();
		$seen   = array();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, public_id, status, payment_status, payment_provider, provider_reference, currency, voucher_snapshot FROM {$orders} WHERE (voucher_snapshot IS NOT NULL AND voucher_snapshot <> '') OR provider_reference LIKE %s ORDER BY order_id DESC LIMIT %d", $wpdb->esc_like( 'voucher:' ) . '%', max( 1, $limit ) ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $o ) {
			$ref = (string) $o->public_id;
			if ( 0 === strpos( (string) $o->provider_reference, 'voucher:' ) ) {
				$key          = substr( (string) $o->provider_reference, strlen( 'voucher:' ) );
				$seen[ $key ] = true;
				$this->check_exchange( $o, $key, $issues );
			}
			$snapshot = $o->voucher_snapshot ? (array) json_decode( (string) $o->voucher_snapshot, true ) : array();
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
			if ( RedemptionService::STATE_COMMITTED === $row['state'] && abs( $pc_paid - (float) $row['amount'] ) > 0.5 / ( 10 ** $decimals ) ) {
				/* translators: 1: amount in Pass Captain, 2: amount in Voucher Captain */
				$issues[] = self::issue( 'checkout', $ref, $key, sprintf( __( 'Amount differs: Pass Captain %1$s, Voucher Captain %2$s.', 'voucher-captain' ), wc_format_decimal( $pc_paid, $decimals ), wc_format_decimal( (float) $row['amount'], $decimals ) ) );
			}
			$pc_refunded = (int) ( $snapshot['refunded_minor'] ?? 0 ) / ( 10 ** $decimals );
			$vc_refunded = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
			if ( abs( $pc_refunded - $vc_refunded ) > 0.5 / ( 10 ** $decimals ) ) {
				/* translators: 1: refunded in Pass Captain, 2: given back in Voucher Captain */
				$issues[] = self::issue( 'checkout', $ref, $key, sprintf( __( 'Refunds differ: Pass Captain refunded %1$s of the voucher share, Voucher Captain gave back %2$s.', 'voucher-captain' ), wc_format_decimal( $pc_refunded, $decimals ), wc_format_decimal( $vc_refunded, $decimals ) ) );
			}
		}
		// Redemptions here that point to Pass Captain without an order there (reserved or committed only).
		$table = Install::tables()['redemptions'];
		$keys  = $wpdb->get_col( $wpdb->prepare( "SELECT redemption_key FROM {$table} WHERE target LIKE %s AND state IN ('reserved','committed') AND created_at < %s ORDER BY id DESC LIMIT %d", $wpdb->esc_like( 'passcaptain:' ) . '%', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), max( 1, $limit ) ) ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $keys as $key ) {
			if ( empty( $seen[ $key ] ) && ! $this->exchange_order_exists( (string) $key ) ) {
				$issues[] = self::issue( 'orphan', '–', (string) $key, __( 'Voucher Captain holds a redemption for Pass Captain without a matching Pass Captain order.', 'voucher-captain' ) );
			}
		}
		return array( 'checked' => count( $rows ), 'issues' => $issues );
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

	/** Exchange orders reference the Pass Captain idempotency key, not the redemption key: look both up. */
	private function exchange_order_exists( string $key ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE provider_reference = %s LIMIT 1", 'voucher:' . $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function issue( string $kind, string $order, string $key, string $problem ): array {
		return array( 'kind' => $kind, 'order' => $order, 'key' => $key, 'problem' => $problem );
	}
}
