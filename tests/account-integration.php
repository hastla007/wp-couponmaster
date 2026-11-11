<?php
/**
 * Transferability, redemption conditions and customer resend checks.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/account-integration.php
 */

function vcap_acct_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_acct_product( array $meta ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( 'Voucher test ' . wp_generate_password( 6, false, false ) );
	$product->set_regular_price( '50' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	foreach ( $meta as $key => $value ) {
		$product->update_meta_data( '_vcap_' . $key, $value );
	}
	$product->save();
	return $product;
}

function vcap_acct_order( WC_Product $product, int $customer_id, string $email ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Test' );
	$order->set_billing_last_name( 'Buyer' );
	$order->calculate_totals();
	$order->save();
	return $order;
}

$login = 'vcap_acct_' . strtolower( wp_generate_password( 6, false, false ) );
$buyer = (int) wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '@example.com',
		'role'       => 'customer',
	)
);
$other = (int) wp_insert_user(
	array(
		'user_login' => $login . '_x',
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '_x@example.com',
		'role'       => 'customer',
	)
);

// Personal voucher with its own conditions.
$personal = vcap_acct_product(
	array(
		'transferable'     => 'no',
		'redemption_terms' => 'Nicht bar auszahlbar.',
		'email_restrict'   => 'none',
	)
);
$order    = vcap_acct_order( $personal, $buyer, $login . '@example.com' );
$issued   = vcap()->issuer->issue_for_order( $order );
vcap_acct_assert( 1 === count( $issued ), 'one voucher is issued for the order' );
$voucher = vcap()->vouchers->get( $issued[0]->id );
vcap_acct_assert( false === $voucher->transferable && 'Nicht bar auszahlbar.' === $voucher->terms, 'transferability and conditions are stored with the voucher' );
$coupon = new WC_Coupon( $voucher->coupon_id );
vcap_acct_assert( array( $login . '@example.com' ) === array_values( $coupon->get_email_restrictions() ), 'a personal voucher restricts the coupon to the buyer (and recipient) email' );

$personal->update_meta_data( '_vcap_redemption_terms', 'Changed later.' );
$personal->save();
$values = VCAP\Placeholders::values( vcap()->vouchers->get( $voucher->id ) );
vcap_acct_assert( 'Nicht bar auszahlbar.' === $values['redemption_terms'], 'later product changes do not alter conditions of sold vouchers' );
vcap_acct_assert( $voucher->transferable_label() === $values['transferable'], '{transferable} placeholder describes the voucher' );

// Shop default conditions apply when the product has none; transferable is the default.
update_option( 'vcap_default_terms', 'Standardbedingungen.' );
$default_product = vcap_acct_product( array() );
$default_order   = vcap_acct_order( $default_product, $buyer, $login . '@example.com' );
$default_voucher = vcap()->issuer->issue_for_order( $default_order )[0];
vcap_acct_assert( true === $default_voucher->transferable && 'Standardbedingungen.' === $default_voucher->terms, 'shop default conditions are used when the product has none' );
vcap_acct_assert( array() === ( new WC_Coupon( $default_voucher->coupon_id ) )->get_email_restrictions(), 'transferable vouchers are not email restricted' );
delete_option( 'vcap_default_terms' );

// Customer resend: only the buyer, only delivered vouchers.
$voucher               = vcap()->vouchers->get( $voucher->id );
$voucher->status       = VCAP\Voucher::STATUS_ACTIVE;
$voucher->delivered_at = gmdate( 'Y-m-d H:i:s' );
vcap()->vouchers->update( $voucher );
wp_set_current_user( $buyer );
vcap_acct_assert( vcap()->downloads->customer_can_resend( $voucher ), 'the buyer may resend a delivered voucher' );
wp_set_current_user( $other );
vcap_acct_assert( ! vcap()->downloads->customer_can_resend( $voucher ), 'another customer may not resend it' );
wp_set_current_user( $buyer );
$voucher->delivered_at = null;
$voucher->status       = VCAP\Voucher::STATUS_SCHEDULED;
vcap_acct_assert( ! vcap()->downloads->customer_can_resend( $voucher ), 'a scheduled gift cannot be resent before its delivery date' );
wp_set_current_user( 0 );

echo "Voucher Captain account checks passed.\n";
