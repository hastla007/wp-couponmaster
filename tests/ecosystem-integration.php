<?php
/**
 * Voucher Captain next to real third-party plugins from wordpress.org (CI job "ecosystem"):
 *   wp eval-file tests/ecosystem-integration.php setup <plugin>   (configures the plugin; separate process)
 *   wp eval-file tests/ecosystem-integration.php run <plugin>
 *
 * <plugin>: stripe | paypal (payment gateways), woocs | curcy | wcml (currency switchers).
 *
 * Gateways: the gateway is registered next to Voucher Captain, and the payment flows a gateway drives (payment
 * pending at the provider, failed attempt and retry, duplicate webhooks, refund without calling the provider)
 * issue and charge vouchers exactly once. Currency switchers: the built-in adapter reads the rate from the real
 * plugin (base EUR, 1 EUR = 1.10 USD), a voucher bought in USD is worth its EUR value and a gift card used in
 * USD is charged its EUR value.
 *
 * Both kinds also run with "Multi-purpose vouchers as payment" (vcap_mpv_after_tax=yes, TAX.md 4.1): a gift card pays
 * part of and the whole order after tax as a negative, untaxed payment line next to the gateway (the order lines
 * still add up to the total the gateway charges), and in a foreign-currency cart a 50 EUR card pays the USD value of
 * 50 EUR – not 50 USD – and is charged 50 EUR.
 */

$mode   = $args[0] ?? 'run';
$plugin = $args[1] ?? (string) getenv( 'VCAP_ECOSYSTEM' );

function vcap_eco_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

defined( 'VCAP_ECO_RATE' ) || define( 'VCAP_ECO_RATE', 1.1 ); // USD per EUR.

if ( 'setup' === $mode ) {
	update_option( 'woocommerce_currency', 'EUR' );
	switch ( $plugin ) {
		case 'woocs':
			$currency = static fn( string $code, float $rate, int $base ) => array( 'name' => $code, 'rate' => $rate, 'symbol' => 'EUR' === $code ? '€' : '$', 'position' => 'right', 'is_etalon' => $base, 'description' => $code, 'hide_cents' => 0, 'decimals' => 2, 'flag' => '', 'interest' => '' );
			update_option( 'woocs', array( 'EUR' => $currency( 'EUR', 1, 1 ), 'USD' => $currency( 'USD', VCAP_ECO_RATE, 0 ) ) );
			break;
		case 'curcy':
			$params = (array) get_option( 'woo_multi_currency_params', array() );
			update_option(
				'woo_multi_currency_params',
				array_merge(
					$params,
					array(
						'enable'                 => 1,
						'currency_default'       => 'EUR',
						'currency'               => array( 'EUR', 'USD' ),
						'currency_rate'          => array( '1', (string) VCAP_ECO_RATE ),
						'currency_rate_fee'      => array( '0', '0' ),
						'currency_rate_fee_type' => array( 'fixed', 'fixed' ),
						'currency_decimals'      => array( '2', '2' ),
						'currency_pos'           => array( 'right', 'left' ),
						'currency_hidden'        => array( '0', '0' ),
						'currency_custom'        => array( '', '' ),
					)
				)
			);
			break;
		case 'wcml':
			$settings                          = (array) get_option( '_wcml_settings', array() );
			$settings['enable_multi_currency'] = 2; // WCML_MULTI_CURRENCIES_INDEPENDENT.
			$option                            = static fn( float $rate ) => array( 'rate' => $rate, 'previous_rate' => '', 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'num_decimals' => 2, 'rounding' => 'disabled', 'rounding_increment' => 1, 'auto_subtract' => 0, 'languages' => array(), 'updated' => gmdate( 'Y-m-d H:i:s' ) );
			$settings['currency_options']      = array( 'USD' => $option( VCAP_ECO_RATE ) );
			update_option( '_wcml_settings', $settings );
			break;
	}
	echo "Set up {$plugin}.\n";
	return;
}

/* ---- Helpers ----------------------------------------------------------------------------------------- */

function vcap_eco_product( string $price ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( 'Ecosystem voucher ' . wp_generate_password( 4, false, false ) );
	$product->set_regular_price( $price );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->save();
	return $product;
}

function vcap_eco_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-eco-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 98000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = 'EUR';
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

function vcap_eco_balance( VCAP\Voucher $card ): float {
	return (float) vcap()->vouchers->get( $card->id )->balance;
}

/** What the gateway is asked to charge: sum of the order lines (items, shipping, fees incl. payment lines, taxes). */
function vcap_eco_lines_total( WC_Order $order ): float {
	$sum = 0.0;
	foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
		$sum += (float) $item->get_total() + (float) $item->get_total_tax();
	}
	return round( $sum, 2 );
}

/** Cart → checkout order with the card applied, under "Multi-purpose vouchers as payment". */
function vcap_eco_mpv_order( WC_Product $goods, VCAP\Voucher $card, string $payment_method, string $email ): array {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $goods->get_id(), 1 );
	WC()->cart->apply_coupon( $card->code );
	WC()->cart->calculate_totals();
	$cart  = array(
		'total'    => (float) WC()->cart->get_total( 'edit' ),
		'payment'  => (float) ( vcap()->payments->cart_payments()[ wc_format_coupon_code( $card->code ) ] ?? 0 ),
		'currency' => get_woocommerce_currency(),
	);
	$order = wc_get_order( WC()->checkout()->create_order( array( 'billing_email' => $email, 'billing_country' => 'DE', 'payment_method' => $payment_method ) ) );
	return array( $cart, $order );
}

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
foreach ( get_plugins() as $file => $data ) {
	if ( is_plugin_active( $file ) ) {
		echo "Active: {$data['Name']} {$data['Version']}\n";
	}
}

/* ---- Payment gateways -------------------------------------------------------------------------------- */

$gateways = array( 'stripe' => 'stripe', 'paypal' => 'ppcp-gateway' );
if ( isset( $gateways[ $plugin ] ) ) {
	$id         = $gateways[ $plugin ];
	$registered = array_keys( WC()->payment_gateways()->payment_gateways() );
	vcap_eco_assert( in_array( $id, $registered, true ), "the {$plugin} gateway ({$id}) is registered next to Voucher Captain", $registered );
	$gateway = WC()->payment_gateways()->payment_gateways()[ $id ];

	// Voucher purchase, payment first pending at the provider (on-hold), then confirmed twice (webhook + return).
	$product = vcap_eco_product( '40' );
	$buy     = wc_create_order();
	$buy->add_product( $product, 1 );
	$buy->set_billing_email( "eco-{$plugin}@example.com" );
	$buy->set_payment_method( $gateway );
	$buy->calculate_totals();
	$buy->update_status( 'on-hold', 'Payment pending at the provider.' );
	vcap_eco_assert( ! vcap()->vouchers->for_order( $buy->get_id() ), 'no voucher while the provider has not confirmed the payment (on-hold)' );
	wc_get_order( $buy->get_id() )->payment_complete( "{$plugin}-txn-1" );
	wc_get_order( $buy->get_id() )->payment_complete( "{$plugin}-txn-1" );
	$issued = vcap()->vouchers->for_order( $buy->get_id() );
	vcap_eco_assert( 1 === count( $issued ) && 40.0 === (float) $issued[0]->initial_amount, 'confirmed payment issues the voucher once, a repeated confirmation does not issue another' );
	vcap_eco_assert( "{$plugin}-txn-1" === wc_get_order( $buy->get_id() )->get_transaction_id() && $id === wc_get_order( $buy->get_id() )->get_payment_method(), 'the order keeps the gateway and its transaction id' );

	// Gift card + gateway pays the rest: first attempt fails, the retry succeeds.
	$card  = vcap_eco_card( 25 );
	$goods = new WC_Product_Simple();
	$goods->set_name( 'Ecosystem goods' );
	$goods->set_regular_price( '60' );
	$goods->set_virtual( true );
	$goods->save();
	$spend = wc_create_order();
	$spend->add_product( $goods, 1 );
	$spend->set_billing_email( "eco-{$plugin}-spend@example.com" );
	$spend->set_payment_method( $gateway );
	$spend->calculate_totals();
	vcap_eco_assert( true === $spend->apply_coupon( $card->code ), 'the gift card is applied next to the gateway' );
	$spend = wc_get_order( $spend->get_id() );
	vcap_eco_assert( abs( 35.0 - (float) $spend->get_total() ) < 0.001, 'the gateway is left to charge 35 of 60' );
	vcap()->coupons->reserve_order( $spend );
	wc_get_order( $spend->get_id() )->update_status( 'failed', 'Card declined.' );
	vcap_eco_assert( abs( 25.0 - vcap_eco_balance( $card ) ) < 0.001, 'a failed payment does not charge the gift card' );
	vcap()->coupons->reserve_order( wc_get_order( $spend->get_id() ) );
	wc_get_order( $spend->get_id() )->payment_complete( "{$plugin}-txn-2" );
	wc_get_order( $spend->get_id() )->payment_complete( "{$plugin}-txn-2" );
	vcap_eco_assert( vcap_eco_balance( $card ) < 0.001, 'the retried payment charges the gift card exactly once' );

	// Refund recorded without calling the provider (as after a refund in the provider's dashboard).
	$refund = wc_create_refund( array( 'order_id' => $buy->get_id(), 'amount' => 40, 'reason' => 'Refunded at the provider', 'refund_payment' => false ) );
	vcap_eco_assert( ! is_wp_error( $refund ), 'a full refund of the voucher purchase is recorded', is_wp_error( $refund ) ? $refund->get_error_message() : null );
	vcap_eco_assert( VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $issued[0]->id )->status, 'the refunded voucher is voided' );
	wc_get_order( $spend->get_id() )->update_status( 'refunded' );
	vcap_eco_assert( abs( 25.0 - vcap_eco_balance( $card ) ) < 0.001, 'a fully refunded redemption order gives the 25 back to the gift card' );

	// "Multi-purpose vouchers as payment": the card pays after tax as an untaxed negative line next to the gateway.
	update_option( VCAP\PaymentVouchers::OPTION, 'yes' );
	wc_load_cart();
	WC()->customer->set_billing_country( 'DE' );
	$part_card = vcap_eco_card( 25 );
	list( $cart, $part ) = vcap_eco_mpv_order( $goods, $part_card, $id, "eco-{$plugin}-mpv@example.com" );
	vcap_eco_assert( abs( 25.0 - $cart['payment'] ) < 0.001 && abs( 35.0 - $cart['total'] ) < 0.001, 'payment after tax: the card pays 25 of 60 in the cart, the gateway is left 35', $cart );
	vcap_eco_assert( $part && abs( 35.0 - (float) $part->get_total() ) < 0.001 && abs( 25.0 - (float) VCAP\PaymentVouchers::paid_amount( $part, $part_card->code ) ) < 0.001 && $id === $part->get_payment_method(), "payment after tax: the {$plugin} order has total 35 and a payment line of 25", $part ? array( 'total' => $part->get_total() ) : null );
	vcap_eco_assert( abs( (float) $part->get_total() - vcap_eco_lines_total( $part ) ) < 0.011, 'payment after tax: the order lines the gateway may send add up to the charged total', array( 'lines' => vcap_eco_lines_total( $part ), 'total' => $part->get_total() ) );
	vcap()->coupons->reserve_order( $part );
	wc_get_order( $part->get_id() )->payment_complete( "{$plugin}-mpv-1" );
	wc_get_order( $part->get_id() )->payment_complete( "{$plugin}-mpv-1" );
	vcap_eco_assert( vcap_eco_balance( $part_card ) < 0.001, 'payment after tax: the confirmed payment charges the card its 25 exactly once' );
	$full_card = vcap_eco_card( 100 );
	list( $cart, $full ) = vcap_eco_mpv_order( $goods, $full_card, $id, "eco-{$plugin}-mpv-full@example.com" );
	vcap_eco_assert( abs( 60.0 - $cart['payment'] ) < 0.001 && abs( $cart['total'] ) < 0.001 && $full && abs( (float) $full->get_total() ) < 0.001 && abs( 60.0 - (float) VCAP\PaymentVouchers::paid_amount( $full, $full_card->code ) ) < 0.001, 'payment after tax: a 100 card pays the whole 60, nothing is left for the gateway', $cart );
	vcap()->coupons->reserve_order( $full );
	wc_get_order( $full->get_id() )->payment_complete( "{$plugin}-mpv-2" );
	vcap_eco_assert( abs( 40.0 - vcap_eco_balance( $full_card ) ) < 0.001, 'payment after tax: the card keeps 40' );
	wc_get_order( $full->get_id() )->update_status( 'cancelled' );
	vcap_eco_assert( abs( 100.0 - vcap_eco_balance( $full_card ) ) < 0.001, 'payment after tax: cancelling gives the 60 back' );
	WC()->cart->empty_cart();
	update_option( VCAP\PaymentVouchers::OPTION, 'no' );
	echo "Voucher Captain + {$plugin} checks passed.\n";
	return;
}

/* ---- Currency switchers ------------------------------------------------------------------------------ */

if ( in_array( $plugin, array( 'woocs', 'curcy', 'wcml' ), true ) ) {
	vcap_eco_assert( 'EUR' === get_option( 'woocommerce_currency' ), 'the shop base currency is EUR' );
	$converted = (float) apply_filters( 'vcap_amount_to_base_currency', 110.0, 'USD', null );
	vcap_eco_assert( abs( 100.0 - $converted ) < 0.01, "the adapter reads the {$plugin} rate: 110 USD = 100 EUR", $converted );
	vcap_eco_assert( abs( 50.0 - (float) apply_filters( 'vcap_amount_to_base_currency', 50.0, 'EUR', null ) ) < 0.0001, 'base-currency amounts are not converted' );
	vcap_eco_assert( VCAP\Compat\MultiCurrency::can_convert( 'USD' ) && '' === VCAP\Compat\MultiCurrency::unsupported_switcher(), "{$plugin} counts as a known converter (no Site Health warning)" );
	$rate_order = wc_create_order();
	$rate_order->set_currency( 'USD' );
	$rate_order->save();
	( new VCAP\Compat\MultiCurrency() )->store_rate( $rate_order );
	vcap_eco_assert( abs( VCAP_ECO_RATE - (float) wc_get_order( $rate_order->get_id() )->get_meta( VCAP\Compat\MultiCurrency::RATE_META ) ) < 0.0001, "checkout stores the {$plugin} exchange rate on the order", wc_get_order( $rate_order->get_id() )->get_meta( VCAP\Compat\MultiCurrency::RATE_META ) );

	// A voucher bought in USD is worth its EUR value.
	$product = vcap_eco_product( '50' );
	$buy     = wc_create_order();
	$item_id = $buy->add_product( $product, 1 );
	$item    = $buy->get_item( $item_id );
	$item->set_subtotal( 55 );
	$item->set_total( 55 );
	$item->save();
	$buy->set_currency( 'USD' );
	$buy->set_billing_email( "eco-{$plugin}@example.com" );
	$buy->save();
	$buy->calculate_totals( false );
	wc_get_order( $buy->get_id() )->payment_complete( "{$plugin}-usd-1" );
	$issued = vcap()->vouchers->for_order( $buy->get_id() );
	vcap_eco_assert( 1 === count( $issued ) && abs( 50.0 - (float) $issued[0]->initial_amount ) < 0.01, 'a voucher paid with 55 USD is worth 50 EUR', $issued ? (float) $issued[0]->initial_amount : null );
	$line = VCAP\Issuer::line_data( current( wc_get_order( $buy->get_id() )->get_items() ) );
	vcap_eco_assert( 'USD' === ( $line['order_currency'] ?? '' ) && 'EUR' === ( $line['voucher_currency'] ?? '' ), 'the line keeps the paid (USD) and the stored (EUR) currency' );

	// An EUR gift card used on a USD order is charged the EUR value of the discount.
	$card  = vcap_eco_card( 50 );
	$goods = new WC_Product_Simple();
	$goods->set_name( 'Ecosystem goods USD' );
	$goods->set_regular_price( '100' );
	$goods->set_virtual( true );
	$goods->save();
	$spend   = wc_create_order();
	$item_id = $spend->add_product( $goods, 1 );
	$item    = $spend->get_item( $item_id );
	$item->set_subtotal( 110 );
	$item->set_total( 110 );
	$item->save();
	$spend->set_currency( 'USD' );
	$spend->set_billing_email( "eco-{$plugin}-spend@example.com" );
	$spend->save();
	$spend->calculate_totals( false );
	$spend->apply_coupon( $card->code );
	$spend    = wc_get_order( $spend->get_id() );
	$discount = (float) $spend->get_discount_total();
	vcap()->coupons->reserve_order( $spend );
	wc_get_order( $spend->get_id() )->payment_complete( "{$plugin}-usd-2" );
	$charged = 50.0 - vcap_eco_balance( $card );
	vcap_eco_assert( $discount > 0 && abs( $discount / VCAP_ECO_RATE - $charged ) < 0.02, 'the gift card is charged the EUR value of the USD discount', array( 'discount_usd' => $discount, 'charged_eur' => $charged ) );
	wc_get_order( $spend->get_id() )->update_status( 'cancelled' );
	vcap_eco_assert( abs( 50.0 - vcap_eco_balance( $card ) ) < 0.02, 'cancelling the USD order gives the EUR value back' );

	// "Multi-purpose vouchers as payment" in a USD cart: the 50 EUR card pays the USD value of 50 EUR (55 USD), not
	// 50 USD, and is charged 50 EUR. The cart is switched through the plugin's own API.
	$switch = static function ( string $currency ) use ( $plugin ): bool {
		global $WOOCS, $woocommerce_wpml; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( 'woocs' === $plugin && is_object( $WOOCS ) && method_exists( $WOOCS, 'set_currency' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$WOOCS->set_currency( $currency ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		} elseif ( 'curcy' === $plugin ) {
			foreach ( array( 'WOOMULTI_CURRENCY_F_Data', 'WOOMULTI_CURRENCY_Data' ) as $class ) {
				if ( class_exists( $class ) && method_exists( $class, 'get_ins' ) && method_exists( $class::get_ins(), 'set_current_currency' ) ) {
					$class::get_ins()->set_current_currency( $currency );
				}
			}
		} elseif ( 'wcml' === $plugin && isset( $woocommerce_wpml->multi_currency ) && method_exists( $woocommerce_wpml->multi_currency, 'set_client_currency' ) ) {
			$woocommerce_wpml->multi_currency->set_client_currency( $currency );
		}
		return $currency === get_woocommerce_currency();
	};
	update_option( VCAP\PaymentVouchers::OPTION, 'yes' );
	wc_load_cart();
	WC()->customer->set_billing_country( 'DE' );
	if ( $switch( 'USD' ) ) {
		$mpv_card = vcap_eco_card( 50 );
		list( $cart, $mpv ) = vcap_eco_mpv_order( $goods, $mpv_card, 'bacs', "eco-{$plugin}-mpv@example.com" );
		$expected = round( 50.0 * VCAP_ECO_RATE, 2 );
		vcap_eco_assert( 'USD' === $cart['currency'] && abs( $expected - $cart['payment'] ) < 0.02, "payment after tax in USD: the 50 EUR card pays {$expected} USD (its EUR value), not 50 USD", $cart );
		vcap_eco_assert( $mpv && 'USD' === $mpv->get_currency() && abs( (float) $mpv->get_total() - vcap_eco_lines_total( $mpv ) ) < 0.011, 'payment after tax in USD: the order lines add up to the charged total', $mpv ? array( 'total' => $mpv->get_total(), 'lines' => vcap_eco_lines_total( $mpv ) ) : null );
		vcap()->coupons->reserve_order( $mpv );
		wc_get_order( $mpv->get_id() )->payment_complete( "{$plugin}-usd-mpv" );
		vcap_eco_assert( vcap_eco_balance( $mpv_card ) < 0.02, 'payment after tax in USD: the card is charged its 50 EUR', vcap_eco_balance( $mpv_card ) );
		wc_get_order( $mpv->get_id() )->update_status( 'cancelled' );
		vcap_eco_assert( abs( 50.0 - vcap_eco_balance( $mpv_card ) ) < 0.02, 'payment after tax in USD: cancelling gives the 50 EUR back' );
	} else {
		// Not a pass: the job summary shows it, and the checklist item stays open until the cart can be switched.
		echo "::warning::{$plugin}: the cart could not be switched to USD from WP-CLI – payment after tax in a foreign currency NOT checked.\n";
	}
	$switch( 'EUR' );
	WC()->cart->empty_cart();
	update_option( VCAP\PaymentVouchers::OPTION, 'no' );
	echo "Voucher Captain + {$plugin} checks passed.\n";
	return;
}

throw new RuntimeException( "Unknown plugin '{$plugin}' (stripe, paypal, woocs, curcy, wcml)." );
