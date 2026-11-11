<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Cryptographically random, collision-checked coupon codes.
 */
final class CodeGenerator {
	public const CHARSETS = array(
		'alnum_upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', // No 0/O/1/I.
		'alpha_upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
		'numeric'     => '0123456789',
		'alnum'       => 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
	);

	public static function random( int $length, string $charset = 'alnum_upper' ): string {
		$chars = self::CHARSETS[ $charset ] ?? self::CHARSETS['alnum_upper'];
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * @param array{prefix?:string,suffix?:string,length?:int,charset?:string,separator?:string,order_ref?:string} $opts
	 */
	public static function build( array $opts ): string {
		$sep   = (string) ( $opts['separator'] ?? '-' );
		$parts = array_filter(
			array(
				trim( (string) ( $opts['prefix'] ?? '' ) ),
				trim( (string) ( $opts['order_ref'] ?? '' ) ),
				self::random( max( 4, min( 40, (int) ( $opts['length'] ?? 10 ) ) ), (string) ( $opts['charset'] ?? 'alnum_upper' ) ),
				trim( (string) ( $opts['suffix'] ?? '' ) ),
			),
			'strlen'
		);
		return implode( $sep, $parts );
	}

	/**
	 * Generate a code that is unused by vouchers and WooCommerce coupons.
	 *
	 * @param callable(string):bool $exists
	 */
	public static function unique( array $opts, callable $exists, int $attempts = 20 ): string {
		for ( $i = 0; $i < $attempts; $i++ ) {
			$code = self::build( $opts );
			if ( ! $exists( $code ) ) {
				return $code;
			}
			$opts['length'] = (int) ( $opts['length'] ?? 10 ) + ( $i > 5 ? 1 : 0 );
		}
		throw new \RuntimeException( 'Unable to generate a unique voucher code.' );
	}
}
