<?php
/**
 * VAT classification of vouchers as described in TAX.md (the dossier for the tax adviser):
 * - which treatment each voucher kind gets (automatic and explicit product setting, filter),
 * - what WP Pass Captain receives in the quote (treatment / purpose),
 * - what is recorded about the sale of a voucher (tax status, tax class, net and tax of the line),
 * - how WooCommerce currently taxes an order paid with a gift card (price reduction, TAX.md section 4).
 *
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/tax-integration.php
 */

use VCAP\ProductConfig;
use VCAP\Voucher;

function vcap_tax_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

/** A voucher product with the given VAT treatment setting (meta only, as the product form saves it). */
function vcap_tax_product( string $treatment, string $discount_type = 'fixed_cart', string $type = 'voucher', string $usage = 'single' ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( "Tax {$type} {$treatment}" );
	$product->set_regular_price( '50' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->update_meta_data( '_vcap_type', $type );
	$product->update_meta_data( '_vcap_discount_type', $discount_type );
	$product->update_meta_data( '_vcap_usage', $usage );
	$product->update_meta_data( '_vcap_tax_treatment', $treatment );
	$product->save();
	return $product;
}

/** A voucher row (and its coupon) without going through an order. */
function vcap_tax_voucher( string $type, string $usage, string $discount_type, int $product_id = 0, float $amount = 50 ): Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-tax-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'percent' === $discount_type ? 'percent' : 'fixed_cart' );
	$coupon->set_amount( $amount );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 60000000 + $coupon->get_id();
	$v->product_id     = $product_id;
	$v->type           = $type;
	$v->usage_mode     = $usage;
	$v->discount_type  = $discount_type;
	$v->initial_amount = $amount;
	$v->balance        = 'balance' === $usage ? $amount : 0.0;
	$v->currency       = get_woocommerce_currency();
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

/* ---- 1. Automatic classification ("auto") ----------------------------------------------------------- */

$auto_value = vcap_tax_product( 'auto' );
vcap_tax_assert( 'payment' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $auto_value->get_id() ) ), 'auto: a sold value voucher is a multi-purpose voucher (payment)' );
$auto_percent = vcap_tax_product( 'auto', 'percent' );
vcap_tax_assert( 'discount' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'percent', $auto_percent->get_id(), 10 ) ), 'auto: a sold percentage voucher is a price reduction (discount)' );
$auto_card = vcap_tax_product( 'auto', 'fixed_cart', 'giftcard', 'balance' );
vcap_tax_assert( 'payment' === ProductConfig::tax_treatment( vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id() ) ), 'auto: a gift card with balance is a multi-purpose voucher (payment)' );
vcap_tax_assert( 'payment' === ProductConfig::tax_treatment( vcap_tax_voucher( 'credit', 'balance', 'fixed_cart' ) ), 'auto: store credit (no product) is treated as payment' );
vcap_tax_assert( 'discount' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'fixed_cart' ) ), 'auto: a value code without a sale behind it (campaign, bonus, referral) is a price reduction' );
vcap_tax_assert( 'discount' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'percent', 0, 15 ) ), 'auto: a percentage code without a sale is a price reduction' );

/* ---- 2. Explicit product setting and filter --------------------------------------------------------- */

$single = vcap_tax_product( 'single' );
vcap_tax_assert( 'single' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $single->get_id() ) ), 'setting "single-purpose" wins over the automatic choice' );
$promo = vcap_tax_product( 'discount' );
vcap_tax_assert( 'discount' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $promo->get_id() ) ), 'setting "promotion" makes a sold value voucher a price reduction' );
$pay_percent = vcap_tax_product( 'payment', 'percent' );
vcap_tax_assert( 'payment' === ProductConfig::tax_treatment( vcap_tax_voucher( 'voucher', 'single', 'percent', $pay_percent->get_id(), 10 ) ), 'setting "multi-purpose" wins even for a percentage voucher' );
$ticket = vcap_tax_product( 'auto', 'fixed_cart', 'ticket' );
vcap_tax_assert( 'payment' === ProductConfig::tax_treatment( vcap_tax_voucher( 'ticket', 'single', 'fixed_cart', $ticket->get_id() ) ), 'auto: a sold ticket voucher with a value is classified like a value voucher (adviser to confirm, TAX.md 2.4)' );
$filter = static fn( $t, $v ) => 'giftcard' === $v->type ? 'single' : $t;
add_filter( 'vcap_voucher_tax_treatment', $filter, 10, 2 );
vcap_tax_assert( 'single' === ProductConfig::tax_treatment( vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id() ) ), 'filter vcap_voucher_tax_treatment overrides the classification' );
remove_filter( 'vcap_voucher_tax_treatment', $filter, 10 );

/* ---- 3. What Pass Captain receives -------------------------------------------------------------------- */

$provider = new VCAP\Compat\PassCaptainProvider( vcap()->redemptions, vcap()->vouchers );
$minor    = 10 ** VCAP\API\V1\RedemptionService::decimals( get_woocommerce_currency() );
$quote    = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $auto_value->get_id() )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
vcap_tax_assert( is_array( $quote ) && 'payment' === $quote['treatment'] && 'multi' === $quote['purpose'], 'Pass Captain gets treatment "payment" / purpose "multi" for a multi-purpose voucher' );
$quote = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $single->get_id() )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
vcap_tax_assert( is_array( $quote ) && 'single' === $quote['treatment'] && 'single' === $quote['purpose'], 'Pass Captain gets treatment "single" / purpose "single" for a single-purpose voucher' );
$quote = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart' )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
vcap_tax_assert( is_array( $quote ) && 'discount' === $quote['treatment'] && 'single' === $quote['purpose'], 'Pass Captain gets treatment "discount" (purpose "single": reduces price and VAT) for a promotion code' );

/* ---- 4. Sale of a voucher: what is recorded --------------------------------------------------------- */

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );

$sold_card = vcap_tax_product( 'payment', 'fixed_cart', 'giftcard', 'balance' );
$sold_card->set_regular_price( '60' );
$sold_card->set_tax_status( 'none' );
$sold_card->save();
$buy = wc_create_order();
$buy->add_product( $sold_card, 1 );
$buy->set_billing_email( 'tax-buy@example.com' );
$buy->set_billing_country( 'AT' );
$buy->calculate_totals();
$buy->payment_complete( 'txn-tax-buy' );
$buy  = wc_get_order( $buy->get_id() );
$item = current( $buy->get_items() );
$line = VCAP\Issuer::line_data( $item );
vcap_tax_assert( 0.0 === (float) $buy->get_total_tax() && 'none' === ( $line['tax_status'] ?? '' ) && 0.0 === (float) $line['total_tax'], 'a multi-purpose gift card sold as "not taxable" is sold without VAT and that is recorded on the line' );
$issued = vcap()->vouchers->for_order( $buy->get_id() );
vcap_tax_assert( 1 === count( $issued ) && abs( 60.0 - (float) $issued[0]->initial_amount ) < 0.001, 'the gift card carries the paid 60 as its value' );

$sold_spv = vcap_tax_product( 'single' );
$sold_spv->set_regular_price( '120' );
$sold_spv->set_tax_status( 'taxable' );
$sold_spv->save();
$buy2 = wc_create_order();
$buy2->add_product( $sold_spv, 1 );
$buy2->set_billing_email( 'tax-buy2@example.com' );
$buy2->set_billing_country( 'AT' );
$buy2->calculate_totals();
$buy2->payment_complete( 'txn-tax-buy2' );
$buy2  = wc_get_order( $buy2->get_id() );
$line2 = VCAP\Issuer::line_data( current( $buy2->get_items() ) );
vcap_tax_assert( abs( 20.0 - (float) $buy2->get_total_tax() ) < 0.01 && 'taxable' === ( $line2['tax_status'] ?? '' ) && abs( 20.0 - (float) $line2['total_tax'] ) < 0.01 && true === $line2['prices_include_tax'], 'a single-purpose voucher sold as taxable charges VAT at sale (20 of 120) and records it' );
$spv = vcap()->vouchers->for_order( $buy2->get_id() );
vcap_tax_assert( 1 === count( $spv ) && abs( 120.0 - (float) $spv[0]->initial_amount ) < 0.001, 'in a tax-inclusive shop the voucher value is the gross price (120)' );

/* ---- 5. Redemption in the WooCommerce cart (current behaviour: price reduction) --------------------- */

$goods = new WC_Product_Simple();
$goods->set_name( 'Taxed goods' );
$goods->set_regular_price( '120' );
$goods->set_virtual( true );
$goods->save();
$card  = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 60 );
$spend = wc_create_order();
$spend->add_product( $goods, 1 );
$spend->set_billing_email( 'tax-spend@example.com' );
$spend->set_billing_country( 'AT' );
$spend->calculate_totals();
$spend->apply_coupon( $card->code );
$spend = wc_get_order( $spend->get_id() );
vcap_tax_assert( abs( 60.0 - (float) $spend->get_total() ) < 0.02, 'a 60 gift card pays 60 of a 120 gross order' );
vcap_tax_assert( abs( 10.0 - (float) $spend->get_total_tax() ) < 0.02 && abs( 10.0 - (float) $spend->get_discount_tax() ) < 0.02, 'current behaviour: WooCommerce charges VAT only on the remaining 60 (10 instead of 20) – the gift card acts as a price reduction (TAX.md 4, open item TODO line 268)' );

/* ---- 6. Refund consequences (TAX.md section 5) ------------------------------------------------------- */

vcap()->coupons->reserve_order( $spend );
$spend->payment_complete( 'txn-tax-spend' );
vcap_tax_assert( vcap()->vouchers->get( $card->id )->balance < 0.02, 'the redemption order uses the gift card up' );
wc_get_order( $spend->get_id() )->update_status( 'cancelled' );
vcap_tax_assert( abs( 60.0 - (float) vcap()->vouchers->get( $card->id )->balance ) < 0.02, 'cancelling the redemption order books the 60 back onto the gift card' );

wc_get_order( $buy->get_id() )->update_status( 'refunded' );
vcap_tax_assert( Voucher::STATUS_VOID === vcap()->vouchers->get( $issued[0]->id )->status, 'a fully refunded gift card purchase voids the unused gift card' );

WC_Tax::_delete_tax_rate( $rate_id );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
echo "Voucher Captain tax classification checks passed.\n";
