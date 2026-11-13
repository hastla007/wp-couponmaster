<?php
namespace VCAP\Discounts;

use VCAP\FirstOrderCoupons;
use VCAP\ProductConfig;
use VCAP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs discount campaigns through WooCommerce's own discount engine.
 *
 * Every active campaign is a virtual coupon "vcap-campaign-{id}" of the custom type "vcap_campaign". Before cart
 * totals are calculated, the matching campaigns are added to (and non-matching ones removed from) the applied
 * coupons; WooCommerce then asks for the discount per line, which the Engine computes. Taxes, rounding, order
 * coupon lines and refunds therefore behave exactly like regular coupons, and catalog prices never change.
 */
final class CartIntegration {
	public const TYPE   = 'vcap_campaign';
	public const PREFIX = 'vcap-campaign-';

	/** @var array<string, array> items WooCommerce applies a campaign coupon to, per code */
	private array $items = array();
	/** @var array<string, bool> */
	private static array $previous = array();
	private bool $syncing = false;

	public function __construct( private Settings $settings, private Repository $campaigns ) {}

	public function register(): void {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'types' ) );
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'virtual_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'remember_items' ), 20, 2 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'discounts' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 26, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart' ), 30 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'order_item' ), 10, 4 );
	}

	/**
	 * Whether new promotions are applied (setting and premium right "promotions"). Off: no promotion is added to a
	 * cart and no bonus voucher is issued; existing orders keep their promotion discount when recalculated.
	 */
	public function enabled(): bool {
		return $this->settings->yes( 'vcap_campaigns_enabled' ) && \VCAP\Licensing\Entitlements::allows( 'promotions' );
	}

	public static function code( int $id ): string {
		return self::PREFIX . $id;
	}

	public static function id_from_code( string $code ): int {
		return preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(\d+)$/', wc_format_coupon_code( $code ), $m ) ? (int) $m[1] : 0;
	}

	public function types( $types ) {
		$types[ self::TYPE ] = __( 'Discount campaign (Voucher Captain)', 'voucher-captain' );
		return $types;
	}

	public function virtual_coupon( $data, $code ) {
		$id = self::id_from_code( (string) $code );
		if ( ! $id || ! $this->campaigns->get( $id ) ) {
			return $data;
		}
		return array(
			'discount_type'  => self::TYPE,
			'amount'         => 0,
			'individual_use' => false,
			'description'    => $this->campaigns->get( $id )['title'],
		);
	}

	/** The Engine decides per line; WooCommerce must hand every cart line to the campaign coupon. */
	public function valid_for_product( $valid, $product, $coupon ) {
		return $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ? true : $valid;
	}

	public function remember_items( $items, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ) {
			$this->items[ $coupon->get_code() ] = is_array( $items ) ? $items : array();
		}
		return $items;
	}

	/**
	 * Context for rule evaluation (cart or order).
	 */
	public function context( $object ): array {
		if ( $object instanceof \WC_Order ) {
			$user    = (int) $object->get_customer_id();
			$email   = (string) $object->get_billing_email();
			$exclude = (int) $object->get_id();
			$coupons = $object->get_coupon_codes();
			$created = $object->get_date_created();
			$now     = $created ? $created->getTimestamp() : time();
		} else {
			$now     = time();
			$user    = get_current_user_id();
			$email   = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
			$exclude = WC()->session ? (int) WC()->session->get( 'order_awaiting_payment' ) : 0;
			$coupons = WC()->cart ? WC()->cart->get_applied_coupons() : array();
		}
		$before = $object instanceof \WC_Order ? $now : 0;
		$key    = $user . '|' . strtolower( $email ) . '|' . $exclude . '|' . $before;
		if ( ! isset( self::$previous[ $key ] ) ) {
			self::$previous[ $key ] = ( $user || is_email( $email ) ) && FirstOrderCoupons::has_previous_order( $user, $email, $exclude, $before );
		}
		$others     = array_values( array_filter( array_map( 'strval', $coupons ), static fn( $c ) => ! self::id_from_code( $c ) ) );
		$individual = false;
		foreach ( $others as $code ) {
			$other = new \WC_Coupon( $code );
			if ( $other->get_id() && $other->get_individual_use() ) {
				$individual = true;
				break;
			}
		}
		$userdata = $user ? get_userdata( $user ) : null;
		return array(
			'now'                => $now,
			'logged_in'          => $user > 0,
			'roles'              => $userdata ? (array) $userdata->roles : array(),
			'has_previous_order' => self::$previous[ $key ],
			'other_coupons'      => $others,
			'individual_use_coupon' => $individual,
			'allow_vouchers'     => $this->settings->yes( 'vcap_discounts_on_vouchers' ),
			'decimals'           => wc_get_price_decimals(),
		);
	}

	/** Customer of a cart or order: [ user id, billing email ]. */
	private static function customer( $object ): array {
		if ( $object instanceof \WC_Order ) {
			return array( (int) $object->get_customer_id(), (string) $object->get_billing_email() );
		}
		return array( get_current_user_id(), WC()->customer ? (string) WC()->customer->get_billing_email() : '' );
	}

	private static function usage_blocker( array $campaign, $object ): string {
		list( $user, $email ) = self::customer( $object );
		return Usage::blocker( $campaign, $user, $email );
	}

	public static function line( string $key, \WC_Product $product, int $qty, float $unit ): array {
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return array(
			'key'        => $key,
			'product_id' => $product->get_id(),
			'parent_id'  => $parent,
			'categories' => wc_get_product_cat_ids( $parent ),
			'unit'       => $unit,
			'qty'        => $qty,
			'is_voucher' => ProductConfig::is_voucher( $product ),
		);
	}

	/**
	 * Lines from WooCommerce discount items (prices in cents for the whole line).
	 */
	private static function lines_from_items( array $items ): array {
		$lines = array();
		foreach ( $items as $item ) {
			if ( ! isset( $item->product ) || ! $item->product instanceof \WC_Product || (int) $item->quantity <= 0 ) {
				continue;
			}
			$lines[] = self::line( (string) $item->key, $item->product, (int) $item->quantity, wc_remove_number_precision( (float) $item->price ) / (int) $item->quantity );
		}
		return $lines;
	}

	/**
	 * Per-line discounts for a campaign coupon (WooCommerce stores them in cents).
	 */
	public function discounts( $discounts, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return $discounts;
		}
		$items  = $this->items[ $coupon->get_code() ] ?? array();
		$out    = array_fill_keys( array_keys( (array) $discounts ), 0 );
		$object = WC()->cart;
		foreach ( $items as $item ) {
			if ( isset( $item->object ) && $item->object instanceof \WC_Order_Item ) {
				$object = $item->object->get_order();
				break;
			}
		}
		$is_order = $object instanceof \WC_Order;
		$id       = self::id_from_code( $coupon->get_code() );
		// An existing order keeps its promotion when it is recalculated later, even if the promotion has ended,
		// was unpublished or promotions were switched off meanwhile.
		$campaign = $this->campaigns->get( $id ) ?? ( $is_order ? $this->campaigns->get_any( $id ) : null );
		if ( ! $campaign || ! $items || ( ! $is_order && ! $this->enabled() ) ) {
			return $out;
		}
		// All promotions of this cart/order, in priority order: each takes only what the earlier ones left.
		$codes  = $is_order ? $object->get_coupon_codes() : ( $object ? $object->get_applied_coupons() : array() );
		$stack  = array();
		foreach ( $codes as $code ) {
			$other_id = self::id_from_code( (string) $code );
			$other    = $other_id ? ( $this->campaigns->get( $other_id ) ?? ( $is_order ? $this->campaigns->get_any( $other_id ) : null ) ) : null;
			if ( $other ) {
				$stack[] = $other;
			}
		}
		usort( $stack, static fn( $a, $b ) => array( $a['priority'], $a['id'] ) <=> array( $b['priority'], $b['id'] ) );
		$rules = array();
		foreach ( $stack as $entry ) {
			$rules[ $entry['id'] ] = $entry['rules'];
		}
		$rules  += array( $campaign['id'] => $campaign['rules'] );
		$amounts = Engine::stack( $rules, self::lines_from_items( $items ), $this->context( $object ) )[ $campaign['id'] ] ?? array();
		foreach ( $items as $item ) {
			$key = (string) $item->key;
			if ( isset( $amounts[ $key ] ) ) {
				$out[ $key ] = min( (float) $item->price, wc_add_number_precision( $amounts[ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * A campaign coupon is only valid while the campaign is active and its rules match.
	 */
	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return $valid;
		}
		$campaign = $this->campaigns->get( self::id_from_code( $coupon->get_code() ) );
		if ( ! $this->enabled() || ! $campaign ) {
			throw new \Exception( esc_html__( 'This promotion is not active.', 'voucher-captain' ), 9040 );
		}
		if ( $discounts instanceof \WC_Discounts ) {
			$lines = self::lines_from_items( $discounts->get_items_to_validate() );
			$object = $discounts->get_object();
			$usage  = self::usage_blocker( $campaign, $object );
			if ( '' !== $usage ) {
				throw new \Exception( esc_html( self::usage_message( $usage ) ), 9042 );
			}
			if ( ! Engine::discounts( $campaign['rules'], $lines, $this->context( $object ) ) ) {
				throw new \Exception( esc_html__( 'The conditions of this promotion are not met.', 'voucher-captain' ), 9041 );
			}
		}
		return $valid;
	}

	public static function usage_message( string $reason ): string {
		return 'usage_limit_customer' === $reason
			? __( 'You have already used this promotion the maximum number of times.', 'voucher-captain' )
			: __( 'This promotion has reached its usage limit.', 'voucher-captain' );
	}

	/**
	 * Add matching campaigns to the cart and remove the ones that no longer match.
	 */
	public function sync_cart( $cart ): void {
		if ( $this->syncing || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$this->syncing = true;
		try {
			$applied = $cart->get_applied_coupons();
			$others  = array_values( array_filter( $applied, static fn( $c ) => ! self::id_from_code( (string) $c ) ) );
			$desired = array();
			if ( $this->enabled() && ! $cart->is_empty() ) {
				$lines = array();
				foreach ( $cart->get_cart() as $key => $item ) {
					if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
						$lines[] = self::line( (string) $key, $item['data'], (int) $item['quantity'], (float) $item['data']->get_price() );
					}
				}
				$available = array_values( array_filter( $this->campaigns->active(), static fn( $c ) => '' === self::usage_blocker( $c, $cart ) ) );
				foreach ( Engine::select( $available, $lines, $this->context( $cart ) ) as $id ) {
					$desired[] = self::code( $id );
				}
			}
			$new = array_merge( $others, $desired );
			if ( $new !== $applied ) {
				$cart->set_applied_coupons( $new );
			}
		} finally {
			$this->syncing = false;
		}
	}

	public function label( $label, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ) {
			$campaign = $this->campaigns->get( self::id_from_code( $coupon->get_code() ) );
			return $campaign ? $campaign['title'] : $label;
		}
		return $label;
	}

	public function coupon_html( $html, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ) {
			// Automatic promotions cannot be removed by the customer (they would be re-applied anyway).
			return (string) preg_replace( '#\s*<a [^>]*woocommerce-remove-coupon[^>]*>.*?</a>#s', '', (string) $html );
		}
		return $html;
	}

	public function order_item( $item, $code, $coupon, $order ): void {
		$id = self::id_from_code( (string) $code );
		$campaign = $id ? $this->campaigns->get( $id ) : null;
		if ( $campaign ) {
			$item->add_meta_data( '_vcap_campaign_id', $id, true );
			$item->add_meta_data( '_vcap_campaign_title', $campaign['title'], true );
		}
	}
}
