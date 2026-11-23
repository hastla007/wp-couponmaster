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
// AP-P1-2: every export ends with "End of export: N rows"; the runtime cache is flushed after each batch and the
// order numbers of a batch come from one query.
$p12_flushes = VCAP\Ledger::$flushes;
$p12_journal = iterator_to_array( $ledger->journal_export( $today, $today, 1 ), false );
vcap_report_assert( VCAP\Ledger::journal_header() === $p12_journal[0] && array( sprintf( 'End of export: %d rows', count( $all ) ) ) === end( $p12_journal ) && count( $p12_journal ) === count( $all ) + 2, 'AP-P1-2: the journal CSV ends with "End of export: N rows" (N = bookings)', end( $p12_journal ) );
vcap_report_assert( VCAP\Ledger::$flushes - $p12_flushes >= count( $all ), 'AP-P1-2: the journal export flushes the runtime cache after every batch', VCAP\Ledger::$flushes - $p12_flushes );
$p12_liability = iterator_to_array( $ledger->liability_export( $today ), false );
vcap_report_assert( array( sprintf( 'End of export: %d rows', iterator_count( $ledger->liability_rows( $today ) ) ) ) === end( $p12_liability ) && VCAP\Ledger::liability_header() === $p12_liability[0], 'AP-P1-2: the liability CSV ends with "End of export: N rows"', end( $p12_liability ) );
$p12_counter = iterator_to_array( $ledger->counter_export( $today, $today, 'till' ), false );
vcap_report_assert( array( sprintf( 'End of export: %d rows', count( $ledger->counter_totals( $today, $today, 'till' ) ) ) ) === end( $p12_counter ), 'AP-P1-2: the counter CSV ends with "End of export: N rows"', end( $p12_counter ) );
$p12_orders = array();
for ( $i = 0; $i < 3; $i++ ) {
	$p12_order = wc_create_order();
	$p12_order->save();
	$p12_orders[] = $p12_order->get_id();
}
wp_cache_flush();
$p12_queries = 0;
VCAP\Format::prime_order_numbers( $p12_orders );
$p12_count = static function ( $q ) use ( &$p12_queries ) {
	++$p12_queries;
	return $q;
};
add_filter( 'query', $p12_count );
$p12_numbers = array_map( array( VCAP\Format::class, 'order_number' ), $p12_orders );
remove_filter( 'query', $p12_count );
vcap_report_assert( 0 === $p12_queries && array_map( 'strval', $p12_orders ) === $p12_numbers, 'AP-P1-2: the order numbers of an export batch are primed in one query (none per row)', array( $p12_queries, $p12_numbers ) );

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
// U6: journal amounts carry their direction; an admin correction is logged with its sign.
$u6_card = $ledger_new( 'balance', 40 );
vcap()->coupons->adjust_balance( $u6_card, -15, 'adjusted', 'U6 correction' );
$u6_rows = array_values( array_filter( $ledger->journal( $today, $today ), static fn( $r ) => (string) $r['code'] === $u6_card->code ) );
$u6_adj  = array_values( array_filter( $u6_rows, static fn( $r ) => 'adjusted' === $r['event'] ) );
vcap_report_assert( 1 === count( $u6_adj ) && -15.0 === (float) $u6_adj[0]['amount'] && 25.0 === (float) $u6_adj[0]['balance_after'], 'U6: lowering a balance by 15 is logged as -15 ("adjusted" with sign)' );
$u6_header = VCAP\Ledger::journal_header();
$u6_amount = array_search( __( 'Amount', 'voucher-captain' ), $u6_header, true );
$u6_redeem = VCAP\Ledger::journal_line( $journal[0] );
vcap_report_assert( VCAP\Csv::number( -20, 2 ) === $u6_redeem[ $u6_amount ] && VCAP\Csv::number( -15, 2 ) === VCAP\Ledger::journal_line( $u6_adj[0] )[ $u6_amount ], 'U6: in the journal CSV a redemption and a lowering correction are negative' );
$u6_sum = array_sum( array_map( static fn( $r ) => VCAP\Ledger::signed_amount( (string) $r['event'], $r['amount'] ), $u6_rows ) );
vcap_report_assert( abs( 25.0 - $u6_sum ) < 0.001, 'U6: the signed journal of a card sums to its remaining value (40 issued − 15 corrected)' );

// U5: order number (sequential numbers plugins) and order ID as two CSV columns; the journal page links the number.
$u5_order  = wc_create_order();
$u5_order->save();
$u5_number = static fn( $number, $order ) => 'R-2026-' . $order->get_id();
add_filter( 'woocommerce_order_number', $u5_number, 10, 2 );
vcap()->vouchers->log( $u6_card->id, 'redeemed', 5, 20, 'U5', $u5_order->get_id() );
$u5_row  = array_values( array_filter( $ledger->journal( $today, $today ), static fn( $r ) => (int) $r['order_id'] === $u5_order->get_id() ) );
$u5_line = $u5_row ? VCAP\Ledger::journal_line( $u5_row[0] ) : array();
$u5_num  = array_search( __( 'Order number', 'voucher-captain' ), $u6_header, true );
$u5_id   = array_search( __( 'Order ID', 'voucher-captain' ), $u6_header, true );
vcap_report_assert( false !== $u5_num && false !== $u5_id && 'R-2026-' . $u5_order->get_id() === ( $u5_line[ $u5_num ] ?? '' ) && $u5_order->get_id() === (int) ( $u5_line[ $u5_id ] ?? 0 ), 'U5: the journal CSV has the order number and the order ID in two columns' );
$u5_link = VCAP\Format::order_link( $u5_order->get_id() );
vcap_report_assert( str_contains( $u5_link, '#R-2026-' . $u5_order->get_id() . '</a>' ) && str_contains( $u5_link, '<a href=' ), 'U5: admin tables link the order number to the order' );
$u5_liab = VCAP\Ledger::liability_header();
vcap_report_assert( in_array( __( 'Order number', 'voucher-captain' ), $u5_liab, true ) && in_array( __( 'Order ID', 'voucher-captain' ), $u5_liab, true ) && count( $u5_liab ) === count( VCAP\Ledger::liability_line( array( 'code' => 'x', 'type' => 'voucher', 'currency' => 'XTS', 'issued' => null, 'expires' => '', 'order_id' => $u5_order->get_id(), 'outstanding' => 1, 'expired' => false ), $today ) ), 'U5: the liability CSV has order number and ID as well' );
remove_filter( 'woocommerce_order_number', $u5_number, 10 );
// T14: open holds (unpaid order, external reservation) are still owed: liability = balance + open holds.
$t14_new = static function ( string $mode, float $value, array $props = array() ) {
	$v                 = new VCAP\Voucher();
	$v->code           = 'ledger-t14-' . strtolower( wp_generate_password( 8, false, false ) );
	$v->order_item_id  = 94000000 + random_int( 1, 9999999 );
	$v->type           = $props['type'] ?? ( 'balance' === $mode ? 'giftcard' : 'voucher' );
	$v->usage_mode     = $mode;
	$v->discount_type  = $props['discount_type'] ?? 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = 'XTT';
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	vcap()->vouchers->log( $v->id, 'issued', $value, 'balance' === $mode ? $value : null, '' );
	return $v;
};
$t14_value = static function () use ( $ledger, $today ): array {
	$sql    = (float) ( $ledger->liability( $today )['totals']['XTT']['giftcard']['amount'] ?? 0 );
	$detail = array_sum( array_column( array_filter( iterator_to_array( $ledger->liability_rows( $today ), false ), static fn( $r ) => 'XTT' === $r['currency'] ), 'outstanding' ) );
	return array( $sql, round( $detail, 2 ) );
};
$t14_card = $t14_new( 'balance', 50 );
vcap()->vouchers->log( $t14_card->id, 'reserved', 20, 30, 'Reserved for order', 777001 );
vcap()->vouchers->log( $t14_card->id, 'api_reserved', 10, 20, 'Redemption x' );
vcap_report_assert( array( 50.0, 50.0 ) === $t14_value(), 'T14: a gift card with an order hold and an external reservation still owes its full 50 (SQL total and detail)' );
vcap()->vouchers->log( $t14_card->id, 'committed', 20, 20, '', 777001 );
vcap()->vouchers->log( $t14_card->id, 'api_released', 10, 30, 'Redemption x' );
vcap_report_assert( array( 30.0, 30.0 ) === $t14_value(), 'T14: once the order hold is charged and the external reservation released, 30 remain' );

// O10: percentage and reusable discount vouchers move no money: their uses never appear in the journal.
$o10_percent  = $t14_new( 'single', 10, array( 'discount_type' => 'percent' ) );
$o10_reusable = $t14_new( 'unlimited', 5 );
vcap()->vouchers->log( $o10_percent->id, 'redeemed', 10, 0, 'Counter' );
vcap()->vouchers->log( $o10_reusable->id, 'redeemed', 5, null, 'Counter' );
$o10_codes = array_column( iterator_to_array( $ledger->journal_all( $today, $today ), false ), 'code' );
vcap_report_assert( ! array_intersect( array( $o10_percent->code, $o10_reusable->code ), $o10_codes ) && in_array( $t14_card->code, $o10_codes, true ) && count( $o10_codes ) === $ledger->journal_count( $today, $today ), 'O10: a 10 % voucher and a reusable code are not booked as redeemed money in the journal (value vouchers are)' );

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
