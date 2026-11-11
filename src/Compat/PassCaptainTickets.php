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
	public const SNAPSHOT_KEY   = 'passcaptain_ticket';

	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers ) {}

	public function register(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_fields' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_filter( 'vcap_line_snapshot', array( $this, 'snapshot' ), 10, 2 );
		add_filter( 'vcap_redeem_code', array( $this, 'redeem_page' ), 10, 2 );
		add_action( 'vcap_voucher_admin_actions', array( $this, 'admin_button' ), 10, 2 );
		add_filter( 'vcap_voucher_admin_action', array( $this, 'admin_action' ), 10, 3 );
		add_filter( 'vcap_voucher_admin_messages', array( $this, 'admin_messages' ) );
		add_filter( 'vcap_event_labels', array( $this, 'event_labels' ) );
		add_action( 'vcap_voucher_event', array( $this, 'on_voucher_event' ), 10, 2 );
	}

	/** Pass Captain's ticket service (contract v1), or null when Pass Captain is not active or too old. */
	public static function service(): ?object {
		$service = apply_filters( self::SERVICE_FILTER, null, 1 );
		return is_object( $service ) && method_exists( $service, 'issue_tickets' ) && method_exists( $service, 'revoke_tickets' ) && method_exists( $service, 'get' ) ? $service : null;
	}

	/** Redemption key of a voucher's exchange (one exchange per voucher). */
	public static function key( Voucher $voucher ): string {
		return 'passcaptain:ticket:' . $voucher->id;
	}

	/** Idempotency key on Pass Captain's side. */
	public static function exchange_key( Voucher $voucher ): string {
		return 'vcap-voucher-' . $voucher->id;
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
			if ( is_array( $line ) && ! empty( $line[ self::SNAPSHOT_KEY ]['ticket_type_id'] ) ) {
				$target = (array) $line[ self::SNAPSHOT_KEY ];
			}
		}
		/** Ticket target of a voucher (e.g. for imported or generated codes); return null for none. */
		$target = apply_filters( 'vcap_passcaptain_ticket_target', $target, $voucher );
		if ( ! is_array( $target ) || empty( $target['ticket_type_id'] ) ) {
			return null;
		}
		return array(
			'ticket_type_id' => absint( $target['ticket_type_id'] ),
			'quantity'       => max( 1, min( 10, absint( $target['quantity'] ?? 1 ) ) ),
			'label'          => (string) ( $target['label'] ?? '' ),
		);
	}

	/**
	 * Exchange a voucher for its Pass Captain tickets.
	 *
	 * @param string $code Voucher code.
	 * @param array  $args email (string, trusted PHP callers: the ticket recipient and the address personal
	 *                     vouchers are checked against; default: logged-in user, else voucher recipient/buyer),
	 *                     name (string), user_id (int, default current user), attendees (array, passed to
	 *                     Pass Captain for ticket types with required attendee fields).
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
		$key      = self::key( $voucher );
		$existing = $this->redemptions->get( $key );
		if ( ! is_wp_error( $existing ) && RedemptionService::STATE_COMMITTED === $existing['state'] ) {
			$state = $service->get( self::exchange_key( $voucher ) );
			return $this->result( $voucher, is_array( $state ) ? $state : array(), (string) ( $existing['meta']['email'] ?? '' ) );
		}

		$user    = wp_get_current_user();
		$user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
		$email   = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$check   = $email; // Only a trusted caller's address may unlock personal vouchers.
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
				'target'      => 'passcaptain:ticket:' . $target['ticket_type_id'],
				'user_id'     => $user_id,
				'email'       => $check,
				'meta'        => array( 'email' => $email, 'tickets' => $target['quantity'] ),
				// Long enough to retry a Pass Captain outage without losing the voucher in between.
				'ttl_minutes' => (int) apply_filters( 'vcap_passcaptain_ticket_reservation_minutes', DAY_IN_SECONDS / MINUTE_IN_SECONDS ),
			)
		);
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}

		$issued = $service->issue_tickets(
			array(
				'provider'       => 'voucher-captain',
				'voucher_id'     => $voucher->id,
				'redemption_key' => $key,
				'code_hint'      => substr( $voucher->code, -4 ),
			),
			self::exchange_key( $voucher ),
			array( 'ticket_type_id' => $target['ticket_type_id'], 'quantity' => $target['quantity'] ),
			array(
				'email'     => $email,
				'name'      => sanitize_text_field( (string) ( $args['name'] ?? ( $voucher->recipient_name ?: $voucher->buyer_name ) ) ),
				'attendees' => is_array( $args['attendees'] ?? null ) ? $args['attendees'] : array(),
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
			$service->revoke_tickets( self::exchange_key( $voucher ), 'voucher_not_redeemable' );
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
		$redemption = $this->redemptions->get( self::key( $voucher ) );
		if ( is_wp_error( $redemption ) || RedemptionService::STATE_COMMITTED !== $redemption['state'] ) {
			return;
		}
		$state = $service->get( self::exchange_key( $voucher ) );
		if ( is_array( $state ) && 'revoked' !== ( $state['status'] ?? '' ) ) {
			$service->revoke_tickets( self::exchange_key( $voucher ), 'voucher_voided' );
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
		$result = $this->exchange( (string) $code );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return 'rejected';
		}
		/* translators: 1: number of tickets, 2: email */
		wc_add_notice( sprintf( __( 'Your tickets (%1$d) were sent to %2$s.', 'voucher-captain' ), $result['tickets'], $result['email'] ) );
		return 'exchanged';
	}

	/* ---- Product settings -------------------------------------------------------------------------- */

	public function product_fields( $product ): void {
		$service = self::service();
		if ( ! $service || ! method_exists( $service, 'targets' ) ) {
			return;
		}
		$current = $product instanceof \WC_Product ? absint( $product->get_meta( self::META_TYPE ) ) : 0;
		$options = array( '' => __( '— No ticket (regular voucher) —', 'voucher-captain' ) );
		foreach ( (array) $service->targets() as $t ) {
			$options[ (string) $t['ticket_type_id'] ] = (string) $t['label'];
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
				'value'       => $current ? (string) $current : '',
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
	}

	public function product_save( \WC_Product $product, $post ): void {
		if ( ! is_array( $post ) || ! array_key_exists( self::META_TYPE, $post ) ) {
			return; // Field not shown (Pass Captain inactive): keep what is stored.
		}
		$type = absint( $post[ self::META_TYPE ] );
		$product->update_meta_data( self::META_TYPE, $type ? $type : '' );
		$product->update_meta_data( self::META_QTY, max( 1, min( 10, absint( $post[ self::META_QTY ] ?? 1 ) ) ) );
		$channels = array_values( array_filter( (array) $product->get_meta( '_vcap_channels' ) ) );
		if ( $type && $channels && ! in_array( PassCaptain::CHANNEL, $channels, true ) ) {
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
		if ( ! $type ) {
			return $extra;
		}
		$label = '';
		$service = self::service();
		if ( $service && method_exists( $service, 'targets' ) ) {
			foreach ( (array) $service->targets() as $t ) {
				if ( (int) $t['ticket_type_id'] === $type ) {
					$label = (string) $t['label'];
				}
			}
		}
		$extra[ self::SNAPSHOT_KEY ] = array(
			'ticket_type_id' => $type,
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
		$redemption = $this->redemptions->get( self::key( $voucher ) );
		if ( ! is_wp_error( $redemption ) && RedemptionService::STATE_COMMITTED === $redemption['state'] ) {
			return; // Already exchanged: see the voucher history.
		}
		if ( in_array( $voucher->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_RESERVED ), true ) ) {
			$form( 'passcaptain_tickets', __( 'Exchange for tickets', 'voucher-captain' ) );
		}
	}

	public function admin_action( $msg, $do, $voucher ) {
		if ( 'passcaptain_tickets' !== $do || ! $voucher instanceof Voucher ) {
			return $msg;
		}
		$email  = is_email( $voucher->recipient_email ) ? $voucher->recipient_email : $voucher->buyer_email;
		$result = $this->exchange( $voucher->code, array( 'email' => $email, 'user_id' => 0 ) );
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
		);
	}
}
