<?php
/**
 * EU VAT and accounting fixes of the 12-expert audit (package C, TAX.md): default "Multi-purpose vouchers as
 * payment" and its warnings (M12-03), tickets with a cash value (M12-10), top-up VAT treatment and T7 value
 * (M12-12), gross single-use multi-purpose vouchers in net-price shops (M12-27), single-purpose rate/country
 * (M12-28), journal columns and bookings (M12-29), voucher payments as order meta (M12-30), vouchers without a
 * sale (M12-42), GDPR erasure of history notes (M12-66), keeping the rest of a single-use voucher (M12-68) and the
 * DATEV export (M12-69).
 *   wp eval-file tests/tax-accounting-integration.php
 */

use VCAP\Ledger;
use VCAP\PaymentVouchers;
use VCAP\ProductConfig;
use VCAP\TaxAccounting;
use VCAP\TopUp;
use VCAP\Voucher;

function vcap_ta_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

function vcap_ta_product( string $treatment, string $type = 'voucher', string $usage = 'single', string $price = '50', array $meta = array() ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( "TA {$type} {$treatment}" );
	$product->set_regular_price( $price );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->update_meta_data( '_vcap_type', $type );
	$product->update_meta_data( '_vcap_discount_type', 'fixed_cart' );
	$product->update_meta_data( '_vcap_usage', $usage );
	$product->update_meta_data( '_vcap_tax_treatment', $treatment );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( $key, $value );
	}
	$product->save();
	return $product;
}

/** A sold voucher row (and its coupon) without going through an order. */
function vcap_ta_voucher( string $type, string $usage, int $product_id, float $amount, array $props = array() ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-ta-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $amount );
	if ( 'single' === $usage ) {
		$coupon->set_usage_limit( 1 );
	}
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 71000000 + $coupon->get_id();
	$v->product_id     = $product_id;
	$v->type           = $type;
	$v->usage_mode     = $usage;
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $amount;
	$v->balance        = $amount;
	$v->currency       = get_woocommerce_currency();
	$v->status         = Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	foreach ( $props as $key => $value ) {
		$v->$key = $value;
	}
	$v->id = vcap()->vouchers->insert( $v );
	return $v;
}

function vcap_ta_events( Voucher $v ): array {
	return vcap()->vouchers->events( $v->id );
}

$keep = array(
	'calc'      => get_option( 'woocommerce_calc_taxes' ),
	'incl'      => get_option( 'woocommerce_prices_include_tax' ),
	'mpv'       => get_option( PaymentVouchers::OPTION, false ),
	'country'   => get_option( 'woocommerce_default_country' ),
	'based'     => get_option( 'woocommerce_tax_based_on' ),
	'allowed'   => get_option( 'woocommerce_allowed_countries' ),
	'specific'  => get_option( 'woocommerce_specific_allowed_countries' ),
	'credit'    => get_option( 'vcap_store_credit' ),
);
update_option( 'woocommerce_default_country', 'AT' );
update_option( 'woocommerce_tax_based_on', 'billing' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
$rates   = array();
$rates[] = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'AT', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'USt', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
$rates[] = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'AT', 'tax_rate_state' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'USt 10', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => 'reduced-rate' ) );
$goods = new WC_Product_Simple();
$goods->set_name( 'TA goods 20 %' );
$goods->set_regular_price( '120' );
$goods->set_virtual( true );
$goods->save();
$goods_reduced = new WC_Product_Simple();
$goods_reduced->set_name( 'TA goods 10 %' );
$goods_reduced->set_regular_price( '110' );
$goods_reduced->set_tax_class( 'reduced-rate' );
$goods_reduced->set_virtual( true );
$goods_reduced->save();

/* ---- M12-03: default and warnings ------------------------------------------------------------------- */

// An existing shop (schema version stored) keeps a missing option = off; a new installation starts with "yes".
delete_option( PaymentVouchers::OPTION );
VCAP\Install::activate_site();
vcap_ta_assert( false === get_option( PaymentVouchers::OPTION, false ) && ! PaymentVouchers::enabled(), 'M12-03: an existing shop that reactivates the plugin keeps its setting (missing = off)' );
$stored_version = get_option( VCAP\Install::OPTION_DB_VERSION );
delete_option( VCAP\Install::OPTION_DB_VERSION );
VCAP\Install::activate_site();
vcap_ta_assert( 'yes' === get_option( PaymentVouchers::OPTION ) && PaymentVouchers::enabled() && $stored_version === get_option( VCAP\Install::OPTION_DB_VERSION ), 'M12-03: a new installation starts with "Multi-purpose vouchers as payment" on', get_option( PaymentVouchers::OPTION ) );

$mpv_product = vcap_ta_product( 'auto', 'giftcard', 'balance' );
$mpv_product->set_tax_status( 'none' );
$mpv_product->save();
update_option( PaymentVouchers::OPTION, 'no' );
$conflict = ProductConfig::tax_status_conflict( $mpv_product );
vcap_ta_assert( is_array( $conflict ) && ! empty( $conflict['untaxed'] ) && 'taxable' === $conflict['expected'] && false !== strpos( $conflict['message'], 'Multi-purpose vouchers as payment' ) && false !== strpos( $conflict['message'], 'keep this product taxable' ) && false === strpos( $conflict['message'], 'Set the tax status to "None"' ), 'M12-03: setting off + multi-purpose product "None": the warning says "turn the setting on, or keep the product taxable"', $conflict );
$health = ( new VCAP\Health() )->test_tax_status();
vcap_ta_assert( 'critical' === $health['status'] && false !== strpos( $health['description'], $mpv_product->get_name() ), 'M12-03: Site Health is critical for multi-purpose products with tax status "None" while the setting is off', $health['status'] );
$mpv_product->set_tax_status( 'taxable' );
$mpv_product->save();
vcap_ta_assert( null === ProductConfig::tax_status_conflict( $mpv_product ), 'M12-03: setting off + multi-purpose product kept taxable: no warning (VAT at the sale, price reduction at the redemption)' );
update_option( PaymentVouchers::OPTION, 'yes' );
$conflict = ProductConfig::tax_status_conflict( $mpv_product );
vcap_ta_assert( is_array( $conflict ) && 'none' === $conflict['expected'] && empty( $conflict['untaxed'] ), 'M12-03: setting on: a taxable multi-purpose product is told to use tax status "None"', $conflict );
vcap_ta_assert( 'critical' !== ( new VCAP\Health() )->test_tax_status()['status'], 'M12-03: with the setting on, Site Health is not critical' );
$steps = VCAP\Admin\Onboarding::steps();
vcap_ta_assert( isset( $steps['vat'] ) && false !== strpos( $steps['vat']['label'], 'Multi-purpose vouchers as payment' ), 'M12-03: the onboarding checklist has a VAT hint for gift cards' );

/* ---- M12-10: tickets with a cash value ---------------------------------------------------------------- */

$ticket_product = vcap_ta_product( 'auto', 'ticket', 'single', '30', array( '_vcap_amount_source' => 'fixed', '_vcap_amount' => '30' ) );
$ticket         = vcap_ta_voucher( 'ticket', 'single', $ticket_product->get_id(), 30 );
$free_ticket    = vcap_ta_voucher( 'ticket', 'single', $ticket_product->get_id(), 0 );
vcap_ta_assert( $ticket->has_cash_value() && ! $free_ticket->has_cash_value() && vcap_ta_voucher( 'voucher', 'single', 0, 10 )->has_cash_value(), 'M12-10: Voucher::has_cash_value(): a ticket with a value has one, a ticket without value none, every other voucher one' );
$payments = new PaymentVouchers( vcap()->vouchers );
vcap_ta_assert( $payments->applies( new WC_Coupon( $ticket->coupon_id ) ) && vcap()->coupons->is_payment_means( new WC_Coupon( $ticket->coupon_id ) ), 'M12-10: a ticket with a cash value pays after tax and is a means of payment like a value voucher' );
vcap()->vouchers->log( $ticket->id, 'redeemed', 30.0, 0, 'TA ticket' );
$today   = wp_date( 'Y-m-d' );
$journal = ( new Ledger( vcap()->vouchers ) )->journal( $today, $today );
vcap_ta_assert( in_array( $ticket->code, array_column( $journal, 'code' ), true ), 'M12-10: the redemption of a ticket with a cash value is in the journal (Ledger::VALUE_VOUCHERS)' );
$liability_codes = array_column( iterator_to_array( ( new Ledger( vcap()->vouchers ) )->liability_rows( $today ), false ), 'code' );
vcap_ta_assert( in_array( $free_ticket->code, $liability_codes, true ) === false, 'M12-10: a ticket without value stays out of the liability' );

/* ---- M12-12: top-ups ---------------------------------------------------------------------------------- */

vcap_ta_assert( array( 60.0, false ) === TopUp::line_value( array( 'amount_source' => 'price', 'per_item' => 1 ), 1, 50.0, 10.0, false, '', 'single' ) && array( 50.0, false ) === TopUp::line_value( array( 'amount_source' => 'price', 'per_item' => 1 ), 1, 50.0, 10.0, false, '', 'payment' ), 'M12-12: net-price shop: a single-purpose top-up sold with VAT adds the gross 60 (T7 like a new card), a multi-purpose one 50' );
$spv_card_product = vcap_ta_product( 'single', 'giftcard', 'balance' );
$spv_card         = vcap_ta_voucher( 'giftcard', 'balance', $spv_card_product->get_id(), 40 );
$mpv_topup        = vcap_ta_product( 'auto', 'giftcard', 'balance', '25', array( TopUp::PRODUCT_META => 'yes' ) );
vcap_ta_assert( '' !== TopUp::treatment_mismatch( $spv_card, TopUp::product_treatment( $mpv_topup ) ) && '' === TopUp::treatment_mismatch( $spv_card, 'single' ), 'M12-12: a multi-purpose top-up does not fit a single-purpose card' );
wc_clear_notices();
$added = apply_filters( 'woocommerce_add_to_cart_validation', true, $mpv_topup->get_id(), 1, 0, array(), array( 'vcap_topup_code' => $spv_card->code ) );
$notes = wc_get_notices( 'error' );
vcap_ta_assert( false === $added && $notes && false !== strpos( wp_json_encode( $notes ), 'treated differently for VAT' ), 'M12-12: adding that top-up to the cart is refused with a clear message', $notes );
wc_clear_notices();
$topup_order = wc_create_order();
$topup_item  = new WC_Order_Item_Product();
$topup_item->set_product( $mpv_topup );
$topup_item->set_quantity( 1 );
$topup_item->set_subtotal( '25' );
$topup_item->set_total( '25' );
$topup_item->add_meta_data( TopUp::ITEM_META, $spv_card->code, true );
$topup_order->add_item( $topup_item );
$topup_order->set_billing_email( 'ta-topup@example.com' );
$topup_order->save();
$per_item = apply_filters( 'vcap_vouchers_per_item', 1, $topup_item, ProductConfig::get( $mpv_topup ) );
vcap_ta_assert( 0 === (int) $per_item && '' !== (string) wc_get_order_item_meta( $topup_item->get_id(), TopUp::HELD_META, true ) && abs( 40.0 - (float) vcap()->vouchers->get( $spv_card->id )->balance ) < 0.001, 'M12-12: a paid top-up of another VAT treatment is held, never booked onto the card' );

/* ---- M12-27: single-use multi-purpose voucher in a net-price shop ------------------------------------ */

update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( PaymentVouchers::OPTION, 'no' );
wc_load_cart();
WC()->customer->set_billing_country( 'AT' );
WC()->customer->set_shipping_country( 'AT' );
$value_product = vcap_ta_product( 'auto' );
$single_mpv    = vcap_ta_voucher( 'voucher', 'single', $value_product->get_id(), 100 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 2 ); // 240 net + 48 VAT.
WC()->cart->apply_coupon( $single_mpv->code );
WC()->cart->calculate_totals();
$saved = (float) WC()->cart->get_coupon_discount_amount( $single_mpv->code, false );
vcap_ta_assert( abs( 100.0 - $saved ) < 0.011, 'M12-27: net prices, setting off: a 100 single-use multi-purpose voucher saves 100 gross (net + VAT), not 120', $saved );
WC()->cart->empty_cart();
update_option( 'woocommerce_prices_include_tax', 'yes' );

/* ---- M12-28: single-purpose vouchers only for the VAT rate and country of their sale -------------------- */

$spv_product = vcap_ta_product( 'single', 'voucher', 'single', '50' );
$spv_buy     = wc_create_order();
$spv_buy->add_product( $spv_product, 1 );
$spv_buy->set_billing_email( 'ta-spv@example.com' );
$spv_buy->set_billing_country( 'AT' );
$spv_buy->calculate_totals();
$spv_buy->payment_complete( 'txn-ta-spv' );
$spv_vouchers = vcap()->vouchers->for_order( $spv_buy->get_id() );
$spv          = $spv_vouchers ? $spv_vouchers[0] : null;
$spv_sale = $spv ? ProductConfig::sale_tax( $spv ) : null;
vcap_ta_assert( $spv_sale && abs( 2000 - $spv_sale['rate_bp'] ) <= 1 && 'AT' === $spv_sale['country'], 'M12-28: a single-purpose voucher sold in AT with 20 % records its sale VAT', $spv ? ProductConfig::sale_tax( $spv ) : null );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods_reduced->get_id(), 1 );
wc_clear_notices();
$applied = WC()->cart->apply_coupon( $spv->code );
$notes   = wp_json_encode( wc_get_notices( 'error' ) );
vcap_ta_assert( ! $applied && false !== strpos( (string) $notes, 'single-purpose voucher was sold with 20' ), 'M12-28: refused for goods with another VAT rate, with a clear message', $notes );
wc_clear_notices();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
$applied = WC()->cart->apply_coupon( $spv->code );
WC()->cart->calculate_totals();
$lines = array();
foreach ( WC()->cart->get_cart() as $values ) {
	$lines[ $values['product_id'] ] = round( (float) $values['line_subtotal'] - (float) $values['line_total'], 2 );
}
vcap_ta_assert( $applied && 0.0 === ( $lines[ $goods_reduced->get_id() ] ?? -1 ) && ( $lines[ $goods->get_id() ] ?? 0 ) > 0, 'M12-28: in a mixed cart it discounts only the goods with the VAT rate and country of its sale', $lines );
WC()->cart->empty_cart();
vcap_ta_assert( '' !== ProductConfig::single_purpose_warning( $spv_product ), 'M12-28: a single-purpose product in a shop that sells to several countries is warned about' );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'AT' ) );
WC()->countries = new WC_Countries();
vcap_ta_assert( '' === ProductConfig::single_purpose_warning( $spv_product ), 'M12-28: no warning when the shop sells to one country only' );
update_option( 'woocommerce_allowed_countries', $keep['allowed'] );
update_option( 'woocommerce_specific_allowed_countries', $keep['specific'] );
WC()->countries = new WC_Countries();

/* ---- M12-29: journal columns and bookings --------------------------------------------------------------- */

$header = Ledger::journal_header();
vcap_ta_assert( in_array( 'VAT treatment', $header, true ) && in_array( 'Order amount', $header, true ), 'M12-29: the journal has the columns "VAT treatment" and "Order amount"' );
$issued = current( array_filter( vcap_ta_events( $spv ), static fn( $e ) => 'issued' === $e['event'] ) );
vcap_ta_assert( array( 'treatment' => 'single', 'sold' => true ) === ProductConfig::parse_snapshot( (string) $issued['note'] ), 'M12-29: the VAT treatment at issue is kept with the "issued" entry', $issued['note'] );
update_post_meta( $spv_product->get_id(), '_vcap_tax_treatment', 'payment' ); // Changed later: the journal keeps the treatment of the sale.
$spv_row = current( array_filter( ( new Ledger( vcap()->vouchers ) )->journal( $today, $today ), static fn( $r ) => $r['code'] === $spv->code && 'issued' === $r['event'] ) );
$line    = Ledger::journal_line( $spv_row );
vcap_ta_assert( 'single' === Ledger::row_treatment( $spv_row ) && in_array( ProductConfig::treatment_label( 'single' ), $line, true ) && in_array( VCAP\Csv::number( (float) wc_get_order( $spv_buy->get_id() )->get_total(), 2 ), $line, true ), 'M12-29: the journal line shows the treatment at issue and the order amount', $line );

// Expiry with the remaining value, booked back when the voucher is valid again.
$expiring = vcap_ta_voucher( 'giftcard', 'balance', $mpv_product->get_id(), 40, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
vcap()->vouchers->expire_overdue();
$expired = current( array_filter( vcap_ta_events( $expiring ), static fn( $e ) => 'expired' === $e['event'] ) );
vcap_ta_assert( $expired && abs( 40.0 - (float) $expired['amount'] ) < 0.001 && -40.0 === Ledger::signed_amount( 'expired', $expired['amount'] ), 'M12-29: the expiry is booked with the remaining value (negative in the journal)', $expired );
TopUp::extend_validity( vcap()->vouchers, vcap()->vouchers->get( $expiring->id ), gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) );
$back = current( array_filter( vcap_ta_events( $expiring ), static fn( $e ) => TaxAccounting::EXPIRY_REVERSED_EVENT === $e['event'] ) );
vcap_ta_assert( $back && abs( 40.0 - (float) $back['amount'] ) < 0.001 && 40.0 === Ledger::signed_amount( TaxAccounting::EXPIRY_REVERSED_EVENT, $back['amount'] ), 'M12-29: extending the validity books the expired value back once', vcap_ta_events( $expiring ) );

// A single-use voucher of 100 used for 60: "redeemed" 60 and "forfeited" 40, both with the order.
update_option( 'woocommerce_calc_taxes', 'no' );
$small_goods = new WC_Product_Simple();
$small_goods->set_name( 'TA goods 60' );
$small_goods->set_regular_price( '60' );
$small_goods->set_virtual( true );
$small_goods->save();
$split_voucher = vcap_ta_voucher( 'voucher', 'single', $value_product->get_id(), 100 );
$split_order   = wc_create_order();
$split_order->add_product( $small_goods, 1 );
$split_order->set_billing_email( 'ta-split@example.com' );
$split_order->calculate_totals();
$split_order->apply_coupon( $split_voucher->code );
$split_order = wc_get_order( $split_order->get_id() );
$split_order->payment_complete( 'txn-ta-split' );
$split_events = vcap_ta_events( $split_voucher );
$by_event     = array();
foreach ( $split_events as $e ) {
	$by_event[ $e['event'] ] = array( (float) $e['amount'], (int) $e['order_id'] );
}
vcap_ta_assert( array( 60.0, $split_order->get_id() ) === ( $by_event['redeemed'] ?? null ) && array( 40.0, $split_order->get_id() ) === ( $by_event[ TaxAccounting::FORFEITED_EVENT ] ?? null ), 'M12-29: a single-use voucher of 100 used for 60 books "redeemed" 60 and "forfeited" 40 with the order', $by_event );
vcap_ta_assert( -40.0 === Ledger::signed_amount( TaxAccounting::FORFEITED_EVENT, 40 ), 'M12-29: the forfeited rest is negative in the journal' );

/* ---- M12-30: voucher payments as order meta for exports ------------------------------------------------ */

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( PaymentVouchers::OPTION, 'yes' );
$pay_card = vcap_ta_voucher( 'giftcard', 'balance', $mpv_product->get_id(), 50 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $pay_card->code );
WC()->cart->calculate_totals();
$pay_order = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => 'ta-pay@example.com', 'billing_country' => 'AT', 'payment_method' => 'bacs' ) ) );
$pay_meta  = $pay_order->get_meta( PaymentVouchers::PAYMENTS_META );
vcap_ta_assert( array( wc_format_coupon_code( $pay_card->code ) => 50.0 ) === $pay_meta && $pay_meta === PaymentVouchers::order_payments( $pay_order ), 'M12-30: the order keeps its voucher payments in "_vcap_voucher_payments" (code => amount)', $pay_meta );
$rest = PaymentVouchers::rest_order_payments( new WP_REST_Response( array( 'id' => $pay_order->get_id() ) ), $pay_order )->get_data();
vcap_ta_assert( isset( $rest['vcap_voucher_payments'][0] ) && '50.00' === $rest['vcap_voucher_payments'][0]['amount'] && false === stripos( (string) $rest['vcap_voucher_payments'][0]['code'], $pay_card->code ), 'M12-30: the WooCommerce REST order response lists the voucher payments (masked code)', $rest['vcap_voucher_payments'] ?? null );
$export_filter = static fn( $p ) => array( 'X' => 1.0 ) + $p;
add_filter( 'vcap_order_voucher_payments', $export_filter );
vcap_ta_assert( isset( PaymentVouchers::order_payments( $pay_order )['X'] ), 'M12-30: filter "vcap_order_voucher_payments" adjusts the payments for exports' );
remove_filter( 'vcap_order_voucher_payments', $export_filter );
$pay_order->update_status( 'cancelled' );
WC()->cart->empty_cart();

/* ---- M12-42: vouchers issued without a sale ------------------------------------------------------------ */

$gift = vcap()->issuer->issue_standalone( array( 'amount' => 25, 'send' => false, 'source' => 'goodwill' ) );
$paid = vcap()->issuer->issue_standalone( array( 'amount' => 30, 'send' => false, 'paid' => true, 'source' => 'company', 'reference' => 'ta-paid-' . wp_generate_password( 6, false, false ) ) );
vcap_ta_assert( $gift instanceof Voucher && false === ProductConfig::is_sold( $gift ) && 'discount' === ProductConfig::tax_treatment( $gift ), 'M12-42: a voucher issued without a sale (goodwill) is a price reduction for VAT', $gift instanceof Voucher ? ProductConfig::tax_treatment( $gift ) : $gift );
vcap_ta_assert( $paid instanceof Voucher && true === ProductConfig::is_sold( $paid ) && 'payment' === ProductConfig::tax_treatment( $paid ), 'M12-42: a voucher issued with "paid" => true is a sold multi-purpose voucher', $paid instanceof Voucher ? ProductConfig::tax_treatment( $paid ) : $paid );
ProductConfig::forget_snapshots();
vcap_ta_assert( false === ProductConfig::is_sold( vcap()->vouchers->get( $gift->id ) ) && true === ProductConfig::is_sold( vcap()->vouchers->get( $paid->id ) ), 'M12-42: the "sold" flag is read back from the history (not only from this request)' );
$liability = ( new Ledger( vcap()->vouchers ) )->liability( $today );
$bucket    = $liability['totals'][ get_woocommerce_currency() ]['voucher'] ?? array();
vcap_ta_assert( ( $bucket['free_count'] ?? 0 ) >= 1 && ( $bucket['free_amount'] ?? 0 ) >= 25.0 && $bucket['amount'] >= $bucket['free_amount'] + 30.0, 'M12-42: the liability report shows the free vouchers separately from the sold ones', $bucket );
$rows = iterator_to_array( ( new Ledger( vcap()->vouchers ) )->liability_rows( $today ), false );
$sold = array_column( $rows, 'sold', 'code' );
vcap_ta_assert( false === ( $sold[ $gift->code ] ?? null ) && true === ( $sold[ $paid->code ] ?? null ) && in_array( 'Sold', Ledger::liability_header(), true ), 'M12-42: the liability CSV has a column "Sold"', array( $sold[ $gift->code ] ?? null, $sold[ $paid->code ] ?? null ) );

/* ---- M12-66: GDPR erasure keeps coded history notes ------------------------------------------------------ */

$erase = vcap_ta_voucher( 'voucher', 'single', 0, 10 );
vcap()->vouchers->log( $erase->id, 'note', null, null, 'Called Max Mustermann about the voucher' );
vcap()->vouchers->log( $erase->id, 'adjusted', -5.0, 5.0, VCAP\Note::make( 'expired' ) );
vcap()->vouchers->anonymise_event_notes( $erase->id );
$notes = wp_list_pluck( vcap_ta_events( $erase ), 'note', 'event' );
$last  = vcap_ta_events( $erase );
vcap_ta_assert( '[personal data removed]' === $notes['note'] && VCAP\Note::make( 'expired' ) === $notes['adjusted'] && TaxAccounting::ERASED_EVENT === end( $last )['event'], 'M12-66: GDPR erasure replaces free-text notes, keeps coded notes and logs the erasure', $notes );

/* ---- M12-68: keep the rest of a sold single-use voucher -------------------------------------------------- */

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'vcap_store_credit', 'yes' );
$keep_product = vcap_ta_product( 'auto', 'voucher', 'single', '100', array( '_vcap_keep_rest' => 'yes' ) );
$keep_voucher = vcap_ta_voucher( 'voucher', 'single', $keep_product->get_id(), 100 );
$customer_id  = wp_insert_user( array( 'user_login' => 'ta-keep-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'ta-keep-' . wp_generate_password( 6, false, false ) . '@example.com' ) );
$keep_order   = wc_create_order( array( 'customer_id' => $customer_id ) );
$keep_order->add_product( $small_goods, 1 );
$keep_order->set_billing_email( 'ta-keep@example.com' );
$keep_order->calculate_totals();
$keep_order->apply_coupon( $keep_voucher->code );
$keep_order = wc_get_order( $keep_order->get_id() );
$keep_order->payment_complete( 'txn-ta-keep' );
$kept = current( array_filter( vcap_ta_events( $keep_voucher ), static fn( $e ) => TaxAccounting::REST_CONVERTED_EVENT === $e['event'] ) );
vcap_ta_assert( $kept && abs( 40.0 - (float) $kept['amount'] ) < 0.001 && abs( 40.0 - vcap()->store_credit->balance( (int) $customer_id ) ) < 0.001, 'M12-68: with "Keep the rest" the unused 40 become store credit of the customer instead of being lost', array( 'events' => wp_list_pluck( vcap_ta_events( $keep_voucher ), 'event' ), 'credit' => vcap()->store_credit->balance( (int) $customer_id ) ) );
wc_get_order( $keep_order->get_id() )->update_status( 'cancelled' );
vcap_ta_assert( Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $keep_voucher->id )->status && abs( vcap()->store_credit->balance( (int) $customer_id ) ) < 0.001 && vcap()->vouchers->has_event( $keep_voucher->id, TaxAccounting::REST_REVERTED_EVENT ), 'M12-68: cancelling the order gives the voucher back and takes the kept credit back', vcap()->store_credit->balance( (int) $customer_id ) );
$losses_cart = null;
vcap_ta_assert( false !== strpos( VCAP\ProductChecks::residual_notice( array( 'code' => 'X', 'value' => 100.0, 'used' => 60.0, 'currency' => 'EUR', 'keep' => true ), true ), 'is not lost' ), 'M12-68: the cart notice tells that the rest is kept' );

/* ---- M12-69: DATEV export --------------------------------------------------------------------------------- */

update_option( VCAP\DatevExport::OPTION_CONSULTANT, '1234567' );
update_option( VCAP\DatevExport::OPTION_CLIENT, '12345' );
update_option( VCAP\DatevExport::OPTION_LIABILITY, '3272' );
update_option( VCAP\DatevExport::OPTION_CLEARING, '1360' );
update_option( VCAP\DatevExport::OPTION_INCOME, '2700' );
vcap_ta_assert( VCAP\DatevExport::configured(), 'M12-69: the DATEV export is ready once numbers and accounts are set' );
$datev = iterator_to_array( VCAP\DatevExport::lines( new Ledger( vcap()->vouchers ), $today, $today ), false );
$found = array_filter( $datev, static fn( $l ) => isset( $l[13] ) && false !== strpos( (string) $l[13], 'Expired' ) );
$first = current( $found );
vcap_ta_assert( 'EXTF' === $datev[0][0] && 21 === $datev[0][2] && 'Buchungsstapel' === $datev[0][3] && VCAP\DatevExport::COLUMNS === $datev[1] && $first && '40,00' === $first[0] && '3272' === $first[6] && '2700' === $first[7], 'M12-69: EXTF booking batch: header, columns, an expiry booked from the liability to the income account', array_slice( $datev, 0, 3 ) );
vcap_ta_assert( str_starts_with( VCAP\DatevExport::encode( $datev[0] ), '"EXTF";700;21;"Buchungsstapel";12;' ), 'M12-69: the file is ";"-separated with quoted texts' );
vcap_ta_assert( '20260101' === VCAP\DatevExport::financial_year_start( '2026-11-15', '0101' ) && '20250701' === VCAP\DatevExport::financial_year_start( '2026-03-01', '0701' ), 'M12-69: the start of the financial year follows the setting' );
foreach ( array( VCAP\DatevExport::OPTION_CONSULTANT, VCAP\DatevExport::OPTION_CLIENT, VCAP\DatevExport::OPTION_LIABILITY, VCAP\DatevExport::OPTION_CLEARING, VCAP\DatevExport::OPTION_INCOME ) as $option ) {
	delete_option( $option );
}

foreach ( $rates as $id ) {
	WC_Tax::_delete_tax_rate( $id );
}
update_option( 'woocommerce_default_country', $keep['country'] );
update_option( 'woocommerce_tax_based_on', $keep['based'] );
update_option( 'woocommerce_calc_taxes', $keep['calc'] );
update_option( 'woocommerce_prices_include_tax', $keep['incl'] );
update_option( 'vcap_store_credit', $keep['credit'] );
if ( false === $keep['mpv'] ) {
	delete_option( PaymentVouchers::OPTION );
} else {
	update_option( PaymentVouchers::OPTION, $keep['mpv'] );
}
echo "Voucher Captain tax and accounting checks passed.\n";
