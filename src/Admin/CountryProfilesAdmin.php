<?php
/**
 * "General": country profile with preview (SET-VC-02).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

use VCAP\CountryProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Admin side of VCAP\CountryProfiles. The block sits inside the WooCommerce settings form of "General"; its buttons
 * post to admin-post.php (formaction) with an own nonce, so "Save changes" never applies a profile. Choosing a profile
 * shows a preview "current → suggested" with one checkbox per value; only the ticked values are applied ("Apply"),
 * "Cancel" changes nothing. Every action needs "manage_woocommerce" and the nonce.
 *
 * @since 1.3.0
 */
final class CountryProfilesAdmin {
	/** Capability for every action. */
	public const CAP = 'manage_woocommerce';

	/** Nonce action and field. */
	public const NONCE       = 'vcap_country_profile';
	public const NONCE_FIELD = 'vcap_profile_nonce';

	/** Field type rendered here. */
	public const FIELD_TYPE = 'vcap_country_profile';

	/** User transients: profile of the open preview, message after an action. */
	private const PENDING = 'vcap_profile_pending_';
	private const NOTICE  = 'vcap_profile_notice_';

	/** Hooks. */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_action( 'admin_post_vcap_country_profile_preview', array( self::class, 'preview' ) );
		add_action( 'admin_post_vcap_country_profile_apply', array( self::class, 'apply' ) );
		add_action( 'admin_post_vcap_country_profile_cancel', array( self::class, 'cancel' ) );
	}

	/**
	 * The block at the top of "General" (Settings::general_fields()).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Country profile', 'voucher-captain' ),
				'id'    => 'vcap_country_profile',
				'desc'  => __( 'Suggested settings for the country you sell in: validity, statutory validity, receipt number, retention of recipient data, date format and link parameter, plus hints on tax and accounting. You see every change before it is made and choose which ones to take; afterwards every value can be changed as before.', 'voucher-captain' ),
			),
			array(
				'title'     => __( 'Profile', 'voucher-captain' ),
				'id'        => 'vcap_country_profile_field',
				'type'      => self::FIELD_TYPE,
				'is_option' => false,
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_country_profile' ),
		);
	}

	/** URL of the block (after an action). */
	public static function page_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=vcap' ) . '#vcap_country_profile-description';
	}

	/** Opens the preview of a profile for the current user (also used by the setup checklist). */
	public static function open_preview( string $id ): bool {
		if ( null === CountryProfiles::get( $id ) ) {
			return false;
		}
		set_transient( self::PENDING . get_current_user_id(), strtoupper( $id ), HOUR_IN_SECONDS );
		return true;
	}

	/** Profile of the open preview of the current user ('' = none). */
	public static function pending(): string {
		$id = get_transient( self::PENDING . get_current_user_id() );
		return is_string( $id ) && null !== CountryProfiles::get( $id ) ? $id : '';
	}

	/** A submit button of the settings form that posts to an admin-post action instead of saving. */
	private static function button( string $action, string $label, string $class = 'button' ): string {
		return '<button type="submit" class="' . esc_attr( $class ) . '" formnovalidate formaction="' . esc_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ) ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Choice of the profile, the open preview and the profile applied last.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render( $field ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$user   = get_current_user_id();
		$notice = get_transient( self::NOTICE . $user );
		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE . $user );
		}
		$pending = self::pending();
		$applied = CountryProfiles::applied();
		$chosen  = '' !== $pending ? $pending : ( null !== $applied && null !== CountryProfiles::get( $applied['id'] ) ? $applied['id'] : CountryProfiles::for_base_country() );
		echo '<tr valign="top"><th scope="row" class="titledesc"><label for="vcap_profile">' . esc_html( (string) ( is_array( $field ) ? ( $field['title'] ?? '' ) : '' ) ) . '</label></th><td class="forminp">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
		if ( is_array( $notice ) ) {
			$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice inline ' . esc_attr( $class ) . '" role="status"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p></div>';
		}
		echo '<select id="vcap_profile" name="vcap_profile">';
		foreach ( array_keys( CountryProfiles::all() ) as $id ) {
			echo '<option value="' . esc_attr( $id ) . '"' . ( $id === $chosen ? ' selected="selected"' : '' ) . '>' . esc_html( CountryProfiles::label( $id ) ) . '</option>';
		}
		echo '</select> ' . self::button( 'vcap_country_profile_preview', __( 'Show suggested changes', 'voucher-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().
		if ( null !== $applied ) {
			/* translators: 1: profile name, 2: date */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last applied: %1$s on %2$s.', 'voucher-captain' ), CountryProfiles::label( $applied['id'] ), wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $applied['time'] ) ) ) . '</p>';
		}
		if ( '' !== $pending ) {
			echo self::preview_html( $pending ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in preview_html().
		}
		echo '</td></tr>';
	}

	/** The preview table of a profile with the buttons "Apply" and "Cancel" (escaped HTML). */
	public static function preview_html( string $id ): string {
		$items = CountryProfiles::preview( $id );
		if ( is_wp_error( $items ) ) {
			return '<div class="notice inline notice-error"><p>' . esc_html( $items->get_error_message() ) . '</p></div>';
		}
		$html  = '<div class="vcap-profile-preview" role="region" aria-labelledby="vcap_profile_preview_title">';
		/* translators: %s: profile name, e.g. Germany */
		$html .= '<h4 id="vcap_profile_preview_title">' . esc_html( sprintf( __( 'Suggested changes: %s', 'voucher-captain' ), CountryProfiles::label( $id ) ) ) . '</h4>';
		$html .= '<p><strong>' . esc_html( CountryProfiles::status( $id ) ) . '</strong></p>';
		$html .= '<input type="hidden" name="vcap_profile_id" value="' . esc_attr( $id ) . '">';
		if ( ! array_filter( $items, static fn( array $item ) => $item['changed'] || '' !== $item['error'] ) ) {
			$html .= '<p>' . esc_html__( 'Nothing to change: every suggested value is already set.', 'voucher-captain' ) . '</p>';
		} else {
			$html .= '<p class="description">' . esc_html__( 'Only the ticked values are changed. Values you set yourself and the link parameter are not ticked: tick them to replace them.', 'voucher-captain' ) . '</p>';
			$html .= '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Apply', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'Setting', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'Current value', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'Suggested value', 'voucher-captain' ) . '</th><th scope="col">' . esc_html__( 'Note', 'voucher-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$box_id = 'vcap_profile_item_' . sanitize_html_class( $item['item'] );
				$notes  = array();
				if ( '' !== $item['review'] ) {
					$notes[] = '<strong>' . esc_html( $item['review'] ) . '</strong>';
				}
				if ( '' !== $item['error'] ) {
					$notes[] = esc_html( $item['error'] );
				} elseif ( ! $item['changed'] ) {
					$notes[] = esc_html__( 'Already set.', 'voucher-captain' );
				} elseif ( $item['opt_in'] ) {
					$notes[] = esc_html__( 'Suggestion only: links and QR codes already sent use the current parameter and stop working after a change.', 'voucher-captain' );
				} elseif ( $item['own'] ) {
					$notes[] = esc_html__( 'You set your own value: tick to replace it.', 'voucher-captain' );
				}
				$box = $item['changed'] && '' === $item['error']
					? '<input type="checkbox" id="' . esc_attr( $box_id ) . '" name="vcap_profile_items[]" value="' . esc_attr( $item['item'] ) . '"' . ( $item['preselected'] ? ' checked="checked"' : '' ) . '>'
					: '';
				$html .= '<tr><td>' . $box . '</td><td>' . ( '' !== $box ? '<label for="' . esc_attr( $box_id ) . '">' . esc_html( $item['label'] ) . '</label>' : esc_html( $item['label'] ) ) . '</td><td>' . esc_html( $item['from'] ) . '</td><td>' . esc_html( $item['to'] ) . '</td><td>' . implode( ' ', $notes ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		$hints = CountryProfiles::hints( $id );
		if ( $hints ) {
			$html .= '<ul>';
			foreach ( $hints as $hint ) {
				$html .= '<li><strong>' . esc_html( $hint['label'] ) . ':</strong> ' . esc_html( $hint['text'] ) . ( '' !== $hint['review'] ? ' <strong>' . esc_html( $hint['review'] ) . '</strong>' : '' ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<p>';
		if ( array_filter( $items, static fn( array $item ) => $item['changed'] && '' === $item['error'] ) ) {
			$html .= self::button( 'vcap_country_profile_apply', __( 'Apply ticked values', 'voucher-captain' ), 'button button-primary' ) . ' ';
		} else {
			$html .= self::button( 'vcap_country_profile_apply', __( 'Use this profile without changes', 'voucher-captain' ), 'button button-primary' ) . ' ';
		}
		$html .= self::button( 'vcap_country_profile_cancel', __( 'Cancel', 'voucher-captain' ) ) . '</p></div>';
		return $html;
	}

	/** Checks the capability and the nonce of an action. */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );
	}

	/** Message shown after the redirect, then back to the block. */
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

	/** admin-post: show the preview of the chosen profile (changes nothing). */
	public static function preview(): void {
		self::guard();
		$id = isset( $_POST['vcap_profile'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['vcap_profile'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		if ( ! self::open_preview( $id ) ) {
			self::done( 'error', __( 'Choose a country profile.', 'voucher-captain' ) );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** admin-post: apply the ticked values of the open preview. */
	public static function apply(): void {
		self::guard();
		$pending = self::pending();
		$posted  = isset( $_POST['vcap_profile_id'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['vcap_profile_id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$items   = isset( $_POST['vcap_profile_items'] ) && is_array( $_POST['vcap_profile_items'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['vcap_profile_items'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		delete_transient( self::PENDING . get_current_user_id() );
		if ( '' === $pending || $pending !== $posted ) {
			self::done( 'error', __( 'The preview of the country profile has expired. Nothing was changed; please choose the profile again.', 'voucher-captain' ) );
		}
		$result = CountryProfiles::apply( $pending, $items );
		if ( is_wp_error( $result ) ) {
			self::done( 'error', $result->get_error_message() );
		}
		$count = count( $result['changed'] );
		self::done(
			'success',
			0 === $count
				/* translators: %s: profile name */
				? sprintf( __( 'Country profile "%s" chosen; no setting was changed.', 'voucher-captain' ), CountryProfiles::label( $pending ) )
				/* translators: 1: number of settings, 2: profile name */
				: sprintf( _n( '%1$d setting was changed from the country profile "%2$s" (a backup of the previous settings was saved). Every value can be changed as before.', '%1$d settings were changed from the country profile "%2$s" (a backup of the previous settings was saved). Every value can be changed as before.', $count, 'voucher-captain' ), $count, CountryProfiles::label( $pending ) )
		);
	}

	/** admin-post: close the preview, nothing is changed. */
	public static function cancel(): void {
		self::guard();
		delete_transient( self::PENDING . get_current_user_id() );
		self::done( 'success', __( 'Country profile not applied, nothing was changed.', 'voucher-captain' ) );
	}
}
