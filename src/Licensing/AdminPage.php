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
 *
 * Once a site holds an activation the page never offers a new activation: it shows the status (or the last
 * confirmed status when the license server cannot be reached), "Check again", "Deactivate this site" and "Remove
 * locally". An activation copied from another website (staging copy, moved site) is explained and can be moved to
 * this address or removed locally. A network license is managed in the network admin by super admins.
 */
final class AdminPage {
	public function register(): void {
		if ( Entitlements::network() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_page' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		}
		add_action( 'admin_post_vcap_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_post_vcap_deactivate_license', array( $this, 'deactivate' ) );
		add_action( 'admin_post_vcap_forget_license', array( $this, 'forget' ) );
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

	/** Network license: Network Admin → Settings → Voucher Captain license. */
	public function add_network_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'Voucher Captain license', 'voucher-captain' ),
			__( 'Voucher Captain license', 'voucher-captain' ),
			'manage_network_options',
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
		if ( ! current_user_can( Entitlements::capability() ) ) {
			return;
		}
		$server  = Entitlements::server_url();
		$client  = Entitlements::client();
		$license = null;
		$error   = null;
		$foreign = '';
		if ( $client && array() !== $client->stored_credentials() ) {
			$foreign = $client->foreign_site();
			if ( '' === $foreign ) {
				// get_entitlements(): online check that also refreshes the signed snapshot the premium gate reads;
				// during an outage it returns the last confirmed snapshot.
				$license = $client->get_entitlements();
				Entitlements::reset();
				if ( is_wp_error( $license ) ) {
					Logger::wp_error( 'License check on the license page failed.', $license );
					$error   = $license;
					$license = null;
				}
			}
		}
		$activated  = $client && array() !== $client->stored_credentials();
		$notice_key = 'vcap_license_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );
		echo '<div class="wrap"><h1>' . esc_html__( 'License', 'voucher-captain' ) . '</h1>';
		if ( is_array( $notice ) ) {
			$notice_class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}
		if ( $activated && ( '' !== $foreign || ( $error && 'site_mismatch' === $error->get_error_code() ) ) ) {
			$this->render_foreign( $foreign, $server );
		} elseif ( $activated ) {
			$last = $client->last_check();
			if ( $error ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( Entitlements::error_hint( (string) $error->get_error_code(), $error->get_error_message(), $last['rejected'] ) ) . '</p></div>';
			} elseif ( is_array( $license ) && ! $last['ok'] ) {
				echo '<div class="notice notice-warning"><p>' . esc_html(
					sprintf(
						/* translators: 1: cause, 2: date of the last confirmation */
						__( '%1$s The status shown is the last confirmation of the license server from %2$s.', 'voucher-captain' ),
						Entitlements::error_hint( $last['code'], $last['message'], $last['rejected'] ),
						self::date_label( $license['server_time'] ?? '' )
					)
				) . '</p></div>';
			}
			if ( is_array( $license ) ) {
				$this->render_status( $license );
			} else {
				echo '<p>' . esc_html__( 'This website is activated, but the license could not be confirmed. Premium features stay switched off until the license server confirms the license.', 'voucher-captain' ) . '</p>';
			}
			$this->render_actions();
		} else {
			if ( $error ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
			}
			$this->render_activation( $server, $client ? $client->copy_of() : array() );
		}
		echo '</div>';
	}

	/** "Check again", "Deactivate this site" and "Remove locally" for an existing activation. */
	private function render_actions(): void {
		echo '<p class="vcap-license-actions"><a class="button" href="' . esc_url( Entitlements::page_url() ) . '">' . esc_html__( 'Check again', 'voucher-captain' ) . '</a> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( __( 'Deactivate the license on this website? Premium features are switched off until the license is activated again. Vouchers sold earlier keep working.', 'voucher-captain' ) ) ) . ');"><input type="hidden" name="action" value="vcap_deactivate_license">';
		wp_nonce_field( 'vcap_deactivate_license' );
		echo '<button type="submit" class="button">' . esc_html__( 'Deactivate this site', 'voucher-captain' ) . '</button></form> ';
		$this->render_forget_button( __( 'Remove the activation from this website only? The license server keeps it and its place until you release it in your customer account. Premium features are switched off here.', 'voucher-captain' ) );
		echo '</p>';
	}

	private function render_forget_button( string $confirm ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"><input type="hidden" name="action" value="vcap_forget_license">';
		wp_nonce_field( 'vcap_forget_license' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Remove locally', 'voucher-captain' ) . '</button></form>';
	}

	/** Activation of another website (a copy or a moved site): explain, offer "move here" and "remove locally". */
	private function render_foreign( string $foreign, string $server ): void {
		$other = '' !== $foreign ? $foreign : __( 'another website', 'voucher-captain' );
		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: address the activation belongs to, 2: this website's address */
				__( 'The license activation stored here belongs to %1$s, but this website\'s address is %2$s. Premium features are switched off here.', 'voucher-captain' ),
				$other,
				Entitlements::network() && function_exists( 'network_home_url' ) ? network_home_url() : home_url()
			)
		) . '</p></div>';
		echo '<h2>' . esc_html__( 'This website is a copy (for example a staging site)', 'voucher-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Remove the copied activation from this website. The original website keeps its activation. Then activate this copy separately, as staging site of the original if you like.', 'voucher-captain' ) . '</p><p>';
		$this->render_forget_button( __( 'Remove the copied activation from this website? The original website is not affected.', 'voucher-captain' ) );
		echo '</p><h2>' . esc_html__( 'The website has moved to this address', 'voucher-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enter the license key to move the activation to this address. The old address loses it – do not do this on a copy while the original is still in use.', 'voucher-captain' ) . '</p>';
		$this->activation_form( $server, array(), __( 'Move activation to this address', 'voucher-captain' ) );
	}

	/** First activation of this website. */
	private function render_activation( string $server, array $copy_of ): void {
		$this->activation_form( $server, $copy_of, __( 'Activate license', 'voucher-captain' ) );
	}

	private function activation_form( string $server, array $copy_of, string $button ): void {
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
		echo '<tr><th><label for="wplc-key">' . esc_html__( 'License key', 'voucher-captain' ) . '</label></th><td><input id="wplc-key" name="license_key" type="password" class="regular-text" autocomplete="off" required></td></tr>';
		if ( ! empty( $copy_of['installation_id'] ) ) {
			echo '<tr><th>' . esc_html__( 'Staging site', 'voucher-captain' ) . '</th><td><label><input type="checkbox" name="staging" value="1" checked> ' . esc_html(
				sprintf(
					/* translators: %s: address of the live website */
					__( 'This website is the staging copy of %s (uses no live-site place of the license).', 'voucher-captain' ),
					(string) $copy_of['site_url']
				)
			) . '</label></td></tr>';
		}
		echo '</table>';
		echo '<p>' . esc_html__( 'The license key is used once for activation and is not stored by this plugin. Activation credentials are stored locally without autoloading.', 'voucher-captain' ) . '</p>';
		echo '<p>' . esc_html__( 'Activation and the twice-daily license check send this website\'s address, an installation ID and the WordPress, PHP and Voucher Captain versions to the license server.', 'voucher-captain' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $button ) . '</button></p></form>';
	}

	/** Status, dates and rights as a readable list. */
	private function render_status( array $license ): void {
		$status = (string) ( $license['license_status'] ?? '' );
		$paid   = self::date_label( $license['paid_until'] ?? '' );
		/* translators: 1: websites using the license, 2: allowed websites */
		$sites = sprintf( __( '%1$d of %2$d', 'voucher-captain' ), (int) ( $license['site_count'] ?? 0 ), (int) ( $license['site_limit'] ?? 0 ) );
		$rows  = array(
			__( 'Status', 'voucher-captain' )       => self::status_label( $status ),
			__( 'Paid through', 'voucher-captain' ) => '' !== $paid ? $paid : __( 'No end date', 'voucher-captain' ),
			__( 'Websites', 'voucher-captain' )     => $sites,
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
			echo '<li>' . esc_html( self::right_label( $label, $status, $client && 'active' === $status && $client->allows_entitlement( 'voucher.' . $feature, $license ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * One line of the rights list. An inactive license does not tell which rights it includes, so it says why
	 * the feature is locked instead of "not included".
	 */
	public static function right_label( string $label, string $status, bool $granted ): string {
		if ( 'active' === $status ) {
			return ( $granted ? '✔ ' : '– ' ) . $label . ' – ' . ( $granted ? __( 'included', 'voucher-captain' ) : __( 'not included', 'voucher-captain' ) );
		}
		$reason = 'past_due' === $status ? __( 'locked until the payment has been received', 'voucher-captain' ) : __( 'locked while the license is not active', 'voucher-captain' );
		return '– ' . $label . ' – ' . $reason;
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
			Entitlements::save_server_url( $server );
		}
		$client = Entitlements::client();
		if ( ! $client ) {
			$this->redirect( 'error', __( 'Enter a valid HTTPS license server URL and license key.', 'voucher-captain' ) );
		}
		$copy_of = $client->copy_of();
		$staging = ! empty( $_POST['staging'] ) && ! empty( $copy_of['installation_id'] );
		// Reuses a kept installation id and secret (pending or this site's own): never a second place for a retry.
		$result = $staging ? $client->activate( $key, 'staging', $copy_of['installation_id'] ) : $client->activate( $key );
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

	/** Remove the activation from this website only (copy of another website, or server unreachable). */
	public function forget(): void {
		$this->authorize( 'vcap_forget_license' );
		$client = Entitlements::client();
		$copy   = $client && '' !== $client->foreign_site();
		if ( $client ) {
			$client->forget_locally();
		}
		Entitlements::reset();
		PremiumUpdater::flush();
		$this->redirect(
			'success',
			$copy
				? __( 'The copied activation was removed from this website. The original website keeps its activation.', 'voucher-captain' )
				: __( 'The activation was removed from this website. The license server keeps it until it is released in your customer account.', 'voucher-captain' )
		);
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( Entitlements::capability() ) ) {
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
