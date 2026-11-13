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
		if ( $this->voucher && '' === $this->placeholder( 'buyer_name' ) ) {
			return __( 'You received a gift from {site_title}', 'voucher-captain' );
		}
		return __( '{buyer_name} sent you a gift from {site_title}', 'voucher-captain' );
	}

	public function get_default_heading() {
		return __( 'You received a gift!', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'Hi {recipient_name}, {buyer_name} sent you a voucher worth {coupon_value}.', 'voucher-captain' );
	}

	/**
	 * Value vouchers name their value, percentage vouchers the discount, ticket vouchers the product. The recipient
	 * is greeted by their own name only ({recipient_name} falls back to the buyer for the PDF), the giver is named
	 * when known.
	 */
	protected function default_intro_for( Voucher $voucher ): string {
		$greet = '' !== trim( $voucher->recipient_name );
		$giver = '' !== $this->placeholder( 'buyer_name' );
		$kind  = self::has_no_value( $voucher ) ? 'product' : ( 'percent' === $voucher->discount_type ? 'percent' : 'value' );
		$texts = array(
			'value'   => array(
				'11' => $this->get_default_intro(),
				'10' => __( 'Hi {recipient_name}, you received a voucher worth {coupon_value} as a gift.', 'voucher-captain' ),
				'01' => __( 'Hello, {buyer_name} sent you a voucher worth {coupon_value}.', 'voucher-captain' ),
				'00' => __( 'Hello, you received a voucher worth {coupon_value} as a gift.', 'voucher-captain' ),
			),
			'percent' => array(
				'11' => __( 'Hi {recipient_name}, {buyer_name} sent you a voucher for {coupon_value} off.', 'voucher-captain' ),
				'10' => __( 'Hi {recipient_name}, you received a voucher for {coupon_value} off as a gift.', 'voucher-captain' ),
				'01' => __( 'Hello, {buyer_name} sent you a voucher for {coupon_value} off.', 'voucher-captain' ),
				'00' => __( 'Hello, you received a voucher for {coupon_value} off as a gift.', 'voucher-captain' ),
			),
			'product' => array(
				'11' => __( 'Hi {recipient_name}, {buyer_name} sent you {product_name}.', 'voucher-captain' ),
				'10' => __( 'Hi {recipient_name}, you received {product_name} as a gift.', 'voucher-captain' ),
				'01' => __( 'Hello, {buyer_name} sent you {product_name}.', 'voucher-captain' ),
				'00' => __( 'Hello, you received {product_name} as a gift.', 'voucher-captain' ),
			),
		);
		return $texts[ $kind ][ (int) $greet . (int) $giver ];
	}
}
