<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Money cases that need the shop's hand: a voucher that could not be voided after a refund, a balance booked back
 * onto an expired or cancelled gift card, a gift card top-up that could not be booked. Each case is kept per order
 * and kind (option "vcap_attention", at most 100) and shown in an admin notice (dashboard, orders, voucher
 * screens) and in Site Health until it is resolved – automatically (e.g. a retried void succeeded) or by the
 * "Done" link of the notice.
 */
final class Attention {
	public const OPTION = 'vcap_attention';
	private const MAX   = 100;

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_vcap_attention_done', array( $this, 'done' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health' ) );
	}

	/** Records (or updates) a case: order, kind (e.g. "void:12"), what happened and what to do. */
	public static function add( int $order_id, string $kind, string $text ): void {
		$all = self::all();
		$key = $order_id . '|' . sanitize_key( $kind );
		unset( $all[ $key ] ); // Newest last, so the cap drops the oldest.
		$all[ $key ] = array(
			'order_id' => $order_id,
			'kind'     => sanitize_key( $kind ),
			'text'     => wp_strip_all_tags( $text ),
			'at'       => time(),
		);
		update_option( self::OPTION, array_slice( $all, -self::MAX, null, true ), false );
	}

	public static function resolve( int $order_id, string $kind ): void {
		$all = self::all();
		$key = $order_id . '|' . sanitize_key( $kind );
		if ( isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			update_option( self::OPTION, $all, false );
		}
	}

	public static function has( int $order_id, string $kind ): bool {
		return isset( self::all()[ $order_id . '|' . sanitize_key( $kind ) ] );
	}

	/** @return array<string, array{order_id:int, kind:string, text:string, at:int}> open cases, oldest first */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
	}

	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'dashboard', 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true ) && ! str_contains( (string) $screen->id, 'vcap' ) ) {
			return;
		}
		$all = self::all();
		if ( ! $all ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> '
			. esc_html__( 'These orders need your attention (money of a refund, cancellation or top-up was not booked as expected):', 'voucher-captain' ) . '</p><ul>';
		foreach ( array_slice( $all, -20, null, true ) as $key => $case ) {
			$order = wc_get_order( (int) $case['order_id'] );
			$label = $order instanceof \WC_Order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( (string) $case['order_id'] );
			$done  = wp_nonce_url( admin_url( 'admin-post.php?action=vcap_attention_done&case=' . rawurlencode( (string) $key ) ), 'vcap_attention_done' );
			echo '<li>' . $label . ': ' . esc_html( (string) $case['text'] ) . ' <a href="' . esc_url( $done ) . '">' . esc_html__( 'Done', 'voucher-captain' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</ul></div>';
	}

	public function done(): void {
		check_admin_referer( 'vcap_attention_done' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$key = sanitize_text_field( wp_unslash( $_GET['case'] ?? '' ) );
		$all = self::all();
		if ( isset( $all[ $key ] ) ) {
			self::resolve( (int) $all[ $key ]['order_id'], (string) $all[ $key ]['kind'] );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public function site_health( $tests ) {
		$tests = (array) $tests;
		$tests['direct']['vcap_attention'] = array(
			'label' => __( 'Voucher Captain', 'voucher-captain' ),
			'test'  => array( $this, 'test' ),
		);
		return $tests;
	}

	public function test(): array {
		$count = count( self::all() );
		return array(
			'label'       => $count
				? __( 'Voucher refunds or top-ups need your attention', 'voucher-captain' )
				: __( 'No voucher refund or top-up is waiting for you', 'voucher-captain' ),
			'status'      => $count ? 'critical' : 'good',
			'badge'       => array(
				'label' => __( 'Vouchers', 'voucher-captain' ),
				'color' => $count ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html(
				$count
					/* translators: %d: number of orders */
					? sprintf( _n( '%d case: a voucher could not be voided, a balance went back onto an expired or cancelled gift card, or a gift card top-up was not booked. The list is shown on the dashboard and the orders screen.', '%d cases: a voucher could not be voided, a balance went back onto an expired or cancelled gift card, or a gift card top-up was not booked. The list is shown on the dashboard and the orders screen.', $count, 'voucher-captain' ), $count )
					: __( 'Every refund, cancellation and top-up was booked on the vouchers as expected.', 'voucher-captain' )
			) . '</p>',
			'test'        => 'vcap_attention',
		);
	}
}
