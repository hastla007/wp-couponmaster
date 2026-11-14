<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log (WooCommerce → Status → Logs, source "voucher-captain"). Used for problems the
 * shop owner or support must be able to trace later: deliveries, migrations, integrations.
 * Never log secrets, voucher codes or personal data.
 */
final class Logger {
	public const SOURCE = 'voucher-captain';
	/** Setting "Verbose log" (D16): 'yes' switches detailed logging on for VERBOSE_HOURS hours. */
	public const VERBOSE_OPTION = 'vcap_debug_log';
	/** Time until which the verbose log is on (set when the setting is switched on). */
	public const VERBOSE_UNTIL_OPTION = 'vcap_debug_log_until';
	/** Hours the verbose log stays on after it was switched on in the settings. */
	public const VERBOSE_HOURS = 24;

	/**
	 * Whether detailed entries are written (D16): constant VCAP_DEBUG, or the setting "Verbose log" switched on
	 * less than VERBOSE_HOURS hours ago (it switches itself off, so it is never forgotten on a live shop).
	 */
	public static function verbose(): bool {
		if ( defined( 'VCAP_DEBUG' ) && VCAP_DEBUG ) {
			return true;
		}
		return function_exists( 'get_option' ) && 'yes' === get_option( self::VERBOSE_OPTION, 'no' ) && (int) get_option( self::VERBOSE_UNTIL_OPTION, 0 ) > time();
	}

	/**
	 * Settings save of "Verbose log": switching it on starts the VERBOSE_HOURS window, saving it while on keeps the
	 * running one (an expired window is started again).
	 *
	 * @param mixed $value Checkbox value ('yes'/'no' or null).
	 */
	public static function sanitize_verbose( $value ) {
		$on = in_array( $value, array( 'yes', '1', 1, true ), true );
		if ( $on && ( 'yes' !== get_option( self::VERBOSE_OPTION, 'no' ) || (int) get_option( self::VERBOSE_UNTIL_OPTION, 0 ) <= time() ) ) {
			update_option( self::VERBOSE_UNTIL_OPTION, time() + self::VERBOSE_HOURS * HOUR_IN_SECONDS, false );
		} elseif ( ! $on ) {
			delete_option( self::VERBOSE_UNTIL_OPTION );
		}
		return $on ? 'yes' : 'no';
	}

	/** Detailed entry, written only while verbose() (level "info", marked "[debug]"). */
	public static function debug( string $message, array $context = array() ): void {
		if ( self::verbose() ) {
			self::log( 'info', '[debug] ' . $message, $context );
		}
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/** Logs a WP_Error with its code (the message of a remote server is kept as data, not as the log text). */
	public static function wp_error( string $message, \WP_Error $error, array $context = array() ): void {
		self::error( $message . ' [' . $error->get_error_code() . '] ' . $error->get_error_message(), $context );
	}

	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( is_object( $logger ) && method_exists( $logger, 'log' ) ) {
			$logger->log( $level, $message, array( 'source' => self::SOURCE ) + $context );
		}
	}
}
