<?php
/**
 * Uninstall: only removes data when "Delete data on uninstall" is enabled. WooCommerce coupons are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'wcvs_delete_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;
foreach ( array( 'wcvs_vouchers', 'wcvs_events', 'wcvs_code_pool' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}
foreach ( array( 'wcvs_template', 'wcvs_field_group' ) as $post_type ) {
	$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcvs\_%' OR option_name LIKE '\_transient\_wcvs\_%' OR option_name LIKE '\_transient\_timeout\_wcvs\_%'" ); // phpcs:ignore
foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'wcvs_manage_vouchers' );
		$role->remove_cap( 'wcvs_scan_tickets' );
	}
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'wcvs' );
}
$uploads = wp_upload_dir( null, false );
$dir     = trailingslashit( $uploads['basedir'] ) . 'wcvs';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore
	}
	rmdir( $dir ); // phpcs:ignore
}
