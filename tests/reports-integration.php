<?php
/**
 * Statistics per code campaign/partner, promotion and coupon.
 *   wp eval-file tests/reports-integration.php
 */

function vcap_report_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

$tag     = strtolower( wp_generate_password( 6, false ) );
$product = new WC_Product_Simple();
$product->set_name( 'Report product' );
$product->set_regular_price( '40' );
$product->set_virtual( true );
$product->save();

$template = new WC_Coupon();
$template->set_code( 'report-template-' . $tag );
$template->set_discount_type( 'fixed_cart' );
$template->set_amount( '5' );
$template->save();

$campaign = 'Partner ' . $tag;
$codes    = ( new VCAP\Campaigns( vcap()->code_pool, vcap()->vouchers ) )->generate_coupons( $template->get_id(), $campaign, 3, array( 'prefix' => 'RP', 'length' => 8 ) );
vcap_report_assert( is_array( $codes ) && 3 === count( $codes ), 'three partner codes are generated' );

$orders = array();
foreach ( array( $codes[0], $codes[1] ) as $code ) {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'report-' . $tag . '@example.com' );
	$order->calculate_totals();
	$order->apply_coupon( $code );
	$order->set_status( 'completed' );
	$order->save();
	$orders[] = $order;
}
$single = 'report-single-' . $tag;
$coupon = new WC_Coupon();
$coupon->set_code( $single );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( '10' );
$coupon->save();
$order = wc_create_order();
$order->add_product( $product, 1 );
$order->calculate_totals();
$order->apply_coupon( $single );
$order->set_status( 'processing' );
$order->save();
$pending = wc_create_order();
$pending->add_product( $product, 1 );
$pending->calculate_totals();
$pending->apply_coupon( $codes[2] );
$pending->save();

$rows = ( new VCAP\Reports() )->collect( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
$row  = $rows[ 'campaign:' . $campaign ] ?? null;
vcap_report_assert( null !== $row, 'the partner campaign appears in the report' );
vcap_report_assert( 3 === $row['issued'] && 2 === $row['uses'] && 2 === $row['orders'], 'issued codes, uses and orders are counted (unpaid orders do not count)' );
vcap_report_assert( 10.0 === $row['discount'] && 70.0 === $row['revenue'], 'discount (2 × 5) and revenue (2 × 35) are summed' );
$other = $rows[ 'coupon:' . strtoupper( $single ) ] ?? null;
vcap_report_assert( null !== $other && 4.0 === $other['discount'] && 1 === $other['orders'], 'a single coupon has its own line' );

$refund = wc_create_refund( array( 'order_id' => $orders[0]->get_id(), 'amount' => 35 ) );
vcap_report_assert( ! is_wp_error( $refund ), 'a refund can be recorded' );
$rows = ( new VCAP\Reports() )->collect( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
vcap_report_assert( 35.0 === $rows[ 'campaign:' . $campaign ]['revenue'], 'refunds are subtracted from the revenue' );
$csv = VCAP\Reports::csv_rows( $rows );
vcap_report_assert( 'type' === $csv[0][0] && count( $csv ) === count( $rows ) + 1, 'the CSV export has a header and one line per group' );
echo "Voucher Captain report checks passed.\n";
