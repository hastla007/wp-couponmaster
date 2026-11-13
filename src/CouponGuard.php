<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limit for WooCommerce's own coupon entry: the cart form, `?wc-ajax=apply_coupon` (cart and checkout) and the
 * Store API (`cart/apply-coupon`, `cart/coupons`, also inside `batch`). Without it, voucher and gift card codes
 * could be guessed there without any limit, around the limits of the Voucher Captain forms.
 *
 * Same rules as Plugin::rate_limited( …, false ): each application counts per visitor before WooCommerce looks
 * the code up; an existing code gives the attempt back, an unknown code (or another customer's store credit) is
 * booked as a failure. Once the visitor's quota is used up, every application in that request is refused with
 * the same message – whether the code exists or not, so the answer reveals nothing. Coupons already in the cart
 * and coupons applied by code (promotions, coupon links) are never touched.
 */
final class CouponGuard {
	public const BUCKET      = 'coupon';
	public const PER_VISITOR = 15;
	public const SITE_WIDE   = 300;
	public const WINDOW      = 900;
	/** Exception code of the refusal (WC_Coupon errors are numbers; VC uses 90xx). */
	public const E_LIMITED = 9040;

	/** Code being applied in this request ('' = none). */
	private string $code = '';
	private bool $limited = false;
	private bool $failed  = false;

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'from_cart_form' ), 19 ); // WC_Form_Handler::update_cart_action runs at 20.
		add_action( 'wc_ajax_apply_coupon', array( $this, 'from_ajax' ), 1 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_before' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'rest_after' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'refuse_when_limited' ), 1, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'error' ), 999, 3 );
		add_action( 'shutdown', array( $this, 'finish' ) );
	}

	/** Classic cart page: "Apply coupon" button. */
	public function from_cart_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its nonce; we only count.
		if ( ! empty( $_POST['apply_coupon'] ) && ! empty( $_POST['coupon_code'] ) && is_string( $_POST['coupon_code'] ) ) {
			$this->begin( wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_coupon_code() sanitizes.
		}
		// phpcs:enable
	}

	/** Cart and checkout coupon field (AJAX). */
	public function from_ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its nonce; we only count.
		if ( ! empty( $_POST['coupon_code'] ) && is_string( $_POST['coupon_code'] ) ) {
			$this->begin( wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_coupon_code() sanitizes.
		}
		// phpcs:enable
	}

	/** Store API (block cart and checkout, headless shops). */
	public function rest_before( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && 'POST' === $request->get_method() && preg_match( '#^/wc/store(?:/v\d+)?/cart/(?:apply-coupon|coupons)/?$#', (string) $request->get_route() ) ) {
			$code = $request->get_param( 'code' );
			if ( is_string( $code ) && '' !== $code ) {
				$this->begin( wc_format_coupon_code( $code ) );
			}
		}
		return $response;
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
		$this->limited = Plugin::rate_limited( self::BUCKET, self::PER_VISITOR, self::SITE_WIDE, self::WINDOW, false );
	}

	/** Books the outcome of the application of this request: failure, or the attempt is given back. */
	public function finish(): void {
		if ( '' === $this->code ) {
			return;
		}
		if ( ! $this->limited ) {
			if ( $this->failed ) {
				Plugin::rate_limit_failure( self::BUCKET, self::WINDOW );
			} else {
				Plugin::rate_limit_success( self::BUCKET, self::WINDOW );
			}
		}
		$this->code    = '';
		$this->limited = false;
		$this->failed  = false;
	}

	private function concerns( $coupon ): bool {
		return '' !== $this->code && $coupon instanceof \WC_Coupon && wc_is_same_coupon( (string) $coupon->get_code(), $this->code );
	}

	/** An existing code while the visitor's quota is used up: refused like an unknown one. */
	public function refuse_when_limited( $valid, $coupon, $discounts = null ) {
		if ( $this->limited && $this->concerns( $coupon ) ) {
			throw new \Exception( esc_html( self::message() ), self::E_LIMITED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped.
		}
		return $valid;
	}

	/**
	 * Unknown code (105) or another customer's store credit (9030): a failed guess. While limited, every error of
	 * the code being applied reads the same, so expiry or usage messages do not confirm that a code exists.
	 */
	public function error( $message, $code, $coupon ) {
		if ( ! $this->concerns( $coupon ) ) {
			return $message;
		}
		if ( $this->limited ) {
			return self::message();
		}
		if ( in_array( (int) $code, array( \WC_Coupon::E_WC_COUPON_NOT_EXIST, 9030 ), true ) ) {
			$this->failed = true;
		}
		return $message;
	}

	public static function message(): string {
		return __( 'Too many attempts. Please try again later.', 'voucher-captain' );
	}
}
