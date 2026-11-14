<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Number formats shown to people.
 */
final class Format {
	/**
	 * A percentage with the shop's decimal separator and the language's spacing: "12.5%" in English, "12,5 %" in
	 * German (the translation of "%s%%" decides).
	 *
	 * @param int|float|string $value
	 */
	public static function percent( $value ): string {
		/* translators: %s: percentage number (e.g. 12.5); "%%" is the percent sign. Use "%s %%" for a space before it. */
		return sprintf( _x( '%s%%', 'percentage', 'voucher-captain' ), wc_format_localized_decimal( (string) (float) $value ) );
	}
}
