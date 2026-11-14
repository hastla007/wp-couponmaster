<?php
namespace VCAP;

use VCAP\Admin\Flash;

defined( 'ABSPATH' ) || exit;

/**
 * Partial return of an order paid with a gift card: "Book back onto voucher" (order screen) puts part of what the
 * order charged to a balance voucher back onto it – also for guest orders and orders with a total of 0, where a
 * WooCommerce refund cannot pay anything back. At most what the order charged to that voucher and was not booked
 * back yet (cap()); recorded as ledger event "returned" with the order line and reason, in the order meta
 * CouponManager::RETURNED_META (a later cancellation or full refund books back only the rest) and as an order note.
 */
final class OrderReturn {
	public function __construct( private VoucherRepository $repo, private CouponManager $coupons ) {}

	public function register(): void {
		add_action( 'vcap_order_metabox_after', array( $this, 'form' ), 20 );
		add_action( 'admin_post_vcap_return_to_voucher', array( $this, 'handle' ) );
		add_filter( 'vcap_event_labels', static fn( array $l ) => $l + array( CouponManager::RETURNED_EVENT => __( 'Booked back from a return', 'voucher-captain' ) ) );
		add_filter( 'vcap_ledger_money_events', static fn( array $e ) => array_merge( $e, array( CouponManager::RETURNED_EVENT ) ) );
	}

	/** What can still be booked back: charged by the order minus already booked back (never negative). */
	public static function cap( float $charged, float $returned, int $decimals = 2 ): float {
		return max( 0.0, round( $charged - $returned, $decimals ) );
	}

	/**
	 * Balance vouchers the order was charged to, with what can still be booked back (base currency).
	 *
	 * @return array<string, array{voucher: Voucher, max: float}> code => voucher, maximum
	 */
	public function returnable( \WC_Order $order ): array {
		$applied  = CouponManager::order_map( $order, '_vcap_redemptions' );
		$returned = CouponManager::order_map( $order, CouponManager::RETURNED_META );
		$list     = array();
		foreach ( $applied as $code => $amount ) {
			$coupon  = function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( (string) $code ) : 0;
			$voucher = $coupon ? $this->repo->get_by_coupon( $coupon ) : $this->repo->get_by_code( (string) $code );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$max = self::cap( (float) $amount, (float) ( $returned[ $code ] ?? 0 ), $voucher->decimals() );
			if ( $max > 0 ) {
				$list[ (string) $code ] = array(
					'voucher' => $voucher,
					'max'     => $max,
				);
			}
		}
		return $list;
	}

	/**
	 * Books $amount (base currency) back onto the voucher $code charged by $order. One request per order at a time.
	 *
	 * @return Voucher|\WP_Error The voucher with its new balance.
	 */
	public function book_back( \WC_Order $order, string $code, float $amount, string $reason, int $item_id = 0 ) {
		global $wpdb;
		$reason = trim( sanitize_text_field( $reason ) );
		$code   = wc_format_coupon_code( $code );
		if ( '' === $reason ) {
			return new \WP_Error( 'vcap_return_reason', __( 'Please enter a reason.', 'voucher-captain' ) );
		}
		$lock = 'vcap_return_' . $order->get_id();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'vcap_return_busy', __( 'Another booking for this order is running. Please reload the order and try again.', 'voucher-captain' ) );
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			$order = $fresh instanceof \WC_Order ? $fresh : $order;
			$list  = $this->returnable( $order );
			if ( ! isset( $list[ $code ] ) ) {
				return new \WP_Error( 'vcap_return_code', __( 'Nothing of this voucher can be booked back for this order.', 'voucher-captain' ) );
			}
			$voucher = $list[ $code ]['voucher'];
			$amount  = round( $amount, $voucher->decimals() );
			if ( $amount <= 0 || $amount > $list[ $code ]['max'] ) {
				return new \WP_Error(
					'vcap_return_amount',
					sprintf(
						/* translators: %s: maximum amount */
						__( 'Enter an amount up to %s (what the order charged to this voucher and was not booked back yet).', 'voucher-captain' ),
						html_entity_decode( wp_strip_all_tags( wc_price( $list[ $code ]['max'], array( 'currency' => $voucher->currency ) ) ), ENT_QUOTES, 'UTF-8' )
					)
				);
			}
			$item = $item_id ? $order->get_item( $item_id ) : null;
			$line = $item instanceof \WC_Order_Item_Product ? wp_strip_all_tags( $item->get_name() ) : '';
			$note = sprintf(
				/* translators: 1: order number, 2: reason */
				__( 'Return from order #%1$s: %2$s', 'voucher-captain' ),
				$order->get_order_number(),
				$reason
			) . ( '' !== $line ? ' (' . $line . ')' : '' );
			if ( ! $this->coupons->adjust_balance( $voucher, $amount, CouponManager::RETURNED_EVENT, $note, $order->get_id() ) ) {
				return new \WP_Error( 'vcap_return_failed', __( 'The amount could not be booked back (database error). Nothing was changed.', 'voucher-captain' ) );
			}
			$returned          = CouponManager::order_map( $order, CouponManager::RETURNED_META );
			$returned[ $code ] = round( (float) ( $returned[ $code ] ?? 0 ) + $amount, $voucher->decimals() );
			$order->update_meta_data( CouponManager::RETURNED_META, $returned );
			$log   = $order->get_meta( '_vcap_returns' );
			$log   = is_array( $log ) ? $log : array();
			$log[] = array(
				'code'        => $code,
				'amount'      => $amount,
				'item_id'     => $item instanceof \WC_Order_Item_Product ? (int) $item_id : 0,
				'reason'      => $reason,
				'recorded_by' => get_current_user_id(),
				'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			$order->update_meta_data( '_vcap_returns', $log );
			$order->save_meta_data();
			$after = $this->repo->get( $voucher->id ) ?? $voucher;
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: voucher code, 3: new balance, 4: reason */
					__( '%1$s booked back onto voucher %2$s (return), new balance %3$s. Reason: %4$s', 'voucher-captain' ),
					wp_strip_all_tags( wc_price( $amount, array( 'currency' => $voucher->currency ) ) ),
					$voucher->code,
					wp_strip_all_tags( wc_price( (float) $after->balance, array( 'currency' => $voucher->currency ) ) ),
					$reason
				) . ( '' !== $line ? ' (' . $line . ')' : '' ),
				false,
				true
			);
			CouponManager::check_restored_card( $this->repo, $order, $after, $amount );
			return $after;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	/** Order screen (voucher box): book part of a gift card payment back onto the card. */
	public function form( \WC_Order $order ): void {
		if ( ! $this->can() ) {
			return;
		}
		$list = $this->returnable( $order );
		if ( ! $list ) {
			return;
		}
		?>
		<hr>
		<p><strong><?php esc_html_e( 'Book back onto voucher', 'voucher-captain' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'For a return of items paid with a gift card: books the amount back onto the card (also for guests and orders with a total of 0). No money is paid back and no WooCommerce refund is recorded.', 'voucher-captain' ); ?></p>
		<p>
			<select name="vcap_return_code" form="vcap-return-voucher" required aria-label="<?php esc_attr_e( 'Voucher', 'voucher-captain' ); ?>">
				<?php foreach ( $list as $code => $row ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>">
						<?php
						/* translators: 1: voucher code, 2: maximum amount */
						echo esc_html( sprintf( __( '%1$s (up to %2$s)', 'voucher-captain' ), $row['voucher']->code, html_entity_decode( wp_strip_all_tags( wc_price( $row['max'], array( 'currency' => $row['voucher']->currency ) ) ), ENT_QUOTES, 'UTF-8' ) ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="vcap_return_item" form="vcap-return-voucher" aria-label="<?php esc_attr_e( 'Returned item', 'voucher-captain' ); ?>">
				<option value="0"><?php esc_html_e( '– returned item (optional) –', 'voucher-captain' ); ?></option>
				<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
					<option value="<?php echo esc_attr( (string) $item_id ); ?>"><?php echo esc_html( wp_strip_all_tags( $item->get_name() ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" class="short" name="vcap_return_amount" form="vcap-return-voucher" inputmode="decimal" required aria-label="<?php esc_attr_e( 'Amount', 'voucher-captain' ); ?>" />
			<input type="text" name="vcap_return_reason" form="vcap-return-voucher" required placeholder="<?php esc_attr_e( 'Reason (required)', 'voucher-captain' ); ?>" aria-label="<?php esc_attr_e( 'Reason', 'voucher-captain' ); ?>" />
		</p>
		<p><button type="submit" form="vcap-return-voucher" class="button"><?php esc_html_e( 'Book back onto voucher', 'voucher-captain' ); ?></button></p>
		<?php
		add_action(
			'admin_footer',
			static function () use ( $order ) {
				echo '<form id="vcap-return-voucher" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="vcap_return_to_voucher" /><input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
				wp_nonce_field( 'vcap_return_to_voucher_' . $order->get_id() );
				echo '</form>';
			}
		);
	}

	public function handle(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'vcap_return_to_voucher_' . $order_id );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'voucher-captain' ), 404 );
		}
		$result = $this->book_back(
			$order,
			sanitize_text_field( wp_unslash( $_POST['vcap_return_code'] ?? '' ) ),
			(float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['vcap_return_amount'] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( $_POST['vcap_return_reason'] ?? '' ) ),
			absint( $_POST['vcap_return_item'] ?? 0 )
		);
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', __( 'Booking back onto the voucher failed:', 'voucher-captain' ) . ' ' . $result->get_error_message() );
		} else {
			Flash::add(
				'success',
				sprintf(
					/* translators: 1: voucher code, 2: new balance */
					__( 'Booked back onto voucher %1$s. New balance: %2$s.', 'voucher-captain' ),
					$result->code,
					html_entity_decode( wp_strip_all_tags( wc_price( (float) $result->balance, array( 'currency' => $result->currency ) ) ), ENT_QUOTES, 'UTF-8' )
				)
			);
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
