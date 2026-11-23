<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-97: physical gift cards. A voucher product with "Physical card" is shipped (not virtual): its vouchers take
 * their code from the product's imported batch (the printed cards), get no email – the card itself is the handover
 * – and stay inactive (scheduled, coupon disabled) until the order reaches an activation status (default
 * "completed", i.e. shipped; SET-VC-33: setting and filter "vcap_physical_activation_statuses"). Print data for card printers is not part
 * of the core (docs/FREE-PREMIUM.md: possible add-on feature).
 */
final class PhysicalCards {
	/** SET-VC-33 (1.12 no. 12): setting with the activation statuses (default "completed"; the filter wins). */
	public const STATUSES_OPTION = 'vcap_physical_activation_statuses';

	/** Statuses before or without payment: never an activation status of the setting (a card is a means of payment). */
	public const UNPAID_STATUSES = array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', 'checkout-draft' );

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status' ), 40, 4 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::STATUSES_OPTION, array( self::class, 'sanitize_statuses' ) );
	}

	public static function is_physical( array $config ): bool {
		return 'yes' === ( $config['physical'] ?? 'no' );
	}

	/**
	 * Order statuses (without "wc-") that mean "shipped": the cards of the order become redeemable. SET-VC-33: the
	 * setting (Settings → Vouchers → Integrations (API); unpaid statuses are dropped, empty = "completed") is the
	 * default of the filter, which wins.
	 *
	 * @return string[]
	 */
	public static function activation_statuses(): array {
		/** Order statuses at which physical gift cards are activated (default: the setting, "completed"). */
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'vcap_physical_activation_statuses', self::setting_statuses() ) ) ) );
	}

	/**
	 * The statuses of the setting: paid statuses only, "completed" when none is left.
	 *
	 * @param mixed $value Stored value (default: the setting).
	 * @return string[]
	 */
	public static function setting_statuses( $value = null ): array {
		$value = null === $value ? Settings::value( self::STATUSES_OPTION ) : $value;
		$list  = array();
		foreach ( (array) $value as $status ) {
			$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';
			$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			if ( '' !== $status && ! in_array( $status, self::UNPAID_STATUSES, true ) && ! in_array( $status, $list, true ) ) {
				$list[] = $status;
			}
		}
		return $list ? $list : array( 'completed' );
	}

	/**
	 * Paid order statuses offered by the setting (status without "wc-" => label).
	 *
	 * @return array<string, string>
	 */
	public static function status_choices(): array {
		$choices = array();
		foreach ( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array( 'wc-completed' => __( 'Completed', 'voucher-captain' ) ) as $key => $label ) {
			$status = str_starts_with( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
			if ( ! in_array( $status, self::UNPAID_STATUSES, true ) ) {
				$choices[ $status ] = (string) $label;
			}
		}
		return $choices;
	}

	/**
	 * WooCommerce filter "woocommerce_admin_settings_sanitize_option_vcap_physical_activation_statuses": only paid
	 * statuses are stored; an empty choice stores "completed".
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public static function sanitize_statuses( $value ): array {
		return self::setting_statuses( is_array( $value ) ? $value : array() );
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
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'active', updated_at = %s WHERE id = %d AND status = 'scheduled' AND delivery_at IS NULL", $table, $now, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
