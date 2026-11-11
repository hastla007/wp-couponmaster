<?php
/**
 * The example connector (examples/vcap-example-connector) works end to end, including retries.
 *   wp eval-file tests/example-connector-integration.php
 */

function vcap_ex_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

require_once dirname( __DIR__ ) . '/examples/vcap-example-connector/vcap-example-connector.php';
VCAP_Example_Connector::boot();
$connector = new VCAP_Example_Connector();

$product = new WC_Product_Simple();
$product->set_name( 'Premium service voucher' );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_type', 'premium' );
$product->update_meta_data( '_vcap_channels', array( 'example' ) );
$product->save();

$order = wc_create_order();
$order->add_product( $product, 4 );
$order->set_billing_email( 'example-buyer@example.com' );
$order->calculate_totals();
$order->set_status( 'processing' );
$order->save();
vcap()->issuer->issue_for_order( $order, true );
$vouchers = vcap()->vouchers->for_order( $order->get_id() );
vcap_ex_assert( 4 === count( $vouchers ) && 'premium' === $vouchers[0]->type, 'premium vouchers of the connector type are issued' );

$user = wp_insert_user( array( 'user_login' => 'vcap_ex_' . strtolower( wp_generate_password( 6, false ) ), 'user_pass' => wp_generate_password(), 'user_email' => 'ex-' . strtolower( wp_generate_password( 6, false ) ) . '@example.com' ) );

$refused = 0;
try {
	vcap()->coupons->validate( true, new WC_Coupon( $vouchers[0]->coupon_id ) );
} catch ( Exception $e ) {
	$refused = (int) $e->getCode();
}
vcap_ex_assert( 9006 === $refused, 'a voucher for the connector channel is refused in the shop' );

vcap_ex_assert( 'granted' === $connector->redeem( $user, $vouchers[0]->code ) && $connector->has_access( $user ), 'redeeming unlocks the service' );
vcap_ex_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $vouchers[0]->id )->status, 'the voucher is redeemed exactly once' );
vcap_ex_assert( 'granted' === $connector->redeem( $user, $vouchers[0]->code ), 'repeating the request (double click, reload) is harmless' );

// Temporary outage of the service: the reservation stays and the retry job completes it later.
$fail = static fn() => true;
add_filter( 'vcap_example_grant_fails', $fail );
$key = VCAP_Example_Connector::key( $user, $vouchers[1]->code );
vcap_ex_assert( 'retrying' === $connector->redeem( $user, $vouchers[1]->code ), 'an outage leaves the redemption pending' );
vcap_ex_assert( 'reserved' === vcap()->redemptions->get( $key )['state'] && ! $connector->has_access( $user, $key ), 'the voucher is reserved, no access yet' );
remove_filter( 'vcap_example_grant_fails', $fail );
$connector->retry( $user, $key );
vcap_ex_assert( 'committed' === vcap()->redemptions->get( $key )['state'] && $connector->has_access( $user, $key ), 'the retry grants access and completes the redemption' );
vcap_ex_assert( ! isset( ( (array) get_user_meta( $user, VCAP_Example_Connector::PENDING, true ) )[ $key ] ), 'nothing is left pending' );

// Permanent outage: after the last attempt the voucher is given back.
add_filter( 'vcap_example_grant_fails', $fail );
$key2 = VCAP_Example_Connector::key( $user, $vouchers[2]->code );
$connector->redeem( $user, $vouchers[2]->code );
update_user_meta( $user, VCAP_Example_Connector::PENDING, array( $key2 => VCAP_Example_Connector::MAX_TRIES ) );
$connector->retry( $user, $key2 );
remove_filter( 'vcap_example_grant_fails', $fail );
vcap_ex_assert( 'released' === vcap()->redemptions->get( $key2 )['state'] && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $vouchers[2]->id )->status, 'after the last failed attempt the voucher is released and usable again' );

// Wrong voucher type for the target.
$plain = new WC_Product_Simple();
$plain->set_name( 'Plain voucher' );
$plain->set_regular_price( '10' );
$plain->set_virtual( true );
$plain->update_meta_data( '_vcap_enabled', 'yes' );
$plain->save();
$plain_order = wc_create_order();
$plain_order->add_product( $plain, 1 );
$plain_order->set_billing_email( 'example-buyer@example.com' );
$plain_order->calculate_totals();
$plain_order->set_status( 'processing' );
$plain_order->save();
vcap()->issuer->issue_for_order( $plain_order, true );
$plain_voucher = vcap()->vouchers->for_order( $plain_order->get_id() )[0];
vcap_ex_assert( ! in_array( $connector->redeem( $user, $plain_voucher->code ), array( 'granted', 'retrying' ), true ), 'other voucher types cannot unlock the service' );

// Refund after the redemption: the example policy takes the access back.
vcap()->issuer->void( vcap()->vouchers->get( $vouchers[0]->id ), 'Purchase order refunded' );
vcap_ex_assert( ! $connector->has_access( $user, VCAP_Example_Connector::key( $user, $vouchers[0]->code ) ), 'voiding a redeemed voucher revokes the access (example policy)' );
echo "Voucher Captain example connector checks passed.\n";
