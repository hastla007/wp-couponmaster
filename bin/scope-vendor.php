<?php
/**
 * Moves bundled third-party libraries that other plugins also ship into the VCAP\Vendor namespace, so two versions
 * can never collide (e.g. WooCommerce 8.x bundles an older sabberworm/php-css-parser: loading both is a fatal
 * error). Run on a production vendor directory, then `composer dump-autoload`:
 *
 *   php bin/scope-vendor.php path/to/vendor
 */
$vendor = rtrim( $argv[1] ?? '', '/' );
if ( '' === $vendor || ! is_dir( $vendor . '/composer' ) ) {
	fwrite( STDERR, "Usage: php bin/scope-vendor.php <vendor dir>\n" );
	exit( 1 );
}

// Namespace => composer package whose autoload mapping is renamed.
$scoped = array(
	'Sabberworm\\CSS' => 'sabberworm/php-css-parser',
);

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $vendor, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/composer/' ) ) {
		continue;
	}
	$code = file_get_contents( $file->getPathname() );
	$new  = $code;
	foreach ( array_keys( $scoped ) as $ns ) {
		list( $first, $rest ) = explode( '\\', $ns, 2 );
		// Matches Sabberworm\CSS and, inside strings, Sabberworm\\CSS – not already prefixed ones.
		$new = preg_replace(
			'/(?<!Vendor\\\\)(?<!Vendor\\\\\\\\)\b' . preg_quote( $first, '/' ) . '(\\\\{1,2})' . preg_quote( $rest, '/' ) . '\b/',
			'VCAP$1Vendor$1' . $first . '$1' . $rest,
			$new
		);
	}
	if ( $new !== $code ) {
		file_put_contents( $file->getPathname(), $new );
		++$count;
	}
}

$installed_file = $vendor . '/composer/installed.json';
$installed      = json_decode( (string) file_get_contents( $installed_file ), true );
$packages       = isset( $installed['packages'] ) ? $installed['packages'] : $installed;
foreach ( $packages as &$package ) {
	foreach ( $scoped as $ns => $name ) {
		if ( $name !== $package['name'] || empty( $package['autoload']['psr-4'] ) ) {
			continue;
		}
		$psr4 = array();
		foreach ( $package['autoload']['psr-4'] as $prefix => $path ) {
			$psr4[ str_replace( $ns . '\\', 'VCAP\\Vendor\\' . $ns . '\\', $prefix ) ] = $path;
		}
		$package['autoload']['psr-4'] = $psr4;
	}
}
unset( $package );
if ( isset( $installed['packages'] ) ) {
	$installed['packages'] = $packages;
} else {
	$installed = $packages;
}
file_put_contents( $installed_file, json_encode( $installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo "Scoped " . implode( ', ', array_keys( $scoped ) ) . " into VCAP\\Vendor ({$count} files).\n";
