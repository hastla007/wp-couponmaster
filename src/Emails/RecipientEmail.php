<?php
namespace VCAP\Emails;

use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

final class RecipientEmail extends VoucherEmail {
	public function __construct() {
		$this->id          = 'vcap_voucher_recipient';
		$this->title       = __( 'Voucher (gift recipient)', 'voucher-captain' );
		$this->description = __( 'Sent to the gift recipient entered on the product page, at the scheduled delivery time.', 'voucher-captain' );
		parent::__construct();
	}

	protected function recipient_address( Voucher $voucher ): string {
		return (string) $voucher->recipient_email;
	}

	public function get_default_subject() {
		return __( '{buyer_name} sent you a gift from {site_title}', 'voucher-captain' );
	}

	public function get_default_heading() {
		return __( 'You received a gift!', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'Hi {recipient_name}, {buyer_name} sent you a voucher worth {coupon_value}.', 'voucher-captain' );
	}
}
