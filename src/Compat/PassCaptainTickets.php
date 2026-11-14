<?php
namespace VCAP\Compat;

use VCAP\API\V1\RedemptionService;
use VCAP\Channels;
use VCAP\Issuer;
use VCAP\ProductConfig;
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
		$this->register_lifecycle();
		$this->register_features();
	}

	/** New ticket vouchers: product settings and sale checks (only with the premium right "passcaptain"). */
	public function register_features(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_fields' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'check_fixed_date_capacity' ), 25, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_capacity' ) );
		add_action( 'admin_notices', array( $this, 'redeem_page_notice' ) );
		add_action( 'admin_notices', array( $this, 'attention_notice' ) );
	}

	/** Without the right: ticket-voucher products are not sold; sold ones are still exchanged. */
	public function register_locked(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'not_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'not_purchasable' ), 20, 2 );
	}

	public function not_purchasable( $purchasable, $product ) {
		$base = $product instanceof \WC_Product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $purchasable || ! $base instanceof \WC_Product ) {
			return $purchasable;
		}
		return absint( $base->get_meta( self::META_TYPE ) ) || absint( $base->get_meta( self::META_EVENT ) ) ? false : $purchasable;
	}

	/**
	 * Sold ticket vouchers: always registered, also while the premium right is locked – line snapshot at issue,
	 * exchange (redeem page, admin, account), channel rules, revocation, emails and placeholders.
	 */
	public function register_lifecycle(): void {
		add_filter( 'vcap_line_snapshot', array( $this, 'snapshot' ), 10, 2 );
		add_filter( 'vcap_voucher_expires_at', array( $this, 'expires_at_event' ), 10, 2 );
		add_filter( 'vcap_redemption_channel_allowed', array( $this, 'channel_allowed' ), 10, 4 );
		add_filter( 'vcap_shop_channel_refused_message', array( $this, 'shop_refused_message' ), 10, 2 );
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
		add_action( 'vcap_passcaptain_tickets_issued', function () { $this->forget_states(); }, 0 );
		add_filter( 'vcap_voucher_next_steps', array( $this, 'next_steps' ), 10, 2 );
		add_filter( 'vcap_email_intro', array( $this, 'email_intro' ), 10, 3 );
		add_filter( 'vcap_placeholder_definitions', array( $this, 'placeholder_definitions' ) );
		add_filter( 'vcap_placeholders', array( $this, 'placeholders' ), 10, 2 );
		add_action( 'vcap_account_voucher_status', array( $this, 'account_status' ) );
		add_action( 'vcap_account_voucher_actions', array( $this, 'account_actions' ) );
		add_filter( 'vcap_customer_can_resend', array( $this, 'customer_can_resend' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'customer_resend_tickets' ), 5 );
	}

	/* ---- Customer communication ------------------------------------------------------------------- */

	/** What a ticket voucher holder does next (voucher email and expiry reminder). */
	public function next_steps( $steps, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! $voucher->is_usable() ) {
			return $steps;
		}
		$target = self::target( $voucher );
		if ( ! $target || $this->exchange_state( $voucher ) ) {
			return $steps;
		}
		$what = self::tickets_label( $target );
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
		if ( ! $voucher instanceof Voucher || ! is_object( $email ) || 'vcap_voucher_expiry' !== ( $email->id ?? '' ) || '' !== (string) $email->get_option( 'intro', '' ) || ! self::target( $voucher ) ) {
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
		$target = $voucher instanceof Voucher ? self::target( $voucher ) : null;
		$values = (array) $values;
		$values['ticket_event']    = $target ? $target['label'] : '';
		$values['ticket_quantity'] = $target ? (string) $target['quantity'] : '';
		return $values;
	}

	/** "2 tickets for Concert X" (or just "2 tickets" without a label). */
	private static function tickets_label( array $target ): string {
		$count = sprintf(
			/* translators: %d: number of tickets */
			_n( '%d ticket', '%d tickets', $target['quantity'], 'voucher-captain' ),
			$target['quantity']
		);
		/* translators: 1: "2 tickets", 2: event */
		return '' !== $target['label'] ? sprintf( __( '%1$s for %2$s', 'voucher-captain' ), $count, $target['label'] ) : $count;
	}

	/**
	 * Pass Captain's state of the voucher's committed exchange (event, starts_at, email, ticket_ids, status), or
	 * null when it was not exchanged (yet).
	 */
	/** @var array<int, array|null> Exchange state per voucher for this request (account pages ask several times). */
	private array $states = array();

	private function exchange_state( Voucher $voucher ): ?array {
		if ( array_key_exists( $voucher->id, $this->states ) ) {
			return $this->states[ $voucher->id ];
		}
		[ $attempt, $redemption ] = $this->attempt( $voucher );
		$service                  = self::service();
		if ( ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] || ! $service ) {
			return $this->states[ $voucher->id ] = null;
		}
		$state = $service->get( self::exchange_key( $voucher, $attempt ) );
		return $this->states[ $voucher->id ] = ( is_array( $state ) ? $state + array( 'key' => self::exchange_key( $voucher, $attempt ) ) : null );
	}

	/** Forget cached exchange states (after an exchange, a revocation or a void). */
	private function forget_states(): void {
		$this->states = array();
	}

	/** Customer account: what the voucher was exchanged for. */
	public function account_status( $voucher ): void {
		if ( ! $voucher instanceof Voucher || ! self::target( $voucher ) ) {
			return;
		}
		$state = $this->exchange_state( $voucher );
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
			$text = sprintf( __( '%1$s, sent to %2$s', 'voucher-captain' ), $text, self::mask_email( (string) $state['email'] ) );
		}
		echo '<br><small>' . esc_html( $text ) . '</small>';
	}

	/** Customer account: "Exchange for tickets" before, "Send tickets again" after the exchange. */
	public function account_actions( $voucher ): void {
		if ( ! $voucher instanceof Voucher || ! self::target( $voucher ) ) {
			return;
		}
		$state = $this->exchange_state( $voucher );
		if ( ! $state ) {
			$url = $voucher->is_usable() ? \VCAP\Shortcodes::redeem_url( $voucher->code ) : '';
			if ( '' !== $url ) {
				/* translators: %s: voucher code */
				echo '<a class="woocommerce-button button vcap-exchange-button" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Exchange voucher %s for tickets', 'voucher-captain' ), $voucher->code ) ) . '">' . esc_html__( 'Exchange for tickets', 'voucher-captain' ) . '</a> ';
			}
			return;
		}
		$service = self::service();
		if ( 'issued' !== ( $state['status'] ?? '' ) || ! $service || ! method_exists( $service, 'resend_tickets' ) || ! $this->is_ticket_holder( $voucher, $state ) ) {
			return;
		}
		echo '<form method="post" class="vcap-resend-tickets-form"><input type="hidden" name="vcap_resend_tickets" value="' . esc_attr( (string) $voucher->id ) . '" />';
		wp_nonce_field( 'vcap_resend_tickets_' . $voucher->id, 'vcap_resend_tickets_nonce' );
		// New ticket codes replace the old ones: asked explicitly (works without JavaScript).
		echo '<label class="vcap-resend-confirm"><input type="checkbox" name="vcap_resend_confirm" value="1" required /> ' . esc_html__( 'I understand that the tickets sent so far become invalid.', 'voucher-captain' ) . '</label> ';
		/* translators: %s: voucher code */
		echo '<button type="submit" class="woocommerce-button button" aria-label="' . esc_attr( sprintf( __( 'Send the tickets of voucher %s again', 'voucher-captain' ), $voucher->code ) ) . '">' . esc_html__( 'Send tickets again', 'voucher-captain' ) . '</button></form>';
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
		return $can && $voucher instanceof Voucher && self::target( $voucher ) && $this->exchange_state( $voucher ) ? false : $can;
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
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error' );
			return;
		}
		if ( empty( $_POST['vcap_resend_confirm'] ) ) {
			wc_add_notice( __( 'Please confirm that the tickets sent so far become invalid.', 'voucher-captain' ), 'error' );
			return;
		}
		$voucher = $this->vouchers->get( $id );
		$state   = $voucher ? $this->exchange_state( $voucher ) : null;
		$state   = $state && $this->is_ticket_holder( $voucher, $state ) ? $state : null;
		$service = self::service();
		if ( ! $state || ! $service || ! method_exists( $service, 'resend_tickets' ) ) {
			wc_add_notice( __( 'The tickets of this voucher cannot be sent again.', 'voucher-captain' ), 'error' );
			return;
		}
		$result = $service->resend_tickets( $state['key'] );
		if ( is_wp_error( $result ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, __( 'Sending the tickets again (requested by the customer) failed:', 'voucher-captain' ) . ' ' . $result->get_error_code() . ': ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_resent', null, null, __( 'Requested by the customer', 'voucher-captain' ) );
			/* translators: %s: masked email */
			wc_add_notice( sprintf( __( 'The tickets were sent again to %s. Only the new tickets are valid.', 'voucher-captain' ), self::mask_email( (string) ( $result['email'] ?? '' ) ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( \VCAP\Downloads::ENDPOINT ) );
		exit;
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
		$this->forget_states();
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
		if ( $this->restore( $key, $row, $voucher, (string) ( $info['order_public_id'] ?? '' ) ) ) {
			$this->notify_restored( $this->vouchers->get( $voucher->id ) ?? $voucher );
		}
	}

	/** Gives the voucher of a committed exchange back (refund of the redemption; idempotent per PC order). */
	private function restore( string $key, array $row, Voucher $voucher, string $order_public_id ): bool {
		$order_ref  = preg_replace( '/[^a-f0-9]/', '', strtolower( $order_public_id ) );
		$refund_key = 'passcaptain:restore:' . substr( '' !== $order_ref ? $order_ref : md5( $key ), 0, 32 );
		$amount     = (float) $row['amount'];
		$refunded   = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
		if ( $amount <= 0 || $refunded + 0.0001 >= $amount ) {
			return false;
		}
		$result = $this->redemptions->refund( $key, $amount - $refunded, $refund_key );
		if ( is_wp_error( $result ) || empty( $result['restored'] ) ) {
			// Tickets are gone but the voucher is not given back: never silently.
			$this->vouchers->log( $voucher->id, 'passcaptain_restore_failed', null, null, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'refund: nothing restored' );
			self::flag_attention( $voucher->id );
			return false;
		}
		$this->forget_states();
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets_restored', (float) $result['restored'], null, $order_public_id );
		return true;
	}

	/**
	 * Tells the customer that the voucher is valid again after its tickets were cancelled (Pass Captain's own
	 * mail only says the tickets are gone). Filter "vcap_passcaptain_notify_restored" (false = no mail).
	 */
	private function notify_restored( Voucher $voucher ): void {
		$to = $voucher->delivery_email();
		if ( ! is_email( $to ) || ! apply_filters( 'vcap_passcaptain_notify_restored', true, $voucher, $to ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$values  = \VCAP\Placeholders::values( $voucher );
		$url     = \VCAP\Shortcodes::redeem_url( $voucher->code );
		$heading = __( 'Your voucher is valid again', 'voucher-captain' );
		$lines   = array(
			/* translators: 1: voucher code, 2: expiry date */
			sprintf( __( 'The tickets you received for voucher %1$s were cancelled. The voucher is valid again until %2$s and can be exchanged for tickets once more.', 'voucher-captain' ), $voucher->code, $values['expiry_date'] ?? '' ),
		);
		$body = '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>';
		if ( '' !== $url ) {
			$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Exchange for tickets', 'voucher-captain' ) . '</a></p>';
		}
		$mailer = WC()->mailer();
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your voucher is valid again', 'voucher-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$sent    = $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body ) );
		if ( $sent ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_restore_notified', null, null, self::mask_email( $to ) );
		} else {
			$this->vouchers->log( $voucher->id, 'delivery_failed', null, null, __( 'The customer could not be told that the voucher is valid again.', 'voucher-captain' ) );
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
	 * Whether a ticket voucher may also be spent as a value voucher (cart, Pass Captain checkout, scanner). Only
	 * when the product explicitly allows the shop as a redemption channel; by default a ticket voucher is only
	 * exchanged for its tickets on the redeem page.
	 */
	public static function value_use_allowed( Voucher $voucher ): bool {
		$allowed = in_array( Channels::SHOP, Channels::of_voucher( $voucher ), true );
		return (bool) apply_filters( 'vcap_passcaptain_ticket_voucher_as_value', $allowed, $voucher );
	}

	/** Ticket vouchers sold without a channel selection are redeemable via Pass Captain only. */
	public function channel_allowed( $allowed, $voucher, $channel, $channels ) {
		if ( ! $allowed || $channels || PassCaptain::CHANNEL === $channel || ! $voucher instanceof Voucher || ! self::target( $voucher ) ) {
			return $allowed;
		}
		return self::value_use_allowed( $voucher );
	}

	/** Ticket vouchers are exchanged on the redeem page: warn when products sell them but the page is missing. */
	/** Vouchers whose tickets could not be taken back or given back (shown until the voucher is opened). */
	public const ATTENTION_OPTION = 'vcap_passcaptain_attention';

	public function attention_notice(): void {
		$ids    = array_map( 'intval', (array) get_option( self::ATTENTION_OPTION, array() ) );
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Pass Captain tickets could not be cancelled or a voucher could not be given back. The customer may hold valid tickets while the voucher is free again (or the other way round). Check these vouchers and their history:', 'voucher-captain' ) . ' ' . implode( ', ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
	}

	/** Remember a voucher for the admin notice. */
	private static function flag_attention( int $voucher_id ): void {
		$ids = array_map( 'intval', (array) get_option( self::ATTENTION_OPTION, array() ) );
		if ( ! in_array( $voucher_id, $ids, true ) ) {
			$ids[] = $voucher_id;
			update_option( self::ATTENTION_OPTION, array_slice( $ids, -50 ), false );
		}
	}

	/**
	 * Takes the tickets of an exchange back in Pass Captain and (optionally) releases the voucher reservation,
	 * checking both answers: a failure is logged as "passcaptain_revoke_failed" with its error code and shown to
	 * the admins – otherwise the customer could keep valid tickets while the voucher is free again, without a trace.
	 */
	private function revoke_checked( Voucher $voucher, string $exchange_key, string $reason, string $release_key = '' ): bool {
		$service = self::service();
		$errors  = array();
		$revoked = $service ? $service->revoke_tickets( $exchange_key, $reason ) : new \WP_Error( 'vcap_passcaptain_inactive', __( 'WP Pass Captain is not active.', 'voucher-captain' ) );
		if ( is_wp_error( $revoked ) ) {
			$errors[] = 'revoke_tickets ' . $revoked->get_error_code() . ': ' . $revoked->get_error_message();
		} elseif ( false === $revoked ) {
			$errors[] = 'revoke_tickets: false';
		}
		if ( '' !== $release_key ) {
			$released = $this->redemptions->release( $release_key );
			// "Missing" = nothing reserved any more (expired or released before): nothing to give back.
			if ( is_wp_error( $released ) && 'vcap_reservation_missing' !== $released->get_error_code() ) {
				$errors[] = 'release ' . $released->get_error_code() . ': ' . $released->get_error_message();
			}
		}
		if ( ! $errors ) {
			return true;
		}
		$this->vouchers->log( $voucher->id, 'passcaptain_revoke_failed', null, null, implode( '; ', $errors ) );
		self::flag_attention( $voucher->id );
		return false;
	}

	public function redeem_page_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) || ! ( str_contains( (string) $screen->id, 'vcap' ) || in_array( $screen->id, array( 'dashboard', 'product', 'edit-product', 'woocommerce_page_wc-settings' ), true ) ) ) {
			return;
		}
		$problem = self::sells_ticket_vouchers() ? \VCAP\Shortcodes::redeem_page_problem() : '';
		if ( '' === $problem ) {
			return;
		}
		$link = admin_url( 'admin.php?page=wc-settings&tab=vcap' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $problem ) . ' ' . esc_html__( 'Customers exchange ticket vouchers for tickets on that page, and voucher emails link to it.', 'voucher-captain' )
			. ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Choose the redeem page', 'voucher-captain' ) . '</a></p></div>';
	}

	/** Whether any published product sells Pass Captain ticket vouchers. */
	public static function sells_ticket_vouchers(): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN (%s, %s) AND pm.meta_value NOT IN ('', '0') AND p.post_status = 'publish' LIMIT 1", self::META_TYPE, self::META_EVENT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) apply_filters( 'vcap_sells_ticket_vouchers', (bool) $found );
	}

	public function shop_refused_message( $message, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! self::target( $voucher ) ) {
			return $message;
		}
		$url = \VCAP\Shortcodes::redeem_url();
		return '' !== $url
			/* translators: %s: URL of the voucher redemption page */
			? sprintf( __( 'This voucher is exchanged for tickets, not used as payment. Please redeem it on %s.', 'voucher-captain' ), $url )
			: __( 'This voucher is exchanged for tickets, not used as payment. Please redeem it on the voucher redemption page.', 'voucher-captain' );
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
		$this->forget_states();
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
			$done = $this->result( $voucher, is_array( $state ) ? $state : array(), (string) ( $existing['meta']['email'] ?? '' ) );
			$done['reused']       = true;
			$done['exchanged_at'] = (string) ( $existing['committed_at'] ?? '' );
			return $done;
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
				$this->revoke_checked( $voucher, self::exchange_key( $voucher, $attempt ), 'voucher_date_changed', $key );
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
				// VAT treatment as in the checkout quote; single-purpose vouchers with the VAT of their sale.
				'purpose'        => ProductConfig::purpose( $voucher ),
				'sale_tax'       => 'single' === ProductConfig::purpose( $voucher ) ? ProductConfig::sale_tax( $voucher ) : null,
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
			$this->revoke_checked( $voucher, self::exchange_key( $voucher, $attempt ), 'voucher_not_redeemable', $key );
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $committed->get_error_code() );
			return $committed;
		}
		$tickets = count( (array) ( $issued['ticket_ids'] ?? array() ) );
		/* translators: 1: number of tickets, 2: Pass Captain order id, 3: email */
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets', null, null, sprintf( _n( '%1$d ticket, Pass Captain order %2$s, sent to %3$s', '%1$d tickets, Pass Captain order %2$s, sent to %3$s', $tickets, 'voucher-captain' ), $tickets, (string) ( $issued['order_public_id'] ?? '' ), $email ) );
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
		$this->forget_states();
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
			if ( $this->revoke_checked( $voucher, self::exchange_key( $voucher, $attempt ), 'voucher_voided' ) ) {
				$this->vouchers->log( $voucher->id, 'passcaptain_tickets_revoked', null, null, (string) ( $state['order_public_id'] ?? '' ) );
			}
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
		// The full address only when it is the visitor's own (entered now or of the logged-in account).
		$own   = array_filter( array( strtolower( $guest_email ), is_user_logged_in() ? strtolower( (string) wp_get_current_user()->user_email ) : '' ) );
		$shown = in_array( strtolower( (string) $result['email'] ), $own, true ) ? $result['email'] : self::mask_email( $result['email'] );
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
				'description' => __( 'Exchange each voucher for tickets of this ticket type (redeem page or voucher admin). The voucher is then only exchanged for its tickets; to let it also pay as a value voucher, tick "Shop" under "Redeemable via".', 'voucher-captain' ),
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

	/** A fixed-date ticket voucher expires when its event starts (no exchange after the date). */
	public function expires_at_event( $expires, $item ) {
		$line   = $item instanceof \WC_Order_Item_Product ? $item->get_meta( Issuer::LINE_META ) : null;
		$starts = is_array( $line ) ? (string) ( $line[ self::SNAPSHOT_KEY ]['starts_at'] ?? '' ) : '';
		if ( '' === $starts || ! strtotime( $starts . ' UTC' ) ) {
			return $expires;
		}
		return ! $expires || strtotime( $starts . ' UTC' ) < strtotime( $expires . ' UTC' ) ? $starts : $expires;
	}

	/**
	 * Fixed-date ticket vouchers are only sold while the date has room for their tickets (all of this product in
	 * the cart). The places are taken when the voucher is exchanged; until then Pass Captain may sell them too.
	 */
	public function check_fixed_date_capacity( $passed, $product_id, $quantity = 1 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product = wc_get_product( (int) $product_id );
		$in_cart = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$in_cart += (int) $line['product_id'] === (int) $product_id ? (int) $line['quantity'] : 0;
			}
		}
		$problem = $product instanceof \WC_Product ? $this->fixed_date_problem( $product, $in_cart + max( 1, (int) $quantity ) ) : '';
		if ( '' !== $problem ) {
			wc_add_notice( $problem, 'error' );
			return false;
		}
		return $passed;
	}

	public function check_cart_capacity(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$counts = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$counts[ (int) $line['product_id'] ] = ( $counts[ (int) $line['product_id'] ] ?? 0 ) + (int) $line['quantity'];
		}
		foreach ( $counts as $product_id => $qty ) {
			$product = wc_get_product( $product_id );
			$problem = $product instanceof \WC_Product ? $this->fixed_date_problem( $product, $qty ) : '';
			if ( '' !== $problem ) {
				wc_add_notice( $problem, 'error' );
			}
		}
	}

	/** Why $vouchers of a fixed-date ticket voucher product cannot be sold right now ('' = fine). */
	public function fixed_date_problem( \WC_Product $product, int $vouchers ): string {
		$type    = absint( $product->get_meta( self::META_TYPE ) );
		$service = self::service();
		if ( ! $type || ! $service || ! method_exists( $service, 'targets' ) || ! apply_filters( 'vcap_passcaptain_check_fixed_date_capacity', true, $product ) ) {
			return '';
		}
		$needed = $vouchers * max( 1, min( 10, absint( $product->get_meta( self::META_QTY ) ) ?: 1 ) );
		$open   = array_map( 'intval', array_column( (array) $service->targets( array( 'quantity' => $needed ) ), 'ticket_type_id' ) );
		/* translators: %s: product name */
		return in_array( $type, $open, true ) ? '' : sprintf( __( '"%s" is not available: the date is sold out or already over.', 'voucher-captain' ), $product->get_name() );
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
		$label     = '';
		$starts_at = '';
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
					$label     = (string) $t['label'];
					$starts_at = (string) ( $t['starts_at'] ?? '' );
				}
			}
		}
		$extra[ self::SNAPSHOT_KEY ] = array(
			'ticket_type_id' => $event ? 0 : $type,
			'event_id'       => $event,
			'ticket_name'    => $event ? (string) $base->get_meta( self::META_NAME ) : '',
			'quantity'       => max( 1, min( 10, absint( $base->get_meta( self::META_QTY ) ) ?: 1 ) ),
			'label'          => $label,
			// Fixed date: the voucher can be exchanged until the event starts (UTC).
			'starts_at'      => $starts_at ?? '',
		);
		return $extra;
	}

	/* ---- Voucher admin ----------------------------------------------------------------------------- */

	public function admin_button( $voucher, $form ): void {
		if ( $voucher instanceof Voucher ) {
			// The admin opened the voucher (history shows the failure): no longer flagged.
			$ids = array_map( 'intval', (array) get_option( self::ATTENTION_OPTION, array() ) );
			if ( in_array( $voucher->id, $ids, true ) ) {
				update_option( self::ATTENTION_OPTION, array_values( array_diff( $ids, array( $voucher->id ) ) ), false );
			}
		}
		if ( ! $voucher instanceof Voucher || ! is_callable( $form ) || ! self::target( $voucher ) || ! self::service() ) {
			return;
		}
		$redemption = $this->attempt( $voucher )[1];
		if ( $redemption && RedemptionService::STATE_COMMITTED === $redemption['state'] ) {
			// Exchanged: send the tickets again, or undo the exchange (e.g. to choose another date).
			if ( method_exists( self::service(), 'resend_tickets' ) ) {
				$form( 'passcaptain_resend', __( 'Send tickets again', 'voucher-captain' ), 'button', array(), __( 'Send the tickets again? New ticket codes are issued; the tickets sent so far become invalid.', 'voucher-captain' ) );
			}
			if ( Voucher::STATUS_VOID !== $voucher->status ) {
				$form( 'passcaptain_undo', __( 'Undo exchange (e.g. to change the date)', 'voucher-captain' ), 'button', array(), __( 'Undo the exchange? Its tickets are cancelled in WP Pass Captain and the voucher becomes valid again, so it can be exchanged for another date.', 'voucher-captain' ) );
			}
			return;
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
		$result = $this->exchange( $voucher->code, array( 'email' => $email, 'user_id' => 0, 'ticket_type_id' => $choice ) );
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
		$service = self::service();
		[ $attempt, $redemption ] = $this->attempt( $voucher );
		if ( ! $service || ! method_exists( $service, 'resend_tickets' ) || ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] ) {
			return 'passcaptain_resend_failed';
		}
		$result = $service->resend_tickets( self::exchange_key( $voucher, $attempt ) );
		if ( is_wp_error( $result ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, $result->get_error_code() . ': ' . $result->get_error_message() );
			return 'passcaptain_resend_failed';
		}
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets_resent', null, null, __( 'Requested by an admin:', 'voucher-captain' ) . ' ' . self::mask_email( (string) ( $result['email'] ?? '' ) ) );
		return 'passcaptain_resent';
	}

	/**
	 * Admin: undo an exchange. The tickets are cancelled in Pass Captain and the voucher becomes valid again (then
	 * it can be exchanged for another date). Refused while checked-in tickets remain (they stay valid).
	 */
	private function admin_undo( Voucher $voucher ): string {
		$service = self::service();
		[ $attempt, $redemption ] = $this->attempt( $voucher );
		if ( ! $service || ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] || Voucher::STATUS_VOID === $voucher->status ) {
			return 'passcaptain_undo_failed';
		}
		$key = self::exchange_key( $voucher, $attempt );
		if ( ! $this->revoke_checked( $voucher, $key, 'voucher_exchange_undone' ) ) {
			$this->forget_states();
			return 'passcaptain_undo_failed';
		}
		$this->forget_states();
		$state = $service->get( $key );
		if ( is_array( $state ) && ( 'revoked' !== ( $state['status'] ?? '' ) || ! empty( $state['ticket_ids'] ) ) ) {
			$this->vouchers->log( $voucher->id, 'passcaptain_tickets_failed', null, null, __( 'The exchange could not be undone completely: tickets that were already checked in stay valid.', 'voucher-captain' ) );
			return 'passcaptain_undo_failed';
		}
		$this->vouchers->log( $voucher->id, 'passcaptain_tickets_revoked', null, null, (string) ( $state['order_public_id'] ?? '' ) );
		$restored = $this->restore( self::key( $voucher, $attempt ), $redemption, $voucher, (string) ( $state['order_public_id'] ?? '' ) );
		return $restored ? 'passcaptain_undone' : 'passcaptain_undo_failed';
	}

	public function admin_messages( array $messages ): array {
		return $messages + array(
			'passcaptain_tickets_issued' => __( 'Tickets issued in WP Pass Captain and sent by email.', 'voucher-captain' ),
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
