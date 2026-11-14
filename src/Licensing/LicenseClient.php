<?php
/**
 * Reusable client for products managed by WP License Captain.
 *
 * @package WPLC
 */

namespace VCAP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation, authenticated entitlement checks, and premium updates.
 *
 * Product plugins should instantiate this client with a product-specific option
 * key and should gate paid features from the signed entitlement payload.
 */
final class LicenseClient {
	/** @var string */
	private $server_url;
	/** @var string */
	private $product_key;
	/** @var string */
	private $product_version;
	/** @var string */
	private $option_key;
	/** @var int Seconds to wait for the license server. */
	private $timeout = 20;
	/** @var string|int Where credentials and snapshot live: '' this site, 'network' (site option), or a blog id. */
	private $scope = '';

	/** Context string of the server's Ed25519 response signature (WP License Captain docs/API-V1.md). */
	public const SIGNED_CONTEXT = 'wplc-response-v1';

	/** Longest validity of an offline snapshot after the server's signed `server_time`. */
	public const SNAPSHOT_MAX_AGE = 14 * DAY_IN_SECONDS;

	/** How far the signed `server_time` may lie ahead of this site's clock (the server's request window). */
	public const CLOCK_SKEW = 300;

	/** Server errors that are temporary although they are 4xx (rate limit, replayed nonce, clock skew). */
	private const TEMPORARY_CODES = array( 'rate_limited', 'request_replayed', 'request_expired', 'replay_store_unavailable', 'rate_limit_store_unavailable', 'retry_later' );

	/** Server errors after which the installation credential itself is gone (released or revoked on the server). */
	private const CREDENTIAL_GONE_CODES = array( 'activation_not_found', 'installation_secret_invalid' );

	/** Most pinned keys and envelope signatures looked at (a key set is current + next, rarely more). */
	private const MAX_KEYS = 8;

	/** @var array{signature_ed25519:string,key_id:string} Server signature of the last verified response (kept with the cache). */
	private array $last_server_signature = array( 'signature_ed25519' => '', 'key_id' => '' );

	/**
	 * @param string     $server_url WP License Captain site URL.
	 * @param string     $product_key Stable product identifier.
	 * @param string     $product_version Installed product version.
	 * @param string     $option_key Product-specific option key.
	 * @param string|int $scope '' (this site), 'network' (multisite network option) or a blog id (read that site's
	 *                          activation, e.g. the main site's for network-wide updates).
	 */
	public function __construct( string $server_url, string $product_key, string $product_version, string $option_key, $scope = '' ) {
		$this->server_url = untrailingslashit( esc_url_raw( $server_url, array( 'https' ) ) );
		$this->product_key = sanitize_key( $product_key );
		$this->product_version = sanitize_text_field( $product_version );
		$this->option_key = sanitize_key( $option_key );
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		if ( $multisite && 'network' === $scope ) {
			$this->scope = 'network';
		} elseif ( $multisite && is_int( $scope ) && $scope > 0 && function_exists( 'get_current_blog_id' ) && get_current_blog_id() !== $scope ) {
			$this->scope = $scope;
		}
	}

	/** Shorter timeout for background checks (update checks must never stall the admin or cron). */
	public function set_timeout( int $seconds ): self {
		$this->timeout = max( 1, $seconds );
		return $this;
	}

	/**
	 * Activate this site and persist only its installation credential.
	 *
	 * Idempotent: the installation id and secret are kept (as "pending") before the request and sent again on the
	 * next attempt, so a timeout after the server created the activation, a refused signature or a retry never
	 * takes a second place of the license. A site that is activated already sends its own credential again – the
	 * server then only updates the site address (moved site) and never creates a new installation.
	 *
	 * @param string $license_key Customer-provided key.
	 * @param string $environment production, staging, or development.
	 * @param string $staging_parent_installation_id Optional production installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( string $license_key, string $environment = 'production', string $staging_parent_installation_id = '' ) {
		if ( '' === $this->server_url || '' === $this->product_key || '' === trim( $license_key ) ) {
			return new \WP_Error( 'wplc_configuration_invalid', __( 'License server settings or license key are missing.', 'voucher-captain' ) );
		}
		$environment = in_array( $environment, array( 'production', 'staging', 'development' ), true ) ? $environment : 'production';
		$parent      = strtolower( sanitize_text_field( $staging_parent_installation_id ) );
		$active      = $this->stored( '' );
		$pending     = $this->stored( '_pending' );
		$candidate   = null;
		$own         = false; // Whether this site's own activation is sent again (moved site).
		if ( $this->usable_pair( $active, $environment, $parent ) && ! empty( $active['activation_id'] ) ) {
			$candidate = $active;
			$own       = true;
		} elseif ( ! empty( $active['activation_id'] ) ) {
			// Never replace a working activation by a new one (a copy removes it locally first).
			return new \WP_Error( 'wplc_already_activated', __( 'This website is already activated. Deactivate it or remove the activation locally first.', 'voucher-captain' ) );
		} elseif ( $this->usable_pair( $pending, $environment, $parent ) ) {
			$candidate = $pending;
		}
		$fresh = null === $candidate;
		if ( $fresh ) {
			$candidate = $this->new_pending( $environment, $parent );
		}
		$verified = $this->send_activation( $license_key, $candidate );
		$retry    = $own ? array( 'activation_deactivated' ) : array( 'installation_conflict', 'activation_deactivated' );
		if ( ! $fresh && is_wp_error( $verified ) && in_array( $verified->get_error_code(), $retry, true ) ) {
			// The kept installation was released on the server (or a pending one belongs to another license): start
			// a new one.
			if ( $own ) {
				$this->forget( '' );
				$this->forget( '_entitlements_cache' );
			}
			$verified = $this->send_activation( $license_key, $this->new_pending( $environment, $parent ) );
		}
		return $verified;
	}

	/**
	 * @param array<string,mixed> $pair Installation id/secret (pending or active).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function send_activation( string $license_key, array $pair ) {
		$installation_id = (string) $pair['installation_id'];
		$secret          = (string) $pair['installation_secret'];
		$payload = array(
			'license_key' => strtoupper( trim( sanitize_text_field( $license_key ) ) ),
			'product_key' => $this->product_key,
			'installation_id' => $installation_id,
			'installation_secret' => $secret,
			'site_url' => $this->site_url(),
			'environment' => (string) $pair['environment'],
			'staging_parent_installation_id' => (string) $pair['staging_parent_installation_id'],
			'product_version' => $this->product_version,
			'wp_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
		);
		$response = $this->request( 'POST', '/wplc/v1/activations', $payload, $secret, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $secret, $installation_id );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		// The server is kept with the credentials: uninstall.php releases the site there without the plugin loaded.
		// The site address binds the credential to this website (a copy of the database is detected).
		$this->store(
			'',
			array(
				'installation_id' => $installation_id,
				'installation_secret' => $secret,
				'activation_id' => absint( $verified['activation_id'] ?? 0 ),
				'server_url' => $this->server_url,
				'site_url' => $this->site_url(),
				'environment' => (string) $pair['environment'],
				'staging_parent_installation_id' => (string) $pair['staging_parent_installation_id'],
			)
		);
		$this->forget( '_pending' );
		$this->forget( '_entitlements_cache' );
		$this->forget( '_copy_of' );

		return $verified;
	}

	/** Whether a stored pair may be sent again for this activation request. */
	private function usable_pair( $pair, string $environment, string $parent ): bool {
		return is_array( $pair ) && ! empty( $pair['installation_id'] ) && ! empty( $pair['installation_secret'] )
			&& $this->server_url === (string) ( $pair['server_url'] ?? $this->server_url )
			&& $environment === (string) ( $pair['environment'] ?? 'production' )
			&& $parent === (string) ( $pair['staging_parent_installation_id'] ?? '' );
	}

	/** New installation id and secret, kept as "pending" before they are sent. */
	private function new_pending( string $environment, string $parent ): array {
		$pending = array(
			'installation_id' => wp_generate_uuid4(),
			'installation_secret' => bin2hex( random_bytes( 32 ) ),
			'server_url' => $this->server_url,
			'environment' => $environment,
			'staging_parent_installation_id' => $parent,
		);
		$this->store( '_pending', $pending );
		return $pending;
	}

	/**
	 * Fetch current signed feature rights.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_entitlements() {
		$verified = $this->authenticated_post( '/wplc/v1/entitlements/check', array( 'product_version' => $this->product_version ) );
		if ( is_array( $verified ) && ! $this->same_site( (string) ( $verified['site_url'] ?? '' ) ) ) {
			return self::site_mismatch_error();
		}
		return $verified;
	}

	/**
	 * Fetch verified entitlements, using the signed local cache only during network/server outages.
	 *
	 * Cached rights expire at the earlier of the server expiry and fourteen days after the server's signed time.
	 * A refusal of the license server (released or unknown installation, inactive or unknown license, another
	 * website) deletes the snapshot at once, and for a released installation the credential too; only outages
	 * (network, 5xx, rate limit, replayed nonce, clock skew, unreadable answers) keep it.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_entitlements() {
		$online = $this->check_entitlements();
		if ( ! is_wp_error( $online ) ) {
			$credentials = $this->stored_credentials();
			if ( $credentials && empty( $credentials['site_url'] ) && is_string( $online['site_url'] ?? null ) && '' !== $online['site_url'] ) {
				// Activation from before the site binding: the server confirmed this site, keep it for offline checks.
				$credentials['site_url'] = $online['site_url'];
				$this->store( '', $credentials );
			}
			if ( 'active' === ( $online['license_status'] ?? '' ) ) {
				$this->store_entitlements_cache( $online );
			} else {
				$this->forget( '_entitlements_cache' );
			}
			$this->record_check( null, (string) ( $online['license_status'] ?? '' ) );
			return $online;
		}

		if ( 'wplc_not_activated' === $online->get_error_code() ) {
			return $online;
		}
		$this->record_check( $online );
		if ( self::is_rejection( $online ) ) {
			$this->forget( '_entitlements_cache' );
			if ( in_array( $online->get_error_code(), self::CREDENTIAL_GONE_CODES, true ) ) {
				$this->forget( '' );
			}
			return $online;
		}

		$cached = $this->read_entitlements_cache();
		return is_wp_error( $cached ) ? $online : $cached;
	}

	/**
	 * Verified local snapshot without contacting the server (premium gate on every request).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cached_entitlements() {
		return $this->read_entitlements_cache();
	}

	/**
	 * The stored activation (installation id, activation id, server, site; never used to unlock anything) or an
	 * empty array.
	 *
	 * @return array<string,mixed>
	 */
	public function stored_credentials(): array {
		$credentials = $this->stored( '' );
		return is_array( $credentials ) && ! empty( $credentials['activation_id'] ) ? $credentials : array();
	}

	/**
	 * Last license check: time, error code and message (empty on success) and the last license status the server
	 * confirmed.
	 *
	 * @return array{time:int,code:string,message:string,status:string,ok:bool,rejected:bool}
	 */
	public function last_check(): array {
		$check = $this->stored( '_last_check' );
		$check = is_array( $check ) ? $check : array();
		return array(
			'time'    => absint( $check['time'] ?? 0 ),
			'code'    => (string) ( $check['code'] ?? '' ),
			'message' => (string) ( $check['message'] ?? '' ),
			'status'  => (string) ( $check['status'] ?? '' ),
			'ok'      => '' === (string) ( $check['code'] ?? '' ) && ! empty( $check['time'] ),
			// The server refused the installation or license (not an outage).
			'rejected' => ! empty( $check['rejected'] ),
		);
	}

	private function record_check( ?\WP_Error $error, string $status = '' ): void {
		$previous = $this->last_check();
		$this->store(
			'_last_check',
			array(
				'time'    => time(),
				'code'    => $error ? (string) $error->get_error_code() : '',
				'message' => $error ? (string) $error->get_error_message() : '',
				'rejected' => $error && self::is_rejection( $error ),
				// An outage keeps the status the server confirmed last.
				'status'  => $error ? $previous['status'] : $status,
			)
		);
	}

	/**
	 * Remove the activation from this website only (no server call): for a copy of another website or when the
	 * server cannot be reached. The license server keeps the activation until it is released in the customer
	 * account.
	 */
	public function forget_locally(): void {
		$credentials = $this->stored_credentials();
		$foreign     = $this->foreign_site();
		foreach ( array( '', '_entitlements_cache', '_pending', '_last_check', '_copy_of' ) as $suffix ) {
			$this->forget( $suffix );
		}
		if ( '' !== $foreign && 'production' === (string) ( $credentials['environment'] ?? 'production' ) ) {
			// A copy of a live site may activate as its staging site (linked to the live installation).
			$this->store( '_copy_of', array( 'installation_id' => (string) $credentials['installation_id'], 'site_url' => $foreign ) );
		}
	}

	/**
	 * The live website this site was copied from (after its copied activation was removed locally), for a staging
	 * activation.
	 *
	 * @return array{installation_id:string,site_url:string}|array{}
	 */
	public function copy_of(): array {
		$copy = $this->stored( '_copy_of' );
		return is_array( $copy ) && ! empty( $copy['installation_id'] ) ? array( 'installation_id' => (string) $copy['installation_id'], 'site_url' => (string) ( $copy['site_url'] ?? '' ) ) : array();
	}

	/**
	 * Address the stored activation is bound to when it is not this website's address ('' = same website or not
	 * known, e.g. an activation made before the binding).
	 */
	public function foreign_site(): string {
		$bound = (string) ( $this->stored_credentials()['site_url'] ?? '' );
		return '' !== $bound && ! $this->same_site( $bound ) ? $bound : '';
	}

	/** This website's address as the license server knows it (the network's address for a network license). */
	public function site_url(): string {
		if ( 'network' === $this->scope && function_exists( 'network_home_url' ) ) {
			return (string) network_home_url();
		}
		if ( is_int( $this->scope ) && function_exists( 'get_home_url' ) ) {
			return (string) get_home_url( $this->scope );
		}
		return (string) home_url();
	}

	/** Whether a bound address names this website ('' = not bound: true). */
	private function same_site( string $bound ): bool {
		return '' === $bound || ( '' !== self::site_identity( $bound ) && self::site_identity( $bound ) === self::site_identity( $this->site_url() ) );
	}

	/**
	 * Identity of a site address as WP License Captain compares it (SiteUrl::same_site()): host (lower case,
	 * without a leading "www."), non-default port and path without trailing slash; the scheme is ignored, so a
	 * move to HTTPS keeps the activation. '' when invalid.
	 */
	public static function site_identity( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[] .' ) );
		if ( function_exists( 'idn_to_ascii' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) && preg_match( '/[^\x20-\x7e]/', $host ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = false === $ascii ? '' : strtolower( $ascii );
		}
		if ( '' === $host ) {
			return '';
		}
		$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$host = '[' . $host . ']';
		}
		$port = (int) ( $parts['port'] ?? 0 );
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		return $host . ( $port ? ':' . $port : '' ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	private static function site_mismatch_error(): \WP_Error {
		return new \WP_Error( 'site_mismatch', __( 'This activation belongs to another website (for example the original of a copied site). Activate this website separately.', 'voucher-captain' ), array( 'status' => 409 ) );
	}

	/** Store an HMAC-protected snapshot received from the already verified API envelope. */
	private function store_entitlements_cache( array $data ): void {
		$credentials = $this->credentials();
		$now         = time();
		$expires     = self::snapshot_expiry( $data, $now );
		if ( is_wp_error( $credentials ) || $expires <= $now ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}
		$this->store(
			'_entitlements_cache',
			array(
				'data' => $data,
				// Information only (age shown in Site Health): validity comes from the signed data alone.
				'cached_at' => $now,
				'expires_at' => $expires,
				'signature' => hash_hmac( 'sha256', $json, $credentials['installation_secret'] ),
				// The server's own signature (the one that matched a pinned key) travels with the snapshot: a pinned
				// build re-checks it offline.
				'signature_ed25519' => $this->last_server_signature['signature_ed25519'],
				'key_id' => $this->last_server_signature['key_id'],
			)
		);
	}

	/**
	 * End of validity of signed entitlement data: the earlier of the server's `cache_expires_at` and
	 * `server_time` + 14 days – both signed, so no local timestamp can extend it. 0 when either is missing or the
	 * server time lies in the future (clock set back).
	 *
	 * @param array<string,mixed> $data Verified entitlement data.
	 */
	public static function snapshot_expiry( array $data, ?int $now = null ): int {
		$now     = $now ?? time();
		$server  = self::utc_time( $data['server_time'] ?? null );
		$expires = self::utc_time( $data['cache_expires_at'] ?? null );
		if ( ! $server || ! $expires || $server > $now + self::CLOCK_SKEW ) {
			return 0;
		}
		return min( $expires, $server + self::SNAPSHOT_MAX_AGE );
	}

	/** Signed time of the license server in entitlement data (0 = none). */
	public static function snapshot_time( array $data ): int {
		return self::utc_time( $data['server_time'] ?? null );
	}

	private static function utc_time( $value ): int {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value ) ) {
			return 0;
		}
		$time = strtotime( $value . ' UTC' );
		return false === $time ? 0 : $time;
	}

	/** Return a cache only if its HMAC, server signature, signed validity and site binding hold. */
	private function read_entitlements_cache() {
		$cache = $this->stored( '_entitlements_cache' );
		$credentials = $this->credentials();
		if ( ! is_array( $cache ) || ! is_array( $cache['data'] ?? null ) || is_wp_error( $credentials ) ) {
			return new \WP_Error( 'wplc_cache_unavailable', __( 'No verified offline entitlement cache is available.', 'voucher-captain' ) );
		}
		if ( time() >= self::snapshot_expiry( $cache['data'] ) ) {
			return new \WP_Error( 'wplc_cache_expired', __( 'The offline entitlement cache has expired.', 'voucher-captain' ) );
		}
		$json = wp_json_encode( $cache['data'], JSON_UNESCAPED_SLASHES );
		$expected = hash_hmac( 'sha256', false === $json ? '' : $json, $credentials['installation_secret'] );
		if ( ! is_string( $cache['signature'] ?? null ) || ! hash_equals( $expected, $cache['signature'] ) ) {
			return new \WP_Error( 'wplc_cache_signature_invalid', __( 'The offline entitlement cache could not be verified.', 'voucher-captain' ) );
		}
		if ( ! self::server_signature_valid( false === $json ? '' : $json, (string) ( $cache['signature_ed25519'] ?? '' ), (string) $credentials['installation_id'], (string) ( $cache['key_id'] ?? '' ) ) ) {
			return new \WP_Error( 'wplc_cache_signature_invalid', __( 'The offline entitlement cache could not be verified.', 'voucher-captain' ) );
		}
		// A snapshot (and credential) copied into another website unlocks nothing there.
		if ( ! $this->same_site( (string) ( $cache['data']['site_url'] ?? '' ) ) || '' !== $this->foreign_site() ) {
			return self::site_mismatch_error();
		}
		return $cache['data'];
	}

	/**
	 * Whether an error only means the license server could not answer right now (network, 5xx, rate limit,
	 * replayed nonce, clock skew, an answer that is not the server's JSON – e.g. a firewall page). The signed
	 * snapshot stays in use until it expires.
	 */
	public static function is_temporary_error( \WP_Error $error ): bool {
		return ! self::is_rejection( $error );
	}

	/**
	 * Whether the license server refused this installation or license (a 4xx answer with its own error code, e.g.
	 * activation_not_found, installation_secret_invalid, license_not_found, license_inactive, site_mismatch).
	 */
	public static function is_rejection( \WP_Error $error ): bool {
		$code   = (string) $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		if ( $status < 400 || $status >= 500 || 429 === $status ) {
			return false;
		}
		// No JSON error of the license server (a proxy or firewall page) or a WordPress REST error (route missing).
		if ( 'wplc_http_error' === $code || 0 === strpos( $code, 'rest_' ) ) {
			return false;
		}
		return ! in_array( $code, self::TEMPORARY_CODES, true );
	}

	/**
	 * Check a feature flag in a response returned by check_entitlements().
	 *
	 * The caller must pass the verified response returned by check_entitlements().
	 * Missing, malformed, foreign-product, false, and zero-value entitlements deny access.
	 *
	 * @param string $key Stable namespaced entitlement identifier.
	 * @param array<string,mixed> $verified_entitlements Verified entitlement response data.
	 * @return bool
	 */
	public function allows_entitlement( string $key, array $verified_entitlements ): bool {
		$prefixes = array(
			'voucher-captain' => 'voucher.',
			'wp-pass-captain' => 'pass.',
			'affiliate-captain' => 'affiliate.',
		);
		$prefix = $prefixes[ $this->product_key ] ?? '';
		if ( '' === $prefix || 0 !== strpos( $key, $prefix ) ) {
			return false;
		}

		$features = $verified_entitlements['entitlements'] ?? $verified_entitlements;
		if ( ! is_array( $features ) || ! array_key_exists( $key, $features ) ) {
			return false;
		}

		$value = $features[ $key ];
		return true === $value || ( ( is_int( $value ) || is_float( $value ) ) && $value > 0 );
	}

	/**
	 * Check for a product update and receive a short-lived package URL.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_update() {
		return $this->authenticated_post(
			'/wplc/v1/updates/check',
			array( 'product_key' => $this->product_key, 'current_version' => $this->product_version )
		);
	}

	/**
	 * Deactivate this site and remove the local credential after confirmation.
	 *
	 * Refused for an activation that belongs to another website (a copy would release the original's place).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate() {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		if ( '' !== $this->foreign_site() ) {
			return self::site_mismatch_error();
		}
		$response = $this->request(
			'DELETE',
			'/wplc/v1/activations/' . absint( $credentials['activation_id'] ?? 0 ),
			array(),
			$credentials['installation_secret'],
			true,
			$credentials['installation_id']
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $credentials['installation_secret'], (string) $credentials['installation_id'] );
		if ( ! is_wp_error( $verified ) ) {
			$this->forget_locally();
		}
		return $verified;
	}

	/**
	 * Sign and send an authenticated POST using the installation credential.
	 *
	 * The body names this website (`site_url`): WP License Captain refuses a credential used from another website
	 * (409 site_mismatch). A credential bound to another address is refused here before any request.
	 *
	 * @param string $path REST route.
	 * @param array<string,mixed> $payload Request payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function authenticated_post( string $path, array $payload ) {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		if ( '' !== $this->foreign_site() ) {
			return self::site_mismatch_error();
		}
		$payload['site_url'] = $this->site_url();
		$response = $this->request( 'POST', $path, $payload, $credentials['installation_secret'], true, $credentials['installation_id'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$match    = null;
		$verified = self::verify_envelope( $response, $credentials['installation_secret'], (string) $credentials['installation_id'], $match );
		if ( is_wp_error( $verified ) ) {
			$this->last_server_signature = array( 'signature_ed25519' => '', 'key_id' => '' );
		} else {
			// Unpinned builds (development) keep the server's main signature as received.
			$this->last_server_signature = $match ?? array(
				'signature_ed25519' => is_string( $response['signature_ed25519'] ?? null ) ? $response['signature_ed25519'] : '',
				'key_id' => is_string( $response['key_id'] ?? null ) ? $response['key_id'] : '',
			);
		}
		return $verified;
	}

	/**
	 * Send a request to the license server.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array<string,mixed> $payload Data.
	 * @param string $secret Signing secret.
	 * @param bool $authenticated Whether to include authentication headers.
	 * @param string $installation_id Installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $route, array $payload, string $secret, bool $authenticated, string $installation_id = '' ) {
		$body = 'GET' === $method || ( 'DELETE' === $method && empty( $payload ) ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new \WP_Error( 'wplc_json_error', __( 'Could not encode the license request.', 'voucher-captain' ) );
		}
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $authenticated ) {
			$timestamp = (string) time();
			$nonce = bin2hex( random_bytes( 16 ) );
			$canonical = implode( "\n", array( $method, $route, $timestamp, $nonce, hash( 'sha256', $body ) ) );
			$headers['X-WPLC-Installation-Id'] = $installation_id;
			$headers['X-WPLC-Timestamp'] = $timestamp;
			$headers['X-WPLC-Nonce'] = $nonce;
			$headers['X-WPLC-Signature'] = hash_hmac( 'sha256', $canonical, $secret );
		}
		// wp_safe_remote_request(): no redirects to or requests against internal addresses. Only the license server
		// pinned at build time may resolve to a private address (e.g. a test server on 127.0.0.1).
		$allow = self::allow_pinned_host( $this->server_url );
		try {
			$http = wp_safe_remote_request(
				$this->server_url . '/wp-json' . $route,
				array(
					'method' => $method,
					'timeout' => $this->timeout,
					'redirection' => 0,
					'sslverify' => true,
					'headers' => $headers,
					'body' => $body,
				)
			);
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $allow, 10 );
			}
		}
		if ( is_wp_error( $http ) ) {
			return $http;
		}
		$code = (int) wp_remote_retrieve_response_code( $http );
		$data = json_decode( (string) wp_remote_retrieve_body( $http ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $data ) ? ( $data['code'] ?? 'wplc_http_error' ) : 'wplc_http_error';
			$message = is_array( $data ) ? ( $data['message'] ?? __( 'The license server returned an error.', 'voucher-captain' ) ) : __( 'The license server returned an invalid response.', 'voucher-captain' );
			return new \WP_Error( sanitize_key( (string) $error ), sanitize_text_field( (string) $message ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'wplc_response_invalid', __( 'The license server returned invalid JSON.', 'voucher-captain' ) );
		}
		if ( isset( $data['data'] ) && isset( $data['signature'] ) ) {
			return $data;
		}
		return new \WP_Error( 'wplc_response_invalid', __( 'The license server response is missing its signature envelope.', 'voucher-captain' ) );
	}

	/**
	 * Lets wp_safe_remote_*() reach the license server fixed at build time (VCAP_LICENSE_SERVER) even when its name
	 * resolves to a private address. Any other host keeps WordPress' protection against internal addresses.
	 *
	 * @param string $url URL that will be requested.
	 * @return callable|null The filter that was added (remove it after the request), or null.
	 */
	public static function allow_pinned_host( string $url ): ?callable {
		if ( ! defined( 'VCAP_LICENSE_SERVER' ) ) {
			return null;
		}
		$pinned = strtolower( (string) wp_parse_url( (string) VCAP_LICENSE_SERVER, PHP_URL_HOST ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $pinned || $pinned !== $host ) {
			return null;
		}
		$allow = static function ( $external, $requested ) use ( $pinned ) {
			return $external || strtolower( trim( (string) $requested, '.' ) ) === $pinned;
		};
		add_filter( 'http_request_host_is_external', $allow, 10, 2 );
		return $allow;
	}

	/**
	 * Verify the response before exposing entitlement data to product code: the HMAC with the installation secret
	 * and – when the build pins the server's public key(s) (VCAP_LICENSE_PUBLIC_KEY) – the server's Ed25519 signature
	 * of "wplc-response-v1\n{installation}\n{JSON}". Only the license server can produce the latter, so a server of
	 * one's own or written options cannot unlock anything. During a key rotation the envelope carries `signatures`
	 * (one per server key); any of them made with a pinned key is enough.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @param string $secret Installation secret.
	 * @param string $installation_id Installation UUID the data must be for.
	 * @param array{signature_ed25519:string,key_id:string}|null $match Set to the signature that matched a pinned key.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_envelope( array $envelope, string $secret, string $installation_id = '', ?array &$match = null ) {
		$json = wp_json_encode( $envelope['data'] ?? null, JSON_UNESCAPED_SLASHES );
		$json = false === $json ? '' : $json;
		$expected = hash_hmac( 'sha256', $json, $secret );
		if ( ! is_string( $envelope['signature'] ?? null ) || ! hash_equals( $expected, $envelope['signature'] ) ) {
			return new \WP_Error( 'wplc_signature_invalid', __( 'The license server response signature could not be verified.', 'voucher-captain' ) );
		}
		if ( array() !== self::pinned_public_keys() ) {
			$match = self::server_signature_match( $json, self::envelope_signatures( $envelope ), $installation_id );
			if ( null === $match ) {
				return new \WP_Error( 'wplc_server_signature_invalid', __( 'The response is not signed by the license server of this product.', 'voucher-captain' ) );
			}
		}
		return is_array( $envelope['data'] ?? null ) ? $envelope['data'] : array();
	}

	/**
	 * Server signatures of an envelope: the entries of `signatures` (key rotation: one per server key) and the main
	 * `signature_ed25519`/`key_id` pair.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @return list<array{signature_ed25519:string,key_id:string}>
	 */
	public static function envelope_signatures( array $envelope ): array {
		$candidates = array();
		foreach ( is_array( $envelope['signatures'] ?? null ) ? array_slice( $envelope['signatures'], 0, self::MAX_KEYS ) : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['signature_ed25519'] ?? null ) ) {
				$candidates[] = array(
					'signature_ed25519' => $entry['signature_ed25519'],
					'key_id' => is_string( $entry['key_id'] ?? null ) ? $entry['key_id'] : '',
				);
			}
		}
		if ( is_string( $envelope['signature_ed25519'] ?? null ) ) {
			$candidates[] = array(
				'signature_ed25519' => $envelope['signature_ed25519'],
				'key_id' => is_string( $envelope['key_id'] ?? null ) ? $envelope['key_id'] : '',
			);
		}
		return $candidates;
	}

	/** Key id of a raw Ed25519 public key: first 16 hex characters of its SHA-256 (as WP License Captain names it). */
	public static function key_id( string $raw_public_key ): string {
		return substr( hash( 'sha256', $raw_public_key ), 0, 16 );
	}

	/**
	 * Base64 Ed25519 public keys of the license server pinned by the build (empty = not pinned).
	 *
	 * VCAP_LICENSE_PUBLIC_KEY holds one key or a set (current and next key during a rotation) separated by commas or
	 * whitespace. A malformed key in the set must not silently disable the check: the whole set is then
	 * array( 'invalid' ), which matches nothing (fail-closed).
	 *
	 * @return list<string>
	 */
	public static function pinned_public_keys(): array {
		if ( ! defined( 'VCAP_LICENSE_PUBLIC_KEY' ) ) {
			return array();
		}
		$keys = preg_split( '/[\s,]+/', trim( (string) VCAP_LICENSE_PUBLIC_KEY ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $keys ) || array() === $keys || count( $keys ) > self::MAX_KEYS ) {
			return array( 'invalid' );
		}
		foreach ( $keys as $key ) {
			$raw = base64_decode( $key, true );
			if ( false === $raw || 32 !== strlen( $raw ) ) {
				return array( 'invalid' );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/** First pinned public key ('' = not pinned, 'invalid' = malformed set). */
	public static function pinned_public_key(): string {
		return self::pinned_public_keys()[0] ?? '';
	}

	/**
	 * Whether the server's Ed25519 signature is valid for one of the pinned keys – always true when the build pins
	 * no public key.
	 *
	 * @param string $json            JSON of the data.
	 * @param string $signature       Base64 signature.
	 * @param string $installation_id Installation UUID.
	 * @param string $key_id          Key id named by the server ('' = unknown: every pinned key is tried).
	 */
	public static function server_signature_valid( string $json, string $signature, string $installation_id, string $key_id = '' ): bool {
		if ( array() === self::pinned_public_keys() ) {
			return true;
		}
		return null !== self::server_signature_match(
			$json,
			array(
				array(
					'signature_ed25519' => $signature,
					'key_id' => $key_id,
				),
			),
			$installation_id
		);
	}

	/**
	 * The first server signature that a pinned key verifies. For each signature the pinned key whose key id the
	 * server names is tried first, then the other pinned keys (a missing or unknown key id is no reason to fail).
	 *
	 * @param string                                                 $json            JSON of the data.
	 * @param list<array{signature_ed25519:string,key_id:string}> $candidates      Signatures of the envelope.
	 * @param string                                                 $installation_id Installation UUID.
	 * @return array{signature_ed25519:string,key_id:string}|null The signature and the key id of the pinned key, or null.
	 */
	public static function server_signature_match( string $json, array $candidates, string $installation_id ): ?array {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$pinned = array();
		foreach ( self::pinned_public_keys() as $encoded ) {
			$raw = base64_decode( $encoded, true );
			if ( false === $raw || 32 !== strlen( $raw ) ) {
				return null;
			}
			$pinned[] = array( self::key_id( $raw ), $raw );
		}
		$message = self::SIGNED_CONTEXT . "\n" . strtolower( $installation_id ) . "\n" . $json;
		foreach ( array_slice( $candidates, 0, self::MAX_KEYS + 1 ) as $candidate ) {
			$sig = base64_decode( (string) ( $candidate['signature_ed25519'] ?? '' ), true );
			if ( false === $sig || 64 !== strlen( $sig ) ) {
				continue;
			}
			// The key the server names first, then the others (stable order otherwise).
			$named = strtolower( (string) ( $candidate['key_id'] ?? '' ) );
			$keys  = $pinned;
			usort(
				$keys,
				static function ( array $a, array $b ) use ( $named ): int {
					return (int) ( $b[0] === $named ) - (int) ( $a[0] === $named );
				}
			);
			foreach ( $keys as list( $id, $raw ) ) {
				try {
					if ( sodium_crypto_sign_verify_detached( $sig, $message, $raw ) ) {
						return array(
							'signature_ed25519' => (string) $candidate['signature_ed25519'],
							'key_id' => $id,
						);
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}
		return null;
	}

	/**
	 * Read credentials saved after activation.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	private function credentials() {
		$credentials = $this->stored( '' );
		if ( ! is_array( $credentials ) || empty( $credentials['installation_id'] ) || empty( $credentials['installation_secret'] ) || empty( $credentials['activation_id'] ) ) {
			return new \WP_Error( 'wplc_not_activated', __( 'This product installation has not been activated.', 'voucher-captain' ) );
		}
		return $credentials;
	}

	/**
	 * Stored value of this client (credentials '', '_pending', '_entitlements_cache', '_last_check'): an option of
	 * this site, of the network (network license) or of another site (main site's activation for updates).
	 *
	 * @return mixed
	 */
	private function stored( string $suffix ) {
		$key = $this->option_key . $suffix;
		if ( 'network' === $this->scope ) {
			return get_site_option( $key, array() );
		}
		if ( is_int( $this->scope ) ) {
			return get_blog_option( $this->scope, $key, array() );
		}
		return get_option( $key, array() );
	}

	/** Save a value (never autoloaded on single sites). */
	private function store( string $suffix, array $value ): void {
		$key = $this->option_key . $suffix;
		if ( 'network' === $this->scope ) {
			update_site_option( $key, $value );
		} elseif ( is_int( $this->scope ) ) {
			update_blog_option( $this->scope, $key, $value );
		} else {
			update_option( $key, $value, false );
		}
	}

	private function forget( string $suffix ): void {
		$key = $this->option_key . $suffix;
		if ( 'network' === $this->scope ) {
			delete_site_option( $key );
		} elseif ( is_int( $this->scope ) ) {
			delete_blog_option( $this->scope, $key );
		} else {
			delete_option( $key );
		}
	}
}
