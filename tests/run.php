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

echo "Redemption API\n";
$valid_keys   = array( 'it-' . str_repeat( 'a', 20 ), 'learnboating:grant:1234', '550e8400-e29b-41d4-a716-446655440000' );
$invalid_keys = array( 'short', '-leading-dash', str_repeat( 'x', 65 ), 'has space 12345', "nl\n12345678" );
check( 'valid idempotency keys are accepted', count( $valid_keys ) === count( array_filter( $valid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
check( 'malformed idempotency keys are rejected', 0 === count( array_filter( $invalid_keys, static fn( $k ) => preg_match( \VCAP\API\V1\RedemptionService::KEY_PATTERN, $k ) ) ) );
$h = \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 5 );
check( 'request fingerprint is stable and amount-normalised', $h === \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.0000, 5 ) );
check( 'request fingerprint changes with target, amount or user', count( array_unique( array( $h, \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:2', 30, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30.01, 5 ), \VCAP\API\V1\RedemptionService::request_hash( 7, 'course:1', 30, 6 ) ) ) ) === 4 );

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
check( 'ticket page: check-in form carries a one-time key, refusals are red', str_contains( $ticket_tpl, 'vcap_checkin_key' ) && str_contains( $ticket_tpl, 'notice--error' ) );

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

echo "License server key set (rotation: current + next key, key_id, signatures[])\n";
// Deterministic keys from seeds (WP License Captain docs/API-V1.md → Key rotation): K1 = 32 × 0x01 (current),
// K2 = 32 × 0x02 (next), K3 = 32 × 0x03 (a key nobody pinned). A fake server answers activation and entitlement
// checks in the given mode; the child process pins the key set given as argument.
$keyset_code = <<<'PHP'
define( 'VCAP_LICENSE_PUBLIC_KEY', $argv[2] );
require $argv[1];
$c    = VCAP\Licensing\LicenseClient::class;
$seed = static fn( int $b ) => sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( chr( $b ), 32 ) ) );
$kid  = static fn( int $b ) => $c::key_id( sodium_crypto_sign_publickey_from_secretkey( $seed( $b ) ) );
$mode = $argv[3];
$GLOBALS['vcap_test_http'] = static function ( $url, $args ) use ( $seed, $kid, $mode, &$secret ) {
	$body = json_decode( (string) $args['body'], true ) ?: array();
	if ( str_contains( $url, '/activations' ) ) {
		$secret = $body['installation_secret'];
		$id     = $body['installation_id'];
		$data   = array( 'activation_id' => 7 );
	} else {
		$id   = $args['headers']['X-WPLC-Installation-Id'];
		$data = array( 'license_status' => 'active', 'entitlements' => array( 'voucher.promotions' => true ), 'cache_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
	}
	$json = json_encode( $data, JSON_UNESCAPED_SLASHES );
	$msg  = "wplc-response-v1\n" . strtolower( $id ) . "\n" . $json;
	$sig  = static fn( int $b ) => base64_encode( sodium_crypto_sign_detached( $msg, $seed( $b ) ) );
	$env  = array( 'data' => $data, 'signature' => hash_hmac( 'sha256', $json, $secret ) );
	switch ( $mode ) {
		case 'rotation': // current key K1 in the main fields, both keys in signatures[] (current first)
			$env += array( 'signature_ed25519' => $sig( 1 ), 'key_id' => $kid( 1 ), 'signatures' => array( array( 'key_id' => $kid( 1 ), 'signature_ed25519' => $sig( 1 ) ), array( 'key_id' => $kid( 2 ), 'signature_ed25519' => $sig( 2 ) ) ) );
			break;
		case 'promoted': // K2 became the current key, K1 is gone
			$env += array( 'signature_ed25519' => $sig( 2 ), 'key_id' => $kid( 2 ) );
			break;
		case 'mislabelled': // K2 signs, but the key id names K1 (or is missing): every pinned key is tried
			$env += array( 'signature_ed25519' => $sig( 2 ), 'key_id' => $kid( 1 ), 'signatures' => array( array( 'signature_ed25519' => $sig( 2 ) ) ) );
			break;
		case 'foreign': // a key of one's own, labelled with the pinned key's id
			$env += array( 'signature_ed25519' => $sig( 3 ), 'key_id' => $kid( 1 ), 'signatures' => array( array( 'key_id' => $kid( 2 ), 'signature_ed25519' => $sig( 3 ) ) ) );
			break;
	}
	return array( 'code' => 200, 'body' => json_encode( $env ) );
};
$client = new VCAP\Licensing\LicenseClient( 'https://licenses.example.test', 'voucher-captain', '1.0', 'vcap_keyset' );
$act    = $client->activate( 'KEY-1' );
$online = is_wp_error( $act ) ? $act : $client->get_entitlements();
$cache  = get_option( 'vcap_keyset_entitlements_cache' );
$names  = array( $kid( 1 ) => 'K1', $kid( 2 ) => 'K2' );
echo json_encode( array(
	'pinned'    => count( $c::pinned_public_keys() ) . ( array( 'invalid' ) === $c::pinned_public_keys() ? ' invalid' : '' ),
	'activate'  => is_wp_error( $act ) ? $act->get_error_code() : 'ok',
	'snapshot'  => is_array( $cache ) ? ( $names[ $cache['key_id'] ] ?? '?' ) . ( 88 === strlen( $cache['signature_ed25519'] ) ? '' : ' no signature' ) : 'none',
	'offline'   => is_array( $client->cached_entitlements() ) ? 'ok' : 'refused',
) );
PHP;
$keyset_file = $out . '/license-keyset.php';
file_put_contents( $keyset_file, '<?php ' . $keyset_code );
$k1 = 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=';
$k2 = 'gTl3Dqh9F19Wo1Rmw0x+zMuNipG07jeiXfYPW4/Js5Q=';
$keyset = static function ( string $pinned, string $mode ) use ( $keyset_file, $sig_prepend ): string {
	return trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ( '' !== $sig_prepend ? ' -d auto_prepend_file=' . escapeshellarg( $sig_prepend ) : '' ) . ' ' . escapeshellarg( $keyset_file ) . ' ' . escapeshellarg( __DIR__ . '/stubs.php' ) . ' ' . escapeshellarg( $pinned ) . ' ' . escapeshellarg( $mode ) . ' 2>&1' ) );
};
$r = $keyset( "$k1, $k2", 'rotation' );
check( 'key set current+next, server signs with both: accepted, the snapshot keeps the current key\'s signature', '{"pinned":"2","activate":"ok","snapshot":"K1","offline":"ok"}' === $r, $r );
$r = $keyset( $k2, 'rotation' );
check( 'build pinning only the next key: the matching entry of signatures[] is used and kept with the snapshot', '{"pinned":"1","activate":"ok","snapshot":"K2","offline":"ok"}' === $r, $r );
$r = $keyset( "$k1\n$k2", 'promoted' );
check( 'after the promotion (server signs with the next key only) a build pinning both keys keeps working', '{"pinned":"2","activate":"ok","snapshot":"K2","offline":"ok"}' === $r, $r );
$r = $keyset( $k1, 'promoted' );
check( 'a build pinning only the old key refuses the promoted key', '{"pinned":"1","activate":"wplc_server_signature_invalid","snapshot":"none","offline":"refused"}' === $r, $r );
$r = $keyset( "$k1 $k2", 'mislabelled' );
check( 'a wrong or missing key_id falls back to every pinned key (snapshot names the key that verified)', '{"pinned":"2","activate":"ok","snapshot":"K2","offline":"ok"}' === $r, $r );
$r = $keyset( "$k1,$k2", 'foreign' );
check( 'a signature of an unpinned key is refused even when it names a pinned key_id', '{"pinned":"2","activate":"wplc_server_signature_invalid","snapshot":"none","offline":"refused"}' === $r, $r );
$r = $keyset( "$k1, not-a-key", 'rotation' );
check( 'a malformed key in the set fails closed (nothing verifies)', '{"pinned":"1 invalid","activate":"wplc_server_signature_invalid","snapshot":"none","offline":"refused"}' === $r, $r );
$r = $keyset( ' , ', 'rotation' );
check( 'an empty key set fails closed', '{"pinned":"1 invalid","activate":"wplc_server_signature_invalid","snapshot":"none","offline":"refused"}' === $r, $r );

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
$wplc_ref = static fn( string $yml ): array => preg_match_all( '/repository: hastla007\/wp-license\s*\n(?:\s*#[^\n]*\n)*\s*ref: ([0-9a-f]{40})/', $yml, $m ) ? array_unique( $m[1] ) : array();
check( 'release.yml builds the manifest with the WP License Captain commit the CI job license-acceptance tested', 1 === count( $wplc_ref( $smoke_yml ) ) && $wplc_ref( $smoke_yml ) === $wplc_ref( $release_yml ), json_encode( array( $wplc_ref( $smoke_yml ), $wplc_ref( $release_yml ) ) ) );
check( 'release.yml refuses a tag that is not on main and a licence server commit that is not merged', str_contains( $release_yml, 'merge-base --is-ancestor "$SHA" origin/main' ) && str_contains( $release_yml, 'merge-base --is-ancestor "$ref" FETCH_HEAD' ) );
check( 'ci-green checks every job of the CI run, not only the run conclusion', str_contains( $release_yml, 'actions/runs/${id}/jobs' ) && str_contains( $release_yml, 'REPORT_ONLY: plugin-check' ) );
check( 'only the Pass Captain work branch may be skipped; the pinned release and main must be downloaded', (bool) preg_match( '/pass_captain_ref: v\d+\.\d+\.\d+\s*\n\s*archive: [0-9a-f]{40}\s*\n\s*required: true/', $smoke_yml ) && (bool) preg_match( '/pass_captain_ref: main\s*\n\s*archive: refs\/heads\/main\s*\n\s*required: true/', $smoke_yml ) && str_contains( $smoke_yml, 'if [ "${{ matrix.required }}" = "true" ]; then' ) );
check( 'quality-wide blocks on WordPress.Security findings and on growing findings (no continue-on-error)', (bool) preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*WordPress\.Security\.EscapeOutput(?:(?!\n  [a-z-]+:\n).)*Findings must not grow/s', $smoke_yml ) && ! preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*continue-on-error/s', $smoke_yml ) );
$smoke_release = (string) file_get_contents( "$root/.github/workflows/release-smoke.yml" );
check( 'the release smoke against the production licence server is manual, gated by an environment, key only from a secret', str_contains( $smoke_release, "on:\n  workflow_dispatch:" ) && ! preg_match( '/^\s+(push|pull_request|schedule):/m', $smoke_release ) && str_contains( $smoke_release, 'environment: release-smoke' ) && str_contains( $smoke_release, '${{ secrets.VCAP_SMOKE_LICENSE_KEY }}' ) && str_contains( $smoke_release, 'if: always()' ) && is_file( "$root/tests/release-smoke.php" ) );
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

echo "Refunds, returns and top-ups (wave I)\n";
// T11: a top-up adds its face value like a new gift card – never what was paid after coupons.
$topup_cfg = ProductConfig::DEFAULTS;
check( 'top-up value: line price before discounts, gross where prices include tax', array( 119.0, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 100.0, 19.0, true ) );
check( 'top-up value in a net-price shop: the net price (like a new gift card), not net + VAT', array( 50.0, false ) === VCAP\TopUp::line_value( $topup_cfg, 1, 50.0, 9.5, false ) );
check( 'top-up value: a fixed amount per unit, already in the base currency', array( 50.0, true ) === VCAP\TopUp::line_value( array( 'amount_source' => 'fixed', 'amount' => '25' ) + $topup_cfg, 2, 0.0, 0.0, true ) );
check( 'top-up value: the amount the customer entered, per unit', array( 80.0, false ) === VCAP\TopUp::line_value( array( 'amount_source' => 'custom' ) + $topup_cfg, 2, 0.0, 0.0, true, '40' ) );
check( 'top-up paid completely with another gift card still adds its value (subtotal, not total)', VCAP\TopUp::line_value( $topup_cfg, 1, 100.0, 0.0, true )[0] > 0 );
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
check( 'free build: outstanding value, redemption journal and reconciliation stay available, only the campaign report is locked', VCAP\Admin\ReportsAdmin::locked( 'campaigns' ) && ! VCAP\Admin\ReportsAdmin::locked( 'liability' ) && ! VCAP\Admin\ReportsAdmin::locked( 'journal' ) && ! VCAP\Admin\ReportsAdmin::locked( 'reconcile' ) );
$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' );
check( 'every edition registers the report export (liability and journal CSV are the accounting record of sold vouchers)', (bool) preg_match( '/if \( is_admin\(\) \) \{.*?\n\t\t\t\( new Admin\\\\ReportsAdmin\(\) \)->register\(\);/s', $plugin_src ) && ! str_contains( $plugin_src, "allows( 'reports' )" ) );

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
check( 'a deleted site takes its six voucher tables with it', 7 === count( $dropped ) && in_array( 'wp_3_vcap_vouchers', $dropped, true ) && in_array( 'wp_3_vcap_claims', $dropped, true ) && ! in_array( 'wp_vcap_vouchers', $dropped, true ) );

// A6: named MySQL locks are server-wide: the site's table prefix keeps them apart.
$lock_a                  = VCAP\Install::lock_name( 'delivery_42' );
$GLOBALS['wpdb']->prefix = 'wp_2_';
$lock_b                  = VCAP\Install::lock_name( 'delivery_42' );
$GLOBALS['wpdb']->prefix = 'wp_';
check( 'the same voucher id on two sites uses two different locks (at most 64 characters)', $lock_a !== $lock_b && strlen( $lock_a ) <= 64 && str_ends_with( $lock_a, '_delivery_42' ) );
check( 'deliveries and promotion holds use the per-site lock name', str_contains( (string) file_get_contents( VCAP_PATH . 'src/Delivery.php' ), "Install::lock_name( 'delivery_'" ) && 2 === substr_count( (string) file_get_contents( VCAP_PATH . 'src/Discounts/Usage.php' ), "Install::lock_name( 'usage_'" ) );

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
		return array( 'id', 'code', 'coupon_id', 'type', 'status', 'order_id', 'order_item_id', 'item_index', 'product_id', 'variation_id', 'template_id', 'usage_mode', 'discount_type', 'initial_amount', 'balance', 'currency', 'expires_at', 'buyer_name', 'buyer_email', 'user_id', 'recipient_name', 'recipient_email', 'message', 'fields', 'send_to', 'delivery_at', 'delivered_at', 'token', 'transferable', 'terms', 'max_checkins', 'checkins', 'last_checkin_at', 'delivered_roles', 'created_at', 'updated_at', 'voucher_id', 'event', 'amount', 'balance_after', 'note', 'batch', 'imported_at', 'used_at', 'redemption_key', 'target', 'state', 'request_hash', 'meta', 'campaign_id', 'customer_key', 'claim_key', 'kind' );
	},
	'/^SHOW INDEX FROM wp_vcap_events$/' => array( 'PRIMARY', 'voucher_id', 'voucher_order', 'event' ),
	'/^SHOW INDEX FROM/'            => static function ( $q ) {
		return array( 'PRIMARY', 'code', 'token', 'order_item_slot', 'order_id', 'coupon_id', 'status', 'product_id', 'recipient_email', 'buyer_email', 'user_type', 'batch_free', 'redemption_key', 'voucher_id', 'user_id', 'state_expires', 'campaign_customer', 'order_campaign', 'kind_created' );
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
$GLOBALS['wpdb'] = $saved_wpdb;

echo "\n$passed passed, $failed failed. Output: $out\n";
exit( $failed ? 1 : 0 );
