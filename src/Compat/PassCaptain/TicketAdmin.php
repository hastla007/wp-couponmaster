<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\API\V1\RedemptionService;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket vouchers in wp-admin (D14, split from PassCaptainTickets): exchange, resend and undo on the voucher page,
 * the notices for a missing redeem page and for tickets that could not be taken or given back, messages and
 * history labels.
 */
final class TicketAdmin {
	public function __construct( private VoucherRepository $vouchers, private ExchangeState $state, private Revocation $revocation, private Exchange $exchange ) {}

	public function attention_notice(): void {
		$ids    = array_map( 'intval', (array) get_option( Revocation::ATTENTION_OPTION, array() ) );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $ids || ! $screen || ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'vcap_manage_vouchers' ) ) || ! ( str_contains( (string) $screen->id, 'vcap' ) || 'dashboard' === $screen->id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
			return;
		}
		$links = array();
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$voucher = $this->vouchers->get( $id );
			if ( $voucher ) {
				$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=vcap-vouchers&voucher=' . $id ) ) . '">' . esc_html( $voucher->code ) . '</a>';
			}
		}
		if ( $links ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Pass Captain tickets could not be cancelled or a voucher could not be given back. The customer may hold valid tickets while the voucher is free again (or the other way round). Check these vouchers and their history:', 'voucher-captain' ) . ' ' . implode( ', ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
	}

	/** Ticket vouchers are exchanged on the redeem page: warn when products sell them but the page is missing. */
	public function redeem_page_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) || ! ( str_contains( (string) $screen->id, 'vcap' ) || in_array( $screen->id, array( 'dashboard', 'product', 'edit-product', 'woocommerce_page_wc-settings' ), true ) ) ) {
			return;
		}
		$problem = TicketContract::sells_ticket_vouchers() ? \VCAP\Shortcodes::redeem_page_problem() : '';
		if ( '' === $problem ) {
			return;
		}
		$link = admin_url( 'admin.php?page=wc-settings&tab=vcap&section=redemption#vcap_redeem_page_id' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $problem ) . ' ' . esc_html__( 'Customers exchange ticket vouchers for tickets on that page, and voucher emails link to it.', 'voucher-captain' )
			. ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Choose the redeem page', 'voucher-captain' ) . '</a></p></div>';
	}

	public function admin_button( $voucher, $form ): void {
		if ( $voucher instanceof Voucher ) {
			// The admin opened the voucher (history shows the failure): no longer flagged.
			$ids = array_map( 'intval', (array) get_option( Revocation::ATTENTION_OPTION, array() ) );
			if ( in_array( $voucher->id, $ids, true ) ) {
				update_option( Revocation::ATTENTION_OPTION, array_values( array_diff( $ids, array( $voucher->id ) ) ), false );
			}
		}
		if ( ! $voucher instanceof Voucher || ! is_callable( $form ) || ! TicketContract::target( $voucher ) || ! TicketContract::service() ) {
			return;
		}
		$redemption = $this->state->attempt( $voucher )[1];
		if ( $redemption && RedemptionService::STATE_COMMITTED === $redemption['state'] ) {
			// Exchanged: send the tickets again, or undo the exchange (e.g. to choose another date).
			if ( method_exists( TicketContract::service(), 'resend_tickets' ) ) {
				$form( 'passcaptain_resend', __( 'Send tickets again', 'voucher-captain' ), 'button', array(), __( 'Send the tickets again? New ticket codes are issued; the tickets sent so far become invalid.', 'voucher-captain' ) );
			}
			if ( Voucher::STATUS_VOID !== $voucher->status ) {
				$form( 'passcaptain_undo', __( 'Undo exchange (e.g. to change the date)', 'voucher-captain' ), 'button', array(), __( 'Undo the exchange? Its tickets are cancelled in Pass Captain and the voucher becomes valid again, so it can be exchanged for another date.', 'voucher-captain' ) );
			}
			return;
		}
		if ( ! in_array( $voucher->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_RESERVED ), true ) ) {
			return;
		}
		$options = TicketContract::target( $voucher )['event_id'] ? TicketContract::options( $voucher ) : null;
		if ( null === $options ) {
			$form( 'passcaptain_tickets', __( 'Exchange for tickets', 'voucher-captain' ) );
			return;
		}
		if ( ! $options ) {
			echo '<span class="description">' . esc_html__( 'There are no upcoming dates for this voucher at the moment.', 'voucher-captain' ) . '</span>';
			return;
		}
		// Same request as the core buttons (admin-post, nonce vcap_voucher_{id}), plus the chosen date.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vcap-inline-form">';
		echo '<input type="hidden" name="action" value="vcap_voucher_action" /><input type="hidden" name="do" value="passcaptain_tickets" /><input type="hidden" name="id" value="' . esc_attr( (string) $voucher->id ) . '" />';
		wp_nonce_field( 'vcap_voucher_' . $voucher->id );
		echo '<label class="screen-reader-text" for="vcap-admin-ticket-choice">' . esc_html__( 'Date', 'voucher-captain' ) . '</label>';
		echo '<select id="vcap-admin-ticket-choice" name="vcap_ticket_choice" required><option value="">' . esc_html__( '— Choose a date —', 'voucher-captain' ) . '</option>';
		foreach ( $options as $o ) {
			echo '<option value="' . esc_attr( (string) $o['ticket_type_id'] ) . '">' . esc_html( (string) $o['date_label'] ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Exchange for tickets', 'voucher-captain' ) . '</button></form>';
	}

	public function admin_action( $msg, $do, $voucher ) {
		if ( ! $voucher instanceof Voucher ) {
			return $msg;
		}
		if ( 'passcaptain_resend' === $do || 'passcaptain_undo' === $do ) {
			return 'passcaptain_resend' === $do ? $this->admin_resend( $voucher ) : $this->admin_undo( $voucher );
		}
		if ( 'passcaptain_tickets' !== $do ) {
			return $msg;
		}
		$email  = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in VoucherAdmin::handle_action().
		$choice = isset( $_POST['vcap_ticket_choice'] ) ? absint( wp_unslash( $_POST['vcap_ticket_choice'] ) ) : 0;
		$before = count( $this->failures( $voucher ) );
		// Staff action (V6: staff vouch for the holder, like at the counter).
		$result = $this->exchange->exchange( $voucher->code, array( 'email' => $email, 'email_verified' => true, 'user_id' => 0, 'ticket_type_id' => $choice ) );
		if ( is_wp_error( $result ) && count( $this->failures( $voucher ) ) === $before ) {
			// Refused before anything was reserved (sold out, no date chosen, …): the admin message points to the history.
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $result->get_error_code() . ': ' . $result->get_error_message() );
		}
		return is_wp_error( $result ) ? 'passcaptain_tickets_failed' : 'passcaptain_tickets_issued';
	}

	private function failures( Voucher $voucher ): array {
		return array_filter( (array) $this->vouchers->events( $voucher->id ), static fn( $e ) => 'passcaptain_tickets_failed' === ( $e['event'] ?? '' ) );
	}

	/** Admin: send the tickets of an exchange again (new codes). */
	private function admin_resend( Voucher $voucher ): string {
		$service = TicketContract::service();
		[ $attempt, $redemption ] = $this->state->attempt( $voucher );
		if ( ! $service || ! method_exists( $service, 'resend_tickets' ) || ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] ) {
			return 'passcaptain_resend_failed';
		}
		$result = $service->resend_tickets( TicketContract::exchange_key( $voucher, $attempt ) );
		if ( is_wp_error( $result ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $result->get_error_code() . ': ' . $result->get_error_message() );
			return 'passcaptain_resend_failed';
		}
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets_resent', null, null, __( 'Requested by an admin:', 'voucher-captain' ) . ' ' . \VCAP\Mask::email( (string) ( $result['email'] ?? '' ) ) );
		return 'passcaptain_resent';
	}

	/**
	 * Admin: undo an exchange. The tickets are cancelled in Pass Captain and the voucher becomes valid again (then
	 * it can be exchanged for another date). Refused while checked-in tickets remain (they stay valid).
	 */
	private function admin_undo( Voucher $voucher ): string {
		$service = TicketContract::service();
		[ $attempt, $redemption ] = $this->state->attempt( $voucher );
		if ( ! $service || ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] || Voucher::STATUS_VOID === $voucher->status ) {
			return 'passcaptain_undo_failed';
		}
		$key = TicketContract::exchange_key( $voucher, $attempt );
		if ( ! $this->revocation->revoke_checked( $voucher, $key, 'voucher_exchange_undone' ) ) {
			$this->state->forget_states();
			return 'passcaptain_undo_failed';
		}
		$this->state->forget_states();
		$state = $service->get( $key );
		if ( is_array( $state ) && ( 'revoked' !== ( $state['status'] ?? '' ) || ! empty( $state['ticket_ids'] ) ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, __( 'The exchange could not be undone completely: tickets that were already checked in stay valid.', 'voucher-captain' ) );
			return 'passcaptain_undo_failed';
		}
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets_revoked', null, null, (string) ( $state['order_public_id'] ?? '' ) );
		$restored = $this->revocation->restore( TicketContract::key( $voucher, $attempt ), $redemption, $voucher, (string) ( $state['order_public_id'] ?? '' ) );
		return $restored ? 'passcaptain_undone' : 'passcaptain_undo_failed';
	}

	public function admin_messages( array $messages ): array {
		return $messages + array(
			'passcaptain_tickets_issued' => __( 'Tickets issued in Pass Captain and sent by email.', 'voucher-captain' ),
			'passcaptain_tickets_failed' => __( 'The tickets could not be issued. The reason is in the voucher history.', 'voucher-captain' ),
			'passcaptain_resent'         => __( 'The tickets were sent again with new codes.', 'voucher-captain' ),
			'passcaptain_resend_failed'  => __( 'The tickets could not be sent again. The reason is in the voucher history.', 'voucher-captain' ),
			'passcaptain_undone'         => __( 'Exchange undone: the tickets are cancelled and the voucher is valid again.', 'voucher-captain' ),
			'passcaptain_undo_failed'    => __( 'The exchange could not be undone. The reason is in the voucher history.', 'voucher-captain' ),
		);
	}

	public function event_labels( array $labels ): array {
		return $labels + array(
			'passcaptain_tickets'         => __( 'Exchanged for Pass Captain tickets', 'voucher-captain' ),
			'passcaptain_tickets_failed'  => __( 'Ticket exchange failed', 'voucher-captain' ),
			'passcaptain_tickets_revoked' => __( 'Pass Captain tickets revoked', 'voucher-captain' ),
			'passcaptain_tickets_restored' => __( 'Voucher restored (tickets cancelled in Pass Captain)', 'voucher-captain' ),
			'passcaptain_tickets_resent'  => __( 'Tickets sent again', 'voucher-captain' ),
			'passcaptain_restore_notified' => __( 'Customer told that the voucher is valid again', 'voucher-captain' ),
			'passcaptain_revoke_failed'   => __( 'Pass Captain tickets could not be cancelled – check them', 'voucher-captain' ),
			'passcaptain_restore_failed'  => __( 'Voucher could not be given back after the tickets were cancelled – check it', 'voucher-captain' ),
		);
	}
}
