<?php
/**
 * Market data of the EU target market (data only – no logic). Read by VCAP\Markets; extend or change it with the
 * filter "vcap_markets" or, for the voucher validity, in the settings ("Statutory validity per country").
 *
 * Per country (ISO 3166-1 alpha-2):
 * - currency:       ISO 4217 code used in that country,
 * - locale:         main WordPress locale for customer texts,
 * - voucher_expiry: statutory default validity of vouchers, verified for that country:
 *                   array{years:int, end_of_year:bool, source:string} – years 0 = never expires;
 *                   null = not verified yet: the shop's default rule applies and Site Health points it out.
 *
 * VAT rates are never stored here: they come from WooCommerce (WooCommerce → Settings → Tax), see TAX.md.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'AT' => array( 'currency' => 'EUR', 'locale' => 'de_AT', 'voucher_expiry' => array( 'years' => 30, 'end_of_year' => false, 'source' => 'OGH-Rechtsprechung (30 Jahre)' ) ),
	'BE' => array( 'currency' => 'EUR', 'locale' => 'nl_BE', 'voucher_expiry' => null ),
	'BG' => array( 'currency' => 'EUR', 'locale' => 'bg_BG', 'voucher_expiry' => null ),
	'CY' => array( 'currency' => 'EUR', 'locale' => 'el', 'voucher_expiry' => null ),
	'CZ' => array( 'currency' => 'CZK', 'locale' => 'cs_CZ', 'voucher_expiry' => null ),
	'DE' => array( 'currency' => 'EUR', 'locale' => 'de_DE', 'voucher_expiry' => array( 'years' => 3, 'end_of_year' => true, 'source' => '§§ 195, 199 BGB (3 Jahre zum Jahresende)' ) ),
	'DK' => array( 'currency' => 'DKK', 'locale' => 'da_DK', 'voucher_expiry' => null ),
	'EE' => array( 'currency' => 'EUR', 'locale' => 'et', 'voucher_expiry' => null ),
	'ES' => array( 'currency' => 'EUR', 'locale' => 'es_ES', 'voucher_expiry' => null ),
	'FI' => array( 'currency' => 'EUR', 'locale' => 'fi', 'voucher_expiry' => null ),
	'FR' => array( 'currency' => 'EUR', 'locale' => 'fr_FR', 'voucher_expiry' => null ),
	'GR' => array( 'currency' => 'EUR', 'locale' => 'el', 'voucher_expiry' => null ),
	'HR' => array( 'currency' => 'EUR', 'locale' => 'hr', 'voucher_expiry' => null ),
	'HU' => array( 'currency' => 'HUF', 'locale' => 'hu_HU', 'voucher_expiry' => null ),
	'IE' => array( 'currency' => 'EUR', 'locale' => 'en_GB', 'voucher_expiry' => null ),
	'IT' => array( 'currency' => 'EUR', 'locale' => 'it_IT', 'voucher_expiry' => null ),
	'LT' => array( 'currency' => 'EUR', 'locale' => 'lt_LT', 'voucher_expiry' => null ),
	'LU' => array( 'currency' => 'EUR', 'locale' => 'fr_FR', 'voucher_expiry' => null ),
	'LV' => array( 'currency' => 'EUR', 'locale' => 'lv', 'voucher_expiry' => null ),
	'MT' => array( 'currency' => 'EUR', 'locale' => 'en_GB', 'voucher_expiry' => null ),
	'NL' => array( 'currency' => 'EUR', 'locale' => 'nl_NL', 'voucher_expiry' => null ),
	'PL' => array( 'currency' => 'PLN', 'locale' => 'pl_PL', 'voucher_expiry' => null ),
	'PT' => array( 'currency' => 'EUR', 'locale' => 'pt_PT', 'voucher_expiry' => null ),
	'RO' => array( 'currency' => 'RON', 'locale' => 'ro_RO', 'voucher_expiry' => null ),
	'SE' => array( 'currency' => 'SEK', 'locale' => 'sv_SE', 'voucher_expiry' => null ),
	'SI' => array( 'currency' => 'EUR', 'locale' => 'sl_SI', 'voucher_expiry' => null ),
	'SK' => array( 'currency' => 'EUR', 'locale' => 'sk_SK', 'voucher_expiry' => null ),
);
