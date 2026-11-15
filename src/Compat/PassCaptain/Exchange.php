<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\API\V1\RedemptionService;
use VCAP\ProductConfig;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Exchanges a voucher for its Pass Captain tickets (D14, split from PassCaptainTickets): reserve through the
 * redemption API, issue through the ticket service, then commit (CONCEPT: reserve → issue → commit).
 */
final class Exchange {
	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers, private ExchangeState $state, private Revocation $revocation ) {}

	/**
	 * Exchange a voucher for its Pass Captain tickets.
	 *
	 * @param string $code Voucher code.
	 * @param array  $args email (string, trusted PHP callers: the ticket recipient and – with email_verified – the
	 *                     address personal vouchers are checked against; default: logged-in user, else voucher
	 *                     recipient/buyer), email_verified (bool: the caller vouches that the holder controls
	 *                     email, e.g. staff checked the ID; V6),
	 *                     name (string), user_id (int, default current user), attendees (array, passed to
	 *                     Pass Captain for ticket types with required attendee fields), ticket_type_id (int,
	 *                     the chosen date of a "customer chooses the date" voucher – one of options()),
	 *                     recipient_email (string: where the tickets go when a guest enters an address; no
	 *                     authorisation for personal vouchers).
	 * @return array<string, mixed>|\WP_Error voucher_id, order_public_id, tickets, email, status ("issued").
	 */
	public function exchange( string $code, array $args = array() ) {
		$this->state->forget_states();
		$voucher = $this->vouchers->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->vouchers->get_by_code( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'This voucher code is not valid.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$target = TicketContract::target( $voucher );
		if ( ! $target ) {
			return new \WP_Error( 'vcap_no_ticket_target', __( 'This voucher cannot be exchanged for tickets.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		if ( $voucher->is_multi_use() ) {
			return new \WP_Error( 'vcap_no_ticket_target', __( 'Gift cards with a balance cannot be exchanged for tickets; use them in the ticket checkout.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		$service = TicketContract::service();
		if ( ! $service ) {
			return new \WP_Error( 'vcap_temporary_failure', __( 'Tickets cannot be issued right now. Please try again later.', 'voucher-captain' ), array( 'status' => 503 ) );
		}
		[ $attempt, $existing ] = $this->state->attempt( $voucher );
		$key                    = TicketContract::key( $voucher, $attempt );
		if ( $existing && RedemptionService::STATE_COMMITTED === $existing['state'] ) {
			$state = $service->get( TicketContract::exchange_key( $voucher, $attempt ) );
			if ( is_array( $state ) && 'revoked' === ( $state['status'] ?? '' ) ) {
				// Never report "sent" for tickets that were revoked (e.g. the event was cancelled).
				return new \WP_Error( 'vcap_ticket_exchange_revoked', __( 'The tickets of this voucher were revoked (for example because the event was cancelled). Please contact us.', 'voucher-captain' ), array( 'status' => 409 ) );
			}
			$done = $this->result( $voucher, is_array( $state ) ? $state : array(), (string) ( $existing['meta']['email'] ?? '' ) );
			$done['reused']       = true;
			$done['exchanged_at'] = (string) ( $existing['committed_at'] ?? '' );
			return $done;
		}
		$ticket_type = $target['ticket_type_id'];
		if ( $target['event_id'] ) {
			$ticket_type = absint( $args['ticket_type_id'] ?? 0 );
			$options     = TicketContract::options( $voucher );
			if ( ! $ticket_type || ! in_array( $ticket_type, array_map( 'intval', array_column( $options, 'ticket_type_id' ) ), true ) ) {
				return new \WP_Error(
					$options ? 'vcap_ticket_choice_required' : 'vcap_no_ticket_dates',
					$options ? __( 'Please choose a date for your tickets.', 'voucher-captain' ) : __( 'There are no upcoming dates for this voucher at the moment.', 'voucher-captain' ),
					array( 'status' => $options ? 400 : 409, 'options' => $options )
				);
			}
			if ( $existing && RedemptionService::STATE_RESERVED === $existing['state'] && 'passcaptain:ticket:' . $ticket_type !== $existing['target'] ) {
				// An earlier attempt for another date is still reserved (e.g. after an outage): release it and
				// start a new attempt with fresh keys on both sides.
				$this->revocation->revoke_checked( $voucher, TicketContract::exchange_key( $voucher, $attempt ), 'voucher_date_changed', $key );
				++$attempt;
				$key = TicketContract::key( $voucher, $attempt );
			}
		}
		$attendees = array();
		foreach ( is_array( $args['attendees'] ?? null ) ? array_values( $args['attendees'] ) : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$attendees[] = array( 'ticket_type_id' => $ticket_type ) + $entry;
			}
		}
		$fields = TicketContract::fields( $ticket_type );
		if ( ! $attendees && array_filter( array_column( $fields, 'required' ) ) ) {
			return new \WP_Error(
				'vcap_ticket_attendees_required',
				__( 'Please fill in the details for each ticket.', 'voucher-captain' ),
				array( 'status' => 400, 'fields' => $fields, 'quantity' => $target['quantity'], 'ticket_type_id' => $ticket_type )
			);
		}

		$user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
		$check   = sanitize_email( (string) ( $args['email'] ?? '' ) ); // Only a trusted caller's address may unlock personal vouchers.
		$email   = $this->ticket_email( $voucher, $args );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'vcap_email_required', __( 'Please log in so we know where to send the tickets.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		$reserved = $this->redemptions->reserve(
			$voucher->code,
			array(
				'key'         => $key,
				'target'      => 'passcaptain:ticket:' . $ticket_type,
				'user_id'     => $user_id,
				'email'       => $check,
				// V6: the caller vouches for the address (staff action, own verified customer); else only the
				// logged-in account (user_id) unlocks a personal voucher.
				'email_verified' => ! empty( $args['email_verified'] ),
				'meta'        => array( 'email' => $email, 'tickets' => $target['quantity'] ),
				// Long enough to retry a Pass Captain outage without losing the voucher in between.
				'ttl_minutes' => (int) apply_filters( 'vcap_passcaptain_ticket_reservation_minutes', DAY_IN_SECONDS / MINUTE_IN_SECONDS ),
			)
		);
		if ( is_wp_error( $reserved ) && 'vcap_idempotency_conflict' === $reserved->get_error_code() && $existing && RedemptionService::STATE_RESERVED === $existing['state'] && $existing['target'] === 'passcaptain:ticket:' . $ticket_type ) {
			// Same voucher and date, retried by someone else (e.g. the admin after the customer): continue it.
			$reserved = $existing;
		}
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		if ( RedemptionService::STATE_RESERVED !== ( $reserved['state'] ?? '' ) ) {
			// Never issue tickets on a reservation that is no longer held (expired meanwhile): retry starts anew.
			return new \WP_Error( 'vcap_temporary_failure', __( 'Tickets cannot be issued right now. Please try again later.', 'voucher-captain' ), array( 'status' => 503 ) );
		}

		$issued = $this->issue( $service, $voucher, $key, $attempt, $ticket_type, $target, $email, $args, $attendees );
		if ( is_wp_error( $issued ) ) {
			$data = (array) $issued->get_error_data();
			if ( empty( $data['retryable'] ) && (int) ( $data['status'] ?? 0 ) < 500 ) {
				$this->redemptions->release( $key );
			}
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $issued->get_error_code() . ': ' . $issued->get_error_message() );
			return new \WP_Error( $issued->get_error_code(), $issued->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 409 ) ) );
		}

		$committed = $this->redemptions->commit( $key );
		if ( is_wp_error( $committed ) ) {
			// The voucher became unusable in between (voided, reservation lost): take the tickets back.
			$this->revocation->revoke_checked( $voucher, TicketContract::exchange_key( $voucher, $attempt ), 'voucher_not_redeemable', $key );
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $committed->get_error_code() );
			return $committed;
		}
		$tickets = count( (array) ( $issued['ticket_ids'] ?? array() ) );
		/* translators: 1: number of tickets, 2: Pass Captain order id, 3: email */
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets', null, null, sprintf( _n( '%1$d ticket, Pass Captain order %2$s, sent to %3$s', '%1$d tickets, Pass Captain order %2$s, sent to %3$s', $tickets, 'voucher-captain' ), $tickets, (string) ( $issued['order_public_id'] ?? '' ), \VCAP\Mask::email( (string) $email ) ) );
		do_action( 'vcap_passcaptain_tickets_issued', $voucher, $issued, $email );
		return $this->result( $voucher, $issued, $email );
	}

	/**
	 * Where the tickets go: a trusted caller's address, else the address a guest typed on the redeem page, the
	 * logged-in user's, the voucher recipient's or buyer's ('' when none of them is valid).
	 */
	private function ticket_email( Voucher $voucher, array $args ): string {
		$user  = wp_get_current_user();
		$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			// Address the customer typed on the redeem page: only where the tickets go, never an authorisation.
			$email = sanitize_email( (string) ( $args['recipient_email'] ?? '' ) );
		}
		if ( ! is_email( $email ) ) {
			$email = $user && $user->exists() ? (string) $user->user_email : '';
		}
		if ( ! is_email( $email ) ) {
			$email = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		}
		return (string) $email;
	}

	/**
	 * Asks Pass Captain to issue the tickets of a reserved exchange (contract v1: provider data, idempotency key,
	 * ticket target, holder).
	 *
	 * @return array|\WP_Error
	 */
	private function issue( object $service, Voucher $voucher, string $key, int $attempt, int $ticket_type, array $target, string $email, array $args, array $attendees ) {
		return $service->issue_tickets(
			array(
				'provider'       => 'voucher-captain',
				'voucher_id'     => $voucher->id,
				'redemption_key' => $key,
				'code_hint'      => substr( $voucher->code, -4 ),
				// VAT treatment as in the checkout quote; single-purpose vouchers with the VAT of their sale.
				'purpose'        => ProductConfig::purpose( $voucher ),
				'sale_tax'       => 'single' === ProductConfig::purpose( $voucher ) ? ProductConfig::sale_tax( $voucher ) : null,
			),
			TicketContract::exchange_key( $voucher, $attempt ),
			array_filter(
				array(
					'ticket_type_id' => $ticket_type,
					'quantity'       => $target['quantity'],
					'event_id'       => $target['event_id'],
					'ticket_name'    => $target['ticket_name'],
				)
			),
			array(
				'email'     => $email,
				'name'      => sanitize_text_field( (string) ( $args['name'] ?? ( $voucher->recipient_name ?: $voucher->buyer_name ) ) ),
				'attendees' => $attendees,
			)
		);
	}

	private function result( Voucher $voucher, array $state, string $email ): array {
		return array(
			'voucher_id'      => $voucher->id,
			'order_public_id' => (string) ( $state['order_public_id'] ?? '' ),
			'tickets'         => count( (array) ( $state['ticket_ids'] ?? array() ) ),
			'email'           => $email,
			'status'          => (string) ( $state['status'] ?? 'issued' ),
		);
	}
}
