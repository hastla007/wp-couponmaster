<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\Issuer;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Voucher Captain's side of Pass Captain's ticket service contract v1 (D14, split from PassCaptainTickets): the
 * service itself, the redemption and idempotency keys, a voucher's ticket target, the date options and attendee
 * fields, and the product meta that sells ticket vouchers.
 */
final class TicketContract {
	public const SERVICE_FILTER = 'wp_pass_captain_ticket_service_v1';
	public const META_TYPE      = '_vcap_passcaptain_ticket_type';
	public const META_QTY       = '_vcap_passcaptain_tickets';
	/** "Customer chooses the date": event id and optional ticket name instead of a fixed ticket type. */
	public const META_EVENT     = '_vcap_passcaptain_event';
	public const META_NAME      = '_vcap_passcaptain_ticket_name';
	public const SNAPSHOT_KEY   = 'passcaptain_ticket';

	/** "2 tickets for Concert X" (or just "2 tickets" without a label). */
	public static function tickets_label( array $target ): string {
		$count = sprintf(
			/* translators: %d: number of tickets */
			_n( '%d ticket', '%d tickets', $target['quantity'], 'voucher-captain' ),
			$target['quantity']
		);
		/* translators: 1: "2 tickets", 2: event */
		return '' !== $target['label'] ? sprintf( __( '%1$s for %2$s', 'voucher-captain' ), $count, $target['label'] ) : $count;
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

	/** Whether any published product sells Pass Captain ticket vouchers. */
	public static function sells_ticket_vouchers(): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN (%s, %s) AND pm.meta_value NOT IN ('', '0') AND p.post_status = 'publish' LIMIT 1", self::META_TYPE, self::META_EVENT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) apply_filters( 'vcap_sells_ticket_vouchers', (bool) $found );
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
}
