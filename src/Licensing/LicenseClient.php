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

	/**
	 * @param string $server_url WP License Captain site URL.
	 * @param string $product_key Stable product identifier.
	 * @param string $product_version Installed product version.
	 * @param string $option_key Product-specific option key.
	 */
	/** Context string of the server's Ed25519 response signature (WP License Captain docs/API-V1.md). */
	public const SIGNED_CONTEXT = 'wplc-response-v1';

	/** Most pinned keys and envelope signatures looked at (a key set is current + next, rarely more). */
	private const MAX_KEYS = 8;

	/** @var array{signature_ed25519:string,key_id:string} Server signature of the last verified response (kept with the cache). */
	private array $last_server_signature = array( 'signature_ed25519' => '', 'key_id' => '' );

	public function __construct( string $server_url, string $product_key, string $product_version, string $option_key ) {
		$this->server_url = untrailingslashit( esc_url_raw( $server_url, array( 'https' ) ) );
		$this->product_key = sanitize_key( $product_key );
		$this->product_version = sanitize_text_field( $product_version );
		$this->option_key = sanitize_key( $option_key );
	}

	/** Shorter timeout for background checks (update checks must never stall the admin or cron). */
	public function set_timeout( int $seconds ): self {
		$this->timeout = max( 1, $seconds );
		return $this;
	}

	/**
	 * Activate this site and persist only its installation credential.
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
		$installation_id = wp_generate_uuid4();
		$secret = bin2hex( random_bytes( 32 ) );
		$payload = array(
			'license_key' => strtoupper( trim( sanitize_text_field( $license_key ) ) ),
			'product_key' => $this->product_key,
			'installation_id' => $installation_id,
			'installation_secret' => $secret,
			'site_url' => home_url(),
			'environment' => in_array( $environment, array( 'production', 'staging', 'development' ), true ) ? $environment : 'production',
			'staging_parent_installation_id' => sanitize_text_field( $staging_parent_installation_id ),
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
		update_option( $this->option_key, array( 'installation_id' => $installation_id, 'installation_secret' => $secret, 'activation_id' => absint( $verified['activation_id'] ?? 0 ), 'server_url' => $this->server_url ), false );

		return $verified;
	}

	/**
	 * Fetch current signed feature rights.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_entitlements() {
		return $this->authenticated_post( '/wplc/v1/entitlements/check', array( 'product_version' => $this->product_version ) );
	}

	/**
	 * Fetch verified entitlements, using the signed local cache only during network/server outages.
	 *
	 * Cached rights expire at the earlier of the server expiry and fourteen days after receipt.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_entitlements() {
		$online = $this->check_entitlements();
		if ( ! is_wp_error( $online ) ) {
			if ( 'active' === ( $online['license_status'] ?? '' ) ) {
				$this->store_entitlements_cache( $online );
			} else {
				delete_option( $this->option_key . '_entitlements_cache' );
			}
			return $online;
		}

		if ( ! $this->is_temporary_server_error( $online ) ) {
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

	/** Store an HMAC-protected snapshot received from the already verified API envelope. */
	private function store_entitlements_cache( array $data ): void {
		$credentials = $this->credentials();
		$expires = strtotime( (string) ( $data['cache_expires_at'] ?? '' ) . ' UTC' );
		if ( is_wp_error( $credentials ) || ! $expires ) {
			return;
		}
		$now = time();
		$expires = min( $expires, $now + 14 * DAY_IN_SECONDS );
		if ( $expires <= $now ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}
		update_option(
			$this->option_key . '_entitlements_cache',
			array(
				'data' => $data,
				'cached_at' => $now,
				'expires_at' => $expires,
				'signature' => hash_hmac( 'sha256', $json, $credentials['installation_secret'] ),
				// The server's own signature (the one that matched a pinned key) travels with the snapshot: a pinned
				// build re-checks it offline.
				'signature_ed25519' => $this->last_server_signature['signature_ed25519'],
				'key_id' => $this->last_server_signature['key_id'],
			),
			false
		);
	}

	/** Return a cache only if its HMAC, timestamp, and hard 14-day limit validate. */
	private function read_entitlements_cache() {
		$cache = get_option( $this->option_key . '_entitlements_cache', array() );
		$credentials = $this->credentials();
		if ( ! is_array( $cache ) || ! is_array( $cache['data'] ?? null ) || is_wp_error( $credentials ) ) {
			return new \WP_Error( 'wplc_cache_unavailable', __( 'No verified offline entitlement cache is available.', 'voucher-captain' ) );
		}
		$cached_at = absint( $cache['cached_at'] ?? 0 );
		$expires_at = absint( $cache['expires_at'] ?? 0 );
		if ( ! $cached_at || ! $expires_at || time() >= $expires_at || $expires_at > $cached_at + 14 * DAY_IN_SECONDS ) {
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
		return $cache['data'];
	}

	/** Only transport failures and server-side 5xx responses may use cached rights. */
	private function is_temporary_server_error( \WP_Error $error ): bool {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'http_request_failed', 'http_request_timeout', 'connect_timeout', 'request_timeout' ), true ) ) {
			return true;
		}
		$data = $error->get_error_data();
		return is_array( $data ) && absint( $data['status'] ?? 0 ) >= 500;
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
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate() {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
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
			delete_option( $this->option_key );
			delete_option( $this->option_key . '_entitlements_cache' );
		}
		return $verified;
	}

	/**
	 * Sign and send an authenticated POST using the installation credential.
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
		$credentials = get_option( $this->option_key, array() );
		if ( ! is_array( $credentials ) || empty( $credentials['installation_id'] ) || empty( $credentials['installation_secret'] ) || empty( $credentials['activation_id'] ) ) {
			return new \WP_Error( 'wplc_not_activated', __( 'This product installation has not been activated.', 'voucher-captain' ) );
		}
		return $credentials;
	}
}
