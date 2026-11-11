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

	public static function types(): array {
		return array(
			'voucher'  => __( 'Voucher', 'voucher-captain' ),
			'giftcard' => __( 'Gift card', 'voucher-captain' ),
			'ticket'   => __( 'Ticket', 'voucher-captain' ),
			'credit'   => __( 'Store credit', 'voucher-captain' ),
		);
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

	public function is_ticket(): bool {
		return 'ticket' === $this->type;
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
		$order = $this->order_id ? wc_get_order( $this->order_id ) : null;
		return $order instanceof \WC_Order ? $order : null;
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

	/** Human readable transferability. */
	public function transferable_label(): string {
		return $this->transferable
			? __( 'Transferable – anyone with the code can redeem it.', 'voucher-captain' )
			: __( 'Personal – only the buyer or the named recipient can redeem it.', 'voucher-captain' );
	}

	public function field( string $key ): string {
		return isset( $this->fields[ $key ]['value'] ) ? (string) $this->fields[ $key ]['value'] : '';
	}
}
