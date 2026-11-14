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
vcap_report_assert( __( 'Type', 'voucher-captain' ) === $csv[0][0] && count( $csv ) === count( $rows ) + 1, 'the CSV export has a header and one line per group' );
// Ledger: outstanding value at a date and the redemption journal (own currency so other fixtures do not count).
$ledger_new = static function ( string $mode, float $value ) use ( $tag ) {
	$v                 = new VCAP\Voucher();
	$v->code           = 'ledger-' . $mode . '-' . strtolower( wp_generate_password( 8, false, false ) );
	$v->order_item_id  = 94000000 + random_int( 1, 9999999 );
	$v->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
	$v->usage_mode     = $mode;
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = 'XTS'; // ISO 4217 test currency.
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	vcap()->vouchers->log( $v->id, 'issued', $value, 'balance' === $mode ? $value : null, '' );
	return $v;
};
$card   = $ledger_new( 'balance', 50 );
$single = $ledger_new( 'single', 25 );
$used   = $ledger_new( 'single', 10 );
vcap()->vouchers->log( $card->id, 'redeemed', 20, 30, 'Order test', 0 );
vcap()->vouchers->log( $used->id, 'redeemed', 10, null, 'Order test', 0 );
$ledger    = new VCAP\Ledger( vcap()->vouchers );
$today     = wp_date( 'Y-m-d' );
$liability = $ledger->liability( $today )['totals']['XTS'] ?? array();
vcap_report_assert( 30.0 === (float) ( $liability['giftcard']['amount'] ?? 0 ) && 25.0 === (float) ( $liability['voucher']['amount'] ?? 0 ) && 1 === (int) ( $liability['voucher']['count'] ?? 0 ), 'outstanding value: gift card balance and the unredeemed single-use voucher, not the redeemed one' );
$yesterday = wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
vcap_report_assert( empty( $ledger->liability( $yesterday )['totals']['XTS'] ), 'vouchers issued later are not part of an earlier day' );
$journal = array_values( array_filter( $ledger->journal( $today, $today ), static fn( $r ) => 'XTS' === $r['currency'] && 'redeemed' === $r['event'] ) );
vcap_report_assert( 2 === count( $journal ) && 30.0 === (float) $journal[0]['balance_after'], 'the journal lists the redemptions of the day with amount and balance' );
$jcsv = VCAP\Ledger::journal_csv( $journal );
vcap_report_assert( VCAP\Ledger::journal_header()[0] === $jcsv[0][0] && 3 === count( $jcsv ), 'the journal exports as CSV' );

// The liability totals are summed in SQL with the same rules as the streamed per-voucher detail.
$voided = $ledger_new( 'single', 7 );
vcap()->vouchers->log( $voided->id, 'voided', null, null, '' );
$back = $ledger_new( 'single', 4 );
vcap()->vouchers->log( $back->id, 'voided', null, null, '' );
vcap()->vouchers->log( $back->id, 'reactivated', null, null, '' );
$liability = $ledger->liability( $today )['totals']['XTS'] ?? array();
$detail    = array_values( array_filter( iterator_to_array( $ledger->liability_rows( $today ), false ), static fn( $r ) => 'XTS' === $r['currency'] ) );
vcap_report_assert( 29.0 === (float) ( $liability['voucher']['amount'] ?? 0 ) && 2 === (int) ( $liability['voucher']['count'] ?? 0 ), 'a voided voucher carries no value, a reactivated one does again' );
vcap_report_assert( 3 === count( $detail ) && abs( 59.0 - array_sum( array_column( $detail, 'outstanding' ) ) ) < 0.001, 'the streamed liability detail matches the totals' );
vcap_report_assert( 3 === count( $jcsv ) && 3 === count( VCAP\Ledger::journal_csv( new ArrayIterator( $journal ) ) ), 'the journal CSV also takes a stream of bookings' );
$all = iterator_to_array( $ledger->journal_all( $today, $today, 1 ), false );
vcap_report_assert( count( $all ) === $ledger->journal_count( $today, $today ) && count( $all ) >= 2, 'the journal export reads every booking in batches' );

global $wpdb;
// B3: the report sums code lines, totals and refunds in SQL per chunk – the number of queries does not grow with
// the number of orders – and a repeated call comes from the cache.
$b3_coupon = new WC_Coupon();
$b3_coupon->set_code( 'report-b3-' . $tag );
$b3_coupon->set_discount_type( 'fixed_cart' );
$b3_coupon->set_amount( '2' );
$b3_coupon->save();
$b3_add = static function ( int $count ) use ( $product, $tag ) {
	for ( $i = 0; $i < $count; $i++ ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->apply_coupon( 'report-b3-' . $tag );
		$order->set_status( 'completed' );
		$order->save();
	}
	return $order;
};
$b3_measure = static function ( string $from, string $to ) use ( $wpdb ): array {
	wp_cache_flush();
	$before = $wpdb->num_queries;
	$rows   = ( new VCAP\Reports() )->collect( $from, $to );
	return array( $rows, $wpdb->num_queries - $before );
};
$b3_order = $b3_add( 5 );
$b3_to    = wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );
list( , $b3_small ) = $b3_measure( wp_date( 'Y-m-d', time() - 4 * DAY_IN_SECONDS ), $b3_to );
$b3_order = $b3_add( 20 );
$b3_from  = wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
list( $b3_rows, $b3_large ) = $b3_measure( $b3_from, $b3_to );
$b3_row = $b3_rows[ 'coupon:' . strtoupper( 'report-b3-' . $tag ) ] ?? null;
vcap_report_assert( null !== $b3_row && 25 === $b3_row['orders'] && 50.0 === $b3_row['discount'] && 950.0 === $b3_row['revenue'], 'B3: 25 orders with a coupon are summed (discount 25 × 2, revenue 25 × 38)' );
vcap_report_assert( $b3_large - $b3_small <= 3, 'B3: 20 more orders cost no more queries (' . $b3_small . ' → ' . $b3_large . '; no order object is loaded)' );
$b3_before = $wpdb->num_queries;
$b3_again  = ( new VCAP\Reports() )->collect( $b3_from, $b3_to );
vcap_report_assert( $b3_again === $b3_rows && $wpdb->num_queries - $b3_before <= 4, 'B3: a repeated call is served from the cache (' . ( $wpdb->num_queries - $b3_before ) . ' queries)' );
$b3_refund = wc_create_refund( array( 'order_id' => $b3_order->get_id(), 'amount' => 8 ) );
$b3_rows   = ( new VCAP\Reports() )->collect( $b3_from, $b3_to );
vcap_report_assert( ! is_wp_error( $b3_refund ) && 942.0 === $b3_rows[ 'coupon:' . strtoupper( 'report-b3-' . $tag ) ]['revenue'], 'B3: a new refund is included at once (not served from the cache)' );

// B14: the liability of a day is cached until the ledger changes; the journal reads the id range of the period.
$b14_before = $wpdb->num_queries;
$b14_first  = $ledger->liability( $today );
$b14_mid    = $wpdb->num_queries;
$b14_second = $ledger->liability( $today );
vcap_report_assert( $b14_first === $b14_second && $wpdb->num_queries - $b14_mid <= 3, 'B14: the liability of a day is served from the cache while the ledger is unchanged (' . ( $wpdb->num_queries - $b14_mid ) . ' queries)' );
vcap()->vouchers->log( $card->id, 'redeemed', 5, 25, 'Order test', 0 );
vcap_report_assert( 25.0 === (float) ( $ledger->liability( $today )['totals']['XTS']['giftcard']['amount'] ?? 0 ), 'B14: a new ledger row recalculates the liability' );
$b14_queries = array();
$b14_log     = static function ( $q ) use ( &$b14_queries ) {
	$b14_queries[] = $q;
	return $q;
};
add_filter( 'query', $b14_log );
iterator_to_array( $ledger->journal_all( $today, $today, 1 ), false );
remove_filter( 'query', $b14_log );
$b14_batches = preg_grep( '/^SELECT e\.id, e\.created_at/', $b14_queries );
vcap_report_assert( $b14_batches && count( preg_grep( '/e\.id > \d+ AND e\.id <= \d+ AND e\.created_at/', $b14_batches ) ) === count( $b14_batches ) && 1 === count( preg_grep( '/SELECT MIN\(id\) AS lo, MAX\(id\) AS hi/', $b14_queries ) ), 'B14: every journal batch is a primary-key range within the period bounds, read once' );

// CSV format: German Excel gets semicolons and a decimal comma; negative numbers are not mistaken for formulas.
$german = static fn() => array( 'separator' => ';', 'decimal' => ',' );
add_filter( 'vcap_csv_format', $german );
VCAP\Csv::reset();
vcap_report_assert( '12,50' === VCAP\Csv::number( 12.5, 2 ) && ';' === VCAP\Csv::format()['separator'], 'the CSV format follows the filter (semicolon, decimal comma)' );
remove_filter( 'vcap_csv_format', $german );
VCAP\Csv::reset();
vcap_report_assert( array( '-12,50', "'-1+1", "'=SUM(A1)" ) === VCAP\Csv::safe_row( array( '-12,50', '-1+1', '=SUM(A1)' ) ), 'negative numbers stay numbers, formulas are neutralised' );

// A voucher used as a payment after tax (fee line) counts in the report.
$voucher_group = 'voucher:' . __( 'Vouchers and gift cards', 'voucher-captain' );
$range         = array( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
$before        = ( new VCAP\Reports() )->collect( $range[0], $range[1] )[ $voucher_group ]['discount'] ?? 0.0;
$pay_coupon    = new WC_Coupon();
$pay_coupon->set_code( 'vcap-pay-' . strtolower( wp_generate_password( 8, false, false ) ) );
$pay_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$pay_coupon->save();
$paid = wc_create_order();
$fee  = new WC_Order_Item_Fee();
$fee->set_name( 'Voucher' );
$fee->set_total( '-15' );
$fee->add_meta_data( VCAP\PaymentVouchers::FEE_META, $pay_coupon->get_code(), true );
$paid->add_item( $fee );
$paid->set_total( '20' );
$paid->set_status( 'completed' );
$paid->save();
$after = ( new VCAP\Reports() )->collect( $range[0], $range[1] )[ $voucher_group ]['discount'] ?? 0.0;
vcap_report_assert( abs( 15.0 - ( $after - $before ) ) < 0.001, 'vouchers used as a payment after tax are reported with the amount paid' );

echo "Voucher Captain report checks passed.\n";
