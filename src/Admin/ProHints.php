<?php
/**
 * Hint lines for functions of the separate add-on (SET-VC-07, owner's decision S1 of 23.11.2026).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Appends a grey text line per add-on function at the end of a settings section of the "Vouchers" tab.
 *
 * Rules (docs/FREE-PREMIUM.md rule 3): text only – no input, no disabled control, no option, no form value, no
 * network request, no license check; the lines are listed in config/pro-hints.php. The add-on replaces a line by
 * registering its real field (through "vcap_settings_fields") with 'replaces' => '<hint id>'. Every user who may
 * manage the shop can hide the lines for themselves ("Hide hints on extensions"; user setting).
 *
 * @since 1.3.0
 */
final class ProHints {
	/** User setting: '1' = the user hid the hint lines. */
	public const USER_META = 'vcap_hide_pro_hints';

	/** Field type rendered by render(). */
	public const FIELD_TYPE = 'vcap_hint';

	/** Admin-post action of the "hide"/"show" link. */
	public const ACTION = 'vcap_pro_hints';

	/**
	 * Hooks: field type, hide/show link, user profile setting.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'toggle' ) );
		add_action( 'show_user_profile', array( $this, 'profile' ), 20 );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
	}

	/**
	 * The hint lines (config/pro-hints.php, filter "vcap_settings_hints"), checked: id, section, title, text, link.
	 *
	 * @return array<int, array{id:string, section:string, title:string, text:string, link:string}>
	 */
	public static function hints(): array {
		$file = defined( 'VCAP_PATH' ) ? VCAP_PATH . 'config/pro-hints.php' : dirname( __DIR__, 2 ) . '/config/pro-hints.php';
		$list = is_readable( $file ) ? include $file : array();
		/**
		 * Hint lines for add-on functions (args: list of {id, section, title, text, link}). Text only; a line is
		 * dropped when its id, title or text is empty or its link is not https.
		 *
		 * @since 1.3.0
		 * @param array<int, array<string, string>> $hints Hint lines.
		 */
		$list = apply_filters( 'vcap_settings_hints', is_array( $list ) ? $list : array() );
		$out  = array();
		$seen = array();
		foreach ( is_array( $list ) ? $list : array() as $hint ) {
			if ( ! is_array( $hint ) ) {
				continue;
			}
			$id    = sanitize_key( (string) ( $hint['id'] ?? '' ) );
			$title = trim( wp_strip_all_tags( (string) ( $hint['title'] ?? '' ) ) );
			$text  = trim( wp_strip_all_tags( (string) ( $hint['text'] ?? '' ) ) );
			$link  = trim( (string) ( $hint['link'] ?? '' ) );
			if ( '' === $id || '' === $title || '' === $text || isset( $seen[ $id ] ) || ( '' !== $link && ! str_starts_with( $link, 'https://' ) ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'id'      => $id,
				'section' => sanitize_title( (string) ( $hint['section'] ?? '' ) ),
				'title'   => $title,
				'text'    => $text,
				'link'    => $link,
			);
		}
		return $out;
	}

	/**
	 * Whether the current user hid the hint lines.
	 */
	public static function hidden(): bool {
		$user = get_current_user_id();
		/**
		 * Whether the hint lines on add-on functions are hidden for the current user (default: the user's own
		 * setting "Hide hints on extensions").
		 *
		 * @since 1.3.0
		 * @param bool $hidden  Hidden.
		 * @param int  $user_id Current user.
		 */
		return (bool) apply_filters( 'vcap_pro_hints_hidden', $user > 0 && '1' === (string) get_user_meta( $user, self::USER_META, true ), $user );
	}

	/**
	 * The fields of a section with its hint lines appended (after "vcap_settings_fields", so a field of the add-on
	 * with 'replaces' removes its line). At most one block per section, under the heading "Extensions".
	 *
	 * @param array<int, array<string, mixed>> $fields  Fields of the section.
	 * @param string                           $section Section id ('' = General).
	 * @return array<int, array<string, mixed>>
	 */
	public static function append( array $fields, string $section ): array {
		if ( self::hidden() ) {
			return $fields;
		}
		$replaced = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['replaces'] ) ) {
				foreach ( (array) $field['replaces'] as $id ) {
					$replaced[ sanitize_key( (string) $id ) ] = true;
				}
			}
		}
		$lines = array();
		foreach ( self::hints() as $hint ) {
			if ( $hint['section'] === $section && ! isset( $replaced[ $hint['id'] ] ) ) {
				$lines[] = array(
					'id'        => 'vcap_hint_' . $hint['id'],
					'type'      => self::FIELD_TYPE,
					'title'     => $hint['title'],
					'desc'      => $hint['text'],
					'link'      => $hint['link'],
					'is_option' => false,
				);
			}
		}
		if ( array() === $lines ) {
			return $fields;
		}
		$block = 'vcap_pro_hints_' . ( '' === $section ? 'general' : $section );
		$hide  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&hide=1' ), self::ACTION );
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'Extensions', 'voucher-captain' ),
					'type'  => 'title',
					'desc'  => sprintf(
						/* translators: %s: link "Hide hints on extensions" */
						__( 'Functions of the separate add-on Voucher Captain Pro. %s', 'voucher-captain' ),
						'<a href="' . esc_url( $hide ) . '">' . esc_html__( 'Hide hints on extensions', 'voucher-captain' ) . '</a>'
					),
					'id'    => $block,
				),
			),
			$lines,
			array(
				array(
					'type' => 'sectionend',
					'id'   => $block,
				),
			)
		);
	}

	/**
	 * Renders a hint line: title with a "Pro" mark and one sentence – text only, no form control.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render( $field ): void {
		$field = is_array( $field ) ? $field : array();
		$link  = (string) ( $field['link'] ?? '' );
		echo '<tr class="vcap-pro-hint"><th scope="row" class="titledesc">' . esc_html( (string) ( $field['title'] ?? '' ) )
			. ' <span class="vcap-pro-badge" style="color:#646970;border:1px solid #c3c4c7;border-radius:3px;padding:0 4px;font-size:11px;font-weight:400;">' . esc_html__( 'Pro', 'voucher-captain' ) . '</span></th>'
			. '<td class="forminp"><p class="description" style="color:#646970;">' . esc_html( (string) ( $field['desc'] ?? '' ) );
		if ( '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more', 'voucher-captain' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'voucher-captain' ) . '</span></a>';
		}
		echo '</p></td></tr>';
	}

	/**
	 * Hides (or shows again) the hint lines for the current user and returns to the settings page.
	 */
	public function toggle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$hide = ! empty( $_GET['hide'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		update_user_meta( get_current_user_id(), self::USER_META, $hide ? '1' : '0' );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-settings&tab=vcap' ) );
		exit;
	}

	/**
	 * User profile: "Hide hints on extensions" (own profile, shop managers only).
	 *
	 * @param \WP_User $user User.
	 */
	public function profile( $user ): void {
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		wp_nonce_field( self::ACTION . '_profile', 'vcap_pro_hints_nonce' );
		echo '<h2>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">'
			. esc_html__( 'Hints on extensions', 'voucher-captain' ) . '</th><td><label><input type="checkbox" name="vcap_hide_pro_hints" value="1"' . checked( '1', (string) get_user_meta( $user->ID, self::USER_META, true ), false ) . '> '
			. esc_html__( 'Hide hints on extensions in the voucher settings', 'voucher-captain' ) . '</label></td></tr></table>';
	}

	/**
	 * Saves the profile setting.
	 *
	 * @param int $user_id User id.
	 */
	public function save_profile( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! isset( $_POST['vcap_pro_hints_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vcap_pro_hints_nonce'] ) ), self::ACTION . '_profile' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		update_user_meta( $user_id, self::USER_META, empty( $_POST['vcap_hide_pro_hints'] ) ? '0' : '1' );
	}
}
