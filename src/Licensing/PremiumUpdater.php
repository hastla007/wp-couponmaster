<?php
/**
 * Premium update integration with checksum verification.
 *
 * @package voucher-captain
 */

namespace VCAP\Licensing;

use VCAP\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License-authorized updates of the premium build (only registered there, see Plugin::boot()).
 *
 * - The update check runs in the background as well (WP-Cron has no current user) and is cached: 12 hours after a
 *   successful answer, 1 hour after a failure, 5 seconds timeout. The cache key contains the installed version, the
 *   license server, the activation and the license status of the signed snapshot, so activation, deactivation and
 *   status changes invalidate it; a forced check ("Check again", deleted update transient) bypasses it.
 * - Only packages of the license server's one-time download grant are offered (plugin header "Update URI" names the
 *   same host; any other offer for this plugin, e.g. from WordPress.org, is removed). Every download is checked
 *   against the SHA-256 of the signed update answer before WordPress installs it.
 * - "View details" (plugins_api) shows version, requirements and release notes from the license server.
 */
final class PremiumUpdater {
	public const CACHE         = 'vcap_update_check';
	public const SUCCESS_TTL   = 12 * HOUR_IN_SECONDS;
	public const FAILURE_TTL   = HOUR_IN_SECONDS;
	public const CHECK_TIMEOUT = 5;

	private $plugin_file;
	private $product_key;
	private $version;
	private $source_url;

	public function __construct() {
		$this->plugin_file = plugin_basename( VCAP_FILE );
		$this->product_key = 'voucher-captain';
		$this->version     = VCAP_VERSION;
		$this->source_url  = 'https://github.com/hastla007/wp-couponmaster';
	}

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'download_checked_package' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		// "Check again" and plugin installs delete the update transient: check the license server again too.
		add_action( 'delete_site_transient_update_plugins', array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		delete_site_transient( self::CACHE );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// Only the license server updates the premium build (never a same-named plugin from another source).
		unset( $transient->response[ $this->plugin_file ] );
		$update = $this->update_info();
		$entry  = array(
			'id'           => $this->update_uri(),
			'slug'         => $this->product_key,
			'plugin'       => $this->plugin_file,
			'new_version'  => $this->version,
			'url'          => $this->source_url,
			'package'      => '',
			'wplc_managed' => true,
		);
		if ( ! $update ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->plugin_file ] = (object) $entry;
			return $transient;
		}
		unset( $transient->no_update[ $this->plugin_file ] );
		$transient->response[ $this->plugin_file ] = (object) array_merge(
			$entry,
			array(
				'new_version'  => $update['version'],
				'package'      => $update['package'],
				'tested'       => $update['tested_wp'],
				'requires'     => $update['requires_wp'],
				'requires_php' => $update['requires_php'],
			)
		);
		return $transient;
	}

	/**
	 * Cached update offer of the license server, or null (none, not activated, server error).
	 *
	 * @return array{version:string,package:string,sha256:string,requires_php:string,requires_wp:string,tested_wp:string,release_notes:string}|null
	 */
	public function update_info( bool $fresh = false ): ?array {
		$client = Entitlements::client();
		if ( ! $client ) {
			return null;
		}
		$key    = $this->cache_key( $client );
		$cached = $fresh ? null : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && (int) ( $cached['until'] ?? 0 ) > time() ) {
			return is_array( $cached['update'] ?? null ) ? $cached['update'] : null;
		}
		$answer = $client->set_timeout( self::CHECK_TIMEOUT )->check_update();
		$update = null;
		$ttl    = self::SUCCESS_TTL;
		if ( is_wp_error( $answer ) ) {
			$ttl = self::FAILURE_TTL;
			if ( 'wplc_not_activated' !== $answer->get_error_code() ) {
				Logger::wp_error( 'Premium update check failed.', $answer );
			}
		} elseif ( ! empty( $answer['update_available'] ) ) {
			$update = $this->validated( $answer );
			if ( ! $update ) {
				$ttl = self::FAILURE_TTL;
				Logger::error( 'Premium update offer refused: the package is not a download grant of the license server or its SHA-256 is invalid.' );
			}
		}
		set_site_transient(
			self::CACHE,
			array(
				'key'    => $key,
				'until'  => time() + $ttl,
				'update' => $update,
			),
			$ttl
		);
		return $update;
	}

	/** Offer as it may be used: package on the license server (download grant) and a valid SHA-256. */
	private function validated( array $answer ): ?array {
		$package  = esc_url_raw( (string) ( $answer['package_url'] ?? '' ), array( 'https' ) );
		$checksum = strtolower( (string) ( $answer['sha256'] ?? '' ) );
		$version  = sanitize_text_field( (string) ( $answer['version'] ?? '' ) );
		if ( '' === $package || '' === $version || ! $this->is_license_package( $package ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return null;
		}
		return array(
			'version'       => $version,
			'package'       => $package,
			'sha256'        => $checksum,
			'requires_php'  => sanitize_text_field( (string) ( $answer['requires_php'] ?? '' ) ),
			'requires_wp'   => sanitize_text_field( (string) ( $answer['requires_wp'] ?? '' ) ),
			'tested_wp'     => sanitize_text_field( (string) ( $answer['tested_wp'] ?? '' ) ),
			'release_notes' => wp_kses_post( (string) ( $answer['release_notes'] ?? '' ) ),
		);
	}

	private function cache_key( LicenseClient $client ): string {
		$credentials = get_option( Entitlements::CREDENTIALS_OPTION, array() );
		$snapshot    = $client->cached_entitlements();
		return md5(
			implode(
				'|',
				array(
					$this->version,
					Entitlements::server_url(),
					is_array( $credentials ) ? (string) ( $credentials['activation_id'] ?? '' ) : '',
					is_array( $snapshot ) ? (string) ( $snapshot['license_status'] ?? '' ) : 'none',
				)
			)
		);
	}

	/** "View details" for the premium build: data of the license server's current release. */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || $this->product_key !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$update = $this->update_info();
		$notes  = $update ? $update['release_notes'] : '';
		return (object) array(
			'name'          => 'Voucher Captain',
			'slug'          => $this->product_key,
			'version'       => $update ? $update['version'] : $this->version,
			'author'        => 'CouponMaster',
			'homepage'      => $this->source_url,
			'requires'      => $update ? $update['requires_wp'] : '',
			'requires_php'  => $update ? $update['requires_php'] : '',
			'tested'        => $update ? $update['tested_wp'] : '',
			'external'      => true,
			'sections'      => array(
				'description' => esc_html__( 'Gift cards, vouchers and store credit for WooCommerce. Premium updates are delivered by the license server after the license check.', 'voucher-captain' ),
				'changelog'   => '' !== $notes ? $notes : esc_html__( 'No release notes are available for this version.', 'voucher-captain' ),
			),
			// No direct download link: the update runs through the one-time download grant and the checksum check.
			'download_link' => '',
		);
	}

	public function download_checked_package( $reply, $package, $upgrader, $hook_extra ) {
		if ( ! $this->is_license_package( (string) $package ) ) {
			return $reply;
		}
		$cached   = $this->update_info();
		$checksum = $cached && $cached['package'] === (string) $package ? $cached['sha256'] : '';
		if ( '' === $checksum ) {
			$refreshed = $this->refresh_package();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$package  = $refreshed['package'];
			$checksum = $refreshed['checksum'];
		}
		$result = $this->download_and_verify( (string) $package, $checksum );
		if ( is_wp_error( $result ) && 'wplc_download_redirect_invalid' === $result->get_error_code() ) {
			// The one-time grant of the cached offer was used or has expired: ask for a new one.
			$refreshed = $this->refresh_package();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$result = $this->download_and_verify( $refreshed['package'], $refreshed['checksum'] );
		}
		if ( is_wp_error( $result ) ) {
			Logger::wp_error( 'Premium update download stopped.', $result );
		}
		return $result;
	}

	private function refresh_package() {
		if ( ! Entitlements::client() ) {
			return new \WP_Error( 'wplc_update_not_configured', __( 'The license server URL is not configured.', 'voucher-captain' ) );
		}
		$update = $this->update_info( true );
		if ( ! $update ) {
			return new \WP_Error( 'wplc_update_unavailable', __( 'No authorized product update is available.', 'voucher-captain' ) );
		}
		return array( 'package' => $update['package'], 'checksum' => $update['sha256'] );
	}

	private function download_and_verify( string $package, string $checksum ) {
		if ( ! $this->is_license_package( $package ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return new \WP_Error( 'wplc_update_metadata_invalid', __( 'The update checksum metadata is invalid.', 'voucher-captain' ) );
		}
		$allow = LicenseClient::allow_pinned_host( $package );
		try {
			$probe = wp_safe_remote_get( $package, array( 'timeout' => 20, 'redirection' => 0 ) );
			if ( is_wp_error( $probe ) ) {
				return $probe;
			}
			$code = (int) wp_remote_retrieve_response_code( $probe );
			if ( 302 !== $code && 301 !== $code ) {
				return new \WP_Error( 'wplc_download_redirect_invalid', __( 'The license server did not return a package redirect.', 'voucher-captain' ) );
			}
			$location = esc_url_raw( (string) wp_remote_retrieve_header( $probe, 'location' ), array( 'https' ) );
			if ( ! $this->is_allowed_package_host( $location ) ) {
				return new \WP_Error( 'wplc_download_host_invalid', __( 'The update package host is not allowed.', 'voucher-captain' ) );
			}
			$tmp = wp_tempnam( 'wplc-update.zip' );
			if ( ! $tmp ) {
				return new \WP_Error( 'wplc_download_temp_failed', __( 'A temporary update file could not be created.', 'voucher-captain' ) );
			}
			$response = wp_safe_remote_get(
				$location,
				array( 'timeout' => 90, 'redirection' => 0, 'stream' => true, 'filename' => $tmp )
			);
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $allow, 10 );
			}
		}
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_file( $tmp ) ) {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return is_wp_error( $response ) ? $response : new \WP_Error( 'wplc_download_failed', __( 'The premium update could not be downloaded.', 'voucher-captain' ) );
		}
		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $checksum, strtolower( $actual ) ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'wplc_checksum_mismatch', __( 'The update package checksum did not match. Installation was stopped.', 'voucher-captain' ) );
		}
		return $tmp;
	}

	private function is_allowed_package_host( string $url ): bool {
		$parts   = wp_parse_url( $url );
		$host    = strtolower( (string) ( $parts['host'] ?? '' ) );
		$server  = wp_parse_url( Entitlements::server_url() );
		$allowed = apply_filters( 'wplc_client_update_hosts', array( strtolower( (string) ( $server['host'] ?? '' ) ) ), $this->product_key );
		$allowed = is_array( $allowed ) ? array_map( 'strtolower', array_map( 'sanitize_text_field', $allowed ) ) : array();
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && in_array( $host, $allowed, true );
	}

	/** A one-time download grant of the license server (HTTPS, same host, grant route). */
	private function is_license_package( string $url ): bool {
		$server  = wp_parse_url( Entitlements::server_url() );
		$package = wp_parse_url( $url );
		return ! empty( $server['host'] )
			&& 'https' === strtolower( (string) ( $package['scheme'] ?? '' ) )
			&& strtolower( (string) ( $server['host'] ) ) === strtolower( (string) ( $package['host'] ?? '' ) )
			&& false !== strpos( (string) ( $package['path'] ?? '' ), '/wplc/v1/updates/download/' );
	}

	/** Value of the plugin header "Update URI" (premium build), else the license server. */
	private function update_uri(): string {
		if ( function_exists( 'get_file_data' ) ) {
			$data = get_file_data( VCAP_FILE, array( 'uri' => 'Update URI' ) );
			if ( ! empty( $data['uri'] ) ) {
				return (string) $data['uri'];
			}
		}
		return Entitlements::server_url();
	}
}
