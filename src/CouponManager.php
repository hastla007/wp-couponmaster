<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates WooCommerce coupons for vouchers and keeps redemption state (single use / stored balance) in sync.
 */
final class CouponManager {
	public function __construct( private Settings $settings, private VoucherRepository $repo ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 20, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
		add_action( 'woocommerce_increase_coupon_usage_count', array( $this, 'usage_increased' ), 10, 3 );
		add_action( 'woocommerce_decrease_coupon_usage_count', array( $this, 'usage_decreased' ), 10, 3 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_balance_notice' ) );
	}

	public function code_exists( string $code ): bool {
		return $this->repo->code_exists( $code ) || wc_get_coupon_id_by_code( $code ) > 0;
	}

	public function create_coupon( Voucher $voucher, array $config ): int {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $voucher->code );
		$coupon->set_discount_type( in_array( $voucher->discount_type, array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ? $voucher->discount_type : 'fixed_cart' );
		$coupon->set_amount( $voucher->is_multi_use() ? $voucher->balance : $voucher->initial_amount );
		$coupon->set_description(
			sprintf(
				/* translators: 1: voucher type, 2: order number */
				__( '%1$s from order #%2$s (Voucher Captain)', 'voucher-captain' ),
				Voucher::types()[ $voucher->type ] ?? $voucher->type,
				$voucher->order() ? $voucher->order()->get_order_number() : $voucher->order_id
			)
		);
		if ( $voucher->expires_at ) {
			$coupon->set_date_expires( strtotime( $voucher->expires_at . ' UTC' ) );
		}
		switch ( $voucher->usage_mode ) {
			case 'balance':
				$coupon->set_usage_limit( 0 );
				break;
			case 'unlimited':
				$coupon->set_usage_limit( (int) $config['usage_limit'] > 0 ? (int) $config['usage_limit'] : 0 );
				break;
			default:
				$coupon->set_usage_limit( 1 );
		}
		$coupon->set_individual_use( 'yes' === $config['individual_use'] );
		$coupon->set_exclude_sale_items( 'yes' === $config['exclude_sale'] );
		$coupon->set_free_shipping( 'yes' === $config['free_shipping'] );
		if ( '' !== (string) $config['min_spend'] ) {
			$coupon->set_minimum_amount( wc_format_decimal( $config['min_spend'] ) );
		}
		if ( '' !== (string) $config['max_spend'] ) {
			$coupon->set_maximum_amount( wc_format_decimal( $config['max_spend'] ) );
		}
		$coupon->set_product_ids( array_map( 'intval', (array) $config['product_ids'] ) );
		$coupon->set_excluded_product_ids( array_map( 'intval', (array) $config['exclude_product_ids'] ) );
		$coupon->set_product_categories( array_map( 'intval', (array) $config['category_ids'] ) );
		$coupon->set_excluded_product_categories( array_map( 'intval', (array) $config['exclude_category_ids'] ) );

		$emails = array();
		if ( 'recipient' === $config['email_restrict'] && is_email( $voucher->recipient_email ) ) {
			$emails[] = $voucher->recipient_email;
		} elseif ( 'buyer' === $config['email_restrict'] && is_email( $voucher->buyer_email ) ) {
			$emails[] = $voucher->buyer_email;
		} elseif ( 'both' === $config['email_restrict'] ) {
			$emails = array_filter( array( $voucher->buyer_email, $voucher->recipient_email ), 'is_email' );
		}
		$coupon->set_email_restrictions( array_values( array_unique( $emails ) ) );

		$coupon->update_meta_data( '_vcap_voucher', 'yes' );
		$coupon->update_meta_data( '_vcap_voucher_code', $voucher->code );
		if ( $voucher->is_ticket() ) {
			// Tickets are admission passes: do not give a cart discount unless an amount was configured.
			$coupon->update_meta_data( '_vcap_ticket', 'yes' );
		}

		$coupon = apply_filters( 'vcap_coupon_before_save', $coupon, $voucher, $config );
		$coupon->save();
		return (int) $coupon->get_id();
	}

	public function set_coupon_enabled( Voucher $voucher, bool $enabled ): void {
		if ( ! $voucher->coupon_id ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $voucher->coupon_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			)
		);
	}

	/**
	 * Apply a new balance to voucher + coupon.
	 */
	public function set_balance( Voucher $voucher, float $balance, string $event, ?float $amount = null, string $note = '', int $order_id = 0 ): void {
		$balance          = max( 0.0, round( $balance, wc_get_price_decimals() ) );
		$voucher->balance = $balance;
		if ( $voucher->is_multi_use() ) {
			if ( $balance <= 0 && Voucher::STATUS_ACTIVE === $voucher->status ) {
				$voucher->status = Voucher::STATUS_REDEEMED;
			} elseif ( $balance > 0 && Voucher::STATUS_REDEEMED === $voucher->status ) {
				$voucher->status = Voucher::STATUS_ACTIVE;
			}
		}
		$this->repo->update( $voucher );
		$this->repo->log( $voucher->id, $event, $amount, $balance, $note, $order_id );

		if ( $voucher->coupon_id && $voucher->is_multi_use() ) {
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			if ( $coupon->get_id() ) {
				$coupon->set_amount( $balance );
				$coupon->save();
			}
		}
	}

	/**
	 * Reject coupons whose voucher is void, not yet delivered or used up.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return $valid;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher ) {
			return $valid;
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			throw new \Exception( esc_html__( 'This voucher has been cancelled.', 'voucher-captain' ), 9001 );
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status || ( $voucher->is_multi_use() && $voucher->balance <= 0 ) ) {
			throw new \Exception( esc_html__( 'This voucher has already been fully redeemed.', 'voucher-captain' ), 9002 );
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			throw new \Exception( esc_html__( 'This voucher has expired.', 'voucher-captain' ), 9003 );
		}
		return $valid;
	}

	public function cart_balance_notice(): void {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$voucher = $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
			if ( $voucher && $voucher->is_multi_use() ) {
				echo '<tr class="vcap-balance"><th>' . esc_html(
					sprintf(
						/* translators: %s: voucher code */
						__( 'Voucher %s balance', 'voucher-captain' ),
						$voucher->code
					)
				) . '</th><td>' . wp_kses_post( wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) . '</td></tr>';
			}
		}
	}

	private function used_amount( \WC_Order_Item_Coupon $item ): float {
		$amount = (float) $item->get_discount();
		if ( $this->settings->yes( 'vcap_balance_include_tax' ) ) {
			$amount += (float) $item->get_discount_tax();
		}
		return $amount;
	}

	/**
	 * Deduct stored balances when an order using balance vouchers is paid; restore on cancellation.
	 */
	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$deduct_statuses  = apply_filters( 'vcap_balance_deduct_statuses', array( 'processing', 'completed', 'on-hold' ) );
		$restore_statuses = apply_filters( 'vcap_balance_restore_statuses', array( 'cancelled', 'refunded', 'failed' ) );

		if ( in_array( $to, $deduct_statuses, true ) ) {
			$this->deduct_order( $order );
		} elseif ( in_array( $to, $restore_statuses, true ) && $this->settings->yes( 'vcap_restore_on_cancel' ) ) {
			$this->restore_order( $order );
		}
	}

	public function deduct_order( \WC_Order $order ): void {
		$applied = (array) $order->get_meta( '_vcap_redemptions' );
		$changed = false;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var \WC_Order_Item_Coupon $item */
			$code = wc_format_coupon_code( $item->get_code() );
			if ( isset( $applied[ $code ] ) ) {
				continue;
			}
			$voucher = $this->find_voucher_for_code( $code );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$amount = min( $voucher->balance, $this->used_amount( $item ) );
			$this->set_balance(
				$voucher,
				$voucher->balance - $amount,
				'redeemed',
				$amount,
				/* translators: %s: order number */
				sprintf( __( 'Used in order #%s', 'voucher-captain' ), $order->get_order_number() ),
				$order->get_id()
			);
			$applied[ $code ] = $amount;
			$changed          = true;
			$order->add_order_note(
				sprintf(
					/* translators: 1: code, 2: amount, 3: remaining balance */
					__( 'Voucher %1$s: %2$s deducted, remaining balance %3$s.', 'voucher-captain' ),
					$voucher->code,
					wc_price( $amount, array( 'currency' => $voucher->currency ) ),
					wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) )
				)
			);
		}
		if ( $changed ) {
			$order->update_meta_data( '_vcap_redemptions', $applied );
			$order->save();
		}
	}

	public function restore_order( \WC_Order $order ): void {
		$applied = (array) $order->get_meta( '_vcap_redemptions' );
		if ( ! $applied ) {
			return;
		}
		foreach ( $applied as $code => $amount ) {
			$voucher = $this->find_voucher_for_code( (string) $code );
			if ( ! $voucher ) {
				continue;
			}
			$this->set_balance(
				$voucher,
				$voucher->balance + (float) $amount,
				'restored',
				(float) $amount,
				/* translators: %s: order number */
				sprintf( __( 'Order #%s cancelled or refunded', 'voucher-captain' ), $order->get_order_number() ),
				$order->get_id()
			);
		}
		$order->delete_meta_data( '_vcap_redemptions' );
		$order->save();
	}

	private function find_voucher_for_code( string $code ): ?Voucher {
		$coupon_id = wc_get_coupon_id_by_code( $code );
		return $coupon_id ? $this->repo->get_by_coupon( (int) $coupon_id ) : $this->repo->get_by_code( $code );
	}

	/**
	 * Single-use vouchers: mark redeemed once WooCommerce's usage limit is reached.
	 */
	public function usage_increased( $coupon, $new_count, $used_by ): void {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher || $voucher->is_multi_use() ) {
			return;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( $limit > 0 && (int) $new_count >= $limit && Voucher::STATUS_ACTIVE === $voucher->status ) {
			$voucher->status  = Voucher::STATUS_REDEEMED;
			$voucher->balance = 0;
			$this->repo->update( $voucher );
			$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, (string) $used_by );
		}
	}

	public function usage_decreased( $coupon, $new_count, $used_by ): void {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher || $voucher->is_multi_use() ) {
			return;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( Voucher::STATUS_REDEEMED === $voucher->status && ( 0 === $limit || (int) $new_count < $limit ) ) {
			$voucher->status  = Voucher::STATUS_ACTIVE;
			$voucher->balance = $voucher->initial_amount;
			$this->repo->update( $voucher );
			$this->repo->log( $voucher->id, 'restored', $voucher->initial_amount, $voucher->balance, (string) $used_by );
		}
	}
}
