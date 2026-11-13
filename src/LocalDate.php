<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Dates entered in the shop's local time without a time zone: "2026-12-24" (date field) or "2026-12-24T19:00"
 * (datetime-local field, e.g. the event date of a ticket). They name a wall-clock time in the shop's time zone, so
 * they must not go through strtotime() (which reads them as UTC) before wp_date() shifts them into the shop's time
 * zone a second time – 19:00 in Berlin would print as 20:00 (summer 21:00), a date west of UTC as the day before.
 */
final class LocalDate {
	private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/';

	/**
	 * The local date (time) as a point in time, null when the value is not such a date or not a valid one.
	 *
	 * @param \DateTimeZone|null $tz Time zone the value was entered in; the shop's time zone by default.
	 */
	public static function parse( string $value, ?\DateTimeZone $tz = null ): ?\DateTimeImmutable {
		$value = trim( $value );
		if ( ! preg_match( self::PATTERN, $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$tz = $tz ?? wp_timezone();
		if ( ! isset( $m[4] ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
			return $date ?: null;
		}
		$hour   = (int) $m[4];
		$minute = (int) $m[5];
		$second = isset( $m[6] ) ? (int) $m[6] : 0;
		if ( $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), $tz );
		return $date ? $date->setTime( $hour, $minute, $second ) : null;
	}

	/** Unix timestamp of the local date (time), null when it is not a valid one. */
	public static function timestamp( string $value, ?\DateTimeZone $tz = null ): ?int {
		$date = self::parse( $value, $tz );
		return $date ? $date->getTimestamp() : null;
	}

	/** Whether the value carries a time of day ("2026-12-24T19:00") and not only a date. */
	public static function has_time( string $value ): bool {
		return (bool) preg_match( self::PATTERN, trim( $value ), $m ) && isset( $m[4] );
	}

	/**
	 * The local date formatted for display (translated month and day names via wp_date()); the time format is
	 * appended only when the value has a time of day. '' when the value is not a valid local date.
	 */
	public static function format( string $value, string $date_format, string $time_format = '', ?\DateTimeZone $tz = null ): string {
		$tz   = $tz ?? wp_timezone();
		$date = self::parse( $value, $tz );
		if ( ! $date ) {
			return '';
		}
		$format = $date_format . ( '' !== $time_format && self::has_time( $value ) ? ' ' . $time_format : '' );
		return (string) wp_date( $format, $date->getTimestamp(), $tz );
	}
}
