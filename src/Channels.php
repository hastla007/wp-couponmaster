<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Redemption channels ("Einlösewege"): where a voucher may be redeemed. Built in: the shop (cart/checkout) and
 * external redemption (REST/PHP redemption API, used by connector plugins). Connectors can add their own channels
 * with the "vcap_redemption_channels" filter and map their redemption targets to them.
 *
 * The allowed channels are chosen per product and stored with the sold order line, so later product changes do not
 * alter what was sold. No selection = every channel.
 */
final class Channels {
	public const SHOP     = 'shop';
	public const EXTERNAL = 'external';
	/** Redemption at the counter with the admin scanner. */
	public const COUNTER  = 'counter';

	/** @return array<string, string> channel => label */
	public static function all(): array {
		$channels = array(
			self::SHOP     => __( 'Shop (cart and checkout)', 'voucher-captain' ),
			self::EXTERNAL => __( 'External redemption (API, connected plugins)', 'voucher-captain' ),
			self::COUNTER  => __( 'At the counter (scanner)', 'voucher-captain' ),
		);
		/** Redemption channels (arg: array of channel key => label): connectors add where their vouchers can be redeemed (API.md). */
		return (array) apply_filters( 'vcap_redemption_channels', $channels );
	}

	/** Channel used by the redemption API for a target (connectors may map their targets to their own channel). */
	public static function for_target( string $target ): string {
		return (string) apply_filters( 'vcap_redemption_channel_for_target', self::EXTERNAL, $target );
	}

	/** Channels stored for the voucher's order line (empty = all). */
	public static function of_voucher( Voucher $voucher ): array {
		if ( ! $voucher->order_item_id ) {
			return array();
		}
		$line = wc_get_order_item_meta( $voucher->order_item_id, Issuer::LINE_META, true );
		return is_array( $line ) && ! empty( $line['channels'] ) ? array_values( array_map( 'strval', (array) $line['channels'] ) ) : array();
	}

	/**
	 * How the recipient redeems this voucher, from the channels it was sold for (mail, PDF, My Account): the cart
	 * only where the shop channel is allowed, the counter where only the counter is, the redeem page otherwise.
	 * Plain text; '' for tickets (checked in, not redeemed). Filter "vcap_redeem_instructions".
	 */
	public static function instructions( Voucher $voucher ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf( self::instructions_pattern( $voucher ), $site );
	}

	/** The same with the shop name linked to the shop (or its redeem page), escaped for HTML. */
	public static function instructions_html( Voucher $voucher ): string {
		$url  = $voucher->id ? Shortcodes::redeem_url( $voucher->code ) : '';
		$link = '<a href="' . esc_url( '' !== $url ? $url : home_url( '/' ) ) . '">' . esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</a>';
		return sprintf( esc_html( self::instructions_pattern( $voucher ) ), $link );
	}

	/** Translated instruction with one %s for the shop name ('' for tickets). */
	private static function instructions_pattern( Voucher $voucher ): string {
		$channels = self::of_voucher( $voucher );
		if ( $voucher->is_ticket() ) {
			$text = '';
		} elseif ( ! $channels || in_array( self::SHOP, $channels, true ) ) {
			$text = in_array( self::COUNTER, $channels, true )
				/* translators: %s: shop name */
				? __( 'Enter the code in the cart or at checkout on %s, or show this voucher at the counter.', 'voucher-captain' )
				/* translators: %s: shop name */
				: __( 'Enter the code in the cart or at checkout on %s to redeem it.', 'voucher-captain' );
		} elseif ( in_array( self::COUNTER, $channels, true ) ) {
			/* translators: %s: shop name */
			$text = __( 'Show this voucher (code or QR code) at the counter of %s to redeem it. It cannot be redeemed in the online shop.', 'voucher-captain' );
		} else {
			/* translators: %s: shop name */
			$text = __( 'Redeem the code on the redeem page of %s. It cannot be redeemed in the cart of the online shop.', 'voucher-captain' );
		}
		$text = (string) apply_filters( 'vcap_redeem_instructions', $text, $voucher, $channels );
		// One %s for the shop name; any other % of a filtered text is literal.
		$parts = explode( '%s', $text, 2 );
		return implode( '%s', array_map( static fn( $part ) => str_replace( '%', '%%', $part ), $parts ) );
	}

	public static function allowed( Voucher $voucher, string $channel ): bool {
		$channels = self::of_voucher( $voucher );
		$allowed  = ! $channels || in_array( $channel, $channels, true );
		return (bool) apply_filters( 'vcap_redemption_channel_allowed', $allowed, $voucher, $channel, $channels );
	}
}
