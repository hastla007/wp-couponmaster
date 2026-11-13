<?php
/**
 * License activation and status screen (Vouchers → License, premium edition only).
 *
 * @package voucher-captain
 */

namespace VCAP\Licensing;

use VCAP\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a site administrator activate or deactivate this product license and see what it grants.
 */
final class AdminPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_post_vcap_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_post_vcap_deactivate_license', array( $this, 'deactivate' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'vcap-vouchers',
			__( 'License', 'voucher-captain' ),
			__( 'License', 'voucher-captain' ),
			'manage_options',
			Entitlements::PAGE,
			array( $this, 'render' )
		);
	}

	/** Translated license status. */
	public static function status_label( string $status ): string {
		$labels = array(
			'active'    => __( 'Active', 'voucher-captain' ),
			'past_due'  => __( 'Payment overdue', 'voucher-captain' ),
			'suspended' => __( 'Suspended', 'voucher-captain' ),
			'expired'   => __( 'Expired', 'voucher-captain' ),
			'revoked'   => __( 'Revoked', 'voucher-captain' ),
			'cancelled' => __( 'Cancelled', 'voucher-captain' ),
		);
		return $labels[ $status ] ?? ( '' !== $status ? $status : __( 'Unknown', 'voucher-captain' ) );
	}

	/** A UTC date of the license server in the site's date format and time zone ('' when empty or invalid). */
	public static function date_label( $value ): string {
		$time = is_string( $value ) && '' !== $value ? strtotime( $value . ' UTC' ) : false;
		return $time ? wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $time ) : '';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$server      = Entitlements::server_url();
		$credentials = get_option( Entitlements::CREDENTIALS_OPTION, array() );
		$license     = null;
		$error       = '';
		if ( is_array( $credentials ) && ! empty( $credentials['installation_id'] ) && '' !== $server ) {
			// get_entitlements(): online check that also refreshes the signed snapshot the premium gate reads.
			$license = Entitlements::client()->get_entitlements();
			Entitlements::reset();
			if ( is_wp_error( $license ) ) {
				Logger::wp_error( 'License check on the license page failed.', $license );
				$error   = $license->get_error_message();
				$license = null;
			}
		}
		$notice_key = 'vcap_license_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );
		echo '<div class="wrap"><h1>' . esc_html__( 'License', 'voucher-captain' ) . '</h1>';
		if ( is_array( $notice ) ) {
			$notice_class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( is_array( $license ) ) {
			$this->render_status( $license );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate the license on this website? Premium features are switched off until the license is activated again. Vouchers sold earlier keep working.', 'voucher-captain' ) ) ) . ');"><input type="hidden" name="action" value="vcap_deactivate_license">';
			wp_nonce_field( 'vcap_deactivate_license' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Deactivate this site', 'voucher-captain' ) . '</button></p></form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vcap_activate_license">';
			wp_nonce_field( 'vcap_activate_license' );
			echo '<table class="form-table"><tr><th><label for="wplc-server">' . esc_html__( 'License server URL', 'voucher-captain' ) . '</label></th><td>';
			if ( Entitlements::server_pinned() ) {
				// Fixed in the premium build: shown, never editable.
				echo '<input id="wplc-server" type="url" class="regular-text" value="' . esc_attr( $server ) . '" readonly>';
				echo '<p class="description">' . esc_html__( 'The license server is fixed in this build.', 'voucher-captain' ) . '</p>';
			} else {
				echo '<input id="wplc-server" name="server_url" type="url" class="regular-text" value="' . esc_attr( $server ) . '" required placeholder="https://licenses.example.com">';
			}
			echo '</td></tr>';
			echo '<tr><th><label for="wplc-key">' . esc_html__( 'License key', 'voucher-captain' ) . '</label></th><td><input id="wplc-key" name="license_key" type="password" class="regular-text" autocomplete="off" required></td></tr></table>';
			echo '<p>' . esc_html__( 'The license key is used once for activation and is not stored by this plugin. Activation credentials are stored locally without autoloading.', 'voucher-captain' ) . '</p>';
			echo '<p>' . esc_html__( 'Activation and the twice-daily license check send this website\'s address, an installation ID and the WordPress, PHP and Voucher Captain versions to the license server.', 'voucher-captain' ) . '</p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate license', 'voucher-captain' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	/** Status, dates and rights as a readable list. */
	private function render_status( array $license ): void {
		$status = (string) ( $license['license_status'] ?? '' );
		$paid   = self::date_label( $license['paid_until'] ?? '' );
		$rows   = array(
			__( 'Status', 'voucher-captain' )       => self::status_label( $status ),
			__( 'Paid through', 'voucher-captain' ) => '' !== $paid ? $paid : __( 'No end date', 'voucher-captain' ),
			/* translators: 1: websites using the license, 2: allowed websites */
			__( 'Websites', 'voucher-captain' )     => sprintf( __( '%1$d of %2$d', 'voucher-captain' ), (int) ( $license['site_count'] ?? 0 ), (int) ( $license['site_limit'] ?? 0 ) ),
		);
		$checked = self::date_label( $license['server_time'] ?? '' );
		if ( '' !== $checked ) {
			$rows[ __( 'Last checked', 'voucher-captain' ) ] = $checked;
		}
		echo '<h2>' . esc_html__( 'Current license status', 'voucher-captain' ) . '</h2><table class="widefat striped" style="max-width:40em"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Feature rights', 'voucher-captain' ) . '</h3><ul class="vcap-license-rights">';
		$client = Entitlements::client();
		foreach ( Entitlements::features() as $feature => $label ) {
			$granted = $client && 'active' === $status && $client->allows_entitlement( 'voucher.' . $feature, $license );
			echo '<li>' . ( $granted ? '✔ ' : '– ' ) . esc_html( $label ) . ' – ' . esc_html( $granted ? __( 'included', 'voucher-captain' ) : __( 'not included', 'voucher-captain' ) ) . '</li>';
		}
		echo '</ul>';
	}

	public function activate(): void {
		$this->authorize( 'vcap_activate_license' );
		if ( Entitlements::server_pinned() ) {
			$server = Entitlements::server_url();
		} else {
			$server = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ), array( 'https' ) ) : '';
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' === $server || '' === $key ) {
			$this->redirect( 'error', __( 'Enter a valid HTTPS license server URL and license key.', 'voucher-captain' ) );
		}
		if ( ! Entitlements::server_pinned() ) {
			update_option( Entitlements::SERVER_OPTION, $server, false );
		}
		$result = ( new LicenseClient( $server, 'voucher-captain', VCAP_VERSION, Entitlements::CREDENTIALS_OPTION ) )->activate( $key );
		if ( is_wp_error( $result ) ) {
			Logger::wp_error( 'License activation failed.', $result );
			$this->redirect( 'error', $result->get_error_message() );
		}
		Entitlements::refresh();
		PremiumUpdater::flush();
		$this->redirect( 'success', __( 'License activated for this site.', 'voucher-captain' ) );
	}

	public function deactivate(): void {
		$this->authorize( 'vcap_deactivate_license' );
		$client = Entitlements::client();
		$result = $client ? $client->deactivate() : new \WP_Error( 'wplc_configuration_invalid', __( 'License server settings or license key are missing.', 'voucher-captain' ) );
		if ( is_wp_error( $result ) ) {
			Logger::wp_error( 'License deactivation failed.', $result );
			$this->redirect( 'error', $result->get_error_message() );
		}
		Entitlements::reset();
		PremiumUpdater::flush();
		$this->redirect( 'success', __( 'This site was deactivated.', 'voucher-captain' ) );
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this license.', 'voucher-captain' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $type, string $message ): void {
		set_transient( 'vcap_license_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( Entitlements::page_url() );
		exit;
	}
}
