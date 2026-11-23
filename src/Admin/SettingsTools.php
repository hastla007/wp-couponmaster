<?php
/**
 * "Privacy & advanced": export, import and reset of the settings, change log (SET-VC-14, SET-VC-15, SET-VC-16).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

use VCAP\Settings;
use VCAP\SettingsLog;
use VCAP\SettingsRegistry;
use VCAP\SettingsTransfer;
use VCAP\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Admin side of VCAP\SettingsTransfer and VCAP\SettingsLog. The controls sit inside the WooCommerce settings form;
 * their buttons post to admin-post.php (formaction) with an own nonce, so "Save changes" never imports or resets.
 * Every action needs "manage_woocommerce" and the nonce. An import is checked as a whole, then shown as a preview
 * (current → new) and applied only after a second click; a reset takes an automatic backup first.
 *
 * @since 1.3.0
 */
final class SettingsTools {
	/** Capability for every action. */
	public const CAP = 'manage_woocommerce';

	/** Nonce action and field. */
	public const NONCE = 'vcap_settings_tools';
	public const NONCE_FIELD = 'vcap_settings_nonce';

	/** Field types rendered here. */
	public const TOOLS_TYPE = 'vcap_settings_tools';
	public const LOG_TYPE   = 'vcap_settings_log';

	/** User transients: pending import (preview) and the message after an action. */
	private const PENDING = 'vcap_settings_import_';
	private const NOTICE  = 'vcap_settings_notice_';

	/** Entries of the change log shown on the page. */
	private const LOG_ROWS = 50;

	/** Hooks. */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::TOOLS_TYPE, array( self::class, 'render_tools' ) );
		add_action( 'woocommerce_admin_field_' . self::LOG_TYPE, array( self::class, 'render_log' ) );
		add_action( 'admin_post_vcap_settings_export', array( self::class, 'export' ) );
		add_action( 'admin_post_vcap_settings_backup', array( self::class, 'download_backup' ) );
		add_action( 'admin_post_vcap_settings_import', array( self::class, 'import' ) );
		add_action( 'admin_post_vcap_settings_import_apply', array( self::class, 'import_apply' ) );
		add_action( 'admin_post_vcap_settings_import_cancel', array( self::class, 'import_cancel' ) );
		add_action( 'admin_post_vcap_settings_reset', array( self::class, 'reset' ) );
	}

	/**
	 * Fields of "Privacy & advanced" (appended by Settings::privacy_fields()).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Export, import and reset settings', 'voucher-captain' ),
				'id'    => 'vcap_settings_tools',
				'desc'  => __( 'The export contains every global setting of this tab as a JSON file, never keys, passwords or other secrets, and no product settings or voucher data. An import is checked completely and shown as a preview before anything is changed.', 'voucher-captain' ),
			),
			array(
				'title'     => __( 'Settings file', 'voucher-captain' ),
				'id'        => 'vcap_settings_tools_field',
				'type'      => self::TOOLS_TYPE,
				'is_option' => false,
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_settings_tools' ),
			array(
				'type'  => 'title',
				'title' => __( 'Change log of the settings', 'voucher-captain' ),
				'id'    => 'vcap_settings_log_block',
				'desc'  => __( 'Who changed which setting when, from which value to which (secrets masked), from the settings page, an import, a reset or WP-CLI.', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				SettingsLog::DAYS_OPTION,
				array(
					'title'             => __( 'Keep the change log for (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Entries older than this are removed by the daily maintenance (default 365). 0 = keep (only the newest 2,000 entries are kept).', 'voucher-captain' ),
				)
			),
			array(
				'title'     => __( 'Latest changes', 'voucher-captain' ),
				'id'        => 'vcap_settings_log_field',
				'type'      => self::LOG_TYPE,
				'is_option' => false,
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_settings_log_block' ),
		);
	}

	/** URL of the section (after an action). */
	public static function page_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=vcap&section=privacy' ) . '#vcap_settings_tools-description';
	}

	/** URL of an admin-post action with the nonce (downloads). */
	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action ) + $args, admin_url( 'admin-post.php' ) ), self::NONCE, self::NONCE_FIELD );
	}

	/** A submit button of the settings form that posts to an admin-post action instead of saving. */
	private static function button( string $action, string $label, string $class = 'button' ): string {
		return '<button type="submit" class="' . esc_attr( $class ) . '" formnovalidate formaction="' . esc_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ) ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Sections a reset can target: 'all' and the sections of the tab ('general' = General).
	 *
	 * @return array<string, string>
	 */
	public static function reset_targets(): array {
		$out = array( 'all' => __( 'All sections', 'voucher-captain' ) );
		foreach ( ( new Settings() )->section_list() as $id => $label ) {
			$out[ '' === $id ? 'general' : $id ] = $label;
		}
		return $out;
	}

	/**
	 * Section id of a reset target (null = all sections), false when unknown.
	 *
	 * @return string|null|false
	 */
	public static function reset_section( string $target ) {
		if ( 'all' === $target ) {
			return null;
		}
		if ( ! array_key_exists( $target, self::reset_targets() ) ) {
			return false;
		}
		return 'general' === $target ? '' : $target;
	}

	/**
	 * Export, import (with preview), reset and backups.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render_tools( $field ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$user   = get_current_user_id();
		$notice = get_transient( self::NOTICE . $user );
		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE . $user );
		}
		$pending = get_transient( self::PENDING . $user );
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( (string) ( $field['title'] ?? '' ) ) . '</th><td class="forminp">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
		if ( is_array( $notice ) ) {
			$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice inline ' . esc_attr( $class ) . '" role="status"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p></div>';
		}
		if ( is_array( $pending ) && isset( $pending['settings'] ) && is_array( $pending['settings'] ) ) {
			self::render_preview( $pending );
		}

		echo '<h4>' . esc_html__( 'Export', 'voucher-captain' ) . '</h4>';
		echo '<p><a class="button" href="' . esc_url( self::action_url( 'vcap_settings_export' ) ) . '">' . esc_html__( 'Download settings (JSON)', 'voucher-captain' ) . '</a></p>';

		echo '<h4>' . esc_html__( 'Import', 'voucher-captain' ) . '</h4>';
		echo '<p><label for="vcap_settings_file">' . esc_html__( 'Settings file (JSON export of Voucher Captain)', 'voucher-captain' ) . '</label><br>';
		echo '<input type="file" id="vcap_settings_file" name="vcap_settings_file" accept=".json,application/json"> ';
		echo self::button( 'vcap_settings_import', __( 'Check file and show changes', 'voucher-captain' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().

		echo '<h4>' . esc_html__( 'Reset to defaults', 'voucher-captain' ) . '</h4>';
		echo '<p class="description" id="vcap_settings_reset_desc">' . esc_html__( 'Sets the chosen settings back to what a new installation has. A backup of all settings is saved first. Secrets, the data removal switch, product settings and vouchers are not changed.', 'voucher-captain' ) . '</p>';
		echo '<p><label for="vcap_settings_reset_target">' . esc_html__( 'Section', 'voucher-captain' ) . '</label> <select id="vcap_settings_reset_target" name="vcap_settings_reset_target" aria-describedby="vcap_settings_reset_desc">';
		foreach ( self::reset_targets() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label><input type="checkbox" name="vcap_settings_reset_confirm" value="1"> ' . esc_html__( 'Yes, reset these settings to their defaults.', 'voucher-captain' ) . '</label></p>';
		echo '<p>' . self::button( 'vcap_settings_reset', __( 'Reset settings', 'voucher-captain' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().

		$backups = SettingsTransfer::backups();
		if ( $backups ) {
			echo '<h4>' . esc_html__( 'Automatic backups', 'voucher-captain' ) . '</h4><ul>';
			foreach ( $backups as $time => $backup ) {
				$reason = SettingsLog::VIA_RESET === $backup['reason'] ? __( 'before a reset', 'voucher-captain' ) : __( 'before an import', 'voucher-captain' );
				echo '<li><a href="' . esc_url( self::action_url( 'vcap_settings_backup', array( 'backup' => $time ) ) ) . '">' . esc_html( wp_date( 'Y-m-d H:i', $time ) ) . '</a> – ' . esc_html( $reason ) . '</li>';
			}
			echo '</ul><p class="description">' . esc_html__( 'Download a backup and import it to restore the settings it contains.', 'voucher-captain' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Preview of a pending import.
	 *
	 * @param array<string, mixed> $pending Pending import (settings, unknown).
	 */
	private static function render_preview( array $pending ): void {
		$diff = SettingsTransfer::diff( (array) $pending['settings'] );
		echo '<div class="vcap-settings-preview" role="region" aria-labelledby="vcap_settings_preview_title">';
		echo '<h4 id="vcap_settings_preview_title">' . esc_html__( 'Preview of the import', 'voucher-captain' ) . '</h4>';
		if ( ! $diff ) {
			echo '<p>' . esc_html__( 'The file contains no changes: every setting already has this value.', 'voucher-captain' ) . '</p>';
		} else {
			/* translators: %d: number of settings */
			echo '<p>' . esc_html( sprintf( _n( '%d setting will change:', '%d settings will change:', count( $diff ), 'voucher-captain' ), count( $diff ) ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Setting', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'Current value', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'New value', 'voucher-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $diff as $key => $change ) {
				echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( SettingsTransfer::display( $change['from'] ) ) . '</td><td>' . esc_html( SettingsTransfer::display( $change['to'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$unknown = array_map( 'strval', (array) ( $pending['unknown'] ?? array() ) );
		if ( $unknown ) {
			/* translators: %s: option names */
			echo '<p class="description">' . esc_html( sprintf( __( 'Not known on this site and skipped: %s', 'voucher-captain' ), implode( ', ', $unknown ) ) ) . '</p>';
		}
		echo '<p>';
		if ( $diff ) {
			echo self::button( 'vcap_settings_import_apply', __( 'Apply these changes', 'voucher-captain' ), 'button button-primary' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().
		}
		echo self::button( 'vcap_settings_import_cancel', __( 'Cancel import', 'voucher-captain' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().
	}

	/**
	 * The latest entries of the change log.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render_log( $field ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( (string) ( $field['title'] ?? '' ) ) . '</th><td class="forminp">';
		$rows = SettingsLog::latest( self::LOG_ROWS );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No changes recorded yet.', 'voucher-captain' ) . '</p></td></tr>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'voucher-captain' ), __( 'User', 'voucher-captain' ), __( 'Setting', 'voucher-captain' ), __( 'From', 'voucher-captain' ), __( 'To', 'voucher-captain' ), __( 'Source', 'voucher-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = $row['user'] > 0 ? get_userdata( $row['user'] ) : false;
			$name = $user instanceof \WP_User ? $user->display_name : ( $row['user'] > 0 ? '#' . $row['user'] : '—' );
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', $row['time'] ) ) . '</td><td>' . esc_html( $name ) . '</td><td><code>' . esc_html( $row['key'] ) . '</code></td><td>' . esc_html( SettingsTransfer::display( $row['from'] ) ) . '</td><td>' . esc_html( SettingsTransfer::display( $row['to'] ) ) . '</td><td>' . esc_html( SettingsLog::source_label( $row ) ) . '</td></tr>';
		}
		echo '</tbody></table></td></tr>';
	}

	/** Capability and nonce of every action; stops the request otherwise. */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );
	}

	/** Message shown after the redirect, then back to the section. */
	private static function done( string $type, string $text ): never {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Sends a JSON file. */
	private static function send_json_file( string $json, string $name ): never {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON file download.
		exit;
	}

	/** admin-post: download of the export. */
	public static function export(): void {
		self::guard();
		self::send_json_file( SettingsTransfer::export_json(), SettingsTransfer::file_name() );
	}

	/** admin-post: download of an automatic backup. */
	public static function download_backup(): void {
		self::guard();
		$time = isset( $_GET['backup'] ) ? absint( wp_unslash( $_GET['backup'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checked the nonce.
		$json = 0 < $time ? SettingsTransfer::backup_json( $time ) : '';
		if ( '' === $json ) {
			self::done( 'error', __( 'This backup no longer exists.', 'voucher-captain' ) );
		}
		self::send_json_file( $json, 'voucher-captain-settings-backup-' . gmdate( 'Y-m-d-His', $time ) . '.json' );
	}

	/** admin-post: checks the uploaded file and keeps it for the preview. */
	public static function import(): void {
		self::guard();
		$file = isset( $_FILES['vcap_settings_file'] ) && is_array( $_FILES['vcap_settings_file'] ) ? $_FILES['vcap_settings_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- guard() checked the nonce; checked below, content parsed as JSON only.
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $tmp ) ) {
			self::done( 'error', __( 'Please choose a settings file (JSON) to import.', 'voucher-captain' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > SettingsTransfer::MAX_BYTES ) {
			self::done( 'error', __( 'The file is too large for a settings export.', 'voucher-captain' ) );
		}
		$json   = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded file.
		$parsed = SettingsTransfer::parse( $json );
		if ( is_wp_error( $parsed ) ) {
			/* translators: %s: reasons */
			self::done( 'error', sprintf( __( 'The file was not imported, nothing was changed: %s', 'voucher-captain' ), $parsed->get_error_message() ) );
		}
		set_transient( self::PENDING . get_current_user_id(), $parsed, 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** admin-post: applies the pending import (checked again) after a backup. */
	public static function import_apply(): void {
		self::guard();
		$user    = get_current_user_id();
		$pending = get_transient( self::PENDING . $user );
		delete_transient( self::PENDING . $user );
		if ( ! is_array( $pending ) || ! isset( $pending['settings'] ) || ! is_array( $pending['settings'] ) ) {
			self::done( 'error', __( 'The import preview has expired. Please upload the file again.', 'voucher-captain' ) );
		}
		$changed = self::apply_import( $pending['settings'] );
		if ( is_wp_error( $changed ) ) {
			/* translators: %s: reasons */
			self::done( 'error', sprintf( __( 'The file was not imported, nothing was changed: %s', 'voucher-captain' ), $changed->get_error_message() ) );
		}
		/* translators: %d: number of settings */
		self::done( 'success', sprintf( _n( '%d setting imported. A backup of the previous settings was saved.', '%d settings imported. A backup of the previous settings was saved.', count( $changed ), 'voucher-captain' ), count( $changed ) ) );
	}

	/**
	 * Checks the values once more (the register may have changed) and applies them after a backup.
	 *
	 * @param array<string, mixed> $settings Values of the preview.
	 * @return string[]|\WP_Error Option names changed.
	 */
	public static function apply_import( array $settings ) {
		$checked = array();
		foreach ( $settings as $key => $value ) {
			$entry = SettingsRegistry::get( (string) $key );
			$valid = null !== $entry && $entry['export'] ? SettingsTransfer::validate( (string) $key, $value ) : new \WP_Error( 'vcap_setting_unknown', (string) $key );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$checked[ (string) $key ] = $valid;
		}
		if ( SettingsTransfer::diff( $checked ) ) {
			SettingsTransfer::backup( SettingsLog::VIA_IMPORT );
		}
		return Source::with( Source::ADMIN, static fn() => SettingsTransfer::apply( $checked, SettingsLog::VIA_IMPORT ) );
	}

	/** admin-post: forgets the pending import. */
	public static function import_cancel(): void {
		self::guard();
		delete_transient( self::PENDING . get_current_user_id() );
		self::done( 'success', __( 'Import cancelled, nothing was changed.', 'voucher-captain' ) );
	}

	/** admin-post: reset of a section or of all sections, after the confirmation and a backup. */
	public static function reset(): void {
		self::guard();
		$target  = isset( $_POST['vcap_settings_reset_target'] ) ? sanitize_key( wp_unslash( $_POST['vcap_settings_reset_target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$section = self::reset_section( $target );
		if ( false === $section ) {
			self::done( 'error', __( 'Please choose what to reset.', 'voucher-captain' ) );
		}
		if ( empty( $_POST['vcap_settings_reset_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
			self::done( 'error', __( 'Nothing was reset: please tick the confirmation first.', 'voucher-captain' ) );
		}
		$result = Source::with( Source::ADMIN, static fn() => SettingsTransfer::reset( $section ) );
		/* translators: %d: number of settings */
		self::done( 'success', sprintf( _n( '%d setting reset to its default. A backup of the previous settings was saved (see "Automatic backups").', '%d settings reset to their defaults. A backup of the previous settings was saved (see "Automatic backups").', count( $result['changed'] ), 'voucher-captain' ), count( $result['changed'] ) ) );
	}
}
