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

echo "Voucher Captain account checks passed.\n";
