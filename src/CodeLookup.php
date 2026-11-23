<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * One rate limit and one answer for every place where a visitor enters a voucher or coupon code: the redeem form,
 * coupon links, WooCommerce's own coupon entry (CouponGuard), the balance check, the gift card top-up and – by
 * address – the redemption API.
 *
 * All of them count in ONE bucket per visitor (address; IPv6 by /64 and /48, see Plugin::rate_limited()), so the
 * quotas of the different forms do not add up. An attempt counts before the lookup (begin()); an existing code
 * gives it back (found()), an unknown code is booked as a failure (failed()). A code the visitor may not use
 * – another customer's store credit – counts and reads like an unknown code (confirms(), visible()), so no
 * surface confirms that it exists.
 *
 * M12-99: imported (physical) cards may carry a PIN (stored hashed per code). Such a code is usable, checkable
 * and can be topped up only with its PIN, entered as CODE#PIN in any code field (code_input(), pin_ok()); without
 * it the code reads like an unknown one and counts as a failed attempt in the same bucket.
 */
final class CodeLookup {
	public const BUCKET      = 'code';
	/** Default attempts per visitor and window (setting "vcap_code_attempts_per_visitor", at least 3; SET-VC-31). */
	public const PER_VISITOR = 15;
	/** Default failed codes site-wide per window before the brake (setting "vcap_code_attempts_site_wide", at least 50). */
	public const SITE_WIDE   = 300;
	public const WINDOW      = 900;

	/** SET-VC-31: attempts per visitor and window – the setting, never below its floor (filter "vcap_rate_limit_code" wins). */
	public static function per_visitor(): int {
		return SettingsRegistry::bounded_int( 'vcap_code_attempts_per_visitor' );
	}

	/** SET-VC-31: failed codes site-wide per window before every quota shrinks – the setting, never below its floor. */
	public static function site_wide(): int {
		return SettingsRegistry::bounded_int( 'vcap_code_attempts_site_wide' );
	}

	/** Counts a lookup of the current visitor before the code is looked up; true = refused, too many attempts. */
	public static function begin(): bool {
		return Plugin::rate_limited( self::BUCKET, self::per_visitor(), self::site_wide(), self::WINDOW, false );
	}

	/** The code exists (and may be shown to this visitor): the attempt is given back. */
	public static function found(): void {
		Plugin::rate_limit_success( self::BUCKET, self::WINDOW );
	}

	/** Unknown code: booked on the site-wide failure counter. */
	public static function failed(): void {
		Plugin::rate_limit_failure( self::BUCKET, self::WINDOW );
	}

	/** Books the outcome of a lookup started with begin(). */
	public static function finish( bool $found ): void {
		if ( $found ) {
			self::found();
		} else {
			self::failed();
		}
	}

	public static function limited_message(): string {
		return __( 'Too many attempts. Please try again later.', 'voucher-captain' );
	}

	public static function not_found_message(): string {
		return __( 'Voucher code not found.', 'voucher-captain' );
	}

	/**
	 * Whether a voucher may be confirmed to the current visitor: everything except another customer's store
	 * credit (which only its owner can use or look up).
	 *
	 * @param int|null $user_id Visitor's user id; null = the current user.
	 */
	public static function visible( ?Voucher $voucher, ?int $user_id = null ): bool {
		if ( ! $voucher ) {
			return false;
		}
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::pin_ok( (string) $voucher->code ) ) {
			return false; // M12-99: a card with a PIN reads like an unknown code without its PIN.
		}
		return 'credit' !== $voucher->type || ( $voucher->user_id > 0 && $user_id === (int) $voucher->user_id );
	}

	// ----------------------------------------------------------------- M12-99: PIN of imported (physical) cards.

	/** Option prefix of the hashed PIN of one code (option per code, not autoloaded; no schema change). */
	public const PIN_PREFIX = 'vcap_pin_';
	/** Option: 'yes' once any PIN was stored (forms show the CODE#PIN hint, checks look PINs up). */
	public const PINS_OPTION = 'vcap_pins_in_use';
	/** Separator of the code#PIN convention, accepted in every code field (forms, coupon field, Store API, REST). */
	public const PIN_SEPARATOR = '#';
	/** Coupon error code of a missing or wrong PIN (read like an unknown code, booked as a failed guess). */
	public const E_PIN = 9070;
	/** WooCommerce session key: codes whose PIN the visitor entered correctly. */
	private const PIN_SESSION = 'vcap_pin_ok';
	/** bcrypt cost of PIN hashes: a few ms per PIN, so a batch of 500 cards imports in one request. */
	private const PIN_COST = 7;

	/** @var array<string, string> PINs entered in this request, by code key */
	private static array $presented = array();
	/** @var array<string, bool> PIN checks of this request, by code key */
	private static array $checked = array();

	/** Whether PINs are used on this site at all (no lookups otherwise). */
	public static function pins_in_use(): bool {
		return 'yes' === get_option( self::PINS_OPTION, 'no' );
	}

	/** Allowed PIN: 3–16 letters or digits. */
	public static function valid_pin( string $pin ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9]{3,16}$/', $pin );
	}

	private static function pin_code_key( string $code ): string {
		return md5( strtolower( (string) wc_format_coupon_code( trim( $code ) ) ) );
	}

	/** Hash of a PIN, bound to its code (PHP bcrypt). */
	public static function hash_pin( string $code, string $pin ): string {
		return password_hash( self::pin_code_key( $code ) . '|' . trim( $pin ), PASSWORD_BCRYPT, array( 'cost' => self::PIN_COST ) );
	}

	/** Stores a PIN hash (hash_pin()) for a code; '' removes the PIN. */
	public static function store_pin_hash( string $code, string $hash ): bool {
		$name = self::PIN_PREFIX . self::pin_code_key( $code );
		unset( self::$checked[ self::pin_code_key( $code ) ] );
		if ( '' === $hash ) {
			return delete_option( $name );
		}
		if ( ! self::pins_in_use() ) {
			update_option( self::PINS_OPTION, 'yes', true );
		}
		return update_option( $name, $hash, false ) || get_option( $name ) === $hash;
	}

	/** Whether a code is protected by a PIN. */
	public static function has_pin( string $code ): bool {
		return self::pins_in_use() && '' !== (string) get_option( self::PIN_PREFIX . self::pin_code_key( $code ), '' );
	}

	/**
	 * Splits the code#PIN convention: the part after the last "#" is the PIN.
	 *
	 * @return array{0:string, 1:string} code, PIN ('' for none)
	 */
	public static function split_pin( string $raw ): array {
		$pos = strrpos( $raw, self::PIN_SEPARATOR );
		if ( false === $pos ) {
			return array( $raw, '' );
		}
		return array( substr( $raw, 0, $pos ), trim( substr( $raw, $pos + 1 ) ) );
	}

	/** The code of an entry; a PIN entered as code#PIN is kept for the checks of this request (pin_ok()). */
	public static function code_input( string $raw ): string {
		list( $code, $pin ) = self::split_pin( $raw );
		if ( '' !== $pin ) {
			$key                     = self::pin_code_key( $code );
			self::$presented[ $key ] = $pin;
			unset( self::$checked[ $key ] );
		}
		return $code;
	}

	/**
	 * Whether the visitor may use a code as far as its PIN goes: no PIN set, the PIN entered in this request is
	 * right (then remembered in the visitor's session, so the coupon stays valid in the cart), or it was entered
	 * correctly earlier in this session.
	 */
	public static function pin_ok( string $code ): bool {
		if ( '' === $code || ! self::pins_in_use() ) {
			return true;
		}
		$key = self::pin_code_key( $code );
		if ( isset( self::$checked[ $key ] ) ) {
			return self::$checked[ $key ];
		}
		$hash = (string) get_option( self::PIN_PREFIX . $key, '' );
		if ( '' === $hash ) {
			$ok = true;
		} elseif ( isset( self::$presented[ $key ] ) && password_verify( $key . '|' . self::$presented[ $key ], $hash ) ) {
			self::remember_pin( $key );
			$ok = true;
		} else {
			$session = function_exists( 'WC' ) && WC()->session ? (array) WC()->session->get( self::PIN_SESSION, array() ) : array();
			$ok      = in_array( $key, $session, true );
		}
		self::$checked[ $key ] = $ok;
		return $ok;
	}

	private static function remember_pin( string $key ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$session = (array) WC()->session->get( self::PIN_SESSION, array() );
		if ( ! in_array( $key, $session, true ) ) {
			$handler = WC()->session;
			if ( $handler instanceof \WC_Session_Handler && ! $handler->has_session() ) {
				$handler->set_customer_session_cookie( true ); // Guests need a session to keep the coupon.
			}
			$session[] = $key;
			WC()->session->set( self::PIN_SESSION, array_slice( $session, -50 ) );
		}
	}

	/** Hint below code fields while PINs are in use ('' otherwise): how to enter a card's PIN. */
	public static function pin_hint(): string {
		if ( ! self::pins_in_use() ) {
			return '';
		}
		return '<p class="vcap-pin-hint description">' . esc_html__( 'Card with a PIN? Enter it after the code, separated by #, e.g. CODE#1234.', 'voucher-captain' ) . '</p>';
	}

	/** Forgets the PINs entered in this request (tests, long-running workers). */
	public static function forget_pins(): void {
		self::$presented = array();
		self::$checked   = array();
	}

	/**
	 * Whether a WooCommerce coupon code may be confirmed to the current visitor: it exists and is not another
	 * customer's store credit.
	 */
	public static function confirms( string $code ): bool {
		$id = '' !== $code && strlen( $code ) <= 100 ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		if ( $id <= 0 ) {
			return false;
		}
		$voucher = isset( vcap()->vouchers ) ? vcap()->vouchers->get_by_coupon( $id ) : null;
		return ! $voucher || self::visible( $voucher );
	}
}
