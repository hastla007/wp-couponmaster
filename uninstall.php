<?php
/**
 * Uninstall: only removes data when "Delete data on uninstall" is enabled. Gift card and store credit coupons are
 * always suspended (draft) – without the plugin nothing would deduct their balance; with data deletion all
 * Voucher Captain coupons go to the trash (restorable there), the other WooCommerce coupons are kept.
 */

// VCAP_UNINSTALL_FUNCTIONS_ONLY: tests/install-integration.php loads the functions without uninstalling.
defined( 'WP_UNINSTALL_PLUGIN' ) || defined( 'VCAP_UNINSTALL_FUNCTIONS_ONLY' ) || exit;

/**
 * Releases this site's license activation on the license server before its credentials are deleted, so the site
 * does not keep using a place of the license. Best effort with a short timeout: an unreachable server never blocks
 * the uninstall (the activation can still be released in the customer account on the license server).
 */
function vcap_uninstall_release_license(): void {
	$credentials = get_option( 'vcap_wplc_credentials' ); // VCAP\Licensing\Entitlements::CREDENTIALS_OPTION.
	if ( ! is_array( $credentials ) || empty( $credentials['activation_id'] ) ) {
		return;
	}
	$server = (string) ( $credentials['server_url'] ?? '' );
	if ( '' === $server ) {
		$server = (string) get_option( 'vcap_wplc_server_url', '' );
	}
	if ( '' === $server && is_readable( __DIR__ . '/voucher-captain.php' ) && preg_match( "/define\\( 'VCAP_LICENSE_SERVER', '([^']+)' \\);/", (string) file_get_contents( __DIR__ . '/voucher-captain.php' ), $m ) ) {
		$server = $m[1]; // Premium build: the server is fixed in the plugin file.
	}
	if ( '' === $server || ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		return;
	}
	require_once __DIR__ . '/vendor/autoload.php';
	if ( ! class_exists( 'VCAP\\Licensing\\LicenseClient' ) ) {
		return;
	}
	$client = new VCAP\Licensing\LicenseClient( $server, 'voucher-captain', '0', 'vcap_wplc_credentials' );
	$client->set_timeout( 5 );
	$client->deactivate();
}

/**
 * Takes the coupons of the current site out of use that would be wrong without the plugin.
 *
 * Gift cards and store credit are coupons without a usage limit whose amount is the remaining balance; only
 * Voucher Captain deducts it. Published ones are set to draft (WooCommerce refuses them) – the same selection as
 * VCAP\Install::suspend_balance_coupons() on deactivation, in plain SQL because the plugin's classes are not
 * loaded here. Deactivation normally did this already – but not when the plugin lost its activation without the
 * hook (folder renamed or removed by hand) or is deleted on a network while still active on single sites.
 * - Data kept: their ids are added to "vcap_suspended_coupons", so a reinstall publishes them again.
 * - Data deleted: every Voucher Captain coupon (meta "_vcap_voucher" = yes) goes to the trash; without its
 *   voucher it would be an ordinary coupon with the voucher's full value. The trash keeps it restorable.
 *
 * @param bool $delete_data Whether the site opted in to deleting its data.
 * @return array<int, string> Changed coupons: id => status before.
 */
function vcap_uninstall_coupons( bool $delete_data ): array {
	global $wpdb;
	$table    = $wpdb->prefix . 'vcap_vouchers';
	$suppress = $wpdb->suppress_errors( true );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
		$balance = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} v ON v.coupon_id = p.ID WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND v.usage_mode = 'balance'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// No voucher table (its data is gone): any published voucher coupon without a usage limit may hold a balance.
		$balance = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_vcap_voucher' AND m.meta_value = 'yes' LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = 'usage_limit' WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND ( l.meta_value IS NULL OR l.meta_value IN ('', '0') )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->suppress_errors( $suppress );
	$balance = array_values( array_unique( array_map( 'intval', (array) $balance ) ) );
	foreach ( array_chunk( $balance, 500 ) as $chunk ) {
		$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE ID IN (" . implode( ',', $chunk ) . ") AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
	}
	$changed = array_fill_keys( $balance, 'publish' );

	if ( ! $delete_data ) {
		if ( $balance ) {
			$known = array_map( 'intval', (array) get_option( 'vcap_suspended_coupons', array() ) ); // VCAP\Install::SUSPENDED_OPTION.
			update_option( 'vcap_suspended_coupons', array_values( array_unique( array_merge( $known, $balance ) ) ), false );
		}
	} else {
		$rows = (array) $wpdb->get_results( "SELECT p.ID, p.post_status FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_vcap_voucher' AND m.meta_value = 'yes' WHERE p.post_type = 'shop_coupon' AND p.post_status NOT IN ('trash', 'auto-draft')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$time = (string) time();
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$ids    = array();
			$values = array();
			foreach ( $chunk as $row ) {
				$id             = (int) $row['ID'];
				$changed[ $id ] = $changed[ $id ] ?? (string) $row['post_status'];
				$ids[]          = $id;
				// Trash meta as wp_trash_post() writes it: "Restore" brings back the status before.
				$values[] = $wpdb->prepare( '(%d, %s, %s), (%d, %s, %s)', $id, '_wp_trash_meta_status', $changed[ $id ], $id, '_wp_trash_meta_time', $time );
			}
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_trash_meta_status', '_wp_trash_meta_time')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- prepared rows.
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		}
	}

	foreach ( array_keys( $changed ) as $id ) {
		clean_post_cache( $id );
	}
	if ( $changed && class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
		WC_Cache_Helper::invalidate_cache_group( 'coupons' ); // Cached "code → coupon id" would keep them usable.
	}
	return $changed;
}

/**
 * Removes Voucher Captain's data of the current site (only when that site opted in). The background license
 * check is removed and the balance coupons are suspended on every site (see vcap_uninstall_coupons()).
 *
 * @return bool Whether the site opted in.
 */
function vcap_uninstall_site(): bool {
	wp_clear_scheduled_hook( 'vcap_license_refresh' ); // VCAP\Licensing\Entitlements::REFRESH_HOOK (classes are not loaded here).
	$delete_data = 'yes' === get_option( 'vcap_delete_on_uninstall', 'no' );
	vcap_uninstall_coupons( $delete_data ); // First: needs the voucher table, which data deletion drops.
	if ( ! $delete_data ) {
		return false;
	}
	vcap_uninstall_release_license();

	global $wpdb;
	foreach ( array( 'vcap_vouchers', 'vcap_events', 'vcap_code_pool', 'vcap_redemptions', 'vcap_promotion_usage', 'vcap_claims' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
	}
	// Templates, field groups and promotions (vcap_discount, with their usage records).
	foreach ( array( 'vcap_template', 'vcap_field_group', 'vcap_discount' ) as $post_type ) {
		$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'any', 'trash', 'auto-draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
	$where   = "option_name LIKE 'vcap\_%' OR option_name LIKE '\_vcap\_claim\_%' OR option_name LIKE '\_vcap\_scan\_%' OR option_name LIKE '\_transient\_vcap\_%' OR option_name LIKE '\_transient\_timeout\_vcap\_%'";
	$options = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE {$where}" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE {$where}" ); // phpcs:ignore
	// A persistent object cache would otherwise keep the deleted options (e.g. the suspended coupon list).
	foreach ( $options as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	// Voucher settings of products and variations ("_vcap_*" meta); coupons keep theirs (restorable from the trash).
	$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('product', 'product_variation') AND m.meta_key LIKE %s", $wpdb->esc_like( '_vcap_' ) . '%' ) ); // phpcs:ignore
	foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'vcap_manage_vouchers' );
			$role->remove_cap( 'vcap_scan_tickets' );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'vcap' );
	}
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'vcap';
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore
		}
		rmdir( $dir ); // phpcs:ignore
	}
	return true;
}

/**
 * Removes the referral data stored with the users (personal referral coupon and reward count). Users are shared
 * by all sites of a network, so on a network only when every site opted in.
 */
function vcap_uninstall_user_meta(): void {
	global $wpdb;
	foreach ( array( '_vcap_referral_coupon', '_vcap_referral_count' ) as $key ) {
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) ); // phpcs:ignore
	}
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'user_meta' );
	}
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return; // Functions only (VCAP_UNINSTALL_FUNCTIONS_ONLY).
}

delete_site_transient( 'vcap_update_check' ); // Cached update check (VCAP\Licensing\PremiumUpdater).

if ( is_multisite() ) {
	// Network-wide uninstall: every site decides for itself (its own "Delete data on uninstall" setting).
	$vcap_all_opted_in = true;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $vcap_site_id ) {
		switch_to_blog( (int) $vcap_site_id );
		$vcap_all_opted_in = vcap_uninstall_site() && $vcap_all_opted_in;
		restore_current_blog();
	}
	if ( $vcap_all_opted_in ) {
		vcap_uninstall_user_meta();
	}
} elseif ( vcap_uninstall_site() ) {
	vcap_uninstall_user_meta();
}
