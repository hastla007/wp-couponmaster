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

$admin = new VCAP\Admin\CampaignAdmin( $campaigns );
ob_start();
$admin->page();
$html = ob_get_clean();
vcap_aff_assert( str_contains( $html, 'Partner (WP Affiliate Captain)' ) && str_contains( $html, 'value="VCAP-TEST-PARTNER"' ), 'the campaign table shows the partner column with the saved code' );

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

foreach ( array_merge( $codes, array( $template->get_code(), $gift->get_code() ) ) as $code ) {
	wp_delete_post( wc_get_coupon_id_by_code( $code ), true );
}
$product->delete( true );
echo "Affiliate Captain adapter checks passed.\n";
