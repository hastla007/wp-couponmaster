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
echo "Voucher Captain block checks passed.\n";
