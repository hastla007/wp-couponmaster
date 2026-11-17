<?php
/**
 * Maintenance and diagnostic paths (TODO D15):
 * - Site Health → Info: Diagnostics::debug_information() adds the "Voucher Captain" section through the core filter,
 *   leaves foreign sections and non-array input alone, contains every maintenance step and no customer data, and
 *   counts open external reservations;
 * - PdfRenderer::to_temp_file() returns null and logs the voucher when the private temp folder cannot be written;
 * - Issuer::issue_async() (Action Scheduler retry callback) ignores invalid ids and unpaid orders, accepts the plain
 *   id and the action's args array, and never issues twice;
 * - VoucherService (typed API v1 facade) answers a provider result that is neither an array nor a WP_Error with
 *   vcap_provider_invalid_response instead of a TypeError.
 *
 *   wp eval-file tests/diagnostics-integration.php
 */

use VCAP\Delivery;
use VCAP\Voucher;

function vcap_diag_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_diag_card( string $email ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-diag-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 40 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 87000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = 40;
	$v->balance        = 40;
	$v->currency       = get_woocommerce_currency();
	$v->status         = Voucher::STATUS_ACTIVE;
	$v->created_at     = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$v->delivered_at   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$v->buyer_email    = $email;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	if ( ! $v->id ) {
		throw new RuntimeException( 'Could not create diagnostics test voucher.' );
	}
	return $v;
}

$tag    = strtolower( wp_generate_password( 6, false, false ) );
$logged = array();
$listen = static function ( $message, $level, $context, $handler = null ) use ( &$logged ) {
	// The filter runs once per log handler: count each entry for the first handler only.
	static $first = null;
	$first = $first ?? ( is_object( $handler ) ? get_class( $handler ) : '' );
	if ( 'voucher-captain' === ( $context['source'] ?? '' ) && ( is_object( $handler ) ? get_class( $handler ) : '' ) === $first ) {
		$logged[] = array( (string) $message, (string) $level, (array) $context );
	}
	return $message;
};
add_filter( 'woocommerce_logger_log_message', $listen, 10, 4 );
$no_mail = static fn() => true;
add_filter( 'pre_wp_mail', $no_mail, 999 );

/* ---- 1. Site Health information ------------------------------------------------------------------------------- */

$diag = new VCAP\Diagnostics();
vcap_diag_assert( 'not an array' === $diag->debug_information( 'not an array' ) && null === $diag->debug_information( null ), 'debug_information hands non-array input back unchanged' );

$card    = vcap_diag_card( "diag-{$tag}@example.com" );
$before  = (int) $diag->fields()['open_reservations']['value'];
$key     = 'diag-' . wp_generate_uuid4();
$reserve = vcap()->redemptions->reserve( $card->code, array( 'key' => $key, 'target' => 'test:diagnostics', 'amount' => 5 ) );
vcap_diag_assert( is_array( $reserve ) && 'reserved' === ( $reserve['state'] ?? '' ), 'test reservation created', $reserve );

$info = apply_filters( 'debug_information', array( 'wp-core' => array( 'label' => 'WordPress', 'fields' => array() ) ) );
vcap_diag_assert( is_array( $info ) && isset( $info['wp-core'], $info['voucher-captain'] ) && 'WordPress' === $info['wp-core']['label'], 'the core filter debug_information adds the "Voucher Captain" section and keeps the other sections' );
$section = $info['voucher-captain'];
$fields  = (array) ( $section['fields'] ?? array() );
$expect  = array( 'version', 'db_version', 'schema_error', 'pass_captain', 'maintenance', 'deliveries_given_up', 'undelivered', 'failed_actions', 'open_reservations', 'attention', 'shortfalls', 'innodb', 'legacy_rows', 'adapters', 'issuing', 'retention', 'verbose_log', 'payment_after_tax' );
vcap_diag_assert( '' !== (string) ( $section['label'] ?? '' ) && array() === array_diff( $expect, array_keys( $fields ) ), 'the section lists version, database, maintenance, deliveries, reservations, cases and settings', array_keys( $fields ) );
$malformed = array_filter( $fields, static fn( $f ) => ! is_array( $f ) || ! is_string( $f['label'] ?? null ) || ! is_string( $f['value'] ?? null ) || ( isset( $f['debug'] ) && ! is_string( $f['debug'] ) ) );
vcap_diag_assert( ! $malformed, 'every Site Health field has a string label and value (and a string debug value where given)', array_keys( $malformed ) );
vcap_diag_assert( VCAP_VERSION === $fields['version']['value'] && str_contains( $fields['db_version']['value'], (string) VCAP_DB_VERSION ), 'the plugin version and the expected database version are shown', array( $fields['version'], $fields['db_version'] ) );
$d14_expected = array();
if ( '' !== VCAP\Compat\MultiCurrency::adapter() ) {
	$d14_expected[] = 'currency: ' . VCAP\Compat\MultiCurrency::adapter();
}
if ( VCAP\Compat\PassCaptain::active() ) {
	$d14_expected[] = 'passcaptain';
}
$d14_adapters = implode( ', ', $d14_expected );
vcap_diag_assert( ( VCAP\Compat\PassCaptain::active() ? ( defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : __( 'Active', 'voucher-captain' ) ) : __( 'Not active', 'voucher-captain' ) ) === $fields['pass_captain']['value'] && ( '' === $d14_adapters ? true : str_starts_with( $fields['adapters']['value'], $d14_adapters ) ), 'D14: Pass Captain and the currency switcher are reported by the integrations themselves (vcap_active_integrations), with the same values as before', array( $fields['pass_captain']['value'], $fields['adapters']['value'] ) );
$missing_steps = array_filter( Delivery::MAINTENANCE_STEPS, static fn( $step ) => ! str_contains( $fields['maintenance']['value'], $step . ': ' ) );
vcap_diag_assert( ! $missing_steps, 'the maintenance summary names every daily maintenance step', $fields['maintenance']['value'] );
vcap_diag_assert( (int) $fields['open_reservations']['value'] === $before + 1, 'an open external reservation is counted', array( $before, $fields['open_reservations']['value'] ) );
$json = (string) wp_json_encode( $info['voucher-captain'] );
vcap_diag_assert( ! str_contains( $json, "diag-{$tag}@example.com" ) && ! str_contains( $json, $card->code ) && ! str_contains( $json, $card->token ), 'the support information contains no customer address, voucher code or token' );

$released = vcap()->redemptions->release( $key );
vcap_diag_assert( is_array( $released ) && (int) $diag->fields()['open_reservations']['value'] === $before, 'a released reservation is no longer counted', $released );

/* ---- 2. Temp PDF that cannot be written ----------------------------------------------------------------------- */

$blocker = trailingslashit( get_temp_dir() ) . 'vcap-diag-blocker-' . $tag;
file_put_contents( $blocker, 'a file where the upload folder should be' );
$move_uploads = static function ( array $uploads ) use ( $blocker ): array {
	// Only Voucher Captain's private folder moves (the WooCommerce log files stay where they are).
	$callers = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'class' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	if ( in_array( VCAP\Install::class, $callers, true ) ) {
		$uploads['basedir'] = $blocker . '/uploads';
	}
	return $uploads;
};
$upload_error = get_option( 'vcap_upload_dir_error', '' );
$logged       = array();
add_filter( 'upload_dir', $move_uploads, 999 );
try {
	$file = vcap()->pdf->to_temp_file( $card );
} finally {
	remove_filter( 'upload_dir', $move_uploads, 999 );
	wp_delete_file( $blocker );
	if ( '' === $upload_error ) {
		delete_option( 'vcap_upload_dir_error' );
	} else {
		update_option( 'vcap_upload_dir_error', $upload_error, false );
	}
}
$entry = array_values( array_filter( $logged, static fn( $e ) => 'pdf_file' === ( $e[2]['element'] ?? '' ) ) );
vcap_diag_assert( null === $file, 'to_temp_file returns null when the private temp folder cannot be written', $file );
vcap_diag_assert( 1 === count( $entry ) && 'error' === $entry[0][1] && $card->id === (int) ( $entry[0][2]['voucher_id'] ?? 0 ), 'D5: the failed temp PDF is logged once as error with the voucher id', $logged );
$ok_file = vcap()->pdf->to_temp_file( $card );
vcap_diag_assert( is_string( $ok_file ) && str_starts_with( (string) file_get_contents( $ok_file ), '%PDF-' ) && str_starts_with( $ok_file, VCAP\Install::upload_dir() . '/tmp/' ), 'with a writable folder the temp PDF is written to the private temp folder', $ok_file );
wp_delete_file( (string) $ok_file );

/* ---- 3. Issuing retry callback -------------------------------------------------------------------------------- */

$product = new WC_Product_Simple();
$product->set_name( 'Diagnostics voucher' );
$product->set_regular_price( '20' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_type', 'voucher' );
$product->save();
$order = wc_create_order();
$order->add_product( $product, 2 );
$order->set_billing_email( "diag-order-{$tag}@example.com" );
$order->calculate_totals( false );
$order->save();
$order_id = $order->get_id();

$missing_order = $order_id + 100000;
$all_vouchers  = static fn(): int => (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . VCAP\Install::tables()['vouchers'] ); // phpcs:ignore WordPress.DB
$count_before  = $all_vouchers();
vcap()->issuer->issue_async( 0 );
vcap()->issuer->issue_async( array() );
vcap()->issuer->issue_async( array( 'order_id' => 'not-a-number' ) );
vcap()->issuer->issue_async( $missing_order );
vcap_diag_assert( $count_before === $all_vouchers(), 'issue_async ignores 0, an empty or malformed args array and an order that does not exist (no error)' );

vcap()->issuer->issue_async( $order_id );
vcap_diag_assert( ! vcap()->vouchers->for_order( $order_id ), 'issue_async does not issue for an unpaid order' );

$order = wc_get_order( $order_id );
$order->set_date_paid( time() );
$order->save(); // Still pending: the status hooks do not issue.
vcap_diag_assert( ! vcap()->vouchers->for_order( $order_id ), 'precondition: the paid but pending order has no vouchers yet' );
vcap()->issuer->issue_async( (string) $order_id ); // As a plain (string) id.
$first = vcap()->vouchers->for_order( $order_id );
vcap_diag_assert( 2 === count( $first ), 'issue_async issues the vouchers of a paid order given by its plain id', count( $first ) );
vcap()->issuer->issue_async( array( 'order_id' => $order_id ) ); // As the Action Scheduler args array.
$second = vcap()->vouchers->for_order( $order_id );
vcap_diag_assert( array_map( static fn( $v ) => (int) $v->id, $first ) === array_map( static fn( $v ) => (int) $v->id, $second ), 'a repeated retry (args array) never issues the vouchers twice', array( count( $first ), count( $second ) ) );
foreach ( $second as $issued ) {
	as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $issued->id ), Delivery::GROUP );
}

/* ---- 4. Typed facade and an untyped third-party provider ------------------------------------------------------ */

$broken = new class() implements VCAP\Contracts\RedemptionProvider {
	public function supports( string $code ): bool {
		return str_starts_with( $code, 'BROKEN-' );
	}
	public function lookup( string $code ) {
		return null;
	}
	public function reserve( string $code, float $amount, \WC_Order $order ) {
		return 'reserved';
	}
	public function commit( string $code, \WC_Order $order ) {
		return array( 'state' => 'committed' );
	}
	public function release( string $code, \WC_Order $order ) {
		return new WP_Error( 'own_error', 'Own error' );
	}
};
$add_broken = static function ( array $providers ) use ( $broken ): array {
	array_unshift( $providers, $broken );
	return $providers;
};
$logged = array();
add_filter( 'vcap_redemption_providers', $add_broken );
$lookup  = vcap()->api_v1->lookup( 'BROKEN-1' );
$reserve = vcap()->api_v1->reserve( 'BROKEN-1', 5.0, $order );
$commit  = vcap()->api_v1->commit( 'BROKEN-1', $order );
$release = vcap()->api_v1->release( 'BROKEN-1', $order );
$own     = vcap()->api_v1->lookup( $card->code );
remove_filter( 'vcap_redemption_providers', $add_broken );
vcap_diag_assert( is_wp_error( $lookup ) && 'vcap_provider_invalid_response' === $lookup->get_error_code() && 502 === ( $lookup->get_error_data()['status'] ?? 0 ) && is_wp_error( $reserve ) && 'vcap_provider_invalid_response' === $reserve->get_error_code(), 'D15: a provider result that is neither an array nor a WP_Error becomes vcap_provider_invalid_response (no TypeError)' );
vcap_diag_assert( array( 'state' => 'committed' ) === $commit && is_wp_error( $release ) && 'own_error' === $release->get_error_code(), 'D15: arrays and WP_Errors of an untyped provider are passed through unchanged' );
vcap_diag_assert( 2 === count( array_filter( $logged, static fn( $e ) => 'error' === $e[1] && str_contains( $e[0], 'neither an array nor a WP_Error' ) ) ), 'D15: each invalid provider result is logged', $logged );
vcap_diag_assert( is_array( $own ) && $card->code === $own['code'], 'the built-in provider still answers its own codes next to a third-party provider', $own );

remove_filter( 'woocommerce_logger_log_message', $listen, 10 );
remove_filter( 'pre_wp_mail', $no_mail, 999 );
echo "Diagnostics integration checks passed.\n";
