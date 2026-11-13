<?php
/**
 * Acceptance against a reachable WP License Captain server with real release packages (CI job
 * "license-acceptance"). Two WordPress sites: the license server (wp-license plugin, served over HTTPS as
 * https://licenses.test) and a shop with the Voucher Captain premium release ZIP. Run with
 * `wp eval-file tests/license-acceptance.php <mode> [args]` on the site the mode names.
 *
 * Server modes: server-setup <key-file>, server-package <version> <zip>, server-bad-checksum, server-status <status>.
 * Shop modes:   shop-locked, shop-activate <key-file>, shop-active, shop-offline, shop-suspended,
 *               shop-update-rejected, shop-update, shop-updated <version>, shop-deactivate.
 */

defined( 'ABSPATH' ) || exit;

$mode = (string) ( $args[0] ?? '' );
$fail = static function ( string $message ): void {
	fwrite( STDERR, "FAIL {$message}\n" );
	exit( 1 );
};
$check = static function ( bool $condition, string $message, $detail = null ) use ( $fail ): void {
	if ( ! $condition ) {
		$fail( $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "  ok   {$message}\n";
};

const VCAP_ACC_SERVER   = 'https://licenses.test';
const VCAP_ACC_PRODUCT  = 'voucher-captain';
const VCAP_ACC_BASENAME = 'voucher-captain/voucher-captain.php';

/** Fresh view of the gate (the snapshot is read once per request otherwise). */
$allows = static function ( string $feature ): bool {
	VCAP\Licensing\Entitlements::reset();
	return VCAP\Licensing\Entitlements::allows( $feature );
};

switch ( $mode ) {
	/* ------------------------------------------------------------------ license server */

	case 'server-setup':
		echo "Server: catalog, paid order, license\n";
		$product = new WC_Product_Simple();
		$product->set_name( 'Voucher Captain Business' );
		$product->set_regular_price( '99' );
		$product->set_virtual( true );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();
		// Rights of the plan: promotions, top-up, reports and Pass Captain on, refer-a-friend off.
		$mapped = WPLC\Catalog\Catalog::save_mapping(
			$product_id,
			0,
			array(
				'product_key'      => VCAP_ACC_PRODUCT,
				'plan_key'         => 'business',
				'billing_interval' => 'year',
				'live_site_limit'  => 2,
				'entitlements'     => array(
					'voucher.promotions'  => true,
					'voucher.topup'       => true,
					'voucher.reports'     => true,
					'voucher.passcaptain' => true,
					'voucher.referrals'   => false,
				),
			)
		);
		$check( $mapped, 'product mapped to voucher-captain / business' );
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_billing_email( 'license-buyer@example.test' );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'ci-license-acceptance' );
		$order = wc_get_order( $order->get_id() );
		$ids   = array();
		foreach ( $order->get_items() as $item ) {
			$ids = array_merge( $ids, (array) $item->get_meta( '_wplc_license_ids' ) );
		}
		$check( 1 === count( $ids ), 'paid order issues exactly one license', $ids );
		global $wpdb;
		$tables  = WPLC\Infrastructure\Database\Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d", (int) $ids[0] ), ARRAY_A );
		$check( is_array( $license ) && 'active' === $license['status'] && VCAP_ACC_PRODUCT === $license['product_key'], 'license is active for voucher-captain' );
		$key = WPLC\License\LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] );
		$check( '' !== $key && hash_equals( (string) $license['license_key_hash'], WPLC\License\LicenseKey::hash( $key ) ), 'license key derived as the customer receives it (only its hash is stored)' );
		update_option( 'vcap_acc_license_id', (int) $ids[0], false );
		file_put_contents( (string) ( $args[1] ?? '' ), $key );
		break;

	case 'server-package':
		$zip = (string) ( $args[2] ?? '' );
		$sha = is_readable( $zip ) ? hash_file( 'sha256', $zip ) : '';
		$check( '' !== $sha, 'update package built: ' . basename( $zip ) . ' sha256 ' . $sha );
		update_option(
			'vcap_acc_package',
			array(
				'version'      => (string) ( $args[1] ?? '' ),
				'package_url'  => VCAP_ACC_SERVER . '/packages/' . basename( $zip ),
				'sha256'       => $sha,
				'requires_php' => '8.1',
				'requires_wp'  => '6.4',
				'tested_wp'    => '7.1',
			),
			false
		);
		break;

	case 'server-bad-checksum':
		$package           = (array) get_option( 'vcap_acc_package', array() );
		$package['sha256'] = str_repeat( '0', 64 );
		update_option( 'vcap_acc_package', $package, false );
		echo "  ok   server announces a wrong SHA-256 for the package\n";
		break;

	case 'server-good-checksum':
		$package           = (array) get_option( 'vcap_acc_package', array() );
		$zip               = ABSPATH . 'packages/' . basename( (string) parse_url( (string) $package['package_url'], PHP_URL_PATH ) );
		$package['sha256'] = (string) hash_file( 'sha256', $zip );
		update_option( 'vcap_acc_package', $package, false );
		echo "  ok   server announces the real SHA-256 again\n";
		break;

	case 'server-status':
		$status = (string) ( $args[1] ?? '' );
		$check( WPLC\License\LicenseLifecycle::admin_set_status( (int) get_option( 'vcap_acc_license_id' ), $status, 'CI acceptance', 1 ), "license set to {$status} on the server" );
		break;

	/* ------------------------------------------------------------------ shop with Voucher Captain premium */

	case 'shop-locked':
		echo "Shop: premium build before activation\n";
		$check( defined( 'VCAP_LICENSE_REQUIRED' ) && VCAP\Licensing\Entitlements::required(), 'premium release ZIP requires a license (VCAP_LICENSE_REQUIRED)' );
		$check( array() === array_diff( array( 'promotions', 'referrals', 'topup', 'reports', 'passcaptain' ), VCAP\Licensing\Entitlements::locked() ), 'every premium feature is locked without activation', VCAP\Licensing\Entitlements::locked() );
		$check( false === has_filter( 'woocommerce_coupon_discount_types' ), 'locked promotions are not registered (no promotion coupon type)' );
		break;

	case 'shop-activate':
		echo "Shop: activation against the license server\n";
		$key = trim( (string) file_get_contents( (string) ( $args[1] ?? '' ) ) );
		update_option( VCAP\Licensing\Entitlements::SERVER_OPTION, VCAP_ACC_SERVER, false );
		$wrong = ( new VCAP\Licensing\LicenseClient( VCAP_ACC_SERVER, VCAP_ACC_PRODUCT, VCAP_VERSION, VCAP\Licensing\Entitlements::CREDENTIALS_OPTION ) )->activate( 'WPLC-00000000-WRONG-KEY' );
		$check( is_wp_error( $wrong ) && false === get_option( VCAP\Licensing\Entitlements::CREDENTIALS_OPTION ), 'a wrong license key is refused, nothing stored', is_wp_error( $wrong ) ? $wrong->get_error_code() : $wrong );
		$result = VCAP\Licensing\Entitlements::client()->activate( $key );
		$check( ! is_wp_error( $result ), 'activation with the real license key', is_wp_error( $result ) ? array( $result->get_error_code(), $result->get_error_message() ) : null );
		$credentials = (array) get_option( VCAP\Licensing\Entitlements::CREDENTIALS_OPTION, array() );
		$check( wp_is_uuid( (string) ( $credentials['installation_id'] ?? '' ) ) && strlen( (string) ( $credentials['installation_secret'] ?? '' ) ) >= 32 && (int) ( $credentials['activation_id'] ?? 0 ) > 0, 'installation id, secret and activation id stored' );
		global $wpdb;
		$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $key ) . '%' ) ), 'the license key itself is stored nowhere' );
		$check( 'no' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VCAP\Licensing\Entitlements::CREDENTIALS_OPTION ) ) || 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VCAP\Licensing\Entitlements::CREDENTIALS_OPTION ) ), 'credentials are not autoloaded' );
		VCAP\Licensing\Entitlements::refresh();
		$check( $allows( 'promotions' ) && $allows( 'topup' ) && $allows( 'reports' ) && $allows( 'passcaptain' ), 'rights of the plan are unlocked from the signed snapshot' );
		$check( ! $allows( 'referrals' ), 'a right the plan sets to false stays locked (refer-a-friend)' );
		break;

	case 'shop-active':
		echo "Shop: next request after activation\n";
		$check( false !== has_filter( 'woocommerce_coupon_discount_types' ), 'promotions are registered in a new request (gate reads the snapshot, no server call)' );
		$check( array( 'referrals' ) === VCAP\Licensing\Entitlements::locked(), 'only refer-a-friend is locked', VCAP\Licensing\Entitlements::locked() );
		break;

	case 'shop-offline':
		echo "Shop: license server unreachable\n";
		$online = VCAP\Licensing\Entitlements::client()->check_entitlements();
		$check( is_wp_error( $online ), 'the server cannot be reached', is_wp_error( $online ) ? $online->get_error_code() : $online );
		VCAP\Licensing\Entitlements::refresh();
		$check( $allows( 'promotions' ) && ! $allows( 'referrals' ), 'the signed offline snapshot keeps the rights during the outage' );
		$check( false !== has_filter( 'woocommerce_coupon_discount_types' ), 'premium features keep working while the server is down' );
		break;

	case 'shop-suspended':
		echo "Shop: license suspended on the server\n";
		VCAP\Licensing\Entitlements::refresh();
		$check( ! $allows( 'promotions' ) && ! $allows( 'topup' ), 'a suspended license locks the premium features at the next refresh (fail-closed)' );
		$check( false === get_option( VCAP\Licensing\Entitlements::CREDENTIALS_OPTION . '_entitlements_cache' ), 'the offline snapshot of the suspended license is deleted' );
		break;

	case 'shop-reactivated':
		echo "Shop: license active again\n";
		VCAP\Licensing\Entitlements::refresh();
		$check( $allows( 'promotions' ) && ! $allows( 'referrals' ), 'reactivated license unlocks the plan rights again' );
		break;

	case 'shop-update-rejected':
	case 'shop-update':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_set_current_user( 1 );
		$before = get_plugin_data( WP_PLUGIN_DIR . '/' . VCAP_ACC_BASENAME, false, false )['Version'];
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$offer = get_site_transient( 'update_plugins' )->response[ VCAP_ACC_BASENAME ] ?? null;
		$check( is_object( $offer ) && ! empty( $offer->wplc_managed ) && false !== strpos( (string) $offer->package, VCAP_ACC_SERVER . '/wp-json/wplc/v1/updates/download/' ), 'update offered by the license server with a one-time download grant', $offer );
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( VCAP_ACC_BASENAME );
		wp_clean_plugins_cache();
		$after = get_plugin_data( WP_PLUGIN_DIR . '/' . VCAP_ACC_BASENAME, false, false )['Version'];
		if ( 'shop-update-rejected' === $mode ) {
			$errors = $upgrader->skin->get_errors();
			$check( true !== $result && $before === $after, 'package with a wrong SHA-256 is not installed, the plugin stays at ' . $before, array( 'result' => is_wp_error( $result ) ? $result->get_error_code() : $result, 'errors' => is_wp_error( $errors ) ? $errors->get_error_codes() : null ) );
			$check( is_wp_error( $errors ) && in_array( 'wplc_checksum_mismatch', $errors->get_error_codes(), true ), 'stopped with wplc_checksum_mismatch', is_wp_error( $errors ) ? $errors->get_error_codes() : null );
		} else {
			$check( true === $result, 'update downloaded through the grant, checksum verified and installed', is_wp_error( $result ) ? $result->get_error_message() : $result );
			echo "  info installed version {$after} (was {$before})\n";
		}
		break;

	case 'shop-updated':
		echo "Shop: after the update\n";
		$expected = (string) ( $args[1] ?? '' );
		$check( VCAP_VERSION === $expected, "Voucher Captain {$expected} runs after the update", VCAP_VERSION );
		$check( defined( 'VCAP_LICENSE_REQUIRED' ) && $allows( 'promotions' ) && ! $allows( 'referrals' ), 'the updated premium build keeps activation and rights' );
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$check( ! isset( get_site_transient( 'update_plugins' )->response[ VCAP_ACC_BASENAME ] ), 'no further update is offered for the installed version' );
		break;

	case 'shop-deactivate':
		echo "Shop: deactivation\n";
		$result = VCAP\Licensing\Entitlements::client()->deactivate();
		$check( ! is_wp_error( $result ), 'site deactivated on the server', is_wp_error( $result ) ? $result->get_error_code() : null );
		$check( false === get_option( VCAP\Licensing\Entitlements::CREDENTIALS_OPTION ), 'local credentials removed' );
		$check( ! $allows( 'promotions' ), 'premium features locked again after deactivation' );
		break;

	default:
		$fail( "unknown mode {$mode}" );
}
