<?php
/**
 * Upgrade from the previous schema with data (CI job "upgrade"):
 *   wp eval-file tests/upgrade-integration.php setup     – with the PREVIOUS release active: create vouchers,
 *                                                         ledger rows, promotion uses and claims
 *   (install the current release ZIP over it)
 *   wp eval-file tests/upgrade-integration.php verify    – data kept, schema current, old rows moved
 *   (install the previous release again = rollback, then:)
 *   wp eval-file tests/upgrade-integration.php rollback  – the old version keeps working on the data, writes a use
 *   (install the current release again, then:)
 *   wp eval-file tests/upgrade-integration.php verify    – also the use written during the rollback is counted
 *
 *   verify <edition>   additionally checks the edition of the installed ZIP (free | premium | development)
 *   wp eval-file tests/upgrade-integration.php edition premium|free – same version, other edition ZIP installed over
 *                                                         it (free → premium and back): data, schema, edition
 *
 * The setup step only uses APIs that the previous release has as well (1.1.0: no VCAP\Claim yet – the claim part
 * is left out there).
 */

function vcap_up_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

global $wpdb;
$mode    = $args[0] ?? 'verify';
$tables  = VCAP\Install::tables();
$fixture = (array) get_option( 'vcap_upgrade_fixture', array() );

if ( 'setup' === $mode ) {
	$vouchers = array();
	foreach ( array( array( 'balance', 'giftcard', 80.0 ), array( 'single', 'voucher', 25.0 ) ) as list( $usage, $type, $value ) ) {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'vcap-up-' . strtolower( wp_generate_password( 10, false, false ) ) );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $value );
		$coupon->update_meta_data( '_vcap_voucher', 'yes' );
		$coupon->save();
		$v                 = new VCAP\Voucher();
		$v->code           = wc_format_coupon_code( $coupon->get_code() );
		$v->coupon_id      = $coupon->get_id();
		$v->order_item_id  = 97000000 + $coupon->get_id();
		$v->type           = $type;
		$v->usage_mode     = $usage;
		$v->initial_amount = $value;
		$v->balance        = $value;
		$v->currency       = get_woocommerce_currency();
		$v->status         = VCAP\Voucher::STATUS_ACTIVE;
		$v->send_to        = 'buyer';
		$v->token          = VCAP\VoucherRepository::new_token();
		$v->id             = vcap()->vouchers->insert( $v );
		vcap()->vouchers->log( $v->id, 'issued', $value, 'balance' === $usage ? $value : null, 'upgrade fixture' );
		$vouchers[] = $v->id;
	}
	vcap()->vouchers->log( $vouchers[0], 'redeemed', 30, 50, 'upgrade fixture', 4242 );
	$wpdb->update( $tables['vouchers'], array( 'balance' => 50 ), array( 'id' => $vouchers[0] ) );

	$promo = wp_insert_post( array( 'post_type' => 'vcap_discount', 'post_status' => 'publish', 'post_title' => 'Upgrade fixture promotion' ) );
	VCAP\Discounts\Usage::add( $promo, 'e:first@example.com' );
	VCAP\Discounts\Usage::add( $promo, 'e:second@example.com' );
	$claim = '';
	if ( class_exists( 'VCAP\Claim' ) ) {
		$claim = 'usage:upgrade-fixture:' . $promo;
		vcap_up_assert( VCAP\Claim::take( $claim ), 'the previous release takes a claim' );
	}

	update_option(
		'vcap_upgrade_fixture',
		array(
			'db_version' => get_option( 'vcap_db_version' ),
			'vouchers'   => $vouchers,
			'events'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['events']}" ), // phpcs:ignore
			'promo'      => $promo,
			'uses'       => 2,
			'claim'      => $claim,
		),
		false
	);
	vcap_up_assert( VCAP_DB_VERSION === get_option( 'vcap_db_version' ), 'the previous release is installed with schema ' . VCAP_DB_VERSION );
	return;
}

vcap_up_assert( ! empty( $fixture['vouchers'] ), 'fixture data from the previous release exists' );

/** The installed ZIP is the expected edition; release editions carry an "Update URI" (no WordPress.org updates). */
function vcap_up_edition( string $expected ): void {
	if ( '' === $expected ) {
		return;
	}
	$edition = class_exists( 'VCAP\Licensing\Entitlements' ) ? VCAP\Licensing\Entitlements::edition() : 'development';
	vcap_up_assert( $expected === $edition, "the installed ZIP is the {$expected} edition" );
	if ( 'development' !== $expected ) {
		$uri = (string) get_file_data( VCAP_FILE, array( 'uri' => 'Update URI' ) )['uri'];
		vcap_up_assert( '' !== $uri, "the {$expected} edition has the header Update URI ({$uri})" );
	}
	if ( 'premium' === $expected ) {
		vcap_up_assert( in_array( 'promotions', VCAP\Licensing\Entitlements::locked(), true ), 'premium without an activated license: the premium features are locked' );
	}
}

if ( 'edition' === $mode ) {
	// Same version, other edition ZIP installed over it: nothing to migrate, nothing lost.
	vcap_up_edition( (string) ( $args[1] ?? '' ) );
	vcap_up_assert( VCAP_DB_VERSION === get_option( 'vcap_db_version' ) && array() === VCAP\Install::verify_schema(), 'the schema is unchanged and complete' );
	$card   = vcap()->vouchers->get( (int) $fixture['vouchers'][0] );
	$single = vcap()->vouchers->get( (int) $fixture['vouchers'][1] );
	vcap_up_assert( $card && 50.0 === (float) $card->balance && $single && 25.0 === (float) $single->balance, 'vouchers and balances are kept across the edition change' );
	vcap_up_assert( (int) $fixture['uses'] === VCAP\Discounts\Usage::count( (int) $fixture['promo'] ), 'promotion uses are kept across the edition change' );
	if ( '' !== (string) $fixture['claim'] ) {
		vcap_up_assert( ! VCAP\Claim::take( (string) $fixture['claim'] ), 'a claim stays taken across the edition change' );
	}
	echo "Voucher Captain edition change checks passed.\n";
	return;
}

if ( 'rollback' === $mode ) {
	// The previous release runs on the newer database: it reads and writes its own way (post meta uses).
	$card = vcap()->vouchers->get( (int) $fixture['vouchers'][0] );
	vcap_up_assert( $card && 50.0 === (float) $card->balance, 'after a rollback the previous release still reads the vouchers' );
	VCAP\Discounts\Usage::add( (int) $fixture['promo'], 'e:rollback@example.com' );
	$fixture['uses'] = (int) $fixture['uses'] + 1;
	update_option( 'vcap_upgrade_fixture', $fixture, false );
	return;
}

// verify: the plugin booted (maybe_upgrade ran under its lock) – schema and data.
vcap_up_edition( (string) ( $args[1] ?? '' ) );
vcap_up_assert( VCAP_DB_VERSION === get_option( 'vcap_db_version' ) && version_compare( VCAP_DB_VERSION, (string) $fixture['db_version'], '>' ), 'the database is upgraded from ' . $fixture['db_version'] . ' to ' . VCAP_DB_VERSION );
vcap_up_assert( array() === VCAP\Install::verify_schema() && '' === (string) get_option( VCAP\Install::SCHEMA_ERROR_OPTION, '' ), 'the upgraded schema is complete' );
vcap_up_assert( ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", VCAP\Install::UPGRADE_LOCK ) ), 'the upgrade lock was given back' );
$card   = vcap()->vouchers->get( (int) $fixture['vouchers'][0] );
$single = vcap()->vouchers->get( (int) $fixture['vouchers'][1] );
vcap_up_assert( $card && 50.0 === (float) $card->balance && 80.0 === (float) $card->initial_amount && $single && 25.0 === (float) $single->balance, 'vouchers and balances are kept' );
vcap_up_assert( (int) $fixture['events'] <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['events']}" ) && 'redeemed' === $wpdb->get_var( $wpdb->prepare( "SELECT event FROM {$tables['events']} WHERE voucher_id = %d AND order_id = %d ORDER BY id DESC LIMIT 1", $card->id, 4242 ) ), 'the ledger is kept and readable through the new index' ); // phpcs:ignore
VCAP\Install::move_legacy_rows( 10.0 );
vcap_up_assert( (int) $fixture['uses'] === VCAP\Discounts\Usage::count( (int) $fixture['promo'] ), 'promotion uses of the previous release are counted (' . $fixture['uses'] . ')' );
vcap_up_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_vcap_used_by'", (int) $fixture['promo'] ) ), 'the old usage rows were moved into the usage table' );
if ( '' !== (string) $fixture['claim'] ) {
	vcap_up_assert( ! VCAP\Claim::take( (string) $fixture['claim'] ), 'a claim taken by the previous release stays taken' );
}
vcap_up_assert( ! $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_vcap_claim_' ) . '%' ) ), 'no claim options are left' );
echo "Voucher Captain upgrade checks passed.\n";
