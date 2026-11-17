<?php
/**
 * PDF engine, templates and wallet links (12-expert audit, package F1):
 * - M12-39: a personal message that would be cut off in the PDF is refused at add to cart and in the Store API with
 *   the number of lines the box holds; one printed below 75 % gets a notice; the product option "max. lines";
 * - M12-41: the download link printed in the QR code lives as long as the voucher (+30 days) or forever;
 * - M12-43: random temp PDF names attached under the configured name, private folder with a random name and
 *   Apache 2.4 protection, Site Health fetches a test file over HTTP;
 * - M12-74: the bundled email prints the voucher links (badges) per row, HTML and plain text.
 *
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/pdf-integration.php
 */

use VCAP\Install;
use VCAP\PdfRenderer;
use VCAP\Voucher;

function vcap_pdf_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_pdf_product( string $name, array $meta ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '30' );
	$p->set_virtual( true );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_vcap_enabled', 'yes' );
	foreach ( $meta as $key => $value ) {
		$p->update_meta_data( '_vcap_' . $key, $value );
	}
	$p->save();
	return $p;
}

function vcap_pdf_voucher( array $props = array() ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-pdf-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 25 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = (int) ( $props['order_item_id'] ?? 86000000 + $coupon->get_id() );
	$v->item_index     = (int) ( $props['item_index'] ?? 0 );
	$v->order_id       = (int) ( $props['order_id'] ?? 0 );
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = 25;
	$v->balance        = 25;
	$v->currency       = get_woocommerce_currency();
	$v->status         = Voucher::STATUS_ACTIVE;
	$v->expires_at     = $props['expires_at'] ?? null;
	$v->created_at     = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$v->buyer_email    = (string) ( $props['buyer'] ?? 'pdf-buyer@example.com' );
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	if ( ! $v->id ) {
		throw new RuntimeException( 'Could not create PDF test voucher.' );
	}
	return $v;
}

$notices = static fn( string $type ) => implode( ' ', array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, wc_get_notices( $type ) ) );
wc_load_cart();
WC()->cart->empty_cart();

/* ---- M12-39: texts that would be cut off are refused before the purchase ---------------------------------- */

$card = vcap_pdf_product( 'PDF fit gift card', array( 'type' => 'giftcard', 'builtin_fields' => array( 'recipient_name', 'message' ) ) );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'message' => str_repeat( "Zeile\n", 40 ) . 'Ende' ) );
vcap_pdf_assert( false === apply_filters( 'woocommerce_add_to_cart_validation', true, $card->get_id(), 1 ), 'M12-39: a message of 41 short lines (would be cut off in the PDF) is refused at add to cart' );
$errors = $notices( 'error' );
vcap_pdf_assert( str_contains( $errors, 'Personal message' ) && (bool) preg_match( '/max\. approx\. \d+ lines/', $errors ), 'M12-39: the error names the field and the number of lines the box holds', $errors );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'message' => str_repeat( "Zeile\n", 8 ) . 'Ende' ) );
vcap_pdf_assert( true === apply_filters( 'woocommerce_add_to_cart_validation', true, $card->get_id(), 1 ) && str_contains( $notices( 'notice' ), 'smaller font' ), 'M12-39: nine lines are accepted with a notice that they are printed smaller', $notices( 'notice' ) );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'message' => 'Alles Gute!' ) );
vcap_pdf_assert( true === apply_filters( 'woocommerce_add_to_cart_validation', true, $card->get_id(), 1 ) && '' === $notices( 'notice' ) . $notices( 'error' ), 'M12-39: a short message passes without a notice' );
wc_clear_notices();
$_POST = array();
$lines = vcap_pdf_product( 'PDF max lines', array( 'type' => 'giftcard', 'builtin_fields' => array( 'message' ), 'message_maxlines' => 3 ) );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'message' => "Eins\nZwei\nDrei\nVier" ) );
vcap_pdf_assert( false === apply_filters( 'woocommerce_add_to_cart_validation', true, $lines->get_id(), 1 ) && str_contains( $notices( 'error' ), 'at most 3 lines' ), 'M12-39: the product option "Message max. lines" refuses a fourth line', $notices( 'error' ) );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'message' => "Eins\nZwei\nDrei" ) );
vcap_pdf_assert( true === apply_filters( 'woocommerce_add_to_cart_validation', true, $lines->get_id(), 1 ), 'M12-39: three lines are accepted' );
wc_clear_notices();
$_POST = array();

if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'vcap_fields', array( 'message' => str_repeat( "Zeile\n", 40 ) ) );
	$refused = '';
	try {
		vcap()->fields->store_api_validate( $card, $request );
	} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
		$refused = $e->getMessage();
	}
	vcap_pdf_assert( str_contains( $refused, 'max. approx.' ), 'M12-39: the Store API (cart block) refuses it as well', $refused );
	$request->set_param( 'vcap_fields', array() );
	vcap()->fields->store_api_validate( $card, $request );
}

/* ---- M12-41: the printed download link lives as long as the voucher ----------------------------------------- */

$dated   = vcap_pdf_voucher( array( 'expires_at' => gmdate( 'Y-m-d 22:59:59', time() + 3 * YEAR_IN_SECONDS ) ) );
$forever = vcap_pdf_voucher();
parse_str( (string) wp_parse_url( vcap()->downloads->printed_url( $dated ), PHP_URL_QUERY ), $q_dated );
parse_str( (string) wp_parse_url( vcap()->downloads->printed_url( $forever ), PHP_URL_QUERY ), $q_forever );
vcap_pdf_assert( (int) $q_dated['exp'] === strtotime( $dated->expires_at . ' UTC' ) + 30 * DAY_IN_SECONDS && '0' === (string) $q_forever['exp'], 'M12-41: the printed link expires 30 days after the voucher, never for a voucher without expiry', array( $q_dated['exp'], $q_forever['exp'] ) );
vcap_pdf_assert( vcap()->downloads->printed_url( $dated ) === vcap()->pdf->qr_payload( array( 'qrContent' => 'download' ), $dated, array() ), 'M12-41: the QR code "PDF download link" carries the printed link' );
$check = new ReflectionMethod( vcap()->downloads, 'valid_guest_link' );
$valid = $check->invoke( vcap()->downloads, $dated->token, (int) $q_dated['exp'], (string) $q_dated['sig'], vcap()->downloads->link_version( $dated ) );
vcap_pdf_assert( true === $valid, 'M12-41: the printed link is signed and valid' );

/* ---- M12-43: temp PDFs, private folder, Site Health -------------------------------------------------------- */

$private = Install::private_dir_name();
vcap_pdf_assert( (bool) preg_match( '/^vcap-[a-z0-9]{20}$/', $private ) && str_ends_with( Install::upload_dir(), '/' . $private ) && ! is_dir( trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'vcap' ), 'M12-43: the private folder has a random name (the old "vcap" folder was renamed)', $private );
vcap_pdf_assert( str_contains( (string) file_get_contents( Install::upload_dir() . '/.htaccess' ), 'Require all denied' ), 'M12-43: its .htaccess uses the Apache 2.4 syntax' );

$mails   = array();
$capture = static function ( $pre, $atts ) use ( &$mails ) {
	$files = array();
	foreach ( (array) ( $atts['attachments'] ?? array() ) as $name => $file ) {
		$files[] = array( 'name' => $name, 'file' => (string) $file, 'pdf' => is_readable( $file ) && str_starts_with( (string) file_get_contents( $file ), '%PDF-' ) );
	}
	$mails[] = array( 'to' => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'], 'message' => (string) $atts['message'], 'files' => $files );
	return true; // Sent.
};
add_filter( 'pre_wp_mail', $capture, 1, 2 );
$keep_attach = get_option( 'vcap_attach_pdf', 'yes' );
update_option( 'vcap_attach_pdf', 'yes' );
$single = vcap_pdf_voucher( array( 'buyer' => 'pdf-single@example.com', 'order_id' => wc_create_order()->get_id() ) );
vcap()->delivery->deliver( $single->id );
$sent      = array_values( array_filter( $mails, static fn( $m ) => str_contains( $m['to'], 'pdf-single@' ) ) );
$attached  = $sent[0]['files'][0] ?? array();
$name      = vcap()->pdf->filename( $single );
vcap_pdf_assert( $name === ( $attached['name'] ?? '' ) && (bool) preg_match( '/^[A-Za-z0-9]{24}-' . preg_quote( $name, '/' ) . '$/', basename( (string) ( $attached['file'] ?? '' ) ) ) && true === ( $attached['pdf'] ?? false ), 'M12-43: the PDF is written under a random name and attached under the configured name (wp_mail attachment key)', $attached );
vcap_pdf_assert( ! file_exists( (string) ( $attached['file'] ?? '' ) ), 'M12-43: the temp PDF is deleted after the mail' );

$health = new VCAP\Health();
$answer = 'open';
$http   = static function ( $pre, $args, $url ) use ( &$answer ) {
	if ( ! str_contains( (string) $url, '/' . Install::private_dir_name() . '/tmp/probe-' ) ) {
		return $pre;
	}
	if ( 'open' === $answer ) {
		$file = Install::upload_dir() . '/tmp/' . basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		return array( 'headers' => array(), 'body' => (string) @file_get_contents( $file ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	}
	return array( 'headers' => array(), 'body' => 'Forbidden', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $http, 10, 3 );
$open   = $health->test_upload_dir();
$answer = 'closed';
$closed = $health->test_upload_dir();
remove_filter( 'pre_http_request', $http, 10 );
vcap_pdf_assert( 'critical' === $open['status'] && str_contains( (string) $open['description'], 'nginx' ) && 'good' === $closed['status'], 'M12-43: Site Health fetches a test file: downloadable (200) is critical, refused (403) is good', array( $open['status'], $closed['status'] ) );
vcap_pdf_assert( ! glob( Install::upload_dir() . '/tmp/probe-*' ), 'M12-43: the test file is removed again' );
$tests = $health->tests( array() );
vcap_pdf_assert( isset( $tests['async']['vcap_upload_dir'] ) && ! isset( $tests['direct']['vcap_upload_dir'] ), 'M12-43: the folder check runs asynchronously (it makes an HTTP request)' );

/* ---- M12-74: voucher links per row of the bundled email -------------------------------------------------- */

$links = static fn( $list, $voucher ) => array_merge( (array) $list, array( array( 'id' => 'wallet', 'label' => 'Add to Wallet', 'url' => 'https://wallet.example/pass/' . $voucher->id, 'image' => 'https://wallet.example/badge.png', 'width' => 120, 'alt' => 'Wallet badge' ) ) );
add_filter( 'vcap_voucher_links', $links, 10, 2 );
$order_id = wc_create_order()->get_id();
$item_id  = 87000000 + wp_rand( 0, 99999 );
$bundle   = array();
for ( $i = 0; $i < 6; $i++ ) {
	$bundle[] = vcap_pdf_voucher( array( 'buyer' => 'pdf-bundle@example.com', 'order_id' => $order_id, 'order_item_id' => $item_id, 'item_index' => $i ) );
}
$mails = array();
foreach ( $bundle as $member ) {
	vcap()->delivery->deliver( $member->id );
}
$bundle_mail = array_values( array_filter( $mails, static fn( $m ) => str_contains( $m['to'], 'pdf-bundle@' ) ) );
$html        = (string) ( $bundle_mail[0]['message'] ?? '' );
vcap_pdf_assert( 1 === count( $bundle_mail ) && 6 === substr_count( $html, '<img src="https://wallet.example/badge.png" width="120" alt="Wallet badge"' ) && str_contains( $html, 'https://wallet.example/pass/' . $bundle[5]->id ), 'M12-74: the bundled email shows the wallet badge of every voucher', array( count( $bundle_mail ), substr_count( $html, 'badge.png' ) ) );
vcap_pdf_assert( str_contains( (string) ( $bundle_mail[0]['files'][0]['name'] ?? '' ), '-and-5-more.pdf' ) && (bool) preg_match( '/^[A-Za-z0-9]{24}-/', basename( (string) ( $bundle_mail[0]['files'][0]['file'] ?? '' ) ) ), 'M12-43: the bundle PDF is attached under its name, its temp file has a random prefix', $bundle_mail[0]['files'] ?? null );
$plain = wc_get_template_html(
	'emails/plain/voucher-bundle.php',
	array(
		'vouchers'           => $bundle,
		'voucher'            => $bundle[0],
		'email_heading'      => 'H',
		'intro'              => 'I',
		'additional_content' => '',
		'pdf_attached'       => false,
		'download_urls'      => array(),
		'account_url'        => '',
		'show_product'       => false,
		'show_holder'        => false,
	),
	'voucher-captain/',
	VCAP_PATH . 'templates/'
);
vcap_pdf_assert( 6 === substr_count( $plain, 'Add to Wallet: https://wallet.example/pass/' ), 'M12-74: the plain-text bundle lists the links of every voucher', $plain );
vcap_pdf_assert( 'https://wallet.example/pass/' . $bundle[0]->id === VCAP\Placeholders::replace( '{link:wallet}', $bundle[0] ), 'M12-74: {link:wallet} is the URL of that link' );
remove_filter( 'vcap_voucher_links', $links, 10 );
remove_filter( 'pre_wp_mail', $capture, 1 );
update_option( 'vcap_attach_pdf', $keep_attach );

echo "All PDF integration checks passed.\n";
