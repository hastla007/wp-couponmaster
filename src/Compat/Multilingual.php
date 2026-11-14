<?php
namespace VCAP\Compat;

use VCAP\TemplatePostType;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * WPML and Polylang: customer emails and their PDF in the order language, translatable settings texts.
 *
 * - Order language: WPML order meta "wpml_language", Polylang pll_get_post_language(), filter "vcap_order_language".
 * - Email locale: the locale of that language, else the site language; filter "vcap_email_locale".
 * - Settings texts (redemption conditions, the email texts): WPML translates the options listed in wpml-config.xml
 *   (<admin-texts>) itself; for Polylang they are registered as strings (Languages → Translations) and translated
 *   here while a mail is being prepared.
 */
final class Multilingual {
	/** Group of the strings in Polylang's string translation. */
	public const GROUP = 'Voucher Captain';

	/** Options with customer-facing texts. */
	public const OPTIONS = array( 'vcap_default_terms', 'vcap_terms_by_country' );

	/** Customer emails whose texts can be translated (WooCommerce option "woocommerce_{id}_settings"). */
	public const EMAIL_IDS = array( 'vcap_voucher_buyer', 'vcap_voucher_recipient', 'vcap_voucher_expiry', 'vcap_bonus_voucher', 'vcap_referral_reward' );

	/** Text settings of these emails. */
	public const EMAIL_KEYS = array( 'subject', 'heading', 'intro', 'additional_content' );

	/** @var string[] Languages of the mails being prepared, innermost last. */
	private static array $languages = array();

	public function register(): void {
		add_action( 'init', array( $this, 'register_strings' ), 20 );
		add_filter( 'vcap_render_template_id', array( $this, 'polylang_template' ), 10, 2 );
	}

	/** Polylang: the settings texts appear under Languages → Translations (only needed on admin pages). */
	public function register_strings(): void {
		if ( ! function_exists( 'pll_register_string' ) || ! is_admin() ) {
			return;
		}
		foreach ( self::OPTIONS as $option ) {
			$value = (string) get_option( $option, '' );
			if ( '' !== trim( $value ) ) {
				pll_register_string( $option, $value, self::GROUP, true );
			}
		}
		foreach ( self::EMAIL_IDS as $id ) {
			$settings = get_option( 'woocommerce_' . $id . '_settings', array() );
			foreach ( self::EMAIL_KEYS as $key ) {
				$value = is_array( $settings ) && is_string( $settings[ $key ] ?? null ) ? $settings[ $key ] : '';
				if ( '' !== trim( $value ) ) {
					pll_register_string( $id . '_' . $key, $value, self::GROUP, in_array( $key, array( 'intro', 'additional_content' ), true ) );
				}
			}
		}
	}

	/** Polylang: the PDF uses the translation of the template in the order language. */
	public function polylang_template( $template_id, $voucher ) {
		if ( ! $template_id || ! $voucher instanceof Voucher || ! function_exists( 'pll_get_post' ) ) {
			return $template_id;
		}
		$lang = self::order_language( $voucher->order() );
		$tid  = '' !== $lang ? (int) pll_get_post( (int) $template_id, $lang ) : 0;
		return $tid && TemplatePostType::POST_TYPE === get_post_type( $tid ) ? $tid : $template_id;
	}

	/** Language code (WPML code, Polylang slug) of an order, '' when unknown. */
	public static function order_language( ?\WC_Order $order ): string {
		$lang = '';
		if ( $order ) {
			$lang = (string) $order->get_meta( 'wpml_language' );
			if ( '' === $lang && function_exists( 'pll_get_post_language' ) ) {
				$lang = (string) pll_get_post_language( $order->get_id() );
			}
		}
		/** Language code of an order (e.g. for another multilingual plugin); '' = the site language. */
		return (string) apply_filters( 'vcap_order_language', $lang, $order );
	}

	/** WordPress locale of a language code ('' when unknown). */
	public static function locale_of( string $lang ): string {
		if ( '' === $lang ) {
			return '';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $languages ) && ! empty( $languages[ $lang ]['default_locale'] ) ) {
				return (string) $languages[ $lang ]['default_locale'];
			}
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			$index   = array_search( $lang, $slugs, true );
			if ( false !== $index && ! empty( $locales[ $index ] ) ) {
				return (string) $locales[ $index ];
			}
		}
		return '';
	}

	/**
	 * Locales the shop offers to customers: the site language and the languages of WPML/Polylang.
	 *
	 * @return string[]
	 */
	public static function shop_locales(): array {
		$locales = array( (string) get_locale() );
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
				$locales[] = is_array( $language ) ? (string) ( $language['default_locale'] ?? '' ) : '';
			}
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$locales = array_merge( $locales, array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'locale' ) ) ) );
		}
		return array_values( array_unique( array_filter( $locales ) ) );
	}

	/**
	 * Locale of a customer email about a voucher or an order (and of the PDF attached to it): the order language
	 * with WPML/Polylang, else the site language. Filter "vcap_email_locale" ('' keeps WooCommerce's choice).
	 *
	 * @param mixed $email The WC_Email being sent (or null).
	 */
	public static function email_locale( ?Voucher $voucher, ?\WC_Order $order = null, $email = null ): string {
		$order  = $order ?? ( $voucher ? $voucher->order() : null );
		$locale = self::locale_of( self::order_language( $order ) );
		return (string) apply_filters( 'vcap_email_locale', '' !== $locale ? $locale : (string) get_locale(), $voucher, $order, $email );
	}

	/**
	 * Switches to a language for a mail or PDF: the WordPress locale (texts, month names) and the WPML language
	 * (translated settings and links). The result goes to restore(); "ready" is false when the locale could not be
	 * switched (not installed), then WooCommerce's own locale handling should apply.
	 *
	 * @return array{locale:bool, lang:bool, wpml:?string, ready:bool}
	 */
	public static function switch_to( string $locale, string $lang = '' ): array {
		$state = array(
			'locale' => false,
			'lang'   => false,
			'wpml'   => null,
			'ready'  => '' === $locale,
		);
		if ( '' !== $lang ) {
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$state['wpml'] = (string) apply_filters( 'wpml_current_language', null );
				do_action( 'wpml_switch_language', $lang );
			}
			self::$languages[] = $lang;
			$state['lang']     = true;
		}
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) {
			$state['locale'] = (bool) switch_to_locale( $locale );
			if ( $state['locale'] ) {
				// As wc_switch_to_site_locale(): translations of plugins (WooCommerce's own included) follow.
				add_filter( 'plugin_locale', 'get_locale' );
				if ( function_exists( 'WC' ) ) {
					WC()->load_plugin_textdomain();
				}
			}
			$state['ready'] = $state['locale'] || determine_locale() === $locale;
		}
		return $state;
	}

	/** Undoes switch_to(). */
	public static function restore( array $state ): void {
		if ( ! empty( $state['locale'] ) ) {
			restore_previous_locale();
			remove_filter( 'plugin_locale', 'get_locale' );
			if ( function_exists( 'WC' ) ) {
				WC()->load_plugin_textdomain();
			}
		}
		if ( ! empty( $state['lang'] ) ) {
			array_pop( self::$languages );
		}
		if ( null !== ( $state['wpml'] ?? null ) ) {
			do_action( 'wpml_switch_language', '' !== $state['wpml'] ? $state['wpml'] : null );
		}
	}

	/**
	 * Runs $callback in the language of an order (WPML language and the language Polylang translates settings
	 * texts into; the WordPress locale stays), e.g. to take the redemption conditions in the customer's language.
	 *
	 * @return mixed What $callback returns.
	 */
	public static function in_order_language( ?\WC_Order $order, callable $callback ) {
		$state = self::switch_to( '', self::order_language( $order ) );
		try {
			return $callback();
		} finally {
			self::restore( $state );
		}
	}

	/** Language of the mail being prepared ('' outside of one). */
	public static function mail_language(): string {
		return self::$languages ? (string) end( self::$languages ) : '';
	}

	/**
	 * A settings text in the language of the mail being prepared (Polylang string translation), or – on the
	 * storefront – in the visitor's language. WPML translates the options itself (wpml-config.xml).
	 */
	public static function translate( string $text, bool $mail_only = false ): string {
		if ( '' === trim( $text ) || ! function_exists( 'pll_translate_string' ) ) {
			return $text;
		}
		$lang = self::mail_language();
		if ( '' === $lang && ! $mail_only && ! is_admin() && function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language();
		}
		return '' !== $lang ? (string) pll_translate_string( $text, $lang ) : $text;
	}
}
