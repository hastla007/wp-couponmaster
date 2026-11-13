<?php
/**
 * CI only (job "license-acceptance"): release metadata the WP License Captain server announces for Voucher
 * Captain – the option is written by tests/license-acceptance.php (server-package). Production servers use their
 * own release registry and storage (wp-license docs/UPDATES.md, UPDATE-STORAGE.md).
 */
add_filter(
	'wplc_update_packages',
	static function ( $packages, $product_key ) {
		$package = get_option( 'vcap_acc_package' );
		if ( 'voucher-captain' === $product_key && is_array( $package ) && $package ) {
			$packages                    = is_array( $packages ) ? $packages : array();
			$packages['voucher-captain'] = $package;
		}
		return $packages;
	},
	10,
	2
);
