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
* Fix: paid orders whose voucher balance could not be deducted are put on hold for review.
* Fix: holds of trashed, deleted or abandoned unpaid orders are released.
* Fix: a due scheduled gift becomes redeemable even when no email can be sent.
* Fix: foreign-currency discounts are converted before they are deducted from a balance.
* Change: random code part defaults to 12 characters.

= 1.0.0 =
* Initial release.
