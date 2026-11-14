<?php
namespace VCAP\Admin;

use VCAP\Delivery;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Issued vouchers: list/search/filter/export, detail view, edits, balance adjustments, resend, void.
 */
final class VoucherAdmin {
	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'admin_post_vcap_voucher_action', array( $this, 'handle_action' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_vcap_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		Flash::register();
		( new Onboarding() )->register();
	}

	/** Plain-text amount for notices and history notes (wc_price without markup). */
	private static function money( float $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	/** Raising a remaining value creates value: own right "vcap_increase_balance" (shop managers always). */
	public static function can_increase(): bool {
		return current_user_can( 'vcap_increase_balance' ) || current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
	}

	public function notices(): void {
		$schema_error = (string) get_option( \VCAP\Install::SCHEMA_ERROR_OPTION, '' );
		if ( '' !== $schema_error && current_user_can( 'manage_woocommerce' ) ) {
			/* translators: %s: technical details */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Voucher Captain could not complete its database update (%s). Vouchers may not be redeemed reliably until it succeeds; the update is retried automatically. Check the database permissions or contact your host.', 'voucher-captain' ), $schema_error ) ) . '</p></div>';
		}
		$upload_error = (string) get_option( 'vcap_upload_dir_error', '' );
		if ( '' !== $upload_error && current_user_can( 'manage_woocommerce' ) ) {
			/* translators: %s: folder path */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Voucher Captain could not create or protect its private folder %s (PDFs, fonts). Check the folder permissions; until then PDF vouchers may fail or be reachable without protection.', 'voucher-captain' ), $upload_error ) ) . '</p></div>';
		}
		$this->undelivered_notice();
		if ( empty( $_GET['vcap_msg'] ) || ! $this->can() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$messages = array(
			'saved'       => __( 'Voucher saved.', 'voucher-captain' ),
			'resent'      => __( 'Voucher email sent.', 'voucher-captain' ),
			'voided'      => __( 'Voucher voided.', 'voucher-captain' ),
			'reactivated' => __( 'Voucher reactivated.', 'voucher-captain' ),
			'adjusted'    => __( 'Balance updated.', 'voucher-captain' ),
			'adjust_failed' => __( 'Balance could not be saved. Check the database tables and try again.', 'voucher-captain' ),
			'redeemed_store' => __( 'Voucher marked as redeemed in the store.', 'voucher-captain' ),
			'redeem_failed'  => __( 'The voucher was not redeemed: it is no longer active.', 'voucher-captain' ),
			'reversed'       => __( 'Redemption cancelled; the value was booked back.', 'voucher-captain' ),
			'action_failed' => __( 'The change could not be saved completely. Check the voucher history and the WooCommerce coupon.', 'voucher-captain' ),
			'bulk'        => __( 'Bulk action completed.', 'voucher-captain' ),
			'issued'      => __( 'Vouchers generated.', 'voucher-captain' ),
			'none'        => __( 'No new vouchers were needed for this order.', 'voucher-captain' ),
		);
		/** Extra messages for connector actions (keys ending in "_failed" are shown as errors). */
		$messages = (array) apply_filters( 'vcap_voucher_admin_messages', $messages );
		$key      = sanitize_key( $_GET['vcap_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			$notice_class = str_ends_with( $key, '_failed' ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/** Vouchers that are due but never reached anyone: shown on the voucher screens and the dashboard. */
	private function undelivered_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $this->can() || ! ( str_contains( (string) $screen->id, 'vcap' ) || 'dashboard' === $screen->id ) ) {
			return;
		}
		if ( ! empty( $_GET['undelivered'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the filtered list is shown already.
			return;
		}
		$count = $this->repo->undelivered_count();
		if ( $count < 1 ) {
			return;
		}
		$url      = add_query_arg( array( 'page' => 'vcap-vouchers', 'undelivered' => 'yes' ), admin_url( 'admin.php' ) );
		$given_up = min( $count, $this->repo->given_up_count() );
		$text     = sprintf(
			/* translators: %d: number of vouchers */
			_n( '%d voucher has not been delivered (email failed, no valid address or email disabled). Delivery is retried automatically with growing pauses; you can also send it again yourself.', '%d vouchers have not been delivered (email failed, no valid address or email disabled). Delivery is retried automatically with growing pauses; you can also send them again yourself.', $count, 'voucher-captain' ),
			$count
		);
		if ( $given_up > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of vouchers */
				_n( '%d of them is no longer retried after repeated failures and has to be sent again by hand.', '%d of them are no longer retried after repeated failures and have to be sent again by hand.', $given_up, 'voucher-captain' ),
				$given_up
			);
		}
		echo '<div class="notice ' . ( $given_up > 0 ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Show them', 'voucher-captain' ) . '</a></p></div>';
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
		echo '<div class="wrap vcap-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Issued vouchers', 'voucher-captain' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=vcap-scanner' ) ) . '">' . esc_html__( 'Open scanner', 'voucher-captain' ) . '</a><hr class="wp-header-end">';
		$table->views();
		// Single GET form (like core list tables): search, filters, pagination and bulk actions.
		// Bulk "Void" revokes Pass Captain tickets and cannot be undone by reactivating: ask first (assets/js/admin.js).
		echo '<form method="get" class="vcap-list-form" data-bulk-void-confirm="' . esc_attr__( 'Void the selected vouchers? They can no longer be redeemed; tickets they were exchanged for in WP Pass Captain are revoked and not reissued when a voucher is reactivated.', 'voucher-captain' ) . '"><input type="hidden" name="page" value="vcap-vouchers" />';
		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $_GET['status'] ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		wp_nonce_field( 'vcap_bulk', 'vcap_bulk_nonce', false );
		$table->search_box( __( 'Search code, email, name, order', 'voucher-captain' ), 'vcap-search' );
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
		$back    = admin_url( 'admin.php?page=vcap-vouchers' );
		$form    = static function ( string $do, string $label, string $class = 'button', array $extra = array(), string $confirm = '' ) use ( $v ): void {
			if ( '' === $confirm && 'void' === $do ) {
				$confirm = __( 'Void this voucher? It can no longer be redeemed; tickets it was exchanged for in WP Pass Captain are revoked.', 'voucher-captain' );
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vcap-inline-form"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
			echo '<input type="hidden" name="action" value="vcap_voucher_action" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $v->id ) . '" />';
			foreach ( $extra as $k => $val ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $val ) . '" />';
			}
			wp_nonce_field( 'vcap_voucher_' . $v->id );
			echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		};
		?>
		<div class="wrap vcap-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( /* translators: %s: code */ __( 'Voucher %s', 'voucher-captain' ), $v->code ) ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back to list', 'voucher-captain' ); ?></a>
			<hr class="wp-header-end">

			<div class="vcap-detail-grid">
				<div class="vcap-card">
					<h2><?php esc_html_e( 'Overview', 'voucher-captain' ); ?></h2>
					<table class="widefat striped" role="presentation">
						<tr><th><?php esc_html_e( 'Status', 'voucher-captain' ); ?></th><td><mark class="vcap-status vcap-status--<?php echo esc_attr( $v->status ); ?>"><span><?php echo esc_html( $v->status_label() ); ?></span></mark></td></tr>
						<tr><th><?php esc_html_e( 'Type', 'voucher-captain' ); ?></th><td><?php echo esc_html( Voucher::types()[ $v->type ] ?? $v->type ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Product', 'voucher-captain' ); ?></th><td><?php echo $product ? '<a href="' . esc_url( get_edit_post_link( $v->product_id ) ?: '' ) . '">' . esc_html( $product->get_name() ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th><td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Value', 'voucher-captain' ); ?></th><td><?php echo 'percent' === $v->discount_type ? esc_html( \VCAP\Format::percent( $v->initial_amount ) ) : wp_kses_post( wc_price( $v->initial_amount, array( 'currency' => $v->currency ) ) ); ?></td></tr>
						<?php if ( $v->is_multi_use() ) : ?>
							<tr><th><?php esc_html_e( 'Balance', 'voucher-captain' ); ?></th><td><strong><?php echo wp_kses_post( wc_price( $v->balance, array( 'currency' => $v->currency ) ) ); ?></strong></td></tr>
						<?php endif; ?>
						<?php if ( $v->is_ticket() ) : ?>
							<tr><th><?php esc_html_e( 'Check-ins', 'voucher-captain' ); ?></th><td><?php echo esc_html( $v->checkins . ' / ' . $v->max_checkins ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Transferability', 'voucher-captain' ); ?></th><td><?php echo esc_html( $v->transferable_label() ); ?></td></tr>
						<?php if ( '' !== $v->terms ) : ?>
							<tr><th><?php esc_html_e( 'Redemption conditions', 'voucher-captain' ); ?></th><td><?php echo wp_kses_post( nl2br( esc_html( $v->terms ) ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Usage', 'voucher-captain' ); ?></th><td><?php echo esc_html( array( 'single' => __( 'Single use', 'voucher-captain' ), 'balance' => __( 'Stored balance', 'voucher-captain' ), 'unlimited' => __( 'Reusable', 'voucher-captain' ) )[ $v->usage_mode ] ?? $v->usage_mode ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Coupon', 'voucher-captain' ); ?></th><td><?php echo $v->coupon_id ? '<a href="' . esc_url( get_edit_post_link( $v->coupon_id ) ?: '' ) . '">#' . esc_html( (string) $v->coupon_id ) . '</a>' : esc_html__( 'none (ticket)', 'voucher-captain' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Delivered', 'voucher-captain' ); ?></th><td><?php echo $v->delivered_at ? esc_html( \VCAP\LocalDate::from_utc( $v->delivered_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Buyer', 'voucher-captain' ); ?></th><td><?php echo esc_html( trim( $v->buyer_name . ' <' . $v->buyer_email . '>' ) ); ?></td></tr>
					</table>
					<p class="vcap-actions">
						<a class="button" target="_blank" href="<?php echo esc_url( vcap()->downloads->admin_url( $v ) ); ?>"><?php esc_html_e( 'View PDF', 'voucher-captain' ); ?></a>
						<?php
						$form( 'resend_buyer', __( 'Email buyer', 'voucher-captain' ) );
						if ( is_email( $v->recipient_email ) ) {
							$form(
								'resend_recipient',
								__( 'Email recipient', 'voucher-captain' ),
								'button',
								array(),
								'buyer' === $v->send_to
									/* translators: %s: email address */
									? sprintf( __( 'This voucher is set to go to the buyer only. Send it to %s anyway? "Send to" is changed to "Recipient", so later emails go there too.', 'voucher-captain' ), $v->recipient_email )
									: ''
							);
						}
						if ( $v->expires_at && ! in_array( 'expiry_reminder', array_column( $this->repo->events( $v->id ), 'event' ), true ) ) {
							$form( 'suppress_reminder', __( 'No expiry reminder', 'voucher-captain' ) );
						}
						if ( ! $v->is_ticket() && ! $v->is_multi_use() && Voucher::STATUS_ACTIVE === $v->status && ! $v->is_expired() ) {
							// Redeemed in the store without the scanner: booked like the scanner (redemption journal).
							$form( 'redeem_store', __( 'Mark as redeemed (store)', 'voucher-captain' ), 'button', array(), __( 'Mark this voucher as redeemed in the store? It can then no longer be used; the redemption is recorded in the history and the redemption journal.', 'voucher-captain' ) );
						}
						if ( Voucher::STATUS_VOID === $v->status ) {
							$form( 'reactivate', __( 'Reactivate', 'voucher-captain' ) );
						} else {
							$form( 'void', __( 'Void voucher', 'voucher-captain' ), 'button button-link-delete' );
						}
						/** Extra buttons for connectors: call $form( $do, $label, $class, $extra_fields, $confirm_text ) and handle $do in vcap_voucher_admin_action. */
						do_action( 'vcap_voucher_admin_actions', $v, $form );
						?>
					</p>
				</div>

				<div class="vcap-card">
					<h2><?php esc_html_e( 'Edit', 'voucher-captain' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="vcap_voucher_action" />
						<input type="hidden" name="do" value="save" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $v->id ); ?>" />
						<?php wp_nonce_field( 'vcap_voucher_' . $v->id ); ?>
						<table class="form-table" role="presentation">
							<tr><th><label for="vcap-rn"><?php esc_html_e( 'Recipient name', 'voucher-captain' ); ?></label></th><td><input class="regular-text" id="vcap-rn" name="recipient_name" value="<?php echo esc_attr( $v->recipient_name ); ?>" /></td></tr>
							<tr><th><label for="vcap-re"><?php esc_html_e( 'Recipient email', 'voucher-captain' ); ?></label></th><td><input class="regular-text" type="email" id="vcap-re" name="recipient_email" value="<?php echo esc_attr( $v->recipient_email ); ?>" /></td></tr>
							<tr><th><label for="vcap-msg"><?php esc_html_e( 'Message', 'voucher-captain' ); ?></label></th><td><textarea class="large-text" rows="3" id="vcap-msg" name="message"><?php echo esc_textarea( $v->message ); ?></textarea></td></tr>
							<tr><th><label for="vcap-send"><?php esc_html_e( 'Send to', 'voucher-captain' ); ?></label></th><td>
								<select id="vcap-send" name="send_to">
									<?php foreach ( array( 'buyer' => __( 'Buyer', 'voucher-captain' ), 'recipient' => __( 'Recipient', 'voucher-captain' ), 'both' => __( 'Both', 'voucher-captain' ) ) as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v->send_to, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td></tr>
							<tr><th><label for="vcap-exp"><?php esc_html_e( 'Expiry date', 'voucher-captain' ); ?></label></th><td><input type="date" id="vcap-exp" name="expires_at" value="<?php echo esc_attr( $v->expires_at ? get_date_from_gmt( $v->expires_at, 'Y-m-d' ) : '' ); ?>" /> <span class="description"><?php esc_html_e( 'Empty = never', 'voucher-captain' ); ?></span></td></tr>
							<tr><th><label for="vcap-del"><?php esc_html_e( 'Delivery date', 'voucher-captain' ); ?></label></th><td><input type="datetime-local" id="vcap-del" name="delivery_at" value="<?php echo esc_attr( $v->delivery_at && ! $v->delivered_at ? get_date_from_gmt( $v->delivery_at, 'Y-m-d\TH:i' ) : '' ); ?>" /> <span class="description"><?php esc_html_e( 'Set a future date to (re)schedule delivery.', 'voucher-captain' ); ?></span></td></tr>
							<?php if ( $v->is_ticket() ) : ?>
								<tr><th><label for="vcap-mc"><?php esc_html_e( 'Admissions', 'voucher-captain' ); ?></label></th><td><input type="number" min="1" id="vcap-mc" name="max_checkins" value="<?php echo esc_attr( (string) $v->max_checkins ); ?>" /></td></tr>
							<?php endif; ?>
						</table>
						<?php submit_button( __( 'Save voucher', 'voucher-captain' ) ); ?>
					</form>

					<?php if ( $v->is_multi_use() || 'single' !== $v->usage_mode ) : ?>
						<h2><?php esc_html_e( 'Adjust balance', 'voucher-captain' ); ?></h2>
						<?php
						$face = 'percent' === $v->discount_type ? 0.0 : (float) $v->initial_amount;
						?>
						<p class="description">
							<?php
							/* translators: 1: remaining value, 2: face value */
							echo esc_html( sprintf( __( 'Remaining value: %1$s of %2$s.', 'voucher-captain' ), self::money( (float) $v->balance, $v->currency ), self::money( $face, $v->currency ) ) );
							?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-balance-adjust"
							data-face="<?php echo esc_attr( (string) $face ); ?>"
							data-balance="<?php echo esc_attr( (string) $v->balance ); ?>"
							data-confirm-add="<?php /* translators: %s: amount entered */ echo esc_attr__( 'Add %s to the remaining value? The change is recorded in the history.', 'voucher-captain' ); ?>"
							data-confirm-set="<?php /* translators: %s: amount entered */ echo esc_attr__( 'Set the remaining value to %s? The current value is replaced and the change is recorded in the history.', 'voucher-captain' ); ?>"
							data-confirm-above="<?php /* translators: %s: face value */ echo esc_attr( sprintf( __( 'This is more than the face value of %s.', 'voucher-captain' ), self::money( $face, $v->currency ) ) ); ?>">
							<input type="hidden" name="action" value="vcap_voucher_action" />
							<input type="hidden" name="do" value="adjust" />
							<input type="hidden" name="confirm_above" value="" />
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $v->id ); ?>" />
							<?php wp_nonce_field( 'vcap_voucher_' . $v->id ); ?>
							<label class="screen-reader-text" for="vcap-adj-mode"><?php esc_html_e( 'Mode', 'voucher-captain' ); ?></label>
							<select name="mode" id="vcap-adj-mode">
								<option value="subtract"><?php esc_html_e( 'Deduct', 'voucher-captain' ); ?></option>
								<?php if ( self::can_increase() ) : ?>
									<option value="add"><?php esc_html_e( 'Add', 'voucher-captain' ); ?></option>
								<?php endif; ?>
								<option value="set"><?php esc_html_e( 'Set to', 'voucher-captain' ); ?></option>
							</select>
							<label class="screen-reader-text" for="vcap-adj-amount"><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></label>
							<input type="text" class="wc_input_price short" id="vcap-adj-amount" name="amount" required />
							<label class="screen-reader-text" for="vcap-adj-note"><?php esc_html_e( 'Note', 'voucher-captain' ); ?></label>
							<input type="text" id="vcap-adj-note" name="note" placeholder="<?php esc_attr_e( 'Note (e.g. redeemed in store)', 'voucher-captain' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Apply', 'voucher-captain' ); ?></button>
						</form>
						<?php
						wp_add_inline_script(
							'vcap-admin',
							"document.addEventListener('submit',function(e){var f=e.target;var m=f.elements&&f.elements.mode?f.elements.mode.value:'';if(!f.classList||!f.classList.contains('vcap-balance-adjust')||('set'!==m&&'add'!==m)){return;}var raw=(f.elements.amount.value||'').replace(/[^0-9.,]/g,'');var n=parseFloat(raw.indexOf(',')>-1?raw.replace(/\\./g,'').replace(',','.'):raw);var face=parseFloat(f.getAttribute('data-face'))||0;var after='add'===m?(parseFloat(f.getAttribute('data-balance'))||0)+n:n;var msg=f.getAttribute('add'===m?'data-confirm-add':'data-confirm-set').replace('%s',f.elements.amount.value);var above=face>0&&after>face;if(above){msg+='\\n\\n'+f.getAttribute('data-confirm-above');}f.elements.confirm_above.value=above?'1':'';if(!window.confirm(msg)){e.preventDefault();f.elements.confirm_above.value='';}});"
						);
						?>
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

			<div class="vcap-card">
				<h2><?php esc_html_e( 'History', 'voucher-captain' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Date', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Event', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Balance after', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Order', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'User', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Note', 'voucher-captain' ); ?></th></tr></thead>
					<tbody>
					<?php
					$labels = VoucherRepository::event_labels();
					foreach ( array_reverse( $this->repo->events( $v->id ) ) as $e ) :
						$user = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null;
						// A redemption without an order (counter, admin deduction) can be cancelled with a reason.
						$reversible = '' === \VCAP\Tickets::reversal_refusal( $e, $v, get_current_user_id(), true, time(), \VCAP\Tickets::is_reversed( (int) $e['id'] ) );
						?>
						<tr>
							<td><?php echo esc_html( \VCAP\LocalDate::from_utc( $e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							<td><?php echo esc_html( $labels[ $e['event'] ] ?? $e['event'] ); ?></td>
							<td><?php echo null !== $e['amount'] && 'checkin' !== $e['event'] && 'checkin_undo' !== $e['event'] ? wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $v->currency ) ) ) : esc_html( null !== $e['amount'] ? (string) (int) $e['amount'] : '' ); ?></td>
							<td><?php echo null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $v->currency ) ) ) : ''; ?></td>
							<td><?php echo $e['order_id'] ? '#' . esc_html( (string) $e['order_id'] ) : ''; ?></td>
							<td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
							<td>
								<?php echo esc_html( (string) $e['note'] ); ?>
								<?php if ( $reversible ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-inline-form vcap-reverse-form" data-confirm="<?php echo esc_attr__( 'Cancel this redemption and book the value back to the voucher? This is recorded in the history and the redemption journal.', 'voucher-captain' ); ?>">
										<input type="hidden" name="action" value="vcap_voucher_action" /><input type="hidden" name="do" value="reverse_redemption" /><input type="hidden" name="id" value="<?php echo esc_attr( (string) $v->id ); ?>" /><input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $e['id'] ); ?>" />
										<?php wp_nonce_field( 'vcap_voucher_' . $v->id ); ?>
										<label class="screen-reader-text" for="vcap-reverse-<?php echo esc_attr( (string) $e['id'] ); ?>"><?php esc_html_e( 'Reason', 'voucher-captain' ); ?></label>
										<input type="text" id="vcap-reverse-<?php echo esc_attr( (string) $e['id'] ); ?>" name="reason" required maxlength="200" placeholder="<?php esc_attr_e( 'Reason (required)', 'voucher-captain' ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Cancel redemption', 'voucher-captain' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function redirect( string $msg, int $id = 0 ): void {
		$args = array( 'page' => 'vcap-vouchers', 'vcap_msg' => $msg );
		if ( $id ) {
			$args['voucher'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_action(): void {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'vcap_voucher_' . $id );
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
				$this->resend( $v, 'resend_buyer' === $do ? 'buyer' : 'recipient' );
				$this->redirect( '', $id );
				break;
			case 'suppress_reminder':
				$this->repo->log( $v->id, 'expiry_reminder', null, null, __( 'Suppressed by admin', 'voucher-captain' ) );
				$this->redirect( 'saved', $id );
				break;
			case 'void':
				$this->redirect( vcap()->issuer->void( $v, __( 'Voided by admin', 'voucher-captain' ) ) ? 'voided' : 'action_failed', $id );
				break;
			case 'reactivate':
				$this->redirect( vcap()->issuer->reactivate( $v ) ? 'reactivated' : 'action_failed', $id );
				break;
			case 'adjust':
				$this->redirect( $this->adjust( $v ), $id );
				break;
			case 'redeem_store':
				$this->redirect( $this->redeem_store( $v ), $id );
				break;
			case 'reverse_redemption':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
				$reversed = vcap()->tickets->reverse_redemption( $v, absint( $_POST['event_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
				if ( is_wp_error( $reversed ) ) {
					Flash::add( 'error', $reversed->get_error_message() );
					$this->redirect( '', $id );
				}
				$this->redirect( 'reversed', $id );
				break;
			case 'save':
				$this->redirect( $this->save( $v ) ? 'saved' : '', $id );
				break;
			default:
				/** Connector actions: return the message key to show ('' = not handled). */
				$msg = (string) apply_filters( 'vcap_voucher_admin_action', '', $do, $v );
				if ( '' !== $msg ) {
					$this->redirect( sanitize_key( $msg ), $id );
				}
		}
		$this->redirect( '', $id );
	}

	/**
	 * Send the voucher email to the buyer or the recipient on the admin's request and report the result with
	 * its reason. Sending to the recipient of a "buyer only" voucher (the admin confirmed it) switches "Send to"
	 * to the recipient.
	 */
	public function resend( Voucher $v, string $role ): bool {
		$address = 'recipient' === $role ? $v->recipient_email : $v->buyer_email;
		$emails  = function_exists( 'WC' ) ? WC()->mailer()->get_emails() : array();
		$email   = $emails[ 'recipient' === $role ? 'VCAP_Email_Voucher_Recipient' : 'VCAP_Email_Voucher_Buyer' ] ?? null;
		$reason  = '';
		if ( ! is_email( $address ) ) {
			$reason = 'recipient' === $role ? __( 'no valid recipient email address is stored', 'voucher-captain' ) : __( 'no valid buyer email address is stored', 'voucher-captain' );
		} elseif ( ! $email ) {
			$reason = __( 'the voucher email is not registered (another plugin may have removed it)', 'voucher-captain' );
		} elseif ( ! $email->is_enabled() ) {
			/* translators: %s: email title */
			$reason = sprintf( __( 'the email "%s" is disabled under WooCommerce → Settings → Emails', 'voucher-captain' ), $email->get_title() );
		}
		if ( '' === $reason && 'recipient' === $role && 'buyer' === $v->send_to ) {
			$v->send_to = 'recipient';
			$this->repo->update_columns( $v, array( 'send_to' ), $v->status );
		}
		if ( '' === $reason ) {
			$started = time();
			if ( $email->trigger( $v ) ) {
				$note = 'recipient' === $role
					/* translators: %s: email address */
					? sprintf( __( 'Sent to the recipient (%s) by an admin', 'voucher-captain' ), $address )
					/* translators: %s: email address */
					: sprintf( __( 'Sent to the buyer (%s) by an admin', 'voucher-captain' ), $address );
				$this->repo->log( $v->id, 'resent', null, null, $note );
				/* translators: %s: email address */
				Flash::add( 'success', sprintf( __( 'Voucher email sent to %s.', 'voucher-captain' ), $address ) );
				return true;
			}
			$last   = \VCAP\Health::mail_failures()[0] ?? null;
			$reason = $last && (int) $last['time'] >= $started - 1 && '' !== (string) $last['error']
				/* translators: %s: error of the mail system */
				? sprintf( __( 'the mail system reported: %s', 'voucher-captain' ), $last['error'] )
				: __( 'the mail system did not accept the email (see Tools → Site Health)', 'voucher-captain' );
		}
		/* translators: %s: reason */
		$message = sprintf( __( 'The voucher email was not sent: %s.', 'voucher-captain' ), $reason );
		$this->repo->log( $v->id, 'delivery_failed', null, null, $message );
		Flash::add( 'error', $message );
		return false;
	}

	/**
	 * "Adjust balance": the amount must be positive (0 allowed for "Set to"), a deduction may not exceed the
	 * remaining value, and "Set to" above the face value needs the admin's confirmation.
	 *
	 * @return string message key for the redirect ('' when a detailed notice was queued)
	 */
	public function adjust( Voucher $v ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_action().
		$raw     = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['amount'] ?? '' ) ) ) );
		$mode    = sanitize_key( $_POST['mode'] ?? 'subtract' );
		$note    = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$confirm = ! empty( $_POST['confirm_above'] );
		// phpcs:enable
		$mode    = in_array( $mode, array( 'subtract', 'add', 'set' ), true ) ? $mode : 'subtract';
		$decimal = '' !== $raw ? (string) wc_format_decimal( $raw ) : '';
		$amount  = round( (float) $decimal, $v->decimals() );
		if ( '' === $decimal || ! is_numeric( $decimal ) || $amount < 0 || ( 'set' !== $mode && $amount <= 0 ) ) {
			Flash::add( 'error', 'set' === $mode ? __( 'Please enter the new remaining value (0 or more).', 'voucher-captain' ) : __( 'Please enter an amount greater than 0.', 'voucher-captain' ) );
			return '';
		}
		$fresh   = $this->repo->get( $v->id ) ?? $v;
		$current = (float) $fresh->balance;
		if ( 'subtract' === $mode && $amount > $current + 0.000001 ) {
			/* translators: 1: available remaining value, 2: amount entered */
			Flash::add( 'error', sprintf( __( 'Only %1$s is available – %2$s cannot be deducted. Nothing was changed.', 'voucher-captain' ), self::money( $current, $v->currency ), self::money( $amount, $v->currency ) ) );
			return '';
		}
		if ( ( 'add' === $mode || ( 'set' === $mode && $amount > $current + 0.000001 ) ) && ! self::can_increase() ) {
			Flash::add( 'error', __( 'You may lower remaining values but not raise them (this needs the right to increase balances). Nothing was changed.', 'voucher-captain' ) );
			return '';
		}
		$face = 'percent' === $v->discount_type ? 0.0 : (float) $v->initial_amount;
		if ( 'add' === $mode && $face > 0 && $current + $amount > $face + 0.000001 && ! $confirm ) {
			/* translators: 1: remaining value after adding, 2: face value */
			Flash::add( 'error', sprintf( __( '%1$s is more than the face value of %2$s. Nothing was changed; confirm the dialog to set a higher value.', 'voucher-captain' ), self::money( $current + $amount, $v->currency ), self::money( $face, $v->currency ) ) );
			return '';
		}
		if ( 'set' === $mode && $face > 0 && $amount > $face + 0.000001 && ! $confirm ) {
			/* translators: 1: amount entered, 2: face value */
			Flash::add( 'error', sprintf( __( '%1$s is more than the face value of %2$s. Nothing was changed; confirm the dialog to set a higher value.', 'voucher-captain' ), self::money( $amount, $v->currency ), self::money( $face, $v->currency ) ) );
			return '';
		}
		if ( 'single' !== $v->usage_mode && ! $v->is_multi_use() ) {
			// Reusable vouchers have no stored balance: switch to balance tracking on first adjustment.
			$v->usage_mode = 'balance';
		}
		// Add/subtract apply to the balance as it is when the row is locked, not as the form showed it.
		$ok = 'set' === $mode
			? vcap()->coupons->set_balance( $v, $amount, 'adjusted', null, $note )
			: vcap()->coupons->adjust_balance( $v, 'add' === $mode ? $amount : -$amount, 'subtract' === $mode ? 'redeemed' : 'adjusted', $note );
		if ( ! $ok ) {
			return 'adjust_failed';
		}
		$after = $this->repo->get( $v->id );
		/* translators: %s: new remaining value */
		Flash::add( 'success', sprintf( __( 'Balance updated. New remaining value: %s.', 'voucher-captain' ), self::money( $after ? (float) $after->balance : $amount, $v->currency ) ) );
		return '';
	}

	/**
	 * "Mark as redeemed (store)": a single-use voucher redeemed in the store without the scanner, booked like
	 * the scanner's redemption (same refusal rules, redemption journal).
	 *
	 * @return string message key
	 */
	public function redeem_store( Voucher $v ): string {
		$tickets = vcap()->tickets;
		$refused = $v->is_ticket() || $v->is_multi_use() || Voucher::STATUS_ACTIVE !== $v->status || $v->is_expired() ? 'not_active' : $tickets->counter_refusal_reason( $v );
		if ( '' !== $refused ) {
			if ( 'not_active' !== $refused ) {
				Flash::add( 'error', $refused );
				return '';
			}
			return 'redeem_failed';
		}
		return $tickets->redeem_single( $v, __( 'Marked as redeemed in the store by an admin', 'voucher-captain' ) ) ? 'redeemed_store' : 'redeem_failed';
	}

	public function save( Voucher $v ): bool {
		$from_status   = $v->status;
		$old_recipient = $v->recipient_email;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_action().
		$email_raw = trim( sanitize_text_field( wp_unslash( $_POST['recipient_email'] ?? '' ) ) );
		if ( '' !== $email_raw && ! is_email( sanitize_email( $email_raw ) ) ) {
			/* translators: %s: what was entered */
			Flash::add( 'error', sprintf( __( '"%s" is not a valid email address. The voucher was not saved.', 'voucher-captain' ), $email_raw ) );
			return false;
		}
		$v->recipient_name  = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
		$v->recipient_email = sanitize_email( $email_raw );
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
		$current       = $v->expires_at ? get_date_from_gmt( $v->expires_at, 'Y-m-d' ) : '';
		if ( $exp !== $current ) {
			// Only touch the stored time when the date was actually changed.
			$v->expires_at = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ? get_gmt_from_date( $exp . ' 23:59:59' ) : null;
		}
		if ( Voucher::STATUS_EXPIRED === $v->status && ! $v->is_expired() ) {
			$v->status = Voucher::STATUS_ACTIVE;
		}
		$delivery = sanitize_text_field( wp_unslash( $_POST['delivery_at'] ?? '' ) );
		// phpcs:enable
		$this->repo->update_columns( $v, array( 'recipient_name', 'recipient_email', 'message', 'send_to', 'fields', 'max_checkins', 'expires_at' ), $from_status );
		$this->repo->log( $v->id, 'edited' );
		if ( strtolower( $old_recipient ) !== strtolower( $v->recipient_email ) ) {
			// Personal vouchers: the coupon must accept the new address (and no longer the old one).
			vcap()->coupons->refresh_email_restrictions( $v, $old_recipient );
			if ( in_array( $v->send_to, array( 'recipient', 'both' ), true ) && is_email( $v->recipient_email ) ) {
				Flash::add( 'info', __( 'The recipient email was changed. Use "Email recipient" if the new address should receive the voucher now.', 'voucher-captain' ) );
			}
		}
		if ( in_array( $v->send_to, array( 'recipient', 'both' ), true ) && ! is_email( $v->recipient_email ) ) {
			Flash::add( 'warning', __( 'There is no recipient email: emails for this voucher go to the buyer.', 'voucher-captain' ) );
		}

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
				vcap()->delivery->reschedule( $v, $utc );
			}
		}
		return true;
	}

	public function handle_bulk(): void {
		$ids = array_map( 'absint', (array) ( $_REQUEST['voucher_ids'] ?? array() ) );
		if ( ! isset( $_REQUEST['vcap_bulk_nonce'] ) || ! $ids ) {
			return;
		}
		check_admin_referer( 'vcap_bulk', 'vcap_bulk_nonce' );
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
		$sent   = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$v = $this->repo->get( $id );
			if ( ! $v ) {
				continue;
			}
			if ( 'void' === $action ) {
				vcap()->issuer->void( $v, __( 'Voided by admin (bulk)', 'voucher-captain' ) );
			} elseif ( 'reactivate' === $action && Voucher::STATUS_VOID === $v->status ) {
				vcap()->issuer->reactivate( $v );
			} elseif ( 'resend' === $action ) {
				$addresses = vcap()->delivery->send( $v );
				if ( $addresses ) {
					++$sent;
					/* translators: %s: email addresses */
					$this->repo->log( $v->id, 'resent', null, null, sprintf( __( 'Sent to %s by an admin (bulk action)', 'voucher-captain' ), implode( ', ', $addresses ) ) );
				} else {
					++$failed;
					$this->repo->log( $v->id, 'delivery_failed', null, null, __( 'Bulk resend: the email was not sent (no valid address, email disabled or mail error).', 'voucher-captain' ) );
				}
			}
		}
		if ( 'resend' === $action ) {
			if ( $sent ) {
				/* translators: %d: number of vouchers */
				Flash::add( 'success', sprintf( _n( '%d voucher sent again.', '%d vouchers sent again.', $sent, 'voucher-captain' ), $sent ) );
			}
			if ( $failed ) {
				/* translators: %d: number of vouchers */
				Flash::add( 'error', sprintf( _n( '%d voucher could not be sent (no valid address, email disabled or mail error) – see its history.', '%d vouchers could not be sent (no valid address, email disabled or mail error) – see their history.', $failed, 'voucher-captain' ), $failed ) );
			}
			$this->redirect( '' );
		}
		$this->redirect( 'bulk' );
	}

	public function export(): void {
		check_admin_referer( 'vcap_export' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$table = new VouchersListTable( $this->repo );
		$args  = array_merge( $table->filter_args(), array( 'per_page' => 500 ) );
		$out   = \VCAP\Csv::open( 'vouchers-' . wp_date( 'Y-m-d' ) . '.csv' );
		$types = Voucher::types();
		\VCAP\Csv::write(
			$out,
			array(
				__( 'Code', 'voucher-captain' ),
				__( 'Type', 'voucher-captain' ),
				__( 'Status', 'voucher-captain' ),
				__( 'Product', 'voucher-captain' ),
				__( 'Value', 'voucher-captain' ),
				__( 'Balance', 'voucher-captain' ),
				__( 'Currency', 'voucher-captain' ),
				__( 'Expires', 'voucher-captain' ),
				__( 'Order', 'voucher-captain' ),
				__( 'Buyer name', 'voucher-captain' ),
				__( 'Buyer email', 'voucher-captain' ),
				__( 'Recipient name', 'voucher-captain' ),
				__( 'Recipient email', 'voucher-captain' ),
				__( 'Check-ins', 'voucher-captain' ),
				__( 'Maximum check-ins', 'voucher-captain' ),
				__( 'Created', 'voucher-captain' ),
			)
		);
		$page = 1;
		do {
			$result = $this->repo->query( array_merge( $args, array( 'page' => $page ) ) );
			foreach ( $result['items'] as $v ) {
				$product = $v->product();
				\VCAP\Csv::write( $out, array( $v->code, $types[ $v->type ] ?? $v->type, $v->status_label(), $product ? $product->get_name() : '', \VCAP\Csv::number( $v->initial_amount, $v->decimals() ), \VCAP\Csv::number( $v->balance, $v->decimals() ), $v->currency, \VCAP\Csv::date( $v->expires_at, false ), $v->order_id ?: '', $v->buyer_name, $v->buyer_email, $v->recipient_name, $v->recipient_email, $v->checkins, $v->max_checkins, \VCAP\Csv::date( $v->created_at ) ) );
			}
			++$page;
		} while ( count( $result['items'] ) === 500 );
		fclose( $out ); // phpcs:ignore
		exit;
	}
}
