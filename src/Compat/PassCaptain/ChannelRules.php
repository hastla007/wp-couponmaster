<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\Channels;
use VCAP\Compat\PassCaptain;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Where a ticket voucher may be redeemed (D14, split from PassCaptainTickets): exchanged for its tickets through
 * Pass Captain, as a value voucher only where the product allows the shop, never at the counter or the door.
 */
final class ChannelRules {

	/**
	 * Whether a ticket voucher may also be spent as a value voucher (cart, Pass Captain checkout, scanner). Only
	 * when the product explicitly allows the shop as a redemption channel; by default a ticket voucher is only
	 * exchanged for its tickets on the redeem page.
	 */
	public static function value_use_allowed( Voucher $voucher ): bool {
		$allowed = in_array( Channels::SHOP, Channels::of_voucher( $voucher ), true );
		return (bool) apply_filters( 'vcap_passcaptain_ticket_voucher_as_value', $allowed, $voucher );
	}

	/** Ticket vouchers sold without a channel selection are redeemable via Pass Captain only. */
	public function channel_allowed( $allowed, $voucher, $channel, $channels ) {
		if ( ! $allowed || $channels || PassCaptain::CHANNEL === $channel || ! $voucher instanceof Voucher || ! TicketContract::target( $voucher ) ) {
			return $allowed;
		}
		return self::value_use_allowed( $voucher );
	}

	public function shop_refused_message( $message, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! TicketContract::target( $voucher ) ) {
			return $message;
		}
		$url = \VCAP\Shortcodes::redeem_url();
		return '' !== $url
			/* translators: %s: URL of the voucher redemption page */
			? sprintf( __( 'This voucher is exchanged for tickets, not used as payment. Please redeem it on %s.', 'voucher-captain' ), $url )
			: __( 'This voucher is exchanged for tickets, not used as payment. Please redeem it on the voucher redemption page.', 'voucher-captain' );
	}

	/** A ticket voucher at the door: no "Redeem" button – it must be exchanged for tickets first. */
	public function scanner_describe( $data, $voucher ) {
		if ( ! $voucher instanceof Voucher || ! TicketContract::target( $voucher ) || ! is_array( $data ) ) {
			return $data;
		}
		$data['redeemable'] = false;
		if ( 'ok' === ( $data['state'] ?? '' ) ) {
			$data['state']   = 'warning';
			$data['message'] = __( 'Ticket voucher – not a ticket. Exchange it for tickets first (redeem page or voucher admin).', 'voucher-captain' );
		}
		return $data;
	}

	public function scanner_redeem_refused( $refused, $voucher ) {
		return $voucher instanceof Voucher && TicketContract::target( $voucher ) ? __( 'Ticket vouchers cannot be redeemed at the counter. Exchange them for tickets first.', 'voucher-captain' ) : $refused;
	}
}
