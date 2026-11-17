<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Net amount of a stored balance in a tax-exclusive shop (TODO T8, T15).
 *
 * WooCommerce spreads a fixed cart coupon by quantity over the items' prices *after* the coupons applied before
 * it, and then removes each item's VAT at that item's rate. What the customer saves (net + VAT) therefore depends
 * on where the coupon lands – lines with different rates, earlier discounts – and on tax rounding; a single
 * net/gross ratio of the undiscounted subtotals is wrong as soon as rates are mixed and another code was applied.
 *
 * This class replays WooCommerce's own fixed cart distribution (the protected methods of WC_Discounts) on the items
 * the coupon applies to, at their discounted prices, computes the VAT the discount removes exactly as the cart
 * (unrounded sum, rounded once) or the order (rounded per line unless "round at subtotal") does, and picks the
 * largest net amount whose gross saving does not exceed the balance: exactly the balance whenever the tax rounding
 * allows it, otherwise one minor unit below (CouponManager::write_off_rounding_rest() books such a rest off).
 *
 * Amounts are in minor units (cents), like WC_Discounts.
 */
final class GrossBalanceSplit extends \WC_Discounts {
	/** @var array<string|int, array> Item key => WC_Tax rates of the item (empty: not taxed). */
	private array $rates = array();
	private \WC_Coupon $coupon;
	private bool $round_per_line;
	private int $cap = 0;

	/**
	 * @param \WC_Coupon            $coupon    The balance coupon.
	 * @param array                 $items     Items the coupon applies to (WC_Discounts items).
	 * @param \WC_Discounts|null    $discounts The running discount calculation (for the prices after earlier coupons).
	 */
	public function __construct( \WC_Coupon $coupon, array $items, ?\WC_Discounts $discounts = null ) {
		parent::__construct();
		$this->coupon         = $coupon;
		$this->object         = $discounts ? $discounts->get_object() : null;
		$this->round_per_line = $this->object instanceof \WC_Order && 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' );
		$this->items          = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->key, $item->price ) ) {
				continue;
			}
			$copy        = clone $item;
			$copy->price = $discounts ? $discounts->get_discounted_price_in_cents( $item ) : (int) round( (float) $item->price );
			if ( $copy->price <= 0 ) {
				continue;
			}
			$this->items[ $copy->key ] = $copy;
			$this->rates[ $copy->key ] = self::item_rates( $item );
			$this->cap                += (int) $copy->price;
		}
	}

	/** Whether VAT is removed from any of the items at all (nothing to convert otherwise). */
	public function has_tax(): bool {
		return (bool) array_filter( $this->rates );
	}

	/** Tax rates of a WC_Discounts item in the WC_Tax format; empty when the item carries no VAT. */
	private static function item_rates( $item ): array {
		if ( isset( $item->subtotal_tax, $item->tax_rates ) ) { // Cart totals item.
			$taxable = isset( $item->product ) && $item->product instanceof \WC_Product && $item->product->is_taxable();
			return $taxable && (float) $item->subtotal_tax > 0 ? (array) $item->tax_rates : array();
		}
		if ( isset( $item->object ) && $item->object instanceof \WC_Order_Item_Product ) {
			$line = $item->object;
			if ( 'taxable' !== $line->get_tax_status() || (float) $line->get_subtotal_tax() <= 0 ) {
				return array();
			}
			$rates = array();
			foreach ( (array) ( $line->get_taxes()['subtotal'] ?? array() ) as $rate_id => $amount ) {
				$rate = \WC_Tax::_get_tax_rate( (int) $rate_id );
				if ( is_array( $rate ) && isset( $rate['tax_rate'] ) && '' !== (string) $amount ) {
					$rates[ (int) $rate_id ] = array(
						'rate'     => (float) $rate['tax_rate'],
						'label'    => (string) ( $rate['tax_rate_name'] ?? '' ),
						'shipping' => 'no',
						'compound' => ! empty( $rate['tax_rate_compound'] ) ? 'yes' : 'no',
					);
				}
			}
			return $rates;
		}
		return array();
	}

	/** Gross saving (net discount + the VAT it removes), in minor units, of a net coupon amount in minor units. */
	public function gross( int $net ): int {
		$code              = $this->coupon->get_code();
		$this->discounts   = array( $code => array_fill_keys( array_keys( $this->items ), 0 ) );
		if ( $net <= 0 || ! $this->items ) {
			return 0;
		}
		$applied = (int) round( (float) $this->apply_coupon_fixed_cart( $this->coupon, $this->items, $net ) );
		$tax     = 0.0;
		foreach ( $this->discounts[ $code ] as $key => $discount ) {
			if ( $discount <= 0 || empty( $this->rates[ $key ] ) ) {
				continue;
			}
			$line = (float) array_sum( \WC_Tax::calc_tax( $discount, $this->rates[ $key ], false ) );
			$tax += $this->round_per_line ? (float) wc_round_tax_total( $line, 0 ) : $line;
		}
		return $applied + (int) round( $tax );
	}

	/**
	 * The largest net amount (minor units) whose gross saving is at most $gross: exactly $gross where the rounding
	 * allows it. When the balance exceeds what the items can take, everything is discounted.
	 */
	public function net_for( int $gross ): int {
		if ( $gross <= 0 || $this->cap <= 0 ) {
			return 0;
		}
		$full = $this->gross( $this->cap );
		if ( $full <= $gross ) {
			return $this->cap;
		}
		$net = (int) floor( $gross * $this->cap / max( 1, $full ) );
		// The ratio estimate is within a few minor units; walk to the exact amount (gross() rises with net).
		for ( $i = 0; $i < 1000 && $net > 0 && $this->gross( $net ) > $gross; $i++ ) {
			--$net;
		}
		for ( $i = 0; $i < 1000 && $net < $this->cap && $this->gross( $net + 1 ) <= $gross; $i++ ) {
			++$net;
		}
		return $net;
	}
}
