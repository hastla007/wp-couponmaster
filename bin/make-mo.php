<?php
/**
 * Compiles languages/*.po into .mo files (no gettext tools needed).
 * Usage: php bin/make-mo.php
 */
$root = dirname( __DIR__ );
foreach ( glob( "$root/languages/*.po" ) as $po ) {
	$entries = array();
	$cur     = array();
	$last    = null;
	$flush   = static function () use ( &$cur, &$entries ) {
		if ( isset( $cur['msgid'] ) ) {
			$id = ( isset( $cur['msgctxt'] ) ? $cur['msgctxt'] . "\4" : '' ) . $cur['msgid'];
			if ( isset( $cur['msgid_plural'] ) ) {
				$id .= "\0" . $cur['msgid_plural'];
				ksort( $cur['msgstr'] );
				$str = implode( "\0", $cur['msgstr'] );
			} else {
				$str = $cur['msgstr'][0] ?? '';
			}
			if ( '' !== str_replace( "\0", '', $str ) || '' === $cur['msgid'] ) {
				$entries[ $id ] = $str;
			}
		}
		$cur = array();
	};
	$unq = static fn( $s ) => stripcslashes( substr( trim( $s ), 1, -1 ) );
	foreach ( file( $po ) as $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( '' === trim( $line ) ) {
			$flush();
			$last = null;
			continue;
		}
		if ( '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid)\s+(".*")$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] && isset( $cur['msgid'] ) ) {
				$flush();
			}
			$cur[ $m[1] ] = $unq( $m[2] );
			$last         = array( $m[1] );
		} elseif ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+(".*")$/', $line, $m ) ) {
			$idx                   = '' === $m[1] ? 0 : (int) $m[1];
			$cur['msgstr'][ $idx ] = $unq( $m[2] );
			$last                  = array( 'msgstr', $idx );
		} elseif ( '"' === $line[0] && $last ) {
			if ( 'msgstr' === $last[0] ) {
				$cur['msgstr'][ $last[1] ] .= $unq( $line );
			} else {
				$cur[ $last[0] ] .= $unq( $line );
			}
		}
	}
	$flush();
	ksort( $entries, SORT_STRING );
	$n       = count( $entries );
	$ids     = '';
	$strs    = '';
	$id_tab  = array();
	$str_tab = array();
	foreach ( $entries as $id => $str ) {
		$id_tab[]  = array( strlen( $id ), strlen( $ids ) );
		$ids      .= $id . "\0";
		$str_tab[] = array( strlen( $str ), strlen( $strs ) );
		$strs     .= $str . "\0";
	}
	$header_size = 28;
	$ids_offset  = $header_size + $n * 16;
	$strs_offset = $ids_offset + strlen( $ids );
	$mo          = pack( 'V7', 0x950412de, 0, $n, $header_size, $header_size + $n * 8, 0, $ids_offset + strlen( $ids ) + strlen( $strs ) );
	foreach ( $id_tab as $t ) {
		$mo .= pack( 'VV', $t[0], $ids_offset + $t[1] );
	}
	foreach ( $str_tab as $t ) {
		$mo .= pack( 'VV', $t[0], $strs_offset + $t[1] );
	}
	$mo .= $ids . $strs;
	file_put_contents( substr( $po, 0, -3 ) . '.mo', $mo );
	echo basename( $po ) . ": $n entries\n";
}
