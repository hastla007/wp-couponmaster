<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\API\V1\RedemptionService;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Tickets taken back and vouchers given back (D14, split from PassCaptainTickets): a voided voucher revokes its
 * tickets, tickets revoked in Pass Captain give the voucher back (and tell the customer), and every failure is
 * logged and listed for the admins.
 */
final class Revocation {
	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers, private ExchangeState $state ) {}

	/**
	 * Pass Captain revoked or refunded the tickets of an exchange (order cancelled or refunded, date or
	 * event cancelled): once no ticket is left – none valid, none used – the voucher is given back (the
	 * redemption is refunded in full, a single-use voucher becomes active again) and can be exchanged anew.
	 * Idempotent per Pass Captain order. Filter "vcap_passcaptain_restore_on_revoke" (false = keep redeemed).
	 *
	 * @param array $info redemption_key, order_public_id, remaining, revoked, reason.
	 */
	public function on_tickets_revoked( $info ): void {
		$this->state->forget_states();
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
	public function restore( string $key, array $row, Voucher $voucher, string $order_public_id ): bool {
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
		$this->state->forget_states();
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
			$this->vouchers->log( $voucher->id, 'passcaptain_restore_notified', null, null, \VCAP\Mask::email( $to ) );
		} else {
			$this->vouchers->log( $voucher->id, 'delivery_failed', null, null, __( 'The customer could not be told that the voucher is valid again.', 'voucher-captain' ) );
		}
	}

	/** Vouchers whose tickets could not be taken back or given back (shown until the voucher is opened). */
	public const ATTENTION_OPTION = 'vcap_passcaptain_attention';

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
	public function revoke_checked( Voucher $voucher, string $exchange_key, string $reason, string $release_key = '' ): bool {
		$service = TicketContract::service();
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

	/** Voucher voided (purchase refunded) after the exchange: revoke the tickets in Pass Captain. */
	public function on_voucher_event( $voucher_id, $event ): void {
		$this->state->forget_states();
		if ( 'voided' !== $event ) {
			return;
		}
		$voucher = $this->vouchers->get( (int) $voucher_id );
		$service = TicketContract::service();
		if ( ! $voucher || ! $service || ! TicketContract::target( $voucher ) || ! apply_filters( 'vcap_passcaptain_revoke_on_void', true, $voucher ) ) {
			return;
		}
		[ $attempt, $redemption ] = $this->state->attempt( $voucher );
		if ( ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] ) {
			return;
		}
		$state = $service->get( TicketContract::exchange_key( $voucher, $attempt ) );
		if ( is_array( $state ) && 'revoked' !== ( $state['status'] ?? '' ) ) {
			if ( $this->revoke_checked( $voucher, TicketContract::exchange_key( $voucher, $attempt ), 'voucher_voided' ) ) {
				$this->vouchers->log( $voucher->id, 'passcaptain_tickets_revoked', null, null, (string) ( $state['order_public_id'] ?? '' ) );
			}
		}
	}
}
