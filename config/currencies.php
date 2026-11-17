<?php
/**
 * ISO 4217 minor units that differ from 2 (data only). Read by RedemptionService::decimals(); filter
 * "vcap_currency_decimals" for single currencies.
 */

defined( 'ABSPATH' ) || exit;

return array(
	0 => array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ),
	3 => array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ),
);
