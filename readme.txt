=== Voucher Captain ===
Contributors: hastla007
Tags: woocommerce, gift card, voucher, pdf, store credit
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
WC requires at least: 11.2.2
WC tested up to: 11.3
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell personalised PDF vouchers, gift cards and store credit with WooCommerce: balance tracking, scheduled delivery, scanner and code import.

== Description ==

Visual PDF designer, recipient fields and custom field groups, scheduled delivery, balance-tracked gift cards and store credit, QR voucher and service-pass scanner, coupon code import (CSV/XLS/XLSX/ODS), outstanding value and redemption journal, WPML, multi-currency and Subscriptions support. Promotions and bonus vouchers, refer a friend, gift card top-up and campaign reports are included. Built for shops in Germany and Austria: statutory validity per buyer country, VAT treatment per voucher kind (single-purpose / multi-purpose) and German translations.

Event tickets, seats and admission belong to the separate plugin Pass Captain. When both are active, vouchers and gift cards pay for tickets in its checkout, ticket vouchers are exchanged for tickets and the scanner checks in both.

= Free, complete core =

Every feature is included and free (GPL-2.0-or-later): there is no license key, no locked feature and no upgrade notice. This includes promotions and bonus vouchers, refer a friend, gift card top-up, campaign and partner reports, and the ticket vouchers and shared scanner for Pass Captain. Any paid offer would be a separate add-on plugin.

== Installation ==

1. Install and activate WooCommerce 11.2.2 or newer.
2. Upload the release ZIP under Plugins → Add New → Upload Plugin and activate Voucher Captain. PHP 8.5 or newer with the mbstring and dom extensions is required (activation stops without them); GD (QR codes, barcodes and images in PDFs), zip (Excel import) and iconv are needed for those features. Tools → Site Health shows what is missing.
3. Check WooCommerce → Settings → Vouchers (issuing status, validity, delivery, store credit, VAT treatment).
4. Edit a product, tick "Voucher" and configure the "Voucher" tab. Two PDF templates (gift card A5, voucher/service pass A6) are created on activation; edit them in the Vouchers menu.
5. Optional: create pages with the shortcodes [vcap_redeem] (redeem page), [vcap_balance] (balance check) and [vcap_my_vouchers].

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
5. Scanner for vouchers, service passes and Pass Captain tickets.
6. Reports: outstanding voucher value at a date and the redemption journal.

== External services ==

Voucher Captain connects to no external service and needs no account, license key or add-on. It never contacts a license or update server; updates come from WordPress.org. The plugin bundles all its libraries and fonts, and QR codes, barcodes and PDFs are created on your server.

The plugin itself makes only these HTTP requests:

* PDF template images from your own media hosts – off by default. Only when a developer names hosts with the filter `vcap_pdf_remote_hosts` (for example the shop's own CDN or offloaded media library) are template images that are missing on the server downloaded from those hosts when a PDF is created. The request carries only the image URL; no customer data is sent.
* Site Health check of the private folder – a request from your site to its own uploads URL (Tools → Site Health) that tests whether voucher PDFs could be fetched from the web. It goes to your own site only.

On the same site (no external transfer): with Pass Captain active, ticket exchanges and ticket payments pass the voucher code, amount, the customer's name and email address and attendee names to Pass Captain. With Affiliate Captain active, the partner of a code campaign is passed to it for the commission of orders that use the code.

== Changelog ==

= 1.3.0 =
* New: accounting export without DATEV (Settings → Vouchers → Integrations → "Accounting export"): ledger accounts (liability, clearing, income from forfeited value) also as optional "Debit account"/"Credit account" columns of the redemption journal CSV (off by default), the start of the financial year as a general setting (DATEV reads it, option name unchanged), and field separator, decimal separator, character set and date format of every CSV export (defaults = previous output; filter vcap_csv_format still wins). The DATEV block is shown automatically for the country profile Germany, shops based in Germany and shops with DATEV values saved, or on request ("Show DATEV settings"). Accounts and columns: tax check pending (TAX.md 6.4a).
* Fixed: Settings → Vouchers: Enter in a field saves (instead of running the country profile preview or the import check), the country profile, import and reset buttons no longer ask "leave page?", a refused colour, logo address, CSS or number is reported and marked at its field, the command limit fields name their command, the import preview and change log show field names and Yes/No, no sideways scrolling on phones; the test email no longer goes to the email's Cc/Bcc; German (du) customer emails say "dein" instead of "Ihr".
* Dev: read functions vcap_read_voucher(), vcap_read_gift_card(), vcap_read_store_credit(), vcap_read_redemption(), vcap_read_redemptions() and vcap_read_order_vouchers() (service vcap()->read, contract 1.2.0) return the state of a voucher by its id – status, amounts in minor units, expiry, delivery, counts – never the code or personal data, and write nothing.
* New: voucher commands for automations (contract 1.3.0): vcap_command_issue(), vcap_command_void(), vcap_command_reactivate() and vcap_command_redeem() (service vcap()->commands) issue, void, reactivate and redeem vouchers by id with an idempotency key and the rights of the authorising user. Each is off until switched on under Settings → Vouchers → Integrations (API); fixed limits the shop can only lower: at most 100 EUR per voucher or redemption, 50 calls a day per command, 1 issued voucher per recipient and day. Every call is recorded without the code.
* Dev: VCAP_CONTRACT_VERSION 1.8.0 (1.2.0 with the read functions, 1.3.0 with the voucher commands, 1.4.0 with the connector access of vcap()->api_v1: voucher(), voucher_by_code(), log_event(), admin_url(), line_snapshot(), 1.5.0 with contact and consent of a voucher id, 1.6.0 with vcap_read_command_limits(), 1.7.0 with journal_id in vcap_store_credit_changed and vcap_gift_card_topped_up, 1.8.0 with the settings register and the extension filters of the settings and the Form Captain hooks); the plugin version rises with the contract.
* Dev: settings register entries that an add-on adds in the action vcap_loaded are now taken into account (the register is cached only from "init", and forgotten once after vcap_loaded); the deprecated constant Settings::DEFAULTS (values of 1.2.0) is back for add-ons that read it.
* Changed: "Default validity (days)" and the product field "Valid for (days)" accept at most 36500 days (100 years).
* Fix: Uninstalling with data removal also deletes the user setting "Hide hints on extensions"; the country selects of the country tables are built once per page.
* New: Contact read function for add-ons (off by default, Settings → Integrations (API)): an add-on can read the address of a voucher's recipient or buyer only for a purpose this person consented to; consents, withdrawals and every read are in the voucher history.
* Changed: Automatic voucher commands in shops in CHF, USD or GBP allow up to 100 in the shop currency without an own entry; other non-euro shops need an own maximum amount.
* Dev: vcap_store_credit_changed (7th argument) and vcap_gift_card_topped_up (5th argument) pass a context array with journal_id, the ledger id of the booking (contract 1.7.0).
* Dev: vcap_read_command_limits( $currency ) (contract 1.6.0) reads the limits of the voucher commands as they apply now – switch, fixed ceilings, effective limits for a currency, today's calls – for a display in a workflow editor; no codes or personal data.
* Changed: Plugin author is now "Captain Suite".
* Fixed: Settings reset restores the defaults an existing shop runs on (no longer the values of a new installation, e.g. multi-purpose vouchers as payment) and shows every change first (page and wp vcap settings reset --dry-run); export, import and backups keep the effective validity rule, old country table texts, empty numbers, HTML conditions, CSS and logo URLs; the change log is written once per request and records rights per role; wp vcap settings get/set handle tables as rows and JSON.
* Changed: German comes in two forms: de_DE addresses the reader with "du", the new de_DE_formal with "Sie"; de_AT stays "Sie". Swiss German uses the formal file (de_CH_informal the informal one).
* Changed: "Remove all data when the plugin is deleted" (was "Delete data on uninstall", still off by default) is switched on only with the typed word LÖSCHEN or DELETE, checked on the server (WP-CLI: wp vcap data-removal on --confirm=LÖSCHEN); switching it off needs no word. Scheduled jobs are removed on every uninstall, also when the data is kept.
* New: Settings → Vouchers → Integrations (API) shows the status of Workflow Captain for Voucher Captain when Workflow Captain is active (adapter, contract version, active workflows, events per state, last correlation ID, links); counts and links only.
* New: optional Form Captain adapter: form submissions become voucher requests (Vouchers → Voucher requests); nothing is issued before a manager approves the request. Stays inactive without Form Captain. Filters vcap_form_captain_available, vcap_form_captain_max_quantity, vcap_form_captain_max_value; actions vcap_form_captain_request_created, _approved, _rejected (ADAPTERS.md, section 7).
* Dev: vcap_vouchers_issued, vcap_voucher_issued_standalone, vcap_voucher_delivered, vcap_voucher_delivery_given_up, vcap_voucher_bounced and vcap_voucher_reissued now fire after the COMMIT when a transaction is open, like the other lifecycle actions; a throwing listener is logged instead of stopping the issue or delivery.
* New: customer self-service and deadlines are settings (were fixed): resend and change gifts in My Account, "Correct recipient" link lifetime and count, printed link after expiry, remaining validity for top-ups, undo own check-in, cancel own counter redemption. Defaults as before; the existing filters still win.
* New: limits against code guessing are settings (Redemption & balance → "Protection against code guessing", redemption API) with a floor no setting can go below, so the protection cannot be switched off; the confirmation email of personal vouchers names the set link lifetime.
* New: Settings → Vouchers → Delivery & downloads → "Email appearance": accent colour and logo of the voucher emails (empty = the WooCommerce email settings, so existing shops look the same; filter vcap_email_colors still wins) and a "Send test email to me" button that sends the gift recipient email with a sample voucher to your own address (at most 5 per hour and user).
* New: Settings → Vouchers → Delivery & downloads → "Additional CSS" for the redeem form, balance check, blocks and My Account, printed only on pages with Voucher Captain output; stored cleaned (no tags, @import, expression() or script addresses; at most 10,000 characters).
* New: Settings → Vouchers → General → "Payment methods for vouchers": "Do not sell vouchers with" hides chosen payment methods in the classic and the block checkout (and when paying for an existing order) only while a voucher product is in the cart (default: none, filter vcap_voucher_excluded_gateways); "Payment methods paid later" (default cash on delivery, bank transfer, cheque as before) decides whose vouchers wait for the confirmed payment (the filter vcap_offline_payment_methods still wins).
* New: Settings → Vouchers → Privacy & advanced → "Trusted proxy header" (none as before, Cloudflare CF-Connecting-IP, or the last X-Forwarded-For entry) for the rate limits behind a proxy or CDN; the filter vcap_client_ip still wins. Site Health also warns when the last five rate-limit hits all came from one address.
* New: Settings → Vouchers → Privacy & advanced → "Rights per role": a table assigns "Manage vouchers", "Scanner" and "Raise remaining values" to any WordPress role; only administrators can change it; the administrator and roles with "Manage WooCommerce" (shop manager) always keep all three. Upgrades keep a saved assignment; every uninstall removes the rights from all roles.
* Changed: every voucher setting has a help text; notes with legal weight (conditions, validity, anonymisation, receipt number, DATEV accounts) are shown below the field instead of only in a tooltip; a number outside its allowed range is saved at the nearest bound (or not saved) with a message naming the field and the range. The help of "One PDF up to (vouchers)" now names both values: 20 for new installations, 50 for older shops that never saved it (stored values unchanged).
* Changed: Settings → Vouchers → General → "Statutory validity per country" and "Redemption conditions per country" are tables instead of free text: one row per country with years, "to the end of the year" and a source/note, or with the conditions and their language ("All languages" or one of the shop's languages). Rows can be added and removed with the keyboard; an invalid row (unknown country, years not 0–999, a second row for the same country) is not saved and the message names the row. Values saved in the old text format keep working and become rows on the next save; lines that cannot be converted are listed above the table. The values are not legally checked; the filter vcap_statutory_expiry is unchanged.
* Dev: settings registry – config/settings.php and VCAP\SettingsRegistry hold default, type, bounds, section, legal weight, export and secret flag of every setting (filter vcap_settings_registry); number settings are also checked on the server when saved. Settings::DEFAULTS is replaced by Settings::defaults() (same values); saved settings are unchanged.
* New: Settings → Vouchers → Privacy & advanced: export the settings as a JSON file (never keys or other secrets) and import it with a full check and a preview of the changes; reset one section or all settings to their defaults after an automatic backup; change log of the settings (who changed what when, from which value to which, secrets masked; kept 365 days by default). WP-CLI: wp vcap settings list|get|set|export|import|reset.
* New: country data for Switzerland (CHF, de_CH/fr_CH/it_CH), Liechtenstein, the United Kingdom and Norway; "known country" and "EU member" are separate (Markets::known(), Markets::is_eu()). Site Health checks every selling country, not only the EU, and names selling countries without country data. The statutory validity of the new countries is not legally checked (the shop default applies until it is set).
* Changed: the automatic "Receipt no. required" comes from the country data (flag fiscal_receipt: Germany and Austria, as before); the filter vcap_counter_require_receipt still wins.
* New: Settings → Vouchers → Integrations (API) → "Integration rules": order statuses that activate physical gift cards (default Completed, paid statuses only), and – each shown only while the partner plugin is active – review request only for the first redemption (yes), commission on voucher sales with Affiliate Captain (yes), give the voucher back when Pass Captain revokes its tickets (yes), vouchers for subscription switches (no). Defaults as before; the filters of the same name win.
* Changed: Settings → Vouchers → "Counter & locations" is a section of its own: counter limits, "Receipt no. required" and the tills/branches moved there from Privacy & advanced (saved values are kept). Tills/branches are a table with name, key and active (inactive tills are not offered on the scanner); a list saved as text before is read and converted when the section is saved. Add-ons add columns with the filter vcap_counter_location_fields.
* New: Settings → Vouchers → Privacy & advanced → "Retention": one field per retention period – recipient data ("Anonymise recipient data after (days)", still 0 = keep), open and completed voucher requests (6 / 24 months), review invitation notes (24 months, withdrawn ones 90 days), voucher command protocol (90 days) and failed counter lookups (92 days); each with a minimum and the note that the period is not legally checked. Defaults are the previous values; the filters vcap_voucher_requests_retention_months, vcap_review_retention_months and vcap_review_revoked_retention_days get the settings and win, new filters vcap_command_log_days and vcap_scanner_failed_lookup_days. The daily maintenance (new step "protocols") and the suggested privacy policy text use the values; Site Health recommends a recipient data period while none is set.
* New: Settings → Vouchers → General → "Country profile" (also the first step of the setup checklist and `wp vcap settings profile list|show|apply`): profiles for Germany, Austria and Switzerland plus neutral EU and "Other countries" suggest the validity rule, the country's statutory validity row, the receipt number at the counter, a retention period for recipient data, the date format and the link parameter, with hints on tax type and accounting format. Every value is shown "current → suggested" first; only ticked values are applied (own values and the link parameter are not ticked), after a backup and with the source "country profile" in the change log. Values with legal weight are marked "Legal check pending" – the profiles are not legally checked yet. Filter vcap_country_profiles.
* New: the setup checklist asks for the country profile, the mandatory texts per country (conditions for every market country the shop sells to), the confirmed tax type and the retention of recipient data; each step shows whether it is done.
* Fix: settings test phase (countries, limits, tills) – the country profile no longer ticks the statutory validity rule for a shop with its own number of days; an invalid edited row of a country table keeps the saved row of that country; the own maximum amount of the voucher commands is stored with its currency and not used after a change of the shop currency or an import from another currency; command counts such as "5.0" are whole numbers and "abc"/"-1" keep the saved value with an error (never the ceiling); product page conditions are translated with Polylang like the voucher; the privacy text uses the filtered retention; CSS escapes no longer get past the "Additional CSS" filter; per-recipient command counters are deleted when the day is over; physical cards are activated at "Completed" also when the chosen activation statuses leave it out; with every till switched off the scanner books nothing instead of taking free text; a double click on "Apply" of a country profile no longer reports "expired".
* Change: requires PHP 8.5, WordPress 7.0 and WooCommerce 11.2.2 or newer (was PHP 8.1, WordPress 6.4, WooCommerce 8.9), the same for all Captain plugins. Supported database servers are MySQL 9.7 or MariaDB 12.3 (LTS) or newer; an older server keeps working and is only reported in Site Health.

= 1.2.0 =
* Change: requires PHP 8.5, WordPress 7.0 and WooCommerce 11.2.2 or newer (was PHP 8.1, WordPress 6.4, WooCommerce 8.9), the same for all Captain plugins. Supported database servers are MySQL 9.7 or MariaDB 12.3 (LTS) or newer; an older server keeps working and is only reported in Site Health.
* Change: new installations settle multi-purpose vouchers, gift cards and store credit as a payment after tax ("Multi-purpose vouchers as payment" on); existing shops keep their setting and see a Site Health note.
* New: admin – "New voucher" (issue without an order, with reason; also `wp vcap issue --amount --email`), "Import cards" (existing gift cards from CSV/XLS/XLSX/ODS with dry run, also `wp vcap import`), bulk actions "Extend validity", "Send expiry reminder" and "Export selection (CSV)", search by code end, code without separators or order number, "Show vouchers" on the user profile.
* New: storefront – live preview of the voucher while typing, design choice from several PDF templates, "As a gift / For me", a separate "Redeem a gift card" field in cart and checkout (classic and blocks), remaining balance in the mini cart, custom amounts through the Store API (blocks, headless).
* New: physical cards (code from a code batch, activated when the order is completed) and "Add to my store credit" for gift cards in My Account.
* New: reports – "Overview" tab (sold, issued without order, redeemed, outstanding, expired with value left, top products).
* New: delivery – "Correct the recipient" link for the buyer (also for guests, signed and time limited), bounce handling with the buyer told, optional buyer emails "Gift delivered" and "Gift used", delivery hour, several expiry reminders (e.g. 30, 7, 1 days), sender name field, attachments above 8 MB sent as links.
* New: white label extension points for add-ons – `vcap_brand_name`, `vcap_brand_logo`, `vcap_admin_menu_title`, `vcap_admin_menu_icon`.
* Fix: a gift card kept in another currency than the shop base currency is refused in the cart and over the API instead of being taken 1:1; with currency switchers the cart cap is compared in the coupon's own units.
* Fix: the customer's own reservation of an abandoned payment counts as available again; product and exclusion rules of a gift card limit only the lines it pays for instead of refusing the whole card.
* Fix: an abandoned payment page no longer blocks a "first order only" coupon of the same customer for up to an hour.
* Change (security): over the REST API a customer can no longer give back their own completed redemption (the service may already have been provided); voucher managers, "Voucher POS" accounts and connectors can. A connector may allow it again with the filter `vcap_customer_may_refund_redemption`.
* Fix (security): voucher settings of a product are also guarded on the WooCommerce REST API and the CSV import (voucher managers only).
* Fix: voucher requests – list with pages and a state filter, a lock per request against two managers acting at once, rejected entries stay answered as rejected, interrupted submits are repaired; review-source records and rejected claims are removed after their retention period.
* New: Captain Suite contracts (optional, for the separate plugins Review Captain and Form Captain). Product setting "Redemption confirms a service (for review invitations)" (default off): counter redemptions of such vouchers are reported to a review plugin with a stable reference and no personal data, and withdrawn when the redemption is cancelled, refunded or the voucher voided. Vouchers → Voucher requests: requests from forms (company orders, personalisation) are only stored – vouchers are issued when a manager confirms them. Voucher Captain sends no review invitations itself. Requests are part of the WordPress personal data export and erasure and of the suggested privacy policy text; at most 500 open requests (filter `vcap_voucher_requests_max_open`). Erasing or deleting a voucher withdraws its review references. Only the first confirmed redemption of a voucher is reported for a review (one service = one request; filter `vcap_review_first_redemption_only`). With an add-on that offers it, an open request with several recipients can be taken over as a company order. The daily maintenance deletes open and partly issued requests after 6 months and issued or taken-over ones after 24 months (filter `vcap_voucher_requests_retention_months`, 0 = keep).
* Fix (security): a customer-chosen voucher amount is worth at most what was paid for it per voucher, and only in the currency it was entered in (fixed when added to the cart); a difference is noted on the order. Same for gift card top-ups.
* Fix (security): REST redemptions – a missing, internal or another user's redemption gets the same 404 for customers; commit, release and refund check access and owner again under the row lock.
* Fix (security): referral rewards are "Individual use only"; "first order only" coupons are held at checkout per customer (also against a parallel order with a plus address); Pass Captain continues an open ticket reservation only for the same caller.
* Fix (security): voucher settings of a product, PDF templates and field groups need the voucher managers' right (not only product rights); with Affiliate Captain, codes under a campaign assigned to a partner need the right to manage affiliates, and the assignment ends with the campaign's last code.
* Fix (security): no customer preview for password-protected or hidden products; scanner links only with http(s); the PDF renderer reads local files only from the plugin, uploads and custom font folders.
* Fix (security): personal vouchers (only buyer/recipient) are redeemed online only by a customer logged in with the matching account email – a guest typing the buyer's address at checkout (also in the cart/checkout blocks, connectors and the Pass Captain checkout) is asked to log in with the masked address. Staff at the counter and in wp-admin are not affected; transferable vouchers neither.
* New: "Reissue code" on the voucher page (and as a bulk action): new code and token, remaining value carried over, the old code and its download links stop working, optionally the new code is mailed. Email download links now expire after 90 days by default (a lifetime saved before is kept).
* New: role "Voucher cashier" (scanner only, no access to orders or customers); WooCommerce no longer sends scanner staff away from wp-admin. Raising a remaining value needs its own right "vcap_increase_balance" (administrators and shop managers); "Add" above the face value asks first. "Refund as store credit" needs WooCommerce's right to edit orders.
* New: cancel a counter redemption (wrong card, wrong amount) – in the scanner right after booking (the cashier within 15 minutes) or in the voucher history (managers), always with a reason; the value is booked back, a single-use voucher becomes active again and its coupon counter goes back. Shown in the redemption journal.
* New: "Mark as redeemed (store)" on the voucher page of a single-use voucher, booked like a scanner redemption. The scanner keeps a receipt number and the till/branch of the device with each redemption and can print the result.
* New: scanner messages with context – when, where and by whom a voucher was used, "reserved in an unpaid shop order" with the time it is released, the planned delivery date and the cancellation reason. The lookup applies the same rules as the redemption (store credit, channels, unpaid orders): no green "Valid" for a code the counter would refuse.
* Fix: scanner – requests time out after 10 seconds with a loading state; an unanswered booking shows "Result unclear – query again" instead of "system error"; an abandoned claim is resolved from the voucher history after two minutes; "still processing" is yellow. Codes are posted instead of put into the lookup URL, and an expired login nonce is renewed automatically.
* Fix: scanner – without a camera (no HTTPS, permission denied) a hint is shown instead of "system error"; on phones the keyboard no longer pops up over the camera after each scan.
* Fix: scanner recognises custom QR links (code in a parameter or the last path segment), PDF download links, codes with spaces or without separators, and Y/Z swapped by a USB scanner on a German keyboard.
* Fix: reverting a check-in is limited to one's own check-in within 10 minutes; later only managers with a reason, never for cancelled or expired tickets.
* Fix: ticket QR page – the check-in form redirects after posting and carries a one-time key (reload, double tap or resending never admit twice); a refused check-in is shown in red.
* Fix: Pass Captain – failures to cancel tickets, release a reservation or give a voucher back are recorded in the history ("passcaptain_revoke_failed", "passcaptain_restore_failed") and shown to the admins; a failed "send tickets again" from the customer account is recorded too.
* Change: every feature stays free and included – no license, no locked features; license data stored by pre-release builds is removed on update and uninstall.
* New: extension points for add-ons – own settings section (`vcap_settings_sections`), own report tab (`vcap_report_tabs`, `vcap_report_page`), admin submenu (`vcap_admin_menu`), voucher links such as "Add to Wallet" (`vcap_voucher_links`), issuing without an order (`issue_standalone()`) and source and user of every history entry (`vcap_voucher_event`); documented as public API in API.md.
* New: setting "Multi-purpose vouchers as payment" (off by default) – multi-purpose vouchers, gift cards and store credit pay the order total after tax, shipping and fees as an untaxed payment line "Voucher CODE" instead of a discount before tax.
* New: gift card top-up – a product marked "Top up" adds the paid value to an existing card; refunds take the top-up back as far as the balance allows.
* New: Reports – outstanding voucher value at a date (with CSV), redemption journal and, with Pass Captain, a reconciliation of voucher payments.
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
* Privacy: referral data is included in the personal data export and erasure; the suggested privacy policy text covers the balance history, referrals, external redemptions, Pass Captain, the scanner and rate-limit counters.
* New: VAT treatment per voucher product (automatic, multi-purpose, single-purpose, promotion). Pass Captain keeps the VAT for multi-purpose vouchers and shows them as a payment on its invoices.
* New: default validity by the buyer's billing country (the shop country when it is unknown) when a product has no expiry of its own – Germany 3 years to the end of the year, Austria 30 years (filter vcap_statutory_expiry). Shops that saved a default in days keep it.
* New: ticket vouchers are given back automatically when Pass Captain cancels or refunds the exchanged tickets (date or event cancelled); the voucher can then be exchanged again.
* New: partial refunds of voucher purchases void as many vouchers as were refunded (unused ones first); refunds that would pay back value already spent from a voucher are refused with the maximum amount (filter vcap_allow_refund_of_spent_vouchers).
* New: scanner privacy – the holder name is hidden unless enabled (personal tickets always show it); order number, customer details and the admin link only for staff who manage vouchers. Value and remaining balance are always shown, the cashier needs them.
* Fix: in shops with net prices a gift card paid the net amount plus tax while only the net amount was deducted; it now pays exactly its balance of the gross price (filter vcap_balance_covers_tax).
* Fix (security): keys of built-in integrations can no longer be created, read or released over REST; Pass Captain reservations are owned by no customer.
* Fix: scanner – ticket vouchers cannot be redeemed at the counter, single-use redemption is atomic, vouchers held by an unpaid order are refused, undo asks first, check-ins are idempotent, Pass Captain results show readable reasons and the date of other-day tickets.
* Fix: ticket exchange – one key per attempt, so a voucher can be exchanged again after a released attempt; guests confirm their email; the date can be changed or the exchange cancelled.
* Fix: voucher currency is the shop base currency; "days" expiry ends at the end of the day in the shop's time zone; stale reservation release can no longer starve; double use of single-use vouchers is logged.
* New: shared scanner – Vouchers → Scanner also recognises Pass Captain tickets and checks them in at Pass Captain (Pass Captain permission required); voucher codes are looked up first.
* New: integration status for Pass Captain in the settings (Integrations), menu link to Pass Captain, hint on the voucher type "Ticket" while Pass Captain is active.
* New: ticket vouchers for Pass Captain – a voucher product names a Pass Captain ticket type; the voucher is exchanged for the tickets on the redeem page, by an admin or by code (reserve → issue tickets → commit, idempotent, tickets revoked when the voucher is voided).
* New: ticket vouchers for any date of a Pass Captain event – the customer chooses the date on the redeem page (admins on the voucher page); optionally limited to one ticket name. Sold-out dates are not offered; required attendee details are asked on the redeem page.
* Dev: hooks vcap_product_settings_fields/_save, vcap_line_snapshot, vcap_redeem_code, vcap_voucher_admin_actions/_action/_messages and vcap_event_labels for connectors.
* New: Affiliate Captain adapter – assign a partner to a code campaign (Vouchers → Generate codes); every code of the campaign is credited to that partner. Purchased vouchers and gift cards are never assigned.
* New: hooks vcap_campaigns_table_header and vcap_campaigns_table_cell for extra columns in the campaign table.
* New: Pass Captain integration – vouchers and gift cards pay for tickets in Pass Captain's checkout (reserve before payment, commit after payment, release on cancellation, proportional give-back on ticket refunds).
* New: redemptions->refund() gives back (part of) a completed external redemption.
* Fix: Pass Captain reservations are held for Pass Captain's whole payment window (late bank transfers no longer find the voucher released).
* Fix: stacked promotions never discount a line by more than its price; recalculating an order keeps the promotions it was placed with.
* Fix: a voucher that cannot be voided after a refund or cancellation is no longer reported as voided: the order gets a note, the case is listed in an admin notice and Site Health, and the void is retried automatically.
* Fix: an order cancelled by hand (no refund) voids only its unused vouchers; vouchers already used stay valid. Partial refunds never void vouchers that were used up completely.
* Fix: vouchers of orders that waited for their payment (e.g. "On hold" as the only issue status) are issued when the order reaches a paid status.
* Fix: "Refund as store credit" runs once per order at a time and ignores a resubmitted form – no double credit.
* New: "Book back onto voucher" on the order screen – a return of items paid with a gift card goes back onto the card, also for guest orders and orders with a total of 0.
* Fix: a fully refunded order gives single-use vouchers back (WooCommerce does so only for cancelled orders); with "Restore balance on cancellation" off, single-use vouchers stay redeemed on cancellation too.
* Fix: value booked back onto an expired or cancelled card is reported (order note, admin notice) instead of vanishing; filter vcap_restore_extension_days extends expired cards.
* Fix: a gift card top-up adds its face value like a new card (net in net-price shops, also when paid with another card); a top-up that cannot be booked holds the order and tells the customer; customers get a confirmation with the new balance and validity; cards valid for less than 30 days cannot be topped up (filter vcap_topup_min_remaining_days), the validity can be extended with vcap_topup_expires_at.
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
* Fix: money safety – foreign-currency amounts are never taken 1:1 (rate stored at checkout, sale held or refused without a rate, Site Health test, WooPayments Multi-Currency adapter); gift card refunds count top-ups (a refunded purchase keeps the topped-up value); single-use vouchers in unpaid orders count as used and a late payment after voiding or redemption is held; sold percentage vouchers only with a fixed value of 0–100 %, "Fixed product discount" blocked, refused coupons roll the voucher back.
* Fix: with WPML or Polylang, voucher emails and their PDF (texts, dates, links) are sent in the order language – also for scheduled deliveries, reminders and resends; filter vcap_email_locale. Polylang gets the template translation of the order language too.
* Fix: the email body follows the mail's language: a saved default text (in any language) is treated as the default, new installs store none.
* Fix: redemption conditions and email texts can be translated (WPML string translation via wpml-config.xml, Polylang strings); wpml-config.xml lists every voucher product setting (top-up, tax treatment, code batch stop, transferability, conditions translated) – checked in CI.
* Fix: default PDF templates are created in the site language, staff print PDFs in the order/site language, dates show translated month names, percentages use the shop's number format.
* Fix: "Additional recipients" get their own copy; only the customer's address decides whether a voucher counts as delivered.
* Fix: a gift sent to the buyer's own address is mailed once (buyer mail only).
* Change: {recipient_name} no longer falls back to the buyer – the PDF leaves out an element whose placeholders are all empty ("For:" without a name); new placeholder {holder_name} (recipient, else buyer) for tickets.
* New: the buyer learns when and where vouchers go – note on the product page (delivery delay/date), in the cart, in WooCommerce's order emails and on the order received page ("What happens next"); the buyer's copy of a gift says it is on its way to the recipient.
* Fix: line breaks in the personal message count as one character, as in the browser's counter.
* Fix: deactivation also suspends vouchers that may not be redeemed in the shop and coupons with rules only Voucher Captain checks (first order only, referral codes, SKU rules); each suspended coupon is marked, only marked coupons are published again, also on a reinstall.
* Fix: after a reactivation, orders paid, cancelled or refunded while the plugin was off are caught up in the background (vouchers issued or voided, referral rewards, top-ups).
* Fix: a deactivation that never got its activation (network deactivation stopped by a time-out, update by upload after a rollback) no longer leaves gift cards suspended: the running plugin publishes them again.
* Fix: network activation sets up 25 sites at once (the others on their first request); deleting a site of a network drops its voucher tables; named database locks are per site.
* Fix: database updates run in admin, cron and WP-CLI requests or the background action vcap_migrate, never in shop requests; tables are created and checked once per update; the schema check covers every index.
* New: Site Health check for the PHP extensions mbstring, dom, gd, zip and iconv; activation stops without mbstring or dom.
* Dev: vcap() is defined only when WooCommerce is active in a supported version; new action vcap_loaded and VCAP\Plugin::ready().
* Dev: constant VCAP_CONTRACT_VERSION (1.1.0) names the version of the public hook and API contract; docs/HOOKS.md lists the first version of every hook.
* Fix (privacy): imported cards get a random reference instead of one derived from their code.
* Fix (security): text from visitors (code of a redeem link, recipient name, attendee fields) can no longer run shortcodes through block output.
* Fix: the redeem form needs no nonce for guests and keeps pages cacheable unless it shows data of the visitor; it is also recognised in synced patterns, template parts, widgets and builder layouts. Its notices appear only in the form that was sent.
* Fix: editor and page builder previews show example notes and no longer create referral codes or show the editor's own vouchers; the block preview is not clickable.
* Fix: cart and checkout blocks show the title of an automatic promotion instead of "vcap-campaign-ID" and refuse to remove it with a message.
* Fix: product pages list the redemption conditions of every country instead of the visitor's (page caches served one visitor's conditions to all).
* Fix: a balance check counts once per request, however many balance forms the page has.
* Accessibility: results of the balance and redeem forms are focused after sending and marked on the field; buttons in voucher lists keep their visible text as name; character counters are field descriptions and are announced only near the limit; responsive tables label every value; field ids are unique with several product forms on a page; the field group editor can reorder fields by keyboard; voucher list checkboxes are labelled.
* New: suggested amounts are a radio group that works without JavaScript (and in quick views on shop pages); the chosen amount is clearly marked; a maximum alone is shown as "Maximum X".
* New: block settings – button text of "Redeem voucher", "My vouchers" with or without store credit and referral code (shortcode `[vcap_my_vouchers extras="no"]`); blocks have keywords and an inserter preview. Shortcode output is wrapped in div.woocommerce; the login hint returns to the page after logging in.

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

= 1.3.0 =
Requires PHP 8.5, WordPress 7.0 and WooCommerce 11.2.2 or newer (was PHP 8.1, WordPress 6.4, WooCommerce 8.9); MySQL 9.7 / MariaDB 12.3 recommended. Adds read functions and voucher commands for add-ons and automations (contract 1.3.0; the commands are off by default). From 1.1.x the database is updated to version 1.8.0 – back up first.

= 1.2.0 =
Requires PHP 8.5, WordPress 7.0 and WooCommerce 11.2.2 or newer (was PHP 8.1, WordPress 6.4, WooCommerce 8.9); MySQL 9.7 / MariaDB 12.3 recommended. All features stay free. The database is updated to version 1.8.0 – back up first. Existing shops keep how multi-purpose vouchers are booked; new installations book them as a payment after tax. Connectors: customers can no longer refund a completed redemption over the REST API (filter `vcap_customer_may_refund_redemption` to allow it).

= 1.1.0 =
Requires WooCommerce 8.9 or newer.
