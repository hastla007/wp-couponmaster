<?php
/**
 * Bulk code generation checks. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/campaign-integration.php
 */

function vcap_camp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$campaigns = new VCAP\Campaigns( vcap()->code_pool, vcap()->vouchers );

$template = new WC_Coupon();
$template->set_code( 'vcap-tpl-' . strtolower( wp_generate_password( 8, false, false ) ) );
$template->set_discount_type( 'percent' );
$template->set_amount( 15 );
$template->set_usage_limit( 1 );
$template->set_individual_use( true );
$template->set_minimum_amount( 30 );
$template->set_date_expires( strtotime( '+60 days' ) );
$template->save();

$name  = 'IT Partner ' . wp_generate_password( 6, false, false );
$codes = $campaigns->generate_coupons( $template->get_id(), $name, 5, array( 'prefix' => 'PART', 'length' => 10 ) );
vcap_camp_assert( is_array( $codes ) && 5 === count( $codes ) && 5 === count( array_unique( $codes ) ), 'five unique campaign codes are generated' );
vcap_camp_assert( 5 === count( array_filter( $codes, static fn( $c ) => (bool) preg_match( '/^PART-[A-HJ-NP-Z2-9]{10}$/', $c ) ) ), 'codes follow the requested prefix and length' );

$short = $campaigns->generate_coupons( $template->get_id(), $name . ' short', 3, array( 'prefix' => 'SH', 'length' => 6 ) );
vcap_camp_assert( is_array( $short ) && 3 === count( array_filter( $short, static fn( $c ) => (bool) preg_match( '/^SH-[A-HJ-NP-Z2-9]{10}$/', $c ) ) ), 'campaign codes with a discount get at least 2^50 combinations (6 characters are raised to 10)' );

$copy = new WC_Coupon( $codes[0] );
vcap_camp_assert(
	'percent' === $copy->get_discount_type() && 15.0 === (float) $copy->get_amount() && 1 === (int) $copy->get_usage_limit()
	&& $copy->get_individual_use() && 30.0 === (float) $copy->get_minimum_amount() && $copy->get_date_expires()
	&& $copy->get_date_expires()->getTimestamp() === $template->get_date_expires()->getTimestamp() && 0 === (int) $copy->get_usage_count(),
	'generated coupons copy the template settings and start unused'
);
$rows = iterator_to_array( $campaigns->campaign_rows( $name ), false );
vcap_camp_assert( 6 === count( $rows ) && __( 'Code', 'voucher-captain' ) === $rows[0][0] && in_array( strtolower( $codes[0] ), array_map( 'strtolower', array_column( array_slice( $rows, 1 ), 0 ) ), true ), 'the campaign CSV lists every code' );
vcap_camp_assert( in_array( $name, array_column( $campaigns->campaigns(), 'campaign' ), true ), 'the campaign appears in the campaign list' );

$voucher_template = new WC_Coupon();
$voucher_template->set_code( 'vcap-vt-' . strtolower( wp_generate_password( 8, false, false ) ) );
$voucher_template->update_meta_data( '_vcap_voucher', 'yes' );
$voucher_template->save();
vcap_camp_assert( is_wp_error( $campaigns->generate_coupons( $voucher_template->get_id(), 'x', 1, array() ) ), 'voucher coupons cannot be used as templates' );
vcap_camp_assert( is_wp_error( $campaigns->generate_coupons( $template->get_id(), 'x', VCAP\Campaigns::MAX_RUN + 1, array() ) ), 'runs above the maximum are refused' );

$batch = 'IT Pool ' . wp_generate_password( 6, false, false );
$pool  = $campaigns->generate_pool( $batch, 10, array( 'length' => 12 ) );
vcap_camp_assert( is_array( $pool ) && 10 === count( $pool ), 'ten pool codes are generated' );
$found = array_values( array_filter( vcap()->code_pool->batches(), static fn( $b ) => $b['batch'] === $batch ) );
vcap_camp_assert( $found && 10 === $found[0]['total'] && 10 === $found[0]['free'], 'the pool batch holds ten free codes' );
vcap_camp_assert( 11 === count( iterator_to_array( $campaigns->pool_rows( $batch ), false ) ), 'the pool CSV lists every code' );
$p12_flushes = VCAP\Ledger::$flushes;
$p12_rows    = iterator_to_array( VCAP\Csv::with_end_row( $campaigns->pool_rows( $batch, 3 ) ), false );
$p12_codes   = array_column( array_slice( $p12_rows, 1, 10 ), 0 );
vcap_camp_assert( 12 === count( $p12_rows ) && 10 === count( array_unique( $p12_codes ) ) && ! array_diff( array_map( 'strtolower', $pool ), array_map( 'strtolower', $p12_codes ) ), 'AP-P1-2: the pool CSV is read by id in batches (3 per query here) and still lists every code once', count( $p12_rows ) );
vcap_camp_assert( array( 'End of export: 10 rows' ) === end( $p12_rows ) && VCAP\Ledger::$flushes - $p12_flushes >= 4, 'AP-P1-2: the pool CSV ends with "End of export: 10 rows" and the runtime cache is flushed after every batch', array( end( $p12_rows ), VCAP\Ledger::$flushes - $p12_flushes ) );
$claimed = array();
for ( $i = 0; $i < 10; $i++ ) {
	$claimed[] = vcap()->code_pool->claim( $batch );
}
vcap_camp_assert( 10 === count( array_unique( array_filter( $claimed ) ) ) && ! array_diff( $claimed, $pool ) && null === vcap()->code_pool->claim( $batch ), 'every pool code is claimed exactly once, then the batch is empty' );
vcap_camp_assert( array_values( array_intersect( $pool, $claimed ) ) !== $claimed, 'pool codes are handed out in random order, not in file order' );
vcap_camp_assert( array( "'=HYPERLINK(1)", 'ok' ) === VCAP\Campaigns::safe_row( array( '=HYPERLINK(1)', 'ok' ) ), 'CSV cells cannot inject spreadsheet formulas' );

// B10: parallel claims never fail on each other – here a competing request takes a free code right before each
// claim statement; the claim still gets one of the remaining codes while any is left (before: five lost rounds
// returned null although codes were free).
global $wpdb;
$b10_batch = 'B10-' . strtolower( wp_generate_password( 6, false, false ) );
$b10_codes = $campaigns->generate_pool( $b10_batch, 12, array( 'length' => 12 ) );
$b10_pool  = VCAP\Install::tables()['pool'];
$b10_busy  = false;
$b10_rival = static function ( $q ) use ( &$b10_busy, $wpdb, $b10_pool, $b10_batch ) {
	if ( $b10_busy || ! preg_match( '/^UPDATE ' . preg_quote( $b10_pool, '/' ) . ' SET used_at/', $q ) ) {
		return $q;
	}
	$b10_busy = true;
	// The competitor takes the very code this claim aims at when the statement names it, else the next free one.
	$target = preg_match( '/WHERE id = (\d+)/', $q, $m ) ? (int) $m[1] : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$b10_pool} WHERE batch = %s AND used_at IS NULL ORDER BY id LIMIT 1", $b10_batch ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "UPDATE {$b10_pool} SET used_at = %s, voucher_id = 999999 WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $target ) ); // phpcs:ignore
	$b10_busy = false;
	return $q;
};
add_filter( 'query', $b10_rival );
$b10_got = array();
for ( $i = 0; $i < 4; $i++ ) {
	$b10_got[] = vcap()->code_pool->claim( $b10_batch );
}
remove_filter( 'query', $b10_rival );
vcap_camp_assert( 4 === count( array_unique( array_filter( $b10_got ) ) ) && ! array_diff( array_filter( $b10_got ), $b10_codes ), 'B10: claims racing with a competitor for the same code still get a free code of the batch' );

// A8: the free codes of a batch are counted on the (batch, used_at) index for that batch only (no GROUP BY over
// the whole pool on every product view), once per request.
VCAP\ProductChecks::flush();
$a8_expected = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b10_pool} WHERE batch = %s AND used_at IS NULL", $b10_batch ) ); // phpcs:ignore
$a8_queries  = array();
$a8_log     = static function ( $q ) use ( &$a8_queries ) {
	$a8_queries[] = $q;
	return $q;
};
add_filter( 'query', $a8_log );
$a8_free  = VCAP\ProductChecks::free_codes( $b10_batch );
$a8_again = VCAP\ProductChecks::free_codes( $b10_batch );
remove_filter( 'query', $a8_log );
$a8_pool = array_values( preg_grep( '/' . preg_quote( $b10_pool, '/' ) . '/', $a8_queries ) );
vcap_camp_assert( $a8_expected > 0 && $a8_expected === $a8_free && $a8_expected === $a8_again, 'A8: the free codes of the batch are counted (' . $a8_free . ')' );
vcap_camp_assert( 1 === count( $a8_pool ) && ! str_contains( $a8_pool[0], 'GROUP BY' ) && str_contains( $a8_pool[0], 'batch = ' ), 'A8: one indexed count of that batch per request, no GROUP BY over the pool' );
$a8_queries = array();
VCAP\ProductChecks::shop_problems();
add_filter( 'query', $a8_log );
VCAP\ProductChecks::shop_problems();
remove_filter( 'query', $a8_log );
vcap_camp_assert( ! preg_grep( '/_vcap_enabled/', $a8_queries ), 'A8: the shop problems shown on admin screens are cached (no product search on the next screen)' );

// B8: the campaign export reads its coupons in chunks with their meta (not one query per field and coupon).
wp_cache_flush();
$b8_before = $wpdb->num_queries;
$b8_rows   = iterator_to_array( $campaigns->campaign_rows( $name ), false );
vcap_camp_assert( 6 === count( $b8_rows ) && $wpdb->num_queries - $b8_before <= 12, 'B8: the campaign export of five coupons reads them in a chunk (' . ( $wpdb->num_queries - $b8_before ) . ' queries)' );

echo "Voucher Captain campaign checks passed.\n";
