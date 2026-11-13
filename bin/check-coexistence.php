<?php
/**
 * Coexistence check for two installed plugins (CI job "pass-captain"):
 *   php bin/check-coexistence.php <plugin dir A> <plugin dir B>
 *
 * Fails when both bundle the same Composer package unscoped (two copies of a class – whichever loads first wins),
 * use the same Composer autoloader class suffix, or declare the same global functions or constants.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/check-coexistence.php <plugin dir A> <plugin dir B>\n" );
	exit( 2 );
}

function vcap_coexist_packages( string $dir ): array {
	$file = rtrim( $dir, '/' ) . '/vendor/composer/installed.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	$list = $data['packages'] ?? $data;
	return array_values( array_filter( array_map( static fn( $p ) => (string) ( $p['name'] ?? '' ), (array) $list ) ) );
}

function vcap_coexist_suffix( string $dir ): string {
	$file = rtrim( $dir, '/' ) . '/vendor/autoload.php';
	return is_readable( $file ) && preg_match( '/ComposerAutoloaderInit(\w+)::/', (string) file_get_contents( $file ), $m ) ? $m[1] : '';
}

/** Global (not namespaced) functions and constants defined in the plugin's own PHP files (vendor excluded). */
function vcap_coexist_globals( string $dir ): array {
	$functions = array();
	$constants = array();
	$it        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( 'php' !== $file->getExtension() || str_contains( $path, '/vendor/' ) || str_contains( $path, '/tests/' ) || str_contains( $path, '/node_modules/' ) ) {
			continue;
		}
		$tokens    = token_get_all( (string) file_get_contents( $path ) );
		$namespace = false;
		$depth     = 0;
		foreach ( $tokens as $i => $t ) {
			if ( is_array( $t ) && T_NAMESPACE === $t[0] ) {
				$namespace = true;
			}
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $t ) {
				--$depth;
			}
			if ( ! $namespace && 0 === $depth && is_array( $t ) && T_FUNCTION === $t[0] ) {
				for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
						$functions[] = strtolower( $tokens[ $j ][1] );
						break;
					}
					if ( '(' === $tokens[ $j ] ) {
						break; // Closure.
					}
				}
			}
		}
		if ( preg_match_all( "/\\bdefine\\(\\s*'([A-Z0-9_]+)'/", (string) file_get_contents( $path ), $m ) ) {
			$constants = array_merge( $constants, $m[1] );
		}
	}
	return array( array_unique( $functions ), array_unique( $constants ) );
}

list( , $a, $b ) = $argv;
$problems = array();
$shared   = array_intersect( vcap_coexist_packages( $a ), vcap_coexist_packages( $b ) );
if ( $shared ) {
	$problems[] = 'Both bundle these Composer packages (scope one copy): ' . implode( ', ', $shared );
}
$sa = vcap_coexist_suffix( $a );
$sb = vcap_coexist_suffix( $b );
if ( '' !== $sa && $sa === $sb ) {
	$problems[] = "Same Composer autoloader suffix: {$sa}";
}
list( $fa, $ca ) = vcap_coexist_globals( $a );
list( $fb, $cb ) = vcap_coexist_globals( $b );
if ( $same = array_intersect( $fa, $fb ) ) {
	$problems[] = 'Same global functions: ' . implode( ', ', $same );
}
if ( $same = array_intersect( $ca, $cb ) ) {
	$problems[] = 'Same global constants: ' . implode( ', ', $same );
}
if ( $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}
printf( "No conflicts: %d + %d packages, %d + %d global functions, %d + %d constants.\n", count( vcap_coexist_packages( $a ) ), count( vcap_coexist_packages( $b ) ), count( $fa ), count( $fb ), count( $ca ), count( $cb ) );
