<?php
/**
 * Software bill of materials (CycloneDX 1.5 JSON) of a built plugin folder (RELEASE.md → SBOM, SECURITY.md).
 *
 *   php bin/sbom.php <plugin dir with vendor/> [output file]
 *
 * Components: the production Composer packages (vendor/composer/installed.json, pkg:composer purls with their
 * licences and source commits) and the libraries bundled outside Composer (bin/bundled-assets.json, e.g. jsQR as
 * pkg:npm/jsqr, with the SHA-256 of the shipped file). Deterministic: the timestamp is SOURCE_DATE_EPOCH (default: now)
 * and the serial number is derived from the content, so identical builds give identical files.
 *
 * Exit code 1 when the folder has no installed.json or a bundled file does not match its recorded SHA-256.
 */

$root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $root || ! is_file( "$root/vendor/composer/installed.json" ) || ! is_file( "$root/voucher-captain.php" ) ) {
	fwrite( STDERR, "Usage: php bin/sbom.php <plugin dir with vendor/> [output file]\n" );
	exit( 1 );
}

$installed = json_decode( (string) file_get_contents( "$root/vendor/composer/installed.json" ), true );
$packages  = is_array( $installed ) ? ( $installed['packages'] ?? $installed ) : array();
$dev       = is_array( $installed ) ? ( $installed['dev-package-names'] ?? array() ) : array();
$main      = (string) file_get_contents( "$root/voucher-captain.php" );
$version   = preg_match( '/^ \* Version:\s*(\S+)/m', $main, $m ) ? $m[1] : '';
$edition   = preg_match( "/define\\( 'VCAP_EDITION', '([a-z]+)' \\);/", $main, $m ) ? $m[1] : 'development';

$licenses = static fn( array $ids ): array => array_map( static fn( $id ) => array( 'license' => array( 'id' => (string) $id ) ), array_values( $ids ) );

$components = array();
foreach ( $packages as $package ) {
	if ( in_array( $package['name'] ?? '', $dev, true ) || 'metapackage' === ( $package['type'] ?? '' ) ) {
		continue;
	}
	$pv        = ltrim( (string) ( $package['version'] ?? '' ), 'v' );
	$component = array(
		'type'     => 'library',
		'bom-ref'  => 'pkg:composer/' . $package['name'] . '@' . $pv,
		'name'     => (string) $package['name'],
		'version'  => $pv,
		'purl'     => 'pkg:composer/' . $package['name'] . '@' . $pv,
		'licenses' => $licenses( (array) ( $package['license'] ?? array() ) ),
	);
	if ( ! empty( $package['source']['url'] ) ) {
		$component['externalReferences'] = array( array( 'type' => 'vcs', 'url' => (string) $package['source']['url'] . ( isset( $package['source']['reference'] ) ? '#' . $package['source']['reference'] : '' ) ) );
	}
	$components[] = $component;
}

$assets = json_decode( (string) file_get_contents( __DIR__ . '/bundled-assets.json' ), true );
foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
	$file = "$root/{$asset['path']}";
	if ( ! is_file( $file ) ) {
		continue;
	}
	$hash = hash_file( 'sha256', $file );
	if ( $hash !== $asset['sha256'] ) {
		fwrite( STDERR, "{$asset['path']} does not match bin/bundled-assets.json (SHA-256 $hash): update its version and hash there.\n" );
		exit( 1 );
	}
	$purl         = "pkg:{$asset['ecosystem']}/{$asset['name']}@{$asset['version']}";
	$components[] = array(
		'type'               => 'library',
		'bom-ref'            => $purl,
		'name'               => (string) $asset['name'],
		'version'            => (string) $asset['version'],
		'purl'               => $purl,
		'licenses'           => $licenses( array( $asset['license'] ) ),
		'hashes'             => array( array( 'alg' => 'SHA-256', 'content' => $hash ) ),
		'externalReferences' => array( array( 'type' => 'vcs', 'url' => (string) $asset['source'] ) ),
	);
}
usort( $components, static fn( $a, $b ) => strcmp( $a['bom-ref'], $b['bom-ref'] ) );

$time = (int) ( getenv( 'SOURCE_DATE_EPOCH' ) ?: time() );
$bom  = array(
	'bomFormat'    => 'CycloneDX',
	'specVersion'  => '1.5',
	'serialNumber' => '',
	'version'      => 1,
	'metadata'     => array(
		'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
		'component' => array(
			'type'     => 'application',
			'bom-ref'  => 'voucher-captain@' . $version,
			'name'     => 'voucher-captain',
			'version'  => $version,
			'licenses' => $licenses( array( 'GPL-2.0-or-later' ) ),
			'properties' => array( array( 'name' => 'voucher-captain:edition', 'value' => $edition ) ),
		),
	),
	'components'   => $components,
	'dependencies' => array( array( 'ref' => 'voucher-captain@' . $version, 'dependsOn' => array_column( $components, 'bom-ref' ) ) ),
);
$digest              = hash( 'sha256', (string) json_encode( $bom ) );
$bom['serialNumber'] = sprintf( 'urn:uuid:%s-%s-5%s-%s-%s', substr( $digest, 0, 8 ), substr( $digest, 8, 4 ), substr( $digest, 13, 3 ), dechex( 8 | ( hexdec( $digest[16] ) & 3 ) ) . substr( $digest, 17, 3 ), substr( $digest, 20, 12 ) );
$json                = json_encode( $bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

if ( isset( $argv[2] ) ) {
	file_put_contents( $argv[2], $json );
	echo "SBOM: " . count( $components ) . " components → {$argv[2]}\n";
} else {
	echo $json;
}
