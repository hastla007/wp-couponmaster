<?php
namespace WCVS\Emails;

use WCVS\Voucher;

defined( 'ABSPATH' ) || exit;

final class BuyerEmail extends VoucherEmail {
	public function __construct() {
		$this->id          = 'wcvs_voucher_buyer';
		$this->title       = __( 'Voucher (buyer)', 'wc-voucher-studio' );
		$this->description = __( 'Sent to the customer with their purchased voucher, gift card or ticket.', 'wc-voucher-studio' );
		parent::__construct();
	}

	protected function recipient_address( Voucher $voucher ): string {
		return (string) $voucher->buyer_email;
	}

	public function get_default_subject() {
		return __( 'Your voucher from {site_title}', 'wc-voucher-studio' );
	}

	public function get_default_heading() {
		return __( 'Your voucher {coupon_code}', 'wc-voucher-studio' );
	}

	public function get_default_intro(): string {
		return __( 'Hi {buyer_name}, thank you for your order #{order_number}. Your voucher for {product_name} is ready.', 'wc-voucher-studio' );
	}
}
