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
vcap_conn_assert( VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $vouchers[1]->id )->status && VCAP\Voucher::STATUS_VOID !== vcap()->vouchers->get( $vouchers[0]->id )->status, 'the line refund voids the unused voucher, not the one in use' );
// Refund before the external redemption is completed: the voided voucher can no longer be committed.
list( , $late_vouchers ) = vcap_conn_issue( $any, 1 ); // Unrestricted: $external is shop-only by now.
$late_key = 'conn-' . wp_generate_uuid4();
$late     = $service->reserve( $late_vouchers[0]->code, array( 'key' => $late_key, 'target' => 'test:course:8' ) );
vcap_conn_assert( ! is_wp_error( $late ), 'a voucher is reserved externally' );
vcap()->issuer->void( vcap()->vouchers->get( $late_vouchers[0]->id ), 'Purchase order refunded' );
$commit = $service->commit( $late_key );
vcap_conn_assert( is_wp_error( $commit ) && 'vcap_cancelled' === $commit->get_error_code(), 'a voucher voided during the reservation cannot be committed' );
$released = $service->release( $late_key );
vcap_conn_assert( ! is_wp_error( $released ) && VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $late_vouchers[0]->id )->status, 'releasing the reservation keeps the voucher voided' );
// Refunds of completed external redemptions (partial, idempotent, capped).
$gift = new WC_Coupon();
$gift->set_code( 'conn-gift-' . strtolower( wp_generate_password( 8, false ) ) );
$gift->set_discount_type( 'fixed_cart' );
$gift->set_amount( 50 );
$gift->update_meta_data( '_vcap_voucher', 'yes' );
$gift->save();
$card                 = new VCAP\Voucher();
$card->code           = wc_format_coupon_code( $gift->get_code() );
$card->coupon_id      = $gift->get_id();
$card->order_item_id  = 98000000 + $gift->get_id();
$card->type           = 'giftcard';
$card->usage_mode     = 'balance';
$card->discount_type  = 'fixed_cart';
$card->initial_amount = 50;
$card->balance        = 50;
$card->currency       = get_woocommerce_currency();
$card->status         = VCAP\Voucher::STATUS_ACTIVE;
$card->send_to        = 'buyer';
$card->token          = VCAP\VoucherRepository::new_token();
$card->id             = vcap()->vouchers->insert( $card );
$rkey = 'conn-r-' . wp_generate_uuid4();
$service->reserve( $card->code, array( 'key' => $rkey, 'target' => 'test:refund', 'amount' => 30 ) );
vcap_conn_assert( 'vcap_not_committed' === $service->refund( $rkey, 10, 'conn-refund-0001' )->get_error_code(), 'reservations that are not completed cannot be refunded' );
$service->commit( $rkey );
$first = $service->refund( $rkey, 10, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $first ) && 10.0 === $first['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'a partial refund returns the amount to the gift card balance' );
$again = $service->refund( $rkey, 10, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $again ) && 0.0 === $again['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'repeating the same refund key changes nothing' );
$retry = $service->refund( $rkey, 7.5, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $retry ) && 0.0 === $retry['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'a retry of a known refund key answers with the stored result, even with a recalculated amount' );
$rest = $service->refund( $rkey, 50, 'conn-refund-0002' );
vcap_conn_assert( ! is_wp_error( $rest ) && 20.0 === $rest['restored'] && 30.0 === $rest['refunded'] && 50.0 === vcap()->vouchers->get( $card->id )->balance, 'refunds are capped at the redeemed amount' );
vcap_conn_assert( 'vcap_refund_exceeded' === $service->refund( $rkey, 1, 'conn-refund-0003' )->get_error_code(), 'a fully refunded redemption cannot be refunded again' );


function vcap_conn_voucher( string $type, string $mode, float $value, array $emails = array() ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'conn-' . $type . '-' . strtolower( wp_generate_password( 8, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->set_email_restrictions( $emails );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 97000000 + $coupon->get_id();
	$v->type           = $type;
	$v->usage_mode     = $mode;
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

// Store credit belongs to one customer account: never redeemable through the redemption API.
$credit = vcap_conn_voucher( 'credit', 'balance', 40 );
$refused = $service->check( $credit->code, array( 'target' => 'test:credit' ) );
vcap_conn_assert( is_wp_error( $refused ) && 'vcap_not_redeemable' === $refused->get_error_code(), 'store credit cannot be redeemed through the redemption API' );

// Personal vouchers: a trusted PHP caller (guest checkout of a connector) may pass the customer email.
$personal = vcap_conn_voucher( 'voucher', 'single', 20, array( 'guest-buyer@example.com' ) );
vcap_conn_assert( is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal' ) ) ), 'a personal voucher is refused without the customer email' );
vcap_conn_assert( ! is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal', 'email' => 'Guest-Buyer@example.com' ) ) ), 'a personal voucher is accepted with the matching customer email' );

// Reactivating a voided single-use voucher that was already redeemed keeps it redeemed.
$single = vcap_conn_voucher( 'voucher', 'single', 25 );
$skey   = 'conn-s-' . wp_generate_uuid4();
$service->reserve( $single->code, array( 'key' => $skey, 'target' => 'test:single' ) );
$service->commit( $skey );
vcap()->issuer->void( vcap()->vouchers->get( $single->id ), 'Purchase order refunded' );
vcap()->issuer->reactivate( vcap()->vouchers->get( $single->id ) );
vcap_conn_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $single->id )->status, 'reactivating a redeemed single-use voucher does not make it usable again' );
$unused = vcap_conn_voucher( 'voucher', 'single', 25 );
vcap()->issuer->void( vcap()->vouchers->get( $unused->id ), 'Purchase order refunded' );
vcap()->issuer->reactivate( vcap()->vouchers->get( $unused->id ) );
vcap_conn_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $unused->id )->status, 'reactivating an unused voucher makes it active again' );

echo "Voucher Captain connector checks passed.\n";
