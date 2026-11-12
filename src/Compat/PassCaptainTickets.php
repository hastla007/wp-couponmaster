<?php
namespace VCAP\Compat;

use VCAP\API\V1\RedemptionService;
use VCAP\Issuer;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket vouchers for WP Pass Captain: a voucher product can name a Pass Captain ticket type ("Concert X,
 * 2 tickets"). The sold voucher is exchanged for those tickets – on the redeem page ([vcap_redeem]), by an
 * admin (voucher page) or by code (vcap()->passcaptain_tickets->exchange()).
 *
 * Flow (CONCEPT: reserve → issue → commit): the voucher is reserved through the redemption API (target
 * "passcaptain:ticket:{type}", channel "passcaptain"), Pass Captain issues the tickets through its versioned
 * ticket service ("wp_pass_captain_ticket_service_v1", idempotent per voucher), and only then is the
 * redemption committed. A temporary Pass Captain failure keeps the reservation, so the same call can simply be
 * repeated; a final one (sold out, ticket type gone) releases it. If the voucher purchase is refunded later
 * (voucher voided), the tickets are revoked in Pass Captain (filter "vcap_passcaptain_revoke_on_void").
 */
final class PassCaptainTickets {
	public const SERVICE_FILTER = 'wp_pass_captain_ticket_service_v1';
	public const META_TYPE      = '_vcap_passcaptain_ticket_type';
	public const META_QTY       = '_vcap_passcaptain_tickets';
	/** "Customer chooses the date": event id and optional ticket name instead of a fixed ticket type. */
	public const META_EVENT     = '_vcap_passcaptain_event';
	public const META_NAME      = '_vcap_passcaptain_ticket_name';
	/** Session key of a code waiting for its date choice on the redeem page. */
	public const SESSION_CODE   = 'vcap_ticket_choice_code';
	/** Session key of the date already chosen for that code (while the attendee details are asked). */
	public const SESSION_CHOICE = 'vcap_ticket_choice_type';
	public const SNAPSHOT_KEY   = 'passcaptain_ticket';

	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers ) {}

	public function register(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_fields' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_filter( 'vcap_line_snapshot', array( $this, 'snapshot' ), 10, 2 );
		add_filter( 'vcap_redeem_code', array( $this, 'redeem_page' ), 10, 2 );
		add_filter( 'vcap_redeem_prefill', array( $this, 'redeem_prefill' ) );
		add_action( 'vcap_redeem_form_fields', array( $this, 'redeem_fields' ) );
		add_action( 'vcap_voucher_admin_actions', array( $this, 'admin_button' ), 10, 2 );
		add_filter( 'vcap_voucher_admin_action', array( $this, 'admin_action' ), 10, 3 );
		add_filter( 'vcap_voucher_admin_messages', array( $this, 'admin_messages' ) );
		add_filter( 'vcap_event_labels', array( $this, 'event_labels' ) );
		add_filter( 'vcap_scanner_describe', array( $this, 'scanner_describe' ), 10, 2 );
		add_filter( 'vcap_scanner_redeem_refused', array( $this, 'scanner_redeem_refused' ), 10, 2 );
		add_action( 'vcap_voucher_event', array( $this, 'on_voucher_event' ), 10, 2 );
		add_action( 'wp_pass_captain_voucher_tickets_revoked_v1', array( $this, 'on_tickets_revoked' ), 10, 1 );
	}

	/**
	 * Pass Captain revoked or refunded the tickets of an exchange (order cancelled or refunded, date or
	 * event cancelled): once no ticket is left – none valid, none used – the voucher is given back (the
	 * redemption is refunded in full, a single-use voucher becomes active again) and can be exchanged anew.
	 * Idempotent per Pass Captain order. Filter "vcap_passcaptain_restore_on_revoke" (false = keep redeemed).
	 *
	 * @param array $info redemption_key, order_public_id, remaining, revoked, reason.
	 */
	public function on_tickets_revoked( $info ): void {
		$info = (array) $info;
		$key  = (string) ( $info['redemption_key'] ?? '' );
		if ( 0 !== strpos( $key, 'passcaptain:ticket:' ) || (int) ( $info['remaining'] ?? 1 ) > 0 ) {
			return;
		}
		$row = $this->redemptions->get( $key );
		if ( is_wp_error( $row ) || RedemptionService::STATE_COMMITTED !== $row['state'] ) {
			return;
		}
		$voucher = $this->vouchers->get( (int) $row['voucher_id'] );
		if ( ! $voucher || Voucher::STATUS_VOID === $voucher->status || ! apply_filters( 'vcap_passcaptain_restore_on_revoke', true, $voucher, $info ) ) {
			return;
		}
		$order_ref  = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $info['order_public_id'] ?? '' ) ) );
		$refund_key = 'passcaptain:restore:' . substr( '' !== $order_ref ? $order_ref : md5( $key ), 0, 32 );
		$amount     = (float) $row['amount'];
		$refunded   = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
		if ( $amount <= 0 || $refunded + 0.0001 >= $amount ) {
			return;
		}
		$result = $this->redemptions->refund( $key, $amount - $refunded, $refund_key );
		if ( ! is_wp_error( $result ) && ! empty( $result['restored'] ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_restored', (float) $result['restored'], null, (string) ( $info['order_public_id'] ?? '' ) );
		}
	}

	/** Pass Captain's ticket service (contract v1), or null when Pass Captain is not active or too old. */
	public static function service(): ?object {
		$service = apply_filters( self::SERVICE_FILTER, null, 1 );
		return is_object( $service ) && method_exists( $service, 'issue_tickets' ) && method_exists( $service, 'revoke_tickets' ) && method_exists( $service, 'get' ) ? $service : null;
	}

	/**
	 * Redemption key of an exchange attempt. Every attempt that ended released (sold out, outage, other date) gets
	 * a fresh key, so a voucher can always be exchanged again; attempt 1 keeps the original key.
	 */
	public static function key( Voucher $voucher, int $attempt = 1 ): string {
		return 'passcaptain:ticket:' . $voucher->id . ( $attempt > 1 ? ':' . $attempt : '' );
	}

	/** Idempotency key on Pass Captain's side (one per attempt). */
	public static function exchange_key( Voucher $voucher, int $attempt = 1 ): string {
		return 'vcap-voucher-' . $voucher->id . ( $attempt > 1 ? '-' . $attempt : '' );
	}

	/**
	 * The current attempt of a voucher: the first attempt whose redemption is reserved or committed, else the next
	 * unused one.
	 *
	 * @return array{0:int,1:array|null} Attempt number and its redemption (null when unused).
	 */
	public function attempt( Voucher $voucher ): array {
		for ( $n = 1; $n <= 50; $n++ ) {
			$row = $this->redemptions->get( self::key( $voucher, $n ) );
			if ( is_wp_error( $row ) ) {
				return array( $n, null );
			}
			// Released attempts and exchanges given back in full (tickets revoked in Pass Captain) are finished.
			$refunded = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
			$returned = RedemptionService::STATE_COMMITTED === $row['state'] && (float) $row['amount'] > 0 && $refunded + 0.0001 >= (float) $row['amount'];
			if ( RedemptionService::STATE_RELEASED !== $row['state'] && ! $returned ) {
				return array( $n, $row );
			}
		}
		return array( 51, null );
	}

	/**
	 * The tickets a voucher is exchanged for: ticket_type_id, quantity, label – or null.
	 *
	 * @return array{ticket_type_id:int,quantity:int,label:string}|null
	 */
	public static function target( Voucher $voucher ): ?array {
		$target = null;
		if ( $voucher->order_item_id ) {
			$line = wc_get_order_item_meta( $voucher->order_item_id, Issuer::LINE_META, true );
			if ( is_array( $line ) && ( ! empty( $line[ self::SNAPSHOT_KEY ]['ticket_type_id'] ) || ! empty( $line[ self::SNAPSHOT_KEY ]['event_id'] ) ) ) {
				$target = (array) $line[ self::SNAPSHOT_KEY ];
			}
		}
		/** Ticket target of a voucher (e.g. for imported or generated codes); return null for none. */
		$target = apply_filters( 'vcap_passcaptain_ticket_target', $target, $voucher );
		if ( ! is_array( $target ) || ( empty( $target['ticket_type_id'] ) && empty( $target['event_id'] ) ) ) {
			return null;
		}
		$event = absint( $target['event_id'] ?? 0 );
		return array(
			// 0 for "customer chooses the date": the ticket type is picked from options() at exchange time.
			'ticket_type_id' => $event ? 0 : absint( $target['ticket_type_id'] ),
			'event_id'       => $event,
			'ticket_name'    => $event ? (string) ( $target['ticket_name'] ?? '' ) : '',
			'quantity'       => max( 1, min( 10, absint( $target['quantity'] ?? 1 ) ) ),
			'label'          => (string) ( $target['label'] ?? '' ),
		);
	}

	/**
	 * Dates the customer can choose for a "customer chooses the date" voucher (empty for fixed tickets).
	 *
	 * @return array<int, array{ticket_type_id:int,date_label:string}>
	 */
	public static function options( Voucher $voucher ): array {
		$target  = self::target( $voucher );
		$service = self::service();
		if ( ! $target || ! $target['event_id'] || ! $service || ! method_exists( $service, 'options' ) ) {
			return array();
		}
		// Only dates with enough free places for all tickets of the voucher.
		return array_values( (array) $service->options( array( 'event_id' => $target['event_id'], 'ticket_name' => $target['ticket_name'], 'quantity' => $target['quantity'] ) ) );
	}

	/**
	 * Attendee fields Pass Captain asks for per ticket of a ticket type (empty when none or unknown).
	 *
	 * @return array<int, array{key:string,label:string,type:string,required:bool}>
	 */
	public static function fields( int $ticket_type_id ): array {
		$service = self::service();
		return $service && $ticket_type_id && method_exists( $service, 'fields' ) ? array_values( (array) $service->fields( $ticket_type_id ) ) : array();
	}

	/**
	 * Exchange a voucher for its Pass Captain tickets.
	 *
	 * @param string $code Voucher code.
	 * @param array  $args email (string, trusted PHP callers: the ticket recipient and the address personal
	 *                     vouchers are checked against; default: logged-in user, else voucher recipient/buyer),
	 *                     name (string), user_id (int, default current user), attendees (array, passed to
	 *                     Pass Captain for ticket types with required attendee fields), ticket_type_id (int,
	 *                     the chosen date of a "customer chooses the date" voucher – one of options()),
	 *                     recipient_email (string: where the tickets go when a guest enters an address; no
	 *                     authorisation for personal vouchers).
	 * @return array<string, mixed>|\WP_Error voucher_id, order_public_id, tickets, email, status ("issued").
	 */
	public function exchange( string $code, array $args = array() ) {
		$voucher = $this->vouchers->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->vouchers->get_by_code( $code );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'This voucher code is not valid.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$target = self::target( $voucher );
		if ( ! $target ) {
			return new \WP_Error( 'vcap_no_ticket_target', __( 'This voucher cannot be exchanged for tickets.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		if ( $voucher->is_multi_use() ) {
			return new \WP_Error( 'vcap_no_ticket_target', __( 'Gift cards with a balance cannot be exchanged for tickets; use them in the ticket checkout.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		$service = self::service();
		if ( ! $service ) {
			return new \WP_Error( 'vcap_temporary_failure', __( 'Tickets cannot be issued right now. Please try again later.', 'voucher-captain' ), array( 'status' => 503 ) );
		}
		[ $attempt, $existing ] = $this->attempt( $voucher );
		$key                    = self::key( $voucher, $attempt );
		if ( $existing && RedemptionService::STATE_COMMITTED === $existing['state'] ) {
			$state = $service->get( self::exchange_key( $voucher, $attempt ) );
			if ( is_array( $state ) && 'revoked' === ( $state['status'] ?? '' ) ) {
				// Never report "sent" for tickets that were revoked (e.g. the event was cancelled).
				return new \WP_Error( 'vcap_ticket_exchange_revoked', __( 'The tickets of this voucher were revoked (for example because the event was cancelled). Please contact us.', 'voucher-captain' ), array( 'status' => 409 ) );
			}
			return $this->result( $voucher, is_array( $state ) ? $state : array(), (string) ( $existing['meta']['email'] ?? '' ) );
		}
		$ticket_type = $target['ticket_type_id'];
		if ( $target['event_id'] ) {
			$ticket_type = absint( $args['ticket_type_id'] ?? 0 );
			$options     = self::options( $voucher );
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
				$service->revoke_tickets( self::exchange_key( $voucher, $attempt ), 'voucher_date_changed' );
				$this->redemptions->release( $key );
				++$attempt;
				$key = self::key( $voucher, $attempt );
			}
		}
		$attendees = array();
		foreach ( is_array( $args['attendees'] ?? null ) ? array_values( $args['attendees'] ) : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$attendees[] = array( 'ticket_type_id' => $ticket_type ) + $entry;
			}
		}
		$fields = self::fields( $ticket_type );
		if ( ! $attendees && array_filter( array_column( $fields, 'required' ) ) ) {
			return new \WP_Error(
				'vcap_ticket_attendees_required',
				__( 'Please fill in the details for each ticket.', 'voucher-captain' ),
				array( 'status' => 400, 'fields' => $fields, 'quantity' => $target['quantity'], 'ticket_type_id' => $ticket_type )
			);
		}

		$user    = wp_get_current_user();
		$user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
		$email   = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$check   = $email; // Only a trusted caller's address may unlock personal vouchers.
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

		$issued = $service->issue_tickets(
			array(
				'provider'       => 'voucher-captain',
				'voucher_id'     => $voucher->id,
				'redemption_key' => $key,
				'code_hint'      => substr( $voucher->code, -4 ),
			),
			self::exchange_key( $voucher, $attempt ),
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
			$service->revoke_tickets( self::exchange_key( $voucher, $attempt ), 'voucher_not_redeemable' );
			$this->redemptions->release( $key );
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $committed->get_error_code() );
			return $committed;
		}
		/* translators: 1: number of tickets, 2: Pass Captain order id, 3: email */
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets', null, null, sprintf( __( '%1$d ticket(s), Pass Captain order %2$s, sent to %3$s', 'voucher-captain' ), count( (array) ( $issued['ticket_ids'] ?? array() ) ), (string) ( $issued['order_public_id'] ?? '' ), $email ) );
		do_action( 'vcap_passcaptain_tickets_issued', $voucher, $issued, $email );
		return $this->result( $voucher, $issued, $email );
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

	/** Voucher voided (purchase refunded) after the exchange: revoke the tickets in Pass Captain. */
	public function on_voucher_event( $voucher_id, $event ): void {
		if ( 'voided' !== $event ) {
			return;
		}
		$voucher = $this->vouchers->get( (int) $voucher_id );
		$service = self::service();
		if ( ! $voucher || ! $service || ! self::target( $voucher ) || ! apply_filters( 'vcap_passcaptain_revoke_on_void', true, $voucher ) ) {
			return;
		}
		[ $attempt, $redemption ] = $this->attempt( $voucher );
		if ( ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] ) {
			return;
		}
		$state = $service->get( self::exchange_key( $voucher, $attempt ) );
		if ( is_array( $state ) && 'revoked' !== ( $state['status'] ?? '' ) ) {
			$service->revoke_tickets( self::exchange_key( $voucher, $attempt ), 'voucher_voided' );
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_revoked', null, null, (string) ( $state['order_public_id'] ?? '' ) );
		}
	}

	/* ---- Redeem page ------------------------------------------------------------------------------- */

	public function redeem_page( $handled, $code ) {
		if ( null !== $handled ) {
			return $handled;
		}
		$voucher = $this->vouchers->get_by_code( (string) $code );
		if ( ! $voucher || ! self::target( $voucher ) || $voucher->is_multi_use() ) {
			return $handled;
		}
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$pending = $session && (string) $session->get( self::SESSION_CODE ) === (string) $code;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Shortcodes::handle_redeem().
		if ( isset( $_POST['vcap_ticket_reset'] ) ) {
			// "Choose another date" / "Cancel": start this code over (or forget it).
			$this->clear_session();
			if ( 'date' === sanitize_key( wp_unslash( $_POST['vcap_ticket_reset'] ) ) && $session ) {
				$session->set( self::SESSION_CODE, (string) $code );
			}
			return 'choose';
		}
		$guest_email = isset( $_POST['vcap_ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['vcap_ticket_email'] ) ) : '';
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
		$result = $this->exchange( (string) $code, $args );
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
		$shown = is_email( $guest_email ) || is_user_logged_in() ? $result['email'] : self::mask_email( $result['email'] );
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

	/** "j***@example.com" – never show a full address the visitor did not enter themselves. */
	public static function mask_email( string $email ): string {
		if ( ! str_contains( $email, '@' ) ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		return mb_substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/** The code waiting for its date choice (pre-filled in the redeem form). */
	public function redeem_prefill( $value ) {
		$code = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( self::SESSION_CODE ) : '';
		return '' !== $code ? $code : $value;
	}

	/** Date select of a "customer chooses the date" voucher and the attendee fields in the redeem form. */
	public function redeem_fields(): void {
		$code    = $this->redeem_prefill( '' );
		$voucher = '' !== $code ? $this->vouchers->get_by_code( $code ) : null;
		$target  = $voucher ? self::target( $voucher ) : null;
		if ( ! $target ) {
			return;
		}
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$choice  = $session ? absint( $session->get( self::SESSION_CHOICE ) ) : 0;
		$type    = $target['event_id'] ? $choice : $target['ticket_type_id'];
		if ( ! is_user_logged_in() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
			$typed = isset( $_POST['vcap_ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['vcap_ticket_email'] ) ) : ( $session ? (string) $session->get( 'vcap_ticket_email' ) : '' );
			echo '<label for="vcap-ticket-email">' . esc_html__( 'Send the tickets to (email)', 'voucher-captain' ) . '</label>';
			echo '<input type="email" id="vcap-ticket-email" name="vcap_ticket_email" required autocomplete="email" maxlength="190" value="' . esc_attr( $typed ) . '" />';
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
		$options = self::options( $voucher );
		if ( ! $options ) {
			return;
		}
		echo '<label for="vcap-ticket-choice">' . esc_html__( 'Date', 'voucher-captain' ) . '</label>';
		echo '<select id="vcap-ticket-choice" name="vcap_ticket_choice" required>';
		echo '<option value="">' . esc_html__( '— Choose a date —', 'voucher-captain' ) . '</option>';
		foreach ( $options as $o ) {
			echo '<option value="' . esc_attr( (string) $o['ticket_type_id'] ) . '">' . esc_html( (string) $o['date_label'] ) . '</option>';
		}
		echo '</select>';
	}

	/** One set of attendee inputs per ticket (names as posted to redeem_page()). */
	private function attendee_fields( int $type, int $quantity, bool $chosen ): void {
		$fields = self::fields( $type );
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
				$id   = 'vcap-attendee-' . $i . '-' . sanitize_key( $f['key'] );
				$name = 'vcap_attendees[' . $i . '][' . sanitize_key( $f['key'] ) . ']';
				$req  = ! empty( $f['required'] ) ? ' required' : '';
				$old  = isset( $posted[ $i ][ sanitize_key( $f['key'] ) ] ) && is_scalar( $posted[ $i ][ sanitize_key( $f['key'] ) ] ) ? (string) $posted[ $i ][ sanitize_key( $f['key'] ) ] : '';
				if ( 'checkbox' === $f['type'] ) {
					echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . esc_attr( $req ) . checked( '' !== $old, true, false ) . ' /> ' . esc_html( $f['label'] ) . '</label>';
					continue;
				}
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
				if ( 'textarea' === $f['type'] ) {
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . esc_attr( $req ) . '>' . esc_textarea( $old ) . '</textarea>';
				} else {
					echo '<input type="' . ( 'email' === $f['type'] ? 'email' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="190"' . esc_attr( $req ) . ' value="' . esc_attr( $old ) . '" />';
				}
			}
			echo '</fieldset>';
		}
	}

	/* ---- Scanner (door) ---------------------------------------------------------------------------- */

	/** A ticket voucher at the door: no "Redeem" button – it must be exchanged for tickets first. */
	public function scanner_describe( $data, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! self::target( $voucher ) || ! is_array( $data ) ) {
			return $data;
		}
		$data['redeemable'] = false;
		if ( 'ok' === ( $data['state'] ?? '' ) ) {
			$data['state']   = 'warning';
			$data['message'] = __( 'Ticket voucher – not a ticket. Exchange it for tickets first (redeem page or voucher admin).', 'voucher-captain' );
		}
		return $data;
	}

	public function scanner_redeem_refused( $refused, $voucher ) {
		return $voucher instanceof Voucher && self::target( $voucher ) ? __( 'Ticket vouchers cannot be redeemed at the counter. Exchange them for tickets first.', 'voucher-captain' ) : $refused;
	}

	/* ---- Product settings -------------------------------------------------------------------------- */

	public function product_fields( $product ): void {
		$service = self::service();
		if ( ! $service || ! method_exists( $service, 'targets' ) ) {
			return;
		}
		if ( $product instanceof \WC_Product && 'ticket' === $product->get_meta( '_vcap_type' ) ) {
			echo '<p class="form-field vcap-passcaptain-hint"><span class="description">' . esc_html__( 'Tip: the voucher type "Ticket" is a simple admission pass without dates or capacity, checked in at Voucher Captain. For events with dates, capacity and admission rules, create them in WP Pass Captain and use a voucher with "WP Pass Captain ticket" below (or sell Pass Captain tickets directly).', 'voucher-captain' ) . '</span></p>';
		}
		$current = $product instanceof \WC_Product ? absint( $product->get_meta( self::META_TYPE ) ) : 0;
		$event   = $product instanceof \WC_Product ? absint( $product->get_meta( self::META_EVENT ) ) : 0;
		$options = array( '' => __( '— No ticket (regular voucher) —', 'voucher-captain' ) );
		if ( method_exists( $service, 'events' ) ) {
			foreach ( (array) $service->events() as $e ) {
				/* translators: 1: event, 2: number of upcoming dates */
				$options[ 'event:' . $e['event_id'] ] = sprintf( __( 'Customer chooses the date: %1$s (%2$d dates)', 'voucher-captain' ), $e['label'], (int) $e['dates'] );
			}
		}
		foreach ( (array) $service->targets() as $t ) {
			/* translators: %s: event, date and ticket type */
			$options[ (string) $t['ticket_type_id'] ] = sprintf( __( 'Fixed date: %s', 'voucher-captain' ), $t['label'] );
		}
		if ( $event && ! isset( $options[ 'event:' . $event ] ) ) {
			/* translators: %d: event id */
			$options[ 'event:' . $event ] = sprintf( __( 'Event #%d (no upcoming dates)', 'voucher-captain' ), $event );
		}
		if ( $current && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: ticket type id */
			$options[ (string) $current ] = sprintf( __( 'Ticket type #%d (no longer on sale)', 'voucher-captain' ), $current );
		}
		woocommerce_wp_select(
			array(
				'id'          => self::META_TYPE,
				'label'       => __( 'WP Pass Captain ticket', 'voucher-captain' ),
				'options'     => $options,
				'value'       => $event ? 'event:' . $event : ( $current ? (string) $current : '' ),
				'desc_tip'    => true,
				'description' => __( 'Exchange each voucher for tickets of this ticket type (redeem page or voucher admin). The voucher becomes redeemable via WP Pass Captain.', 'voucher-captain' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_QTY,
				'label'             => __( 'Tickets per voucher', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => (string) max( 1, absint( $product instanceof \WC_Product ? $product->get_meta( self::META_QTY ) : 1 ) ),
				'custom_attributes' => array( 'min' => 1, 'max' => 10, 'step' => 1 ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_NAME,
				'label'       => __( 'Ticket name (date choice)', 'voucher-captain' ),
				'value'       => $product instanceof \WC_Product ? (string) $product->get_meta( self::META_NAME ) : '',
				'placeholder' => __( 'Any ticket type', 'voucher-captain' ),
				'desc_tip'    => true,
				'description' => __( 'Only for "customer chooses the date": offer only ticket types with exactly this name (e.g. "Standard").', 'voucher-captain' ),
			)
		);
	}

	public function product_save( \WC_Product $product, $post ): void {
		if ( ! is_array( $post ) || ! array_key_exists( self::META_TYPE, $post ) ) {
			return; // Field not shown (Pass Captain inactive): keep what is stored.
		}
		$value = sanitize_text_field( (string) $post[ self::META_TYPE ] );
		$event = 0 === strpos( $value, 'event:' ) ? absint( substr( $value, 6 ) ) : 0;
		$type  = $event ? 0 : absint( $value );
		$product->update_meta_data( self::META_TYPE, $type ? $type : '' );
		$product->update_meta_data( self::META_EVENT, $event ? $event : '' );
		$product->update_meta_data( self::META_NAME, $event ? sanitize_text_field( (string) ( $post[ self::META_NAME ] ?? '' ) ) : '' );
		$product->update_meta_data( self::META_QTY, max( 1, min( 10, absint( $post[ self::META_QTY ] ?? 1 ) ) ) );
		$channels = array_values( array_filter( (array) $product->get_meta( '_vcap_channels' ) ) );
		if ( ( $type || $event ) && $channels && ! in_array( PassCaptain::CHANNEL, $channels, true ) ) {
			// A ticket voucher must be redeemable via Pass Captain, whatever else was ticked.
			$channels[] = PassCaptain::CHANNEL;
			$product->update_meta_data( '_vcap_channels', $channels );
		}
	}

	/** Stores the ticket target with each sold voucher line (later product edits never change it). */
	public function snapshot( $extra, $item ) {
		$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
		$base    = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$type    = $base ? absint( $base->get_meta( self::META_TYPE ) ) : 0;
		$event   = $base ? absint( $base->get_meta( self::META_EVENT ) ) : 0;
		if ( ! $type && ! $event ) {
			return $extra;
		}
		$label = '';
		$service = self::service();
		if ( $service && $event && method_exists( $service, 'events' ) ) {
			foreach ( (array) $service->events() as $e ) {
				if ( (int) $e['event_id'] === $event ) {
					$label = (string) $e['label'];
				}
			}
		} elseif ( $service && method_exists( $service, 'targets' ) ) {
			foreach ( (array) $service->targets() as $t ) {
				if ( (int) $t['ticket_type_id'] === $type ) {
					$label = (string) $t['label'];
				}
			}
		}
		$extra[ self::SNAPSHOT_KEY ] = array(
			'ticket_type_id' => $event ? 0 : $type,
			'event_id'       => $event,
			'ticket_name'    => $event ? (string) $base->get_meta( self::META_NAME ) : '',
			'quantity'       => max( 1, min( 10, absint( $base->get_meta( self::META_QTY ) ) ?: 1 ) ),
			'label'          => $label,
		);
		return $extra;
	}

	/* ---- Voucher admin ----------------------------------------------------------------------------- */

	public function admin_button( $voucher, $form ): void {
		if ( ! $voucher instanceof Voucher || ! is_callable( $form ) || ! self::target( $voucher ) || ! self::service() ) {
			return;
		}
		$redemption = $this->attempt( $voucher )[1];
		if ( $redemption && RedemptionService::STATE_COMMITTED === $redemption['state'] ) {
			return; // Already exchanged: see the voucher history.
		}
		if ( ! in_array( $voucher->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_RESERVED ), true ) ) {
			return;
		}
		$options = self::target( $voucher )['event_id'] ? self::options( $voucher ) : null;
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
		if ( 'passcaptain_tickets' !== $do || ! $voucher instanceof Voucher ) {
			return $msg;
		}
		$email  = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in VoucherAdmin::handle_action().
		$choice = isset( $_POST['vcap_ticket_choice'] ) ? absint( wp_unslash( $_POST['vcap_ticket_choice'] ) ) : 0;
		$result = $this->exchange( $voucher->code, array( 'email' => $email, 'user_id' => 0, 'ticket_type_id' => $choice ) );
		return is_wp_error( $result ) ? 'passcaptain_tickets_failed' : 'passcaptain_tickets_issued';
	}

	public function admin_messages( array $messages ): array {
		return $messages + array(
			'passcaptain_tickets_issued' => __( 'Tickets issued in WP Pass Captain and sent by email.', 'voucher-captain' ),
			'passcaptain_tickets_failed' => __( 'The tickets could not be issued. The reason is in the voucher history.', 'voucher-captain' ),
		);
	}

	public function event_labels( array $labels ): array {
		return $labels + array(
			'passcaptain_tickets'         => __( 'Exchanged for Pass Captain tickets', 'voucher-captain' ),
			'passcaptain_tickets_failed'  => __( 'Ticket exchange failed', 'voucher-captain' ),
			'passcaptain_tickets_revoked' => __( 'Pass Captain tickets revoked', 'voucher-captain' ),
			'passcaptain_tickets_restored' => __( 'Voucher restored (tickets cancelled in Pass Captain)', 'voucher-captain' ),
		);
	}
}
