<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Catches up on orders after a reactivation. While the plugin was deactivated, nothing issued vouchers for paid
 * orders, voided vouchers of cancelled or refunded purchases, booked referral rewards or top-ups. After the
 * reactivation (Install::resume()), an Action Scheduler action goes through the orders changed since the
 * deactivation in batches and runs Voucher Captain's own handlers of their current status again – only those,
 * never the hooks of other plugins (no second customer email from WooCommerce). The handlers are idempotent (they
 * also run again when an order's status changes back and forth), so an order handled twice changes nothing.
 */
final class CatchUp {
	public const HOOK  = 'vcap_catch_up_orders';
	private const BATCH = 50;

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 3 );
	}

	/** Queues the first batch (Plugin::ensure_schedules()). */
	public static function queue( int $since, int $until ): void {
		if ( $since > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( 'since' => $since, 'until' => $until, 'page' => 1 ), Delivery::GROUP );
		}
	}

	/**
	 * One batch: orders changed since $since (oldest id first); orders placed after the reactivation ($until) were
	 * handled when they happened. Queues the next batch while there may be more.
	 *
	 * @param int|array $since Deactivation time (or the action's args array).
	 * @param int       $until Reactivation time.
	 * @param int       $page  Batch number.
	 * @return int Orders caught up in this batch.
	 */
	public function run( $since, $until = 0, $page = 1 ): int {
		if ( is_array( $since ) ) {
			$until = (int) ( $since['until'] ?? 0 );
			$page  = (int) ( $since['page'] ?? 1 );
			$since = (int) ( $since['since'] ?? 0 );
		}
		$since = (int) $since;
		$page  = max( 1, (int) $page );
		if ( $since <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$orders = (array) wc_get_orders(
			array(
				'type'          => 'shop_order',
				'date_modified' => '>=' . $since,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'limit'         => self::BATCH,
				'paged'         => $page,
			)
		);
		$done = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$created = $order->get_date_created();
			if ( $until > 0 && $created && $created->getTimestamp() > $until ) {
				continue;
			}
			try {
				self::replay_order( $order, $since );
				++$done;
			} catch ( \Throwable $e ) {
				// One broken order never stops the others.
				Logger::error( 'Catch-up after reactivation: order skipped after an error: ' . Health::mask_addresses( $e->getMessage() ), array( 'order_id' => $order->get_id() ) );
			}
		}
		if ( count( $orders ) >= self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( 'since' => $since, 'until' => (int) $until, 'page' => $page + 1 ), Delivery::GROUP );
		} else {
			Logger::info( 'Catch-up after reactivation finished.', array( 'batches' => $page ) );
		}
		return $done;
	}

	/** Runs Voucher Captain's handlers of the order's payment, status and refunds again. */
	public static function replay_order( \WC_Order $order, int $since ): void {
		$id     = $order->get_id();
		$status = $order->get_status();
		$paid   = $order->get_date_paid();
		if ( $paid && $paid->getTimestamp() >= $since ) {
			self::replay( 'woocommerce_payment_complete', array( $id ) );
		}
		self::replay( 'woocommerce_order_status_' . $status, array( $id, $order, array() ) );
		self::replay( 'woocommerce_order_status_changed', array( $id, '', $status, $order ) );
		if ( 'refunded' !== $status ) {
			$refunds = $order->get_refunds();
			if ( $refunds ) {
				self::replay( 'woocommerce_order_partially_refunded', array( $id, $refunds[0]->get_id() ) );
			}
		}
	}

	/**
	 * Calls Voucher Captain's callbacks of a hook, in priority order.
	 *
	 * @param array $args Hook arguments.
	 */
	public static function replay( string $hook, array $args ): void {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( self::own_callbacks( $wp_filter[ $hook ] ) as $callback ) {
			call_user_func_array( $callback['function'], array_slice( $args, 0, max( 0, (int) $callback['accepted_args'] ) ) );
		}
	}

	/**
	 * The callbacks of a hook (WP_Hook) defined in Voucher Captain's own code, by priority.
	 *
	 * @param object $hook WP_Hook (its public "callbacks": priority => id => array{function, accepted_args}).
	 * @return array<int, array{function: callable, accepted_args: int}>
	 */
	public static function own_callbacks( $hook ): array {
		$by_priority = is_object( $hook ) && isset( $hook->callbacks ) ? (array) $hook->callbacks : array();
		ksort( $by_priority );
		$own = array();
		foreach ( $by_priority as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				if ( isset( $callback['function'] ) && self::is_own( $callback['function'] ) ) {
					$own[] = $callback;
				}
			}
		}
		return $own;
	}

	/** Whether a callback is defined in this plugin's src/ folder. */
	private static function is_own( $callable ): bool {
		try {
			if ( is_array( $callable ) && 2 === count( $callable ) ) {
				$file = ( new \ReflectionMethod( $callable[0], (string) $callable[1] ) )->getFileName();
			} elseif ( $callable instanceof \Closure || ( is_string( $callable ) && ! str_contains( $callable, '::' ) ) ) {
				$file = ( new \ReflectionFunction( $callable ) )->getFileName();
			} elseif ( is_string( $callable ) ) {
				$file = ( new \ReflectionMethod( $callable ) )->getFileName();
			} else {
				return false;
			}
		} catch ( \ReflectionException $e ) {
			return false;
		}
		return is_string( $file ) && str_starts_with( str_replace( '\\', '/', $file ), str_replace( '\\', '/', VCAP_PATH . 'src/' ) );
	}
}
