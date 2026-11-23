<?php
namespace VCAP\Admin;

use VCAP\Claim;

defined( 'ABSPATH' ) || exit;

/**
 * One-time token per rendered admin form against double submissions: a double click, a reload of the result
 * page or "back" and submit again send the same token, and only the first request may act on it (a Claim row).
 * A nonce does not help here, it stays valid for hours. Booking forms (balance adjustment, store credit, code
 * generation) render field() and call claim() before they change anything; when nothing was changed (input
 * refused) they release() the token, so the corrected form can be sent again.
 */
final class FormToken {
	public const FIELD = 'vcap_form_token';

	/** Hidden input with a fresh token. */
	public static function field(): string {
		return '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( self::generate() ) . '" />';
	}

	public static function generate(): string {
		return function_exists( 'wp_generate_uuid4' ) ? str_replace( '-', '', wp_generate_uuid4() ) : bin2hex( random_bytes( 16 ) );
	}

	/** Token of the submitted form ('' when the form had none, e.g. a page rendered before an update). */
	public static function posted(): string {
		$raw = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return substr( $raw, 0, 64 );
	}

	/**
	 * True for the first request with this form's token, false for every repetition. A form without a token
	 * cannot be told apart from a new one and is let through.
	 */
	public static function claim( string $scope ): bool {
		$token = self::posted();
		return '' === $token || Claim::take( self::name( $scope, $token ) );
	}

	/** Nothing was changed: the same form may be sent again (e.g. after correcting the amount). */
	public static function release( string $scope ): void {
		$token = self::posted();
		if ( '' !== $token ) {
			Claim::release( self::name( $scope, $token ) );
		}
	}

	private static function name( string $scope, string $token ): string {
		return 'form:' . sanitize_key( $scope ) . ':' . get_current_user_id() . ':' . $token;
	}
}
