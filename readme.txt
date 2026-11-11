=== Voucher Captain ===
Tags: woocommerce, gift card, voucher, pdf, coupon
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell personalised PDF vouchers, gift cards, stored-value vouchers and discount codes with WooCommerce. Full event ticketing is handled by the separate Ticket Captain plugin.

== Description ==

Visual PDF designer, recipient fields and custom field groups, scheduled delivery, balance-tracked gift cards, QR voucher and service-pass scanner, coupon code import (CSV/XLS/XLSX/ODS), WPML, multi-currency and Subscriptions support.

== Changelog ==

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
* Fix: paid orders whose voucher balance could not be deducted are put on hold for review.
* Fix: holds of trashed, deleted or abandoned unpaid orders are released.
* Fix: a due scheduled gift becomes redeemable even when no email can be sent.
* Fix: foreign-currency discounts are converted before they are deducted from a balance.
* Change: random code part defaults to 12 characters.

= 1.0.0 =
* Initial release.
