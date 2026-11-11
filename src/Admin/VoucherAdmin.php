<?php
namespace WCVS\Admin;

use WCVS\Delivery;
use WCVS\Voucher;
use WCVS\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Issued vouchers: list/search/filter/export, detail view, edits, balance adjustments, resend, void.
 */
final class VoucherAdmin {
	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'admin_post_wcvs_voucher_action', array( $this, 'handle_action' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_wcvs_export', array( $this, 'export' ) );
		add_filter( 'set-screen-option', array( $this, 'screen_option' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function screen_option( $status, $option, $value ) {
		return 'wcvs_vouchers_per_page' === $option ? (int) $value : $status;
	}

	private function can(): bool {
		return current_user_can( 'wcvs_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	public function notices(): void {
		if ( empty( $_GET['wcvs_msg'] ) || ! $this->can() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$messages = array(
			'saved'       => __( 'Voucher saved.', 'voucher-captain' ),
			'resent'      => __( 'Voucher email sent.', 'voucher-captain' ),
			'voided'      => __( 'Voucher voided.', 'voucher-captain' ),
			'reactivated' => __( 'Voucher reactivated.', 'voucher-captain' ),
			'adjusted'    => __( 'Balance updated.', 'voucher-captain' ),
			'bulk'        => __( 'Bulk action completed.', 'voucher-captain' ),
			'issued'      => __( 'Vouchers generated.', 'voucher-captain' ),
			'none'        => __( 'No new vouchers were needed for this order.', 'voucher-captain' ),
		);
		$key = sanitize_key( $_GET['wcvs_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	public function page(): void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		$id = absint( $_GET['voucher'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			$this->detail( $id );
			return;
		}
		$table = new VouchersListTable( $this->repo );
		$table->prepare_items();
		echo '<div class="wrap wcvs-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Issued vouchers', 'voucher-captain' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=wcvs-scanner' ) ) . '">' . esc_html__( 'Open scanner', 'voucher-captain' ) . '</a><hr class="wp-header-end">';
		$table->views();
		// Single GET form (like core list tables): search, filters, pagination and bulk actions.
		echo '<form method="get"><input type="hidden" name="page" value="wcvs-vouchers" />';
		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $_GET['status'] ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		wp_nonce_field( 'wcvs_bulk', 'wcvs_bulk_nonce', false );
		$table->search_box( __( 'Search code, email, name, order', 'voucher-captain' ), 'wcvs-search' );
		$table->display();
		echo '</form></div>';
	}

	private function detail( int $id ): void {
		$v = $this->repo->get( $id );
		if ( ! $v ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Voucher not found.', 'voucher-captain' ) . '</p></div>';
			return;
		}
		$order   = $v->order();
		$product = $v->product();
		$back    = admin_url( 'admin.php?page=wcvs-vouchers' );
		$form    = static function ( string $do, string $label, string $class = 'button', array $extra = array() ) use ( $v ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcvs-inline-form">';
			echo '<input type="hidden" name="action" value="wcvs_voucher_action" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $v->id ) . '" />';
			foreach ( $extra as $k => $val ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $val ) . '" />';
			}
			wp_nonce_field( 'wcvs_voucher_' . $v->id );
			echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		};
		?>
		<div class="wrap wcvs-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( /* translators: %s: code */ __( 'Voucher %s', 'voucher-captain' ), $v->code ) ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back to list', 'voucher-captain' ); ?></a>
			<hr class="wp-header-end">

			<div class="wcvs-detail-grid">
				<div class="wcvs-card">
					<h2><?php esc_html_e( 'Overview', 'voucher-captain' ); ?></h2>
					<table class="widefat striped" role="presentation">
						<tr><th><?php esc_html_e( 'Status', 'voucher-captain' ); ?></th><td><mark class="wcvs-status wcvs-status--<?php echo esc_attr( $v->status ); ?>"><span><?php echo esc_html( $v->status_label() ); ?></span></mark></td></tr>
						<tr><th><?php esc_html_e( 'Type', 'voucher-captain' ); ?></th><td><?php echo esc_html( Voucher::types()[ $v->type ] ?? $v->type ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Product', 'voucher-captain' ); ?></th><td><?php echo $product ? '<a href="' . esc_url( get_edit_post_link( $v->product_id ) ?: '' ) . '">' . esc_html( $product->get_name() ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th><td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Value', 'voucher-captain' ); ?></th><td><?php echo 'percent' === $v->discount_type ? esc_html( $v->initial_amount . '%' ) : wp_kses_post( wc_price( $v->initial_amount, array( 'currency' => $v->currency ) ) ); ?></td></tr>
						<?php if ( $v->is_multi_use() ) : ?>
							<tr><th><?php esc_html_e( 'Balance', 'voucher-captain' ); ?></th><td><strong><?php echo wp_kses_post( wc_price( $v->balance, array( 'currency' => $v->currency ) ) ); ?></strong></td></tr>
						<?php endif; ?>
						<?php if ( $v->is_ticket() ) : ?>
							<tr><th><?php esc_html_e( 'Check-ins', 'voucher-captain' ); ?></th><td><?php echo esc_html( $v->checkins . ' / ' . $v->max_checkins ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Usage', 'voucher-captain' ); ?></th><td><?php echo esc_html( array( 'single' => __( 'Single use', 'voucher-captain' ), 'balance' => __( 'Stored balance', 'voucher-captain' ), 'unlimited' => __( 'Reusable', 'voucher-captain' ) )[ $v->usage_mode ] ?? $v->usage_mode ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Coupon', 'voucher-captain' ); ?></th><td><?php echo $v->coupon_id ? '<a href="' . esc_url( get_edit_post_link( $v->coupon_id ) ?: '' ) . '">#' . esc_html( (string) $v->coupon_id ) . '</a>' : esc_html__( 'none (ticket)', 'voucher-captain' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Delivered', 'voucher-captain' ); ?></th><td><?php echo $v->delivered_at ? esc_html( get_date_from_gmt( $v->delivered_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Buyer', 'voucher-captain' ); ?></th><td><?php echo esc_html( trim( $v->buyer_name . ' <' . $v->buyer_email . '>' ) ); ?></td></tr>
					</table>
					<p class="wcvs-actions">
						<a class="button" target="_blank" href="<?php echo esc_url( wcvs()->downloads->admin_url( $v ) ); ?>"><?php esc_html_e( 'View PDF', 'voucher-captain' ); ?></a>
						<?php
						$form( 'resend_buyer', __( 'Email buyer', 'voucher-captain' ) );
						if ( is_email( $v->recipient_email ) ) {
							$form( 'resend_recipient', __( 'Email recipient', 'voucher-captain' ) );
						}
						if ( Voucher::STATUS_VOID === $v->status ) {
							$form( 'reactivate', __( 'Reactivate', 'voucher-captain' ) );
						} else {
							$form( 'void', __( 'Void voucher', 'voucher-captain' ), 'button button-link-delete' );
						}
						?>
					</p>
				</div>

				<div class="wcvs-card">
					<h2><?php esc_html_e( 'Edit', 'voucher-captain' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wcvs_voucher_action" />
						<input type="hidden" name="do" value="save" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $v->id ); ?>" />
						<?php wp_nonce_field( 'wcvs_voucher_' . $v->id ); ?>
						<table class="form-table" role="presentation">
							<tr><th><label for="wcvs-rn"><?php esc_html_e( 'Recipient name', 'voucher-captain' ); ?></label></th><td><input class="regular-text" id="wcvs-rn" name="recipient_name" value="<?php echo esc_attr( $v->recipient_name ); ?>" /></td></tr>
							<tr><th><label for="wcvs-re"><?php esc_html_e( 'Recipient email', 'voucher-captain' ); ?></label></th><td><input class="regular-text" type="email" id="wcvs-re" name="recipient_email" value="<?php echo esc_attr( $v->recipient_email ); ?>" /></td></tr>
							<tr><th><label for="wcvs-msg"><?php esc_html_e( 'Message', 'voucher-captain' ); ?></label></th><td><textarea class="large-text" rows="3" id="wcvs-msg" name="message"><?php echo esc_textarea( $v->message ); ?></textarea></td></tr>
							<tr><th><label for="wcvs-send"><?php esc_html_e( 'Send to', 'voucher-captain' ); ?></label></th><td>
								<select id="wcvs-send" name="send_to">
									<?php foreach ( array( 'buyer' => __( 'Buyer', 'voucher-captain' ), 'recipient' => __( 'Recipient', 'voucher-captain' ), 'both' => __( 'Both', 'voucher-captain' ) ) as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v->send_to, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td></tr>
							<tr><th><label for="wcvs-exp"><?php esc_html_e( 'Expiry date', 'voucher-captain' ); ?></label></th><td><input type="date" id="wcvs-exp" name="expires_at" value="<?php echo esc_attr( $v->expires_at ? get_date_from_gmt( $v->expires_at, 'Y-m-d' ) : '' ); ?>" /> <span class="description"><?php esc_html_e( 'Empty = never', 'voucher-captain' ); ?></span></td></tr>
							<tr><th><label for="wcvs-del"><?php esc_html_e( 'Delivery date', 'voucher-captain' ); ?></label></th><td><input type="datetime-local" id="wcvs-del" name="delivery_at" value="<?php echo esc_attr( $v->delivery_at && ! $v->delivered_at ? get_date_from_gmt( $v->delivery_at, 'Y-m-d\TH:i' ) : '' ); ?>" /> <span class="description"><?php esc_html_e( 'Set a future date to (re)schedule delivery.', 'voucher-captain' ); ?></span></td></tr>
							<?php if ( $v->is_ticket() ) : ?>
								<tr><th><label for="wcvs-mc"><?php esc_html_e( 'Admissions', 'voucher-captain' ); ?></label></th><td><input type="number" min="1" id="wcvs-mc" name="max_checkins" value="<?php echo esc_attr( (string) $v->max_checkins ); ?>" /></td></tr>
							<?php endif; ?>
						</table>
						<?php submit_button( __( 'Save voucher', 'voucher-captain' ) ); ?>
					</form>

					<?php if ( $v->is_multi_use() || 'single' !== $v->usage_mode ) : ?>
						<h2><?php esc_html_e( 'Adjust balance', 'voucher-captain' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcvs-balance-adjust">
							<input type="hidden" name="action" value="wcvs_voucher_action" />
							<input type="hidden" name="do" value="adjust" />
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $v->id ); ?>" />
							<?php wp_nonce_field( 'wcvs_voucher_' . $v->id ); ?>
							<label class="screen-reader-text" for="wcvs-adj-mode"><?php esc_html_e( 'Mode', 'voucher-captain' ); ?></label>
							<select name="mode" id="wcvs-adj-mode">
								<option value="subtract"><?php esc_html_e( 'Deduct', 'voucher-captain' ); ?></option>
								<option value="add"><?php esc_html_e( 'Add', 'voucher-captain' ); ?></option>
								<option value="set"><?php esc_html_e( 'Set to', 'voucher-captain' ); ?></option>
							</select>
							<label class="screen-reader-text" for="wcvs-adj-amount"><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></label>
							<input type="text" class="wc_input_price short" id="wcvs-adj-amount" name="amount" required />
							<label class="screen-reader-text" for="wcvs-adj-note"><?php esc_html_e( 'Note', 'voucher-captain' ); ?></label>
							<input type="text" id="wcvs-adj-note" name="note" placeholder="<?php esc_attr_e( 'Note (e.g. redeemed in store)', 'voucher-captain' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Apply', 'voucher-captain' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( $v->fields ) : ?>
						<h2><?php esc_html_e( 'Personalisation', 'voucher-captain' ); ?></h2>
						<table class="widefat striped" role="presentation">
							<?php foreach ( $v->fields as $key => $field ) : ?>
								<tr><th><?php echo esc_html( $field['label'] ?? $key ); ?> <code>{field:<?php echo esc_html( $key ); ?>}</code></th><td><?php echo esc_html( $field['value'] ?? '' ); ?></td></tr>
							<?php endforeach; ?>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="wcvs-card">
				<h2><?php esc_html_e( 'History', 'voucher-captain' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Date', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Event', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Balance after', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'User', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Note', 'voucher-captain' ); ?></th></tr></thead>
					<tbody>
					<?php
					$labels = VoucherRepository::event_labels();
					foreach ( array_reverse( $this->repo->events( $v->id ) ) as $e ) :
						$user = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null;
						?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							<td><?php echo esc_html( $labels[ $e['event'] ] ?? $e['event'] ); ?></td>
							<td><?php echo null !== $e['amount'] && 'checkin' !== $e['event'] && 'checkin_undo' !== $e['event'] ? wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $v->currency ) ) ) : esc_html( null !== $e['amount'] ? (string) (int) $e['amount'] : '' ); ?></td>
							<td><?php echo null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $v->currency ) ) ) : ''; ?></td>
							<td><?php echo $e['order_id'] ? '#' . esc_html( (string) $e['order_id'] ) : ''; ?></td>
							<td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
							<td><?php echo esc_html( (string) $e['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function redirect( string $msg, int $id = 0 ): void {
		$args = array( 'page' => 'wcvs-vouchers', 'wcvs_msg' => $msg );
		if ( $id ) {
			$args['voucher'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_action(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'wcvs_voucher_' . $id );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$v = $this->repo->get( $id );
		if ( ! $v ) {
			wp_die( esc_html__( 'Voucher not found.', 'voucher-captain' ), 404 );
		}
		$do = sanitize_key( $_POST['do'] ?? '' );
		switch ( $do ) {
			case 'resend_buyer':
			case 'resend_recipient':
				$sent = wcvs()->delivery->send( $v, 'resend_buyer' === $do ? 'buyer' : 'recipient' );
				if ( 'resend_buyer' === $do && ! $sent && isset( WC()->mailer()->get_emails()['WCVS_Email_Voucher_Buyer'] ) ) {
					WC()->mailer()->get_emails()['WCVS_Email_Voucher_Buyer']->trigger( $v );
				}
				$this->repo->log( $v->id, 'resent', null, null, 'resend_buyer' === $do ? $v->buyer_email : $v->recipient_email );
				$this->redirect( 'resent', $id );
				break;
			case 'void':
				wcvs()->issuer->void( $v, __( 'Voided by admin', 'voucher-captain' ) );
				$this->redirect( 'voided', $id );
				break;
			case 'reactivate':
				wcvs()->issuer->reactivate( $v );
				$this->redirect( 'reactivated', $id );
				break;
			case 'adjust':
				$amount = (float) wc_format_decimal( wp_unslash( (string) ( $_POST['amount'] ?? 0 ) ) );
				$mode   = sanitize_key( $_POST['mode'] ?? 'subtract' );
				$note   = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
				$new    = 'set' === $mode ? $amount : ( 'add' === $mode ? $v->balance + $amount : $v->balance - $amount );
				if ( 'single' !== $v->usage_mode && ! $v->is_multi_use() ) {
					// Reusable vouchers have no stored balance: switch to balance tracking on first adjustment.
					$v->usage_mode = 'balance';
				}
				wcvs()->coupons->set_balance( $v, $new, 'subtract' === $mode ? 'redeemed' : 'adjusted', abs( $new - $v->balance ), $note );
				$this->redirect( 'adjusted', $id );
				break;
			case 'save':
				$this->save( $v );
				$this->redirect( 'saved', $id );
				break;
		}
		$this->redirect( '', $id );
	}

	private function save( Voucher $v ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_action().
		$v->recipient_name  = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
		$v->recipient_email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
		$v->message         = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$send_to            = sanitize_key( $_POST['send_to'] ?? 'buyer' );
		$v->send_to         = in_array( $send_to, array( 'buyer', 'recipient', 'both' ), true ) ? $send_to : 'buyer';
		foreach ( array( 'recipient_name', 'recipient_email', 'message' ) as $key ) {
			if ( isset( $v->fields[ $key ] ) ) {
				$v->fields[ $key ]['value'] = $v->$key;
			}
		}
		if ( isset( $_POST['max_checkins'] ) ) {
			$v->max_checkins = max( 1, absint( $_POST['max_checkins'] ) );
			if ( $v->is_ticket() && Voucher::STATUS_REDEEMED === $v->status && $v->checkins < $v->max_checkins ) {
				$v->status = Voucher::STATUS_ACTIVE;
			}
		}
		$exp = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
		$v->expires_at = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ? get_gmt_from_date( $exp . ' 23:59:59' ) : null;
		if ( Voucher::STATUS_EXPIRED === $v->status && ! $v->is_expired() ) {
			$v->status = Voucher::STATUS_ACTIVE;
		}
		$delivery = sanitize_text_field( wp_unslash( $_POST['delivery_at'] ?? '' ) );
		// phpcs:enable
		$this->repo->update( $v );
		$this->repo->log( $v->id, 'edited' );

		if ( $v->coupon_id ) {
			$coupon = new \WC_Coupon( $v->coupon_id );
			if ( $coupon->get_id() ) {
				$coupon->set_date_expires( $v->expires_at ? strtotime( $v->expires_at . ' UTC' ) : null );
				$coupon->save();
			}
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $delivery ) ) {
			$utc = get_gmt_from_date( str_replace( 'T', ' ', $delivery ) . ':00' );
			if ( strtotime( $utc . ' UTC' ) > time() ) {
				wcvs()->delivery->reschedule( $v, $utc );
			}
		}
	}

	public function handle_bulk(): void {
		$ids = array_map( 'absint', (array) ( $_REQUEST['voucher_ids'] ?? array() ) );
		if ( ! isset( $_REQUEST['wcvs_bulk_nonce'] ) || ! $ids ) {
			return;
		}
		check_admin_referer( 'wcvs_bulk', 'wcvs_bulk_nonce' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '-1' ) );
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ?? '' ) );
		}
		if ( ! in_array( $action, array( 'void', 'reactivate', 'resend' ), true ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			$v = $this->repo->get( $id );
			if ( ! $v ) {
				continue;
			}
			if ( 'void' === $action ) {
				wcvs()->issuer->void( $v, __( 'Voided by admin (bulk)', 'voucher-captain' ) );
			} elseif ( 'reactivate' === $action && Voucher::STATUS_VOID === $v->status ) {
				wcvs()->issuer->reactivate( $v );
			} elseif ( 'resend' === $action ) {
				wcvs()->delivery->send( $v );
				$this->repo->log( $v->id, 'resent' );
			}
		}
		$this->redirect( 'bulk' );
	}

	public function export(): void {
		check_admin_referer( 'wcvs_export' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$table = new VouchersListTable( $this->repo );
		$args  = array_merge( $table->filter_args(), array( 'per_page' => 500 ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="vouchers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'code', 'type', 'status', 'product', 'value', 'balance', 'currency', 'expires', 'order', 'buyer_name', 'buyer_email', 'recipient_name', 'recipient_email', 'checkins', 'max_checkins', 'created' ) );
		$page = 1;
		do {
			$result = $this->repo->query( array_merge( $args, array( 'page' => $page ) ) );
			foreach ( $result['items'] as $v ) {
				$product = $v->product();
				$row     = array( $v->code, $v->type, $v->status_label(), $product ? $product->get_name() : '', $v->initial_amount, $v->balance, $v->currency, $v->expires_at ? get_date_from_gmt( $v->expires_at, 'Y-m-d' ) : '', $v->order_id, $v->buyer_name, $v->buyer_email, $v->recipient_name, $v->recipient_email, $v->checkins, $v->max_checkins, get_date_from_gmt( $v->created_at, 'Y-m-d H:i' ) );
				// Neutralise spreadsheet formula injection.
				$row = array_map( static fn( $c ) => is_string( $c ) && preg_match( '/^[=+\-@\t\r]/', $c ) ? "'" . $c : $c, $row );
				fputcsv( $out, $row );
			}
			++$page;
		} while ( count( $result['items'] ) === 500 );
		fclose( $out ); // phpcs:ignore
		exit;
	}
}
