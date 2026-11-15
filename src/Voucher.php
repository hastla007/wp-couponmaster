<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * An issued voucher / gift card / ticket. Coupon restrictions live on the linked WooCommerce coupon;
 * lifecycle, delivery, personalisation and redemption state live here.
 */
final class Voucher {
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_REDEEMED  = 'redeemed';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_VOID      = 'void';
	public const STATUS_SCHEDULED = 'scheduled';
	/** Locked by an external redemption that is not committed yet (see API\V1\RedemptionService). */
	public const STATUS_RESERVED  = 'reserved';

	public int $id               = 0;
	public string $code          = '';
	public int $coupon_id        = 0;
	public string $type          = 'voucher';
	public string $status        = self::STATUS_ACTIVE;
	public int $order_id         = 0;
	public int $order_item_id    = 0;
	public int $item_index       = 0;
	public int $product_id       = 0;
	public int $variation_id     = 0;
	public int $template_id      = 0;
	public string $usage_mode    = 'single';
	public string $discount_type = 'fixed_cart';
	public float $initial_amount = 0.0;
	public float $balance        = 0.0;
	public string $currency      = '';
	public ?string $expires_at   = null;
	public string $buyer_name    = '';
	public string $buyer_email   = '';
	/** Owner of a store credit account (type 'credit'). */
	public int $user_id          = 0;
	public string $recipient_name  = '';
	public string $recipient_email = '';
	public string $message       = '';
	/** @var array<string, array{label:string,value:string}> */
	public array $fields         = array();
	public string $send_to       = 'buyer';
	public ?string $delivery_at  = null;
	public ?string $delivered_at = null;
	public string $token         = '';
	/** Whether someone other than buyer/recipient may redeem it (snapshot at purchase). */
	public bool $transferable    = true;
	/** Redemption conditions as agreed at purchase (snapshot, plain text). */
	public string $terms         = '';
	public int $max_checkins     = 1;
	public int $checkins         = 0;
	public ?string $last_checkin_at = null;
	public string $created_at    = '';
	public string $updated_at    = '';

	public static function statuses(): array {
		return array(
			self::STATUS_ACTIVE    => __( 'Active', 'voucher-captain' ),
			self::STATUS_SCHEDULED => __( 'Scheduled', 'voucher-captain' ),
			self::STATUS_RESERVED  => __( 'Reserved', 'voucher-captain' ),
			self::STATUS_REDEEMED  => __( 'Redeemed', 'voucher-captain' ),
			self::STATUS_EXPIRED   => __( 'Expired', 'voucher-captain' ),
			self::STATUS_VOID      => __( 'Void', 'voucher-captain' ),
		);
	}

	/**
	 * Voucher types. Connector plugins may add their own (e.g. "course"); such vouchers behave like regular
	 * vouchers (coupon + PDF) and can be targeted in the "vcap_redemption_allowed" filter. "ticket" and "credit"
	 * have built-in special handling and cannot be removed.
	 */
	public static function types(): array {
		$core  = array(
			'voucher'  => __( 'Voucher', 'voucher-captain' ),
			'giftcard' => __( 'Gift card', 'voucher-captain' ),
			'ticket'   => __( 'Ticket', 'voucher-captain' ),
			'credit'   => __( 'Store credit', 'voucher-captain' ),
		);
		$extra = (array) apply_filters( 'vcap_voucher_types', array() );
		foreach ( $extra as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && strlen( $key ) <= 20 && ! isset( $core[ $key ] ) ) {
				$core[ $key ] = (string) $label;
			}
		}
		return $core;
	}

	public static function from_row( array $row ): self {
		$v = new self();
		foreach ( $row as $key => $value ) {
			if ( ! property_exists( $v, $key ) ) {
				continue;
			}
			switch ( $key ) {
				case 'id':
				case 'coupon_id':
				case 'order_id':
				case 'order_item_id':
				case 'item_index':
				case 'product_id':
				case 'variation_id':
				case 'template_id':
				case 'user_id':
				case 'max_checkins':
				case 'checkins':
					$v->$key = (int) $value;
					break;
				case 'transferable':
					$v->transferable = (bool) (int) $value;
					break;
				case 'initial_amount':
				case 'balance':
					$v->$key = (float) $value;
					break;
				case 'fields':
					$decoded   = $value ? json_decode( (string) $value, true ) : array();
					$v->fields = is_array( $decoded ) ? $decoded : array();
					break;
				case 'expires_at':
				case 'delivery_at':
				case 'delivered_at':
				case 'last_checkin_at':
					$v->$key = $value ? (string) $value : null;
					break;
				default:
					$v->$key = (string) $value;
			}
		}
		return $v;
	}

	public function to_row(): array {
		$row = get_object_vars( $this );
		unset( $row['id'] );
		$row['fields'] = wp_json_encode( $this->fields );
		$row['transferable'] = $this->transferable ? 1 : 0;
		return $row;
	}

	/** Decimal places for booking this voucher's amounts: ISO 4217 minor units, not the shop's display setting. */
	public function decimals(): int {
		return API\V1\RedemptionService::decimals( '' !== $this->currency ? $this->currency : (string) get_option( 'woocommerce_currency' ) );
	}

	public function is_ticket(): bool {
		return 'ticket' === $this->type;
	}

	/**
	 * M12-10: whether the voucher carries money: every voucher but a ticket, and a ticket with a value above 0
	 * (sold with a fixed value). Such tickets are settled and reported like value vouchers (PaymentVouchers,
	 * means of payment, Ledger::VALUE_VOUCHERS); a ticket without a value is an admission pass only.
	 */
	public function has_cash_value(): bool {
		return ! $this->is_ticket() || $this->initial_amount > 0;
	}

	public function is_multi_use(): bool {
		return 'balance' === $this->usage_mode;
	}

	public function is_expired(): bool {
		return $this->expires_at && strtotime( $this->expires_at . ' UTC' ) < time();
	}

	public function is_usable(): bool {
		return in_array( $this->status, array( self::STATUS_ACTIVE, self::STATUS_SCHEDULED ), true ) && ! $this->is_expired();
	}

	public function product(): ?\WC_Product {
		$product = wc_get_product( $this->variation_id ?: $this->product_id );
		return $product instanceof \WC_Product ? $product : null;
	}

	public function order(): ?\WC_Order {
		if ( $this->order_id && isset( self::$primed_orders[ $this->order_id ] ) ) {
			return self::$primed_orders[ $this->order_id ];
		}
		$order = $this->order_id ? wc_get_order( $this->order_id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/** @var array<int, \WC_Order> orders loaded together for a list (prime_orders()), until forget_orders() */
	private static array $primed_orders = array();

	/**
	 * B15: loads the orders of a voucher list with one query (not one wc_get_order() per row). Only for rendering
	 * a list: call forget_orders() afterwards, so later code reads the orders fresh.
	 *
	 * @param Voucher[] $vouchers
	 */
	public static function prime_orders( array $vouchers ): void {
		$ids = array_values( array_unique( array_filter( array_map( static fn( $v ) => (int) $v->order_id, $vouchers ) ) ) );
		if ( ! $ids || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		foreach ( (array) wc_get_orders( array( 'post__in' => $ids, 'limit' => count( $ids ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			if ( $order instanceof \WC_Order ) {
				self::$primed_orders[ $order->get_id() ] = $order;
			}
		}
	}

	/** Forgets the orders of prime_orders(). */
	public static function forget_orders(): void {
		self::$primed_orders = array();
	}

	public function delivery_email(): string {
		return 'recipient' === $this->send_to && is_email( $this->recipient_email ) ? $this->recipient_email : $this->buyer_email;
	}

	public function status_label(): string {
		if ( self::STATUS_ACTIVE === $this->status && $this->is_expired() ) {
			return self::statuses()[ self::STATUS_EXPIRED ];
		}
		return self::statuses()[ $this->status ] ?? $this->status;
	}

	/**
	 * Human readable transferability, from the restriction that is in effect: the email addresses on the
	 * WooCommerce coupon (the snapshot flag only for vouchers without a coupon, e.g. tickets).
	 */
	public function transferable_label(): string {
		$emails = null;
		if ( $this->coupon_id && class_exists( '\WC_Coupon' ) ) {
			$coupon = new \WC_Coupon( $this->coupon_id );
			$emails = $coupon->get_id() ? array_map( 'strtolower', (array) $coupon->get_email_restrictions() ) : null;
		}
		if ( null === $emails ) {
			return $this->transferable
				? __( 'Transferable – anyone with the code can redeem it.', 'voucher-captain' )
				: __( 'Personal – only the buyer or the named recipient can redeem it.', 'voucher-captain' );
		}
		if ( ! $emails ) {
			return __( 'Transferable – anyone with the code can redeem it.', 'voucher-captain' );
		}
		$buyer     = '' !== $this->buyer_email && in_array( strtolower( $this->buyer_email ), $emails, true );
		$recipient = '' !== $this->recipient_email && in_array( strtolower( $this->recipient_email ), $emails, true );
		if ( $recipient && ! $buyer ) {
			return __( 'Personal – only the named recipient can redeem it.', 'voucher-captain' ) . ' ' . PersonalVoucher::login_hint();
		}
		if ( $buyer && ! $recipient ) {
			return __( 'Personal – only the buyer can redeem it.', 'voucher-captain' ) . ' ' . PersonalVoucher::login_hint();
		}
		if ( ! $buyer && ! $recipient ) {
			return __( 'Personal – only the email addresses stored with the voucher can redeem it.', 'voucher-captain' ) . ' ' . PersonalVoucher::login_hint();
		}
		return __( 'Personal – only the buyer or the named recipient can redeem it.', 'voucher-captain' ) . ' ' . PersonalVoucher::login_hint();
	}

	public function field( string $key ): string {
		return isset( $this->fields[ $key ]['value'] ) ? (string) $this->fields[ $key ]['value'] : '';
	}
}
