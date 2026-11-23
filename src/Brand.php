<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Product name and logo as shown to people (admin notices, Site Health, support info, privacy text, scanner, PDF
 * metadata). An add-on may rebrand them (white label) through the filters `vcap_brand_name` and `vcap_brand_logo`;
 * technical identifiers (text domain, slugs, hook names, the name in integration contracts) never change.
 */
final class Brand {
	/** Contexts passed to the filters (an add-on may treat them differently). */
	public const CONTEXTS = array( 'admin', 'notice', 'site_health', 'privacy', 'pdf', 'scanner' );

	/** Shown product name, default "Voucher Captain". */
	public static function name( string $context = 'admin' ): string {
		$default = __( 'Voucher Captain', 'voucher-captain' );
		/**
		 * Product name shown to people (white label). Not used for technical identifiers.
		 *
		 * @since 1.2.0
		 * @param string $name    Default "Voucher Captain" (translated).
		 * @param string $context admin, notice, site_health, privacy, pdf or scanner.
		 */
		$name = apply_filters( 'vcap_brand_name', $default, $context );
		$name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
		return '' !== $name ? $name : $default;
	}

	/** URL of a logo to show next to the name, '' for none (the core shows no logo of its own). */
	public static function logo( string $context = 'admin' ): string {
		/**
		 * Logo shown next to the product name (white label); '' shows none.
		 *
		 * @since 1.2.0
		 * @param string $url     Default ''.
		 * @param string $context admin, notice, site_health, privacy, pdf or scanner.
		 */
		$url = apply_filters( 'vcap_brand_logo', '', $context );
		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/** Title of the main admin menu, default "Vouchers". */
	public static function menu_title(): string {
		$default = __( 'Vouchers', 'voucher-captain' );
		/**
		 * Title of the main admin menu (white label).
		 *
		 * @since 1.2.0
		 * @param string $title Default "Vouchers" (translated).
		 */
		$title = apply_filters( 'vcap_admin_menu_title', $default );
		$title = is_string( $title ) ? trim( wp_strip_all_tags( $title ) ) : '';
		return '' !== $title ? $title : $default;
	}

	/** Icon of the main admin menu: a dashicon class, an image URL or a data URI (like add_menu_page()). */
	public static function menu_icon(): string {
		/**
		 * Icon of the main admin menu (white label), as accepted by add_menu_page().
		 *
		 * @since 1.2.0
		 * @param string $icon Default "dashicons-tickets-alt".
		 */
		$icon = apply_filters( 'vcap_admin_menu_icon', 'dashicons-tickets-alt' );
		return is_string( $icon ) && '' !== trim( $icon ) ? trim( $icon ) : 'dashicons-tickets-alt';
	}
}
