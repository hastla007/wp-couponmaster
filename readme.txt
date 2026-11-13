=== Voucher Captain ===
Contributors: hastla007
Tags: woocommerce, gift card, voucher, pdf, store credit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.9
WC tested up to: 11.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell personalised PDF vouchers, gift cards and store credit with WooCommerce: balance tracking, scheduled delivery, scanner and code import.

== Description ==

Visual PDF designer, recipient fields and custom field groups, scheduled delivery, balance-tracked gift cards and store credit, QR voucher and service-pass scanner, coupon code import (CSV/XLS/XLSX/ODS), outstanding value and redemption journal, WPML, multi-currency and Subscriptions support. Premium edition: promotions and bonus vouchers, refer a friend, gift card top-up and campaign reports (see Editions). Built for shops in Germany and Austria: statutory validity per buyer country, VAT treatment per voucher kind (single-purpose / multi-purpose) and German translations.

Event tickets, seats and admission belong to the separate plugin WP Pass Captain. When both are active, vouchers and gift cards pay for tickets in its checkout, ticket vouchers are exchanged for tickets and the scanner checks in both.

= Editions =

The free edition and the premium edition are built from the same code.

* Both editions: PDF vouchers and the PDF designer, recipient fields, scheduled delivery, gift cards with balance, store credit, scanner, code import and code campaigns, redemption API, VAT treatment, outstanding voucher value and redemption journal (with CSV), payment with vouchers and gift cards in the WP Pass Captain checkout, WPML, multi-currency and Subscriptions support.
* Premium only (active license through WP License Captain): promotions and bonus vouchers, refer a friend, gift card top-up, campaign and partner reports, ticket vouchers and the shared scanner for WP Pass Captain.

Without an active license (free edition, or premium with an expired license) the premium features are switched off for new configuration and new sales. What was sold earlier keeps being processed: gift cards, vouchers and store credit stay redeemable, refunds and cancellations reverse top-ups, referral rewards and bonus vouchers, and the outstanding value and journal stay available for your accounts.

Coming from 1.1.0 (where everything was included) and installing the free edition: running promotions are no longer applied to carts, referral codes customers already shared are refused ("Referral codes are currently not available."; rewards for orders that already used one are still granted or withdrawn), top-up products can no longer be bought and the campaign report is locked. Install the premium edition and activate a license to keep them.

== Installation ==

1. Install and activate WooCommerce 8.9 or newer.
2. Upload the release ZIP under Plugins → Add New → Upload Plugin and activate Voucher Captain. PHP 8.1 or newer with the GD, mbstring and zip extensions is required.
3. Premium edition: open the Voucher Captain license page, enter the license key and activate this site.
4. Check WooCommerce → Settings → Vouchers (issuing status, validity, delivery, store credit, VAT treatment).
5. Edit a product, tick "Voucher" and configure the "Voucher" tab. Two PDF templates (gift card A5, voucher/service pass A6) are created on activation; edit them in the Vouchers menu.
6. Optional: create pages with the shortcodes [vcap_redeem] (redeem page), [vcap_balance] (balance check) and [vcap_my_vouchers].

== Frequently Asked Questions ==

= Are vouchers WooCommerce coupons? =

Yes. Every voucher is a WooCommerce coupon with its own record (balance, history, recipient, PDF). Gift cards and store credit keep their remaining balance; single-use vouchers are spent at once.

= How is VAT handled? =

Each voucher product has a VAT treatment (automatic, multi-purpose, single-purpose, promotion). By default vouchers reduce the price before tax like a discount. With the setting "Multi-purpose vouchers as payment" (off by default) multi-purpose vouchers pay the order total after tax instead. Have your tax adviser confirm the treatment; TAX.md in the source repository describes every case.

= How long are vouchers valid? =

A product can set days, a fixed date or no expiry. Without its own expiry a voucher gets the statutory default of the buyer's country (Germany: 3 years to the end of the year, Austria: 30 years; filter vcap_statutory_expiry).

= What happens on refunds and cancellations? =

Cancelled, failed or fully refunded orders give the used voucher amount back. Refunds of voucher purchases void the refunded vouchers; value already spent cannot be paid out again. Refunds as store credit are possible only with the customer's recorded consent.

= Does it work with the block checkout and HPOS? =

Yes, with High-Performance Order Storage and the legacy order storage, and with the cart and checkout blocks. With the optional setting "Multi-purpose vouchers as payment" the blocks show the payment row "Voucher CODE" below the discounts; the coupon itself shows a discount of 0 there with a note that it pays below. The warning that a single-use voucher worth more than the cart loses its remaining value is shown in the blocks as well.

= Which data does the plugin share? =

See "External services" below. Customer data stays on your site; the suggested privacy policy text is added under Settings → Privacy.

== Screenshots ==

1. PDF designer with drag and drop, layers, QR code, barcode and placeholders.
2. Voucher tab of a product: value, validity, delivery, recipient fields and VAT treatment.
3. Customer view: product page with recipient fields and preview, "Vouchers" in My Account.
4. Vouchers list and detail screen with balance, history and resend.
5. Scanner for vouchers, service passes and WP Pass Captain tickets.
6. Reports: outstanding voucher value at a date and the redemption journal.

== External services ==

The free edition connects to no external service. The plugin bundles all its libraries and fonts; PDFs never load remote images.

Premium edition only – license server (WP License Captain, run by the plugin vendor, address configured on the license page): to activate the license, keep the premium features unlocked and offer updates, the site sends its address (home URL), the WordPress, PHP and plugin versions, the license key, the chosen environment (production/staging/development) and a random installation ID. This happens when the license is activated or deactivated, about twice a day in the background, when the license page is opened and when WordPress checks for updates; update packages are downloaded from the license server's release storage and checked with SHA-256. No customer, order or voucher data is sent. The license terms and privacy policy of the vendor apply.

On the same site (no external transfer): with WP Pass Captain active, ticket exchanges and ticket payments pass the voucher code, amount, the customer's name and email address and attendee names to WP Pass Captain.

== Changelog ==

= 1.2.0 =
* New: premium edition with licensing through WP License Captain – promotions and bonus vouchers, refer a friend, gift card top-up, campaign and partner reports and the WP Pass Captain ticket vouchers need an active license there and are switched off without one (fail-closed; a signed 14-day offline cache bridges server outages). License page to activate or deactivate the site. The outstanding voucher value and the redemption journal stay available in every edition.
* New: the free edition carries an "Update URI" header, so WordPress never offers a WordPress.org plugin of the same name as its update.
* New: premium updates come from the license server's release storage; every package is checked against its SHA-256 before it is installed.
* New: the premium edition trusts the license server's current and next signing key, so the server can change its key without locking shops out of license checks and updates; every release checks the pinned key against the live license server.
* New: setting "Multi-purpose vouchers as payment" (off by default) – multi-purpose vouchers, gift cards and store credit pay the order total after tax, shipping and fees as an untaxed payment line "Voucher CODE" instead of a discount before tax.
* New: gift card top-up – a product marked "Top up" adds the paid value to an existing card; refunds take the top-up back as far as the balance allows.
* New: Reports – outstanding voucher value at a date (with CSV), redemption journal and, with WP Pass Captain, a reconciliation of voucher payments.
* New: statutory validity follows the buyer's billing country; voucher products whose validity is shorter than the statutory period get a warning (product screen and Site Health).
* New: VAT check – voucher products whose tax status does not fit the voucher kind are flagged.
* New: refunds as store credit require and record the customer's consent.
* New: Site Health checks (scheduled tasks, failed mails, private folder, redeem page) and a short log of failed mails with masked addresses.
* New: multisite – network activation, sites added later and per-site uninstall.
* New: fixed-date ticket vouchers are sold only while the date has room and expire when the event starts.
* New: "Not delivered" filter and notices for vouchers that have not reached anyone; customers are told when a ticket voucher is valid again.
* New: German formal ("Sie"), Swiss and Austrian German sites use the German translation when they have no file of their own.
* New: the release ZIP ships the licence files of all bundled libraries and THIRD-PARTY-NOTICES.txt.
* Fix: gift card and store credit coupons are suspended while the plugin is deactivated, so they cannot take their full balance off orders.
* Fix (security): rate limits are atomic, count failures only and ignore forged forwarding headers; XLSX/ODS imports refuse zip bombs.
* Fix: recipient emails choose their default subject per voucher.
* Privacy: referral data is included in the personal data export and erasure; the suggested privacy policy text covers the balance history, referrals, external redemptions, WP Pass Captain, the scanner, rate-limit counters and the license check.
* New: VAT treatment per voucher product (automatic, multi-purpose, single-purpose, promotion). Pass Captain keeps the VAT for multi-purpose vouchers and shows them as a payment on its invoices.
* New: default validity by the shop country when a product has no expiry of its own – Germany 3 years to the end of the year, Austria 30 years (filter vcap_statutory_expiry). Shops that saved a default in days keep it.
* New: ticket vouchers are given back automatically when Pass Captain cancels or refunds the exchanged tickets (date or event cancelled); the voucher can then be exchanged again.
* New: partial refunds of voucher purchases void as many vouchers as were refunded (unused ones first); refunds that would pay back value already spent from a voucher are refused with the maximum amount (filter vcap_allow_refund_of_spent_vouchers).
* New: scanner privacy – the holder name is hidden unless enabled (personal tickets always show it); order number and original value only for staff who manage vouchers.
* Fix: in shops with net prices a gift card paid the net amount plus tax while only the net amount was deducted; it now pays exactly its balance of the gross price (filter vcap_balance_covers_tax).
* Fix (security): keys of built-in integrations can no longer be created, read or released over REST; Pass Captain reservations are owned by no customer.
* Fix: scanner – ticket vouchers cannot be redeemed at the counter, single-use redemption is atomic, vouchers held by an unpaid order are refused, undo asks first, check-ins are idempotent, Pass Captain results show readable reasons and the date of other-day tickets.
* Fix: ticket exchange – one key per attempt, so a voucher can be exchanged again after a released attempt; guests confirm their email; the date can be changed or the exchange cancelled.
* Fix: voucher currency is the shop base currency; "days" expiry ends at the end of the day in the shop's time zone; stale reservation release can no longer starve; double use of single-use vouchers is logged.
* New: shared scanner – Vouchers → Scanner also recognises WP Pass Captain tickets and checks them in at Pass Captain (Pass Captain permission required); voucher codes are looked up first.
* New: integration status for WP Pass Captain in the settings (Integrations), menu link to Pass Captain, hint on the voucher type "Ticket" while Pass Captain is active.
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

== Upgrade Notice ==

= 1.2.0 =
Promotions, refer a friend (shared codes are refused), top-ups, campaign reports and ticket vouchers are now Premium (Vouchers → License); the free edition switches them off. Sold vouchers, outstanding value and journal keep working. Database update 1.4.0 – back up first.

= 1.1.0 =
Requires WooCommerce 8.9 or newer.
