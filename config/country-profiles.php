<?php
/**
 * Country profiles (SET-VC-02): suggested settings per country, applied only after a preview "current → new" that
 * the admin confirms (owner decision E4 = a). Data only – labels and hint texts are in VCAP\CountryProfiles. Read by
 * VCAP\CountryProfiles; extend or change it with the filter "vcap_country_profiles".
 *
 * Owner rule S8: profiles DE, AT and CH (legal and tax check per country before sales start) plus EU and OTHER with
 * neutral values. NONE of the values with legal weight is legally checked yet ("checked" => false): every one of them
 * is shown with the mark "legal check" (Rechtsprüfung) until the owner's check is recorded here.
 *
 * Per profile:
 * - country:    ISO code of the market (config/markets.php), '' for the neutral profiles EU and OTHER,
 * - checked:    the owner's legal/tax check of this profile's values is done (false for every profile today),
 * - settings:   option name => suggested value in the stored format (checked like an import before it is applied):
 *               vcap_default_expiry_rule      validity rule of products without their own expiry,
 *               vcap_counter_require_receipt  'market' = from the market flag "fiscal_receipt" (DE, AT: receipt
 *                                             number required; other countries: no suggestion),
 *               vcap_retention_days           anonymise recipient data after (days) – owner rule S9: new installations
 *                                             keep the data ("never"), the profile suggests a period,
 *               vcap_date_format              PHP date format of PDFs and emails,
 *               vcap_coupon_link_param        link parameter: suggestion only, never preselected (owner decision E10
 *                                             is open; links already sent use the current parameter),
 *               a value null or a missing key = no suggestion (the current value stays),
 * - expiry_row: statutory validity row of the country for "Statutory validity per country" (vcap_expiry_by_country,
 *               SET-VC-04): 'market' = from the market data (voucher_expiry), an array{years, end_of_year, note} or
 *               null (no row suggested); only this country's row is added or replaced. While the profile is not
 *               checked, the note gets the mark "Legal check pending",
 * - tax:        key of the hint on the default tax type (no setting is changed; see TAX.md),
 * - accounting: key of the hint on the accounting format (no setting is changed).
 */

defined( 'ABSPATH' ) || exit;

return array(
	'DE'    => array(
		'country'    => 'DE',
		'checked'    => false,
		'settings'   => array(
			'vcap_default_expiry_rule'     => 'statutory',
			'vcap_counter_require_receipt' => 'market',
			'vcap_retention_days'          => 1095,
			'vcap_date_format'             => 'd.m.Y',
			'vcap_coupon_link_param'       => 'gutschein',
		),
		'expiry_row' => 'market',
		'tax'        => 'de',
		'accounting' => 'datev',
	),
	'AT'    => array(
		'country'    => 'AT',
		'checked'    => false,
		'settings'   => array(
			'vcap_default_expiry_rule'     => 'statutory',
			'vcap_counter_require_receipt' => 'market',
			'vcap_retention_days'          => 1095,
			'vcap_date_format'             => 'd.m.Y',
			'vcap_coupon_link_param'       => 'gutschein',
		),
		'expiry_row' => 'market',
		'tax'        => 'at',
		'accounting' => 'at',
	),
	'CH'    => array(
		'country'    => 'CH',
		'checked'    => false,
		'settings'   => array(
			'vcap_default_expiry_rule'     => 'statutory',
			'vcap_counter_require_receipt' => 'market',
			'vcap_retention_days'          => 1095,
			'vcap_date_format'             => 'd.m.Y',
			'vcap_coupon_link_param'       => 'gutschein',
		),
		// Not in the market data (CH validity not legally checked, S8): the general limitation period of the Swiss
		// Code of Obligations as a suggestion, marked for the legal check.
		'expiry_row' => array(
			'years'       => 10,
			'end_of_year' => false,
			'note'        => 'Art. 127 OR (10 Jahre)',
		),
		'tax'        => 'ch',
		'accounting' => 'ch',
	),
	// Neutral profiles: no value with legal weight is suggested (validity, receipt duty and retention stay as they are).
	'EU'    => array(
		'country'    => '',
		'checked'    => false,
		'settings'   => array(
			'vcap_coupon_link_param' => 'voucher',
		),
		'expiry_row' => null,
		'tax'        => 'eu',
		'accounting' => 'csv',
	),
	'OTHER' => array(
		'country'    => '',
		'checked'    => false,
		'settings'   => array(
			'vcap_coupon_link_param' => 'voucher',
		),
		'expiry_row' => null,
		'tax'        => 'other',
		'accounting' => 'csv',
	),
);
