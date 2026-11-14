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
	/**
	 * Credit/debit entered on a profile form, waiting until WordPress saved the profile (user id => amount,
	 * reason and refusal). Booked on "profile_update" only: a profile that fails validation books nothing.
	 *
	 * @var array<int, array{amount:float, reason:string, error:string}>
	 */
	private array $pending = array();

	public function __construct( private StoreCredit $credit, private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_action( 'user_profile_update_errors', array( $this, 'profile_errors' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'book_profile_credit' ), 10, 1 );
		add_action( 'admin_post_vcap_refund_credit', array( $this, 'refund' ) );
		add_action( 'vcap_order_metabox_after', array( $this, 'order_form' ) );
		Flash::register();
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	/** A refund changes the order: voucher managers also need WooCommerce's right to edit orders. */
	private function can_refund(): bool {
		return $this->can() && current_user_can( 'edit_shop_orders' );
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
					<?php echo FormToken::field(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in field(). ?>
					<input type="text" class="small-text" id="vcap-credit-amount" name="vcap_credit_amount" placeholder="+10 / -5" inputmode="decimal"
						data-confirm="<?php /* translators: %s: amount entered, e.g. +10 or -5 */ echo esc_attr__( 'Book %s as store credit for this customer (a minus sign removes credit)? The change is recorded with your user and the reason.', 'voucher-captain' ); ?>" />
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
				<?php foreach ( $this->repo->events_page( $account->id, 50 ) as $e ) : // B15: the newest 50 only. ?>
					<?php $by = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null; ?>
					<tr>
						<td><?php echo esc_html( \VCAP\LocalDate::from_utc( $e['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( $labels[ $e['event'] ] ?? $e['event'] ); ?></td>
						<td><?php echo null !== $e['amount'] ? wp_kses_post( wc_price( (float) $e['amount'], array( 'currency' => $account->currency ) ) ) : ''; ?></td>
						<td><?php echo null !== $e['balance_after'] ? wp_kses_post( wc_price( (float) $e['balance_after'], array( 'currency' => $account->currency ) ) ) : ''; ?></td>
						<td><?php echo \VCAP\Format::order_link( $e['order_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in order_link(). ?></td>
						<td><?php echo $by ? esc_html( $by->display_name ) : '—'; ?></td>
						<td><?php echo esc_html( \VCAP\Note::render( (string) $e['note'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		// Plausibility check before the profile is saved: the amount as typed, with its sign.
		wp_print_inline_script_tag( "(function(){var f=document.getElementById('vcap-credit-amount');if(!f||!f.form){return;}f.form.addEventListener('submit',function(e){var v=(f.value||'').trim();if(v&&!window.confirm(f.getAttribute('data-confirm').replace('%s',v))){e.preventDefault();}});})();" );
	}

	/**
	 * Profile form submitted (before WordPress validates it): the credit/debit is checked and kept until the
	 * profile was saved (book_profile_credit()); a refused amount is reported as a profile error.
	 */
	public function save_profile( int $user_id ): void {
		unset( $this->pending[ $user_id ] );
		if ( ! isset( $_POST['vcap_credit_nonce'] ) || ! is_scalar( $_POST['vcap_credit_amount'] ?? '' ) || '' === trim( (string) wp_unslash( $_POST['vcap_credit_amount'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_credit_nonce'] ) ), 'vcap_credit_' . $user_id ) || ! $this->can() || ! $this->credit->enabled() ) {
			return;
		}
		$raw    = sanitize_text_field( wp_unslash( (string) $_POST['vcap_credit_amount'] ) );
		$parsed = self::parse_amount( $raw, true );
		$reason = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['vcap_credit_reason'] ?? '' ) ) ) );
		$error  = $parsed['ok'] ? ( '' === $reason ? __( 'Please enter an amount and a reason.', 'voucher-captain' ) : '' ) : self::refusal( $raw, $parsed['error'] );
		$this->pending[ $user_id ] = array(
			'amount' => $parsed['value'],
			'reason' => $reason,
			'error'  => $error,
		);
	}

	/** A refused credit/debit stops the profile from being saved, so it can be corrected and sent again. */
	public function profile_errors( $errors, $update = true, $user = null ): void {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		if ( $errors instanceof \WP_Error && '' !== ( $this->pending[ $user_id ]['error'] ?? '' ) ) {
			$errors->add( 'vcap_credit', $this->pending[ $user_id ]['error'] );
		}
	}

	/**
	 * The profile was saved: book the credit/debit once per rendered form (a resent form books nothing).
	 *
	 * @return array{balance:float}|\WP_Error|null null when nothing was pending
	 */
	public function book_profile_credit( int $user_id ) {
		$pending = $this->pending[ $user_id ] ?? null;
		unset( $this->pending[ $user_id ] );
		if ( ! $pending || '' !== $pending['error'] ) {
			return null;
		}
		$scope = 'credit-' . $user_id;
		if ( ! FormToken::claim( $scope ) ) {
			Flash::add( 'warning', __( 'This store credit change was already submitted and has not been booked again. Check the journal below before entering it once more.', 'voucher-captain' ) );
			return new \WP_Error( 'vcap_credit_duplicate', 'duplicate' );
		}
		$result = $this->credit->adjust( $user_id, $pending['amount'], $pending['reason'] );
		if ( is_wp_error( $result ) ) {
			FormToken::release( $scope );
			Flash::add( 'error', __( 'The store credit was not changed:', 'voucher-captain' ) . ' ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Strictly parsed amount of a store credit form (see \VCAP\Amount): "2,50" is never 250, "1e3" never 13.
	 * With $signed a leading "+" or minus sign (also "−", "–") is accepted for credit/debit.
	 *
	 * @return array{ok:bool, value:float, minor:int, error:string}
	 */
	public static function parse_amount( string $raw, bool $signed, string $currency = '' ): array {
		$currency = '' !== $currency ? $currency : (string) get_option( 'woocommerce_currency' );
		return \VCAP\Amount::parse(
			$raw,
			array(
				'decimals'       => \VCAP\API\V1\RedemptionService::decimals( $currency ),
				'strip'          => array( $currency, html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' ) ),
				'allow_negative' => $signed,
			)
		);
	}

	private static function refusal( string $raw, string $error, string $currency = '' ): string {
		$currency = '' !== $currency ? $currency : (string) get_option( 'woocommerce_currency' );
		/* translators: 1: what was entered, 2: why it was refused */
		return sprintf( __( '"%1$s" was not accepted: %2$s Nothing was changed.', 'voucher-captain' ), $raw, \VCAP\Amount::message( $error, \VCAP\API\V1\RedemptionService::decimals( $currency ) ) );
	}

	public function order_form( \WC_Order $order ): void {
		if ( ! $this->credit->enabled() || ! $this->can_refund() || ! $order->get_customer_id() || (float) $order->get_remaining_refund_amount() <= 0 ) {
			return;
		}
		?>
		<hr>
		<p><strong><?php esc_html_e( 'Refund as store credit', 'voucher-captain' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Records a refund without paying money back and credits the customer account instead. Use only if the customer chose store credit; how they agreed is recorded with the refund.', 'voucher-captain' ); ?></p>
		<p>
			<input type="text" class="short" name="vcap_refund_credit_amount" form="vcap-refund-credit" inputmode="decimal" required value="<?php echo esc_attr( wc_format_localized_price( $order->get_remaining_refund_amount() ) ); ?>" aria-label="<?php esc_attr_e( 'Amount', 'voucher-captain' ); ?>" />
			<input type="text" name="vcap_refund_credit_reason" form="vcap-refund-credit" required placeholder="<?php esc_attr_e( 'Reason (required)', 'voucher-captain' ); ?>" aria-label="<?php esc_attr_e( 'Reason', 'voucher-captain' ); ?>" />
			<br><span class="description">
				<?php
				/* translators: %s: remaining refundable amount */
				echo esc_html( sprintf( __( 'Up to %s (the remaining refundable total). Refunding all of it sets the order to "Refunded".', 'voucher-captain' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_remaining_refund_amount(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ) ) );
				?>
			</span>
		</p>
		<p>
			<label for="vcap-refund-credit-consent"><?php esc_html_e( 'Customer agreed to store credit', 'voucher-captain' ); ?></label><br>
			<select id="vcap-refund-credit-consent" name="vcap_refund_credit_consent" form="vcap-refund-credit" required>
				<option value=""><?php esc_html_e( '– how? (required) –', 'voucher-captain' ); ?></option>
				<?php foreach ( \VCAP\StoreCredit::consent_channels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="vcap_refund_credit_consent_note" form="vcap-refund-credit" maxlength="500" placeholder="<?php esc_attr_e( 'Note, e.g. date of the email', 'voucher-captain' ); ?>" aria-label="<?php esc_attr_e( 'Consent note', 'voucher-captain' ); ?>" />
		</p>
		<?php
		// The metabox sits inside the order <form>; this separate form is placed after it and linked via form="".
		$confirm = sprintf(
			/* translators: 1: placeholder for the amount entered (keep "%s"), 2: currency */
			__( 'Refund %1$s %2$s as store credit? No money is paid back; the amount is added to the customer\'s store credit and booked as a refund. If this refunds the whole remaining total, the order becomes "Refunded": vouchers bought with it are voided and connected tickets are cancelled. This cannot be undone.', 'voucher-captain' ),
			'%s',
			html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' )
		);
		add_action(
			'admin_footer',
			static function () use ( $order, $confirm ) {
				echo '<form id="vcap-refund-credit" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr( $confirm ) . '">';
				echo '<input type="hidden" name="action" value="vcap_refund_credit" /><input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
				wp_nonce_field( 'vcap_refund_credit_' . $order->get_id() );
				echo '<input type="hidden" name="vcap_refund_credit_key" value="' . esc_attr( StoreCredit::request_key() ) . '" />';
				echo '</form>';
				// Confirmation with the amount entered: the refund cannot be undone.
				echo "<script>document.getElementById('vcap-refund-credit').addEventListener('submit',function(e){var a=document.querySelector('[name=\"vcap_refund_credit_amount\"]');if(!window.confirm(this.getAttribute('data-confirm').replace('%s',a?a.value:''))){e.preventDefault();}});</script>";
			}
		);
		echo '<p><button type="submit" form="vcap-refund-credit" class="button">' . esc_html__( 'Refund as store credit', 'voucher-captain' ) . '</button></p>';
	}

	public function refund(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'vcap_refund_credit_' . $order_id );
		if ( ! $this->can_refund() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'voucher-captain' ), 404 );
		}
		$raw    = sanitize_text_field( wp_unslash( $_POST['vcap_refund_credit_amount'] ?? '' ) );
		$parsed = self::parse_amount( $raw, false, (string) $order->get_currency() );
		if ( ! $parsed['ok'] ) {
			Flash::add( 'error', __( 'Refund as store credit failed:', 'voucher-captain' ) . ' ' . self::refusal( $raw, $parsed['error'], (string) $order->get_currency() ) );
			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}
		$amount = $parsed['value'];
		$result = $this->credit->refund_as_credit(
			$order,
			$amount,
			sanitize_text_field( wp_unslash( $_POST['vcap_refund_credit_reason'] ?? '' ) ),
			array(
				'channel' => sanitize_key( wp_unslash( $_POST['vcap_refund_credit_consent'] ?? '' ) ),
				'note'    => sanitize_textarea_field( wp_unslash( $_POST['vcap_refund_credit_consent_note'] ?? '' ) ),
			),
			sanitize_key( wp_unslash( $_POST['vcap_refund_credit_key'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', __( 'Refund as store credit failed:', 'voucher-captain' ) . ' ' . $result->get_error_message() );
		} else {
			$currency = (string) get_option( 'woocommerce_currency' );
			Flash::add(
				'success',
				sprintf(
					/* translators: 1: refunded amount, 2: new store credit balance of the customer */
					__( '%1$s refunded as store credit. The customer\'s store credit is now %2$s.', 'voucher-captain' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
					html_entity_decode( wp_strip_all_tags( wc_price( (float) $result['balance'], array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' )
				)
			);
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
