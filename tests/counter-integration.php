<?php
/**
 * R6 "Locations & staff" (free core): till/branch and receipt as ledger columns, the counter report per till and
 * staff member, daily counter limits, and the add-on extension points (vcap_voucher_event arguments, standalone
 * issuing, vcap_voucher_links). Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/counter-integration.php
 */

use VCAP\Counter;
use VCAP\Install;
use VCAP\Ledger;
use VCAP\Voucher;

function vcap_ctr_assert( bool $condition, string $message, $context = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null !== $context ? ' — ' . wp_json_encode( $context ) : '' ) );
	}
	echo "PASS: {$message}\n";
}

/** A voucher with its coupon (as issued), not linked to an order. */
function vcap_ctr_voucher( string $mode = 'balance', float $value = 100.0 ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-ctr-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$voucher                 = new Voucher();
	$voucher->code           = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 81000000 + $coupon->get_id();
	$voucher->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
	$voucher->usage_mode     = $mode;
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $value;
	$voucher->balance        = $value;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->status         = Voucher::STATUS_ACTIVE;
	$voucher->send_to        = 'buyer';
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return $voucher;
}

function vcap_ctr_user( string $role ): int {
	$login = 'vcap_ctr_' . $role . '_' . strtolower( wp_generate_password( 6, false, false ) );
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => $role ) );
}

/** Counter redemption through the scanner endpoint. */
function vcap_ctr_scan( Voucher $voucher, array $params = array() ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
	$req->set_param( 'q', $voucher->code );
	$req->set_param( 'idempotency_key', wp_generate_uuid4() );
	foreach ( $params as $name => $value ) {
		$req->set_param( $name, $value );
	}
	$res = VCAP\Source::with( VCAP\Source::SCANNER, static fn() => vcap()->tickets->rest_redeem( $req ) );
	return is_wp_error( $res ) ? $res : $res->get_data();
}

global $wpdb;
$events  = Install::tables()['events'];
$today   = wp_date( 'Y-m-d' );
$tag     = strtolower( wp_generate_password( 6, false, false ) );
$till_a  = 'Shop A ' . $tag;
$till_b  = 'Shop B ' . $tag;
foreach ( array( Counter::USER_AMOUNT, Counter::USER_COUNT, Counter::TILL_AMOUNT, Counter::TILL_COUNT ) as $opt ) {
	delete_option( $opt );
}
vcap_ctr_assert( Install::event_till_ready(), 'schema 1.8.0: the ledger has the columns till and receipt' );

// 1. Till and receipt are stored structured, shown in the journal and counted per till and staff member.
$cashier = vcap_ctr_user( Install::CASHIER_ROLE );
wp_set_current_user( $cashier );
$card = vcap_ctr_voucher( 'balance', 100 );
$res  = vcap_ctr_scan( $card, array( 'amount' => 30, 'till' => $till_a, 'receipt' => 'R-1' ) );
$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE voucher_id = %d AND event = 'redeemed' ORDER BY id DESC LIMIT 1", $card->id ), ARRAY_A ); // phpcs:ignore
vcap_ctr_assert( ! empty( $res['success'] ) && $till_a === $row['till'] && 'R-1' === $row['receipt'] && (int) $row['user_id'] === $cashier && 'scanner' === $row['source'], 'a counter redemption stores till/branch and receipt in their own ledger columns', $row );
vcap_ctr_assert( str_contains( (string) $row['note'], $till_a ), 'the note keeps the till for the history' );
$single = vcap_ctr_voucher( 'single', 25 );
$res2   = vcap_ctr_scan( $single, array( 'till' => $till_b ) );
vcap_ctr_assert( ! empty( $res2['success'] ), 'a single-use voucher is redeemed at till B' );
$journal = ( new Ledger( vcap()->vouchers ) )->journal( $today, $today );
$jrow    = current( array_filter( $journal, static fn( $r ) => (int) $r['id'] === (int) $row['id'] ) );
vcap_ctr_assert( $jrow && $till_a === $jrow['till'] && 'R-1' === $jrow['receipt'], 'the redemption journal lists till/branch and receipt', $jrow );
$line = Ledger::journal_line( $jrow );
vcap_ctr_assert( in_array( $till_a, $line, true ) && in_array( 'R-1', $line, true ), 'the journal CSV line carries till and receipt' );
// Reversal: counts at the till of the redemption it cancels.
$reversed = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $card->id ), (int) $row['id'], 'wrong card' );
$rev_row  = $wpdb->get_row( $wpdb->prepare( "SELECT till, receipt FROM {$events} WHERE voucher_id = %d AND event = 'redeem_reversed' ORDER BY id DESC LIMIT 1", $card->id ), ARRAY_A ); // phpcs:ignore
vcap_ctr_assert( 30.0 === (float) $reversed && $till_a === $rev_row['till'] && 'R-1' === $rev_row['receipt'], 'a cancellation is booked at the till of the redemption it cancels', $rev_row );
vcap_ctr_scan( $card, array( 'amount' => 12.5, 'till' => $till_a ) );
$by_till = array();
foreach ( ( new Ledger( vcap()->vouchers ) )->counter_totals( $today, $today, 'till' ) as $t ) {
	$by_till[ $t['till'] ] = $t;
}
vcap_ctr_assert( isset( $by_till[ $till_a ], $by_till[ $till_b ] ) && 2 === $by_till[ $till_a ]['count'] && 42.5 === $by_till[ $till_a ]['amount'] && 1 === $by_till[ $till_a ]['reversed_count'] && 30.0 === $by_till[ $till_a ]['reversed_amount'] && 12.5 === $by_till[ $till_a ]['net'] && 1 === $by_till[ $till_b ]['count'] && 25.0 === $by_till[ $till_b ]['amount'], 'the till report counts redemptions, amounts and cancellations per till and currency', $by_till[ $till_a ] ?? null );
$both = array_values( array_filter( ( new Ledger( vcap()->vouchers ) )->counter_totals( $today, $today, 'both' ), static fn( $t ) => $till_b === $t['till'] ) );
vcap_ctr_assert( 1 === count( $both ) && $cashier === $both[0]['user_id'] && Ledger::staff_name( $cashier ) === Ledger::counter_line( $both[0], 'both' )[1], 'grouped by till and staff member, the report names the cashier', $both );
$older = ( new Ledger( vcap()->vouchers ) )->counter_totals( '2001-01-01', '2001-01-02', 'till' );
vcap_ctr_assert( array() === $older, 'a period without counter redemptions is empty' );

// 2. Rows booked before 1.8.0 get till and receipt from their note (migration).
$legacy = vcap_ctr_voucher( 'balance', 50 );
vcap()->vouchers->log( $legacy->id, 'redeemed', 10, 40, VCAP\Tickets::counter_note( '', 'OLD-7', 'Legacy till' ) );
$legacy_id = (int) $wpdb->insert_id;
vcap()->vouchers->log( $legacy->id, 'redeem_reversed', 10, 50, sprintf( 'Cancels redemption #%d: test', $legacy_id ) );
$legacy_rev = (int) $wpdb->insert_id;
vcap()->vouchers->log( $legacy->id, 'redeemed', 5, 45, 'Im Laden eingelöst · Beleg 99 · Kasse Graz' );
$german_id = (int) $wpdb->insert_id;
update_option( Install::EVENT_TILL_CURSOR, $legacy_id - 1, false );
vcap_ctr_assert( Install::fill_event_tills( 5.0 ), 'the till migration finishes' );
$filled = $wpdb->get_results( $wpdb->prepare( "SELECT id, till, receipt FROM {$events} WHERE id IN (%d, %d, %d) ORDER BY id", $legacy_id, $legacy_rev, $german_id ), ARRAY_A ); // phpcs:ignore
vcap_ctr_assert( 'Legacy till' === $filled[0]['till'] && 'OLD-7' === $filled[0]['receipt'] && 'Legacy till' === $filled[1]['till'] && 'Graz' === $filled[2]['till'] && '99' === $filled[2]['receipt'], 'existing counter redemptions (English and German notes) and their cancellations get till and receipt from the note', $filled );
vcap_ctr_assert( (int) get_option( Install::EVENT_TILL_CURSOR ) >= $german_id, 'the migration keeps its cursor' );

// 3. Daily limits per staff member and per till.
update_option( Counter::USER_COUNT, 1 );
$c2 = vcap_ctr_user( Install::CASHIER_ROLE );
wp_set_current_user( $c2 );
$v1 = vcap_ctr_voucher( 'balance', 100 );
$ok = vcap_ctr_scan( $v1, array( 'amount' => 10, 'till' => $till_b ) );
$no = vcap_ctr_scan( $v1, array( 'amount' => 10, 'till' => $till_b ) );
vcap_ctr_assert( ! empty( $ok['success'] ) && empty( $no['success'] ) && 'daily_limit' === $no['reason'] && 'user' === $no['limit']['scope'] && str_contains( $no['message'], 'your daily limit is 1 counter redemptions' ) && str_contains( $no['message'], 'left: 0' ) && 90.0 === (float) vcap()->vouchers->get( $v1->id )->balance, 'the count limit per staff member refuses the second redemption, naming the limit and what is left (nothing booked)', $no );
delete_option( Counter::USER_COUNT );
update_option( Counter::USER_AMOUNT, 50 );
$amt_ok = vcap_ctr_scan( $v1, array( 'amount' => 40, 'till' => $till_b ) );
$amt_no = vcap_ctr_scan( $v1, array( 'amount' => 0.01, 'till' => $till_b ) );
vcap_ctr_assert( ! empty( $amt_ok['success'] ) && empty( $amt_no['success'] ) && 'amount' === $amt_no['limit']['kind'] && 0.0 === (float) $amt_no['limit']['left'] && 50.0 === (float) $amt_no['limit']['used'], 'the amount limit counts the day\'s redemptions of the staff member (10 + 40 = 50, one cent more is refused)', $amt_no );
$single_no = vcap_ctr_scan( vcap_ctr_voucher( 'single', 5 ), array( 'till' => $till_b ) );
vcap_ctr_assert( empty( $single_no['success'] ) && 'daily_limit' === $single_no['reason'], 'a single-use value voucher counts with its value' );
update_user_option( $c2, Counter::META_AMOUNT, '200' );
$own = vcap_ctr_scan( $v1, array( 'amount' => 20, 'till' => $till_b ) );
vcap_ctr_assert( ! empty( $own['success'] ), 'an own limit on the user profile replaces the setting' );
update_user_option( $c2, Counter::META_AMOUNT, '0' );
vcap_ctr_assert( ! empty( vcap_ctr_scan( $v1, array( 'amount' => 1, 'till' => $till_b ) )['success'] ), 'an own limit of 0 means unlimited' );
delete_user_option( $c2, Counter::META_AMOUNT );
delete_option( Counter::USER_AMOUNT );

update_option( Counter::TILL_COUNT, 1 );
$tc_user = vcap_ctr_user( Install::CASHIER_ROLE );
wp_set_current_user( $tc_user );
$till_c  = 'Shop C ' . $tag;
$v2      = vcap_ctr_voucher( 'balance', 100 );
$first   = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => $till_c ) );
wp_set_current_user( vcap_ctr_user( Install::CASHIER_ROLE ) );
$other   = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => $till_c ) );
$else    = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => 'Shop D ' . $tag ) );
vcap_ctr_assert( ! empty( $first['success'] ) && empty( $other['success'] ) && 'till' === $other['limit']['scope'] && str_contains( $other['message'], $till_c ) && ! empty( $else['success'] ), 'the till limit counts every staff member at that till, not other tills', $other );

// Managers are not limited unless a filter says so.
$manager = vcap_ctr_user( 'shop_manager' );
wp_set_current_user( $manager );
$mgr_ok = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => $till_c ) );
add_filter( 'vcap_counter_limit_applies', '__return_true' );
$mgr_no = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => $till_c ) );
remove_filter( 'vcap_counter_limit_applies', '__return_true' );
vcap_ctr_assert( ! empty( $mgr_ok['success'] ) && empty( $mgr_no['success'] ), 'staff who manage vouchers are not limited, unless vcap_counter_limit_applies says so' );

// Parallel scans: while another connection holds the till's limit lock, the scan waits and is refused untouched.
$other_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock     = Install::lock_name( VCAP\Counter::lock_key( 'till', strtoupper( $till_c ) . ' ' ) ); // AP-S3-5: another spelling of the same till;
$other_db->get_var( $other_db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
wp_set_current_user( $tc_user );
$before  = (float) vcap()->vouchers->get( $v2->id )->balance;
$blocked = vcap_ctr_scan( $v2, array( 'amount' => 1, 'till' => $till_c ) );
$other_db->get_var( $other_db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
vcap_ctr_assert( empty( $blocked['success'] ) && 'reserved' === $blocked['reason'] && $before === (float) vcap()->vouchers->get( $v2->id )->balance, 'a scan never checks the limit while another booking of the same till holds its lock (atomic under parallel scans)', $blocked );
vcap_ctr_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ), 'the limit lock is released after the booking' );
delete_option( Counter::TILL_COUNT );

// 4. vcap_voucher_event: source and acting user as trailing arguments; old listeners keep working.
$seen_new = null;
$seen_old = null;
$new_cb   = static function ( $id, $event, $amount, $balance, $note, $order, $source, $user ) use ( &$seen_new ) {
	$seen_new = array( $event, $source, $user );
};
$old_cb   = static function ( $id, $event ) use ( &$seen_old ) {
	$seen_old = $event;
};
add_action( 'vcap_voucher_event', $new_cb, 10, 8 );
add_action( 'vcap_voucher_event', $old_cb, 10, 2 );
wp_set_current_user( $cashier );
vcap_ctr_scan( vcap_ctr_voucher( 'single', 5 ), array( 'till' => $till_a ) );
remove_action( 'vcap_voucher_event', $new_cb, 10 );
remove_action( 'vcap_voucher_event', $old_cb, 10 );
vcap_ctr_assert( array( 'redeemed', 'scanner', $cashier ) === $seen_new && 'redeemed' === $seen_old, 'vcap_voucher_event passes the source and the acting user id; a listener with two arguments still works', $seen_new );
$redeemed_data = null;
$redeemed_cb   = static function ( $data ) use ( &$redeemed_data ) {
	$redeemed_data = $data;
};
add_action( 'vcap_voucher_redeemed', $redeemed_cb );
vcap_ctr_scan( vcap_ctr_voucher( 'single', 5 ), array( 'till' => $till_a, 'receipt' => 'R-9' ) );
remove_action( 'vcap_voucher_redeemed', $redeemed_cb );
vcap_ctr_assert( $till_a === ( $redeemed_data['till'] ?? '' ) && 'R-9' === ( $redeemed_data['receipt'] ?? '' ), 'vcap_voucher_redeemed carries till and receipt of a counter redemption' );

// 5. Standalone issuing (no order).
wp_set_current_user( 1 );
$mails = array();
$catch = static function ( $short, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $catch, 999, 2 );
$links = static fn( $list, $voucher, $context ) => array_merge( $list, array( array( 'label' => 'Add to Wallet', 'url' => 'https://wallet.example/pass/' . $voucher->id . '?c=' . $context ) ) );
add_filter( 'vcap_voucher_links', $links, 10, 3 );
$ref    = 'company-' . $tag . '/1';
$issued = vcap()->issuer->issue_standalone(
	array(
		'amount'          => 75,
		'type'            => 'giftcard',
		'usage'           => 'balance',
		'recipient_name'  => 'Erika Muster',
		'recipient_email' => 'erika-' . $tag . '@example.com',
		'buyer_name'      => 'ACME GmbH',
		'buyer_email'     => 'hr-' . $tag . '@example.com',
		'source'          => 'company-order',
		'reference'       => $ref,
		'expiry_days'     => 30,
	)
);
vcap_ctr_assert( $issued instanceof Voucher && $issued->id > 0 && 0 === $issued->order_id && 75.0 === $issued->balance && 'recipient' === $issued->send_to && $issued->expires_at, 'issue_standalone() creates a voucher without an order', is_wp_error( $issued ) ? $issued->get_error_message() : null );
$coupon = new WC_Coupon( $issued->coupon_id );
vcap_ctr_assert( $coupon->get_id() && 75.0 === (float) $coupon->get_amount() && 0 === strcasecmp( $coupon->get_code(), $issued->code ) && 'publish' === get_post_status( $issued->coupon_id ), 'its WooCommerce coupon is created with the value' );
$issued_event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE voucher_id = %d AND event = 'issued'", $issued->id ), ARRAY_A ); // phpcs:ignore
vcap_ctr_assert( $issued_event && 75.0 === (float) $issued_event['amount'] && str_contains( VCAP\Note::render( (string) $issued_event['note'] ), $ref ), 'the ledger has its "issued" entry with source and reference', $issued_event );
vcap()->delivery->deliver( $issued->id );
$sent = array_values( array_filter( $mails, static fn( $m ) => in_array( 'erika-' . $tag . '@example.com', (array) $m['to'], true ) || ( is_string( $m['to'] ) && str_contains( $m['to'], 'erika-' . $tag ) ) ) );
vcap_ctr_assert( 1 === count( $sent ) && null !== vcap()->vouchers->get( $issued->id )->delivered_at, 'the voucher is mailed to the recipient', count( $mails ) );
vcap_ctr_assert( str_contains( (string) $sent[0]['message'], 'https://wallet.example/pass/' . $issued->id . '?c=email' ) && str_contains( (string) $sent[0]['message'], 'Add to Wallet' ), 'vcap_voucher_links adds its link to the voucher email' );
$replay = vcap()->issuer->issue_standalone( array( 'amount' => 75, 'recipient_email' => 'erika-' . $tag . '@example.com', 'source' => 'company-order', 'reference' => $ref ) );
vcap_ctr_assert( is_wp_error( $replay ) && 'vcap_reference_used' === $replay->get_error_code() && $issued->id === (int) ( $replay->get_error_data()['voucher_id'] ?? 0 ), 'a second call with the same reference issues nothing and names the voucher' );
// AP-P1-3: a new reference is decided by its claim – no query reads the "issued" notes of every voucher.
$p13_queries = array();
$p13_log     = static function ( $q ) use ( &$p13_queries ) {
	$p13_queries[] = $q;
	return $q;
};
add_filter( 'query', $p13_log );
$p13_new = vcap()->issuer->issue_standalone( array( 'amount' => 15, 'send' => false, 'source' => 'company-order', 'reference' => $ref . '-2' ) );
remove_filter( 'query', $p13_log );
$p13_scans = preg_grep( '/SELECT voucher_id FROM \S+ WHERE .*note = /s', $p13_queries );
vcap_ctr_assert( $p13_new instanceof Voucher && ! $p13_scans, 'AP-P1-3: issuing with a new reference reads no ledger notes (the claim decides)', array_values( $p13_scans ) );
$p13_queries = array();
add_filter( 'query', $p13_log );
$p13_replay = vcap()->issuer->issue_standalone( array( 'amount' => 15, 'send' => false, 'source' => 'company-order', 'reference' => $ref . '-2' ) );
remove_filter( 'query', $p13_log );
$p13_scans = array_values( preg_grep( '/SELECT voucher_id FROM \S+ WHERE .*note = /s', $p13_queries ) );
vcap_ctr_assert( is_wp_error( $p13_replay ) && $p13_new->id === (int) ( $p13_replay->get_error_data()['voucher_id'] ?? 0 ) && 1 === count( $p13_scans ) && str_contains( $p13_scans[0], 'created_at >=' ), 'AP-P1-3: a used reference finds its voucher from the time of its claim on (created_at index)', $p13_scans );
$quiet = vcap()->issuer->issue_standalone( array( 'amount' => 20, 'send' => false ) );
vcap_ctr_assert( $quiet instanceof Voucher && null !== $quiet->delivered_at && 'single' === $quiet->usage_mode && ! as_has_scheduled_action( 'vcap_deliver_voucher', array( 'voucher_id' => $quiet->id ) ), 'send => false: no email, the voucher counts as handed over' );
$bad = array(
	vcap()->issuer->issue_standalone( array( 'amount' => 0 ) ),
	vcap()->issuer->issue_standalone( array( 'amount' => 10 ) ),
	vcap()->issuer->issue_standalone( array( 'amount' => 10, 'send' => false, 'type' => 'credit' ) ),
	vcap()->issuer->issue_standalone( array( 'amount' => 10, 'send' => false, 'code' => $issued->code ) ),
);
vcap_ctr_assert( array( 'vcap_invalid_amount', 'vcap_no_address', 'vcap_invalid_args', 'vcap_code_taken' ) === array_map( static fn( $e ) => is_wp_error( $e ) ? $e->get_error_code() : 'ok', $bad ), 'invalid arguments are refused (no value, no address to send to, store credit type, code taken)' );
$product = new WC_Product_Simple();
$product->set_name( 'R6 voucher ' . $tag );
$product->set_regular_price( '40' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->save();
$from_product = vcap()->issuer->issue_standalone( array( 'product_id' => $product->get_id(), 'buyer_email' => 'p-' . $tag . '@example.com' ) );
vcap_ctr_assert( $from_product instanceof Voucher && 40.0 === $from_product->initial_amount && $product->get_id() === $from_product->product_id && 'buyer' === $from_product->send_to, 'a voucher product gives the value (price) and settings', is_wp_error( $from_product ) ? $from_product->get_error_message() : null );

// My Account voucher list shows the extra link escaped.
ob_start();
VCAP\Plugin::template( 'myaccount/vouchers-table.php', array( 'vouchers' => array( vcap()->vouchers->get( $issued->id ) ), 'downloads' => vcap()->downloads, 'title' => '' ) );
$table = (string) ob_get_clean();
vcap_ctr_assert( str_contains( $table, 'vcap-voucher-link' ) && str_contains( $table, esc_url( 'https://wallet.example/pass/' . $issued->id . '?c=account' ) ), 'the My Account voucher list shows the vcap_voucher_links link' );
remove_filter( 'vcap_voucher_links', $links, 10 );
remove_filter( 'pre_wp_mail', $catch, 999 );

// D14: every path writes the coupon through CouponManager::sync_coupon() – API and counter agree on the usage limit.
wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
delete_option( Counter::USER_COUNT );
delete_option( Counter::TILL_COUNT );
$d14_usage = static function ( Voucher $v ): array {
	wp_cache_delete( $v->coupon_id, 'post_meta' );
	$c = new WC_Coupon( $v->coupon_id );
	return array( 'limit' => (int) $c->get_usage_limit(), 'count' => (int) $c->get_usage_count() );
};
$d14_api     = vcap_ctr_voucher( 'single', 20 );
$d14_counter = vcap_ctr_voucher( 'single', 20 );
$d14_key     = 'd14-' . wp_generate_uuid4();
$d14_res     = vcap()->redemptions->reserve( $d14_api->code, array( 'key' => $d14_key, 'target' => 'test:d14', 'amount' => 20 ) );
$d14_com     = is_wp_error( $d14_res ) ? $d14_res : vcap()->redemptions->commit( $d14_key );
$d14_scan    = vcap_ctr_scan( $d14_counter );
vcap_ctr_assert( ! is_wp_error( $d14_com ) && ! empty( $d14_scan['success'] ) && $d14_usage( $d14_api ) === $d14_usage( $d14_counter ) && array( 'limit' => 1, 'count' => 1 ) === $d14_usage( $d14_counter ), 'D14: a single-use voucher redeemed over the API and one redeemed at the counter leave the same used-up coupon', array( $d14_usage( $d14_api ), $d14_usage( $d14_counter ), is_wp_error( $d14_com ) ? $d14_com->get_error_code() : null ) );
$d14_refund = vcap()->redemptions->refund( $d14_key, 20, 'd14r-' . wp_generate_uuid4() );
$d14_event  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE voucher_id = %d AND event = 'redeemed' ORDER BY id DESC LIMIT 1", $d14_counter->id ) ); // phpcs:ignore
$d14_back   = vcap()->tickets->reverse_redemption( vcap()->vouchers->get( $d14_counter->id ), $d14_event, 'd14 test' );
$d14_left   = array_map( static fn( $u ) => $u['limit'] - $u['count'], array( $d14_usage( $d14_api ), $d14_usage( $d14_counter ) ) );
vcap_ctr_assert( ! is_wp_error( $d14_refund ) && ! is_wp_error( $d14_back ) && Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $d14_api->id )->status && Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $d14_counter->id )->status && array( 1, 1 ) === $d14_left, 'D14: an API refund and a cancelled counter redemption both leave exactly one use on the coupon', array( $d14_left, is_wp_error( $d14_refund ) ? $d14_refund->get_error_code() : null, is_wp_error( $d14_back ) ? $d14_back->get_error_code() : null ) );
// A coupon that allows two uses although the voucher is single-use (e.g. edited in WooCommerce) is put right.
$d14_wide = new WC_Coupon( $d14_counter->coupon_id );
$d14_wide->set_usage_limit( 5 );
$d14_wide->save();
VCAP\CouponManager::sync_coupon( vcap()->vouchers->get( $d14_counter->id ), array( 'usage' ) );
vcap_ctr_assert( 1 === $d14_usage( $d14_counter )['limit'] - $d14_usage( $d14_counter )['count'], 'D14: the usage sync keeps an active single-use voucher at exactly one use', $d14_usage( $d14_counter ) );
// Expiry and status follow the voucher row through the same method (admin edit, top-up extension, CLI repair).
$d14_card = vcap_ctr_voucher( 'balance', 50 );
VCAP\TopUp::extend_validity( vcap()->vouchers, $d14_card, gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS ) );
$d14_card    = vcap()->vouchers->get( $d14_card->id );
$d14_expires = ( new WC_Coupon( $d14_card->coupon_id ) )->get_date_expires();
vcap_ctr_assert( $d14_expires && strtotime( $d14_card->expires_at . ' UTC' ) === $d14_expires->getTimestamp(), 'D14: an extended validity is written to the coupon expiry', array( $d14_card->expires_at, $d14_expires ? $d14_expires->date( 'Y-m-d H:i:s' ) : null ) );
$d14_card->status = Voucher::STATUS_VOID;
VCAP\CouponManager::sync_coupon( $d14_card, array( 'status' ) );
$d14_off = get_post_status( $d14_card->coupon_id );
$d14_card->status = Voucher::STATUS_ACTIVE;
VCAP\CouponManager::sync_coupon( $d14_card, array( 'status' ) );
vcap_ctr_assert( 'draft' === $d14_off && 'publish' === get_post_status( $d14_card->coupon_id ), 'D14: the coupon status follows the voucher status (void = disabled, active = enabled)' );

echo "Counter integration: all checks passed.\n";
