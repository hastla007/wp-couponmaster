<?php
/**
 * Transferability, redemption conditions and customer resend checks.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/account-integration.php
 */

function vcap_acct_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_acct_product( array $meta ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( 'Voucher test ' . wp_generate_password( 6, false, false ) );
	$product->set_regular_price( '50' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( '_vcap_' . $key, $value );
	}
	$product->save();
	return $product;
}

function vcap_acct_order( WC_Product $product, int $customer_id, string $email ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Test' );
	$order->set_billing_last_name( 'Buyer' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

$login = 'vcap_acct_' . strtolower( wp_generate_password( 6, false, false ) );
$buyer = (int) wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '@example.com',
		'role'       => 'customer',
	)
);
$other = (int) wp_insert_user(
	array(
		'user_login' => $login . '_x',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '_x@example.com',
		'role'       => 'customer',
	)
);

// Personal voucher with its own conditions.
$personal = vcap_acct_product(
	array(
		'transferable'     => 'no',
		'redemption_terms' => 'Nicht bar auszahlbar.',
		'email_restrict'   => 'none',
	)
);
$order    = vcap_acct_order( $personal, $buyer, $login . '@example.com' );
$issued   = vcap()->issuer->issue_for_order( $order );
vcap_acct_assert( 1 === count( $issued ), 'one voucher is issued for the order' );
$voucher = vcap()->vouchers->get( $issued[0]->id );
vcap_acct_assert( false === $voucher->transferable && 'Nicht bar auszahlbar.' === $voucher->terms, 'transferability and conditions are stored with the voucher' );
$coupon = new WC_Coupon( $voucher->coupon_id );
vcap_acct_assert( array( $login . '@example.com' ) === array_values( $coupon->get_email_restrictions() ), 'a personal voucher restricts the coupon to the buyer (and recipient) email' );

// V6: a personal voucher is redeemed only logged in with the matching account – a guest typing the buyer's email at
// checkout (classic cart, order) is refused with the masked address to log in with.
wc_load_cart();
$v6_live  = vcap()->vouchers->get( $voucher->id );
if ( VCAP\Voucher::STATUS_ACTIVE !== $v6_live->status ) {
	$v6_live->status = VCAP\Voucher::STATUS_ACTIVE;
	vcap()->vouchers->update( $v6_live );
}
$v6_masked = substr( $login, 0, 1 ) . '***@example.com';
$v6_goods  = new WC_Product_Simple();
$v6_goods->set_name( 'V6 goods' );
$v6_goods->set_regular_price( '80' );
$v6_goods->set_virtual( true );
$v6_goods->save();
$v6_valid  = static function ( $object ) use ( $voucher ) {
	return ( new WC_Discounts( $object ) )->is_coupon_valid( new WC_Coupon( $voucher->code ) );
};
wp_set_current_user( 0 );
WC()->customer->set_billing_email( $login . '@example.com' );
$v6_guest = $v6_valid( WC()->cart );
vcap_acct_assert( is_wp_error( $v6_guest ) && str_contains( $v6_guest->get_error_message(), 'Please log in with ' . $v6_masked . ' to use this personal voucher.' ), 'V6: a guest (with the buyer\'s billing email) cannot use a personal voucher in the cart – "Please log in with ' . $v6_masked . '"' );
$v6_guest_order = vcap_acct_order( $v6_goods, 0, $login . '@example.com' );
$v6_guest_order_valid = $v6_valid( $v6_guest_order );
vcap_acct_assert( is_wp_error( $v6_guest_order_valid ) && str_contains( $v6_guest_order_valid->get_error_message(), 'Please log in with' ), 'V6: nor in a guest order with the buyer\'s billing email' );
wp_set_current_user( $other );
$v6_other = $v6_valid( WC()->cart );
vcap_acct_assert( is_wp_error( $v6_other ) && str_contains( $v6_other->get_error_message(), 'belongs to another customer account' ), 'V6: another logged-in customer is refused' );
wp_set_current_user( $buyer );
// AP-S1-1: the account was created after the update and logged in without proving its mailbox (WooCommerce logs
// new accounts in at once) – refused, with the offer to send the confirmation link.
wc_clear_notices();
$ap_unproven = $v6_valid( WC()->cart );
vcap_acct_assert( is_wp_error( $ap_unproven ) && str_contains( $ap_unproven->get_error_message(), 'Please confirm your email address ' . $v6_masked ), 'AP-S1-1: a new account whose mailbox is not proven is refused ("Please confirm your email address ' . $v6_masked . '")' );
$ap_offer = implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) );
vcap_acct_assert( str_contains( $ap_offer, 'vcap_send_email_confirmation=1' ) && str_contains( $ap_offer, $v6_masked ), 'AP-S1-1: the cart notice offers to send the confirmation link to the masked address' );
wc_clear_notices();
reset_password( get_userdata( $buyer ), wp_generate_password( 24 ) ); // The emailed reset link proves the mailbox.
wp_set_current_user( $buyer );
vcap_acct_assert( strtolower( $login . '@example.com' ) === get_user_meta( $buyer, VCAP\PersonalVoucher::META_VERIFIED, true ), 'AP-S1-1: a password reset through the emailed link stores the proof of mailbox' );
vcap_acct_assert( true === $v6_valid( WC()->cart ), 'V6: logged in with the matching account the personal voucher is valid' );
wp_set_current_user( 0 );
vcap_acct_assert( true === $v6_valid( vcap_acct_order( $v6_goods, $buyer, $login . '@example.com' ) ), 'V6: an order of the matching account stays valid (e.g. recalculated later without a login)' );
$v6_admin_order = vcap_acct_order( $v6_goods, 0, $login . '@example.com' );
$v6_admin_order->set_created_via( 'admin' );
$v6_admin_order->save();
vcap_acct_assert( true === $v6_valid( $v6_admin_order ), 'V6: an order staff created in wp-admin may carry the personal voucher (staff checked the holder)' );
vcap_acct_assert( str_contains( $voucher->transferable_label(), 'Online redemption requires logging in to the customer account with that email address.' ), 'V6: the voucher text says that online redemption requires the customer account' );

// AP-S1-1: proof of mailbox. WooCommerce's account details change the email without confirmation.
$ap_coupon_for = static function ( string $email ) use ( $personal ): WC_Coupon {
	// A personal voucher of the shop (only Voucher Captain coupons get the identity check), bound to $email.
	$issued = vcap()->issuer->issue_for_order( vcap_acct_order( $personal, 0, $email ) );
	$live   = vcap()->vouchers->get( $issued[0]->id );
	if ( VCAP\Voucher::STATUS_ACTIVE !== $live->status ) {
		$live->status = VCAP\Voucher::STATUS_ACTIVE;
		vcap()->vouchers->update( $live );
	}
	$coupon = new WC_Coupon( $live->coupon_id );
	if ( array( strtolower( $email ) ) !== array_values( $coupon->get_email_restrictions() ) ) {
		throw new RuntimeException( 'FAIL: (setup) personal voucher restricted to ' . $email );
	}
	return $coupon;
};
$ap_valid_as = static function ( int $user_id, WC_Coupon $coupon ) {
	wp_set_current_user( 0 );
	clean_user_cache( $user_id );
	wp_set_current_user( $user_id );
	// As at checkout, the billing email is the account's (WooCommerce 8.9 checks the restriction against it).
	WC()->cart->get_customer()->set_billing_email( (string) get_userdata( $user_id )->user_email );
	update_user_meta( $user_id, 'billing_email', (string) get_userdata( $user_id )->user_email );
	return ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $coupon->get_id() ) );
};
$ap_victim = $login . '_victim@example.com';
$ap_coupon = $ap_coupon_for( $ap_victim );
\WC_Shortcode_My_Account::reset_password( get_userdata( $other ), wp_generate_password( 24 ) ); // WooCommerce's lost-password form.
vcap_acct_assert( strtolower( $login . '_x@example.com' ) === get_user_meta( $other, VCAP\PersonalVoucher::META_VERIFIED, true ), 'AP-S1-1: WooCommerce\'s lost-password reset also stores the proof (it fires password_reset)' );
wp_update_user( array( 'ID' => $other, 'user_email' => $ap_victim ) ); // What WC_Form_Handler::save_account_details() does.
do_action( 'woocommerce_save_account_details', $other );
wc_clear_notices();
$ap_changed = $ap_valid_as( $other, $ap_coupon );
// WooCommerce 8.9 compares the email restriction with the account's billing email itself and refuses first, with
// its own wording; newer versions leave the decision to the proof check, which names the confirmation.
$ap_wc_refused = is_wp_error( $ap_changed ) && version_compare( WC()->version, '9.0', '<' ) && str_contains( $ap_changed->get_error_message(), 'valid email' );
vcap_acct_assert( is_wp_error( $ap_changed ) && ( $ap_wc_refused || str_contains( $ap_changed->get_error_message(), 'Please confirm your email address' ) ) && '' === get_user_meta( $other, VCAP\PersonalVoucher::META_VERIFIED, true ) && (int) get_user_meta( $other, VCAP\PersonalVoucher::META_CHANGED, true ) > 0, 'AP-S1-1: an email changed in My Account is refused until confirmed (the proof of the old address is dropped)' );
$ap_url = VCAP\PersonalVoucher::confirmation_url( get_userdata( $other ) );
parse_str( (string) wp_parse_url( $ap_url, PHP_URL_QUERY ), $ap_link );
vcap_acct_assert( ! str_contains( $ap_url, rawurlencode( $ap_victim ) ) && ! str_contains( $ap_url, $ap_victim ) && ! VCAP\PersonalVoucher::confirm( (int) $ap_link['vcap_confirm_email'], (int) $ap_link['exp'] + 1, (string) $ap_link['sig'] ) && ! VCAP\PersonalVoucher::confirm( (int) $buyer, (int) $ap_link['exp'], (string) $ap_link['sig'] ) && ! VCAP\PersonalVoucher::confirm( (int) $ap_link['vcap_confirm_email'], (int) $ap_link['exp'], strrev( (string) $ap_link['sig'] ) ), 'AP-S1-1: a confirmation link with another expiry, user or signature does not verify (and carries no address)' );
vcap_acct_assert( VCAP\PersonalVoucher::confirm( (int) $ap_link['vcap_confirm_email'], (int) $ap_link['exp'], (string) $ap_link['sig'] ) && true === $ap_valid_as( $other, $ap_coupon ), 'AP-S1-1: the confirmation link verifies the address, then the personal voucher is valid' );
wp_update_user( array( 'ID' => $other, 'user_email' => $login . '_y@example.com' ) );
vcap_acct_assert( ! VCAP\PersonalVoucher::confirm( (int) $ap_link['vcap_confirm_email'], (int) $ap_link['exp'], (string) $ap_link['sig'] ) && '' === get_user_meta( $other, VCAP\PersonalVoucher::META_VERIFIED, true ), 'AP-S1-1: a link sent before the email changed does not verify the new address' );
// Grandfathering: accounts registered before the update whose email never changed keep redeeming.
global $wpdb;
$ap_old_user = static function ( string $suffix ) use ( $login, $wpdb ): int {
	$id = (int) wp_insert_user( array( 'user_login' => $login . '_' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '_' . $suffix . '@example.com', 'role' => 'customer' ) );
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', VCAP\PersonalVoucher::since() - 30 * DAY_IN_SECONDS ) ), array( 'ID' => $id ) );
	clean_user_cache( $id );
	return $id;
};
$ap_gf = $ap_old_user( 'gf' );
vcap_acct_assert( true === $ap_valid_as( $ap_gf, $ap_coupon_for( $login . '_gf@example.com' ) ) && strtolower( $login . '_gf@example.com' ) === get_user_meta( $ap_gf, VCAP\PersonalVoucher::META_VERIFIED, true ), 'AP-S1-1: a customer registered before the update (email unchanged) is grandfathered and accepted' );
$ap_gf2 = $ap_old_user( 'gf2' );
wp_update_user( array( 'ID' => $ap_gf2, 'user_email' => $login . '_gf2b@example.com' ) );
vcap_acct_assert( is_wp_error( $ap_valid_as( $ap_gf2, $ap_coupon_for( $login . '_gf2b@example.com' ) ) ), 'AP-S1-1: an old account whose email changed after the update is not grandfathered' );
// The confirmation email: sent to the account address with the link, at most three per hour and account.
$ap_mails = array();
$ap_catch = static function ( $short, $atts ) use ( &$ap_mails ) {
	$ap_mails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $ap_catch, 10, 2 );
$ap_results = array();
for ( $i = 0; $i < 4; $i++ ) {
	$ap_results[] = VCAP\PersonalVoucher::send_confirmation( get_userdata( $ap_gf2 ) );
}
remove_filter( 'pre_wp_mail', $ap_catch, 10 );
vcap_acct_assert( array( 'sent', 'sent', 'sent', 'limited' ) === $ap_results && 3 === count( $ap_mails ) && in_array( $login . '_gf2b@example.com', (array) $ap_mails[0]['to'], true ) && str_contains( (string) $ap_mails[0]['message'], 'vcap_confirm_email=' . $ap_gf2 ), 'AP-S1-1: the confirmation email goes to the account address with the signed link, rate limited (3 per hour)' );
vcap_acct_assert( 'verified' === VCAP\PersonalVoucher::send_confirmation( get_userdata( $ap_gf ) ), 'AP-S1-1: no confirmation email for an address that is already verified' );
$m25_registered = wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) strtotime( get_userdata( $ap_gf2 )->user_registered . ' UTC' ) );
vcap_acct_assert( str_contains( (string) $ap_mails[0]['message'], $login . '_gf2' ) && str_contains( (string) $ap_mails[0]['message'], $m25_registered ) && str_contains( (string) $ap_mails[0]['message'], 'did not create this account' ), 'M12-25: the confirmation email names the account and its registration date and warns not to click for someone else\'s account' );
// M12-25: the link confirms only for the same account, logged in – no pre-hijacking by a click of the address owner.
// M12-87: the page of the link sends no-cache headers and Referrer-Policy: no-referrer.
$m25_headers = array();
$m25_capture = static function ( $headers ) use ( &$m25_headers ) {
	$m25_headers = (array) $headers;
	return $headers;
};
$m25_open = static function ( string $url ) use ( $m25_capture ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$_GET     = $query;
	$location = null;
	$stop     = static function ( $to ) {
		throw new RuntimeException( 'redirect:' . $to );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	add_filter( 'nocache_headers', $m25_capture, 100 );
	try {
		VCAP\PersonalVoucher::handle_request();
	} catch ( RuntimeException $e ) {
		$location = substr( $e->getMessage(), 9 );
	} finally {
		remove_filter( 'wp_redirect', $stop, 1 );
		remove_filter( 'nocache_headers', $m25_capture, 100 );
		$_GET = array();
	}
	return $location;
};
delete_user_meta( $ap_gf2, VCAP\PersonalVoucher::META_VERIFIED );
$m25_link = VCAP\PersonalVoucher::confirmation_url( get_userdata( $ap_gf2 ) );
wp_set_current_user( 0 );
$m25_guest = $m25_open( $m25_link );
vcap_acct_assert( null !== $m25_guest && str_contains( $m25_guest, 'vcap_confirm_email=' . $ap_gf2 ) && '' === get_user_meta( $ap_gf2, VCAP\PersonalVoucher::META_VERIFIED, true ), 'M12-25: a click without login does not confirm – the visitor is sent to the login with the link kept for afterwards' );
vcap_acct_assert( 'no-referrer' === ( $m25_headers['Referrer-Policy'] ?? '' ) && str_contains( (string) ( $m25_headers['Cache-Control'] ?? '' ), 'no-cache' ), 'M12-87: the confirmation link answers with no-cache headers and Referrer-Policy: no-referrer' );
add_filter( 'woocommerce_is_account_page', '__return_true' );
$m25_form = $m25_open( $m25_link );
remove_filter( 'woocommerce_is_account_page', '__return_true' );
vcap_acct_assert( null === $m25_form && '' === get_user_meta( $ap_gf2, VCAP\PersonalVoucher::META_VERIFIED, true ), 'M12-25: on My Account the login form is shown (WooCommerce returns to the link after the login), nothing confirmed yet' );
wp_set_current_user( $ap_gf );
$m25_other = $m25_open( $m25_link );
vcap_acct_assert( null !== $m25_other && ! str_contains( $m25_other, 'vcap_email_confirmed=1' ) && '' === get_user_meta( $ap_gf2, VCAP\PersonalVoucher::META_VERIFIED, true ), 'M12-25: a customer logged in with another account cannot confirm the address' );
wp_set_current_user( $ap_gf2 );
$m25_same = $m25_open( $m25_link );
vcap_acct_assert( null !== $m25_same && str_contains( $m25_same, 'vcap_email_confirmed=1' ) && strtolower( $login . '_gf2b@example.com' ) === get_user_meta( $ap_gf2, VCAP\PersonalVoucher::META_VERIFIED, true ), 'M12-25: logged in with the account the link was sent for, the click confirms the address' );
wc_clear_notices();
wp_set_current_user( 0 );

// V11: order notes reach connectors and all staff – the issued code appears masked only.
$notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
vcap_acct_assert( ! str_contains( strtoupper( $notes ), strtoupper( $voucher->code ) ) && str_contains( $notes, VCAP\Downloads::masked_code( $voucher->code ) ), 'the "vouchers issued" order note shows the code masked, never in full' );

// S10: neither address usable any more (erased buyer, no recipient): the voucher stays personal.
$nobody                  = vcap()->vouchers->get( $voucher->id );
$nobody->buyer_email     = '';
$nobody->recipient_email = 'not-an-address';
vcap()->coupons->refresh_email_restrictions( $nobody, $login . '@example.com' );
$kept = array_values( ( new WC_Coupon( $voucher->coupon_id ) )->get_email_restrictions() );
vcap_acct_assert( array() !== $kept && vcap()->coupons->restriction_kept, 'without a valid buyer or recipient address the coupon keeps its restriction (never silently transferable) and the admin is warned' );

$personal->update_meta_data( '_vcap_redemption_terms', 'Changed later.' );
$personal->save();
$values = VCAP\Placeholders::values( vcap()->vouchers->get( $voucher->id ) );
vcap_acct_assert( 'Nicht bar auszahlbar.' === $values['redemption_terms'], 'later product changes do not alter conditions of sold vouchers' );
vcap_acct_assert( $voucher->transferable_label() === $values['transferable'], '{transferable} placeholder describes the voucher' );

// Shop default conditions apply when the product has none; transferable is the default.
update_option( 'vcap_default_terms', 'Standardbedingungen.' );
$default_product = vcap_acct_product( array() );
$default_order   = vcap_acct_order( $default_product, $buyer, $login . '@example.com' );
$default_voucher = vcap()->issuer->issue_for_order( $default_order )[0];
vcap_acct_assert( true === $default_voucher->transferable && 'Standardbedingungen.' === $default_voucher->terms, 'shop default conditions are used when the product has none' );
vcap_acct_assert( array() === ( new WC_Coupon( $default_voucher->coupon_id ) )->get_email_restrictions(), 'transferable vouchers are not email restricted' );
delete_option( 'vcap_default_terms' );

// Customer resend: only the buyer, only delivered vouchers.
$voucher               = vcap()->vouchers->get( $voucher->id );
$voucher->status       = VCAP\Voucher::STATUS_ACTIVE;
$voucher->delivered_at = gmdate( 'Y-m-d H:i:s' );
vcap()->vouchers->update( $voucher );
wp_set_current_user( $buyer );
vcap_acct_assert( vcap()->downloads->customer_can_resend( $voucher ), 'the buyer may resend a delivered voucher' );
wp_set_current_user( $other );
vcap_acct_assert( ! vcap()->downloads->customer_can_resend( $voucher ), 'another customer may not resend it' );
wp_set_current_user( $buyer );
$voucher->delivered_at = null;
$voucher->status       = VCAP\Voucher::STATUS_SCHEDULED;
vcap_acct_assert( ! vcap()->downloads->customer_can_resend( $voucher ), 'a scheduled gift cannot be resent before its delivery date' );
wp_set_current_user( 0 );

// F3: responsive tables – column headers with scope, every cell named by data-title, actions cell, empty state.
wp_set_current_user( $buyer );
ob_start();
vcap()->downloads->account_page( false );
$f3_list = (string) ob_get_clean();
preg_match_all( '/<th\b[^>]*>/', $f3_list, $f3_th );
preg_match_all( '/<td\b[^>]*>/', $f3_list, $f3_td );
vcap_acct_assert( count( $f3_th[0] ) >= 5 && count( $f3_th[0] ) === substr_count( $f3_list, '<th scope="col"' ), 'F3: every column header of the voucher list has scope="col"' );
vcap_acct_assert( $f3_td[0] && count( $f3_td[0] ) === count( preg_grep( '/data-title="[^"]+"/', $f3_td[0] ) ), 'F3: every cell of the voucher list carries data-title (column name on small screens)' );
vcap_acct_assert( (bool) preg_match( '/<td class="[^"]*\bactions\b[^"]*" data-title="Actions"/', $f3_list ) && ! str_contains( $f3_list, 'data-title="Download"' ), 'F3: the actions cell has the class "actions" and is named "Actions", not "Download"' );
vcap_acct_assert( str_contains( $f3_list, 'Personal – only the buyer can redeem it.' ) && ! str_contains( $f3_list, 'Personal, not transferable' ), 'U15: My Account names who may redeem the voucher (transferable_label), not a fixed "Personal, not transferable"' );
wp_set_current_user( $other );
ob_start();
vcap()->downloads->account_page( false );
$f3_empty = (string) ob_get_clean();
vcap_acct_assert( str_contains( $f3_empty, 'You have no vouchers yet.' ) && ! str_contains( $f3_empty, '<table' ), 'F3: a customer without vouchers sees an empty state instead of an empty table' );

// B15: My Account → Vouchers in pages of 20, the orders of a page loaded together (not one read per row).
$b15_product = vcap_acct_product( array() );
for ( $i = 0; $i < 21; $i++ ) {
	$b15_order           = vcap_acct_order( $b15_product, $buyer, $login . '@example.com' );
	$b15_v               = new VCAP\Voucher();
	$b15_v->code         = 'B15-' . strtoupper( wp_generate_password( 10, false, false ) );
	$b15_v->order_id     = $b15_order->get_id();
	$b15_v->order_item_id = 97000000 + $b15_order->get_id();
	$b15_v->product_id   = $b15_product->get_id();
	$b15_v->type         = 'voucher';
	$b15_v->usage_mode   = 'single';
	$b15_v->initial_amount = 10;
	$b15_v->balance      = 10;
	$b15_v->currency     = get_woocommerce_currency();
	$b15_v->status       = VCAP\Voucher::STATUS_ACTIVE;
	$b15_v->buyer_email  = $login . '@example.com';
	vcap_acct_assert( vcap()->vouchers->insert( $b15_v ) > 0, 'B15: (setup) voucher ' . $i . ' stored' );
}
$b15_all = vcap()->vouchers->for_customer( $buyer );
$b15_p2  = vcap()->vouchers->for_customer( $buyer, 5, 20 );
vcap_acct_assert( count( $b15_all ) >= 23 && array_column( array_slice( $b15_all, 20, 5 ), 'id' ) === array_column( $b15_p2, 'id' ), 'B15: for_customer( user, limit, offset ) returns the requested page of the list' );
wp_set_current_user( $buyer );
wp_cache_flush();
$b15_reads = 0;
$b15_log   = static function ( $q ) use ( &$b15_reads ) {
	$b15_reads += preg_match( '/FROM\s+`?\w*wc_orders`?\s/i', $q ) ? 1 : 0;
	return $q;
};
add_filter( 'query', $b15_log );
ob_start();
vcap()->downloads->account_page( false );
$b15_page1 = (string) ob_get_clean();
remove_filter( 'query', $b15_log );
vcap_acct_assert( 20 === substr_count( $b15_page1, 'vcap-download actions' ) && str_contains( $b15_page1, 'vcap_page=2' ) && ! str_contains( $b15_page1, 'Newer vouchers' ), 'B15: the first page shows 20 vouchers and links to the older ones' );
vcap_acct_assert( $b15_reads <= 10, 'B15: the orders of the page are read together, not once per voucher (' . $b15_reads . ' order queries for 20 rows)' );
$_GET[ VCAP\Downloads::PAGE_ARG ] = '2';
ob_start();
vcap()->downloads->account_page( false );
$b15_page2 = (string) ob_get_clean();
unset( $_GET[ VCAP\Downloads::PAGE_ARG ] );
vcap_acct_assert( count( $b15_all ) - 20 === substr_count( $b15_page2, 'vcap-download actions' ) && str_contains( $b15_page2, 'Newer vouchers' ) && ! str_contains( $b15_page2, 'vcap_page=3' ), 'B15: the second page shows the rest and links back' );
wp_set_current_user( 0 );

// G8: "My vouchers" block with showExtras = false leaves out store credit and referral code; with both blocks on a
// page each extra section is shown once. F3: the store credit history is a responsive table as well.
$g8_keep = array( 'vcap_referral_enabled' => get_option( 'vcap_referral_enabled', false ), 'vcap_store_credit' => get_option( 'vcap_store_credit', false ) );
update_option( 'vcap_referral_enabled', 'yes' );
update_option( 'vcap_store_credit', 'yes' );
wp_set_current_user( $buyer );
vcap()->store_credit->adjust( $buyer, 12, 'F3 test credit' );
$g8_top   = did_action( 'vcap_account_vouchers_top' );
$g8_plain = do_blocks( '<!-- wp:vcap/my-vouchers {"showExtras":false} /-->' );
vcap_acct_assert( $g8_top === did_action( 'vcap_account_vouchers_top' ) && ! str_contains( $g8_plain, 'vcap-store-credit' ) && ! str_contains( $g8_plain, 'vcap-referral' ) && str_contains( $g8_plain, 'vcap-vouchers' ), 'G8: the "My vouchers" block with showExtras = false shows the list only' );
$g8_page = do_blocks( '<!-- wp:vcap/my-vouchers /--><!-- wp:vcap/referral /--><!-- wp:vcap/my-vouchers /-->' );
vcap_acct_assert( 1 === substr_count( $g8_page, '<section class="vcap-referral"' ) && 1 === substr_count( $g8_page, '<section class="vcap-store-credit"' ), 'G8: store credit and referral code are shown once per page, also with a "Refer a friend" block and a second list' );
$g8_credit = substr( $g8_page, (int) strpos( $g8_page, '<section class="vcap-store-credit"' ) );
$g8_credit = substr( $g8_credit, 0, (int) strpos( $g8_credit, '</section>' ) );
preg_match_all( '/<td\b[^>]*>/', $g8_credit, $g8_td );
vcap_acct_assert( str_contains( $g8_credit, '<th scope="col">' ) && $g8_td[0] && count( $g8_td[0] ) === count( preg_grep( '/data-title="[^"]+"/', $g8_td[0] ) ), 'F3: the store credit history has column headers with scope and data-title on every cell' );
vcap_acct_assert( ! str_contains( $g8_page, 'onfocus' ) && str_contains( $g8_page, '<div class="wp-block-vcap-my-vouchers"' ), 'F16: blocks render with their block wrapper attributes, the referral link without an inline handler' );
foreach ( $g8_keep as $g8_option => $g8_value ) {
	false === $g8_value ? delete_option( $g8_option ) : update_option( $g8_option, $g8_value );
}

// F3: the gift card balance in the cart totals is not a second total (since U2 a full-width note row).
wc_load_cart();
WC()->cart->empty_cart();
$f3_goods = new WC_Product_Simple();
$f3_goods->set_name( 'F3 goods' );
$f3_goods->set_regular_price( '100' );
$f3_goods->set_virtual( true );
$f3_goods->save();
$f3_card_coupon = new WC_Coupon();
$f3_card_coupon->set_code( 'vcap-f3-card-' . strtolower( wp_generate_password( 8, false, false ) ) );
$f3_card_coupon->set_discount_type( 'fixed_cart' );
$f3_card_coupon->set_amount( 30 );
$f3_card_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$f3_card_coupon->save();
$f3_card                 = new VCAP\Voucher();
$f3_card->code           = wc_format_coupon_code( $f3_card_coupon->get_code() );
$f3_card->coupon_id      = $f3_card_coupon->get_id();
$f3_card->order_item_id  = 72000000 + $f3_card_coupon->get_id();
$f3_card->type           = 'giftcard';
$f3_card->usage_mode     = 'balance';
$f3_card->discount_type  = 'fixed_cart';
$f3_card->initial_amount = 30;
$f3_card->balance        = 30;
$f3_card->currency       = get_woocommerce_currency();
$f3_card->status         = VCAP\Voucher::STATUS_ACTIVE;
$f3_card->send_to        = 'buyer';
$f3_card->token          = VCAP\VoucherRepository::new_token();
$f3_card->id             = vcap()->vouchers->insert( $f3_card );
WC()->cart->add_to_cart( $f3_goods->get_id(), 1 );
WC()->cart->apply_coupon( $f3_card->code );
ob_start();
vcap()->coupons->cart_balance_notice();
$f3_row = (string) ob_get_clean();
// U2 turned the row into a full-width note that names the voucher and says what the amount means (no second total).
vcap_acct_assert( (bool) preg_match( '/<tr class="vcap-balance"><td colspan="2"><small>[^<]*' . preg_quote( $f3_card->code, '/' ) . '[^<]*<\/small><\/td><\/tr>/i', $f3_row ) && ! str_contains( $f3_row, '<th' ), 'F3/U2: the gift card balance in the cart totals is one self-describing note row spanning the table, not a second total' );
WC()->cart->empty_cart();
$f3_single_coupon = new WC_Coupon();
$f3_single_coupon->set_code( 'vcap-f3-single-' . strtolower( wp_generate_password( 8, false, false ) ) );
$f3_single_coupon->set_discount_type( 'fixed_cart' );
$f3_single_coupon->set_amount( 150 );
$f3_single_coupon->set_usage_limit( 1 );
$f3_single_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$f3_single_coupon->save();
$f3_single                 = clone $f3_card;
$f3_single->id             = 0;
$f3_single->code           = wc_format_coupon_code( $f3_single_coupon->get_code() );
$f3_single->coupon_id      = $f3_single_coupon->get_id();
$f3_single->order_item_id  = 72000000 + $f3_single_coupon->get_id();
$f3_single->type           = 'voucher';
$f3_single->usage_mode     = 'single';
$f3_single->initial_amount = 150;
$f3_single->balance        = 150;
$f3_single->token          = VCAP\VoucherRepository::new_token();
$f3_single->id             = vcap()->vouchers->insert( $f3_single );
WC()->cart->add_to_cart( $f3_goods->get_id(), 1 );
WC()->cart->apply_coupon( $f3_single->code );
WC()->cart->calculate_totals();
ob_start();
( new VCAP\ProductChecks() )->residual_rows();
$f3_residual = (string) ob_get_clean();
vcap_acct_assert( (bool) preg_match( '/<tr class="vcap-residual-warning"><td colspan="2" data-title="[^"]+">/', $f3_residual ), 'F3: the remaining-value warning row in the cart totals carries data-title' );
WC()->cart->empty_cart();
wc_clear_notices();
wp_set_current_user( 0 );

// H10: until delivery the buyer may correct the recipient email and the delivery date; logged; coupon follows.
// M12-16: the date only where the product lets the customer choose one (here the delivery date field).
$h10_product = vcap_acct_product(
	array(
		'transferable'   => 'no',
		'email_restrict' => 'none',
		'builtin_fields' => array( 'recipient_name', 'recipient_email', 'message', 'delivery_date' ),
	)
);
$h10_order   = vcap_acct_order( $h10_product, $buyer, $login . '@example.com' );
$h10_voucher = vcap()->issuer->issue_for_order( $h10_order )[0];
$h10_voucher                  = vcap()->vouchers->get( $h10_voucher->id );
$h10_voucher->recipient_email = 'anna@gmial.com';
$h10_voucher->send_to         = 'recipient';
vcap()->vouchers->update( $h10_voucher );
vcap()->coupons->refresh_email_restrictions( $h10_voucher, '' );
vcap()->delivery->reschedule( vcap()->vouchers->get( $h10_voucher->id ), gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
$h10_voucher = vcap()->vouchers->get( $h10_voucher->id );
wp_set_current_user( $other );
vcap_acct_assert( ! vcap()->downloads->customer_can_change( $h10_voucher ) && '' !== vcap()->downloads->change_delivery( $h10_voucher, 'anna@gmail.com', '' ), 'H10: another customer cannot change the gift' );
wp_set_current_user( $buyer );
vcap_acct_assert( VCAP\Voucher::STATUS_SCHEDULED === $h10_voucher->status && vcap()->downloads->customer_can_change( $h10_voucher ), 'H10: the buyer may change a scheduled gift' );
vcap_acct_assert( '' !== vcap()->downloads->change_delivery( $h10_voucher, 'not-an-email', '' ) && 'anna@gmial.com' === vcap()->vouchers->get( $h10_voucher->id )->recipient_email, 'H10: an invalid address is refused and nothing changes' );
$h10_day = wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS );
$h10_err = vcap()->downloads->change_delivery( $h10_voucher, 'anna@gmail.com', $h10_day );
$h10_new = vcap()->vouchers->get( $h10_voucher->id );
$h10_restrict = array_map( 'strtolower', ( new WC_Coupon( $h10_new->coupon_id ) )->get_email_restrictions() );
vcap_acct_assert( '' === $h10_err && 'anna@gmail.com' === $h10_new->recipient_email && in_array( 'anna@gmail.com', $h10_restrict, true ) && ! in_array( 'anna@gmial.com', $h10_restrict, true ), 'H10: the new recipient is stored and the coupon restriction follows it', array( $h10_err, $h10_restrict ) );
vcap_acct_assert( $h10_day === VCAP\LocalDate::from_utc( (string) $h10_new->delivery_at, 'Y-m-d' ) && VCAP\Voucher::STATUS_SCHEDULED === $h10_new->status, 'H10: the delivery is planned for the new date' );
$h10_log = VCAP\Tickets::last_event( vcap()->vouchers->events( $h10_new->id ), array( 'edited' ) );
vcap_acct_assert( $h10_log && str_contains( (string) $h10_log['note'], 'anna@gmial.com' ) && str_contains( (string) $h10_log['note'], 'anna@gmail.com' ) && $buyer === (int) $h10_log['user_id'], 'H10: the history records the change with old and new values and who made it' );
$h10_new->delivered_at = gmdate( 'Y-m-d H:i:s' );
$h10_new->status       = VCAP\Voucher::STATUS_ACTIVE;
vcap()->vouchers->update( $h10_new );
vcap_acct_assert( ! vcap()->downloads->customer_can_change( vcap()->vouchers->get( $h10_new->id ) ) && '' !== vcap()->downloads->change_delivery( $h10_new, 'x@example.com', '' ), 'H10: after delivery the gift can no longer be changed' );

// M12-16: a delivery date set by the shop (mode "date", presale) cannot be brought forward by the buyer.
$m16_shop_day = wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS );
$m16_product  = vcap_acct_product( array( 'delivery' => 'date', 'delivery_date' => $m16_shop_day, 'email_restrict' => 'none' ) );
$m16_voucher  = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $m16_product, $buyer, $login . '@example.com' ) )[0]->id );
$m16_before   = (string) $m16_voucher->delivery_at;
vcap_acct_assert( VCAP\Voucher::STATUS_SCHEDULED === $m16_voucher->status && $m16_shop_day === VCAP\LocalDate::from_utc( $m16_before, 'Y-m-d' ), 'M12-16: a presale voucher is scheduled for the shop date' );
vcap_acct_assert( vcap()->downloads->customer_can_change( $m16_voucher ) && ! vcap()->downloads->customer_can_change_date( $m16_voucher ) && ! vcap()->downloads->change_options( $m16_voucher )['date'], 'M12-16: without a customer date the date is not offered for change' );
$m16_err = vcap()->downloads->change_delivery( $m16_voucher, '', wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
vcap_acct_assert( '' !== $m16_err && $m16_before === (string) vcap()->vouchers->get( $m16_voucher->id )->delivery_at, 'M12-16: a presale date cannot be brought forward to tomorrow (' . $m16_err . ')' );
vcap_acct_assert( '' !== vcap()->downloads->change_delivery( $m16_voucher, '', '', array( 'send_now' => true ) ) && $m16_before === (string) vcap()->vouchers->get( $m16_voucher->id )->delivery_at, 'M12-16: nor sent right away' );
// With the delivery date field in mode "date": never before the shop date (product page and account), not after the expiry.
$m16_cfg = VCAP\ProductConfig::get( vcap_acct_product( array( 'delivery' => 'date', 'delivery_date' => $m16_shop_day, 'builtin_fields' => array( 'delivery_date' ) ) ) );
vcap_acct_assert( $m16_shop_day === VCAP\Delivery::earliest_customer_date( $m16_cfg ) && $m16_shop_day === VCAP\LocalDate::from_utc( (string) VCAP\Delivery::compute_time( $m16_cfg, array( 'delivery_date' => array( 'value' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) ) ), 'Y-m-d' ), 'M12-16: a customer date never comes before the shop date (earliest date and computed time)' );
$m16_late_product = vcap_acct_product( array( 'delivery' => 'date', 'delivery_date' => $m16_shop_day, 'builtin_fields' => array( 'delivery_date' ) ) );
$_POST['vcap_fields'] = array( 'delivery_date' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) );
$m16_errors = vcap()->fields->errors( $m16_late_product );
$_POST['vcap_fields'] = array( 'delivery_date' => wp_date( 'Y-m-d', time() + 25 * DAY_IN_SECONDS ) );
$m16_ok = vcap()->fields->errors( $m16_late_product );
unset( $_POST['vcap_fields'] );
vcap_acct_assert( 1 === count( $m16_errors ) && ! $m16_ok, 'M12-16: the product page refuses a date before the presale date and accepts a later one', array( $m16_errors, $m16_ok ) );
// Mode "customer": the minimum is today (as on the product page), the maximum the last day before the expiry.
$m16_cust = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( vcap_acct_product( array( 'delivery' => 'customer', 'expiry_mode' => 'days', 'expiry_days' => 30 ) ), $buyer, $login . '@example.com' ) )[0]->id );
vcap()->delivery->reschedule( $m16_cust, gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
$m16_cust = vcap()->vouchers->get( $m16_cust->id );
$m16_opts = vcap()->downloads->change_options( $m16_cust );
vcap_acct_assert( $m16_opts['date'] && wp_date( 'Y-m-d' ) === $m16_opts['min'] && $m16_opts['max'] < wp_date( 'Y-m-d', time() + 31 * DAY_IN_SECONDS ) && $m16_opts['send_now'], 'M12-16: with a customer date the form offers today up to the last day before the expiry', $m16_opts );
vcap_acct_assert( '' !== vcap()->downloads->change_delivery( $m16_cust, '', wp_date( 'Y-m-d', time() + 40 * DAY_IN_SECONDS ) ), 'M12-16: a date after the expiry is refused' );
vcap_acct_assert( '' === vcap()->downloads->change_delivery( $m16_cust, '', wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) ) && wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) === VCAP\LocalDate::from_utc( (string) vcap()->vouchers->get( $m16_cust->id )->delivery_at, 'Y-m-d' ), 'M12-16: a date in range is taken' );
// M12-82: the order's "What happens next" shows the changed date of the voucher, not the checkout value.
$m82_lines = VCAP\NextSteps::for_order( wc_get_order( $m16_cust->order_id ) );
vcap_acct_assert( 1 === count( $m82_lines ) && str_contains( $m82_lines[0]['text'], VCAP\LocalDate::from_utc( (string) vcap()->vouchers->get( $m16_cust->id )->delivery_at, vcap()->settings->date_format() ) ), 'M12-82: the order shows the delivery date of the voucher after a change', $m82_lines );

// M12-47: an empty email changes only the date; name and message are editable; "send now" delivers at once;
// a new address sets the routing of the product (send_to) – a gift entered "to me" goes to the recipient then.
$m47 = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( vcap_acct_product( array( 'delivery' => 'customer' ) ), $buyer, $login . '@example.com' ) )[0]->id );
vcap()->delivery->reschedule( $m47, gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
$m47 = vcap()->vouchers->get( $m47->id );
vcap_acct_assert( 'buyer' === $m47->send_to && '' === $m47->recipient_email, 'M12-47: a gift without recipient email is routed to the buyer' );
$m47_err = vcap()->downloads->change_delivery( $m47, 'friend@example.com', '', array( 'recipient_name' => 'Friend', 'message' => "Alles Gute\r\nzum Geburtstag" ) );
$m47     = vcap()->vouchers->get( $m47->id );
vcap_acct_assert( '' === $m47_err && 'friend@example.com' === $m47->recipient_email && 'both' === $m47->send_to && 'Friend' === $m47->recipient_name && "Alles Gute\nzum Geburtstag" === $m47->message && VCAP\Delivery::to_recipient( $m47 ), 'M12-47: the new recipient gets the routing of the product (recipient and buyer copy), name and message are changed', array( $m47_err, $m47->send_to ) );
vcap_acct_assert( '' === vcap()->downloads->change_delivery( $m47, '', '', array( 'send_now' => true ) ) && strtotime( (string) vcap()->vouchers->get( $m47->id )->delivery_at . ' UTC' ) <= time() + 5, 'M12-47: "send now" plans the delivery right away' );
wp_set_current_user( 0 );

// M12-47: "Confirm recipient email" must match; it is checked, never stored with the cart item.
$m47_confirm = vcap_acct_product( array( 'builtin_fields' => array( 'recipient_email', VCAP\Fields::CONFIRM_KEY ) ) );
$_POST['vcap_fields'] = array( 'recipient_email' => 'anna@example.com', VCAP\Fields::CONFIRM_KEY => 'anna@exmaple.com' );
$m47_mismatch = vcap()->fields->errors( $m47_confirm );
$_POST['vcap_fields'] = array( 'recipient_email' => 'anna@example.com', VCAP\Fields::CONFIRM_KEY => 'Anna@Example.com' );
$m47_match  = vcap()->fields->errors( $m47_confirm );
$m47_posted = vcap()->fields->collect_posted( $m47_confirm );
unset( $_POST['vcap_fields'] );
vcap_acct_assert( 1 === count( $m47_mismatch ) && ! $m47_match && isset( $m47_posted['recipient_email'] ) && ! isset( $m47_posted[ VCAP\Fields::CONFIRM_KEY ] ), 'M12-47: a mismatching confirmation is refused, a matching one accepted and not stored', array( $m47_mismatch, $m47_match, array_keys( $m47_posted ) ) );

// M12-47: signed "Correct the recipient" link (also for guests): before the delivery the address changes, after
// the delivery the voucher is reissued (old code invalid) and mailed to the new address; at most twice, unused only.
$m47_mails = array();
$m47_catch = static function ( $short, $atts ) use ( &$m47_mails ) {
	$m47_mails[] = (string) ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] );
	return true;
};
add_filter( 'pre_wp_mail', $m47_catch, 10, 2 );
$m47_fix = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, 0, 'guest-buyer@example.com' ) )[0]->id );
$m47_fix->recipient_email = 'wrong@exmaple.com';
$m47_fix->send_to         = 'recipient';
vcap()->vouchers->update( $m47_fix );
vcap()->delivery->reschedule( vcap()->vouchers->get( $m47_fix->id ), gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) );
$m47_fix  = vcap()->vouchers->get( $m47_fix->id );
$m47_args = array();
wp_parse_str( (string) wp_parse_url( vcap()->downloads->correction_url( $m47_fix ), PHP_URL_QUERY ), $m47_args );
vcap_acct_assert( isset( $m47_args['vcap_fix'] ) && null !== vcap()->downloads->voucher_of_correction_link( (string) $m47_args['vcap_fix'], (int) $m47_args['exp'], (string) $m47_args['sig'] ) && null === vcap()->downloads->voucher_of_correction_link( (string) $m47_args['vcap_fix'], (int) $m47_args['exp'] + 1, (string) $m47_args['sig'] ) && null === vcap()->downloads->voucher_of_correction_link( (string) $m47_args['vcap_fix'], time() - 10, (string) $m47_args['sig'] ), 'M12-47: the correction link is signed and time-limited (a changed or past expiry is refused)' );
$m47_code = $m47_fix->code;
vcap_acct_assert( '' === vcap()->downloads->correct_recipient( $m47_fix, 'right@example.com' ) && 'right@example.com' === vcap()->vouchers->get( $m47_fix->id )->recipient_email && $m47_code === vcap()->vouchers->get( $m47_fix->id )->code && ! $m47_mails, 'M12-47: before the delivery the address is corrected, the code stays and nothing is mailed yet' );
$m47_live = vcap()->vouchers->get( $m47_fix->id );
$m47_live->status       = VCAP\Voucher::STATUS_ACTIVE;
$m47_live->delivered_at = gmdate( 'Y-m-d H:i:s' );
$m47_live->delivery_at  = null;
vcap()->vouchers->update( $m47_live );
vcap_acct_assert( '' === vcap()->downloads->correct_recipient( $m47_live, 'third@example.com' ), 'M12-47: after the delivery the correction goes through' );
$m47_after = vcap()->vouchers->get( $m47_fix->id );
vcap_acct_assert( 'third@example.com' === $m47_after->recipient_email && $m47_after->code !== $m47_code && null === vcap()->vouchers->get_by_code( $m47_code ) && in_array( 'third@example.com', $m47_mails, true ) && 1 === vcap()->vouchers->count_event( $m47_fix->id, VCAP\Reissue::EVENT ), 'M12-47: after the delivery the voucher is reissued (old code invalid) and mailed to the new address', $m47_mails );
vcap_acct_assert( null === vcap()->downloads->voucher_of_correction_link( (string) $m47_args['vcap_fix'], (int) $m47_args['exp'], (string) $m47_args['sig'] ), 'M12-47: the link of the old code no longer works after the reissue' );
vcap_acct_assert( 2 === vcap()->vouchers->count_event( $m47_fix->id, VCAP\Downloads::FIX_EVENT ) && '' !== vcap()->downloads->correct_recipient( $m47_after, 'fourth@example.com' ) && '' === vcap()->downloads->correction_url( $m47_after ), 'M12-47: at most two corrections per voucher' );
$m47_used = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, 0, 'guest-buyer@example.com' ) )[0]->id );
$m47_used->recipient_email = 'x@example.com';
$m47_used->send_to         = 'recipient';
$m47_used->status          = VCAP\Voucher::STATUS_REDEEMED;
vcap()->vouchers->update( $m47_used );
vcap_acct_assert( '' !== vcap()->downloads->correction_refusal( $m47_used ) && '' === vcap()->downloads->correction_url( $m47_used ), 'M12-47: a used voucher cannot be redirected' );
ob_start();
$m47_mail = WC()->mailer()->get_emails()['VCAP_Email_Voucher_Buyer'];
$m47_mail->voucher = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, 0, 'guest-buyer@example.com' ) )[0]->id );
$m47_mail->voucher->recipient_email = 'friend@example.com';
$m47_mail->voucher->send_to         = 'both';
$m47_html = (string) $m47_mail->get_content_html();
ob_end_clean();
$m47_mail->voucher = null;
remove_filter( 'pre_wp_mail', $m47_catch, 10 );
vcap_acct_assert( str_contains( $m47_html, 'vcap_fix=' ), 'M12-47: the buyer copy of a gift carries the "Correct the recipient" link' );

// M12-18: My Account lists gifts received at the verified account address (not scheduled ones, no change or
// resend forms) and the guest orders of that address with the bought vouchers.
$m18_login = 'vcap_m18_' . strtolower( wp_generate_password( 6, false, false ) );
$m18_user  = (int) wp_insert_user( array( 'user_login' => $m18_login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $m18_login . '@example.com', 'role' => 'customer' ) );
$m18_mail  = $m18_login . '@example.com';
$m18_gift  = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, $buyer, $login . '@example.com' ) )[0]->id );
$m18_gift->recipient_email = $m18_mail;
$m18_gift->send_to         = 'recipient';
$m18_gift->status          = VCAP\Voucher::STATUS_ACTIVE;
$m18_gift->delivered_at    = gmdate( 'Y-m-d H:i:s' );
vcap()->vouchers->update( $m18_gift );
$m18_secret = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, $buyer, $login . '@example.com' ) )[0]->id );
$m18_secret->recipient_email = $m18_mail;
$m18_secret->send_to         = 'recipient';
$m18_secret->status          = VCAP\Voucher::STATUS_SCHEDULED;
$m18_secret->delivery_at     = gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS );
vcap()->vouchers->update( $m18_secret );
$m18_guest = vcap()->vouchers->get( vcap()->issuer->issue_for_order( vcap_acct_order( $personal, 0, $m18_mail ) )[0]->id );
update_user_meta( $m18_user, VCAP\PersonalVoucher::META_CHANGED, time() ); // Not grandfathered: no proof yet.
$m18_ids = static fn( array $list ) => array_map( static fn( $v ) => (int) $v->id, $list );
vcap_acct_assert( array() === vcap()->vouchers->received_by( VCAP\PersonalVoucher::verified_email( get_userdata( $m18_user ) ), $m18_user ) && ! in_array( $m18_guest->id, $m18_ids( vcap()->vouchers->for_customer( $m18_user, 20, 0, VCAP\PersonalVoucher::verified_email( get_userdata( $m18_user ) ) ) ), true ), 'M12-18: without a proven mailbox neither received gifts nor guest orders are listed' );
VCAP\PersonalVoucher::mark_verified( $m18_user );
$m18_verified = VCAP\PersonalVoucher::verified_email( get_userdata( $m18_user ) );
$m18_received = $m18_ids( vcap()->vouchers->received_by( $m18_verified, $m18_user ) );
vcap_acct_assert( in_array( $m18_gift->id, $m18_received, true ) && ! in_array( $m18_secret->id, $m18_received, true ) && ! in_array( $m18_guest->id, $m18_received, true ), 'M12-18: the delivered gift is listed as received, the scheduled one stays hidden', $m18_received );
vcap_acct_assert( in_array( $m18_guest->id, $m18_ids( vcap()->vouchers->for_customer( $m18_user, 20, 0, $m18_verified ) ), true ), 'M12-18: a guest order of the verified address is in the list of bought vouchers' );
wp_set_current_user( $m18_user );
ob_start();
vcap()->downloads->account_page( false );
$m18_html = (string) ob_get_clean();
wp_set_current_user( 0 );
vcap_acct_assert( str_contains( $m18_html, 'Received vouchers' ) && str_contains( $m18_html, esc_html( $m18_gift->code ) ) && ! str_contains( $m18_html, esc_html( $m18_secret->code ) ) && ! str_contains( $m18_html, 'name="vcap_change"' ) && ! str_contains( $m18_html, 'name="vcap_resend"' ), 'M12-18: My Account shows the received gift without change or resend forms' );

// M10/H11: the visible copy of voucher fields has a stable key; label and a formatted date are only for display.
$m10_order = wc_create_order();
$m10_item  = new WC_Order_Item_Product();
$m10_item->set_name( 'M10 voucher' );
( new VCAP\Fields() )->save_order_item(
	$m10_item,
	'm10',
	array(
		'vcap_fields' => array(
			'recipient_name' => array( 'label' => 'Recipient name', 'value' => 'Anna' ),
			'delivery_date'  => array( 'label' => 'Delivery date', 'value' => '2026-12-24' ),
		),
	),
	$m10_order
);
$m10_order->add_item( $m10_item );
$m10_order->save();
$m10_item = $m10_order->get_item( $m10_item->get_id() );
$m10_meta = array();
foreach ( $m10_item->get_formatted_meta_data() as $m10_row ) {
	$m10_meta[ $m10_row->key ] = array( wp_strip_all_tags( $m10_row->display_key ), trim( wp_strip_all_tags( $m10_row->display_value ) ) );
}
vcap_acct_assert( 'Anna' === $m10_item->get_meta( 'vcap_recipient_name' ) && '2026-12-24' === $m10_item->get_meta( 'vcap_delivery_date' ) && '' === $m10_item->get_meta( 'Recipient name' ), 'M10: the visible field copy is stored under a stable key (vcap_recipient_name), whatever the language of the order' );
vcap_acct_assert( array( 'Recipient name', 'Anna' ) === ( $m10_meta['vcap_recipient_name'] ?? null ) && array( 'Delivery date', wp_date( vcap()->settings->date_format(), (int) strtotime( '2026-12-24 12:00:00 ' . wp_timezone_string() ) ) ) === ( $m10_meta['vcap_delivery_date'] ?? null ), 'M10/H11: the order screen and emails show the field label and the date in the voucher date format', $m10_meta );
$h11_cart = ( new VCAP\Fields() )->display_item_data( array(), array( 'vcap_fields' => array( 'delivery_date' => array( 'label' => 'Delivery date', 'value' => '2026-12-24' ) ) ) );
vcap_acct_assert( wp_date( vcap()->settings->date_format(), (int) strtotime( '2026-12-24 12:00:00 ' . wp_timezone_string() ) ) === ( $h11_cart[0]['value'] ?? '' ), 'H11: the cart shows the delivery date in the voucher date format, not "2026-12-24"', $h11_cart );
vcap_acct_assert( 'Anna' === VCAP\Placeholders::replace( '{item_meta:vcap_recipient_name}', (function () use ( $m10_order, $m10_item ) { $v = new VCAP\Voucher(); $v->order_id = $m10_order->get_id(); $v->order_item_id = $m10_item->get_id(); return $v; })() ), 'M10: {item_meta:vcap_recipient_name} works in every language' );

// V10: email download links get a default lifetime of 90 days (a value saved before is kept) and carry the
// voucher's link version; "Reissue code" gives a new code and token, carries the remaining value over, deletes the
// old coupon and invalidates old links.
$v10_saved = get_option( 'vcap_download_link_days', null );
delete_option( 'vcap_download_link_days' );
$v10_args = array();
wp_parse_str( (string) wp_parse_url( vcap()->downloads->signed_url( vcap()->vouchers->get( $voucher->id ), true ), PHP_URL_QUERY ), $v10_args );
vcap_acct_assert( abs( (int) $v10_args['exp'] - ( time() + 90 * DAY_IN_SECONDS ) ) < 120, 'V10: email download links expire after 90 days when the setting was never saved' );
update_option( 'vcap_download_link_days', 0 );
wp_parse_str( (string) wp_parse_url( vcap()->downloads->signed_url( vcap()->vouchers->get( $voucher->id ), true ), PHP_URL_QUERY ), $v10_args );
vcap_acct_assert( '0' === (string) $v10_args['exp'], 'V10: a lifetime saved before (0 = never) is kept' );
null === $v10_saved ? delete_option( 'vcap_download_link_days' ) : update_option( 'vcap_download_link_days', $v10_saved );

$v10_product = vcap_acct_product( array( 'type' => 'giftcard', 'usage' => 'balance', 'email_restrict' => 'buyer' ) );
$v10_order   = vcap_acct_order( $v10_product, $buyer, $login . '@example.com' );
$v10_issued  = vcap()->issuer->issue_for_order( $v10_order );
$v10         = vcap()->vouchers->get( $v10_issued[0]->id );
$v10->status = VCAP\Voucher::STATUS_ACTIVE;
vcap()->vouchers->update( $v10 );
$v10_pay = vcap_acct_order( $v6_goods, $buyer, $login . '@example.com' ); // Paid partly with the card before the reissue.
vcap()->coupons->adjust_balance( $v10, -15, 'redeemed', 'V10 partial use', $v10_pay->get_id() );
$v10_pay->update_meta_data( '_vcap_redemptions', array( wc_format_coupon_code( $v10->code ) => 15.0 ) );
$v10_pay->save();
$v10         = vcap()->vouchers->get( $v10->id );
$v10_old     = array( 'code' => $v10->code, 'token' => $v10->token, 'coupon' => $v10->coupon_id, 'balance' => $v10->balance );
$v10_link    = array();
wp_parse_str( (string) wp_parse_url( vcap()->downloads->signed_url( $v10, true ), PHP_URL_QUERY ), $v10_link );
$v10_valid   = new ReflectionMethod( VCAP\Downloads::class, 'valid_guest_link' );
$v10_valid->setAccessible( true );
$v10_sign    = new ReflectionMethod( VCAP\Downloads::class, 'sign' );
$v10_sign->setAccessible( true );
vcap_acct_assert( true === $v10_valid->invoke( vcap()->downloads, $v10->token, (int) $v10_link['exp'], (string) $v10_link['sig'], vcap()->downloads->link_version( $v10 ) ) && 0 === vcap()->downloads->link_version( $v10 ), 'V10: a link of a voucher never reissued is valid (version 0 signs as before)' );

$v10_reissue = new VCAP\Reissue( vcap()->settings, vcap()->vouchers, vcap()->coupons );
$v10_void    = clone $v10;
$v10_void->status = VCAP\Voucher::STATUS_VOID;
vcap_acct_assert( '' !== $v10_reissue->refusal( $v10_void ) && '' === $v10_reissue->refusal( $v10 ), 'V10: void vouchers cannot be reissued, active ones can' );
wp_set_current_user( $buyer ); // Flash notices are per user.
$v10_ok  = ( new VCAP\Admin\VoucherAdmin( vcap()->vouchers ) )->reissue( $v10, false, 'Wrong address' );
$v10_new = vcap()->vouchers->get( $v10->id );
wp_set_current_user( 0 );
$v10_coupon = new WC_Coupon( $v10_new->coupon_id );
vcap_acct_assert( $v10_ok && $v10_new->code !== $v10_old['code'] && $v10_new->token !== $v10_old['token'] && $v10_new->coupon_id !== $v10_old['coupon'] && abs( $v10_new->balance - $v10_old['balance'] ) < 0.001 && VCAP\Voucher::STATUS_ACTIVE === $v10_new->status, 'V10: reissue gives a new code, token and coupon and keeps the remaining value and state' );
vcap_acct_assert( null === get_post( $v10_old['coupon'] ) && 0 === (int) wc_get_coupon_id_by_code( $v10_old['code'] ) && null === vcap()->vouchers->get_by_code( $v10_old['code'] ) && null === vcap()->vouchers->get_by_token( $v10_old['token'] ), 'V10: the old WooCommerce coupon is deleted – the old code and token are unknown' );
vcap_acct_assert( strtolower( $v10_new->code ) === strtolower( $v10_coupon->get_code() ) && abs( (float) $v10_coupon->get_amount() - $v10_old['balance'] ) < 0.001 && array( $login . '@example.com' ) === array_values( $v10_coupon->get_email_restrictions() ) && 'yes' === $v10_coupon->get_meta( '_vcap_voucher' ), 'V10: the new coupon carries the remaining value and the restrictions over' );
$v10_events = wp_list_pluck( vcap()->vouchers->events( $v10->id ), 'event' );
$v10_notes  = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $v10_order->get_id() ) ), 'content' ) );
vcap_acct_assert( in_array( 'reissued', $v10_events, true ) && str_contains( $v10_notes, 'was reissued as ' . VCAP\Downloads::masked_code( $v10_new->code ) ) && ! str_contains( strtoupper( $v10_notes ), strtoupper( $v10_new->code ) ), 'V10: the reissue is in the voucher history and (masked) in an order note' );
vcap_acct_assert( 1 === vcap()->downloads->link_version( $v10_new ) && false === $v10_valid->invoke( vcap()->downloads, $v10_new->token, (int) $v10_link['exp'], (string) $v10_sign->invoke( vcap()->downloads, $v10_new->token, (int) $v10_link['exp'], 0 ), vcap()->downloads->link_version( $v10_new ) ), 'V10: after a reissue the link version is 1 – a signature without it is refused' );
wp_parse_str( (string) wp_parse_url( vcap()->downloads->signed_url( $v10_new, true ), PHP_URL_QUERY ), $v10_link );
vcap_acct_assert( true === $v10_valid->invoke( vcap()->downloads, $v10_new->token, (int) $v10_link['exp'], (string) $v10_link['sig'], vcap()->downloads->link_version( $v10_new ) ), 'V10: fresh links (My Account, new email) work with the new token' );
$v10_discounts = new WC_Discounts( vcap_acct_order( $v6_goods, $buyer, $login . '@example.com' ) );
vcap_acct_assert( is_wp_error( $v10_discounts->is_coupon_valid( new WC_Coupon( $v10_old['code'] ) ) ), 'V10: the old code can no longer be redeemed' );
$v10_by_old = VCAP\Reissue::voucher_of_previous_code( vcap()->vouchers, $v10_old['code'] );
vcap_acct_assert( $v10_by_old && $v10_by_old->id === $v10->id, 'V10: refunds of orders paid with the old code still find the voucher (previous code on the new coupon)' );
vcap()->coupons->restore_order( wc_get_order( $v10_pay->get_id() ) );
vcap_acct_assert( abs( vcap()->vouchers->get( $v10->id )->balance - ( $v10_old['balance'] + 15 ) ) < 0.001, 'V10: cancelling an order paid with the old code books the value back onto the reissued voucher' );

// AP-Q1-2: a balance card in an unpaid order is not reissued – also once that order's hold was released.
$q12_issued = vcap()->issuer->issue_for_order( vcap_acct_order( $v10_product, $buyer, $login . '@example.com' ) );
$q12        = vcap()->vouchers->get( $q12_issued[0]->id );
$q12->status = VCAP\Voucher::STATUS_ACTIVE;
vcap()->vouchers->update( $q12 );
$q12_pending = vcap_acct_order( $v6_goods, $buyer, $login . '@example.com' );
vcap_acct_assert( true === $q12_pending->apply_coupon( $q12->code ), 'AP-Q1-2: the card is applied to an unpaid order' );
$q12_pending = wc_get_order( $q12_pending->get_id() );
vcap()->coupons->reserve_order( $q12_pending );
$q12_held = (float) vcap()->vouchers->get( $q12->id )->balance;
$q12_pending->set_status( 'failed' );
$q12_pending->save(); // The failed payment releases the hold; the order can still be paid through its pay link.
$q12       = vcap()->vouchers->get( $q12->id );
$q12_full  = (float) $q12->balance;
$q12_try   = $v10_reissue->reissue( $q12, 'Reported stolen' );
vcap_acct_assert( $q12_full > $q12_held && 0.0 === vcap()->coupons->open_holds( $q12 ) && $q12_try instanceof WP_Error && 'vcap_reissue_refused' === $q12_try->get_error_code() && $q12->code === vcap()->vouchers->get( $q12->id )->code, 'AP-Q1-2: with the hold released, the card in the unpaid (failed) order is still refused for a reissue' );

// AP-Q1-2: an old code on an unpaid order that is paid after a reissue is never charged (second line of defence:
// here the shop counts no unpaid statuses, so the reissue goes through).
$q12_no_open = static fn() => array();
add_filter( 'vcap_open_order_statuses', $q12_no_open );
$q12_new = $v10_reissue->reissue( vcap()->vouchers->get( $q12->id ), 'Reported stolen' );
remove_filter( 'vcap_open_order_statuses', $q12_no_open );
vcap_acct_assert( $q12_new instanceof VCAP\Voucher && $q12_new->code !== $q12->code, 'AP-Q1-2: (statuses filtered away) the card is reissued' );
$q12_retry_error = '';
try {
	vcap()->coupons->reserve_order( wc_get_order( $q12_pending->get_id() ) ); // The pay page's reserve (reserve_retry_order()).
} catch ( Exception $e ) {
	$q12_retry_error = $e->getMessage();
}
vcap_acct_assert( str_contains( $q12_retry_error, 'replaced by a new code' ) && ! str_contains( strtoupper( $q12_retry_error ), strtoupper( $q12->code ) ) && abs( (float) vcap()->vouchers->get( $q12->id )->balance - $q12_full ) < 0.001, 'AP-Q1-2: a pay retry with the old code is refused and reserves nothing on the reissued card (' . $q12_retry_error . ')' );
$q12_paid = wc_get_order( $q12_pending->get_id() );
$q12_paid->set_status( 'processing' );
$q12_paid->save(); // E.g. a gateway callback that marks the order paid anyway.
$q12_paid = wc_get_order( $q12_pending->get_id() );
vcap_acct_assert( abs( (float) vcap()->vouchers->get( $q12->id )->balance - $q12_full ) < 0.001 && VCAP\CouponManager::has_shortfall( $q12_paid ) && 'on-hold' === $q12_paid->get_status(), 'AP-Q1-2: paid anyway, the old code is not charged – the order is held with a shortfall for review' );

echo "Voucher Captain account checks passed.\n";
