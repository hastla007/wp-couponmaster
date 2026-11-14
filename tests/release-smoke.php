<?php
/**
 * Release smoke of the ZIP of a release draft (workflow release-smoke.yml, RELEASE.md). Runs on a throw-away shop:
 *
 *   wp eval-file tests/release-smoke.php installed <version>
 *       <version> is installed and active; it is the free core (docs/FREE-PREMIUM.md): every feature is available,
 *       there is no license page, no "Update URI" header, no update or license handler of its own, and no license
 *       data of earlier builds is left.
 */

defined( 'ABSPATH' ) || exit;

$mode     = (string) ( $args[0] ?? '' );
$basename = 'voucher-captain/voucher-captain.php';
$check    = static function ( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "::error::Release smoke: {$message}" . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) . "\n" );
		exit( 1 );
	}
	echo "  ok   {$message}\n";
};

/** @return string[] classes of the object callbacks hooked to $hook (all hooks when empty) */
$hooked = static function ( string $hook = '' ): array {
	global $wp_filter;
	$classes = array();
	foreach ( '' === $hook ? array_keys( (array) $wp_filter ) : array( $hook ) as $name ) {
		if ( ! isset( $wp_filter[ $name ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $name ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) ) {
					$classes[] = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				}
			}
		}
	}
	return array_values( array_unique( $classes ) );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';

switch ( $mode ) {
	case 'installed':
		$version = (string) ( $args[1] ?? '' );
		$header  = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
		$check( is_plugin_active( $basename ) && function_exists( 'vcap' ), 'Voucher Captain is active' );
		$check( $version === VCAP_VERSION && $version === $header['Version'], "Voucher Captain {$version} is installed", VCAP_VERSION );
		$check( '' === (string) ( $header['UpdateURI'] ?? '' ), 'no "Update URI" header (updates come from WordPress.org)', $header['UpdateURI'] ?? null );
		$check( ! is_dir( VCAP_PATH . 'src/Licensing' ) && ! is_file( VCAP_PATH . 'config/premium-features.php' ), 'no licensing code in the ZIP' );
		$check( ! defined( 'VCAP_EDITION' ) && ! defined( 'VCAP_LICENSE_REQUIRED' ) && ! defined( 'VCAP_LICENSE_SERVER' ), 'no edition or license constants' );
		$own_updaters = array_filter( array_merge( $hooked( 'pre_set_site_transient_update_plugins' ), $hooked( 'plugins_api' ) ), static fn( $c ) => str_starts_with( $c, 'VCAP\\' ) );
		$check( ! $own_updaters, 'no update handler of its own', array_values( $own_updaters ) );
		$all = $hooked();
		foreach ( array(
			'VCAP\\TopUp'                     => 'gift card top-up',
			'VCAP\\Referrals'                 => 'refer a friend',
			'VCAP\\Discounts\\CartIntegration' => 'promotions',
			'VCAP\\Discounts\\Bonus'          => 'bonus vouchers',
			'VCAP\\Compat\\PassCaptainTickets' => 'Pass Captain ticket vouchers',
			'VCAP\\Compat\\PassCaptainScanner' => 'Pass Captain shared scanner',
		) as $class => $feature ) {
			$check( in_array( $class, $all, true ), "{$feature} is registered" );
		}
		$check( in_array( 'VCAP\\TopUp', $hooked( 'vcap_product_settings_fields' ), true ) && in_array( 'VCAP\\Compat\\PassCaptainTickets', $hooked( 'vcap_product_settings_fields' ), true ), 'top-up and ticket-voucher product settings are offered' );
		$check( in_array( 'VCAP\\Referrals', $hooked( 'vcap_account_vouchers_top' ), true ), 'the referral block is shown in My Account' );
		$check( ! method_exists( 'VCAP\\Admin\\ReportsAdmin', 'locked' ), 'every report view is available' );
		$check( ! array_filter( $all, static fn( $c ) => str_starts_with( $c, 'VCAP\\Licensing\\' ) ), 'no license hooks' );
		$left = array_filter( VCAP\Install::LEGACY_LICENSE_OPTIONS, static fn( $o ) => false !== get_option( $o ) );
		$check( ! $left && ! wp_next_scheduled( VCAP\Install::LEGACY_LICENSE_HOOK ), 'no license data of earlier builds is left', array_values( $left ) );
		break;

	default:
		fwrite( STDERR, "Usage: wp eval-file tests/release-smoke.php installed <version>\n" );
		exit( 2 );
}
