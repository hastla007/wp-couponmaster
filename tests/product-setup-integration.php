<?php
/**
 * Product setup and admin UX (third expert review, work package D):
 * - "customer chooses the amount" counts steps from 0 without a minimum (25/50/100 can be bought), presets are
 *   checked against range and step;
 * - a fixed expiry date is required, a past one makes the product unpurchasable (also variations), Site Health
 *   lists such products; "global default" ignores a leftover number of days;
 * - gift cards default to a stored balance, a single-use voucher worth more than the cart says the rest is lost;
 * - one "who may redeem" choice (read compatibly from "transferable"), "recipient only" without address falls back
 *   to the buyer, the label follows the restriction, a new recipient email updates the coupon;
 * - "Buyer only" hides the recipient email field unless it restricts redemption;
 * - vouchers are issued only for paid orders; unpaid statuses are not offered as issue status;
 * - an empty imported code batch can stop sales;
 * - balance adjustments and resending are validated and reported; onboarding creates the redeem page.
 *
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/product-setup-integration.php
 */

use VCAP\Admin\Flash;
use VCAP\CustomAmount;
use VCAP\ProductChecks;
use VCAP\ProductConfig;

function vcap_ps_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_ps_product( string $name, array $meta, string $price = '40' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_virtual( true );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_vcap_enabled', 'yes' );
	foreach ( $meta as $key => $value ) {
		$p->update_meta_data( '_vcap_' . $key, $value );
	}
	$p->save();
	return $p;
}

/** Paid order for a product (vouchers are issued on "processing"); $fields: personalisation of the line. */
function vcap_ps_buy( WC_Product $product, array $fields = array(), string $email = 'ps-buyer@example.com' ): array {
	$order   = wc_create_order();
	$item_id = $order->add_product( $product, 1 );
	if ( $fields ) {
		$item = $order->get_item( $item_id );
		$item->update_meta_data( '_vcap_fields', $fields );
		$item->save();
	}
	$order->set_billing_email( $email );
	$order->calculate_totals();
	$order->payment_complete( 'txn-ps-' . $product->get_id() . '-' . wp_generate_password( 6, false, false ) );
	return array( wc_get_order( $order->get_id() ), vcap()->vouchers->for_order( $order->get_id() ) );
}

wc_load_cart();
WC()->cart->empty_cart();
$keep_terms = get_option( 'vcap_default_terms', false );
$keep_rule  = get_option( 'vcap_default_expiry_rule', false );

/* ---- 1. Custom amount ------------------------------------------------------------------------------------ */

$free = vcap_ps_product( 'PS custom amount', array( 'type' => 'giftcard', 'amount_source' => 'custom', 'custom_step' => '1', 'custom_presets' => '25, 50, 100' ), '' );
$cfg  = ProductConfig::get( $free );
vcap_ps_assert( null === CustomAmount::error_for( $cfg, '25' ) && null === CustomAmount::error_for( $cfg, '50' ) && null === CustomAmount::error_for( $cfg, '100' ), 'without a minimum, 25/50/100 match a step of 1 (steps count from 0)' );
vcap_ps_assert( null !== CustomAmount::error_for( $cfg, '25.5' ) && null !== CustomAmount::error_for( $cfg, '0' ), 'off-grid and zero amounts are refused' );
vcap_ps_assert( array() === CustomAmount::config_errors( $cfg ), 'the presets fit the configuration' );
vcap_ps_assert( (bool) WC()->cart->add_to_cart( $free->get_id(), 1, 0, array(), array( 'vcap_amount' => '25' ) ), 'a 25 € gift card without minimum can be added to the cart' );
wc_clear_notices();
vcap_ps_assert( ! WC()->cart->add_to_cart( $free->get_id(), 1, 0, array(), array( 'vcap_amount' => '25.5' ) ), 'an amount off the step grid is refused at add to cart' );
wc_clear_notices();
WC()->cart->empty_cart();

$ranged = vcap_ps_product( 'PS custom amount min', array( 'amount_source' => 'custom', 'custom_min' => '10', 'custom_max' => '200', 'custom_step' => '5', 'custom_presets' => '7, 25, 300' ), '' );
$cfg    = ProductConfig::get( $ranged );
vcap_ps_assert( null === CustomAmount::error_for( $cfg, '25' ) && null !== CustomAmount::error_for( $cfg, '12' ) && null !== CustomAmount::error_for( $cfg, '205' ), 'with a minimum of 10 and step 5: 25 is fine, 12 and 205 are refused' );
$errors = CustomAmount::config_errors( $cfg );
vcap_ps_assert( 1 === count( $errors ) && false !== strpos( $errors[0], '7' ) && false !== strpos( $errors[0], '300' ), 'presets outside the range are reported', $errors );
$ranged->update_meta_data( '_vcap_custom_min', '300' );
$ranged->save();
vcap_ps_assert( count( CustomAmount::config_errors( ProductConfig::get( wc_get_product( $ranged->get_id() ) ) ) ) >= 1 && 'error' === ( ProductChecks::problems( wc_get_product( $ranged->get_id() ) )[0]['level'] ?? '' ), 'minimum above maximum is an error on the product screen' );

/* ---- 2. Fixed expiry date --------------------------------------------------------------------------------- */

$yesterday = wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$next_year = wp_date( 'Y-m-d', time() + 365 * DAY_IN_SECONDS );
$past      = vcap_ps_product( 'PS expired voucher', array( 'expiry_mode' => 'date', 'expiry_date' => $yesterday ) );
vcap_ps_assert( 'date_past' === ProductChecks::unsellable_reason( $past ) && ! $past->is_purchasable(), 'a voucher product whose fixed expiry date has passed cannot be bought' );
vcap_ps_assert( ! WC()->cart->add_to_cart( $past->get_id(), 1 ), 'it cannot be added to the cart' );
wc_clear_notices();
$problems = ProductChecks::shop_problems();
vcap_ps_assert( in_array( $past->get_id(), array_map( static fn( $p ) => $p->get_id(), $problems['expired'] ), true ), 'the expired product is listed for the admin notice' );
$health = ( new ProductChecks() )->test_voucher_products();
vcap_ps_assert( 'recommended' === $health['status'] && false !== strpos( $health['description'], 'PS expired voucher' ), 'Site Health names the expired voucher product', $health );
$missing = vcap_ps_product( 'PS date missing', array( 'expiry_mode' => 'date', 'expiry_date' => '' ) );
vcap_ps_assert( 'date_missing' === ProductChecks::unsellable_reason( $missing ) && ! $missing->is_purchasable(), '"Fixed date" without a date cannot be bought (never "never expires" by accident)' );
$future = vcap_ps_product( 'PS future date', array( 'expiry_mode' => 'date', 'expiry_date' => $next_year ) );
vcap_ps_assert( $future->is_purchasable(), 'a future fixed date can be bought' );
list( , $future_vouchers ) = vcap_ps_buy( $future );
vcap_ps_assert( $future_vouchers && get_date_from_gmt( (string) $future_vouchers[0]->expires_at, 'Y-m-d' ) === $next_year, 'the voucher expires at the end of the fixed date', $future_vouchers ? $future_vouchers[0]->expires_at : null );

$variable = new WC_Product_Variable();
$variable->set_name( 'PS expired variable' );
$variable->set_status( 'publish' );
$variable->update_meta_data( '_vcap_enabled', 'yes' );
$variable->update_meta_data( '_vcap_expiry_mode', 'date' );
$variable->update_meta_data( '_vcap_expiry_date', $yesterday );
$variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_regular_price( '30' );
$variation->set_virtual( true );
$variation->set_status( 'publish' );
$variation->save();
vcap_ps_assert( ! wc_get_product( $variation->get_id() )->is_purchasable(), 'variations of an expired voucher product cannot be bought either' );

/* ---- 7. Expiry "global default" ignores a leftover number of days ---------------------------------------- */

update_option( 'vcap_default_expiry_rule', 'statutory' );
$base     = strtotime( '2026-01-10 12:00:00 UTC' );
$default  = ProductConfig::expiry_for( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'default', 'expiry_days' => '30' ) ), $base, 'DE' );
vcap_ps_assert( $default === ProductConfig::statutory_expiry( $base, 'DE' ), '"Global default" uses the statutory validity, not a hidden number of days', $default );
$no_days  = ProductConfig::expiry_for( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'days', 'expiry_days' => '' ) ), $base, 'DE' );
vcap_ps_assert( null !== $no_days && $no_days === ProductConfig::statutory_expiry( $base, 'DE' ), '"Days after purchase" without days falls back to the global default instead of "never"', $no_days );
vcap_ps_assert( 'days_missing' === ProductConfig::expiry_problem( array_merge( ProductConfig::DEFAULTS, array( 'expiry_mode' => 'days', 'expiry_days' => '0' ) ) ), 'missing days are reported' );

/* ---- 3. Gift card usage and lost remaining value ---------------------------------------------------------- */

$card = vcap_ps_product( 'PS gift card', array( 'type' => 'giftcard', 'amount_source' => 'fixed', 'amount' => '50' ) );
vcap_ps_assert( 'balance' === ProductConfig::get( $card )['usage'], 'a gift card without a saved usage keeps a stored balance' );
list( , $card_vouchers ) = vcap_ps_buy( $card );
vcap_ps_assert( $card_vouchers && 'balance' === $card_vouchers[0]->usage_mode, 'the issued gift card is multi-use with balance' );
$old_card = vcap_ps_product( 'PS gift card single', array( 'type' => 'giftcard', 'usage' => 'single' ) );
$messages = wp_list_pluck( ProductChecks::problems( $old_card ), 'message' );
vcap_ps_assert( 'single' === ProductConfig::get( $old_card )['usage'] && (bool) array_filter( $messages, static fn( $m ) => false !== strpos( $m, 'Single use' ) ), 'a saved "single use" gift card keeps its setting but is warned about', $messages );

$single = vcap_ps_product( 'PS single voucher', array( 'type' => 'voucher', 'usage' => 'single', 'amount_source' => 'fixed', 'amount' => '50' ) );
list( , $single_vouchers ) = vcap_ps_buy( $single );
$normal = new WC_Product_Simple();
$normal->set_name( 'PS normal product' );
$normal->set_regular_price( '20' );
$normal->set_status( 'publish' );
$normal->save();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $normal->get_id(), 1 );
vcap_ps_assert( $single_vouchers && WC()->cart->apply_coupon( $single_vouchers[0]->code ), 'the single-use voucher is applied to a 20 € cart' );
WC()->cart->calculate_totals();
$losses = ProductChecks::residual_losses( WC()->cart );
vcap_ps_assert( 1 === count( $losses ) && abs( 50.0 - $losses[0]['value'] ) < 0.01 && $losses[0]['used'] < 50.0, 'a 50 € single-use voucher on a 20 € cart is reported as losing value', $losses );
ob_start();
( new ProductChecks() )->residual_rows();
$row = (string) ob_get_clean();
vcap_ps_assert( false !== strpos( $row, $single_vouchers[0]->code ) && false !== strpos( $row, 'vcap-residual-warning' ), 'the cart totals show the warning' );
WC()->cart->empty_cart();
wc_clear_notices();

/* ---- 5. Who may redeem ------------------------------------------------------------------------------------ */

$legacy = vcap_ps_product( 'PS legacy personal', array( 'transferable' => 'no', 'email_restrict' => 'none' ) );
$cfg    = ProductConfig::get( $legacy );
vcap_ps_assert( 'both' === $cfg['email_restrict'] && 'no' === $cfg['transferable'], 'a product saved with "not transferable" reads as "buyer or recipient"' );
$recipient_only = vcap_ps_product( 'PS recipient only', array( 'email_restrict' => 'recipient' ) );
list( , $no_recipient ) = vcap_ps_buy( $recipient_only, array(), 'ps-fallback@example.com' );
$coupon = new WC_Coupon( $no_recipient[0]->coupon_id );
vcap_ps_assert( array( 'ps-fallback@example.com' ) === array_values( $coupon->get_email_restrictions() ), '"recipient only" without a recipient address is limited to the buyer (never unrestricted)', $coupon->get_email_restrictions() );
vcap_ps_assert( false === $no_recipient[0]->transferable && false !== strpos( $no_recipient[0]->transferable_label(), 'only the buyer' ), 'the label says who can redeem', $no_recipient[0]->transferable_label() );
list( , $with_recipient ) = vcap_ps_buy( $recipient_only, array( 'recipient_email' => array( 'label' => 'Recipient email', 'value' => 'ps-friend@example.com' ) ) );
$voucher = $with_recipient[0];
vcap_ps_assert( array( 'ps-friend@example.com' ) === array_values( ( new WC_Coupon( $voucher->coupon_id ) )->get_email_restrictions() ) && false !== strpos( $voucher->transferable_label(), 'named recipient' ), 'with a recipient address only the recipient may redeem, and the label says so' );

// Admin changes the recipient email: the coupon follows; an invalid address is refused.
$admin   = new VCAP\Admin\VoucherAdmin( vcap()->vouchers );
$_POST   = array( 'recipient_name' => 'Friend', 'recipient_email' => 'ps-new-friend@example.com', 'message' => '', 'send_to' => 'recipient', 'expires_at' => get_date_from_gmt( (string) $voucher->expires_at, 'Y-m-d' ), 'delivery_at' => '' );
vcap_ps_assert( $admin->save( $voucher ), 'the voucher is saved with a new recipient email' );
vcap_ps_assert( array( 'ps-new-friend@example.com' ) === array_values( ( new WC_Coupon( $voucher->coupon_id ) )->get_email_restrictions() ), 'the coupon restriction is updated to the new address' );
$_POST['recipient_email'] = 'not-an-address';
vcap_ps_assert( ! $admin->save( vcap()->vouchers->get( $voucher->id ) ) && 'ps-new-friend@example.com' === vcap()->vouchers->get( $voucher->id )->recipient_email, 'an invalid email is refused instead of being emptied silently' );
$flash = Flash::pending();
vcap_ps_assert( 'error' === ( end( $flash )['type'] ?? '' ), 'the refusal is shown as an error notice', $flash );
delete_transient( 'vcap_flash_' . get_current_user_id() );

/* ---- 4. Resend with result ------------------------------------------------------------------------------- */

$mail_ok = static fn() => true;
add_filter( 'pre_wp_mail', $mail_ok, 999 );
$buyer_only = vcap()->vouchers->get( $voucher->id );
$buyer_only->send_to = 'buyer';
vcap()->vouchers->update_columns( $buyer_only, array( 'send_to' ) );
vcap_ps_assert( $admin->resend( $buyer_only, 'recipient' ) && 'recipient' === vcap()->vouchers->get( $voucher->id )->send_to, '"Email recipient" on a buyer-only voucher sends it and switches "Send to" to the recipient' );
remove_filter( 'pre_wp_mail', $mail_ok, 999 );
$nobody = vcap()->vouchers->get( $no_recipient[0]->id );
vcap_ps_assert( ! $admin->resend( $nobody, 'recipient' ), 'without a recipient address nothing is reported as sent' );
$flash = Flash::pending();
vcap_ps_assert( 'error' === ( end( $flash )['type'] ?? '' ) && false !== strpos( (string) end( $flash )['message'], 'recipient email' ), 'the reason is shown', $flash );
delete_transient( 'vcap_flash_' . get_current_user_id() );

/* ---- 10. Adjust balance ----------------------------------------------------------------------------------- */

$gift = vcap()->vouchers->get( $card_vouchers[0]->id );
$_POST = array( 'mode' => 'subtract', 'amount' => '80', 'note' => '' );
$admin->adjust( $gift );
$flash = Flash::pending();
vcap_ps_assert( 50.0 === (float) vcap()->vouchers->get( $gift->id )->balance && false !== strpos( (string) ( end( $flash )['message'] ?? '' ), '80' ), 'deducting more than the remaining value is refused with a message', $flash );
$_POST = array( 'mode' => 'add', 'amount' => '-5', 'note' => '' );
$admin->adjust( $gift );
vcap_ps_assert( 50.0 === (float) vcap()->vouchers->get( $gift->id )->balance, 'a negative amount is refused' );
$_POST = array( 'mode' => 'set', 'amount' => '70', 'note' => '' );
$admin->adjust( $gift );
vcap_ps_assert( 50.0 === (float) vcap()->vouchers->get( $gift->id )->balance, '"Set to" above the face value needs a confirmation' );
$_POST = array( 'mode' => 'subtract', 'amount' => '20', 'note' => 'In store' );
$admin->adjust( $gift );
$flash = Flash::pending();
vcap_ps_assert( 30.0 === (float) vcap()->vouchers->get( $gift->id )->balance && 'success' === ( end( $flash )['type'] ?? '' ) && false !== strpos( (string) end( $flash )['message'], '30' ), 'a valid deduction is booked and the new remaining value is shown', $flash );
$_POST = array();
delete_transient( 'vcap_flash_' . get_current_user_id() );

/* ---- 6. Recipient field with "Buyer only" ----------------------------------------------------------------- */

$defs = VCAP\Fields::builtin_definitions( array_merge( ProductConfig::DEFAULTS, array( 'send_to' => 'buyer', 'email_restrict' => 'none' ) ) );
vcap_ps_assert( ! isset( $defs['recipient_email'] ) && isset( $defs['recipient_name'] ), '"Buyer only" does not ask for a recipient email nobody would receive' );
$defs = VCAP\Fields::builtin_definitions( array_merge( ProductConfig::DEFAULTS, array( 'send_to' => 'buyer', 'email_restrict' => 'both' ) ) );
vcap_ps_assert( isset( $defs['recipient_email'] ) && false !== strpos( $defs['recipient_email']['tooltip'], 'not to this address' ), 'when the address restricts redemption the field stays, with an honest hint' );

/* ---- 8. Issue only paid orders ---------------------------------------------------------------------------- */

$hold = wc_create_order();
$hold->add_product( $single, 1 );
$hold->set_billing_email( 'ps-hold@example.com' );
$hold->calculate_totals();
$hold->set_status( 'on-hold' );
$hold->save();
vcap()->issuer->on_status( $hold->get_id(), $hold );
vcap_ps_assert( ! vcap()->vouchers->for_order( $hold->get_id() ) && ! VCAP\Issuer::payment_ok( $hold ), 'an unpaid order (on hold) issues no voucher, even when its status is an issue status' );
$allow = static fn() => false;
add_filter( 'vcap_issue_requires_payment', $allow );
vcap()->issuer->on_status( $hold->get_id(), $hold );
remove_filter( 'vcap_issue_requires_payment', $allow );
vcap_ps_assert( (bool) vcap()->vouchers->for_order( $hold->get_id() ), 'the filter can allow issuing before payment' );
$fields  = vcap()->settings->fields( '' );
$options = array();
foreach ( $fields as $field ) {
	if ( 'vcap_issue_statuses' === ( $field['id'] ?? '' ) ) {
		$options = $field['options'];
	}
}
vcap_ps_assert( isset( $options['processing'] ) && ! isset( $options['pending'] ) && ! isset( $options['failed'] ) && ( ! isset( $options['on-hold'] ) || in_array( 'on-hold', (array) get_option( 'vcap_issue_statuses', array() ), true ) ), 'unpaid statuses are not offered as issue status', array_keys( $options ) );

/* ---- 8b. Offline payments (cash on delivery, bank transfer, cheque) wait for the money ------------------- */

/** Order for $single paid with an offline gateway, created unpaid. */
function vcap_ps_offline_order( WC_Product $product, string $method, string $email ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->set_payment_method( $method );
	$order->set_payment_method_title( 'cod' === $method ? 'Cash on delivery' : 'Direct bank transfer' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

$cod = vcap_ps_offline_order( $single, 'cod', 'ps-cod@example.com' );
$cod->update_status( 'processing', 'Payment to be made upon delivery.' ); // What the COD gateway does at checkout.
$cod = wc_get_order( $cod->get_id() );
vcap_ps_assert( $cod->is_paid() && ! VCAP\Issuer::payment_ok( $cod ) && ! vcap()->vouchers->for_order( $cod->get_id() ), 'cash on delivery: "Processing" is not paid, no voucher is issued' );
vcap_ps_assert( '' !== (string) $cod->get_meta( VCAP\Issuer::AWAITING_PAYMENT ) && (bool) array_filter( wc_get_order_notes( array( 'order_id' => $cod->get_id() ) ), static fn( $n ) => false !== strpos( $n->content, 'Vouchers are not issued yet' ) ), 'the order remembers that its vouchers wait and says so in a note' );
$cod->update_status( 'completed' );
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $cod->get_id() ) ), 'cash on delivery: the voucher is issued when the order is completed' );
wc_get_order( $cod->get_id() )->update_status( 'completed' );
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $cod->get_id() ) ), 'a repeated completion issues nothing more' );

$cod_paid = vcap_ps_offline_order( $single, 'cod', 'ps-cod-paid@example.com' );
$cod_paid->update_status( 'processing' );
vcap_ps_assert( ! vcap()->vouchers->for_order( $cod_paid->get_id() ), 'cash on delivery: no voucher before the payment' );
wc_get_order( $cod_paid->get_id() )->payment_complete( 'cod-collected-1' ); // Already "Processing": WooCommerce fires woocommerce_payment_complete_order_status_processing.
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $cod_paid->get_id() ) ) && 'payment_complete' === wc_get_order( $cod_paid->get_id() )->get_meta( VCAP\Issuer::PAYMENT_CONFIRMED ), 'a payment recorded later (payment_complete) issues the waiting voucher' );
$cheque = vcap_ps_offline_order( $single, 'cheque', 'ps-cheque@example.com' );
$cheque->update_status( 'on-hold' );
wc_get_order( $cheque->get_id() )->payment_complete( 'cheque-cashed-1' ); // On hold: the regular woocommerce_payment_complete.
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $cheque->get_id() ) ), 'cheque: a recorded payment issues the voucher' );

$bacs = vcap_ps_offline_order( $single, 'bacs', 'ps-bacs@example.com' );
$bacs->update_status( 'on-hold', 'Awaiting BACS payment' );
vcap_ps_assert( ! vcap()->vouchers->for_order( $bacs->get_id() ), 'bank transfer on hold: no voucher' );
wc_get_order( $bacs->get_id() )->update_status( 'processing', 'Money arrived.', true ); // By hand (order screen, bulk action).
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $bacs->get_id() ) ) && 'manual' === wc_get_order( $bacs->get_id() )->get_meta( VCAP\Issuer::PAYMENT_CONFIRMED ), 'bank transfer: setting "Processing" by hand confirms the payment and issues the voucher' );

$invoice = vcap_ps_offline_order( $single, 'vcap-test-invoice', 'ps-invoice@example.com' );
$offline = static fn( $methods ) => array_merge( (array) $methods, array( 'vcap-test-invoice' ) );
add_filter( 'vcap_offline_payment_methods', $offline );
$invoice->update_status( 'processing' );
vcap_ps_assert( ! vcap()->vouchers->for_order( $invoice->get_id() ), 'the filter "vcap_offline_payment_methods" adds gateways (e.g. invoice) to the rule' );
remove_filter( 'vcap_offline_payment_methods', $offline );

// "Completed" not an issue status: the completion still issues what waited for the money.
$late = vcap_ps_offline_order( $single, 'cod', 'ps-cod-late@example.com' );
$late->update_status( 'processing' );
$hooks = array();
foreach ( array( 'on_status', 'on_completed' ) as $method ) {
	if ( has_action( 'woocommerce_order_status_completed', array( vcap()->issuer, $method ) ) ) {
		$hooks[] = $method;
		remove_action( 'woocommerce_order_status_completed', array( vcap()->issuer, $method ), 20 );
	}
}
wc_get_order( $late->get_id() )->update_status( 'completed' );
vcap_ps_assert( ! vcap()->vouchers->for_order( $late->get_id() ), '(without the completed status hooks nothing is issued)' );
vcap()->issuer->on_completed( $late->get_id() );
foreach ( $hooks as $method ) {
	add_action( 'woocommerce_order_status_completed', array( vcap()->issuer, $method ), 20, 'on_status' === $method ? 3 : 2 );
}
vcap_ps_assert( 1 === count( vcap()->vouchers->for_order( $late->get_id() ) ), 'the completion hook issues the vouchers that waited for the payment' );
$desc     = '';
$keep_cod = get_option( 'woocommerce_cod_settings', false );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_virtual' => 'yes' ) );
$health = ( new VCAP\Health() )->test_offline_payment();
$fields = vcap()->settings->fields( '' );
foreach ( $fields as $field ) {
	if ( 'vcap_issue_statuses' === ( $field['id'] ?? '' ) ) {
		$desc = (string) $field['desc'];
	}
}
vcap_ps_assert( VCAP\Issuer::cod_for_virtual() && 'recommended' === $health['status'] && false !== strpos( $desc, 'Cash on delivery is offered for virtual products' ), 'cash on delivery for virtual products is pointed out in the settings and in Site Health' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_virtual' => 'no' ) );
vcap_ps_assert( ! VCAP\Issuer::cod_for_virtual() && 'good' === ( new VCAP\Health() )->test_offline_payment()['status'], 'without cash on delivery for virtual products there is no hint' );
false === $keep_cod ? delete_option( 'woocommerce_cod_settings' ) : update_option( 'woocommerce_cod_settings', $keep_cod );

/* ---- 13. Empty code batch --------------------------------------------------------------------------------- */

$batch = 'ps-batch-' . strtolower( wp_generate_password( 5, false, false ) );
vcap()->code_pool->add( strtoupper( $batch ) . '-1', $batch );
ProductChecks::flush();
$pooled = vcap_ps_product( 'PS pooled voucher', array( 'code_batch' => $batch, 'code_pool_empty' => 'stop', 'code_pool_low' => '1' ) );
vcap_ps_assert( $pooled->is_purchasable(), 'a product with free codes can be bought' );
vcap_ps_assert( (bool) array_filter( ProductChecks::problems( $pooled ), static fn( $p ) => 'warning' === $p['level'] ), 'one free code left is reported as running low' );
list( , $pooled_vouchers ) = vcap_ps_buy( $pooled );
ProductChecks::flush();
vcap_ps_assert( $pooled_vouchers && strtoupper( $batch ) . '-1' === $pooled_vouchers[0]->code, 'the imported code is used' );
$pooled = wc_get_product( $pooled->get_id() );
vcap_ps_assert( 'codes_empty' === ProductChecks::unsellable_reason( $pooled ) && ! $pooled->is_purchasable(), 'with "stop selling" an empty batch makes the product unpurchasable' );
$pooled->update_meta_data( '_vcap_code_pool_empty', 'random' );
$pooled->save();
vcap_ps_assert( wc_get_product( $pooled->get_id() )->is_purchasable(), 'with "random codes" it can still be bought' );

/* ---- 14. Customer delivery date: coupled with the delivery, never after the expiry --------------------------- */

// Saving: the field with "Immediately" switches to "On a date chosen by the customer"; with a fixed date it is switched off.
$coupled = vcap_ps_product( 'PS delivery date immediate', array() );
$_POST   = array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_delivery' => 'immediate', '_vcap_builtin_fields' => array( 'recipient_name', 'delivery_date', 'delivery_date_required' ) );
delete_transient( 'vcap_flash_' . get_current_user_id() );
( new VCAP\ProductSettings() )->save( $coupled );
$coupled->save();
$coupled = wc_get_product( $coupled->get_id() );
vcap_ps_assert( 'customer' === $coupled->get_meta( '_vcap_delivery' ) && in_array( 'delivery_date_required', (array) $coupled->get_meta( '_vcap_builtin_fields' ), true ), 'the delivery date field with "Immediately" switches the delivery to the customer date' );
vcap_ps_assert( in_array( 'info', array_column( Flash::pending(), 'type' ), true ), 'the shop is told that the delivery was switched' );
$fixed_day = vcap_ps_product( 'PS delivery date fixed', array() );
$_POST     = array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_delivery' => 'date', '_vcap_delivery_date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ), '_vcap_builtin_fields' => array( 'message', 'delivery_date' ) );
( new VCAP\ProductSettings() )->save( $fixed_day );
$fixed_day->save();
$fixed_day = wc_get_product( $fixed_day->get_id() );
vcap_ps_assert( 'date' === $fixed_day->get_meta( '_vcap_delivery' ) && array( 'message' ) === array_values( (array) $fixed_day->get_meta( '_vcap_builtin_fields' ) ) && in_array( 'warning', array_column( Flash::pending(), 'type' ), true ), 'with a fixed delivery date the field is switched off, with a warning' );
$_POST = array();
delete_transient( 'vcap_flash_' . get_current_user_id() );

// Stored before the fix (field on, delivery "Immediately"): the customer's date is used and printed, and the product screen warns.
$legacy = vcap_ps_product( 'PS delivery date legacy', array( 'delivery' => 'immediate', 'builtin_fields' => array( 'recipient_name', 'delivery_date' ), 'expiry_mode' => 'never' ) );
vcap_ps_assert( in_array( 'warning', array_column( ProductChecks::problems( $legacy ), 'level' ), true ), 'the product screen warns that the field and the delivery do not match' );
$wish_day = wp_date( 'Y-m-d', time() + 12 * DAY_IN_SECONDS );
list( , $legacy_vouchers ) = vcap_ps_buy( $legacy, array( 'delivery_date' => array( 'label' => 'Delivery date', 'value' => $wish_day ) ) );
$legacy_voucher = $legacy_vouchers[0] ?? null;
vcap_ps_assert( $legacy_voucher && VCAP\Voucher::STATUS_SCHEDULED === $legacy_voucher->status && $wish_day === get_date_from_gmt( (string) $legacy_voucher->delivery_at, 'Y-m-d' ), 'the voucher is delivered on the date the customer chose, not right away', $legacy_voucher ? $legacy_voucher->delivery_at : null );
vcap_ps_assert( VCAP\Placeholders::values( $legacy_voucher )['delivery_date'] === wp_date( vcap()->settings->date_format(), (int) strtotime( (string) $legacy_voucher->delivery_at . ' UTC' ) ), 'the voucher prints the date that is really used' );

// Validity 10 days: a delivery date in 20 days is refused (classic form and Store API) and the field offers no later date.
$short = vcap_ps_product( 'PS delivery after expiry', array( 'delivery' => 'customer', 'expiry_mode' => 'days', 'expiry_days' => 10 ) );
$last  = VCAP\Delivery::latest_delivery_date( ProductConfig::get( $short ), time() );
vcap_ps_assert( wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) === $last, 'the last possible delivery date is the expiry day', $last );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'delivery_date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) ) );
// The product form runs this filter before WC()->cart->add_to_cart() (WC_Form_Handler), which itself does not.
vcap_ps_assert( false === apply_filters( 'woocommerce_add_to_cart_validation', true, $short->get_id(), 1 ), 'a delivery date after the expiry is refused at add to cart' );
$notices = implode( ' ', array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, wc_get_notices( 'error' ) ) );
vcap_ps_assert( str_contains( $notices, wp_date( (string) get_option( 'date_format' ), (int) strtotime( $last . ' 12:00:00 ' . wp_timezone_string() ) ) ), 'the message names the last possible date', $notices );
wc_clear_notices();
$_POST = array( 'vcap_fields' => array( 'delivery_date' => wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
vcap_ps_assert( array() === vcap()->fields->errors( $short ), 'a delivery date within the validity is accepted' );
$GLOBALS['product'] = $short;
ob_start();
vcap()->fields->render( 'before_button' );
$form = (string) ob_get_clean();
vcap_ps_assert( str_contains( $form, 'max="' . $last . '"' ), 'the date field offers no day after the last possible one' );
$_POST = array();
if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'vcap_fields', array( 'delivery_date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) ) );
	$refused = false;
	try {
		vcap()->fields->store_api_validate( $short, $request );
	} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
		$refused = 'vcap_invalid_voucher_fields' === $e->getErrorCode();
	}
	vcap_ps_assert( $refused, 'the Store API (cart block) refuses it as well' );
	$request->set_param( 'vcap_fields', array() );
	vcap()->fields->store_api_validate( $short, $request ); // Leaves no stale Store API values behind.
}
WC()->cart->empty_cart();

// "X days after purchase" / fixed delivery date after the expiry: an error on the product screen.
$late_delay = vcap_ps_product( 'PS delay after expiry', array( 'delivery' => 'delay', 'delivery_delay_days' => 45, 'expiry_mode' => 'days', 'expiry_days' => 30 ) );
vcap_ps_assert( in_array( 'error', array_column( ProductChecks::problems( $late_delay ), 'level' ), true ), 'a delivery delay longer than the validity is reported as an error' );
$ok_delay = vcap_ps_product( 'PS delay within validity', array( 'delivery' => 'delay', 'delivery_delay_days' => 5, 'expiry_mode' => 'days', 'expiry_days' => 30 ) );
vcap_ps_assert( ! in_array( 'error', array_column( ProductChecks::problems( $ok_delay ), 'level' ), true ), 'a delay within the validity is fine' );

/* ---- 15. Discount setup of sold vouchers (percentage, fixed product discount) ------------------------------ */

$pct_price = vcap_ps_product( 'PS percent from price', array( 'discount_type' => 'percent', 'amount_source' => 'price' ), '120' );
vcap_ps_assert( 'discount_invalid' === ProductChecks::unsellable_reason( $pct_price ) && ! $pct_price->is_purchasable(), 'a percentage taken from the product price (120 € = 120 %) cannot be bought' );
vcap_ps_assert( (bool) array_filter( ProductChecks::problems( $pct_price ), static fn( $p ) => 'error' === $p['level'] ) && in_array( $pct_price->get_id(), array_map( static fn( $p ) => $p->get_id(), ProductChecks::shop_problems()['invalid'] ), true ), 'the product screen and the shop notices name the invalid discount setup' );
$per_item = vcap_ps_product( 'PS fixed product discount', array( 'discount_type' => 'fixed_product', 'amount_source' => 'fixed', 'amount' => '50' ), '50' );
vcap_ps_assert( 'discount_invalid' === ProductChecks::unsellable_reason( $per_item ) && ! $per_item->is_purchasable(), '"Fixed product discount" (the value per item and unit) cannot be bought for a sold voucher' );
$pct_ok = vcap_ps_product( 'PS percent 20', array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '20' ), '30' );
vcap_ps_assert( '' === ProductChecks::unsellable_reason( $pct_ok ) && $pct_ok->is_purchasable(), 'a fixed percentage of 20 can be bought' );

// Issuing an order of such a product (e.g. placed before the setup changed): no voucher row, a note on the order.
list( $bad_order, $bad_vouchers ) = vcap_ps_buy( $pct_price );
$bad_notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $bad_order->get_id() ) ), 'content' ) );
vcap_ps_assert( ! $bad_vouchers && false !== strpos( $bad_notes, 'discount setup is invalid' ), 'an order of a product with an invalid discount setup issues nothing and says why', $bad_notes );
$over = vcap_ps_product( 'PS percent 150', array( 'discount_type' => 'percent', 'amount_source' => 'fixed', 'amount' => '150' ), '30' );
list( , $over_vouchers ) = vcap_ps_buy( $over );
vcap_ps_assert( ! $over_vouchers, 'a percentage above 100 is refused before any voucher is inserted' );

// WooCommerce refuses the coupon (WC_Data_Exception): the voucher row is rolled back, the slot is free again.
$refuse = static function () {
	throw new WC_Data_Exception( 'vcap_test_refused', 'Refused by the test' );
};
add_filter( 'vcap_coupon_before_save', $refuse );
list( $exc_order, $exc_vouchers ) = vcap_ps_buy( $pct_ok );
remove_filter( 'vcap_coupon_before_save', $refuse );
$exc_notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $exc_order->get_id() ) ), 'content' ) );
vcap_ps_assert( ! $exc_vouchers && false !== strpos( $exc_notes, 'Refused by the test' ) && false !== strpos( $exc_notes, 'could not be created' ), 'a coupon WooCommerce refuses rolls the voucher back with a note (no voucher without coupon)', $exc_notes );
$retry = vcap()->issuer->issue_for_order( wc_get_order( $exc_order->get_id() ), true );
vcap_ps_assert( 1 === count( $retry ) && 'percent' === ( new WC_Coupon( $retry[0]->coupon_id ) )->get_discount_type() && 20.0 === (float) ( new WC_Coupon( $retry[0]->coupon_id ) )->get_amount(), 'the next issuing run creates the voucher (20 % coupon)' );

// Saving the product form: an invalid combination keeps the previous discount setup.
$form = vcap_ps_product( 'PS form discount', array( 'type' => 'voucher', 'discount_type' => 'fixed_cart', 'amount_source' => 'price' ), '80' );
$_POST = array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_discount_type' => 'percent', '_vcap_amount_source' => 'price', '_vcap_amount' => '' );
( new VCAP\ProductSettings() )->save( $form );
$form->save();
$_POST = array();
$form  = wc_get_product( $form->get_id() );
vcap_ps_assert( 'fixed_cart' === $form->get_meta( '_vcap_discount_type' ) && 'price' === $form->get_meta( '_vcap_amount_source' ) && $form->is_purchasable(), 'saving "percentage from the product price" keeps the previous discount setup' );
vcap_ps_assert( class_exists( 'WC_Admin_Meta_Boxes' ) || false !== strpos( (string) wp_json_encode( Flash::pending() ), 'not saved' ), 'the product screen says why it was not saved' );
$_POST = array( '_vcap_enabled' => 'yes', '_vcap_type' => 'voucher', '_vcap_discount_type' => 'percent', '_vcap_amount_source' => 'fixed', '_vcap_amount' => '25' );
( new VCAP\ProductSettings() )->save( $form );
$form->save();
$_POST = array();
$form  = wc_get_product( $form->get_id() );
vcap_ps_assert( 'percent' === $form->get_meta( '_vcap_discount_type' ) && '25' === (string) $form->get_meta( '_vcap_amount' ) && $form->is_purchasable(), 'a fixed percentage of 25 is saved' );

/* ---- 12. Onboarding ----------------------------------------------------------------------------------------- */

$keep_page = get_option( 'vcap_redeem_page_id', false );
$page      = VCAP\Admin\Onboarding::create_page( 'redeem_page' );
vcap_ps_assert( $page > 0 && $page === (int) get_option( 'vcap_redeem_page_id' ) && VCAP\Shortcodes::page_has_redeem_form( $page ) && VCAP\Admin\Onboarding::steps()['redeem_page']['done'], 'the checklist creates the redeem page and selects it' );
wp_delete_post( $page, true );
false === $keep_page ? delete_option( 'vcap_redeem_page_id' ) : update_option( 'vcap_redeem_page_id', $keep_page );
delete_transient( 'vcap_redeem_page_guess' );

false === $keep_terms ? delete_option( 'vcap_default_terms' ) : update_option( 'vcap_default_terms', $keep_terms );
false === $keep_rule ? delete_option( 'vcap_default_expiry_rule' ) : update_option( 'vcap_default_expiry_rule', $keep_rule );
echo "Voucher Captain product setup checks passed.\n";
