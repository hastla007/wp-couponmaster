<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal, dependency-free reader for the first worksheet of CSV, XLSX, ODS and legacy XLS (BIFF8) files.
 * Returns rows as arrays of strings. Designed for code lists, not for formulas or formatting.
 */
final class SpreadsheetReader {
	public const MAX_ROWS = 100000;

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function read( string $file, string $extension ): array {
		switch ( strtolower( $extension ) ) {
			case 'csv':
			case 'txt':
				return self::csv( $file );
			case 'xlsx':
				return self::xlsx( $file );
			case 'ods':
				return self::ods( $file );
			case 'xls':
				return self::xls( $file );
		}
		throw new \InvalidArgumentException( esc_html__( 'Unsupported file type.', 'voucher-captain' ) );
	}

	// ---------------------------------------------------------------- CSV.

	public static function csv( string $file ): array {
		$content = (string) file_get_contents( $file );
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content ); // BOM.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		$first     = strtok( $content, "\n" ) ?: '';
		$delimiter = ',';
		$best      = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $d ) {
			$count = substr_count( $first, $d );
			if ( $count > $best ) {
				$best      = $count;
				$delimiter = $d;
			}
		}
		$rows   = array();
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $content );
		rewind( $handle );
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			if ( array( null ) === $row ) {
				continue;
			}
			$rows[] = array_map( static fn( $c ) => trim( (string) $c ), $row );
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
		}
		fclose( $handle );
		return $rows;
	}

	// ---------------------------------------------------------------- XLSX.

	/** Most entries a spreadsheet archive may have. */
	public const MAX_ZIP_ENTRIES = 5000;
	/** Largest compression ratio accepted for entries over 1 MB (real spreadsheets stay far below). */
	public const MAX_ZIP_RATIO = 200;

	/** Largest unpacked size of one archive entry (filter "vcap_import_max_unzipped_bytes", default 64 MB). */
	public static function max_entry_bytes(): int {
		return max( 1024, (int) apply_filters( 'vcap_import_max_unzipped_bytes', 64 * 1024 * 1024 ) );
	}

	/**
	 * One entry of an XLSX/ODS archive, guarded against zip bombs: entry count, unpacked size and compression
	 * ratio are checked before reading, and reading stops at the limit even when the archive lies about sizes.
	 */
	private static function zip_entry( string $file, string $name ): ?string {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			throw new \RuntimeException( esc_html__( 'The file is not a valid spreadsheet archive.', 'voucher-captain' ) );
		}
		try {
			if ( $zip->numFiles > self::MAX_ZIP_ENTRIES ) {
				throw new \RuntimeException( esc_html__( 'The spreadsheet archive has too many entries.', 'voucher-captain' ) );
			}
			$stat = $zip->statName( $name );
			if ( false === $stat ) {
				return null;
			}
			$limit = self::max_entry_bytes();
			$size  = (int) $stat['size'];
			$comp  = max( 1, (int) $stat['comp_size'] );
			if ( $size > $limit || ( $size > 1024 * 1024 && $size / $comp > self::MAX_ZIP_RATIO ) ) {
				throw new \RuntimeException( esc_html__( 'The spreadsheet is too large to import. Please split it into smaller files.', 'voucher-captain' ) );
			}
			$data = $zip->getFromName( $name, $limit + 1 );
			if ( false === $data ) {
				return null;
			}
			if ( strlen( $data ) > $limit ) {
				throw new \RuntimeException( esc_html__( 'The spreadsheet is too large to import. Please split it into smaller files.', 'voucher-captain' ) );
			}
			return $data;
		} finally {
			$zip->close();
		}
	}

	private static function xml( string $data ): \SimpleXMLElement {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $data, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			throw new \RuntimeException( esc_html__( 'The spreadsheet XML could not be parsed.', 'voucher-captain' ) );
		}
		return $xml;
	}

	private static function col_index( string $ref ): int {
		$letters = preg_replace( '/\d+/', '', strtoupper( $ref ) );
		$n       = 0;
		for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	public static function xlsx( string $file ): array {
		$shared = array();
		$ss     = self::zip_entry( $file, 'xl/sharedStrings.xml' );
		if ( $ss ) {
			$xml = self::xml( $ss );
			foreach ( $xml->si as $si ) {
				if ( isset( $si->t ) ) {
					$shared[] = (string) $si->t;
				} else {
					$text = '';
					foreach ( $si->r as $r ) {
						$text .= (string) $r->t;
					}
					$shared[] = $text;
				}
			}
		}

		// First sheet according to workbook relationships.
		$sheet_path = 'xl/worksheets/sheet1.xml';
		$wb         = self::zip_entry( $file, 'xl/workbook.xml' );
		$rels       = self::zip_entry( $file, 'xl/_rels/workbook.xml.rels' );
		if ( $wb && $rels ) {
			$wbx   = self::xml( $wb );
			$relx  = self::xml( $rels );
			$sheet = $wbx->sheets->sheet[0] ?? null;
			if ( $sheet ) {
				$rid = (string) $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
				foreach ( $relx->Relationship as $rel ) {
					if ( (string) $rel['Id'] === $rid ) {
						$target     = ltrim( (string) $rel['Target'], '/' );
						$sheet_path = str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
					}
				}
			}
		}
		$data = self::zip_entry( $file, $sheet_path );
		if ( null === $data ) {
			throw new \RuntimeException( esc_html__( 'No worksheet found.', 'voucher-captain' ) );
		}
		$xml  = self::xml( $data );
		$rows = array();
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$idx  = isset( $c['r'] ) ? self::col_index( (string) $c['r'] ) : count( $cells );
				$type = (string) $c['t'];
				if ( 's' === $type ) {
					$value = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$value = (string) $c->is->t;
				} else {
					$value = (string) $c->v;
				}
				$cells[ $idx ] = trim( self::number_to_string( $value, $type ) );
			}
			if ( $cells ) {
				$max  = max( array_keys( $cells ) );
				$line = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$line[] = $cells[ $i ] ?? '';
				}
				$rows[] = $line;
			}
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $rows;
	}

	private static function number_to_string( string $value, string $type = 'n' ): string {
		if ( ( '' === $type || 'n' === $type ) && is_numeric( $value ) && preg_match( '/^-?\d+(\.0+)?(E\+?\d+)?$/i', $value ) ) {
			$f = (float) $value;
			if ( abs( $f ) < 1e15 && floor( $f ) === $f ) {
				return number_format( $f, 0, '.', '' );
			}
		}
		return $value;
	}

	// ---------------------------------------------------------------- ODS.

	public static function ods( string $file ): array {
		$data = self::zip_entry( $file, 'content.xml' );
		if ( null === $data ) {
			throw new \RuntimeException( esc_html__( 'No content found.', 'voucher-captain' ) );
		}
		$xml = self::xml( $data );
		$ns  = $xml->getNamespaces( true );
		$xml->registerXPathNamespace( 'table', $ns['table'] ?? 'urn:oasis:names:tc:opendocument:xmlns:table:1.0' );
		$xml->registerXPathNamespace( 'text', $ns['text'] ?? 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );
		$tables = $xml->xpath( '//table:table' );
		if ( ! $tables ) {
			return array();
		}
		$table_ns = $ns['table'] ?? 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
		$text_ns  = $ns['text'] ?? 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
		$office   = $ns['office'] ?? 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
		$rows     = array();
		foreach ( $tables[0]->xpath( './/table:table-row' ) as $row ) {
			$repeat_rows = min( 1000, max( 1, (int) $row->attributes( $table_ns )->{'number-rows-repeated'} ) );
			$line        = array();
			foreach ( $row->children( $table_ns ) as $name => $cell ) {
				if ( 'table-cell' !== $name && 'covered-table-cell' !== $name ) {
					continue;
				}
				$repeat = min( 256, max( 1, (int) $cell->attributes( $table_ns )->{'number-columns-repeated'} ) );
				$value  = '';
				$paras  = $cell->children( $text_ns );
				if ( count( $paras ) ) {
					$parts = array();
					foreach ( $paras as $p ) {
						$parts[] = trim( strip_tags( $p->asXML() ) );
					}
					$value = implode( "\n", $parts );
				} else {
					$value = (string) $cell->attributes( $office )->value;
				}
				for ( $i = 0; $i < $repeat; $i++ ) {
					$line[] = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
				}
			}
			while ( $line && '' === end( $line ) ) {
				array_pop( $line );
			}
			if ( ! $line ) {
				continue;
			}
			for ( $i = 0; $i < $repeat_rows; $i++ ) {
				$rows[] = $line;
			}
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $rows;
	}

	// ---------------------------------------------------------------- XLS (BIFF8 in OLE2).

	public static function xls( string $file ): array {
		$data = (string) file_get_contents( $file );
		if ( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" !== substr( $data, 0, 8 ) ) {
			// Some "xls" exports are really CSV/HTML.
			return self::csv( $file );
		}
		$stream = self::ole_stream( $data, array( 'Workbook', 'Book' ) );
		if ( null === $stream ) {
			throw new \RuntimeException( esc_html__( 'No workbook stream found.', 'voucher-captain' ) );
		}
		return self::biff( $stream );
	}

	private static function u16( string $s, int $o ): int {
		return unpack( 'v', substr( $s, $o, 2 ) )[1];
	}

	private static function u32( string $s, int $o ): int {
		return unpack( 'V', substr( $s, $o, 4 ) )[1];
	}

	private static function ole_stream( string $data, array $names ): ?string {
		$sector_size      = 1 << self::u16( $data, 30 );
		$mini_size        = 1 << self::u16( $data, 32 );
		$dir_start        = self::u32( $data, 48 );
		$mini_cutoff      = self::u32( $data, 56 );
		$mini_fat_start   = self::u32( $data, 60 );
		$difat_start      = self::u32( $data, 68 );
		$difat_count      = self::u32( $data, 72 );
		$sector = static fn( int $id ): string => substr( $data, ( $id + 1 ) * $sector_size, $sector_size );

		// DIFAT → FAT sector list.
		$fat_sectors = array();
		for ( $i = 0; $i < 109; $i++ ) {
			$id = self::u32( $data, 76 + $i * 4 );
			if ( $id < 0xFFFFFFFA ) {
				$fat_sectors[] = $id;
			}
		}
		$next = $difat_start;
		for ( $n = 0; $n < $difat_count && $next < 0xFFFFFFFA; $n++ ) {
			$block = $sector( $next );
			for ( $i = 0; $i < $sector_size / 4 - 1; $i++ ) {
				$id = self::u32( $block, $i * 4 );
				if ( $id < 0xFFFFFFFA ) {
					$fat_sectors[] = $id;
				}
			}
			$next = self::u32( $block, $sector_size - 4 );
		}
		$fat = array();
		foreach ( $fat_sectors as $id ) {
			$fat = array_merge( $fat, array_values( unpack( 'V*', $sector( $id ) ) ) );
		}
		$chain = static function ( int $start, array $table, int $limit = 1000000 ): array {
			$ids = array();
			while ( $start < 0xFFFFFFFA && isset( $table[ $start ] ) && count( $ids ) < $limit ) {
				$ids[] = $start;
				$start = $table[ $start ];
			}
			return $ids;
		};
		$read = static function ( int $start ) use ( $chain, $fat, $sector ): string {
			$out = '';
			foreach ( $chain( $start, $fat ) as $id ) {
				$out .= $sector( $id );
			}
			return $out;
		};

		$dir   = $read( $dir_start );
		$root  = null;
		$found = null;
		for ( $o = 0; $o + 128 <= strlen( $dir ); $o += 128 ) {
			$name_len = self::u16( $dir, $o + 64 );
			$name     = $name_len > 2 ? mb_convert_encoding( substr( $dir, $o, $name_len - 2 ), 'UTF-8', 'UTF-16LE' ) : '';
			$type     = ord( $dir[ $o + 66 ] );
			$entry    = array(
				'start' => self::u32( $dir, $o + 116 ),
				'size'  => self::u32( $dir, $o + 120 ),
			);
			if ( 5 === $type ) {
				$root = $entry;
			} elseif ( 2 === $type && in_array( $name, $names, true ) && null === $found ) {
				$found = $entry;
			}
		}
		if ( ! $found ) {
			return null;
		}
		if ( $found['size'] >= $mini_cutoff || ! $root ) {
			return substr( $read( $found['start'] ), 0, $found['size'] );
		}
		// Small stream stored in the mini stream.
		$mini_stream = $read( $root['start'] );
		$mini_fat    = array_values( unpack( 'V*', $read( $mini_fat_start ) ) ?: array() );
		$out         = '';
		foreach ( $chain( $found['start'], $mini_fat ) as $id ) {
			$out .= substr( $mini_stream, $id * $mini_size, $mini_size );
		}
		return substr( $out, 0, $found['size'] );
	}

	private static function rk( int $rk ): float {
		if ( $rk & 0x02 ) {
			$value = (float) ( $rk >> 2 );
			if ( $rk & 0x80000000 ) {
				$value = (float) ( ( $rk >> 2 ) | ~0x3FFFFFFF );
			}
		} else {
			$bits  = pack( 'V', 0 ) . pack( 'V', $rk & 0xFFFFFFFC );
			$value = unpack( 'd', $bits )[1];
		}
		return ( $rk & 0x01 ) ? $value / 100 : $value;
	}

	private static function num( float $f ): string {
		return ( floor( $f ) === $f && abs( $f ) < 1e15 ) ? number_format( $f, 0, '.', '' ) : (string) $f;
	}

	/**
	 * Read a BIFF8 unicode string that may continue across CONTINUE record boundaries.
	 *
	 * @param array<int,int> $bounds offsets in $buf where a CONTINUE record began
	 */
	private static function sst_string( string $buf, int &$pos, array $bounds ): string {
		$chars = self::u16( $buf, $pos );
		$flags = ord( $buf[ $pos + 2 ] );
		$pos  += 3;
		$rich  = $flags & 0x08 ? self::u16( $buf, $pos ) : 0;
		$pos  += $flags & 0x08 ? 2 : 0;
		$ext   = $flags & 0x04 ? self::u32( $buf, $pos ) : 0;
		$pos  += $flags & 0x04 ? 4 : 0;
		$wide  = (bool) ( $flags & 0x01 );
		$out   = '';
		$left  = $chars;
		while ( $left > 0 ) {
			$next_bound = PHP_INT_MAX;
			foreach ( $bounds as $b ) {
				if ( $b > $pos ) {
					$next_bound = $b;
					break;
				}
			}
			$avail_bytes = $next_bound - $pos;
			$take        = min( $left, $wide ? intdiv( $avail_bytes, 2 ) : $avail_bytes );
			$bytes       = substr( $buf, $pos, $wide ? $take * 2 : $take );
			$out        .= $wide ? mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16LE' ) : mb_convert_encoding( $bytes, 'UTF-8', 'ISO-8859-1' );
			$pos        += strlen( $bytes );
			$left       -= $take;
			if ( $left > 0 && $pos === $next_bound ) {
				// Continuation re-states the compression flag.
				$wide = (bool) ( ord( $buf[ $pos ] ) & 0x01 );
				++$pos;
			} elseif ( $left > 0 ) {
				break;
			}
		}
		$pos += $rich * 4 + $ext;
		return $out;
	}

	private static function biff( string $s ): array {
		$len     = strlen( $s );
		$pos     = 0;
		$sst     = array();
		$cells   = array();
		$in_sheet = false;
		$sheets_seen = 0;

		while ( $pos + 4 <= $len ) {
			$type = self::u16( $s, $pos );
			$size = self::u16( $s, $pos + 2 );
			$data = substr( $s, $pos + 4, $size );
			$pos += 4 + $size;

			switch ( $type ) {
				case 0x0809: // BOF.
					$dt = self::u16( $data, 2 );
					if ( 0x0010 === $dt ) {
						++$sheets_seen;
						$in_sheet = 1 === $sheets_seen;
					}
					break;
				case 0x000A: // EOF.
					if ( $in_sheet ) {
						break 2;
					}
					break;
				case 0x00FC: // SST (+ CONTINUE).
					$buf    = $data;
					$bounds = array();
					while ( $pos + 4 <= $len && 0x003C === self::u16( $s, $pos ) ) {
						$csize    = self::u16( $s, $pos + 2 );
						$bounds[] = strlen( $buf );
						$buf     .= substr( $s, $pos + 4, $csize );
						$pos     += 4 + $csize;
					}
					$count = self::u32( $buf, 4 );
					$p     = 8;
					for ( $i = 0; $i < $count && $p < strlen( $buf ); $i++ ) {
						$sst[] = self::sst_string( $buf, $p, $bounds );
					}
					break;
				case 0x00FD: // LABELSST.
					if ( $in_sheet ) {
						$cells[ self::u16( $data, 0 ) ][ self::u16( $data, 2 ) ] = $sst[ self::u32( $data, 6 ) ] ?? '';
					}
					break;
				case 0x0204: // LABEL.
					if ( $in_sheet ) {
						$n    = self::u16( $data, 6 );
						$wide = ord( $data[8] ?? "\0" ) & 0x01;
						$str  = substr( $data, 9, $wide ? $n * 2 : $n );
						$cells[ self::u16( $data, 0 ) ][ self::u16( $data, 2 ) ] = $wide ? mb_convert_encoding( $str, 'UTF-8', 'UTF-16LE' ) : mb_convert_encoding( $str, 'UTF-8', 'ISO-8859-1' );
					}
					break;
				case 0x0203: // NUMBER.
					if ( $in_sheet ) {
						$cells[ self::u16( $data, 0 ) ][ self::u16( $data, 2 ) ] = self::num( unpack( 'd', substr( $data, 6, 8 ) )[1] );
					}
					break;
				case 0x027E: // RK.
					if ( $in_sheet ) {
						$cells[ self::u16( $data, 0 ) ][ self::u16( $data, 2 ) ] = self::num( self::rk( self::u32( $data, 6 ) ) );
					}
					break;
				case 0x00BD: // MULRK.
					if ( $in_sheet ) {
						$row   = self::u16( $data, 0 );
						$first = self::u16( $data, 2 );
						$n     = intdiv( strlen( $data ) - 6, 6 );
						for ( $i = 0; $i < $n; $i++ ) {
							$cells[ $row ][ $first + $i ] = self::num( self::rk( self::u32( $data, 4 + $i * 6 + 2 ) ) );
						}
					}
					break;
			}
			if ( count( $cells ) >= self::MAX_ROWS ) {
				break;
			}
		}

		ksort( $cells );
		$rows = array();
		foreach ( $cells as $row ) {
			$max  = max( array_keys( $row ) );
			$line = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = trim( (string) ( $row[ $i ] ?? '' ) );
			}
			$rows[] = $line;
		}
		return $rows;
	}
}
