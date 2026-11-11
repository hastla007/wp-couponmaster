<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the voucher emails with WooCommerce (WooCommerce → Settings → Emails).
 */
final class Emails {
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'classes' ) );
	}

	public function classes( array $emails ): array {
		$emails['VCAP_Email_Voucher_Buyer']     = new Emails\BuyerEmail();
		$emails['VCAP_Email_Voucher_Recipient'] = new Emails\RecipientEmail();
		$emails['VCAP_Email_Voucher_Expiry']    = new Emails\ExpiryReminderEmail();
		$emails['VCAP_Email_Bonus']             = new Emails\BonusEmail();
		$emails['VCAP_Email_Referral']          = new Emails\ReferralEmail();
		return $emails;
	}
}
