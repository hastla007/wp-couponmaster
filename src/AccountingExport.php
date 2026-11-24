<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-20: the country-neutral part of the accounting export – settings block "Accounting export" (section
 * "Integrations"): ledger accounts (liability, clearing, income from forfeited value), optional account columns in
 * the redemption journal CSV, the start of the financial year, the format of every CSV file (separator, decimal
 * separator, character set, date format) and whether the DATEV block is shown.
 *
 * The three accounts keep their option names of 1.2.0 (vcap_datev_*_account): they are the one source for the
 * journal columns and the DATEV booking batch; so does the financial year start (vcap_datev_fy_start), now a field
 * of the general block that DATEV reads. Defaults reproduce the output of 1.2.0 exactly: no account columns, separator and decimal separator
 * by the administrator's language, UTF-8 with BOM, dates as Y-m-d.
 *
 * Accounts and columns are a suggestion of the plugin, not checked by a tax adviser (TAX.md 6.4a, "zu prüfen").
 *
 * @since 1.3.0
 */
final class AccountingExport {
	/** Name of 1.2.0 kept (the field moved from the DATEV block to the general one). */
	public const OPTION_FY_START     = DatevExport::OPTION_FY_START;
	public const OPTION_COLUMNS      = 'vcap_journal_account_columns';
	public const OPTION_SHOW_DATEV   = 'vcap_show_datev';
	public const OPTION_SEPARATOR    = 'vcap_csv_separator';
	public const OPTION_DECIMAL      = 'vcap_csv_decimal';
	public const OPTION_CHARSET      = 'vcap_csv_charset';
	public const OPTION_DATE_FORMAT  = 'vcap_csv_date_format';
	public const DEFAULT_FY_START    = '0101';

	/** Journal bookings whose counter account is the income account (value no longer owed). */
	public const INCOME_EVENTS = array( 'expired', TaxAccounting::EXPIRY_REVERSED_EVENT, TaxAccounting::FORFEITED_EVENT, CouponManager::WRITTEN_OFF_EVENT );

	/** Date formats offered for CSV files (PHP date format of the date part; the time is always " H:i"). */
	public const DATE_FORMATS = array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y' );

	/** Whether $mmdd is a valid month and day (MMDD, 29 February allowed). */
	public static function valid_fy_start( string $mmdd ): bool {
		if ( ! preg_match( '/^(\d{2})(\d{2})$/', $mmdd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[1], (int) $m[2], 2024 );
	}

	/**
	 * Start of the financial year (MMDD) for every export – the option of 1.2.0, through the filter
	 * "vcap_setting_vcap_datev_fy_start" (Settings::value()); anything that is no valid MMDD counts as 0101.
	 */
	public static function fy_start(): string {
		$value = preg_replace( '/\D/', '', (string) Settings::value( self::OPTION_FY_START ) ) ?? '';
		return self::valid_fy_start( $value ) ? $value : self::DEFAULT_FY_START;
	}

	/**
	 * The three ledger accounts (digits only, '' = not set).
	 *
	 * @return array{liability:string, clearing:string, income:string}
	 */
	public static function accounts(): array {
		$digits = static fn( string $option ): string => preg_replace( '/\D/', '', (string) get_option( $option, '' ) ) ?? '';
		return array(
			'liability' => $digits( DatevExport::OPTION_LIABILITY ),
			'clearing'  => $digits( DatevExport::OPTION_CLEARING ),
			'income'    => $digits( DatevExport::OPTION_INCOME ),
		);
	}

	/**
	 * Debit and credit account of one journal booking, null when the row is no booking (promotion, amount 0). Value
	 * added to a voucher: the liability grows (credit); value taken off: the liability shrinks (debit). The counter
	 * account is the income account for value no longer owed, else the clearing account.
	 *
	 * @param array<string, mixed>                                   $r        Journal row.
	 * @param array{liability:string, clearing:string, income:string} $accounts Accounts.
	 * @return array{0:string, 1:string}|null
	 */
	public static function booking_accounts( array $r, array $accounts ): ?array {
		$amount = Ledger::signed_amount( (string) $r['event'], $r['amount'] );
		if ( abs( $amount ) < 0.000001 || 'discount' === Ledger::row_treatment( $r ) ) {
			return null;
		}
		$counter = in_array( (string) $r['event'], self::INCOME_EVENTS, true ) ? $accounts['income'] : $accounts['clearing'];
		return $amount > 0 ? array( $counter, $accounts['liability'] ) : array( $accounts['liability'], $counter );
	}

	/**
	 * Accounts for the journal CSV columns, null when the columns are off (default: the CSV keeps its columns).
	 *
	 * @return array{liability:string, clearing:string, income:string}|null
	 */
	public static function journal_accounts(): ?array {
		return 'yes' === Settings::value( self::OPTION_COLUMNS ) ? self::accounts() : null;
	}

	/**
	 * Column names added to the journal CSV.
	 *
	 * @return string[]
	 */
	public static function journal_columns(): array {
		return array( __( 'Debit account', 'voucher-captain' ), __( 'Credit account', 'voucher-captain' ) );
	}

	/**
	 * Cells of the account columns of one journal row ('' for rows without a booking).
	 *
	 * @param array<string, mixed>                                   $r        Journal row.
	 * @param array{liability:string, clearing:string, income:string} $accounts Accounts.
	 * @return array{0:string, 1:string}
	 */
	public static function journal_cells( array $r, array $accounts ): array {
		return self::booking_accounts( $r, $accounts ) ?? array( '', '' );
	}

	/**
	 * Whether the DATEV block is shown: "yes"/"no" as chosen; "auto" (default) for shops with the country profile DE
	 * or based in Germany and for shops that saved DATEV numbers or accounts. The filter
	 * "vcap_setting_vcap_show_datev" (Settings::value()) wins. A hidden block keeps its values.
	 */
	public static function show_datev(): bool {
		$value = (string) Settings::value( self::OPTION_SHOW_DATEV );
		return 'yes' === ( in_array( $value, array( 'yes', 'no' ), true ) ? $value : self::show_datev_auto() );
	}

	/** What "auto" means for this shop ('yes'/'no'). */
	public static function show_datev_auto(): string {
		$applied = CountryProfiles::applied();
		$country = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );
		if ( ( null !== $applied && 'DE' === strtoupper( $applied['id'] ) ) || 'DE' === $country ) {
			return 'yes';
		}
		foreach ( array( DatevExport::OPTION_CONSULTANT, DatevExport::OPTION_CLIENT, DatevExport::OPTION_LIABILITY, DatevExport::OPTION_CLEARING, DatevExport::OPTION_INCOME ) as $option ) {
			if ( '' !== trim( (string) get_option( $option, '' ) ) ) {
				return 'yes';
			}
		}
		return 'no';
	}

	/** Settings fields (section "Integrations", before the DATEV block). */
	public static function settings_fields(): array {
		$account = array( 'pattern' => '[0-9]{1,9}', 'inputmode' => 'numeric' );
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Accounting export', 'voucher-captain' ),
				'id'    => 'vcap_accounting',
				'desc'  => __( 'Applies to the redemption journal (Vouchers → Reports → Redemption journal → Export CSV) and every other CSV export. Accounts and columns are a suggestion: agree them with your tax adviser for your country.', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				DatevExport::OPTION_LIABILITY,
				array(
					'title'             => __( 'Voucher liability account', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Account for the value of sold vouchers still owed to customers (e.g. "other liabilities").', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				DatevExport::OPTION_CLEARING,
				array(
					'title'             => __( 'Clearing account', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Counter account of voucher sales and redemptions (e.g. a clearing account for shop payments).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				DatevExport::OPTION_INCOME,
				array(
					'title'             => __( 'Income account for forfeited value', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => $account,
					'desc_tip'          => __( 'Counter account of value that is no longer owed: expired vouchers, forfeited remaining value, rounding rests.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_COLUMNS,
				array(
					'title' => __( 'Accounts in the journal CSV', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Add the columns "Debit account" and "Credit account" to the journal CSV (from the three accounts above; empty for promotions). Off: the CSV keeps its columns.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_FY_START,
				array(
					'title'             => __( 'Start of the financial year (MMDD)', 'voucher-captain' ),
					'type'              => 'text',
					'custom_attributes' => array( 'pattern' => '[0-9]{4}', 'inputmode' => 'numeric' ),
					'desc'              => __( 'Month and day the financial year starts, e.g. 0101 for 1 January or 0701 for 1 July. The DATEV export assigns each booking to its financial year; agree it with your tax adviser.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_SEPARATOR,
				array(
					'title'   => __( 'CSV field separator', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						''    => __( 'Automatic (by your language)', 'voucher-captain' ),
						';'   => __( 'Semicolon (;)', 'voucher-captain' ),
						','   => __( 'Comma (,)', 'voucher-captain' ),
						'tab' => __( 'Tab', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Automatic: a semicolon for languages with a decimal comma (e.g. German), else a comma – what spreadsheets there open in columns.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_DECIMAL,
				array(
					'title'   => __( 'CSV decimal separator', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						''  => __( 'Automatic (by your language)', 'voucher-captain' ),
						',' => __( 'Comma (12,50)', 'voucher-captain' ),
						'.' => __( 'Point (12.50)', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Decimal separator of amounts in CSV files. Automatic follows the language of the user who exports (de_CH: point).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_CHARSET,
				array(
					'title'   => __( 'CSV character set', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'utf-8-bom'    => __( 'UTF-8 with BOM (Excel)', 'voucher-captain' ),
						'utf-8'        => __( 'UTF-8 without BOM', 'voucher-captain' ),
						'windows-1252' => __( 'Windows-1252 (ANSI)', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Character set of CSV files. Windows-1252 suits older accounting software; characters it cannot show become "?".', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_DATE_FORMAT,
				array(
					'title'    => __( 'CSV date format', 'voucher-captain' ),
					'type'     => 'select',
					'options'  => array(
						'Y-m-d' => '2026-12-31',
						'd.m.Y' => '31.12.2026',
						'd/m/Y' => '31/12/2026',
						'm/d/Y' => '12/31/2026',
					),
					'desc_tip' => __( 'Format of dates in CSV files (local shop time; the time of day follows as hours:minutes).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				self::OPTION_SHOW_DATEV,
				array(
					'title'    => __( 'Show DATEV settings', 'voucher-captain' ),
					'type'     => 'select',
					'options'  => array(
						'auto' => 'yes' === self::show_datev_auto() ? __( 'Automatic (currently shown)', 'voucher-captain' ) : __( 'Automatic (currently hidden)', 'voucher-captain' ),
						'yes'  => __( 'Show', 'voucher-captain' ),
						'no'   => __( 'Hide', 'voucher-captain' ),
					),
					'desc_tip' => __( 'The block "Accounting export (DATEV)" is a German format for tax advisers. Automatic: shown for the country profile Germany, shops based in Germany and shops that saved DATEV values. Hidden values are kept.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_accounting' ),
		);
	}
}
