<?php
/**
 * Data of the 1.2.0 contracts on a multisite network: each site has its own workflow instance (and so its own
 * event ids), its own voucher requests and its own review-source records. Each step runs as its own process:
 *   wp eval-file tests/multisite-data-integration.php record                          (main site)
 *   wp --url=http://localhost/before/ eval-file tests/multisite-data-integration.php record
 *   wp eval-file tests/multisite-data-integration.php verify                          (main site)
 */

use VCAP\Integrations\ReviewSource;
use VCAP\Integrations\VoucherRequests;
use VCAP\Workflow\Provider;

function vcap_msdata_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

$mode = $args[0] ?? '';
$blog = get_current_blog_id();

if ( 'record' === $mode ) {
	$instance = Provider::instance();
	$request  = vcap()->voucher_requests->submit(
		array(
			'reference'  => 'formcaptain:entry:multisite-' . $blog,
			'contact'    => array( 'email' => "ms-{$blog}@example.com" ),
			'recipients' => array( array( 'name' => 'Ann', 'email' => "ms-{$blog}-ann@example.com" ) ),
		)
	);
	vcap_msdata_assert( is_array( $request ) && $request['request_id'] > 0, "site {$blog}: a voucher request is stored", $request );
	$reference = ReviewSource::reference( 'multisite-' . $blog );
	add_option( ReviewSource::OPTION_PREFIX . $reference, array( 'v' => 1, 'state' => 'eligible', 'voucher_id' => 0, 'experience_id' => 0, 'channel' => 'counter', 'at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_at' => '', 'reason' => '' ), '', false );
	$seen          = (array) get_site_option( 'vcap_msdata_probe', array() );
	$seen[ $blog ] = array( 'instance' => $instance, 'event' => Provider::event_id( 1, 'voucher.redeemed' ), 'request' => (int) $request['request_id'], 'reference' => $reference );
	update_site_option( 'vcap_msdata_probe', $seen );
	echo "recorded site {$blog}\n";
	return;
}

if ( 'verify' === $mode ) {
	$seen = (array) get_site_option( 'vcap_msdata_probe', array() );
	vcap_msdata_assert( count( $seen ) >= 2, 'two sites recorded their data', array_keys( $seen ) );
	$instances = array_column( $seen, 'instance' );
	$events    = array_column( $seen, 'event' );
	vcap_msdata_assert( count( array_unique( $instances ) ) === count( $instances ) && count( array_unique( $events ) ) === count( $events ), 'each site has its own workflow instance, so the same ledger row gives different event ids', $seen );
	foreach ( $seen as $site => $data ) {
		switch_to_blog( (int) $site );
		$own    = VoucherRequests::get( (int) $data['request'] );
		$others = array_filter( $seen, static fn( $d, $s ) => (int) $s !== (int) $site, ARRAY_FILTER_USE_BOTH );
		$leak   = array_filter( $others, static fn( $d ) => null !== get_option( ReviewSource::OPTION_PREFIX . $d['reference'], null ) );
		$items  = array_column( VoucherRequests::page( array(), 1, 200 )['items'], 'reference' );
		restore_current_blog();
		vcap_msdata_assert( null !== $own && 'formcaptain:entry:multisite-' . $site === $own['reference'] && in_array( 'formcaptain:entry:multisite-' . $site, $items, true ) && ! array_intersect( $items, array_map( static fn( $s ) => 'formcaptain:entry:multisite-' . $s, array_keys( $others ) ) ), "site {$site}: its voucher request list shows only its own requests", $items );
		vcap_msdata_assert( array() === $leak, "site {$site}: review-source records of other sites are not visible", array_keys( $leak ) );
	}
	delete_site_option( 'vcap_msdata_probe' );
	echo "Multisite data checks passed.\n";
	return;
}

throw new RuntimeException( "Unknown mode '{$mode}' (record, verify)." );
