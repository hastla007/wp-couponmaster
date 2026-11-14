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
 * (REFRESH_HOOK, as WP-Cron event and as recurring Action Scheduler action), on an administrator's admin page load
 * once the snapshot is older than a day (short timeout, at most once an hour) and whenever the license page is
 * opened. A locked feature only stops what is new (configuration, sales, customer screens); vouchers, top-ups,
 * rewards and reservations sold earlier keep being processed.
 *
 * Multisite: when the plugin is network-activated the license belongs to the network (one activation, network
 * options, bound to the network's address, managed under Network Admin → Settings → Voucher Captain license). With
 * activation per site every site has its own activation; premium updates (network-wide) then use the main site's.
 */
final class Entitlements {
	public const SERVER_OPTION      = 'vcap_wplc_server_url';
	public const CREDENTIALS_OPTION = 'vcap_wplc_credentials';
	public const REFRESH_HOOK       = 'vcap_license_refresh';
	public const PAGE               = 'vcap-license';

	/** Product page for the upgrade from the free edition (a build may set VCAP_UPGRADE_URL). */
	public const PRODUCT_URL = 'https://github.com/hastla007/wp-couponmaster';

	/** A snapshot older than this is refreshed on an administrator's admin page load. */
	public const ADMIN_REFRESH_AGE = DAY_IN_SECONDS;
	/** From this snapshot age on administrators are warned that the license could not be checked. */
	public const WARN_AGE = 3 * DAY_IN_SECONDS;
	/** Background and admin refreshes are not repeated within this time (also after a failure). */
	private const RETRY_AFTER = HOUR_IN_SECONDS;

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
				return __( 'Campaign and partner reports', 'voucher-captain' );
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
		if ( ! self::is_premium() && self::DEVELOPMENT !== self::edition() ) {
			return '';
		}
		return (string) ( self::network() ? get_site_option( self::SERVER_OPTION, '' ) : get_option( self::SERVER_OPTION, '' ) );
	}

	/** Store the license server of an unpinned build (development) where the license lives. */
	public static function save_server_url( string $server ): void {
		if ( self::network() ) {
			update_site_option( self::SERVER_OPTION, $server );
		} else {
			update_option( self::SERVER_OPTION, $server, false );
		}
	}

	public static function server_pinned(): bool {
		return defined( 'VCAP_LICENSE_SERVER' ) && '' !== (string) VCAP_LICENSE_SERVER;
	}

	/** Whether the license belongs to the whole network (multisite with the plugin network-activated). */
	public static function network(): bool {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! defined( 'VCAP_FILE' ) ) {
			return false;
		}
		$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $network_plugins ) && isset( $network_plugins[ plugin_basename( VCAP_FILE ) ] );
	}

	public static function client(): ?LicenseClient {
		if ( self::is_free() ) {
			return null;
		}
		if ( self::network() ) {
			self::migrate_to_network();
		}
		$server = self::server_url();
		return '' === $server ? null : new LicenseClient( $server, 'voucher-captain', defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '0', self::CREDENTIALS_OPTION, self::network() ? 'network' : '' );
	}

	/** @var bool Whether migrate_to_network() ran in this request. */
	private static bool $migrated = false;

	/**
	 * Network activation of a plugin that was licensed on the main site before (earlier versions stored the license
	 * per site): the main site's activation becomes the network license once, so the network is not locked.
	 */
	private static function migrate_to_network(): void {
		if ( self::$migrated || ! function_exists( 'get_main_site_id' ) ) {
			return;
		}
		self::$migrated = true;
		if ( get_site_option( self::CREDENTIALS_OPTION . '_migrated' ) ) {
			return;
		}
		update_site_option( self::CREDENTIALS_OPTION . '_migrated', time() );
		$main        = (int) get_main_site_id();
		$credentials = get_blog_option( $main, self::CREDENTIALS_OPTION );
		if ( ! is_array( $credentials ) || empty( $credentials['activation_id'] ) || get_site_option( self::CREDENTIALS_OPTION ) ) {
			return;
		}
		foreach ( array( self::CREDENTIALS_OPTION, self::CREDENTIALS_OPTION . '_entitlements_cache', self::SERVER_OPTION ) as $option ) {
			$value = get_blog_option( $main, $option );
			if ( false !== $value ) {
				update_site_option( $option, $value );
				delete_blog_option( $main, $option );
			}
		}
	}

	/**
	 * Client of the activation that authorizes premium updates. WordPress keeps one update list for the whole
	 * network, so on a multisite every site must answer alike: the network license, or – with activation per
	 * site – the main site's activation.
	 */
	public static function update_client(): ?LicenseClient {
		if ( self::is_free() || ! function_exists( 'is_multisite' ) || ! is_multisite() || self::network() || ! function_exists( 'get_main_site_id' ) ) {
			return self::client();
		}
		$main = (int) get_main_site_id();
		// The network admin (main site) checks the download only where the plugin runs there: no offer otherwise.
		if ( ! in_array( plugin_basename( VCAP_FILE ), (array) get_blog_option( $main, 'active_plugins', array() ), true ) ) {
			return null;
		}
		$server = self::server_pinned() ? (string) VCAP_LICENSE_SERVER : (string) get_blog_option( $main, self::SERVER_OPTION, '' );
		return '' === $server ? null : new LicenseClient( $server, 'voucher-captain', defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '0', self::CREDENTIALS_OPTION, $main );
	}

	/** Capability to manage the license (super admins for a network license). */
	public static function capability(): string {
		return self::network() ? 'manage_network_options' : 'manage_options';
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
		if ( ! self::is_premium() ) {
			/* translators: %s: name of the premium feature */
			return sprintf( __( '%s is included in Voucher Captain Premium.', 'voucher-captain' ), $label );
		}
		if ( 'past_due' === self::last_status() ) {
			/* translators: %s: name of the premium feature */
			return sprintf( __( '%s is locked until the overdue license payment has been received.', 'voucher-captain' ), $label );
		}
		/* translators: %s: name of the premium feature */
		return sprintf( __( '%s needs an active Voucher Captain license.', 'voucher-captain' ), $label );
	}

	/** Admin hint for a locked feature (HTML, escaped): link to the license page (premium) or the upgrade (free). */
	public static function locked_notice( string $feature, bool $inline = true ): string {
		return '<div class="notice notice-info' . ( $inline ? ' inline' : '' ) . ' vcap-license-locked"><p>' . self::locked_html( $feature ) . '</p></div>';
	}

	/** Reason and link of a locked feature as escaped inline HTML (settings descriptions, notices). */
	public static function locked_html( string $feature ): string {
		$html = esc_html( self::locked_message( $feature ) );
		if ( self::is_premium() && current_user_can( self::capability() ) ) {
			$html .= ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Manage license', 'voucher-captain' ) . '</a>';
		} elseif ( self::is_free() ) {
			$html .= ' <a href="' . esc_url( self::upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Premium', 'voucher-captain' ) . '</a>';
		}
		return $html;
	}

	/** Where the free edition links for the upgrade. */
	public static function upgrade_url(): string {
		$url = defined( 'VCAP_UPGRADE_URL' ) ? esc_url_raw( (string) VCAP_UPGRADE_URL, array( 'https' ) ) : '';
		return '' !== $url ? $url : self::PRODUCT_URL;
	}

	public static function page_url(): string {
		return self::network() && function_exists( 'network_admin_url' ) ? network_admin_url( 'settings.php?page=' . self::PAGE ) : admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** License status the server confirmed last ('' = unknown). */
	public static function last_status(): string {
		$client = self::client();
		return $client ? $client->last_check()['status'] : '';
	}

	/**
	 * Online check of the signed snapshot (outages keep the last snapshot until it expires).
	 *
	 * @param int $timeout Seconds to wait for the license server (0 = the client's default).
	 */
	public static function refresh( int $timeout = 0 ): void {
		$client = self::client();
		if ( ! $client ) {
			return;
		}
		if ( $timeout > 0 ) {
			$client->set_timeout( $timeout );
		}
		$result = $client->get_entitlements();
		if ( is_wp_error( $result ) ) {
			Logger::wp_error( 'License check failed.', $result );
		} elseif ( 'active' !== ( $result['license_status'] ?? '' ) ) {
			Logger::warning( 'License is not active: ' . sanitize_key( (string) ( $result['license_status'] ?? 'unknown' ) ) . '. Premium features are locked.' );
		}
		self::reset();
	}

	/**
	 * Background refresh (WP-Cron event and Action Scheduler action share the hook): skipped when the license was
	 * checked within the last hour, so the two schedules and the sites of a network do not ask twice.
	 */
	public static function scheduled_refresh(): void {
		$client = self::client();
		if ( ! $client || array() === $client->stored_credentials() || $client->last_check()['time'] > time() - self::RETRY_AFTER ) {
			return;
		}
		self::refresh();
	}

	/**
	 * Fallback when neither WP-Cron nor the Action Scheduler runs: an administrator's admin page load refreshes a
	 * snapshot older than a day (5 seconds timeout, at most once an hour, never in AJAX requests).
	 */
	public static function maybe_refresh(): void {
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! current_user_can( self::capability() ) ) {
			return;
		}
		$client = self::client();
		if ( ! $client || array() === $client->stored_credentials() || $client->last_check()['time'] > time() - self::RETRY_AFTER ) {
			return;
		}
		$snapshot = $client->cached_entitlements();
		if ( is_array( $snapshot ) && LicenseClient::snapshot_time( $snapshot ) > time() - self::ADMIN_REFRESH_AGE ) {
			return;
		}
		self::refresh( 5 );
	}

	/** Recurring Action Scheduler action (Plugin::ensure_schedules(), once a day; network license: main site). */
	public static function schedule_refresh(): void {
		if ( ! self::is_premium() || ! self::schedules_here() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::REFRESH_HOOK, array(), 'vcap' ) ) {
			as_schedule_recurring_action( time() + 600, 12 * HOUR_IN_SECONDS, self::REFRESH_HOOK, array(), 'vcap' );
		}
	}

	/** A network license is refreshed by the main site's schedules only. */
	private static function schedules_here(): bool {
		return ! self::network() || ! function_exists( 'is_main_site' ) || is_main_site();
	}

	public static function register(): void {
		if ( ! self::is_premium() ) {
			return;
		}
		add_action( self::REFRESH_HOOK, array( self::class, 'scheduled_refresh' ) );
		if ( self::schedules_here() && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::REFRESH_HOOK );
		}
		add_action( 'admin_init', array( self::class, 'maybe_refresh' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		if ( self::network() ) {
			add_action( 'network_admin_notices', array( self::class, 'notice' ) );
		}
	}

	/**
	 * Check state for warnings: last check, snapshot time and the date the premium features lock.
	 *
	 * @return array{activated:bool,valid:bool,checked:int,locks_at:int,error:string}
	 */
	public static function check_state(): array {
		$client   = self::client();
		$state    = array( 'activated' => false, 'valid' => false, 'checked' => 0, 'locks_at' => 0, 'error' => '' );
		if ( ! $client || array() === $client->stored_credentials() ) {
			return $state;
		}
		$snapshot = $client->cached_entitlements();
		$last     = $client->last_check();
		$state['activated'] = true;
		$state['error']     = $last['ok'] ? '' : self::error_hint( $last['code'], $last['message'], $last['rejected'] );
		if ( is_array( $snapshot ) ) {
			$state['valid']    = true;
			$state['checked']  = LicenseClient::snapshot_time( $snapshot );
			$state['locks_at'] = LicenseClient::snapshot_expiry( $snapshot );
		} elseif ( is_wp_error( $snapshot ) && '' === $state['error'] ) {
			$state['error'] = self::error_hint( $snapshot->get_error_code(), $snapshot->get_error_message() );
		}
		return $state;
	}

	/** Readable cause of a failed license check, by its error code. */
	public static function error_hint( string $code, string $message = '', bool $rejected = false ): string {
		if ( '' === $code ) {
			return '';
		}
		if ( 'request_expired' === $code ) {
			return __( 'The clock of this server differs from the license server\'s clock; check the server time.', 'voucher-captain' );
		}
		if ( in_array( $code, array( 'wplc_signature_invalid', 'wplc_server_signature_invalid', 'wplc_cache_signature_invalid' ), true ) ) {
			return __( 'The answer of the license server could not be verified.', 'voucher-captain' );
		}
		if ( in_array( $code, array( 'wplc_http_error', 'wplc_response_invalid' ), true ) || 0 === strpos( $code, 'rest_' ) ) {
			return __( 'The answer did not come from the license server (for example a firewall or maintenance page).', 'voucher-captain' );
		}
		if ( 'site_mismatch' === $code ) {
			return __( 'The activation belongs to another website address.', 'voucher-captain' );
		}
		if ( 'wplc_cache_expired' === $code || 'wplc_cache_unavailable' === $code ) {
			return __( 'No current license confirmation is available.', 'voucher-captain' );
		}
		if ( $rejected && '' !== $message ) {
			return $message; // The license server's own explanation (refused activation or license).
		}
		return __( 'The license server could not be reached.', 'voucher-captain' );
	}

	/** Date and time in the site's format. */
	private static function date( int $time ): string {
		return wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $time );
	}

	/** Admin warning before the snapshot expires (P10) and the hint about locked features. */
	public static function notice(): void {
		if ( ! self::is_premium() || ! current_user_can( self::capability() ) ) {
			return;
		}
		$state = self::check_state();
		if ( $state['valid'] && $state['checked'] && $state['checked'] < time() - self::WARN_AGE ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( self::overdue_text( $state ) ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Check license now', 'voucher-captain' ) . '</a></p></div>';
		}
		$locked = self::locked();
		if ( ! $locked ) {
			return;
		}
		$labels = array_map( array( self::class, 'label' ), $locked );
		$text   = 'past_due' === self::last_status()
			/* translators: %s: list of features */
			? __( 'Voucher Captain premium features are switched off until the overdue license payment has been received: %s. Vouchers, top-ups and rewards sold earlier keep working.', 'voucher-captain' )
			/* translators: %s: list of features */
			: __( 'Voucher Captain premium features are switched off until the license is active: %s. Vouchers, top-ups and rewards sold earlier keep working.', 'voucher-captain' );
		$link   = $state['activated'] ? __( 'Manage license', 'voucher-captain' ) : __( 'Activate license', 'voucher-captain' );
		$cause  = $state['activated'] && ! $state['valid'] && '' !== $state['error'] ? ' ' . $state['error'] : '';
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( $text, implode( ', ', $labels ) ) . $cause ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html( $link ) . '</a></p></div>';
	}

	/**
	 * "Not checked since …, locked on …" (notice and Site Health).
	 *
	 * @param array{checked:int,locks_at:int,error:string} $state check_state().
	 */
	public static function overdue_text( array $state ): string {
		return sprintf(
			/* translators: 1: date of the last successful license check, 2: cause, 3: date the premium features are switched off */
			__( 'The Voucher Captain license has not been confirmed since %1$s. %2$s Premium features will be switched off on %3$s unless the license can be checked by then.', 'voucher-captain' ),
			self::date( $state['checked'] ),
			'' !== $state['error'] ? $state['error'] : __( 'The scheduled license check has not run.', 'voucher-captain' ),
			self::date( $state['locks_at'] )
		);
	}

	/**
	 * Site Health result of the license check (premium edition): good, overdue (older than a day), critical (no
	 * valid snapshot – premium features locked) or not activated.
	 *
	 * @return array{status:string,label:string,description:string}|null Null outside the premium edition.
	 */
	public static function health(): ?array {
		if ( ! self::is_premium() ) {
			return null;
		}
		$state = self::check_state();
		if ( ! $state['activated'] ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'The Voucher Captain license is not activated', 'voucher-captain' ),
				'description' => __( 'Premium features stay switched off until the license is activated.', 'voucher-captain' ),
			);
		}
		if ( ! $state['valid'] ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The Voucher Captain license could not be confirmed', 'voucher-captain' ),
				'description' => trim( __( 'Premium features are switched off until the license is checked successfully.', 'voucher-captain' ) . ' ' . $state['error'] ),
			);
		}
		if ( $state['checked'] < time() - self::ADMIN_REFRESH_AGE ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'The Voucher Captain license check is overdue', 'voucher-captain' ),
				'description' => self::overdue_text( $state ) . ' ' . __( 'Check that WP-Cron or the Action Scheduler runs and that this server can reach the license server.', 'voucher-captain' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The Voucher Captain license is checked regularly', 'voucher-captain' ),
			/* translators: %s: date and time of the last successful license check */
			'description' => sprintf( __( 'Last confirmed by the license server on %s.', 'voucher-captain' ), self::date( $state['checked'] ) ),
		);
	}
}
