<?php
namespace VCAP\Emails;

use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Reminder before a voucher with remaining value expires (to the recipient if the gift went to them).
 */
final class ExpiryReminderEmail extends VoucherEmail {
	public function __construct() {
		$this->id          = 'vcap_voucher_expiry';
		$this->title       = __( 'Voucher expiry reminder', 'voucher-captain' );
		$this->description = __( 'Sent once, a configurable number of days before a voucher with remaining value expires (WooCommerce → Settings → Vouchers → Delivery & downloads).', 'voucher-captain' );
		parent::__construct();
	}

	protected function recipient_address( Voucher $voucher ): string {
		return $voucher->delivery_email();
	}

	public function get_default_subject() {
		return __( 'Your voucher from {site_title} expires on {expiry_date}', 'voucher-captain' );
	}

	public function get_default_heading() {
		return __( 'Don\'t forget your voucher', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'Your voucher {coupon_code} is valid until {expiry_date}. Remaining value: {coupon_balance}.', 'voucher-captain' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['attach_pdf']['default'] = 'no';
	}
}
