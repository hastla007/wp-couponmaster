<?php
/**
 * Plugin Name: WP License Captain – release registry (manifests)
 * Description: Announces premium releases to WP License Captain from immutable release manifests in the release storage.
 * Version: 1.0.0
 *
 * Must-use plugin for the licence server (copy to wp-content/mu-plugins/). Each published release is one JSON
 * manifest created by wp-license's tools/create-release-manifest.php from the release ZIP (product key, version,
 * HTTPS package URL, SHA-256, required PHP/WordPress, tested WordPress, release notes). The registry announces the
 * highest valid version per product through the "wplc_update_packages" filter; clients verify the SHA-256 of the
 * downloaded ZIP before installing it. Withdrawing a release = removing its manifest.
 *
 * Configuration (wp-config.php):
 * - WPLC_RELEASES_DIR  directory of the manifests (default: wp-content/wplc-releases), not web-readable needed;
 * - WPLC_RELEASE_HOSTS extra package hosts for "wplc_update_package_hosts" (array of host names; the licence
 *                      server's own host is allowed by WP License Captain itself).
 */

defined( 'ABSPATH' ) || exit;

/** @return array<string, array<string, string>> product key => package metadata of the newest valid manifest */
function wplc_release_registry_packages(): array {
	static $packages = null;
	if ( null !== $packages ) {
		return $packages;
	}
	$packages = array();
	$dir      = defined( 'WPLC_RELEASES_DIR' ) ? (string) WPLC_RELEASES_DIR : WP_CONTENT_DIR . '/wplc-releases';
	foreach ( glob( rtrim( $dir, '/' ) . '/*.json' ) ?: array() as $file ) {
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $manifest ) ) {
			continue;
		}
		$key     = (string) ( $manifest['product_key'] ?? '' );
		$version = (string) ( $manifest['version'] ?? '' );
		$url     = (string) ( $manifest['package_url'] ?? '' );
		$sha     = strtolower( (string) ( $manifest['sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $key ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $sha ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			continue; // Invalid manifests are never announced.
		}
		if ( isset( $packages[ $key ] ) && version_compare( $packages[ $key ]['version'], $version, '>=' ) ) {
			continue;
		}
		$packages[ $key ] = array(
			'version'       => $version,
			'package_url'   => $url,
			'sha256'        => $sha,
			'requires_php'  => (string) ( $manifest['requires_php'] ?? '' ),
			'requires_wp'   => (string) ( $manifest['requires_wp'] ?? '' ),
			'tested_wp'     => (string) ( $manifest['tested_wp'] ?? '' ),
			'release_notes' => (string) ( $manifest['release_notes'] ?? '' ),
		);
	}
	return $packages;
}

add_filter(
	'wplc_update_packages',
	static function ( $packages, $product_key ) {
		$known = wplc_release_registry_packages();
		if ( isset( $known[ $product_key ] ) ) {
			$packages                 = is_array( $packages ) ? $packages : array();
			$packages[ $product_key ] = $known[ $product_key ];
		}
		return $packages;
	},
	10,
	2
);

add_filter(
	'wplc_update_package_hosts',
	static function ( $hosts ) {
		$hosts = is_array( $hosts ) ? $hosts : array();
		if ( defined( 'WPLC_RELEASE_HOSTS' ) ) {
			foreach ( (array) WPLC_RELEASE_HOSTS as $host ) {
				$hosts[] = strtolower( (string) $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}
);
