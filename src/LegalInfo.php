<?php
/**
 * Issuer details and legal texts (SET-VC-11).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-11: issuer (name, address, contact), links to terms, privacy policy and right of withdrawal and a free legal
 * text for PDFs and emails – block "Issuer and legal texts" in the section "General". They appear only where a PDF
 * template or an email text uses the placeholders {issuer}, {legal_text}, {terms_url}, {privacy_url} and
 * {withdrawal_url} (Placeholders), so existing vouchers and emails stay unchanged.
 *
 * Every field is empty by default and then filled at render time from the shop: the site name, the WooCommerce
 * store address (always with the country), the WooCommerce terms and conditions page, the WordPress privacy policy
 * page and, for the contact, the sender address of the WooCommerce emails (owner decision OD-4 of 24.11.2026; nothing
 * when that is empty too – no other address). The withdrawal link has no default. The texts are translated per language: WPML through wpml-config.xml
 * (admin-texts), Polylang through Compat\Multilingual (string translation). Nothing here is legally checked.
 *
 * @since 1.3.0
 */
final class LegalInfo {
	public const NAME_OPTION       = 'vcap_issuer_name';
	public const ADDRESS_OPTION    = 'vcap_issuer_address';
	public const CONTACT_OPTION    = 'vcap_issuer_contact';
	public const TERMS_OPTION      = 'vcap_terms_url';
	public const PRIVACY_OPTION    = 'vcap_privacy_url';
	public const WITHDRAWAL_OPTION = 'vcap_withdrawal_url';
	public const TEXT_OPTION       = 'vcap_legal_text';

	/** Settings holding a link (only http and https are stored). */
	public const URL_OPTIONS = array( self::TERMS_OPTION, self::PRIVACY_OPTION, self::WITHDRAWAL_OPTION );

	/** Settings with several lines (stored as plain text). */
	public const TEXT_OPTIONS = array( self::ADDRESS_OPTION, self::TEXT_OPTION );

	/** All settings of the block, in this order (also the texts translated by WPML/Polylang). */
	public const OPTIONS = array( self::NAME_OPTION, self::ADDRESS_OPTION, self::CONTACT_OPTION, self::TERMS_OPTION, self::PRIVACY_OPTION, self::WITHDRAWAL_OPTION, self::TEXT_OPTION );

	/** Sanitizers of the settings page (Settings::register()). */
	public static function register_sanitizers(): void {
		foreach ( self::URL_OPTIONS as $key ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $key, array( self::class, 'sanitize_url_option' ), 10, 2 );
		}
		foreach ( self::TEXT_OPTIONS as $key ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $key, array( self::class, 'sanitize_text' ) );
		}
	}

	/**
	 * A link setting: an http(s) URL or '' (empty = the default page). Anything else is refused: '' is returned.
	 *
	 * @param mixed $value Value.
	 */
	public static function clean_url( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value || ! preg_match( '#^https?://[^\s/]+#i', $value ) ) {
			return '';
		}
		return (string) esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * WooCommerce filter "woocommerce_admin_settings_sanitize_option_{link option}": an invalid link is not saved
	 * (the stored value stays) and reported with the field name.
	 *
	 * @param mixed                $value  Submitted value.
	 * @param array<string, mixed> $option Settings field.
	 * @return mixed
	 */
	public static function sanitize_url_option( $value, $option = array() ) {
		$raw   = is_scalar( $value ) ? trim( (string) $value ) : '';
		$clean = self::clean_url( $raw );
		if ( '' === $raw || '' !== $clean ) {
			return $clean;
		}
		$key = is_array( $option ) ? (string) ( $option['id'] ?? '' ) : '';
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: setting name, 2: value entered */
					__( '%1$s "%2$s" was not saved: enter a complete link starting with https:// (or leave it empty for the default page).', 'voucher-captain' ),
					is_array( $option ) ? (string) ( $option['title'] ?? $key ) : $key,
					$raw
				)
			);
		}
		return null; // WooCommerce keeps the stored value.
	}

	/**
	 * WooCommerce filter for the address and the legal text: plain text with line breaks (placeholders print text).
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_text( $value ): string {
		return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
	}

	/** A stored text in the current language (Polylang; WPML translates the option itself). */
	private static function text( string $key ): string {
		$value = get_option( $key, '' );
		return is_scalar( $value ) ? trim( Compat\Multilingual::translate( (string) $value ) ) : '';
	}

	/** Issuer name: the setting, else the site name. */
	public static function issuer_name(): string {
		$name = self::text( self::NAME_OPTION );
		return '' !== $name ? $name : trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/** Issuer address (lines): the setting, else the WooCommerce store address. */
	public static function issuer_address(): string {
		$address = self::text( self::ADDRESS_OPTION );
		return '' !== $address ? $address : self::store_address();
	}

	/**
	 * Contact (phone, email, …): the setting, else the sender address of the WooCommerce emails
	 * ("woocommerce_email_from_address", OD-4); '' when both are empty or the sender address is invalid. No other
	 * address (e.g. the WordPress admin email) is used.
	 */
	public static function issuer_contact(): string {
		$contact = self::text( self::CONTACT_OPTION );
		return '' !== $contact ? $contact : self::sender_address();
	}

	/** OD-4: sender address of the WooCommerce emails (WooCommerce → Settings → Emails), '' when empty or invalid. */
	public static function sender_address(): string {
		$from = get_option( 'woocommerce_email_from_address', '' );
		$from = is_scalar( $from ) ? sanitize_email( trim( (string) $from ) ) : '';
		return '' !== $from && is_email( $from ) ? $from : '';
	}

	/** {issuer}: name, address and contact, one per line; empty parts are left out. */
	public static function issuer(): string {
		return implode( "\n", array_filter( array( self::issuer_name(), self::issuer_address(), self::issuer_contact() ), static fn( $part ) => '' !== $part ) );
	}

	/**
	 * Store address of WooCommerce (Settings → General → Store address), one line per part; '' when none is set.
	 * The country is added (also the shop's own), because vouchers travel across borders.
	 */
	public static function store_address(): string {
		$parts = array(
			'address_1' => trim( (string) get_option( 'woocommerce_store_address', '' ) ),
			'address_2' => trim( (string) get_option( 'woocommerce_store_address_2', '' ) ),
			'postcode'  => trim( (string) get_option( 'woocommerce_store_postcode', '' ) ),
			'city'      => trim( (string) get_option( 'woocommerce_store_city', '' ) ),
		);
		if ( '' === implode( '', $parts ) ) {
			return '';
		}
		$base    = (string) get_option( 'woocommerce_default_country', '' );
		$country = strtoupper( (string) strtok( $base, ':' ) );
		$state   = str_contains( $base, ':' ) ? substr( $base, strpos( $base, ':' ) + 1 ) : '';
		$name    = $country;
		$wc      = function_exists( 'WC' ) ? WC() : null;
		if ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) ) {
			$countries = (array) $wc->countries->get_countries();
			$name      = '' !== $country && isset( $countries[ $country ] ) ? html_entity_decode( (string) $countries[ $country ], ENT_QUOTES, 'UTF-8' ) : $country;
			if ( method_exists( $wc->countries, 'get_formatted_address' ) ) {
				$force = static fn() => true;
				add_filter( 'woocommerce_formatted_address_force_country_display', $force );
				$html = (string) $wc->countries->get_formatted_address( $parts + array( 'country' => $country, 'state' => $state ), "\n" );
				remove_filter( 'woocommerce_formatted_address_force_country_display', $force );
				$lines = array_filter( array_map( 'trim', explode( "\n", wp_strip_all_tags( str_ireplace( array( '<br/>', '<br />', '<br>' ), "\n", $html ) ) ) ) );
				if ( $lines ) {
					return html_entity_decode( implode( "\n", $lines ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		$lines = array( $parts['address_1'], $parts['address_2'], trim( $parts['postcode'] . ' ' . $parts['city'] ), $name );
		return implode( "\n", array_filter( $lines, static fn( $line ) => '' !== $line ) );
	}

	/** A link setting in the current language, '' when empty or invalid. */
	private static function url( string $key ): string {
		return self::clean_url( self::text( $key ) );
	}

	/** {terms_url}: the setting, else the WooCommerce terms and conditions page ('' when none). */
	public static function terms_url(): string {
		$url = self::url( self::TERMS_OPTION );
		if ( '' === $url && function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$page = (int) wc_terms_and_conditions_page_id();
			$url  = $page > 0 ? (string) get_permalink( $page ) : '';
		}
		return $url;
	}

	/** {privacy_url}: the setting, else the WordPress privacy policy page ('' when none is published). */
	public static function privacy_url(): string {
		$url = self::url( self::PRIVACY_OPTION );
		return '' === $url && function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : $url;
	}

	/** {withdrawal_url}: the setting ('' when empty – there is no default page). */
	public static function withdrawal_url(): string {
		return self::url( self::WITHDRAWAL_OPTION );
	}

	/** {legal_text}: the free legal text ('' when empty). */
	public static function legal_text(): string {
		return self::text( self::TEXT_OPTION );
	}

	/**
	 * Placeholder values (token => text) for Placeholders::values().
	 *
	 * @return array<string, string>
	 */
	public static function placeholders(): array {
		return array(
			'issuer'         => self::issuer(),
			'legal_text'     => self::legal_text(),
			'terms_url'      => self::terms_url(),
			'privacy_url'    => self::privacy_url(),
			'withdrawal_url' => self::withdrawal_url(),
		);
	}

	/**
	 * Block "Issuer and legal texts" of the section "General" (Settings::general_fields()). The placeholder of each
	 * field shows what applies while it is empty.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		$store   = self::store_address();
		$terms   = function_exists( 'wc_terms_and_conditions_page_id' ) && (int) wc_terms_and_conditions_page_id() > 0 ? (string) get_permalink( (int) wc_terms_and_conditions_page_id() ) : '';
		$privacy = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		$check   = __( 'Not legally checked by the plugin – have it checked for each country you sell to.', 'voucher-captain' );
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Issuer and legal texts', 'voucher-captain' ),
				'desc'  => __( 'Printed only where a PDF template or an email text uses the placeholders {issuer}, {legal_text}, {terms_url}, {privacy_url} or {withdrawal_url}; existing templates and emails stay unchanged. Empty fields use the shop details: the site name, the WooCommerce store address, the sender address of the WooCommerce emails, the WooCommerce terms and conditions page and the privacy policy page. With WPML or Polylang the texts are translated per language (String Translation).', 'voucher-captain' ),
				'id'    => 'vcap_legal_info',
			),
			SettingsRegistry::field(
				self::NAME_OPTION,
				array(
					'title'       => __( 'Issuer name', 'voucher-captain' ),
					'type'        => 'text',
					'placeholder' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
					'desc_tip'    => __( 'Company or person that issues the vouchers, e.g. with the legal form. Empty = the site name.', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::ADDRESS_OPTION,
				array(
					'title'       => __( 'Issuer address', 'voucher-captain' ),
					'type'        => 'textarea',
					'css'         => 'width:100%;max-width:400px;height:70px;',
					'placeholder' => $store,
					'desc_tip'    => __( 'Postal address, one line per part. Empty = the store address of WooCommerce (Settings → General).', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::CONTACT_OPTION,
				array(
					'title'       => __( 'Issuer contact', 'voucher-captain' ),
					'type'        => 'text',
					'placeholder' => self::sender_address(),
					'desc_tip'    => __( 'E.g. phone and email address for questions about vouchers. Empty = the sender address of the WooCommerce emails (WooCommerce → Settings → Emails); no contact line if that is empty too.', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::TERMS_OPTION,
				array(
					'title'       => __( 'Terms and conditions (link)', 'voucher-captain' ),
					'type'        => 'url',
					'placeholder' => $terms,
					'desc_tip'    => __( 'Placeholder {terms_url}. Empty = the terms and conditions page of WooCommerce (Settings → Advanced).', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::PRIVACY_OPTION,
				array(
					'title'       => __( 'Privacy policy (link)', 'voucher-captain' ),
					'type'        => 'url',
					'placeholder' => $privacy,
					'desc_tip'    => __( 'Placeholder {privacy_url}. Empty = the privacy policy page of WordPress (Settings → Privacy).', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::WITHDRAWAL_OPTION,
				array(
					'title'    => __( 'Right of withdrawal (link)', 'voucher-captain' ),
					'type'     => 'url',
					'desc_tip' => __( 'Placeholder {withdrawal_url}: page with the cancellation policy. Empty = no link.', 'voucher-captain' ) . ' ' . $check,
				)
			),
			SettingsRegistry::field(
				self::TEXT_OPTION,
				array(
					'title'    => __( 'Legal text for PDF and email', 'voucher-captain' ),
					'type'     => 'textarea',
					'css'      => 'width:100%;max-width:400px;height:80px;',
					'desc_tip' => __( 'Placeholder {legal_text}: free text, e.g. legal notice or information on the right of withdrawal, printed where a PDF template or email text uses it. Plain text. Empty = nothing.', 'voucher-captain' ) . ' ' . $check,
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vcap_legal_info',
			),
		);
	}
}
