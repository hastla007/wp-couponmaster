<?php
/**
 * Accent colour and logo of the Voucher Captain emails (SET-VC-29).
 *
 * @package VCAP
 */

namespace VCAP\Emails;

use VCAP\Settings;
use VCAP\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Accent colour and logo of the voucher emails. Empty settings keep the WooCommerce email settings (base colour,
 * header image), so shops that never saved them look as before; the filters "vcap_setting_vcap_email_color" /
 * "vcap_setting_vcap_email_logo" and "vcap_email_colors" win. Logo and sender are free in the core (owner's rule
 * S6); white label stays an add-on.
 *
 * While a Voucher Captain email is rendered and styled (during()), WooCommerce's own header and styles read the
 * shop's choices through pre_option filters – other emails of the shop are not touched.
 *
 * @since 1.3.0
 */
final class Branding {
	/** Accent colour (#rrggbb, '' = WooCommerce base colour). */
	public const COLOR_OPTION = 'vcap_email_color';

	/** Logo URL ('' = WooCommerce header image). */
	public const LOGO_OPTION = 'vcap_email_logo';

	/** Colour of the voucher emails when neither the shop nor WooCommerce sets one. */
	public const FALLBACK_COLOR = '#1f3a4d';

	/** Depth of nested during() calls (the filters are added once). */
	private static int $depth = 0;

	public static function register(): void {
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::COLOR_OPTION, array( self::class, 'save_color' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::LOGO_OPTION, array( self::class, 'save_logo' ), 10, 2 );
	}

	/**
	 * PV-P5-3: WooCommerce filter "woocommerce_admin_settings_sanitize_option_vcap_email_color" – hex digits without
	 * "#" get one; anything else that is no #rgb/#rrggbb is not saved: the previous value stays and a message names the field.
	 *
	 * @param mixed $value  Submitted value.
	 * @param mixed $option Settings field.
	 */
	public static function save_color( $value, $option = array() ): string {
		if ( is_string( $value ) && preg_match( '/^\s*([0-9a-f]{3}|[0-9a-f]{6})\s*$/i', $value, $hex ) ) {
			$value = '#' . $hex[1]; // Six (or three) hex digits without "#" are taken as the colour they spell.
		}
		$color = self::sanitize_color( $value );
		if ( '' === $color && is_string( $value ) && '' !== trim( $value ) ) {
			return self::refuse(
				self::COLOR_OPTION,
				$option,
				__( 'Accent colour', 'voucher-captain' ),
				/* translators: 1: field label, 2: value entered */
				__( '%1$s: "%2$s" is no colour – enter it as # followed by 3 or 6 hexadecimal digits, e.g. #1f3a4d. The previous value is kept.', 'voucher-captain' ),
				$value
			);
		}
		return $color;
	}

	/**
	 * PV-P5-3: WooCommerce filter "woocommerce_admin_settings_sanitize_option_vcap_email_logo" – an address without
	 * http:// or https:// is not saved: the previous value stays and a message names the field.
	 *
	 * @param mixed $value  Submitted value.
	 * @param mixed $option Settings field.
	 */
	public static function save_logo( $value, $option = array() ): string {
		$url = self::sanitize_logo( $value );
		if ( '' === $url && is_string( $value ) && '' !== trim( $value ) ) {
			return self::refuse(
				self::LOGO_OPTION,
				$option,
				__( 'Logo', 'voucher-captain' ),
				/* translators: 1: field label, 2: value entered */
				__( '%1$s: "%2$s" is no web address – enter the full address starting with https://. The previous value is kept.', 'voucher-captain' ),
				$value
			);
		}
		return $url;
	}

	/**
	 * Reports a refused value (message at the top, field marked) and returns the stored value.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $option   Settings field (its title names the field).
	 * @param string $fallback Field name when the field has no title.
	 * @param string $format   Message with %1$s (field) and %2$s (value).
	 * @param string $value    Submitted value.
	 */
	private static function refuse( string $key, $option, string $fallback, string $format, string $value ): string {
		$label   = is_array( $option ) && '' !== trim( (string) ( $option['title'] ?? '' ) ) ? trim( wp_strip_all_tags( (string) $option['title'] ) ) : $fallback;
		$shown   = mb_strlen( trim( $value ) ) > 80 ? mb_substr( trim( $value ), 0, 79 ) . '…' : trim( $value );
		$message = sprintf( $format, $label, wp_strip_all_tags( $shown ) );
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( $message );
		}
		SettingsRegistry::flag( $key, $message );
		$stored = get_option( $key, '' );
		return self::COLOR_OPTION === $key ? self::sanitize_color( $stored ) : self::sanitize_logo( $stored );
	}

	/**
	 * A colour as stored: #rgb or #rrggbb, anything else is ''.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_color( $value ): string {
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
		return is_string( $color ) ? strtolower( $color ) : '';
	}

	/**
	 * A logo URL as stored: http(s) only, anything else is ''.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_logo( $value ): string {
		$url = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return (string) esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/** The accent colour the shop chose ('' = none, WooCommerce's applies). */
	public static function color(): string {
		return self::sanitize_color( Settings::value( self::COLOR_OPTION ) );
	}

	/** The logo the shop chose ('' = none, WooCommerce's header image applies). */
	public static function logo(): string {
		return self::sanitize_logo( Settings::value( self::LOGO_OPTION ) );
	}

	/** Base colour of the voucher emails: the shop's accent colour, else WooCommerce's base colour, else the fallback. */
	public static function base_color(): string {
		$own = self::color();
		if ( '' !== $own ) {
			return $own;
		}
		$wc = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
		return $wc ? $wc : self::FALLBACK_COLOR;
	}

	/**
	 * Runs $render with the shop's logo and accent colour in place of WooCommerce's header image and base colour
	 * (only for what is set; empty settings change nothing).
	 *
	 * @template T
	 * @param callable(): T $render Renders or styles a Voucher Captain email.
	 * @return T
	 */
	public static function during( callable $render ) {
		if ( 0 === self::$depth++ ) {
			add_filter( 'pre_option_woocommerce_email_header_image', array( self::class, 'pre_logo' ) );
			add_filter( 'pre_option_woocommerce_email_base_color', array( self::class, 'pre_color' ) );
		}
		try {
			return $render();
		} finally {
			if ( 0 === --self::$depth ) {
				remove_filter( 'pre_option_woocommerce_email_header_image', array( self::class, 'pre_logo' ) );
				remove_filter( 'pre_option_woocommerce_email_base_color', array( self::class, 'pre_color' ) );
			}
		}
	}

	/**
	 * Filter "pre_option_woocommerce_email_header_image": the shop's logo, else the stored WooCommerce value.
	 *
	 * @param mixed $pre Value of earlier filters (false = read the option).
	 * @return mixed
	 */
	public static function pre_logo( $pre ) {
		$logo = self::logo();
		return '' !== $logo ? $logo : $pre;
	}

	/**
	 * Filter "pre_option_woocommerce_email_base_color": the base colour of the voucher emails ("vcap_email_colors"
	 * wins) once the shop set an accent colour, else the stored WooCommerce value.
	 *
	 * @param mixed $pre Value of earlier filters (false = read the option).
	 * @return mixed
	 */
	public static function pre_color( $pre ) {
		if ( '' === self::color() ) {
			return $pre;
		}
		return class_exists( '\WC_Email' ) ? VoucherEmail::colors()['base'] : self::base_color();
	}
}
