<?php
namespace VCAP\Admin;

use VCAP\Integrations\VoucherRequests;

defined( 'ABSPATH' ) || exit;

/**
 * Vouchers → Voucher requests: requests handed over by a form plugin (Integrations\VoucherRequests). A manager
 * who may issue vouchers without an order (IssueVoucher::can()) checks a request and issues its vouchers – or
 * rejects it. Nothing is issued before that click.
 */
final class VoucherRequestsAdmin {
	public const SLUG = 'vcap-requests';

	public function __construct( private VoucherRequests $requests ) {}

	public function register(): void {
		add_action( 'admin_post_vcap_request_confirm', array( $this, 'confirm' ) );
		add_action( 'admin_post_vcap_request_reject', array( $this, 'reject' ) );
		add_action( 'admin_post_vcap_request_handover', array( $this, 'handover' ) );
	}

	/** States as shown in the list. */
	private static function state_label( string $state ): string {
		return VoucherRequests::state_label( $state );
	}

	private static function kind_label( string $kind ): string {
		return 'personalisation' === $kind ? __( 'Personalisation', 'voucher-captain' ) : __( 'Company order', 'voucher-captain' );
	}

	public function page(): void {
		if ( ! IssueVoucher::can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$id      = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$request = $id ? VoucherRequests::get( $id ) : null;
		echo '<div class="wrap vcap-wrap"><h1>' . esc_html__( 'Voucher requests', 'voucher-captain' ) . '</h1>';
		echo '<p>' . esc_html__( 'Requests from forms (e.g. company orders or personalisation wishes). A request is no voucher: vouchers are issued only when you confirm it here. Payment is agreed with the customer outside this screen.', 'voucher-captain' ) . '</p>';
		if ( $request ) {
			$this->detail( $request );
		} else {
			$this->table();
		}
		echo '</div>';
	}

	/** R5: list filters (default: requests still to be dealt with). */
	private static function views(): array {
		return array(
			'open' => array( __( 'To do', 'voucher-captain' ), array( VoucherRequests::STATE_OPEN, VoucherRequests::STATE_PARTIAL ) ),
			'done' => array( __( 'Done', 'voucher-captain' ), array( VoucherRequests::STATE_ISSUED, VoucherRequests::STATE_HANDED_OVER ) ),
			'all'  => array( __( 'All', 'voucher-captain' ), array() ),
		);
	}

	private function table(): void {
		$views = self::views();
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$view  = isset( $views[ $view ] ) ? $view : 'open';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$links = array();
		foreach ( $views as $key => $def ) {
			$url     = add_query_arg( array( 'page' => self::SLUG, 'view' => $key ), admin_url( 'admin.php' ) );
			$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $key === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $def[0] ) . '</a></li>';
		}
		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$result = VoucherRequests::page( $views[ $view ][1], $paged, 50 );
		$rows   = $result['items'];
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No voucher requests yet.', 'voucher-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Received', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Type', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Contact', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Vouchers', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Status', 'voucher-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url     = add_query_arg( array( 'page' => self::SLUG, 'request' => (int) $r['id'] ), admin_url( 'admin.php' ) );
			$contact = (array) $r['contact'];
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( get_date_from_gmt( (string) $r['received_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</a></td>';
			echo '<td>' . esc_html( self::kind_label( (string) $r['kind'] ) ) . '</td>';
			echo '<td>' . esc_html( trim( $contact['company'] . ' ' . $contact['name'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['quantity'] ) . '</td>';
			echo '<td>' . esc_html( self::state_label( (string) $r['state'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $result['pages'] > 1 ) {
			$pages = paginate_links(
				array(
					'base'    => add_query_arg( array( 'page' => self::SLUG, 'view' => $view, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $result['pages'],
				)
			);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $pages ) . '</div></div>';
		}
	}

	/** @param array<string, mixed> $r Request. */
	private function detail( array $r ): void {
		$contact = (array) $r['contact'];
		$back    = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( '← All voucher requests', 'voucher-captain' ) . '</a></p>';
		echo '<table class="form-table" role="presentation">';
		$lines = array(
			__( 'Type', 'voucher-captain' )        => self::kind_label( (string) $r['kind'] ),
			__( 'Status', 'voucher-captain' )      => self::state_label( (string) $r['state'] ),
			__( 'Reference', 'voucher-captain' )   => (string) $r['reference'],
			__( 'Contact', 'voucher-captain' )     => trim( implode( ' · ', array_filter( array( $contact['company'], $contact['name'], $contact['email'], $contact['phone'] ) ) ) ),
			__( 'Vouchers', 'voucher-captain' )    => (string) (int) $r['quantity'],
			__( 'Value', 'voucher-captain' )       => '' !== (string) $r['amount'] ? wp_strip_all_tags( wc_price( (float) $r['amount'] ) ) : '—',
			__( 'Message', 'voucher-captain' )     => (string) $r['message'],
			__( 'Note', 'voucher-captain' )        => (string) $r['note'],
		);
		foreach ( $lines as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( '' !== $value ? $value : '—' ) ) . '</td></tr>';
		}
		echo '</table>';
		if ( $r['recipients'] ) {
			echo '<h2>' . esc_html__( 'Recipients', 'voucher-captain' ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( (array) $r['recipients'] as $i => $rec ) {
				echo '<tr><td>' . esc_html( (string) ( $i + 1 ) ) . '</td><td>' . esc_html( (string) $rec['name'] ) . '</td><td>' . esc_html( (string) $rec['email'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		foreach ( (array) $r['errors'] as $error ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) $error ) . '</p></div>';
		}
		if ( $r['vouchers'] ) {
			echo '<p>';
			foreach ( (array) $r['vouchers'] as $voucher_id ) {
				$url = add_query_arg( array( 'page' => 'vcap-vouchers', 'voucher' => (int) $voucher_id ), admin_url( 'admin.php' ) );
				/* translators: %d: voucher id */
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Voucher #%d', 'voucher-captain' ), (int) $voucher_id ) ) . '</a> ';
			}
			echo '</p>';
		}
		$handover = VoucherRequests::handover( (int) $r['id'] );
		if ( $handover ) {
			/* translators: %d: id of the company order */
			$text = sprintf( __( 'Handed over to company order #%d', 'voucher-captain' ), $handover['order_id'] );
			echo '<div class="notice notice-info inline"><p>' . ( '' !== $handover['url'] ? '<a href="' . esc_url( $handover['url'] ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text ) ) . ' (' . esc_html( get_date_from_gmt( $handover['at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . ')</p></div>';
		}
		if ( in_array( $r['state'], array( VoucherRequests::STATE_ISSUED, VoucherRequests::STATE_HANDED_OVER ), true ) ) {
			return;
		}
		if ( $this->requests->handover_available( $r ) ) {
			?>
			<h2><?php esc_html_e( 'Company order', 'voucher-captain' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-card">
				<input type="hidden" name="action" value="vcap_request_handover" />
				<input type="hidden" name="request" value="<?php echo esc_attr( (string) (int) $r['id'] ); ?>" />
				<?php wp_nonce_field( 'vcap_request_handover_' . (int) $r['id'] ); ?>
				<p class="description"><?php esc_html_e( 'An extension can take this request over as a company order (preview, invoice, issuing in one step). The recipients then move to the company order and no voucher is issued here.', 'voucher-captain' ); ?></p>
				<?php submit_button( __( 'Take over as company order', 'voucher-captain' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		}
		?>
		<h2><?php esc_html_e( 'Issue vouchers', 'voucher-captain' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-card">
			<input type="hidden" name="action" value="vcap_request_confirm" />
			<input type="hidden" name="request" value="<?php echo esc_attr( (string) (int) $r['id'] ); ?>" />
			<?php wp_nonce_field( 'vcap_request_confirm_' . (int) $r['id'] ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vcap-request-product"><?php esc_html_e( 'Product', 'voucher-captain' ); ?></label></th>
					<td><input type="number" min="0" step="1" id="vcap-request-product" name="product_id" value="<?php echo esc_attr( (string) (int) $r['product_id'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Id of the voucher product whose settings apply; 0 = a gift card with the value below.', 'voucher-captain' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vcap-request-amount"><?php esc_html_e( 'Value', 'voucher-captain' ); ?></label></th>
					<td><input type="text" inputmode="decimal" class="wc_input_price short" id="vcap-request-amount" name="amount" value="<?php echo esc_attr( (string) $r['amount'] ); ?>" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sale', 'voucher-captain' ); ?></th>
					<td><fieldset>
						<label><input type="radio" name="sale" value="sold" required /> <?php esc_html_e( 'Sold (paid or invoiced outside the shop)', 'voucher-captain' ); ?></label><br />
						<label><input type="radio" name="sale" value="free" /> <?php esc_html_e( 'Free of charge (goodwill, prize)', 'voucher-captain' ); ?></label>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delivery', 'voucher-captain' ); ?></th>
					<td><label><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Send by email now (to the recipient, else to the contact)', 'voucher-captain' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Issue vouchers', 'voucher-captain' ) ); ?>
		</form>
		<?php if ( VoucherRequests::STATE_OPEN === $r['state'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vcap_request_reject" />
				<input type="hidden" name="request" value="<?php echo esc_attr( (string) (int) $r['id'] ); ?>" />
				<?php wp_nonce_field( 'vcap_request_reject_' . (int) $r['id'] ); ?>
				<?php submit_button( __( 'Reject and delete request', 'voucher-captain' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public function confirm(): void {
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0;
		check_admin_referer( 'vcap_request_confirm_' . $id );
		$post   = wp_unslash( $_POST );
		$result = $this->requests->confirm(
			$id,
			array(
				'product_id' => absint( $post['product_id'] ?? 0 ),
				'amount'     => (string) wc_format_decimal( sanitize_text_field( (string) ( $post['amount'] ?? '' ) ) ),
				'sale'       => sanitize_key( (string) ( $post['sale'] ?? '' ) ),
				'send'       => ! empty( $post['send'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			/* translators: %s: reason */
			Flash::add( 'error', sprintf( __( 'No voucher was issued: %s', 'voucher-captain' ), $result->get_error_message() ) );
		} elseif ( VoucherRequests::STATE_ISSUED === $result['state'] ) {
			/* translators: %d: number of vouchers */
			Flash::add( 'success', sprintf( _n( '%d voucher issued for this request.', '%d vouchers issued for this request.', count( $result['vouchers'] ), 'voucher-captain' ), count( $result['vouchers'] ) ) );
		} else {
			Flash::add( 'warning', __( 'Not all vouchers could be issued. Correct the settings and confirm again: vouchers issued already are not issued twice.', 'voucher-captain' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'request' => $id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post "vcap_request_handover" (D5): nonce per request, right vcap_manage_vouchers (checked by
	 * VoucherRequests::hand_over()). Success → to the company order of the add-on; an error → back to the request.
	 */
	public function handover(): void {
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0;
		check_admin_referer( 'vcap_request_handover_' . $id );
		$result = $this->requests->hand_over( $id );
		$back   = add_query_arg( array( 'page' => self::SLUG, 'request' => $id ), admin_url( 'admin.php' ) );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: reason */
			Flash::add( 'error', sprintf( __( 'The request was not taken over as a company order: %s', 'voucher-captain' ), $result->get_error_message() ) );
			wp_safe_redirect( $back );
			exit;
		}
		/* translators: %d: id of the company order */
		Flash::add( 'success', sprintf( __( 'Handed over to company order #%d', 'voucher-captain' ), (int) $result['order_id'] ) );
		wp_safe_redirect( '' !== $result['url'] ? $result['url'] : $back, 302, 'Voucher Captain' );
		exit;
	}

	public function reject(): void {
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0;
		check_admin_referer( 'vcap_request_reject_' . $id );
		$result = $this->requests->reject( $id );
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', $result->get_error_message() );
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'request' => $id ), admin_url( 'admin.php' ) ) );
			exit;
		}
		Flash::add( 'success', __( 'Voucher request rejected and deleted.', 'voucher-captain' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
