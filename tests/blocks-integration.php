<?php
/**
 * Voucher fields with WooCommerce blocks (Store API: product collection buttons, cart and checkout blocks).
 *   wp eval-file tests/blocks-integration.php
 */

function vcap_blocks_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
wc_load_cart();
WC()->cart->empty_cart();

$product = new WC_Product_Simple();
$product->set_name( 'Block voucher' );
$product->set_regular_price( '25' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_builtin_fields', array( 'recipient_name', 'recipient_email_required', 'message' ) );
$product->save();

function vcap_blocks_add( int $id, array $fields ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_param( 'id', $id );
	$request->set_param( 'quantity', 1 );
	if ( $fields ) {
		$request->set_param( 'vcap_fields', $fields );
	}
	return rest_do_request( $request );
}

$missing = vcap_blocks_add( $product->get_id(), array() );
vcap_blocks_assert( $missing->is_error() && 0 === count( WC()->cart->get_cart() ), 'a voucher with a required recipient email cannot be added without it' );

$invalid = vcap_blocks_add( $product->get_id(), array( 'recipient_email' => 'not-an-email' ) );
vcap_blocks_assert( $invalid->is_error(), 'invalid field values are refused' );

$ok = vcap_blocks_add( $product->get_id(), array( 'recipient_email' => 'block@example.com', 'message' => 'Happy birthday' ) );
vcap_blocks_assert( ! $ok->is_error(), 'the voucher is added with its fields through the Store API' );
$items = array_values( WC()->cart->get_cart() );
vcap_blocks_assert( 1 === count( $items ) && 'block@example.com' === ( $items[0]['vcap_fields']['recipient_email']['value'] ?? '' ), 'the fields are stored as cart item data' );

$cart = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data();
$data = wp_json_encode( $cart['items'][0]['item_data'] ?? array() );
vcap_blocks_assert( false !== strpos( (string) $data, 'block@example.com' ) && false !== strpos( (string) $data, 'Happy birthday' ), 'the cart block shows the voucher fields' );

$second = vcap_blocks_add( $product->get_id(), array( 'recipient_email' => 'other@example.com' ) );
vcap_blocks_assert( ! $second->is_error() && 2 === count( WC()->cart->get_cart() ), 'different personalisations become separate cart lines' );

$order = wc_create_order();
foreach ( WC()->cart->get_cart() as $key => $values ) {
	$item = new WC_Order_Item_Product();
	$item->set_product( $values['data'] );
	$item->set_quantity( $values['quantity'] );
	do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $values, $order );
	$order->add_item( $item );
}
$order->save();
$emails = array();
foreach ( $order->get_items() as $item ) {
	$emails[] = $item->get_meta( '_vcap_fields' )['recipient_email']['value'] ?? '';
}
sort( $emails );
vcap_blocks_assert( array( 'block@example.com', 'other@example.com' ) === $emails, 'checkout writes the fields onto the order lines' );

WC()->cart->empty_cart();

// Cart and checkout blocks: voucher payments after tax, remaining balance and the store credit hint come with
// the Store API cart (extensions["voucher-captain"]), not only from the classic PHP hooks.
update_option( VCAP\PaymentVouchers::OPTION, 'yes' );
$card_product = new WC_Product_Simple();
$card_product->set_name( 'Block gift card' );
$card_product->set_regular_price( '30' );
$card_product->set_virtual( true );
$card_product->update_meta_data( '_vcap_enabled', 'yes' );
$card_product->update_meta_data( '_vcap_type', 'giftcard' );
$card_product->update_meta_data( '_vcap_usage', 'balance' );
$card_product->update_meta_data( '_vcap_tax_treatment', 'payment' );
$card_product->save();
$block_coupon = new WC_Coupon();
$block_coupon->set_code( 'vcap-block-' . strtolower( wp_generate_password( 10, false, false ) ) );
$block_coupon->set_discount_type( 'fixed_cart' );
$block_coupon->set_amount( 30 );
$block_coupon->update_meta_data( '_vcap_voucher', 'yes' );
$block_coupon->save();
$block_card                 = new VCAP\Voucher();
$block_card->code           = wc_format_coupon_code( $block_coupon->get_code() );
$block_card->coupon_id      = $block_coupon->get_id();
$block_card->order_item_id  = 96000000 + $block_coupon->get_id();
$block_card->product_id     = $card_product->get_id();
$block_card->type           = 'giftcard';
$block_card->usage_mode     = 'balance';
$block_card->discount_type  = 'fixed_cart';
$block_card->initial_amount = 30;
$block_card->balance        = 30;
$block_card->currency       = get_woocommerce_currency();
$block_card->status         = VCAP\Voucher::STATUS_ACTIVE;
$block_card->send_to        = 'buyer';
$block_card->token          = VCAP\VoucherRepository::new_token();
$block_card->id             = vcap()->vouchers->insert( $block_card );
$block_goods = new WC_Product_Simple();
$block_goods->set_name( 'Block goods' );
$block_goods->set_regular_price( '40' );
$block_goods->set_virtual( true );
$block_goods->save();
WC()->cart->add_to_cart( $block_goods->get_id(), 1 );
WC()->cart->apply_coupon( $block_card->code );
$block_cart = json_decode( (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data() ), true ); // Extension data may be objects.
$block_ext  = $block_cart['extensions']['voucher-captain'] ?? array();
$minor      = 10 ** wc_get_price_decimals();
vcap_blocks_assert( isset( $block_ext['payments'][0] ) && $block_card->code === $block_ext['payments'][0]['code'] && (string) ( 30 * $minor ) === $block_ext['payments'][0]['amount'] && false !== strpos( $block_ext['payments'][0]['label'], strtoupper( $block_card->code ) ), 'Store API cart: the voucher payment row "Voucher CODE" with its amount (30)' );
vcap_blocks_assert( (string) ( 10 * $minor ) === (string) ( $block_cart['totals']['total_price'] ?? '' ), 'Store API cart: the total is what is left after the voucher payment (40 − 30 = 10)' );
vcap_blocks_assert( in_array( $block_card->code, $block_ext['paying_coupons'] ?? array(), true ) && '' !== (string) ( $block_ext['coupon_note'] ?? '' ), 'Store API cart: the paying coupon is marked, with the note for its coupon row' );
vcap_blocks_assert( isset( $block_ext['balances'][0] ) && $block_card->code === $block_ext['balances'][0]['code'] && '' !== $block_ext['balances'][0]['formatted'], 'Store API cart: the remaining balance of the applied gift card' );
vcap_blocks_assert( null === ( $block_ext['store_credit'] ?? null ), 'Store API cart: no store credit hint for guests' );
WC()->cart->empty_cart();
update_option( VCAP\PaymentVouchers::OPTION, 'no' );

update_option( 'vcap_store_credit', 'yes' );
$block_user = wp_insert_user(
	array(
		'user_login' => 'vcap-block-' . wp_generate_password( 6, false, false ),
		'user_email' => 'vcap-block-' . wp_generate_password( 6, false, false ) . '@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
wp_set_current_user( $block_user );
vcap()->store_credit->adjust( $block_user, 15, 'Block test' );
WC()->cart->add_to_cart( $block_goods->get_id(), 1 );
$credit_ext = json_decode( (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data() ), true )['extensions']['voucher-captain'] ?? array();
vcap_blocks_assert( is_array( $credit_ext['store_credit'] ?? null ) && false !== strpos( $credit_ext['store_credit']['url'], 'vcap_apply_credit' ) && '' !== $credit_ext['store_credit']['text'], 'Store API cart: the "use store credit" hint with its link for a customer with credit' );
wp_set_current_user( 0 );
WC()->cart->empty_cart();
echo "Voucher Captain block checks passed.\n";
