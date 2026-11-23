<?php
/**
 * A5: the core works alone and offline. vcap_test_no_network() blocks every HTTP request of the running suite
 * (pre_http_request answers with a WP_Error, nothing leaves the machine) and records it; the returned function
 * stops the block and gives the requests made from Voucher Captain's own code (a plugin file of Voucher Captain
 * in the call stack). Requests of WordPress or WooCommerce themselves are blocked too but not counted.
 *
 * Used by tests/woocommerce-integration.php and tests/order-lifecycle-integration.php (no add-on installed in CI).
 *
 * @return callable(): string[] Stops the block; URLs requested by Voucher Captain's code.
 */
function vcap_test_no_network(): callable {
	$core  = array();
	$base  = wp_normalize_path( VCAP_PATH );
	$block = static function ( $pre, $args, $url ) use ( &$core, $base ) {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( isset( $frame['file'] ) && str_starts_with( wp_normalize_path( (string) $frame['file'] ), $base ) ) {
				$core[] = (string) $url;
				break;
			}
		}
		return new WP_Error( 'vcap_test_offline', 'Network access is blocked in this test (A5).' );
	};
	add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
	return static function () use ( &$core, $block ): array {
		remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
		return $core;
	};
}
