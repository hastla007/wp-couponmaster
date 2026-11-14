<?php
/**
 * Premium features of Voucher Captain (data only): keys of the entitlements "voucher.{key}" on WP License Captain.
 * The labels are translated at runtime (VCAP\Licensing\Entitlements::label()), not here.
 *
 * - Free edition (VCAP_EDITION 'free'): these features are permanently off for new configuration, new sales and
 *   customer screens; what was sold earlier keeps being processed (refunds, reversals, exchanges, cleanup).
 * - Premium edition (VCAP_EDITION 'premium', VCAP_LICENSE_REQUIRED): available with an active license that grants
 *   the right; fail-closed otherwise.
 * - Development checkouts (no edition constant): everything on.
 */

defined( 'ABSPATH' ) || exit;

// 'reports' locks the campaign and partner evaluation only: the outstanding value and the redemption journal (with
// their CSV) are the accounting record of sold vouchers and stay available in every edition.
return array( 'promotions', 'referrals', 'topup', 'reports', 'passcaptain' );
