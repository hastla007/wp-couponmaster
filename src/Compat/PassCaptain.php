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

	/** Whether WP Pass Captain is active with the voucher bridge (contract v1). */
	public static function active(): bool {
		return interface_exists( self::INTERFACE );
	}

	/**
	 * Status of the integration for the settings page (HTML, escaped).
	 */
	/**
	 * Whether the active WP Pass Captain keeps tickets of a paid order reserved while Voucher Captain reports a
	 * gift card shortfall (versioned hold contract, Pass Captain 1.4+). Older versions issue the tickets anyway.
	 */
	public static function supports_hold_fulfilment(): bool {
		$constant = 'PassCaptain\\Integrations\\WooCommerce\\OrderAdapter::HOLD_FULFILMENT';
		return self::active() && defined( $constant ) && (int) constant( $constant ) >= 1;
	}

	public static function status_html(): string {
		if ( ! self::active() ) {
			return esc_html__( 'Not active – vouchers in the ticket checkout, ticket vouchers and the shared scanner are off. Voucher Captain works fully without it.', 'voucher-captain' );
		}
		$rows = array(
			array( true, __( 'Vouchers and gift cards pay in the ticket checkout (contract v1; tick "WP Pass Captain" under "Redeemable via" or leave all channels unticked).', 'voucher-captain' ) ),
			array( null !== PassCaptainTickets::service(), null !== PassCaptainTickets::service() ? __( 'Ticket vouchers: choose a ticket type or event in the product\'s voucher settings.', 'voucher-captain' ) : __( 'Ticket vouchers: not available – update WP Pass Captain.', 'voucher-captain' ) ),
			array( null !== PassCaptainScanner::service(), null !== PassCaptainScanner::service() ? __( 'Shared scanner: Vouchers → Scanner also checks in Pass Captain tickets.', 'voucher-captain' ) : __( 'Shared scanner: not available – update WP Pass Captain.', 'voucher-captain' ) ),
			array( self::supports_hold_fulfilment(), self::supports_hold_fulfilment() ? __( 'Gift card shortfall: tickets of the shop order wait for the review.', 'voucher-captain' ) : __( 'Gift card shortfall: this WP Pass Captain issues tickets anyway – update it.', 'voucher-captain' ) ),
		);
		$version = defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '';
		/* translators: %s: version */
		$html = '<strong>' . esc_html( '' !== $version ? sprintf( __( 'Active (version %s)', 'voucher-captain' ), $version ) : __( 'Active', 'voucher-captain' ) ) . '</strong>';
		foreach ( $rows as $row ) {
			$html .= '<br />' . ( $row[0] ? '✔ ' : '– ' ) . esc_html( $row[1] );
		}
		return $html . '<br /><a href="' . esc_url( admin_url( 'admin.php?page=wp-pass-captain' ) ) . '">' . esc_html__( 'Open WP Pass Captain', 'voucher-captain' ) . '</a>';
	}

	public function channels( array $channels ): array {
		return $channels + array( self::CHANNEL => __( 'WP Pass Captain (ticket checkout)', 'voucher-captain' ) );
	}

	public function channel_for_target( $channel, $target ) {
		return 0 === strpos( (string) $target, self::CHANNEL . ':' ) ? self::CHANNEL : $channel;
	}
}
