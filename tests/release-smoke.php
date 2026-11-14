<?php
/**
 * Release smoke against the PRODUCTION licence server (workflow release-smoke.yml, RELEASE.md step 6). Runs on a
 * throw-away shop with the premium ZIP of the release draft:
 *
 *   wp eval-file tests/release-smoke.php installed <version> <https://licence-server>
 *       the premium build of <version>: licence server and "Update URI" fixed to that server, signing key pinned,
 *       every premium feature locked before activation
 *   wp eval-file tests/release-smoke.php activate <key file>
 *       activation with the smoke licence (key read from a file, never printed), signed snapshot, rights unlocked
 *   wp eval-file tests/release-smoke.php update <version>
 *       (previous premium release installed and activated) the server offers <version> with a one-time grant, the
 *       package installs (SHA-256 checked by the client) and keeps the activation
 *   wp eval-file tests/release-smoke.php deactivate
 *       releases the activation on the server again (always run, frees the smoke licence's site slot)
 */

defined( 'ABSPATH' ) || exit;

use VCAP\Licensing\Entitlements;

$mode     = (string) ( $args[0] ?? '' );
$basename = 'voucher-captain/voucher-captain.php';
$check    = static function ( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "::error::Release smoke: {$message}" . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) . "\n" );
		exit( 1 );
	}
	echo "  ok   {$message}\n";
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';

switch ( $mode ) {
	case 'installed':
		$version = (string) ( $args[1] ?? '' );
		$server  = untrailingslashit( (string) ( $args[2] ?? '' ) );
		$header  = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
		$check( $version === VCAP_VERSION && $version === $header['Version'], "Voucher Captain {$version} is installed", VCAP_VERSION );
		$check( 'premium' === Entitlements::edition() && Entitlements::required(), 'the ZIP is the premium edition' );
		$check( Entitlements::server_pinned() && $server === Entitlements::server_url(), "the build talks to the production licence server {$server}", Entitlements::server_url() );
		$check( wp_parse_url( $server, PHP_URL_HOST ) === wp_parse_url( (string) $header['UpdateURI'], PHP_URL_HOST ), 'the header Update URI names the licence server', $header['UpdateURI'] );
		$check( array() !== VCAP\Licensing\LicenseClient::pinned_public_keys() && ! in_array( 'invalid', VCAP\Licensing\LicenseClient::pinned_public_keys(), true ), 'the build pins the server signing key(s)' );
		$check( count( Entitlements::keys() ) === count( Entitlements::locked() ), 'every premium feature is locked before activation' );
		break;

	case 'activate':
		$key = trim( (string) file_get_contents( (string) ( $args[1] ?? '' ) ) );
		$check( '' !== $key, 'the smoke licence key is provided (secret VCAP_SMOKE_LICENSE_KEY)' );
		$result = Entitlements::client()->activate( $key ); // Like a customer: production; 'deactivate' frees the slot.
		$check( ! is_wp_error( $result ), 'activation against the production licence server (Ed25519-signed answer accepted)', is_wp_error( $result ) ? array( $result->get_error_code(), $result->get_error_message() ) : null );
		Entitlements::refresh();
		Entitlements::reset();
		$snapshot = get_option( Entitlements::CREDENTIALS_OPTION . '_entitlements_cache' );
		$check( is_array( $snapshot ) && 88 === strlen( (string) ( $snapshot['signature_ed25519'] ?? '' ) ), 'the signed offline snapshot is stored' );
		$check( count( Entitlements::locked() ) < count( Entitlements::keys() ), 'the smoke licence unlocks premium features', Entitlements::locked() );
		echo '  info unlocked: ' . implode( ', ', array_diff( Entitlements::keys(), Entitlements::locked() ) ) . "\n";
		break;

	case 'update':
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$version = (string) ( $args[1] ?? '' );
		$before  = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false )['Version'];
		wp_set_current_user( 0 ); // Like WP-Cron.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$offer = get_site_transient( 'update_plugins' )->response[ $basename ] ?? null;
		$check( is_object( $offer ) && $version === (string) $offer->new_version && ! empty( $offer->wplc_managed ), "the licence server offers {$version} to {$before} with a one-time download grant", $offer ? array( 'new_version' => $offer->new_version ?? null ) : null );
		wp_set_current_user( 1 );
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $basename );
		wp_clean_plugins_cache();
		$check( true === $result, 'the package is downloaded through the grant, its SHA-256 verified and installed', is_wp_error( $result ) ? $result->get_error_message() : $result );
		$reactivated = activate_plugin( $basename );
		$check( ! is_wp_error( $reactivated ), 'the updated plugin is reactivated' );
		$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false )['Version'];
		$check( $version === $after, "Voucher Captain {$version} runs after the update", $after );
		break;

	case 'deactivate':
		$client = Entitlements::client();
		if ( ! $client || false === get_option( Entitlements::CREDENTIALS_OPTION ) ) {
			echo "  info nothing to deactivate\n";
			break;
		}
		$result = $client->deactivate();
		$check( ! is_wp_error( $result ), 'the smoke activation is released on the licence server', is_wp_error( $result ) ? $result->get_error_code() : null );
		break;

	default:
		fwrite( STDERR, "Usage: wp eval-file tests/release-smoke.php installed|activate|update|deactivate …\n" );
		exit( 2 );
}
