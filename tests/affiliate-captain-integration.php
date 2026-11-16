<?php
/**
 * WP Affiliate Captain adapter (partner per code campaign). Runs with and without Affiliate Captain installed:
 * without it, the two contract functions are stubbed deterministically.
 *   wp eval-file tests/affiliate-captain-integration.php
 */

function vcap_aff_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

if ( ! function_exists( 'wpac_contract_version' ) ) {
	function wpac_contract_version() { return '1.1.0'; }
}
if ( ! function_exists( 'wpac_resolve_partner_code' ) ) {
	function wpac_resolve_partner_code( $code, array $context = array() ) { return apply_filters( 'wpac_resolve_partner_code', null, (string) $code, $context ); }
}
// A deterministic partner for this test, answered before Affiliate Captain's own lookup (if installed).
add_filter( 'wpac_resolve_partner_code', static fn( $r, $code ) => 'VCAP-TEST-PARTNER' === strtoupper( (string) $code ) ? array( 'affiliate_id' => 9999, 'code_id' => 1, 'code' => 'VCAP-TEST-PARTNER' ) : $r, 1, 2 );

use VCAP\Compat\AffiliateCaptain;

vcap_aff_assert( AffiliateCaptain::available(), 'adapter is available for contract 1.x' );
( new AffiliateCaptain() )->init();
vcap_aff_assert( isset( apply_filters( 'wpac_registered_integrations', array() )['voucher-captain'] ), 'integration is listed on the Affiliate Captain status page' );

wp_set_current_user( 1 );
$template = new WC_Coupon();
$template->set_code( 'vcap-aff-tpl-' . strtolower( wp_generate_password( 6, false, false ) ) );
$template->set_discount_type( 'percent' );
$template->set_amount( 10 );
$template->save();
$campaigns = new VCAP\Campaigns( vcap()->code_pool, vcap()->vouchers );
$name      = 'Affiliate test ' . wp_generate_password( 6, false, false );
$codes     = $campaigns->generate_coupons( $template->get_id(), $name, 2, array( 'prefix' => 'AFF' ) );
vcap_aff_assert( is_array( $codes ) && 2 === count( $codes ), 'campaign codes generated' );

vcap_aff_assert( null === apply_filters( 'wpac_coupon_affiliate', null, $codes[0] ), 'codes of an unassigned campaign belong to no partner' );
$bad = AffiliateCaptain::assign( $name, 'does-not-exist' );
vcap_aff_assert( is_wp_error( $bad ) && '' === AffiliateCaptain::partner_for_campaign( $name ), 'unknown partner codes are refused' );
vcap_aff_assert( true === AffiliateCaptain::assign( $name, 'vcap-test-partner' ), 'a valid partner code is saved' );
vcap_aff_assert( 'VCAP-TEST-PARTNER' === AffiliateCaptain::partner_for_campaign( $name ), 'the partner code is stored normalised' );

$resolved = apply_filters( 'wpac_coupon_affiliate', null, strtoupper( $codes[1] ) );
vcap_aff_assert( is_array( $resolved ) && 9999 === (int) $resolved['affiliate_id'], 'every code of the campaign resolves to the partner (case-insensitive)' );
$direct = array( 'affiliate_id' => 1, 'code_id' => 2, 'code' => 'DIRECT' );
vcap_aff_assert( $direct === apply_filters( 'wpac_coupon_affiliate', $direct, $codes[0] ), 'a direct coupon link in Affiliate Captain keeps priority' );
vcap_aff_assert( null === apply_filters( 'wpac_coupon_affiliate', null, $template->get_code() ), 'the template coupon itself is not part of the campaign' );

$gift = new WC_Coupon();
$gift->set_code( 'vcap-aff-gift-' . strtolower( wp_generate_password( 6, false, false ) ) );
$gift->set_discount_type( 'fixed_cart' );
$gift->set_amount( 50 );
$gift->update_meta_data( '_vcap_voucher', 'yes' );
$gift->update_meta_data( VCAP\Campaigns::META, $name );
$gift->save();
vcap_aff_assert( null === apply_filters( 'wpac_coupon_affiliate', null, $gift->get_code() ), 'purchased vouchers and gift cards never map to a partner' );

$admin   = new VCAP\Admin\CampaignAdmin( $campaigns );
$user    = wp_get_current_user();
$had_cap = $user->has_cap( AffiliateCaptain::CAPABILITY );
if ( ! $had_cap ) {
	ob_start();
	$admin->page();
	$html = ob_get_clean();
	vcap_aff_assert( str_contains( $html, 'Partner (WP Affiliate Captain)' ) && str_contains( $html, '<code>VCAP-TEST-PARTNER</code>' ) && ! str_contains( $html, 'value="VCAP-TEST-PARTNER"' ), 'without the Affiliate Captain capability the partner is shown read-only' );
	$user->add_cap( AffiliateCaptain::CAPABILITY );
}
ob_start();
$admin->page();
$html = ob_get_clean();
if ( ! $had_cap ) {
	$user->remove_cap( AffiliateCaptain::CAPABILITY );
}
vcap_aff_assert( str_contains( $html, 'Partner (WP Affiliate Captain)' ) && str_contains( $html, 'value="VCAP-TEST-PARTNER"' ), 'with the capability the campaign table shows the editable partner code' );

$base = apply_filters( 'wpac_commission_base', 5000, array( 'product_id' => 0 ), array( 'source' => 'woocommerce' ) );
vcap_aff_assert( 5000 === (int) $base, 'voucher sales earn commission by default' );
add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );
$product = new WC_Product_Simple();
$product->set_name( 'Affiliate test gift card' );
$product->set_regular_price( '50' );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->save();
$is_voucher = VCAP\ProductConfig::is_voucher( $product->get_id() );
$base       = apply_filters( 'wpac_commission_base', 5000, array( 'product_id' => $product->get_id() ), array( 'source' => 'woocommerce' ) );
vcap_aff_assert( $is_voucher && 0 === (int) $base, 'with the opt-out filter voucher products earn no commission' );
$other = apply_filters( 'wpac_commission_base', 5000, array( 'product_id' => $template->get_id() ), array( 'source' => 'woocommerce' ) );
vcap_aff_assert( 5000 === (int) $other, 'the opt-out filter leaves other products untouched' );
remove_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' );

vcap_aff_assert( true === AffiliateCaptain::assign( $name, '' ) && null === apply_filters( 'wpac_coupon_affiliate', null, $codes[0] ), 'removing the partner stops the attribution' );

// S18: generating more codes under a campaign assigned to a partner needs Affiliate Captain's right; the assignment
// ends with the last code of the campaign.
vcap_aff_assert( true === AffiliateCaptain::assign( $name, 'vcap-test-partner' ), 'S18: the partner is assigned again' );
$s18_clerk = wp_insert_user( array( 'user_login' => 'vcap_s18_' . strtolower( wp_generate_password( 6, false, false ) ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'shop_manager' ) );
get_userdata( $s18_clerk )->add_cap( AffiliateCaptain::CAPABILITY, false ); // Denied for this user even if the role has it.
wp_set_current_user( $s18_clerk );
$s18_refused = AffiliateCaptain::generation_refusal( $name );
$s18_free    = AffiliateCaptain::generation_refusal( 'Unassigned ' . wp_generate_password( 6, false, false ) );
wp_set_current_user( 1 );
$s18_admin_ok = '' === AffiliateCaptain::generation_refusal( $name ) || ! current_user_can( AffiliateCaptain::CAPABILITY );
vcap_aff_assert( '' !== $s18_refused && str_contains( $s18_refused, $name ) && '' === $s18_free && $s18_admin_ok, 'S18: without wpac_manage_affiliates no codes can be generated under a campaign assigned to a partner (other names are free)' );
vcap_aff_assert( str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/CampaignAdmin.php' ), 'AffiliateCaptain::generation_refusal( $name )' ), 'S18: the "Generate codes" handler asks before generating coupons' );
// SEC2: the same clerk cannot put a coupon into the partner's campaign over the WooCommerce REST API either.
wp_set_current_user( $s18_clerk );
$sec2_request = new WP_REST_Request( 'POST', '/wc/v3/coupons' );
$sec2_request->set_body_params(
	array(
		'code'          => 'vcap-sec2-' . strtolower( wp_generate_password( 6, false, false ) ),
		'discount_type' => 'percent',
		'amount'        => '10',
		'meta_data'     => array( array( 'key' => VCAP\Campaigns::META, 'value' => $name ) ),
	)
);
$sec2_response = rest_do_request( $sec2_request );
wp_set_current_user( 1 );
$sec2_id = (int) ( $sec2_response->get_data()['id'] ?? 0 );
vcap_aff_assert( 201 === $sec2_response->get_status() && $sec2_id > 0 && '' === (string) get_post_meta( $sec2_id, VCAP\Campaigns::META, true ), 'SEC2: over the REST coupon API a user without wpac_manage_affiliates cannot assign a coupon to a partner campaign (the coupon is saved without it)', array( $sec2_response->get_status(), get_post_meta( $sec2_id, VCAP\Campaigns::META, true ) ) );
wp_delete_post( $sec2_id, true );
wp_delete_user( $s18_clerk );
foreach ( $codes as $code ) {
	wp_delete_post( wc_get_coupon_id_by_code( $code ), true );
}
// The gift card above also carries the campaign name: the assignment stays until it is gone too.
vcap_aff_assert( 'VCAP-TEST-PARTNER' === AffiliateCaptain::partner_for_campaign( $name ), 'S18: the assignment stays while a code of the campaign exists' );
wp_delete_post( $gift->get_id(), true );
vcap_aff_assert( '' === AffiliateCaptain::partner_for_campaign( $name ), 'S18: deleting the last code of a campaign removes its partner assignment' );
foreach ( array( $template->get_code() ) as $code ) {
	wp_delete_post( wc_get_coupon_id_by_code( $code ), true );
}
$product->delete( true );
echo "Affiliate Captain adapter checks passed.\n";
