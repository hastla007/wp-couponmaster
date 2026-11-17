<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Where a ledger entry came from (D8): admin screen, background task, REST API, ticket scanner, checkout, WP-CLI
 * or another front-end request. Stored with every history entry next to the user, so "who changed this, and how?"
 * can be answered. Code that knows better (e.g. the scanner endpoints) sets it with with().
 */
final class Source {
	public const ADMIN    = 'admin';
	public const CRON     = 'cron';
	public const REST     = 'rest';
	public const SCANNER  = 'scanner';
	public const CHECKOUT = 'checkout';
	public const CLI      = 'cli';
	public const WEB      = 'web';
	/** VC-1: a voucher command of an automatic caller (vcap_command_issue() and the other entry points). */
	public const WORKFLOW = 'workflow';

	/** REST routes of the ticket scanner (Tickets::rest_routes()). */
	private const SCANNER_ROUTES = array( '/vcap/v1/lookup', '/vcap/v1/checkin', '/vcap/v1/redeem' );

	/** @var string[] Sources set by with(), innermost last. */
	private static array $stack = array();

	/**
	 * Runs $work with the given source.
	 *
	 * @return mixed What $work returns.
	 */
	public static function with( string $source, callable $work ) {
		self::$stack[] = sanitize_key( $source );
		try {
			return $work();
		} finally {
			array_pop( self::$stack );
		}
	}

	/** Translated labels of the sources (history, CSV). */
	public static function labels(): array {
		return array(
			self::ADMIN    => __( 'Admin', 'voucher-captain' ),
			self::CRON     => __( 'Background task', 'voucher-captain' ),
			self::REST     => __( 'REST API', 'voucher-captain' ),
			self::SCANNER  => __( 'Scanner', 'voucher-captain' ),
			self::CHECKOUT => __( 'Checkout', 'voucher-captain' ),
			self::CLI      => __( 'WP-CLI', 'voucher-captain' ),
			self::WEB      => __( 'Website', 'voucher-captain' ),
			self::WORKFLOW => __( 'Workflow', 'voucher-captain' ),
		);
	}

	public static function current(): string {
		if ( self::$stack ) {
			return (string) end( self::$stack );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::CLI;
		}
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || did_action( 'action_scheduler_before_execute' ) > did_action( 'action_scheduler_after_execute' ) ) {
			return self::CRON;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::for_route( self::rest_route() );
		}
		if ( did_action( 'woocommerce_checkout_process' ) || did_action( 'woocommerce_before_pay_action' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return self::CHECKOUT;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return self::ADMIN;
		}
		return self::WEB;
	}

	/**
	 * Source of a REST route: the scanner routes (and the paths below them, e.g. "/vcap/v1/redeem/reverse"), the
	 * Store API (checkout) or any other REST route. M12-44: whole path segments are compared, so
	 * "/vcap/v1/redemptions" (the redemption API) is not taken for the scanner's "/vcap/v1/redeem".
	 */
	public static function for_route( string $route ): string {
		$route = '/' . trim( strtolower( $route ), '/' );
		foreach ( self::SCANNER_ROUTES as $prefix ) {
			if ( self::under( $route, $prefix ) ) {
				return self::SCANNER;
			}
		}
		return self::under( $route, '/wc/store' ) ? self::CHECKOUT : self::REST;
	}

	/** Whether $route is $prefix or a path below it (whole segments). */
	private static function under( string $route, string $prefix ): bool {
		return $route === $prefix || str_starts_with( $route, $prefix . '/' );
	}

	/** Route of the current REST request ('' when unknown). */
	private static function rest_route(): string {
		global $wp;
		if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ) {
			return '/' . ltrim( (string) $wp->query_vars['rest_route'], '/' );
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$pos    = strpos( $path, '/' . $prefix . '/' );
		return false === $pos ? '' : substr( $path, $pos + strlen( $prefix ) + 1 );
	}
}
