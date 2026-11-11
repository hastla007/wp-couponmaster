<?php
namespace VCAP\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * WP Pass Captain (tickets): Voucher Captain registers itself as the voucher provider of Pass Captain's versioned
 * bridge ("wp_pass_captain_voucher_provider_v1"), so vouchers and gift cards can pay for tickets in Pass Captain's
 * own checkout. Only active when Pass Captain is installed; neither plugin depends on the other.
 *
 * Redemptions run through the Voucher Captain redemption API (target "passcaptain:…", channel "passcaptain"),
 * so the WooCommerce cart sees the same voucher state and a code can never be spent twice.
 */
final class PassCaptain {
	public const CHANNEL   = 'passcaptain';
	public const FILTER    = 'wp_pass_captain_voucher_provider_v1';
	public const INTERFACE = 'PassCaptain\\Integrations\\Vouchers\\ProviderInterface';

	public function register(): void {
		add_filter( self::FILTER, array( $this, 'provider' ), 10, 2 );
		add_filter( 'vcap_redemption_channels', array( $this, 'channels' ) );
		add_filter( 'vcap_redemption_channel_for_target', array( $this, 'channel_for_target' ), 10, 2 );
	}

	/**
	 * Hand Pass Captain our provider for contract version 1 (another provider registered earlier wins).
	 */
	public function provider( $provider, $version = 1 ) {
		if ( null !== $provider || 1 !== (int) $version || ! interface_exists( self::INTERFACE ) ) {
			return $provider;
		}
		return new PassCaptainProvider( vcap()->redemptions, vcap()->vouchers );
	}

	public function channels( array $channels ): array {
		return $channels + array( self::CHANNEL => __( 'WP Pass Captain (ticket checkout)', 'voucher-captain' ) );
	}

	public function channel_for_target( $channel, $target ) {
		return 0 === strpos( (string) $target, self::CHANNEL . ':' ) ? self::CHANNEL : $channel;
	}
}
