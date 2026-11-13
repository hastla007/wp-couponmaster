<?php
/**
 * Minimal WordPress / WooCommerce stubs so that the plugin's pure logic and the PDF pipeline
 * can be tested without a WordPress install. Not a replacement for integration tests.
 */

define( 'ABSPATH', sys_get_temp_dir() . '/vcap-abspath/' );
defined( 'VCAP_FILE' ) || define( 'VCAP_FILE', dirname( __DIR__ ) . '/voucher-captain.php' );
define( 'VCAP_PATH', dirname( __DIR__ ) . '/' );
define( 'VCAP_URL', 'https://shop.test/wp-content/plugins/voucher-captain/' );
define( 'VCAP_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MONTH_IN_SECONDS', 30 * 86400 );
define( 'YEAR_IN_SECONDS', 365 * 86400 );
define( 'MB_IN_BYTES', 1048576 );
@mkdir( ABSPATH, 0777, true );

$GLOBALS['vcap_test_options'] = array(
	'date_format'        => 'd.m.Y',
	'time_format'        => 'H:i',
	'woocommerce_currency' => 'EUR',
	'vcap_secret'        => 'test-secret',
);
$GLOBALS['vcap_test_filters'] = array();
$GLOBALS['vcap_test_uploads'] = sys_get_temp_dir() . '/vcap-uploads';
@mkdir( $GLOBALS['vcap_test_uploads'], 0777, true );

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['vcap_test_filters'][ $hook ][] = array( $cb, $args ); return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function has_filter( $hook ) { return ! empty( $GLOBALS['vcap_test_filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['vcap_test_filters'][ $hook ] ?? array() as $f ) {
		$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, $f[1] ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['vcap_test_filters'][ $hook ] ?? array() as $f ) { call_user_func_array( $f[0], array_slice( $args, 0, $f[1] ) ); } }
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ); }
function esc_url_raw( $s ) { $s = (string) $s; return preg_match( '#^(https?:)?//#i', $s ) || '' === $s ? $s : ''; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_EMAIL ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? $c : null; }
function sanitize_file_name( $s ) { return trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $s ), '-.' ); }
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f | JSON_UNESCAPED_UNICODE ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, $q ); }
function wp_unslash( $v ) { return $v; }
function wp_slash( $v ) { return $v; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function get_option( $k, $d = false ) { return $GLOBALS['vcap_test_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['vcap_test_options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( ! isset( $GLOBALS['vcap_test_options'][ $k ] ) ) { $GLOBALS['vcap_test_options'][ $k ] = $v; } }
function get_bloginfo( $k ) { return 'Café Süß & Co'; }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://shop.test/wp-json/' . $p; }
function add_query_arg( ...$a ) { if ( 3 === count( $a ) ) { $a = array( array( $a[0] => $a[1] ), $a[2] ); } return $a[1] . ( str_contains( $a[1], '?' ) ? '&' : '?' ) . http_build_query( $a[0] ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function set_url_scheme( $u ) { return (string) $u; }
function wp_upload_dir( $t = null, $c = true ) { return array( 'basedir' => $GLOBALS['vcap_test_uploads'], 'baseurl' => 'https://shop.test/wp-content/uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function get_attached_file( $id ) { return $GLOBALS['vcap_test_attachments'][ $id ] ?? false; }
function wp_timezone() { return new DateTimeZone( 'Europe/Vienna' ); }
function wp_timezone_string() { return 'Europe/Vienna'; }
function wp_date( $f, $ts = null, $tz = null ) { $d = new DateTime( '@' . ( $ts ?? time() ) ); $d->setTimezone( $tz ?: wp_timezone() ); return $d->format( $f ); }
function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) { return wp_date( $f, strtotime( $s . ' UTC' ) ); }
function get_woocommerce_currency() { return 'EUR'; }
function get_woocommerce_currency_symbol() { return '€'; }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $n, $dp = false ) { $n = str_replace( ',', '.', (string) $n ); return false === $dp ? $n : (string) round( (float) $n, (int) $dp ); }
function wc_format_localized_decimal( $n ) { return str_replace( '.', ',', (string) $n ); }
function wc_price( $amount, $args = array() ) { return '<span class="amount">' . number_format( (float) $amount, 2, ',', '.' ) . '&nbsp;&euro;</span>'; }
function wc_get_product( $id ) { return null; }
function wc_get_order( $id ) { return null; }
function wc_format_coupon_code( $c ) { return strtolower( trim( (string) $c ) ); }
function get_post_meta( $id, $k = '', $s = false ) { return $GLOBALS['vcap_test_meta'][ $id ][ $k ] ?? ''; }
function get_posts( $a = array() ) { return array(); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $c ) { return false; }
function get_current_user_id() { return 0; }
function wp_generate_password( $l = 12, $s = true, $e = false ) { return substr( bin2hex( random_bytes( $l ) ), 0, $l ); }

require_once VCAP_PATH . 'vendor/autoload.php';
spl_autoload_register(
	static function ( $class ) {
		if ( str_starts_with( $class, 'VCAP\\' ) ) {
			$file = VCAP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

final class VCAP_Test_Plugin {
	public \VCAP\Settings $settings;
	public \VCAP\PdfRenderer $pdf;
	public object $downloads;
	public function __construct() {
		$this->settings  = new \VCAP\Settings();
		$this->pdf       = new \VCAP\PdfRenderer( $this->settings );
		$this->downloads = new class() {
			public function signed_url( $v, $email = false ) { return 'https://shop.test/?vcap_pdf=' . $v->token; }
		};
	}
}
function vcap() { static $p = null; return $p ??= new VCAP_Test_Plugin(); }
// No pages in the unit tests: the redeem page lookup finds none (-1 = cached "none").
function get_transient( $k ) { return $GLOBALS['vcap_test_transients'][ $k ] ?? -1; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['vcap_test_transients'][ $k ] = $v; return true; }
function get_post_status( $id = null ) { return false; }
function get_permalink( $id = 0 ) { return ''; }
function did_action( $h ) { return 0; }
function delete_option( $k ) { unset( $GLOBALS['vcap_test_options'][ $k ] ); return true; }
function delete_transient( $k ) { unset( $GLOBALS['vcap_test_transients'][ $k ] ); return true; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xfff ), random_int( 0x8000, 0xbfff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ) ); }
}
/** HTTP: $GLOBALS['vcap_test_http'] = callable( $url, $args ) returning array{code:int, body:string} or WP_Error. */
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) { $h = $GLOBALS['vcap_test_http'] ?? null; return $h ? $h( $url, $args ) : new WP_Error( 'http_request_failed', 'no transport' ); }
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) $r['code'] : 0; }
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) $r['body'] : ''; }
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $cb, $prio = 10 ) {
		foreach ( $GLOBALS['vcap_test_filters'][ $hook ] ?? array() as $i => $f ) {
			if ( $f[0] === $cb ) {
				unset( $GLOBALS['vcap_test_filters'][ $hook ][ $i ] );
			}
		}
		return true;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	/** GET through the same fake transport; 'stream' + 'filename' write the body to that file. */
	function wp_remote_get( $url, $args = array() ) {
		$r = wp_remote_request( $url, array( 'method' => 'GET' ) + $args );
		if ( is_array( $r ) && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $r['body'] );
		}
		return $r;
	}
	function wp_remote_retrieve_header( $r, $name ) { return is_array( $r ) ? (string) ( $r['headers'][ strtolower( $name ) ] ?? '' ) : ''; }
	function wp_tempnam( $name = '' ) { return tempnam( sys_get_temp_dir(), 'vcap' ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}
