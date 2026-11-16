<?php
namespace VCAP\Workflow;

use VCAP\Note;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WF-04: optional customer communication that a workflow plugin may take over. Voucher Captain's own flows stay
 * the default: a communication is handed over only while (1) its switch under WooCommerce → Settings → Vouchers →
 * Integrations is on (default off), (2) a workflow plugin with a compatible contract is registered and (3) that
 * plugin says it handles this communication right now (its "communications" list; e.g. not while its licence has
 * expired). Otherwise Voucher Captain sends its own email as before, so a missing, deactivated or incompatible
 * workflow plugin never leaves a customer without the message.
 *
 * Only optional notices can be handed over – never the voucher email with its code and PDF, a payment, a refund
 * or a booking. Each hand-over is written to the voucher history (ledger event "workflow_handover"), which fires
 * the workflow event "voucher.communication_handed_over" after the COMMIT.
 */
final class Handover {
	/** Ledger event of a hand-over. */
	public const EVENT = 'workflow_handover';

	public const EXPIRY_REMINDER = 'expiry_reminder';
	public const GIFT_DELIVERED  = 'gift_delivered';
	public const GIFT_USED       = 'gift_used';

	/** Communication => option of its switch ("yes" = hand over). */
	public const OPTIONS = array(
		self::EXPIRY_REMINDER => 'vcap_workflow_handover_expiry_reminder',
		self::GIFT_DELIVERED  => 'vcap_workflow_handover_gift_delivered',
		self::GIFT_USED       => 'vcap_workflow_handover_gift_used',
	);

	/** Translated names of the communications (settings, history, status). */
	public static function labels(): array {
		return array(
			self::EXPIRY_REMINDER => __( 'Expiry reminder', 'voucher-captain' ),
			self::GIFT_DELIVERED  => __( 'Notice to the buyer: gift delivered', 'voucher-captain' ),
			self::GIFT_USED       => __( 'Notice to the buyer: gift used', 'voucher-captain' ),
		);
	}

	/** Whether the shop switched the hand-over of this communication on (says nothing about the workflow plugin). */
	public static function switched_on( string $communication ): bool {
		$option = self::OPTIONS[ $communication ] ?? '';
		return '' !== $option && 'yes' === get_option( $option, 'no' );
	}

	/** Communications whose switch is on. */
	public static function switched(): array {
		return array_values( array_filter( array_keys( self::OPTIONS ), array( self::class, 'switched_on' ) ) );
	}

	/**
	 * Whether Voucher Captain leaves this communication to the workflow plugin now: switched on, and a compatible
	 * workflow plugin is registered that handles it (Provider::consumer()). False falls back to the native email.
	 */
	public static function active( string $communication ): bool {
		if ( ! self::switched_on( $communication ) ) {
			return false;
		}
		$consumer = Provider::consumer();
		return Provider::COMPATIBLE === Provider::compatibility( $consumer )
			&& 'expired' !== ( $consumer['entitlement'] ?? '' )
			&& in_array( $communication, (array) ( $consumer['communications'] ?? array() ), true );
	}

	/** Coded history note of a hand-over (exact text, also matched by the expiry reminder query). */
	public static function note( string $communication, int $days = 0 ): string {
		return Note::make( self::EVENT, $days > 0 ? array( $communication, $days ) : array( $communication ) );
	}

	/**
	 * Records the hand-over in the voucher history; the workflow event follows after the COMMIT.
	 *
	 * @param int                    $days Days before the expiry (expiry reminders).
	 * @param VoucherRepository|null $repo Repository (default: the plugin's).
	 */
	public static function record( Voucher $voucher, string $communication, int $days = 0, ?VoucherRepository $repo = null ): bool {
		if ( ! isset( self::OPTIONS[ $communication ] ) || $voucher->id <= 0 ) {
			return false;
		}
		return ( $repo ?? vcap()->vouchers )->log( $voucher->id, self::EVENT, null, null, self::note( $communication, $days ) );
	}

	/** Who the handed-over communication is meant for: the holder for reminders, the buyer for gift notices. */
	public static function role( Voucher $voucher, string $communication ): string {
		// Like Voucher::delivery_email(), the address the native expiry reminder goes to.
		return self::EXPIRY_REMINDER === $communication && 'recipient' === $voucher->send_to && is_email( $voucher->recipient_email ) ? 'recipient' : 'buyer';
	}
}
