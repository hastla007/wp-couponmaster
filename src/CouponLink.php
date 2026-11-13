<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Shareable coupon links such as https://shop.example/?gutschein=SUMMER10 (opt-in, off by default).
 *
 * The code is taken from the URL once, kept in the WooCommerce session and applied through WooCommerce's own
 * coupon validation as soon as the cart has items. The parameter is removed with a redirect, so reloading or
 * sharing the resulting page does not apply the code again, and a coupon the customer removed stays removed.
 */
final class CouponLink {
	public const SESSION_KEY = 'vcap_pending_coupon';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		// Rate limit of WooCommerce's own coupon entry (cart form, AJAX, Store API): registered with the other
		// code entry points, independently of the link setting.
		( new CouponGuard() )->register();
		add_action( 'wp_loaded', array( $this, 'handle_request' ), 30 );
		add_action( 'wp_loaded', array( $this, 'no_cache_while_pending' ), 40 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply_pending' ), 30, 0 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'apply_pending' ), 30, 0 );
		add_action( 'template_redirect', array( $this, 'waiting_notice_on_cart' ), 20 );
	}

	/** The code remembered for this visitor ('' when none). */
	private static function pending(): string {
		return function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( self::SESSION_KEY, '' ) : '';
	}

	/**
	 * While a code waits in the session, every page is personal: it is kept out of full-page caches before any
	 * output (the cart is still empty, so cache plugins see no cart cookie that would make them skip the page).
	 */
	public function no_cache_while_pending(): void {
		if ( '' !== self::pending() && ! is_admin() ) {
			Shortcodes::no_cache();
		}
	}

	/**
	 * Cart and checkout say that a code waits for products – only there, and with the code masked (last four
	 * characters): other pages may still end up in a cache that ignores the no-cache signals.
	 */
	public function waiting_notice_on_cart(): void {
		$code = self::pending();
		if ( '' === $code || ! WC()->cart || ! WC()->cart->is_empty() || ! ( is_cart() || is_checkout() ) ) {
			return;
		}
		Shortcodes::no_cache();
		if ( ! wc_has_notice( $this->waiting_notice( $code ), 'notice' ) ) {
			wc_add_notice( $this->waiting_notice( $code ), 'notice' );
		}
	}

	public function enabled(): bool {
		return $this->settings->yes( 'vcap_coupon_link' );
	}

	public function param(): string {
		$param = sanitize_key( (string) $this->settings->get( 'vcap_coupon_link_param' ) );
		return '' !== $param ? $param : 'gutschein';
	}

	/**
	 * Frontend request with the link parameter: remember the code, then redirect without it.
	 */
	public function handle_request(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! $this->enabled() ) {
			return;
		}
		$param = $this->param();
		if ( ! isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Public link, only stores a code for WooCommerce validation.
			return;
		}
		$this->capture( (string) wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$this->apply_pending();
		// The redirect depends on the visitor (session, rate limit): never cached by a CDN or page cache, and the
		// code in the link is not passed on as Referer.
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		}
		wp_safe_redirect( remove_query_arg( $param ) );
		exit;
	}

	/**
	 * Validate the raw code from the URL and keep it in the session.
	 *
	 * @return bool whether a code was remembered
	 */
	public function capture( string $raw ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		if ( Plugin::rate_limited( 'couponlink', 20, 600, 10 * MINUTE_IN_SECONDS, false ) ) {
			wc_add_notice( __( 'Too many voucher links opened. Please try again later.', 'voucher-captain' ), 'error' );
			return false;
		}
		$code = wc_format_coupon_code( sanitize_text_field( $raw ) );
		if ( '' === $code || strlen( $code ) > 100 || ! wc_get_coupon_id_by_code( $code ) ) {
			Plugin::rate_limit_failure( 'couponlink', 10 * MINUTE_IN_SECONDS );
			wc_add_notice( __( 'This voucher link is not valid.', 'voucher-captain' ), 'error' );
			return false;
		}
		Plugin::rate_limit_success( 'couponlink', 10 * MINUTE_IN_SECONDS );
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true ); // Guests need a session to keep the code.
		}
		WC()->session->set( self::SESSION_KEY, $code );
		return true;
	}

	/**
	 * Apply a remembered code once the cart has items; WooCommerce validates it and shows its own notices.
	 */
	public function apply_pending(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}
		$code = (string) WC()->session->get( self::SESSION_KEY, '' );
		if ( '' === $code ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			return; // Waits; cart and checkout say so (waiting_notice_on_cart()), no page is cached meanwhile.
		}
		WC()->session->set( self::SESSION_KEY, null ); // Once only – also if WooCommerce rejects the code.
		if ( ! WC()->cart->has_discount( $code ) ) {
			WC()->cart->apply_coupon( $code );
		}
	}

	private function waiting_notice( string $code ): string {
		return self::waiting_text( $code );
	}

	/** "The voucher …ABCD will be applied …": the code masked, a notice may end up in a page cache. */
	public static function waiting_text( string $code ): string {
		/* translators: %s: masked voucher code, e.g. "…7QX2" */
		return sprintf( __( 'The voucher %s will be applied as soon as you add products to your cart.', 'voucher-captain' ), Downloads::masked_code( $code ) );
	}
}
