<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Order ids by an order meta key, read directly from the order store in use: the HPOS tables
 * (wc_orders / wc_orders_meta) or the classic one (posts / postmeta). wc_get_orders() ignores "meta_query" for the
 * classic store (and WooCommerce 9.2+ reports it as wrong use), so a query by meta there returned every order of
 * the status instead – the oldest ones first, never the ones with the meta.
 */
final class OrderLookup {
	/**
	 * Ids of orders that have the meta key (with $value: exactly this value).
	 *
	 * @param array $args {
	 *     @type string|string[] $status          Order statuses without "wc-" (default: any).
	 *     @type string          $type            Order type (default "shop_order").
	 *     @type int             $modified_before Only orders last changed before this timestamp.
	 *     @type int             $after_id        Keyset paging: only ids after (ASC) / before (DESC) this one.
	 *     @type string          $order           "ASC" (default) or "DESC" by id.
	 *     @type int             $limit           At most this many ids (default 200).
	 *     @type bool|null       $hpos            Order store to read (default: the one in use; tests).
	 * }
	 * @return int[]
	 */
	public static function ids_with_meta( string $key, ?string $value = null, array $args = array() ): array {
		global $wpdb;
		$args   = array_merge(
			array(
				'status'          => array(),
				'type'            => 'shop_order',
				'modified_before' => 0,
				'after_id'        => 0,
				'order'           => 'ASC',
				'limit'           => 200,
				'hpos'            => null,
			),
			$args
		);
		$hpos   = null === $args['hpos'] ? self::hpos() : (bool) $args['hpos'];
		$desc   = 'DESC' === strtoupper( (string) $args['order'] );
		$where  = array();
		$values = array( $key );
		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$join   = "INNER JOIN {$meta} m ON m.order_id = o.id AND m.meta_key = %s";
			$cols   = array( 'id' => 'o.id', 'type' => 'o.type', 'status' => 'o.status', 'modified' => 'o.date_updated_gmt' );
			$from   = "{$orders} o";
		} else {
			$join = "INNER JOIN {$wpdb->postmeta} m ON m.post_id = o.ID AND m.meta_key = %s";
			$cols = array( 'id' => 'o.ID', 'type' => 'o.post_type', 'status' => 'o.post_status', 'modified' => 'o.post_modified_gmt' );
			$from = "{$wpdb->posts} o";
		}
		if ( null !== $value ) {
			$join    .= ' AND m.meta_value = %s';
			$values[] = $value;
		}
		$where[]  = "{$cols['type']} = %s";
		$values[] = (string) $args['type'];
		$statuses = array_values( array_filter( array_map( 'strval', (array) $args['status'] ) ) );
		if ( $statuses ) {
			$where[] = "{$cols['status']} IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			foreach ( $statuses as $status ) {
				$values[] = str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status;
			}
		}
		if ( (int) $args['modified_before'] > 0 ) {
			$where[]  = "{$cols['modified']} < %s";
			$values[] = gmdate( 'Y-m-d H:i:s', (int) $args['modified_before'] );
		}
		if ( (int) $args['after_id'] > 0 ) {
			$where[]  = $cols['id'] . ( $desc ? ' < %d' : ' > %d' );
			$values[] = (int) $args['after_id'];
		}
		$values[] = max( 1, (int) $args['limit'] );
		$order    = $desc ? 'DESC' : 'ASC';
		$sql      = "SELECT DISTINCT {$cols['id']} FROM {$from} {$join} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$cols['id']} {$order} LIMIT %d";
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table and column names from above, values as placeholders.
	}

	/** Whether WooCommerce stores orders in its own tables (HPOS). */
	public static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
