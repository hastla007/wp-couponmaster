<?php
namespace VCAP\Admin;

use VCAP\StoreCredit;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Store credit administration: balance, journal and manual credit/debit on the user profile; "refund as store
 * credit" on the order screen. Every change needs the voucher capability, a nonce and a reason.
 */
final class StoreCreditAdmin {
	public function __construct( private StoreCredit $credit, private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_action( 'admin_post_vcap_refund_credit', array( $this, 'refund' ) );
		add_action( 'vcap_order_metabox_after', array( $this, 'order_form' ) );
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	public function profile( \WP_User $user ): void {
		if ( ! $this->credit->enabled() || ! $this->can() ) {
			return;
		}
		$account = $this->credit->account( (int) $user->ID );
		$labels  = VoucherRepository::event_labels();
		?>
		<h2 id="vcap-store-credit"><?php esc_html_e( 'Store credit', 'voucher-captain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Balance', 'voucher-captain' ); ?></th>
				<td>
					<strong><?php echo wp_kses_post( wc_price( $account ? $account->balance : 0, array( 'currency' => $account ? $account->currency : get_option( 'woocommerce_currency' ) ) ) ); ?></strong>
					<?php if ( $account ) : ?>
						· <a href="<?php echo esc_url( admin_url( 'admin.php?page=vcap-vouchers&voucher=' . $account->id ) ); ?>"><code><?php echo esc_html( $account->code ); ?></code></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="vcap-credit-amount"><?php esc_html_e( 'Credit / debit', 'voucher-captain' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'vcap_credit_' . $user->ID, 'vcap_credit_nonce' ); ?>
					<input type="text" class="small-text" id="vcap-credit-amount" name="vcap_credit_amount" placeholder="+10 / -5" inputmode="decimal" />
					<label class="screen-reader-text" for="vcap-credit-reason"><?php esc_html_e( 'Reason', 'voucher-captain' ); ?></label>
					<input type="text" class="regular-text" id="vcap-credit-reason" name="vcap_credit_reason" placeholder="<?php esc_attr_e( 'Reason (required)', 'voucher-captain' ); ?>" />
					<p class="description"><?php esc_html_e( 'Positive amounts add credit, negative amounts remove it. Every change is recorded with your user and the reason.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
		</table>
		<?php if ( $account ) : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th><?php esc_html_e( 'Date', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Event', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Balance after', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'User', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Note', 'voucher-captain' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( array_reverse( $this->repo->events( $account->id ) ), 0, 50 ) as $e ) : ?>
					<?php $by = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null; ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( $labels[ $e['event'] ] ?? $e['event'] ); ?></td>
						<td><?php echo null !== $e['amount'] ? wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $account->currency ) ) ) : ''; ?></td>
						<td><?php echo null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $account->currency ) ) ) : ''; ?></td>
						<td><?php echo $e['order_id'] ? '#' . esc_html( (string) $e['order_id'] ) : ''; ?></td>
						<td><?php echo $by ? esc_html( $by->display_name ) : '—'; ?></td>
						<td><?php echo esc_html( (string) $e['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	public function save_profile( int $user_id ): void {
		if ( ! isset( $_POST['vcap_credit_nonce'] ) || '' === trim( (string) wp_unslash( $_POST['vcap_credit_amount'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_credit_nonce'] ) ), 'vcap_credit_' . $user_id ) || ! $this->can() || ! $this->credit->enabled() ) {
			return;
		}
		$amount = (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['vcap_credit_amount'] ) ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['vcap_credit_reason'] ?? '' ) );
		$result = $this->credit->adjust( $user_id, $amount, $reason );
		if ( is_wp_error( $result ) ) {
			add_filter(
				'user_profile_update_errors',
				static function ( $errors ) use ( $result ) {
					$errors->add( 'vcap_credit', $result->get_error_message() );
					return $errors;
				}
			);
		}
	}

	public function order_form( \WC_Order $order ): void {
		if ( ! $this->credit->enabled() || ! $this->can() || ! $order->get_customer_id() || (float) $order->get_remaining_refund_amount() <= 0 ) {
			return;
		}
		?>
		<hr>
		<p><strong><?php esc_html_e( 'Refund as store credit', 'voucher-captain' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Records a refund without paying money back and credits the customer account instead. Use only if the customer chose store credit.', 'voucher-captain' ); ?></p>
		<p>
			<input type="text" class="short" name="vcap_refund_credit_amount" form="vcap-refund-credit" inputmode="decimal" placeholder="<?php echo esc_attr( wc_format_localized_price( $order->get_remaining_refund_amount() ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'voucher-captain' ); ?>" />
			<input type="text" name="vcap_refund_credit_reason" form="vcap-refund-credit" placeholder="<?php esc_attr_e( 'Reason (required)', 'voucher-captain' ); ?>" aria-label="<?php esc_attr_e( 'Reason', 'voucher-captain' ); ?>" />
		</p>
		<?php
		// The metabox sits inside the order <form>; this separate form is placed after it and linked via form="".
		add_action(
			'admin_footer',
			static function () use ( $order ) {
				echo '<form id="vcap-refund-credit" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="vcap_refund_credit" /><input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
				wp_nonce_field( 'vcap_refund_credit_' . $order->get_id() );
				echo '</form>';
			}
		);
		echo '<p><button type="submit" form="vcap-refund-credit" class="button">' . esc_html__( 'Refund as store credit', 'voucher-captain' ) . '</button></p>';
	}

	public function refund(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'vcap_refund_credit_' . $order_id );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'voucher-captain' ), 404 );
		}
		$amount = (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['vcap_refund_credit_amount'] ?? '' ) ) );
		$result = $this->credit->refund_as_credit( $order, $amount, sanitize_text_field( wp_unslash( $_POST['vcap_refund_credit_reason'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'Refund as store credit failed:', 'voucher-captain' ) . ' ' . $result->get_error_message() );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
