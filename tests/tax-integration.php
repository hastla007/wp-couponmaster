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

function vcap_tax_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
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

// Runs where WP Pass Captain is installed (CI job "pass-captain"); the provider implements its interface.
if ( interface_exists( 'PassCaptain\\Integrations\\Vouchers\\ProviderInterface' ) ) {
	$provider = new VCAP\Compat\PassCaptainProvider( vcap()->redemptions, vcap()->vouchers );
	$minor    = 10 ** VCAP\API\V1\RedemptionService::decimals( get_woocommerce_currency() );
	$quote    = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $auto_value->get_id() )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
	vcap_tax_assert( is_array( $quote ) && 'payment' === $quote['treatment'] && 'multi' === $quote['purpose'], 'Pass Captain gets treatment "payment" / purpose "multi" for a multi-purpose voucher' );
	$quote = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $single->get_id() )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
	vcap_tax_assert( is_array( $quote ) && 'single' === $quote['treatment'] && 'single' === $quote['purpose'], 'Pass Captain gets treatment "single" / purpose "single" for a single-purpose voucher' );
	$quote = $provider->quote( array( 'code' => vcap_tax_voucher( 'voucher', 'single', 'fixed_cart' )->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 80 * $minor, 'order_reference' => 'tax-test' ) );
	vcap_tax_assert( is_array( $quote ) && 'discount' === $quote['treatment'] && 'discount' === $quote['purpose'] && ! isset( $quote['sale_tax'] ), 'Pass Captain gets purpose "discount" for a promotion code (reduces price and VAT, never labelled "VAT already charged at sale")' );
} else {
	echo "SKIP: Pass Captain is not installed – quote checks run in the pass-captain job\n";
}

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
vcap_tax_assert( 2000 === ( $line2['tax_rate_bp'] ?? null ) && 'AT' === ( $line2['tax_country'] ?? null ), 'the line snapshot records the VAT of the sale: 20 % (2000 bp), taxed in AT', $line2 );
vcap_tax_assert( array( 'rate_bp' => 2000, 'country' => 'AT' ) === ProductConfig::sale_tax( $spv[0] ), 'sale_tax() of the sold single-purpose voucher: 2000 bp in AT' );
if ( interface_exists( 'PassCaptain\\Integrations\\Vouchers\\ProviderInterface' ) ) {
	$quote = ( new VCAP\Compat\PassCaptainProvider( vcap()->redemptions, vcap()->vouchers ) )->quote( array( 'code' => $spv[0]->code, 'currency' => get_woocommerce_currency(), 'amount_minor' => 50 * 10 ** wc_get_price_decimals(), 'order_reference' => 'tax-sale-tax' ) );
	vcap_tax_assert( is_array( $quote ) && 'single' === $quote['purpose'] && array( 'rate_bp' => 2000, 'country' => 'AT' ) === ( $quote['sale_tax'] ?? null ), 'Pass Captain gets the rate and country of the sale with a single-purpose voucher', $quote );
}

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

// Line-based refund: refunding the single-purpose voucher line (100 net + 20 VAT) reports net and VAT per line.
$spv_item = current( $buy2->get_items() );
$refund2  = wc_create_refund( array( 'order_id' => $buy2->get_id(), 'amount' => 120, 'line_items' => array( $spv_item->get_id() => array( 'qty' => 1, 'refund_total' => 100, 'refund_tax' => array( $rate_id => 20 ) ) ) ) );
$lines2   = vcap()->api_v1->order_lines( wc_get_order( $buy2->get_id() ) );
$line2r   = $lines2[ $spv_item->get_id() ] ?? array();
vcap_tax_assert( ! is_wp_error( $refund2 ) && 1 === ( $line2r['refunded_quantity'] ?? 0 ) && abs( 100.0 - (float) ( $line2r['refunded_total'] ?? 0 ) ) < 0.01 && abs( 20.0 - (float) ( $line2r['refunded_tax'] ?? 0 ) ) < 0.01, 'line-based refund: order_lines() reports refunded quantity, net and VAT per line (100 + 20)', $line2r );
vcap_tax_assert( Voucher::STATUS_VOID === vcap()->vouchers->get( $spv[0]->id )->status, 'the refunded single-purpose voucher is voided' );

wc_get_order( $buy->get_id() )->update_status( 'refunded' );
vcap_tax_assert( Voucher::STATUS_VOID === vcap()->vouchers->get( $issued[0]->id )->status, 'a fully refunded gift card purchase voids the unused gift card' );

WC_Tax::_delete_tax_rate( $rate_id );

/* ---- 7. Tax cases (TAX.md section 10): rates, place of supply (OSS), same gross price, small business -- */

$keep_country = get_option( 'woocommerce_default_country' );
$keep_based   = get_option( 'woocommerce_tax_based_on' );
update_option( 'woocommerce_default_country', 'AT' );
update_option( 'woocommerce_tax_based_on', 'billing' );
$rate = static fn( string $country, string $percent, string $class = '' ) => WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => $country, 'tax_rate_state' => '', 'tax_rate' => $percent, 'tax_rate_name' => "VAT {$country} {$percent}", 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => $class ) );
$rates = array( $rate( 'AT', '20.0000' ), $rate( 'AT', '10.0000', 'reduced-rate' ), $rate( 'DE', '19.0000' ), $rate( 'DE', '7.0000', 'reduced-rate' ) );
$goods_reduced = new WC_Product_Simple();
$goods_reduced->set_name( 'Reduced-rate goods' );
$goods_reduced->set_regular_price( '110' );
$goods_reduced->set_tax_class( 'reduced-rate' );
$goods_reduced->set_virtual( true );
$goods_reduced->save();

/** Order with the given lines (product => [net, tax-class rate country]), taxes by the billing country. */
$order_with = static function ( array $lines, string $country, string $email ): WC_Order {
	$order = wc_create_order();
	foreach ( $lines as $line ) {
		list( $product, $net ) = $line;
		// Net amounts go in with the line, so its tax is calculated on them (not on the product price).
		$order->add_product( $product, 1, array( 'subtotal' => $net, 'total' => $net ) );
	}
	$order->set_billing_email( $email );
	$order->set_billing_country( $country );
	$order->save();
	$order->calculate_totals( true );
	return wc_get_order( $order->get_id() );
};
$pay_with_card = static function ( WC_Order $order, float $value ) use ( $auto_card ): array {
	$card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), $value );
	$order->apply_coupon( $card->code );
	vcap()->coupons->reserve_order( wc_get_order( $order->get_id() ) );
	wc_get_order( $order->get_id() )->payment_complete( 'txn-' . $card->code );
	return array( wc_get_order( $order->get_id() ), (float) vcap()->vouchers->get( $card->id )->balance );
};

// 7.1 Different rates in one order (tax-inclusive prices): the card is spread over both lines by value,
// each line keeps its own rate, the card is charged exactly what it paid.
update_option( 'woocommerce_prices_include_tax', 'yes' );
list( $mixed, $left ) = $pay_with_card( $order_with( array( array( $goods, 100 ), array( $goods_reduced, 100 ) ), 'AT', 'tax-mixed@example.com' ), 115 );
vcap_tax_assert( abs( 230.0 - (float) $mixed->get_subtotal() - ( (float) $mixed->get_cart_tax() + (float) $mixed->get_discount_tax() ) ) < 0.05, 'mixed-rate order: 120 at 20 % + 110 at 10 % = 230 gross before the card' );
vcap_tax_assert( abs( 115.0 - (float) $mixed->get_total() ) < 0.03 && $left < 0.03, 'mixed rates, gross prices: a 115 card pays 115 of 230 and is used up exactly' );
$line_rates = array();
foreach ( $mixed->get_items() as $line ) {
	$line_rates[ $line->get_name() ] = (float) $line->get_total() > 0 ? round( (float) $line->get_total_tax() / (float) $line->get_total(), 3 ) : null;
}
vcap_tax_assert( array( 'Taxed goods' => 0.2, 'Reduced-rate goods' => 0.1 ) === $line_rates, 'mixed rates, gross prices: each line keeps its own rate after the card (20 % and 10 %)', $line_rates );
vcap_tax_assert( abs( 115.0 - (float) $mixed->get_total_tax() - array_sum( array_map( static fn( $l ) => (float) $l->get_total(), $mixed->get_items() ) ) ) < 0.03, 'mixed rates, gross prices: the remaining 115 is net + VAT of both lines', array( 'tax' => $mixed->get_total_tax() ) );

// 7.2 Same with net prices: the card still pays gross (net share + its VAT), across both rates.
update_option( 'woocommerce_prices_include_tax', 'no' );
list( $mixed_net, $left ) = $pay_with_card( $order_with( array( array( $goods, 100 ), array( $goods_reduced, 100 ) ), 'AT', 'tax-mixed-net@example.com' ), 115 );
vcap_tax_assert( abs( 115.0 - (float) $mixed_net->get_total() ) < 0.05 && $left < 0.05, 'mixed rates, net prices: a 115 card pays 115 of the 230 gross total and is used up exactly', array( 'total' => $mixed_net->get_total(), 'card_left' => $left, 'discount' => $mixed_net->get_discount_total(), 'discount_tax' => $mixed_net->get_discount_tax(), 'subtotal' => $mixed_net->get_subtotal(), 'tax' => $mixed_net->get_total_tax() ) );

// 7.3 Place of supply in another EU country (OSS): the order is taxed at the buyer's rate, the card pays gross.
update_option( 'woocommerce_prices_include_tax', 'yes' );
list( $oss, $left ) = $pay_with_card( $order_with( array( array( $goods, 100 ) ), 'DE', 'tax-oss@example.com' ), 50 );
vcap_tax_assert( abs( 69.0 - (float) $oss->get_total() ) < 0.03 && $left < 0.03, 'OSS: a DE order (100 net + 19 %) paid with a 50 card leaves 69 and uses the card up exactly' );
$oss_rates = array_values( array_unique( array_map( static fn( $t ) => (int) $t->get_rate_id(), array_values( $oss->get_items( 'tax' ) ) ) ) );
vcap_tax_assert( array( (int) $rates[2] ) === $oss_rates, 'OSS: only the buyer country rate (DE 19 %) is on the order' );
vcap_tax_assert( abs( 69.0 / 1.19 * 0.19 - (float) $oss->get_total_tax() ) < 0.03, 'OSS: VAT is the DE share of the remaining 69 (current price-reduction behaviour, TAX.md 4.1)' );

// 7.4 Same gross price in every country vs. the WooCommerce default: a voucher bought for "50" is worth what
// the buyer actually paid (gross), whatever rate applied.
$taxable_card = vcap_tax_product( 'single' );
$taxable_card->set_regular_price( '50' );
$taxable_card->set_tax_status( 'taxable' );
$taxable_card->save();
$same_gross = $order_with( array( array( $taxable_card, round( 50 / 1.19, 2 ) ) ), 'DE', 'tax-gross@example.com' );
$same_gross->payment_complete( 'txn-tax-gross' );
$v_same = vcap()->vouchers->for_order( $same_gross->get_id() );
vcap_tax_assert( abs( 50.0 - (float) wc_get_order( $same_gross->get_id() )->get_total() ) < 0.02 && 1 === count( $v_same ) && abs( 50.0 - (float) $v_same[0]->initial_amount ) < 0.02, 'same gross price: a DE buyer pays 50 incl. 19 % and gets a 50 voucher' );
$default_gross = $order_with( array( array( $taxable_card, round( 50 / 1.2, 2 ) ) ), 'DE', 'tax-net@example.com' );
$default_gross->payment_complete( 'txn-tax-net' );
$paid_gross = (float) wc_get_order( $default_gross->get_id() )->get_total();
$v_default  = vcap()->vouchers->for_order( $default_gross->get_id() );
vcap_tax_assert( abs( 49.58 - $paid_gross ) < 0.02 && 1 === count( $v_default ) && abs( $paid_gross - (float) $v_default[0]->initial_amount ) < 0.001, 'WooCommerce default (net kept, DE rate added): the DE buyer pays 49.58 and the voucher is worth exactly that' );
$non_taxable = $order_with( array( array( $sold_card, 60 ) ), 'DE', 'tax-mpv-de@example.com' );
$non_taxable->payment_complete( 'txn-tax-mpv-de' );
$v_mpv = vcap()->vouchers->for_order( $non_taxable->get_id() );
vcap_tax_assert( 0.0 === (float) wc_get_order( $non_taxable->get_id() )->get_total_tax() && 1 === count( $v_mpv ) && abs( 60.0 - (float) $v_mpv[0]->initial_amount ) < 0.001, 'a multi-purpose card sold as not taxable costs and is worth 60 in every country' );

// 7.5 Small business (no VAT charged): taxes off – sale and redemption carry no VAT, the card pays its value.
update_option( 'woocommerce_calc_taxes', 'no' );
$small_buy = wc_create_order();
$small_buy->add_product( $taxable_card, 1 );
$small_buy->set_billing_email( 'tax-small@example.com' );
$small_buy->calculate_totals();
$small_buy->payment_complete( 'txn-tax-small' );
$v_small = vcap()->vouchers->for_order( $small_buy->get_id() );
vcap_tax_assert( 0.0 === (float) wc_get_order( $small_buy->get_id() )->get_total_tax() && 1 === count( $v_small ) && abs( 50.0 - (float) $v_small[0]->initial_amount ) < 0.001, 'small business: a 50 voucher is sold for 50 without VAT' );
$small_spend = wc_create_order();
$small_spend->add_product( $goods, 1 );
$small_spend->set_billing_email( 'tax-small-spend@example.com' );
$small_spend->calculate_totals();
list( $small_spend, $left ) = $pay_with_card( $small_spend, 50 );
vcap_tax_assert( 0.0 === (float) $small_spend->get_total_tax() && abs( 70.0 - (float) $small_spend->get_total() ) < 0.001 && $left < 0.001, 'small business: a 50 card pays 50 of 120, no VAT anywhere' );

/* ---- 8. Multi-purpose vouchers as a payment after tax (setting vcap_mpv_after_tax, TAX.md 4.1) -------- */

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( VCAP\PaymentVouchers::OPTION, 'yes' );
wc_load_cart();
WC()->customer->set_billing_country( 'AT' );
WC()->customer->set_shipping_country( 'AT' );
WC()->customer->set_billing_email( 'tax-after@example.com' );
$after_card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 60 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $after_card->code );
WC()->cart->calculate_totals();
$after_pay  = vcap()->payments->cart_payments();
$after_cart = array( 'total' => WC()->cart->get_total( 'edit' ), 'tax' => WC()->cart->get_total_tax(), 'fees' => count( WC()->cart->get_fees() ), 'payments' => $after_pay, 'discount' => WC()->cart->get_coupon_discount_amount( wc_format_coupon_code( $after_card->code ), false ) );
vcap_tax_assert( abs( 60.0 - (float) WC()->cart->get_total( 'edit' ) ) < 0.01 && abs( 20.0 - (float) WC()->cart->get_total_tax() ) < 0.01, 'after tax: goods 120 incl. 20 % VAT, card 60 → the customer pays 60 and the order keeps the full 20 VAT', $after_cart );
vcap_tax_assert( array( wc_format_coupon_code( $after_card->code ) => 60.0 ) === $after_pay && 0.0 === (float) $after_cart['discount'] && 0 === $after_cart['fees'], 'the card pays 60 after tax; its coupon lowers no price and there is no taxed fee', $after_cart );
ob_start();
vcap()->payments->payment_rows();
vcap_tax_assert( false !== strpos( (string) ob_get_clean(), strtoupper( $after_card->code ) ), 'cart and checkout show a payment row "Voucher CODE"' );
$after_id = WC()->checkout()->create_order( array( 'billing_email' => 'tax-after@example.com', 'billing_country' => 'AT', 'payment_method' => 'bacs' ) );
$after    = wc_get_order( $after_id );
$after_fee = $after ? current( $after->get_items( 'fee' ) ) : null;
vcap_tax_assert( $after && abs( 60.0 - (float) $after->get_total() ) < 0.01 && abs( 20.0 - (float) $after->get_total_tax() ) < 0.01 && $after_fee && wc_format_coupon_code( $after_card->code ) === $after_fee->get_meta( VCAP\PaymentVouchers::FEE_META ) && abs( 60.0 - (float) VCAP\PaymentVouchers::paid_amount( $after, $after_card->code ) ) < 0.01, 'the order: total 60, VAT 20, a payment line marked with the card code', $after ? array( 'total' => $after->get_total(), 'tax' => $after->get_total_tax() ) : $after_id );
$after_recalc = wc_get_order( $after_id );
$after_recalc->calculate_totals( true );
$after_recalc = wc_get_order( $after_id );
vcap_tax_assert( abs( 60.0 - (float) $after_recalc->get_total() ) < 0.01 && abs( 20.0 - (float) $after_recalc->get_total_tax() ) < 0.01 && 0.0 === (float) current( $after_recalc->get_items( 'fee' ) )->get_total_tax(), 'recalculating the order (with taxes) keeps total 60, VAT 20 and an untaxed payment line', array( 'total' => $after_recalc->get_total(), 'tax' => $after_recalc->get_total_tax() ) );
vcap()->coupons->reserve_order( wc_get_order( $after_id ) );
wc_get_order( $after_id )->payment_complete( 'txn-tax-after' );
vcap_tax_assert( abs( 0.0 - (float) vcap()->vouchers->get( $after_card->id )->balance ) < 0.01, 'the card is charged exactly the 60 it paid' );
wc_get_order( $after_id )->update_status( 'cancelled' );
vcap_tax_assert( abs( 60.0 - (float) vcap()->vouchers->get( $after_card->id )->balance ) < 0.01, 'cancelling the order gives the 60 back to the card' );
$big_card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 200 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $big_card->code );
WC()->cart->calculate_totals();
vcap_tax_assert( abs( (float) WC()->cart->get_total( 'edit' ) ) < 0.01 && abs( 20.0 - (float) WC()->cart->get_total_tax() ) < 0.01 && array( wc_format_coupon_code( $big_card->code ) => 120.0 ) === vcap()->payments->cart_payments(), 'a 200 card pays the whole 120 (VAT 20 stays), never more than is due', array( 'total' => WC()->cart->get_total( 'edit' ), 'tax' => WC()->cart->get_total_tax(), 'payments' => vcap()->payments->cart_payments() ) );
$big_order = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => 'tax-after-big@example.com', 'billing_country' => 'AT', 'payment_method' => 'bacs' ) ) );
$big_order->calculate_totals( true );
$big_order = wc_get_order( $big_order->get_id() );
vcap_tax_assert( abs( (float) $big_order->get_total() ) < 0.01 && abs( 20.0 - (float) $big_order->get_total_tax() ) < 0.01 && abs( 120.0 - (float) VCAP\PaymentVouchers::paid_amount( $big_order, $big_card->code ) ) < 0.01, 'an order paid fully by the card stays at 0 with 20 VAT after a recalculation (WooCommerce would cap the line at the net 100)', array( 'total' => $big_order->get_total(), 'tax' => $big_order->get_total_tax() ) );
$big_order->update_status( 'cancelled' );

// 8.1 The voucher pays only what it may pay for: vouchers do not buy vouchers or top-ups (purchase rules) …
$mixed_card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 200 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->add_to_cart( $auto_card->get_id(), 1 );
WC()->cart->apply_coupon( $mixed_card->code );
WC()->cart->calculate_totals();
$mixed_state = array( 'total' => WC()->cart->get_total( 'edit' ), 'payments' => vcap()->payments->cart_payments(), 'coupons' => WC()->cart->get_applied_coupons() );
vcap_tax_assert( array( wc_format_coupon_code( $mixed_card->code ) => 120.0 ) === vcap()->payments->cart_payments() && abs( 50.0 - (float) WC()->cart->get_total( 'edit' ) ) < 0.01, 'goods 120 + a 50 gift card product, 200 card: the card pays only the 120 goods, the gift card product stays to pay', $mixed_state );
$mixed_order = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => 'tax-after-mixed@example.com', 'billing_country' => 'AT', 'payment_method' => 'bacs' ) ) );
vcap_tax_assert( abs( 50.0 - (float) $mixed_order->get_total() ) < 0.01 && abs( 120.0 - (float) VCAP\PaymentVouchers::paid_amount( $mixed_order, $mixed_card->code ) ) < 0.01, 'the order of that cart: payment line 120, total 50', array( 'total' => $mixed_order->get_total() ) );
$mixed_order->update_status( 'cancelled' );

// … and a voucher limited to a category pays only the products of that category (and no shipping or fees).
$pay_cat   = wp_insert_term( 'Voucher payment category ' . wp_generate_password( 6, false, false ), 'product_cat' );
$cat_goods = new WC_Product_Simple();
$cat_goods->set_name( 'Category goods' );
$cat_goods->set_regular_price( '60' );
$cat_goods->set_virtual( true );
$cat_goods->set_category_ids( array( (int) $pay_cat['term_id'] ) );
$cat_goods->save();
$cat_card   = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 200 );
$cat_coupon = new WC_Coupon( $cat_card->coupon_id );
$cat_coupon->set_product_categories( array( (int) $pay_cat['term_id'] ) );
$cat_coupon->save();
$cat_fee = static function ( $cart ) {
	$cart->add_fee( 'Handling', 10, true );
};
add_action( 'woocommerce_cart_calculate_fees', $cat_fee );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->add_to_cart( $cat_goods->get_id(), 1 );
WC()->cart->apply_coupon( $cat_card->code );
WC()->cart->calculate_totals();
$cat_state = array( 'total' => WC()->cart->get_total( 'edit' ), 'payments' => vcap()->payments->cart_payments(), 'coupons' => WC()->cart->get_applied_coupons(), 'fees' => WC()->cart->get_fee_total() );
vcap_tax_assert( array( wc_format_coupon_code( $cat_card->code ) => 60.0 ) === vcap()->payments->cart_payments(), 'a card limited to a category pays only the 60 of the product in it, not the other goods or the fee', $cat_state );
remove_action( 'woocommerce_cart_calculate_fees', $cat_fee );
$open_card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 500 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
add_action( 'woocommerce_cart_calculate_fees', $cat_fee );
WC()->cart->apply_coupon( $open_card->code );
WC()->cart->calculate_totals();
remove_action( 'woocommerce_cart_calculate_fees', $cat_fee );
$open_state = array( 'total' => WC()->cart->get_total( 'edit' ), 'payments' => vcap()->payments->cart_payments(), 'fees' => WC()->cart->get_fee_total(), 'fee_tax' => WC()->cart->get_fee_tax() );
vcap_tax_assert( abs( (float) WC()->cart->get_total( 'edit' ) ) < 0.01 && abs( 132.0 - (float) ( vcap()->payments->cart_payments()[ wc_format_coupon_code( $open_card->code ) ] ?? 0 ) ) < 0.02, 'an unrestricted card also pays the fees (goods 120 + fee 10 + 20 % VAT = 132)', $open_state );

// 8.2 Other cart instances (WooCommerce Subscriptions calculates its recurring carts as clones of the main cart,
// without the voucher) neither pay nor overwrite the payments of the main cart.
$clone_card = vcap_tax_voucher( 'giftcard', 'balance', 'fixed_cart', $auto_card->get_id(), 50 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $clone_card->code );
WC()->cart->calculate_totals();
$recurring = clone WC()->cart;
$recurring->set_applied_coupons( array() );
$recurring->calculate_totals();
$clone_code  = wc_format_coupon_code( $clone_card->code );
$clone_state = array( 'payments' => vcap()->payments->cart_payments(), 'session' => WC()->session->get( 'vcap_voucher_payments' ), 'recurring' => $recurring->get_total( 'edit' ), 'main' => WC()->cart->get_total( 'edit' ) );
vcap_tax_assert( array( $clone_code => 50.0 ) === vcap()->payments->cart_payments() && array( $clone_code => 50.0 ) === WC()->session->get( 'vcap_voucher_payments' ) && abs( 120.0 - (float) $recurring->get_total( 'edit' ) ) < 0.01 && abs( 70.0 - (float) WC()->cart->get_total( 'edit' ) ) < 0.01, 'a second cart calculation (clone without the card) keeps the main cart payment of 50 and pays nothing itself', $clone_state );
$recurring_with = clone WC()->cart;
$recurring_with->calculate_totals();
vcap_tax_assert( abs( 120.0 - (float) $recurring_with->get_total( 'edit' ) ) < 0.01 && array( $clone_code => 50.0 ) === vcap()->payments->cart_payments(), 'a cloned cart that still carries the card does not pay with it (a voucher pays the order it is applied to, not a renewal)', array( 'recurring' => $recurring_with->get_total( 'edit' ) ) );
WC()->session->set( 'vcap_voucher_payments', array( $clone_code => 1.0 ) );
$saved_id    = -1;
$saved_early = static function ( $order ) use ( &$saved_id ) {
	$saved_id = (int) $order->get_id();
};
add_action( 'woocommerce_checkout_create_order', $saved_early, 30 );
$clone_order = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => 'tax-after-clone@example.com', 'billing_country' => 'AT', 'payment_method' => 'bacs' ) ) );
vcap_tax_assert( abs( 70.0 - (float) $clone_order->get_total() ) < 0.01 && abs( 50.0 - (float) VCAP\PaymentVouchers::paid_amount( $clone_order, $clone_code ) ) < 0.01 && 1 === count( $clone_order->get_items( 'fee' ) ), 'the order is created with the payment line recalculated from the main cart (not from the session): total 70, card 50', array( 'total' => $clone_order->get_total() ) );
remove_action( 'woocommerce_checkout_create_order', $saved_early, 30 );
vcap_tax_assert( 0 === $saved_id, 'adding the payment lines does not save the new order early (it is saved once, after every woocommerce_checkout_create_order callback)', $saved_id );
$clone_order->update_status( 'cancelled' );

$spv_after = vcap_tax_voucher( 'voucher', 'single', 'fixed_cart', $single->get_id(), 60 );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $goods->get_id(), 1 );
WC()->cart->apply_coupon( $spv_after->code );
WC()->cart->calculate_totals();
vcap_tax_assert( array() === vcap()->payments->cart_payments() && abs( 10.0 - (float) WC()->cart->get_total_tax() ) < 0.01, 'a single-purpose voucher stays a price reduction (VAT 10 on the remaining 60)', array( 'tax' => WC()->cart->get_total_tax() ) );
WC()->cart->empty_cart();
update_option( VCAP\PaymentVouchers::OPTION, 'no' );

foreach ( $rates as $id ) {
	WC_Tax::_delete_tax_rate( $id );
}
update_option( 'woocommerce_default_country', $keep_country );
update_option( 'woocommerce_tax_based_on', $keep_based );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
echo "Voucher Captain tax classification checks passed.\n";
