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

$copy = new WC_Coupon( $codes[0] );
vcap_camp_assert(
	'percent' === $copy->get_discount_type() && 15.0 === (float) $copy->get_amount() && 1 === (int) $copy->get_usage_limit()
	&& $copy->get_individual_use() && 30.0 === (float) $copy->get_minimum_amount() && $copy->get_date_expires()
	&& $copy->get_date_expires()->getTimestamp() === $template->get_date_expires()->getTimestamp() && 0 === (int) $copy->get_usage_count(),
	'generated coupons copy the template settings and start unused'
);
$rows = $campaigns->campaign_rows( $name );
vcap_camp_assert( 6 === count( $rows ) && 'code' === $rows[0][0] && in_array( strtolower( $codes[0] ), array_map( 'strtolower', array_column( array_slice( $rows, 1 ), 0 ) ), true ), 'the campaign CSV lists every code' );
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
vcap_camp_assert( 11 === count( $campaigns->pool_rows( $batch ) ), 'the pool CSV lists every code' );
vcap_camp_assert( array( "'=HYPERLINK(1)", 'ok' ) === VCAP\Campaigns::safe_row( array( '=HYPERLINK(1)', 'ok' ) ), 'CSV cells cannot inject spreadsheet formulas' );

echo "Voucher Captain campaign checks passed.\n";
