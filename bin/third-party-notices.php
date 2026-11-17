<?php
/**
 * Third-party licences of the release ZIP (RELEASE.md → Third-party licences).
 *
 *   php bin/third-party-notices.php <plugin dir>     Checks that every bundled Composer package (vendor/composer/
 *                                                     installed.json, production only) keeps a licence file and
 *                                                     writes <plugin dir>/THIRD-PARTY-NOTICES.txt.
 *   php bin/third-party-notices.php --check-zip <zip> Checks a finished release ZIP: a licence file for every bundled
 *                                                     package, the notices file and the licence texts in licenses/.
 *
 * Exit code 1 when a licence file is missing.
 */

const VCAP_TPN_LICENSE_FILE = '/^(LICEN[CS]E|COPYING|NOTICE)([._-][^\/]*)?$/i';

/**
 * Production packages of an installed.json document.
 *
 * @return array<int, array<string, mixed>>
 */
function vcap_tpn_packages( string $json ): array {
	$installed = json_decode( $json, true );
	if ( ! is_array( $installed ) ) {
		fwrite( STDERR, "vendor/composer/installed.json is missing or invalid.\n" );
		exit( 1 );
	}
	$packages = $installed['packages'] ?? $installed;
	$dev      = $installed['dev-package-names'] ?? array();
	$packages = array_values( array_filter( $packages, static fn( $p ) => ! in_array( $p['name'], $dev, true ) && 'metapackage' !== ( $p['type'] ?? '' ) ) );
	usort( $packages, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
	return $packages;
}

/** Folder of a package relative to the plugin root, e.g. vendor/dompdf/dompdf. */
function vcap_tpn_folder( array $package ): string {
	$path = $package['install-path'] ?? ( '../' . $package['name'] );
	return 'vendor/' . ltrim( preg_replace( '#^\.\./#', '', $path ), '/' );
}

if ( '--check-zip' === ( $argv[1] ?? '' ) ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( (string) ( $argv[2] ?? '' ) ) ) {
		fwrite( STDERR, "Usage: php bin/third-party-notices.php --check-zip <release zip>\n" );
		exit( 1 );
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = preg_replace( '#^voucher-captain/#', '', (string) $zip->getNameIndex( $i ) );
	}
	$errors = array();
	foreach ( array( 'LICENSE', 'THIRD-PARTY-NOTICES.txt', 'licenses/GPL-3.0.txt', 'licenses/DejaVu-Fonts-LICENSE.txt' ) as $required ) {
		if ( ! in_array( $required, $names, true ) ) {
			$errors[] = "$required is missing";
		}
	}
	$installed = $zip->getFromName( 'voucher-captain/vendor/composer/installed.json' );
	$notices   = (string) $zip->getFromName( 'voucher-captain/THIRD-PARTY-NOTICES.txt' );
	foreach ( vcap_tpn_packages( (string) $installed ) as $package ) {
		$folder = vcap_tpn_folder( $package ) . '/';
		$found  = array_filter( $names, static fn( $n ) => str_starts_with( $n, $folder ) && preg_match( VCAP_TPN_LICENSE_FILE, substr( $n, strlen( $folder ) ) ) );
		if ( ! $found ) {
			$errors[] = "{$package['name']}: no licence file in $folder";
		}
		if ( ! str_contains( $notices, $package['name'] . ' ' ) ) {
			$errors[] = "{$package['name']}: not listed in THIRD-PARTY-NOTICES.txt";
		}
	}
	if ( $errors ) {
		fwrite( STDERR, "Third-party licences incomplete:\n  " . implode( "\n  ", $errors ) . "\n" );
		exit( 1 );
	}
	echo "Third-party licences complete.\n";
	exit( 0 );
}

$root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $root || ! is_file( "$root/vendor/composer/installed.json" ) ) {
	fwrite( STDERR, "Usage: php bin/third-party-notices.php <plugin dir with vendor/>\n" );
	exit( 1 );
}

$errors   = array();
$sections = array();
$by_lic   = array();
foreach ( vcap_tpn_packages( (string) file_get_contents( "$root/vendor/composer/installed.json" ) ) as $package ) {
	$folder = vcap_tpn_folder( $package );
	$files  = is_dir( "$root/$folder" ) ? array_values( array_filter( scandir( "$root/$folder" ), static fn( $f ) => (bool) preg_match( VCAP_TPN_LICENSE_FILE, $f ) ) ) : array();
	if ( ! $files ) {
		$errors[] = "{$package['name']}: no licence file in $folder";
	}
	$licenses = implode( ' OR ', (array) ( $package['license'] ?? array( 'unknown' ) ) );
	foreach ( $files as $file ) {
		$by_lic[ $licenses ][] = "$folder/$file";
	}
	// Files bin/scope-vendor.php changed (namespace prefix against version conflicts with other plugins).
	$modified = false;
	if ( is_dir( "$root/$folder" ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$folder", FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( 'php' === $f->getExtension() && str_contains( (string) file_get_contents( $f->getPathname() ), 'VCAP\\Vendor\\' ) ) {
				$modified = true;
				break;
			}
		}
	}
	$text  = $package['name'] . ' ' . ltrim( (string) ( $package['version'] ?? '' ), 'v' ) . "\n";
	$text .= "  Licence:  $licenses\n";
	$text .= '  Folder:   ' . $folder . ( $files ? ' (licence: ' . implode( ', ', $files ) . ')' : '' ) . "\n";
	$source = $package['homepage'] ?? ( $package['source']['url'] ?? '' );
	if ( $source ) {
		$text .= "  Source:   $source" . ( isset( $package['source']['reference'] ) ? " (commit {$package['source']['reference']})" : '' ) . "\n";
	}
	$text .= $modified
		? "  Changes:  namespace references rewritten to VCAP\\Vendor\\… by bin/scope-vendor.php at build time (avoids\n            conflicts with other plugins); tests, docs and examples left out. Otherwise the unmodified release.\n"
		: "  Changes:  none (tests, docs and examples left out).\n";
	$sections[] = $text;
}
if ( $errors ) {
	fwrite( STDERR, "Bundled packages without a licence file:\n  " . implode( "\n  ", $errors ) . "\n" );
	exit( 1 );
}

$fonts = "$root/vendor/dompdf/dompdf/lib/fonts";
$out   = "Voucher Captain – third-party notices\n=====================================\n\n";
$out  .= "Voucher Captain is free software under the GNU General Public License, version 2 or later (file LICENSE).\n";
$out  .= "It bundles the software listed below. Each component remains under its own licence; the licence file of\n";
$out  .= "every package is kept in its folder. Replacing a bundled library with a compatible version of your own is\n";
$out  .= "allowed (vendor/ is a standard Composer installation, see composer.json).\n\n";
$out  .= "1. PHP libraries (vendor/)\n--------------------------\n\n" . implode( "\n", $sections ) . "\n";
$out  .= "2. Fonts and data files\n-----------------------\n\n";
if ( is_dir( $fonts ) ) {
	$out .= "DejaVu fonts (vendor/dompdf/dompdf/lib/fonts/DejaVu*.ttf, *.ufm)\n";
	$out .= "  Licence:  Bitstream Vera Fonts licence and Arev Fonts licence; DejaVu changes are in the public domain.\n";
	$out .= "            Full text: licenses/DejaVu-Fonts-LICENSE.txt\n\n";
	$out .= "Adobe Core 14 font metrics (vendor/dompdf/dompdf/lib/fonts/*.afm)\n";
	$out .= "  Licence:  Adobe's copyright notice and permission are kept in every .afm file" . ( is_file( "$fonts/mustRead.html" ) ? ' and in mustRead.html' : '' ) . ".\n\n";
}
if ( is_file( "$root/vendor/dompdf/dompdf/lib/res/sRGB2014.icc.LICENSE" ) ) {
	$out .= "sRGB2014.icc colour profile (vendor/dompdf/dompdf/lib/res/sRGB2014.icc)\n";
	$out .= "  Licence:  International Color Consortium, see sRGB2014.icc.LICENSE next to it.\n\n";
}
// Libraries bundled outside Composer: name, version and SHA-256 in bin/bundled-assets.json (also used by the SBOM
// and the weekly vulnerability check, scheduled.yml).
$assets = json_decode( (string) file_get_contents( __DIR__ . '/bundled-assets.json' ), true );
foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
	if ( is_file( "$root/{$asset['path']}" ) ) {
		$out .= "{$asset['title']} {$asset['version']} ({$asset['path']}, {$asset['used_by']})\n";
		$out .= "  Licence:  {$asset['license']}, see {$asset['license_file']}. Source: {$asset['source']}\n\n";
	}
}
$out .= "3. Licence texts\n----------------\n\n";
$out .= "GPL-2.0-or-later (Voucher Captain): LICENSE\n";
$out .= "GPL-3.0: licenses/GPL-3.0.txt – the LGPL-3.0 adds permissions to the GPL-3.0, so both texts apply to\n";
$out .= "  LGPL-3.0 components.\n";
ksort( $by_lic );
foreach ( $by_lic as $license => $files ) {
	$out .= "$license: " . implode( ', ', array_unique( $files ) ) . "\n";
}
file_put_contents( "$root/THIRD-PARTY-NOTICES.txt", $out );
echo 'THIRD-PARTY-NOTICES.txt: ' . count( $sections ) . " packages.\n";
