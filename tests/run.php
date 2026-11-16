<?php
/**
 * Lightweight test runner (no WordPress needed):  php tests/run.php [output-dir]
 */
require __DIR__ . '/stubs.php';

use VCAP\CodeGenerator;
use VCAP\CouponManager;
use VCAP\Delivery;
use VCAP\PdfRenderer;
use VCAP\Placeholders;
use VCAP\ProductConfig;
use VCAP\SpreadsheetReader;
use VCAP\TemplatePostType;
use VCAP\Tickets;
use VCAP\Voucher;

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
$q12 = ProductConfig::terms_by_country( "DE: Gültig 3 Jahre.\nNB: Keine Barauszahlung.\nOK: gilt auch online.\nAT: Gültig 30 Jahre." );
check( 'Q12: only ISO country codes start a block – "NB: …" stays part of the German conditions', array( 'DE' => "Gültig 3 Jahre.\nNB: Keine Barauszahlung.\nOK: gilt auch online.", 'AT' => 'Gültig 30 Jahre.' ) === $q12, json_encode( $q12 ) );
check( 'Q12: lines that are not used are found for the save notice (text before the first block, unreadable validity lines)', array( 'Allgemein: gilt überall' ) === ProductConfig::unassigned_terms_lines( "Allgemein: gilt überall\n\nDE: x" ) && array( 'DE: drei Jahre', 'NB: 5' ) === VCAP\Markets::unparsed_expiry_lines( "DE: drei Jahre\nAT: 30\n\nNB: 5" ) && ! VCAP\Markets::is_country_code( 'NB' ) && VCAP\Markets::is_country_code( 'ch' ) );
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
check( 'minimum length for 2^50: 10 letters/digits, 11 letters, 16 digits, 10 mixed case', 10 === CodeGenerator::min_length( 'alnum_upper' ) && 11 === CodeGenerator::min_length( 'alpha_upper' ) && 16 === CodeGenerator::min_length( 'numeric' ) && 10 === CodeGenerator::min_length( 'alnum' ) );
check( 'K5: mixed case counts case-insensitively like WooCommerce codes (33 characters, 9 of them only ≈ 45 bits)', 33 === CodeGenerator::effective_alphabet( 'alnum' ) && CodeGenerator::bits( 9, 'alnum' ) < 50 && CodeGenerator::bits( 10, 'alnum' ) >= 50 && abs( CodeGenerator::bits( 10, 'alnum' ) - CodeGenerator::estimated_bits( 'ab3defghjk' ) ) < 1.5 );
$money_code = CodeGenerator::build( array( 'prefix' => 'GIFT', 'length' => 8, 'charset' => 'numeric', 'min_bits' => CodeGenerator::MIN_BITS ) );
check( 'codes that carry money are lengthened to the minimum guessing space', (bool) preg_match( '/^GIFT-\d{16}$/', $money_code ), $money_code );
check( 'other codes keep the configured length', (bool) preg_match( '/^\d{8}$/', CodeGenerator::build( array( 'length' => 8, 'charset' => 'numeric' ) ) ) );
check( 'estimated guessing space of imported codes (8 digits weak, 16 digits / 10 alphanumerics enough)', CodeGenerator::estimated_bits( '12345678' ) < 50 && CodeGenerator::estimated_bits( '1234-5678-9012-3456' ) >= 50 && CodeGenerator::estimated_bits( 'K7QM2X9ZPA' ) >= 50 );
$affixes = CodeGenerator::common_affixes( array( 'SHOP-2026-48151623', 'shop-2026-99990000', 'SHOP-2026-12121212' ) );
check( 'a prefix shared by the whole batch does not count', array( 'shop-2026-', '' ) === $affixes && CodeGenerator::estimated_bits( 'SHOP-2026-48151623', $affixes[0], $affixes[1] ) < 50, json_encode( $affixes ) );
$affixes = CodeGenerator::common_affixes( array( 'GIFT2026000001', 'gift2026000002', 'GIFT2026000317' ) );
check( 'the shared prefix is taken away character by character, not only up to a separator', array( 'gift2026000', '' ) === $affixes && CodeGenerator::estimated_bits( 'GIFT2026000317', $affixes[0], $affixes[1] ) < 15, json_encode( $affixes ) );
check( 'a shared suffix is found after the prefix', array( 'a-', '-x' ) === CodeGenerator::common_affixes( array( 'A-12-X', 'a-34-x', 'A-56-X' ) ) );
$random_batch = array();
for ( $i = 0; $i < 50; $i++ ) {
	$random_batch[] = CodeGenerator::random( 12 );
}
check( 'random codes share no prefix or suffix and keep their full length', array( '', '' ) === CodeGenerator::common_affixes( $random_batch ) );
$rated = CodeGenerator::analyse_batch( $random_batch );
check( 'a batch of random codes is strong and no series', ! $rated['sequential'] && min( $rated['bits'] ) >= 50, json_encode( array( $rated['sequential'], min( $rated['bits'] ) ) ) );
$digits_batch = array();
for ( $i = 0; $i < 50; $i++ ) {
	$digits_batch[] = 'CARD-' . CodeGenerator::random( 18, 'numeric' );
}
$rated = CodeGenerator::analyse_batch( $digits_batch );
check( 'random 18-digit numbers are no series', ! $rated['sequential'] && ! $rated['check_digit'] && min( $rated['bits'] ) >= 50 );
$series = array();
for ( $i = 0; $i < 40; $i++ ) {
	$series[] = sprintf( 'GIFT-%016d', 4000123412340000 + 7919 * $i );
}
$rated = CodeGenerator::analyse_batch( $series );
check( 'evenly spaced numbers are a series and count as weak', $rated['sequential'] && 0.0 === max( $rated['bits'] ) );
$wide = array();
for ( $i = 0; $i < 30; $i++ ) {
	$wide[] = sprintf( '%016d', 1000000000000000 + 299999999999989 * $i ); // No shared prefix, 16 digits each.
}
$rated = CodeGenerator::analyse_batch( $wide );
check( 'a series with a large step is found although no prefix is shared', '' === $rated['prefix'] && $rated['sequential'] && 0.0 === max( $rated['bits'] ) );
$jitter = array();
$next   = 5000000000000000;
for ( $i = 0; $i < 30; $i++ ) {
	$next    += 100000000000000 + random_int( 0, 1000000000000 );
	$jitter[] = (string) $next;
}
check( 'nearly constant steps (low variance) are a series', CodeGenerator::analyse_batch( $jitter )['sequential'] );
$close = array();
$next  = 7300000000000000;
for ( $i = 0; $i < 30; $i++ ) {
	$next   += random_int( 1, 40 );
	$close[] = 'X' . $next . 'Y';
}
$close[] = 'X' . CodeGenerator::random( 16, 'numeric' ) . 'Y'; // One random number in the file keeps the prefix empty.
$rated = CodeGenerator::analyse_batch( $close );
check( 'numbers much closer together than random ones are part of a series', count( array_filter( $rated['bits'], static fn( $b ) => $b < 50 ) ) >= 30, json_encode( $rated['prefix'] ) );
$luhn = array();
foreach ( range( 1, 20 ) as $i ) {
	$base = CodeGenerator::random( 15, 'numeric' );
	for ( $d = 0; $d < 10 && ! CodeGenerator::luhn_valid( $base . $d ); $d++ ) {
	}
	$luhn[] = $base . $d;
}
$rated = CodeGenerator::analyse_batch( $luhn );
check( 'a Luhn check digit is recognised and adds no guessing space (16 digits with check digit are weak)', CodeGenerator::luhn_valid( '4111111111111111' ) && ! CodeGenerator::luhn_valid( '4111111111111112' ) && $rated['check_digit'] && max( $rated['bits'] ) < 50 && max( $rated['bits'] ) > 49 );
check( 'GS1/EAN check digits are recognised', CodeGenerator::gs1_valid( '4006381333931' ) && ! CodeGenerator::gs1_valid( '4006381333932' ) );
$mixed = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$mixed[] = 'KQ' . CodeGenerator::random( 12, 'alpha_upper' ) . '-' . sprintf( '%05d', $i );
}
$rated = CodeGenerator::analyse_batch( $mixed );
check( 'a numbered part counts as 0 bits, random letters around it still count', $rated['sequential'] && min( $rated['bits'] ) > 50 && min( $rated['bits'] ) < 12 * log( 26, 2 ) + 1 );
$existing = array();
for ( $i = 0; $i < 20; $i++ ) {
	$existing[] = sprintf( 'GIFT-%016d', 4000123412340000 + $i );
}
$single = CodeGenerator::analyse_batch( array( 7 => 'GIFT-4000123412340020' ), $existing );
check( 'a single code is rated with the structure of the codes already in the batch', 'gift-40001234123400' === $single['prefix'] && $single['bits'][7] < 50, json_encode( $single ) );
$single = CodeGenerator::analyse_batch( array( 'GIFT-' . CodeGenerator::random( 20, 'numeric' ) ), array( 'GIFT-' . CodeGenerator::random( 20, 'numeric' ), 'GIFT-' . CodeGenerator::random( 20, 'numeric' ) ) );
check( 'a single random code next to random codes of the batch stays strong', ! $single['sequential'] && $single['bits'][0] >= 50, json_encode( $single ) );
check( 'a single code without other codes keeps its full length', CodeGenerator::analyse_batch( array( '1234567890123456' ) )['bits'][0] >= 50 );
// M12-26: the alphabet is what the batch really uses – hex codes of 10 characters are 40 bits, not 52.
$hex_batch = array();
for ( $i = 0; $i < 20; $i++ ) {
	$hex_batch[] = strtoupper( bin2hex( random_bytes( 5 ) ) );
}
$rated = CodeGenerator::analyse_batch( $hex_batch );
check( 'M12-26: a hex batch of 10 characters counts as weak (16 characters seen, about 40 bits)', max( $rated['bits'] ) < 50 && max( $rated['bits'] ) <= 10 * log( 16, 2 ) + 0.01, json_encode( max( $rated['bits'] ) ) );
check( 'M12-26: a single code says nothing about the alphabet (full letters and digits)', CodeGenerator::analyse_batch( array( 'K7QM2X9ZPA' ) )['bits'][0] >= 50 && 0 === CodeGenerator::alphabet_seen( array( 'k7qm2x9zpa' ) ) );
$rated = CodeGenerator::analyse_batch( array( 7 => strtoupper( bin2hex( random_bytes( 5 ) ) ) ), $hex_batch );
check( 'M12-26: one more hex code is rated with the alphabet of the codes already in the batch', $rated['bits'][7] < 50, json_encode( $rated['bits'] ) );

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

echo "Local dates (datetime-local / date fields in the shop's time zone)\n";
$berlin = new DateTimeZone( 'Europe/Berlin' );
$ny     = new DateTimeZone( 'America/New_York' );
$utc    = new DateTimeZone( 'UTC' );
check( 'Berlin winter: 19:00 stays 19:00 (18:00 UTC)', '2026-12-24 19:00' === VCAP\LocalDate::format( '2026-12-24T19:00', 'Y-m-d', 'H:i', $berlin ) && strtotime( '2026-12-24 18:00:00 UTC' ) === VCAP\LocalDate::timestamp( '2026-12-24T19:00', $berlin ) );
check( 'Berlin summer: 19:00 stays 19:00 (17:00 UTC)', '2026-07-24 19:00' === VCAP\LocalDate::format( '2026-07-24T19:00', 'Y-m-d', 'H:i', $berlin ) && strtotime( '2026-07-24 17:00:00 UTC' ) === VCAP\LocalDate::timestamp( '2026-07-24T19:00', $berlin ) );
check( 'New York date only: same day, local midnight (05:00 UTC), no time appended', '24.12.2026' === VCAP\LocalDate::format( '2026-12-24', 'd.m.Y', 'H:i', $ny ) && strtotime( '2026-12-24 05:00:00 UTC' ) === VCAP\LocalDate::timestamp( '2026-12-24', $ny ) );
check( 'UTC: date and time unchanged', '2026-12-24 19:00' === VCAP\LocalDate::format( '2026-12-24T19:00', 'Y-m-d', 'H:i', $utc ) && strtotime( '2026-12-24 19:00:00 UTC' ) === VCAP\LocalDate::timestamp( '2026-12-24 19:00', $utc ) );
check( 'invalid dates and other text are not local dates', null === VCAP\LocalDate::parse( '2026-02-31' ) && null === VCAP\LocalDate::parse( '2026-12-24T24:00' ) && null === VCAP\LocalDate::parse( 'tomorrow' ) && '' === VCAP\LocalDate::format( 'next week', 'Y-m-d' ) );
$GLOBALS['vcap_test_options']['time_format'] = 'H:i';
check( '{event_date}: 19:00 in the shop time zone stays 19:00 (winter and summer)', '24.12.2026 19:00' === Placeholders::local_date( '2026-12-24T19:00' ) && '24.07.2026 19:00' === Placeholders::local_date( '2026-07-24T19:00' ), Placeholders::local_date( '2026-12-24T19:00' ) . ' / ' . Placeholders::local_date( '2026-07-24T19:00' ) );
check( '{event_date} date only and free text', '24.12.2026' === Placeholders::local_date( '2026-12-24' ) && 'Spring 2027' === Placeholders::local_date( 'Spring 2027' ) && '' === Placeholders::local_date( '' ) );
$v3         = clone $v;
$v3->fields = array( 'delivery_date' => array( 'label' => 'Delivery date', 'value' => '2026-12-24' ) );
$v3->delivery_at  = null;
$v3->delivered_at = '2026-11-10 09:00:00';
check( '{delivery_date} without a planned delivery shows the day it went out, never an unused chosen date', '10.11.2026' === Placeholders::replace( '{delivery_date}', $v3 ), Placeholders::replace( '{delivery_date}', $v3 ) );
unset( $GLOBALS['vcap_test_options']['time_format'] );

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
$field_on = array( 'builtin_fields' => array( 'recipient_name', 'delivery_date' ) );
$imm_date = Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, $field_on ), array( 'delivery_date' => array( 'value' => $future ) ) );
check( 'delivery date field with "immediately": the customer date is used', $imm_date && wp_date( 'Y-m-d H:i', strtotime( $imm_date . ' UTC' ) ) === $future . ' 08:00', (string) $imm_date );
$del_date = Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, $field_on, array( 'delivery' => 'delay', 'delivery_delay_days' => 3 ) ), array( 'delivery_date' => array( 'value' => $future ) ) );
check( 'delivery date field with a delay: a filled customer date wins', $del_date && wp_date( 'Y-m-d', strtotime( $del_date . ' UTC' ) ) === $future, (string) $del_date );
$del_empty = Delivery::compute_time( array_merge( ProductConfig::DEFAULTS, $field_on, array( 'delivery' => 'delay', 'delivery_delay_days' => 3 ) ), array( 'delivery_date' => array( 'value' => '' ) ) );
check( 'delivery date field left empty: the product delivery applies', $del_empty && abs( strtotime( $del_empty . ' UTC' ) - ( time() + 3 * DAY_IN_SECONDS ) ) < 5 );
check( 'field switched off: a stray date is ignored', null === Delivery::compute_time( ProductConfig::DEFAULTS, array( 'delivery_date' => array( 'value' => $future ) ) ) );
check( 'customer date enabled by mode or field', Delivery::customer_date_enabled( array( 'delivery' => 'customer' ) ) && Delivery::customer_date_enabled( array( 'delivery' => 'immediate', 'builtin_fields' => array( 'delivery_date_required' ) ) ) && ! Delivery::customer_date_enabled( ProductConfig::DEFAULTS ) );
$valid30 = array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'days', 'expiry_days' => 30 ) );
check( 'last delivery date: the expiry day (valid 30 days, delivered at 08:00)', wp_date( 'Y-m-d', strtotime( ProductConfig::expiry_for( $valid30, $base ) . ' UTC' ) ) === Delivery::latest_delivery_date( $valid30, $base ) && '2026-02-09' === Delivery::latest_delivery_date( $valid30, $base ), (string) Delivery::latest_delivery_date( $valid30, $base ) );
check( 'last delivery date: none for vouchers that never expire', null === Delivery::latest_delivery_date( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'never' ) ), $base ) );
$GLOBALS['vcap_test_filters']['vcap_delivery_min_validity_days'] = array( array( static fn() => 7, 1 ) );
check( 'last delivery date keeps a minimum validity (filter)', '2026-02-02' === Delivery::latest_delivery_date( $valid30, $base ) );
unset( $GLOBALS['vcap_test_filters']['vcap_delivery_min_validity_days'] );
$late = Delivery::delivery_after_expiry( array_merge( $valid30, array( 'delivery' => 'delay', 'delivery_delay_days' => 45 ) ), $base );
check( 'delay after the validity is reported', is_array( $late ) && '2026-02-24' === $late['delivery'] && '2026-02-09' === $late['expiry'], json_encode( $late ) );
check( 'delay within the validity is fine', null === Delivery::delivery_after_expiry( array_merge( $valid30, array( 'delivery' => 'delay', 'delivery_delay_days' => 10 ) ), $base ) );
$fixed_late = Delivery::delivery_after_expiry( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'date', 'expiry_date' => '2030-12-31', 'delivery' => 'date', 'delivery_date' => '2031-01-15' ) ), $base );
check( 'fixed delivery date after a fixed expiry date is reported', is_array( $fixed_late ) && '2031-01-15' === $fixed_late['delivery'] && '2030-12-31' === $fixed_late['expiry'], json_encode( $fixed_late ) );
check( 'retry pauses grow from 15 minutes to at most a day', 900 === Delivery::retry_delay( 1 ) && 1800 === Delivery::retry_delay( 2 ) && 3600 === Delivery::retry_delay( 3 ) && DAY_IN_SECONDS === Delivery::retry_delay( 9 ) && DAY_IN_SECONDS === Delivery::retry_delay( 40 ) );
check( 'deliveries are given up after 12 failed attempts by default', 12 === Delivery::max_attempts() );
check( 'maintenance runs cheap purges first and mails last', 'tmp_pdfs' === Delivery::MAINTENANCE_STEPS[0] && 'expiry_reminders' === Delivery::MAINTENANCE_STEPS[ count( Delivery::MAINTENANCE_STEPS ) - 1 ] && in_array( 'delivery_net', Delivery::MAINTENANCE_STEPS, true ) );

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

echo "Bundled delivery PDF (Z6)\n";
$z6_list = array();
for ( $z6_i = 1; $z6_i <= 3; $z6_i++ ) {
	$z6_v       = clone $v;
	$z6_v->id   = 9100 + $z6_i;
	$z6_v->code = 'BUNDLE-000' . $z6_i;
	$z6_list[]  = $z6_v;
}
$z6_parts = vcap()->pdf->render_many( $z6_list );
check( 'Z6: three vouchers of one design are rendered as one PDF document', 1 === count( $z6_parts ) && 3 === count( $z6_parts[0]['vouchers'] ) && str_starts_with( $z6_parts[0]['pdf'], '%PDF-' ) );
file_put_contents( "$out/z6-bundle.pdf", $z6_parts[0]['pdf'] ?? '' );
$z6_info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( "$out/z6-bundle.pdf" ) . ' 2>/dev/null' );
if ( preg_match( '/Pages:\s+(\d+)/', $z6_info, $z6_m ) ) {
	check( 'Z6: the bundle PDF has exactly one page per voucher (no blank pages)', '3' === $z6_m[1], 'pages=' . $z6_m[1] );
	$z6_text = (string) shell_exec( 'pdftotext ' . escapeshellarg( "$out/z6-bundle.pdf" ) . ' - 2>/dev/null' );
	$z6_page = (string) shell_exec( 'pdftotext -f 2 -l 2 ' . escapeshellarg( "$out/z6-bundle.pdf" ) . ' - 2>/dev/null' );
	check( 'Z6: every code is printed, each on its own page', str_contains( $z6_text, 'BUNDLE-0001' ) && str_contains( $z6_text, 'BUNDLE-0003' ) && str_contains( $z6_page, 'BUNDLE-0002' ) && ! str_contains( $z6_page, 'BUNDLE-0001' ), $z6_page );
}
$z6_files = vcap()->pdf->bundle_to_temp_files( $z6_list );
check( 'Z6: the bundle PDF is written as one private temp file named after the first voucher and the count', is_array( $z6_files ) && 1 === count( $z6_files ) && str_ends_with( $z6_files[0], '-and-2-more.pdf' ) && is_file( $z6_files[0] ), wp_json_encode( $z6_files ) );
foreach ( (array) $z6_files as $z6_file ) {
	@unlink( $z6_file );
}
check( 'Z6: bundled from 5 vouchers, PDF up to 50 by default', 5 === Delivery::bundle_threshold() && 50 === Delivery::bundle_pdf_limit() );
$GLOBALS['vcap_test_options']['vcap_bundle_threshold'] = 1;
$z6_one = Delivery::bundle_threshold();
$GLOBALS['vcap_test_options']['vcap_bundle_threshold'] = 0;
$z6_off = Delivery::bundle_threshold();
unset( $GLOBALS['vcap_test_options']['vcap_bundle_threshold'] );
$GLOBALS['vcap_test_filters']['vcap_bundle_threshold'] = array( array( static fn() => 20, 1 ) );
$GLOBALS['vcap_test_filters']['vcap_bundle_pdf_limit'] = array( array( static fn() => 0, 1 ) );
check( 'Z6: setting 0 switches bundling off, 1 means 2; the filters "vcap_bundle_threshold" and "vcap_bundle_pdf_limit" decide', 2 === $z6_one && 0 === $z6_off && 20 === Delivery::bundle_threshold() && 0 === Delivery::bundle_pdf_limit() );
unset( $GLOBALS['vcap_test_filters']['vcap_bundle_threshold'], $GLOBALS['vcap_test_filters']['vcap_bundle_pdf_limit'] );
$z6_a                  = clone $v;
$z6_a->order_item_id   = 55;
$z6_a->send_to         = 'recipient';
$z6_a->buyer_email     = 'buyer@example.com';
$z6_a->recipient_email = 'Lea@Example.com';
$z6_b                  = clone $z6_a;
$z6_b->recipient_email = 'lea@example.com';
$z6_c                  = clone $z6_a;
$z6_c->recipient_email = 'max@example.com';
$z6_d                  = clone $z6_a;
$z6_d->order_item_id   = 56;
check( 'Z6: vouchers share a bundle only with the same order line and recipient address (case ignored)', Delivery::bundle_key( $z6_a ) === Delivery::bundle_key( $z6_b ) && Delivery::bundle_key( $z6_a ) !== Delivery::bundle_key( $z6_c ) && Delivery::bundle_key( $z6_a ) !== Delivery::bundle_key( $z6_d ) );
$z6_mail = (string) file_get_contents( VCAP_PATH . 'templates/emails/voucher-bundle.php' );
check( 'Z6: the bundled email has its own HTML and plain template with a code table and download links', str_contains( $z6_mail, '$download_urls[ $vcap_v->id ]' ) && str_contains( $z6_mail, "woocommerce_email_header" ) && str_contains( (string) file_get_contents( VCAP_PATH . 'templates/emails/plain/voucher-bundle.php' ), '$download_urls[ $vcap_v->id ]' ) );

echo "PDF temp files, templates and designer (P8)\n";
$p8_design = array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 10, 'text' => '{coupon_code}' ) ) );
// Q14: a parallel delivery with the same file name writes its PDF while this one renders.
$p8_target = VCAP\Install::upload_dir() . '/tmp/' . vcap()->pdf->filename( $v );
@unlink( $p8_target );
$GLOBALS['vcap_test_filters']['vcap_pdf_html'] = array(
	array(
		static function ( $html ) use ( $p8_target ) {
			if ( ! file_exists( $p8_target ) ) {
				file_put_contents( $p8_target, 'OTHER VOUCHER' );
			}
			return $html;
		},
		1,
	),
);
$p8_file = vcap()->pdf->to_temp_file( $v, $p8_design );
unset( $GLOBALS['vcap_test_filters']['vcap_pdf_html'] );
check( 'Q14: a temp PDF never overwrites or shares the file of a parallel delivery with the same name', is_string( $p8_file ) && $p8_file !== $p8_target && 'OTHER VOUCHER' === file_get_contents( $p8_target ) && str_starts_with( (string) file_get_contents( $p8_file ), '%PDF-' ) && str_ends_with( $p8_file, '-' . basename( $p8_target ) ), (string) $p8_file );
@unlink( $p8_target );
@unlink( (string) $p8_file );
$p8_file = vcap()->pdf->to_temp_file( $v, $p8_design );
check( 'M12-43: a temp PDF always has a random 24-character prefix and is attached under the configured name', $p8_file !== $p8_target && (bool) preg_match( '/^[A-Za-z0-9]{24}-' . preg_quote( basename( $p8_target ), '/' ) . '$/', basename( (string) $p8_file ) ) && basename( $p8_target ) === PdfRenderer::attachment_name( (string) $p8_file ), (string) $p8_file );
@unlink( (string) $p8_file );

// Z11: QR codes and barcodes are vector SVG (no GD) and the printed QR modules are those of the payload.
$p8_payload = 'https://shop.test/redeem/?vcap_code=ab12-Cd34';
$p8_qr      = array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'qrcode', 'x' => 10, 'y' => 10, 'w' => 60, 'h' => 60, 'qrContent' => 'custom', 'qrValue' => $p8_payload, 'color' => '#000000' ) ) );
$p8_html    = vcap()->pdf->html( $v, $p8_qr );
check( 'Z11: the QR code is an SVG image, not a GD PNG', str_contains( $p8_html, 'data:image/svg+xml;base64,' ) && ! str_contains( $p8_html, 'image/png' ) );
file_put_contents( "$out/p8-qr.pdf", vcap()->pdf->render( $v, $p8_qr ) );
shell_exec( 'pdftoppm -png -r 300 -singlefile ' . escapeshellarg( "$out/p8-qr.pdf" ) . ' ' . escapeshellarg( "$out/p8-qr" ) . ' 2>/dev/null' );
if ( is_readable( "$out/p8-qr.png" ) ) {
	$p8_ref = new chillerlan\QRCode\QRCode( new chillerlan\QRCode\QROptions( array( 'eccLevel' => chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true, 'quietzoneSize' => 4 ) ) );
	foreach ( chillerlan\QRCode\Common\Mode::INTERFACES as $p8_mode ) {
		if ( $p8_mode::validateString( $p8_payload ) ) {
			$p8_ref->addSegment( new $p8_mode( $p8_payload ) );
			break;
		}
	}
	$p8_grid  = $p8_ref->getQRMatrix()->getMatrix( true );
	$p8_n     = count( $p8_grid );
	$p8_img   = imagecreatefrompng( "$out/p8-qr.png" );
	$p8_px    = 300 / 25.4;
	$p8_wrong = 0;
	foreach ( $p8_grid as $p8_y => $p8_row ) {
		foreach ( $p8_row as $p8_x => $p8_dark ) {
			$p8_rgb   = imagecolorat( $p8_img, (int) round( ( 10 + ( $p8_x + 0.5 ) * 60 / $p8_n ) * $p8_px ), (int) round( ( 10 + ( $p8_y + 0.5 ) * 60 / $p8_n ) * $p8_px ) );
			$p8_lum   = ( ( $p8_rgb >> 16 ) & 255 ) + ( ( $p8_rgb >> 8 ) & 255 ) + ( $p8_rgb & 255 );
			$p8_wrong += ( $p8_lum < 384 ) !== $p8_dark ? 1 : 0;
		}
	}
	check( "Z11: every printed QR module matches the payload's QR matrix ({$p8_n}×{$p8_n})", 0 === $p8_wrong, "$p8_wrong wrong modules" );
}
$p8_bc = static fn( string $type, string $value ) => vcap()->pdf->html( $v, array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'barcode', 'x' => 5, 'y' => 5, 'w' => 60, 'h' => 15, 'barcodeType' => $type, 'qrValue' => $value ) ) ) );
check( 'Z11: barcodes are SVG images, not GD PNGs', str_contains( $p8_bc( 'C128', '{coupon_code}' ), 'data:image/svg+xml;base64,' ) && ! str_contains( $p8_bc( 'C128', '{coupon_code}' ), 'image/png' ) );
check( 'Z11: an EAN-13 barcode of a code that is no EAN (GIFT-ABCD-1234) is printed as Code 128, never left empty', str_contains( $p8_bc( 'EAN13', '{coupon_code}' ), 'data:image/svg+xml;base64,' ) && str_contains( $p8_bc( 'EAN13', '{coupon_code}' ), 'GIFT-ABCD-1234' ) );
check( 'Z11: EAN-13 takes 12 digits or 13 with a valid check digit, Code 39 no lower case', PdfRenderer::barcode_fits( 'EAN13', '400638133393' ) && PdfRenderer::barcode_fits( 'EAN13', '4006381333931' ) && ! PdfRenderer::barcode_fits( 'EAN13', '4006381333932' ) && ! PdfRenderer::barcode_fits( 'EAN13', '1234567890123456' ) && ! PdfRenderer::barcode_fits( 'C39', 'gift-1' ) && PdfRenderer::barcode_fits( 'C39', 'GIFT-1' ) );
$p8_clean = TemplatePostType::sanitize_design( array( 'elements' => array( array( 'type' => 'barcode', 'barcodeType' => 'EAN13', 'qrValue' => 'SHOP-1' ), array( 'type' => 'barcode', 'barcodeType' => 'EAN13', 'qrValue' => '400638133393' ), array( 'type' => 'barcode', 'barcodeType' => 'EAN13', 'qrValue' => '{product_sku}' ) ) ) );
check( 'Z11: saving switches a fixed value that EAN-13 cannot carry to Code 128 and keeps fitting values and placeholders', 'C128' === $p8_clean['elements'][0]['barcodeType'] && 'EAN13' === $p8_clean['elements'][1]['barcodeType'] && 'EAN13' === $p8_clean['elements'][2]['barcodeType'] );

// Z14: a custom font (vcap_pdf_fonts) is measured with the render options, not with a bare dompdf that does not know it.
$p8_font = $GLOBALS['vcap_test_uploads'] . '/fonts-test/TestMono.ttf';
@mkdir( dirname( $p8_font ), 0777, true );
copy( VCAP_PATH . 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf', $p8_font );
$GLOBALS['vcap_test_filters']['vcap_pdf_fonts'] = array( array( static fn( $fonts ) => array( 'Test Mono' => array( 'normal' => $p8_font ) ) + (array) $fonts, 1 ) );
$p8_ratio = PdfRenderer::font_height_ratio( 'Test Mono', false, false );
check( 'Z14: a custom font gets its own line-height ratio (same as the TTF it is, not 1.0)', abs( $p8_ratio - PdfRenderer::font_height_ratio( 'DejaVu Sans Mono', false, false ) ) < 0.001 && abs( $p8_ratio - 1.0 ) > 0.05, (string) $p8_ratio );
file_put_contents( "$out/p8-font.pdf", vcap()->pdf->render( $v, array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 10, 'text' => 'Custom {coupon_code}', 'font' => 'Test Mono' ) ) ) ) );
$p8_fonts = (string) shell_exec( 'pdffonts ' . escapeshellarg( "$out/p8-font.pdf" ) . ' 2>/dev/null' );
if ( '' !== $p8_fonts ) {
	check( 'Z14: the custom font is embedded in the PDF', str_contains( $p8_fonts, 'DejaVuSansMono' ), $p8_fonts );
}
unset( $GLOBALS['vcap_test_filters']['vcap_pdf_fonts'] );
$p8_css = TemplatePostType::designer_font_css();
check( 'Z14: the designer loads the DejaVu fonts dompdf prints with as web fonts (all 12 styles)', 12 === substr_count( $p8_css, '@font-face' ) && str_contains( $p8_css, "font-family:'DejaVu Serif';font-weight:normal;font-style:italic;src:url('https://shop.test/wp-content/plugins/voucher-captain/vendor/dompdf/dompdf/lib/fonts/DejaVuSerif-Italic.ttf')" ), $p8_css );
// H16: a long message and a long code are set smaller until they fit, the last words are printed.
$p8_long          = clone $v;
$p8_long->message = trim( str_repeat( 'Liebe Anna, wir wünschen dir alles Gute, viel Freude und einen wunderbaren Tag mit allen, die du gern hast. ', 5 ) ) . "\nHerzlich, deine Familie ENDGRUSS";
$p8_long->code    = 'GIFT-K7QM2X9ZPA-ABCDEFGHJKLMNPQR-2026';
file_put_contents( "$out/p8-long.pdf", vcap()->pdf->render( $p8_long, TemplatePostType::default_designs()['giftcard'] ) );
$p8_text = preg_replace( '/\s+/', ' ', (string) shell_exec( 'pdftotext -raw ' . escapeshellarg( "$out/p8-long.pdf" ) . ' - 2>/dev/null' ) );
if ( '' !== trim( (string) $p8_text ) ) {
	// pdftotext also extracts clipped text: the word's position must lie inside the message box (y 56–86 mm).
	$p8_bbox = (string) shell_exec( 'pdftotext -bbox ' . escapeshellarg( "$out/p8-long.pdf" ) . ' - 2>/dev/null' );
	$p8_end  = preg_match( '/yMax="([\d.]+)">ENDGRUSS</', $p8_bbox, $p8_m ) ? (float) $p8_m[1] : 999.0;
	check( 'H16: the last line of a long message is printed inside its box, not cut off', str_contains( (string) $p8_text, 'deine Familie ENDGRUSS' ) && $p8_end <= 86 * PdfRenderer::MM_TO_PT, 'yMax ' . $p8_end . ' pt' );
	check( 'H16: a long code is printed whole on one line', str_contains( (string) $p8_text, 'GIFT-K7QM2X9ZPA-ABCDEFGHJKLMNPQR-2026' ), (string) $p8_text );
}
$p8_fit = PdfRenderer::fit_size( 'Kurz', 'DejaVu Serif', 11, 1.25, false, true, 0, 120, 30 );
$p8_cut = PdfRenderer::fit_size( str_repeat( 'Wort ', 2000 ), 'DejaVu Serif', 11, 1.25, false, true, 0, 120, 30 );
check( 'H16: a text that fits keeps its size; one that cannot fit stops at half the size and says so', array( 'size' => 11.0, 'fits' => true ) === $p8_fit && 5.5 === $p8_cut['size'] && false === $p8_cut['fits'], json_encode( array( $p8_fit, $p8_cut ) ) );
check( 'H16: the template preview shows a worst-case message as long as products allow by default', 300 === mb_strlen( TemplatePostType::preview_voucher()->message ) );
// M8: characters without a glyph are found before the PDF prints boxes; emoji are left out; RTL text is reported.
$p8_missing = PdfRenderer::unsupported_chars( 'Jörg 张伟 – Alles Gute 🎉 ✓ €' );
check( 'M8: Chinese letters and emoji have no glyph in the PDF fonts, umlauts, ✓ and € do', array( '张', '伟', '🎉' ) === $p8_missing, json_encode( $p8_missing, JSON_UNESCAPED_UNICODE ) );
$p8_emoji          = clone $v;
$p8_emoji->message = "Alles Gute 🎉🎁 und 👍🏽 viel Spaß ❤️\nDein Team ✓";
$p8_html           = vcap()->pdf->html( $p8_emoji, array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 30, 'text' => '{message}' ) ) ) );
check( 'M8: emoji without a glyph are left out of the PDF instead of printing empty boxes, the rest of the text stays', str_contains( $p8_html, 'Alles Gute und viel Spaß' ) && str_contains( $p8_html, 'Dein Team ✓' ) && ! preg_match( '/[\x{1F300}-\x{1FAFF}\x{FE0F}\x{200D}]/u', $p8_html ), $p8_html );
check( 'M8: the customer is warned about characters the PDF cannot print and about right-to-left text', str_contains( PdfRenderer::print_warning( 'Message', 'Für 张伟 🎉' ), '张 伟 🎉' ) && str_contains( PdfRenderer::print_warning( 'Message', 'שלום' ), 'right-to-left' ) && '' === PdfRenderer::print_warning( 'Message', 'Jörg Müller ✓' ) );
check( 'M8: the add-to-cart validation shows the print warnings as notices', str_contains( (string) file_get_contents( VCAP_PATH . 'src/Fields.php' ), "foreach ( \$this->print_warnings( \$product ) as \$message ) {\n\t\t\t\twc_add_notice( \$message, 'notice' )" ) );
$GLOBALS['vcap_test_filters']['vcap_pdf_fonts']          = array( array( static fn( $fonts ) => array( 'Test Mono' => array( 'normal' => $p8_font ) ), 1 ) );
$GLOBALS['vcap_test_filters']['vcap_pdf_fallback_fonts'] = array( array( static fn() => array( 'Test Mono', 'Not Registered' ), 1 ) );
check( 'M8: fallback fonts (vcap_pdf_fallback_fonts) follow DejaVu Sans in every font stack, unregistered families are ignored', "'DejaVu Serif', 'DejaVu Sans', 'Test Mono'" === PdfRenderer::font_stack( 'DejaVu Serif' ), PdfRenderer::font_stack( 'DejaVu Serif' ) );
unset( $GLOBALS['vcap_test_filters']['vcap_pdf_fonts'], $GLOBALS['vcap_test_filters']['vcap_pdf_fallback_fonts'] );
check( 'M8: COMPATIBILITY.md documents fallback fonts and the right-to-left limit', str_contains( (string) file_get_contents( VCAP_PATH . 'COMPATIBILITY.md' ), 'vcap_pdf_fallback_fonts' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'COMPATIBILITY.md' ), 'no bidirectional layout' ) );
// Z10: image types dompdf cannot draw (AVIF, HEIC) are left out, refused in the designer and reported.
$p8_avif = $GLOBALS['vcap_test_uploads'] . '/2026/11/photo.avif';
file_put_contents( $p8_avif, "\0\0\0\x1cftypavif\0\0\0\0avifmif1miaf" . str_repeat( "\0", 64 ) );
$GLOBALS['vcap_test_attachments'][79] = $p8_avif;
$p8_img_design = array( 'page' => array( 'format' => 'A6', 'backgroundImageId' => 79 ), 'elements' => array( array( 'id' => 'i1', 'type' => 'image', 'name' => 'Photo', 'imageId' => 79, 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 50 ) ) );
check( 'Z10: an AVIF image is left out of the PDF instead of printing a broken-image frame', null === vcap()->pdf->image_path( 79, '' ) && ! str_contains( vcap()->pdf->html( $v, $p8_img_design ), '<img' ) && $png === vcap()->pdf->image_path( 77, '' ) );
check( 'Z10: only JPEG, PNG, GIF (and WebP with GD support) can be chosen', array_slice( PdfRenderer::image_mimes(), 0, 3 ) === array( 'image/jpeg', 'image/png', 'image/gif' ) && ! in_array( 'image/avif', PdfRenderer::image_mimes(), true ) && str_contains( (string) file_get_contents( VCAP_PATH . 'assets/js/designer.js' ), 'library: { type: mimes }' ) );
$p8_warn = TemplatePostType::design_warnings( $p8_img_design );
// getimagesize() names AVIF as image/avif on PHP 8.1; newer versions refuse the stub file, then the extension is shown.
check( 'Z10: the designer warns about the AVIF background and image', 2 === count( $p8_warn ) && (bool) preg_match( '~Image "Photo" is of type (image/)?avif,~', $p8_warn[1] ), json_encode( $p8_warn ) );
// C7: missing local files are reported; images of allowed hosts (offloaded media / CDN) are downloaded.
$GLOBALS['vcap_test_attachments'][80] = $GLOBALS['vcap_test_uploads'] . '/2026/11/offloaded.png';
$p8_warn = TemplatePostType::design_warnings( array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'image', 'name' => 'Logo', 'imageId' => 80, 'src' => 'https://cdn.shop.test/2026/11/offloaded.png' ) ) ) );
check( 'C7: an image without a local file is reported in the designer and Site Health', 1 === count( $p8_warn ) && str_contains( $p8_warn[0], 'Image "Logo" cannot be read' ), json_encode( $p8_warn ) );
$GLOBALS['vcap_test_http_log'] = array();
$GLOBALS['vcap_test_http']     = static fn( $url ) => array( 'code' => 200, 'body' => (string) file_get_contents( $png ), 'headers' => array() );
check( 'C7: without an allowed host nothing is downloaded', null === vcap()->pdf->image_path( 80, 'https://cdn.shop.test/2026/11/offloaded.png' ) && array() === $GLOBALS['vcap_test_http_log'] );
$GLOBALS['vcap_test_filters']['vcap_pdf_remote_hosts'] = array( array( static fn() => array( 'cdn.shop.test' ), 1 ) );
$p8_remote = vcap()->pdf->image_path( 80, 'https://cdn.shop.test/2026/11/offloaded.png' );
check( 'C7: an offloaded image is downloaded from an allowed host into the private temp folder and printed', is_string( $p8_remote ) && str_starts_with( $p8_remote, VCAP\Install::upload_dir() . '/tmp/' ) && file_get_contents( $p8_remote ) === file_get_contents( $png ) && array( 'https://cdn.shop.test/2026/11/offloaded.png' ) === $GLOBALS['vcap_test_http_log'] );
$GLOBALS['vcap_test_http_log'] = array();
check( 'C7: the download is reused (no second request) and other hosts stay blocked', $p8_remote === vcap()->pdf->image_path( 80, 'https://cdn.shop.test/2026/11/offloaded.png' ) && null === vcap()->pdf->image_path( 0, 'https://evil.test/x.png' ) && array() === $GLOBALS['vcap_test_http_log'] );
$GLOBALS['vcap_test_http'] = static fn( $url ) => array( 'code' => 200, 'body' => '<html>not an image</html>', 'headers' => array() );
check( 'C7: a download that is no image is not used', null === vcap()->pdf->image_path( 0, 'https://cdn.shop.test/other.png' ) );
unset( $GLOBALS['vcap_test_filters']['vcap_pdf_remote_hosts'], $GLOBALS['vcap_test_http'] );
@unlink( (string) $p8_remote );
check( 'C7: Site Health checks the template images', method_exists( VCAP\Health::class, 'test_template_images' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Health.php' ), "'rate_limits', 'template_images', " ) );
echo "M12 PDF engine, templates and wallet links (F1)\n";
// M12-39: the customer's texts are measured as the PDF sets them; too long → error with the lines the box holds.
$m12_gift          = TemplatePostType::default_designs()['giftcard'];
$m12_fit           = clone $v;
$m12_fit->message  = 'Alles Gute!';
$m12_report        = PdfRenderer::fit_report( $m12_gift, $m12_fit );
$m12_msg_row       = array_values( array_filter( $m12_report, static fn( $r ) => in_array( 'message', $r['tokens'], true ) ) )[0] ?? null;
check( 'M12-39: the fit report covers the elements with {message} and {recipient_name}; a short message keeps its size', null !== $m12_msg_row && true === $m12_msg_row['fits'] && 1.0 === (float) $m12_msg_row['scale'] && in_array( array( 'recipient_name' ), array_map( static fn( $r ) => $r['tokens'], $m12_report ), true ), wp_json_encode( $m12_report ) );
$m12_fit->message  = str_repeat( "Zeile\n", 40 );
$m12_cut           = array_values( array_filter( PdfRenderer::fit_report( $m12_gift, $m12_fit ), static fn( $r ) => in_array( 'message', $r['tokens'], true ) ) )[0] ?? array();
check( 'M12-39: 40 short lines do not fit the message box: reported with the number of lines it holds at half the size', false === ( $m12_cut['fits'] ?? null ) && ( $m12_cut['max_lines'] ?? 0 ) >= 8 && ( $m12_cut['max_lines'] ?? 99 ) <= 20, wp_json_encode( $m12_cut ) );
$m12_fit->message  = str_repeat( "Zeile\n", 8 ) . 'Ende';
$m12_small         = array_values( array_filter( PdfRenderer::fit_report( $m12_gift, $m12_fit ), static fn( $r ) => in_array( 'message', $r['tokens'], true ) ) )[0] ?? array();
check( 'M12-39: nine lines fit only below 75 % of the size (notice)', true === ( $m12_small['fits'] ?? null ) && ( $m12_small['scale'] ?? 1 ) < PdfRenderer::SHRINK_NOTICE, wp_json_encode( $m12_small ) );
$m12_fit->message  = str_repeat( "Zeile\n", 40 );
$m12_banner        = VCAP\Preview::with_fit_banner( $m12_gift, $m12_fit );
$m12_note          = end( $m12_banner['elements'] );
check( 'M12-39: the customer preview shows a red note "Text too long … max. approx. N lines"', 'vcap-fit-note' === ( $m12_note['id'] ?? '' ) && str_contains( (string) $m12_note['text'], 'max. approx.' ) && count( $m12_banner['elements'] ) === count( $m12_gift['elements'] ) + 1 );
$m12_fit->message  = 'Kurz';
check( 'M12-39: no note when every text fits at full size', VCAP\Preview::with_fit_banner( $m12_gift, $m12_fit ) === $m12_gift );
$m12_lines = TemplatePostType::preview_voucher( 'lines' )->message;
check( 'M12-39: the template preview has a worst-case variant with many line breaks', TemplatePostType::PREVIEW_LINES === count( explode( "\n", $m12_lines ) ) && 300 === mb_strlen( TemplatePostType::preview_voucher()->message ) && str_contains( (string) file_get_contents( VCAP_PATH . 'assets/js/designer.js' ), "name: 'variant'" ) );
check( 'M12-39: the product option "Message max. lines" exists (default 0 = no limit) and is validated', 0 === ProductConfig::DEFAULTS['message_maxlines'] && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Fields.php' ), "may contain at most %2\$d lines." ) );

// M12-40: QR codes and barcodes on white with quiet zones, opacity ignored, readability warnings.
$m12_codes = vcap()->pdf->html( $v, array( 'page' => array( 'format' => 'A6', 'background' => '#1f3a4d' ), 'elements' => array( array( 'type' => 'qrcode', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 30, 'qrContent' => 'code', 'opacity' => 0.3 ), array( 'type' => 'barcode', 'x' => 10, 'y' => 60, 'w' => 80, 'h' => 20, 'opacity' => 0.4 ) ) ) );
preg_match_all( '/<div class="el" style="([^"]*)"><img src="data:image\/svg/', $m12_codes, $m12_code_styles );
check( 'M12-40: the opacity of QR codes and barcodes is ignored, both lie on white', 2 === count( $m12_code_styles[1] ) && ! str_contains( implode( '', $m12_code_styles[1] ), 'opacity' ) && str_contains( $m12_codes, 'background:#ffffff;" /></div>' ) && str_contains( $m12_code_styles[1][1], 'background:#ffffff' ), $m12_codes );
preg_match( '/<img src="data:image\/svg\+xml;base64,[^"]+" style="width:([\d.]+)mm;height:[\d.]+mm;margin-left:([\d.]+)mm;"/', $m12_codes, $m12_bar );
$m12_modules = PdfRenderer::barcode_modules( 'C128', $v->code );
check( 'M12-40: a barcode keeps 10 modules of white left and right', isset( $m12_bar[2] ) && abs( (float) $m12_bar[2] - 80 * 10 / $m12_modules ) < 0.01 && abs( (float) $m12_bar[1] - ( 80 - 2 * (float) $m12_bar[2] ) ) < 0.01, wp_json_encode( array( $m12_bar, $m12_modules ) ) );
$m12_qr_svg = preg_match( '/<img src="data:image\/svg\+xml;base64,([^"]+)" style="width:30mm/', $m12_codes, $m12_q ) ? (string) base64_decode( $m12_q[1] ) : '';
check( 'M12-40: the light QR modules are drawn in white (no dark ground shows through)', str_contains( $m12_qr_svg, '#fff' ), substr( $m12_qr_svg, 0, 300 ) );
$m12_warn = TemplatePostType::design_warnings(
	array(
		'page'     => array( 'format' => 'A6' ),
		'elements' => array(
			array( 'id' => 'q', 'name' => 'Tiny QR', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 12, 'qrContent' => 'download' ),
			array( 'id' => 'b', 'name' => 'Narrow bars', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'w' => 25, 'h' => 10 ),
			array( 'id' => 'p', 'name' => 'Pale QR', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 40, 'color' => '#aaaaaa' ),
		),
	)
);
check( 'M12-40: the designer warns about QR modules under 0.33 mm, bar modules under 0.19 mm and contrast under 4.5:1', 3 === count( $m12_warn ) && str_contains( $m12_warn[0], 'Tiny QR' ) && str_contains( $m12_warn[0], '0.33' ) && str_contains( $m12_warn[1], 'Narrow bars' ) && str_contains( $m12_warn[1], '0.19' ) && str_contains( $m12_warn[2], 'Pale QR' ) && str_contains( $m12_warn[2], '4.5:1' ), wp_json_encode( $m12_warn ) );
check( 'M12-40: the starter designs pass the readability checks', array() === TemplatePostType::design_warnings( TemplatePostType::default_designs()['giftcard'] ) && array() === TemplatePostType::design_warnings( TemplatePostType::default_designs()['ticket'] ) );
check( 'M12-40: contrast ratio as WCAG defines it', 21.0 === PdfRenderer::contrast_ratio( '#000', '#ffffff' ) && 1.0 === PdfRenderer::contrast_ratio( '#fff', '#ffffff' ) && PdfRenderer::contrast_ratio( '#777777', '#ffffff' ) < 4.5 && PdfRenderer::contrast_ratio( '#767676', '#ffffff' ) >= 4.5 );
check( 'M12-40/41: the designer explains the browser scanner (QR only on iOS/Firefox) and the lifetime of the printed download link', str_contains( (string) file_get_contents( VCAP_PATH . 'assets/js/designer.js' ), 't.barcodeScanner' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'assets/js/designer.js' ), 't.qrDownloadHint' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/TemplatePostType.php' ), "'barcodeScanner'" ) );

// M12-43: private folder with a random name, Apache 2.4 protection, temp files cleaned up after an hour.
$m12_base = $GLOBALS['vcap_test_uploads'];
unset( $GLOBALS['vcap_test_options'][ VCAP\Install::PRIVATE_DIR_OPTION ] );
@mkdir( $m12_base . '/vcap', 0777, true );
file_put_contents( $m12_base . '/vcap/keep.txt', 'old' );
$m12_name = VCAP\Install::private_dir_name();
check( 'M12-43: the private folder gets a random name; the old folder "vcap" is renamed with its files', (bool) preg_match( '/^vcap-[a-z0-9]{20}$/', $m12_name ) && ! is_dir( $m12_base . '/vcap' ) && 'old' === @file_get_contents( $m12_base . '/' . $m12_name . '/keep.txt' ) && VCAP\Install::upload_dir() === $m12_base . '/' . $m12_name, $m12_name );
VCAP\Install::ensure_upload_dir();
check( 'M12-43: the .htaccess files use the Apache 2.4 syntax (with a 2.2 fallback)', str_contains( (string) file_get_contents( VCAP\Install::upload_dir() . '/tmp/.htaccess' ), 'Require all denied' ) && str_contains( (string) file_get_contents( VCAP\Install::upload_dir() . '/.htaccess' ), 'Deny from all' ) );
$m12_dir  = VCAP\Install::upload_dir();
$m12_old  = time() - 2 * HOUR_IN_SECONDS;
$m12_junk = array( '/tmp/abc-voucher.pdf', '/tmp/img-0123.img', '/tmp/img-0123.img.Xy12.part', '/fonts/ca_dompdf_img_AbCd', '/img/x.png.Ab.part' );
foreach ( $m12_junk as $m12_f ) {
	file_put_contents( $m12_dir . $m12_f, 'x' );
	touch( $m12_dir . $m12_f, $m12_old );
}
file_put_contents( $m12_dir . '/tmp/fresh.pdf', 'x' );
file_put_contents( $m12_dir . '/fonts/DejaVuSans.ufm', 'x' );
touch( $m12_dir . '/fonts/DejaVuSans.ufm', $m12_old );
$m12_purged = VCAP\Install::purge_temp_files();
check( 'M12-43: the maintenance removes temp PDFs, img-*, *.part and dompdf temp images older than an hour, nothing else', 5 === $m12_purged && ! array_filter( $m12_junk, static fn( $f ) => file_exists( $m12_dir . $f ) ) && file_exists( $m12_dir . '/tmp/fresh.pdf' ) && file_exists( $m12_dir . '/fonts/DejaVuSans.ufm' ), (string) $m12_purged );
@unlink( $m12_dir . '/tmp/fresh.pdf' );
@unlink( $m12_dir . '/fonts/DejaVuSans.ufm' );
check( 'M12-92: COMPATIBILITY.md explains what WooCommerce Analytics shows', str_contains( (string) file_get_contents( VCAP_PATH . 'COMPATIBILITY.md' ), '## WooCommerce Analytics' ) );
check( 'M12-43: COMPATIBILITY.md has the nginx snippet; Site Health requests a test file', str_contains( (string) file_get_contents( VCAP_PATH . 'COMPATIBILITY.md' ), 'location ~* ^/wp-content/uploads/vcap' ) && method_exists( VCAP\Health::class, 'probe_private_dir' ) );

// M12-72: subdivision flags and keycaps leave no tag characters or boxes.
$m12_emoji = PdfRenderer::strip_unprintable_emoji( "Für Schottland 🏴\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F} und 1\u{FE0F}\u{20E3} ok" );
check( 'M12-72: subdivision flags (tag characters) and keycap emoji are left out', ! preg_match( '/[\x{E0000}-\x{E007F}\x{FE0F}\x{1F3F4}]/u', $m12_emoji ) && str_contains( $m12_emoji, 'Für Schottland' ) && str_contains( $m12_emoji, 'ok' ), $m12_emoji );

// M12-73: large PNGs are reduced once and cached, opaque ones as JPEG; previews are cached for 10 minutes.
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$m12_png = $m12_base . '/2026/11/big-opaque.png';
	$m12_im  = imagecreatetruecolor( 2400, 1700 );
	for ( $m12_i = 0; $m12_i < 3000; $m12_i++ ) {
		imagefilledellipse( $m12_im, random_int( 0, 2400 ), random_int( 0, 1700 ), 60, 60, (int) imagecolorallocate( $m12_im, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) ) );
	}
	imagepng( $m12_im, $m12_png );
	$m12_alpha = $m12_base . '/2026/11/big-alpha.png';
	$m12_ia    = imagecreatetruecolor( 3000, 2000 );
	imagealphablending( $m12_ia, false );
	imagesavealpha( $m12_ia, true );
	imagefill( $m12_ia, 0, 0, (int) imagecolorallocatealpha( $m12_ia, 255, 0, 0, 80 ) );
	imagepng( $m12_ia, $m12_alpha );
	$m12_o  = PdfRenderer::optimized_image( $m12_png, 105, 74 );
	$m12_a  = PdfRenderer::optimized_image( $m12_alpha, 105, 74 );
	$m12_ai = @getimagesize( $m12_a );
	check( 'M12-73: an opaque PNG is printed from a cached JPEG, a large transparent PNG from a reduced PNG', str_ends_with( $m12_o, '.jpg' ) && 'image/jpeg' === ( getimagesize( $m12_o )['mime'] ?? '' ) && str_starts_with( $m12_o, VCAP\Install::upload_dir() . '/img/' ) && str_ends_with( $m12_a, '.png' ) && is_array( $m12_ai ) && $m12_ai[0] < 3000 && PdfRenderer::png_has_alpha( $m12_a ), wp_json_encode( array( $m12_o, $m12_a, $m12_ai ) ) );
	$m12_mtime = filemtime( $m12_o );
	check( 'M12-73: the reduced copy is made once and reused; a small PNG is used as it is', $m12_o === PdfRenderer::optimized_image( $m12_png, 105, 74 ) && filemtime( $m12_o ) === $m12_mtime && $png === PdfRenderer::optimized_image( $png, 105, 74 ) );
	$m12_bg = vcap()->pdf->html( $v, array( 'page' => array( 'format' => 'A6', 'backgroundImage' => 'https://shop.test/wp-content/uploads/2026/11/big-alpha.png' ), 'elements' => array() ) );
	check( 'M12-73: the PDF embeds a reduced cached copy, not the original', (bool) preg_match( '#' . preg_quote( VCAP\Install::upload_dir() . '/img/', '#' ) . '[0-9a-f]{32}\.png#', $m12_bg ) && ! str_contains( $m12_bg, $m12_alpha ), $m12_bg );
}
$m12_pv_a          = clone $v;
$m12_pv_b          = clone $v;
$m12_pv_b->message = 'Anders';
check( 'M12-73: the preview cache key follows design, fields and amount', VCAP\Preview::cache_file( $m12_gift, $m12_pv_a ) === VCAP\Preview::cache_file( $m12_gift, clone $v ) && VCAP\Preview::cache_file( $m12_gift, $m12_pv_a ) !== VCAP\Preview::cache_file( $m12_gift, $m12_pv_b ) && 600 === VCAP\Preview::CACHE_SECONDS );

// M12-74: voucher links with badge images and the placeholder {link:<id>}.
$GLOBALS['vcap_test_filters']['vcap_voucher_links'] = array(
	array(
		static fn( $links ) => array(
			array( 'id' => 'Wallet', 'label' => 'Add to Wallet', 'url' => 'https://wallet.example/p/1', 'image' => 'https://wallet.example/badge.svg', 'width' => 9999, 'alt' => '<b>Apple</b> Wallet' ),
			array( 'id' => 'g', 'label' => 'Google', 'url' => 'https://pay.example/g', 'image' => 'http://insecure.example/b.png' ),
		),
		1,
	),
);
$m12_links = VCAP\Downloads::voucher_links( $v, 'email' );
check( 'M12-74: voucher links keep an id, an https badge image (width 16–400) and an alt text; http images are dropped', 'wallet' === ( $m12_links[0]['id'] ?? '' ) && 'https://wallet.example/badge.svg' === ( $m12_links[0]['image'] ?? '' ) && 400 === ( $m12_links[0]['width'] ?? 0 ) && 'Apple Wallet' === ( $m12_links[0]['alt'] ?? '' ) && ! isset( $m12_links[1]['image'] ), wp_json_encode( $m12_links ) );
$m12_links_html = VCAP\Downloads::voucher_links_html( $m12_links );
check( 'M12-74: badges are printed as escaped image links, other links as text', str_contains( $m12_links_html, '<img src="https://wallet.example/badge.svg" width="400" alt="Apple Wallet"' ) && str_contains( $m12_links_html, '>Google</a>' ), $m12_links_html );
check( 'M12-74: {link:wallet} prints the URL of that link', 'Pass: https://wallet.example/p/1' === Placeholders::replace( 'Pass: {link:wallet}', $v ) );
unset( $GLOBALS['vcap_test_filters']['vcap_voucher_links'] );
check( 'M12-74: both bundle templates print the voucher links per row', str_contains( (string) file_get_contents( VCAP_PATH . 'templates/emails/voucher-bundle.php' ), "voucher_links( \$vcap_v, 'email' )" ) && str_contains( (string) file_get_contents( VCAP_PATH . 'templates/emails/plain/voucher-bundle.php' ), "voucher_links( \$vcap_v, 'email_plain' )" ) );

// M12-75: bleed, crop marks, N-up on A4.
$m12_bleed_design = array(
	'page'     => array( 'format' => 'A6', 'bleed' => 3, 'cropMarks' => true, 'background' => '#123456' ),
	'elements' => array(
		array( 'id' => 'band', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 105, 'h' => 20, 'fill' => '#ff0000' ),
		array( 'id' => 'txt', 'type' => 'text', 'x' => 10, 'y' => 30, 'w' => 50, 'h' => 10, 'text' => 'Hallo' ),
	),
);
check( 'M12-75: the sheet is the page plus the bleed on every side', array( 111.0, 154.0 ) === PdfRenderer::sheet_size( $m12_bleed_design['page'] ) && array( 105, 148 ) === PdfRenderer::page_size( $m12_bleed_design['page'] ) );
$m12_band = PdfRenderer::bleed_element( $m12_bleed_design['elements'][0], 105, 148, 3 );
$m12_txt  = PdfRenderer::bleed_element( $m12_bleed_design['elements'][1], 105, 148, 3 );
check( 'M12-75: a shape at the edge reaches into the bleed, a text only moves', 0.0 === (float) $m12_band['x'] && 0.0 === (float) $m12_band['y'] && 111.0 === (float) $m12_band['w'] && 23.0 === (float) $m12_band['h'] && 13.0 === (float) $m12_txt['x'] && 33.0 === (float) $m12_txt['y'] && 50.0 === (float) $m12_txt['w'], wp_json_encode( array( $m12_band, $m12_txt ) ) );
file_put_contents( "$out/m12-bleed.pdf", vcap()->pdf->render( $v, $m12_bleed_design ) );
$m12_info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( "$out/m12-bleed.pdf" ) . ' 2>/dev/null' );
if ( preg_match( '/Page size:\s+([\d.]+) x ([\d.]+) pts/', $m12_info, $m12_m ) ) {
	check( 'M12-75: the PDF of a design with 3 mm bleed is 111 × 154 mm', abs( (float) $m12_m[1] - 111 * PdfRenderer::MM_TO_PT ) < 1 && abs( (float) $m12_m[2] - 154 * PdfRenderer::MM_TO_PT ) < 1, $m12_m[0] );
}
check( 'M12-75: crop marks are drawn in the bleed', 8 === substr_count( vcap()->pdf->html( $v, $m12_bleed_design ), 'background:#000000;"></div>' ) && 0 === substr_count( vcap()->pdf->html( $v, array_replace_recursive( $m12_bleed_design, array( 'page' => array( 'cropMarks' => false ) ) ) ), 'background:#000000;"></div>' ) );
check( 'M12-75: N-up: four A6, two A5, one DL beside another, one A4 per A4 sheet', 4 === PdfRenderer::nup_layout( 105, 148 )['cols'] * PdfRenderer::nup_layout( 105, 148 )['rows'] && 2 === PdfRenderer::nup_layout( 148, 210 )['cols'] * PdfRenderer::nup_layout( 148, 210 )['rows'] && 3 === PdfRenderer::nup_layout( 99, 210 )['cols'] * PdfRenderer::nup_layout( 99, 210 )['rows'] && 1 === PdfRenderer::nup_layout( 210, 297 )['cols'] * PdfRenderer::nup_layout( 210, 297 )['rows'], wp_json_encode( PdfRenderer::nup_layout( 99, 210 ) ) );
$GLOBALS['vcap_test_filters']['vcap_template_design'] = array( array( static fn() => array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 10, 'text' => '{coupon_code}' ) ) ), 1 ) );
$m12_nup_list = array();
for ( $m12_i = 0; $m12_i < 5; $m12_i++ ) {
	$m12_nv       = clone $v;
	$m12_nv->id   = 900 + $m12_i;
	$m12_nv->code = 'NUP-CODE-' . $m12_i;
	$m12_nup_list[] = $m12_nv;
}
$m12_nup = vcap()->pdf->render_many( $m12_nup_list, true );
unset( $GLOBALS['vcap_test_filters']['vcap_template_design'] );
file_put_contents( "$out/m12-nup.pdf", $m12_nup[0]['pdf'] );
$m12_nup_info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( "$out/m12-nup.pdf" ) . ' 2>/dev/null' );
if ( '' !== $m12_nup_info ) {
	$m12_nup_text = (string) shell_exec( 'pdftotext ' . escapeshellarg( "$out/m12-nup.pdf" ) . ' - 2>/dev/null' );
	check( 'M12-75: five A6 vouchers are printed on two A4 sheets (render_many N-up)', 1 === count( $m12_nup ) && (bool) preg_match( '/Pages:\s+2\b/', $m12_nup_info ) && (bool) preg_match( '/Page size:\s+595\.\d+ x 841\.\d+ pts/', $m12_nup_info ) && 5 === substr_count( $m12_nup_text, 'NUP-CODE-' ), $m12_nup_info );
}

// M12-76: PDF title "Product – Shop" (never the code) and the document language.
$m12_title_info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( "$out/m12-bleed.pdf" ) . ' 2>/dev/null' );
if ( '' !== $m12_title_info ) {
	check( 'M12-76: the PDF title names the shop (and product), not the voucher code', str_contains( $m12_title_info, 'Café Süß & Co' ) && ! str_contains( $m12_title_info, $v->code ), $m12_title_info );
}
check( 'M12-76: the PDF HTML carries the language and a title without the code', (bool) preg_match( '/<html lang="[a-z]{2}(-[A-Z]{2})?">/', vcap()->pdf->html( $v, $m12_bleed_design ) ) && ! str_contains( PdfRenderer::pdf_title( $v ), $v->code ) && str_contains( (string) file_get_contents( VCAP_PATH . 'ACCESSIBILITY.md' ), 'nicht getaggt' ) );

// M12-77: {expiry_line}; a line with {expiry_date} is left out for vouchers without expiry.
$m12_noexp             = clone $v;
$m12_noexp->expires_at = null;
$m12_noexp_html        = vcap()->pdf->html( $m12_noexp, array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 20, 'text' => "Code {coupon_code}\nValid until {expiry_date}" ), array( 'type' => 'text', 'x' => 5, 'y' => 30, 'w' => 90, 'h' => 10, 'text' => '{expiry_line}' ) ) ) );
check( 'M12-77: without expiry "Valid indefinitely" is printed and "Valid until {expiry_date}" is left out', str_contains( $m12_noexp_html, 'Valid indefinitely' ) && ! str_contains( $m12_noexp_html, 'Valid until' ) && ! str_contains( $m12_noexp_html, 'unlimited' ) && str_contains( $m12_noexp_html, 'Code ' . $v->code ), $m12_noexp_html );
check( 'M12-77: with expiry {expiry_line} reads "Valid until <date>"; the starter gift card uses it', str_starts_with( Placeholders::replace( '{expiry_line}', $v ), 'Valid until ' ) && str_contains( Placeholders::replace( '{expiry_line}', $v ), '2027' ) && in_array( '{expiry_line}', array_column( TemplatePostType::default_designs()['giftcard']['elements'], 'text' ), true ) );
// Z13: a voucher keeps the design of its template at issue time (also after a redesign or deletion).
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) { $GLOBALS['vcap_test_meta'][ $id ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $k ) { unset( $GLOBALS['vcap_test_meta'][ $id ][ $k ] ); return true; }
}
$p8_tpl = static fn( string $label ) => (string) wp_json_encode( array( 'page' => array( 'format' => 'A6' ), 'elements' => array( array( 'type' => 'text', 'x' => 5, 'y' => 5, 'w' => 90, 'h' => 10, 'text' => $label . ' {coupon_code}' ) ) ) );
$p8_label = static fn( array $design ) => (string) ( $design['elements'][0]['text'] ?? '' );
$GLOBALS['vcap_test_meta'][500][ TemplatePostType::META ] = $p8_tpl( 'CHRISTMAS' );
TemplatePostType::record_design_change( 500, $p8_tpl( 'CHRISTMAS' ), $p8_tpl( 'JANUARY' ), true );
$GLOBALS['vcap_test_meta'][500][ TemplatePostType::META ] = $p8_tpl( 'JANUARY' );
$p8_hist = $GLOBALS['vcap_test_meta'][500][ TemplatePostType::HISTORY_META ] ?? array();
$p8_sold                = clone $v;
$p8_sold->id            = 41;
$p8_sold->template_id   = 500;
$p8_sold->created_at    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$p8_new                 = clone $p8_sold;
$p8_new->id             = 42;
$p8_new->created_at     = gmdate( 'Y-m-d H:i:s', time() + 5 );
$p8_preview             = clone $p8_sold;
$p8_preview->id         = 0;
check( 'Z13: a replaced design that vouchers were issued with is kept with the time it was replaced', 1 === count( $p8_hist ) && $p8_tpl( 'CHRISTMAS' ) === $p8_hist[0]['design'] && abs( $p8_hist[0]['until'] - time() ) < 5 );
check( 'Z13: a voucher sold before the redesign keeps its design, later vouchers and previews get the new one', 'CHRISTMAS {coupon_code}' === $p8_label( TemplatePostType::design_for_voucher( 500, $p8_sold ) ) && 'JANUARY {coupon_code}' === $p8_label( TemplatePostType::design_for_voucher( 500, $p8_new ) ) && 'JANUARY {coupon_code}' === $p8_label( TemplatePostType::design_for_voucher( 500, $p8_preview ) ) );
file_put_contents( "$out/p8-sold.pdf", vcap()->pdf->render( $p8_sold ) );
$p8_text = (string) shell_exec( 'pdftotext ' . escapeshellarg( "$out/p8-sold.pdf" ) . ' - 2>/dev/null' );
if ( '' !== trim( $p8_text ) ) {
	check( 'Z13: the PDF of the sold voucher shows the design it was issued with', str_contains( $p8_text, 'CHRISTMAS GIFT-ABCD-1234' ) && ! str_contains( $p8_text, 'JANUARY' ), $p8_text );
}
TemplatePostType::record_design_change( 500, $p8_tpl( 'JANUARY' ), $p8_tpl( 'DRAFT' ), false );
check( 'Z13: a design no voucher was issued with is not kept', 1 === count( $GLOBALS['vcap_test_meta'][500][ TemplatePostType::HISTORY_META ] ) );
TemplatePostType::record_design_change( 500, $p8_tpl( 'DRAFT' ), $p8_tpl( 'DRAFT' ), true, true );
check( 'Z13: "Also use this design for vouchers already issued" drops the kept designs', ! isset( $GLOBALS['vcap_test_meta'][500][ TemplatePostType::HISTORY_META ] ) && 'JANUARY {coupon_code}' === $p8_label( TemplatePostType::design_for_voucher( 500, $p8_sold ) ) );
$GLOBALS['vcap_test_options'][ TemplatePostType::ARCHIVE_OPTION . 501 ] = array( 'deleted' => time() - 60, 'design' => $p8_tpl( 'DELETED' ), 'history' => array() );
$p8_sold->template_id = 501;
$p8_new->template_id  = 501;
check( 'Z13: vouchers issued with a template deleted later keep its design, newer ones get the fallback', 'DELETED {coupon_code}' === $p8_label( TemplatePostType::design_for_voucher( 501, $p8_sold ) ) && TemplatePostType::default_designs()['giftcard']['page'] === TemplatePostType::design_for_voucher( 501, $p8_new )['page'] );
unset( $GLOBALS['vcap_test_meta'][500], $GLOBALS['vcap_test_options'][ TemplatePostType::ARCHIVE_OPTION . 501 ] );
// H6: how to redeem follows the voucher's redemption channels in the mail, the PDF and My Account.
if ( ! function_exists( 'wc_get_order_item_meta' ) ) {
	function wc_get_order_item_meta( $id, $key, $single = true ) { return $GLOBALS['vcap_test_item_meta'][ $id ][ $key ] ?? ''; }
}
$p8_ch = static function ( array $channels, string $type = 'giftcard' ) use ( $v ): VCAP\Voucher {
	$c                = clone $v;
	$c->type          = $type;
	$c->order_item_id = 7001 + count( $GLOBALS['vcap_test_item_meta'] ?? array() );
	$GLOBALS['vcap_test_item_meta'][ $c->order_item_id ][ VCAP\Issuer::LINE_META ] = array( 'channels' => $channels );
	return $c;
};
$p8_spa = $p8_ch( array( VCAP\Channels::COUNTER ) );
check( 'H6: without a channel selection the code is entered in the cart', 'Enter the code in the cart or at checkout on Café Süß & Co to redeem it.' === VCAP\Channels::instructions( $p8_ch( array() ) ) );
check( 'H6: a voucher only for the counter (spa) is not sent to the cart', str_contains( VCAP\Channels::instructions( $p8_spa ), 'at the counter of Café Süß & Co' ) && ! str_contains( VCAP\Channels::instructions( $p8_spa ), 'cart or at checkout' ) );
check( 'H6: shop + counter names both, external only points to the redeem page, tickets get none', str_contains( VCAP\Channels::instructions( $p8_ch( array( 'shop', 'counter' ) ) ), 'or show this voucher at the counter' ) && str_contains( VCAP\Channels::instructions( $p8_ch( array( 'external' ) ) ), 'redeem page' ) && '' === VCAP\Channels::instructions( $p8_ch( array(), 'ticket' ) ) );
check( 'H6: the shop name is linked and escaped in the HTML mail text', str_contains( VCAP\Channels::instructions_html( $p8_ch( array() ) ), '<a href="https://shop.test/">Café Süß &amp; Co</a>' ) );
$p8_gift = TemplatePostType::default_designs()['giftcard'];
$p8_html = vcap()->pdf->html( $p8_spa, $p8_gift );
check( 'H6: the default gift card PDF shows the shop URL, how to redeem (per channel), transferability and conditions', str_contains( $p8_html, 'https://shop.test/' ) && str_contains( $p8_html, 'at the counter of Café Süß &amp; Co' ) && str_contains( $p8_html, 'Transferable' ) && str_contains( implode( ' ', array_column( $p8_gift['elements'], 'text' ) ), '{redemption_terms}' ) );
$p8_mail = (string) file_get_contents( VCAP_PATH . 'templates/emails/voucher.php' );
check( 'H6: the mails and My Account use the channel instructions, not the fixed cart sentence', ! str_contains( $p8_mail, "Enter the code in the cart or at checkout on %s to redeem it." ) && str_contains( $p8_mail, 'Channels::instructions_html( $voucher )' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'templates/emails/plain/voucher.php' ), 'Channels::instructions( $voucher )' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'templates/myaccount/vouchers-table.php' ), 'Channels::instructions( $voucher )' ) );
check( 'H6: {redeem_instructions} is offered as a placeholder', isset( Placeholders::definitions()['redeem_instructions'] ) );
unset( $GLOBALS['vcap_test_item_meta'] );
$p8_js = (string) file_get_contents( VCAP_PATH . 'assets/js/designer.js' );
check( 'H16: the designer shrinks overflowing texts like the PDF and marks texts that are still cut off', str_contains( $p8_js, 'function fitText( inner )' ) && str_contains( $p8_js, "classList.toggle( 'is-overflowing'" ) );
check( 'Z14: the designer gives custom PDF fonts their own font stack', str_contains( $p8_js, "FONT_STACKS[ family ] = '\"' +" ) );

echo "Redemption API\n";
$valid_keys   = array( 'it-' . str_repeat( 'a', 20 ), 'learnboating:grant:1234', '550e8400-e29b-41d4-a716-446655440000' );
$invalid_keys = array( 'short', '-leading-dash', str_repeat( 'x', 65 ), 'has space 12345', "nl\n12345678" );
check( 'valid idempotency keys are accepted', count( $valid_keys ) === count( array_filter( $valid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
check( 'malformed idempotency keys are rejected', 0 === count( array_filter( $invalid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
$h = \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 5 );
check( 'request fingerprint is stable and amount-normalised', $h === \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.0000, 5 ) );
check( 'request fingerprint changes with target, amount or user', count( array_unique( array( $h, \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:2', 30, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.01, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 6 ) ) ) ) === 4 );

$p13_deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => 1 ) ) ) );
check( 'P13: caller metadata is limited to 8 KB and 3 levels', \VCAP\API\V1\RedemptionService::meta_acceptable( array( 'a' => array( 'b' => array( 'c' => 1 ) ) ) ) && ! \VCAP\API\V1\RedemptionService::meta_acceptable( $p13_deep ) && ! \VCAP\API\V1\RedemptionService::meta_acceptable( array( 'x' => str_repeat( 'y', 8200 ) ) ) && \VCAP\API\V1\RedemptionService::meta_acceptable( null ) );
check( 'P13: a REST key with a prefix must use the prefix of its target; UUID keys are free', \VCAP\API\V1\RestController::key_matches_target( 'learnboating:u5:abc', 'learnboating:product:1' ) && ! \VCAP\API\V1\RestController::key_matches_target( 'learnboating:grant:1234', 'test:course:1' ) && \VCAP\API\V1\RestController::key_matches_target( '550e8400-e29b-41d4-a716-446655440000', 'test:course:1' ) && ! \VCAP\API\V1\RestController::key_matches_target( 'pos:1234567', 'posx:till' ) );
check( 'P13: the Learnboating grant keys are internal (never created over REST)', \VCAP\API\V1\RestController::is_internal_key( 'learnboating:grant:1234' ) && ! \VCAP\API\V1\RestController::is_internal_key( 'learnboating:u5:abc' ) );

echo "Counter redemption (scanner): plausibility and refusal reasons\n";
check( 'an amount within the balance may be booked', '' === Tickets::counter_amount_error( 12.5, 50, 2, false ) && '' === Tickets::counter_amount_error( 50, 50, 2, false ) );
check( 'an amount above the balance is refused unless a partial booking is asked for', 'exceeds_balance' === Tickets::counter_amount_error( 80, 50, 2, false ) && '' === Tickets::counter_amount_error( 80, 50, 2, true ) );
check( 'one cent above the balance is already refused (minor units, no float noise)', 'exceeds_balance' === Tickets::counter_amount_error( 50.01, 50, 2, false ) && '' === Tickets::counter_amount_error( 0.1 + 0.2, 0.3, 2, false ) );
check( 'zero, negative and non-finite amounts are refused', 'amount_missing' === Tickets::counter_amount_error( 0, 50, 2, false ) && 'amount_missing' === Tickets::counter_amount_error( -5, 50, 2, true ) && 'amount_missing' === Tickets::counter_amount_error( NAN, 50, 2, false ) && 'amount_missing' === Tickets::counter_amount_error( INF, 50, 2, true ) );
check( 'more decimals than the currency has are refused (EUR 0.999, JPY 12.5), KWD 1.5 is fine', 'amount_precision' === Tickets::counter_amount_error( 0.999, 50, 2, false ) && 'amount_precision' === Tickets::counter_amount_error( 12.5, 5000, 0, false ) && '' === Tickets::counter_amount_error( 1.5, 5, 3, false ) && '' === Tickets::counter_amount_error( 0.29, 5, 2, false ) );
check( 'minor units are exact', 29 === CouponManager::minor_units( 0.29, 2 ) && 1500 === CouponManager::minor_units( 1500, 0 ) && 1500 === CouponManager::minor_units( 1.5, 3 ) );
$counter_card              = new Voucher();
$counter_card->usage_mode  = 'balance';
$counter_card->balance     = 50;
$counter_card->status      = Voucher::STATUS_ACTIVE;
$counter_reason            = static function ( array $props ) use ( $counter_card ): string {
	$v = clone $counter_card;
	foreach ( $props as $k => $val ) {
		$v->$k = $val;
	}
	return CouponManager::balance_refusal( $v );
};
check( 'an active card with balance can be charged', '' === CouponManager::balance_refusal( $counter_card ) );
check(
	'each refusal names its own reason (cancelled, expired, reserved, not delivered, used up, other status)',
	'void' === $counter_reason( array( 'status' => Voucher::STATUS_VOID ) )
	&& 'expired' === $counter_reason( array( 'status' => Voucher::STATUS_EXPIRED ) )
	&& 'expired' === $counter_reason( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) )
	&& 'reserved' === $counter_reason( array( 'status' => Voucher::STATUS_RESERVED ) )
	&& 'scheduled' === $counter_reason( array( 'status' => Voucher::STATUS_SCHEDULED ) )
	&& 'redeemed' === $counter_reason( array( 'status' => Voucher::STATUS_REDEEMED ) )
	&& 'redeemed' === $counter_reason( array( 'balance' => 0.0 ) )
	&& 'not_active' === $counter_reason( array( 'status' => 'pending' ) )
	&& 'not_multi_use' === $counter_reason( array( 'usage_mode' => 'single' ) )
	&& 'not_found' === CouponManager::balance_refusal( null )
);
$counter_messages = array();
foreach ( array( 'exceeds_balance', 'void', 'expired', 'reserved', 'scheduled', 'redeemed', 'not_active', 'no_transactions', 'db_error', 'amount_precision', 'amount_missing' ) as $counter_code ) {
	$counter_messages[ $counter_code ] = Tickets::refusal_message( $counter_code, '80,00 €', '50,00 €', 2 );
}
check( 'refusal messages differ per reason (no blanket "amount exceeds the balance")', count( $counter_messages ) === count( array_unique( $counter_messages ) ), json_encode( $counter_messages, JSON_UNESCAPED_UNICODE ) );
check( 'the over-balance refusal names the entered amount and the remaining balance', str_contains( $counter_messages['exceeds_balance'], '80,00 €' ) && str_contains( $counter_messages['exceeds_balance'], '50,00 €' ) );
check( 'only the over-balance refusal mentions the balance being exceeded', 1 === count( array_filter( $counter_messages, static fn( $m ) => str_contains( $m, 'exceeds' ) ) ) );

echo "Scanner: code recognition, context, undo and cancellation rules\n";
$cand = Tickets::code_candidates( 'https://shop.test/gutschein/?gutschein=ABCD-EFGH-2345&utm=x' );
check( 'a custom QR link is read: its parameter values are looked up as codes', 'abcd-efgh-2345' === ( $cand[0] ?? '' ) && in_array( 'x', $cand, true ), json_encode( $cand ) );
check( 'the last path segment of a link counts too', in_array( 'k7qm2x9zpa', Tickets::code_candidates( 'https://shop.test/redeem/K7QM2X9ZPA' ), true ) );
$cand = Tickets::code_candidates( ' ABCD EFGH ' );
check( 'spaces are removed or read as dashes, separators dropped', array( 'abcd efgh', 'abcdefgh', 'abcd-efgh' ) === array_slice( $cand, 0, 3 ), json_encode( $cand ) );
$cand = Tickets::code_candidates( 'GIFTßYZ12' );
check( 'USB scanner on a German keyboard: Y/Z swapped and ß read as dash, after the exact forms', 'giftßyz12' === $cand[0] && in_array( 'gift-zy12', $cand, true ) && array_search( 'gift-zy12', $cand, true ) > array_search( 'giftßyz12', $cand, true ), json_encode( $cand ) );
check( 'at most 12 candidates, never empty ones', count( Tickets::code_candidates( 'https://shop.test/?a=1&b=2&c=3&d=4&e=5&f=6' ) ) <= 12 && array() === Tickets::code_candidates( '   ' ) );

$ev = static fn( int $id, string $event, ?float $balance = null, int $order = 0, int $user = 0, string $at = '2026-01-01 10:00:00', float $amount = 10.0 ) => array( 'id' => $id, 'voucher_id' => 7, 'event' => $event, 'amount' => $amount, 'balance_after' => $balance, 'order_id' => $order, 'user_id' => $user, 'note' => '', 'created_at' => $at );
$hist = array( $ev( 1, 'issued', 50.0 ), $ev( 2, 'reserved', 0.0, 99 ), $ev( 3, 'resent' ) );
check( 'a balance held by an unpaid order is recognised (not "already redeemed")', 2 === ( Tickets::open_reservation( $hist )['id'] ?? 0 ) );
check( 'once committed or released the reservation is no longer open', null === Tickets::open_reservation( array_merge( $hist, array( $ev( 4, 'committed', 0.0, 99 ) ) ) ) && null === Tickets::open_reservation( array_merge( $hist, array( $ev( 4, 'reservation_released', 50.0, 99 ) ) ) ) );
check( 'last_event finds the newest event of a kind', 2 === Tickets::last_event( $hist, array( 'reserved', 'issued' ) )['id'] && null === Tickets::last_event( $hist, array( 'voided' ) ) );

$ticket               = new Voucher();
$ticket->id           = 7;
$ticket->type         = 'ticket';
$ticket->status       = Voucher::STATUS_ACTIVE;
$ticket->max_checkins = 2;
$ticket->checkins     = 1;
$now                  = strtotime( '2026-01-01 10:05:00 UTC' );
$mine                 = $ev( 5, 'checkin', null, 0, 3, '2026-01-01 10:00:00' );
check( 'own check-in can be reverted within the time window', 'own' === Tickets::undo_mode( $ticket, $mine, 3, false, $now ) );
check( 'after the window, or someone else\'s check-in: only managers, with a reason', '' === Tickets::undo_mode( $ticket, $mine, 3, false, $now + 3600 ) && '' === Tickets::undo_mode( $ticket, $mine, 4, false, $now ) && 'reason' === Tickets::undo_mode( $ticket, $mine, 4, true, $now ) );
$void_ticket         = clone $ticket;
$void_ticket->status = Voucher::STATUS_VOID;
$old_ticket          = clone $ticket;
$old_ticket->expires_at = '2020-01-01 00:00:00';
check( 'cancelled or expired tickets cannot be reverted at all (no extra admissions)', '' === Tickets::undo_mode( $void_ticket, $mine, 3, true, $now ) && '' === Tickets::undo_mode( $old_ticket, $mine, 3, true, $now ) );
$m15_ticket           = clone $ticket;
$m15_ticket->max_checkins = 3;
$m15_ticket->checkins = 2;
$m15_hist             = array( $ev( 1, 'checkin', null, 0, 4, '2026-01-01 09:00:00', 2.0 ), $ev( 2, 'checkin', null, 0, 3, '2026-01-01 10:00:00', 1.0 ), $ev( 3, 'checkin_undo', null, 0, 3, '2026-01-01 10:01:00', 1.0 ) );
check( 'M12-15: own undo reverts at most the admissions of that check-in (checked in 1, undone 1 → no further own undo)', 1 === Tickets::undone_since_checkin( $m15_hist ) && 0 === Tickets::undone_since_checkin( array_slice( $m15_hist, 0, 2 ) ) && 'own' === Tickets::undo_mode( $m15_ticket, $m15_hist[1], 3, false, $now, 0 ) && '' === Tickets::undo_mode( $m15_ticket, $m15_hist[1], 3, false, $now, 1 ) && 'reason' === Tickets::undo_mode( $m15_ticket, $m15_hist[1], 3, true, $now, 1 ) );
check( 'M12-15: a check-in of 2 admissions can be undone twice by its cashier', 'own' === Tickets::undo_mode( $m15_ticket, $m15_hist[0], 4, false, strtotime( '2026-01-01 09:05:00 UTC' ), 1 ) && '' === Tickets::undo_mode( $m15_ticket, $m15_hist[0], 4, false, strtotime( '2026-01-01 09:05:00 UTC' ), 2 ) );
check( 'M12-44: ledger source by whole route segments – the redemption API is not the scanner', 'scanner' === VCAP\Source::for_route( '/vcap/v1/redeem' ) && 'scanner' === VCAP\Source::for_route( '/vcap/v1/redeem/reverse' ) && 'scanner' === VCAP\Source::for_route( 'vcap/v1/lookup' ) && 'rest' === VCAP\Source::for_route( '/vcap/v1/redemptions' ) && 'rest' === VCAP\Source::for_route( '/vcap/v1/redemptions/abc12345/commit' ) && 'rest' === VCAP\Source::for_route( '/vcap/v1/lookups' ) && 'checkout' === VCAP\Source::for_route( '/wc/store/v1/checkout' ) && 'rest' === VCAP\Source::for_route( '/wc/storefront' ) );

$card             = new Voucher();
$card->id         = 7;
$card->usage_mode = 'balance';
$card->status     = Voucher::STATUS_ACTIVE;
$booking          = $ev( 8, 'redeemed', 20.0, 0, 3, '2026-01-01 10:00:00' );
check( 'the cashier can cancel their own counter redemption shortly after', '' === Tickets::reversal_refusal( $booking, $card, 3, false, $now, false ) );
check( 'later or by someone else only a manager may cancel it', 'forbidden' === Tickets::reversal_refusal( $booking, $card, 3, false, $now + 3600, false ) && 'forbidden' === Tickets::reversal_refusal( $booking, $card, 4, false, $now, false ) && '' === Tickets::reversal_refusal( $booking, $card, 4, true, $now + 3600, false ) );
check(
	'shop orders, cancelled vouchers, other events and double cancellations are refused',
	'order' === Tickets::reversal_refusal( $ev( 8, 'redeemed', 20.0, 55, 3 ), $card, 3, true, $now, false )
	&& 'reversed' === Tickets::reversal_refusal( $booking, $card, 3, true, $now, true )
	&& 'not_found' === Tickets::reversal_refusal( $ev( 8, 'adjusted', 20.0 ), $card, 3, true, $now, false )
	&& 'not_found' === Tickets::reversal_refusal( null, $card, 3, true, $now, false )
	&& 'void' === Tickets::reversal_refusal( $booking, ( static function ( $v ) { $v->status = Voucher::STATUS_VOID; return $v; } )( clone $card ), 3, true, $now, false )
);
$rev_codes = array( 'reason', 'order', 'reversed', 'void', 'forbidden', 'used', 'failed', 'other' );
check( 'every cancellation refusal has its own message', count( $rev_codes ) === count( array_unique( array_map( array( Tickets::class, 'reversal_message' ), $rev_codes ) ) ) );
check( 'cancelled counter redemptions count in the redemption journal and give a single-use voucher its value back', in_array( 'redeem_reversed', VCAP\Ledger::MONEY_EVENTS, true ) );
$f_root  = dirname( __DIR__ );
$f_admin = (string) file_get_contents( $f_root . '/assets/css/admin.css' );
check( 'F14: admin.css is loaded on the order screens (posts and HPOS) and the detail grid fits a phone', str_contains( $f_admin, 'minmax(min(380px, 100%), 1fr)' ) && in_array( 'shop_order', VCAP\Admin\Menu::ORDER_SCREENS, true ) && in_array( 'woocommerce_page_wc-orders', VCAP\Admin\Menu::ORDER_SCREENS, true ) );
$f_css   = $f_admin . (string) file_get_contents( $f_root . '/assets/css/frontend.css' ) . preg_replace( '/\.vcap-d-(handle|rotate|guide)[^}]*}/', '', (string) file_get_contents( $f_root . '/assets/css/designer.css' ) );
$f_mail  = (string) file_get_contents( $f_root . '/templates/emails/voucher.php' );
check( 'F15: no physical left/right in the stylesheets (logical properties; canvas handles excepted), RTL-aware email, no off-screen -9999px', ! preg_match( '/(margin|padding|border)-(left|right)\s*:|text-align:\s*(left|right)|float:\s*(left|right)/', $f_css ) && ! preg_match( '/(border|padding|margin)-left:/', $f_mail ) && str_contains( $f_mail, "is_rtl() ? 'right' : 'left'" ) && ! str_contains( (string) file_get_contents( $f_root . '/src/Shortcodes.php' ), '-9999px' ) );
check( 'F16: email without nested <p>, text contrast ≥ 4.5:1 (#666), bulletproof buttons; no inline onfocus; translated preview error; designer icons ≥ 24 px', ! str_contains( $f_mail, '<p><?php echo wp_kses_post( wpautop' ) && ! str_contains( $f_mail, '#777' ) && 2 === substr_count( $f_mail, 'bgcolor="<?php echo esc_attr( $vcap_c[\'base\'] ); ?>"' ) && ! str_contains( (string) file_get_contents( $f_root . '/src/Referrals.php' ), 'onfocus' ) && str_contains( (string) file_get_contents( $f_root . '/assets/js/campaigns.js' ), 'cfg.error' ) && str_contains( (string) file_get_contents( $f_root . '/assets/css/designer.css' ), 'min-width: 24px; min-height: 24px;' ) );
check( 'M11: field keys from labels without Latin letters become field_N (by position), like slug() in field-groups.js', 'field_3' === VCAP\Fields::field_key( '', 'Όνομα παραλήπτη', 3 ) && 'field_1' === VCAP\Fields::field_key( '', 'Име', 1 ) && 'grusskarte' === VCAP\Fields::field_key( '', 'Grußkarte', 2 ) && 'farbe_wahlen' === VCAP\Fields::field_key( '', 'Farbe wählen!', 1 ) && 'my_key' === VCAP\Fields::field_key( 'my_key', 'Όνομα', 4 ) && 'field_42' === VCAP\Fields::field_key( '42', '', 1 ) && '' === VCAP\Fields::field_key( ' ', '', 5 ) && str_contains( (string) file_get_contents( $f_root . '/assets/js/field-groups.js' ), "'field_' + ( index + 1 )" ) );
check( 'M10/H11: delivery dates show in the voucher date format, the help text names the delivery time, the picker starts today', '24.12.2026' === VCAP\Fields::display_value( '2026-12-24' ) && 'Anna' === VCAP\Fields::display_value( 'Anna' ) && '08:00' === VCAP\Fields::delivery_time_text() && str_contains( VCAP\Fields::builtin_definitions( array( 'builtin_fields' => array( 'delivery_date' ) ) + ProductConfig::DEFAULTS )['delivery_date']['tooltip'], '08:00' ) && 'vcap_recipient_name' === VCAP\Fields::item_meta_key( 'recipient_name' ) );
check( 'U14: the reconciliation names states in words and amounts with currency (no raw "committed" or "12.50")', 'Confirmed' === VCAP\Compat\PassCaptainReconcile::state_label( 'committed' ) && 'Released' === VCAP\Compat\PassCaptainReconcile::state_label( 'released' ) && 'Reserved' === VCAP\Compat\PassCaptainReconcile::state_label( 'reserved' ) && ! str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Compat/PassCaptainReconcile.php' ), 'wc_format_decimal' ) && "12,50\u{a0}€" === VCAP\Tickets::money_text( 12.5, 'EUR' ) );
check( 'U12: send_to and the old buyer copy flag become one routing choice', 'both' === ProductConfig::normalise_routing( array( 'send_to' => 'recipient', 'buyer_copy' => 'yes' ) )['send_to'] && 'recipient' === ProductConfig::normalise_routing( array( 'send_to' => 'recipient', 'buyer_copy' => 'no' ) )['send_to'] && 'buyer' === ProductConfig::normalise_routing( array( 'send_to' => 'buyer', 'buyer_copy' => 'yes' ) )['send_to'] && 'both' === ProductConfig::normalise_routing( ProductConfig::DEFAULTS )['send_to'] );
check( 'T15: a booked-off rounding rest is a money event that takes value off the card (journal and liability stay reconciled)', in_array( VCAP\CouponManager::WRITTEN_OFF_EVENT, VCAP\Ledger::MONEY_EVENTS, true ) && -0.01 === VCAP\Ledger::signed_amount( VCAP\CouponManager::WRITTEN_OFF_EVENT, 0.01 ) && isset( VCAP\VoucherRepository::event_labels()[ VCAP\CouponManager::WRITTEN_OFF_EVENT ] ) );
check( 'U6: the journal amount is signed – redemptions negative, issue/refunds/credits positive, corrections with their own sign', -12.5 === VCAP\Ledger::signed_amount( 'redeemed', 12.5 ) && -3.0 === VCAP\Ledger::signed_amount( 'api_committed', '3' ) && -4.0 === VCAP\Ledger::signed_amount( 'debit', 4 ) && 20.0 === VCAP\Ledger::signed_amount( 'issued', 20 ) && 5.0 === VCAP\Ledger::signed_amount( 'restored', 5 ) && -7.0 === VCAP\Ledger::signed_amount( 'adjusted', -7 ) && 7.0 === VCAP\Ledger::signed_amount( 'adjusted', 7 ) && ! array_diff( VCAP\Ledger::OUT_EVENTS, VCAP\Ledger::MONEY_EVENTS ) );
check( 'counter note keeps receipt number and till', 'Redeemed in store · Receipt 4711 · Till Filiale 2' === Tickets::counter_note( '', ' 4711 ', 'Filiale 2' ) && 'Gift · Receipt 1' === Tickets::counter_note( 'Gift', '1' ) );
check( 'WooCommerce keeps sending users without the scanner right away from wp-admin', true === Tickets::allow_scanner_admin( true ) && false === Tickets::allow_scanner_admin( false ) );

$scanner_js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/scanner.js' );
$scanner_php = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Scanner.php' );
$ticket_tpl  = (string) file_get_contents( dirname( __DIR__ ) . '/templates/ticket-status.php' );
check( 'scanner: codes are posted, never put into the lookup URL', ! str_contains( $scanner_js, 'lookup?q=' ) && str_contains( $scanner_js, "api( 'lookup', 'POST'" ) );
check( 'scanner: requests time out and an unanswered booking is shown as unclear', str_contains( $scanner_js, 'AbortController' ) && str_contains( $scanner_js, 't.unclear' ) && str_contains( $scanner_php, "'unclear'" ) );
check( 'scanner: an expired nonce is renewed and the request repeated', str_contains( $scanner_js, 'renewNonce' ) && str_contains( $scanner_php, 'action=rest-nonce' ) );
check( 'scanner: no camera is a hint (HTTPS, permission), not a system error', str_contains( $scanner_js, 'function noCamera' ) && ! str_contains( $scanner_js, 'showError( { message: t.noCamera } )' ) );
check( 'scanner: no autofocus that opens the phone keyboard over the camera', ! str_contains( $scanner_php, 'autofocus' ) && str_contains( $scanner_js, 'pointer: coarse' ) );
check( 'M12-40: jsQR also reads inverted (light-on-dark) QR codes', str_contains( $scanner_js, "inversionAttempts: 'attemptBoth'" ) && ! str_contains( $scanner_js, 'dontInvert' ) );
check( 'M12-79: a replayed answer is marked, pending keys survive a reload (sessionStorage) and are dropped after any answer but "in progress"', str_contains( $scanner_js, 'd.replayed' ) && str_contains( $scanner_js, 't.replayed' ) && str_contains( $scanner_php, "'replayed'" ) && str_contains( $scanner_js, 'window.sessionStorage.setItem( KEY_STORE' ) && str_contains( $scanner_js, "'vcap_in_progress' !== err.code" ) );
check( 'M12-80: camera stops on visibilitychange and ended tracks, torch button, ideal 1280 px, play() rejection handled', str_contains( $scanner_js, "'visibilitychange'" ) && str_contains( $scanner_js, "addEventListener( 'ended'" ) && str_contains( $scanner_js, 'torch: ! torchOn' ) && str_contains( $scanner_php, 'id="vcap-torch"' ) && str_contains( $scanner_js, 'width: { ideal: 1280 }' ) && str_contains( $scanner_js, 'playing.catch' ) );
check( 'M12-24/M12-31/M12-45: scanner shows the personal banner with ID checkbox, the VAT text, the receipt rule, own reversible bookings and prints the 80 mm slip', str_contains( $scanner_js, 'vcap-r-personal' ) && str_contains( $scanner_js, 'body.id_checked' ) && str_contains( $scanner_js, 'd.treatment_label' ) && str_contains( $scanner_js, 'current.receipt_required' ) && str_contains( $scanner_js, 'd.reversible' ) && str_contains( $scanner_js, 'printSlip( current && current.slip )' ) && str_contains( $scanner_php, 'id="vcap-slip"' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.css' ), 'width: 72mm' ) );
check( 'M12-81: managed tills as a dropdown, fixed per staff member; offline mode documented as intentionally absent', str_contains( $scanner_php, "'fixedTill'" ) && str_contains( $scanner_php, '<select id="vcap-till"' ) && str_contains( $scanner_js, 'cfg.fixedTill' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Settings.php' ), 'Counter::LOCATIONS' ) && str_contains( $scanner_php, 'vcap-online-only' ) );
check( 'ticket page: check-in form carries a one-time key, refusals are red', str_contains( $ticket_tpl, 'vcap_checkin_key' ) && str_contains( $ticket_tpl, 'notice--error' ) );
// AP-W1-2 / AP-Q2-7: every overridable template names its version, so outdated theme copies can be reported.
$w12_versions = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/templates', FilesystemIterator::SKIP_DOTS ) ) as $w12_file ) {
	if ( 'php' === $w12_file->getExtension() ) {
		$w12_versions[ substr( $w12_file->getPathname(), strlen( dirname( __DIR__ ) . '/templates/' ) ) ] = VCAP\Plugin::template_version( $w12_file->getPathname() );
	}
}
check( 'AP-W1-2: every overridable template carries "@version 1.2.0"', count( $w12_versions ) >= 6 && array( '1.2.0' ) === array_values( array_unique( $w12_versions ) ), json_encode( $w12_versions ) );
$w13_src = array_map( static fn( $f ) => (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/' . $f ), array( 'sc' => 'StoreCreditAdmin.php', 'jp' => 'JobProgress.php', 'im' => 'Import.php' ) );
check( 'AP-W1-3: no inline <script> or onsubmit in the store credit refund, job progress and code import screens', ! preg_grep( '/(echo\s+["\']<script|^\s*<script|onsubmit=)/m', $w13_src ) && str_contains( $w13_src['sc'], "wp_add_inline_script(\n\t\t\t'vcap-admin'" ) && str_contains( $w13_src['jp'], "wp_add_inline_script( 'vcap-admin'" ) && str_contains( $w13_src['im'], 'data-confirm="' ) );
check( 'AP-W1-2: the plugin templates are added to woocommerce_template_overrides_scan_paths', array( 'WooCommerce' => '/wc/', 'Voucher Captain' => VCAP_PATH . 'templates/' ) === VCAP\Plugin::template_scan_paths( array( 'WooCommerce' => '/wc/' ) ) );

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

	$far  = "$out/far.xlsx";
	$zip  = new ZipArchive();
	$zip->open( $far, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>CODE-1</t></is></c><c r="ZZZZZZ1" t="inlineStr"><is><t>x</t></is></c></row></sheetData></worksheet>' );
	$zip->close();
	$refused = '';
	$peak    = memory_get_usage();
	try {
		SpreadsheetReader::read( $far, 'xlsx' );
	} catch ( RuntimeException $e ) {
		$refused = $e->getMessage();
	}
	check( 'Q9: a cell "ZZZZZZ1" is refused with a message instead of padding the row until memory runs out', str_contains( $refused, 'beyond the last column' ) && memory_get_usage() - $peak < 5 * 1024 * 1024, $refused );
	$gap = "$out/gap.xlsx";
	$zip = new ZipArchive();
	$zip->open( $gap, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>CODE-1</t></is></c><c r="XFD1" t="inlineStr"><is><t>last</t></is></c></row></sheetData></worksheet>' );
	$zip->close();
	$gap_rows = SpreadsheetReader::read( $gap, 'xlsx' );
	check( 'Q9: gaps are not filled (column XFD is read without 16382 empty cells)', 2 === count( $gap_rows[0] ) && 'last' === $gap_rows[0][16383] && 'CODE-1' === $gap_rows[0][0], json_encode( array_keys( $gap_rows[0] ) ) );
	unlink( $far );
	unlink( $gap );
}

$ole = "$out/broken.xls";
file_put_contents( $ole, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\0", 22 ) . pack( 'v', 30 ) . str_repeat( "\xFF", 600 ) );
$refused = '';
try {
	SpreadsheetReader::read( $ole, 'xls' );
} catch ( RuntimeException $e ) {
	$refused = $e->getMessage();
}
check( 'Q9: an XLS with an impossible sector size is refused as damaged', str_contains( $refused, 'damaged' ), $refused );
unlink( $ole );

$utf16 = "$out/unicode.txt";
file_put_contents( $utf16, "\xFF\xFE" . mb_convert_encoding( "code\tnote\r\nÄBC-123\tx\r\nDEF-456\ty\r\n", 'UTF-16LE', 'UTF-8' ) );
$u_rows = SpreadsheetReader::read( $utf16, 'txt' );
check( 'Q10: Excel "Unicode text" (UTF-16LE with BOM) is read without NUL bytes', array( 'code', 'ÄBC-123', 'DEF-456' ) === array_column( $u_rows, 0 ), json_encode( $u_rows ) );
file_put_contents( $utf16, "\xFE\xFF" . mb_convert_encoding( "code\nGHI-789\n", 'UTF-16BE', 'UTF-8' ) );
check( 'Q10: UTF-16BE with BOM is read as well', array( 'code', 'GHI-789' ) === array_column( SpreadsheetReader::read( $utf16, 'txt' ), 0 ) );
file_put_contents( $utf16, mb_convert_encoding( "code\nJKL-012\n", 'UTF-16LE', 'UTF-8' ) );
check( 'Q10: UTF-16LE without BOM is recognised by its NUL bytes', array( 'code', 'JKL-012' ) === array_column( SpreadsheetReader::read( $utf16, 'txt' ), 0 ) );
file_put_contents( $utf16, "code\n\xC4BC-1\n" );
check( 'Windows-1252 files are still converted', 'ÄBC-1' === SpreadsheetReader::read( $utf16, 'txt' )[1][0] );
unlink( $utf16 );

$q10_masked = VCAP\Downloads::masked_code( 'gutschein-äöüé' );
check( 'Q10: the masked code keeps whole multibyte characters (no broken UTF-8)', '…ÄÖÜÉ' === $q10_masked && mb_check_encoding( $q10_masked, 'UTF-8' ) && '…X-ÄÖ' === VCAP\Downloads::masked_code( 'abx-äö' ), $q10_masked );
echo "Code import checks (Q2, Q10, H8)\n";
$fmt_any = "\x01^\\S{1,100}$\x01u";
check( 'Q2: WC stub formats like WooCommerce 11.3 ("B&B" → "b&amp;b")', 'b&amp;b-1' === wc_format_coupon_code( 'B&B-1' ) && 'abc' === wc_format_coupon_code( ' ABC ' ) );
check( 'Q2: an imported code WooCommerce would change ("B&B-…", "<b>…") is refused with the reason', str_contains( VCAP\Admin\Import::code_problem( 'B&B-12345678', $fmt_any ), 'b&amp;b' ) && '' !== VCAP\Admin\Import::code_problem( 'X<b>Y</b>', $fmt_any ) );
check( 'Q2: a code longer than 100 characters is refused before the insert', str_contains( VCAP\Admin\Import::code_problem( str_repeat( 'A', 101 ), "\x01^\\S{1,200}$\x01u" ), '100' ) && '' === VCAP\Admin\Import::code_problem( str_repeat( 'A', 100 ), $fmt_any ) );
check( 'Q10: codes with control characters (NUL from a misread file) are refused', '' !== VCAP\Admin\Import::code_problem( "A\0B\0C\0", $fmt_any ) && '' !== VCAP\Admin\Import::code_problem( "AB\x7FC", $fmt_any ) );
check( 'Q2: ordinary codes pass; the format is still checked', '' === VCAP\Admin\Import::code_problem( 'GIFT-K7QM2X9ZPA', $fmt_any ) && str_contains( VCAP\Admin\Import::code_problem( 'ab', "\x01^[A-Z]{4,}$\x01u" ), 'format' ) );
$col = VCAP\Admin\Import::resolve_column( array( array( 'Name', 'ID', 'Nr' ), array( 'x', 'K7QM2X9ZPA', '1' ) ), 'ID', false );
check( 'H8: a two-letter header ("ID") is found as a name first, not read as column letters', 1 === $col['index'] && true === $col['header'] && '' === $col['error'], json_encode( $col ) );
$col = VCAP\Admin\Import::resolve_column( array( array( 'Name', 'Code' ) ), 'B', true );
check( 'H8: a column letter still works', 1 === $col['index'] && '' === $col['error'] );
check( 'H8: an unknown column name is an error', '' !== VCAP\Admin\Import::resolve_column( array( array( 'Name' ) ), 'Gutscheincode', true )['error'] );
$b4_report = VCAP\Admin\Import::empty_report( 'b' );
for ( $i = 0; $i < 250; $i++ ) {
	VCAP\Jobs::add_error( $b4_report, 'invalid', "Row {$i}" );
}
check( 'B4: an import error list keeps at most 100 rows and counts all of them', VCAP\Jobs::MAX_ERRORS === count( $b4_report['invalid'] ) && 250 === VCAP\Jobs::count( $b4_report, 'invalid' ) && 'Row 99' === end( $b4_report['invalid'] ) );
check( 'B4: a report stored before the cap is counted by its list', 3 === VCAP\Jobs::count( array( 'weak' => array( 'a', 'b', 'c' ) ), 'weak' ) && 0 === VCAP\Jobs::count( array(), 'not_saved' ) );
check( 'B4: progress of a background job in percent', 40 === VCAP\Jobs::percent( array( 'done' => 100, 'total' => 250 ) ) && 100 === VCAP\Jobs::percent( array( 'done' => 260, 'total' => 250 ) ) && 0 === VCAP\Jobs::percent( array( 'done' => 0, 'total' => 0 ) ) );
check( 'Q2: prefix/suffix/separator only take letters, digits, dot, hyphen, underscore', VCAP\CodeGenerator::valid_affix( 'SHOP_2026.X-' ) && ! VCAP\CodeGenerator::valid_affix( 'B&B' ) && ! VCAP\CodeGenerator::valid_affix( 'Ä' ) && ! VCAP\CodeGenerator::valid_affix( str_repeat( 'A', 21 ) ) && ! VCAP\CodeGenerator::valid_affix( '----', true ) && VCAP\CodeGenerator::valid_affix( '', true ) );
$built = VCAP\CodeGenerator::build( array( 'prefix' => 'B&B Hotel', 'suffix' => str_repeat( 'Z', 40 ), 'order_ref' => str_repeat( '9', 40 ), 'length' => 40, 'separator' => ' & ' ) );
check( 'Q2: generated codes never contain characters WooCommerce changes and stay within 100 characters', strlen( $built ) <= 100 && wc_format_coupon_code( $built ) === strtolower( $built ) && str_starts_with( $built, 'BBHotel' ), $built );

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
	// U11: glossary – store credit "Kundenguthaben", a voucher's balance "Restwert", coupon "WooCommerce-Code",
	// reward "Prämie", buyer "Käufer"; bare "Guthaben" is neither and not used.
	$u11_off    = array();
	$u11_credit = array();
	foreach ( $po as $key => $translation ) {
		$source = substr( $key, strpos( $key, "\x04" ) + 1 );
		foreach ( $translation[0] as $form ) {
			if ( preg_match( '/(?<![Kk]unden)(?<![Kk]unden-)[Gg]uthaben|WooCommerce-Gutschein|kaufenden? Person|[Bb]elohnung|gespeichert\w* Guthaben/u', $form, $u11_m ) ) {
				$u11_off[] = $u11_m[0] . ': ' . $source;
			}
			if ( preg_match( '/store credit/i', $source ) && ! str_contains( $form, 'Kundenguthaben' ) ) {
				$u11_credit[] = $source;
			}
		}
	}
	check( "U11: {$locale} uses the glossary (no bare \"Guthaben\", \"WooCommerce-Gutschein\", \"kaufende Person\", \"Belohnung\")", ! $u11_off, implode( ' | ', array_slice( $u11_off, 0, 5 ) ) );
	check( "U11: {$locale} translates every \"store credit\" as \"Kundenguthaben\"", ! $u11_credit, implode( ' | ', array_slice( array_unique( $u11_credit ), 0, 5 ) ) );
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
// A switcher without a rate list (Aelia, WCML) returns amounts of a currency it does not know unchanged: that
// currency has no rate (an order in it, e.g. created by hand, must not be taken 1:1), the configured one has.
$GLOBALS['vcap_test_filters']['wc_aelia_cs_convert'] = array( array( static fn( $amount, $from ) => 'USD' === $from ? $amount / 2 : $amount, 3 ) );
check( 'Aelia: a configured currency converts (50 USD = 25 EUR)', VCAP\Compat\MultiCurrency::can_convert( 'USD' ) && 25.0 === VCAP\Compat\MultiCurrency::to_base( 50.0, 'USD' ) );
check( 'Aelia: a currency it returns unchanged is not converted 1:1', ! VCAP\Compat\MultiCurrency::can_convert( 'PLN' ) && null === VCAP\Compat\MultiCurrency::to_base( 100.0, 'PLN' ) );
unset( $GLOBALS['vcap_test_filters']['wc_aelia_cs_convert'] );
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
echo "Languages and customer mails (multilingual shops, recipients, delivery notes)\n";
$wpml = (string) file_get_contents( dirname( __DIR__ ) . '/wpml-config.xml' );
$product_metas = array_map( array( ProductConfig::class, 'meta_key' ), array_keys( ProductConfig::DEFAULTS ) );
$product_metas = array_merge( $product_metas, array( '_vcap_enabled', VCAP\TopUp::PRODUCT_META ) );
$unlisted      = array_values( array_filter( $product_metas, static fn( $key ) => ! preg_match( '#<custom-field action="(copy|copy-once|translate)">' . preg_quote( $key, '#' ) . '</custom-field>#', $wpml ) ) );
check( 'wpml-config.xml lists every voucher setting of a product (ProductConfig::DEFAULTS, enabled, top-up)', ! $unlisted, implode( ', ', $unlisted ) );
check( 'wpml-config.xml: redemption conditions and event location are translated per language', str_contains( $wpml, '<custom-field action="translate">_vcap_redemption_terms</custom-field>' ) && str_contains( $wpml, '<custom-field action="translate">_vcap_event_location</custom-field>' ) );
$email_ids = array();
foreach ( glob( dirname( __DIR__ ) . '/src/Emails/*.php' ) as $file ) {
	if ( preg_match_all( "/this->id\\s*=\\s*(?:\\\$this->id \\? \\\$this->id : )?'([a-z_]+)'/", (string) file_get_contents( $file ), $m ) ) {
		$email_ids = array_merge( $email_ids, $m[1] );
	}
}
sort( $email_ids );
$known_ids = VCAP\Compat\Multilingual::EMAIL_IDS;
sort( $known_ids );
check( 'every customer email of the plugin is in the list of translatable email texts', $email_ids === $known_ids, implode( ',', $email_ids ) );
$admin_texts = true;
foreach ( VCAP\Compat\Multilingual::OPTIONS as $option ) {
	$admin_texts = $admin_texts && str_contains( $wpml, '<key name="' . $option . '" />' );
}
foreach ( VCAP\Compat\Multilingual::EMAIL_IDS as $id ) {
	$block       = preg_match( '#<key name="woocommerce_' . $id . '_settings">(.*?)</key>\s*(?=<key name="woocommerce_|</admin-texts>)#s', $wpml, $m ) ? $m[1] : '';
	foreach ( VCAP\Compat\Multilingual::EMAIL_KEYS as $key ) {
		$admin_texts = $admin_texts && str_contains( $block, '<key name="' . $key . '" />' );
	}
}
check( 'wpml-config.xml registers the redemption conditions and the email texts for string translation', $admin_texts );

check( 'the email locale falls back to the site language', 'de_DE' === VCAP\Compat\Multilingual::email_locale( null ) );
add_filter( 'vcap_order_language', static fn( $lang ) => 'en' );
add_filter( 'vcap_email_locale', static fn( $locale ) => 'en_GB', 10, 1 );
check( 'filter "vcap_email_locale" chooses the locale of a customer mail', 'en_GB' === VCAP\Compat\Multilingual::email_locale( null ) && 'en' === VCAP\Compat\Multilingual::order_language( null ) );
unset( $GLOBALS['vcap_test_filters']['vcap_email_locale'], $GLOBALS['vcap_test_filters']['vcap_order_language'] );
$state = VCAP\Compat\Multilingual::switch_to( '', 'fr' );
$inner = VCAP\Compat\Multilingual::mail_language();
VCAP\Compat\Multilingual::restore( $state );
check( 'the language of a mail is known while it is prepared and forgotten afterwards', 'fr' === $inner && '' === VCAP\Compat\Multilingual::mail_language() && $state['ready'] );

$intro = static fn() => __( 'Hi {buyer_name}, thank you for your order #{order_number}. Your voucher for {product_name} is ready.', 'voucher-captain' );
check( 'the translation files are read for the default texts', count( VCAP\I18n::read_mo( dirname( __DIR__ ) . '/languages/voucher-captain-de_DE.mo' ) ) > 1000 );
check( 'a saved default email body is recognised in English and in German', VCAP\I18n::is_default_text( ' Hi {buyer_name}, thank you for your order #{order_number}. Your voucher for {product_name} is ready.', $intro ) && VCAP\I18n::is_default_text( 'Hallo {buyer_name}, vielen Dank für Ihre Bestellung #{order_number}. Ihr Gutschein für {product_name} ist bereit.', $intro ) && VCAP\I18n::is_default_text( '', $intro ) );
check( 'an email body of the shop is kept', ! VCAP\I18n::is_default_text( 'Danke für Ihren Einkauf, {buyer_name}!', $intro ) );

check( 'stored UTC dates are shown in the shop time zone with wp_date()', '24.12.2026 09:00' === VCAP\LocalDate::from_utc( '2026-12-24 08:00:00', 'd.m.Y H:i' ) && '' === VCAP\LocalDate::from_utc( '', 'd.m.Y' ) && '' === VCAP\LocalDate::from_utc( null, 'd.m.Y' ) );
check( 'percentages use the decimal separator of the shop', '12,5%' === VCAP\Format::percent( '12.5' ) && '15%' === VCAP\Format::percent( 15 ) );

$gift                  = clone $v;
$gift->recipient_name  = '';
$gift->buyer_name      = 'Max Käufer';
$names                 = Placeholders::values( $gift );
check( '{recipient_name} is empty without a recipient name, {holder_name} names the buyer', '' === $names['recipient_name'] && 'Max Käufer' === $names['holder_name'] && 'Max Käufer' === $names['buyer_name'] );
check( 'a text whose placeholders are all empty is recognised, other texts are not', Placeholders::all_empty( 'For: {recipient_name}', $gift ) && ! Placeholders::all_empty( 'For: {recipient_name} {buyer_name}', $gift ) && ! Placeholders::all_empty( 'GIFT VOUCHER', $gift ) );
$pdf_gift = vcap()->pdf->html( $gift, TemplatePostType::default_designs()['giftcard'] );
$pdf_named = vcap()->pdf->html( $v, TemplatePostType::default_designs()['giftcard'] );
check( 'the PDF leaves out "For:" without a recipient name and never names the buyer there', ! str_contains( $pdf_gift, 'For:' ) && ! str_contains( $pdf_gift, 'Max Käufer' ) && str_contains( $pdf_named, 'For: Jörg Empfänger' ) );
check( 'the ticket names its holder (recipient, else buyer)', str_contains( vcap()->pdf->html( $gift, TemplatePostType::default_designs()['ticket'] ), 'Guest: Max Käufer' ) );

$routed                  = new Voucher();
$routed->send_to         = 'recipient';
$routed->buyer_email     = 'Anna@Example.com';
$routed->recipient_email = ' anna@example.com';
check( 'a gift to the buyer\'s own address goes only to the buyer', ! Delivery::to_recipient( $routed ) && Delivery::same_address( 'Anna@Example.com ', 'anna@example.com' ) );
$delivery = ( new ReflectionClass( Delivery::class ) )->newInstanceWithoutConstructor();
check( '… and the buyer is the one who must receive it', array( 'buyer' ) === $delivery->required_roles( $routed ) );
$routed->recipient_email = 'ben@example.com';
check( 'a gift to another address goes to the recipient', Delivery::to_recipient( $routed ) && array( 'recipient' ) === $delivery->required_roles( $routed ) );
check( 'additional recipients: valid, without the main address and duplicates, each separately', array( 'archive@shop.test', 'Office@shop.test' ) === Delivery::extra_addresses( 'archive@shop.test, nonsense, BEN@example.com, Office@shop.test, office@shop.test', 'ben@example.com' ) );

check( 'line breaks of a message count as one character (CRLF → LF)', "Hallo\nWelt\nzwei" === VCAP\Fields::normalize_newlines( "Hallo\r\nWelt\rzwei" ) && 300 === mb_strlen( VCAP\Fields::normalize_newlines( str_repeat( "ab\r\n", 100 ) ) ) );

$cfg = array( 'send_to' => 'recipient' ) + ProductConfig::DEFAULTS;
check( 'the buyer learns where and when a gift goes', 'The voucher will be emailed to ben@example.com on 24.12.2026.' === VCAP\NextSteps::summary( $cfg, array( 'recipient_email' => array( 'value' => 'ben@example.com' ) ), 'anna@example.com', '2026-12-24 08:00:00' ) );
check( '… right after payment, to the buyer when no other recipient is entered', 'The voucher will be emailed to you right after payment.' === VCAP\NextSteps::summary( $cfg, array( 'recipient_email' => array( 'value' => 'anna@example.com' ) ), 'anna@example.com', null ) && 'The voucher will be emailed to ben@example.com right after payment.' === VCAP\NextSteps::summary( $cfg, array( 'recipient_email' => array( 'value' => 'ben@example.com' ) ), '', null ) );
check( 'the product page names a delivery delay', 'The voucher is sent by email 3 days after the purchase.' === VCAP\NextSteps::product_hint( array( 'delivery' => 'delay', 'delivery_delay_days' => 3 ) + ProductConfig::DEFAULTS ) && '' === VCAP\NextSteps::product_hint( ProductConfig::DEFAULTS ) );

// Translator comments belong to the string with the placeholders (make-pot attaches a comment to the next string).
$pot_file = $out . '/check.pot';
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/make-pot.php' ) . ' ' . escapeshellarg( $pot_file ), $ignored, $pot_status );
$uncommented = array();
$misplaced   = array();
foreach ( preg_split( "/\n\s*\n/", (string) @file_get_contents( $pot_file ) ) as $block ) {
	if ( ! preg_match( '/^msgid "(.+)"$/m', $block, $m ) ) {
		continue;
	}
	$has_placeholder = (bool) preg_match( '/%(\d+\$)?[sdf]/', $m[1] );
	$has_comment     = (bool) preg_match( '/^#\. translators:/mi', $block );
	if ( $has_placeholder && ! $has_comment ) {
		$uncommented[] = $m[1];
	} elseif ( ! $has_placeholder && $has_comment ) {
		$misplaced[] = $m[1];
	}
}
check( 'every text with placeholders has a translators comment, and no comment sits on a text without one', 0 === $pot_status && ! $uncommented && ! $misplaced, implode( ' | ', array_slice( array_merge( $uncommented, $misplaced ), 0, 5 ) ) );
echo "Release tooling (workflows, SBOM, bundled libraries)\n";
$root     = dirname( __DIR__ );
$smoke_yml = (string) file_get_contents( "$root/.github/workflows/plugin-smoke.yml" );
$release_yml = (string) file_get_contents( "$root/.github/workflows/release.yml" );
check( 'release.yml builds one ZIP (no editions, no licence server tooling or manifest)', str_contains( $release_yml, 'bin/build.sh' ) && ! preg_match( '/VCAP_EDITION|VCAP_LICENSE_|wp-license|wplc|premium/', $release_yml . $smoke_yml ) );
check( 'release.yml refuses a tag that is not on main', str_contains( $release_yml, 'merge-base --is-ancestor "$SHA" origin/main' ) );
check( 'ci-green checks every job of the CI run, not only the run conclusion', str_contains( $release_yml, 'actions/runs/${id}/jobs' ) && str_contains( $release_yml, 'REPORT_ONLY: plugin-check' ) );
check( 'only the Pass Captain work branch may be skipped; the pinned release and main must be downloaded', (bool) preg_match( '/pass_captain_ref: v\d+\.\d+\.\d+\s*\n\s*archive: [0-9a-f]{40}\s*\n\s*required: true/', $smoke_yml ) && (bool) preg_match( '/pass_captain_ref: main\s*\n\s*archive: refs\/heads\/main\s*\n\s*required: true/', $smoke_yml ) && str_contains( $smoke_yml, 'if [ "${{ matrix.required }}" = "true" ]; then' ) );
check( 'quality-wide blocks on WordPress.Security findings and on growing findings (no continue-on-error)', (bool) preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*WordPress\.Security\.EscapeOutput(?:(?!\n  [a-z-]+:\n).)*Findings must not grow/s', $smoke_yml ) && ! preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*continue-on-error/s', $smoke_yml ) );
preg_match( '/\n  ecosystem:\n(.*?)\n  [a-z-]+:\n/s', $smoke_yml, $r15 );
check( 'R15: the ecosystem gate installs pinned plugin versions (every matrix entry has one, the install uses it)', isset( $r15[1] ) && substr_count( $r15[1], '- key:' ) >= 5 && substr_count( $r15[1], '- key:' ) === preg_match_all( "/\n\s+version: '\d+(\.\d+)+'/", $r15[1] ) && str_contains( $r15[1], 'plugin install ${{ matrix.slug }} --version=${{ matrix.version }}' ) );
$smoke_release = (string) file_get_contents( "$root/.github/workflows/release-smoke.yml" );
check( 'the release smoke is manual and installs the draft ZIP over the previous release', str_contains( $smoke_release, "on:\n  workflow_dispatch:" ) && ! preg_match( '/^\s+(push|pull_request|schedule):/m', $smoke_release ) && str_contains( $smoke_release, 'tests/release-smoke.php' ) && ! preg_match( '/secrets\.|licen[cs]e server/i', $smoke_release ) && is_file( "$root/tests/release-smoke.php" ) );
check( 'CI runs the license guard in the smoke job', str_contains( $smoke_yml, 'php tests/license-guard.php' ) && is_file( "$root/tests/license-guard.php" ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$root/tests/license-guard.php" ) . ' 2>&1', $guard_log, $guard_status );
check( 'free core: no licensing or update code in the shipped files (tests/license-guard.php)', 0 === $guard_status, implode( ' ', $guard_log ) );
$plugin_src = (string) file_get_contents( "$root/src/Plugin.php" );
check( 'free core: top-up, referrals, ticket vouchers and the shared scanner are registered unconditionally', ! is_dir( "$root/src/Licensing" ) && ! is_file( "$root/config/premium-features.php" ) && ! str_contains( $plugin_src, 'Licensing' ) && str_contains( $plugin_src, '( new TopUp( $this->vouchers, $this->coupons ) )->register();' ) && str_contains( $plugin_src, '$referrals->register();' ) && str_contains( $plugin_src, '$this->passcaptain_tickets->register();' ) && str_contains( $plugin_src, '( new Compat\PassCaptainScanner() )->register();' ) );
check( 'free core: license data of earlier builds is removed on upgrade and uninstall', in_array( 'vcap_wplc_credentials', VCAP\Install::LEGACY_LICENSE_OPTIONS, true ) && 'vcap_license_refresh' === VCAP\Install::LEGACY_LICENSE_HOOK && str_contains( (string) file_get_contents( "$root/uninstall.php" ), 'vcap_uninstall_license_data();' ) );
$bundled = json_decode( (string) file_get_contents( "$root/bin/bundled-assets.json" ), true );
$jsqr    = is_array( $bundled ) ? current( array_filter( $bundled, static fn( $a ) => 'jsqr' === $a['name'] ) ) : null;
check( 'bin/bundled-assets.json records the shipped jsQR file (SHA-256) – a replaced file needs a new version entry', $jsqr && hash_file( 'sha256', "$root/{$jsqr['path']}" ) === $jsqr['sha256'] );
check( 'the scanner enqueues jsQR with the version recorded for the SBOM and the vulnerability check', $jsqr && str_contains( (string) file_get_contents( "$root/src/Admin/Scanner.php" ), "'assets/vendor/jsqr/jsQR.js', array(), '{$jsqr['version']}'" ) );
$sbom_file = $out . '/sbom.cdx.json';
@unlink( $sbom_file );
shell_exec( 'SOURCE_DATE_EPOCH=1700000000 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$root/bin/sbom.php" ) . ' ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $sbom_file ) . ' 2>&1' );
$sbom  = is_file( $sbom_file ) ? json_decode( (string) file_get_contents( $sbom_file ), true ) : null;
$purls = is_array( $sbom ) ? array_column( $sbom['components'], 'purl' ) : array();
check( 'SBOM: CycloneDX 1.5 with the plugin, its Composer packages and jsQR (npm purl with SHA-256)', is_array( $sbom ) && 'CycloneDX' === $sbom['bomFormat'] && '1.5' === $sbom['specVersion'] && ( preg_match( '/^ \* Version:\s*(\S+)/m', (string) file_get_contents( "$root/voucher-captain.php" ), $vm ) ? $vm[1] : '?' ) === $sbom['metadata']['component']['version'] && in_array( 'pkg:npm/jsqr@1.4.0', $purls, true ) && (bool) preg_grep( '#^pkg:composer/dompdf/dompdf@#', $purls ) && ! preg_grep( '#phpstan|php-stubs#', $purls ), json_encode( $purls ) );
$sbom_again = (string) shell_exec( 'SOURCE_DATE_EPOCH=1700000000 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$root/bin/sbom.php" ) . ' ' . escapeshellarg( $root ) . ' 2>&1' );
check( 'SBOM is reproducible (same sources and SOURCE_DATE_EPOCH, same file and serial number)', is_file( $sbom_file ) && file_get_contents( $sbom_file ) === $sbom_again );
echo "Storefront output (shortcodes, blocks, forms)\n";
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) { return str_contains( (string) $content, '[' . $tag ); }
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id ) { return $GLOBALS['vcap_test_posts'][ (int) $id ][ $field ] ?? ''; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id = null ) { return $GLOBALS['vcap_test_posts'][ (int) $id ]['post_type'] ?? false; }
}
if ( ! function_exists( 'parse_blocks' ) ) {
	// Only what the test content uses: synced pattern references at the top level and inside a group.
	function parse_blocks( $content ) {
		$blocks = array();
		preg_match_all( '/<!-- wp:block \{"ref":(\d+)\} \/-->/', (string) $content, $m );
		foreach ( $m[1] as $ref ) {
			$blocks[] = array( 'blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => (int) $ref ), 'innerBlocks' => array() ) ) );
		}
		return $blocks;
	}
}
$escaped = VCAP\Shortcodes::escape_shortcodes( '<input value="[vcap_referral]" /><script>var a = [1, 2];</script><p>[x]</p>' );
check( 'block output: brackets become entities, so do_shortcode() runs no shortcode from visitor text', '<input value="&#91;vcap_referral&#93;" /><script>var a = [1, 2];</script><p>&#91;x&#93;</p>' === $escaped, $escaped );
check( 'visitor text in attributes and text has its brackets escaped', '&#91;vcap_referral&#93; &quot;x&quot;' === VCAP\Shortcodes::esc_attr_text( '[vcap_referral] "x"' ) && '&lt;b&gt;&#91;a&#93;' === VCAP\Shortcodes::esc_html_text( '<b>[a]' ) );
check( 'a code from a redeem link keeps only code characters', 'vcap_referral' === VCAP\Shortcodes::clean_code( '[vcap_referral]' ) && 'GIFT-ABC 12' === VCAP\Shortcodes::clean_code( " GIFT-ABC 12\"'<>`\\ " ) && 100 === strlen( VCAP\Shortcodes::clean_code( str_repeat( 'A', 150 ) ) ) );
$first  = VCAP\Shortcodes::unique_id( 'vcap-test-field' );
$second = VCAP\Shortcodes::unique_id( 'vcap-test-field' );
check( 'ids are unique on the page and the first form keeps its id', 'vcap-test-field' === $first && 'vcap-test-field-2' === $second && 'vcap-test-field-3' === VCAP\Shortcodes::unique_id( 'vcap-test-field' ) );
$suffix = VCAP\Shortcodes::voucher_suffix( 'GIFT-[1]' );
check( 'buttons of a voucher list name the voucher in hidden text, not in aria-label (label in name)', '<span class="screen-reader-text"> – voucher GIFT-&#91;1&#93;</span>' === $suffix, $suffix );
check( 'shortcode output is wrapped in div.woocommerce (WooCommerce styles on any page)', '<div class="woocommerce" id="vcap-redeem"><form></form></div>' === VCAP\Shortcodes::wrap( '<form></form>', 'vcap-redeem' ) && 'button' === VCAP\Shortcodes::button_class() );

$GLOBALS['vcap_test_posts'] = array(
	501 => array( 'post_type' => 'wp_block', 'post_content' => '<!-- wp:vcap/redeem /-->' ),
	502 => array( 'post_type' => 'wp_block', 'post_content' => '<!-- wp:block {"ref":501} /-->' ),
	503 => array( 'post_type' => 'page', 'post_content' => '<!-- wp:block {"ref":502} /-->' ),
	504 => array( 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
	505 => array( 'post_type' => 'page', 'post_content' => '' ),
	506 => array( 'post_type' => 'post', 'post_content' => '<!-- wp:vcap/redeem /-->' ),
	507 => array( 'post_type' => 'page', 'post_content' => '<!-- wp:block {"ref":506} /-->' ),
);
$GLOBALS['vcap_test_meta'][505]['_elementor_data'] = '[{"widgetType":"shortcode","settings":{"shortcode":"[vcap_redeem]"}}]';
check( 'the redeem form is found in synced patterns, also nested ones', VCAP\Shortcodes::page_has_redeem_form( 503 ) && ! VCAP\Shortcodes::page_has_redeem_form( 504 ) );
check( 'only synced patterns are followed, not other posts', ! VCAP\Shortcodes::page_has_redeem_form( 507 ) );
check( 'the redeem form is found in Elementor data', VCAP\Shortcodes::page_has_redeem_form( 505 ) );
unset( $GLOBALS['vcap_test_posts'], $GLOBALS['vcap_test_meta'][505] );

$amount_config = array( 'custom_presets' => '25, 50;100' ) + ProductConfig::DEFAULTS;
check( 'suggested amount chosen without JavaScript (radio button) is the amount', '50' === VCAP\CustomAmount::posted_amount( $amount_config, array( 'vcap_amount_preset' => '50', 'vcap_amount' => '' ) ) );
check( 'a typed amount wins over the chosen suggestion', '37' === VCAP\CustomAmount::posted_amount( $amount_config, array( 'vcap_amount_preset' => '50', 'vcap_amount' => '37' ) ) );
check( 'only configured suggestions count', '' === VCAP\CustomAmount::posted_amount( $amount_config, array( 'vcap_amount_preset' => '5000' ) ) && '' === VCAP\CustomAmount::posted_amount( $amount_config, array( 'vcap_amount_preset' => array( '50' ) ) ) );

echo "Strict amounts (Q7, H12, H13)\n";
$amt = static fn( $raw, array $o = array() ) => VCAP\Amount::parse( $raw, $o + array( 'decimals' => 2, 'decimal_sep' => '.', 'strip' => array( 'EUR', '€' ) ) );
check( 'Q7: "2,50" in a shop with a decimal point is 2.50, never 250', 250 === $amt( '2,50' )['minor'] && 2.5 === $amt( '2,50' )['value'] );
check( 'Q7: "12,50 €", "€ 1.234,50" and "1 234,50" are read like the scanner', 1250 === $amt( '12,50 €' )['minor'] && 123450 === $amt( '€ 1.234,50' )['minor'] && 123450 === $amt( '1 234,50' )['minor'] && 123450 === $amt( "1'234.50" )['minor'] );
check( 'Q7: a Unicode minus or dash is "negative", not +5', 'negative' === $amt( '−5' )['error'] && 'negative' === $amt( '–5' )['error'] && 'negative' === $amt( '-5' )['error'] );
check( 'Q7: with a sign allowed, "−5" is -5 and "+10" is 10', -5.0 === $amt( '−5', array( 'allow_negative' => true ) )['value'] && 10.0 === $amt( '+10', array( 'allow_negative' => true ) )['value'] && 'invalid' === $amt( '+10' )['error'] );
check( 'Q7: "1e3", "12,50; 25", "1..5" and "abc" are invalid instead of 13 / 12 / guesses', 'invalid' === $amt( '1e3' )['error'] && 'invalid' === $amt( '12,50; 25' )['error'] && 'invalid' === $amt( '1..5' )['error'] && 'invalid' === $amt( 'abc' )['error'] && 'invalid' === $amt( array( '5' ) )['error'] );
check( 'Q7: "1.500" is ambiguous with two decimals, 1.5 with three decimals and a decimal point', 'ambiguous' === $amt( '1.500' )['error'] && 1500 === $amt( '1.500', array( 'decimals' => 3 ) )['minor'] && 'ambiguous' === $amt( '1,500', array( 'decimals' => 3 ) )['error'] );
check( 'Q7: too many decimals, zero and empty are refused with their reason', 'decimals' === $amt( '0,999' )['error'] && 'zero' === $amt( '0' )['error'] && 'empty' === $amt( ' € ' )['error'] && $amt( '0', array( 'allow_zero' => true ) )['ok'] );
check( 'Q7: the reason is explained (e.g. ambiguous with an example)', false !== strpos( VCAP\Amount::message( 'ambiguous', 2 ), 'ambiguous' ) && false !== strpos( VCAP\Amount::message( 'decimals', 0 ), 'no decimal' ) );
check( 'H13: suggested amounts are separated by semicolons, "12,50" stays one amount', array( 12.5, 25.0 ) === VCAP\Amount::parse_list( '12,50; 25' )['values'] && array( 12.5, 25.0, 50.0 ) === VCAP\Amount::parse_list( "12,50\n25\n50" )['values'] );
check( 'H13: legacy comma lists still work ("10, 25, 50", "10,25,50")', array( 10.0, 25.0, 50.0 ) === VCAP\Amount::parse_list( '10, 25, 50' )['values'] && array( 10.0, 25.0, 50.0 ) === VCAP\Amount::parse_list( '10,25,50' )['values'] );
$preset_config = array( 'amount_source' => 'custom', 'custom_presets' => '12,50; 25; zehn', 'custom_min' => '', 'custom_max' => '', 'custom_step' => '0.5' ) + ProductConfig::DEFAULTS;
check( 'H13: presets become plain numbers; an unreadable entry is reported on the product screen', array( '12.5', '25' ) === VCAP\CustomAmount::presets( $preset_config ) && (bool) array_filter( VCAP\CustomAmount::config_errors( $preset_config ), static fn( $e ) => false !== strpos( $e, '"zehn"' ) && false !== strpos( $e, 'semicolon' ) ), json_encode( VCAP\CustomAmount::config_errors( $preset_config ) ) );
check( 'Q7: a typed "12,50" is the amount 12.50; "1e3" is handed on and refused', '12.5' === VCAP\CustomAmount::posted_amount( $preset_config, array( 'vcap_amount' => '12,50' ) ) && null !== VCAP\CustomAmount::error_for( $preset_config, VCAP\CustomAmount::posted_amount( $preset_config, array( 'vcap_amount' => '1e3' ) ) ) );
$range_config = array( 'amount_source' => 'custom', 'custom_presets' => '', 'custom_min' => '10', 'custom_max' => '200', 'custom_step' => '5' ) + ProductConfig::DEFAULTS;
$range_error  = (string) VCAP\CustomAmount::error_for( $range_config, '250' );
check( 'H12: the range error names both limits', false !== strpos( $range_error, '10,00' ) && false !== strpos( $range_error, '200,00' ), $range_error );
check( 'H12: a minimum or maximum alone is named as well', false !== strpos( (string) VCAP\CustomAmount::error_for( array( 'custom_max' => '' ) + $range_config, '5' ), 'at least 10,00' ) && false !== strpos( (string) VCAP\CustomAmount::error_for( array( 'custom_min' => '' ) + $range_config, '250' ), 'more than 200,00' ) );
$step_error = (string) VCAP\CustomAmount::error_for( $range_config, '12' );
check( 'H12: the step error names the step and the nearest allowed amounts', false !== strpos( $step_error, 'steps of 5,00' ) && false !== strpos( $step_error, '10,00' ) && false !== strpos( $step_error, '15,00' ), $step_error );
check( 'Q7/H12: an ambiguous amount says why instead of "outside the range"', false !== strpos( (string) VCAP\CustomAmount::error_for( $range_config, '1.500' ), 'ambiguous' ) );
check( 'Q7: number fields are read strictly ("2,50" = 2.5, "1e3" refused)', '2.5' === VCAP\Fields::parse_number( '2,50' ) && null === VCAP\Fields::parse_number( '1e3' ) && '-3' === VCAP\Fields::parse_number( '−3' ) );

echo "CSV export format (A15)\n";
$w15_readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
$w15_main   = (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' );
preg_match( "/define\\( 'VCAP_DB_VERSION', '([0-9.]+)' \\)/", $w15_main, $w15_db );
preg_match( '/== Upgrade Notice ==\s*= [0-9.]+ =\s*(.+)/', $w15_readme, $w15_notice );
check( 'AP-W1-5: readme names the filter vcap_pdf_remote_hosts instead of "PDFs never load remote images"', str_contains( $w15_readme, '`vcap_pdf_remote_hosts`' ) && ! str_contains( $w15_readme, 'never load remote images' ) );
check( 'AP-W1-5: the upgrade notice names the current database version', isset( $w15_db[1], $w15_notice[1] ) && str_contains( $w15_notice[1], 'database is updated to version ' . $w15_db[1] ), $w15_notice[1] ?? '' );
check( 'AP-W1-6: the new product editor is declared incompatible', str_contains( $w15_main, "declare_compatibility( 'product_block_editor', __FILE__, false )" ) );
check( 'AP-W1-4: CSV downloads are sent with "X-Content-Type-Options: nosniff" (attachment, text/csv)', in_array( 'X-Content-Type-Options: nosniff', VCAP\Csv::headers( 'vouchers.csv' ), true ) && in_array( 'Content-Disposition: attachment; filename="vouchers.csv"', VCAP\Csv::headers( 'vouchers.csv' ), true ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Csv.php' ), 'foreach ( self::headers( $filename ) as $line )' ) );
check( 'A15: decimal comma for fr/it/es/nl/pl/de, point for de_CH(_informal) and en', ',' === VCAP\Csv::locale_decimal( 'fr_FR' ) && ',' === VCAP\Csv::locale_decimal( 'it_IT' ) && ',' === VCAP\Csv::locale_decimal( 'es_ES' ) && ',' === VCAP\Csv::locale_decimal( 'nl_NL' ) && ',' === VCAP\Csv::locale_decimal( 'pl_PL' ) && ',' === VCAP\Csv::locale_decimal( 'de_DE_formal' ) && '.' === VCAP\Csv::locale_decimal( 'de_CH_informal' ) && '.' === VCAP\Csv::locale_decimal( 'de_CH' ) && '.' === VCAP\Csv::locale_decimal( 'en_US' ) && '.' === VCAP\Csv::locale_decimal( 'es_MX' ) );
$GLOBALS['wp_locale'] = (object) array( 'number_format' => array( 'decimal_point' => ',', 'thousands_sep' => ' ' ) );
check( 'A15: an unknown language follows WordPress\' number format of the current locale', ',' === VCAP\Csv::locale_decimal( 'xx_XX' ) );
unset( $GLOBALS['wp_locale'] );
VCAP\Csv::reset();
check( 'A15: a decimal comma comes with ";" as the field separator', array( 'separator' => ';', 'decimal' => ',' ) === VCAP\Csv::format() );
$report_csv = VCAP\Reports::csv_rows( array( array( 'type' => 'coupon', 'name' => 'X (JPY)', 'issued' => null, 'uses' => 1, 'orders' => 1, 'discount' => 500.0, 'revenue' => 4500.0, 'currency' => 'JPY' ), array( 'type' => 'coupon', 'name' => 'Y', 'issued' => null, 'uses' => 1, 'orders' => 1, 'discount' => 5.0, 'revenue' => 45.0 ) ) );
check( 'A15: the report CSV names each row\'s own currency (with its decimals)', 'JPY' === $report_csv[1][7] && '500' === $report_csv[1][5] && 'EUR' === $report_csv[2][7] && '5,00' === $report_csv[2][5], json_encode( $report_csv ) );
VCAP\Csv::reset();

echo "Expiry date on the edit form (Q13)\n";
$pe   = array( VCAP\Admin\VoucherAdmin::class, 'parse_expiry' );
$next = ( (int) wp_date( 'Y' ) + 1 ) . '-06-30';
check( 'Q13: a plausible date is taken', array( 'ok' => true, 'date' => $next, 'error' => '' ) === $pe( $next, false, '2030-01-01' ) );
check( 'Q13: "0026-12-31" (year typed as "26") and a date 60 years ahead are refused', ! $pe( '0026-12-31', false, '' )['ok'] && ! $pe( ( (int) wp_date( 'Y' ) + 60 ) . '-01-01', false, '' )['ok'] && ! $pe( '2027-02-30', false, '' )['ok'] );
check( 'Q13: an emptied field is not "never expires" – only the checkbox is', ! $pe( '', false, '2030-01-01' )['ok'] && array( 'ok' => true, 'date' => null, 'error' => '' ) === $pe( '', true, '2030-01-01' ) && $pe( '', false, '' )['ok'] );
check( 'Q13: an unchanged old date of an expired voucher can still be saved', array( 'ok' => true, 'date' => '2019-12-31', 'error' => '' ) === $pe( '2019-12-31', false, '2019-12-31' ) );

echo "Required field hints (H14)\n";
$req_defs = VCAP\Fields::builtin_definitions( array_merge( ProductConfig::DEFAULTS, array( 'builtin_fields' => array( 'recipient_email', 'recipient_email_required', 'delivery_date', 'delivery_date_required' ) ) ) );
check( 'H14: required recipient email and delivery date do not say "leave empty"', true === $req_defs['recipient_email']['required'] && false === stripos( $req_defs['recipient_email']['tooltip'], 'leave empty' ) && false === stripos( $req_defs['delivery_date']['tooltip'], 'leave empty' ) && '' !== $req_defs['delivery_date']['tooltip'], json_encode( array( $req_defs['recipient_email']['tooltip'], $req_defs['delivery_date']['tooltip'] ) ) );
$opt_defs = VCAP\Fields::builtin_definitions( array_merge( ProductConfig::DEFAULTS, array( 'builtin_fields' => array( 'recipient_email', 'delivery_date' ) ) ) );
check( 'H14: optional fields keep the "leave empty" hint', false !== stripos( $opt_defs['recipient_email']['tooltip'], 'leave empty' ) && false !== stripos( $opt_defs['delivery_date']['tooltip'], 'leave empty' ) );
$req_buyer = VCAP\Fields::builtin_definitions( array_merge( ProductConfig::DEFAULTS, array( 'send_to' => 'buyer', 'email_restrict' => 'recipient', 'builtin_fields' => array( 'recipient_email', 'recipient_email_required' ) ) ) );
check( 'H14: "buyer only" with a required address drops "(yours, if you leave it empty)"', false === stripos( $req_buyer['recipient_email']['tooltip'], 'leave it empty' ) && false !== strpos( $req_buyer['recipient_email']['tooltip'], 'Only this address' ) );

$GLOBALS['vcap_test_options']['vcap_terms_by_country'] = "AT: Austria terms\nDE: Germany terms";
$GLOBALS['vcap_test_options']['vcap_default_terms']    = 'General terms';
check( 'product pages list every country variant of the conditions (same HTML for every visitor, cacheable)', array( 'AT' => 'Austria terms', 'DE' => 'Germany terms', '' => 'General terms' ) === ProductConfig::terms_variants( ProductConfig::DEFAULTS ) );
check( 'the product\'s own conditions are the only variant', array( '' => 'Own' ) === ProductConfig::terms_variants( array( 'redemption_terms' => 'Own' ) + ProductConfig::DEFAULTS ) );
unset( $GLOBALS['vcap_test_options']['vcap_terms_by_country'], $GLOBALS['vcap_test_options']['vcap_default_terms'] );

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private string $method = 'GET', private string $route = '', private array $params = array() ) {}
		public function get_route() { return $this->route; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}
}
$promotions = new VCAP\Discounts\Repository();
( new ReflectionProperty( $promotions, 'cache' ) )->setValue( $promotions, array( 12 => array( 'id' => 12, 'title' => 'Summer sale', 'priority' => 0, 'rules' => array() ) ) );
$promotion_cart = new VCAP\Discounts\CartIntegration( new VCAP\Settings(), $promotions );
$refused        = $promotion_cart->refuse_removal( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/remove-coupon', array( 'code' => 'vcap-campaign-12' ) ) );
check( 'cart block: removing an automatic promotion is refused with a message', $refused instanceof WP_Error && 'vcap_promotion_not_removable' === $refused->get_error_code() && 400 === $refused->get_error_data()['status'] );
check( 'cart block: other coupons and routes are not affected', null === $promotion_cart->refuse_removal( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/remove-coupon', array( 'code' => 'summer10' ) ) ) && null === $promotion_cart->refuse_removal( null, null, new WP_REST_Request( 'POST', '/wc/store/v1/cart/apply-coupon', array( 'code' => 'vcap-campaign-12' ) ) ) );

// F7 (frontend.js, no JS runtime here): the counter is the field's description, not a live region; a separate
// live region speaks the characters left only near the limit and only after typing paused.
$front_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' );
check( 'F7: the character counter gets an id and is linked to its field by aria-describedby', str_contains( $front_js, "\$counter = \$( '<span class=\"vcap-counter\"></span>' ).attr( 'id', id );" ) && str_contains( $front_js, "\$input.attr( 'aria-describedby', described );" ) && ! preg_match( '/class="vcap-counter"[^>]*aria-live/', $front_js ) );
check( 'F7: a separate live region announces only at or below 20 characters left, debounced', str_contains( $front_js, 'var ANNOUNCE_BELOW = 20;' ) && str_contains( $front_js, 'class="vcap-counter-live screen-reader-text" aria-live="polite"' ) && str_contains( $front_js, "clearTimeout( \$input.data( 'vcapAnnounce' ) );" ) && str_contains( $front_js, "\$region.text( left <= ANNOUNCE_BELOW ? text : '' );" ) && (bool) preg_match( '/var ANNOUNCE_DELAY = [5-9]\d\d;/', $front_js ) );
check( 'F7: an unchanged counter text is not written again (no repeated announcement on focus)', str_contains( $front_js, 'if ( $counter.text() === text ) {' ) );
// G5 (blocks.js): the server-side preview is wrapped in wp.components.Disabled (no POSTs, no downloads from the editor).
$blocks_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/blocks.js' );
check( 'G5: the block preview (ServerSideRender) is wrapped in wp.components.Disabled', str_contains( $blocks_js, 'var components = wp.components || {};' ) && str_contains( $blocks_js, 'preview = el( components.Disabled, null, preview );' ) && str_contains( $blocks_js, 'var preview = ServerSideRender ? el( ServerSideRender, {' ) );

echo "Refunds, returns and top-ups (wave I)\n";
// T11: a top-up adds its face value like a new gift card – never what was paid after coupons.
$topup_cfg = ProductConfig::DEFAULTS;
check( 'top-up value: line price before discounts, gross where prices include tax', array( 119.0, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 100.0, 19.0, true ) );
check( 'top-up value in a net-price shop: the net price (like a new gift card), not net + VAT', array( 50.0, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 50.0, 9.5, false ) );
check( 'top-up value: a fixed amount per unit, already in the base currency', array( 50.0, true ) === VCAP\TopUp::line_value( array( 'amount_source' => 'fixed', 'amount' => '25' ) + $topup_cfg, 2, 0.0, 0.0, true ) );
check( 'top-up value: the amount the customer entered, per unit', array( 80.0, false ) === VCAP\TopUp::line_value( array( 'amount_source' => 'custom' ) + $topup_cfg, 2, 67.23, 12.77, true, '40' ) );
check( 'S4: a top-up adds no more than was paid, and an amount entered in another currency counts as the amount paid', array( 70.0, false ) === VCAP\TopUp::line_value( array( 'amount_source' => 'custom' ) + $topup_cfg, 2, 70.0, 0.0, true, '40' ) && array( 60.0, false ) === VCAP\TopUp::line_value( array( 'amount_source' => 'custom' ) + $topup_cfg, 2, 60.0, 0.0, true, '40', 'payment', 'EUR', 'USD' ) );
check( 'S4: a chosen amount is the face value only up to the amount paid per voucher and in the currency it was entered in', array( 50.0, false ) === VCAP\Issuer::custom_face_value( '50', 'EUR', 'EUR', 50.004, 2 ) && array( 42.5, true ) === VCAP\Issuer::custom_face_value( '50', 'EUR', 'EUR', 42.5, 2 ) && array( 45.0, true ) === VCAP\Issuer::custom_face_value( '50', 'USD', 'EUR', 45.0, 2 ) && array( 30.0, false ) === VCAP\Issuer::custom_face_value( '', '', 'EUR', 30.0, 2 ) && array( 20.0, false ) === VCAP\Issuer::custom_face_value( '20', '', 'EUR', 50.0, 2 ) && array( 0.0, true ) === VCAP\Issuer::custom_face_value( '50', 'EUR', 'EUR', 0.0, 2 ) );
check( 'S4: the currency is fixed with the amount when it is added to the cart and stored on the order line', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/CustomAmount.php' ), "\$data['vcap_currency'] = (string) get_woocommerce_currency();" ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/CustomAmount.php' ), 'self::CURRENCY_META' ) );
check( 'top-up paid completely with another gift card still adds its value (subtotal, not total)', VCAP\TopUp::line_value( $topup_cfg, 1, 100.0, 0.0, true )[0] > 0 );
// M12-12: the T7 gross rule is shared by new gift cards (Issuer::voucher_amount()) and top-ups.
check( 'M12-12: net-price shop, single-purpose top-up sold with VAT: the gross amount paid (50 + 19 % = 59.50)', array( 59.5, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 50.0, 9.5, false, '', 'single' ) );
check( 'M12-12: a multi-purpose top-up keeps the net value; an entered amount follows the same rule', array( 50.0, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 50.0, 9.5, false, '', 'payment' ) && array( 59.5, false ) === VCAP\TopUp::line_value( array( 'amount_source' => 'custom' ) + $topup_cfg, 1, 50.0, 9.5, false, '50', 'single' ) );
check( 'M12-12: Issuer::sold_with_vat_value(): unchanged for fixed amounts, single use, untaxed sales and tax-inclusive shops', 50.0 === VCAP\Issuer::sold_with_vat_value( 50.0, array( 'amount_source' => 'fixed', 'usage' => 'balance' ), 50.0, 9.5, 'single', false ) && 50.0 === VCAP\Issuer::sold_with_vat_value( 50.0, array( 'usage' => 'single' ), 50.0, 9.5, 'single', false ) && 50.0 === VCAP\Issuer::sold_with_vat_value( 50.0, array( 'usage' => 'balance' ), 50.0, 0.0, 'single', false ) && 50.0 === VCAP\Issuer::sold_with_vat_value( 50.0, array( 'usage' => 'balance' ), 50.0, 9.5, 'single', true ) );
check( 'M12-69: DATEV financial year start follows the setting (MMDD)', '20260101' === VCAP\DatevExport::financial_year_start( '2026-11-15', '0101' ) && '20250701' === VCAP\DatevExport::financial_year_start( '2026-03-01', '0701' ) && '20260701' === VCAP\DatevExport::financial_year_start( '2026-07-01', '0701' ) );
// E4: no top-up onto a card that expires soon.
$soon             = new Voucher();
$soon->code       = 'GIFT-SOON';
$soon->expires_at = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
$later            = clone $soon;
$later->expires_at = gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS );
$never            = clone $soon;
$never->expires_at = null;
check( 'top-up refused for a card valid less than 30 days, with its expiry date', '' !== VCAP\TopUp::remaining_validity_error( $soon ) && str_contains( VCAP\TopUp::remaining_validity_error( $soon ), wp_date( (string) get_option( 'date_format', 'Y-m-d' ), strtotime( $soon->expires_at . ' UTC' ) ) ) );
check( 'top-up allowed for a card valid long enough or without expiry', '' === VCAP\TopUp::remaining_validity_error( $later ) && '' === VCAP\TopUp::remaining_validity_error( $never ) );
add_filter( 'vcap_topup_min_remaining_days', static fn() => 0 );
check( 'minimum remaining validity 0 (filter): any valid card can be topped up', '' === VCAP\TopUp::remaining_validity_error( $soon ) );
unset( $GLOBALS['vcap_test_filters']['vcap_topup_min_remaining_days'] );
// E5: the customer confirmation names the masked card, amount, new balance and validity.
$conf_card             = new Voucher();
$conf_card->code       = 'GIFT-ABCD-1234';
$conf_card->balance    = 80.0;
$conf_card->currency   = 'EUR';
$conf_card->expires_at = '2029-12-31 22:59:59';
$conf = VCAP\TopUp::confirmation( $conf_card, 50.0 );
check( 'top-up confirmation: masked code, amount, new balance, valid until', str_contains( $conf, '…1234' ) && ! str_contains( $conf, 'ABCD' ) && str_contains( $conf, '50,00' ) && str_contains( $conf, '80,00' ) && str_contains( $conf, wp_date( (string) get_option( 'date_format', 'Y-m-d' ), strtotime( '2029-12-31 22:59:59 UTC' ) ) ), $conf );
// E2: booking back is capped by what the order charged to the voucher and was not booked back yet.
check( 'book back onto voucher: at most the charged amount minus earlier returns', 20.0 === VCAP\OrderReturn::cap( 50.0, 30.0 ) && 0.0 === VCAP\OrderReturn::cap( 50.0, 60.0 ) && 0.01 === VCAP\OrderReturn::cap( 0.3, 0.29 ) );
// P7, T6, E5: cases for the shop are kept per order and kind until resolved.
VCAP\Attention::add( 7, 'void:3', 'Voucher X could not be voided' );
VCAP\Attention::add( 7, 'void:3', 'Voucher X could not be voided (again)' );
VCAP\Attention::add( 8, 'topup:9', 'Top-up not booked' );
$attention = VCAP\Attention::all();
check( 'attention: one case per order and kind, updated in place', 2 === count( $attention ) && 'Voucher X could not be voided (again)' === ( $attention['7|void3']['text'] ?? '' ) && VCAP\Attention::has( 8, 'topup:9' ), wp_json_encode( $attention ) );
VCAP\Attention::resolve( 7, 'void:3' );
check( 'attention: a resolved case is gone, others stay', ! VCAP\Attention::has( 7, 'void:3' ) && VCAP\Attention::has( 8, 'topup:9' ) );
check( 'attention: Site Health reports open cases as critical', 'critical' === ( new VCAP\Attention() )->test()['status'] );
VCAP\Attention::resolve( 8, 'topup:9' );
check( 'attention: no case left – Site Health good', 'good' === ( new VCAP\Attention() )->test()['status'] && array() === VCAP\Attention::all() );
for ( $i = 1; $i <= 105; $i++ ) {
	VCAP\Attention::add( $i, 'void:' . $i, 'case ' . $i );
}
check( 'attention: at most 100 cases, the oldest dropped first', 100 === count( VCAP\Attention::all() ) && ! VCAP\Attention::has( 1, 'void:1' ) && VCAP\Attention::has( 105, 'void:105' ) );
delete_option( VCAP\Attention::OPTION );
// S12: every store-credit refund form gets its own one-time key.
check( 'refund as store credit: one-time form keys differ', VCAP\StoreCredit::request_key() !== VCAP\StoreCredit::request_key() && 32 === strlen( VCAP\StoreCredit::request_key() ) );
echo "Lifecycle and multisite (deactivation, upgrade, site tables)\n";
/** Records the queries of the code under test; $answers maps a query pattern (regex) to its result. */
final class VCAP_Test_Wpdb {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $options  = 'wp_options';
	public int $rows_affected = 0;
	public array $log     = array();
	public array $answers = array();
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $query ), $args );
	}
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function suppress_errors( $s = true ) { return false; }
	public function get_blog_prefix( $id = null ) { return 'wp_' . ( (int) $id > 1 ? (int) $id . '_' : '' ); }
	public function get_charset_collate() { return ''; }
	private function answer( $query, $default ) {
		$this->log[] = $query;
		foreach ( $this->answers as $pattern => $result ) {
			if ( preg_match( $pattern, $query ) ) {
				return is_callable( $result ) ? $result( $query ) : $result;
			}
		}
		return $default;
	}
	public function query( $q ) { return $this->answer( $q, 1 ); }
	public function get_var( $q ) { return $this->answer( $q, null ); }
	public function get_row( $q, $o = null ) { return $this->answer( $q, null ); }
	public function get_col( $q, $i = 0 ) { return $this->answer( $q, array() ); }
	public function get_results( $q, $o = null ) { return $this->answer( $q, array() ); }
}
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $id ) { $GLOBALS['vcap_test_cleaned'][] = (int) $id; }
	function wp_cache_delete( $k, $g = '' ) { return true; }
	function maybe_unserialize( $v ) { return is_string( $v ) && false !== ( $u = @unserialize( $v ) ) ? $u : $v; } // phpcs:ignore
}
$saved_wpdb      = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new VCAP_Test_Wpdb();

// M6: table names follow switch_to_blog() (the repository keeps no prefix of its own).
$repo                    = new VCAP\VoucherRepository();
$GLOBALS['wpdb']->prefix = 'wp_7_';
$repo->get( 5 );
$GLOBALS['wpdb']->prefix = 'wp_';
check( 'a repository built on one site reads the tables of the site switched to', str_contains( (string) end( $GLOBALS['wpdb']->log ), 'FROM wp_7_vcap_vouchers ' ) );

// A11: deleting a site drops its voucher tables.
$dropped = VCAP\Install::drop_site_tables( array( 'wp_3_posts' ), 3 );
check( 'a deleted site takes its nine voucher tables with it', 10 === count( $dropped ) && in_array( 'wp_3_vcap_vouchers', $dropped, true ) && in_array( 'wp_3_vcap_claims', $dropped, true ) && in_array( 'wp_3_vcap_scan_keys', $dropped, true ) && ! in_array( 'wp_vcap_vouchers', $dropped, true ) );

// A6: named MySQL locks are server-wide: the site's table prefix keeps them apart.
$lock_a                  = VCAP\Install::lock_name( 'delivery_42' );
$GLOBALS['wpdb']->prefix = 'wp_2_';
$lock_b                  = VCAP\Install::lock_name( 'delivery_42' );
$GLOBALS['wpdb']->prefix = 'wp_';
check( 'the same voucher id on two sites uses two different locks (at most 64 characters)', $lock_a !== $lock_b && strlen( $lock_a ) <= 64 && str_ends_with( $lock_a, '_delivery_42' ) );
check( 'deliveries and promotion holds use the per-site lock name', str_contains( (string) file_get_contents( VCAP_PATH . 'src/Delivery.php' ), "Install::lock_name( 'delivery_'" ) && 2 === substr_count( (string) file_get_contents( VCAP_PATH . 'src/Discounts/Usage.php' ), "Install::lock_name( 'usage_'" ) );
// AP-Q2-5: the remaining named locks (refund as credit, book back, onboarding pages, background jobs) are per site too.
$q25_src = static fn( string $f ): string => (string) file_get_contents( VCAP_PATH . 'src/' . $f );
check( 'AP-Q2-5: refund-as-credit, book-back, onboarding and job locks use Install::lock_name() (no bare GET_LOCK names)', str_contains( $q25_src( 'StoreCredit.php' ), "Install::lock_name( 'refund_credit_'" ) && str_contains( $q25_src( 'OrderReturn.php' ), 'self::lock_name( $order->get_id() )' ) && str_contains( $q25_src( 'Admin/Onboarding.php' ), "Install::lock_name( 'onboarding_'" ) && str_contains( $q25_src( 'Jobs.php' ), "Install::lock_name( 'job_'" ) && ! preg_match( '/\$lock\s*=\s*(\'vcap_|substr\()/',$q25_src( 'StoreCredit.php' ) . $q25_src( 'OrderReturn.php' ) . $q25_src( 'Admin/Onboarding.php' ) . $q25_src( 'Jobs.php' ) ) );
check( 'AP-Q2-5: OrderReturn::lock_name() is the per-site name other order paths can share', VCAP\Install::lock_name( 'return_42' ) === VCAP\OrderReturn::lock_name( 42 ) );

// L10/L4: the marker of a suspended coupon is written first; without it the coupon is not drafted.
$GLOBALS['vcap_test_cleaned'] = array();
$GLOBALS['wpdb']->log         = array();
$suspended                    = VCAP\Install::suspend_balance_coupons( array( 11, 12, 12 ) );
$sql                          = implode( "\n", $GLOBALS['wpdb']->log );
check( 'deactivation marks each coupon before it sets it to draft', 2 === $suspended && preg_match( "/INSERT INTO wp_postmeta .*\\(11, '_vcap_suspended', '1'\\),\\(12, /", $sql ) && strpos( $sql, 'INSERT INTO wp_postmeta' ) < strpos( $sql, "SET post_status = 'draft'" ) && array( 11, 12 ) === $GLOBALS['vcap_test_cleaned'] );
$GLOBALS['wpdb']->answers = array( '/^INSERT INTO wp_postmeta/' => false );
$GLOBALS['wpdb']->log     = array();
$suspended                = VCAP\Install::suspend_balance_coupons( array( 13 ) );
check( 'a coupon whose marker could not be written stays published (reactivation would not find it)', 0 === $suspended && ! preg_grep( "/SET post_status = 'draft'/", $GLOBALS['wpdb']->log ) );

// L4: besides gift cards: vouchers not redeemable in the shop and coupons with rules only Voucher Captain checks.
$GLOBALS['wpdb']->answers = array(
	'/^SHOW TABLES LIKE/'               => 'wp_vcap_vouchers',
	"/v\\.usage_mode = 'balance'/"      => array( '21' ),
	'/woocommerce_order_itemmeta/'      => array(
		array( 'ID' => '22', 'meta_value' => serialize( array( 'channels' => array( 'counter' ) ) ) ),
		array( 'ID' => '23', 'meta_value' => serialize( array( 'channels' => array( 'shop', 'counter' ) ) ) ),
	),
	'/_vcap_first_order/'               => array( '24', '21' ),
);
$GLOBALS['wpdb']->log = array();
$selected             = VCAP\Install::suspendable_coupon_ids();
$meta_sql             = (string) current( preg_grep( '/_vcap_first_order/', $GLOBALS['wpdb']->log ) );
check( 'deactivation also suspends counter-only vouchers and first-order, referral and SKU coupons', array( 21, 22, 24 ) === $selected && str_contains( $meta_sql, '_vcap_referral_owner' ) && str_contains( $meta_sql, '_vcap_skus' ) && str_contains( $meta_sql, '_vcap_exclude_skus' ), json_encode( $selected ) );

// L2: the id list of earlier releases publishes only coupons that still belong to a gift card or store credit.
$GLOBALS['vcap_test_options'][ VCAP\Install::SUSPENDED_OPTION ] = array( 31, 32 );
$GLOBALS['wpdb']->answers = array(
	'/^SHOW TABLES LIKE/'                   => 'wp_vcap_vouchers',
	'/SELECT DISTINCT coupon_id FROM wp_vcap_vouchers/' => array( '31' ),
	'/^SELECT post_id FROM wp_postmeta/'    => static fn( $q ) => str_contains( $q, 'post_id > 0 ' ) ? array( '41', '42' ) : array(),
);
$GLOBALS['wpdb']->log = array();
VCAP\Install::restore_balance_coupons();
$published = array_values( preg_grep( "/SET post_status = 'publish'/", $GLOBALS['wpdb']->log ) );
check( 'reactivation publishes marked coupons and listed ids with a gift card row only', 2 === count( $published ) && str_contains( $published[0], 'IN (31)' ) && str_contains( $published[1], 'IN (41,42)' ) && ! isset( $GLOBALS['vcap_test_options'][ VCAP\Install::SUSPENDED_OPTION ] ) && preg_grep( "/^DELETE FROM wp_postmeta WHERE post_id IN \\(41,42\\) AND meta_key = '_vcap_suspended'/", $GLOBALS['wpdb']->log ), json_encode( $published ) );

// L12: every index of the schema is verified, not only the unique keys.
$GLOBALS['wpdb']->answers = array(
	'/^SHOW TABLES LIKE/'           => static fn( $q ) => preg_match( "/LIKE '([a-z_0-9\\\\]+)'/", $q, $m ) ? stripslashes( $m[1] ) : null,
	'/^SHOW COLUMNS FROM/'          => static function ( $q ) {
		preg_match( '/FROM (\S+)/', $q, $m );
		return array( 'id', 'code', 'coupon_id', 'type', 'status', 'order_id', 'order_item_id', 'item_index', 'product_id', 'variation_id', 'template_id', 'usage_mode', 'discount_type', 'initial_amount', 'balance', 'currency', 'expires_at', 'buyer_name', 'buyer_email', 'user_id', 'recipient_name', 'recipient_email', 'message', 'fields', 'send_to', 'delivery_at', 'delivered_at', 'token', 'transferable', 'terms', 'max_checkins', 'checkins', 'last_checkin_at', 'delivered_roles', 'created_at', 'updated_at', 'voucher_id', 'event', 'amount', 'balance_after', 'source', 'till', 'receipt', 'note', 'actor_email_hash', 'batch', 'imported_at', 'used_at', 'redemption_key', 'target', 'state', 'request_hash', 'meta', 'campaign_id', 'customer_key', 'claim_key', 'kind', 'uses', 'window_end', 'counter_key', 'hits', 'key_hash', 'signature', 'event_mark', 'claimed_at', 'response' );
	},
	'/^SHOW INDEX FROM wp_vcap_events$/' => array( 'PRIMARY', 'voucher_ledger', 'voucher_order', 'actor_email_hash', 'event' ),
	'/^SHOW INDEX FROM/'            => static function ( $q ) {
		return array( 'PRIMARY', 'code', 'token', 'order_item_slot', 'order_id', 'coupon_id', 'status_expires', 'status_updated', 'expires_at', 'delivery_due', 'product_id', 'recipient_email', 'buyer_email', 'user_type', 'batch_free', 'batch_used', 'redemption_key', 'voucher_id', 'user_id', 'state_expires', 'campaign_customer', 'order_campaign', 'kind_created', 'claimed_at' );
	},
	'/information_schema/'          => 'InnoDB',
);
$problems = VCAP\Install::verify_schema();
check( 'a missing ordinary index is reported by the schema check', array( 'index wp_vcap_events.created_at is missing' ) === $problems, json_encode( $problems ) );
$GLOBALS['wpdb']->answers = array();

// L5: after a reactivation only Voucher Captain's own handlers of an order hook run again.
final class VCAP_Test_Foreign_Handler {
	public static array $calls = array();
	public static function handle( ...$args ) { self::$calls[] = $args; }
}
$hook = (object) array(
	'callbacks' => array(
		30 => array( 'own' => array( 'function' => array( VCAP\Install::class, 'lock_name' ), 'accepted_args' => 1 ) ),
		10 => array(
			'foreign' => array( 'function' => array( 'VCAP_Test_Foreign_Handler', 'handle' ), 'accepted_args' => 3 ),
			'closure' => array( 'function' => static function () {}, 'accepted_args' => 1 ),
			'string'  => array( 'function' => 'VCAP\\Install::missing_extensions', 'accepted_args' => 1 ),
		),
	),
);
$own = VCAP\CatchUp::own_callbacks( $hook );
check( 'the catch-up replays only callbacks from the plugin\'s src/, by priority (no foreign emails or webhooks)', 2 === count( $own ) && 'VCAP\\Install::missing_extensions' === $own[0]['function'] && 'lock_name' === $own[1]['function'][1] );
$GLOBALS['wp_filter'] = array( 'vcap_test_hook' => (object) array( 'callbacks' => array( 10 => array( 'foreign' => array( 'function' => array( 'VCAP_Test_Foreign_Handler', 'handle' ), 'accepted_args' => 1 ) ) ) ) );
VCAP\CatchUp::replay( 'vcap_test_hook', array( 1, 2 ) );
check( 'foreign handlers are never called by the catch-up', array() === VCAP_Test_Foreign_Handler::$calls );
unset( $GLOBALS['wp_filter'] );

// L8: without WooCommerce the plugin does not boot (no vcap() for connectors, no fatal error later).
$refused = false;
try {
	VCAP\Plugin::instance();
} catch ( LogicException $e ) {
	$refused = true;
}
check( 'without WooCommerce Plugin::instance() refuses to boot and ready() stays false', $refused && ! VCAP\Plugin::requirements_met() && ! VCAP\Plugin::ready() );
$main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
check( 'vcap() is defined only after the WooCommerce check, followed by the action vcap_loaded', strpos( $main, 'requirements_met()' ) < strpos( $main, 'function vcap()' ) && strpos( $main, 'function vcap()' ) < strpos( $main, "do_action( 'vcap_loaded'" ) );

// L9 / A3.
check( 'missing PHP extensions are named (activation and Site Health)', array( 'vcap_no_such_extension' ) === VCAP\Install::missing_extensions( array( 'pcre', 'vcap_no_such_extension' ) ) && array( 'mbstring', 'dom' ) === VCAP\Install::REQUIRED_EXTENSIONS );
check( 'a network activation sets up at most 25 sites in its own request', 25 === VCAP\Install::NETWORK_ACTIVATION_SITES );
// A13: keys without a Pass Captain order (orphans) are looked up together, 500 per query, and a missing key is
// remembered for a while – not one LIKE/longtext query per key on every report call.
if ( ! interface_exists( 'PassCaptain\\Integrations\\Vouchers\\ProviderInterface' ) ) {
	eval( 'namespace PassCaptain\\Integrations\\Vouchers; interface ProviderInterface {}' ); // phpcs:ignore Squiz.PHP.Eval
}
$a13_keys = array( 'found-key' );
for ( $i = 0; $i < 599; $i++ ) {
	$a13_keys[] = 'orphan-' . $i;
}
$GLOBALS['wpdb']->answers = array( '/provider_reference IN/' => array( 'voucher:found-key' ) );
$GLOBALS['wpdb']->log     = array();
unset( $GLOBALS['vcap_test_transients']['vcap_pc_orphans'] );
$a13_reconcile = ( new ReflectionClass( VCAP\Compat\PassCaptainReconcile::class ) )->newInstanceWithoutConstructor();
try {
	$a13_method = new ReflectionMethod( VCAP\Compat\PassCaptainReconcile::class, 'orders_existing' );
	$a13_method->setAccessible( true );
	$a13_first   = $a13_method->invoke( $a13_reconcile, $a13_keys );
	$a13_queries = count( preg_grep( '/wpcap_orders/', $GLOBALS['wpdb']->log ) );
	$GLOBALS['wpdb']->log = array();
	$a13_second  = $a13_method->invoke( $a13_reconcile, $a13_keys );
	$a13_again   = array_values( preg_grep( '/wpcap_orders/', $GLOBALS['wpdb']->log ) );
} catch ( \ReflectionException $e ) {
	$a13_first = $a13_second = null;
	$a13_queries = -1;
	$a13_again   = array( 'missing method' );
}
check( 'A13: 600 keys are checked in two queries (500 per IN list), only the key with an order is found', array( 'found-key' => true ) === $a13_first && 2 === $a13_queries, (string) $a13_queries );
check( 'A13: on the next report call only the found key is asked again, orphans come from the cache', array( 'found-key' => true ) === $a13_second && 1 === count( $a13_again ) && ! str_contains( $a13_again[0], 'orphan-' ), json_encode( $a13_again ) );
$GLOBALS['wpdb']->answers = array();

$GLOBALS['wpdb'] = $saved_wpdb;

// C8 / C13: PDF output and the ticket status page are kept out of page caches with DONOTCACHEPAGE (not only
// no-cache headers), and the PDF stream empties output buffers and switches compression off.
$vcap_source = static function ( string $class, string $method ): string {
	$ref   = new ReflectionMethod( $class, $method );
	$lines = file( (string) $ref->getFileName() );
	return implode( '', array_slice( (array) $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
};
$stream = $vcap_source( 'VCAP\\Preview', 'stream' );
$ticket = $vcap_source( 'VCAP\\Tickets', 'ticket_page' );
check( 'PDF stream and ticket status page use Shortcodes::no_cache() (DONOTCACHEPAGE), not only nocache_headers()', str_contains( $stream, 'Shortcodes::no_cache()' ) && str_contains( $ticket, 'Shortcodes::no_cache()' ) && ! str_contains( $ticket, 'nocache_headers()' ) );
check( 'the PDF stream cleans output buffers and switches zlib compression off before Content-Length', str_contains( $stream, 'wc_clean_buffers()' ) && str_contains( $stream, 'zlib.output_compression' ) && strpos( $stream, 'wc_clean_buffers()' ) < strpos( $stream, 'Content-Length' ) );

echo "Support, logging and audit trail (P7)\n";
// M13: notes are stored as code + arguments and translated when shown; plain notes of earlier versions stay.
$m13_note = VCAP\Note::make( 'delivery_partial', array( 'buyer', wp_json_encode( array( 'buyer' => 'mail_failed' ) ) ) );
check( 'M13: a coded note is stored without the sentence and rendered with role labels, never the internal role key', VCAP\Note::is_coded( $m13_note ) && ! str_contains( $m13_note, 'Not delivered' ) && 'Not delivered to: Buyer (will be retried). Buyer: the mail system did not accept the email' === VCAP\Note::render( $m13_note ), VCAP\Note::render( $m13_note ) );
check( 'M13: plain-text notes of earlier versions and staff notes are shown unchanged', 'Not delivered to: recipient (will be retried).' === VCAP\Note::render( 'Not delivered to: recipient (will be retried).' ) && '@vcap:broken' === VCAP\Note::render( '@vcap:broken' ) );
check( 'M13: "Sent to 2 addresses" names the roles', 'Sent to 2 addresses (Recipient, Buyer)' === VCAP\Note::render( VCAP\Note::make( 'delivered', array( 2, 'recipient,buyer' ) ) ) );
// D4: the reason per role is kept – disabled, no address and mail system error read differently.
$d4_text = VCAP\Note::render( VCAP\Note::make( 'delivery_not_sent', array( wp_json_encode( array( 'recipient' => 'disabled', 'buyer' => 'mail_failed' ) ) ) ) );
check( 'D4: a failed delivery names the reason per role (email disabled vs. mail system)', 'No email was sent (Recipient: email disabled; Buyer: the mail system did not accept the email).' === $d4_text, $d4_text );
$d4_mail = (string) file_get_contents( dirname( __DIR__ ) . '/src/Emails/VoucherEmail.php' );
check( 'D4: VoucherEmail::trigger() records disabled / no_recipient / mail_failed', str_contains( $d4_mail, "'disabled'" ) && str_contains( $d4_mail, "'no_recipient'" ) && str_contains( $d4_mail, "'mail_failed'" ) );
check( 'D4: the mail failure log keeps the voucher id', str_contains( $vcap_source( 'VCAP\\Health', 'record_mail_failure' ), "'voucher_id'" ) );
// D8: edit diff with masked addresses, the message only marked as changed.
$d8_before                  = new Voucher();
$d8_before->recipient_email = 'anna@gmial.com';
$d8_before->message         = 'Happy birthday';
$d8_before->send_to         = 'recipient';
$d8_before->status          = Voucher::STATUS_ACTIVE;
$d8_after                   = clone $d8_before;
$d8_after->recipient_email  = 'anna@gmail.com';
$d8_after->message          = 'Happy birthday, Anna';
$d8_diff                    = VCAP\Admin\VoucherAdmin::edit_diff( $d8_before, $d8_after );
$d8_text                    = VCAP\Note::render( VCAP\Note::make( 'edited', array( wp_json_encode( $d8_diff ) ) ) );
check( 'D8: "edited" records before → after with masked addresses and no message text', array( 'recipient_email', 'message' ) === array_keys( $d8_diff ) && str_contains( $d8_text, 'Recipient email: a***@gmial.com → a***@gmail.com' ) && ! str_contains( $d8_text, 'birthday' ), $d8_text );
check( 'D8: the ledger has a source column and log() stores Source::current()', str_contains( $vcap_source( 'VCAP\\VoucherRepository', 'log' ), "Source::current()" ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Install.php' ), "source varchar(20) NOT NULL DEFAULT ''" ) && '1.6.0' === VCAP\Install::EVENT_SOURCE_VERSION );
check( 'D8: WP-CLI is recorded as source "cli"', ( defined( 'WP_CLI' ) && WP_CLI ? 'cli' : VCAP\Source::with( 'cli', static fn() => VCAP\Source::current() ) ) === 'cli' && isset( VCAP\Source::labels()['scanner'] ) );
// H7: manual sends check the status.
$h7 = new Voucher();
$h7->status = Voucher::STATUS_SCHEDULED;
$h7_scheduled = VCAP\Admin\VoucherAdmin::resend_refusal( $h7 );
$h7->status = Voucher::STATUS_VOID;
$h7_void = VCAP\Admin\VoucherAdmin::resend_refusal( $h7 );
$h7->status = Voucher::STATUS_ACTIVE;
check( 'H7: a scheduled or void voucher is not emailed by hand, an active one is', '' !== $h7_scheduled && str_contains( $h7_scheduled, 'Send now' ) && '' !== $h7_void && '' === VCAP\Admin\VoucherAdmin::resend_refusal( $h7 ) );
// D5: barcode type vs. code.
check( 'D5: EAN-13 refuses letters, Code 39 lower case; Code 128 takes anything', '' !== PdfRenderer::barcode_problem( 'EAN13', 'GIFT-K7QM2X9ZPA' ) && '' === PdfRenderer::barcode_problem( 'EAN13', '4006381333931' ) && '' !== PdfRenderer::barcode_problem( 'C39', 'abc' ) && '' === PdfRenderer::barcode_problem( 'C128', 'gift-äöü' ) );
check( 'D5: failed QR codes, barcodes and temp files are logged with the voucher id and element', str_contains( $vcap_source( 'VCAP\\PdfRenderer', 'to_temp_file' ), 'write_failed' ) && str_contains( $vcap_source( 'VCAP\\PdfRenderer', 'write_failed' ), "'voucher_id'" ) && str_contains( $vcap_source( 'VCAP\\PdfRenderer', 'element_failed' ), "'element'" ) && str_contains( $vcap_source( 'VCAP\\Delivery', 'record_attempt' ), "'delivered_without_pdf'" ) );
// D16: verbose log with an expiry.
$GLOBALS['vcap_test_options'][ VCAP\Logger::VERBOSE_OPTION ]       = 'yes';
$GLOBALS['vcap_test_options'][ VCAP\Logger::VERBOSE_UNTIL_OPTION ] = time() + 60;
$d16_on = VCAP\Logger::verbose();
$GLOBALS['vcap_test_options'][ VCAP\Logger::VERBOSE_UNTIL_OPTION ] = time() - 1;
$d16_expired = VCAP\Logger::verbose();
unset( $GLOBALS['vcap_test_options'][ VCAP\Logger::VERBOSE_OPTION ], $GLOBALS['vcap_test_options'][ VCAP\Logger::VERBOSE_UNTIL_OPTION ] );
check( 'D16: the verbose log is on while its window runs and switches itself off', $d16_on && ! $d16_expired && ( defined( 'VCAP_DEBUG' ) || ! VCAP\Logger::verbose() ) );
// Z12: values typed into the visible order item meta.
check( 'Z12: a corrected recipient email is taken over, an invalid one or a bad date is not', 'anna@gmail.com' === VCAP\Fields::visible_value( 'recipient_email', ' anna@gmail.com ' ) && null === VCAP\Fields::visible_value( 'recipient_email', 'anna(at)gmail' ) && null === VCAP\Fields::visible_value( 'delivery_date', '24.12.2026' ) && '2026-12-24' === VCAP\Fields::visible_value( 'delivery_date', '2026-12-24' ) );
// D13: the hook reference is generated and current.
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/hooks.php' ) . ' --check 2>&1', $d13_out, $d13_rc );
check( 'D13: docs/HOOKS.md is generated from the code and up to date (php bin/hooks.php --check)', 0 === $d13_rc && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/docs/HOOKS.md' ), '`vcap_voucher_deleted`' ), implode( "\n", $d13_out ) );
// D9: WP-CLI commands exist and are registered only under WP-CLI.
$d9 = new ReflectionClass( 'VCAP\\CLI\\Command' );
check( 'D9: "wp vcap" has doctor, migrate, deliver, issue, sync, ratelimit, diagnostics', ! array_diff( array( 'doctor', 'migrate', 'deliver', 'issue', 'sync', 'ratelimit', 'diagnostics' ), array_map( static fn( $m ) => $m->getName(), $d9->getMethods( ReflectionMethod::IS_PUBLIC ) ) ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' ), "defined( 'WP_CLI' ) && WP_CLI" ) );

echo "P12 abuse protection (safe defaults)\n";
$p12_defaults = VCAP\Settings::DEFAULTS;
check( 'V3/V5/V7: abuse limits are on by default (14 days wait, 10 rewards per 30 days, address check, 500 custom max, 1000 per 24 h, hold above 250 for 24 h)', 14 === $p12_defaults['vcap_referral_wait_days'] && 10 === $p12_defaults['vcap_referral_max_rewards'] && 'yes' === $p12_defaults['vcap_referral_address_check'] && 14 === $p12_defaults['vcap_bonus_wait_days'] && 500 === $p12_defaults['vcap_custom_max_default'] && 1000 === $p12_defaults['vcap_customer_daily_limit'] && 250 === $p12_defaults['vcap_hold_new_customer_above'] && 24 === $p12_defaults['vcap_hold_hours'] && '' === $p12_defaults['vcap_referral_min_spend'] );
$p12_custom = array( 'amount_source' => 'custom', 'custom_min' => '', 'custom_max' => '', 'custom_step' => '1' ) + ProductConfig::DEFAULTS;
$p12_max    = (float) ProductConfig::effective_custom_max( $p12_custom );
$p12_own    = (string) ProductConfig::effective_custom_max( array( 'custom_max' => '2000' ) + $p12_custom );
$p12_minhi  = (float) ProductConfig::effective_custom_max( array( 'custom_min' => '800' ) + $p12_custom );
$GLOBALS['vcap_test_options']['vcap_custom_max_default'] = 0;
$p12_off = ProductConfig::effective_custom_max( $p12_custom );
unset( $GLOBALS['vcap_test_options']['vcap_custom_max_default'] );
check( 'V7: a custom amount without own maximum is limited to 500 by default; an own maximum is kept; never below the minimum; 0 = no limit', 500.0 === $p12_max && '2000' === $p12_own && 800.0 === $p12_minhi && '' === $p12_off, var_export( array( $p12_max, $p12_own, $p12_minhi, $p12_off ), true ) );
check( 'V7: 600 is refused for a product without own maximum (default 500)', null !== VCAP\CustomAmount::error_for( array( 'custom_max' => $p12_max ) + $p12_custom, '600' ) && null === VCAP\CustomAmount::error_for( array( 'custom_max' => $p12_max ) + $p12_custom, '500' ) );
$p12_ref = ( new ReflectionClass( VCAP\Referrals::class ) )->newInstanceWithoutConstructor();
( new ReflectionProperty( VCAP\Referrals::class, 'settings' ) )->setValue( $p12_ref, new VCAP\Settings() );
$p12_auto = $p12_ref->effective_min_spend();
$GLOBALS['vcap_test_options']['vcap_referral_min_spend'] = '0';
$p12_zero = $p12_ref->effective_min_spend();
$GLOBALS['vcap_test_options']['vcap_referral_min_spend'] = '35';
$p12_own_min = $p12_ref->effective_min_spend();
$GLOBALS['vcap_test_options']['vcap_referral_friend_type'] = 'percent';
$GLOBALS['vcap_test_options']['vcap_referral_min_spend']   = '';
$p12_pct = $p12_ref->effective_min_spend();
unset( $GLOBALS['vcap_test_options']['vcap_referral_min_spend'], $GLOBALS['vcap_test_options']['vcap_referral_friend_type'] );
check( 'V3: the friend\'s code needs an order above the discount: automatic 2 × 10 = 20, an entered 0 becomes 11, 35 stays 35, percentages as entered', 20.0 === $p12_auto && 11.0 === $p12_zero && 35.0 === $p12_own_min && 0.0 === $p12_pct, var_export( array( $p12_auto, $p12_zero, $p12_own_min, $p12_pct ), true ) );

// D15: the API v1 facades declare their documented return types natively; the provider interface stays untyped (BC).
$d15_type  = static fn( string $class, string $method ): string => (string) ( new ReflectionMethod( $class, $method ) )->getReturnType();
$d15_types = array();
foreach ( array( 'check', 'reserve', 'commit', 'release', 'refund', 'get' ) as $d15_m ) {
	$d15_types[ 'redemptions::' . $d15_m ] = $d15_type( VCAP\API\V1\RedemptionService::class, $d15_m );
}
foreach ( array( 'lookup', 'reserve', 'commit', 'release' ) as $d15_m ) {
	$d15_types[ 'api_v1::' . $d15_m ]   = $d15_type( VCAP\API\V1\VoucherService::class, $d15_m );
	$d15_types[ 'provider::' . $d15_m ] = $d15_type( VCAP\API\V1\WooCommerceRedemptionProvider::class, $d15_m );
}
foreach ( array( 'check', 'reserve', 'get', 'commit', 'release' ) as $d15_m ) {
	$d15_types[ 'rest::' . $d15_m ] = $d15_type( VCAP\API\V1\RestController::class, $d15_m );
}
$d15_rest = array_filter( $d15_types, static fn( $t, $k ) => str_starts_with( $k, 'rest::' ), ARRAY_FILTER_USE_BOTH );
$d15_data = array_diff_key( $d15_types, $d15_rest );
check( 'D15: RedemptionService, VoucherService and the built-in provider return array|WP_Error natively', array() === array_filter( $d15_data, static fn( $t ) => ! in_array( $t, array( 'WP_Error|array', 'array|WP_Error' ), true ) ), var_export( $d15_data, true ) );
check( 'D15: the REST callbacks return WP_REST_Response|WP_Error and the permission callbacks bool|WP_Error natively', array() === array_filter( $d15_rest, static fn( $t ) => 'WP_REST_Response|WP_Error' !== $t ) && 'WP_Error|bool' === $d15_type( VCAP\API\V1\RestController::class, 'can_redeem' ) && 'WP_Error|bool' === $d15_type( VCAP\API\V1\RestController::class, 'can_access' ), var_export( $d15_rest, true ) . $d15_type( VCAP\API\V1\RestController::class, 'can_access' ) );
$d15_iface = array();
foreach ( array( 'lookup', 'reserve', 'commit', 'release' ) as $d15_m ) {
	$d15_iface[ $d15_m ] = ( new ReflectionMethod( VCAP\Contracts\RedemptionProvider::class, $d15_m ) )->hasReturnType();
}
check( 'D15: the RedemptionProvider interface keeps lookup/reserve/commit/release without native return types (existing providers stay compatible within v1)', array( false ) === array_values( array_unique( $d15_iface ) ) && 'bool' === $d15_type( VCAP\Contracts\RedemptionProvider::class, 'supports' ), var_export( $d15_iface, true ) );
$d15_legacy = new class() implements VCAP\Contracts\RedemptionProvider {
	public function supports( string $code ): bool { return true; }
	public function lookup( string $code ) { return array(); }
	public function reserve( string $code, float $amount, \WC_Order $order ) { return array(); }
	public function commit( string $code, \WC_Order $order ) { return array(); }
	public function release( string $code, \WC_Order $order ) { return array(); }
};
check( 'D15: an untyped provider written against the 1.1 contract still implements the interface', $d15_legacy instanceof VCAP\Contracts\RedemptionProvider );
echo "D6 event timing (actions after COMMIT)\n";
if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( $hook, $args ) { do_action( $hook, ...$args ); }
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log( $level, $message, $context = array() ) { $GLOBALS['vcap_test_logged'][] = array( $level, $message, $context ); }
		};
	}
}
$GLOBALS['vcap_test_logged'] = array();
VCAP\Logger::error( 'Mail to jane.doe@example.com failed: SMTP 550 jane.doe@example.com unknown', array( 'to' => 'Jane <jane.doe@example.com>', 'order_id' => 7, 'nested' => array( 'cc' => 'bob@example.org' ) ) );
$m100_entry = $GLOBALS['vcap_test_logged'][0] ?? array( '', '', array() );
check( 'M12-100: the logger masks email addresses in the message and in the context values', ! str_contains( wp_json_encode( $m100_entry ), 'jane.doe@' ) && ! str_contains( wp_json_encode( $m100_entry ), 'bob@' ) && str_contains( (string) $m100_entry[1], 'j***@example.com' ) && 7 === ( $m100_entry[2]['order_id'] ?? null ) && 'voucher-captain' === ( $m100_entry[2]['source'] ?? '' ), wp_json_encode( $m100_entry ) );
/** Database double for D6: records queries; COMMIT can be refused. */
final class VCAP_D6_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $insert_id     = 1;
	public bool $refuse_commit = false;
	public bool $savepoint_gone = false;
	public array $log         = array();
	public function query( $q ) { $this->log[] = $q; return ( $this->refuse_commit && 'COMMIT' === $q ) || ( $this->savepoint_gone && str_starts_with( $q, 'ROLLBACK TO SAVEPOINT' ) ) ? false : 1; }
	public function update( ...$a ) { return 1; }
	public function insert( ...$a ) { return 1; }
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return null; }
	public function get_row( $q, $o = null ) { return null; }
	public function get_results( $q, $o = null ) { return array(); }
	public function get_col( $q, $i = 0 ) { return array(); }
}
$d6_saved_wpdb   = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new VCAP_D6_Wpdb();
VCAP\Db::reset();
$d6_seen = array();
add_action( 'vcap_d6_test', static function ( $value ) use ( &$d6_seen ) { $d6_seen[] = $value; } );

VCAP\Db::action( 'vcap_d6_test', 'now' );
check( 'D6: outside a transaction an action fires immediately', array( 'now' ) === $d6_seen );

$d6_seen = array();
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'a' );
$d6_before = $d6_seen;
VCAP\Db::commit();
check( 'D6: an action is not visible to listeners before COMMIT and fires once after it', array() === $d6_before && array( 'a' ) === $d6_seen && array( 'START TRANSACTION', 'COMMIT' ) === $GLOBALS['wpdb']->log );

$d6_seen              = array();
$GLOBALS['wpdb']->log = array();
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'gone' );
VCAP\Db::rollback_if_error( 'd6_test' );
VCAP\Db::begin();
VCAP\Db::commit();
check( 'D6: an action of a rolled back transaction is never fired (not even with the next COMMIT)', array() === $d6_seen && array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ) === $GLOBALS['wpdb']->log );

$d6_seen              = array();
$GLOBALS['wpdb']->log = array();
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'outer' );
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'inner' );
VCAP\Db::commit();
$d6_after_inner = $d6_seen;
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'undone' );
VCAP\Db::rollback_silently();
$d6_open = VCAP\Db::in_transaction();
VCAP\Db::commit();
check( 'D6: nested helper calls use savepoints and dispatch at the outermost COMMIT (an inner ROLLBACK drops only its own actions)', array() === $d6_after_inner && $d6_open && array( 'outer', 'inner' ) === $d6_seen && ! VCAP\Db::in_transaction() && array( 'START TRANSACTION', 'SAVEPOINT vcap_sp_1', 'RELEASE SAVEPOINT vcap_sp_1', 'SAVEPOINT vcap_sp_1', 'ROLLBACK TO SAVEPOINT vcap_sp_1', 'COMMIT' ) === $GLOBALS['wpdb']->log, implode( ' | ', $GLOBALS['wpdb']->log ) );

// A listener that opens its own transaction runs after the outer COMMIT: its START TRANSACTION can no longer
// commit the outer one implicitly, and its own actions follow its own COMMIT.
$d6_seen              = array();
$GLOBALS['wpdb']->log = array();
add_action(
	'vcap_d6_tx_listener',
	static function () {
		$GLOBALS['vcap_d6_open_in_listener'] = VCAP\Db::in_transaction();
		VCAP\Db::begin();
		VCAP\Db::action( 'vcap_d6_test', 'from listener' );
		VCAP\Db::commit();
	}
);
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_tx_listener' );
VCAP\Db::action( 'vcap_d6_test', 'outer' );
VCAP\Db::commit();
check( 'D6: a listener starting its own transaction runs after the outer COMMIT and no longer commits the outer one', false === $GLOBALS['vcap_d6_open_in_listener'] && array( 'START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'COMMIT' ) === $GLOBALS['wpdb']->log && array( 'from listener', 'outer' ) === $d6_seen, implode( ' | ', $GLOBALS['wpdb']->log ) . ' / ' . implode( ',', $d6_seen ) );

// A refused COMMIT keeps the level open for the caller's rollback; nothing is dispatched.
$d6_seen                         = array();
$GLOBALS['wpdb']->log            = array();
$GLOBALS['wpdb']->refuse_commit  = true;
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_test', 'lost' );
$d6_committed = VCAP\Db::commit();
$d6_still     = VCAP\Db::in_transaction();
VCAP\Db::rollback_if_error( 'd6_test' );
$GLOBALS['wpdb']->refuse_commit = false;
check( 'D6: a failed COMMIT dispatches nothing and leaves the rollback to the caller', ! $d6_committed && $d6_still && ! VCAP\Db::in_transaction() && array() === $d6_seen && 'ROLLBACK' === end( $GLOBALS['wpdb']->log ) );

// Db::transaction(): true commits, false rolls back, an exception rolls back and is thrown again.
$d6_seen              = array();
$GLOBALS['wpdb']->log = array();
$d6_ok                = VCAP\Db::transaction( static function () { VCAP\Db::action( 'vcap_d6_test', 'tx' ); return true; }, 'd6' );
$d6_no                = VCAP\Db::transaction( static function () { VCAP\Db::action( 'vcap_d6_test', 'no' ); return false; }, 'd6' );
$d6_thrown            = false;
try {
	VCAP\Db::transaction( static function () { VCAP\Db::action( 'vcap_d6_test', 'boom' ); throw new RuntimeException( 'boom' ); }, 'd6' );
} catch ( RuntimeException $e ) {
	$d6_thrown = true;
}
check( 'D6: Db::transaction() commits on true, rolls back on false or an exception (actions only after the commit)', $d6_ok && ! $d6_no && $d6_thrown && array( 'tx' ) === $d6_seen && ! VCAP\Db::in_transaction() && array( 'START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK' ) === $GLOBALS['wpdb']->log, implode( ' | ', $GLOBALS['wpdb']->log ) );

// A listener that throws after the COMMIT is logged; the others still run (the data is committed).
$d6_seen                     = array();
$GLOBALS['vcap_test_logged'] = array();
add_action( 'vcap_d6_throws', static function () { throw new RuntimeException( 'listener failed' ); } );
VCAP\Db::begin();
VCAP\Db::action( 'vcap_d6_throws' );
VCAP\Db::action( 'vcap_d6_test', 'after' );
$d6_commit = VCAP\Db::commit();
check( 'D6: a listener failing after COMMIT is logged and does not stop the other listeners', $d6_commit && array( 'after' ) === $d6_seen && 1 === count( $GLOBALS['vcap_test_logged'] ) && str_contains( $GLOBALS['vcap_test_logged'][0][1], 'listener failed' ) );

// Repository hooks: vcap_voucher_updated / vcap_voucher_event wait for the COMMIT and get the state of the call.
$d6_updates = array();
$d6_events  = array();
add_action( 'vcap_voucher_updated', static function ( $v ) use ( &$d6_updates ) { $d6_updates[] = $v->balance; } );
add_action( 'vcap_voucher_event', static function ( $id, $event ) use ( &$d6_events ) { $d6_events[] = $event; }, 10, 2 );
$d6_repo             = new VCAP\VoucherRepository();
$d6_voucher          = new VCAP\Voucher();
$d6_voucher->id      = 7;
$d6_voucher->balance = 40.0;
VCAP\Db::begin();
$d6_repo->update( $d6_voucher );
$d6_repo->log( 7, 'redeemed', 10.0, 30.0, 'test' );
$d6_voucher->balance = 30.0;
$d6_repo->update( $d6_voucher );
$d6_mid = array( $d6_updates, $d6_events );
VCAP\Db::commit();
VCAP\Db::begin();
$d6_repo->update( $d6_voucher );
$d6_repo->log( 7, 'voided' );
VCAP\Db::rollback_silently();
check( 'D6: vcap_voucher_updated and vcap_voucher_event fire after the COMMIT (each with the voucher as saved then), never after a ROLLBACK', array( array(), array() ) === $d6_mid && array( 40.0, 30.0 ) === $d6_updates && array( 'redeemed' ) === $d6_events, var_export( array( $d6_updates, $d6_events ), true ) );
$d6_sources = implode( "\n", array_map( 'file_get_contents', array_merge( glob( VCAP_PATH . 'src/Coupons/*.php' ), array( VCAP_PATH . 'src/CouponManager.php', VCAP_PATH . 'src/API/V1/RedemptionService.php', VCAP_PATH . 'src/StoreCredit.php', VCAP_PATH . 'src/VoucherRepository.php', VCAP_PATH . 'src/Install.php' ) ) ) );
check( 'D6: every transaction in src runs through the shared helper (no raw START TRANSACTION / COMMIT / ROLLBACK)', ! preg_match( "/query\\( '(START TRANSACTION|COMMIT|ROLLBACK)' \\)/", $d6_sources ) );

// M12-05: outside a transaction a throwing listener is logged as well; the booking that fired it is done.
VCAP\Db::reset();
$d6_seen                     = array();
$GLOBALS['vcap_test_logged'] = array();
$m12_05_thrown               = false;
try {
	VCAP\Db::action( 'vcap_d6_throws' );
	VCAP\Db::action_ref_array( 'vcap_d6_throws', array( 1 ) );
	VCAP\Db::after_commit( static function () { throw new RuntimeException( 'after commit failed' ); } );
	VCAP\Db::action( 'vcap_d6_test', 'still' );
} catch ( Throwable $e ) {
	$m12_05_thrown = true;
}
check( 'M12-05: Db::action(), action_ref_array() and after_commit() catch and log a throwing listener at depth 0 too', ! $m12_05_thrown && array( 'still' ) === $d6_seen && 3 === count( $GLOBALS['vcap_test_logged'] ) && str_contains( $GLOBALS['vcap_test_logged'][2][1], 'after commit failed' ), var_export( $GLOBALS['vcap_test_logged'], true ) );

// M12-04: a deadlock inside a savepoint rolls back the whole transaction (InnoDB): the outer levels can only be rolled
// back, no action of any level is fired, nothing is committed by a later COMMIT and the next transaction is normal.
foreach ( array( 'deadlock error' => 'error', 'savepoint gone' => 'savepoint' ) as $m12_04_case => $m12_04_how ) {
	VCAP\Db::reset();
	$d6_seen              = array();
	$GLOBALS['wpdb']->log = array();
	VCAP\Db::begin();
	VCAP\Db::action( 'vcap_d6_test', 'outer' );
	VCAP\Db::begin();
	VCAP\Db::action( 'vcap_d6_test', 'inner' );
	if ( 'error' === $m12_04_how ) {
		$GLOBALS['wpdb']->last_error = 'Deadlock found when trying to get lock; try restarting transaction';
	} else {
		$GLOBALS['wpdb']->savepoint_gone = true;
	}
	VCAP\Db::rollback_silently();
	$GLOBALS['wpdb']->last_error     = '';
	$GLOBALS['wpdb']->savepoint_gone = false;
	$m12_04_open     = VCAP\Db::in_transaction();
	$m12_04_nested   = VCAP\Db::begin();
	$m12_04_commit   = VCAP\Db::commit();
	VCAP\Db::rollback_silently();
	$m12_04_closed   = ! VCAP\Db::in_transaction();
	$m12_04_log      = $GLOBALS['wpdb']->log;
	VCAP\Db::begin();
	VCAP\Db::action( 'vcap_d6_test', 'next' );
	$m12_04_next = VCAP\Db::commit();
	$m12_04_rest = array_slice( $GLOBALS['wpdb']->log, count( $m12_04_log ) );
	$m12_04_expect = 'error' === $m12_04_how
		? array( 'START TRANSACTION', 'SAVEPOINT vcap_sp_1', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK' )
		: array( 'START TRANSACTION', 'SAVEPOINT vcap_sp_1', 'ROLLBACK TO SAVEPOINT vcap_sp_1', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK' );
	check( "M12-04: a deadlock in a savepoint ({$m12_04_case}) leaves Db consistent: outer level open but not committable, its actions dropped, the next transaction normal", $m12_04_open && ! $m12_04_nested && ! $m12_04_commit && $m12_04_closed && $m12_04_expect === $m12_04_log && $m12_04_next && array( 'START TRANSACTION', 'COMMIT' ) === $m12_04_rest && array( 'next' ) === $d6_seen, implode( ' | ', $GLOBALS['wpdb']->log ) . ' / ' . implode( ',', $d6_seen ) );
}
$m12_04_sources = implode( "\n", array_map( 'file_get_contents', array( VCAP_PATH . 'src/Coupons/Balances.php', VCAP_PATH . 'src/API/V1/RedemptionService.php', VCAP_PATH . 'src/StoreCredit.php' ) ) );
check( 'M12-04: bookings read the voucher with get_for_update() (no "SELECT id … FOR UPDATE" followed by a plain get()), the charge state with a locking read', ! preg_match( '/SELECT id FROM \{\$(table|tables\[\'vouchers\'\])\} WHERE id = %d FOR UPDATE/', $m12_04_sources ) && substr_count( $m12_04_sources, 'get_for_update(' ) >= 10 && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Coupons/Balances.php' ), "' LOCK IN SHARE MODE'" ) );
VCAP\Db::reset();
$GLOBALS['wpdb'] = $d6_saved_wpdb;
echo "R6 tills, counter limits and add-on hooks\n";
$r6_note = VCAP\Tickets::counter_note( 'Lunch', '4711', 'Shop 1' );
check( 'R6: till and receipt are read back from a counter note written before schema 1.8.0 (English and German labels)', array( 'till' => 'Shop 1', 'receipt' => '4711' ) === VCAP\Counter::parse_note( $r6_note ) && array( 'till' => 'Filiale Graz', 'receipt' => 'B-12' ) === VCAP\Counter::parse_note( 'Im Laden eingelöst · Beleg B-12 · Kasse Filiale Graz' ) && array( 'till' => '', 'receipt' => '' ) === VCAP\Counter::parse_note( 'Redeemed in store' ) && array( 'till' => '', 'receipt' => '' ) === VCAP\Counter::parse_note( VCAP\Note::make( 'delivered', array( 1 ) ) ), var_export( VCAP\Counter::parse_note( $r6_note ), true ) );
$r6_none  = VCAP\Counter::exceeded( array( 'amount' => 0.0, 'count' => 0 ), array( 'count' => 99, 'amount' => 9999.0 ), 500.0 );
$r6_fits  = VCAP\Counter::exceeded( array( 'amount' => 100.0, 'count' => 3 ), array( 'count' => 2, 'amount' => 60.0 ), 40.0 );
$r6_amt   = VCAP\Counter::exceeded( array( 'amount' => 100.0, 'count' => 0 ), array( 'count' => 2, 'amount' => 60.0 ), 40.01 );
$r6_cnt   = VCAP\Counter::exceeded( array( 'amount' => 0.0, 'count' => 3 ), array( 'count' => 3, 'amount' => 0.0 ), 0.0 );
check( 'R6: daily limits: 0 = unlimited, exactly reaching the limit fits, one cent above is refused with what is left, the count limit counts this redemption', null === $r6_none && null === $r6_fits && 'amount' === $r6_amt['kind'] && 40.0 === $r6_amt['left'] && 'count' === $r6_cnt['kind'] && 0.0 === $r6_cnt['left'], var_export( array( $r6_fits, $r6_amt, $r6_cnt ), true ) );
check( 'R6: the scanner message names the limit and what is left', str_contains( VCAP\Counter::limit_message( 'till', 'Shop 1', $r6_cnt, 'EUR' ), '"Shop 1"' ) && str_contains( VCAP\Counter::limit_message( 'user', '', $r6_cnt, 'EUR' ), 'left: 0' ) );
check( 'R6: counter limits are off by default (0 = unlimited)', 0 === VCAP\Settings::DEFAULTS[ VCAP\Counter::USER_AMOUNT ] && 0 === VCAP\Settings::DEFAULTS[ VCAP\Counter::USER_COUNT ] && 0 === VCAP\Settings::DEFAULTS[ VCAP\Counter::TILL_AMOUNT ] && 0 === VCAP\Settings::DEFAULTS[ VCAP\Counter::TILL_COUNT ] );
$r6_head = VCAP\Ledger::journal_header();
$r6_line = VCAP\Ledger::journal_line( array( 'created_at' => '2026-11-14 10:00:00', 'code' => 'ABC', 'type' => 'giftcard', 'currency' => 'EUR', 'event' => 'redeemed', 'amount' => 5, 'balance_after' => 10, 'order_id' => 0, 'till' => 'Shop 1', 'receipt' => '4711', 'note' => 'x' ) );
check( 'R6: the journal CSV has its own till/branch and receipt columns', 'Shop 1' === $r6_line[ array_search( 'Till / branch', $r6_head, true ) ] && '4711' === $r6_line[ array_search( 'Receipt no.', $r6_head, true ) ] && count( $r6_head ) === count( $r6_line ) );
check( 'R6: the counter report CSV groups by till, staff member or both', array( 'Till / branch', 'Staff member' ) === array_slice( VCAP\Ledger::counter_header( 'both' ), 0, 2 ) && 'Currency' === VCAP\Ledger::counter_header( 'user' )[1] && 'Till / branch' === VCAP\Ledger::counter_header( 'till' )[0] );
$r6_v         = new VCAP\Voucher();
$r6_v->status = VCAP\Voucher::STATUS_ACTIVE;
add_filter(
	'vcap_voucher_links',
	static fn( $links, $voucher, $context ) => array_merge( $links, array( array( 'label' => '<b>Add to Wallet</b>', 'url' => 'https://wallet.example/' . $context ), array( 'label' => 'Bad', 'url' => 'javascript:alert(1)' ), array( 'label' => '', 'url' => 'https://x.example/' ), 'junk' ) ),
	10,
	3
);
$r6_links = VCAP\Downloads::voucher_links( $r6_v, 'email' );
$r6_v->status = VCAP\Voucher::STATUS_VOID;
check( 'R6: vcap_voucher_links: only labelled http(s) links are kept, as plain text; none for a cancelled voucher', array( array( 'label' => 'Add to Wallet', 'url' => 'https://wallet.example/email' ) ) === $r6_links && array() === VCAP\Downloads::voucher_links( $r6_v, 'account' ), var_export( $r6_links, true ) );
unset( $GLOBALS['vcap_test_filters']['vcap_voucher_links'] );
$r6_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/VoucherRepository.php' );
check( 'R6: vcap_voucher_event passes the source and the acting user id as trailing arguments', str_contains( $r6_src, "Db::action( 'vcap_voucher_event', \$voucher_id, \$event, \$amount, \$balance_after, Note::render( \$note ), \$order_id, \$source, (int) \$row['user_id'] )" ) );
check( 'R6: the admin menu fires vcap_admin_menu with its parent slug', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Menu.php' ), "do_action( 'vcap_admin_menu', 'vcap-vouchers' )" ) );
check( 'R6: schema 1.8.0 adds the ledger columns till and receipt, migrated after 1.7.0', '1.8.0' === VCAP\Install::EVENT_TILL_VERSION && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' ), "define( 'VCAP_DB_VERSION', '1.8.0' )" ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Install.php' ), "till varchar(100) NOT NULL DEFAULT ''" ) );

echo "D14 coupon sync and code masking\n";
$d14_u = static fn( string $mode, string $status, int $limit, int $count ) => CouponManager::coupon_usage_for( $mode, $status, $limit, $count );
check(
	'D14: one usage rule – redeemed = used up, active single-use = exactly one use left, balance untouched',
	array( 1, 1 ) === $d14_u( 'single', Voucher::STATUS_REDEEMED, 1, 0 )
		&& array( 2, 2 ) === $d14_u( 'single', Voucher::STATUS_REDEEMED, 1, 2 )
		&& array( 2, 1 ) === $d14_u( 'single', Voucher::STATUS_ACTIVE, 1, 1 )
		&& array( 1, 0 ) === $d14_u( 'single', Voucher::STATUS_ACTIVE, 5, 0 )
		&& array( 1, 0 ) === $d14_u( 'single', Voucher::STATUS_RESERVED, 1, 0 )
		&& array( 11, 10 ) === $d14_u( 'unlimited', Voucher::STATUS_ACTIVE, 10, 10 )
		&& array( 10, 3 ) === $d14_u( 'unlimited', Voucher::STATUS_ACTIVE, 10, 3 )
		&& array( 0, 0 ) === $d14_u( 'unlimited', Voucher::STATUS_ACTIVE, 0, 0 )
		&& array( 0, 4 ) === $d14_u( 'balance', Voucher::STATUS_REDEEMED, 0, 4 )
);
check( 'D14: coupon status per voucher status (void/scheduled off, active on, others unchanged)', false === CouponManager::coupon_enabled_for( Voucher::STATUS_VOID ) && false === CouponManager::coupon_enabled_for( Voucher::STATUS_SCHEDULED ) && true === CouponManager::coupon_enabled_for( Voucher::STATUS_ACTIVE ) && null === CouponManager::coupon_enabled_for( Voucher::STATUS_REDEEMED ) && null === CouponManager::coupon_enabled_for( Voucher::STATUS_EXPIRED ) );
$d14_writers = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $d14_file ) {
	$d14_src = (string) file_get_contents( (string) $d14_file );
	// Voucher coupons are written by CouponManager only (create_coupon/sync_coupon, in src/Coupons since the split:
	// CouponFactory and CouponSync); other coupons (bonus, referral, campaign, first order, SKU rules) are not voucher coupons.
	if ( preg_match( '/->set_usage_limit\(|->set_usage_count\(|->set_date_expires\(/', $d14_src ) && ! preg_match( '#/(CouponManager|Coupons/CouponFactory|Coupons/CouponSync|Reissue|Referrals|Discounts/Bonus|Campaigns|FirstOrderCoupons|CouponSkuRules)\.php$#', (string) $d14_file ) ) {
		$d14_writers[] = basename( (string) $d14_file );
	}
}
check( 'D14: no other class sets usage limit/count or expiry on a voucher coupon (all through CouponManager::sync_coupon)', array() === $d14_writers, implode( ', ', $d14_writers ) );
check(
	'D14: one masking helper – codes "…" + last four in capitals (whole characters), addresses "j***@domain"; the old names are aliases',
	'…7KQ2' === VCAP\Mask::code( 'gift-ab7kq2' ) && '7KQ2' === VCAP\Mask::code_tail( 'gift-ab7kq2' ) && '…ÄÖÜÉ' === VCAP\Mask::code( 'x-äöüé' ) && '…AB' === VCAP\Mask::code( 'ab' )
		&& 'j***@example.com' === VCAP\Mask::email( 'jane@example.com' ) && 'no address' === VCAP\Mask::email( 'no address' ) && 'Ä***@b.de' === VCAP\Mask::email( 'Äxy@b.de' )
		&& 'Mail to j***@example.com and b***@test.org failed' === VCAP\Mask::addresses( 'Mail to jane@example.com and bob@test.org failed' )
		&& VCAP\Downloads::masked_code( 'abc-12345' ) === VCAP\Mask::code( 'abc-12345' ) && VCAP\Compat\PassCaptainTickets::mask_email( 'x@y.z' ) === VCAP\Mask::email( 'x@y.z' ) && VCAP\Health::mask_addresses( 'a@b.cd' ) === VCAP\Mask::addresses( 'a@b.cd' )
);
$d14_maskers = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $d14_file ) {
	// "code_hint" (Pass Captain contract payload) keeps the code's own case on purpose: it is not a display mask.
	$d14_lines = preg_grep( "/(mb_)?substr\\([^;]*-4\\b|'\\*\\*\\*@'/", (array) file( (string) $d14_file ) );
	if ( array_filter( $d14_lines, static fn( $l ) => ! str_contains( $l, "'code_hint'" ) ) && ! str_ends_with( (string) $d14_file, '/Mask.php' ) ) {
		$d14_maskers[] = basename( (string) $d14_file );
	}
}
check( 'D14: no class masks codes or addresses on its own (all through VCAP\Mask)', array() === $d14_maskers, implode( ', ', $d14_maskers ) );
$d14_core = (string) file_get_contents( dirname( __DIR__ ) . '/src/Health.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/src/Diagnostics.php' );
check( 'D14: Site Health and the diagnostics no longer reference src/Compat classes (inverted through filters)', ! preg_match( '/Compat\\\\[A-Z]/', $d14_core ) );
$d14_filters = $GLOBALS['vcap_test_filters'] ?? array();
unset( $GLOBALS['vcap_test_filters']['vcap_currency_health'] );
$d14_plain = ( new VCAP\Health() )->test_currency();
( new VCAP\Compat\MultiCurrency() )->register();
$GLOBALS['vcap_test_options'][ VCAP\Compat\MultiCurrency::UNKNOWN_OPTION ] = array( 'CZK' => time() );
$d14_compat = ( new VCAP\Health() )->test_currency();
$GLOBALS['vcap_test_filters'] = $d14_filters;
unset( $GLOBALS['vcap_test_options'][ VCAP\Compat\MultiCurrency::UNKNOWN_OPTION ] );
check( 'D14: the currency check reads the state the currency integration registers (critical with a refused currency, good without the integration)', 'good' === $d14_plain['status'] && 'critical' === $d14_compat['status'] && str_contains( wp_json_encode( $d14_compat ), 'CZK' ), wp_json_encode( array( $d14_plain['status'], $d14_compat['status'] ) ) );

echo "Audit panel package D (lifecycle, async work, performance)\n";
// AP-Q2-6: filter callbacks accept whatever the previous filter returned.
$q26_callbacks = array( array( VCAP\Downloads::class, 'query_vars' ), array( VCAP\Downloads::class, 'menu_item' ), array( VCAP\Emails::class, 'classes' ), array( VCAP\ProductSettings::class, 'type_option' ), array( VCAP\ProductSettings::class, 'tab' ), array( VCAP\Compat\Subscriptions::class, 'type_option_classes' ), array( VCAP\Settings::class, 'add_tab' ), array( VCAP\Plugin::class, 'action_links' ), array( VCAP\Privacy::class, 'exporters' ), array( VCAP\Privacy::class, 'erasers' ) );
$q26_typed     = array_values( array_filter( $q26_callbacks, static fn( $c ) => null !== ( new ReflectionMethod( $c[0], $c[1] ) )->getParameters()[0]->getType() ) );
$q26_downloads = ( new ReflectionClass( VCAP\Downloads::class ) )->newInstanceWithoutConstructor();
( new ReflectionProperty( VCAP\Downloads::class, 'settings' ) )->setValue( $q26_downloads, new VCAP\Settings() );
$GLOBALS['vcap_test_options']['vcap_myaccount_tab'] = 'yes';
$q26_vars  = $q26_downloads->query_vars( null );
$q26_menu  = $q26_downloads->menu_item( 'broken' );
$q26_tabs  = ( new VCAP\ProductSettings() )->tab( false );
$q26_types = ( new VCAP\ProductSettings() )->type_option( '' );
$q26_subs  = ( new ReflectionClass( VCAP\Compat\Subscriptions::class ) )->newInstanceWithoutConstructor()->type_option_classes( null );
unset( $GLOBALS['vcap_test_options']['vcap_myaccount_tab'] );
check( 'AP-Q2-6: filter callbacks take an untyped value and normalise a non-array from another plugin (no TypeError)', ! $q26_typed && array( VCAP\Downloads::ENDPOINT => VCAP\Downloads::ENDPOINT ) === $q26_vars && isset( $q26_menu[ VCAP\Downloads::ENDPOINT ], $q26_tabs['vcap'], $q26_types['vcap_enabled'] ) && array() === $q26_subs, wp_json_encode( array( $q26_typed, $q26_vars, $q26_menu ) ) );

// AP-P1-7: a storefront request reads no option for the recount flag; defaults are stored without touching saved values.
$GLOBALS['vcap_test_option_reads'] = array();
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { return false; }
}
VCAP\Discounts\Usage::reconcile_if_due();
check( 'AP-P1-7: Usage::reconcile_if_due() checks the request context before it reads its option', ! isset( $GLOBALS['vcap_test_option_reads'][ VCAP\Discounts\Usage::RECONCILE_DUE_OPTION ] ), wp_json_encode( $GLOBALS['vcap_test_option_reads'] ) );
$p17_keep = $GLOBALS['vcap_test_options'];
$GLOBALS['vcap_test_options']['vcap_customer_daily_limit'] = 0;
unset( $GLOBALS['vcap_test_options']['vcap_coupon_link'], $GLOBALS['vcap_test_options']['vcap_store_credit'] );
$p17_stored = VCAP\Settings::store_request_defaults();
check( 'AP-P1-7: the defaults of settings read on every request are stored autoloaded; a saved value (0) is kept', 0 === $GLOBALS['vcap_test_options']['vcap_customer_daily_limit'] && ! in_array( 'vcap_customer_daily_limit', $p17_stored, true ) && 'no' === $GLOBALS['vcap_test_options']['vcap_coupon_link'] && 'yes' === ( $GLOBALS['vcap_test_autoload']['vcap_store_credit'] ?? null ) && array() === VCAP\Settings::store_request_defaults(), wp_json_encode( $p17_stored ) );
$GLOBALS['vcap_test_options'] = $p17_keep;

// AP-S3-5: the till lock is named like the SQL comparison counts (case-insensitive, trimmed).
check( 'AP-S3-5: "Till 1", "till 1 " and "TILL 1" share one limit lock; staff locks and other tills do not', VCAP\Counter::lock_key( 'till', 'Till 1' ) === VCAP\Counter::lock_key( 'till', ' till 1 ' ) && VCAP\Counter::lock_key( 'till', 'TILL 1' ) === VCAP\Counter::lock_key( 'till', 'Till 1' ) && VCAP\Counter::lock_key( 'till', 'Till 2' ) !== VCAP\Counter::lock_key( 'till', 'Till 1' ) && VCAP\Counter::lock_key( 'user', 5 ) !== VCAP\Counter::lock_key( 'till', '5' ) );

// AP-P1-2: closing line of the exports.
$p12_rows = iterator_to_array( VCAP\Csv::with_end_row( ( static function () {
	yield array( 'Header' );
	yield array( 'a' );
	yield array( 'b' );
} )() ), false );
check( 'AP-P1-2: an export ends with "End of export: N rows" (header not counted, also for an empty export)', array( 'End of export: 2 rows' ) === end( $p12_rows ) && 4 === count( $p12_rows ) && array( array( 'H' ), array( 'End of export: 0 rows' ) ) === iterator_to_array( VCAP\Csv::with_end_row( array( array( 'H' ) ) ), false ), wp_json_encode( $p12_rows ) );
echo "\nAudit panel package A: money flows, masked notes\n";
// AP-S3-3: order notes reach REST connectors and all staff – a voucher code in an add_order_note() call is masked.
$s33_unmasked = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $s33_file ) {
	if ( ! str_ends_with( (string) $s33_file, '.php' ) ) {
		continue;
	}
	$s33_src = (string) file_get_contents( (string) $s33_file );
	$s33_off = 0;
	while ( false !== ( $s33_at = strpos( $s33_src, 'add_order_note(', $s33_off ) ) ) {
		// The call's arguments up to the matching parenthesis, with every Mask::*( … ) call taken out.
		$s33_end   = $s33_at + strlen( 'add_order_note(' );
		$s33_depth = 1;
		while ( $s33_end < strlen( $s33_src ) && $s33_depth > 0 ) {
			$s33_depth += ( '(' === $s33_src[ $s33_end ] ) - ( ')' === $s33_src[ $s33_end ] );
			++$s33_end;
		}
		$s33_args = substr( $s33_src, $s33_at, $s33_end - $s33_at );
		$s33_off  = $s33_end;
		while ( preg_match( '/Mask::\w+\s*\(/', $s33_args, $s33_m, PREG_OFFSET_CAPTURE ) ) {
			$s33_j = $s33_m[0][1] + strlen( $s33_m[0][0] );
			for ( $s33_d = 1; $s33_j < strlen( $s33_args ) && $s33_d > 0; ++$s33_j ) {
				$s33_d += ( '(' === $s33_args[ $s33_j ] ) - ( ')' === $s33_args[ $s33_j ] );
			}
			$s33_args = substr( $s33_args, 0, $s33_m[0][1] ) . 'MASKED' . substr( $s33_args, $s33_j );
		}
		if ( preg_match( '/->code\b|\$code\b|->get_code\(/', $s33_args ) ) {
			$s33_unmasked[] = basename( (string) $s33_file ) . ':' . ( substr_count( substr( $s33_src, 0, $s33_at ), "\n" ) + 1 );
		}
	}
}
check( 'AP-S3-3: no add_order_note() call passes a voucher code (->code, $code, ->get_code()) without Mask::', array() === $s33_unmasked, implode( ', ', $s33_unmasked ) );

// AP-Q1-1: the per-order ledger state machine reads only the charge events.
check( 'AP-Q1-1: the SQL list of the charge events is the same as ORDER_CHARGE_EVENTS', "'" . implode( "','", VCAP\CouponManager::ORDER_CHARGE_EVENTS ) . "'" === VCAP\CouponManager::ORDER_CHARGE_EVENTS_SQL );
$q11_hist = array( $ev( 1, 'issued', 50.0 ), $ev( 2, 'reserved', 20.0, 99 ), $ev( 3, 'topup', 120.0, 99 ), $ev( 4, 'refund_credit', 130.0, 99 ) );
check( 'AP-Q1-1: a top-up or a refund as credit of the same order does not close its open reservation', 2 === ( Tickets::open_reservation( $q11_hist )['id'] ?? 0 ) );
check( 'AP-Q1-1: the reservation of another order stays open when one order commits', 2 === ( Tickets::open_reservation( array_merge( $q11_hist, array( $ev( 5, 'committed', 130.0, 98 ) ) ) )['id'] ?? 0 ) && null === Tickets::open_reservation( array_merge( $q11_hist, array( $ev( 5, 'committed', 130.0, 99 ) ) ) ) );

// AP-Q1-5: the rounding tolerance applies only across currencies and is capped at a few minor units.
check(
	'AP-Q1-5: no rounding tolerance in the base currency; across currencies 0.1 % within 1 to 5 minor units',
	0.0 === VCAP\CouponManager::tolerance_for( 'EUR', 'EUR', 1000.0, 2 ) && 0.0 === VCAP\CouponManager::tolerance_for( 'eur', 'EUR', 1000.0, 2 ) && 0.0 === VCAP\CouponManager::tolerance_for( '', 'EUR', 1000.0, 2 )
		&& 0.05 === VCAP\CouponManager::tolerance_for( 'USD', 'EUR', 1000.0, 2 ) && 0.02 === VCAP\CouponManager::tolerance_for( 'USD', 'EUR', 20.0, 2 ) && 0.01 === VCAP\CouponManager::tolerance_for( 'USD', 'EUR', 0.5, 2 )
		&& 5.0 === VCAP\CouponManager::tolerance_for( 'USD', 'JPY', 100000.0, 0 )
);

// M12-88: export batches empty the object cache only in a way the drop-in supports.
$m88 = array();
foreach ( array( array( true, true ), array( true, false ), array( false, false ), array( false, true ) ) as $m88_case ) {
	$GLOBALS['vcap_test_cache'] = array( 'supports' => $m88_case[0], 'external' => $m88_case[1], 'calls' => array() );
	VCAP\Ledger::flush_runtime_cache();
	$m88[] = implode( ',', $GLOBALS['vcap_test_cache']['calls'] );
}
unset( $GLOBALS['vcap_test_cache'] );
check( 'M12-88: runtime flush where supported, full flush of a non-persistent cache otherwise, nothing for a persistent drop-in without flush_runtime', array( 'runtime', 'runtime', 'full', '' ) === $m88, implode( '|', $m88 ) );

// M12-58: WooPayments Multi-Currency (fake of its public API: WC_Payments_Multi_Currency()->get_enabled_currencies()
// with currency objects that have get_rate(); price in the currency = base price × rate).
if ( ! function_exists( 'WC_Payments_Multi_Currency' ) ) {
	function WC_Payments_Multi_Currency() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		return $GLOBALS['vcap_test_wcpay_mc'] ?? null;
	}
}
$m58_currency = static fn( float $rate ) => new class( $rate ) {
	public function __construct( private float $rate ) {}
	public function get_rate() {
		return $this->rate;
	}
};
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'EUR';
$m58_before  = VCAP\Compat\MultiCurrency::adapter();
$m58_filters = $GLOBALS['vcap_test_filters'];
( new VCAP\Compat\MultiCurrency() )->register();
$GLOBALS['vcap_test_wcpay_mc'] = new class( array( 'EUR' => $m58_currency( 1.0 ), 'USD' => $m58_currency( 1.25 ), 'CHF' => $m58_currency( 0.0 ) ) ) {
	public function __construct( private array $list ) {}
	public function get_enabled_currencies() {
		return $this->list;
	}
};
check(
	'M12-58: WooPayments Multi-Currency is a known converter – 125 USD = 100 EUR at its rate, a currency it lists without rate or does not list is never taken 1:1',
	'' === $m58_before && 'WooPayments Multi-Currency' === VCAP\Compat\MultiCurrency::adapter()
		&& VCAP\Compat\MultiCurrency::can_convert( 'USD' ) && 100.0 === VCAP\Compat\MultiCurrency::to_base( 125.0, 'USD' )
		&& abs( 40.0 - (float) ( new VCAP\Compat\MultiCurrency() )->convert( 50.0, 'USD', null ) ) < 1e-9
		&& ! VCAP\Compat\MultiCurrency::can_convert( 'CHF' ) && null === VCAP\Compat\MultiCurrency::to_base( 10.0, 'CHF' )
		&& ! VCAP\Compat\MultiCurrency::can_convert( 'GBP' ) && null === VCAP\Compat\MultiCurrency::to_base( 10.0, 'GBP' ),
	VCAP\Compat\MultiCurrency::adapter()
);
unset( $GLOBALS['vcap_test_wcpay_mc'], $GLOBALS['vcap_test_options'][ VCAP\Compat\MultiCurrency::UNKNOWN_OPTION ] );
$GLOBALS['vcap_test_filters'] = $m58_filters;
check( 'M12-58: without WooPayments Multi-Currency (function returns nothing) no adapter is reported', '' === VCAP\Compat\MultiCurrency::adapter() );

// M12-102: docs/FREE-PREMIUM.md keeps no hook table of its own (it went stale) but refers to the generated
// docs/HOOKS.md; every hook named in its section on extension points exists there.
$m102_doc   = (string) file_get_contents( dirname( __DIR__ ) . '/docs/FREE-PREMIUM.md' );
$m102_hooks = (string) file_get_contents( dirname( __DIR__ ) . '/docs/HOOKS.md' );
$m102_part  = (string) strstr( $m102_doc, '## Öffentliche Erweiterungspunkte' );
preg_match_all( '/`(vcap_[a-z0-9_]+)`/', $m102_part, $m102_named );
$m102_missing = array_values( array_filter( array_unique( $m102_named[1] ), static fn( $h ) => ! str_contains( $m102_hooks, '`' . $h . '`' ) ) );
check( 'M12-102: FREE-PREMIUM.md refers to docs/HOOKS.md instead of an own hook table, and every hook it names is in HOOKS.md', '' !== $m102_part && str_contains( $m102_doc, '(HOOKS.md)' ) && ! preg_match( '/^\|\s*`vcap_/m', $m102_doc ) && count( $m102_named[1] ) > 10 && array() === $m102_missing, implode( ', ', $m102_missing ) );

echo "Security check (S6–S21, E2)\n";
$sec_src = static fn( string $file ): string => (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
$s9_rest = $sec_src( 'src/API/V1/RestController.php' );
$s9_svc  = $sec_src( 'src/API/V1/RedemptionService.php' );
check( 'S9: the REST permission check no longer lets a missing redemption through, commit/release/refund pass the access rules and the owner to the service, which checks them again under the row lock', ! str_contains( $s9_rest, 'return true; // Let the callback answer 404' ) && 3 === substr_count( $s9_rest, '$this->customer_args( $request )' ) && 3 <= substr_count( $s9_svc, '$this->access_denied( $row, $args )' ) && (bool) preg_match( '/FOR UPDATE", \$key \), ARRAY_A \); \/\/ phpcs:ignore\s+\$denied\s+= \$row \? \$this->access_denied/', $s9_svc ) );
$s11_open = array( 'user_id' => 0, 'meta' => array( 'email' => 'holder@example.com' ) );
check( 'S11: an open Pass Captain ticket reservation is continued only by the account that made it, by a guest only for the same address, or by staff', VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 0, 'Holder@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 0, 'other@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 7, 'holder@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( array( 'user_id' => 0 ), 0, '', false ) && VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 7, 'other@example.com', true ) && VCAP\Compat\PassCaptain\Exchange::may_continue( array( 'user_id' => 7 ) + $s11_open, 7, 'new@example.com', false ) );
check( 'S13: the customer preview refuses password-protected (password not entered), hidden and unpublished products – editors still see published ones', VCAP\Preview::public_product( 'publish', false, 'visible', false ) && VCAP\Preview::public_product( 'publish', false, 'search', false ) && ! VCAP\Preview::public_product( 'publish', true, 'visible', false ) && ! VCAP\Preview::public_product( 'publish', false, 'hidden', false ) && ! VCAP\Preview::public_product( 'draft', false, 'visible', false ) && VCAP\Preview::public_product( 'publish', true, 'hidden', true ) && ! VCAP\Preview::public_product( 'private', false, 'visible', true ) && str_contains( $sec_src( 'src/Preview.php' ), 'self::public_product( (string) get_post_status( $parent_id ), post_password_required( $parent_id )' ) );
check( 'S6: referral rewards are "Individual use only" (several rewards cannot be stacked)', (bool) preg_match( '/function reward_coupon\(.*?set_individual_use\( true \).*?return \$coupon->save\(\)/s', $sec_src( 'src/Referrals.php' ) ) );

echo "\n$passed passed, $failed failed. Output: $out\n";
exit( $failed ? 1 : 0 );
