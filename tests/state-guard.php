<?php
/**
 * Shared helper of the integration suites (M12-101): a suite that changes shop settings or adds tax rates takes a
 * snapshot first and restores it in `finally`, so a failing assertion does not leave taxes switched on or a
 * setting changed for the suites that run after it.
 *
 *   require_once __DIR__ . '/state-guard.php';
 *   $vcap_restore = vcap_test_state_guard( array( 'woocommerce_calc_taxes', … ) );
 *   try { … } finally { $vcap_restore(); }
 */

if ( ! function_exists( 'vcap_test_state_guard' ) ) {
	/**
	 * Snapshot of the given options and of the tax rate table.
	 *
	 * @param string[] $options Option names (a missing option is deleted again on restore).
	 * @return callable Restores the options and deletes tax rates added since the snapshot.
	 */
	function vcap_test_state_guard( array $options ): callable {
		global $wpdb;
		$missing = '__vcap_missing_option__';
		$saved   = array();
		foreach ( $options as $option ) {
			$saved[ $option ] = get_option( $option, $missing );
		}
		$last_rate = (int) $wpdb->get_var( "SELECT MAX(tax_rate_id) FROM {$wpdb->prefix}woocommerce_tax_rates" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return static function () use ( $saved, $missing, $last_rate ): void {
			global $wpdb;
			foreach ( $saved as $option => $value ) {
				if ( $missing === $value ) {
					delete_option( $option );
				} else {
					update_option( $option, $value );
				}
			}
			$added = (array) $wpdb->get_col( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id > %d", $last_rate ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $added as $rate_id ) {
				WC_Tax::_delete_tax_rate( (int) $rate_id );
			}
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
		};
	}
}
