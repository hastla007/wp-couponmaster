<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limit for WooCommerce's own coupon entry: the cart form, `?wc-ajax=apply_coupon` (cart and checkout, also
 * through admin-ajax.php) and the Store API (`cart/apply-coupon`, `cart/coupons`, also inside `batch`). Without it,
 * voucher and gift card codes could be guessed there without any limit, around the limits of the plugin's forms.
 *
 * Same rules and bucket as the plugin's forms (CodeLookup): each application counts per visitor before WooCommerce looks
 * the code up; an existing code gives the attempt back, an unknown code (or another customer's store credit) is
 * booked as a failure. Once the visitor's quota is used up, every application in that request is refused with
 * the same message – whether the code exists or not, so the answer reveals nothing (Store API: status 429). Coupons already in the cart
 * and coupons applied by code (promotions, coupon links) are never touched.
 */
final class CouponGuard {
	/** The shared bucket of all code entries (CodeLookup): the quotas of the forms do not add up. */
	public const BUCKET      = CodeLookup::BUCKET;
	public const PER_VISITOR = CodeLookup::PER_VISITOR;
	public const SITE_WIDE   = CodeLookup::SITE_WIDE;
	public const WINDOW      = CodeLookup::WINDOW;
	/** Exception code of the refusal (WC_Coupon errors are numbers; VC uses 90xx). */
	public const E_LIMITED = 9040;

	/** Code being applied in this request ('' = none). */
	private string $code = '';
	private bool $limited = false;
	private bool $failed  = false;

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'from_cart_form' ), 19 ); // WC_Form_Handler::update_cart_action runs at 20.
		add_action( 'wc_ajax_apply_coupon', array( $this, 'from_ajax' ), 1 );
		add_action( 'wp_ajax_woocommerce_apply_coupon', array( $this, 'from_ajax' ), 1 );
		add_action( 'wp_ajax_nopriv_woocommerce_apply_coupon', array( $this, 'from_ajax' ), 1 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_before' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'rest_after' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'refuse_when_limited' ), 1, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'require_pin' ), 2, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'error' ), 999, 3 );
		add_action( 'shutdown', array( $this, 'finish' ) );
	}

	/** Classic cart page: "Apply coupon" button. */
	public function from_cart_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its nonce; we only count.
		if ( ! empty( $_POST['apply_coupon'] ) && ! empty( $_POST['coupon_code'] ) && is_string( $_POST['coupon_code'] ) ) {
			$this->begin( wc_format_coupon_code( self::posted_code() ) );
		}
		// phpcs:enable
	}

	/** Cart and checkout coupon field (AJAX). */
	public function from_ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its nonce; we only count.
		if ( ! empty( $_POST['coupon_code'] ) && is_string( $_POST['coupon_code'] ) ) {
			$this->begin( wc_format_coupon_code( self::posted_code() ) );
		}
		// phpcs:enable
	}

	/**
	 * The posted coupon code. M12-99: a PIN entered as code#PIN is taken off (kept for this request's PIN check)
	 * and WooCommerce gets the plain code.
	 */
	private static function posted_code(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- WooCommerce verifies its nonce and formats the code.
		$raw  = (string) wp_unslash( $_POST['coupon_code'] ?? '' );
		$code = CodeLookup::code_input( $raw );
		if ( $code !== $raw ) {
			$_POST['coupon_code']    = wp_slash( $code );
			$_REQUEST['coupon_code'] = wp_slash( $code );
		}
		// phpcs:enable
		return $code;
	}

	/**
	 * Store API (block cart and checkout, headless shops). Recognised by the route's handler class, else by the
	 * route path compared case-insensitively – WordPress matches REST routes case-insensitively, so
	 * "/wc/store/v1/Cart/Apply-Coupon" reaches the same handler (also through ?rest_route= and batch requests).
	 *
	 * M12-01: `cart/coupons` checks the code with a validate_callback (coupon_exists) before any callback runs, so
	 * WooCommerce answers an unknown code with 400 rest_invalid_param and an existing one with 401 (missing nonce)
	 * or 201. Here the attempt is counted first; a limited visitor gets 429 for every code; an unknown code (also
	 * one smuggled in as `discount_type`, which has the same validate_callback) and another customer's store
	 * credit are booked as failures and get one uniform 400 "invalid code" – before the nonce check, so that
	 * answer does not depend on the nonce either.
	 *
	 * @param mixed $response Result so far (WP_Error from the parameter validation).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function rest_before( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== strtoupper( $request->get_method() ) ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( self::is_batch( $handler, $route ) ) {
			self::batch_validation( $request );
			return $response;
		}
		$kind = self::coupon_route( $handler, $route );
		if ( '' === $kind ) {
			return $response;
		}
		$code = $request->get_param( 'code' );
		if ( is_string( $code ) && str_contains( $code, CodeLookup::PIN_SEPARATOR ) ) {
			// M12-99: code#PIN – the plain code goes on, the PIN is checked when the coupon is validated.
			$code = wc_format_coupon_code( CodeLookup::code_input( $code ) );
			$request->set_param( 'code', $code );
			if ( 'coupons' === $kind && self::invalid_code_param( $response ) && '' !== $code && ( new \WC_Coupon( $code ) )->get_id() ) {
				$response = null; // Only "code#PIN" failed coupon_exists; the plain code exists.
			}
		}
		$code  = is_string( $code ) ? wc_format_coupon_code( $code ) : '';
		$type  = 'coupons' === $kind ? $request->get_param( 'discount_type' ) : null;
		$probe = is_string( $type ) && '' !== $type;
		if ( '' === $code && $probe ) {
			$code = wc_format_coupon_code( (string) $type );
		}
		if ( '' === $code ) {
			return $response;
		}
		$this->begin( $code );
		if ( $this->limited ) {
			return new \WP_Error( 'woocommerce_rest_cart_coupon_error', self::message(), array( 'status' => 429 ) );
		}
		if ( 'coupons' === $kind && ( $probe || self::invalid_code_param( $response ) || self::foreign( $code ) ) ) {
			$this->failed = true;
			return new \WP_Error( 'woocommerce_rest_cart_coupon_error', self::invalid_message( $code ), array( 'status' => 400 ) );
		}
		return $response;
	}

	/** Whether a Store API request adds a coupon to the cart (handler CartApplyCoupon or CartCoupons). */
	public static function applies_coupon( $handler, string $route ): bool {
		return '' !== self::coupon_route( $handler, $route );
	}

	/**
	 * Which coupon route a Store API request goes to: 'apply' (cart/apply-coupon), 'coupons' (cart/coupons, with
	 * WooCommerce's validate_callback on the code) or '' (another route).
	 *
	 * @param mixed $handler Route handler.
	 */
	private static function coupon_route( $handler, string $route ): string {
		$short = self::handler_class( $handler );
		if ( 'CartApplyCoupon' === $short ) {
			return 'apply';
		}
		if ( 'CartCoupons' === $short ) {
			return 'coupons';
		}
		if ( preg_match( '#^/wc/store(?:/v\d+)?/cart/(apply-coupon|coupons)/?$#i', $route, $m ) ) {
			return 'coupons' === strtolower( $m[1] ) ? 'coupons' : 'apply';
		}
		return '';
	}

	/**
	 * Short class name of a Store API route handler ('' = no Store API handler).
	 *
	 * @param mixed $handler Route handler.
	 */
	private static function handler_class( $handler ): string {
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) {
			$class = get_class( $callback[0] );
			if ( str_contains( $class, 'StoreApi' ) ) {
				return substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
			}
		}
		return '';
	}

	/**
	 * Store API batch route.
	 *
	 * @param mixed $handler Route handler.
	 */
	private static function is_batch( $handler, string $route ): bool {
		return 'Batch' === self::handler_class( $handler ) || (bool) preg_match( '#^/wc/store(?:/v\d+)?/batch/?$#i', $route );
	}

	/**
	 * A batch with validation "require-all-validate" answers parameter errors of its requests without running any
	 * filter – the coupon_exists check of cart/coupons would answer uncounted. Batches that apply a coupon are
	 * therefore validated request by request ("normal"), so every application passes rest_before().
	 */
	private static function batch_validation( \WP_REST_Request $request ): void {
		$requests = $request->get_param( 'requests' );
		if ( 'require-all-validate' !== $request->get_param( 'validation' ) || ! is_array( $requests ) ) {
			return;
		}
		foreach ( $requests as $single ) {
			$path   = is_array( $single ) && is_string( $single['path'] ?? null ) ? (string) wp_parse_url( $single['path'], PHP_URL_PATH ) : '';
			$method = is_array( $single ) && is_string( $single['method'] ?? null ) ? strtoupper( $single['method'] ) : 'POST';
			if ( 'POST' === $method && self::applies_coupon( array(), $path ) ) {
				$request->set_param( 'validation', 'normal' );
				return;
			}
		}
	}

	/**
	 * WooCommerce's parameter validation refused the code (coupon_exists = false: unknown code).
	 *
	 * @param mixed $response Result so far.
	 */
	private static function invalid_code_param( $response ): bool {
		if ( ! is_wp_error( $response ) || 'rest_invalid_param' !== $response->get_error_code() ) {
			return false;
		}
		$data = $response->get_error_data();
		return is_array( $data ) && is_array( $data['params'] ?? null ) && ( isset( $data['params']['code'] ) || isset( $data['params']['discount_type'] ) );
	}

	/** Another customer's store credit: exists, but must read like an unknown code (CodeLookup::visible()). */
	private static function foreign( string $code ): bool {
		$id = strlen( $code ) <= 100 ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		if ( $id <= 0 || ! isset( vcap()->vouchers ) ) {
			return false;
		}
		$voucher = vcap()->vouchers->get_by_coupon( $id );
		return $voucher instanceof Voucher && ! CodeLookup::visible( $voucher );
	}

	/** WooCommerce's own "does not exist" message for the code, worded as its Store API words coupon errors. */
	private static function invalid_message( string $code ): string {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		return esc_html( wp_strip_all_tags( (string) $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_NOT_EXIST ) ) );
	}

	public function rest_after( $response, $handler, $request ) {
		$this->finish();
		return $response;
	}

	/** Counts the application before WooCommerce looks the code up. */
	public function begin( string $code ): void {
		$this->finish();
		if ( '' === $code ) {
			return;
		}
		$this->code    = $code;
		$this->failed  = false;
		$this->limited = CodeLookup::begin();
	}

	/** Books the outcome of the application of this request: failure, or the attempt is given back. */
	public function finish(): void {
		if ( '' === $this->code ) {
			return;
		}
		if ( ! $this->limited ) {
			CodeLookup::finish( ! $this->failed );
		}
		$this->code    = '';
		$this->limited = false;
		$this->failed  = false;
	}

	/** Same coupon code (wc_is_same_coupon() exists only from WooCommerce 9.x; the minimum is 8.9). */
	private static function same_code( string $a, string $b ): bool {
		if ( function_exists( 'wc_is_same_coupon' ) ) {
			return wc_is_same_coupon( $a, $b );
		}
		return wc_strtolower( wc_format_coupon_code( $a ) ) === wc_strtolower( wc_format_coupon_code( $b ) );
	}

	private function concerns( $coupon ): bool {
		return '' !== $this->code && $coupon instanceof \WC_Coupon && self::same_code( (string) $coupon->get_code(), $this->code );
	}

	/** An existing code while the visitor's quota is used up: refused like an unknown one. */
	public function refuse_when_limited( $valid, $coupon, $discounts = null ) {
		if ( $this->limited && $this->concerns( $coupon ) ) {
			throw new \Exception( esc_html( self::message() ), self::E_LIMITED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped.
		}
		return $valid;
	}

	/**
	 * M12-99: a code protected by a PIN is valid only with its PIN (code#PIN, entered in this request or earlier in
	 * the session). Without it the code reads like an unknown one and counts as a failed guess. Only visitor carts
	 * (with a WooCommerce session) are checked; staff (wp-admin, REST orders) have the card in hand, like at
	 * the counter.
	 *
	 * @param mixed $valid     Validity so far.
	 * @param mixed $coupon    WC_Coupon.
	 * @param mixed $discounts WC_Discounts.
	 * @return mixed
	 * @throws \Exception When the PIN is missing or wrong.
	 */
	public function require_pin( $valid, $coupon, $discounts = null ) {
		$visitor = function_exists( 'WC' ) && WC()->session && ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capabilities.
		if ( $visitor && $coupon instanceof \WC_Coupon && ! CodeLookup::pin_ok( (string) $coupon->get_code() ) ) {
			throw new \Exception( (string) \WC_Coupon::E_WC_COUPON_NOT_EXIST, CodeLookup::E_PIN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an integer.
		}
		return $valid;
	}

	/**
	 * Unknown code (105), another customer's store credit (9030) or a missing PIN (9070): a failed guess. While limited, every error of
	 * the code being applied reads the same, so expiry or usage messages do not confirm that a code exists.
	 */
	public function error( $message, $code, $coupon ) {
		if ( ! $this->concerns( $coupon ) ) {
			return $message;
		}
		if ( $this->limited ) {
			return self::message();
		}
		if ( in_array( (int) $code, array( \WC_Coupon::E_WC_COUPON_NOT_EXIST, 9030, CodeLookup::E_PIN ), true ) ) {
			$this->failed = true;
		}
		return $message;
	}

	public static function message(): string {
		return CodeLookup::limited_message();
	}
}
