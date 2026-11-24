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

	/**
	 * M12-92: key figures of a period (local days) in the shop currency: vouchers sold (issued from orders) and
	 * issued without an order, value redeemed (net of cancelled redemptions and refunds), value still open at the
	 * end of the period (Ledger::liability()), value that expired in the period, and the best-selling voucher
	 * products. Store credit is not "sold"; percentage and ticket vouchers count as pieces without value.
	 *
	 * @return array{sold:array{count:int,value:float}, free:array{count:int,value:float}, redeemed:float, open:array{count:int,value:float}, expired:array{count:int,value:float}, top:array<int, array{product_id:int,name:string,count:int,value:float}>, currency:string}
	 */
	public static function overview( string $from, string $to ): array {
		global $wpdb;
		$t        = Install::tables();
		$currency = (string) get_option( 'woocommerce_currency' );
		list( $start, $end ) = Ledger::utc_range( $from, $to );
		$value    = "CASE WHEN discount_type = 'percent' OR type = 'ticket' THEN 0 ELSE initial_amount END";
		$issued   = $wpdb->get_results( $wpdb->prepare( "SELECT ( order_id > 0 ) AS sold, COUNT(*) AS cnt, SUM( {$value} ) AS val FROM {$t['vouchers']} WHERE created_at BETWEEN %s AND %s AND type <> 'credit' AND currency = %s GROUP BY ( order_id > 0 )", $start, $end, $currency ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out      = array(
			'sold'     => array( 'count' => 0, 'value' => 0.0 ),
			'free'     => array( 'count' => 0, 'value' => 0.0 ),
			'redeemed' => 0.0,
			'open'     => array( 'count' => 0, 'value' => 0.0 ),
			'expired'  => array( 'count' => 0, 'value' => 0.0 ),
			'top'      => array(),
			'currency' => $currency,
		);
		foreach ( $issued as $row ) {
			$out[ (int) $row['sold'] ? 'sold' : 'free' ] = array( 'count' => (int) $row['cnt'], 'value' => round( (float) $row['val'], 2 ) );
		}
		$value_vouchers = Ledger::VALUE_VOUCHERS;
		$redeemed        = $wpdb->get_var( $wpdb->prepare( "SELECT SUM( CASE WHEN e.event IN ('redeemed','committed','api_committed') THEN e.amount ELSE -e.amount END ) FROM %i e INNER JOIN %i v ON v.id = e.voucher_id WHERE e.created_at BETWEEN %s AND %s AND e.event IN ('redeemed','committed','api_committed','redeem_reversed','restored','api_refunded') AND e.amount IS NOT NULL AND v.currency = %s AND {$value_vouchers}", $t['events'], $t['vouchers'], $start, $end, $currency ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['redeemed'] = round( (float) $redeemed, 2 );
		foreach ( ( new Ledger( vcap()->vouchers ) )->liability( $to )['totals'][ $currency ] ?? array() as $bucket ) {
			$out['open']['count'] += (int) $bucket['count'];
			$out['open']['value'] += (float) $bucket['amount'];
		}
		$out['open']['value'] = round( $out['open']['value'], 2 );
		// Expired in the period with value left: the stored balance, or a single-use voucher's value while unused.
		// SET-VC-06: a voucher expires when its grace days end (the dates are shifted back by them); with "never
		// expires" nothing expires.
		if ( ExpiryPolicy::expires_ever() ) {
			$expired = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS cnt, SUM( CASE WHEN usage_mode = 'balance' THEN balance ELSE initial_amount END ) AS val FROM %i WHERE expires_at BETWEEN %s AND %s AND expires_at <= %s AND status IN ('active','expired') AND balance > 0 AND discount_type <> 'percent' AND type NOT IN ('ticket','credit') AND currency = %s", $t['vouchers'], ExpiryPolicy::sql_cutoff( $start ), ExpiryPolicy::sql_cutoff( $end ), ExpiryPolicy::sql_cutoff( gmdate( 'Y-m-d H:i:s' ) ), $currency ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out['expired'] = array( 'count' => (int) ( $expired['cnt'] ?? 0 ), 'value' => round( (float) ( $expired['val'] ?? 0 ), 2 ) );
		}
		$top = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, COUNT(*) AS cnt, SUM( {$value} ) AS val FROM {$t['vouchers']} WHERE created_at BETWEEN %s AND %s AND order_id > 0 AND product_id > 0 AND type <> 'credit' AND currency = %s GROUP BY product_id ORDER BY cnt DESC, val DESC LIMIT 5", $start, $end, $currency ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $top as $row ) {
			$product      = wc_get_product( (int) $row['product_id'] );
			$out['top'][] = array(
				'product_id' => (int) $row['product_id'],
				/* translators: %d: product id */
				'name'       => $product ? $product->get_name() : sprintf( __( 'Product #%d (deleted)', 'voucher-captain' ), (int) $row['product_id'] ),
				'count'      => (int) $row['cnt'],
				'value'      => round( (float) $row['val'], 2 ),
			);
		}
		return $out;
	}

	/** Paid statuses that count for reports. */
	public static function statuses(): array {
		/** Order statuses (without "wc-") whose orders count in the campaign report (default: WooCommerce's paid statuses and refunded). */
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
			/** Group of a coupon code in the campaign report (args: array{type, name}, code, coupon id), e.g. an add-on's own source. */
			$group = (array) apply_filters( 'vcap_report_group', $group, $code, $id );
		}
		return $this->groups[ $code ] = $group;
	}

	/** Seconds a calculated report is kept (also recalculated as soon as an order or refund is added). */
	public const CACHE_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Codes used in paid orders of the period: coupon lines, and vouchers used as a payment after tax (fee lines
	 * with "_vcap_payment_code", see PaymentVouchers – counted with the amount paid). Only orders that have such
	 * lines are read (found in the order item tables, which both order stores share), and only their figures –
	 * code lines, currency, total and refunds are summed per chunk of 1000 orders in SQL, no order object is
	 * loaded. The result is cached for CACHE_SECONDS per period (key with the newest order id, so a new order or
	 * refund is included at once).
	 *
	 * @return array<string, array{type:string,name:string,orders:int,uses:int,discount:float,revenue:float,issued:?int}>
	 */
	public function collect( string $from, string $to ): array {
		$cache_key = 'vcap_report_' . md5( wp_json_encode( array( $from, $to, self::statuses(), get_woocommerce_currency(), self::newest_order_id(), get_locale() ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
			$this->truncated = ! empty( $cached['truncated'] );
			return (array) $cached['rows'];
		}
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
		foreach ( array_chunk( self::orders_with_codes( array_slice( $ids, 0, self::MAX_ORDERS ) ), 1000 ) as $chunk ) {
			$totals = self::order_totals( $chunk );
			$lines  = self::code_lines( $chunk );
			foreach ( $chunk as $id ) {
				if ( ! isset( $totals[ $id ] ) || empty( $lines[ $id ] ) ) {
					continue;
				}
				$seen     = array();
				$currency = $totals[ $id ]['currency'];
				foreach ( $lines[ $id ] as list( $code, $amount ) ) {
					$group = $this->group( $code );
					$key   = $group['type'] . ':' . $group['name'];
					if ( $currency && get_woocommerce_currency() !== $currency ) {
						// Amounts in other currencies are never added to shop-currency amounts.
						$key           .= ':' . $currency;
						$group['name'] .= ' (' . $currency . ')';
					}
					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ] = $group + array( 'orders' => 0, 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'issued' => null, 'currency' => $currency ? $currency : get_woocommerce_currency() );
					}
					++$rows[ $key ]['uses'];
					$rows[ $key ]['discount'] += $amount;
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ] = true;
						++$rows[ $key ]['orders'];
						$rows[ $key ]['revenue'] += $totals[ $id ]['total'] - $totals[ $id ]['refunded'];
					}
				}
			}
		}
		$this->add_issued( $rows );
		uasort( $rows, static fn( $a, $b ) => array( $a['type'], -$a['discount'], $a['name'] ) <=> array( $b['type'], -$b['discount'], $b['name'] ) );
		foreach ( $rows as &$row ) {
			$row['discount'] = round( $row['discount'], wc_get_price_decimals() );
			$row['revenue']  = round( $row['revenue'], wc_get_price_decimals() );
		}
		unset( $row );
		set_transient( $cache_key, array( 'rows' => $rows, 'truncated' => $this->truncated ), self::CACHE_SECONDS );
		return $rows;
	}

	/** Newest order or refund id (part of the cache key: a new order or refund recalculates the report). */
	private static function newest_order_id(): int {
		global $wpdb;
		if ( OrderLookup::hpos() ) {
			return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wc_orders" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Currency, total and refunded amount of orders, from the order store in use (the values of
	 * WC_Order::get_currency(), get_total() and get_total_refunded()).
	 *
	 * @param int[] $ids
	 * @return array<int, array{currency:string,total:float,refunded:float}>
	 */
	private static function order_totals( array $ids ): array {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids and table names.
		if ( OrderLookup::hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			foreach ( (array) $wpdb->get_results( "SELECT id, currency, total_amount FROM {$orders} WHERE id IN ({$in})", ARRAY_A ) as $r ) {
				$out[ (int) $r['id'] ] = array( 'currency' => (string) $r['currency'], 'total' => (float) $r['total_amount'], 'refunded' => 0.0 );
			}
			$refunds = (array) $wpdb->get_results( $wpdb->prepare( "SELECT parent_order_id AS id, SUM(total_amount) AS amount FROM {$orders} WHERE type = %s AND parent_order_id IN ({$in}) GROUP BY parent_order_id", 'shop_order_refund' ), ARRAY_A );
			$sign    = -1.0; // Refunds are stored with a negative total.
		} else {
			foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_order_currency','_order_total')", ARRAY_A ) as $r ) {
				$id         = (int) $r['post_id'];
				$out[ $id ] = ( $out[ $id ] ?? array( 'currency' => '', 'total' => 0.0, 'refunded' => 0.0 ) );
				if ( '_order_currency' === $r['meta_key'] ) {
					$out[ $id ]['currency'] = (string) $r['meta_value'];
				} else {
					$out[ $id ]['total'] = (float) $r['meta_value'];
				}
			}
			$refunds = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.post_parent AS id, SUM(m.meta_value) AS amount FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_refund_amount' WHERE p.post_type = %s AND p.post_parent IN ({$in}) GROUP BY p.post_parent", 'shop_order_refund' ), ARRAY_A );
			$sign    = 1.0;
		}
		// phpcs:enable
		foreach ( $refunds as $r ) {
			if ( isset( $out[ (int) $r['id'] ] ) ) {
				$out[ (int) $r['id'] ]['refunded'] = $sign * (float) $r['amount'];
			}
		}
		return $out;
	}

	/**
	 * Code lines of orders: coupon lines (code, discount incl. tax) first, then voucher payment lines (code,
	 * amount paid), each in item order – what WC_Order::get_items() gives, read from the order item tables.
	 *
	 * @param int[] $ids
	 * @return array<int, array<int, array{0:string,1:float}>>
	 */
	private static function code_lines( array $ids ): array {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return array();
		}
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$in    = implode( ',', $ids );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids and table names.
				"SELECT oi.order_id, oi.order_item_id, oi.order_item_type, oi.order_item_name, m.meta_key, m.meta_value FROM {$items} oi LEFT JOIN {$meta} m ON m.order_item_id = oi.order_item_id AND m.meta_key IN ('discount_amount','discount_amount_tax','_line_total',%s) WHERE oi.order_id IN ({$in}) AND oi.order_item_type IN ('coupon','fee') ORDER BY oi.order_id, oi.order_item_id",
				PaymentVouchers::FEE_META
			),
			ARRAY_A
		);
		$found = array();
		foreach ( $rows as $r ) {
			$item = (int) $r['order_item_id'];
			if ( ! isset( $found[ $item ] ) ) {
				$found[ $item ] = array( 'order' => (int) $r['order_id'], 'type' => (string) $r['order_item_type'], 'name' => (string) $r['order_item_name'], 'meta' => array() );
			}
			if ( null !== $r['meta_key'] ) {
				$found[ $item ]['meta'][ (string) $r['meta_key'] ] = (string) $r['meta_value'];
			}
		}
		$coupons = array();
		$fees    = array();
		foreach ( $found as $item ) {
			if ( 'coupon' === $item['type'] ) {
				$coupons[ $item['order'] ][] = array( $item['name'], (float) ( $item['meta']['discount_amount'] ?? 0 ) + (float) ( $item['meta']['discount_amount_tax'] ?? 0 ) );
			} elseif ( '' !== (string) ( $item['meta'][ PaymentVouchers::FEE_META ] ?? '' ) ) {
				$fees[ $item['order'] ][] = array( (string) $item['meta'][ PaymentVouchers::FEE_META ], abs( (float) ( $item['meta']['_line_total'] ?? 0 ) ) );
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array_merge( $coupons[ $id ] ?? array(), $fees[ $id ] ?? array() );
		}
		return $out;
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
			// Each row in its own currency: amounts of orders in other currencies are kept apart (see collect()).
			$currency = (string) ( $r['currency'] ?? get_woocommerce_currency() );
			$decimals = API\V1\RedemptionService::decimals( $currency );
			$out[]    = array( self::type_label( $r['type'] ), $r['name'], null === $r['issued'] ? '' : $r['issued'], $r['uses'], $r['orders'], Csv::number( $r['discount'], $decimals ), Csv::number( $r['revenue'], $decimals ), $currency );
		}
		return $out;
	}
}
