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
		return round( (float) apply_filters( 'vcap_voucher_amount', $amount, $item, $config, $order ), wc_get_price_decimals() );
	}

	private function create_voucher( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product, array $config, int $index ): ?Voucher {
		$voucher                = new Voucher();
		$voucher->type          = in_array( $config['type'], array_keys( Voucher::types() ), true ) ? $config['type'] : 'voucher';
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
		$voucher->currency      = get_woocommerce_currency();
		$voucher->initial_amount = $this->voucher_amount( $order, $item, $config );
		$voucher->balance       = $voucher->initial_amount;
		$voucher->expires_at    = ProductConfig::expiry_for( $config, $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time() );
		$voucher->buyer_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$voucher->buyer_email   = (string) $order->get_billing_email();
		$voucher->max_checkins  = (int) $config['max_checkins'];
		$voucher->transferable  = 'no' !== $config['transferable'];
		$voucher->terms         = ProductConfig::terms( $config );

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
		if ( $from_pool ) {
			$this->pool->attach( $code, $voucher->id );
		}

		$create_coupon = 'ticket' !== $voucher->type || $voucher->initial_amount > 0;
		if ( apply_filters( 'vcap_create_coupon', $create_coupon, $voucher, $config ) ) {
			$voucher->coupon_id = $this->coupons->create_coupon( $voucher, $config );
			if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
				// Not redeemable before the gift is delivered.
				$this->coupons->set_coupon_enabled( $voucher, false );
			}
			$this->repo->update( $voucher );
		}
		$this->repo->log( $voucher->id, 'issued', $voucher->initial_amount, $voucher->balance, '', $order->get_id() );
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

	public function void( Voucher $voucher, string $note = '' ): void {
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return;
		}
		$voucher->status = Voucher::STATUS_VOID;
		$this->repo->update( $voucher );
		$this->coupons->set_coupon_enabled( $voucher, false );
		$this->repo->log( $voucher->id, 'voided', null, $voucher->balance, $note );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $voucher->id ), 'vcap' );
		}
	}

	public function reactivate( Voucher $voucher ): void {
		$voucher->status = $voucher->is_multi_use() && $voucher->balance <= 0 ? Voucher::STATUS_REDEEMED : Voucher::STATUS_ACTIVE;
		$this->repo->update( $voucher );
		$this->coupons->set_coupon_enabled( $voucher, true );
		$this->repo->log( $voucher->id, 'reactivated', null, $voucher->balance );
	}
}
