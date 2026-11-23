<?php
/**
 * Markup shared by the controls inside the "Vouchers" settings form (PV-P5-2, PV-P5-4, PV-P5-7).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The "Vouchers" settings tab is one WooCommerce form. Some blocks in it (country profile, export/import/reset) have
 * their own buttons that post to admin-post.php. This class keeps them from disturbing the form:
 *
 * - default_submit(): a visually hidden "Save changes" button printed as the first element of every section, so
 *   Enter in a field saves (the browser submits with the first submit button of the form) instead of running the
 *   first action button of the page.
 * - action_button(): an action button that clears WooCommerce's "unsaved changes" prompt when clicked.
 * - PREVENT_CHANGE: class of the cell around such controls; WooCommerce then does not count a change in them as an
 *   unsaved change of the settings.
 * - notice(): the message after an action, announced (role="alert") and linked to the control it is about.
 *
 * @since 1.3.0
 */
final class SettingsUi {
	/** Field type of the hidden default button (Settings::output() puts it first). */
	public const DEFAULT_SUBMIT_TYPE = 'vcap_default_submit';

	/** WooCommerce class: changes inside an element with it are not "unsaved changes" of the settings. */
	public const PREVENT_CHANGE = 'wc-settings-prevent-change-event';

	/** Clears WooCommerce's unsaved-changes prompt (assets/js/admin/settings.js sets window.onbeforeunload). */
	private const CLEAR_PROMPT = 'window.onbeforeunload=null;';

	/** PV-P2-7: a second click on an action button is ignored (the first request runs). */
	private const ONCE = "if(this.dataset.vcapSent){return false;}this.dataset.vcapSent='1';this.setAttribute('aria-disabled','true');";

	/**
	 * The field that prints the default button.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_submit_field(): array {
		return array(
			'type'      => self::DEFAULT_SUBMIT_TYPE,
			'id'        => self::DEFAULT_SUBMIT_TYPE,
			'is_option' => false,
		);
	}

	/**
	 * The hidden default button: same name and value as WooCommerce's "Save changes", not reachable with Tab and
	 * hidden from screen readers (the visible button stays the one they use).
	 */
	public static function default_submit(): string {
		$label = __( 'Save changes', 'voucher-captain' );
		return '<button type="submit" name="save" value="' . esc_attr( $label ) . '" class="screen-reader-text vcap-default-submit" tabindex="-1" aria-hidden="true" onclick="' . esc_attr( self::CLEAR_PROMPT ) . '">' . esc_html( $label ) . '</button>';
	}

	/** Action "woocommerce_admin_field_vcap_default_submit". */
	public static function render_default_submit(): void {
		echo self::default_submit(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in default_submit().
	}

	/**
	 * A submit button of the settings form that posts to an admin-post action instead of saving (escaped HTML).
	 *
	 * @param string $action admin-post action.
	 * @param string $label  Button text.
	 * @param string $class  CSS classes.
	 */
	public static function action_button( string $action, string $label, string $class = 'button' ): string {
		return '<button type="submit" class="' . esc_attr( $class ) . '" formnovalidate formaction="' . esc_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ) ) . '" onclick="' . esc_attr( self::CLEAR_PROMPT . self::ONCE ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * The message after an action (escaped HTML): an error is announced at once (role="alert"), a success politely
	 * (role="status"); both can take the focus (tabindex="-1", id) so the control can point at them.
	 *
	 * @param array<string, mixed> $notice Notice: type ('error'|'success'), text.
	 * @param string               $id     Element id.
	 */
	public static function notice( array $notice, string $id ): string {
		$error = 'error' === ( $notice['type'] ?? '' );
		return '<div id="' . esc_attr( $id ) . '" class="notice inline ' . ( $error ? 'notice-error' : 'notice-success' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" tabindex="-1"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p></div>';
	}

	/**
	 * A wide table in a box that scrolls sideways on small screens instead of the whole page (escaped HTML in,
	 * escaped HTML out).
	 *
	 * @param string $table Table markup.
	 */
	public static function scroll( string $table ): string {
		return '<div class="vcap-table-scroll" style="overflow-x:auto;max-width:100%;">' . $table . '</div>';
	}

	/**
	 * A stored value as people read it (PV-P5-6): yes/no of a switch translated, an empty value named.
	 *
	 * @param string $text Value as SettingsTransfer::display() shows it.
	 * @param string $type Type in the settings register ('bool' = yes/no switch).
	 */
	public static function readable( string $text, string $type = '' ): string {
		if ( '' === $text ) {
			return __( '(empty)', 'voucher-captain' );
		}
		if ( 'bool' === $type || 'checkbox' === $type ) {
			if ( 'yes' === $text ) {
				return __( 'Yes', 'voucher-captain' );
			}
			if ( 'no' === $text ) {
				return __( 'No', 'voucher-captain' );
			}
		}
		return $text;
	}
}
