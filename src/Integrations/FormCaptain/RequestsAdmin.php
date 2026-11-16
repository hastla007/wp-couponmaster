<?php
/**
 * Admin screen for voucher requests from Form Captain.
 *
 * @package VCAP\Integrations\FormCaptain
 */

namespace VCAP\Integrations\FormCaptain;

use VCAP\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Vouchers → Voucher requests: list, edit, approve (issues through the voucher service) or reject. Every change needs
 * the voucher capability and a nonce per request. The screen is shown on sites with the request table only.
 *
 * @since 1.2.0
 */
final class RequestsAdmin {
	public const PAGE = 'vcap-fc-requests';

	/**
	 * Hooks the menu entry and the form handler.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_vcap_fc_request', array( self::class, 'handle' ) );
	}

	/**
	 * Whether Voucher Captain is running and the request table exists.
	 */
	private static function usable(): bool {
		return class_exists( 'WooCommerce' ) && class_exists( Menu::class ) && RequestRepository::ready();
	}

	/**
	 * Capability for the screen.
	 */
	private static function cap(): string {
		return Menu::cap();
	}

	/**
	 * Adds the submenu below Vouchers.
	 */
	public static function menu(): void {
		if ( ! self::usable() ) {
			return;
		}
		// With the request service of Voucher Captain the requests are listed there; this screen stays only
		// while the fallback table still holds requests from before.
		if ( null !== RequestService::core_service() && 0 === array_sum( ( new RequestRepository() )->counts() ) ) {
			return;
		}
		$pending = (int) ( ( new RequestRepository() )->counts()[ RequestRepository::STATUS_PENDING ] ?? 0 );
		$label   = __( 'Voucher requests', 'voucher-captain' );
		if ( $pending > 0 ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>';
		}
		add_submenu_page( 'vcap-vouchers', __( 'Voucher requests', 'voucher-captain' ), $label, self::cap(), self::PAGE, array( self::class, 'page' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			RequestRepository::STATUS_PENDING   => __( 'To be reviewed', 'voucher-captain' ),
			RequestRepository::STATUS_APPROVING => __( 'Being issued', 'voucher-captain' ),
			RequestRepository::STATUS_APPROVED  => __( 'Approved', 'voucher-captain' ),
			RequestRepository::STATUS_REJECTED  => __( 'Rejected', 'voucher-captain' ),
			RequestRepository::STATUS_WITHDRAWN => __( 'Consent withdrawn', 'voucher-captain' ),
		);
	}

	/**
	 * Notices after a redirect.
	 *
	 * @return array<string, array{0:string, 1:string}> key => [type, text]
	 */
	private static function messages(): array {
		return array(
			'saved'    => array( 'success', __( 'Request saved.', 'voucher-captain' ) ),
			'approved' => array( 'success', __( 'Request approved, vouchers issued.', 'voucher-captain' ) ),
			'rejected' => array( 'success', __( 'Request rejected.', 'voucher-captain' ) ),
		);
	}

	/**
	 * Renders the list or one request.
	 */
	public static function page(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$id     = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		$msg    = isset( $_GET['vcap_msg'] ) ? sanitize_key( wp_unslash( $_GET['vcap_msg'] ) ) : '';
		$error  = isset( $_GET['vcap_error'] ) ? sanitize_text_field( wp_unslash( $_GET['vcap_error'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Voucher requests', 'voucher-captain' ) . '</h1>';
		$messages = self::messages();
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $msg ][1] ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		$repo    = new RequestRepository();
		$request = $id ? $repo->get( $id ) : null;
		if ( $request ) {
			self::detail( $request );
		} else {
			self::table( $repo, isset( self::statuses()[ $status ] ) ? $status : '' );
		}
		echo '</div>';
	}

	/**
	 * Request list.
	 *
	 * @param RequestRepository $repo   Storage.
	 * @param string            $status Status filter.
	 */
	private static function table( RequestRepository $repo, string $status ): void {
		$labels = self::statuses();
		$counts = $repo->counts();
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<p class="description">' . esc_html__( 'Requests sent with Form Captain forms. A request never issues anything by itself: vouchers are issued only when you approve it.', 'voucher-captain' ) . '</p>';
		echo '<ul class="subsubsub">';
		$links = array( '<a href="' . esc_url( $base ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'voucher-captain' ) . ' <span class="count">(' . (int) array_sum( $counts ) . ')</span></a>' );
		foreach ( $labels as $key => $label ) {
			if ( empty( $counts[ $key ] ) ) {
				continue;
			}
			$links[] = '<a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '"' . ( $status === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a>';
		}
		echo '<li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Request', 'voucher-captain' ), __( 'Date', 'voucher-captain' ), __( 'Company', 'voucher-captain' ), __( 'Contact person', 'voucher-captain' ), __( 'Vouchers', 'voucher-captain' ), __( 'Status', 'voucher-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = $repo->list( $status, 100 );
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No requests yet.', 'voucher-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$url = add_query_arg( 'request', (int) $row['id'], $base );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '"><strong>#' . (int) $row['id'] . '</strong></a></td>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['company'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['contact_name'] ) . '</td>';
			echo '<td>' . (int) $row['quantity'] . ' × ' . wp_kses_post( wc_price( (float) $row['value'] ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $row['status'] ] ?? $row['status'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Voucher products for the approval select.
	 *
	 * @return array<int, string> id => name
	 */
	private static function products(): array {
		$out = array();
		$ids = wc_get_products(
			array(
				'limit'      => 200,
				'status'     => 'publish',
				'type'       => array( 'simple' ),
				'return'     => 'ids',
				'meta_key'   => '_vcap_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( (array) $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$out[ (int) $product_id ] = $product->get_name() . ' (#' . (int) $product_id . ')';
			}
		}
		return $out;
	}

	/**
	 * One request: details, edit form, approve and reject.
	 *
	 * @param array<string, mixed> $request Request row.
	 */
	private static function detail( array $request ): void {
		$id      = (int) $request['id'];
		$labels  = self::statuses();
		$pending = RequestRepository::STATUS_PENDING === $request['status'];
		$action  = admin_url( 'admin-post.php' );
		$fields  = array(
			'company'      => __( 'Company', 'voucher-captain' ),
			'contact_name' => __( 'Contact person', 'voucher-captain' ),
			'email'        => __( 'Email', 'voucher-captain' ),
			'quantity'     => __( 'Number of vouchers', 'voucher-captain' ),
			'value'        => __( 'Value per voucher', 'voucher-captain' ),
		);
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">&larr; ' . esc_html__( 'All requests', 'voucher-captain' ) . '</a></p>';
		/* translators: 1: request id, 2: status */
		echo '<h2>' . esc_html( sprintf( __( 'Request #%1$d – %2$s', 'voucher-captain' ), $id, $labels[ $request['status'] ] ?? $request['status'] ) ) . '</h2>';
		if ( (int) $request['order_id'] ) {
			$order = wc_get_order( (int) $request['order_id'] );
			if ( $order ) {
				/* translators: 1: order number, 2: number of vouchers */
				echo '<p>' . esc_html( sprintf( __( 'Order #%1$s, %2$d voucher(s) issued.', 'voucher-captain' ), $order->get_order_number(), (int) $request['vouchers'] ) ) . ' <a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open order', 'voucher-captain' ) . '</a></p>';
			}
		}
		if ( (int) $request['anonymized'] ) {
			echo '<p>' . esc_html__( 'The personal data of this request was removed (entry deleted or consent withdrawn in Form Captain).', 'voucher-captain' ) . '</p>';
		}
		if ( '' !== (string) $request['note'] ) {
			echo '<p><strong>' . esc_html__( 'Note', 'voucher-captain' ) . ':</strong> ' . esc_html( (string) $request['note'] ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( 'vcap_fc_request_' . $id );
		echo '<input type="hidden" name="action" value="vcap_fc_request" /><input type="hidden" name="request" value="' . (int) $id . '" />';
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $label ) {
			$value = 'value' === $key ? wc_format_localized_decimal( (string) wc_format_decimal( (string) $request['value'], wc_get_price_decimals() ) ) : (string) $request[ $key ];
			echo '<tr><th scope="row"><label for="vcap-fc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" class="regular-text" id="vcap-fc-' . esc_attr( $key ) . '" name="vcap_fc[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"' . ( $pending ? '' : ' readonly' ) . ' />';
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="vcap-fc-personalization">' . esc_html__( 'Personalisation text', 'voucher-captain' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="vcap-fc-personalization" name="vcap_fc[personalization]"' . ( $pending ? '' : ' readonly' ) . '>' . esc_textarea( (string) $request['personalization'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="vcap-fc-product">' . esc_html__( 'Voucher product', 'voucher-captain' ) . '</label></th><td>';
		echo '<select id="vcap-fc-product" name="vcap_fc[product_id]"' . ( $pending ? '' : ' disabled' ) . '><option value="0">' . esc_html__( '— Choose —', 'voucher-captain' ) . '</option>';
		foreach ( self::products() as $product_id => $name ) {
			echo '<option value="' . (int) $product_id . '"' . selected( (int) $request['product_id'], $product_id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Approving creates an order on hold (to be invoiced) with this product and issues the vouchers through Voucher Captain.', 'voucher-captain' ) . '</p></td></tr>';
		if ( $pending ) {
			echo '<tr><th scope="row"><label for="vcap-fc-reason">' . esc_html__( 'Reason for rejection (internal)', 'voucher-captain' ) . '</label></th><td><input type="text" class="regular-text" id="vcap-fc-reason" name="vcap_fc_reason" maxlength="255" /></td></tr>';
		}
		echo '</table>';
		if ( $pending ) {
			echo '<p class="submit">';
			echo '<button type="submit" class="button" name="do" value="save">' . esc_html__( 'Save changes', 'voucher-captain' ) . '</button> ';
			echo '<button type="submit" class="button button-primary" name="do" value="approve">' . esc_html__( 'Approve and issue vouchers', 'voucher-captain' ) . '</button> ';
			echo '<button type="submit" class="button button-link-delete" name="do" value="reject">' . esc_html__( 'Reject', 'voucher-captain' ) . '</button>';
			echo '</p>';
		}
		echo '</form>';
	}

	/**
	 * Handles save, approve and reject.
	 */
	public static function handle(): void {
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		check_admin_referer( 'vcap_fc_request_' . $id );
		if ( ! self::usable() || ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$input   = isset( $_POST['vcap_fc'] ) && is_array( $_POST['vcap_fc'] ) ? map_deep( wp_unslash( $_POST['vcap_fc'] ), 'strval' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by RequestService::sanitize().
		$service = Bootstrap::service();
		$result  = new \WP_Error( 'vcap_fc_action', __( 'Unknown action.', 'voucher-captain' ) );
		$msg     = '';
		if ( 'save' === $do || 'approve' === $do ) {
			$result = $service->edit( $id, $input );
			$msg    = 'saved';
			if ( true === $result && 'approve' === $do ) {
				$result = $service->approve( $id, absint( $input['product_id'] ?? 0 ) );
				$msg    = 'approved';
			}
		} elseif ( 'reject' === $do ) {
			$result = $service->reject( $id, isset( $_POST['vcap_fc_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['vcap_fc_reason'] ) ) : '' );
			$msg    = 'rejected';
		}
		$args = array(
			'page'    => self::PAGE,
			'request' => $id,
		);
		if ( is_wp_error( $result ) ) {
			$args['vcap_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['vcap_msg'] = $msg;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
