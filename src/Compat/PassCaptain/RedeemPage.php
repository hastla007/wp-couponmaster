<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket vouchers on the redeem page ([vcap_redeem], D14, split from PassCaptainTickets): the email of a guest, the
 * date of a "customer chooses the date" voucher and the attendee details are asked step by step, then the voucher
 * is exchanged.
 */
final class RedeemPage {
	public function __construct( private VoucherRepository $vouchers, private Exchange $exchange ) {}

	/** Session key of a code waiting for its date choice on the redeem page. */
	public const SESSION_CODE   = 'vcap_ticket_choice_code';
	/** Session key of the date already chosen for that code (while the attendee details are asked). */
	public const SESSION_CHOICE = 'vcap_ticket_choice_type';

	public function redeem_page( $handled, $code ) {
		if ( null !== $handled ) {
			return $handled;
		}
		$voucher = $this->vouchers->get_by_code( (string) $code );
		if ( ! $voucher || ! TicketContract::target( $voucher ) || $voucher->is_multi_use() ) {
			return $handled;
		}
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$pending = $session && (string) $session->get( self::SESSION_CODE ) === (string) $code;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Shortcodes::handle_redeem() for logged-in customers; guests send no nonce (rate limited, the code itself authorises the exchange).
		if ( isset( $_POST['vcap_ticket_reset'] ) ) {
			// "Choose another date" / "Cancel": start this code over (or forget it).
			$this->clear_session();
			if ( is_string( $_POST['vcap_ticket_reset'] ) && 'date' === sanitize_key( wp_unslash( $_POST['vcap_ticket_reset'] ) ) && $session ) {
				$session->set( self::SESSION_CODE, (string) $code );
			}
			return 'choose';
		}
		// Public form: anything but a string (e.g. "vcap_ticket_email[]=x") is an invalid address, not a fatal error.
		$guest_email = isset( $_POST['vcap_ticket_email'] ) && is_scalar( $_POST['vcap_ticket_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['vcap_ticket_email'] ) ) : '';
		if ( ! is_user_logged_in() && ! is_email( $guest_email ) ) {
			// Guests say where the tickets go (never silently the buyer's address of a gift voucher).
			if ( $session ) {
				$session->set( self::SESSION_CODE, (string) $code );
			}
			wc_add_notice( isset( $_POST['vcap_ticket_email'] ) ? __( 'Please enter a valid email address for your tickets.', 'voucher-captain' ) : __( 'Please enter the email address the tickets should be sent to.', 'voucher-captain' ), isset( $_POST['vcap_ticket_email'] ) ? 'error' : 'notice' );
			return 'choose';
		}
		if ( $session && is_email( $guest_email ) ) {
			$session->set( 'vcap_ticket_email', $guest_email );
		}
		$choice    = isset( $_POST['vcap_ticket_choice'] ) ? absint( wp_unslash( $_POST['vcap_ticket_choice'] ) ) : ( $pending ? absint( $session->get( self::SESSION_CHOICE ) ) : 0 );
		$posted    = isset( $_POST['vcap_attendees'] ) && is_array( $_POST['vcap_attendees'] ) ? wp_unslash( $_POST['vcap_attendees'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		// phpcs:enable
		$attendees = array();
		foreach ( $posted as $answers ) {
			$clean = array();
			foreach ( is_array( $answers ) ? $answers : array() as $field => $value ) {
				$clean[ sanitize_key( (string) $field ) ] = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			}
			$attendees[] = array( 'answers' => $clean );
		}
		$args = array_filter( array( 'ticket_type_id' => $choice, 'attendees' => $attendees, 'recipient_email' => $guest_email ) );
		$result = $this->exchange->exchange( (string) $code, $args );
		$step   = is_wp_error( $result ) ? $result->get_error_code() : '';
		if ( in_array( $step, array( 'vcap_ticket_choice_required', 'vcap_ticket_attendees_required' ), true ) || ( $attendees && 0 === strpos( $step, 'wpcap_attendee' ) ) ) {
			// Ask for the missing input: the form comes back with the code, the date and/or the attendee fields.
			if ( $session ) {
				$session->set( self::SESSION_CODE, (string) $code );
				$session->set( self::SESSION_CHOICE, 'vcap_ticket_choice_required' === $step ? 0 : $choice );
			}
			wc_add_notice( $result->get_error_message(), ( $choice || $attendees ) && 'vcap_ticket_attendees_required' !== $step ? 'error' : 'notice' );
			return 'choose';
		}
		if ( is_wp_error( $result ) ) {
			// Final error (e.g. the date sold out meanwhile): keep the code, forget the date so it can be chosen again.
			if ( $session && $pending ) {
				$session->set( self::SESSION_CHOICE, null );
			}
			wc_add_notice( $result->get_error_message(), 'error' );
			return 'rejected';
		}
		$this->clear_session();
		// The full address only when it is the visitor's own (entered now or of the logged-in account).
		$own   = array_filter( array( strtolower( $guest_email ), is_user_logged_in() ? strtolower( (string) wp_get_current_user()->user_email ) : '' ) );
		$shown = in_array( strtolower( (string) $result['email'] ), $own, true ) ? $result['email'] : \VCAP\Mask::email( $result['email'] );
		if ( ! empty( $result['reused'] ) ) {
			$when = '' !== (string) ( $result['exchanged_at'] ?? '' ) ? \VCAP\LocalDate::from_utc( (string) $result['exchanged_at'], (string) get_option( 'date_format' ) ) : '';
			wc_add_notice(
				'' !== $when
					/* translators: 1: date, 2: number of tickets, 3: (masked) email */
					? sprintf( _n( 'This voucher was already exchanged on %1$s: %2$d ticket went to %3$s.', 'This voucher was already exchanged on %1$s: %2$d tickets went to %3$s.', (int) $result['tickets'], 'voucher-captain' ), $when, $result['tickets'], $shown )
					/* translators: 1: number of tickets, 2: (masked) email */
					: sprintf( _n( 'This voucher was already exchanged: %1$d ticket went to %2$s.', 'This voucher was already exchanged: %1$d tickets went to %2$s.', (int) $result['tickets'], 'voucher-captain' ), $result['tickets'], $shown ),
				'notice'
			);
			return 'exchanged';
		}
		/* translators: 1: number of tickets, 2: email */
		wc_add_notice( sprintf( __( 'Your tickets (%1$d) were sent to %2$s.', 'voucher-captain' ), $result['tickets'], $shown ) );
		return 'exchanged';
	}

	private function clear_session(): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( $session ) {
			$session->set( self::SESSION_CODE, null );
			$session->set( self::SESSION_CHOICE, null );
			$session->set( 'vcap_ticket_email', null );
		}
	}

	/** The code waiting for its date choice (pre-filled in the redeem form). */
	public function redeem_prefill( $value ) {
		// A code from a link (?vcap_code=…) wins over a code still waiting in the session.
		if ( '' !== (string) $value ) {
			return $value;
		}
		$code = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( self::SESSION_CODE ) : '';
		return '' !== $code ? $code : $value;
	}

	/** Date select of a "customer chooses the date" voucher and the attendee fields in the redeem form. */
	public function redeem_fields(): void {
		$code    = $this->redeem_prefill( '' );
		$voucher = '' !== $code ? $this->vouchers->get_by_code( $code ) : null;
		$target  = $voucher ? TicketContract::target( $voucher ) : null;
		if ( ! $target ) {
			return;
		}
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$choice  = $session ? absint( $session->get( self::SESSION_CHOICE ) ) : 0;
		$type    = $target['event_id'] ? $choice : $target['ticket_type_id'];
		if ( ! is_user_logged_in() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
			$typed = isset( $_POST['vcap_ticket_email'] ) ? ( is_scalar( $_POST['vcap_ticket_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['vcap_ticket_email'] ) ) : '' ) : ( $session ? (string) $session->get( 'vcap_ticket_email' ) : '' );
			$email_id = \VCAP\Shortcodes::unique_id( 'vcap-ticket-email' );
			echo '<label for="' . esc_attr( $email_id ) . '">' . esc_html__( 'Send the tickets to (email)', 'voucher-captain' ) . '</label>';
			echo '<input type="email" id="' . esc_attr( $email_id ) . '" name="vcap_ticket_email" required autocomplete="email" maxlength="190" value="' . \VCAP\Shortcodes::esc_attr_text( $typed ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_attr_text().
		}
		echo '<p class="vcap-ticket-steps">';
		if ( $target['event_id'] && $choice ) {
			echo '<button type="submit" class="button" name="vcap_ticket_reset" value="date" formnovalidate>' . esc_html__( 'Choose another date', 'voucher-captain' ) . '</button> ';
		}
		echo '<button type="submit" class="button" name="vcap_ticket_reset" value="all" formnovalidate>' . esc_html__( 'Cancel', 'voucher-captain' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Redeeming issues the tickets bindingly; the voucher is used up.', 'voucher-captain' ) . '</p>';
		if ( $type ) {
			$this->attendee_fields( $type, $target['quantity'], (bool) $target['event_id'] );
			return;
		}
		$options = TicketContract::options( $voucher );
		if ( ! $options ) {
			return;
		}
		$choice_id = \VCAP\Shortcodes::unique_id( 'vcap-ticket-choice' );
		echo '<label for="' . esc_attr( $choice_id ) . '">' . esc_html__( 'Date', 'voucher-captain' ) . '</label>';
		echo '<select id="' . esc_attr( $choice_id ) . '" name="vcap_ticket_choice" required>';
		echo '<option value="">' . esc_html__( '— Choose a date —', 'voucher-captain' ) . '</option>';
		foreach ( $options as $o ) {
			echo '<option value="' . esc_attr( (string) $o['ticket_type_id'] ) . '">' . esc_html( (string) $o['date_label'] ) . '</option>';
		}
		echo '</select>';
	}

	/** One set of attendee inputs per ticket (names as posted to redeem_page()). */
	private function attendee_fields( int $type, int $quantity, bool $chosen ): void {
		$fields = TicketContract::fields( $type );
		if ( ! $fields ) {
			return;
		}
		if ( $chosen ) {
			echo '<input type="hidden" name="vcap_ticket_choice" value="' . esc_attr( (string) $type ) . '" />';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- display only, escaped below.
		$posted = isset( $_POST['vcap_attendees'] ) && is_array( $_POST['vcap_attendees'] ) ? wp_unslash( $_POST['vcap_attendees'] ) : array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			/* translators: %d: ticket number */
			echo '<fieldset class="vcap-ticket-attendee"><legend>' . esc_html( sprintf( __( 'Ticket %d', 'voucher-captain' ), $i + 1 ) ) . '</legend>';
			foreach ( $fields as $f ) {
				$id   = \VCAP\Shortcodes::unique_id( 'vcap-attendee-' . $i . '-' . sanitize_key( $f['key'] ) );
				$name = 'vcap_attendees[' . $i . '][' . sanitize_key( $f['key'] ) . ']';
				$req  = ! empty( $f['required'] ) ? ' required' : '';
				$old  = isset( $posted[ $i ][ sanitize_key( $f['key'] ) ] ) && is_scalar( $posted[ $i ][ sanitize_key( $f['key'] ) ] ) ? (string) $posted[ $i ][ sanitize_key( $f['key'] ) ] : '';
				if ( 'checkbox' === $f['type'] ) {
					echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . esc_attr( $req ) . checked( '' !== $old, true, false ) . ' /> ' . esc_html( $f['label'] ) . '</label>';
					continue;
				}
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
				if ( 'textarea' === $f['type'] ) {
					// Brackets as entities: posted text must not run a shortcode (block output passes do_shortcode()).
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . esc_attr( $req ) . '>' . \VCAP\Shortcodes::esc_html_text( $old ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_html_text().
				} else {
					echo '<input type="' . ( 'email' === $f['type'] ? 'email' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="190"' . esc_attr( $req ) . ' value="' . \VCAP\Shortcodes::esc_attr_text( $old ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_attr_text().
				}
			}
			echo '</fieldset>';
		}
	}
}
