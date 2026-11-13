<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log (WooCommerce → Status → Logs, source "voucher-captain"). Used for problems the
 * shop owner or support must be able to trace later: license checks, premium updates, integrations.
 * Never log license keys, installation secrets, voucher codes or personal data.
 */
final class Logger {
	public const SOURCE = 'voucher-captain';

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
