<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Optional store credit per customer account.
 *
 * Each account is a personal stored-balance voucher (type 'credit') with a WooCommerce coupon that only its owner
 * can use. Checkout therefore reuses the gift card machinery: reservation before payment, partial use, restore on
 * cancellation/refund. Every change is an append-only ledger entry (credit, debit, refund_credit, reserved,
 * committed, …) with user, time, order and reason.
 *
 * Credits are kept in the shop base currency; currency switchers convert the coupon like any other coupon.
 */
final class StoreCredit {
	public function __construct( private Settings $settings, private VoucherRepository $repo, private CouponManager $coupons ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_owner' ), 21, 3 );
		add_action( 'vcap_account_vouchers_top', array( $this, 'account_block' ) );
		add_action( 'woocommerce_before_cart', array( $this, 'cart_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'cart_notice' ) );
		add_action( 'wp_loaded', array( $this, 'apply_from_link' ), 25 );
	}

	public function enabled(): bool {
		return $this->settings->yes( 'vcap_store_credit' );
	}

	/**
	 * The customer's credit account, optionally created on first use.
	 */
	public function account( int $user_id, bool $create = false ): ?Voucher {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND type = 'credit' ORDER BY id ASC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore
		if ( $row ) {
			return Voucher::from_row( $row );
		}
		if ( ! $create ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$v                 = new Voucher();
		$v->type           = 'credit';
		$v->user_id        = $user_id;
		$v->usage_mode     = 'balance';
		$v->discount_type  = 'fixed_cart';
		$v->currency       = (string) get_option( 'woocommerce_currency' );
		$v->buyer_email    = (string) $user->user_email;
		$v->buyer_name     = (string) $user->display_name;
		$v->send_to        = 'buyer';
		$v->order_item_id  = 0;
		$v->item_index     = $user_id; // Unique slot per customer (vouchers from orders use order item ids).
		$v->delivered_at   = $this->repo->now();
		$v->code           = CodeGenerator::unique(
			array(
				'prefix' => 'CREDIT',
				'length' => 12,
			),
			array( $this->coupons, 'code_exists' )
		);
		if ( ! $this->repo->insert( $v ) ) {
			// Created concurrently: return the existing account.
			return $this->account( $user_id, false );
		}
		$v->coupon_id = $this->coupons->create_coupon( $v, ProductConfig::DEFAULTS );
		$coupon       = new \WC_Coupon( $v->coupon_id );
		/* translators: %s: customer name */
		$coupon->set_description( sprintf( __( 'Store credit of %s (Voucher Captain)', 'voucher-captain' ), $v->buyer_name ) );
		$coupon->save();
		$this->repo->update( $v );
		$this->repo->log( $v->id, 'issued', 0.0, 0.0, __( 'Store credit account opened', 'voucher-captain' ) );
		return $v;
	}

	public function balance( int $user_id ): float {
		$account = $this->account( $user_id );
		return $account ? (float) $account->balance : 0.0;
	}

	/**
	 * Add (positive) or remove (negative) credit atomically. A reason is required.
	 *
	 * @return array{balance:float,amount:float}|\WP_Error
	 */
	public function adjust( int $user_id, float $amount, string $reason, int $order_id = 0, string $event = '' ) {
		$reason = trim( sanitize_text_field( $reason ) );
		$amount = round( $amount, wc_get_price_decimals() );
		if ( 0.0 === $amount || '' === $reason ) {
			return new \WP_Error( 'vcap_credit_input', __( 'Please enter an amount and a reason.', 'voucher-captain' ) );
		}
		$account = $this->account( $user_id, $amount > 0 );
		if ( ! $account ) {
			return new \WP_Error( 'vcap_credit_account', __( 'This customer has no store credit.', 'voucher-captain' ) );
		}
		if ( ! Install::tables_support_transactions() ) {
			return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$event = '' !== $event ? $event : ( $amount > 0 ? 'credit' : 'debit' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
		}
		try {
			$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $account->id ) ); // phpcs:ignore
			$fresh = $this->repo->get( $account->id );
			if ( ! $fresh || $fresh->balance + $amount < -0.00001 ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'vcap_insufficient_balance', __( 'The store credit is not sufficient.', 'voucher-captain' ) );
			}
			$fresh->balance = max( 0.0, round( $fresh->balance + $amount, wc_get_price_decimals() ) );
			if ( $fresh->balance > 0 && in_array( $fresh->status, array( Voucher::STATUS_REDEEMED, Voucher::STATUS_EXPIRED ), true ) ) {
				$fresh->status = Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, $event, abs( $amount ), $fresh->balance, $reason, $order_id ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
		if ( $fresh->coupon_id ) {
			$coupon = new \WC_Coupon( $fresh->coupon_id );
			if ( $coupon->get_id() ) {
				$coupon->set_amount( $fresh->balance );
				$coupon->save();
			}
		}
		do_action( 'vcap_store_credit_changed', $user_id, $amount, $fresh->balance, $event, $reason, $order_id );
		return array(
			'balance' => $fresh->balance,
			'amount'  => $amount,
		);
	}

	/**
	 * Refund part of an order as store credit. Explicit only: WooCommerce records the refund without paying money
	 * back, and the amount is credited to the customer's account.
	 *
	 * @return array{balance:float,amount:float}|\WP_Error
	 */
	public function refund_as_credit( \WC_Order $order, float $amount, string $reason ) {
		$user_id = (int) $order->get_customer_id();
		$reason  = trim( sanitize_text_field( $reason ) );
		$amount  = round( $amount, wc_get_price_decimals() );
		if ( ! $this->enabled() ) {
			return new \WP_Error( 'vcap_credit_disabled', __( 'Store credit is not enabled.', 'voucher-captain' ) );
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'vcap_credit_guest', __( 'Guest orders cannot be refunded as store credit.', 'voucher-captain' ) );
		}
		if ( $amount <= 0 || $amount > (float) $order->get_remaining_refund_amount() + 0.00001 || '' === $reason ) {
			return new \WP_Error( 'vcap_credit_input', __( 'Please enter a reason and an amount up to the remaining refundable total.', 'voucher-captain' ) );
		}
		$refund = wc_create_refund(
			array(
				'amount'         => $amount,
				/* translators: %s: reason */
				'reason'         => sprintf( __( 'Refunded as store credit: %s', 'voucher-captain' ), $reason ),
				'order_id'       => $order->get_id(),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		$credit = (float) apply_filters( 'vcap_amount_to_base_currency', $amount, $order->get_currency(), $order );
		$result = $this->adjust( $user_id, $credit, $reason, $order->get_id(), 'refund_credit' );
		if ( is_wp_error( $result ) ) {
			$refund->delete( true ); // No credit, no refund record.
			return $result;
		}
		$order->add_order_note(
			/* translators: %s: amount */
			sprintf( __( '%s refunded as store credit.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) )
		);
		return $result;
	}

	/**
	 * A credit coupon may only be used by its owner (cart: logged-in user; admin order: the order's customer).
	 */
	public function validate_owner( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return $valid;
		}
		$voucher = $this->repo->get_by_coupon( $coupon->get_id() );
		if ( ! $voucher || 'credit' !== $voucher->type ) {
			return $valid;
		}
		$user = $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order
			? (int) $discounts->get_object()->get_customer_id()
			: get_current_user_id();
		if ( ! $this->enabled() || $user !== $voucher->user_id ) {
			throw new \Exception( esc_html__( 'This store credit belongs to another customer account.', 'voucher-captain' ), 9030 );
		}
		if ( ! $this->settings->yes( 'vcap_credit_with_coupons' ) && $this->has_discount_codes( $discounts ) ) {
			throw new \Exception( esc_html__( 'Store credit cannot be combined with discount codes.', 'voucher-captain' ), 9031 );
		}
		return $valid;
	}

	/**
	 * Whether the cart/order also uses discount codes (gift cards, store credit and promotions do not count).
	 */
	private function has_discount_codes( $discounts ): bool {
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		$codes  = $object instanceof \WC_Order ? $object->get_coupon_codes() : ( WC()->cart ? WC()->cart->get_applied_coupons() : array() );
		foreach ( $codes as $code ) {
			if ( \VCAP\Discounts\CartIntegration::id_from_code( (string) $code ) ) {
				continue;
			}
			$id = wc_get_coupon_id_by_code( (string) $code );
			if ( $id && 'yes' !== get_post_meta( $id, '_vcap_voucher', true ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ customer UI.

	private function apply_url(): string {
		return wp_nonce_url( add_query_arg( 'vcap_apply_credit', '1', wc_get_cart_url() ), 'vcap_apply_credit' );
	}

	public function apply_from_link(): void {
		if ( empty( $_GET['vcap_apply_credit'] ) || ! $this->enabled() || ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'vcap_apply_credit' ) ) {
			$account = $this->account( get_current_user_id() );
			if ( $account && $account->balance > 0 && ! WC()->cart->has_discount( $account->code ) ) {
				WC()->cart->apply_coupon( $account->code );
			}
		}
		wp_safe_redirect( remove_query_arg( array( 'vcap_apply_credit', '_wpnonce' ) ) );
		exit;
	}

	public function cart_notice(): void {
		if ( ! $this->enabled() || ! is_user_logged_in() || ! WC()->cart ) {
			return;
		}
		$account = $this->account( get_current_user_id() );
		if ( ! $account || $account->balance <= 0 || WC()->cart->has_discount( $account->code ) ) {
			return;
		}
		wc_print_notice(
			sprintf(
				/* translators: 1: balance, 2: link */
				__( 'You have %1$s store credit. %2$s', 'voucher-captain' ),
				wc_price( $account->balance, array( 'currency' => $account->currency ) ),
				'<a class="button" href="' . esc_url( $this->apply_url() ) . '">' . esc_html__( 'Use store credit', 'voucher-captain' ) . '</a>'
			),
			'notice'
		);
	}

	public function account_block(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$account = $this->account( get_current_user_id() );
		if ( ! $account ) {
			return;
		}
		$labels = VoucherRepository::event_labels();
		echo '<section class="vcap-store-credit"><h2>' . esc_html__( 'Store credit', 'voucher-captain' ) . '</h2>';
		echo '<p><strong>' . wp_kses_post( wc_price( $account->balance, array( 'currency' => $account->currency ) ) ) . '</strong> · ';
		/* translators: %s: coupon code */
		echo esc_html( sprintf( __( 'Code for the cart: %s', 'voucher-captain' ), $account->code ) );
		if ( $account->balance > 0 ) {
			echo ' · <a href="' . esc_url( $this->apply_url() ) . '">' . esc_html__( 'Use store credit', 'voucher-captain' ) . '</a>';
		}
		echo '</p><table class="woocommerce-table shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'Date', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Event', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Amount', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Balance after', 'voucher-captain' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( array_reverse( $this->repo->events( $account->id ) ), 0, 25 ) as $e ) {
			if ( null === $e['amount'] || 'issued' === $e['event'] ) {
				continue;
			}
			echo '<tr><td>' . esc_html( get_date_from_gmt( $e['created_at'], get_option( 'date_format' ) ) ) . '</td><td>' . esc_html( $labels[ $e['event'] ] ?? $e['event'] ) . '</td><td>' . wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $account->currency ) ) ) . '</td><td>' . ( null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $account->currency ) ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}
}
