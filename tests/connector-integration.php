<?php
/**
 * Connector extension points: custom voucher types, redemption channels, per-line order/refund data.
 *   wp eval-file tests/connector-integration.php
 */

function vcap_conn_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

add_filter( 'vcap_voucher_types', static fn( $types ) => $types + array( 'course' => 'Course voucher', 'ticket' => 'Overwritten?' ) );
$types = VCAP\Voucher::types();
vcap_conn_assert( 'Course voucher' === ( $types['course'] ?? '' ) && 'Overwritten?' !== $types['ticket'], 'plugins can add voucher types but not replace built-in ones' );

function vcap_conn_product( string $name, array $channels, string $type = 'voucher' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '40' );
	$p->set_virtual( true );
	$p->update_meta_data( '_vcap_enabled', 'yes' );
	$p->update_meta_data( '_vcap_type', $type );
	$p->update_meta_data( '_vcap_channels', $channels );
	$p->save();
	return $p;
}

function vcap_conn_issue( WC_Product $product, int $qty ): array {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_billing_email( 'connector@example.com' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
	vcap()->issuer->issue_for_order( $order, true );
	$order = wc_get_order( $order->get_id() );
	return array( $order, vcap()->vouchers->for_order( $order->get_id() ) );
}

$external = vcap_conn_product( 'Course voucher product', array( 'external' ), 'course' );
list( $order, $vouchers ) = vcap_conn_issue( $external, 2 );
vcap_conn_assert( 2 === count( $vouchers ) && 'course' === $vouchers[0]->type, 'a voucher of a custom type is issued' );

$items = $order->get_items();
$item  = reset( $items );
$line  = VCAP\Issuer::line_data( $item );
vcap_conn_assert(
	40.0 === (float) $line['face_value'] && 2 === $line['vouchers'] && $order->get_currency() === $line['order_currency'] && get_woocommerce_currency() === $line['voucher_currency']
		&& 80.0 === (float) $line['total'] && array_key_exists( 'total_tax', $line ) && array_key_exists( 'tax_class', $line ) && array( 'external' ) === $line['channels'],
	'value, currency, tax data and channels are stored on the order line'
);

$service = vcap()->redemptions;
$code    = $vouchers[0]->code;
$refused = 0;
try {
	vcap()->coupons->validate( true, new WC_Coupon( $code ) );
} catch ( Exception $e ) {
	$refused = (int) $e->getCode();
}
vcap_conn_assert( 9006 === $refused, 'an external-only voucher is refused in the shop' );
$key      = 'conn-' . wp_generate_uuid4();
$reserved = $service->reserve( $code, array( 'key' => $key, 'target' => 'test:course:7' ) );
vcap_conn_assert( ! is_wp_error( $reserved ) && 'reserved' === $reserved['state'], 'an external-only voucher can be redeemed through the API' );
$service->release( $key );

$shop = vcap_conn_product( 'Shop only voucher', array( 'shop' ) );
list( , $shop_vouchers ) = vcap_conn_issue( $shop, 1 );
$blocked = $service->reserve( $shop_vouchers[0]->code, array( 'key' => 'conn-' . wp_generate_uuid4(), 'target' => 'test:course:7' ) );
vcap_conn_assert( is_wp_error( $blocked ) && 'vcap_not_redeemable' === $blocked->get_error_code(), 'a shop-only voucher is refused by the redemption API' );
$any = vcap_conn_product( 'Unrestricted voucher', array() );
list( , $any_vouchers ) = vcap_conn_issue( $any, 1 );
vcap_conn_assert( VCAP\Channels::allowed( $any_vouchers[0], 'shop' ) && VCAP\Channels::allowed( $any_vouchers[0], 'external' ), 'without a selection every channel is allowed' );

// Later product changes do not change what was sold.
$external->update_meta_data( '_vcap_channels', array( 'shop' ) );
$external->save();
vcap_conn_assert( ! VCAP\Channels::allowed( $vouchers[1], 'shop' ), 'channels are fixed when the voucher is sold' );

// Per-line order and refund data for connectors.
$refund = wc_create_refund(
	array(
		'order_id'   => $order->get_id(),
		'amount'     => 40,
		'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 40 ) ),
	)
);
vcap_conn_assert( ! is_wp_error( $refund ), 'a line refund is recorded' );
$lines = vcap()->api_v1->order_lines( wc_get_order( $order->get_id() ) );
$data  = $lines[ $item->get_id() ] ?? array();
vcap_conn_assert( 2 === $data['quantity'] && 1 === $data['refunded_quantity'] && 40.0 === $data['refunded_total'], 'order_lines() reports quantities and refunds per line' );
vcap_conn_assert( 2 === count( $data['vouchers'] ) && $code === $data['vouchers'][0]['code'] && 40.0 === (float) $data['voucher_line']['face_value'], 'order_lines() lists the vouchers and the line snapshot' );
echo "Voucher Captain connector checks passed.\n";
