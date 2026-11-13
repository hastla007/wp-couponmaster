<?php
namespace VCAP\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Fail-closed gate for premium features (config/premium-features.php, entitlement keys "voucher.{feature}").
 *
 * - Enforced only where the license is required: constant VCAP_LICENSE_REQUIRED (set in the premium build) or
 *   filter "vcap_license_required". Without it every feature stays available (free edition, development).
 * - The gate never calls the license server while a page loads: it reads the signed local entitlement snapshot
 *   (LicenseClient::cached_entitlements(), HMAC with the installation secret, at most 14 days old). The snapshot is
 *   refreshed twice a day in the background (REFRESH_HOOK) and whenever the license page is opened.
 * - Missing activation, no or expired snapshot, broken signature, inactive license, missing/false/zero right,
 *   foreign product key: denied.
 */
final class Entitlements {
	public const SERVER_OPTION      = 'vcap_wplc_server_url';
	public const CREDENTIALS_OPTION = 'vcap_wplc_credentials';
	public const REFRESH_HOOK       = 'vcap_license_refresh';

	/** @var array<string, bool> */
	private static array $memo = array();

	public static function required(): bool {
		return (bool) apply_filters( 'vcap_license_required', defined( 'VCAP_LICENSE_REQUIRED' ) && VCAP_LICENSE_REQUIRED );
	}

	/** @return array<string, string> feature => label */
	public static function features(): array {
		$file = dirname( __DIR__, 2 ) . '/config/premium-features.php';
		return (array) apply_filters( 'vcap_premium_features', is_readable( $file ) ? (array) include $file : array() );
	}

	public static function client(): ?LicenseClient {
		$server = (string) get_option( self::SERVER_OPTION, '' );
		return '' === $server ? null : new LicenseClient( $server, 'voucher-captain', defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '0', self::CREDENTIALS_OPTION );
	}

	/** Is the premium feature usable here? */
	public static function allows( string $feature ): bool {
		if ( ! self::required() || ! isset( self::features()[ $feature ] ) ) {
			return true;
		}
		if ( ! isset( self::$memo[ $feature ] ) ) {
			$client = self::client();
			$data   = $client ? $client->cached_entitlements() : null;
			self::$memo[ $feature ] = $client && is_array( $data ) && 'active' === ( $data['license_status'] ?? '' ) && $client->allows_entitlement( 'voucher.' . $feature, $data );
		}
		return (bool) apply_filters( 'vcap_premium_feature_allowed', self::$memo[ $feature ], $feature );
	}

	/** @return string[] premium features that are locked right now */
	public static function locked(): array {
		return array_values( array_filter( array_keys( self::features() ), static fn( $f ) => ! self::allows( (string) $f ) ) );
	}

	public static function reset(): void {
		self::$memo = array();
	}

	/** Background refresh of the signed snapshot (online check; outages keep the last snapshot until it expires). */
	public static function refresh(): void {
		$client = self::client();
		if ( $client ) {
			$client->get_entitlements();
			self::reset();
		}
	}

	public static function register(): void {
		add_action( self::REFRESH_HOOK, array( self::class, 'refresh' ) );
		if ( self::required() && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::REFRESH_HOOK );
		}
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function notice(): void {
		if ( ! self::required() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$locked = self::locked();
		if ( ! $locked ) {
			return;
		}
		$labels = array_map( static fn( $f ) => (string) self::features()[ $f ], $locked );
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: list of features */
				__( 'Voucher Captain premium features are switched off until the license is active: %s.', 'voucher-captain' ),
				implode( ', ', $labels )
			)
		) . ' <a href="' . esc_url( admin_url( 'options-general.php?page=vcap-license' ) ) . '">' . esc_html__( 'Activate license', 'voucher-captain' ) . '</a></p></div>';
	}
}
