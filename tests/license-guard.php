<?php
/**
 * License guard (docs/FREE-PREMIUM.md): the shipped files of the free core contain no licensing or update code –
 * no license client, no WP License Captain API calls, no "Update URI" header, no own update filters, no entitlement
 * checks, no edition or license constants – and bin/build.sh builds no second (premium) edition (A2).
 *
 * Network calls (A5): every HTTP request the core can make is on the allowlist below (readme.txt → "External
 * services" describes each one). The core never calls a license or update server; a new wp_remote_*() call, a
 * cURL/socket call or a browser request to a URL that does not come from the page's own configuration fails.
 *
 *   php tests/license-guard.php [plugin root]
 *
 * Exits 1 and lists file:line of every hit. The optional root is for the self-test in tests/run.php.
 */

$root = isset( $argv[1] ) && '' !== $argv[1] ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

// Shipped files and folders (bin/build.sh), without bundled third-party code.
$paths   = array( 'voucher-captain.php', 'uninstall.php', 'readme.txt', 'src', 'templates', 'assets', 'examples', 'config' );
$exclude = array( 'assets/vendor/' );

$patterns = array(
	'/\bLicenseClient\b/'                              => 'license client',
	'#wplc/v1#'                                        => 'WP License Captain API',
	'/^\s*\*?\s*Update URI\s*:/'                       => '"Update URI" header',
	'/site_transient_update_plugins/'                  => 'own update filter',
	'/[\'"]update_plugins_/'                           => 'own update filter (update_plugins_{hostname})',
	'/[\'"]plugins_api[\'"]/'                          => 'own plugins_api filter',
	'/\ballows_entitlement\b/'                         => 'entitlement check',
	'/VCAP_EDITION/'                                   => 'edition constant',
	'/VCAP_LICENSE_/'                                  => 'license constant',
	// As in Affiliate Captain's guard (review 16.11.2026, R16-09): any other trace of the license client.
	'/wplc_/i'                                         => 'WP License Captain prefix (wplc_)',
	'/entitlement/i'                                   => 'entitlement wording',
	'/licen[sc]e_key/i'                                => 'license key handling',
);

// The only license-related text allowed in the shipped files: the clean-up of what pre-release builds with a
// license client left behind (VCAP\Install::LEGACY_LICENSE_OPTIONS and the comments around it, FREE-PREMIUM rule 4).
$legacy_cleanup = array(
	'file'  => 'src/Install.php',
	'line'  => '/^\s*(\*|\/\*\*|\'vcap_wplc_[a-z_]+\',\s*$)/',
	'whats' => array( 'WP License Captain prefix (wplc_)', 'entitlement wording' ),
);

// bin/build.sh is not shipped but decides what is: one build, no edition switch, no license tooling.
$build_patterns = array(
	'/\bLicenseClient\b/'       => 'license client',
	'/wplc/i'                   => 'WP License Captain tooling',
	'/VCAP_EDITION\s*=/'        => 'edition switch',
	'/VCAP_LICENSE_\w*\s*=/'    => 'license constant',
	'/(?<!FREE-)premium/i'       => 'premium edition',
);

/*
 * A5: the only HTTP requests of the core (file => function => number of calls). Both are documented in readme.txt
 * → "External services":
 * - PdfRenderer::remote_image(): template images from hosts the shop names with "vcap_pdf_remote_hosts" (none by
 *   default; meant for the shop's own CDN or offloaded media library).
 * - Health::probe_private_dir(): Site Health loopback request to the shop's own uploads URL.
 */
$network_allowlist = array(
	'src/PdfRenderer.php' => array( 'wp_safe_remote_get' => 1 ),
	'src/Health.php'      => array( 'wp_remote_get' => 1 ),
);
$php_network = '/\b(wp_(?:safe_)?remote_(?:get|post|head|request)|download_url|curl_init|curl_multi_init|fsockopen|pfsockopen|stream_socket_client|_wp_http_get_object|wp_get_http)\s*\(|\bnew\s+\\\\?WP_Http\b|\b(?:file_get_contents|fopen|readfile|copy)\(\s*[\'"](?:https?|ftp):/i';
// Browser requests: allowed only to a URL from the page's configuration (cfg.…, printed by PHP: REST/AJAX URLs of
// the own site), never to a literal or computed foreign address.
$js_network  = '/\b(fetch|\$\.(?:post|get|ajax|getJSON)|jQuery\.(?:post|get|ajax|getJSON)|navigator\.sendBeacon|importScripts|new\s+WebSocket|new\s+EventSource)\s*\(\s*([^,)]*)/';

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

$hits    = array();
$network = array();
foreach ( $files as $relative ) {
	$lines = file( "$root/$relative", FILE_IGNORE_NEW_LINES );
	$is_js = str_ends_with( $relative, '.js' );
	$is_php = str_ends_with( $relative, '.php' );
	foreach ( false === $lines ? array() : $lines as $number => $line ) {
		foreach ( $patterns as $pattern => $what ) {
			if ( preg_match( $pattern, $line ) ) {
				if ( $legacy_cleanup['file'] === $relative && in_array( $what, $legacy_cleanup['whats'], true ) && preg_match( $legacy_cleanup['line'], $line ) ) {
					continue;
				}
				$hits[] = sprintf( '%s:%d: %s – %s', $relative, $number + 1, $what, trim( $line ) );
			}
		}
		if ( $is_php && preg_match_all( $php_network, $line, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $call ) {
				$function = strtolower( '' !== ( $call[1] ?? '' ) ? $call[1] : 'direct stream or WP_Http' );
				$network[ $relative ][ $function ][] = $number + 1;
			}
		}
		if ( $is_js && preg_match_all( $js_network, $line, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $call ) {
				if ( ! preg_match( '/^cfg\.[A-Za-z_]+/', trim( $call[2] ) ) ) {
					$hits[] = sprintf( '%s:%d: browser request to a URL that is not from the page configuration (cfg.…) – %s', $relative, $number + 1, trim( $line ) );
				}
			}
		}
	}
}

// A5: network calls against the allowlist (also an allowlisted call that is gone, so the list stays exact).
foreach ( $network as $relative => $functions ) {
	foreach ( $functions as $function => $lines ) {
		$allowed = $network_allowlist[ $relative ][ $function ] ?? 0;
		if ( count( $lines ) > $allowed ) {
			$hits[] = sprintf( '%s:%s: network call %s() is not on the allowlist of tests/license-guard.php (document it in readme.txt → External services first)', $relative, implode( ',', $lines ), $function );
		}
	}
}
if ( dirname( __DIR__ ) === $root ) {
	foreach ( $network_allowlist as $relative => $functions ) {
		foreach ( $functions as $function => $count ) {
			if ( count( $network[ $relative ][ $function ] ?? array() ) !== $count ) {
				$hits[] = sprintf( '%s: allowlisted network call %s() is used %d times, allowlist says %d – update the allowlist and readme.txt', $relative, $function, count( $network[ $relative ][ $function ] ?? array() ), $count );
			}
		}
	}
}

$build = is_file( "$root/bin/build.sh" ) ? file( "$root/bin/build.sh", FILE_IGNORE_NEW_LINES ) : array();
foreach ( false === $build ? array() : $build as $number => $line ) {
	foreach ( $build_patterns as $pattern => $what ) {
		if ( preg_match( $pattern, $line ) ) {
			$hits[] = sprintf( 'bin/build.sh:%d: %s – %s', $number + 1, $what, trim( $line ) );
		}
	}
}

// SET-VC-07 (docs/FREE-PREMIUM.md rule 3): the hint lines on add-on functions are text only – the list in
// config/pro-hints.php may use no other keys and call no function except __().
$hints_file = "$root/config/pro-hints.php";
if ( is_file( $hints_file ) ) {
	$hints_src = (string) file_get_contents( $hints_file );
	$hints_php = preg_replace( '#/\*.*?\*/#s', '', $hints_src ); // Without the doc comment.
	preg_match_all( "/'([a-z_]+)'\s*=>/", $hints_php, $hint_keys );
	foreach ( array_unique( $hint_keys[1] ) as $key ) {
		if ( ! in_array( $key, array( 'id', 'section', 'title', 'text', 'link' ), true ) ) {
			$hits[] = sprintf( 'config/pro-hints.php: key "%s" – a hint line has only id, section, title, text, link (text only)', $key );
		}
	}
	$hints_code = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", "''", $hints_php ); // Without the texts.
	preg_match_all( '/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $hints_code, $hint_calls );
	foreach ( array_unique( $hint_calls[1] ) as $call ) {
		if ( ! in_array( $call, array( '__', 'array', 'defined' ), true ) ) {
			$hits[] = sprintf( 'config/pro-hints.php: call %s() – a hint line is text only (no option, no network, no code)', $call );
		}
	}
}

if ( $hits ) {
	fwrite( STDERR, "License guard: licensing, update or unlisted network code in the core (docs/FREE-PREMIUM.md):\n  " . implode( "\n  ", $hits ) . "\n" );
	exit( 1 );
}
$calls = array();
foreach ( $network as $relative => $functions ) {
	foreach ( $functions as $function => $lines ) {
		$calls[] = "$relative:" . implode( ',', $lines ) . " $function()";
	}
}
echo 'License guard: ' . count( $files ) . " shipped files and bin/build.sh checked, no licensing or update code. Network calls (all allowlisted): " . ( $calls ? implode( '; ', $calls ) : 'none' ) . ".\n";
