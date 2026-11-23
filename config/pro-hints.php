<?php
/**
 * Hint lines for functions of the separate add-on Voucher Captain Pro (owner's decision S1 of 23.11.2026,
 * docs/FREE-PREMIUM.md rule 3). Text only: each line names a function the add-on really has, in one sentence. No
 * option, no form value, no network request, no code of the function – the add-on registers its own field with
 * 'replaces' => <id> and the line disappears. Only functions that are on sale are listed (SETTINGS-voucher 3.3).
 *
 * Keys: id (stable, a–z0–9_), section (settings section: '' General, redemption, promotions, codes, delivery,
 * privacy, api), title, text (translated), link (optional, https).
 *
 * @package VCAP
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'      => 'white_label',
		'section' => '',
		'title'   => __( 'Your own brand', 'voucher-captain' ),
		'text'    => __( 'Your own name, logo and menu title instead of “Voucher Captain”. Available with Voucher Captain Pro.', 'voucher-captain' ),
	),
	array(
		'id'      => 'sms_delivery',
		'section' => 'delivery',
		'title'   => __( 'Text messages', 'voucher-captain' ),
		'text'    => __( 'Send the voucher link by text message (seven, Twilio, MessageBird), with your own text, sender and consent. Available with Voucher Captain Pro.', 'voucher-captain' ),
	),
	array(
		'id'      => 'wallet_passes',
		'section' => 'delivery',
		'title'   => __( 'Apple Wallet and Google Wallet', 'voucher-captain' ),
		'text'    => __( 'Customers add vouchers to Apple Wallet or Google Wallet, with your colours, logo and barcode. Available with Voucher Captain Pro.', 'voucher-captain' ),
	),
	array(
		'id'      => 'bulk_orders',
		'section' => 'privacy',
		'title'   => __( 'Company orders', 'voucher-captain' ),
		'text'    => __( 'Company orders: upload a recipient list and issue many vouchers at once. Available with Voucher Captain Pro.', 'voucher-captain' ),
	),
);
