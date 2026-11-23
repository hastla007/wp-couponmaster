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
		// Before CouponManager::validate() (20): another customer's credit must not reveal its balance or state (AP-S1-3).
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_owner' ), 19, 3 );
		add_action( 'vcap_account_vouchers_top', array( $this, 'account_block' ) );
		add_action( 'woocommerce_before_cart', array( $this, 'cart_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'cart_notice' ) );
		add_action( 'wp_loaded', array( $this, 'apply_from_link' ), 25 );
	}

	public function enabled(): bool {
		return $this->settings->yes( 'vcap_store_credit' );
	}

	/**
	 * The customer's credit account, optionally created on first use. $repair false: only read (no missing coupon
	 * is created again), e.g. while answering a Store API GET request.
	 */
	public function account( int $user_id, bool $create = false, bool $repair = true ): ?Voucher {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND type = 'credit' ORDER BY id ASC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore
		if ( $row ) {
			$account = Voucher::from_row( $row );
			// An account whose WooCommerce coupon is missing (failed save, deleted by hand) cannot be used in
			// the cart: give it a coupon again.
			return ! $repair || ( $account->coupon_id && get_post_status( $account->coupon_id ) ) ? $account : $this->repair_coupon( $account );
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
		$v->code           = CodeIndex::unique(
			array(
				'prefix' => 'CREDIT',
				'length' => 12,
			)
		);
		if ( ! $this->repo->insert( $v ) ) {
			// Created concurrently: return the existing account.
			return $this->account( $user_id, false );
		}
		if ( ! $this->attach_coupon( $v ) ) {
			// No usable coupon: undo the account, the next attempt opens it again.
			$this->repo->delete( $v->id );
			return null;
		}
		$this->repo->log( $v->id, 'issued', 0.0, 0.0, __( 'Store credit account opened', 'voucher-captain' ) );
		return $v;
	}

	/** Creates the account's WooCommerce coupon and stores its id; false (and no coupon left) on failure. */
	private function attach_coupon( Voucher $v ): bool {
		/* translators: %s: customer name */
		$description = sprintf( __( 'Store credit of %s (Voucher Captain)', 'voucher-captain' ), $v->buyer_name );
		$coupon_id   = $this->coupons->create_coupon( $v, ProductConfig::DEFAULTS, $description );
		// Verify the stored post itself: after a failed insert WooCommerce can still report an id.
		if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) || 0 !== strcasecmp( (string) get_post_field( 'post_title', $coupon_id ), $v->code ) ) {
			return false;
		}
		$v->coupon_id = $coupon_id;
		// M12-22: only the coupon id – the balance of $v may be stale (a booking can land meanwhile).
		if ( ! $this->repo->update_columns( $v, array( 'coupon_id' ) ) ) {
			wp_delete_post( $coupon_id, true );
			$v->coupon_id = 0;
			return false;
		}
		return true;
	}

	/** Gives an existing account a coupon again (its balance is kept); the account is returned either way. */
	private function repair_coupon( Voucher $account ): Voucher {
		$existing = wc_get_coupon_id_by_code( $account->code );
		if ( $existing ) {
			$account->coupon_id = (int) $existing;
			$this->repo->update_columns( $account, array( 'coupon_id' ) ); // M12-22: never the (possibly stale) balance.
		} elseif ( $this->attach_coupon( $account ) ) {
			$this->repo->log( $account->id, 'edited', null, null, __( 'Store credit coupon was missing and has been created again.', 'voucher-captain' ) );
		}
		return $account;
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
		$amount = round( $amount, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
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
		$event = '' !== $event ? $event : ( $amount > 0 ? 'credit' : 'debit' );
		if ( ! Db::begin() ) {
			return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
		}
		try {
			$fresh = $this->repo->get_for_update( (int) $account->id ); // M12-04: the balance as committed now, under the lock.
			if ( ! $fresh || $fresh->balance + $amount < -0.00001 ) {
				Db::rollback_if_error( 'store_credit_adjust', array( 'user_id' => $user_id, 'order_id' => $order_id ) );
				return new \WP_Error( 'vcap_insufficient_balance', __( 'The store credit is not sufficient.', 'voucher-captain' ) );
			}
			$fresh->balance = max( 0.0, round( $fresh->balance + $amount, $fresh->decimals() ) );
			if ( $fresh->balance > 0 && in_array( $fresh->status, array( Voucher::STATUS_REDEEMED, Voucher::STATUS_EXPIRED ), true ) ) {
				$fresh->status = Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, $event, abs( $amount ), $fresh->balance, $reason, $order_id ) ) {
				Db::rollback_if_error( 'store_credit_adjust', array( 'user_id' => $user_id, 'order_id' => $order_id ) );
				return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'store_credit_adjust', array( 'user_id' => $user_id, 'order_id' => $order_id ) );
				return new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'store_credit_adjust', array( 'user_id' => $user_id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		CouponManager::sync_coupon( $fresh, array( 'amount' ) );
		/**
		 * The store credit of a customer changed (args: user id, amount, balance after, event, reason, order id,
		 * context array with journal_id = ledger id of this booking (since contract 1.7.0, VC-12; register 7 args)).
		 */
		Db::action( 'vcap_store_credit_changed', $user_id, $amount, $fresh->balance, $event, $reason, $order_id, array( 'journal_id' => $this->repo->last_log_id( (int) $fresh->id, $event ) ) );
		return array(
			'balance' => $fresh->balance,
			'amount'  => $amount,
		);
	}

	/** How the customer agreed to store credit instead of money (recorded with every refund as credit). */
	public static function consent_channels(): array {
		return array(
			'email'     => __( 'By email', 'voucher-captain' ),
			'phone'     => __( 'By phone', 'voucher-captain' ),
			'written'   => __( 'In writing (letter, form)', 'voucher-captain' ),
			'in_person' => __( 'In person', 'voucher-captain' ),
			'account'   => __( 'In the customer account', 'voucher-captain' ),
		);
	}

	/**
	 * Refund part of an order as store credit. Explicit only: WooCommerce records the refund without paying money
	 * back, and the amount is credited to the customer's account. A money refund is the customer's right, so the
	 * customer's consent to credit instead is required and recorded (how, note, who recorded it, when) on the order
	 * (meta _vcap_credit_consents), on the refund and as an order note.
	 *
	 * Runs under a lock per order (two tabs, a resubmitted form): the remaining refundable total is read, the credit
	 * booked and the refund recorded by one request at a time. $request_key (one per form, see request_key())
	 * makes a resubmission of the same form a no-op instead of a second credit.
	 *
	 * @param array{channel?:string, note?:string} $consent     How the customer agreed (see consent_channels()).
	 * @param string                               $request_key One-time key of the submitted form ('' = none).
	 * @return array{balance:float,amount:float,voucher_id?:int,code?:string}|\WP_Error Guest orders (M12-57): the
	 *         new gift card's id and code.
	 */
	public function refund_as_credit( \WC_Order $order, float $amount, string $reason, array $consent = array(), string $request_key = '' ) {
		global $wpdb;
		$lock = Install::lock_name( 'refund_credit_' . $order->get_id() );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'vcap_credit_busy', __( 'Another refund of this order is being recorded right now. Please reload the order and check its refunds before trying again.', 'voucher-captain' ) );
		}
		$claim = '' !== $request_key ? 'refund-credit:' . $order->get_id() . ':' . sanitize_key( $request_key ) : '';
		try {
			if ( '' !== $claim && ! Claim::take( $claim ) ) {
				return new \WP_Error( 'vcap_credit_duplicate', __( 'This refund as store credit was already submitted and recorded. Reload the order to see it.', 'voucher-captain' ) );
			}
			// Fresh from the database: the remaining refundable total may have changed since the page was loaded.
			$fresh  = wc_get_order( $order->get_id() );
			$result = $this->refund_as_credit_locked( $fresh instanceof \WC_Order ? $fresh : $order, $amount, $reason, $consent );
			if ( is_wp_error( $result ) && '' !== $claim ) {
				Claim::release( $claim ); // Nothing was booked: the corrected form may be sent again.
			}
			return $result;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * M12-57: address a guest order is refunded to as a new gift card ('' = not possible). Filter
	 * "vcap_refund_guest_as_gift_card" (default true) turns refunds of guest orders as gift card off.
	 */
	public static function guest_gift_card_email( \WC_Order $order ): string {
		$email = sanitize_email( (string) $order->get_billing_email() );
		/** Whether a guest order can be refunded as a new gift card instead of store credit (default true). */
		return '' !== $email && is_email( $email ) && apply_filters( 'vcap_refund_guest_as_gift_card', true, $order ) ? $email : '';
	}

	/**
	 * M12-11: refund lines for a refund of $amount without a choice of items: every line, fee and shipping line
	 * gets its share of what is still refundable of it, net and per tax rate, so the refund carries the VAT it pays
	 * back (correction of the sale's VAT). The rounding rest goes onto the last line. A refund of the whole remaining
	 * total returns the items as well (their quantities), so the order counts as returned completely (M12-02).
	 *
	 * @return array<int, array{qty:int, refund_total:float, refund_tax:array<int, float>}>
	 */
	public static function refund_lines( \WC_Order $order, float $amount ): array {
		$decimals = wc_get_price_decimals();
		$lines    = array();
		$open     = 0.0;
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product && ! $item instanceof \WC_Order_Item_Fee && ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$type  = $item->get_type();
			$net   = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item_id, $type ) );
			$taxes = $item->get_taxes();
			$tax   = array();
			foreach ( (array) ( $taxes['total'] ?? array() ) as $rate_id => $value ) {
				if ( '' !== $value && null !== $value ) {
					$tax[ $rate_id ] = (float) $value - abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id, $type ) );
				}
			}
			$gross = $net + array_sum( $tax );
			if ( $gross <= 0 ) {
				continue;
			}
			$lines[ (int) $item_id ] = array( $net, $tax );
			$open                   += $gross;
		}
		if ( $open <= 0 || $amount <= 0 ) {
			return array();
		}
		$factor = min( 1.0, $amount / $open );
		$all    = $amount + 0.5 / ( 10 ** $decimals ) >= (float) $order->get_remaining_refund_amount();
		$result = array();
		$sum    = 0.0;
		foreach ( $lines as $item_id => list( $net, $tax ) ) {
			$total              = round( $net * $factor, $decimals );
			$taxes              = array_map( static fn( float $value ): float => round( $value * $factor, $decimals ), $tax );
			$item               = $order->get_item( $item_id );
			$result[ $item_id ] = array(
				'qty'          => $all && $item instanceof \WC_Order_Item_Product ? max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) ) : 0,
				'refund_total' => $total,
				'refund_tax'   => $taxes,
			);
			$sum               += $total + array_sum( $taxes );
		}
		$rest = round( $amount - $sum, $decimals );
		$last = array_key_last( $result );
		if ( null !== $last && 0.0 !== $rest ) {
			$result[ $last ]['refund_total'] = round( $result[ $last ]['refund_total'] + $rest, $decimals );
		}
		return $result;
	}

	/**
	 * M12-37: a refund recorded by refund_as_credit() was deleted on the order screen – the credit it paid out is
	 * taken back (ledger "refund_credit_reversed"), a guest's gift card voided while unused. What was spent already
	 * cannot be taken back: the shop gets a notice (Attention).
	 */
	public function reverse_refund( \WC_Order $order, int $refund_id ): void {
		$consents = $order->get_meta( '_vcap_credit_consents' );
		if ( ! is_array( $consents ) ) {
			return;
		}
		$changed = false;
		foreach ( $consents as $index => $record ) {
			if ( ! is_array( $record ) || (int) ( $record['refund_id'] ?? 0 ) !== $refund_id || ! empty( $record['reversed'] ) ) {
				continue;
			}
			$credit = isset( $record['credit'] ) ? (float) $record['credit'] : (float) ( Compat\MultiCurrency::to_base( (float) ( $record['amount'] ?? 0 ), (string) $order->get_currency(), $order ) ?? 0.0 );
			$open   = $this->take_back( $order, $record, $credit );
			if ( $open > 0.00001 ) {
				$text = sprintf(
					/* translators: 1: refund id, 2: amount that could not be taken back */
					__( 'Refund #%1$d (refunded as store credit or gift card) was deleted, but %2$s of the credit was already spent and could not be taken back. Settle it with the customer.', 'voucher-captain' ),
					$refund_id,
					wp_strip_all_tags( wc_price( $open ) )
				);
				$order->add_order_note( $text );
				Attention::add( $order->get_id(), 'credit-reversed:' . $refund_id, $text );
			}
			$consents[ $index ]['reversed'] = gmdate( 'Y-m-d H:i:s' );
			$changed                        = true;
		}
		if ( $changed ) {
			$order->update_meta_data( '_vcap_credit_consents', $consents );
			$order->save_meta_data();
		}
	}

	/** Takes back the credit of a deleted refund; returns what could not be taken back (spent). */
	private function take_back( \WC_Order $order, array $record, float $credit ): float {
		/* translators: %s: order number */
		$reason = sprintf( __( 'Refund of order #%s deleted', 'voucher-captain' ), $order->get_order_number() );
		if ( ! empty( $record['voucher_id'] ) ) {
			$card = $this->repo->get( (int) $record['voucher_id'] );
			if ( $card && Voucher::STATUS_VOID === $card->status ) {
				return 0.0;
			}
			if ( $card && (float) $card->balance + 0.00001 >= (float) $card->initial_amount && vcap()->issuer->void( $card, $reason ) ) {
				$order->add_order_note( sprintf( /* translators: %s: gift card code */ __( 'Gift card %s of the deleted refund was voided.', 'voucher-captain' ), Mask::code( $card->code ) ) );
				return 0.0;
			}
			return $credit;
		}
		$user_id = (int) ( $record['user_id'] ?? $order->get_customer_id() );
		$take    = min( $credit, $user_id > 0 ? $this->balance( $user_id ) : 0.0 );
		if ( $take > 0 ) {
			$result = $this->adjust( $user_id, -$take, $reason, $order->get_id(), 'refund_credit_reversed' );
			if ( is_wp_error( $result ) ) {
				return $credit;
			}
			$order->add_order_note( sprintf( /* translators: %s: amount */ __( '%s of store credit taken back (the refund was deleted).', 'voucher-captain' ), wp_strip_all_tags( wc_price( $take ) ) ) );
		}
		return max( 0.0, round( $credit - $take, 6 ) );
	}

	/** One-time key for a "refund as store credit" form (see refund_as_credit()). */
	public static function request_key(): string {
		return function_exists( 'wp_generate_uuid4' ) ? str_replace( '-', '', wp_generate_uuid4() ) : bin2hex( random_bytes( 16 ) );
	}

	/** @return array{balance:float,amount:float,voucher_id?:int,code?:string}|\WP_Error */
	private function refund_as_credit_locked( \WC_Order $order, float $amount, string $reason, array $consent ) {
		$user_id = (int) $order->get_customer_id();
		$reason  = trim( sanitize_text_field( $reason ) );
		$channel = sanitize_key( (string) ( $consent['channel'] ?? '' ) );
		if ( ! isset( self::consent_channels()[ $channel ] ) ) {
			return new \WP_Error( 'vcap_credit_consent', __( 'Record how the customer agreed to store credit instead of a money refund.', 'voucher-captain' ) );
		}
		$amount  = round( $amount, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
		if ( ! $this->enabled() ) {
			return new \WP_Error( 'vcap_credit_disabled', __( 'Store credit is not enabled.', 'voucher-captain' ) );
		}
		$guest_email = $user_id <= 0 ? self::guest_gift_card_email( $order ) : '';
		if ( $user_id <= 0 && '' === $guest_email ) {
			return new \WP_Error( 'vcap_credit_guest', __( 'Guest orders cannot be refunded as store credit.', 'voucher-captain' ) );
		}
		if ( $amount <= 0 || $amount > (float) $order->get_remaining_refund_amount() + 0.00001 || '' === $reason ) {
			return new \WP_Error( 'vcap_credit_input', __( 'Please enter a reason and an amount up to the remaining refundable total.', 'voucher-captain' ) );
		}
		// Credit first: a full refund sets the order to "refunded" right away (gift cards restored, vouchers
		// voided, connected tickets cancelled), which cannot be undone by deleting the refund afterwards.
		// A credit that turns out to have no refund is simply booked back.
		$credit = Compat\MultiCurrency::to_base( $amount, (string) $order->get_currency(), $order );
		if ( null === $credit ) {
			// Store credit is kept in the base currency: a foreign-currency refund is never credited 1:1.
			return new \WP_Error(
				'vcap_credit_currency',
				sprintf(
					/* translators: 1: order currency, 2: shop base currency */
					__( 'This order was paid in %1$s and no exchange rate to the shop currency %2$s is known, so it cannot be refunded as store credit. Refund the money instead, or set up the exchange rate first.', 'voucher-captain' ),
					(string) $order->get_currency(),
					(string) get_option( 'woocommerce_currency' )
				)
			);
		}
		$credit = round( $credit, API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
		$card   = null;
		if ( '' !== $guest_email ) {
			// M12-57: a guest has no account – the credit is a new gift card for the amount, mailed once the refund
			// is recorded (issued without email first, so a failed refund leaves nothing in the customer's inbox).
			$card = vcap()->issuer->issue_standalone(
				array(
					'type'        => 'giftcard',
					'usage'       => 'balance',
					'amount'      => $credit,
					'buyer_email' => $guest_email,
					'buyer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'send'        => false,
					'source'      => 'refund-credit',
				)
			);
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$result = array(
				'balance'    => (float) $card->balance,
				'amount'     => $credit,
				'voucher_id' => (int) $card->id,
				'code'       => (string) $card->code,
			);
		} else {
			$result = $this->adjust( $user_id, $credit, $reason, $order->get_id(), 'refund_credit' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$refund = wc_create_refund(
			array(
				'amount'         => $amount,
				'reason'         => sprintf(
					$card
						/* translators: %s: reason */
						? __( 'Refunded as a gift card: %s', 'voucher-captain' )
						/* translators: %s: reason */
						: __( 'Refunded as store credit: %s', 'voucher-captain' ),
					$reason
				),
				'order_id'       => $order->get_id(),
				// M12-11: the refund carries its share of every line and tax rate, so the VAT is corrected as well.
				'line_items'     => self::refund_lines( $order, $amount ),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			if ( $card ) {
				vcap()->issuer->void( $card, __( 'Refund as a gift card failed', 'voucher-captain' ) );
				return $refund;
			}
			$back = $this->adjust( $user_id, -$credit, __( 'Refund as store credit failed', 'voucher-captain' ), $order->get_id(), 'refund_credit_reversed' );
			if ( is_wp_error( $back ) ) {
				$order->add_order_note( __( 'Store credit was added but the refund could not be recorded, and the credit could not be booked back. Please check the customer\'s store credit.', 'voucher-captain' ) );
			}
			return $refund;
		}
		if ( $card ) {
			vcap()->delivery->reschedule( $card, null ); // Mailed now that the refund is recorded.
		}
		$record   = array(
			'refund_id'   => (int) $refund->get_id(),
			'amount'      => $amount,
			'credit'      => $credit,
			'user_id'     => $user_id,
			'voucher_id'  => $card ? (int) $card->id : 0,
			'channel'     => $channel,
			'note'        => mb_substr( sanitize_textarea_field( (string) ( $consent['note'] ?? '' ) ), 0, 500 ),
			'recorded_by' => get_current_user_id(),
			'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$refund->update_meta_data( '_vcap_credit_consent', $record );
		$refund->save_meta_data();
		$consents   = $order->get_meta( '_vcap_credit_consents' );
		$consents   = is_array( $consents ) ? $consents : array();
		$consents[] = $record;
		$order->update_meta_data( '_vcap_credit_consents', $consents );
		$order->save_meta_data();
		$order->add_order_note(
			( $card
				/* translators: 1: amount, 2: gift card code */
				? sprintf( __( '%1$s refunded as a new gift card %2$s (mailed to the customer).', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), Mask::code( $card->code ) )
				/* translators: %s: amount */
				: sprintf( __( '%s refunded as store credit.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) ) )
			. ' ' . sprintf(
				/* translators: 1: how the customer agreed (e.g. "By email"), 2: optional note */
				__( 'Customer consent to store credit: %1$s%2$s.', 'voucher-captain' ),
				self::consent_channels()[ $channel ],
				'' !== $record['note'] ? ' – ' . $record['note'] : ''
			)
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
			if ( PersonalVoucher::staff_context() ) {
				throw new \Exception( esc_html__( 'This store credit belongs to another customer account.', 'voucher-captain' ), 9030 );
			}
			// AP-S1-3: for customers it reads exactly like an unknown code (WooCommerce's own "does not exist"
			// message, a numeric message is turned into it by WC_Discounts), so the coupon field confirms nothing.
			// The error code 9030 still books the attempt as a failed guess (CouponGuard::error()).
			throw new \Exception( (string) \WC_Coupon::E_WC_COUPON_NOT_EXIST, 9030 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an integer.
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

	/**
	 * Link that applies the customer's store credit (apply_from_link()). A raw URL with "&", not wp_nonce_url()'s
	 * HTML-escaped "&amp;": the Store API hands it to the cart block as data and React puts it into href verbatim,
	 * so an escaped link would arrive as "amp;_wpnonce" and fail the nonce check. HTML output escapes it with esc_url().
	 */
	public function apply_url(): string {
		return add_query_arg(
			array(
				'vcap_apply_credit' => '1',
				'_wpnonce'          => wp_create_nonce( 'vcap_apply_credit' ),
			),
			wc_get_cart_url()
		);
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
				'<a class="button vcap-use-store-credit" href="' . esc_url( $this->apply_url() ) . '">' . esc_html__( 'Use store credit', 'voucher-captain' ) . '</a>'
			),
			'notice'
		);
		if ( GiftCardField::enabled() ) {
			GiftCardField::enqueue_script(); // M12-95: applied without reloading the page (the link stays the fallback).
		}
	}

	public function account_block(): void {
		static $shown = false;
		// Once per page; not in an editor's preview (it would show, or repair, the editor's own account).
		if ( $shown || ! $this->enabled() || Shortcodes::is_editor_preview() ) {
			return;
		}
		$account = $this->account( get_current_user_id() );
		if ( ! $account ) {
			return;
		}
		$shown  = true;
		$labels = VoucherRepository::event_labels();
		echo '<section class="vcap-store-credit"><h2>' . esc_html__( 'Store credit', 'voucher-captain' ) . '</h2>';
		echo '<p><strong>' . wp_kses_post( wc_price( $account->balance, array( 'currency' => $account->currency ) ) ) . '</strong> · ';
		/* translators: %s: coupon code */
		echo esc_html( sprintf( __( 'Code for the cart: %s', 'voucher-captain' ), $account->code ) );
		if ( $account->balance > 0 ) {
			echo ' · <a href="' . esc_url( $this->apply_url() ) . '">' . esc_html__( 'Use store credit', 'voucher-captain' ) . '</a>';
		}
		echo '</p>';
		$titles = array(
			'date'    => __( 'Date', 'voucher-captain' ),
			'event'   => __( 'Event', 'voucher-captain' ),
			'amount'  => __( 'Amount', 'voucher-captain' ),
			'balance' => __( 'Balance after', 'voucher-captain' ),
		);
		$rows   = '';
		// The newest 25 entries only, read through the (voucher_id, id) index (not the whole history).
		foreach ( array_reverse( $this->repo->events( $account->id, 25 ) ) as $e ) {
			if ( null === $e['amount'] || 'issued' === $e['event'] ) {
				continue;
			}
			$cells = array(
				'date'    => esc_html( LocalDate::from_utc( $e['created_at'], get_option( 'date_format' ) ) ),
				'event'   => esc_html( $labels[ $e['event'] ] ?? $e['event'] ),
				'amount'  => wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $account->currency ) ) ),
				'balance' => null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $account->currency ) ) ) : '',
			);
			$rows .= '<tr>';
			foreach ( $cells as $key => $cell ) {
				// data-title: the column name in front of the value on small screens (responsive table).
				$rows .= '<td data-title="' . esc_attr( $titles[ $key ] ) . '">' . $cell . '</td>';
			}
			$rows .= '</tr>';
		}
		if ( '' === $rows ) {
			echo '<p class="vcap-store-credit-empty">' . esc_html__( 'No store credit bookings yet.', 'voucher-captain' ) . '</p></section>';
			return;
		}
		echo '<table class="woocommerce-table shop_table shop_table_responsive vcap-store-credit-table"><thead><tr>';
		foreach ( $titles as $title ) {
			echo '<th scope="col">' . esc_html( $title ) . '</th>';
		}
		echo '</tr></thead><tbody>' . $rows . '</tbody></table></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- cells escaped above.
	}
}
