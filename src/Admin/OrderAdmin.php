<?php
namespace VCAP\Admin;

use VCAP\Issuer;
use VCAP\ProductConfig;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Order screen integration (HPOS + legacy): voucher meta box and manual issue / resend order actions.
 */
final class OrderAdmin {
	public function __construct( private VoucherRepository $repo, private Issuer $issuer ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 30 );
		add_filter( 'woocommerce_order_actions', array( $this, 'actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_vcap_issue', array( $this, 'issue' ) );
		add_action( 'woocommerce_order_action_vcap_resend', array( $this, 'resend' ) );
		add_action( 'woocommerce_order_action_vcap_accept_shortfall', array( $this, 'accept_shortfall' ) );
	}

	/** Order action: accept a voucher shortfall and fulfil the order (status back to processing). */
	public function accept_shortfall( $order ): void {
		$order = self::order_from( $order );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) || ! \VCAP\CouponManager::has_shortfall( $order ) ) {
			return;
		}
		vcap()->coupons->accept_shortfall( $order );
		if ( 'on-hold' === $order->get_status() ) {
			$order->update_status( 'processing', __( 'Fulfilment after the voucher shortfall was accepted.', 'voucher-captain' ) );
		}
	}

	private function screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	public function meta_box(): void {
		foreach ( array_unique( array( $this->screen_id(), 'shop_order' ) ) as $screen ) {
			add_meta_box( 'vcap-order-vouchers', __( 'Vouchers', 'voucher-captain' ), array( $this, 'render' ), $screen, 'side', 'default' );
		}
	}

	private static function order_from( $post_or_order ): ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}
		$order = wc_get_order( $post_or_order instanceof \WP_Post ? $post_or_order->ID : (int) $post_or_order );
		return $order instanceof \WC_Order ? $order : null;
	}

	private function has_voucher_items( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product() && ProductConfig::is_voucher( $item->get_product() ) ) {
				return true;
			}
		}
		return false;
	}

	public function render( $post_or_order ): void {
		$order = self::order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$vouchers = $this->repo->for_order( $order->get_id() );
		if ( ! $vouchers ) {
			echo '<p>' . ( $this->has_voucher_items( $order )
				? esc_html__( 'No vouchers issued yet. Use the order action "Generate vouchers" to issue them now.', 'voucher-captain' )
				: esc_html__( 'This order contains no voucher products.', 'voucher-captain' ) ) . '</p>';
			do_action( 'vcap_order_metabox_after', $order );
			return;
		}
		echo '<ul class="vcap-order-vouchers">';
		foreach ( $vouchers as $v ) {
			$detail = admin_url( 'admin.php?page=vcap-vouchers&voucher=' . $v->id );
			echo '<li><a href="' . esc_url( $detail ) . '"><code>' . esc_html( $v->code ) . '</code></a> ';
			echo '<mark class="vcap-status vcap-status--' . esc_attr( $v->status ) . '"><span>' . esc_html( $v->status_label() ) . '</span></mark><br>';
			if ( $v->is_multi_use() ) {
				echo '<small>' . esc_html__( 'Balance:', 'voucher-captain' ) . ' ' . wp_kses_post( wc_price( $v->balance, array( 'currency' => $v->currency ) ) ) . '</small> · ';
			}
			if ( $v->recipient_email ) {
				echo '<small>→ ' . esc_html( $v->recipient_email ) . '</small> · ';
			}
			echo '<a href="' . esc_url( vcap()->downloads->admin_url( $v ) ) . '" target="_blank">PDF</a></li>';
		}
		echo '</ul>';
		do_action( 'vcap_order_metabox_after', $order );
	}

	public function actions( array $actions, $order = null ): array {
		$order = $order instanceof \WC_Order ? $order : self::order_from( $GLOBALS['theorder'] ?? ( $GLOBALS['post'] ?? null ) );
		if ( $order && \VCAP\CouponManager::has_shortfall( $order ) ) {
			$actions['vcap_accept_shortfall'] = __( 'Accept voucher shortfall and fulfil the order', 'voucher-captain' );
		}
		if ( $order && $this->has_voucher_items( $order ) ) {
			$actions['vcap_issue']  = __( 'Generate vouchers', 'voucher-captain' );
			$actions['vcap_resend'] = __( 'Resend voucher emails', 'voucher-captain' );
		}
		return $actions;
	}

	public function issue( \WC_Order $order ): void {
		$created = $this->issuer->issue_for_order( $order, true );
		if ( ! $created ) {
			$order->add_order_note( __( 'Generate vouchers: all vouchers for this order already exist.', 'voucher-captain' ) );
		}
	}

	public function resend( \WC_Order $order ): void {
		$count  = 0;
		$failed = array();
		foreach ( $this->repo->for_order( $order->get_id() ) as $v ) {
			if ( Voucher::STATUS_VOID === $v->status ) {
				continue;
			}
			$sent = vcap()->delivery->send( $v );
			if ( ! $sent ) {
				$failed[] = $v->code;
				$this->repo->log( $v->id, 'delivery_failed', null, null, __( 'Resend from the order: no email was sent.', 'voucher-captain' ) );
				continue;
			}
			$this->repo->log( $v->id, 'resent', null, null, sprintf( 'Sent to %d recipient(s)', count( $sent ) ) );
			++$count;
		}
		/* translators: %d: number of vouchers */
		$note = sprintf( __( 'Voucher emails resent for %d voucher(s).', 'voucher-captain' ), $count );
		if ( $failed ) {
			/* translators: %s: voucher codes */
			$note .= ' ' . sprintf( __( 'Not sent (email disabled or no valid address): %s', 'voucher-captain' ), implode( ', ', $failed ) );
		}
		$order->add_order_note( $note );
	}
}
