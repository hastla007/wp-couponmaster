<?php
/**
 * Consent checkbox at the voucher product (SET-VC-12).
 *
 * @package VCAP
 */

namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-12: an optional required checkbox on the product page of a voucher with a text of the shop (e.g. "I have read
 * the voucher terms" or "I want the voucher delivered right away"). Off by default: the global setting
 * "vcap_consent_enabled" switches it on for every voucher product, the product setting "consent" ('' = use the global
 * setting, 'yes' = always, 'no' = never) decides per product; the filter "vcap_consent_required" wins.
 *
 * The checkbox is a built-in field (Fields::builtin_definitions(), key "consent"): checked on the product form, in the
 * Store API add-to-cart request (blocks) and again at checkout (Fields::check_cart_items()). The order line keeps the
 * text as shown and the time of the order ("given_at", UTC) in "_vcap_fields"; the voucher takes it over with its
 * fields, gets a history entry "Consent given" and the personal data export of WordPress lists it.
 *
 * The text and its legal effect (e.g. for the right of withdrawal) are not legally checked.
 *
 * @since 1.3.0
 */
final class Consent {
	/** Key of the built-in field (in "_vcap_fields" of the order line and the voucher). */
	public const KEY = 'consent';

	/** Global setting: checkbox on every voucher product ('yes'/'no'). */
	public const ENABLED_OPTION = 'vcap_consent_enabled';

	/** Global setting: text of the checkbox ('' = default_text()). */
	public const TEXT_OPTION = 'vcap_consent_text';

	/** Product setting (ProductConfig key, meta "_vcap_consent"): '' = global setting, 'yes', 'no'. */
	public const PRODUCT_KEY = 'consent';

	/** Event of the voucher history. */
	public const EVENT = 'purchase_consent';

	/** Code of the history note (args: text, time UTC). */
	public const NOTE = 'consent_given';

	/** Longest text stored (characters). */
	public const MAX_TEXT = 500;

	public static function register(): void {
		add_filter( 'vcap_event_labels', array( self::class, 'event_labels' ) );
		add_filter( 'vcap_note_text', array( self::class, 'note_text' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::TEXT_OPTION, array( self::class, 'sanitize_text' ) );
	}

	/**
	 * Choices of the product setting.
	 *
	 * @return array<string, string>
	 */
	public static function product_choices(): array {
		return array(
			''    => __( 'Use the global setting', 'voucher-captain' ),
			'yes' => __( 'Show the consent checkbox', 'voucher-captain' ),
			'no'  => __( 'Do not show it', 'voucher-captain' ),
		);
	}

	/**
	 * Whether a product with this configuration asks for the consent: its own choice, else the global setting.
	 *
	 * @param array<string, mixed> $config ProductConfig::get().
	 */
	public static function required_for( array $config ): bool {
		$own      = (string) ( $config[ self::PRODUCT_KEY ] ?? '' );
		$required = 'yes' === $own || ( 'no' !== $own && 'yes' === Settings::value( self::ENABLED_OPTION ) );
		/**
		 * Whether the product page of a voucher asks for the consent checkbox (SET-VC-12).
		 *
		 * @since 1.3.0
		 * @param bool                 $required Product setting, else the global setting.
		 * @param array<string, mixed> $config   Voucher configuration of the product.
		 */
		return (bool) apply_filters( 'vcap_consent_required', $required, $config );
	}

	/** Text used while the setting is empty. */
	public static function default_text(): string {
		return __( 'I have read the terms of this voucher and agree to them.', 'voucher-captain' );
	}

	/** Text of the checkbox in the current language (WPML through the option, Polylang through string translation). */
	public static function text(): string {
		$text = trim( (string) Settings::value( self::TEXT_OPTION ) );
		$text = '' !== $text ? Compat\Multilingual::translate( $text ) : self::default_text();
		/**
		 * Text of the consent checkbox on the product page (SET-VC-12); stored with the order as shown.
		 *
		 * @since 1.3.0
		 * @param string $text Setting, else the default text.
		 */
		return self::clean( (string) apply_filters( 'vcap_consent_text', $text ) );
	}

	/** One line of plain text, at most MAX_TEXT characters. */
	private static function clean( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_textarea_field( $text ) ) );
		return mb_substr( $text, 0, self::MAX_TEXT );
	}

	/**
	 * Saving the setting: plain text (no HTML), at most MAX_TEXT characters.
	 *
	 * @param mixed $value Value.
	 */
	public static function sanitize_text( $value ): string {
		return mb_substr( trim( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, self::MAX_TEXT );
	}

	/**
	 * The built-in field.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'key'         => self::KEY,
			'label'       => self::text(),
			'type'        => 'checkbox',
			'required'    => true,
			'maxlength'   => 0,
			'placeholder' => '',
			'tooltip'     => '',
		);
	}

	/** Error message of a missing consent. */
	public static function missing_message( string $label ): string {
		/* translators: %s: text of the consent checkbox */
		return sprintf( __( 'Please confirm "%s" to buy this voucher.', 'voucher-captain' ), $label );
	}

	/**
	 * Fields of an order line with the time of the consent (UTC), set when the order is created.
	 *
	 * @param array<string, mixed> $fields Cart item "vcap_fields".
	 * @return array<string, mixed>
	 */
	public static function stamp( array $fields, ?int $time = null ): array {
		if ( isset( $fields[ self::KEY ] ) && is_array( $fields[ self::KEY ] ) && '' !== (string) ( $fields[ self::KEY ]['value'] ?? '' ) ) {
			$fields[ self::KEY ]['given_at'] = gmdate( 'Y-m-d H:i:s', $time ?? time() );
		}
		return $fields;
	}

	/**
	 * The consent kept in the fields of an order line or a voucher: text and time (UTC), null when none.
	 *
	 * @param mixed $fields "_vcap_fields" / Voucher::$fields.
	 * @return array{text: string, given_at: string}|null
	 */
	public static function of( $fields ): ?array {
		$field = is_array( $fields ) && is_array( $fields[ self::KEY ] ?? null ) ? $fields[ self::KEY ] : null;
		if ( null === $field || '' === (string) ( $field['value'] ?? '' ) ) {
			return null;
		}
		return array(
			'text'     => (string) ( $field['label'] ?? '' ),
			'given_at' => (string) ( $field['given_at'] ?? '' ),
		);
	}

	/** History entry "Consent given" of a voucher just issued with a consent (nothing without one). */
	public static function log( VoucherRepository $repo, Voucher $voucher, int $order_id = 0 ): bool {
		$consent = self::of( $voucher->fields );
		if ( null === $consent || ! $voucher->id ) {
			return false;
		}
		return $repo->log( (int) $voucher->id, self::EVENT, null, null, Note::make( self::NOTE, array( $consent['text'], $consent['given_at'] ) ), $order_id );
	}

	/**
	 * Label of the history event.
	 *
	 * @param mixed $labels Labels.
	 * @return array<string, string>
	 */
	public static function event_labels( $labels ): array {
		$labels                = is_array( $labels ) ? $labels : array();
		$labels[ self::EVENT ] = __( 'Consent given', 'voucher-captain' );
		return $labels;
	}

	/**
	 * Text of the history note.
	 *
	 * @param mixed  $text Text so far.
	 * @param string $code Note code.
	 * @param array  $args Arguments (text, time UTC).
	 */
	public static function note_text( $text, $code = '', $args = array() ) {
		if ( self::NOTE !== $code || ! is_array( $args ) ) {
			return $text;
		}
		$at = (string) ( $args[1] ?? '' );
		return '' !== $at
			/* translators: 1: text of the consent checkbox, 2: date and time */
			? sprintf( __( '"%1$s" confirmed with the order (%2$s).', 'voucher-captain' ), (string) ( $args[0] ?? '' ), self::local_time( $at ) )
			/* translators: %s: text of the consent checkbox */
			: sprintf( __( '"%s" confirmed with the order.', 'voucher-captain' ), (string) ( $args[0] ?? '' ) );
	}

	/** A UTC time "Y-m-d H:i:s" in the site's date and time format. */
	public static function local_time( string $utc ): string {
		$ts = strtotime( $utc . ' UTC' );
		if ( false === $ts ) {
			return $utc;
		}
		return wp_date( trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) ), $ts );
	}

	/**
	 * Rows of the personal data export (WordPress exporter, Privacy::export()).
	 *
	 * @param mixed $fields Voucher::$fields.
	 * @return array<int, array{name: string, value: string}>
	 */
	public static function export_rows( $fields ): array {
		$consent = self::of( $fields );
		if ( null === $consent ) {
			return array();
		}
		return array(
			array(
				'name'  => __( 'Consent', 'voucher-captain' ),
				'value' => $consent['text'],
			),
			array(
				'name'  => __( 'Consent given at (UTC)', 'voucher-captain' ),
				'value' => $consent['given_at'],
			),
		);
	}

	/**
	 * Block "Consent checkbox" of the section "General" (SET-VC-12).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Consent checkbox', 'voucher-captain' ),
				'desc'  => __( 'A required checkbox on the product page of vouchers, e.g. to confirm the voucher terms or to ask for immediate delivery. The text and the time of the order are kept with the order and the voucher and are part of the personal data export. Not legally checked: have the text checked for your country.', 'voucher-captain' ),
				'id'    => 'vcap_consent',
			),
			SettingsRegistry::field(
				self::ENABLED_OPTION,
				array(
					'title' => __( 'Consent checkbox', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Ask for the consent on every voucher product (each product can override this under "Personalisation & delivery").', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::TEXT_OPTION,
				array(
					'title'       => __( 'Text of the checkbox', 'voucher-captain' ),
					'type'        => 'textarea',
					'css'         => 'width:100%;max-width:560px;height:4em',
					'placeholder' => self::default_text(),
					/* translators: %d: number of characters */
					'desc'        => sprintf( __( 'Plain text, up to %d characters; empty = the text shown as placeholder. A changed text applies to new orders only.', 'voucher-captain' ), self::MAX_TEXT ),
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vcap_consent',
			),
		);
	}
}
