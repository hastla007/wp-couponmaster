<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-69: the redemption journal as a DATEV booking batch (EXTF "Buchungsstapel", format category 21) for the tax
 * adviser – optional, configured under WooCommerce → Settings → Vouchers → Integrations (accounts), downloaded in
 * Reports → Redemption journal.
 *
 * Every journal booking of a value voucher becomes one booking between the voucher liability account and the
 * clearing account (sales, money in transit); value lost at the expiry or forfeited by a single-use voucher is
 * booked against the income account. Bookings of promotions (VAT treatment "discount": no consideration, no
 * liability) are left out. No VAT key (BU-Schlüssel) is set: the voucher bookings themselves carry no VAT, the VAT
 * of sales and redemptions is in the shop's revenue bookings (TAX.md "Kontierung"). Have the import tested by the
 * tax adviser before relying on it.
 */
final class DatevExport {
	public const OPTION_CONSULTANT = 'vcap_datev_consultant';
	public const OPTION_CLIENT     = 'vcap_datev_client';
	public const OPTION_FY_START   = 'vcap_datev_fy_start';
	public const OPTION_LENGTH     = 'vcap_datev_account_length';
	public const OPTION_LIABILITY  = 'vcap_datev_liability_account';
	public const OPTION_CLEARING   = 'vcap_datev_clearing_account';
	public const OPTION_INCOME     = 'vcap_datev_income_account';

	/** Journal bookings whose counter account is the income account (value no longer owed). */
	private const INCOME_EVENTS = array( 'expired', TaxAccounting::EXPIRY_REVERSED_EVENT, TaxAccounting::FORFEITED_EVENT, CouponManager::WRITTEN_OFF_EVENT );

	/** Column names of the booking lines (the first 14 columns of the DATEV format). */
	public const COLUMNS = array( 'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext' );

	/** Settings fields (section "Integrations"). */
	public static function settings_fields(): array {
		$account = array( 'pattern' => '[0-9]{1,9}', 'inputmode' => 'numeric' );
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Accounting export (DATEV)', 'voucher-captain' ),
				'id'    => 'vcap_datev',
				'desc'  => __( 'Optional: download the redemption journal as a DATEV booking batch (EXTF) under Vouchers → Reports → Redemption journal. Agree the accounts with your tax adviser and have a test import checked before using it.', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				self::OPTION_CONSULTANT,
				array(
					'title'             => __( 'Consultant number', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => array( 'pattern' => '[0-9]{4,7}', 'inputmode' => 'numeric' ),
					'desc_tip'          => __( 'DATEV consultant number of your tax adviser (4 to 7 digits). Written into the header of the booking batch.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_CLIENT,
				array(
					'title'             => __( 'Client number', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => array( 'pattern' => '[0-9]{1,5}', 'inputmode' => 'numeric' ),
					'desc_tip'          => __( 'Your DATEV client number at the tax adviser (1 to 5 digits).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_FY_START,
				array(
					'title'             => __( 'Start of the financial year (MMDD)', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => array( 'pattern' => '[0-9]{4}', 'inputmode' => 'numeric' ),
					'desc'              => __( 'Month and day the financial year starts, e.g. 0101 for 1 January or 0701 for 1 July. The export assigns each booking to its financial year; agree it with your tax adviser.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_LENGTH,
				array(
					'title'             => __( 'Length of ledger accounts', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Number of digits of your general ledger accounts in DATEV (4 to 9, default 4, e.g. SKR03/SKR04).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_LIABILITY,
				array(
					'title'             => __( 'Voucher liability account', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Account for the value of sold vouchers still owed to customers (e.g. "other liabilities").', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_CLEARING,
				array(
					'title'             => __( 'Clearing account', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Counter account of voucher sales and redemptions (e.g. a clearing account for shop payments).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_INCOME,
				array(
					'title'             => __( 'Income account for forfeited value', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Counter account of value that is no longer owed: expired vouchers, forfeited remaining value, rounding rests.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_datev' ),
		);
	}

	/**
	 * Settings of the export.
	 *
	 * @return array{consultant:string, client:string, fy_start:string, length:int, liability:string, clearing:string, income:string}
	 */
	public static function config(): array {
		$digits = static fn( string $option ): string => preg_replace( '/\D/', '', (string) get_option( $option, '' ) ) ?? '';
		$fy     = $digits( self::OPTION_FY_START );
		return array(
			'consultant' => $digits( self::OPTION_CONSULTANT ),
			'client'     => $digits( self::OPTION_CLIENT ),
			'fy_start'   => 4 === strlen( $fy ) ? $fy : (string) SettingsRegistry::default( self::OPTION_FY_START ),
			'length'     => max( 4, min( 9, (int) get_option( self::OPTION_LENGTH, SettingsRegistry::default( self::OPTION_LENGTH ) ) ) ),
			'liability'  => $digits( self::OPTION_LIABILITY ),
			'clearing'   => $digits( self::OPTION_CLEARING ),
			'income'     => $digits( self::OPTION_INCOME ),
		);
	}

	/** Whether the accounts and numbers needed for an export are set. */
	public static function configured(): bool {
		$c = self::config();
		return '' !== $c['consultant'] && '' !== $c['client'] && '' !== $c['liability'] && '' !== $c['clearing'] && '' !== $c['income'];
	}

	/** Start of the financial year that contains $date (Y-m-d) as YYYYMMDD. */
	public static function financial_year_start( string $date, string $fy_start ): string {
		$year  = (int) substr( $date, 0, 4 );
		$start = sprintf( '%04d%s', $year, $fy_start );
		return str_replace( '-', '', $date ) < $start ? sprintf( '%04d%s', $year - 1, $fy_start ) : $start;
	}

	/**
	 * The booking line of one journal row, null when the row is no booking for DATEV (promotion, amount 0).
	 *
	 * @return array<int, string>|null
	 */
	public static function booking( array $r, array $config ): ?array {
		$amount = Ledger::signed_amount( (string) $r['event'], $r['amount'] );
		if ( abs( $amount ) < 0.000001 || 'discount' === Ledger::row_treatment( $r ) ) {
			return null;
		}
		$counter  = in_array( (string) $r['event'], self::INCOME_EVENTS, true ) ? $config['income'] : $config['clearing'];
		$currency = '' !== (string) ( $r['currency'] ?? '' ) ? (string) $r['currency'] : (string) get_option( 'woocommerce_currency' );
		// Value added to a voucher: the liability grows (credit). Value taken off: the liability shrinks (debit).
		list( $debit, $credit ) = $amount > 0 ? array( $counter, $config['liability'] ) : array( $config['liability'], $counter );
		$labels  = VoucherRepository::event_labels();
		$ts      = strtotime( (string) $r['created_at'] . ' UTC' );
		$doc     = (int) ( $r['order_id'] ?? 0 ) > 0 ? Format::order_number( $r['order_id'] ) : (string) $r['code'];
		$text    = ( $labels[ (string) $r['event'] ] ?? (string) $r['event'] ) . ' ' . Mask::code( (string) $r['code'] );
		$decimal = API\V1\RedemptionService::decimals( $currency );
		return array(
			number_format( abs( $amount ), $decimal, ',', '' ),
			'S',
			$currency,
			'',
			'',
			'',
			$debit,
			$credit,
			'',
			false === $ts ? '' : wp_date( 'dm', $ts ),
			self::field( preg_replace( '/[^A-Za-z0-9$&%*+\-\/]/', '', $doc ) ?? '', 36 ),
			'',
			'',
			self::field( $text, 60 ),
		);
	}

	/** A text field cut to DATEV's length. */
	private static function field( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * The lines of the EXTF file (header, column names, bookings), unencoded.
	 *
	 * @return \Generator<int, array<int, string|int>>
	 */
	public static function lines( Ledger $ledger, string $from, string $to ): \Generator {
		$c = self::config();
		yield array( 'EXTF', 700, 21, 'Buchungsstapel', 12, gmdate( 'YmdHis' ) . '000', '', 'RE', '', '', $c['consultant'], $c['client'], self::financial_year_start( $from, $c['fy_start'] ), $c['length'], str_replace( '-', '', $from ), str_replace( '-', '', $to ), self::field( 'Voucher Captain ' . $from . ' - ' . $to, 30 ), '', 1, 0, 0, (string) get_option( 'woocommerce_currency' ), '', '', '', '', '', '', '', '', '' );
		yield self::COLUMNS;
		foreach ( $ledger->journal_all( $from, $to ) as $r ) {
			$line = self::booking( $r, $c );
			if ( null !== $line ) {
				yield $line;
			}
		}
	}

	/** One line in DATEV's CSV form: ";"-separated, texts in double quotes, numbers bare. */
	public static function encode( array $line ): string {
		$cells = array();
		foreach ( $line as $i => $cell ) {
			$numeric = is_int( $cell ) || ( 0 === $i && is_string( $cell ) && preg_match( '/^\d+(,\d+)?$/', $cell ) );
			$cells[] = $numeric ? (string) $cell : '"' . str_replace( '"', '""', (string) $cell ) . '"';
		}
		$text = implode( ';', $cells ) . "\r\n";
		// DATEV reads Windows-1252 (ANSI).
		return function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ) : $text;
	}

	/** Streams the file to the browser (Reports → Redemption journal). */
	public static function send( Ledger $ledger, string $from, string $to ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=windows-1252' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'EXTF_Buchungsstapel_Gutscheine_' . $from . '_' . $to . '.csv' ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		foreach ( self::lines( $ledger, $from, $to ) as $line ) {
			echo self::encode( $line ); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download, not HTML.
		}
	}
}
