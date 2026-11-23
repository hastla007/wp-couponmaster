<?php
/**
 * Writes docs/HOOKS.md: the reference of the plugin's own actions and filters (`vcap_*`), generated from the code
 * (do_action / apply_filters / Db::action with a literal hook name) with the doc comment right before each call
 * (D13). Actions fired through Db::action() are marked as deferred until COMMIT inside a transaction (D6).
 * The "Since" column names the first plugin version of each hook: from bin/hooks-since.php (hooks up to 1.2.0) or
 * from `@since x.y.z` in the doc comment before the call (every hook added later); a hook without either is an error.
 *   php bin/hooks.php          (writes docs/HOOKS.md)
 *   php bin/hooks.php --check  (exit 1 if docs/HOOKS.md is out of date)
 */
$root   = dirname( __DIR__ );
$target = $root . '/docs/HOOKS.md';

/**
 * Hooks found in the given files.
 *
 * @return array<string, array{type:string, files:string[], doc:string}>
 */
function vcap_hooks_scan( string $root ): array {
	$files = array();
	foreach ( array( 'src', 'templates' ) as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}
	$files = array_merge( $files, array( $root . '/voucher-captain.php' ) );
	sort( $files );
	$hooks = array();
	foreach ( $files as $path ) {
		$code = (string) file_get_contents( $path );
		$rel  = ltrim( substr( $path, strlen( $root ) ), '/' );
		if ( ! preg_match_all( "/\\b(do_action|apply_filters|do_action_ref_array|apply_filters_ref_array|Db::action|Db::action_ref_array)\\(\\s*'(vcap_[a-z0-9_]+)'/", $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $m as $match ) {
			$name = $match[2][0];
			$type = str_starts_with( $match[1][0], 'do_action' ) || str_starts_with( $match[1][0], 'Db::' ) ? 'action' : 'filter';
			$doc  = vcap_hooks_doc( substr( $code, 0, (int) $match[0][1] ) );
			$hook = $hooks[ $name ] ?? array( 'type' => $type, 'files' => array(), 'doc' => '', 'deferred' => false, 'since' => '' );
			if ( '' === $hook['since'] ) {
				$hook['since'] = vcap_hooks_since( substr( $code, 0, (int) $match[0][1] ) );
			}
			$hook['deferred'] = $hook['deferred'] || str_starts_with( $match[1][0], 'Db::' );
			$hook['files'][] = $rel;
			$hook['files']   = array_values( array_unique( $hook['files'] ) );
			if ( '' === $hook['doc'] && '' !== $doc ) {
				$hook['doc'] = $doc;
			}
			$hooks[ $name ] = $hook;
		}
	}
	ksort( $hooks );
	return $hooks;
}

/** The doc comment that ends right before a hook call (no statement in between), as one line of text. */
function vcap_hooks_doc( string $before ): string {
	$end = strrpos( $before, '*/' );
	if ( false === $end ) {
		return '';
	}
	$between = substr( $before, $end + 2 );
	if ( str_contains( $between, ';' ) || str_contains( $between, '{' ) || str_contains( $between, '}' ) || substr_count( $between, "\n" ) > 3 ) {
		return '';
	}
	$start = strrpos( substr( $before, 0, $end ), '/*' );
	if ( false === $start ) {
		return '';
	}
	$text  = substr( $before, $start + 2, $end - $start - 2 );
	$lines = array();
	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( ltrim( trim( $line ), '*' ) );
		if ( str_starts_with( $line, '@' ) ) {
			break;
		}
		if ( preg_match( '/^(translators:|phpcs:)/', $line ) ) {
			return '';
		}
		$lines[] = $line;
	}
	return trim( preg_replace( '/\s+/', ' ', implode( ' ', $lines ) ) );
}

/**
 * The `@since x.y.z` of the doc comment of the statement with the hook call, or ''. Same rule as vcap_hooks_doc(): the
 * comment may be followed by the start of that statement (`return (bool) apply_filters(`, `$max = max( 1, apply_filters(`),
 * but not by another statement or block.
 */
function vcap_hooks_since( string $before ): string {
	$end = strrpos( $before, '*/' );
	if ( false === $end ) {
		return '';
	}
	$between = substr( $before, $end + 2 );
	if ( str_contains( $between, ';' ) || str_contains( $between, '{' ) || str_contains( $between, '}' ) || substr_count( $between, "\n" ) > 3 ) {
		return '';
	}
	$start = strrpos( substr( $before, 0, $end ), '/*' );
	if ( false === $start ) {
		return '';
	}
	return preg_match( '/@since\s+(\d+\.\d+\.\d+)/', substr( $before, $start, $end - $start ), $m ) ? $m[1] : '';
}

/**
 * Adds the first version of every hook: bin/hooks-since.php wins (hooks up to 1.2.0), then `@since` in the code.
 *
 * @return array{0: array, 1: string[]} The hooks and the names of hooks without a version.
 */
function vcap_hooks_with_since( array $hooks, array $known ): array {
	$missing = array();
	foreach ( $hooks as $name => $hook ) {
		$hooks[ $name ]['since'] = $known[ $name ] ?? (string) ( $hook['since'] ?? '' );
		if ( '' === $hooks[ $name ]['since'] ) {
			$missing[] = $name;
		}
	}
	return array( $hooks, $missing );
}

function vcap_hooks_markdown( array $hooks ): string {
	$out  = "# Hook reference\n\n";
	$out .= "Actions and filters of Voucher Captain (`vcap_*`), generated from the code by `php bin/hooks.php` – do not edit by hand.\n";
	$out .= "CI checks that this file is current (`php bin/hooks.php --check`). Connector contracts (when hooks fire, transactions)\n";
	$out .= "are described in CONNECTORS.md and API.md. Actions marked \"after COMMIT\" are buffered while a database transaction\n";
	$out .= "is open and fired only once it was committed (CONNECTORS.md, \"Event timing\").\n\n";
	$out .= "\"Since\" is the first plugin version with the hook (from the git history; 1.0.x was never released\n";
	$out .= "with a public API contract, the contract starts with 1.1.0). Add-ons check the contract version instead of single hooks: `VCAP_CONTRACT_VERSION` (CONNECTORS.md, section 6).\n";
	foreach ( array( 'action' => 'Actions', 'filter' => 'Filters' ) as $type => $title ) {
		$out .= "\n## {$title}\n\n| Hook | Since | Where | Description |\n|---|---|---|---|\n";
		foreach ( $hooks as $name => $hook ) {
			if ( $type !== $hook['type'] ) {
				continue;
			}
			$files = implode( ', ', array_map( static fn( $f ) => '`' . $f . '`', $hook['files'] ) );
			$doc   = '' !== $hook['doc'] ? str_replace( '|', '\\|', $hook['doc'] ) : '–';
			if ( ! empty( $hook['deferred'] ) ) {
				$doc .= ' *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).*';
			}
			$out  .= "| `{$name}` | {$hook['since']} | {$files} | {$doc} |\n";
		}
	}
	return $out;
}

list( $hooks, $missing ) = vcap_hooks_with_since( vcap_hooks_scan( $root ), require __DIR__ . '/hooks-since.php' );
if ( $missing ) {
	fwrite( STDERR, "Hooks without a first version (add `@since x.y.z` to the doc comment before the call): " . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}
$markdown = vcap_hooks_markdown( $hooks );
if ( in_array( '--check', $argv, true ) ) {
	if ( ! is_file( $target ) || (string) file_get_contents( $target ) !== $markdown ) {
		fwrite( STDERR, "docs/HOOKS.md is out of date: run php bin/hooks.php\n" );
		exit( 1 );
	}
	echo "docs/HOOKS.md is up to date.\n";
	exit( 0 );
}
file_put_contents( $target, $markdown );
echo 'Wrote ' . $target . "\n";
