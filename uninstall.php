<?php
/**
 * Uninstall: only removes data when "Delete data on uninstall" is enabled. Always: the license activation is
 * released, gift card and store credit coupons and coupons with rules only Voucher Captain checks are suspended
 * (draft) – without the plugin nothing would deduct their balance or check their rules; with data deletion all
 * Voucher Captain coupons go to the trash (restorable there), the other WooCommerce coupons are kept.
 */

// VCAP_UNINSTALL_FUNCTIONS_ONLY: tests/install-integration.php loads the functions without uninstalling.
defined( 'WP_UNINSTALL_PLUGIN' ) || defined( 'VCAP_UNINSTALL_FUNCTIONS_ONLY' ) || exit;

/**
 * Loads the plugin's classes (Composer autoloader and its own PSR-4 loader for src/), for the shared selection of
 * coupons to suspend (VCAP\Install) and the license client.
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
	// Premium build: the license server is fixed in the plugin file; defined here as well, so the license client
	// pins its host for the release request (the plugin file itself is not loaded during an uninstall).
	if ( ! defined( 'VCAP_LICENSE_SERVER' ) && is_readable( __DIR__ . '/voucher-captain.php' ) && preg_match( "/define\\( 'VCAP_LICENSE_SERVER', '([^']+)' \\);/", (string) file_get_contents( __DIR__ . '/voucher-captain.php' ), $m ) ) {
		define( 'VCAP_LICENSE_SERVER', $m[1] );
	}
}

/**
 * Releases this site's license activation on the license server and deletes its credentials – on every uninstall,
 * also when the data is kept, so the site does not keep using a place of the license. Best effort with a short
 * timeout: an unreachable server never blocks the uninstall (the activation can still be released in the customer
 * account on the license server).
 *
 * @param float $deadline microtime() by which all releases of this uninstall must be done (0 = no common limit).
 * @param bool  $network  The network license (plugin network-activated) instead of this site's.
 */
function vcap_uninstall_release_license( float $deadline = 0.0, bool $network = false ): void {
	// VCAP\Licensing\Entitlements::CREDENTIALS_OPTION; a network license is stored network-wide.
	$credentials = $network ? get_site_option( 'vcap_wplc_credentials' ) : get_option( 'vcap_wplc_credentials' );
	if ( is_array( $credentials ) && ! empty( $credentials['activation_id'] ) ) {
		vcap_uninstall_autoload();
		$server = (string) ( $credentials['server_url'] ?? '' );
		if ( '' === $server ) {
			$server = (string) ( $network ? get_site_option( 'vcap_wplc_server_url', '' ) : get_option( 'vcap_wplc_server_url', '' ) );
		}
		if ( '' === $server && defined( 'VCAP_LICENSE_SERVER' ) ) {
			$server = (string) VCAP_LICENSE_SERVER;
		}
		$timeout = $deadline > 0 ? (int) floor( min( 5.0, $deadline - microtime( true ) ) ) : 5;
		if ( '' !== $server && $timeout >= 1 && class_exists( 'VCAP\\Licensing\\LicenseClient' ) ) {
			// deactivate() refuses an activation copied from another website: a copy never releases the original's place.
			$client = new VCAP\Licensing\LicenseClient( $server, 'voucher-captain', '0', 'vcap_wplc_credentials', $network ? 'network' : '' );
			$client->set_timeout( $timeout );
			$client->deactivate();
		}
	}
	// Removed also when the server could not be reached: the plugin and its license page are gone.
	if ( $network ) {
		foreach ( array( '', '_entitlements_cache', '_pending', '_last_check', '_copy_of', '_migrated' ) as $suffix ) {
			delete_site_option( 'vcap_wplc_credentials' . $suffix );
		}
		delete_site_option( 'vcap_wplc_server_url' );
	} else {
		foreach ( array( '', '_entitlements_cache', '_pending', '_last_check', '_copy_of' ) as $suffix ) {
			delete_option( 'vcap_wplc_credentials' . $suffix );
		}
	}
}

/**
 * Network license (plugin was network-activated): released and its network options deleted on every network
 * uninstall, within the common time budget.
 */
function vcap_uninstall_network_license( float $deadline = 0.0 ): void {
	vcap_uninstall_release_license( $deadline, true );
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
 * Removes Voucher Captain's data of the current site (only when that site opted in). The background license
 * check is removed and the balance coupons are suspended on every site (see vcap_uninstall_coupons()).
 *
 * @return bool Whether the site opted in.
 */
function vcap_uninstall_site(): bool {
	wp_clear_scheduled_hook( 'vcap_license_refresh' ); // VCAP\Licensing\Entitlements::REFRESH_HOOK.
	vcap_uninstall_release_license(); // Every uninstall (a network uninstall released them before, with a time budget).
	$delete_data = 'yes' === get_option( 'vcap_delete_on_uninstall', 'no' );
	vcap_uninstall_coupons( $delete_data ); // First: needs the voucher table, which data deletion drops.
	if ( ! $delete_data ) {
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
	// Referral coupon id and count of this site's customers (per-site user options, VCAP\Referrals).
	vcap_uninstall_referral_meta( array( $wpdb->get_blog_prefix() . '_vcap_referral_coupon', $wpdb->get_blog_prefix() . '_vcap_referral_count' ) );
	foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'vcap_manage_vouchers' );
			$role->remove_cap( 'vcap_scan_tickets' );
			$role->remove_cap( 'vcap_increase_balance' );
		}
	}
	remove_role( 'vcap_cashier' );
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
 * Removes the referral data earlier versions stored network-wide with the users (personal referral coupon and
 * reward count, now per site). Users are shared by all sites of a network, so on a network only when every site
 * opted in.
 */
function vcap_uninstall_user_meta(): void {
	vcap_uninstall_referral_meta( array( '_vcap_referral_coupon', '_vcap_referral_count' ) );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return; // Functions only (VCAP_UNINSTALL_FUNCTIONS_ONLY).
}

delete_site_transient( 'vcap_update_check' ); // Cached update check (VCAP\Licensing\PremiumUpdater).
delete_site_option( 'vcap_network_deactivating' ); // Heartbeat of a network deactivation (VCAP\Install).

if ( is_multisite() ) {
	// Network-wide uninstall: every site decides for itself (its own "Delete data on uninstall" setting).
	$vcap_sites = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
	// License releases first, all together within 30 seconds (one slow server must not use up the request before
	// the coupons of every site are suspended); sites left over drop their credentials without a release.
	$vcap_release_deadline = microtime( true ) + 30;
	vcap_uninstall_network_license( $vcap_release_deadline );
	foreach ( $vcap_sites as $vcap_site_id ) {
		switch_to_blog( $vcap_site_id );
		vcap_uninstall_release_license( $vcap_release_deadline );
		restore_current_blog();
	}
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
