<?php
/**
 * Uninstall: only removes data when "Delete data on uninstall" is enabled. WooCommerce coupons are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Voucher Captain's data of the current site (only when that site opted in). The background license
 * check is removed on every site, it would only fail without the plugin.
 *
 * @return bool Whether the site opted in.
 */
function vcap_uninstall_site(): bool {
	wp_clear_scheduled_hook( 'vcap_license_refresh' ); // VCAP\Licensing\Entitlements::REFRESH_HOOK (classes are not loaded here).
	if ( 'yes' !== get_option( 'vcap_delete_on_uninstall', 'no' ) ) {
		return false;
	}

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
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vcap\_%' OR option_name LIKE '\_vcap\_claim\_%' OR option_name LIKE '\_vcap\_scan\_%' OR option_name LIKE '\_transient\_vcap\_%' OR option_name LIKE '\_transient\_timeout\_vcap\_%'" ); // phpcs:ignore
	// Voucher settings of products and variations ("_vcap_*" meta); coupons keep theirs, like the coupons themselves.
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
