<?php
/**
 * Router for PHP's built-in web server (browser tests): static files and PHP files are served directly,
 * everything else goes to WordPress (pretty permalinks).
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $root . $path;
if ( '/' !== $path && ( is_file( $file ) || ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir( $root );
require $root . '/index.php';
