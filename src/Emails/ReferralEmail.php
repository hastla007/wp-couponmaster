<?php
namespace VCAP\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Reward for the referring customer once a referred friend's order is completed.
 */
final class ReferralEmail extends BonusEmail {
	public function __construct() {
		$this->id          = 'vcap_referral_reward';
		$this->title       = __( 'Referral reward', 'voucher-captain' );
		$this->description = __( 'Sent to a customer when a friend they referred completed an order (Refer a friend, reward as coupon).', 'voucher-captain' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Thank you for recommending {site_title}', 'voucher-captain' );
	}

	public function get_default_heading() {
		return __( 'Your friend ordered – here is your reward', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'A friend you referred has placed an order. As a thank-you you receive {coupon_amount} off your next order with the code below. Valid until {expiry_date}.', 'voucher-captain' );
	}
}
