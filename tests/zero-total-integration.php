<?php
/**
 * Fully discounted (0 €) orders: WooCommerce treats them as paid; vouchers are issued and balances charged.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/zero-total-integration.php
 */

function vcap_zero_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

// This shop allows paying vouchers with gift cards (off by default, see tests/purchase-rules-integration.php).
update_option( 'vcap_vouchers_buy_vouchers', 'yes' );

// A gift card with 50 balance pays for everything.
$card_coupon = new WC_Coupon();
$card_coupon->set_code( 'vcap-zero-' . strtolower( wp_generate_password( 10, false, false ) ) );
$card_coupon->set_discount_type( 'fixed_cart' );
$card_coupon->set_amount( 50 );
$card_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$card_coupon->save();
$card                 = new VCAP\Voucher();
$card->code           = wc_format_coupon_code( $card_coupon->get_code() );
$card->coupon_id      = $card_coupon->get_id();
$card->order_item_id  = 50000000 + $card_coupon->get_id();
$card->type           = 'giftcard';
$card->usage_mode     = 'balance';
$card->initial_amount = 50;
$card->balance        = 50;
$card->currency       = get_woocommerce_currency();
$card->token          = VCAP\VoucherRepository::new_token();
$card->id             = vcap()->vouchers->insert( $card );

// The customer buys a 30 € voucher product with it.
$product = new WC_Product_Simple();
$product->set_name( 'Zero total voucher' );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->save();

$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_email( 'zero@example.com' );
$order->calculate_totals();
$applied = $order->apply_coupon( $card->code );
vcap_zero_assert( true === $applied, 'the gift card is applied to the order' );
$order = wc_get_order( $order->get_id() );
vcap_zero_assert( 0.0 === (float) $order->get_total() && ! $order->needs_payment(), 'the order total is 0 and WooCommerce needs no payment' );

vcap()->coupons->reserve_order( $order );
$order->payment_complete(); // What WooCommerce's checkout does for orders without payment.
$order = wc_get_order( $order->get_id() );

vcap_zero_assert( in_array( $order->get_status(), array( 'processing', 'completed' ), true ), 'the 0 € order is treated as paid' );
vcap_zero_assert( 1 === count( vcap()->vouchers->for_order( $order->get_id() ) ), 'the purchased voucher is issued' );
$bought = vcap()->vouchers->for_order( $order->get_id() )[0];
vcap_zero_assert( 30.0 === (float) $bought->initial_amount, 'the purchased voucher keeps its full value of 30' );
vcap_zero_assert( 20.0 === (float) vcap()->vouchers->get( $card->id )->balance, 'the gift card balance is charged with 30' );

delete_option( 'vcap_vouchers_buy_vouchers' );
echo "Voucher Captain zero-total checks passed.\n";
