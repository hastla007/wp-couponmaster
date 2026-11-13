<?php
/**
 * Premium features of Voucher Captain (data only) – entitlement key "voucher.{key}" on WP License Captain.
 * Gated only where the license is required (premium edition, constant VCAP_LICENSE_REQUIRED); free builds keep
 * every feature. Proposal of the feature split – to be confirmed by the product owner (TODO.md, License Captain).
 */

defined( 'ABSPATH' ) || exit;

return array(
	'promotions'  => 'Promotions (rule-based discounts and bonus vouchers)',
	'referrals'   => 'Refer a friend',
	'topup'       => 'Gift card top-up',
	'reports'     => 'Reports, liability and booking journal',
	'passcaptain' => 'WP Pass Captain integration (vouchers in the ticket checkout, ticket vouchers)',
);
