<?php
/**
 * Acceptance against a reachable WP License Captain server with real release packages (CI job
 * "license-acceptance"). Two WordPress sites: the license server (wp-license plugin, served over HTTPS as
 * https://licenses.test) and a shop with the Voucher Captain premium release ZIP. Run with
 * `wp eval-file tests/license-acceptance.php <mode> [args]` on the site the mode names.
 *
 * Server modes: server-setup <key-file>, server-releases <version>, server-bad-checksum|server-good-checksum <version>,
 *               server-status <status>.
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

/** Whether $hook has a callback [instance of $class, $method]. */
$hooked = static function ( string $hook, string $class, string $method ): bool {
	global $wp_filter;
	foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $method === $callback['function'][1] && $callback['function'][0] instanceof $class ) {
				return true;
			}
		}
	}
	return false;
};

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

	case 'server-releases':
		// Release storage: manifests made by wp-license tools/create-release-manifest.php, read by the registry
		// (tools/license-server/wplc-release-registry.php). The newest valid release is announced.
		echo "Server: release storage with SHA-256 manifests\n";
		$packages = apply_filters( 'wplc_update_packages', array(), VCAP_ACC_PRODUCT );
		$package  = $packages[ VCAP_ACC_PRODUCT ] ?? array();
		$expected = (string) ( $args[1] ?? '' );
		$zip      = ABSPATH . 'packages/' . basename( (string) parse_url( (string) ( $package['package_url'] ?? '' ), PHP_URL_PATH ) );
		$check( $expected === ( $package['version'] ?? '' ), "the registry announces the newest release {$expected}", $package );
		$check( is_readable( $zip ) && hash_equals( (string) hash_file( 'sha256', $zip ), (string) ( $package['sha256'] ?? '' ) ), 'its SHA-256 matches the stored ZIP' );
		$check( '' !== ( $package['release_notes'] ?? '' ) && '8.1' === ( $package['requires_php'] ?? '' ) && '' !== ( $package['tested_wp'] ?? '' ), 'release notes and requirements come from the manifest' );
		break;

	case 'server-bad-checksum':
	case 'server-good-checksum':
		$file     = WP_CONTENT_DIR . '/wplc-releases/' . VCAP_ACC_PRODUCT . '-' . (string) ( $args[1] ?? '' ) . '.json';
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		$zip      = ABSPATH . 'packages/' . (string) $manifest['package_file'];
		$manifest['sha256'] = 'server-bad-checksum' === $mode ? str_repeat( '0', 64 ) : (string) hash_file( 'sha256', $zip );
		file_put_contents( $file, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo 'server-bad-checksum' === $mode ? "  ok   manifest of the release now names a wrong SHA-256\n" : "  ok   manifest names the real SHA-256 again\n";
		break;

	case 'server-status':
		$status = (string) ( $args[1] ?? '' );
		$check( WPLC\License\LicenseLifecycle::admin_set_status( (int) get_option( 'vcap_acc_license_id' ), $status, 'CI acceptance', 1 ), "license set to {$status} on the server" );
		break;

	/* ------------------------------------------------------------------ shop with Voucher Captain premium */

	case 'shop-locked':
		echo "Shop: premium build before activation\n";
		$check( defined( 'VCAP_LICENSE_REQUIRED' ) && VCAP\Licensing\Entitlements::required() && 'premium' === VCAP\Licensing\Entitlements::edition(), 'premium release ZIP requires a license (edition premium, VCAP_LICENSE_REQUIRED)' );
		$check( VCAP_ACC_SERVER === VCAP\Licensing\Entitlements::server_url() && VCAP\Licensing\Entitlements::server_pinned(), 'the license server is fixed in the build (VCAP_LICENSE_SERVER)' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$header = get_plugin_data( WP_PLUGIN_DIR . '/' . VCAP_ACC_BASENAME, false, false );
		$check( 'licenses.test' === wp_parse_url( (string) $header['UpdateURI'], PHP_URL_HOST ), 'plugin header "Update URI" names the license server (no WordPress.org updates)', $header['UpdateURI'] );
		$check( array() === array_diff( array( 'promotions', 'referrals', 'topup', 'reports', 'passcaptain' ), VCAP\Licensing\Entitlements::locked() ), 'every premium feature is locked without activation', VCAP\Licensing\Entitlements::locked() );
		add_filter( 'vcap_license_required', '__return_false' );
		add_filter( 'vcap_premium_feature_allowed', '__return_true' );
		$check( ! $allows( 'promotions' ) && VCAP\Licensing\Entitlements::required(), 'no filter unlocks a premium feature in the premium build' );
		remove_filter( 'vcap_license_required', '__return_false' );
		remove_filter( 'vcap_premium_feature_allowed', '__return_true' );
		$check( ! $hooked( 'vcap_product_settings_fields', 'VCAP\TopUp', 'product_field' ) && $hooked( 'woocommerce_order_status_refunded', 'VCAP\TopUp', 'revoke' ), 'locked: new top-up sales are not registered, the reversal of sold top-ups is' );
		$check( false !== has_filter( 'woocommerce_coupon_discount_types' ), 'locked: the promotion coupon type stays registered for existing orders' );
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
		$check( $allows( 'promotions' ) && $hooked( 'vcap_product_settings_fields', 'VCAP\TopUp', 'product_field' ), 'premium features are registered in a new request (gate reads the snapshot, no server call)' );
		$check( array( 'referrals' ) === VCAP\Licensing\Entitlements::locked(), 'only refer-a-friend is locked', VCAP\Licensing\Entitlements::locked() );
		break;

	case 'shop-offline':
		echo "Shop: license server unreachable\n";
		$online = VCAP\Licensing\Entitlements::client()->check_entitlements();
		$check( is_wp_error( $online ), 'the server cannot be reached', is_wp_error( $online ) ? $online->get_error_code() : $online );
		VCAP\Licensing\Entitlements::refresh();
		$check( $allows( 'promotions' ) && ! $allows( 'referrals' ), 'the signed offline snapshot keeps the rights during the outage' );
		$check( $hooked( 'woocommerce_before_add_to_cart_button', 'VCAP\TopUp', 'code_field' ), 'premium features keep working while the server is down' );
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
		$before = get_plugin_data( WP_PLUGIN_DIR . '/' . VCAP_ACC_BASENAME, false, false )['Version'];
		// Like WP-Cron (wp_update_plugins event): no current user.
		wp_set_current_user( 0 );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$offer = get_site_transient( 'update_plugins' )->response[ VCAP_ACC_BASENAME ] ?? null;
		$check( is_object( $offer ) && ! empty( $offer->wplc_managed ) && false !== strpos( (string) $offer->package, VCAP_ACC_SERVER . '/wp-json/wplc/v1/updates/download/' ), 'update offered by the license server with a one-time download grant – also without a current user (cron)', $offer );
		$asked = 0;
		$count = static function ( $response, $context, $class, $request_args, $url ) use ( &$asked ) {
			$asked += str_contains( (string) $url, '/wplc/v1/updates/check' ) ? 1 : 0;
		};
		add_action( 'http_api_debug', $count, 10, 5 );
		set_site_transient( 'update_plugins', get_site_transient( 'update_plugins' ) ); // Next cron run / admin page load.
		remove_action( 'http_api_debug', $count, 10 );
		$cron_offer = get_site_transient( 'update_plugins' )->response[ VCAP_ACC_BASENAME ] ?? null;
		$check( 0 === $asked && is_object( $cron_offer ) && $cron_offer->package === $offer->package, 'the offer survives the next transient refresh from the cache, without asking the license server again', $asked );
		wp_set_current_user( 1 );
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ); // collects errors (get_errors()).
		$result   = $upgrader->upgrade( VCAP_ACC_BASENAME );
		wp_clean_plugins_cache();
		$after = get_plugin_data( WP_PLUGIN_DIR . '/' . VCAP_ACC_BASENAME, false, false )['Version'];
		if ( 'shop-update-rejected' === $mode ) {
			$errors = $upgrader->skin->get_errors();
			$codes  = array_merge( is_wp_error( $result ) ? $result->get_error_codes() : array(), is_wp_error( $errors ) ? $errors->get_error_codes() : array() );
			$check( true !== $result && $before === $after, 'package with a wrong SHA-256 is not installed, the plugin stays at ' . $before, array( 'result' => is_wp_error( $result ) ? $result->get_error_code() : $result, 'codes' => $codes ) );
			$check( in_array( 'wplc_checksum_mismatch', $codes, true ), 'stopped with wplc_checksum_mismatch', $codes );
		} else {
			$check( true === $result, 'update downloaded through the grant, checksum verified and installed', is_wp_error( $result ) ? $result->get_error_message() : $result );
			// The upgrader deactivates the plugin before installing; wp-admin/update.php reactivates it afterwards.
			$reactivated = activate_plugin( VCAP_ACC_BASENAME );
			$check( ! is_wp_error( $reactivated ), 'updated plugin reactivated as after an update in the admin', is_wp_error( $reactivated ) ? $reactivated->get_error_message() : null );
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
