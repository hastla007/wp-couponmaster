<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the voucher emails with WooCommerce (WooCommerce → Settings → Emails).
 */
final class Emails {
	/** M12-84: ledger event once the buyer was told that the gift was used (sent at most once). */
	public const USED_NOTICE_EVENT = 'gift_used_notice';

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'classes' ) );
		add_action( 'vcap_voucher_delivered', array( $this, 'gift_delivered' ), 20, 1 );
		add_action( 'vcap_voucher_redeemed', array( $this, 'gift_used' ), 20, 1 );
	}

	/** @param mixed $emails What the previous filter returned (AP-Q2-6: a non-array is normalised, no TypeError). */
	public function classes( $emails ): array {
		$emails                                 = is_array( $emails ) ? $emails : array();
		$emails['VCAP_Email_Voucher_Buyer']    = new Emails\BuyerEmail();
		$emails['VCAP_Email_Voucher_Recipient'] = new Emails\RecipientEmail();
		$emails['VCAP_Email_Voucher_Bundle']    = new Emails\BundleEmail();
		$emails['VCAP_Email_Voucher_Expiry']    = new Emails\ExpiryReminderEmail();
		$emails['VCAP_Email_Bonus']             = new Emails\BonusEmail();
		$emails['VCAP_Email_Referral']          = new Emails\ReferralEmail();
		$emails['VCAP_Email_Gift_Delivered']    = new Emails\GiftNoticeEmail( 'delivered' );
		$emails['VCAP_Email_Gift_Used']         = new Emails\GiftNoticeEmail( 'used' );
		return $emails;
	}

	/** The gift notice email of a kind when it is registered and switched on. */
	private static function notice( string $class ): ?Emails\GiftNoticeEmail {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$email = WC()->mailer()->get_emails()[ $class ] ?? null;
		return $email instanceof Emails\GiftNoticeEmail && $email->is_enabled() ? $email : null;
	}

	/**
	 * M12-84: "Your gift was delivered" to the buyer of a gift (optional email). Not for "recipient and buyer"
	 * gifts: the buyer got a copy at the same moment.
	 *
	 * @param mixed $voucher Voucher delivered (action vcap_voucher_delivered).
	 */
	public function gift_delivered( $voucher ): void {
		$email = $voucher instanceof Voucher && 'both' !== $voucher->send_to ? self::notice( 'VCAP_Email_Gift_Delivered' ) : null;
		if ( $email && Emails\GiftNoticeEmail::applies( $voucher ) ) {
			try {
				$email->trigger( $voucher );
			} catch ( \Throwable $e ) {
				Logger::error( 'Gift delivered notice failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $voucher->id ) );
			}
		}
	}

	/**
	 * M12-84: "Your gift was used" to the buyer on the first redemption of a gift (optional email, at most once).
	 *
	 * @param mixed $data Redemption data (action vcap_voucher_redeemed).
	 */
	public function gift_used( $data ): void {
		$email = is_array( $data ) && ! empty( $data['voucher_id'] ) ? self::notice( 'VCAP_Email_Gift_Used' ) : null;
		if ( ! $email ) {
			return;
		}
		$voucher = vcap()->vouchers->get( (int) $data['voucher_id'] );
		if ( ! $voucher || ! Emails\GiftNoticeEmail::applies( $voucher ) || vcap()->vouchers->has_event( $voucher->id, self::USED_NOTICE_EVENT ) || ! Claim::take( 'gift-used:' . $voucher->id ) ) {
			return;
		}
		try {
			if ( $email->trigger( $voucher ) ) {
				vcap()->vouchers->log( $voucher->id, self::USED_NOTICE_EVENT );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Gift used notice failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $voucher->id ) );
		} finally {
			Claim::release( 'gift-used:' . $voucher->id );
		}
	}
}
