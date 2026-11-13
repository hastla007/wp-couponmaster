<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Turns purchased voucher products into vouchers + WooCommerce coupons.
 *
 * Idempotent: every voucher occupies a unique (order_item_id, item_index) slot, so re-running for the same
 * order (status flapping, manual re-issue, concurrent webhooks) never creates duplicates.
 */
final class Issuer {
	public function __construct(
		private Settings $settings,
		private VoucherRepository $repo,
		private CouponManager $coupons,
		private CodePool $pool,
		private Delivery $delivery
	) {}

	public function register(): void {
		foreach ( (array) $this->settings->get( 'vcap_issue_statuses' ) as $status ) {
			add_action( 'woocommerce_order_status_' . sanitize_key( $status ), array( $this, 'on_status' ), 20, 2 );
		}
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_purchase_cancelled' ), 30, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_purchase_cancelled' ), 30, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'guard_refund' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'on_partial_refund' ), 20, 2 );
		add_action( 'vcap_issue_order', array( $this, 'issue_async' ) );
	}

	public function on_status( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! apply_filters( 'vcap_should_issue_for_order', true, $order ) ) {
			return;
		}
		$this->issue_for_order( $order );
	}

	public function issue_async( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			$this->issue_for_order( $order );
		}
	}

	/**
	 * @return Voucher[] newly created vouchers
	 */
	public function issue_for_order( \WC_Order $order, bool $manual = false ): array {
		$created = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var \WC_Order_Item_Product $item */
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
				continue;
			}
			$config = ProductConfig::get( $product );
			$qty    = max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
			$count  = (int) apply_filters( 'vcap_vouchers_per_item', $qty * $config['per_item'], $item, $config );
			if ( $count > 0 ) {
				$this->record_line( $order, $item, $config, $count );
			}

			for ( $index = 0; $index < $count; $index++ ) {
				if ( $this->repo->get_slot( (int) $item_id, $index ) ) {
					continue;
				}
				$voucher = $this->create_voucher( $order, $item, $product, $config, $index );
				if ( $voucher ) {
					$created[] = $voucher;
				}
			}
		}

		if ( $created ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: count, 2: codes */
					_n( '%1$d voucher issued: %2$s', '%1$d vouchers issued: %2$s', count( $created ), 'voucher-captain' ),
					count( $created ),
					implode( ', ', wp_list_pluck( $created, 'code' ) )
				) . ( $manual ? ' ' . __( '(manual)', 'voucher-captain' ) : '' )
			);
			foreach ( $created as $voucher ) {
				$this->delivery->schedule( $voucher );
			}
			do_action( 'vcap_vouchers_issued', $created, $order );
		}
		return $created;
	}

	public const LINE_META = '_vcap_line';

	/**
	 * Snapshot of the voucher line at issue time: face value and currency of the vouchers, what was paid for the
	 * line (net, tax, after discounts) in the order currency, and the tax setup. Written once per line, so later
	 * product or tax changes never alter what was sold.
	 */
	private function record_line( \WC_Order $order, \WC_Order_Item_Product $item, array $config, int $count ): void {
		if ( is_array( $item->get_meta( self::LINE_META ) ) ) {
			return;
		}
		$product  = $item->get_product();
		$sale_tax = ProductConfig::line_tax( $order, $item );
		$core     = array(
			'version'            => 1,
			'voucher_type'       => (string) $config['type'],
			'discount_type'      => (string) $config['discount_type'],
			'amount_source'      => (string) $config['amount_source'],
			'face_value'         => $this->voucher_amount( $order, $item, $config ),
			// Base currency: switchers (WCML, Aelia, …) change get_woocommerce_currency() during the request.
			'voucher_currency'   => (string) get_option( 'woocommerce_currency' ),
			'vouchers'           => $count,
			'quantity'           => (int) $item->get_quantity(),
			'order_currency'     => $order->get_currency(),
			'subtotal'           => (float) $item->get_subtotal(),
			'subtotal_tax'       => (float) $item->get_subtotal_tax(),
			'total'              => (float) $item->get_total(),
			'total_tax'          => (float) $item->get_total_tax(),
			'tax_class'          => (string) $item->get_tax_class(),
			'tax_status'         => $product ? (string) $product->get_tax_status() : '',
			'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			// VAT of the sale (single-purpose vouchers: Pass Captain compares it with the goods it is redeemed for).
			'tax_rate_bp'        => $sale_tax['rate_bp'],
			'tax_country'        => $sale_tax['country'],
			// Kept as configured, even for channels whose plugin is not active right now (never widen access).
			'channels'           => array_values( array_filter( array_map( 'sanitize_key', (array) ( $config['channels'] ?? array() ) ) ) ),
			'recorded_at'        => time(),
		);
		/** Extra snapshot keys of connectors (e.g. the ticket a voucher is exchanged for); core keys always win. */
		$item->update_meta_data( self::LINE_META, $core + (array) apply_filters( 'vcap_line_snapshot', array(), $item, $config, $order ) );
		$item->save_meta_data();
	}

	/**
	 * The stored line snapshot (empty array for lines without vouchers).
	 */
	public static function line_data( \WC_Order_Item_Product $item ): array {
		$data = $item->get_meta( self::LINE_META );
		return is_array( $data ) ? $data : array();
	}

	private function voucher_amount( \WC_Order $order, \WC_Order_Item_Product $item, array $config ): float {
		if ( 'ticket' === $config['type'] && 'fixed' !== $config['amount_source'] ) {
			// Tickets are admission passes; they only carry a cash value when one is set explicitly.
			return 0.0;
		}
		$qty  = max( 1, (int) $item->get_quantity() );
		// Coupon amounts follow the shop's price mode: gross where prices include tax, net otherwise.
		$paid = (float) $item->get_subtotal() + ( wc_prices_include_tax() ? (float) $item->get_subtotal_tax() : 0.0 );
		switch ( $config['amount_source'] ) {
			case 'fixed':
				$amount = (float) wc_format_decimal( $config['amount'] );
				break;
			case 'custom':
				$custom = $item->get_meta( '_vcap_custom_amount' );
				$amount = '' !== (string) $custom ? (float) $custom : $paid / $qty;
				break;
			default:
				$amount = $paid / $qty / max( 1, (int) $config['per_item'] );
		}
		if ( 'percent' !== $config['discount_type'] && 'fixed' !== $config['amount_source'] ) {
			// Paid / entered in the order currency; coupons are stored in the shop base currency.
			$amount = (float) apply_filters( 'vcap_amount_to_base_currency', $amount, $order->get_currency(), $order );
		}
		// Vouchers are kept in the shop base currency, booked with its ISO 4217 decimals.
		return round( (float) apply_filters( 'vcap_voucher_amount', $amount, $item, $config, $order ), API\V1\RedemptionService::decimals( (string) get_option( 'woocommerce_currency' ) ) );
	}

	private function create_voucher( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product, array $config, int $index ): ?Voucher {
		$voucher                = new Voucher();
		$voucher->type          = isset( Voucher::types()[ $config['type'] ] ) && 'credit' !== $config['type'] ? $config['type'] : 'voucher';
		$voucher->order_id      = $order->get_id();
		$voucher->order_item_id = (int) $item->get_id();
		$voucher->item_index    = $index;
		$voucher->product_id    = (int) $item->get_product_id();
		$voucher->variation_id  = (int) $item->get_variation_id();
		$voucher->template_id   = (int) $config['template'];
		$voucher->discount_type = $config['discount_type'];
		$voucher->usage_mode    = 'percent' === $config['discount_type'] && 'balance' === $config['usage'] ? 'single' : $config['usage'];
		if ( 'balance' === $voucher->usage_mode ) {
			$voucher->discount_type = 'fixed_cart';
		}
		$voucher->currency      = (string) get_option( 'woocommerce_currency' ); // Amounts are in base currency.
		$voucher->initial_amount = $this->voucher_amount( $order, $item, $config );
		$voucher->balance       = $voucher->initial_amount;
		$voucher->expires_at    = ProductConfig::expiry_for( $config, $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time() );
		/** Expiry (UTC "Y-m-d H:i:s" or null) of a voucher being issued, e.g. no later than the event it is for. */
		$voucher->expires_at    = apply_filters( 'vcap_voucher_expires_at', $voucher->expires_at, $item, $config, $order );
		$voucher->buyer_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$voucher->buyer_email   = (string) $order->get_billing_email();
		$voucher->max_checkins  = (int) $config['max_checkins'];
		$voucher->transferable  = 'no' !== $config['transferable'];
		$voucher->terms         = ProductConfig::terms( $config, ProductConfig::country_of( $order ) );

		$fields                   = is_array( $item->get_meta( '_vcap_fields' ) ) ? $item->get_meta( '_vcap_fields' ) : array();
		$voucher->recipient_name  = (string) ( $fields['recipient_name']['value'] ?? '' );
		$voucher->recipient_email = sanitize_email( (string) ( $fields['recipient_email']['value'] ?? '' ) );
		$voucher->message         = (string) ( $fields['message']['value'] ?? '' );
		$voucher->fields          = $fields;

		$voucher->send_to     = $voucher->recipient_email && 'buyer' !== $config['send_to'] ? $config['send_to'] : 'buyer';
		$voucher->delivery_at = Delivery::compute_time( $config, $fields, $order );
		$voucher->status      = $voucher->delivery_at && strtotime( $voucher->delivery_at . ' UTC' ) > time() ? Voucher::STATUS_SCHEDULED : Voucher::STATUS_ACTIVE;

		// Code: imported batch first, generated fallback.
		$code      = '';
		$from_pool = false;
		if ( '' !== (string) $config['code_batch'] ) {
			$code      = (string) $this->pool->claim( (string) $config['code_batch'] );
			$from_pool = '' !== $code;
			if ( ! $from_pool ) {
				$order->add_order_note(
					/* translators: %s: batch name */
					sprintf( __( 'Voucher code batch "%s" is empty, a random code was generated instead.', 'voucher-captain' ), $config['code_batch'] )
				);
			}
		}
		if ( '' === $code ) {
			$code = CodeGenerator::unique(
				array(
					'prefix'    => '' !== (string) $config['code_prefix'] ? $config['code_prefix'] : $this->settings->get( 'vcap_code_prefix' ),
					'suffix'    => '' !== (string) $config['code_suffix'] ? $config['code_suffix'] : $this->settings->get( 'vcap_code_suffix' ),
					'length'    => (int) ( $config['code_length'] ?: $this->settings->get( 'vcap_code_length' ) ),
					'charset'   => $this->settings->get( 'vcap_code_charset' ),
					'separator' => $this->settings->get( 'vcap_code_separator' ),
					'order_ref' => $this->settings->yes( 'vcap_code_order_ref' ) ? $order->get_order_number() : '',
				),
				array( $this->coupons, 'code_exists' )
			);
		}
		$voucher->code = (string) apply_filters( 'vcap_voucher_code', $code, $order, $item, $index );

		if ( ! $this->repo->insert( $voucher ) ) {
			// Slot taken by a concurrent request or code collision.
			if ( $from_pool ) {
				$this->pool->release( $code );
			}
			return null;
		}
		if ( $from_pool && ! $this->pool->attach( $code, $voucher->id ) ) {
			// The code is taken (used_at set) but not linked: the pool list would show it unassigned. Say so.
			$this->repo->log( $voucher->id, 'note', null, null, __( 'Imported code could not be linked to this voucher in the code pool.', 'voucher-captain' ) );
		}

		$create_coupon = 'ticket' !== $voucher->type || $voucher->initial_amount > 0;
		if ( apply_filters( 'vcap_create_coupon', $create_coupon, $voucher, $config ) ) {
			$voucher->coupon_id = $this->coupons->create_coupon( $voucher, $config );
			if ( ! $voucher->coupon_id || ! $this->repo->update( $voucher ) ) {
				// Never leave a voucher without its WooCommerce coupon (the code would be sent but not work): undo
				// this slot so the next issuing run creates it again, and tell the shop.
				if ( $voucher->coupon_id ) {
					wp_delete_post( $voucher->coupon_id, true );
				}
				$this->repo->delete( $voucher->id );
				if ( $from_pool && ! $this->pool->release( $code ) ) {
					/* translators: %s: code */
					$order->add_order_note( sprintf( __( 'Imported code %s could not be returned to the code pool.', 'voucher-captain' ), $code ) );
				}
				/* translators: %s: product name */
				$order->add_order_note( sprintf( __( 'A voucher for "%s" could not be created (WooCommerce coupon not saved). It will be issued on the next attempt.', 'voucher-captain' ), $item->get_name() ) );
				return null;
			}
			if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
				// Not redeemable before the gift is delivered.
				$this->coupons->set_coupon_enabled( $voucher, false );
			}
		}
		if ( ! $this->repo->log( $voucher->id, 'issued', $voucher->initial_amount, $voucher->balance, '', $order->get_id() ) ) {
			$order->add_order_note( sprintf( /* translators: %s: code */ __( 'Voucher %s was issued, but its history entry could not be written.', 'voucher-captain' ), $voucher->code ) );
		}
		return $voucher;
	}

	/**
	 * Purchase order cancelled/refunded → void its vouchers.
	 */
	public function on_purchase_cancelled( $order_id, $order = null ): void {
		if ( ! $this->settings->yes( 'vcap_void_on_refund' ) ) {
			return;
		}
		foreach ( $this->repo->for_order( (int) $order_id ) as $voucher ) {
			$this->void( $voucher, __( 'Purchase order cancelled or refunded', 'voucher-captain' ) );
		}
	}

	/** Whether a voucher was (partly) used or is being redeemed right now. */
	private static function in_use( Voucher $voucher ): bool {
		return self::spent_value( $voucher ) > 0 || Voucher::STATUS_RESERVED === $voucher->status;
	}

	/** Value of a voucher that was already used (base currency). */
	public static function spent_value( Voucher $voucher ): float {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return 0.0;
		}
		if ( $voucher->is_multi_use() ) {
			return max( 0.0, (float) $voucher->initial_amount - (float) $voucher->balance );
		}
		return Voucher::STATUS_REDEEMED === $voucher->status ? (float) $voucher->initial_amount : 0.0;
	}

	/**
	 * Before a refund of a voucher purchase is saved: refunding more than the order minus the value already
	 * spent from its vouchers would pay back goods the customer already received. Such refunds are refused
	 * with the maximum amount (filter "vcap_allow_refund_of_spent_vouchers" to allow them knowingly).
	 *
	 * @param \WC_Order_Refund $refund Refund about to be saved.
	 * @param array            $args   wc_create_refund() arguments.
	 * @throws \Exception To stop the refund (WooCommerce shows the message).
	 */
	public function guard_refund( $refund, $args ): void {
		$order = wc_get_order( (int) ( $args['order_id'] ?? ( $refund instanceof \WC_Order_Refund ? $refund->get_parent_id() : 0 ) ) );
		if ( ! $order instanceof \WC_Order || ! $this->settings->yes( 'vcap_void_on_refund' ) ) {
			return;
		}
		$spent = 0.0;
		$codes = array();
		foreach ( $this->repo->for_order( $order->get_id() ) as $voucher ) {
			$used = self::spent_value( $voucher );
			if ( $used > 0 ) {
				$spent  += $used;
				$codes[] = $voucher->code;
			}
		}
		if ( $spent <= 0 ) {
			return;
		}
		// Balances are kept in the shop base currency: compare in the base currency, report in the order currency.
		// WooCommerce has already saved this refund (wc_create_refund() recalculates its taxes before the hook),
		// so only earlier refunds count as refunded.
		$current_id = $refund instanceof \WC_Order_Refund ? (int) $refund->get_id() : 0;
		$refunded   = 0.0;
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1 ) ) as $earlier ) {
			if ( (int) $earlier->get_id() !== $current_id ) {
				$refunded += abs( (float) $earlier->get_amount() );
			}
		}
		$remaining = (float) $order->get_total() - $refunded;
		$amount    = (float) ( $args['amount'] ?? 0 );
		$to_base   = static function ( float $value ) use ( $order ): float {
			return $order->get_currency() && $order->get_currency() !== get_option( 'woocommerce_currency' ) ? (float) apply_filters( 'vcap_amount_to_base_currency', $value, $order->get_currency(), $order ) : $value;
		};
		$remaining_base = $to_base( $remaining );
		$max_base       = $remaining_base - $spent;
		$decimals       = API\V1\RedemptionService::decimals( (string) $order->get_currency() );
		$tolerance      = 0.5 / ( 10 ** $decimals );
		if ( $to_base( $amount ) <= $max_base + $tolerance || apply_filters( 'vcap_allow_refund_of_spent_vouchers', false, $order, $amount, $spent ) ) {
			return;
		}
		$max   = $remaining_base > 0 ? round( $remaining * max( 0.0, $max_base ) / $remaining_base, $decimals ) : 0.0;
		$spent = $remaining_base > 0 ? $remaining * $spent / $remaining_base : $spent;
		throw new \Exception(
			sprintf(
				/* translators: 1: voucher codes, 2: spent value, 3: maximum refund */
				__( 'Vouchers from this order were already used (%1$s, value used: %2$s). At most %3$s can be refunded; the unused vouchers are voided.', 'voucher-captain' ),
				implode( ', ', $codes ),
				html_entity_decode( wp_strip_all_tags( wc_price( $spent, array( 'currency' => $order->get_currency() ) ) ) ),
				html_entity_decode( wp_strip_all_tags( wc_price( max( 0, $max ), array( 'currency' => $order->get_currency() ) ) ) )
			)
		);
	}

	/**
	 * Partial refund of voucher lines: as many vouchers as the refunded quantity covers are voided, unused
	 * ones first (newest first). Used vouchers are only voided when no unused one is left for that line.
	 */
	public function on_partial_refund( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->settings->yes( 'vcap_void_on_refund' ) ) {
			return;
		}
		$by_item = array();
		foreach ( $this->repo->for_order( $order->get_id() ) as $voucher ) {
			$by_item[ (int) $voucher->order_item_id ][] = $voucher;
		}
		if ( ! $by_item ) {
			return;
		}
		// Refunded quantities straight from the refund documents: the order's cached refund list was read by
		// wc_create_refund() before the new refund was saved.
		$refunded = array();
		$refunds  = wc_get_orders(
			array(
				'type'   => 'shop_order_refund',
				'parent' => $order->get_id(),
				'limit'  => -1,
			)
		);
		foreach ( $refunds as $refund ) {
			foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
				$original = (int) $refund_item->get_meta( '_refunded_item_id' );
				if ( $original ) {
					$refunded[ $original ] = ( $refunded[ $original ] ?? 0 ) + abs( (int) $refund_item->get_quantity() );
				}
			}
		}
		foreach ( $by_item as $item_id => $vouchers ) {
			$item = $order->get_item( $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product || $item->get_quantity() < 1 ) {
				continue;
			}
			$refunded_qty = (int) ( $refunded[ $item_id ] ?? 0 );
			$per_unit     = max( 1, (int) round( count( $vouchers ) / $item->get_quantity() ) );
			$void_target  = min( count( $vouchers ), $refunded_qty * $per_unit );
			$voided       = count( array_filter( $vouchers, static fn( Voucher $v ) => Voucher::STATUS_VOID === $v->status ) );
			if ( $voided >= $void_target ) {
				continue;
			}
			$open = array_values( array_filter( $vouchers, static fn( Voucher $v ) => Voucher::STATUS_VOID !== $v->status ) );
			usort(
				$open,
				static fn( Voucher $a, Voucher $b ) => array( self::in_use( $a ) ? 1 : 0, -$a->item_index ) <=> array( self::in_use( $b ) ? 1 : 0, -$b->item_index )
			);
			foreach ( array_slice( $open, 0, $void_target - $voided ) as $voucher ) {
				/* translators: %d: refund id */
				$this->void( $voucher, sprintf( __( 'Partial refund #%d of the purchase order', 'voucher-captain' ), (int) $refund_id ) );
				$order->add_order_note(
					sprintf(
						/* translators: %s: voucher code */
						__( 'Voucher %s voided (refunded).', 'voucher-captain' ),
						$voucher->code
					)
				);
			}
		}
	}

	/** @return bool False when the voucher could not be voided (nothing logged, the coupon stays as it was). */
	public function void( Voucher $voucher, string $note = '' ): bool {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return true;
		}
		if ( ! $this->repo->update_status( $voucher->id, Voucher::STATUS_VOID ) ) {
			return false;
		}
		$voucher->status = Voucher::STATUS_VOID;
		if ( ! $this->coupons->set_coupon_enabled( $voucher, false ) ) {
			// The voucher is void but its coupon still works: say so loudly instead of pretending success.
			$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, trim( $note . ' ' . __( '(WooCommerce coupon could not be disabled – disable it manually)', 'voucher-captain' ) ) );
			return false;
		}
		$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, $note );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $voucher->id ), 'vcap' );
		}
		return true;
	}

	/** @return bool False when the status or the coupon could not be restored. */
	public function reactivate( Voucher $voucher ): bool {
		$fresh   = $this->repo->get( $voucher->id ) ?? $voucher;
		$coupon  = $fresh->coupon_id ? new \WC_Coupon( $fresh->coupon_id ) : null;
		$used_up = $fresh->is_multi_use()
			? $fresh->balance <= 0
			: ( $coupon && $coupon->get_id() && $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() );
		// A single-use voucher that was already redeemed (shop or externally) stays redeemed.
		$status = $used_up ? Voucher::STATUS_REDEEMED : Voucher::STATUS_ACTIVE;
		if ( ! $this->repo->update_status( $voucher->id, $status ) ) {
			return false;
		}
		$voucher->status  = $status;
		$voucher->balance = $fresh->balance;
		$enabled          = $this->coupons->set_coupon_enabled( $voucher, true );
		$this->repo->log( $voucher->id, 'reactivated', null, $voucher->balance, $enabled ? '' : __( 'WooCommerce coupon could not be enabled – enable it manually', 'voucher-captain' ) );
		return $enabled;
	}
}
