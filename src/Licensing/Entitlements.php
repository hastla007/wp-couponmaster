<?php
namespace VCAP\Licensing;

use VCAP\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Edition and fail-closed gate for premium features (config/premium-features.php, entitlement keys "voucher.{feature}").
 *
 * Editions (set by bin/build.sh, see LICENSING.md):
 * - "free" (VCAP_EDITION 'free'): premium features are permanently off for new configuration, new sales and customer
 *   screens. No license page, no premium updater, no license server contact.
 * - "premium" (VCAP_EDITION 'premium' and VCAP_LICENSE_REQUIRED): a feature is on while the signed local entitlement
 *   snapshot grants it (LicenseClient::cached_entitlements(), HMAC with the installation secret, at most 14 days old).
 *   The license server is fixed at build time (VCAP_LICENSE_SERVER). No filter can unlock a feature.
 * - "development" (a checkout without the build constants): every feature is on. Tests can switch the gate on with
 *   the filter "vcap_license_required" (it can only lock, never unlock).
 *
 * The gate never calls the license server while a page loads; the snapshot is refreshed twice a day in the background
 * (REFRESH_HOOK) and whenever the license page is opened. A locked feature only stops what is new (configuration,
 * sales, customer screens); vouchers, top-ups, rewards and reservations sold earlier keep being processed.
 */
final class Entitlements {
	public const SERVER_OPTION      = 'vcap_wplc_server_url';
	public const CREDENTIALS_OPTION = 'vcap_wplc_credentials';
	public const REFRESH_HOOK       = 'vcap_license_refresh';
	public const PAGE               = 'vcap-license';

	public const FREE        = 'free';
	public const PREMIUM     = 'premium';
	public const DEVELOPMENT = 'development';

	/** @var array<string, bool> */
	private static array $memo = array();

	/** Edition of this build: free, premium or development (no build constants). */
	public static function edition(): string {
		if ( defined( 'VCAP_LICENSE_REQUIRED' ) && VCAP_LICENSE_REQUIRED ) {
			return self::PREMIUM;
		}
		if ( defined( 'VCAP_EDITION' ) ) {
			// An unknown value never unlocks anything: it counts as the free edition.
			return self::PREMIUM === VCAP_EDITION ? self::PREMIUM : self::FREE;
		}
		return self::DEVELOPMENT;
	}

	public static function is_premium(): bool {
		return self::PREMIUM === self::edition();
	}

	public static function is_free(): bool {
		return self::FREE === self::edition();
	}

	/** Whether premium features need an active license here. */
	public static function required(): bool {
		switch ( self::edition() ) {
			case self::PREMIUM:
				return true;
			case self::FREE:
				return false;
			default:
				// Development only (tests): the filter can switch the gate on, never off.
				return (bool) apply_filters( 'vcap_license_required', false );
		}
	}

	/** @var string[]|null */
	private static ?array $keys = null;

	/** @return string[] keys of the premium features (no translation: safe before "init"). */
	public static function keys(): array {
		if ( null === self::$keys ) {
			$file       = dirname( __DIR__, 2 ) . '/config/premium-features.php';
			self::$keys = array_values( array_map( 'strval', is_readable( $file ) ? (array) include $file : array() ) );
		}
		return self::$keys;
	}

	/** @return array<string, string> feature => translated label (for screens, after "init") */
	public static function features(): array {
		$list = array();
		foreach ( self::keys() as $feature ) {
			$list[ $feature ] = self::label( $feature );
		}
		return $list;
	}

	/** Translated name of a premium feature. */
	public static function label( string $feature ): string {
		switch ( $feature ) {
			case 'promotions':
				return __( 'Promotions (rule-based discounts and bonus vouchers)', 'voucher-captain' );
			case 'referrals':
				return __( 'Refer a friend', 'voucher-captain' );
			case 'topup':
				return __( 'Gift card top-up', 'voucher-captain' );
			case 'reports':
				return __( 'Reports, liability and booking journal', 'voucher-captain' );
			case 'passcaptain':
				return __( 'WP Pass Captain integration (ticket vouchers, shared scanner)', 'voucher-captain' );
		}
		return $feature;
	}

	/** License server of this build: fixed in the premium build (VCAP_LICENSE_SERVER), else the stored option. */
	public static function server_url(): string {
		if ( self::server_pinned() ) {
			return (string) VCAP_LICENSE_SERVER;
		}
		return self::is_premium() || self::DEVELOPMENT === self::edition() ? (string) get_option( self::SERVER_OPTION, '' ) : '';
	}

	public static function server_pinned(): bool {
		return defined( 'VCAP_LICENSE_SERVER' ) && '' !== (string) VCAP_LICENSE_SERVER;
	}

	public static function client(): ?LicenseClient {
		if ( self::is_free() ) {
			return null;
		}
		$server = self::server_url();
		return '' === $server ? null : new LicenseClient( $server, 'voucher-captain', defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '0', self::CREDENTIALS_OPTION );
	}

	/** Is the premium feature usable here (new configuration, new sales, customer screens)? */
	public static function allows( string $feature ): bool {
		if ( ! in_array( $feature, self::keys(), true ) ) {
			return true;
		}
		if ( self::is_free() ) {
			return false;
		}
		if ( ! self::required() ) {
			return true;
		}
		if ( ! isset( self::$memo[ $feature ] ) ) {
			$client = self::client();
			$data   = $client ? $client->cached_entitlements() : null;
			self::$memo[ $feature ] = $client && is_array( $data ) && 'active' === ( $data['license_status'] ?? '' ) && $client->allows_entitlement( 'voucher.' . $feature, $data );
		}
		return self::$memo[ $feature ];
	}

	/** @return string[] premium features that are locked right now */
	public static function locked(): array {
		return array_values( array_filter( self::keys(), static fn( $f ) => ! self::allows( $f ) ) );
	}

	public static function reset(): void {
		self::$memo = array();
	}

	/** Why a feature is not available (plain text). */
	public static function locked_message( string $feature ): string {
		$label = self::label( $feature );
		return self::is_premium()
			/* translators: %s: name of the premium feature */
			? sprintf( __( '%s needs an active Voucher Captain license.', 'voucher-captain' ), $label )
			/* translators: %s: name of the premium feature */
			: sprintf( __( '%s is included in Voucher Captain Premium.', 'voucher-captain' ), $label );
	}

	/** Admin hint for a locked feature (HTML, escaped), with a link to the license page in the premium edition. */
	public static function locked_notice( string $feature, bool $inline = true ): string {
		$html = esc_html( self::locked_message( $feature ) );
		if ( self::is_premium() && current_user_can( 'manage_options' ) ) {
			$html .= ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Manage license', 'voucher-captain' ) . '</a>';
		}
		return '<div class="notice notice-info' . ( $inline ? ' inline' : '' ) . ' vcap-license-locked"><p>' . $html . '</p></div>';
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Background refresh of the signed snapshot (online check; outages keep the last snapshot until it expires). */
	public static function refresh(): void {
		$client = self::client();
		if ( ! $client ) {
			return;
		}
		$result = $client->get_entitlements();
		if ( is_wp_error( $result ) ) {
			Logger::wp_error( 'License check failed.', $result );
		} elseif ( 'active' !== ( $result['license_status'] ?? '' ) ) {
			Logger::warning( 'License is not active: ' . sanitize_key( (string) ( $result['license_status'] ?? 'unknown' ) ) . '. Premium features are locked.' );
		}
		self::reset();
	}

	public static function register(): void {
		if ( ! self::is_premium() ) {
			return;
		}
		add_action( self::REFRESH_HOOK, array( self::class, 'refresh' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::REFRESH_HOOK );
		}
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function notice(): void {
		if ( ! self::is_premium() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$locked = self::locked();
		if ( ! $locked ) {
			return;
		}
		$labels = array_map( array( self::class, 'label' ), $locked );
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: list of features */
				__( 'Voucher Captain premium features are switched off until the license is active: %s. Vouchers, top-ups and rewards sold earlier keep working.', 'voucher-captain' ),
				implode( ', ', $labels )
			)
		) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Activate license', 'voucher-captain' ) . '</a></p></div>';
	}
}
