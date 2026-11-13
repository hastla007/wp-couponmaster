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

	public static function allowed( Voucher $voucher, string $channel ): bool {
		$channels = self::of_voucher( $voucher );
		$allowed  = ! $channels || in_array( $channel, $channels, true );
		return (bool) apply_filters( 'vcap_redemption_channel_allowed', $allowed, $voucher, $channel, $channels );
	}
}
