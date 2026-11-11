<?php
namespace VCAP\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rule engine for discount campaigns (no WordPress/WooCommerce calls), used by the cart, the admin preview
 * and the tests.
 *
 * A line is: [ 'key' => string, 'product_id' => int, 'parent_id' => int (variations), 'categories' => int[], 'unit' => float, 'qty' => int,
 *              'is_voucher' => bool ]
 * The context is: [ 'now' => int, 'logged_in' => bool, 'roles' => string[], 'has_previous_order' => bool,
 *                   'other_coupons' => string[], 'allow_vouchers' => bool, 'decimals' => int ]
 */
final class Engine {
	public const ACTIONS   = array( 'percent', 'fixed', 'tiered', 'bxgy', 'bonus' );
	public const BONUS_TYPES = array( 'percent', 'fixed_cart' );
	public const CUSTOMERS = array( 'any', 'logged_in', 'roles', 'first_order', 'returning' );

	/**
	 * Normalise stored rules and fill defaults.
	 */
	public static function normalize( array $r ): array {
		$ints = static fn( $v ) => array_values( array_filter( array_map( 'intval', (array) $v ) ) );
		$tiers = array();
		foreach ( (array) ( $r['tiers'] ?? array() ) as $tier ) {
			$min = (int) ( $tier['min_qty'] ?? 0 );
			$pct = (float) ( $tier['percent'] ?? 0 );
			if ( $min > 0 && $pct > 0 ) {
				$tiers[] = array( 'min_qty' => $min, 'percent' => min( 100.0, $pct ) );
			}
		}
		usort( $tiers, static fn( $a, $b ) => $a['min_qty'] <=> $b['min_qty'] );
		return array(
			'action'          => in_array( $r['action'] ?? '', self::ACTIONS, true ) ? $r['action'] : 'percent',
			'percent'         => max( 0.0, min( 100.0, (float) ( $r['percent'] ?? 0 ) ) ),
			'amount'          => max( 0.0, (float) ( $r['amount'] ?? 0 ) ),
			'tiers'           => $tiers,
			'buy_qty'         => max( 1, (int) ( $r['buy_qty'] ?? 2 ) ),
			'get_qty'         => max( 1, (int) ( $r['get_qty'] ?? 1 ) ),
			'get_percent'     => max( 0.0, min( 100.0, (float) ( $r['get_percent'] ?? 100 ) ) ),
			'repeat'          => ! empty( $r['repeat'] ),
			'products'        => $ints( $r['products'] ?? array() ),
			'categories'      => $ints( $r['categories'] ?? array() ),
			'exclude_products'   => $ints( $r['exclude_products'] ?? array() ),
			'exclude_categories' => $ints( $r['exclude_categories'] ?? array() ),
			'min_subtotal'    => max( 0.0, (float) ( $r['min_subtotal'] ?? 0 ) ),
			'min_qty'         => max( 0, (int) ( $r['min_qty'] ?? 0 ) ),
			'customer'        => in_array( $r['customer'] ?? '', self::CUSTOMERS, true ) ? $r['customer'] : 'any',
			'roles'           => array_values( array_filter( array_map( 'strval', (array) ( $r['roles'] ?? array() ) ) ) ),
			'date_from'       => (int) ( $r['date_from'] ?? 0 ),
			'date_to'         => (int) ( $r['date_to'] ?? 0 ),
			'stackable'       => ! empty( $r['stackable'] ),
			'with_coupons'    => ! isset( $r['with_coupons'] ) || ! empty( $r['with_coupons'] ),
			'usage_limit'              => max( 0, (int) ( $r['usage_limit'] ?? 0 ) ),
			'usage_limit_per_customer' => max( 0, (int) ( $r['usage_limit_per_customer'] ?? 0 ) ),
			'bonus_type'               => in_array( $r['bonus_type'] ?? '', self::BONUS_TYPES, true ) ? $r['bonus_type'] : 'fixed_cart',
			'bonus_amount'             => max( 0.0, (float) ( $r['bonus_amount'] ?? 0 ) ),
			'bonus_days'               => max( 0, (int) ( $r['bonus_days'] ?? 90 ) ),
			'bonus_min_spend'          => max( 0.0, (float) ( $r['bonus_min_spend'] ?? 0 ) ),
			'bonus_trigger'            => 'gift_card' === ( $r['bonus_trigger'] ?? '' ) ? 'gift_card' : 'order',
		);
	}

	/**
	 * Lines the campaign may discount.
	 */
	public static function eligible( array $rules, array $lines, array $context ): array {
		return array_values(
			array_filter(
				$lines,
				static function ( $line ) use ( $rules, $context ) {
					if ( (int) $line['qty'] <= 0 || (float) $line['unit'] <= 0 ) {
						return false;
					}
					if ( ! empty( $line['is_voucher'] ) && empty( $context['allow_vouchers'] ) ) {
						return false;
					}
					$cats = array_map( 'intval', (array) ( $line['categories'] ?? array() ) );
					// A rule on a variable product covers all of its variations.
					$ids = array_unique( array( (int) $line['product_id'], (int) ( $line['parent_id'] ?? $line['product_id'] ) ) );
					if ( array_intersect( $ids, $rules['exclude_products'] ) || array_intersect( $cats, $rules['exclude_categories'] ) ) {
						return false;
					}
					if ( ! $rules['products'] && ! $rules['categories'] ) {
						return true;
					}
					return (bool) array_intersect( $ids, $rules['products'] ) || (bool) array_intersect( $cats, $rules['categories'] );
				}
			)
		);
	}

	/**
	 * Why the campaign does not apply, or '' when it does.
	 */
	public static function blocker( array $rules, array $lines, array $context ): string {
		$now = (int) ( $context['now'] ?? time() );
		if ( $rules['date_from'] && $now < $rules['date_from'] ) {
			return 'not_started';
		}
		if ( $rules['date_to'] && $now > $rules['date_to'] ) {
			return 'ended';
		}
		if ( ( ! $rules['with_coupons'] && ! empty( $context['other_coupons'] ) ) || ! empty( $context['individual_use_coupon'] ) ) {
			return 'other_coupon'; // A coupon marked "individual use only" never shares the cart with promotions.
		}
		switch ( $rules['customer'] ) {
			case 'logged_in':
				if ( empty( $context['logged_in'] ) ) {
					return 'customer';
				}
				break;
			case 'roles':
				if ( ! array_intersect( $rules['roles'], (array) ( $context['roles'] ?? array() ) ) ) {
					return 'customer';
				}
				break;
			case 'first_order':
				if ( ! empty( $context['has_previous_order'] ) ) {
					return 'customer';
				}
				break;
			case 'returning':
				if ( empty( $context['has_previous_order'] ) ) {
					return 'customer';
				}
				break;
		}
		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			if ( ! empty( $line['is_voucher'] ) && empty( $context['allow_vouchers'] ) ) {
				continue; // Buying vouchers never unlocks a promotion on other products.
			}
			$subtotal += (float) $line['unit'] * (int) $line['qty'];
		}
		if ( $rules['min_subtotal'] > 0 && $subtotal + 0.00001 < $rules['min_subtotal'] ) {
			return 'min_subtotal';
		}
		$eligible = self::eligible( $rules, $lines, $context );
		if ( ! $eligible ) {
			return 'no_items';
		}
		$qty = array_sum( array_map( static fn( $l ) => (int) $l['qty'], $eligible ) );
		if ( $rules['min_qty'] > 0 && $qty < $rules['min_qty'] ) {
			return 'min_qty';
		}
		if ( 'tiered' === $rules['action'] && null === self::tier( $rules, $qty ) ) {
			return 'min_qty';
		}
		if ( 'bxgy' === $rules['action'] && $qty < $rules['buy_qty'] + $rules['get_qty'] ) {
			return 'min_qty';
		}
		return '';
	}

	/**
	 * Why an order does not earn the bonus voucher of a "bonus" promotion ('' when it does).
	 * With the trigger "gift_card" only voucher/gift card lines count.
	 */
	public static function bonus_blocker( array $rules, array $lines, array $context ): string {
		if ( 'bonus' !== $rules['action'] || $rules['bonus_amount'] <= 0 ) {
			return 'no_items';
		}
		if ( 'gift_card' === $rules['bonus_trigger'] ) {
			$lines                     = array_values( array_filter( $lines, static fn( $l ) => ! empty( $l['is_voucher'] ) ) );
			$context['allow_vouchers'] = true;
		}
		return self::blocker( $rules, $lines, $context );
	}

	private static function tier( array $rules, int $qty ): ?array {
		$found = null;
		foreach ( $rules['tiers'] as $tier ) {
			if ( $qty >= $tier['min_qty'] ) {
				$found = $tier;
			}
		}
		return $found;
	}

	/**
	 * Discount per line key (currency units, rounded); empty when the campaign does not apply.
	 *
	 * @return array<string, float>
	 */
	public static function discounts( array $rules, array $lines, array $context ): array {
		if ( '' !== self::blocker( $rules, $lines, $context ) ) {
			return array();
		}
		$decimals = (int) ( $context['decimals'] ?? 2 );
		$eligible = self::eligible( $rules, $lines, $context );
		$out      = array();
		switch ( $rules['action'] ) {
			case 'percent':
			case 'tiered':
				$pct = 'tiered' === $rules['action'] ? self::tier( $rules, (int) array_sum( array_column( $eligible, 'qty' ) ) )['percent'] : $rules['percent'];
				foreach ( $eligible as $line ) {
					$out[ $line['key'] ] = round( (float) $line['unit'] * (int) $line['qty'] * $pct / 100, $decimals );
				}
				break;

			case 'fixed':
				$total  = array_sum( array_map( static fn( $l ) => (float) $l['unit'] * (int) $l['qty'], $eligible ) );
				$amount = min( $rules['amount'], $total );
				$given  = 0.0;
				foreach ( $eligible as $i => $line ) {
					$share = $i === count( $eligible ) - 1
						? round( $amount - $given, $decimals )
						: round( $amount * ( (float) $line['unit'] * (int) $line['qty'] ) / $total, $decimals );
					$out[ $line['key'] ] = $share;
					$given              += $share;
				}
				break;

			case 'bxgy':
				// Expand to single units; the cheapest units of each "buy X + get Y" group are discounted.
				$units = array();
				foreach ( $eligible as $line ) {
					for ( $u = 0; $u < (int) $line['qty']; $u++ ) {
						$units[] = array( 'key' => $line['key'], 'unit' => (float) $line['unit'] );
					}
				}
				$group  = $rules['buy_qty'] + $rules['get_qty'];
				$groups = intdiv( count( $units ), $group );
				if ( ! $rules['repeat'] ) {
					$groups = min( 1, $groups );
				}
				usort( $units, static fn( $a, $b ) => $a['unit'] <=> $b['unit'] );
				foreach ( array_slice( $units, 0, $groups * $rules['get_qty'] ) as $unit ) {
					$out[ $unit['key'] ] = ( $out[ $unit['key'] ] ?? 0.0 ) + $unit['unit'] * $rules['get_percent'] / 100;
				}
				foreach ( $out as $key => $value ) {
					$out[ $key ] = round( $value, $decimals );
				}
				break;
		}
		return array_filter( $out, static fn( $v ) => $v > 0 );
	}

	/**
	 * Discounts of several campaigns applied together, in the given order: each campaign can only take what the
	 * campaigns before it left of a line, so stacked promotions never exceed the line price.
	 *
	 * @param array<int|string, array> $rules_by_id Rules per campaign id, in application order.
	 * @return array<int|string, array<string, float>> Discount per campaign id and line key.
	 */
	public static function stack( array $rules_by_id, array $lines, array $context ): array {
		$decimals = (int) ( $context['decimals'] ?? 2 );
		$left     = array();
		foreach ( $lines as $line ) {
			$left[ $line['key'] ] = round( (float) $line['unit'] * (int) $line['qty'], $decimals );
		}
		$out = array();
		foreach ( $rules_by_id as $id => $rules ) {
			$out[ $id ] = array();
			foreach ( self::discounts( $rules, $lines, $context ) as $key => $amount ) {
				$give = round( min( $amount, $left[ $key ] ?? 0.0 ), $decimals );
				if ( $give > 0 ) {
					$out[ $id ][ $key ] = $give;
					$left[ $key ]      -= $give;
				}
			}
		}
		return $out;
	}

	/**
	 * Choose campaigns: lowest priority number first; a non-stackable campaign applies alone, stackable ones
	 * combine with each other.
	 *
	 * @param array<int, array{id:int, priority:int, rules:array}> $campaigns
	 * @return int[] campaign ids to apply, in order
	 */
	public static function select( array $campaigns, array $lines, array $context ): array {
		usort( $campaigns, static fn( $a, $b ) => array( $a['priority'], $a['id'] ) <=> array( $b['priority'], $b['id'] ) );
		$chosen = array();
		foreach ( $campaigns as $campaign ) {
			if ( ! self::discounts( $campaign['rules'], $lines, $context ) ) {
				continue;
			}
			if ( ! $campaign['rules']['stackable'] ) {
				if ( $chosen ) {
					continue; // An exclusive campaign never joins others.
				}
				return array( $campaign['id'] );
			}
			$chosen[] = $campaign['id'];
		}
		return $chosen;
	}
}
