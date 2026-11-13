<?php
namespace VCAP;

use VCAP\Discounts\Bonus;
use VCAP\Discounts\CartIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * Redemption statistics per code campaign/partner, promotion, bonus voucher and coupon: orders, discount granted
 * and revenue (order total minus refunds) of paid orders in a date range.
 */
final class Reports {
	/** Orders of the period that are evaluated at most (newest first); beyond, $truncated is set and shown. */
	public const MAX_ORDERS = 50000;

	/** Whether the last collect() stopped at MAX_ORDERS. */
	public bool $truncated = false;

	/** Paid statuses that count for reports. */
	public static function statuses(): array {
		return array_map( static fn( $s ) => 'wc-' . $s, (array) apply_filters( 'vcap_report_statuses', array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) ) );
	}

	/** @var array<string, array{type:string,name:string}> */
	private array $groups = array();

	/**
	 * Report group of a coupon code: type + name.
	 *
	 * @return array{type:string,name:string}
	 */
	public function group( string $code ): array {
		$code = wc_format_coupon_code( $code );
		if ( isset( $this->groups[ $code ] ) ) {
			return $this->groups[ $code ];
		}
		$campaign_id = CartIntegration::id_from_code( $code );
		if ( $campaign_id ) {
			$group = array( 'type' => 'promotion', 'name' => (string) get_the_title( $campaign_id ) );
		} else {
			$id    = (int) wc_get_coupon_id_by_code( $code );
			$group = array( 'type' => 'coupon', 'name' => strtoupper( $code ) );
			if ( $id ) {
				$bonus    = (int) get_post_meta( $id, Bonus::COUPON_SOURCE, true );
				$referrer = (int) get_post_meta( $id, Referrals::OWNER, true );
				$campaign = (string) get_post_meta( $id, Campaigns::META, true );
				if ( 'yes' === get_post_meta( $id, '_vcap_voucher', true ) ) {
					$group = array( 'type' => 'voucher', 'name' => __( 'Vouchers and gift cards', 'voucher-captain' ) );
				} elseif ( $referrer ) {
					$user  = get_userdata( $referrer );
					$group = array( 'type' => 'referral', 'name' => $user ? $user->display_name . ' <' . $user->user_email . '>' : '#' . $referrer );
				} elseif ( $bonus ) {
					$group = array( 'type' => 'bonus', 'name' => (string) get_the_title( $bonus ) );
				} elseif ( '' !== $campaign ) {
					$group = array( 'type' => 'campaign', 'name' => $campaign );
				}
			}
			$group = (array) apply_filters( 'vcap_report_group', $group, $code, $id );
		}
		return $this->groups[ $code ] = $group;
	}

	/**
	 * Codes used in paid orders of the period: coupon lines, and vouchers used as a payment after tax (fee lines
	 * with "_vcap_payment_code", see PaymentVouchers – counted with the amount paid). Only orders that have such
	 * lines are loaded (found in the order item tables, which both order stores share).
	 *
	 * @return array<string, array{type:string,name:string,orders:int,uses:int,discount:float,revenue:float,issued:?int}>
	 */
	public function collect( string $from, string $to ): array {
		$ids             = (array) wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::statuses(),
				'date_created' => $from . '...' . $to,
				'limit'        => self::MAX_ORDERS + 1,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		$this->truncated = count( $ids ) > self::MAX_ORDERS;
		$rows            = array();
		foreach ( self::orders_with_codes( array_slice( $ids, 0, self::MAX_ORDERS ) ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$seen     = array();
			$currency = $order->get_currency();
			$uses     = array();
			foreach ( $order->get_items( 'coupon' ) as $item ) {
				$uses[] = array( (string) $item->get_code(), (float) $item->get_discount() + (float) $item->get_discount_tax() );
			}
			foreach ( $order->get_items( 'fee' ) as $item ) {
				$code = (string) $item->get_meta( PaymentVouchers::FEE_META );
				if ( '' !== $code ) {
					$uses[] = array( $code, abs( (float) $item->get_total() ) );
				}
			}
			foreach ( $uses as list( $code, $amount ) ) {
				$group = $this->group( $code );
				$key   = $group['type'] . ':' . $group['name'];
				if ( $currency && get_woocommerce_currency() !== $currency ) {
					// Amounts in other currencies are never added to shop-currency amounts.
					$key          .= ':' . $currency;
					$group['name'] .= ' (' . $currency . ')';
				}
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = $group + array( 'orders' => 0, 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'issued' => null );
				}
				++$rows[ $key ]['uses'];
				$rows[ $key ]['discount'] += $amount;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					++$rows[ $key ]['orders'];
					$rows[ $key ]['revenue'] += (float) $order->get_total() - (float) $order->get_total_refunded();
				}
			}
		}
		$this->add_issued( $rows );
		uasort( $rows, static fn( $a, $b ) => array( $a['type'], -$a['discount'], $a['name'] ) <=> array( $b['type'], -$b['discount'], $b['name'] ) );
		foreach ( $rows as &$row ) {
			$row['discount'] = round( $row['discount'], wc_get_price_decimals() );
			$row['revenue']  = round( $row['revenue'], wc_get_price_decimals() );
		}
		return $rows;
	}

	/**
	 * The orders among $ids that have a coupon line or a voucher payment line.
	 *
	 * @param int[] $ids Order ids.
	 * @return int[]
	 */
	private static function orders_with_codes( array $ids ): array {
		global $wpdb;
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$out   = array();
		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			$in  = implode( ',', $chunk );
			$out = array_merge(
				$out,
				array_map(
					'intval',
					(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids and table names.
							"SELECT DISTINCT oi.order_id FROM {$items} oi WHERE oi.order_id IN ({$in}) AND ( oi.order_item_type = 'coupon' OR ( oi.order_item_type = 'fee' AND EXISTS ( SELECT 1 FROM {$meta} m WHERE m.order_item_id = oi.order_item_id AND m.meta_key = %s ) ) )",
							PaymentVouchers::FEE_META
						)
					)
				)
			);
		}
		// Keep the order of $ids (newest first).
		return array_values( array_intersect( array_map( 'intval', $ids ), $out ) );
	}

	/** Number of codes issued per code campaign and bonus promotion (also listed without redemptions). */
	private function add_issued( array &$rows ): void {
		global $wpdb;
		$campaigns = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS name, COUNT(*) AS total FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_coupon' AND p.post_status <> 'trash' WHERE m.meta_key = %s GROUP BY meta_value", Campaigns::META ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $campaigns ? $campaigns : array() as $c ) {
			$key = 'campaign:' . $c['name'];
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'type' => 'campaign', 'name' => (string) $c['name'], 'orders' => 0, 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'issued' => null );
			}
			$rows[ $key ]['issued'] = (int) $c['total'];
		}
		$bonus = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS id, COUNT(*) AS total FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_coupon' AND p.post_status <> 'trash' WHERE m.meta_key = %s GROUP BY meta_value", Bonus::COUPON_SOURCE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $bonus ? $bonus : array() as $b ) {
			$name = (string) get_the_title( (int) $b['id'] );
			$key  = 'bonus:' . $name;
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'type' => 'bonus', 'name' => $name, 'orders' => 0, 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'issued' => null );
			}
			$rows[ $key ]['issued'] = (int) $b['total'];
		}
	}

	public static function type_label( string $type ): string {
		$labels = array(
			'campaign'  => __( 'Code campaign / partner', 'voucher-captain' ),
			'promotion' => __( 'Promotion', 'voucher-captain' ),
			'bonus'     => __( 'Bonus voucher', 'voucher-captain' ),
			'referral'  => __( 'Referral', 'voucher-captain' ),
			'voucher'   => __( 'Vouchers and gift cards', 'voucher-captain' ),
			'coupon'    => __( 'Coupon', 'voucher-captain' ),
		);
		return $labels[ $type ] ?? $type;
	}

	/** CSV rows for export (header first). */
	public static function csv_rows( array $rows ): array {
		$out = array( array( __( 'Type', 'voucher-captain' ), __( 'Name', 'voucher-captain' ), __( 'Codes issued', 'voucher-captain' ), __( 'Uses', 'voucher-captain' ), __( 'Orders', 'voucher-captain' ), __( 'Discount', 'voucher-captain' ), __( 'Revenue', 'voucher-captain' ), __( 'Currency', 'voucher-captain' ) ) );
		foreach ( $rows as $r ) {
			$out[] = array( self::type_label( $r['type'] ), $r['name'], null === $r['issued'] ? '' : $r['issued'], $r['uses'], $r['orders'], Csv::number( $r['discount'], wc_get_price_decimals() ), Csv::number( $r['revenue'], wc_get_price_decimals() ), get_woocommerce_currency() );
		}
		return $out;
	}
}
