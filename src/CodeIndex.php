<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Whether codes are free, checked cheaply and in batches (B4).
 *
 * A code is taken when a voucher, a code pool entry or a WooCommerce coupon uses it. The checks run in this order:
 * 1. the plugin's own tables (vouchers.code and code_pool.code carry a UNIQUE index; their collation compares
 *    case-insensitively like WooCommerce codes): one indexed query for a whole batch of candidates;
 * 2. WooCommerce, only for what is left: for a single code wc_get_coupon_id_by_code() – it compares
 *    LOWER(post_title), which no index serves, and caches no misses – asked for the final candidate only; for bulk
 *    runs (campaigns, imports) one query per CHUNK codes ("LOWER(post_title) IN (…)" on the shop_coupon rows).
 * WooCommerce misses are remembered for the request (per site), codes handed out by unique()/claim_free() are
 * remembered as taken, so two codes of one run never collide even before they are saved.
 */
final class CodeIndex {
	/** Codes per query of a batched check (placeholder list of the IN clause). */
	public const CHUNK = 500;
	/** Candidates generated per round of unique(). */
	public const CANDIDATES = 5;

	/** @var array<int, array<string, true>> Codes WooCommerce does not know, per site (lower case). */
	private static array $wc_free = array();
	/** @var array<int, array<string, true>> Codes handed out or found taken in this request, per site (lower case). */
	private static array $taken = array();

	/** Forgets what the request learned (tests; after codes were deleted). */
	public static function reset(): void {
		self::$wc_free = array();
		self::$taken   = array();
	}

	private static function key( string $code ): string {
		return strtolower( $code );
	}

	/** Remembers a code as taken for the rest of the request (it was saved or handed out). */
	public static function remember( string $code ): void {
		$blog                                       = get_current_blog_id();
		self::$taken[ $blog ][ self::key( $code ) ] = true;
		unset( self::$wc_free[ $blog ][ self::key( $code ) ] );
	}

	/**
	 * Codes of the list that a voucher or a code pool entry uses: one query on the unique code indexes of both
	 * tables per CHUNK codes.
	 *
	 * @param string[] $codes
	 * @return array<string, true> lower-case codes that are taken
	 */
	public static function own_taken( array $codes ): array {
		global $wpdb;
		$codes = array_values( array_unique( array_filter( array_map( 'strval', $codes ), 'strlen' ) ) );
		$found = array();
		if ( ! $codes ) {
			return $found;
		}
		$tables   = Install::tables();
		$vouchers = $tables['vouchers'];
		$pool     = $tables['pool'];
		foreach ( array_chunk( $codes, self::CHUNK ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$vouchers} WHERE code IN ({$in}) UNION ALL SELECT code FROM {$pool} WHERE code IN ({$in})", array_merge( $chunk, $chunk ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- placeholder list.
			foreach ( $rows as $row ) {
				$found[ self::key( (string) $row ) ] = true;
			}
		}
		return $found;
	}

	/**
	 * Codes of the list that a WooCommerce coupon (any status but trash) uses, one query per CHUNK codes. Misses
	 * are remembered for the request.
	 *
	 * @param string[] $codes
	 * @return array<string, true> lower-case codes that are taken
	 */
	public static function wc_taken( array $codes ): array {
		global $wpdb;
		$blog  = get_current_blog_id();
		$ask   = array();
		foreach ( $codes as $code ) {
			$key = self::key( (string) $code );
			if ( '' !== $key && ! isset( self::$wc_free[ $blog ][ $key ] ) ) {
				$ask[ $key ] = true;
			}
		}
		$found = array();
		foreach ( array_chunk( array_keys( $ask ), self::CHUNK ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT LOWER(post_title) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status NOT IN ('trash', 'auto-draft') AND LOWER(post_title) IN ({$in})", $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- placeholder list.
			foreach ( $rows as $row ) {
				$found[ self::key( (string) $row ) ] = true;
			}
			foreach ( $chunk as $key ) {
				if ( ! isset( $found[ $key ] ) ) {
					self::$wc_free[ $blog ][ $key ] = true;
				}
			}
		}
		return $found;
	}

	/** One code through WooCommerce's own lookup (filters included); a miss is remembered for the request. */
	private static function wc_has( string $code ): bool {
		$blog = get_current_blog_id();
		$key  = self::key( $code );
		if ( isset( self::$wc_free[ $blog ][ $key ] ) ) {
			return false;
		}
		$taken = function_exists( 'wc_get_coupon_id_by_code' ) && (int) wc_get_coupon_id_by_code( $code ) > 0;
		if ( ! $taken ) {
			self::$wc_free[ $blog ][ $key ] = true;
		}
		return $taken;
	}

	/** Whether a voucher, a code pool entry or a WooCommerce coupon uses the code (or this request handed it out). */
	public static function taken( string $code ): bool {
		if ( '' === $code ) {
			return true;
		}
		if ( isset( self::$taken[ get_current_blog_id() ][ self::key( $code ) ] ) ) {
			return true;
		}
		return (bool) self::own_taken( array( $code ) ) || self::wc_has( $code );
	}

	/**
	 * The first free code of the candidates, remembered as taken (handed out): one query on the own tables for all
	 * of them, then WooCommerce for the first candidate left – a further one only if WooCommerce knows it.
	 *
	 * @param string[] $candidates
	 */
	public static function claim_first( array $candidates ): ?string {
		$blog       = get_current_blog_id();
		$candidates = array_values( array_filter( array_map( 'strval', $candidates ), static fn( $c ) => '' !== $c && ! isset( self::$taken[ $blog ][ self::key( $c ) ] ) ) );
		$own        = self::own_taken( $candidates );
		foreach ( $candidates as $code ) {
			if ( isset( $own[ self::key( $code ) ] ) || self::wc_has( $code ) ) {
				self::$taken[ $blog ][ self::key( $code ) ] = true;
				continue;
			}
			self::remember( $code );
			return $code;
		}
		return null;
	}

	/**
	 * The free codes of a list (keys kept), checked in batches against the own tables and WooCommerce, and
	 * remembered as taken: the caller saves them now (import, campaign run).
	 *
	 * @param array<int|string, string> $codes
	 * @return array<int|string, string>
	 */
	public static function claim_free( array $codes ): array {
		$blog  = get_current_blog_id();
		$codes = array_filter( array_map( 'strval', $codes ), static fn( $c ) => '' !== $c && ! isset( self::$taken[ $blog ][ self::key( $c ) ] ) );
		$own   = self::own_taken( $codes );
		$rest  = array_filter( $codes, static fn( $c ) => ! isset( $own[ self::key( $c ) ] ) );
		$wc    = self::wc_taken( $rest );
		$free  = array();
		$seen  = array();
		foreach ( $codes as $n => $code ) {
			$key                        = self::key( $code );
			self::$taken[ $blog ][ $key ] = true;
			if ( isset( $own[ $key ] ) || isset( $wc[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$free[ $n ]   = $code;
		}
		return $free;
	}

	/**
	 * A new code (CodeGenerator::build() options) that no voucher, code pool entry or coupon uses: CANDIDATES codes
	 * per round, checked together (claim_first()). From the third round on the random part grows by one character.
	 *
	 * @throws \RuntimeException When no free code was found.
	 */
	public static function unique( array $opts, int $rounds = 4 ): string {
		for ( $round = 0; $round < $rounds; $round++ ) {
			$candidates = array();
			for ( $i = 0; $i < self::CANDIDATES; $i++ ) {
				$candidates[] = CodeGenerator::build( $opts );
			}
			$code = self::claim_first( array_unique( $candidates ) );
			if ( null !== $code ) {
				return $code;
			}
			if ( $round >= 1 ) {
				$opts['length'] = (int) ( $opts['length'] ?? 12 ) + 1;
			}
		}
		throw new \RuntimeException( 'Unable to generate a unique voucher code.' );
	}

	/**
	 * $count new codes for a bulk run, checked in batches (claim_free()) instead of one lookup per code.
	 *
	 * @return string[] fewer than $count only when no free codes were found after several rounds
	 */
	public static function unique_many( array $opts, int $count, int $rounds = 5 ): array {
		$codes = array();
		$want  = $count;
		for ( $round = 0; $round < $rounds && $want > 0; $round++ ) {
			$candidates = array();
			$make       = $want + (int) ceil( $want / 10 ); // A few more than needed: some may be taken.
			for ( $i = 0; $i < $make; $i++ ) {
				$candidates[] = CodeGenerator::build( $opts );
			}
			$free  = array_slice( array_values( self::claim_free( array_values( array_unique( $candidates ) ) ) ), 0, $want );
			$codes = array_merge( $codes, $free );
			$want -= count( $free );
			if ( $round >= 1 ) {
				$opts['length'] = (int) ( $opts['length'] ?? 12 ) + 1;
			}
		}
		return $codes;
	}
}
