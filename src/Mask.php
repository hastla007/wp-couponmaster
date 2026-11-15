<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * D14: the one place that masks voucher codes and email addresses for order notes, history entries, notices, logs
 * and Site Health (a code is a means of payment, an address is personal data). Downloads::masked_code() and
 * Compat\PassCaptainTickets::mask_email() stay as public aliases.
 */
final class Mask {
	/** Last four characters of a code in capitals, e.g. "7KQ2" (for texts that put the "…" themselves). */
	public static function code_tail( string $code ): string {
		// Q10: cut whole characters – a byte cut would split multibyte codes (Ä, €) into invalid UTF-8.
		return mb_substr( mb_strtoupper( $code, 'UTF-8' ), -4, null, 'UTF-8' );
	}

	/** Last four characters of a code, e.g. "…7KQ2". */
	public static function code( string $code ): string {
		return '…' . self::code_tail( $code );
	}

	/** First character of the local part and the domain, e.g. "j***@example.com"; no address: unchanged. */
	public static function email( string $email ): string {
		if ( ! str_contains( $email, '@' ) ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		return mb_substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/** Every email address in a text masked (mail errors, logs). */
	public static function addresses( string $text ): string {
		return (string) preg_replace_callback( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static fn( $m ) => self::email( $m[0] ), $text );
	}
}
