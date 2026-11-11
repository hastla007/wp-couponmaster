<?php
/**
 * Generates languages/wc-voucher-studio.pot from the PHP sources (no WP-CLI needed).
 * Usage: php bin/make-pot.php
 */
$root   = dirname( __DIR__ );
$domain = 'wc-voucher-studio';
$funcs  = array( '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1, '_x' => 'x', '_n' => 'n' );
$files  = array( "$root/wc-voucher-studio.php" );
foreach ( array( 'src', 'templates' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir" ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && 'php' === $f->getExtension() ) {
			$files[] = $f->getPathname();
		}
	}
}
sort( $files );
$entries = array();
foreach ( $files as $file ) {
	$tokens  = token_get_all( file_get_contents( $file ) );
	$comment = '';
	$count   = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && T_COMMENT === $t[0] && false !== stripos( $t[1], 'translators:' ) ) {
			$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $t[1] ) );
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funcs[ $t[1] ] ) ) {
			continue;
		}
		$j = $i + 1;
		while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
			continue;
		}
		// Collect top-level string arguments.
		$args  = array();
		$depth = 0;
		$cur   = null;
		for ( $k = $j + 1; $k < $count; $k++ ) {
			$tk = $tokens[ $k ];
			if ( '(' === $tk || '[' === $tk ) {
				$depth++;
			} elseif ( ')' === $tk || ']' === $tk ) {
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
				$depth--;
			} elseif ( ',' === $tk && 0 === $depth ) {
				$args[] = $cur;
				$cur    = null;
			} elseif ( 0 === $depth && is_array( $tk ) && T_CONSTANT_ENCAPSED_STRING === $tk[0] ) {
				$cur = null === $cur ? eval( 'return ' . $tk[1] . ';' ) : $cur; // phpcs:ignore
			} elseif ( 0 === $depth && is_array( $tk ) && ! in_array( $tk[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				$cur = false; // Non-literal argument.
			}
		}
		$type = $funcs[ $t[1] ];
		$line = $t[2];
		$ref  = str_replace( $root . '/', '', $file ) . ':' . $line;
		if ( 'n' === $type ) {
			list( $single, $plural ) = array( $args[0] ?? null, $args[1] ?? null );
			$dom = $args[3] ?? null;
			if ( ! is_string( $single ) || ! is_string( $plural ) || $dom !== $domain ) {
				continue;
			}
			$key = $single . "\0" . $plural;
		} elseif ( 'x' === $type ) {
			$single = $args[0] ?? null;
			$ctx    = $args[1] ?? null;
			if ( ! is_string( $single ) || ( $args[2] ?? null ) !== $domain ) {
				continue;
			}
			$key = $ctx . "\4" . $single;
		} else {
			$single = $args[0] ?? null;
			if ( ! is_string( $single ) || ( $args[1] ?? null ) !== $domain ) {
				continue;
			}
			$key = $single;
		}
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array( 'refs' => array(), 'comment' => '', 'plural' => $plural ?? null, 'ctx' => $ctx ?? null, 'msgid' => $single );
		}
		$entries[ $key ]['refs'][] = $ref;
		if ( $comment ) {
			$entries[ $key ]['comment'] = $comment;
		}
		$comment = '';
		$plural  = null;
		$ctx     = null;
	}
}
$esc = static fn( $s ) => '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s ) . '"';
$out = "# Copyright (C) " . gmdate( 'Y' ) . " Voucher Studio for WooCommerce\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Voucher Studio for WooCommerce 1.0.0\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i+0000' ) . "\\n\"\n\"X-Domain: $domain\\n\"\n\n";
foreach ( $entries as $e ) {
	if ( $e['comment'] ) {
		$out .= '#. ' . str_replace( "\n", "\n#. ", $e['comment'] ) . "\n";
	}
	$out .= '#: ' . implode( ' ', array_slice( $e['refs'], 0, 6 ) ) . "\n";
	if ( preg_match( '/%[0-9$]*[sd]/', $e['msgid'] ) ) {
		$out .= "#, php-format\n";
	}
	if ( $e['ctx'] ) {
		$out .= 'msgctxt ' . $esc( $e['ctx'] ) . "\n";
	}
	$out .= 'msgid ' . $esc( $e['msgid'] ) . "\n";
	if ( $e['plural'] ) {
		$out .= 'msgid_plural ' . $esc( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$out .= "msgstr \"\"\n\n";
	}
}
@mkdir( "$root/languages" );
file_put_contents( "$root/languages/$domain.pot", $out );
echo count( $entries ) . " strings written to languages/$domain.pot\n";
