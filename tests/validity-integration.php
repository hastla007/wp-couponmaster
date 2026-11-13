<?php
/**
 * Statutory validity and the warning on short validity (TODO "Warnung bei kurzer Gültigkeit …; gesetzliche Frist
 * nach Käuferland statt Shopland"):
 * - a new voucher gets the statutory default validity of the buyer's country (billing country), not the shop's,
 * - voucher products whose validity ends before the statutory period are reported (product notice, Site Health).
 *
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/validity-integration.php
 */

use VCAP\ProductConfig;

function vcap_val_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

$keep = array(
	'country' => get_option( 'woocommerce_default_country' ),
	'rule'    => get_option( 'vcap_default_expiry_rule' ),
	'days'    => get_option( 'vcap_default_expiry_days', null ),
);
update_option( 'woocommerce_default_country', 'DE:BY' );
update_option( 'vcap_default_expiry_rule', 'statutory' );
delete_option( 'vcap_default_expiry_days' );

$product = new WC_Product_Simple();
$product->set_name( 'Validity voucher' );
$product->set_regular_price( '40' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_type', 'voucher' );
$product->save();

/* ---- Statutory default by the buyer's country ------------------------------------------------------ */

$buy = static function ( string $country ) use ( $product ): ?VCAP\Voucher {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'validity-' . strtolower( $country ) . '@example.com' );
	$order->set_billing_country( $country );
	$order->calculate_totals();
	$order->payment_complete( 'txn-validity-' . $country );
	$vouchers = vcap()->vouchers->for_order( $order->get_id() );
	return $vouchers[0] ?? null;
};
$year = (int) wp_date( 'Y' );
$de   = $buy( 'DE' );
$at   = $buy( 'AT' );
vcap_val_assert( $de && 0 === strpos( (string) $de->expires_at, ( $year + 3 ) . '-12-31' ), 'German buyer of a German shop: 3 years to the end of the year', $de ? $de->expires_at : null );
vcap_val_assert( $at && (int) substr( (string) $at->expires_at, 0, 4 ) === $year + 30, 'Austrian buyer of the same German shop: 30 years (Austrian law), not the German 3 years', $at ? $at->expires_at : null );

/* ---- Warning on short validity -------------------------------------------------------------------- */

vcap_val_assert( array() === ProductConfig::short_validity( $product ) && '' === ProductConfig::short_validity_message( $product ), 'statutory validity: no warning' );
$product->update_meta_data( '_vcap_expiry_mode', 'days' );
$product->update_meta_data( '_vcap_expiry_days', 365 );
$product->save();
$short = ProductConfig::short_validity( wc_get_product( $product->get_id() ) );
vcap_val_assert( isset( $short['DE'] ) && $short['DE']['product'] < $short['DE']['statutory'], 'one year validity in a German shop is reported as shorter than the statutory period', $short );
vcap_val_assert( false !== strpos( ProductConfig::short_validity_message( wc_get_product( $product->get_id() ) ), 'DE ' ), 'the warning names the country and the statutory end date' );
$health = ( new VCAP\Health() )->test_validity();
vcap_val_assert( 'recommended' === $health['status'] && false !== strpos( $health['description'], 'Validity voucher' ), 'Site Health lists the product with the short validity', $health );
$product->update_meta_data( '_vcap_expiry_days', 365 * 40 );
$product->save();
vcap_val_assert( array() === ProductConfig::short_validity( wc_get_product( $product->get_id() ) ), 'a validity longer than every statutory period: no warning' );
$product->update_meta_data( '_vcap_expiry_mode', 'never' );
$product->save();
vcap_val_assert( array() === ProductConfig::short_validity( wc_get_product( $product->get_id() ) ), 'vouchers without expiry: no warning' );

update_option( 'woocommerce_default_country', $keep['country'] );
false === $keep['rule'] ? delete_option( 'vcap_default_expiry_rule' ) : update_option( 'vcap_default_expiry_rule', $keep['rule'] );
if ( null !== $keep['days'] ) {
	update_option( 'vcap_default_expiry_days', $keep['days'] );
}
echo "Voucher Captain validity checks passed.\n";
