<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * History notes stored as a code with arguments and translated when shown (M13): the ledger and its CSV export are
 * in the language of the reader, not in the language of the request that wrote the entry, and internal role keys
 * ("recipient", "buyer") never end up in a sentence.
 *
 * Stored form: "@vcap:" followed by JSON {"c": code, "a": [args]}. Notes written before (plain text) and free text
 * typed by staff are kept and shown as they are.
 */
final class Note {
	public const PREFIX = '@vcap:';

	/** Encoded note for VoucherRepository::log(). Arguments are scalars (strings, numbers). */
	public static function make( string $code, array $args = array() ): string {
		$args = array_values( array_map( static fn( $a ) => is_scalar( $a ) || null === $a ? $a : (string) wp_json_encode( $a ), $args ) );
		return self::PREFIX . (string) wp_json_encode( array( 'c' => $code, 'a' => $args ) );
	}

	/** Whether a stored note is an encoded one. */
	public static function is_coded( string $note ): bool {
		return str_starts_with( $note, self::PREFIX );
	}

	/**
	 * Code and arguments of an encoded note, null for plain text.
	 *
	 * @return array{code:string, args:array}|null
	 */
	public static function decode( string $note ): ?array {
		if ( ! self::is_coded( $note ) ) {
			return null;
		}
		$data = json_decode( substr( $note, strlen( self::PREFIX ) ), true );
		if ( ! is_array( $data ) || ! isset( $data['c'] ) || ! is_string( $data['c'] ) ) {
			return null;
		}
		return array( 'code' => $data['c'], 'args' => is_array( $data['a'] ?? null ) ? array_values( $data['a'] ) : array() );
	}

	/** The note as text in the current language (plain notes unchanged; an unknown code shows the code). */
	public static function render( string $note ): string {
		$decoded = self::decode( $note );
		if ( null === $decoded ) {
			return $note;
		}
		$code = $decoded['code'];
		$args = $decoded['args'];
		$text = self::text( $code, $args );
		/** Text of a coded history note (connectors can translate their own codes). */
		return (string) apply_filters( 'vcap_note_text', $text, $code, $args );
	}

	/** Translated label of a delivery role. */
	public static function role_label( string $role ): string {
		switch ( $role ) {
			case 'recipient':
				return __( 'Recipient', 'voucher-captain' );
			case 'buyer':
				return __( 'Buyer', 'voucher-captain' );
		}
		return $role;
	}

	/** Translated reason why a voucher email was not sent (VoucherEmail::$last_failure). */
	public static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case 'disabled':
				return __( 'email disabled', 'voucher-captain' );
			case 'no_recipient':
				return __( 'no valid address', 'voucher-captain' );
			case 'mail_failed':
				return __( 'the mail system did not accept the email', 'voucher-captain' );
			case 'not_registered':
				return __( 'email not registered', 'voucher-captain' );
			case 'exception':
				return __( 'error while preparing the email', 'voucher-captain' );
		}
		return $reason;
	}

	/** "Recipient: email disabled, Buyer: mail system …" from a role => reason map. */
	private static function reasons( $map ): string {
		$map   = is_string( $map ) ? json_decode( $map, true ) : $map;
		$parts = array();
		foreach ( is_array( $map ) ? $map : array() as $role => $reason ) {
			$parts[] = self::role_label( (string) $role ) . ': ' . self::reason_label( (string) $reason );
		}
		return implode( '; ', $parts );
	}

	private static function roles( $list ): string {
		return implode( ', ', array_map( array( self::class, 'role_label' ), array_filter( explode( ',', (string) $list ) ) ) );
	}

	private static function text( string $code, array $args ): string {
		$a = static fn( int $i ) => $args[ $i ] ?? '';
		switch ( $code ) {
			case 'voided_refund':
				// The reason as written when the purchase was refunded or cancelled (Issuer::void()).
				return (string) $a( 0 );
			case 'delivery_not_sent':
				/* translators: %s: per role the reason, e.g. "Recipient: email disabled" */
				return sprintf( __( 'No email was sent (%s).', 'voucher-captain' ), self::reasons( $a( 0 ) ) );
			case 'delivery_partial':
				/* translators: 1: roles still missing, e.g. "Buyer", 2: reasons per role */
				return sprintf( __( 'Not delivered to: %1$s (will be retried). %2$s', 'voucher-captain' ), self::roles( $a( 0 ) ), self::reasons( $a( 1 ) ) );
			case 'delivered':
				$count = (int) $a( 0 );
				/* translators: %d: number of addresses */
				$text = sprintf( _n( 'Sent to %d address', 'Sent to %d addresses', $count, 'voucher-captain' ), $count );
				$roles = self::roles( $a( 1 ) );
				$text  = '' !== $roles ? $text . ' (' . $roles . ')' : $text;
				$batch = (int) $a( 2 );
				if ( $batch > 1 ) {
					/* translators: %d: number of other vouchers in the same email */
					$text .= ' ' . sprintf( _n( 'in one email together with %d other voucher', 'in one email together with %d other vouchers', $batch - 1, 'voucher-captain' ), $batch - 1 );
				}
				return $text;
			case 'delivered_without_pdf':
				return __( 'The email was sent without the PDF attachment: the PDF could not be created (see the WooCommerce log).', 'voucher-captain' );
			case 'delivery_error':
				/* translators: %s: error message */
				return sprintf( __( 'Delivery failed: %s', 'voucher-captain' ), (string) $a( 0 ) );
			case 'delivery_given_up':
				$failures = (int) $a( 0 );
				/* translators: %d: number of failed attempts */
				return sprintf( _n( 'No more automatic attempts after %d failed delivery. Check the email address and the email settings, then send the voucher again.', 'No more automatic attempts after %d failed deliveries. Check the email address and the email settings, then send the voucher again.', $failures, 'voucher-captain' ), $failures );
			case 'delivery_expired':
				$expiry = wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $a( 0 ) . ' UTC' ) );
				/* translators: %s: expiry date */
				return sprintf( __( 'Not delivered: the voucher had already expired on %s.', 'voucher-captain' ), $expiry );
			case 'reminder_failed':
				return __( 'Expiry reminder could not be sent; it is retried on the next run.', 'voucher-captain' );
			case 'issued_standalone':
				$parts = array_filter( array( (string) $a( 0 ), (string) $a( 1 ) ), static fn( $p ) => '' !== $p );
				return $parts
					/* translators: %s: source and reference given by the caller, e.g. "company-order, order-4711/3" */
					? sprintf( __( 'Issued without order (%s)', 'voucher-captain' ), implode( ', ', $parts ) )
					: __( 'Issued without order', 'voucher-captain' );
			case 'resent':
				/* translators: 1: role ("Buyer", "Recipient"), 2: masked email address */
				return sprintf( __( 'Sent to the %1$s (%2$s) by an admin', 'voucher-captain' ), self::role_label( (string) $a( 0 ) ), (string) $a( 1 ) );
			case 'resent_bulk':
				/* translators: %s: roles, e.g. "Recipient, Buyer" */
				return sprintf( __( 'Sent to %s by an admin (bulk action)', 'voucher-captain' ), self::roles( $a( 0 ) ) );
			case 'printed_nup':
				return __( 'Printed by an admin (several vouchers per A4 sheet)', 'voucher-captain' );
			case 'resend_failed':
				/* translators: %s: reason */
				return sprintf( __( 'The voucher email was not sent: %s.', 'voucher-captain' ), (string) $a( 0 ) );
			case 'expired':
				return __( 'Expired (the validity ended).', 'voucher-captain' );
			case 'edited':
				return self::changes( $a( 0 ) );
			case 'reissued':
				/* translators: 1: old code (masked), 2: new code (masked) */
				$text = sprintf( __( 'Reissued: the code %1$s was replaced by %2$s. The old code and its download links no longer work.', 'voucher-captain' ), (string) $a( 0 ), (string) $a( 1 ) );
				return '' !== (string) $a( 2 ) ? $text . ' ' . (string) $a( 2 ) : $text;
		}
		return $code;
	}

	/** "Expiry: 2026-12-31 → 2027-12-31; Recipient email: j***@example.com → a***@example.com". */
	private static function changes( $map ): string {
		$map    = is_string( $map ) ? json_decode( $map, true ) : $map;
		$labels = array(
			'expires_at'      => __( 'Expiry', 'voucher-captain' ),
			'recipient_name'  => __( 'Recipient name', 'voucher-captain' ),
			'recipient_email' => __( 'Recipient email', 'voucher-captain' ),
			'message'         => __( 'Message', 'voucher-captain' ),
			'send_to'         => __( 'Send to', 'voucher-captain' ),
			'status'          => __( 'Status', 'voucher-captain' ),
			'max_checkins'    => __( 'Maximum check-ins', 'voucher-captain' ),
			'delivery_at'     => __( 'Delivery', 'voucher-captain' ),
		);
		$parts = array();
		foreach ( is_array( $map ) ? $map : array() as $field => $change ) {
			$from    = is_array( $change ) ? (string) ( $change[0] ?? '' ) : '';
			$to      = is_array( $change ) ? (string) ( $change[1] ?? '' ) : '';
			$parts[] = ( $labels[ $field ] ?? $field ) . ': ' . ( '' !== $from ? $from : '—' ) . ' → ' . ( '' !== $to ? $to : '—' );
		}
		return $parts ? implode( '; ', $parts ) : __( 'Saved without changes', 'voucher-captain' );
	}
}
