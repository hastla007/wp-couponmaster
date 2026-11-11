<?php
/**
 * Shop data for the browser tests (wp eval-file tests/e2e/setup.php). Writes tests/e2e/.state.json.
 */
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE:' );
update_option( 'woocommerce_store_address', 'Teststraße 1' );
update_option( 'woocommerce_store_city', 'Berlin' );
update_option( 'woocommerce_store_postcode', '10115' );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'DE', 'AT' ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery', 'enable_for_virtual' => 'yes', 'enable_for_methods' => array() ) );
update_option( 'vcap_store_credit', 'yes' );

WC_Install::create_pages();

function vcap_e2e_page( string $slug, string $title, string $content ): int {
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	return (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ) );
}

$state = array(
	'block_cart'       => (int) get_option( 'woocommerce_cart_page_id' ),
	'block_checkout'   => (int) get_option( 'woocommerce_checkout_page_id' ),
	'classic_cart'     => vcap_e2e_page( 'classic-cart', 'Classic cart', "<!-- wp:shortcode -->\n[woocommerce_cart]\n<!-- /wp:shortcode -->" ),
	'classic_checkout' => vcap_e2e_page( 'classic-checkout', 'Classic checkout', "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->" ),
	'tools'            => vcap_e2e_page( 'voucher-tools', 'Voucher tools', "<!-- wp:shortcode -->\n[vcap_balance]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[vcap_redeem]\n<!-- /wp:shortcode -->" ),
	'blocks'           => vcap_e2e_page( 'voucher-blocks', 'Voucher blocks', "<!-- wp:vcap/balance /-->\n\n<!-- wp:vcap/redeem /-->" ),
);

$voucher = new WC_Product_Simple();
$voucher->set_name( 'E2E Voucher' );
$voucher->set_slug( 'e2e-voucher' );
$voucher->set_regular_price( '25' );
$voucher->set_virtual( true );
$voucher->set_status( 'publish' );
$voucher->update_meta_data( '_vcap_enabled', 'yes' );
$voucher->update_meta_data( '_vcap_builtin_fields', array( 'recipient_name', 'recipient_email_required', 'message' ) );
$voucher->save();
$state['voucher_product'] = $voucher->get_id();

$coupon = new WC_Coupon();
$coupon->set_code( 'e2e10' );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->save();

$customer = wp_insert_user( array( 'user_login' => 'e2e-customer', 'user_pass' => 'e2e-Pass-123!', 'user_email' => 'e2e-customer@example.test', 'role' => 'customer' ) );
$state['customer'] = (int) $customer;
$order = wc_create_order( array( 'customer_id' => $customer ) );
$order->add_product( $voucher, 1 );
$order->set_billing_email( 'e2e-customer@example.test' );
$order->calculate_totals();
$order->set_status( 'processing' );
$order->save();
vcap()->store_credit->adjust( (int) $customer, 15, 'E2E credit' );

file_put_contents( __DIR__ . '/.state.json', wp_json_encode( $state ) );
echo "E2E shop prepared.\n";
