<?php
/**
 * Concurrency and ledger core (audit M12-04, -05, -06, -22, -62, -63): bookings inside an outer transaction read the
 * latest committed balance, a listener failing after the COMMIT never lets a scanner retry book twice, the privacy
 * eraser and other non-booking writes never put back a stale balance or status, the coupon amount is saved outside
 * the row lock, and the stored balances are reconciled with the ledger. Run through WP-CLI after activating
 * WooCommerce and Voucher Captain:
 *   wp eval-file tests/ledger-integration.php
 */

use VCAP\Attention;
use VCAP\Db;
use VCAP\Install;
use VCAP\Ledger;
use VCAP\Voucher;

function vcap_lg_assert( bool $condition, string $message, $context = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null !== $context ? ' — ' . wp_json_encode( $context ) : '' ) );
	}
	echo "PASS: {$message}\n";
}

/** A voucher with its coupon (as issued), not linked to an order. */
function vcap_lg_voucher( string $mode = 'balance', float $value = 100.0, string $type = '' ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-lg-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$voucher                 = new Voucher();
	$voucher->code           = wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 83000000 + $coupon->get_id();
	$voucher->type           = '' !== $type ? $type : ( 'balance' === $mode ? 'giftcard' : 'voucher' );
	$voucher->usage_mode     = $mode;
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $value;
	$voucher->balance        = $value;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->status         = Voucher::STATUS_ACTIVE;
	$voucher->send_to        = 'buyer';
	$voucher->buyer_email    = 'ledger-buyer@example.com';
	$voucher->buyer_name     = 'Ledger Buyer';
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	$GLOBALS['vcap_lg_ids'][] = (int) $voucher->id;
	vcap()->vouchers->log( $voucher->id, 'issued', $value, 'balance' === $mode ? $value : null );
	return $voucher;
}

/** A second database connection: changes from "another request" that this request does not see coming. */
function vcap_lg_other(): mysqli {
	global $wpdb;
	list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
	return new mysqli( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : 3306, $socket ? (string) $socket : null );
}

/** Another request deducts $amount from the card and commits (balance and ledger row, as a booking writes them). */
function vcap_lg_other_deduct( mysqli $other, Voucher $voucher, float $amount, int $order_id = 0, string $event = 'redeemed' ): float {
	$t       = Install::tables();
	$balance = (float) $other->query( "SELECT balance FROM {$t['vouchers']} WHERE id = " . (int) $voucher->id )->fetch_row()[0];
	$after   = round( $balance - $amount, 2 );
	$other->query( 'START TRANSACTION' );
	$other->query( "UPDATE {$t['vouchers']} SET balance = {$after} WHERE id = " . (int) $voucher->id );
	$other->query( "INSERT INTO {$t['events']} (voucher_id, event, amount, balance_after, order_id, user_id, note, created_at) VALUES (" . (int) $voucher->id . ", '{$event}', {$amount}, {$after}, " . (int) $order_id . ", 0, 'other request', UTC_TIMESTAMP())" );
	$other->query( 'COMMIT' );
	return $after;
}

global $wpdb;
$t     = Install::tables();
$other = vcap_lg_other();
wp_set_current_user( 1 );
VCAP\Db::reset();

/* ---- M12-04: bookings inside an outer transaction (connector) see the latest committed balance ------------------ */

$card = vcap_lg_voucher( 'balance', 100.0 );
if ( false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' ) ) {
	// As MariaDB 11.6.2+ by default: a locking read of a row changed since the snapshot would fail with 1020 (and roll
	// back the transaction). The plugin switches it off for its bookings (Db::classic_locking_reads()).
	$lg_suppress = $wpdb->suppress_errors( true );
	$wpdb->query( 'SET SESSION innodb_snapshot_isolation = ON' ); // phpcs:ignore
	$wpdb->suppress_errors( $lg_suppress );
}
Db::begin(); // A connector's own Db::transaction() …
$seen = vcap()->vouchers->get( $card->id ); // … that read the card first: its REPEATABLE READ snapshot is taken now.
vcap_lg_other_deduct( $other, $card, 80.0 ); // The till deducts 80 € and commits meanwhile.
$reason  = '';
$refused = vcap()->coupons->deduct_balance_atomic( $card, 90.0, 'connector', 0, false, $reason );
$fits    = vcap()->coupons->deduct_balance_atomic( $card, 15.0, 'connector', 0, false );
Db::commit();
$after = vcap()->vouchers->get( $card->id );
vcap_lg_assert( 100.0 === $seen->balance && null === $refused && 'exceeds_balance' === $reason && is_array( $fits ) && 5.0 === (float) $fits['balance'] && 5.0 === $after->balance, 'M12-04: a deduction inside an outer transaction that read before books from the balance committed by another request (no 170 € from a 100 € card)', array( $reason, $fits, $after->balance ) );

$card2 = vcap_lg_voucher( 'balance', 100.0 );
Db::begin();
vcap()->vouchers->get( $card2->id );
vcap_lg_other_deduct( $other, $card2, 80.0 );
$api = vcap()->redemptions->reserve( $card2->code, array( 'key' => 'lg-' . wp_generate_uuid4(), 'target' => 'test:lg', 'amount' => 90 ) );
Db::rollback_silently();
vcap_lg_assert( is_wp_error( $api ) && 20.0 === vcap()->vouchers->get( $card2->id )->balance, 'M12-04: RedemptionService::reserve() inside a connector transaction refuses 90 € when another request left 20 €', is_wp_error( $api ) ? $api->get_error_code() : $api );

$order = wc_create_order();
$order->set_billing_email( 'ledger@example.com' );
$order->save();
$card3 = vcap_lg_voucher( 'balance', 100.0 );
Db::begin();
vcap()->vouchers->events( $card3->id );
vcap_lg_other_deduct( $other, $card3, 30.0, $order->get_id(), 'reserved' ); // Another request reserved 30 € for this order.
$res = ( new ReflectionMethod( VCAP\CouponManager::class, 'reserve_balance_atomic' ) )->invoke( vcap()->coupons, $card3, 30.0, $order->get_id(), 'checkout' );
Db::commit();
$reserved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['events']} WHERE voucher_id = %d AND event = 'reserved'", $card3->id ) ); // phpcs:ignore
vcap_lg_assert( is_array( $res ) && 'reserved' === $res['state'] && 70.0 === (float) $res['balance'] && 1 === $reserved && 70.0 === vcap()->vouchers->get( $card3->id )->balance, 'M12-04: the charge state is read with a locking read – the reservation another request committed is found, not booked a second time', array( $res, $reserved ) );

$credit_user = (int) wp_insert_user( array( 'user_login' => 'vcap_lg_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'lg-' . wp_generate_password( 6, false, false ) . '@example.com' ) );
vcap()->store_credit->adjust( $credit_user, 50.0, 'start' );
$account = vcap()->store_credit->account( $credit_user );
Db::begin();
vcap()->vouchers->get( $account->id );
vcap_lg_other_deduct( $other, $account, 40.0, 0, 'debit' );
$debit = vcap()->store_credit->adjust( $credit_user, -20.0, 'connector' );
Db::rollback_silently();
vcap_lg_assert( is_wp_error( $debit ) && 'vcap_insufficient_balance' === $debit->get_error_code() && 10.0 === vcap()->vouchers->get( $account->id )->balance, 'M12-04: StoreCredit::adjust() inside an outer transaction books from the balance another request left', is_wp_error( $debit ) ? $debit->get_error_code() : $debit );

/* ---- M12-05: an exception after the COMMIT never lets the scanner's retry with the same key book again ------------ */

function vcap_lg_scan( Voucher $voucher, string $key, float $amount ) {
	$req = new WP_REST_Request( 'POST', '/vcap/v1/redeem' );
	$req->set_param( 'q', $voucher->code );
	$req->set_param( 'idempotency_key', $key );
	$req->set_param( 'amount', $amount );
	$res = VCAP\Source::with( VCAP\Source::SCANNER, static fn() => vcap()->tickets->rest_redeem( $req ) );
	return is_wp_error( $res ) ? $res : $res->get_data();
}

$scan_card = vcap_lg_voucher( 'balance', 100.0 );
$throw     = static function () {
	throw new RuntimeException( 'listener after commit' );
};
add_action( 'vcap_voucher_redeemed', $throw );
$key   = wp_generate_uuid4();
$first = vcap_lg_scan( $scan_card, $key, 10.0 );
remove_action( 'vcap_voucher_redeemed', $throw );
$retry = vcap_lg_scan( $scan_card, $key, 10.0 );
vcap_lg_assert( ! is_wp_error( $first ) && ! empty( $first['success'] ) && ! empty( $retry['replayed'] ) && 90.0 === vcap()->vouchers->get( $scan_card->id )->balance, 'M12-05: a listener of vcap_voucher_redeemed that throws after the COMMIT is logged; the retry with the same key gets the stored answer and deducts nothing', array( $first, $retry ) );

// An exception that escapes after the booking (here: a coupon save hook while the coupon amount is synced).
$scan_card2 = vcap_lg_voucher( 'balance', 100.0 );
$boom       = static function ( $coupon_id ) use ( $scan_card2 ) {
	if ( (int) $coupon_id === (int) $scan_card2->coupon_id ) {
		throw new RuntimeException( 'coupon hook failed' );
	}
};
add_action( 'woocommerce_update_coupon', $boom );
$key2   = wp_generate_uuid4();
$thrown = null;
try {
	$answer = vcap_lg_scan( $scan_card2, $key2, 10.0 );
} catch ( Throwable $e ) {
	$thrown = $e->getMessage();
}
remove_action( 'woocommerce_update_coupon', $boom );
$again = vcap_lg_scan( $scan_card2, $key2, 10.0 );
vcap_lg_assert( null === $thrown && ! empty( $answer['recovered'] ) && 'warning' === $answer['state'] && ! empty( $again['replayed'] ) && ! empty( $again['recovered'] ) && 90.0 === vcap()->vouchers->get( $scan_card2->id )->balance, 'M12-05: an exception after the booking keeps the key: "already booked" is stored and answered, the retry with the same key deducts nothing', array( $thrown, $answer ?? null, $again ) );

// Nothing booked: the key is released and the same key may act.
$scan_card3 = vcap_lg_voucher( 'balance', 100.0 );
$early      = static function () {
	throw new RuntimeException( 'before booking' );
};
add_filter( 'vcap_counter_limit_applies', $early );
$key3    = wp_generate_uuid4();
$thrown3 = null;
try {
	vcap_lg_scan( $scan_card3, $key3, 10.0 );
} catch ( Throwable $e ) {
	$thrown3 = $e->getMessage();
}
remove_filter( 'vcap_counter_limit_applies', $early );
$released = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['scan']} WHERE key_hash = %s", md5( $key3 ) ) ); // phpcs:ignore
$acted    = vcap_lg_scan( $scan_card3, $key3, 10.0 );
vcap_lg_assert( 'before booking' === $thrown3 && 0 === $released && ! empty( $acted['success'] ) && empty( $acted['recovered'] ) && 90.0 === vcap()->vouchers->get( $scan_card3->id )->balance, 'M12-05: an exception before any booking still releases the key (thrown on), and the same key then books once', array( $thrown3, $released, $acted ) );

/* ---- M12-06: the privacy eraser never puts back a stale balance or status ----------------------------------------- */

$erase_card = vcap_lg_voucher( 'balance', 100.0 );
$erase_cpn  = new WC_Coupon( $erase_card->coupon_id );
$erase_cpn->set_email_restrictions( array( 'ledger-buyer@example.com' ) );
$erase_cpn->save();
$redeem_meanwhile = static function ( $coupon_id ) use ( $erase_card, $other ) {
	static $done = false;
	if ( ! $done && (int) $coupon_id === (int) $erase_card->coupon_id ) {
		$done = true;
		vcap_lg_other_deduct( $other, $erase_card, 30.0 ); // A redemption lands while the eraser works.
	}
};
add_action( 'woocommerce_update_coupon', $redeem_meanwhile );
$erased = ( new VCAP\Privacy( vcap()->vouchers ) )->erase( 'ledger-buyer@example.com' );
remove_action( 'woocommerce_update_coupon', $redeem_meanwhile );
$erased_row = vcap()->vouchers->get( $erase_card->id );
vcap_lg_assert( ! empty( $erased['items_removed'] ) && '' === $erased_row->buyer_email && '' === $erased_row->buyer_name && 70.0 === $erased_row->balance && Voucher::STATUS_ACTIVE === $erased_row->status, 'M12-06: the privacy eraser writes only the personal data – a redemption made between loading and writing is kept', array( $erased, $erased_row->balance, $erased_row->buyer_email ) );

/* ---- M12-22: no full-row writes from stale objects --------------------------------------------------------------- */

// SingleUse::usage_decreased(): a voiding that landed after the voucher was read is not lifted.
$single = vcap_lg_voucher( 'single', 25.0 );
vcap()->vouchers->update_status( $single->id, Voucher::STATUS_REDEEMED );
$single_coupon = new WC_Coupon( $single->coupon_id );
vcap()->vouchers->get_by_coupon( $single->coupon_id ); // Read (memoised) as redeemed …
$other->query( "UPDATE {$t['vouchers']} SET status = 'void' WHERE id = " . (int) $single->id ); // … voided by another request.
vcap()->coupons->usage_decreased( $single_coupon, 0, 'someone@example.com' );
$restored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['events']} WHERE voucher_id = %d AND event = 'restored'", $single->id ) ); // phpcs:ignore
vcap_lg_assert( Voucher::STATUS_VOID === vcap()->vouchers->get( $single->id )->status && 0 === $restored, 'M12-22: usage_decreased() gives the use back only while the voucher is still redeemed (conditional UPDATE) and logs only then', $restored );
VCAP\VoucherRepository::forget();
$single2 = vcap_lg_voucher( 'single', 25.0 );
vcap()->vouchers->update_status( $single2->id, Voucher::STATUS_REDEEMED );
vcap()->coupons->usage_decreased( new WC_Coupon( $single2->coupon_id ), 0, 'someone@example.com' );
$single2_row = vcap()->vouchers->get( $single2->id );
vcap_lg_assert( Voucher::STATUS_ACTIVE === $single2_row->status && 25.0 === $single2_row->balance && vcap()->vouchers->has_event( $single2->id, 'restored' ), 'M12-22: a redeemed single-use voucher still gets its use back (active, face value, logged)' );

// Tickets::checkin(): the last admission sets the status in the same UPDATE; an undo landing meanwhile is kept.
$ticket               = vcap_lg_voucher( 'single', 0.0, 'ticket' );
$wpdb->update( $t['vouchers'], array( 'max_checkins' => 2, 'checkins' => 1 ), array( 'id' => $ticket->id ) ); // phpcs:ignore
$ticket               = vcap()->vouchers->get( $ticket->id );
$undo_meanwhile = static function ( $voucher_id, $event ) use ( $ticket, $other, $t ) {
	if ( (int) $voucher_id === (int) $ticket->id && 'checkin' === $event ) {
		// A parallel undo_checkin() (both of its statements) from another scanner.
		$other->query( "UPDATE {$t['vouchers']} SET checkins = checkins - 1 WHERE id = " . (int) $ticket->id . ' AND checkins > 0' );
		$other->query( "UPDATE {$t['vouchers']} SET status = 'active' WHERE id = " . (int) $ticket->id . " AND status = 'redeemed' AND checkins < max_checkins" );
	}
};
add_action( 'vcap_voucher_event', $undo_meanwhile, 10, 2 );
$checked_in = vcap()->tickets->checkin( $ticket );
remove_action( 'vcap_voucher_event', $undo_meanwhile, 10 );
$ticket_row = vcap()->vouchers->get( $ticket->id );
vcap_lg_assert( $checked_in && 1 === $ticket_row->checkins && Voucher::STATUS_ACTIVE === $ticket_row->status, 'M12-22: the last check-in changes the status in the same UPDATE – a parallel undo is not overwritten', array( $ticket_row->checkins, $ticket_row->status ) );
$checked_last = vcap()->tickets->checkin( $ticket_row );
$ticket_row   = vcap()->vouchers->get( $ticket->id );
vcap_lg_assert( $checked_last && 2 === $ticket_row->checkins && Voucher::STATUS_REDEEMED === $ticket_row->status && ! vcap()->tickets->checkin( $ticket_row ), 'M12-22: the last admission marks the ticket redeemed, a further check-in is refused' );

// StoreCredit::attach_coupon() via repair: only the coupon id is written, never the balance read before.
$repair_user = (int) wp_insert_user( array( 'user_login' => 'vcap_lgr_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'lgr-' . wp_generate_password( 6, false, false ) . '@example.com' ) );
vcap()->store_credit->adjust( $repair_user, 50.0, 'start' );
$repair_account = vcap()->store_credit->account( $repair_user );
wp_delete_post( $repair_account->coupon_id, true );
$credit_meanwhile = static function () use ( $repair_account, $other ) {
	static $done = false;
	if ( ! $done ) {
		$done = true;
		vcap_lg_other_deduct( $other, $repair_account, -30.0, 0, 'credit' ); // Credit of 30 € booked meanwhile.
	}
};
add_action( 'woocommerce_new_coupon', $credit_meanwhile );
$repaired = vcap()->store_credit->account( $repair_user );
remove_action( 'woocommerce_new_coupon', $credit_meanwhile );
$repaired_row = vcap()->vouchers->get( $repair_account->id );
vcap_lg_assert( $repaired && $repaired_row->coupon_id && $repaired_row->coupon_id !== $repair_account->coupon_id && 80.0 === $repaired_row->balance, 'M12-22: repairing a store credit coupon writes only the coupon id – a credit booked meanwhile is kept', array( $repaired_row->coupon_id, $repaired_row->balance ) );

/* ---- M12-62: the coupon is saved outside the voucher row lock, and saved again when the balance moved ------------ */

$sync_card = vcap_lg_voucher( 'balance', 100.0 );
$wpdb->update( $t['vouchers'], array( 'balance' => 40 ), array( 'id' => $sync_card->id ) ); // phpcs:ignore
$in_tx         = array();
$move_meanwhile = static function ( $coupon_id ) use ( $sync_card, $other, &$in_tx ) {
	if ( (int) $coupon_id !== (int) $sync_card->coupon_id ) {
		return;
	}
	$in_tx[] = Db::in_transaction();
	if ( 1 === count( $in_tx ) ) {
		vcap_lg_other_deduct( $other, $sync_card, 5.0 ); // Another booking lands while this save runs.
	}
};
add_action( 'woocommerce_update_coupon', $move_meanwhile );
$synced = VCAP\Coupons\CouponSync::sync_coupon_amount( $sync_card );
remove_action( 'woocommerce_update_coupon', $move_meanwhile );
wp_cache_delete( $sync_card->coupon_id, 'post_meta' );
$sync_amount = (float) ( new WC_Coupon( $sync_card->coupon_id ) )->get_amount( 'edit' );
vcap_lg_assert( $synced && array( false, false ) === $in_tx && 35.0 === $sync_amount, 'M12-62: WC_Coupon::save() runs without the voucher row lock; a balance changed during the save is read again and saved', array( $in_tx, $sync_amount ) );

/* ---- M12-63: stored balances are reconciled with the ledger ------------------------------------------------------ */

// Earlier suites insert fixtures with a balance but without ledger rows (direct SQL); they are not this check's subject.
$before = Ledger::verify();
$pre    = array_map( static fn( $r ) => (int) $r['voucher_id'], $before['rows'] );
$own    = array_intersect( $pre, array_merge( $GLOBALS['vcap_lg_ids'], array( (int) $account->id, (int) $repair_account->id ) ) );
vcap_lg_assert( $before['complete'] && $before['checked'] > 0 && array() === $own, 'M12-63: after the bookings of this suite (direct, nested, concurrent, repaired) every stored balance equals the balance after its last ledger row', $before['rows'] );
$drift = vcap_lg_voucher( 'balance', 60.0 );
vcap()->coupons->deduct_balance_atomic( $drift, 10.0, 'normal booking', 0, false );
$wpdb->update( $t['vouchers'], array( 'balance' => 45 ), array( 'id' => $drift->id ) ); // phpcs:ignore -- written without a booking
$found  = Ledger::reconcile();
$hit    = array_values( array_filter( $found['rows'], static fn( $r ) => (int) $r['voucher_id'] === (int) $drift->id ) );
$health = ( new VCAP\Health() )->test_ledger();
vcap_lg_assert( count( $pre ) + 1 === count( $found['rows'] ) && 1 === count( $hit ) && 45.0 === $hit[0]['balance'] && 50.0 === $hit[0]['ledger'] && Attention::has( 0, Ledger::VERIFY_KIND . $drift->id ) && 'critical' === $health['status'] && str_contains( $health['description'], $drift->code ), 'M12-63: a balance written without a booking is found (stored 45, ledger 50), listed in Attention and reported by Site Health', array( $hit, $health['status'] ) );
vcap()->coupons->adjust_balance( $drift, 5.0, 'adjusted', 'correction' );
$fixed  = Ledger::reconcile();
$health = ( new VCAP\Health() )->test_ledger();
vcap_lg_assert( count( $pre ) === count( $fixed['rows'] ) && ! Attention::has( 0, Ledger::VERIFY_KIND . $drift->id ) && ( $pre ? 'critical' : 'good' ) === $health['status'] && ! str_contains( $health['description'], $drift->code ), 'M12-63: after a correction booking the Attention case is resolved and Site Health no longer lists the voucher', $fixed );
$tests = ( new VCAP\Health() )->tests( array() );
vcap_lg_assert( isset( $tests['async']['vcap_ledger'] ) && method_exists( VCAP\CLI\Command::class, 'ledger' ), 'M12-63: the ledger check is a Site Health test and the CLI command "wp vcap ledger verify" exists' );
// Clean up the Attention cases of the fixtures (they would show on the dashboard of later suites).
foreach ( $pre as $id ) {
	Attention::resolve( 0, Ledger::VERIFY_KIND . $id );
}
if ( ! $pre ) {
	( new VCAP\CLI\Command() )->ledger( array( 'verify' ) ); // Halts with exit code 1 on a difference.
	echo "PASS: M12-63: wp vcap ledger verify succeeds on matching balances\n";
}

$other->close();
echo "All ledger checks passed.\n";
