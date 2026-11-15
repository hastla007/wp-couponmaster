<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What the holder of a ticket voucher sees (D14, split from PassCaptainTickets): next steps and expiry text in the
 * emails, placeholders, and the account page with "Exchange for tickets" or "Send tickets again".
 */
final class Customer {
	public function __construct( private VoucherRepository $vouchers, private ExchangeState $state ) {}

	/** What a ticket voucher holder does next (voucher email and expiry reminder). */
	public function next_steps( $steps, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! $voucher->is_usable() ) {
			return $steps;
		}
		$target = TicketContract::target( $voucher );
		if ( ! $target || $this->state->exchange_state( $voucher ) ) {
			return $steps;
		}
		$what = TicketContract::tickets_label( $target );
		return array(
			'' !== \VCAP\Shortcodes::redeem_url()
				? __( 'Open the redeem page with the button below – your code is already filled in.', 'voucher-captain' )
				: __( 'Enter your code on our website\'s redeem page.', 'voucher-captain' ),
			$target['event_id']
				? __( 'Choose your date and, if asked, enter the names of the attendees.', 'voucher-captain' )
				: __( 'If asked, enter the names of the attendees.', 'voucher-captain' ),
			/* translators: %s: e.g. "2 tickets for Concert X" */
			sprintf( __( 'You receive %s by email right away.', 'voucher-captain' ), $what ),
			__( 'Show the tickets at the entrance – the voucher itself is not an admission ticket.', 'voucher-captain' ),
		);
	}

	/** The expiry reminder of a ticket voucher speaks of tickets, not of a remaining value (default text only). */
	public function email_intro( $intro, $voucher, $email ) {
		if ( ! $voucher instanceof Voucher || ! is_object( $email ) || 'vcap_voucher_expiry' !== ( $email->id ?? '' ) || '' !== (string) $email->get_option( 'intro', '' ) || ! TicketContract::target( $voucher ) ) {
			return $intro;
		}
		return __( 'Your ticket voucher {coupon_code} is valid until {expiry_date}. Exchange it for your tickets before then.', 'voucher-captain' );
	}

	public function placeholder_definitions( array $defs ): array {
		return $defs + array(
			'ticket_event'    => __( 'Event of a ticket voucher', 'voucher-captain' ),
			'ticket_quantity' => __( 'Number of tickets of a ticket voucher', 'voucher-captain' ),
		);
	}

	public function placeholders( $values, $voucher ) {
		$target = $voucher instanceof Voucher ? TicketContract::target( $voucher ) : null;
		$values = (array) $values;
		$values['ticket_event']    = $target ? $target['label'] : '';
		$values['ticket_quantity'] = $target ? (string) $target['quantity'] : '';
		return $values;
	}

	/** Customer account: what the voucher was exchanged for. */
	public function account_status( $voucher ): void {
		if ( ! $voucher instanceof Voucher || ! TicketContract::target( $voucher ) ) {
			return;
		}
		$state = $this->state->exchange_state( $voucher );
		if ( ! $state ) {
			return;
		}
		if ( 'revoked' === ( $state['status'] ?? '' ) ) {
			echo '<br><small>' . esc_html__( 'The tickets were cancelled.', 'voucher-captain' ) . '</small>';
			return;
		}
		$count = count( (array) ( $state['ticket_ids'] ?? array() ) );
		$what  = '' !== (string) ( $state['event'] ?? '' ) ? trim( $state['event'] . ( '' !== (string) ( $state['starts_at'] ?? '' ) ? ', ' . $state['starts_at'] : '' ) ) : '';
		$text  = '' !== $what
			/* translators: 1: number of tickets, 2: event and date */
			? sprintf( _n( '%1$d ticket for %2$s', '%1$d tickets for %2$s', $count, 'voucher-captain' ), $count, $what )
			/* translators: %d: number of tickets */
			: sprintf( _n( 'Exchanged for %d ticket', 'Exchanged for %d tickets', $count, 'voucher-captain' ), $count );
		if ( '' !== (string) ( $state['email'] ?? '' ) ) {
			/* translators: 1: tickets text, 2: masked email */
			$text = sprintf( __( '%1$s, sent to %2$s', 'voucher-captain' ), $text, \VCAP\Mask::email( (string) $state['email'] ) );
		}
		echo '<br><small>' . esc_html( $text ) . '</small>';
	}

	/** Customer account: "Exchange for tickets" before, "Send tickets again" after the exchange. */
	public function account_actions( $voucher ): void {
		if ( ! $voucher instanceof Voucher || ! TicketContract::target( $voucher ) ) {
			return;
		}
		$state = $this->state->exchange_state( $voucher );
		if ( ! $state ) {
			$url = $voucher->is_usable() ? \VCAP\Shortcodes::redeem_url( $voucher->code ) : '';
			if ( '' !== $url ) {
				echo '<a class="woocommerce-button button vcap-exchange-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Exchange for tickets', 'voucher-captain' ) . wp_kses( \VCAP\Shortcodes::voucher_suffix( $voucher->code ), array( 'span' => array( 'class' => true ) ) ) . '</a> ';
			}
			return;
		}
		$service = TicketContract::service();
		if ( 'issued' !== ( $state['status'] ?? '' ) || ! $service || ! method_exists( $service, 'resend_tickets' ) || ! $this->is_ticket_holder( $voucher, $state ) ) {
			return;
		}
		echo '<form method="post" class="vcap-resend-tickets-form"><input type="hidden" name="vcap_resend_tickets" value="' . esc_attr( (string) $voucher->id ) . '" />';
		wp_nonce_field( 'vcap_resend_tickets_' . $voucher->id, 'vcap_resend_tickets_nonce' );
		// New ticket codes replace the old ones: asked explicitly (works without JavaScript).
		echo '<label class="vcap-resend-confirm"><input type="checkbox" name="vcap_resend_confirm" value="1" required /> ' . esc_html__( 'I understand that the tickets sent so far become invalid.', 'voucher-captain' ) . '</label> ';
		echo '<button type="submit" class="woocommerce-button button">' . esc_html__( 'Send tickets again', 'voucher-captain' ) . wp_kses( \VCAP\Shortcodes::voucher_suffix( $voucher->code ), array( 'span' => array( 'class' => true ) ) ) . '</button></form>';
	}

	/**
	 * Only whoever holds the tickets may have them sent again (new codes invalidate the old ones): the logged-in
	 * customer whose address the tickets went to – not the buyer of a gift whose recipient already has them.
	 */
	private function is_ticket_holder( Voucher $voucher, array $state ): bool {
		$user = wp_get_current_user();
		$to   = strtolower( (string) ( $state['email'] ?? '' ) );
		$mine = $user && $user->exists() && '' !== $to && strtolower( (string) $user->user_email ) === $to;
		$own  = $this->is_buyer( $voucher ) && strtolower( (string) $voucher->buyer_email ) === $to;
		return (bool) apply_filters( 'vcap_passcaptain_can_resend_tickets', $mine || $own, $voucher, $state );
	}

	/** After the exchange, "Send email again" would only resend the voucher – the tickets are what counts. */
	public function customer_can_resend( $can, $voucher ) {
		return $can && $voucher instanceof Voucher && TicketContract::target( $voucher ) && $this->state->exchange_state( $voucher ) ? false : $can;
	}

	private function is_buyer( Voucher $voucher ): bool {
		$order = $voucher->order();
		return is_user_logged_in() && $order instanceof \WC_Order && (int) $order->get_customer_id() === get_current_user_id();
	}

	/** POST handler of "Send tickets again" (new codes; Pass Captain limits it to 3 per hour). */
	public function customer_resend_tickets(): void {
		if ( empty( $_POST['vcap_resend_tickets'] ) || empty( $_POST['vcap_resend_tickets_nonce'] ) || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}
		$id = absint( $_POST['vcap_resend_tickets'] );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_resend_tickets_nonce'] ) ), 'vcap_resend_tickets_' . $id ) ) {
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error', \VCAP\Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		if ( empty( $_POST['vcap_resend_confirm'] ) ) {
			wc_add_notice( __( 'Please confirm that the tickets sent so far become invalid.', 'voucher-captain' ), 'error', \VCAP\Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		$voucher = $this->vouchers->get( $id );
		$state   = $voucher ? $this->state->exchange_state( $voucher ) : null;
		$state   = $state && $this->is_ticket_holder( $voucher, $state ) ? $state : null;
		$service = TicketContract::service();
		if ( ! $state || ! $service || ! method_exists( $service, 'resend_tickets' ) ) {
			wc_add_notice( __( 'The tickets of this voucher cannot be sent again.', 'voucher-captain' ), 'error', \VCAP\Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		$result = $service->resend_tickets( $state['key'] );
		if ( is_wp_error( $result ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, __( 'Sending the tickets again (requested by the customer) failed:', 'voucher-captain' ) . ' ' . $result->get_error_code() . ': ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error', \VCAP\Shortcodes::ACCOUNT_NOTICE );
		} else {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_resent', null, null, __( 'Requested by the customer', 'voucher-captain' ) );
			/* translators: %s: masked email */
			wc_add_notice( sprintf( __( 'The tickets were sent again to %s. Only the new tickets are valid.', 'voucher-captain' ), \VCAP\Mask::email( (string) ( $result['email'] ?? '' ) ) ), 'success', \VCAP\Shortcodes::ACCOUNT_NOTICE );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( \VCAP\Downloads::ENDPOINT ) );
		exit;
	}

	/** "j***@example.com" – never show a full address the visitor did not enter themselves. Alias of Mask::email(). */
	public static function mask_email( string $email ): string {
		return \VCAP\Mask::email( $email );
	}
}
