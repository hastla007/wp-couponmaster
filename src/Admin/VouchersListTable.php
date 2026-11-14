<?php
namespace VCAP\Admin;

use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class VouchersListTable extends \WP_List_Table {
	public function __construct( private VoucherRepository $repo ) {
		parent::__construct(
			array(
				'singular' => 'voucher',
				'plural'   => 'vouchers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox" />',
			'code'      => __( 'Code', 'voucher-captain' ),
			'product'   => __( 'Product', 'voucher-captain' ),
			'value'     => __( 'Value / balance', 'voucher-captain' ),
			'status'    => __( 'Status', 'voucher-captain' ),
			'recipient' => __( 'Buyer → recipient', 'voucher-captain' ),
			'order'     => __( 'Order', 'voucher-captain' ),
			'expires'   => __( 'Expires', 'voucher-captain' ),
			'created'   => __( 'Issued', 'voucher-captain' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'code'    => array( 'code', false ),
			'value'   => array( 'balance', false ),
			'status'  => array( 'status', false ),
			'order'   => array( 'order_id', false ),
			'expires' => array( 'expires_at', false ),
			'created' => array( 'created_at', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'resend'     => __( 'Resend email', 'voucher-captain' ),
			'void'       => __( 'Void', 'voucher-captain' ),
			'reactivate' => __( 'Reactivate', 'voucher-captain' ),
		);
	}

	protected function get_views(): array {
		$current = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$views   = array( '' => __( 'All', 'voucher-captain' ) ) + Voucher::statuses();
		$out     = array();
		foreach ( $views as $key => $label ) {
			$url         = add_query_arg( array( 'page' => 'vcap-vouchers', 'status' => $key ), admin_url( 'admin.php' ) );
			$out[ $key ?: 'all' ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $out;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$type     = sanitize_key( $_GET['type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$expiring = sanitize_key( $_GET['expiring'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$undeliv  = sanitize_key( $_GET['undelivered'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="vcap-filter-type">' . esc_html__( 'Filter by type', 'voucher-captain' ) . '</label>';
		echo '<select name="type" id="vcap-filter-type"><option value="">' . esc_html__( 'All types', 'voucher-captain' ) . '</option>';
		foreach ( Voucher::types() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label><input type="checkbox" name="expiring" value="soon" ' . checked( $expiring, 'soon', false ) . '> ' . esc_html__( 'Expiring within 30 days', 'voucher-captain' ) . '</label> ';
		echo '<label><input type="checkbox" name="undelivered" value="yes" ' . checked( $undeliv, 'yes', false ) . '> ' . esc_html__( 'Not delivered', 'voucher-captain' ) . '</label> ';
		submit_button( __( 'Filter', 'voucher-captain' ), '', 'filter_action', false );
		$args = $this->filter_args();
		$args['s'] = $args['search']; // filter_args() reads the search term from "s".
		unset( $args['search'] );
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'vcap_export' ), array_filter( $args ) ), admin_url( 'admin-post.php' ) ), 'vcap_export' );
		echo ' <a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'voucher-captain' ) . '</a>';
		echo '</div>';
	}

	public function filter_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		return array(
			'search'   => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ),
			'status'   => sanitize_key( $_REQUEST['status'] ?? '' ),
			'type'     => sanitize_key( $_REQUEST['type'] ?? '' ),
			'product'  => absint( $_REQUEST['product'] ?? 0 ),
			'expiring' => sanitize_key( $_REQUEST['expiring'] ?? '' ),
			'undelivered' => sanitize_key( $_REQUEST['undelivered'] ?? '' ),
			'orderby'  => sanitize_key( $_REQUEST['orderby'] ?? 'id' ),
			'order'    => sanitize_key( $_REQUEST['order'] ?? 'desc' ),
		);
		// phpcs:enable
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'vcap_vouchers_per_page', 20 );
		$result   = $this->repo->query(
			array_merge(
				$this->filter_args(),
				array(
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'code' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/** Row checkbox with a label naming the voucher, like the core list tables (screen readers, bulk actions). */
	protected function column_cb( $item ): string {
		$id = 'cb-select-' . (int) $item->id;
		/* translators: %s: voucher code */
		return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Select voucher %s', 'voucher-captain' ), $item->code ) ) . '</label>'
			. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="voucher_ids[]" value="' . esc_attr( (string) $item->id ) . '" />';
	}

	protected function column_code( Voucher $v ): string {
		$url     = add_query_arg( array( 'page' => 'vcap-vouchers', 'voucher' => $v->id ), admin_url( 'admin.php' ) );
		$actions = array(
			'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Details', 'voucher-captain' ) . '</a>',
			'pdf'  => '<a href="' . esc_url( vcap()->downloads->admin_url( $v ) ) . '" target="_blank">PDF</a>',
		);
		if ( $v->coupon_id ) {
			$actions['coupon'] = '<a href="' . esc_url( get_edit_post_link( $v->coupon_id ) ?: '' ) . '">' . esc_html__( 'Coupon', 'voucher-captain' ) . '</a>';
		}
		return '<strong><a href="' . esc_url( $url ) . '"><code>' . esc_html( $v->code ) . '</code></a></strong><br><small>' . esc_html( Voucher::types()[ $v->type ] ?? $v->type ) . '</small>' . $this->row_actions( $actions );
	}

	protected function column_product( Voucher $v ): string {
		$product = $v->product();
		return $product ? '<a href="' . esc_url( get_edit_post_link( $v->product_id ) ?: '' ) . '">' . esc_html( $product->get_name() ) . '</a>' : '–';
	}

	protected function column_value( Voucher $v ): string {
		if ( 'percent' === $v->discount_type ) {
			return esc_html( wc_format_localized_decimal( $v->initial_amount ) . '%' );
		}
		$out = wc_price( $v->initial_amount, array( 'currency' => $v->currency ) );
		if ( $v->is_multi_use() ) {
			$out .= '<br><small>' . esc_html__( 'Balance:', 'voucher-captain' ) . ' ' . wc_price( $v->balance, array( 'currency' => $v->currency ) ) . '</small>';
		}
		if ( $v->is_ticket() ) {
			$out .= '<br><small>' . esc_html( sprintf( /* translators: 1: used, 2: max */ __( 'Check-ins: %1$d / %2$d', 'voucher-captain' ), $v->checkins, $v->max_checkins ) ) . '</small>';
		}
		return wp_kses_post( $out );
	}

	protected function column_status( Voucher $v ): string {
		$status = Voucher::STATUS_ACTIVE === $v->status && $v->is_expired() ? Voucher::STATUS_EXPIRED : $v->status;
		$out    = '<mark class="vcap-status vcap-status--' . esc_attr( $status ) . '"><span>' . esc_html( $v->status_label() ) . '</span></mark>';
		if ( Voucher::STATUS_SCHEDULED === $v->status && $v->delivery_at ) {
			$out .= '<br><small>' . esc_html( get_date_from_gmt( $v->delivery_at, get_option( 'date_format' ) ) ) . '</small>';
		}
		return $out;
	}

	protected function column_recipient( Voucher $v ): string {
		$out = esc_html( $v->buyer_name ?: $v->buyer_email );
		if ( $v->recipient_name || $v->recipient_email ) {
			$out .= ' → ' . esc_html( $v->recipient_name ?: $v->recipient_email );
		}
		return $out;
	}

	protected function column_order( Voucher $v ): string {
		$order = $v->order();
		return $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : ( $v->order_id ? '#' . esc_html( (string) $v->order_id ) : '–' );
	}

	protected function column_expires( Voucher $v ): string {
		return $v->expires_at ? esc_html( get_date_from_gmt( $v->expires_at, get_option( 'date_format' ) ) ) : esc_html__( 'Never', 'voucher-captain' );
	}

	protected function column_created( Voucher $v ): string {
		return esc_html( get_date_from_gmt( $v->created_at, get_option( 'date_format' ) ) );
	}

	public function no_items(): void {
		esc_html_e( 'No vouchers found.', 'voucher-captain' );
	}
}
