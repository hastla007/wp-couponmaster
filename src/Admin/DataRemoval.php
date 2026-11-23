<?php
/**
 * "Remove all data when the plugin is deleted" (SET-VC-S10, owner's decision S10 of 23.11.2026).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The setting is off by default (data is kept). Switching it on needs the typed word LÖSCHEN, LOESCHEN or DELETE
 * (any letter case), checked on the server; switching it off and saving other fields need no word. WP-CLI:
 * "wp vcap data-removal on --confirm=LÖSCHEN". On a network each site decides for itself; scheduled jobs are
 * removed on every uninstall (uninstall.php). The setting is never part of an export of settings.
 *
 * Same standard in all Captain plugins (cores, add-ons, connectors with own tables); the option name stays.
 *
 * @since 1.3.0
 */
final class DataRemoval {
	/** The stored setting ('yes' / 'no'), unchanged name. */
	public const OPTION = 'vcap_delete_on_uninstall';

	/** Form field for the confirmation word (not stored). */
	public const CONFIRM_FIELD = 'vcap_delete_on_uninstall_confirm';

	/** Field type rendered by render(). */
	public const FIELD_TYPE = 'vcap_confirm_word';

	/** Accepted confirmation words (compared in upper case). */
	public const WORDS = array( 'LÖSCHEN', 'LOESCHEN', 'DELETE' );

	/**
	 * Hooks: confirmation field and the check when the settings are saved.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION, array( self::class, 'sanitize' ), 10, 1 );
	}

	/**
	 * Whether the text is one of the confirmation words (surrounding spaces and letter case do not matter).
	 *
	 * @param mixed $word Typed text.
	 */
	public static function is_confirmation( $word ): bool {
		if ( ! is_string( $word ) ) {
			return false;
		}
		$word = trim( $word );
		$word = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $word, 'UTF-8' ) : strtoupper( $word );
		return in_array( $word, self::WORDS, true );
	}

	/**
	 * The value to store: switching on without the word keeps the stored value ('no') and shows an error; already on,
	 * switching off or another value pass unchanged.
	 *
	 * @param string      $value New value ('yes' / 'no').
	 * @param string|null $word  Typed word; null reads the settings form (WooCommerce checked its nonce).
	 * @return string
	 */
	public static function sanitize( $value, $word = null ): string {
		$value = 'yes' === $value ? 'yes' : 'no';
		if ( 'yes' !== $value || 'yes' === get_option( self::OPTION, 'no' ) ) {
			return $value;
		}
		if ( null === $word ) {
			$word = isset( $_POST[ self::CONFIRM_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CONFIRM_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the settings nonce.
		}
		if ( self::is_confirmation( $word ) ) {
			return 'yes';
		}
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( __( 'Data removal was not switched on: type LÖSCHEN (or DELETE) in the confirmation field to switch it on. Your data is kept.', 'voucher-captain' ) );
		}
		return 'no';
	}

	/**
	 * Settings fields: the checkbox and, below it, the confirmation field.
	 *
	 * @param string $description Help text of the checkbox (what is deleted).
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( string $description ): array {
		return array(
			\VCAP\SettingsRegistry::field(
				self::OPTION,
				array(
					'title' => __( 'Remove all data when the plugin is deleted', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => $description,
				)
			),
			array(
				'title'     => __( 'Confirm data removal', 'voucher-captain' ),
				'id'        => self::CONFIRM_FIELD,
				'type'      => self::FIELD_TYPE,
				'is_option' => false,
			),
		);
	}

	/**
	 * Confirmation field (never prefilled, nothing stored).
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render( $field ): void {
		$on = 'yes' === get_option( self::OPTION, 'no' );
		echo '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr( self::CONFIRM_FIELD ) . '">' . esc_html( (string) ( $field['title'] ?? '' ) ) . '</label></th><td class="forminp">';
		if ( $on ) {
			echo '<p class="description">' . esc_html__( 'Data removal is switched on. Untick the box above and save to keep the data again; no word is needed for that.', 'voucher-captain' ) . '</p>';
		} else {
			echo '<input type="text" id="' . esc_attr( self::CONFIRM_FIELD ) . '" name="' . esc_attr( self::CONFIRM_FIELD ) . '" value="" autocomplete="off" spellcheck="false" aria-describedby="' . esc_attr( self::CONFIRM_FIELD ) . '-desc" class="regular-text"> '
				. '<p class="description" id="' . esc_attr( self::CONFIRM_FIELD ) . '-desc">' . esc_html__( 'To switch data removal on, tick the box above and type LÖSCHEN (or DELETE) here, then save. Without the word the data is kept.', 'voucher-captain' ) . '</p>';
		}
		echo '</td></tr>';
	}
}
