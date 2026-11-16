<?php
namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One-time admin notices that survive the redirect after a form post (per user, a few minutes): results with
 * details – amounts, addresses, reasons – that a fixed message key cannot carry.
 */
final class Flash {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	private static function key(): string {
		return 'vcap_flash_' . get_current_user_id();
	}

	/** @param string $type success, error, warning or info. */
	public static function add( string $type, string $message ): void {
		$list   = get_transient( self::key() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( self::key(), array_slice( $list, -6 ), 5 * MINUTE_IN_SECONDS );
	}

	/** @return array<int, array{type:string, message:string}> pending notices (for tests). */
	public static function pending(): array {
		$list = get_transient( self::key() );
		return is_array( $list ) ? $list : array();
	}

	public static function render(): void {
		$list = self::pending();
		if ( ! $list ) {
			return;
		}
		delete_transient( self::key() );
		foreach ( $list as $notice ) {
			echo '<div class="notice notice-' . esc_attr( (string) $notice['type'] ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
		}
	}
}
