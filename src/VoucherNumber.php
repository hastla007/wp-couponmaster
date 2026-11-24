<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-13: sequential voucher number – a readable number in addition to the random code (e.g. "GS-2026-00042"),
 * for bookkeeping and the till. Off by default (decision E9 a, recommendation: free core, off from the factory).
 *
 * Settings (WooCommerce → Settings → Vouchers → Codes → "Voucher numbers"): on/off, pattern with {year}, {number} and
 * {prefix}, prefix, digits of {number}, restart every year or never. Filter "vcap_voucher_number_enabled" wins over
 * the setting; "vcap_voucher_number" may change the finished number.
 *
 * Assignment: per site (the counter is an option of the site), without gaps and never twice. The counter is one
 * options row per period ("vcap_voucher_number_seq" or "vcap_voucher_number_seq_{year}"), incremented with a single
 * UPDATE … SET option_value = LAST_INSERT_ID(option_value + 1) and read back with SELECT LAST_INSERT_ID() on the same
 * connection (same technique as CodePool::claim()): InnoDB locks the row, so concurrent orders wait for each other
 * and never read the same value. The increment and the write of the number onto the voucher run in one transaction
 * (Db::begin()); when the write fails the increment is rolled back, so no number is lost. A number is given only to
 * a voucher that is kept (after its row and its WooCommerce coupon exist), never to one that is undone afterwards.
 *
 * Storage: column vcap_vouchers.voucher_number (schema 1.9.0). Voucher::to_row() never writes it, so saving a
 * voucher can never overwrite or clear its number; only assign() writes it, once.
 */
final class VoucherNumber {
	public const OPTION_ENABLED = 'vcap_voucher_number_enabled';
	public const OPTION_PATTERN = 'vcap_voucher_number_pattern';
	public const OPTION_PREFIX  = 'vcap_voucher_number_prefix';
	public const OPTION_DIGITS  = 'vcap_voucher_number_digits';
	public const OPTION_RESET   = 'vcap_voucher_number_reset';
	/** Counter option (restart "never"); restart "yearly" uses SEQUENCE_OPTION . '_' . year. Internal state. */
	public const SEQUENCE_OPTION = 'vcap_voucher_number_seq';
	/** Pattern of a shop that never saved one. */
	public const DEFAULT_PATTERN = '{year}-{number}';
	/** Length of the stored number (column voucher_number varchar(64)). */
	public const MAX_LENGTH = 64;
	/** Longest pattern and prefix accepted by the settings. */
	public const PATTERN_LENGTH = 40;
	public const PREFIX_LENGTH  = 20;

	/** Whether new vouchers get a number: the setting, then the filter (which wins). */
	public static function enabled(): bool {
		$on = 'yes' === Settings::value( self::OPTION_ENABLED );
		/**
		 * Whether new vouchers get a sequential voucher number (SET-VC-13). Args: bool from the setting "Voucher
		 * numbers". The filter wins over the setting.
		 *
		 * @since 1.3.0
		 */
		return (bool) apply_filters( 'vcap_voucher_number_enabled', $on );
	}

	/**
	 * The format settings (stored values, invalid ones replaced by their defaults).
	 *
	 * @return array{pattern:string, prefix:string, digits:int, reset:string}
	 */
	public static function config(): array {
		$pattern = trim( (string) Settings::value( self::OPTION_PATTERN ) );
		$prefix  = trim( (string) Settings::value( self::OPTION_PREFIX ) );
		$reset   = (string) Settings::value( self::OPTION_RESET );
		return array(
			'pattern' => self::valid_pattern( $pattern ) ? $pattern : self::DEFAULT_PATTERN,
			'prefix'  => self::valid_prefix( $prefix ) ? $prefix : '',
			'digits'  => max( 1, min( 12, (int) Settings::value( self::OPTION_DIGITS ) ) ),
			'reset'   => 'never' === $reset ? 'never' : 'yearly',
		);
	}

	/** A pattern: letters, digits, ". _ - / #" and the tokens {year}, {number}, {prefix}; {number} is required. */
	public static function valid_pattern( string $pattern ): bool {
		if ( '' === $pattern || strlen( $pattern ) > self::PATTERN_LENGTH || ! str_contains( $pattern, '{number}' ) ) {
			return false;
		}
		$rest = str_replace( array( '{year}', '{number}', '{prefix}' ), '', $pattern );
		return 1 === preg_match( '/^[A-Za-z0-9._\-\/#]*$/', $rest );
	}

	/** A prefix: letters, digits, ". _ - / #", up to 20 characters ('' = none). */
	public static function valid_prefix( string $prefix ): bool {
		return strlen( $prefix ) <= self::PREFIX_LENGTH && 1 === preg_match( '/^[A-Za-z0-9._\-\/#]*$/', $prefix );
	}

	/**
	 * The number for counter value $sequence in $year. With a yearly restart the year is put in front when the
	 * pattern has no {year} (the numbers of two years would be the same otherwise); the prefix goes in front when
	 * the pattern has no {prefix}.
	 *
	 * @param array{pattern:string, prefix:string, digits:int, reset:string} $config config().
	 */
	public static function format( int $sequence, int $year, array $config ): string {
		$pattern = self::valid_pattern( (string) $config['pattern'] ) ? (string) $config['pattern'] : self::DEFAULT_PATTERN;
		if ( 'never' !== $config['reset'] && ! str_contains( $pattern, '{year}' ) ) {
			$pattern = '{year}-' . $pattern;
		}
		if ( ! str_contains( $pattern, '{prefix}' ) ) {
			$pattern = '{prefix}' . $pattern;
		}
		$digits = max( 1, min( 12, (int) $config['digits'] ) );
		$text   = strtr(
			$pattern,
			array(
				'{year}'   => sprintf( '%04d', $year ),
				'{number}' => str_pad( (string) max( 0, $sequence ), $digits, '0', STR_PAD_LEFT ),
				'{prefix}' => (string) $config['prefix'],
			)
		);
		return substr( $text, 0, self::MAX_LENGTH );
	}

	/** Option holding the counter of the period: one per year with a yearly restart, one for good otherwise. */
	public static function sequence_option( int $year, string $reset ): string {
		return 'never' === $reset ? self::SEQUENCE_OPTION : self::SEQUENCE_OPTION . '_' . $year;
	}

	/** Year of a UTC time ("Y-m-d H:i:s", null = now) in the site's time zone. */
	public static function year_of( ?string $utc ): int {
		$ts = null !== $utc && '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
		return (int) wp_date( 'Y', false !== $ts ? $ts : time() );
	}

	/**
	 * Gives a kept voucher its number (once; nothing when the feature is off, the voucher has one already or the
	 * column does not exist yet). Returns the number, '' when none was given.
	 */
	public static function assign( Voucher $voucher ): string {
		if ( '' !== $voucher->voucher_number ) {
			return $voucher->voucher_number;
		}
		if ( $voucher->id <= 0 || 'credit' === $voucher->type || ! self::enabled() || ! Install::voucher_number_ready() ) {
			return '';
		}
		global $wpdb;
		$config = self::config();
		$year   = self::year_of( $voucher->created_at ?: null );
		$option = self::sequence_option( $year, $config['reset'] );
		self::ensure_counter( $option );
		if ( ! Db::begin() ) {
			Db::fail( 'voucher_number_begin', array( 'voucher_id' => $voucher->id ) );
			return '';
		}
		$sequence = self::next( $option );
		$number   = '';
		if ( $sequence > 0 ) {
			$number = self::format( $sequence, $year, $config );
			/**
			 * The sequential number of a voucher before it is stored (SET-VC-13). Args: string number, int counter
			 * value, int year, Voucher. Keep it unique (max. 64 characters); '' gives the voucher no number.
			 *
			 * @since 1.3.0
			 */
			$number = substr( trim( (string) apply_filters( 'vcap_voucher_number', $number, $sequence, $year, $voucher ) ), 0, self::MAX_LENGTH );
		}
		$written = '' !== $number && 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET voucher_number = %s WHERE id = %d AND voucher_number = %s', Install::tables()['vouchers'], $number, $voucher->id, '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $written && Db::commit() ) {
			wp_cache_delete( $option, 'options' );
			$voucher->voucher_number = $number;
			VoucherRepository::forget();
			return $number;
		}
		// Not written (filter gave '', database error, numbered meanwhile): the counter goes back, no gap.
		Db::rollback_if_error( 'voucher_number', array( 'voucher_id' => $voucher->id ) );
		return '';
	}

	/** Creates the counter row of a period once (value 0, not autoloaded); an existing row is left alone. */
	private static function ensure_counter( string $option ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'off')", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Increments the counter of a period atomically and returns the new value (0 on failure). One statement: the
	 * row lock serialises concurrent requests, LAST_INSERT_ID() returns the value this connection wrote.
	 */
	private static function next( string $option ): int {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $updated ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The number the next voucher would get now (preview on the settings page; read past the object cache). */
	public static function preview(): string {
		global $wpdb;
		$config  = self::config();
		$year    = self::year_of( null );
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::sequence_option( $year, $config['reset'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::format( $current + 1, $year, $config );
	}

	/** Settings page: pattern and prefix are kept only when valid (else the stored value stays and an error shows). */
	public static function register_sanitizers(): void {
		foreach ( array( self::OPTION_PATTERN, self::OPTION_PREFIX ) as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( self::class, 'sanitize_setting' ), 10, 2 );
		}
	}

	/**
	 * @param mixed $value
	 * @param array $option
	 * @return string
	 */
	public static function sanitize_setting( $value, $option = array() ) {
		$id    = (string) ( $option['id'] ?? '' );
		$value = trim( sanitize_text_field( (string) $value ) );
		$ok    = self::OPTION_PATTERN === $id ? self::valid_pattern( $value ) : self::valid_prefix( $value );
		if ( $ok ) {
			return $value;
		}
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error( self::invalid_message( $id, $value ) );
		}
		return (string) Settings::value( $id );
	}

	/** Why a pattern or prefix was not saved. */
	public static function invalid_message( string $id, string $value ): string {
		if ( self::OPTION_PATTERN === $id ) {
			/* translators: %s: value entered */
			return sprintf( __( 'Voucher number pattern "%s" was not saved: it must contain {number} and may contain {year} and {prefix}, letters, digits and . _ - / # (up to 40 characters).', 'voucher-captain' ), $value );
		}
		/* translators: %s: value entered */
		return sprintf( __( 'Voucher number prefix "%s" was not saved: use only letters, digits and . _ - / # (up to 20 characters).', 'voucher-captain' ), $value );
	}
}
