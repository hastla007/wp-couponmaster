<?php
/**
 * Additional CSS for the storefront output (SET-VC-30).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * "Additional CSS" of the "Vouchers" settings (Delivery & downloads): printed after the storefront style only on
 * pages with Voucher Captain output (shortcodes, blocks, voucher fields, My Account – see Downloads::enqueue_style()).
 * Stored cleaned (sanitize()): no tags, no "</style", no expression(), no javascript:/vbscript: URLs, no behaviours
 * or bindings, no @import, at most MAX_LENGTH characters; cleaned again before it is printed. The filter
 * "vcap_setting_vcap_custom_css" wins.
 *
 * @since 1.3.0
 */
final class CustomCss {
	public const OPTION = 'vcap_custom_css';

	/** Longest stored CSS in characters (technical limit). */
	public const MAX_LENGTH = 10000;

	/** Handle of the inline style (no file; depends on "vcap-frontend"). */
	public const HANDLE = 'vcap-custom-css';

	/** Whether the CSS was added to this page already. */
	private static bool $added = false;

	public static function register(): void {
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION, array( self::class, 'sanitize_option' ), 10, 3 );
	}

	/**
	 * WooCommerce filter "woocommerce_admin_settings_sanitize_option_vcap_custom_css": cleans the raw input
	 * (WooCommerce's textarea cleaning would turn ">" of child selectors into "&gt;").
	 *
	 * @param mixed $value     Value cleaned by WooCommerce.
	 * @param mixed $option    Settings field.
	 * @param mixed $raw_value Submitted value.
	 */
	public static function sanitize_option( $value, $option = array(), $raw_value = null ): string {
		$input = null !== $raw_value ? $raw_value : $value;
		$clean = self::sanitize( $input );
		// PV-P5-3: say so when the stored CSS differs from what was entered (shortened, parts removed).
		$message = self::change_message( is_string( $input ) ? $input : '', $clean, is_array( $option ) ? (string) ( $option['title'] ?? '' ) : '' );
		if ( '' !== $message ) {
			if ( class_exists( '\WC_Admin_Settings' ) ) {
				\WC_Admin_Settings::add_error( $message );
			}
			SettingsRegistry::flag( self::OPTION, $message );
		}
		return $clean;
	}

	/**
	 * PV-P5-3: the message when the stored CSS is not what was entered ('' = stored as entered, apart from line
	 * endings and surrounding spaces).
	 *
	 * @param string $input Entered CSS.
	 * @param string $clean What sanitize() made of it.
	 * @param string $label Field label ('' = "Additional CSS").
	 */
	public static function change_message( string $input, string $clean, string $label = '' ): string {
		$plain = trim( str_replace( array( "\r\n", "\r" ), "\n", $input ) );
		if ( $plain === $clean ) {
			return '';
		}
		$label = '' !== trim( $label ) ? trim( wp_strip_all_tags( $label ) ) : __( 'Additional CSS', 'voucher-captain' );
		$parts = array();
		if ( mb_strlen( $plain ) > self::MAX_LENGTH ) {
			/* translators: %d: maximum number of characters */
			$parts[] = sprintf( __( 'it was shortened to %d characters', 'voucher-captain' ), self::MAX_LENGTH );
			$plain   = trim( mb_substr( $plain, 0, self::MAX_LENGTH ) );
		}
		if ( $plain !== $clean ) {
			$parts[] = __( 'comments and parts that are not allowed (tags, @import, expression(), script addresses, behaviours or bindings) were removed', 'voucher-captain' );
		}
		/* translators: 1: field label, 2: what was changed */
		return sprintf( __( '%1$s was saved changed: %2$s. Check the saved text below.', 'voucher-captain' ), $label, implode( '; ', $parts ) );
	}

	/**
	 * CSS as stored and printed.
	 *
	 * @param mixed $css Entered CSS.
	 */
	public static function sanitize( $css ): string {
		$css = is_string( $css ) ? $css : '';
		$css = str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), $css );
		$css = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', $css ); // Comments could hide the patterns below.
		$css = wp_strip_all_tags( $css );
		$css = str_replace( '<', '', $css ); // No "</style", no tag of any kind.
		// Escapes (\65 = "e") and line breaks could spell a pattern out, so it is searched without them.
		$probe = static fn( string $text ): string => strtolower( (string) preg_replace( '/[\s\\\\]+/', '', $text ) );
		if ( preg_match( '/expression\(|javascript:|vbscript:|-moz-binding|behaviou?r:|@import/', $probe( $css ) ) ) {
			$css = str_replace( '\\', '', $css );
		}
		$patterns = array( '/e\s*x\s*p\s*r\s*e\s*s\s*s\s*i\s*o\s*n\s*\(/i', '/(?:java|vb)\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '/-moz-binding\s*:[^;}]*/i', '/behaviou?r\s*:[^;}]*/i', '/@import[^;]*;?/i' );
		do {
			$before = $css;
			$css    = (string) preg_replace( $patterns, '', $css );
		} while ( $before !== $css );
		$css = trim( $css );
		return mb_strlen( $css ) > self::MAX_LENGTH ? trim( mb_substr( $css, 0, self::MAX_LENGTH ) ) : $css;
	}

	/** The CSS to print ('' = none). */
	public static function css(): string {
		return self::sanitize( Settings::value( self::OPTION ) );
	}

	/** Adds the CSS after the storefront style (once per page; nothing when it is empty). */
	public static function enqueue(): void {
		if ( self::$added ) {
			return;
		}
		$css = self::css();
		if ( '' === $css ) {
			return;
		}
		self::$added = true;
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, false, array( 'vcap-frontend' ), VCAP_VERSION );
		}
		wp_add_inline_style( self::HANDLE, $css );
		wp_enqueue_style( self::HANDLE );
	}

	/** Forgets that the CSS was added (tests). */
	public static function reset(): void {
		self::$added = false;
	}
}
