<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-97: physical gift cards. A voucher product with "Physical card" is shipped (not virtual): its vouchers take
 * their code from the product's imported batch (the printed cards), get no email – the card itself is the handover
 * – and stay inactive (scheduled, coupon disabled) until the order reaches an activation status (default
 * "completed", i.e. shipped; filter "vcap_physical_activation_statuses"). Print data for card printers is not part
 * of the core (docs/FREE-PREMIUM.md: possible add-on feature).
 */
final class PhysicalCards {
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status' ), 40, 4 );
	}

	public static function is_physical( array $config ): bool {
		return 'yes' === ( $config['physical'] ?? 'no' );
	}

	/** Order statuses (without "wc-") that mean "shipped": the cards of the order become redeemable. */
	public static function activation_statuses(): array {
		/** Order statuses at which physical gift cards are activated (default: completed). */
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'vcap_physical_activation_statuses', array( 'completed' ) ) ) ) );
	}

	/**
	 * Called by Issuer::create_voucher() before the voucher is saved: a physical card counts as handed over (no
	 * email, no delivery date) and is inactive until the order is shipped.
	 */
	public static function prepare( Voucher $voucher, array $config, \WC_Order $order, string $now ): void {
		if ( ! self::is_physical( $config ) ) {
			return;
		}
		$voucher->delivery_at  = null;
		$voucher->delivered_at = $now;
		$voucher->status       = in_array( $order->get_status(), self::activation_statuses(), true ) ? Voucher::STATUS_ACTIVE : Voucher::STATUS_SCHEDULED;
	}

	/** Physical cards of an order still waiting for the shipment. @return Voucher[] */
	public static function waiting( int $order_id ): array {
		$out = array();
		foreach ( vcap()->vouchers->for_order( $order_id ) as $voucher ) {
			if ( Voucher::STATUS_SCHEDULED !== $voucher->status || $voucher->delivery_at || ! $voucher->delivered_at ) {
				continue;
			}
			$product = $voucher->product();
			$variation = $voucher->variation_id ? wc_get_product( $voucher->variation_id ) : null;
			if ( $product && self::is_physical( ProductConfig::get( $variation ? $variation : $product ) ) ) {
				$out[] = $voucher;
			}
		}
		return $out;
	}

	/** @param mixed $order The order (WooCommerce passes it from 3.x on). */
	public function on_status( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! in_array( (string) $to, self::activation_statuses(), true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::activate( $order );
		}
	}

	/** Activates the waiting physical cards of an order. @return int number of cards activated */
	public static function activate( \WC_Order $order ): int {
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$done  = 0;
		foreach ( self::waiting( $order->get_id() ) as $voucher ) {
			$now     = vcap()->vouchers->now();
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active', updated_at = %s WHERE id = %d AND status = 'scheduled' AND delivery_at IS NULL", $now, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 !== (int) $changed ) {
				continue;
			}
			$voucher->status = Voucher::STATUS_ACTIVE;
			vcap()->coupons->set_coupon_enabled( $voucher, true );
			vcap()->vouchers->log( $voucher->id, 'activated', null, null, Note::make( 'physical_activated', array( (string) $order->get_order_number() ) ), $order->get_id() );
			++$done;
		}
		if ( $done ) {
			/* translators: %d: number of cards */
			$order->add_order_note( sprintf( _n( '%d physical gift card activated (order shipped).', '%d physical gift cards activated (order shipped).', $done, 'voucher-captain' ), $done ) );
		}
		return $done;
	}
}
