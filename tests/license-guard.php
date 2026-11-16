<?php
/**
 * License guard (docs/FREE-PREMIUM.md): the shipped files of the free core contain no licensing or update code –
 * no license client, no WP License Captain API calls, no "Update URI" header, no own update filters, no entitlement
 * checks, no edition or license constants.
 *
 *   php tests/license-guard.php [--root=DIR]
 *
 * Exits 1 and lists file:line of every hit. --root checks another directory with the same layout (used by
 * tests/run.php to prove that the guard finds planted license code).
 *
 * The only license-related text allowed in the shipped files is the clean-up of what pre-release builds with a
 * license client left behind (VCAP\Install::LEGACY_LICENSE_OPTIONS and its comments, see $allowed below).
 */

$root = dirname( __DIR__ );
foreach ( array_slice( $argv ?? array(), 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--root=' ) ) {
		$root = rtrim( substr( $arg, 7 ), '/' );
	}
}

// Shipped files and folders (bin/build.sh), without bundled third-party code.
$paths   = array( 'voucher-captain.php', 'uninstall.php', 'readme.txt', 'src', 'templates', 'assets', 'examples', 'config' );
$exclude = array( 'assets/vendor/' );

$patterns = array(
	'/\bLicenseClient\b/'                              => 'license client',
	'#wplc/v1#'                                        => 'WP License Captain API',
	'/^\s*\*?\s*Update URI\s*:/'                       => '"Update URI" header',
	'/pre_set_site_transient_update_plugins/'         => 'own update filter',
	'/[\'"]plugins_api[\'"]/'                          => 'own plugins_api filter',
	'/\ballows_entitlement\b/'                         => 'entitlement check',
	'/VCAP_EDITION/'                                   => 'edition constant',
	'/VCAP_LICENSE_/'                                  => 'license constant',
	'/wplc_/i'                                         => 'WP License Captain prefix (wplc_)',
	'/[\'"]site_transient_update_plugins[\'"]/'        => 'own update filter (site_transient_update_plugins)',
	'/entitlement/i'                                   => 'entitlement',
	'/licen[sc]e_key/i'                                => 'license key handling',
);

// Clean-up of earlier builds: the legacy option names and the comment lines of src/Install.php may name what is removed.
$allowed = array(
	'src/Install.php' => '/^\s*(\*|\/\*\*|\'vcap_wplc_[a-z_]+\',\s*$)/',
);
$allowed_for = array( 'WP License Captain prefix (wplc_)', 'entitlement' );

$files = array();
foreach ( $paths as $path ) {
	$full = "$root/$path";
	if ( is_file( $full ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( $full ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		foreach ( $exclude as $prefix ) {
			if ( str_starts_with( $relative, $prefix ) ) {
				continue 2;
			}
		}
		// Text files only (images, fonts and PDFs cannot carry code).
		if ( preg_match( '/\.(png|jpe?g|gif|webp|ico|svgz|woff2?|ttf|otf|eot|pdf|zip|mo)$/i', $relative ) ) {
			continue;
		}
		$files[] = $relative;
	}
}
sort( $files );

$hits = array();
foreach ( $files as $relative ) {
	$lines = file( "$root/$relative", FILE_IGNORE_NEW_LINES );
	foreach ( false === $lines ? array() : $lines as $number => $line ) {
		foreach ( $patterns as $pattern => $what ) {
			if ( preg_match( $pattern, $line ) ) {
				if ( isset( $allowed[ $relative ] ) && in_array( $what, $allowed_for, true ) && preg_match( $allowed[ $relative ], $line ) ) {
					continue;
				}
				$hits[] = sprintf( '%s:%d: %s – %s', $relative, $number + 1, $what, trim( $line ) );
			}
		}
	}
}

if ( $hits ) {
	fwrite( STDERR, "License guard: licensing or update code in shipped files (docs/FREE-PREMIUM.md):\n  " . implode( "\n  ", $hits ) . "\n" );
	exit( 1 );
}
echo 'License guard: ' . count( $files ) . " shipped files checked, no licensing or update code.\n";
