<?php
/**
 * Constants WordPress and voucher-captain.php define at runtime, for PHPStan (phpstan-wide.neon.dist).
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'WP_LANG_DIR' ) || define( 'WP_LANG_DIR', ABSPATH . 'wp-content/languages' );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'voucher-captain/voucher-captain.php' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 2592000 );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 31536000 );
defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1048576 );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'VCAP_VERSION' ) || define( 'VCAP_VERSION', '0.0.0' );
defined( 'VCAP_DB_VERSION' ) || define( 'VCAP_DB_VERSION', '0.0.0' );
defined( 'VCAP_FILE' ) || define( 'VCAP_FILE', dirname( __DIR__ ) . '/voucher-captain.php' );
defined( 'VCAP_PATH' ) || define( 'VCAP_PATH', dirname( __DIR__ ) . '/' );
defined( 'VCAP_URL' ) || define( 'VCAP_URL', 'https://example.test/wp-content/plugins/voucher-captain/' );
if ( ! function_exists( 'vcap' ) ) {
	/** Declared by voucher-captain.php once WooCommerce passed its check (inside the plugins_loaded callback). */
	function vcap(): \VCAP\Plugin {
		return \VCAP\Plugin::instance();
	}
}
// Action Scheduler (bundled with WooCommerce, not part of the WooCommerce stubs).
if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	abstract class ActionScheduler_Store {
		const STATUS_COMPLETE = 'complete';
		const STATUS_PENDING  = 'pending';
		const STATUS_RUNNING  = 'in-progress';
		const STATUS_FAILED   = 'failed';
		const STATUS_CANCELED = 'canceled';
	}
}
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/** @return int */
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ) {
		return 0;
	}
	/** @return int */
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ) {
		return 0;
	}
	/** @return int */
	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ) {
		return 0;
	}
	/** @return int|null */
	function as_unschedule_action( string $hook, array $args = array(), string $group = '' ) {
		return null;
	}
	function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {}
	/** @return int|bool */
	function as_next_scheduled_action( string $hook, ?array $args = null, string $group = '' ) {
		return false;
	}
	function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
		return false;
	}
	/** @return array<int|string, mixed> */
	function as_get_scheduled_actions( array $args = array(), string $return_format = OBJECT ): array {
		return array();
	}
}
