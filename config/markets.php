<?php
/**
 * Market data of the target markets – the EU-27 plus Switzerland, Liechtenstein, the United Kingdom and Norway (data
 * only – no logic). Read by VCAP\Markets; extend or change it with the filter "vcap_markets" or, for the voucher
 * validity, in the settings ("Statutory validity per country").
 *
 * Per country (ISO 3166-1 alpha-2):
 * - currency:       ISO 4217 code used in that country,
 * - locale:         main WordPress locale for customer texts,
 * - languages:      optional, all WordPress locales customers of that country may use (default: the main locale),
 * - eu:             EU member state (true) or not (false) – "known country" and "EU member" are separate,
 * - voucher_expiry: statutory default validity of vouchers, verified for that country:
 *                   array{years:int, end_of_year:bool, source:string} – years 0 = never expires;
 *                   null = not verified yet: the shop's default rule applies and Site Health points it out.
 *                   CH, LI, GB, NO: null = not legally checked yet (owner rule S8; legal check per country before
 *                   sales start).
 * - fiscal_receipt: the till receipt documents the sale (DE KassenSichV, AT RKSV) – the automatic setting
 *                   "Receipt no. required" asks for the receipt number at the counter. true only for DE and AT
 *                   (the behaviour before 1.3.0); false for every other country = not legally checked, not
 *                   "no duty". Filter "vcap_counter_require_receipt" wins.
 *
 * VAT rates are never stored here: they come from WooCommerce (WooCommerce → Settings → Tax), see TAX.md.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'AT' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => true, 'locale' => 'de_AT', 'voucher_expiry' => array( 'years' => 30, 'end_of_year' => false, 'source' => 'OGH-Rechtsprechung (30 Jahre)' ) ),
	'BE' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'nl_BE', 'voucher_expiry' => null ),
	'BG' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'bg_BG', 'voucher_expiry' => null ),
	'CY' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'el', 'voucher_expiry' => null ),
	'CZ' => array( 'currency' => 'CZK', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'cs_CZ', 'voucher_expiry' => null ),
	'DE' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => true, 'locale' => 'de_DE', 'voucher_expiry' => array( 'years' => 3, 'end_of_year' => true, 'source' => '§§ 195, 199 BGB (3 Jahre zum Jahresende)' ) ),
	'DK' => array( 'currency' => 'DKK', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'da_DK', 'voucher_expiry' => null ),
	'EE' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'et', 'voucher_expiry' => null ),
	'ES' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'es_ES', 'voucher_expiry' => null ),
	'FI' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'fi', 'voucher_expiry' => null ),
	'FR' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'fr_FR', 'voucher_expiry' => null ),
	'GR' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'el', 'voucher_expiry' => null ),
	'HR' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'hr', 'voucher_expiry' => null ),
	'HU' => array( 'currency' => 'HUF', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'hu_HU', 'voucher_expiry' => null ),
	'IE' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'en_GB', 'voucher_expiry' => null ),
	'IT' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'it_IT', 'voucher_expiry' => null ),
	'LT' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'lt_LT', 'voucher_expiry' => null ),
	'LU' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'fr_FR', 'voucher_expiry' => null ),
	'LV' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'lv', 'voucher_expiry' => null ),
	'MT' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'en_GB', 'voucher_expiry' => null ),
	'NL' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'nl_NL', 'voucher_expiry' => null ),
	'PL' => array( 'currency' => 'PLN', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'pl_PL', 'voucher_expiry' => null ),
	'PT' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'pt_PT', 'voucher_expiry' => null ),
	'RO' => array( 'currency' => 'RON', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'ro_RO', 'voucher_expiry' => null ),
	'SE' => array( 'currency' => 'SEK', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'sv_SE', 'voucher_expiry' => null ),
	'SI' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'sl_SI', 'voucher_expiry' => null ),
	'SK' => array( 'currency' => 'EUR', 'eu' => true, 'fiscal_receipt' => false, 'locale' => 'sk_SK', 'voucher_expiry' => null ),

	// Not in the EU (statutory validity and receipt duty not legally checked – owner rule S8).
	'CH' => array( 'currency' => 'CHF', 'eu' => false, 'fiscal_receipt' => false, 'locale' => 'de_CH', 'languages' => array( 'de_CH', 'fr_CH', 'it_CH' ), 'voucher_expiry' => null ),
	'GB' => array( 'currency' => 'GBP', 'eu' => false, 'fiscal_receipt' => false, 'locale' => 'en_GB', 'voucher_expiry' => null ),
	'LI' => array( 'currency' => 'CHF', 'eu' => false, 'fiscal_receipt' => false, 'locale' => 'de_CH', 'voucher_expiry' => null ),
	'NO' => array( 'currency' => 'NOK', 'eu' => false, 'fiscal_receipt' => false, 'locale' => 'nb_NO', 'voucher_expiry' => null ),
);
