<?php
/**
 * B4: bulk code checks, background issuing of large orders, background code generation and imports.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/bulk-integration.php
 */

use VCAP\CodeIndex;
use VCAP\Delivery;
use VCAP\Issuer;
use VCAP\Jobs;

function vcap_bulk_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' — ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

global $wpdb;
$tag      = strtolower( wp_generate_password( 6, false, false ) );
$repo     = vcap()->vouchers;
$pool     = vcap()->code_pool;
$tables   = VCAP\Install::tables();
$queries  = array();
$log      = static function ( $q ) use ( &$queries ) {
	$queries[] = $q;
	return $q;
};
$count_q  = static function ( string $pattern ) use ( &$queries ): int {
	return count( preg_grep( $pattern, $queries ) );
};
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
wp_set_current_user( $admin_id );

/* ---- 1. Code checks: own indexed tables first, WooCommerce last, batched, misses cached --------------------- */

foreach ( array( 'vouchers', 'pool' ) as $key ) {
	$explain = (array) $wpdb->get_row( "EXPLAIN SELECT code FROM {$tables[ $key ]} WHERE code IN ('B4-A', 'B4-B', 'B4-C')", ARRAY_A ); // phpcs:ignore
	vcap_bulk_assert( 'code' === ( $explain['key'] ?? '' ), "B4: the {$key} lookup by code uses the unique code index (no schema change needed)", $explain );
}

$pool_code = 'B4-POOL-' . strtoupper( $tag ) . 'K7QM2X9ZPA';
$pool->add( $pool_code, 'b4-own-' . $tag );
$wc_code = 'b4-wc-' . $tag . '-k7qm2x9zpa';
$coupon  = new WC_Coupon();
$coupon->set_code( $wc_code );
$coupon->set_amount( 5 );
$coupon->save();
CodeIndex::reset();

$queries = array();
add_filter( 'query', $log );
$in_pool = CodeIndex::taken( strtolower( $pool_code ) );
remove_filter( 'query', $log );
vcap_bulk_assert( $in_pool && 0 === $count_q( '/(LOWER\()?post_title\)? = /i' ), 'B4: a code of the code pool is found (case-insensitively) on the own index, WooCommerce is not asked', $queries );

$queries = array();
add_filter( 'query', $log );
$in_wc = CodeIndex::taken( strtoupper( $wc_code ) );
remove_filter( 'query', $log );
vcap_bulk_assert( $in_wc && $count_q( '/(LOWER\()?post_title\)? = /i' ) <= 1, 'B4: a WooCommerce coupon code is found through WooCommerce after the own tables', $queries );

$free    = 'B4-FREE-' . strtoupper( wp_generate_password( 12, false, false ) );
$queries = array();
add_filter( 'query', $log );
$first  = CodeIndex::taken( $free );
$second = CodeIndex::taken( $free );
remove_filter( 'query', $log );
vcap_bulk_assert( ! $first && ! $second && 1 === $count_q( '/(LOWER\()?post_title\)? = /i' ), 'B4: a WooCommerce miss is cached for the request (one lookup for two checks)', $queries );

$candidates = array( $wc_code, $pool_code, 'B4-C1-' . strtoupper( $tag ) . 'AAAAAAAAAA', 'B4-C2-' . strtoupper( $tag ) . 'BBBBBBBBBB', 'B4-C3-' . strtoupper( $tag ) . 'CCCCCCCCCC' );
CodeIndex::reset();
$queries = array();
add_filter( 'query', $log );
$claimed = CodeIndex::claim_first( $candidates );
remove_filter( 'query', $log );
$own_q = $count_q( '/' . preg_quote( $tables['vouchers'], '/' ) . ' WHERE code IN/' );
vcap_bulk_assert( $candidates[2] === $claimed && 1 === $own_q && $count_q( '/(LOWER\()?post_title\)? = /i' ) <= 2 && ! preg_grep( '/LOWER\(\x27B4-POOL/i', $queries ) && ! preg_grep( '/LOWER\(\x27B4-C2/i', $queries ), 'B4: five candidates are checked in one query on both own tables; WooCommerce only for the candidates left, up to the first free one', array( $claimed, $own_q, $queries ) );
vcap_bulk_assert( CodeIndex::taken( $candidates[2] ) && $candidates[3] === CodeIndex::claim_first( $candidates ), 'B4: a code handed out counts as taken for the rest of the request' );

CodeIndex::reset();
$queries = array();
add_filter( 'query', $log );
$many = CodeIndex::unique_many( array( 'prefix' => 'B4M', 'length' => 12 ), 600 );
remove_filter( 'query', $log );
vcap_bulk_assert( 600 === count( array_unique( array_map( 'strtolower', $many ) ) ), 'B4: 600 unique codes for a bulk run' );
vcap_bulk_assert( $count_q( '/(LOWER\()?post_title\)? = /i' ) <= 3 && $count_q( '/ WHERE code IN/' ) <= 3, 'B4: … checked in batches of up to 500 (' . count( $queries ) . ' queries, not one per code)', count( $queries ) );

$free_list = CodeIndex::claim_free( array( 7 => $pool_code, 8 => $wc_code, 9 => 'B4-F-' . strtoupper( $tag ) . 'DDDDDDDDDD', 10 => 'b4-f-' . $tag . 'dddddddddd' ) );
vcap_bulk_assert( array( 9 => 'B4-F-' . strtoupper( $tag ) . 'DDDDDDDDDD' ) === $free_list, 'B4: claim_free() keeps the keys and drops codes in use and case variants of the same code', $free_list );

/* ---- 2. Large orders are issued in the background -------------------------------------------------------- */

$product = new WC_Product_Simple();
$product->set_name( 'B4 voucher ' . $tag );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_type', 'voucher' );
$product->save();
$make_order = static function ( int $qty ) use ( $product, $tag ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_billing_email( "b4-{$tag}@example.com" );
	$order->set_payment_method( 'stripe' );
	$order->calculate_totals( false );
	$order->save();
	return $order;
};
$args_of   = static fn( WC_Order $o ) => array( 'order_id' => $o->get_id() );
$issued_ev = array();
$listen    = static function ( $created, $order ) use ( &$issued_ev ) {
	$issued_ev[] = array( $order->get_id(), count( $created ) );
};
add_action( 'vcap_vouchers_issued', $listen, 10, 2 );
$notes_of = static fn( WC_Order $o ) => implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) ) );
$delivery_planned = static function ( array $vouchers ): int {
	return count( array_filter( $vouchers, static fn( $v ) => false !== as_next_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $v->id ), Delivery::GROUP ) ) );
};

$small = $make_order( 3 );
$small->payment_complete( 'b4-small' );
vcap_bulk_assert( 3 === count( $repo->for_order( $small->get_id() ) ) && '' === $small->get_meta( Issuer::ASYNC_META ), 'B4: an order with 3 vouchers is issued right away, as before' );

$big = $make_order( 30 );
$queries = array();
add_filter( 'query', $log );
$big->payment_complete( 'b4-big' );
remove_filter( 'query', $log );
$big = wc_get_order( $big->get_id() );
vcap_bulk_assert( 0 === count( $repo->for_order( $big->get_id() ) ) && is_array( $big->get_meta( Issuer::ASYNC_META ) ) && 30 === (int) $big->get_meta( Issuer::ASYNC_META )['total'], 'B4: an order with 30 vouchers (threshold 20) issues none in the payment request', $big->get_meta( Issuer::ASYNC_META ) );
vcap_bulk_assert( 0 === $count_q( '/^\s*INSERT INTO `?' . preg_quote( $tables['vouchers'], '/' ) . '/' ), 'B4: the payment request inserts no voucher (' . count( $queries ) . ' queries in total)' );
vcap_bulk_assert( str_contains( $notes_of( $big ), '30 vouchers are being issued in the background' ), 'B4: the order notes that the vouchers are being issued' );
vcap_bulk_assert( false !== as_next_scheduled_action( Issuer::ISSUE_RETRY_HOOK, $args_of( $big ), Delivery::GROUP ), 'B4: the first batch is queued in Action Scheduler (hook vcap_issue_order)' );

vcap()->issuer->issue_async( $args_of( $big ) );
$big   = wc_get_order( $big->get_id() );
$first = $repo->for_order( $big->get_id() );
vcap_bulk_assert( Issuer::BATCH_SIZE === count( $first ) && is_array( $big->get_meta( Issuer::ASYNC_META ) ), 'B4: the first batch issues ' . Issuer::BATCH_SIZE . ' vouchers', count( $first ) );
vcap_bulk_assert( 0 === $delivery_planned( $first ) && ! in_array( $big->get_id(), array_column( $issued_ev, 0 ), true ), 'B4: delivery waits until every voucher of the order exists (no delivery, no vcap_vouchers_issued yet)' );
$next = as_next_scheduled_action( Issuer::ISSUE_RETRY_HOOK, $args_of( $big ), Delivery::GROUP );
vcap_bulk_assert( is_int( $next ) && $next <= time() + 5, 'B4: the next batch is queued right away', $next );

vcap()->issuer->issue_for_order( $big ); // A status hook meanwhile: no voucher issued outside the batches.
vcap_bulk_assert( Issuer::BATCH_SIZE === count( $repo->for_order( $big->get_id() ) ) && vcap()->issuer->last_deferred, 'B4: an issuing run outside the batches leaves the work to them' );

vcap()->issuer->issue_async( $args_of( $big ) );
$big = wc_get_order( $big->get_id() );
$all = $repo->for_order( $big->get_id() );
vcap_bulk_assert( 30 === count( $all ) && 30 === count( array_unique( array_map( static fn( $v ) => $v->item_index, $all ) ) ) && '' === $big->get_meta( Issuer::ASYNC_META ), 'B4: the second batch issues the rest – 30 vouchers, one per item slot, the background state is cleared' );
vcap_bulk_assert( 30 === $delivery_planned( $all ) && array( array( $big->get_id(), 30 ) ) === array_values( array_filter( $issued_ev, static fn( $e ) => $e[0] === $big->get_id() ) ), 'B4: then all 30 are delivered together and vcap_vouchers_issued fires once with all of them', $issued_ev );
vcap_bulk_assert( str_contains( $notes_of( $big ), 'All vouchers of this order have been issued (30 in the background)' ) && false === as_next_scheduled_action( Issuer::ISSUE_RETRY_HOOK, $args_of( $big ), Delivery::GROUP ), 'B4: the order notes the end, no further batch is queued' );
vcap()->issuer->issue_async( $args_of( $big ) );
vcap_bulk_assert( 30 === count( $repo->for_order( $big->get_id() ) ), 'B4: a repeated batch is idempotent (one voucher per slot)' );

$cancelled = $make_order( 25 );
$cancelled->payment_complete( 'b4-cancel' );
$cancelled = wc_get_order( $cancelled->get_id() );
$cancelled->update_status( 'cancelled' );
vcap()->issuer->issue_async( $args_of( $cancelled ) );
$cancelled = wc_get_order( $cancelled->get_id() );
vcap_bulk_assert( 0 === count( $repo->for_order( $cancelled->get_id() ) ) && '' === $cancelled->get_meta( Issuer::ASYNC_META ), 'B4: an order cancelled before its batches ran gets no vouchers' );

$cli = $make_order( 22 );
add_filter( 'vcap_should_issue_for_order', '__return_false' );
$cli->payment_complete( 'b4-cli' );
remove_filter( 'vcap_should_issue_for_order', '__return_false' );
$cli     = wc_get_order( $cli->get_id() );
$created = vcap()->issuer->issue_for_order( $cli, true, false );
vcap_bulk_assert( 22 === count( $created ) && ! vcap()->issuer->last_deferred, 'B4: WP-CLI ("wp vcap issue") issues everything at once' );

remove_action( 'vcap_vouchers_issued', $listen, 10 );
foreach ( array( $small, $big, $cli ) as $o ) {
	foreach ( $repo->for_order( $o->get_id() ) as $v ) {
		as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $v->id ), Delivery::GROUP );
	}
	as_unschedule_all_actions( Issuer::ISSUE_RETRY_HOOK, $args_of( $o ), Delivery::GROUP );
}
as_unschedule_all_actions( Issuer::ISSUE_RETRY_HOOK, $args_of( $cancelled ), Delivery::GROUP );

/* ---- 3. Many campaign / pool codes are generated in background steps ------------------------------------- */

$campaigns = new VCAP\Campaigns( $pool, $repo );
$job_id    = Jobs::id( VCAP\Campaigns::JOB, $admin_id );
Jobs::finish( $job_id );
$batch  = 'B4 pool ' . $tag;
$queued = $campaigns->queue( 'pool', 0, $batch, 250, array( 'length' => 12 ), $admin_id );
vcap_bulk_assert( true === $queued && Jobs::running( Jobs::get( $job_id ) ) && false !== as_next_scheduled_action( Jobs::HOOK, array( 'id' => $job_id ), Delivery::GROUP ), 'B4: 250 pool codes are queued as a background job' );
vcap_bulk_assert( is_wp_error( $campaigns->queue( 'pool', 0, $batch, 10, array(), $admin_id ) ), 'B4: a second run waits until the first one is finished' );
$one_step = static fn() => 0;
add_filter( 'vcap_job_step_seconds', $one_step );
Jobs::run( array( 'id' => $job_id ) );
remove_filter( 'vcap_job_step_seconds', $one_step );
$job = Jobs::get( $job_id );
vcap_bulk_assert( VCAP\Campaigns::STEP === (int) $job['done'] && Jobs::running( $job ) && 40 === Jobs::percent( $job ) && false !== as_next_scheduled_action( Jobs::HOOK, array( 'id' => $job_id ), Delivery::GROUP ), 'B4: one Action Scheduler step makes 100 codes and queues the next (40 %)', $job );
for ( $i = 0; $i < 5 && Jobs::running( Jobs::get( $job_id ) ); $i++ ) {
	Jobs::advance( $job_id, 30 );
}
$job   = Jobs::get( $job_id );
$found = array_values( array_filter( $pool->batches(), static fn( $b ) => $b['batch'] === $batch ) );
vcap_bulk_assert( 'done' === $job['status'] && 250 === (int) $job['done'] && $found && 250 === $found[0]['total'], 'B4: the job ends with exactly 250 codes in the batch', array( $job, $found ) );
list( $shown, $running ) = VCAP\Admin\JobProgress::load( VCAP\Campaigns::JOB );
vcap_bulk_assert( is_array( $shown ) && ! $running && 'done' === $shown['status'] && null === Jobs::get( $job_id ), 'B4: the result screen gets the finished job once, then it is removed' );

$template = new WC_Coupon();
$template->set_code( 'b4-tpl-' . $tag );
$template->set_discount_type( 'percent' );
$template->set_amount( 10 );
$template->save();
$campaign = 'B4 campaign ' . $tag;
vcap_bulk_assert( true === $campaigns->queue( 'coupons', $template->get_id(), $campaign, 130, array( 'prefix' => 'B4C' ), $admin_id ), 'B4: 130 campaign coupons are queued' );
for ( $i = 0; $i < 5 && Jobs::running( Jobs::get( $job_id ) ); $i++ ) {
	Jobs::advance( $job_id, 30 );
}
$totals = array_column( $campaigns->campaigns(), 'total', 'campaign' );
vcap_bulk_assert( 'done' === Jobs::get( $job_id )['status'] && 130 === (int) ( $totals[ $campaign ] ?? 0 ), 'B4: … and all 130 coupons exist when the job is done', $totals[ $campaign ] ?? null );
Jobs::finish( $job_id );

vcap_bulk_assert( true === $campaigns->queue( 'coupons', $template->get_id(), $campaign . ' gone', 150, array(), $admin_id ), 'B4: a run whose template is deleted meanwhile …' );
wp_delete_post( $template->get_id(), true );
Jobs::advance( $job_id, 30 );
$job = Jobs::get( $job_id );
vcap_bulk_assert( 'failed' === $job['status'] && '' !== $job['error'], '… stops with an error instead of running forever', $job );
Jobs::finish( $job_id );

/* ---- 4. Large imports run in background chunks; error lists are capped ---------------------------------- */

$importer = new VCAP\Admin\Import( $pool );
$imp_id   = Jobs::id( VCAP\CodePool::IMPORT_JOB, $admin_id );
Jobs::finish( $imp_id );
$rows = array( array( 'Code' ) );
for ( $i = 0; $i < 1200; $i++ ) {
	$rows[] = array( 'B4I-' . strtoupper( wp_generate_password( 14, false, false ) ) );
}
for ( $i = 0; $i < 250; $i++ ) {
	$rows[] = array( 'bad code ' . $i ); // Spaces: not the standard format.
}
$rows[]       = array( $pool_code ); // Already in the pool.
$rows[]       = array( strtoupper( $wc_code ) ); // A WooCommerce coupon.
$imp_batch    = 'B4 import ' . $tag;
list( $report, $codes ) = $importer->prepare( $rows, array( 'batch' => $imp_batch, 'column' => 'Code', 'header' => true, 'format' => 'standard' ) );
vcap_bulk_assert( 1202 === count( $codes ) && Jobs::MAX_ERRORS === count( $report['invalid'] ) && 250 === Jobs::count( $report, 'invalid' ), 'B4: 250 invalid rows are counted, at most 100 are listed', array( count( $report['invalid'] ), Jobs::count( $report, 'invalid' ) ) );
$report = $importer->import_or_queue( $report, $codes, $admin_id );
vcap_bulk_assert( ! empty( $report['queued'] ) && 3 === (int) Jobs::get( $imp_id )['chunks'] && 0 === $report['imported'], 'B4: 1202 codes are imported in the background, in 3 chunks of up to 500' );
vcap_bulk_assert( 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_job_' . $imp_id . '_c' ) . '%' ) ), 'B4: the chunks wait in separate options (no option grows with the file)' ); // phpcs:ignore

// The second chunk fails to save 150 codes: reported as "not saved", listed up to 100.
$fail_left = 150;
$fail      = static function ( $q ) use ( &$fail_left, $tables ) {
	if ( $fail_left > 0 && str_starts_with( ltrim( $q ), 'INSERT INTO `' . $tables['pool'] ) && str_contains( $q, 'B4I-' ) ) {
		--$fail_left;
		return 'INSERT INTO vcap_no_such_table_b4 (x) VALUES (1)';
	}
	return $q;
};
CodeIndex::reset();
$queries = array();
add_filter( 'query', $log );
$wpdb->suppress_errors( true );
Jobs::advance( $imp_id, 0 ); // First chunk: 500 codes.
remove_filter( 'query', $log );
vcap_bulk_assert( 1 === (int) Jobs::get( $imp_id )['next'] && $count_q( '/(LOWER\()?post_title\)? = /i' ) <= 1 && $count_q( '/ WHERE code IN/' ) <= 1, 'B4: a chunk of 500 codes is checked with one query on the own tables and one on WooCommerce', array( $count_q( '/(LOWER\()?post_title\)? = /i' ), $count_q( '/ WHERE code IN/' ) ) );
add_filter( 'query', $fail );
Jobs::advance( $imp_id, 0 );
remove_filter( 'query', $fail );
for ( $i = 0; $i < 5 && Jobs::running( Jobs::get( $imp_id ) ); $i++ ) {
	Jobs::advance( $imp_id, 30 );
}
$wpdb->suppress_errors( false );
$job    = Jobs::get( $imp_id );
$report = (array) $job['report'];
vcap_bulk_assert( 'done' === $job['status'] && 1050 === (int) $report['imported'] && 2 === (int) $report['dupes_existing'], 'B4: the background import adds 1050 codes and skips the 2 codes in use', $report['imported'] . '/' . $report['dupes_existing'] );
vcap_bulk_assert( 150 === Jobs::count( $report, 'not_saved' ) && Jobs::MAX_ERRORS === count( $report['not_saved'] ) && str_contains( $report['not_saved'][0], 'vcap_no_such_table_b4' ), 'B4: 150 codes the database refused are counted, 100 listed with the reason' );
vcap_bulk_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'vcap_job_' . $imp_id . '_c' ) . '%' ) ) && strlen( (string) maybe_serialize( $job ) ) < 64 * KB_IN_BYTES, 'B4: the chunks are removed, the stored report stays small', strlen( (string) maybe_serialize( $job ) ) ); // phpcs:ignore
Jobs::finish( $imp_id );

$small_rows = array( array( 'B4S-' . strtoupper( wp_generate_password( 14, false, false ) ) ), array( 'B4S-' . strtoupper( wp_generate_password( 14, false, false ) ) ) );
list( $report, $codes ) = $importer->prepare( $small_rows, array( 'batch' => $imp_batch, 'column' => 'A', 'header' => false, 'format' => 'standard' ) );
$report = $importer->import_or_queue( $report, $codes, $admin_id );
vcap_bulk_assert( empty( $report['queued'] ) && 2 === $report['imported'] && null === Jobs::get( $imp_id ), 'B4: a small file is still imported right away' );

echo "Voucher Captain bulk checks passed.\n";
