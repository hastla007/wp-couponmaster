<?php
/**
 * Lightweight test runner (no WordPress needed):  php tests/run.php [output-dir]
 */
require __DIR__ . '/stubs.php';

use VCAP\CodeGenerator;
use VCAP\Delivery;
use VCAP\PdfRenderer;
use VCAP\Placeholders;
use VCAP\ProductConfig;
use VCAP\SpreadsheetReader;
use VCAP\TemplatePostType;
use VCAP\Voucher;
use VCAP\Licensing\PremiumUpdater;

$out    = $argv[1] ?? sys_get_temp_dir() . '/vcap-test-output';
@mkdir( $out, 0777, true );
$failed = 0;
$passed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed, $passed;
	$ok ? $passed++ : $failed++;
	echo ( $ok ? "  ✔ " : "  ✘ " ) . $name . ( $ok || '' === $detail ? '' : " — $detail" ) . "\n";
}

echo "Country conditions\n";
$parsed = ProductConfig::terms_by_country( "at: Gültig 30 Jahre.\nKeine Barauszahlung.\nDE: Gültig 3 Jahre.\n\nxx-invalid line" );
check( 'conditions per country are parsed in blocks', array( 'AT' => "Gültig 30 Jahre.\nKeine Barauszahlung.", 'DE' => "Gültig 3 Jahre.\n\nxx-invalid line" ) === $parsed, json_encode( $parsed ) );
$GLOBALS['vcap_test_options']['vcap_terms_by_country'] = "AT: Austria terms";
$GLOBALS['vcap_test_options']['vcap_default_terms']    = 'General terms';
check( 'the billing country picks its conditions', 'Austria terms' === ProductConfig::terms( ProductConfig::DEFAULTS, 'at' ) );
check( 'other countries use the general conditions', 'General terms' === ProductConfig::terms( ProductConfig::DEFAULTS, 'DE' ) );
check( 'product conditions always win', 'Own' === ProductConfig::terms( array( 'redemption_terms' => 'Own' ) + ProductConfig::DEFAULTS, 'AT' ) );
unset( $GLOBALS['vcap_test_options']['vcap_terms_by_country'], $GLOBALS['vcap_test_options']['vcap_default_terms'] );

echo "EU markets (configuration, not code)\n";
check( 'all 27 EU countries are configured', 27 === count( VCAP\Markets::all() ) && VCAP\Markets::is_eu( 'fr' ) && ! VCAP\Markets::is_eu( 'US' ) );
check( 'currency and locale per country', 'PLN' === VCAP\Markets::get( 'PL' )['currency'] && 'de_AT' === VCAP\Markets::get( 'AT' )['locale'] );
check( 'verified validity: DE 3 years to the end of the year, AT 30 years', array( 'years' => 3, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'DE' ) && array( 'years' => 30, 'end_of_year' => false ) === ProductConfig::statutory_rule( 'AT' ) );
check( 'unverified country: shop default (3 years to the end of the year)', null === VCAP\Markets::expiry_rule( 'FR' ) && array( 'years' => 3, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'FR' ) );
check( 'setting is parsed per line (years, "end", 0 = never)', array( 'FR' => array( 'years' => 5, 'end_of_year' => true ), 'IT' => array( 'years' => 10, 'end_of_year' => false ), 'NL' => array( 'years' => 0, 'end_of_year' => false ) ) === VCAP\Markets::parse_expiry( "fr: 5 end\nIT=10\nNL: 0\nbroken line\nXX" ) );
$GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ] = "FR: 5 end\nAT: 1";
check( 'the shop setting wins over the market data', array( 'years' => 5, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'FR' ) && array( 'years' => 1, 'end_of_year' => false ) === ProductConfig::statutory_rule( 'AT' ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ] );
check( 'ISO 4217 minor units from config/currencies.php', 0 === VCAP\API\V1\RedemptionService::decimals( 'JPY' ) && 3 === VCAP\API\V1\RedemptionService::decimals( 'kwd' ) && 2 === VCAP\API\V1\RedemptionService::decimals( 'EUR' ) && 2 === VCAP\API\V1\RedemptionService::decimals( 'HUF' ) );

echo "Code generator\n";
$code = CodeGenerator::build( array( 'prefix' => 'GIFT', 'suffix' => 'X', 'length' => 8, 'charset' => 'alnum_upper', 'separator' => '-', 'order_ref' => '1042' ) );
check( 'format PREFIX-ORDER-RANDOM-SUFFIX', (bool) preg_match( '/^GIFT-1042-[A-HJ-NP-Z2-9]{8}-X$/', $code ), $code );
check( 'numeric charset', (bool) preg_match( '/^\d{12}$/', CodeGenerator::random( 12, 'numeric' ) ) );
$seen = array();
for ( $i = 0; $i < 5000; $i++ ) {
	$seen[ CodeGenerator::random( 10 ) ] = true;
}
check( 'no collisions in 5000 codes', 5000 === count( $seen ) );
$taken = array( 'A' => true );
$calls = 0;
$unique = CodeGenerator::unique( array( 'length' => 4 ), function ( $c ) use ( &$calls ) { return ++$calls < 3; } );
check( 'unique() retries on collision', 3 === $calls && 4 === strlen( $unique ) );
check( 'minimum length for 2^50: 10 letters/digits, 11 letters, 16 digits, 9 mixed case', 10 === CodeGenerator::min_length( 'alnum_upper' ) && 11 === CodeGenerator::min_length( 'alpha_upper' ) && 16 === CodeGenerator::min_length( 'numeric' ) && 9 === CodeGenerator::min_length( 'alnum' ) );
$money_code = CodeGenerator::build( array( 'prefix' => 'GIFT', 'length' => 8, 'charset' => 'numeric', 'min_bits' => CodeGenerator::MIN_BITS ) );
check( 'codes that carry money are lengthened to the minimum guessing space', (bool) preg_match( '/^GIFT-\d{16}$/', $money_code ), $money_code );
check( 'other codes keep the configured length', (bool) preg_match( '/^\d{8}$/', CodeGenerator::build( array( 'length' => 8, 'charset' => 'numeric' ) ) ) );
check( 'estimated guessing space of imported codes (8 digits weak, 16 digits / 10 alphanumerics enough)', CodeGenerator::estimated_bits( '12345678' ) < 50 && CodeGenerator::estimated_bits( '1234-5678-9012-3456' ) >= 50 && CodeGenerator::estimated_bits( 'K7QM2X9ZPA' ) >= 50 );
$affixes = CodeGenerator::common_affixes( array( 'SHOP-2026-48151623', 'shop-2026-99990000', 'SHOP-2026-12121212' ) );
check( 'a prefix shared by the whole batch does not count', array( 'shop-2026-', '' ) === $affixes && CodeGenerator::estimated_bits( 'SHOP-2026-48151623', $affixes[0], $affixes[1] ) < 50, json_encode( $affixes ) );
check( 'random codes that start alike by chance keep their full length', array( '', '' ) === CodeGenerator::common_affixes( array( '1234567890123456', '1299999999999999' ) ) );

echo "Design sanitising\n";
$dirty = array(
	'page'     => array( 'format' => 'EVIL', 'orientation' => 'sideways', 'background' => 'red;background:url(x)', 'width' => 5000 ),
	'elements' => array(
		array( 'type' => 'script', 'text' => 'x' ),
		array( 'type' => 'text', 'id' => 'a"><b', 'text' => '<script>alert(1)</script>Hi {coupon_code}', 'color' => 'javascript:x', 'font' => 'Comic Sans', 'size' => '12', 'bold' => 1 ),
		array( 'type' => 'image', 'src' => 'javascript:alert(1)', 'fit' => 'cover' ),
	),
);
$clean = TemplatePostType::sanitize_design( $dirty );
check( 'unknown format falls back to A5', 'A5' === $clean['page']['format'] );
check( 'bad colour replaced', '#000000' === $clean['page']['background'] );
check( 'unknown element type dropped', 2 === count( $clean['elements'] ) );
check( 'script tags stripped from text', 'alert(1)Hi {coupon_code}' === $clean['elements'][0]['text'], $clean['elements'][0]['text'] );
check( 'id sanitised', 'ab' === $clean['elements'][0]['id'] );
check( 'unknown font removed', ! isset( $clean['elements'][0]['font'] ) );
check( 'javascript: URL removed', '' === $clean['elements'][1]['src'] );

echo "Page sizes\n";
check( 'A5 landscape = 210×148', array( 210, 148 ) === PdfRenderer::page_size( array( 'format' => 'A5', 'orientation' => 'landscape' ) ) );
check( 'A6 portrait = 105×148', array( 105, 148 ) === PdfRenderer::page_size( array( 'format' => 'A6', 'orientation' => 'portrait' ) ) );
check( 'custom clamps to ≥20mm', array( 20, 99 ) == PdfRenderer::page_size( array( 'format' => 'custom', 'width' => 3, 'height' => 99 ) ) );

echo "Placeholders\n";
$v                  = new Voucher();
$v->code            = 'GIFT-ABCD-1234';
$v->initial_amount  = 50;
$v->balance         = 32.5;
$v->usage_mode      = 'balance';
$v->currency        = 'EUR';
$v->expires_at      = '2027-12-31 22:59:59';
$v->buyer_name      = 'Anna Käufer';
$v->recipient_name  = 'Jörg Empfänger';
$v->message         = "Alles Gute zum Geburtstag!\nGenieß den Tag – mit Ümläuten & Sonderzeichen ✓";
$v->fields          = array( 'dedication' => array( 'label' => 'Widmung', 'value' => 'Für die Beste' ) );
$v->created_at      = '2026-11-11 10:00:00';
$v->token           = str_repeat( 'a', 48 );
$v->max_checkins    = 2;
$text = Placeholders::replace( '{coupon_code}|{coupon_value}|{coupon_balance}|{expiry_date}|{recipient_name}|{field:dedication}|{unknown}|{site_name}', $v );
check( 'static + dynamic tokens', "GIFT-ABCD-1234|50,00\u{a0}€|32,50\u{a0}€|31.12.2027|Jörg Empfänger|Für die Beste|{unknown}|Café Süß & Co" === $text, $text );
$v2 = clone $v;
$v2->discount_type = 'percent';
$v2->initial_amount = 15;
check( 'percent value', '15%' === Placeholders::replace( '{coupon_value}', $v2 ) );

check( 'caller metadata cannot seed internal redemption bookkeeping', array( 'order' => 7 ) === VCAP\API\V1\RedemptionService::caller_meta( array( '_refunds' => array( 'x' => 49.99 ), '_state' => 1, 'order' => 7 ) ) && array() === VCAP\API\V1\RedemptionService::caller_meta( 'x' ) );
check( 'redemption amounts use ISO currency decimals', 0 === VCAP\API\V1\RedemptionService::decimals( 'JPY' ) && 2 === VCAP\API\V1\RedemptionService::decimals( 'EUR' ) && 3 === VCAP\API\V1\RedemptionService::decimals( 'kwd' ) );

echo "Expiry & delivery\n";
$base = strtotime( '2026-01-10 12:00:00 UTC' );
check( 'expiry in days (shop-local end of day)', '2026-02-09 22:59:59' === ProductConfig::expiry_for( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'days', 'expiry_days' => 30 ) ), $base ) );
check( 'never expires', null === ProductConfig::expiry_for( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'never' ) ), $base ) );
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'DE:BY';
check( 'statutory default (Germany): 3 years to the end of the year', 'statutory' === ProductConfig::default_expiry_rule() && '2029-12-31 22:59:59' === ProductConfig::expiry_for( ProductConfig::DEFAULTS, $base ) );
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'AT';
check( 'statutory default (Austria): 30 years', '2056-01-10 22:59:59' === ProductConfig::expiry_for( ProductConfig::DEFAULTS, $base ) );
check( 'statutory default by the buyer country, whatever the shop country: AT buyer 30 years, DE buyer 3 years to the end of the year', '2056-01-10 22:59:59' === ProductConfig::expiry_for( ProductConfig::DEFAULTS, $base, 'AT' ) && '2029-12-31 22:59:59' === ProductConfig::expiry_for( ProductConfig::DEFAULTS, $base, 'DE' ) );
$GLOBALS['vcap_test_options']['vcap_default_expiry_days'] = 365;
check( 'a saved default in days is kept after the update', 'days' === ProductConfig::default_expiry_rule() && '2027-01-10 22:59:59' === ProductConfig::expiry_for( ProductConfig::DEFAULTS, $base ) );
unset( $GLOBALS['vcap_test_options']['vcap_default_expiry_days'], $GLOBALS['vcap_test_options']['woocommerce_default_country'] );
check( 'fixed expiry date (local end of day)', '2027-03-01 22:59:59' === ProductConfig::expiry_for( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'date', 'expiry_date' => '2027-03-01' ) ), $base ) );
$future = wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
$cust   = Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, array( 'delivery' => 'customer' ) ), array( 'delivery_date' => array( 'value' => $future ) ) );
check( 'customer delivery date at 08:00 local', $cust && wp_date( 'Y-m-d H:i', strtotime( $cust . ' UTC' ) ) === $future . ' 08:00', (string) $cust );
check( 'past date delivers immediately', null === Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, array( 'delivery' => 'date', 'delivery_date' => '2020-01-01' ) ), array() ) );
check( 'immediate', null === Delivery::compute_time( ProductConfig::DEFAULTS, array() ) );
$delay = Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, array( 'delivery' => 'delay', 'delivery_delay_days' => 3 ) ), array() );
check( 'relative delay ≈ +3 days', $delay && abs( strtotime( $delay . ' UTC' ) - ( time() + 3 * DAY_IN_SECONDS ) ) < 5 );

echo "PDF rendering\n";
// A small PNG for image/background tests.
$img = imagecreatetruecolor( 300, 200 );
imagefill( $img, 0, 0, imagecolorallocate( $img, 230, 210, 180 ) );
imagefilledellipse( $img, 150, 100, 160, 160, imagecolorallocate( $img, 31, 58, 77 ) );
$png = $GLOBALS['vcap_test_uploads'] . '/2026/11/logo.png';
@mkdir( dirname( $png ), 0777, true );
imagepng( $img, $png );
$GLOBALS['vcap_test_attachments'][77] = $png;

$designs                                = TemplatePostType::default_designs();
$designs['giftcard']['elements'][]      = array( 'id' => 'logo', 'type' => 'image', 'imageId' => 77, 'x' => 18, 'y' => 60, 'w' => 38, 'h' => 30, 'fit' => 'contain' );
$designs['giftcard']['elements'][]      = array( 'id' => 'bc', 'type' => 'barcode', 'x' => 120, 'y' => 128, 'w' => 45, 'h' => 12, 'qrValue' => '{coupon_code}', 'color' => '#333333' );
$designs['giftcard']['elements'][]      = array( 'id' => 'rot', 'type' => 'text', 'x' => 150, 'y' => 40, 'w' => 60, 'h' => 10, 'text' => 'Rotated {field:dedication}', 'rotation' => -12, 'size' => 10, 'color' => '#b8925a', 'italic' => true );
$designs['giftcard']['page']['backgroundImage'] = 'https://shop.test/wp-content/uploads/2026/11/logo.png';
$designs['giftcard']['page']['backgroundFit']   = 'cover';
$designs['giftcard']['elements'][]      = array( 'id' => 'veil', 'type' => 'rect', 'x' => 68, 'y' => 6, 'w' => 136, 'h' => 136, 'fill' => '#fbf7f0', 'opacity' => 0.9 );
// Put the veil just above the background but below the content.
$veil = array_pop( $designs['giftcard']['elements'] );
array_splice( $designs['giftcard']['elements'], 2, 0, array( $veil ) );

$ticket                 = clone $v;
$ticket->type           = 'ticket';
$ticket->code           = 'TICKET-7Q2M';
$ticket->initial_amount = 0;

foreach ( array( 'giftcard' => $v, 'ticket' => $ticket ) as $key => $voucher ) {
	$start = microtime( true );
	try {
		$pdf = vcap()->pdf->render( $voucher, $designs[ $key ] );
	} catch ( \Throwable $e ) {
		check( "$key renders", false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		continue;
	}
	$file = "$out/$key.pdf";
	file_put_contents( $file, $pdf );
	check( "$key renders a PDF (" . round( strlen( $pdf ) / 1024 ) . ' KB, ' . round( ( microtime( true ) - $start ) * 1000 ) . ' ms)', str_starts_with( $pdf, '%PDF-' ) );
	$size = PdfRenderer::page_size( $designs[ $key ]['page'] );
	$info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( $file ) . ' 2>/dev/null' );
	if ( preg_match( '/Page size:\s+([\d.]+) x ([\d.]+) pts/', $info, $m ) ) {
		check( "$key page size {$size[0]}×{$size[1]} mm", abs( $m[1] - $size[0] * PdfRenderer::MM_TO_PT ) < 1 && abs( $m[2] - $size[1] * PdfRenderer::MM_TO_PT ) < 1, "$m[1] x $m[2] pt" );
		preg_match( '/Pages:\s+(\d+)/', $info, $p );
		check( "$key is a single page", '1' === ( $p[1] ?? '' ), 'pages=' . ( $p[1] ?? '?' ) );
	}
	$text = (string) shell_exec( 'pdftotext ' . escapeshellarg( $file ) . ' - 2>/dev/null' );
	if ( '' !== $text ) {
		check( "$key contains code", str_contains( $text, $voucher->code ) );
		if ( 'giftcard' === $key ) {
			check( 'umlauts survive (Jörg Empfänger, Ümläuten)', str_contains( $text, 'Jörg Empfänger' ) && str_contains( $text, 'Ümläuten' ) );
			check( 'value printed', str_contains( $text, "50,00\u{a0}€" ) || str_contains( $text, '50,00 €' ) );
			$fonts = (string) shell_exec( 'pdffonts ' . escapeshellarg( $file ) . ' 2>/dev/null' );
			if ( '' !== $fonts ) {
				check( 'missing glyphs (✓ in DejaVu Serif Italic) fall back to DejaVu Sans', str_contains( $fonts, 'DejaVuSans-Oblique' ), $fonts );
			}
			check( 'custom field placeholder in markup', str_contains( vcap()->pdf->html( $voucher, $designs[ $key ] ), 'Rotated Für die Beste' ) );
		}
	}
	shell_exec( 'pdftoppm -png -r 110 -singlefile ' . escapeshellarg( $file ) . ' ' . escapeshellarg( "$out/$key" ) . ' 2>/dev/null' );
}
$html = vcap()->pdf->html( $v, array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 10, 'text' => '<img src=x onerror=alert(1)> {message}' ) ) ) );
check( 'text is HTML-escaped in PDF markup', ! str_contains( $html, '<img src=x' ) && str_contains( $html, '&lt;img src=x' ) );
check( 'remote images are never fetched', null === vcap()->pdf->image_path( 0, 'https://evil.test/x.png' ) );
check( 'uploads URL maps to local file', $png === vcap()->pdf->image_path( 0, 'https://shop.test/wp-content/uploads/2026/11/logo.png' ) );
check( 'path traversal blocked', null === vcap()->pdf->image_path( 0, 'https://shop.test/wp-content/uploads/../../../../etc/passwd' ) );

echo "Redemption API\n";
$valid_keys   = array( 'it-' . str_repeat( 'a', 20 ), 'learnboating:grant:1234', '550e8400-e29b-41d4-a716-446655440000' );
$invalid_keys = array( 'short', '-leading-dash', str_repeat( 'x', 65 ), 'has space 12345', "nl\n12345678" );
check( 'valid idempotency keys are accepted', count( $valid_keys ) === count( array_filter( $valid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
check( 'malformed idempotency keys are rejected', 0 === count( array_filter( $invalid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
$h = \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 5 );
check( 'request fingerprint is stable and amount-normalised', $h === \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.0000, 5 ) );
check( 'request fingerprint changes with target, amount or user', count( array_unique( array( $h, \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:2', 30, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.01, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 6 ) ) ) ) === 4 );

echo "Discount campaign engine\n";
use VCAP\Discounts\Engine;
$lines = array(
	array( 'key' => 'shirt', 'product_id' => 11, 'categories' => array( 5 ), 'unit' => 20.0, 'qty' => 3, 'is_voucher' => false ),
	array( 'key' => 'cap', 'product_id' => 12, 'categories' => array( 6 ), 'unit' => 10.0, 'qty' => 1, 'is_voucher' => false ),
	array( 'key' => 'gift', 'product_id' => 13, 'categories' => array(), 'unit' => 50.0, 'qty' => 1, 'is_voucher' => true ),
);
$ctx = array( 'now' => 1800000000, 'logged_in' => true, 'roles' => array( 'customer' ), 'has_previous_order' => false, 'other_coupons' => array(), 'allow_vouchers' => false, 'decimals' => 2 );
$r   = static fn( array $x ) => Engine::normalize( $x );
check( 'percent: 10 % on all non-voucher lines', array( 'shirt' => 6.0, 'cap' => 1.0 ) === Engine::discounts( $r( array( 'action' => 'percent', 'percent' => 10 ) ), $lines, $ctx ) );
check( 'vouchers are never discounted unless allowed', ! isset( Engine::discounts( $r( array( 'action' => 'percent', 'percent' => 10 ) ), $lines, $ctx )['gift'] ) );
check( 'category restriction', array( 'cap' => 1.0 ) === Engine::discounts( $r( array( 'action' => 'percent', 'percent' => 10, 'categories' => array( 6 ) ) ), $lines, $ctx ) );
check( 'excluded product', array( 'cap' => 1.0 ) === Engine::discounts( $r( array( 'action' => 'percent', 'percent' => 10, 'exclude_products' => array( 11 ) ) ), $lines, $ctx ) );
$fixed = Engine::discounts( $r( array( 'action' => 'fixed', 'amount' => 7 ) ), $lines, $ctx );
check( 'fixed amount is split proportionally and sums exactly', abs( array_sum( $fixed ) - 7.0 ) < 0.00001 && 6.0 === $fixed['shirt'] && 1.0 === $fixed['cap'], json_encode( $fixed ) );
check( 'fixed amount never exceeds the eligible total', abs( array_sum( Engine::discounts( $r( array( 'action' => 'fixed', 'amount' => 500 ) ), $lines, $ctx ) ) - 70.0 ) < 0.00001 );
$tiers = array( 'action' => 'tiered', 'tiers' => array( array( 'min_qty' => 2, 'percent' => 5 ), array( 'min_qty' => 4, 'percent' => 15 ) ) );
check( 'tiered: 4 items reach the 15 % tier', array( 'shirt' => 9.0, 'cap' => 1.5 ) === Engine::discounts( $r( $tiers ), $lines, $ctx ) );
check( 'tiered: 3 shirts only reach 5 %', array( 'shirt' => 3.0 ) === Engine::discounts( $r( $tiers + array( 'categories' => array( 5 ) ) ), $lines, $ctx ) );
check( 'tiered: below the first tier nothing applies', array() === Engine::discounts( $r( $tiers + array( 'products' => array( 12 ) ) ), $lines, $ctx ) );
check( 'buy 2 get 1 free: the cheapest unit is free', array( 'cap' => 10.0 ) === Engine::discounts( $r( array( 'action' => 'bxgy', 'buy_qty' => 2, 'get_qty' => 1, 'get_percent' => 100 ) ), $lines, $ctx ) );
$bogo = Engine::discounts( $r( array( 'action' => 'bxgy', 'buy_qty' => 1, 'get_qty' => 1, 'get_percent' => 50, 'repeat' => true ) ), $lines, $ctx );
ksort( $bogo );
check( 'buy 1 get 1 50 % repeated: two cheapest units at half price', array( 'cap' => 5.0, 'shirt' => 10.0 ) === $bogo, json_encode( $bogo ) );
check( 'buy 1 get 1 without repeat: only one unit', array( 'cap' => 5.0 ) === Engine::discounts( $r( array( 'action' => 'bxgy', 'buy_qty' => 1, 'get_qty' => 1, 'get_percent' => 50 ) ), $lines, $ctx ) );
check( 'buy X get Y needs enough items', 'min_qty' === Engine::blocker( $r( array( 'action' => 'bxgy', 'buy_qty' => 3, 'get_qty' => 1, 'products' => array( 11 ) ) ), $lines, $ctx ) );
check( 'minimum cart value ignores voucher lines (buying a gift card unlocks nothing)', '' === Engine::blocker( $r( array( 'min_subtotal' => 70 ) ), $lines, $ctx ) && 'min_subtotal' === Engine::blocker( $r( array( 'min_subtotal' => 71 ) ), $lines, $ctx ) );
check( 'voucher lines count when discounts on vouchers are allowed', '' === Engine::blocker( $r( array( 'min_subtotal' => 120 ) ), $lines, array( 'allow_vouchers' => true ) + $ctx ) );
$stacked = Engine::stack( array( 1 => $r( array( 'action' => 'bxgy', 'buy_qty' => 2, 'get_qty' => 1, 'get_percent' => 100, 'categories' => array( 5 ) ) ), 2 => $r( array( 'action' => 'percent', 'percent' => 50 ) ) ), $lines, $ctx );
check( 'stacked promotions never exceed the line price', 20.0 === $stacked[1]['shirt'] && 30.0 === $stacked[2]['shirt'] && 60.0 >= $stacked[1]['shirt'] + $stacked[2]['shirt'] && 5.0 === $stacked[2]['cap'], json_encode( $stacked ) );
$alone = Engine::stack( array( 1 => $r( array( 'action' => 'percent', 'percent' => 80 ) ), 2 => $r( array( 'action' => 'percent', 'percent' => 50 ) ) ), $lines, $ctx );
check( 'the second promotion only gets what the first one left', 48.0 === $alone[1]['shirt'] && 12.0 === $alone[2]['shirt'], json_encode( $alone ) );
check( 'promotions do not share the cart with individual-use coupons', 'other_coupon' === Engine::blocker( $r( array() ), $lines, array( 'individual_use_coupon' => true ) + $ctx ) );
check( 'date window', 'not_started' === Engine::blocker( $r( array( 'date_from' => 1800000001 ) ), $lines, $ctx ) && 'ended' === Engine::blocker( $r( array( 'date_to' => 1799999999 ) ), $lines, $ctx ) );
check( 'customer groups', 'customer' === Engine::blocker( $r( array( 'customer' => 'roles', 'roles' => array( 'wholesale' ) ) ), $lines, $ctx ) && '' === Engine::blocker( $r( array( 'customer' => 'first_order' ) ), $lines, $ctx ) && 'customer' === Engine::blocker( $r( array( 'customer' => 'returning' ) ), $lines, $ctx ) );
check( 'can refuse to combine with other coupons', 'other_coupon' === Engine::blocker( $r( array( 'with_coupons' => false ) ), $lines, array( 'other_coupons' => array( 'summer' ) ) + $ctx ) );
check( 'bonus promotions never discount the cart', array() === Engine::discounts( $r( array( 'action' => 'bonus', 'bonus_amount' => 10 ) ), $lines, $ctx ) );
check( 'bonus after a qualifying order', '' === Engine::bonus_blocker( $r( array( 'action' => 'bonus', 'bonus_amount' => 10, 'min_subtotal' => 70 ) ), $lines, $ctx ) );
check( 'bonus for gift card purchases counts only vouchers', '' === Engine::bonus_blocker( $r( array( 'action' => 'bonus', 'bonus_amount' => 5, 'bonus_trigger' => 'gift_card', 'min_subtotal' => 50 ) ), $lines, $ctx ) && 'min_subtotal' === Engine::bonus_blocker( $r( array( 'action' => 'bonus', 'bonus_amount' => 5, 'bonus_trigger' => 'gift_card', 'min_subtotal' => 51 ) ), $lines, $ctx ) );
check( 'bonus needs a value', 'no_items' === Engine::bonus_blocker( $r( array( 'action' => 'bonus' ) ), $lines, $ctx ) );
$campaigns = array(
	array( 'id' => 1, 'priority' => 20, 'rules' => $r( array( 'percent' => 5, 'stackable' => true ) ) ),
	array( 'id' => 2, 'priority' => 10, 'rules' => $r( array( 'percent' => 20 ) ) ),
	array( 'id' => 3, 'priority' => 30, 'rules' => $r( array( 'percent' => 5, 'stackable' => true ) ) ),
);
check( 'priority: an exclusive campaign with the best priority applies alone', array( 2 ) === Engine::select( $campaigns, $lines, $ctx ) );
$campaigns[1]['priority'] = 25;
check( 'stackable campaigns combine, a later exclusive one is skipped', array( 1, 3 ) === Engine::select( $campaigns, $lines, $ctx ) );

echo "Spreadsheet import\n";
$csv = "$out/codes.csv";
file_put_contents( $csv, "\xEF\xBB\xBFcode;note\nAAA-111;x\nBBB-222;y\n\n\"CCC;333\";z\n" );
$rows = SpreadsheetReader::read( $csv, 'csv' );
check( 'CSV with BOM + semicolon delimiter', array( 'code', 'AAA-111', 'BBB-222', 'CCC;333' ) === array_column( $rows, 0 ), json_encode( $rows ) );

if ( class_exists( 'ZipArchive' ) ) {
	// Zip bomb: a tiny archive whose worksheet unpacks to far more than the limit is refused before reading.
	$bomb = "$out/bomb.xlsx";
	$zip  = new ZipArchive();
	$zip->open( $bomb, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData>' . str_repeat( ' ', 80 * 1024 * 1024 ) . '</sheetData></worksheet>' );
	$zip->close();
	$refused = '';
	try {
		SpreadsheetReader::read( $bomb, 'xlsx' );
	} catch ( RuntimeException $e ) {
		$refused = $e->getMessage();
	}
	check( 'XLSX zip bomb is refused before it is unpacked', str_contains( $refused, 'too large' ), $refused );
	$ok_file = "$out/small.xlsx";
	$zip     = new ZipArchive();
	$zip->open( $ok_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c t="inlineStr"><is><t>CODE-1</t></is></c></row></sheetData></worksheet>' );
	$zip->close();
	check( 'a normal XLSX is still read', array( array( 'CODE-1' ) ) === SpreadsheetReader::read( $ok_file, 'xlsx' ) );
	unlink( $bomb );
}

echo "License (WP License Captain): client, offline cache, fail-closed gate\n";
// Fake license server: signs each answer with the installation secret (HMAC over the JSON data).
$lic = array( 'secret' => '', 'mode' => 'ok', 'status' => 'active', 'rights' => array( 'voucher.promotions' => true, 'voucher.referrals' => false, 'voucher.reports' => 0, 'pass.tickets' => true ) );
$GLOBALS['vcap_test_http'] = static function ( $url, $args ) use ( &$lic ) {
	if ( 'down' === $lic['mode'] ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
	}
	if ( '503' === $lic['mode'] ) {
		return array( 'code' => 503, 'body' => '{"code":"maintenance","message":"down"}' );
	}
	if ( '403' === $lic['mode'] ) {
		return array( 'code' => 403, 'body' => '{"code":"license_revoked","message":"revoked"}' );
	}
	$body = json_decode( (string) $args['body'], true ) ?: array();
	if ( str_contains( $url, '/activations' ) && 'POST' === $args['method'] ) {
		$lic['secret'] = $body['installation_secret'];
		$data          = array( 'activation_id' => 7 );
	} else {
		$data = array( 'license_status' => $lic['status'], 'entitlements' => $lic['rights'], 'cache_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
	}
	$sig = hash_hmac( 'sha256', json_encode( $data, JSON_UNESCAPED_SLASHES ), 'forged' === $lic['mode'] ? 'wrong-secret' : $lic['secret'] );
	return array( 'code' => 200, 'body' => json_encode( array( 'data' => $data, 'signature' => $sig ) ) );
};
$GLOBALS['vcap_test_options']['vcap_wplc_server_url'] = 'https://licenses.example.test';
$client = new VCAP\Licensing\LicenseClient( 'https://licenses.example.test', 'voucher-captain', '1.0', 'vcap_wplc_credentials' );
$lic['mode'] = 'forged';
check( 'a forged activation answer is refused and nothing is stored', is_wp_error( $client->activate( 'KEY-1' ) ) && ! get_option( 'vcap_wplc_credentials' ) );
$lic['mode'] = 'ok';
$act = $client->activate( 'key-1' );
$cred = get_option( 'vcap_wplc_credentials' );
check( 'activation stores installation id, secret and activation id – never the license key', is_array( $act ) && 7 === $cred['activation_id'] && 64 === strlen( $cred['installation_secret'] ) && ! str_contains( (string) json_encode( $cred ), 'KEY-1' ) );
$online = $client->get_entitlements();
check( 'online check returns the signed rights and stores a signed snapshot', is_array( $online ) && is_array( get_option( 'vcap_wplc_credentials_entitlements_cache' ) ) );
check( 'rights: true allows; false, 0, missing and foreign-product keys deny', $client->allows_entitlement( 'voucher.promotions', $online ) && ! $client->allows_entitlement( 'voucher.referrals', $online ) && ! $client->allows_entitlement( 'voucher.reports', $online ) && ! $client->allows_entitlement( 'voucher.topup', $online ) && ! $client->allows_entitlement( 'pass.tickets', $online ) );
check( 'development checkout (no edition constant): every premium feature stays on', VCAP\Licensing\Entitlements::allows( 'referrals' ) && VCAP\Licensing\Entitlements::allows( 'topup' ) );
$required = static fn() => true;
add_filter( 'vcap_license_required', $required );
VCAP\Licensing\Entitlements::reset();
check( 'premium edition: allowed rights work, others are locked', VCAP\Licensing\Entitlements::allows( 'promotions' ) && ! VCAP\Licensing\Entitlements::allows( 'referrals' ) && ! VCAP\Licensing\Entitlements::allows( 'topup' ) && array( 'referrals', 'topup', 'reports', 'passcaptain' ) === VCAP\Licensing\Entitlements::locked() );
$lic['mode'] = 'down';
$offline = $client->get_entitlements();
check( 'server unreachable: the signed snapshot keeps the rights (offline)', is_array( $offline ) && $client->allows_entitlement( 'voucher.promotions', $offline ) );
$lic['mode'] = '503';
check( 'server error 5xx: the signed snapshot keeps the rights', is_array( $client->get_entitlements() ) );
$cache = get_option( 'vcap_wplc_credentials_entitlements_cache' );
$tampered = $cache;
$tampered['data']['entitlements']['voucher.referrals'] = true;
update_option( 'vcap_wplc_credentials_entitlements_cache', $tampered );
VCAP\Licensing\Entitlements::reset();
check( 'a tampered snapshot is refused (fail-closed)', is_wp_error( $client->get_entitlements() ) && ! VCAP\Licensing\Entitlements::allows( 'promotions' ) );
$expired = $cache;
$expired['cached_at'] = time() - 20 * DAY_IN_SECONDS;
$expired['expires_at'] = time() - DAY_IN_SECONDS;
update_option( 'vcap_wplc_credentials_entitlements_cache', $expired );
VCAP\Licensing\Entitlements::reset();
check( 'an expired snapshot (more than 14 days offline) locks the features', is_wp_error( $client->get_entitlements() ) && ! VCAP\Licensing\Entitlements::allows( 'promotions' ) );
$long = $cache;
$long['expires_at'] = $long['cached_at'] + 30 * DAY_IN_SECONDS;
update_option( 'vcap_wplc_credentials_entitlements_cache', $long );
check( 'a snapshot claiming more than 14 days is refused', is_wp_error( $client->get_entitlements() ) );
update_option( 'vcap_wplc_credentials_entitlements_cache', $cache );
$lic['mode'] = '403';
VCAP\Licensing\Entitlements::reset();
check( 'license refused by the server (4xx): no offline fallback', is_wp_error( $client->get_entitlements() ) );
$lic['mode'] = 'ok';
$lic['status'] = 'expired';
$client->get_entitlements();
VCAP\Licensing\Entitlements::reset();
check( 'license no longer active: snapshot deleted, premium features locked', false === get_option( 'vcap_wplc_credentials_entitlements_cache' ) && ! VCAP\Licensing\Entitlements::allows( 'promotions' ) );
delete_option( 'vcap_wplc_credentials' );
VCAP\Licensing\Entitlements::reset();
check( 'not activated: locked', ! VCAP\Licensing\Entitlements::allows( 'promotions' ) );
remove_filter( 'vcap_license_required', $required );
unset( $GLOBALS['vcap_test_http'], $GLOBALS['vcap_test_options']['vcap_wplc_server_url'] );
VCAP\Licensing\Entitlements::reset();

echo "Premium updates (PR #11): redirect, host allow-list, SHA-256\n";
$GLOBALS['vcap_test_options']['vcap_wplc_server_url'] = 'https://licenses.example.test';
$zip      = 'PK-fake-release-zip';
$redirect = 'https://licenses.example.test/files/voucher-captain.zip';
$GLOBALS['vcap_test_http'] = static function ( $url ) use ( &$zip, &$redirect ) {
	if ( str_contains( $url, '/wplc/v1/updates/download/' ) ) {
		return array( 'code' => 302, 'body' => '', 'headers' => array( 'location' => $redirect ) );
	}
	return array( 'code' => 200, 'body' => $zip, 'headers' => array() );
};
$updater  = new PremiumUpdater();
$updater->register_hooks();
$call     = static fn( string $m, ...$a ) => ( new ReflectionMethod( $updater, $m ) )->invoke( $updater, ...$a );
$package  = 'https://licenses.example.test/wp-json/wplc/v1/updates/download/abc';
$file     = $call( 'download_and_verify', $package, hash( 'sha256', $zip ) );
check( 'a package with the expected SHA-256 is accepted', is_string( $file ) && is_file( $file ) && hash_file( 'sha256', $file ) === hash( 'sha256', $zip ) );
@unlink( (string) $file );
$bad = $call( 'download_and_verify', $package, str_repeat( 'a', 64 ) );
check( 'a different SHA-256 stops the installation', is_wp_error( $bad ) && 'wplc_checksum_mismatch' === $bad->get_error_code() );
$redirect = 'https://evil.example.org/voucher-captain.zip';
$host     = $call( 'download_and_verify', $package, hash( 'sha256', $zip ) );
check( 'a redirect to a host outside the allow-list is refused', is_wp_error( $host ) && 'wplc_download_host_invalid' === $host->get_error_code() );
$redirect = 'http://licenses.example.test/voucher-captain.zip';
check( 'plain HTTP is refused', is_wp_error( $call( 'download_and_verify', $package, hash( 'sha256', $zip ) ) ) );
check( 'IP addresses are never allowed hosts', ! $call( 'is_allowed_package_host', 'https://192.0.2.1/x.zip' ) && $call( 'is_allowed_package_host', 'https://licenses.example.test/x.zip' ) );
check( 'only download URLs of the license server are handled by the updater', $call( 'is_license_package', $package ) && ! $call( 'is_license_package', 'https://downloads.wordpress.org/plugin/x.zip' ) );

// Update offer: background (no current user), cached, only download grants of the license server.
$upd_secret = str_repeat( 'b', 64 );
update_option( 'vcap_wplc_credentials', array( 'installation_id' => 'inst-1', 'installation_secret' => $upd_secret, 'activation_id' => 9 ) );
$offer = array( 'update_available' => true, 'version' => '9.9.9', 'package_url' => 'https://licenses.example.test/wp-json/wplc/v1/updates/download/' . str_repeat( 'c', 64 ), 'sha256' => hash( 'sha256', $zip ), 'requires_php' => '8.1', 'requires_wp' => '6.4', 'tested_wp' => '7.1', 'release_notes' => '<p>Notes 9.9.9</p>' );
$checks = 0;
$timeouts = array();
$GLOBALS['vcap_test_http'] = static function ( $url, $args ) use ( &$offer, &$checks, &$timeouts, $upd_secret ) {
	++$checks;
	$timeouts[] = $args['timeout'] ?? null;
	return array( 'code' => 200, 'body' => json_encode( array( 'data' => $offer, 'signature' => hash_hmac( 'sha256', json_encode( $offer, JSON_UNESCAPED_SLASHES ), $upd_secret ) ) ) );
};
$basename  = plugin_basename( VCAP_FILE );
$wporg     = (object) array( 'response' => array( $basename => (object) array( 'slug' => 'voucher-captain', 'package' => 'https://downloads.wordpress.org/plugin/voucher-captain.zip' ) ), 'no_update' => array() );
$offered   = $updater->inject_update( $wporg );
check( 'without a current user (WP-Cron) the update of the license server is offered', isset( $offered->response[ $basename ] ) && '9.9.9' === $offered->response[ $basename ]->new_version && ! empty( $offered->response[ $basename ]->wplc_managed ) );
check( 'an offer of another source for the premium plugin (e.g. WordPress.org) is replaced', $offer['package_url'] === $offered->response[ $basename ]->package );
check( 'the update check uses a short timeout (5 s)', array( 5 ) === $timeouts, (string) json_encode( $timeouts ) );
$updater->inject_update( (object) array( 'response' => array() ) );
$updater->inject_update( (object) array( 'response' => array() ) );
check( 'the answer is cached: further transient updates do not ask the license server', 1 === $checks, (string) $checks );
delete_site_transient( 'update_plugins' );
$updater->inject_update( (object) array( 'response' => array() ) );
check( 'a forced check (update transient deleted) asks the license server again', 2 === $checks, (string) $checks );
$info = $updater->plugins_api( false, 'plugin_information', (object) array( 'slug' => 'voucher-captain' ) );
check( '"View details" shows version, requirements and release notes of the license server', is_object( $info ) && '9.9.9' === $info->version && '8.1' === $info->requires_php && str_contains( $info->sections['changelog'], 'Notes 9.9.9' ) && false === $updater->plugins_api( false, 'plugin_information', (object) array( 'slug' => 'woocommerce' ) ) );
$offer['package_url'] = 'https://cdn.example.org/voucher-captain-9.9.9.zip';
PremiumUpdater::flush();
$foreign = $updater->inject_update( (object) array( 'response' => array() ) );
check( 'a package URL outside the license server is never offered (not even with a checksum)', ! isset( $foreign->response[ $basename ] ) && isset( $foreign->no_update[ $basename ] ) );
$offer['package_url'] = 'https://licenses.example.test/wp-json/wplc/v1/updates/download/' . str_repeat( 'd', 64 );
$offer['sha256']      = 'not-a-checksum';
PremiumUpdater::flush();
check( 'an offer without a valid SHA-256 is not offered', ! isset( $updater->inject_update( (object) array( 'response' => array() ) )->response[ $basename ] ) );
$offer['sha256'] = hash( 'sha256', $zip );
$offer['update_available'] = false;
PremiumUpdater::flush();
$GLOBALS['vcap_test_http'] = static function ( $url, $args ) use ( &$offer, $upd_secret ) {
	return array( 'code' => 503, 'body' => '{"code":"maintenance","message":"down"}' );
};
$checks = 0;
check( 'a failing license server leaves no offer (failure cached for an hour)', ! isset( $updater->inject_update( (object) array( 'response' => array() ) )->response[ $basename ] ) && HOUR_IN_SECONDS === PremiumUpdater::FAILURE_TTL && 12 * HOUR_IN_SECONDS === PremiumUpdater::SUCCESS_TTL );
delete_option( 'vcap_wplc_credentials' );
PremiumUpdater::flush();
unset( $GLOBALS['vcap_test_http'], $GLOBALS['vcap_test_options']['vcap_wplc_server_url'] );

echo "Translations\n";
/** @return array<string, array{0: string[], 1: ?string, 2: bool}> key (context \x04 msgid) => [msgstr forms, msgid_plural, fuzzy] */
function vcap_test_po( string $file ): array {
	$entries = array();
	foreach ( preg_split( "/\n\s*\n/", (string) file_get_contents( $file ) ) as $block ) {
		$lines = array();
		$key   = null;
		foreach ( explode( "\n", $block ) as $line ) {
			if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $m ) ) {
				$key           = $m[1];
				$lines[ $key ] = stripcslashes( $m[2] );
			} elseif ( null !== $key && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$lines[ $key ] .= stripcslashes( $m[1] );
			}
		}
		if ( ! isset( $lines['msgid'] ) || '' === $lines['msgid'] ) {
			continue;
		}
		$forms = array();
		foreach ( $lines as $k => $v ) {
			if ( str_starts_with( $k, 'msgstr' ) ) {
				$forms[] = $v;
			}
		}
		$entries[ ( $lines['msgctxt'] ?? '' ) . "\x04" . $lines['msgid'] ] = array( $forms, $lines['msgid_plural'] ?? null, str_contains( $block, '#, fuzzy' ) );
	}
	return $entries;
}
$placeholders = static function ( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[sdf]|\{[a-z_]+\}/', $text, $m );
	$list = array_map( static fn( $p ) => preg_replace( '/%\d+\$/', '%', $p ), $m[0] );
	sort( $list );
	return $list;
};
$pot = vcap_test_po( dirname( __DIR__ ) . '/languages/voucher-captain.pot' );
check( 'the POT file lists the plugin texts', count( $pot ) > 1000, (string) count( $pot ) );
foreach ( array( 'de_DE', 'de_AT' ) as $locale ) {
	$po        = vcap_test_po( dirname( __DIR__ ) . "/languages/voucher-captain-{$locale}.po" );
	$missing   = array();
	$broken    = array();
	foreach ( $pot as $key => $entry ) {
		$translation = $po[ $key ] ?? null;
		if ( ! $translation || $translation[2] || in_array( '', $translation[0], true ) || ! $translation[0] ) {
			$missing[] = substr( $key, strpos( $key, "\x04" ) + 1 );
			continue;
		}
		$source = substr( $key, strpos( $key, "\x04" ) + 1 );
		$want   = array_values( array_unique( array_merge( $placeholders( $source ), $placeholders( (string) $entry[1] ) ) ) );
		foreach ( $translation[0] as $form ) {
			$got = array_values( array_unique( $placeholders( $form ) ) );
			// Plural forms may leave out the number (e.g. "one voucher"), never add a placeholder.
			if ( $entry[1] ? array_diff( $got, $want ) : $got !== $want ) {
				$broken[] = $source;
			}
		}
	}
	$obsolete = array_diff_key( $po, $pot );
	check( "{$locale}: every text is translated (none missing or fuzzy)", ! $missing, implode( ' | ', array_slice( $missing, 0, 5 ) ) );
	check( "{$locale}: translations keep the placeholders (%s, %1\$s, {code}) of the source", ! $broken, implode( ' | ', array_slice( array_unique( $broken ), 0, 5 ) ) );
	check( "{$locale}: no translations for texts the plugin no longer uses", ! $obsolete, implode( ' | ', array_slice( array_keys( $obsolete ), 0, 5 ) ) );
	$mo = dirname( __DIR__ ) . "/languages/voucher-captain-{$locale}.mo";
	check( "{$locale}: the compiled .mo file is there", is_file( $mo ) && filesize( $mo ) > 1000 );
}

// Locale fallback (VCAP\I18n): German variants without their own file load the German translation.
$lang = dirname( __DIR__ ) . '/languages/';
$wp_lang = $out . '/wp-languages/plugins/';
@mkdir( $wp_lang, 0777, true );
check( 'de_DE_formal without its own file loads the German translation', $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_DE_formal.mo', 'voucher-captain' ) );
check( 'de_CH and de_CH_informal load the German translation', $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_CH.mo', 'voucher-captain' ) && $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_CH_informal.mo', 'voucher-captain' ) );
check( 'a locale with its own file keeps it (de_AT)', $lang . 'voucher-captain-de_AT.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_AT.mo', 'voucher-captain' ) );
check( 'the global languages folder falls back to the plugin file when it has no German file', $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $wp_lang . 'voucher-captain-de_DE_formal.mo', 'voucher-captain' ) );
check( 'other locales and other text domains are left alone', $lang . 'voucher-captain-fr_FR.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-fr_FR.mo', 'voucher-captain' ) && $wp_lang . 'woocommerce-de_DE_formal.mo' === \VCAP\I18n::mofile( $wp_lang . 'woocommerce-de_DE_formal.mo', 'woocommerce' ) );
check( 'just-in-time loading finds the plugin folder for de_DE_formal', $lang === \VCAP\I18n::lang_dir( false, 'voucher-captain', 'de_DE_formal' ) && false === \VCAP\I18n::lang_dir( false, 'voucher-captain', 'fr_FR' ) );
// bin/make-pot.php: version from the plugin header, header fields translatable (like wp i18n make-pot).
$pot_file = $out . '/make-pot-test.pot';
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/make-pot.php' ) . ' ' . escapeshellarg( $pot_file ), $pot_log, $pot_status );
$pot_new = 0 === $pot_status ? vcap_test_po( $pot_file ) : array();
preg_match( '/^ \* Version:\s*(\S+)/m', (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' ), $vcap_version );
preg_match( '/^ \* Description:\s*(.+)$/m', (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' ), $vcap_description );
check( 'make-pot: the POT names the plugin version of the header', str_contains( (string) @file_get_contents( $pot_file ), 'Project-Id-Version: Voucher Captain ' . ( $vcap_version[1] ?? '?' ) . '\\n' ), implode( ' ', $pot_log ) );
check( 'make-pot: plugin name and description are translatable', isset( $pot_new[ "\x04Voucher Captain" ], $pot_new[ "\x04" . trim( $vcap_description[1] ?? '?' ) ] ) );
@unlink( $pot_file );

echo "License server signature (Ed25519, WP License Captain docs/API-V1.md test vector)\n";
$sig_code = <<<'PHP'
define( 'VCAP_LICENSE_PUBLIC_KEY', 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=' );
require $argv[1];
$c   = VCAP\Licensing\LicenseClient::class;
$sig = '4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==';
$id  = 'a1b2c3d4-0000-4000-8000-000000000001';
echo json_encode( array(
	$c::server_signature_valid( '{"license_status":"active"}', $sig, $id ),
	$c::server_signature_valid( '{"license_status":"active"}', $sig, strtoupper( $id ) ),
	$c::server_signature_valid( '{"license_status":"suspended"}', $sig, $id ),
	$c::server_signature_valid( '{"license_status":"active"}', $sig, 'a1b2c3d4-0000-4000-8000-000000000002' ),
	$c::server_signature_valid( '{"license_status":"active"}', '', $id ),
) );
PHP;
$sig_file = $out . '/license-signature.php';
file_put_contents( $sig_file, '<?php ' . $sig_code );
$sig_prepend = (string) ini_get( 'auto_prepend_file' );
$sig_out     = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ( '' !== $sig_prepend ? ' -d auto_prepend_file=' . escapeshellarg( $sig_prepend ) : '' ) . ' ' . escapeshellarg( $sig_file ) . ' ' . escapeshellarg( __DIR__ . '/stubs.php' ) . ' 2>&1' );
check( 'pinned key: the server signature of the documented vector is accepted (installation case-insensitive)', str_starts_with( trim( $sig_out ), '[true,true,' ), $sig_out );
check( 'pinned key: other data, another installation or a missing signature are rejected', '[true,true,false,false,false]' === trim( $sig_out ), $sig_out );
check( 'without a pinned key the HMAC alone decides (development and free builds)', VCAP\Licensing\LicenseClient::server_signature_valid( '{}', '', 'x' ) );

echo "Money guards: currencies, gift card shares, discount setup\n";
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'EUR';
$fx_filters = $GLOBALS['vcap_test_filters']['vcap_amount_to_base_currency'] ?? null;
$fx_known   = $GLOBALS['vcap_test_filters']['vcap_currency_rate_known'] ?? null;
( new VCAP\Compat\MultiCurrency() )->register();
check( 'the base currency always converts (unchanged)', VCAP\Compat\MultiCurrency::can_convert( 'EUR' ) && 12.5 === VCAP\Compat\MultiCurrency::to_base( 12.5, 'EUR' ) );
check( 'a foreign currency without converter is never taken 1:1', ! VCAP\Compat\MultiCurrency::can_convert( 'CZK' ) && null === VCAP\Compat\MultiCurrency::to_base( 1000.0, 'CZK' ) );
check( 'the refusal is recorded for Site Health', isset( VCAP\Compat\MultiCurrency::unknown_currencies()['CZK'] ) );
check( 'the public filter still returns the amount unchanged without a rate (extension contract)', 1000.0 === (float) apply_filters( 'vcap_amount_to_base_currency', 1000.0, 'CZK', null ) );
add_filter( 'vcap_amount_to_base_currency', static fn( $amount, $currency ) => 'CZK' === $currency ? $amount / 25 : $amount, 20, 2 );
check( 'a converter on the public filter counts as a known rate (1000 CZK = 40 EUR)', VCAP\Compat\MultiCurrency::can_convert( 'CZK' ) && 40.0 === VCAP\Compat\MultiCurrency::to_base( 1000.0, 'CZK' ) );
check( 'other currencies stay unknown', ! VCAP\Compat\MultiCurrency::can_convert( 'HUF' ) );
add_filter( 'vcap_currency_rate_known', static fn( $known, $currency ) => $known || 'DKK' === $currency, 10, 2 );
check( 'vcap_currency_rate_known declares a 1:1 peg as known', VCAP\Compat\MultiCurrency::can_convert( 'DKK' ) && 10.0 === VCAP\Compat\MultiCurrency::to_base( 10.0, 'DKK' ) );
$GLOBALS['vcap_test_filters']['vcap_amount_to_base_currency'] = $fx_filters;
$GLOBALS['vcap_test_filters']['vcap_currency_rate_known']     = $fx_known;
if ( null === $fx_filters ) {
	unset( $GLOBALS['vcap_test_filters']['vcap_amount_to_base_currency'] );
}
if ( null === $fx_known ) {
	unset( $GLOBALS['vcap_test_filters']['vcap_currency_rate_known'] );
}
unset( $GLOBALS['vcap_test_options'][ VCAP\Compat\MultiCurrency::UNKNOWN_OPTION ] );

$sh = VCAP\TopUp::shares( 100, 0, 100, 100 );
check( 'card 100 + top-up 100, 100 spent: the purchase counts as spent, the top-up as unspent', 100.0 === $sh['purchase_spent'] && 0.0 === $sh['purchase_unspent'] && 100.0 === $sh['topup_unspent'], wp_json_encode( $sh ) );
$sh = VCAP\TopUp::shares( 100, 0, 100, 150 );
check( 'card 100 + top-up 100, 50 spent: 50 of the purchase and the whole top-up can be refunded (= balance 150)', 50.0 === $sh['purchase_spent'] && 50.0 === $sh['purchase_unspent'] && 100.0 === $sh['topup_unspent'], wp_json_encode( $sh ) );
$sh = VCAP\TopUp::shares( 100, 0, 0, 50 );
check( 'after the top-up (100) was refunded first, the purchase counts 50 as spent', 50.0 === $sh['purchase_spent'] && 50.0 === $sh['purchase_unspent'], wp_json_encode( $sh ) );
$sh = VCAP\TopUp::shares( 100, 50, 100, 100 );
check( 'after the unspent purchased value (50) was taken off, the purchase has nothing left and the top-up keeps 100', 50.0 === $sh['purchase_spent'] && 0.0 === $sh['purchase_unspent'] && 100.0 === $sh['topup_unspent'], wp_json_encode( $sh ) );
$sh = VCAP\TopUp::shares( 100, 0, 100, 100, true );
check( 'a voided card: top-ups only claim the balance above the purchased value still on the books', 0.0 === $sh['topup_unspent'], wp_json_encode( $sh ) );
$sh = VCAP\TopUp::shares( 20, 0, 0, 60 );
check( 'goodwill above purchase and top-ups is not refundable', 0.0 === $sh['purchase_spent'] && 20.0 === $sh['purchase_unspent'] && 0.0 === $sh['topup_unspent'], wp_json_encode( $sh ) );
$over = array();
mt_srand( 42 );
for ( $i = 0; $i < 2000; $i++ ) {
	$initial = mt_rand( 0, 300 );
	$topups  = mt_rand( 0, 300 );
	$removed = mt_rand( 0, $initial );
	$balance = mt_rand( 0, max( 0, $initial - $removed + $topups ) );
	foreach ( array( false, true ) as $void ) {
		$sh = VCAP\TopUp::shares( $initial, $removed, $topups, $balance, $void );
		if ( $sh['purchase_unspent'] + $sh['topup_unspent'] > $balance + 1e-9 || $sh['purchase_spent'] + $sh['purchase_unspent'] > $initial - $removed + 1e-9 ) {
			$over[] = array( $initial, $removed, $topups, $balance, $void );
		}
	}
}
check( 'purchase refund + top-up refunds never exceed the balance (2000 random cards, active and voided)', ! $over, wp_json_encode( array_slice( $over, 0, 3 ) ) );

$cfg = static fn( array $c ) => $c + ProductConfig::DEFAULTS;
check( 'percentage from the product price is refused', (bool) ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'price' ) ) ) );
check( 'percentage from a customer-chosen amount is refused', (bool) ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'custom' ) ) ) );
check( 'percentage above 100 or of 0 is refused', ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '120' ) ) ) && ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '' ) ) ) );
check( 'a fixed percentage between 0 and 100 is fine', array() === ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '100' ) ) ) && array() === ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '12,5' ) ) ) );
check( '"Fixed product discount" is refused for sold single-use vouchers, not for stored balances (cart discount)', ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'fixed_product' ) ) ) && array() === ProductConfig::discount_errors( $cfg( array( 'discount_type' => 'fixed_product', 'usage' => 'balance' ) ) ) );
check( 'a ticket without fixed value carries no value (no coupon) and stays fine', array() === ProductConfig::discount_errors( $cfg( array( 'type' => 'ticket', 'discount_type' => 'percent', 'amount_source' => 'price' ) ) ) );
check( 'the default setup (fixed cart discount from the price) is fine', array() === ProductConfig::discount_errors( ProductConfig::DEFAULTS ) );

echo "Editions (constants set by bin/build.sh)\n";
// Premium build in a child process (constants cannot be undone here): no filter unlocks anything.
$premium_code = <<<'PHP'
define( 'VCAP_EDITION', 'premium' );
define( 'VCAP_LICENSE_REQUIRED', true );
define( 'VCAP_LICENSE_SERVER', 'https://licenses.pinned.test' );
require $argv[1];
add_filter( 'vcap_license_required', '__return_false' );
add_filter( 'vcap_premium_feature_allowed', '__return_true' );
add_filter( 'vcap_premium_features', '__return_empty_array' );
update_option( 'vcap_wplc_server_url', 'https://evil.example.org' );
$e = VCAP\Licensing\Entitlements::class;
echo json_encode( array( $e::edition(), $e::required(), $e::allows( 'promotions' ), count( $e::locked() ), $e::server_url(), $e::server_pinned() ) );
PHP;
$premium_file = $out . '/edition-premium.php';
file_put_contents( $premium_file, '<?php ' . $premium_code );
$prepend      = (string) ini_get( 'auto_prepend_file' );
$premium_out  = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ( '' !== $prepend ? ' -d auto_prepend_file=' . escapeshellarg( $prepend ) : '' ) . ' ' . escapeshellarg( $premium_file ) . ' ' . escapeshellarg( __DIR__ . '/stubs.php' ) . ' 2>&1' );
check( 'premium build: license required, filters cannot unlock, all five features locked without activation', '["premium",true,false,5,"https:\/\/licenses.pinned.test",true]' === trim( $premium_out ), $premium_out );
check( 'premium build: the license server is the one fixed at build time (stored option ignored)', str_contains( $premium_out, 'licenses.pinned.test' ) && ! str_contains( $premium_out, 'evil' ), $premium_out );
// Free build (last block of this file: the constant stays defined).
define( 'VCAP_EDITION', 'free' );
VCAP\Licensing\Entitlements::reset();
$free_locked = VCAP\Licensing\Entitlements::locked();
check( 'free build: edition "free", no license and no license server', 'free' === VCAP\Licensing\Entitlements::edition() && ! VCAP\Licensing\Entitlements::required() && null === VCAP\Licensing\Entitlements::client() && '' === VCAP\Licensing\Entitlements::server_url() );
check( 'free build: all five premium features are locked', array( 'promotions', 'referrals', 'topup', 'reports', 'passcaptain' ) === $free_locked, implode( ',', $free_locked ) );
add_filter( 'vcap_license_required', '__return_true' );
check( 'free build: features outside the premium list are never locked', VCAP\Licensing\Entitlements::allows( 'store_credit' ) );
check( 'free build: the lock names the premium edition', str_contains( VCAP\Licensing\Entitlements::locked_message( 'topup' ), 'included in Voucher Captain Premium' ) );

echo "\n$passed passed, $failed failed. Output: $out\n";
exit( $failed ? 1 : 0 );
