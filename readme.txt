=== Voucher Captain ===
Tags: woocommerce, gift card, voucher, pdf, coupon
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell personalised PDF vouchers, gift cards, stored-value vouchers and discount codes with WooCommerce. Full event ticketing is handled by the separate Ticket Captain plugin.

== Description ==

Visual PDF designer, recipient fields and custom field groups, scheduled delivery, configurable voucher expiry reminders, balance-tracked gift cards, QR voucher and service-pass scanner, coupon code import (CSV/XLS/XLSX/ODS), WPML, multi-currency and Subscriptions support.

== Changelog ==

= Unreleased =
* New: configure multiple voucher expiry reminder intervals, e.g. 30, 7 and 1 days before expiry.
* New: ticket vouchers for WP Pass Captain – a voucher product names a Pass Captain ticket type; the voucher is exchanged for the tickets on the redeem page, by an admin or by code (reserve → issue tickets → commit, idempotent, tickets revoked when the voucher is voided).
* New: ticket vouchers for any date of a Pass Captain event – the customer chooses the date on the redeem page (admins on the voucher page); optionally limited to one ticket name. Sold-out dates are not offered; required attendee details are asked on the redeem page.
* Dev: hooks vcap_product_settings_fields/_save, vcap_line_snapshot, vcap_redeem_code, vcap_voucher_admin_actions/_action/_messages and vcap_event_labels for connectors.
* New: WP Affiliate Captain adapter – assign a partner to a code campaign (Vouchers → Generate codes); every code of the campaign is credited to that partner. Purchased vouchers and gift cards are never assigned.
* New: hooks vcap_campaigns_table_header and vcap_campaigns_table_cell for extra columns in the campaign table.
* New: WP Pass Captain integration – vouchers and gift cards pay for tickets in Pass Captain's checkout (reserve before payment, commit after payment, release on cancellation, proportional give-back on ticket refunds).
* New: redemptions->refund() gives back (part of) a completed external redemption.
* Fix: Pass Captain reservations are held for Pass Captain's whole payment window (late bank transfers no longer find the voucher released).
* Fix: stacked promotions never discount a line by more than its price; recalculating an order keeps the promotions it was placed with.
* Fix: "individual use" coupons no longer stack with promotions; gift card lines in the cart no longer count towards a promotion's minimum cart value.
* Fix: store credit can no longer be redeemed through the redemption API or Pass Captain (it is used in the shop only).
* Fix: Pass Captain amounts use ISO 4217 minor units, independent of the shop's price decimals setting.
* Fix: retrying a refund with a known refund key returns the stored result instead of a conflict.
* Fix: reactivating a voided single-use voucher that was already redeemed keeps it redeemed; voiding/reactivating no longer overwrites a balance changed at the same time.
* Fix: redemption channels of plugins that are inactive are kept on products and vouchers (never widened to "all channels").
* Fix: personal (email-restricted) vouchers can be used in guest checkouts of connectors that pass the customer email.
* Fix: guest orders with an account's email count against per-customer promotion limits.
* Fix: bonus vouchers are judged on the price paid after discount codes; a cancelled and later refunded order no longer gives back another order's use.
* Fix: refer a friend – own codes are refused for email aliases (+tag, Gmail dots); the reward is withdrawn when the friend's order is cancelled or refunded (unused coupon trashed, store credit debited).
* Fix: parallel requests for the same order status change count uses, bonus vouchers and referral rewards only once.
* Fix: Reports no longer count trashed bonus vouchers as issued.

= 1.1.0 =
* New: redeem vouchers and gift cards outside the shop (courses, tickets, memberships) through the PHP service vcap()->redemptions or the REST API /wp-json/vcap/v1/redemptions – reserve, commit, release, with idempotency keys, stable error codes and automatic release of expired reservations.
* New: settings section "Integrations (API)" (reservation lifetime, invalid-code limit).
* New: gift card balances are reserved before payment and committed after payment (transactional ledger).
* New: automatic promotions (Vouchers → Promotions) – percentage, fixed amount, quantity tiers and "buy X, get Y" with customer groups, first/returning order, minimum cart value, period, priority and stacking, plus a test cart before publishing. Off by default.
* New: promotion usage limits (total and per customer).
* New: "Bonus voucher after the order" promotions – a single-use coupon by email after a qualifying paid order or gift card purchase, withdrawn when the order is cancelled or refunded.
* New: SKU and "exclude SKU" rules for any coupon (with prefix wildcard).
* New: Vouchers → Reports – uses, orders, discount and revenue per code campaign/partner, promotion, bonus voucher and coupon, with CSV export.
* New: refer a friend – personal first-order codes in My Account, reward for the referrer as coupon or store credit once the friend's order is completed.
* New: shortcodes and blocks – [vcap_voucher_status], [vcap_redeem] (redeem page), [vcap_my_vouchers] (with "send again") and [vcap_referral].
* New: discounts are always applied before gift cards and store credit; settings "Store credit with discount codes" and "Order of gift cards and store credit".
* New: redemption channels per product ("Redeemable via": shop / external), custom voucher types (vcap_voucher_types), per-line voucher snapshot (value, currencies, tax data) and order_lines() for connector plugins.
* New: voucher fields in block-based add-to-cart requests (Store API "vcap_fields"), validated like the product form.
* Fix: a voucher voided while an external redemption is reserved can no longer be committed (vcap_cancelled).
* Fix: promotion editor selects have accessible labels.
* Dev: CONNECTORS.md, ADAPTERS.md and an example connector plugin; browser tests (Playwright, axe) in CI.
* New: redemption conditions per country (billing country decides).
* Change: requires WooCommerce 8.9 or newer (checked on load); tested with WordPress 7.1.
* Fix: fatal error together with WooCommerce 8.x when emails were styled after a PDF was rendered – the bundled CSS parser now lives in its own namespace (VCAP\Vendor).
* Fix: paid orders whose voucher balance could not be deducted are put on hold for review.
* Fix: holds of trashed, deleted or abandoned unpaid orders are released.
* Fix: a due scheduled gift becomes redeemable even when no email can be sent.
* Fix: foreign-currency discounts are converted before they are deducted from a balance.
* Change: random code part defaults to 12 characters.

= 1.0.0 =
* Initial release.
