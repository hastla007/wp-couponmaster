<?php
/**
 * Store credit checks. Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/store-credit-integration.php
 */

function vcap_sc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

// M12-03: new installations settle multi-purpose vouchers as a payment after tax ("Multi-purpose vouchers as
// payment" on). This suite checks the coupon mode of existing shops; the payment mode is covered in
// tests/tax-integration.php section 8 and tests/tax-accounting-integration.php.
update_option( VCAP\PaymentVouchers::OPTION, 'no' );

function vcap_sc_user( string $name ): int {
	$login = 'vcap_sc_' . $name . '_' . strtolower( wp_generate_password( 6, false, false ) );
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'user_email' => $login . '@example.com', 'role' => 'customer' ) );
}

function vcap_sc_order( WC_Product $product, int $customer ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $customer ? get_userdata( $customer )->user_email : 'guest-sc@example.com' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

update_option( 'vcap_store_credit', 'yes' );
$credit  = vcap()->store_credit;
$alice   = vcap_sc_user( 'alice' );
$bob     = vcap_sc_user( 'bob' );
$product = new WC_Product_Simple();
$product->set_name( 'Store credit product' );
$product->set_regular_price( '20' );
$product->set_status( 'publish' );
$product->save();

vcap_sc_assert( null === $credit->account( $alice ) && 0.0 === $credit->balance( $alice ), 'customers have no account until credit is added' );
$added = $credit->adjust( $alice, 30, 'Goodwill' );
$account = $credit->account( $alice );
vcap_sc_assert( ! is_wp_error( $added ) && 30.0 === $added['balance'] && 'credit' === $account->type && $alice === $account->user_id, 'adding credit opens the account' );
vcap_sc_assert( 30.0 === (float) ( new WC_Coupon( $account->coupon_id ) )->get_amount(), 'the account coupon follows the balance' );
vcap_sc_assert( 25.0 === $credit->adjust( $alice, -5, 'Correction' )['balance'], 'credit can be removed' );
vcap_sc_assert( is_wp_error( $credit->adjust( $alice, -100, 'Too much' ) ), 'the balance cannot go below zero' );
vcap_sc_assert( is_wp_error( $credit->adjust( $alice, 10, '' ) ), 'a reason is required' );
$events = array_column( vcap()->vouchers->events( $account->id ), 'note', 'event' );
vcap_sc_assert( 'Goodwill' === ( $events['credit'] ?? '' ) && 'Correction' === ( $events['debit'] ?? '' ), 'credit changes are journaled with their reason' );
$vc12_ctx = array();
$vc12_cb  = static function ( ...$args ) use ( &$vc12_ctx ) {
	$vc12_ctx[] = $args[6] ?? null;
};
add_action( 'vcap_store_credit_changed', $vc12_cb, 10, 7 );
$credit->adjust( $alice, 5, 'Same second' );
$credit->adjust( $alice, -5, 'Same second' );
$credit->adjust( $alice, 5, 'Same second' );
$credit->adjust( $alice, -5, 'Same second' ); // Balance back at 25 for the checks below.
remove_action( 'vcap_store_credit_changed', $vc12_cb, 10 );
$vc12_events = array_column( vcap()->vouchers->events( (int) $account->id ), 'id', 'id' );
vcap_sc_assert( 4 === count( $vc12_ctx ) && 4 === count( array_unique( array_column( $vc12_ctx, 'journal_id' ) ) ) && array() === array_diff( array_column( $vc12_ctx, 'journal_id' ), array_keys( $vc12_events ) ), 'VC-12: vcap_store_credit_changed passes the ledger id of each booking as journal_id (four bookings in one second stay apart)', $vc12_ctx );

// A missing account coupon is created again (balance kept); a failed coupon save does not leave a broken account.
wp_delete_post( $account->coupon_id, true );
$repaired = $credit->account( $alice );
vcap_sc_assert( $repaired && $repaired->coupon_id && 'publish' === get_post_status( $repaired->coupon_id ) && 25.0 === (float) $repaired->balance, 'an account whose coupon was deleted gets a new one and keeps its balance' );
$account  = $repaired;
$carol    = vcap_sc_user( 'carol' );
global $wpdb;
$posts_tb = $wpdb->posts;
$no_coupon = static fn( $q ) => ( str_starts_with( ltrim( (string) $q ), 'INSERT INTO `' . $posts_tb . '`' ) && str_contains( (string) $q, 'shop_coupon' ) ) ? '' : $q;
add_filter( 'query', $no_coupon );
$failed = $credit->adjust( $carol, 10, 'Goodwill' );
remove_filter( 'query', $no_coupon );
vcap_sc_assert( is_wp_error( $failed ) && null === $credit->account( $carol ), 'a store credit account whose coupon cannot be saved is not opened' );
$retry = $credit->adjust( $carol, 10, 'Goodwill' );
vcap_sc_assert( ! is_wp_error( $retry ) && 10.0 === $credit->balance( $carol ), 'the next attempt opens the account' );

// Paying with store credit: only the owner, partial use, deducted at payment.
$foreign = vcap_sc_order( $product, $bob );
vcap_sc_assert( is_wp_error( $foreign->apply_coupon( $account->code ) ), 'another customer cannot use the store credit' );
$order = vcap_sc_order( $product, $alice );
vcap_sc_assert( true === $order->apply_coupon( $account->code ), 'the owner can use the store credit' );
$order = wc_get_order( $order->get_id() );
vcap_sc_assert( 0.0 === (float) $order->get_total(), 'the 20 product is fully paid with 25 credit' );
vcap()->coupons->reserve_order( $order );
$order->payment_complete();
vcap_sc_assert( 5.0 === $credit->balance( $alice ), 'the used credit is deducted and the rest remains' );

// Refund as store credit: explicit, no money back, credited to the account.
$paid = vcap_sc_order( $product, $alice );
$paid->set_status( 'completed' );
$paid->save();
vcap_sc_assert( 'vcap_credit_consent' === ( ( $no_consent = $credit->refund_as_credit( $paid, 8, 'Customer chose credit' ) ) instanceof WP_Error ? $no_consent->get_error_code() : '' ), 'a refund as credit without recorded customer consent is refused' );
$refunded = $credit->refund_as_credit( $paid, 8, 'Customer chose credit', array( 'channel' => 'email', 'note' => 'Mail of 12 Nov' ) );
$consents = (array) wc_get_order( $paid->get_id() )->get_meta( '_vcap_credit_consents' );
vcap_sc_assert( 1 === count( $consents ) && 'email' === $consents[0]['channel'] && 'Mail of 12 Nov' === $consents[0]['note'] && 8.0 === (float) $consents[0]['amount'] && (int) $consents[0]['refund_id'] > 0 && 'email' === ( wc_get_order( (int) $consents[0]['refund_id'] )->get_meta( '_vcap_credit_consent' )['channel'] ?? '' ), 'the consent is recorded on the order and on the refund (how, note, amount)' );
$paid     = wc_get_order( $paid->get_id() );
vcap_sc_assert( ! is_wp_error( $refunded ) && 13.0 === $credit->balance( $alice ), 'a refund as store credit adds to the balance' );
vcap_sc_assert( 8.0 === (float) $paid->get_total_refunded() && 12.0 === (float) $paid->get_remaining_refund_amount(), 'WooCommerce records the refund' );
vcap_sc_assert( is_wp_error( $credit->refund_as_credit( $paid, 50, 'Too much', array( 'channel' => 'email' ) ) ), 'refunds above the refundable total are refused' );
// S12: the same form sent twice (resubmitted POST, double click) books the credit once.
$twice_key   = VCAP\StoreCredit::request_key();
$twice_order = vcap_sc_order( $product, $alice );
$twice_order->set_status( 'completed' );
$twice_order->save();
$twice_before = $credit->balance( $alice );
$twice_first  = $credit->refund_as_credit( $twice_order, 3, 'Customer chose credit', array( 'channel' => 'email' ), $twice_key );
$twice_second = $credit->refund_as_credit( wc_get_order( $twice_order->get_id() ), 3, 'Customer chose credit', array( 'channel' => 'email' ), $twice_key );
vcap_sc_assert( ! is_wp_error( $twice_first ) && $twice_second instanceof WP_Error && 'vcap_credit_duplicate' === $twice_second->get_error_code(), 'a resubmitted refund form is refused as a duplicate' );
vcap_sc_assert( round( $twice_before + 3, 2 ) === round( $credit->balance( $alice ), 2 ) && 3.0 === (float) wc_get_order( $twice_order->get_id() )->get_total_refunded(), 'the credit and the refund are booked once' );
$stale = $credit->refund_as_credit( $twice_order, 18, 'Stale page', array( 'channel' => 'email' ), VCAP\StoreCredit::request_key() );
vcap_sc_assert( is_wp_error( $stale ), 'a second form with a stale remaining total is checked against the current refunds' );
$guest = vcap_sc_order( $product, 0 );
$guest->set_status( 'completed' );
$guest->save();
add_filter( 'vcap_refund_guest_as_gift_card', '__return_false' ); // M12-57: on by default (gift card), see refund-lifecycle.
vcap_sc_assert( is_wp_error( $credit->refund_as_credit( $guest, 5, 'Guest', array( 'channel' => 'email' ) ) ), 'guest orders cannot be refunded as credit when refunds as gift card are turned off' );
remove_filter( 'vcap_refund_guest_as_gift_card', '__return_false' );

// Fault injection: WooCommerce cannot record the refund – the credit added first is booked back, nothing else changes.
$before_fail  = $credit->balance( $alice );
$refunds_fail = (float) wc_get_order( $paid->get_id() )->get_total_refunded();
$break_refund = static function () {
	throw new Exception( 'Injected refund failure' );
};
add_action( 'woocommerce_create_refund', $break_refund );
$failed_refund = $credit->refund_as_credit( wc_get_order( $paid->get_id() ), 4, 'Customer chose credit', array( 'channel' => 'phone' ) );
remove_action( 'woocommerce_create_refund', $break_refund );
$paid_after = wc_get_order( $paid->get_id() );
$journal    = array_column( vcap()->vouchers->events( $credit->account( $alice )->id ), 'event' );
vcap_sc_assert( is_wp_error( $failed_refund ) && $before_fail === $credit->balance( $alice ) && $refunds_fail === (float) $paid_after->get_total_refunded() && in_array( 'refund_credit_reversed', $journal, true ), 'a refund WooCommerce cannot record books the credit back' );

// The credit was spent in the meantime (booking back fails): the order gets a note for the shop team.
$spend_it = static function () use ( $credit, $alice ) {
	$credit->adjust( $alice, -$credit->balance( $alice ), 'Spent at the same time' );
	throw new Exception( 'Injected refund failure' );
};
add_action( 'woocommerce_create_refund', $spend_it );
$spent_refund = $credit->refund_as_credit( wc_get_order( $paid->get_id() ), 4, 'Customer chose credit', array( 'channel' => 'phone' ) );
remove_action( 'woocommerce_create_refund', $spend_it );
$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $paid->get_id() ) ) );
vcap_sc_assert( is_wp_error( $spent_refund ) && (bool) array_filter( $notes, static fn( $n ) => str_contains( $n, 'could not be booked back' ) ), 'credit that was already spent when the refund failed is flagged on the order' );
$credit->adjust( $alice, $before_fail, 'Restore test balance' );

// A foreign-currency order is never refunded as credit 1:1: store credit is kept in the base currency.
$fx_currency = 'CZK' === get_option( 'woocommerce_currency' ) ? 'HUF' : 'CZK';
$fx_paid     = vcap_sc_order( $product, $alice );
$fx_paid->set_currency( $fx_currency );
$fx_paid->set_status( 'completed' );
$fx_paid->save();
$fx_before = $credit->balance( $alice );
$fx_refund = $credit->refund_as_credit( wc_get_order( $fx_paid->get_id() ), 10, 'Customer chose credit', array( 'channel' => 'email' ) );
vcap_sc_assert( $fx_refund instanceof WP_Error && 'vcap_credit_currency' === $fx_refund->get_error_code() && $fx_before === $credit->balance( $alice ) && 0.0 === (float) wc_get_order( $fx_paid->get_id() )->get_total_refunded(), 'a refund as credit in a currency without exchange rate is refused (nothing credited, nothing refunded)' );
$fx_paid = wc_get_order( $fx_paid->get_id() );
$fx_paid->update_meta_data( VCAP\Compat\MultiCurrency::RATE_META, '25' );
$fx_paid->save();
$fx_refund = $credit->refund_as_credit( wc_get_order( $fx_paid->get_id() ), 10, 'Customer chose credit', array( 'channel' => 'email' ) );
vcap_sc_assert( ! is_wp_error( $fx_refund ) && abs( $fx_before + 0.4 - $credit->balance( $alice ) ) < 0.001, 'with the exchange rate of the sale stored on the order, 10 are credited as 0.40 in the base currency' );
delete_option( VCAP\Compat\MultiCurrency::UNKNOWN_OPTION );

// Discounts are calculated first, store credit pays the rest (even when the credit was applied first).
$credit->adjust( $alice, 30, 'Top-up for ordering test' );
$five = new WC_Coupon();
$five->set_code( 'sc-five-' . strtolower( wp_generate_password( 6, false ) ) );
$five->set_discount_type( 'fixed_cart' );
$five->set_amount( '5' );
$five->save();
wp_set_current_user( $alice );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
WC()->cart->apply_coupon( $account->code );
WC()->cart->apply_coupon( $five->get_code() );
WC()->cart->calculate_totals();
vcap_sc_assert( 15.0 === round( (float) WC()->cart->get_coupon_discount_amount( wc_format_coupon_code( $account->code ), false ), 2 ), 'the discount code is applied before the store credit (credit pays 15, not 20)' );
update_option( 'vcap_credit_with_coupons', 'no' );
$check = ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $account->code ) );
vcap_sc_assert( is_wp_error( $check ), 'store credit can be kept apart from discount codes' );
delete_option( 'vcap_credit_with_coupons' );
WC()->cart->empty_cart();
wp_set_current_user( 0 );

// Profile credit (Q6/Q7): booked only after the profile was saved, once per form, amounts read strictly.
$sc_admin_login = 'vcap_sc_admin_' . strtolower( wp_generate_password( 6, false, false ) );
$sc_admin_id    = (int) wp_insert_user( array( 'user_login' => $sc_admin_login, 'user_pass' => wp_generate_password( 20 ), 'user_email' => $sc_admin_login . '@example.com', 'role' => 'administrator' ) );
vcap_sc_assert( $sc_admin_id > 0, 'admin for the profile checks' );
wp_set_current_user( $sc_admin_id );
$profile_admin = new VCAP\Admin\StoreCreditAdmin( $credit, vcap()->vouchers );
$dave          = vcap_sc_user( 'dave' );
$profile_post  = static fn( string $amount, string $token, string $reason = 'Goodwill' ) => array(
	'vcap_credit_nonce'  => wp_create_nonce( 'vcap_credit_' . $dave ),
	'vcap_credit_amount' => $amount,
	'vcap_credit_reason' => $reason,
	VCAP\Admin\FormToken::FIELD => $token,
);
$token = VCAP\Admin\FormToken::generate();
$_POST = $profile_post( '2,50', $token );
$profile_admin->save_profile( $dave );
vcap_sc_assert( 0.0 === $credit->balance( $dave ), 'Q6: nothing is booked before WordPress saved the profile' );
$profile_errors = new WP_Error();
$profile_admin->profile_errors( $profile_errors, true, get_userdata( $dave ) );
vcap_sc_assert( ! $profile_errors->has_errors(), 'a valid credit adds no profile error' );
$profile_admin->book_profile_credit( $dave );
vcap_sc_assert( 2.5 === $credit->balance( $dave ), 'Q7: "2,50" on the profile is 2.50 store credit, not 250' );
$profile_admin->save_profile( $dave );
$profile_admin->book_profile_credit( $dave );
vcap_sc_assert( 2.5 === $credit->balance( $dave ), 'Q6: the same form sent again (double click, reload) is not booked twice' );
$_POST = $profile_post( '−1', VCAP\Admin\FormToken::generate() );
$profile_admin->save_profile( $dave );
$profile_admin->book_profile_credit( $dave );
vcap_sc_assert( 1.5 === $credit->balance( $dave ), 'Q7: a Unicode minus removes credit instead of adding it' );
$_POST = $profile_post( '1e3', VCAP\Admin\FormToken::generate() );
$profile_admin->save_profile( $dave );
$profile_errors = new WP_Error();
$profile_admin->profile_errors( $profile_errors, true, get_userdata( $dave ) );
$profile_admin->book_profile_credit( $dave );
vcap_sc_assert( $profile_errors->has_errors() && 1.5 === $credit->balance( $dave ), 'Q7: "1e3" is refused as a profile error (the profile is not saved) and books nothing' );
$_POST = $profile_post( '5', VCAP\Admin\FormToken::generate() );
$profile_admin->save_profile( $dave );
// Another profile error (e.g. a taken email address): WordPress does not save, profile_update never fires.
$_POST = $profile_post( '5', $_POST[ VCAP\Admin\FormToken::FIELD ] );
$profile_admin->save_profile( $dave );
$profile_admin->book_profile_credit( $dave );
vcap_sc_assert( 6.5 === $credit->balance( $dave ), 'Q6: a profile that failed once and is sent again books the credit once' );
$_POST = array();
delete_transient( 'vcap_flash_' . get_current_user_id() );
wp_set_current_user( 0 );

// AP-Q1-1 (a): an order paid partly with store credit and fully refunded as store credit: the credit it spent
// comes back as well (the "refund_credit" ledger row of the same order must not hide its charge).
$erin = vcap_sc_user( 'erin' );
$credit->adjust( $erin, 5, 'Goodwill' );
$erin_account = $credit->account( $erin );
$erin_order   = vcap_sc_order( $product, $erin );
$erin_order->apply_coupon( $erin_account->code );
$erin_order = wc_get_order( $erin_order->get_id() );
vcap()->coupons->reserve_order( $erin_order );
$erin_order->payment_complete();
$erin_order = wc_get_order( $erin_order->get_id() );
vcap_sc_assert( 15.0 === (float) $erin_order->get_total() && 0.0 === $credit->balance( $erin ), 'AP-Q1-1: the 20 order is paid with 5 credit and 15 money' );
$erin_refund = $credit->refund_as_credit( $erin_order, 15, 'Customer chose credit', array( 'channel' => 'email' ) );
vcap_sc_assert( ! is_wp_error( $erin_refund ) && wc_get_order( $erin_order->get_id() )->has_status( 'refunded' ), 'AP-Q1-1: the money part is refunded as store credit (order fully refunded)' );
vcap_sc_assert( 20.0 === $credit->balance( $erin ), 'AP-Q1-1: the 15 refunded as credit and the 5 credit spent on the order are both on the account (20)' );

// Disabled feature: credit coupons stop working.
update_option( 'vcap_store_credit', 'no' );
$late = vcap_sc_order( $product, $alice );
vcap_sc_assert( is_wp_error( $late->apply_coupon( $account->code ) ), 'store credit cannot be used while the feature is disabled' );
delete_option( 'vcap_store_credit' );

echo "Voucher Captain store credit checks passed.\n";
