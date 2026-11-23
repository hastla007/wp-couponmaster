<?php
/**
 * A real block checkout through the Store API (M12-58): rest_do_request( POST /wc/store/v1/checkout ) with a gift
 * card in the cart and a test gateway that declines the first attempt; the retry with the same cart succeeds. The
 * card is charged once, for the order that was paid, and is never charged for the declined attempt. Runs in both
 * modes of "Multi-purpose vouchers as payment" (coupon discount, payment line after tax).
 *   wp eval-file tests/store-checkout-integration.php
 */

function vcap_sco_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

require_once __DIR__ . '/state-guard.php';
$vcap_restore = vcap_test_state_guard( array( VCAP\PaymentVouchers::OPTION, 'woocommerce_calc_taxes', 'woocommerce_vcap_e2e_fail_settings' ) );
try {

/** Test gateway: declines while $GLOBALS['vcap_sco_decline'] is set, otherwise takes the payment. */
class VCAP_SCO_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'vcap_sco';
		$this->title              = 'Test gateway';
		$this->method_title       = 'Test gateway';
		$this->enabled            = 'yes';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->method_description = 'Declines while asked to (tests).';
	}

	public function is_available() {
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! empty( $GLOBALS['vcap_sco_decline'] ) ) {
			$order->update_status( 'failed', 'Test gateway: card declined.' );
			throw new Exception( 'Card declined.' );
		}
		$order->payment_complete( 'sco-' . $order_id );
		return array( 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() );
	}
}
add_filter( 'woocommerce_payment_gateways', static fn( $gateways ) => array_merge( (array) $gateways, array( 'VCAP_SCO_Gateway' ) ) );
WC()->payment_gateways()->init();
// The REST server of this process registered its routes before the test gateway existed (the checkout schema
// lists the gateway ids as an enum): start a new one so the routes are registered again.
$GLOBALS['wp_rest_server'] = null;
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
update_option( 'woocommerce_calc_taxes', 'no' );

$goods = new WC_Product_Simple();
$goods->set_name( 'Store API checkout goods' );
$goods->set_regular_price( '30' );
$goods->set_virtual( true );
$goods->set_status( 'publish' );
$goods->save();

/** A gift card as the plugin issues it (coupon + voucher row). */
function vcap_sco_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-sco-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 95000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

/** POST /wc/store/v1/checkout as the block checkout sends it. */
function vcap_sco_checkout( string $email ): WP_REST_Response {
	$address = array( 'first_name' => 'Store', 'last_name' => 'Api', 'address_1' => 'Blockweg 2', 'city' => 'Berlin', 'postcode' => '10115', 'country' => 'DE', 'email' => $email );
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		(string) wp_json_encode(
			array(
				'billing_address'  => $address,
				'shipping_address' => $address,
				'payment_method'   => 'vcap_sco',
				'payment_data'     => array(),
			)
		)
	);
	return rest_do_request( $request );
}

foreach ( array( 'no', 'yes' ) as $after_tax ) {
	update_option( VCAP\PaymentVouchers::OPTION, $after_tax );
	$label = 'yes' === $after_tax ? 'payment after tax' : 'coupon discount';
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->session->set( 'store_api_draft_order', 0 );
	$card = vcap_sco_card( 20 );
	$add  = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$add->set_param( 'id', $goods->get_id() );
	$add->set_param( 'quantity', 1 );
	$added  = rest_do_request( $add );
	$coupon = new WP_REST_Request( 'POST', '/wc/store/v1/cart/apply-coupon' );
	$coupon->set_param( 'code', $card->code );
	$applied = rest_do_request( $coupon );
	vcap_sco_assert( ! $added->is_error() && ! $applied->is_error(), "{$label}: the goods and the gift card are in the Store API cart", array( $added->get_status(), $applied->get_data() ) );

	// First attempt: the gateway declines.
	$GLOBALS['vcap_sco_decline'] = true;
	$email    = "store-checkout-{$after_tax}@example.test";
	$declined = vcap_sco_checkout( $email );
	$draft    = (int) WC()->session->get( 'store_api_draft_order', 0 );
	$failed   = $draft ? wc_get_order( $draft ) : null;
	vcap_sco_assert( 400 <= $declined->get_status() && $failed && $failed->has_status( array( 'failed', 'pending', 'checkout-draft' ) ), "{$label}: the declined payment answers with an error and leaves the order unpaid", array( $declined->get_status(), $declined->get_data(), $failed ? $failed->get_status() : null ) );
	vcap_sco_assert( abs( 20.0 - (float) vcap()->vouchers->get( $card->id )->balance ) < 0.001, "{$label}: the declined attempt charges nothing on the card", vcap()->vouchers->get( $card->id )->balance );

	// Retry with the same cart (the customer presses "Place order" again).
	$GLOBALS['vcap_sco_decline'] = false;
	$paid_response = vcap_sco_checkout( $email );
	$paid_data     = $paid_response->get_data();
	$paid          = wc_get_order( (int) ( $paid_data['order_id'] ?? 0 ) );
	vcap_sco_assert( 200 === $paid_response->get_status() && $paid && $paid->is_paid(), "{$label}: the retry succeeds and the order is paid", array( $paid_response->get_status(), $paid_data['payment_result'] ?? $paid_data ) );
	vcap_sco_assert( abs( 10.0 - (float) $paid->get_total() ) < 0.001 && ( 'yes' === $after_tax ? abs( 20.0 - (float) VCAP\PaymentVouchers::paid_amount( $paid, $card->code ) ) < 0.001 : in_array( $card->code, array_map( 'wc_format_coupon_code', $paid->get_coupon_codes() ), true ) ), "{$label}: the paid order totals 10 with the card's 20", array( 'total' => $paid->get_total(), 'coupons' => $paid->get_coupon_codes() ) );
	$fresh   = vcap()->vouchers->get( $card->id );
	$charges = array_values( array_filter( vcap()->vouchers->events( $card->id ), static fn( $e ) => in_array( $e['event'], array( 'redeemed', 'committed' ), true ) ) );
	$orders  = array_values( array_unique( array_map( static fn( $e ) => (int) $e['order_id'], $charges ) ) );
	vcap_sco_assert( abs( (float) $fresh->balance ) < 0.001 && array( $paid->get_id() ) === $orders && abs( 20.0 - array_sum( array_map( static fn( $e ) => (float) $e['amount'], $charges ) ) ) < 0.001, "{$label}: the card is charged 20 once, for the paid order only (ledger)", array( 'balance' => $fresh->balance, 'charges' => $charges ) );
	vcap_sco_assert( 0.0 === vcap()->coupons->open_holds( $fresh ), "{$label}: no reservation of the declined attempt is left on the card", vcap()->coupons->open_holds( $fresh ) );
	WC()->cart->empty_cart();
}
unset( $GLOBALS['vcap_sco_decline'] );

echo "Voucher Captain Store API checkout checks passed.\n";
} finally {
	$vcap_restore();
}
