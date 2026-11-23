<?php
/**
 * Uninstall: only removes data when "Remove all data when the plugin is deleted" is on (switched on only with the typed
 * word LÖSCHEN/DELETE, VCAP\Admin\DataRemoval; each site of a network decides for itself). Always: scheduled jobs,
 * the plugin's rights on every role (SET-VC-21) and leftovers of the license client of earlier builds are removed,
 * gift card and store credit coupons and coupons with rules only Voucher Captain checks are suspended (draft) – without the plugin nothing would deduct their
 * balance or check their rules; with data deletion all Voucher Captain coupons go to the trash (restorable there),
 * the other WooCommerce coupons are kept.
 */

// VCAP_UNINSTALL_FUNCTIONS_ONLY: tests/install-integration.php loads the functions without uninstalling.
defined( 'WP_UNINSTALL_PLUGIN' ) || defined( 'VCAP_UNINSTALL_FUNCTIONS_ONLY' ) || exit;

/**
 * Loads the plugin's classes (Composer autoloader and its own PSR-4 loader for src/), for the shared selection of
 * coupons to suspend and the cleanup of earlier license data (VCAP\Install).
 */
function vcap_uninstall_autoload(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}
	spl_autoload_register(
		static function ( string $class ): void {
			if ( str_starts_with( $class, 'VCAP\\' ) ) {
				$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

/**
 * Removes what the license client of earlier builds stored (options, background check, cached update check) on the
 * current site, or the network license options. Voucher data is not touched.
 *
 * @param bool $network The network options (plugin was network-activated) instead of this site's.
 */
function vcap_uninstall_license_data( bool $network = false ): void {
	vcap_uninstall_autoload();
	if ( $network ) {
		foreach ( VCAP\Install::LEGACY_LICENSE_OPTIONS as $option ) {
			delete_site_option( $option );
		}
		return;
	}
	VCAP\Install::remove_license_data( false );
}

/**
 * Takes the coupons of the current site out of use that would be wrong without the plugin.
 *
 * Gift cards and store credit are coupons without a usage limit whose amount is the remaining balance; only
 * Voucher Captain deducts it. They, vouchers that may not be redeemed in the shop and coupons with rules only
 * Voucher Captain checks (first order only, referral codes, SKU rules) are set to draft – the same selection and
 * marker as VCAP\Install::suspend_balance_coupons() on deactivation, so a reinstall publishes them again.
 * Deactivation normally did this already – but not when the plugin lost its activation without the hook (folder
 * renamed or removed by hand) or is deleted on a network while still active on single sites.
 * With "Delete data on uninstall" every Voucher Captain coupon (meta "_vcap_voucher" = yes) goes to the trash as
 * well; without its voucher it would be an ordinary coupon with the voucher's full value. The trash keeps it
 * restorable.
 *
 * @param bool $delete_data Whether the site opted in to deleting its data.
 * @return array<int, string> Changed coupons: id => status before.
 */
function vcap_uninstall_coupons( bool $delete_data ): array {
	global $wpdb;
	vcap_uninstall_autoload();
	$table    = $wpdb->prefix . 'vcap_vouchers';
	$suppress = $wpdb->suppress_errors( true );
	$balance  = VCAP\Install::suspendable_coupon_ids();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		// No voucher table (its data is gone): any published voucher coupon without a usage limit may hold a balance.
		$balance = array_merge( $balance, (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_vcap_voucher' AND m.meta_value = 'yes' LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = 'usage_limit' WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND ( l.meta_value IS NULL OR l.meta_value IN ('', '0') )" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->suppress_errors( $suppress );
	$balance = array_values( array_unique( array_map( 'intval', $balance ) ) );
	VCAP\Install::suspend_balance_coupons( $balance );
	$changed = array_fill_keys( $balance, 'publish' );

	if ( $delete_data ) {
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
 * SET-VC-21: removes the plugin's rights (vcap_manage_vouchers, vcap_scan_tickets, vcap_increase_balance) from every
 * role of the current site – also from roles an administrator gave them in "Rights per role" – and forgets that the
 * table was saved, so a new installation starts with the default assignment again.
 */
function vcap_uninstall_caps(): void {
	vcap_uninstall_autoload();
	if ( function_exists( 'wp_roles' ) ) {
		VCAP\Admin\RoleCaps::remove_all( (array) wp_roles()->role_objects );
	}
	delete_option( VCAP\Admin\RoleCaps::CUSTOMISED_OPTION );
}

/**
 * Removes Voucher Captain's data of the current site (only when that site opted in). Leftover license data of
 * earlier builds is removed and the balance coupons are suspended on every site (see vcap_uninstall_coupons()).
 *
 * @return bool Whether the site opted in.
 */
function vcap_uninstall_site(): bool {
	vcap_uninstall_license_data(); // Every uninstall, also when the data is kept.
	$delete_data = 'yes' === get_option( 'vcap_delete_on_uninstall', 'no' );
	vcap_uninstall_coupons( $delete_data ); // First: needs the voucher table, which data deletion drops.
	vcap_uninstall_jobs(); // Every uninstall, also when the data is kept: nothing would run the jobs any more.
	vcap_uninstall_caps(); // Every uninstall: rights of a plugin that is gone (a new installation gives the defaults).
	if ( ! $delete_data ) {
		return false;
	}

	global $wpdb;
	foreach ( array( 'vcap_vouchers', 'vcap_events', 'vcap_code_pool', 'vcap_redemptions', 'vcap_promotion_usage', 'vcap_claims', 'vcap_promotion_counts', 'vcap_rate_limits', 'vcap_scan_keys', 'vcap_fc_requests' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
	}
	// Templates, field groups and promotions (vcap_discount, with their usage records).
	foreach ( array( 'vcap_template', 'vcap_field_group', 'vcap_discount', 'vcap_request' ) as $post_type ) {
		$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'any', 'trash', 'auto-draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
	$where   = vcap_uninstall_options_where();
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
	// Referral coupon id and count of this site's customers (per-site user options, VCAP\Referrals).
	vcap_uninstall_referral_meta( array( $wpdb->get_blog_prefix() . '_vcap_referral_coupon', $wpdb->get_blog_prefix() . '_vcap_referral_count' ) );
	// Own daily counter limits and the fixed till of staff members (per-site user options, VCAP\Counter).
	vcap_uninstall_referral_meta( array( $wpdb->get_blog_prefix() . 'vcap_counter_limit_amount', $wpdb->get_blog_prefix() . 'vcap_counter_limit_count', $wpdb->get_blog_prefix() . 'vcap_counter_location' ) );
	remove_role( 'vcap_cashier' );
	$uploads = wp_upload_dir( null, false );
	// The private folder: "vcap" or, since M12-43, "vcap-<random suffix>" (the option naming it is already gone).
	$dirs = array_filter(
		array_merge( array( trailingslashit( $uploads['basedir'] ) . 'vcap' ), glob( trailingslashit( $uploads['basedir'] ) . 'vcap-*', GLOB_ONLYDIR ) ?: array() ),
		static fn( $d ) => (bool) preg_match( '#/vcap(-[a-z0-9]{16,40})?$#', $d )
	);
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore
		}
		rmdir( $dir ); // phpcs:ignore
	}
	return true;
}

/**
 * Removes the scheduled jobs of the current site (Action Scheduler group "vcap", the recurring maintenance and
 * reservation release; the WP-Cron hook of earlier builds goes with vcap_uninstall_license_data()) – on every
 * uninstall, also when the data is kept.
 */
function vcap_uninstall_jobs(): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'vcap' );
		as_unschedule_all_actions( 'vcap_daily_maintenance' );
		as_unschedule_all_actions( 'vcap_release_stale_order_reservations' );
	}
}

/**
 * SQL condition for the options of a site that uninstall removes: Voucher Captain's own options, claims, scan
 * keys and transients, and the settings of its WooCommerce emails ("woocommerce_vcap_{email id}_settings", M12-67).
 */
function vcap_uninstall_options_where(): string {
	return "option_name LIKE 'vcap\_%' OR option_name LIKE '\_vcap\_claim\_%' OR option_name LIKE '\_vcap\_scan\_%' OR option_name LIKE '\_transient\_vcap\_%' OR option_name LIKE '\_transient\_timeout\_vcap\_%' OR option_name LIKE 'woocommerce\_vcap\_%\_settings'";
}

/**
 * Deletes the given user meta keys of all users.
 *
 * @param string[] $keys Meta keys.
 */
function vcap_uninstall_referral_meta( array $keys ): void {
	global $wpdb;
	foreach ( $keys as $key ) {
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) ); // phpcs:ignore
	}
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'user_meta' );
	}
}

/**
 * Removes what Voucher Captain stores network-wide with the users: the referral data of earlier versions (personal
 * referral coupon and reward count, now per site) and the email verification proof of personal vouchers
 * (VCAP\PersonalVoucher: verified address, time of the last email change, M12-67). Users are shared by all sites
 * of a network, so on a network only when every site opted in.
 */
function vcap_uninstall_user_meta(): void {
	vcap_uninstall_referral_meta( array( '_vcap_referral_coupon', '_vcap_referral_count', '_vcap_verified_email', '_vcap_email_changed' ) );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return; // Functions only (VCAP_UNINSTALL_FUNCTIONS_ONLY).
}

delete_site_transient( 'vcap_update_check' ); // Cached update check of earlier (licensed) builds.
delete_site_option( 'vcap_network_deactivating' ); // Heartbeat of a network deactivation (VCAP\Install).

if ( is_multisite() ) {
	// Network-wide uninstall: every site decides for itself (its own "Delete data on uninstall" setting).
	$vcap_sites = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
	vcap_uninstall_license_data( true ); // Network license options of earlier builds.
	$vcap_all_opted_in = true;
	foreach ( $vcap_sites as $vcap_site_id ) {
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
