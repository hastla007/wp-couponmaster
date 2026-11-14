<?php
namespace VCAP\Emails;

use VCAP\Delivery;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

final class BuyerEmail extends VoucherEmail {
	public function __construct() {
		$this->id          = 'vcap_voucher_buyer';
		$this->title       = __( 'Voucher (buyer)', 'voucher-captain' );
		$this->description = __( 'Sent to the customer with their purchased voucher, gift card or ticket.', 'voucher-captain' );
		parent::__construct();
	}

	protected function recipient_address( Voucher $voucher ): string {
		return (string) $voucher->buyer_email;
	}

	public function get_default_subject() {
		if ( $this->voucher && Delivery::to_recipient( $this->voucher ) ) {
			return '' !== $this->placeholder( 'recipient_name' )
				? __( 'Your gift for {recipient_name} is on its way', 'voucher-captain' )
				: __( 'Your gift from {site_title} is on its way', 'voucher-captain' );
		}
		return __( 'Your voucher from {site_title}', 'voucher-captain' );
	}

	protected function default_subject_variants(): array {
		return array(
			static fn() => __( 'Your voucher from {site_title}', 'voucher-captain' ),
			static fn() => __( 'Your gift for {recipient_name} is on its way', 'voucher-captain' ),
			static fn() => __( 'Your gift from {site_title} is on its way', 'voucher-captain' ),
		);
	}

	public function get_default_heading() {
		return __( 'Your voucher {coupon_code}', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'Hi {buyer_name}, thank you for your order #{order_number}. Your voucher for {product_name} is ready.', 'voucher-captain' );
	}

	/**
	 * The buyer's copy of a gift (it goes out together with the recipient's mail) says where the gift went, so it
	 * reads as a confirmation and not as a voucher for the buyer.
	 */
	protected function default_intro_for( Voucher $voucher ): string {
		$named   = '' !== $this->placeholder( 'buyer_name' );
		if ( Delivery::to_recipient( $voucher ) ) {
			$texts = array(
				'11' => __( 'Hi {buyer_name}, your gift for {recipient_name} is on its way to {recipient_email}. This copy is for your records.', 'voucher-captain' ),
				'10' => __( 'Hi {buyer_name}, your gift is on its way to {recipient_email}. This copy is for your records.', 'voucher-captain' ),
				'01' => __( 'Hello, your gift for {recipient_name} is on its way to {recipient_email}. This copy is for your records.', 'voucher-captain' ),
				'00' => __( 'Hello, your gift is on its way to {recipient_email}. This copy is for your records.', 'voucher-captain' ),
			);
			return $texts[ (int) $named . (int) ( '' !== $this->placeholder( 'recipient_name' ) ) ];
		}
		$ordered = '' !== $this->placeholder( 'order_number' );
		$product = '' !== $this->placeholder( 'product_name' );
		if ( $ordered ) {
			return $named ? $this->get_default_intro() : __( 'Hello, thank you for your order #{order_number}. Your voucher for {product_name} is ready.', 'voucher-captain' );
		}
		if ( $product ) {
			return $named ? __( 'Hi {buyer_name}, your voucher for {product_name} is ready.', 'voucher-captain' ) : __( 'Hello, your voucher for {product_name} is ready.', 'voucher-captain' );
		}
		return $named ? __( 'Hi {buyer_name}, your voucher is ready.', 'voucher-captain' ) : __( 'Hello, your voucher is ready.', 'voucher-captain' );
	}
}
