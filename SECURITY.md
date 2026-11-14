# Security and privacy controls

This document is the security policy of Voucher Captain (how to report a vulnerability, which versions get fixes)
and records the controls exercised by the current integration suite. It is not a security certification.

## Reporting a vulnerability

Please do **not** open a public issue, pull request or forum post for a security problem.

- **Where:** privately through GitHub's vulnerability reporting of this repository (*Security → Report a
  vulnerability*, `https://github.com/hastla007/wp-couponmaster/security/advisories/new`).
- **What:** affected version, the steps or a proof of concept, the impact you see, and
  whether the problem is already known to others or exploited.
- **Our timeline:** acknowledgement within 5 working days, a first assessment (confirmed, severity, affected
  versions) within 10 working days, a fix for confirmed vulnerabilities as fast as the severity requires and at the
  latest within 90 days. We coordinate the publication date with you, publish a GitHub security advisory with the
  fixed version and credit you unless you prefer otherwise. Please give us that time before publishing.
- **Actively exploited vulnerabilities and severe incidents** are reported by the vendor as the EU Cyber Resilience
  Act requires (since 11 September 2026: early warning within 24 hours, notification within 72 hours, final report
  within 14 days after a fix is available, through the single reporting platform/CSIRT), and shops are told what to
  do in the advisory and the release notes ("Fix (security)" entries, RELEASE.md).
- **security.txt:** the vendor's website publishes `/.well-known/security.txt` (RFC 9116) pointing to the reporting
  address above (RELEASE_CHECKLIST.md → manual checks).

### Supported versions

| Version | Security fixes |
| --- | --- |
| 1.2.x (current minor) | yes |
| 1.1.x | until 6 months after the release of 1.2.0 – please update |
| < 1.1 | no |

Fixes ship as a new patch release of the current minor version (ZIP on GitHub and WordPress.org).

### What a release ships for security review

Every GitHub release has, per ZIP, the SHA-256 (`*.sha256`) and a software bill of materials in CycloneDX JSON
(`*.cdx.json`, `bin/sbom.php`): all bundled Composer packages with version, licence and source commit, and the
libraries bundled outside Composer (`bin/bundled-assets.json`, e.g. jsQR in `assets/vendor/jsqr/`) with the SHA-256
of the shipped file. Known vulnerabilities are checked weekly (`scheduled.yml` → `dependency-audit`: Composer audit,
npm audit of the browser tests, and the OSV database for the bundled libraries) and on every push (`composer audit`).

## Admin and scanner access

Voucher administration and import/export handlers check capabilities and WordPress nonces. PDF admin downloads require both a nonce and voucher-management capability. Scanner REST routes require a scanner capability (or WooCommerce management capability); the integration suite checks unauthenticated lookup, check-in, and redemption requests. The role "Voucher cashier" has only the scanner capability; customer names, order numbers and the admin link are shown to voucher managers only. Cancelling a counter redemption needs a reason and is limited to managers or the booking user within 15 minutes; reverting a check-in to one's own within 10 minutes (managers with a reason). Raising a remaining value needs `vcap_increase_balance`, refunding as store credit `edit_shop_orders`. Scanner lookups are POST requests, so codes do not end up in URLs and server logs.

## Rate limits

Every place where a code can be entered is rate limited with fixed windows, in **one shared bucket per visitor** (`VCAP\CodeLookup`, 15 failed codes per 15 minutes, filter `vcap_rate_limit_code`), so the quotas of the different forms do not add up: balance check, redeem form, coupon links, gift card top-up, WooCommerce's own coupon entry (cart form, `?wc-ajax=apply_coupon`, Store API `cart/apply-coupon` and `cart/coupons`, recognised by handler class and case-insensitive route, also inside `batch`) and the redemption API. The API counts per user (setting "invalid codes per 15 minutes") **and** – except for staff with voucher management rights – per address in the shared bucket, so self-registered accounts cannot multiply the quota. Each attempt is counted before the lookup, in the same atomic step as the check, so parallel requests cannot pass the check together; an existing code gives the attempt back, so customers entering real vouchers never use up their quota. Unknown codes and another customer's store credit count as failures and get the same answer on every surface (the top-up form also answers "unknown", "not a gift card", "void/expired" and "other currency" alike; the held order line keeps the exact reason for staff). The site-wide counter never refuses a request by itself (that would let anyone lock out all customers): while it is full, every visitor's quota shrinks to a fifth (`vcap_rate_limit_brake`); shop managers and logged-in customers with vouchers of their own keep the full quota. A visitor whose quota is used up gets the same answer for every code, existing or not. The PDF preview (20 per 10 minutes), guest PDF downloads (per visitor and per link) and the customer's "send again" (3 per voucher and hour) count every request; their site-wide counters brake the same way.

The balance check and the PDF preview need no nonce for guests (they only read, are rate limited, and a nonce in a cached page would be expired or another visitor's). A logged-in customer whose nonce is missing or expired is answered like a guest (balance check: without the account's own store credit; preview: without the account's name) instead of getting no answer.

Counters live in the database by default (one `INSERT … ON DUPLICATE KEY UPDATE` row per window in `wp_options`, purged by the daily maintenance) and therefore hold across parallel requests and several web nodes. A persistent object cache is used only on request (`vcap_rate_limit_backend` = `object_cache`); if it does not count (outage, no atomic increment, eviction) the database counts instead. Counter names are an HMAC (site salt) of bucket and address, so they cannot be turned back into IPv4 addresses. The visitor is identified by `REMOTE_ADDR` only – forwarded headers can be forged; behind a trusted reverse proxy or CDN supply the real client address with the `vcap_client_ip` filter (otherwise all visitors share the proxy's counter – Site Health warns when the request carries forwarding headers or comes from a private address). IPv6 addresses count per /64 network, because one connection usually owns a whole /64, and additionally per /48 with four times the quota (`vcap_rate_limit_ipv6_48_factor`). Limits per bucket can be changed with `vcap_rate_limit_{bucket}`. The first visitor over a limit in a window is logged (WooCommerce log, source `voucher-captain`, without the address) and shown in Site Health, which also offers "Reset rate limits". Rate limits reduce code enumeration; they are not a substitute for edge-level abuse protection under distributed traffic.

## Code strength

Codes that carry money must not be guessable even at the rate limits above. Generated voucher codes and generated code pool batches have a random part of at least 2^50 combinations (10 letters/digits, 11 letters or 16 digits; WooCommerce codes are case-insensitive, so the mixed-case character set counts as 33 characters, not 56; the length setting is raised automatically; filter `vcap_min_code_bits`). The code import estimates the guessing space of each code (letters and digits used, without the prefix or suffix shared by the whole file character by character – for a single code, by the codes already in the batch; a check digit (Luhn, GS1/EAN) every code carries does not count; numbers of a series – evenly spaced, or much closer to another code than chance explains – count as 0 bits) and skips weaker codes unless the merchant accepts them and additionally confirms the risk (two checkboxes); a series is named in the import report and accepted weak codes are reported as a warning. Imported codes are handed out in random order, not in file order. Single codes generated for a campaign from a template coupon that gives a discount get the same minimum. Referral codes are meant to be shared and are not affected.

## Codes on order pages and in links

The order details under an order (`woocommerce_order_details_after_order_table`) also appear on the order tracking page, where the order number and billing email are enough. Full codes and signed PDF links are therefore shown only on the order received page with the order key, or to the logged-in customer of the order (filter `vcap_order_codes_visible`); elsewhere the codes are masked (last four characters) without links. Cart, checkout and order notes show gift cards to be topped up masked as well. Order notes (which reach ERP/CRM/shipping connectors through the REST API and every member of staff) name issued, bonus, referral and restored vouchers by their masked code only; the full code is on the voucher's detail page. Referral reward notes in the friend's order name the referrer by account number, not by email address; the privacy eraser removes addresses and codes from notes written by earlier versions.

Redeem links in emails, PDFs and the account (`?vcap_code=`) are opened once: the code moves into the WooCommerce session – for a visitor without a session into a short-lived HTTP-only cookie of its own (30 minutes, only for a plausible code), so a link does not start a 48-hour session – and the visitor is redirected to the URL without it, so it does not stay in the browser history, analytics or Referer headers. The redeem page is sent with `Referrer-Policy: no-referrer` and is not cached while it shows a code from the session. The first request still carries the code (server and CDN access logs). Coupon links (`?gutschein=`) redirect the same way, with no-cache headers and `Referrer-Policy: no-referrer`. While a code waits in the session for products, every page is sent as not cacheable (`DONOTCACHEPAGE` and no-cache headers, decided on `wp_loaded`), and the waiting notice appears on cart and checkout only, with the code masked (last four characters).

## Redemption API targets

Over REST, customers may reserve, commit or release only for targets whose prefix a connector registered (`vcap_rest_redemption_target_prefixes`, default none); shop managers and connector users may use any other target. Targets and keys of built-in integrations (`passcaptain:…`, `wpcap-…`; filters `vcap_internal_redemption_target_prefixes`, `vcap_internal_redemption_key_prefixes`) are refused over REST, so nobody can book a ticket voucher or a Pass Captain payment without its delivery. Targets are checked in their stored (normalised) form.

## Deactivation

Gift cards and store credit are WooCommerce coupons without a usage limit whose amount is the remaining balance; only Voucher Captain deducts that balance. On deactivation these coupons are set to draft (WooCommerce no longer accepts the codes) and published again on reactivation. The same applies to coupons whose rules only Voucher Captain checks: vouchers that may not be redeemed in the shop (redemption channels), "First order only" coupons, referral codes and coupons with SKU rules. Each suspended coupon carries the marker `_vcap_suspended`; only marked coupons are published again (never coupons drafted by hand). If a deactivation never got its activation (network deactivation stopped half-way, update by upload after a rollback), the running plugin publishes them again by itself once the deactivation is 15 minutes old. Not covered while the plugin is off: the setting that keeps discount codes off voucher products – ordinary coupons then reduce voucher products like any other product. Orders paid, cancelled or refunded while the plugin was off are caught up after the reactivation (vouchers issued or voided, referral rewards and top-ups booked, Action Scheduler action `vcap_catch_up_orders`). Uninstalling suspends them as well (also when deactivation did not run, e.g. a network delete while the plugin is active on single sites); with "Delete data on uninstall" every Voucher Captain coupon is moved to the trash, because without its voucher it would be an ordinary coupon worth the full value. While the plugin runs, a Voucher Captain coupon without a voucher row is refused at checkout. This does not cover a plugin paused by WordPress recovery mode after a fatal error: there, nothing of the plugin runs, so fix or deactivate it promptly.

## Guest PDF links

Guest PDF links are signed with an HMAC tied to the site secret and the voucher token. The secret is created once with `add_option()` and never overwritten after a failed read (which would invalidate every link already sent). A rendered PDF is kept for at most an hour in the private upload folder under a name that changes with every voucher change, and a `downloaded` event is written at most once an hour per voucher. Page links expire after one day. Email-link lifetime follows the merchant's download-link setting; zero means no expiry. A changed token, expiry, or signature makes the URL invalid. Admin-generated links use a nonce and capability check.

## Personal data and retention

Privacy export and erasure paginate records in WordPress-sized batches. Erasure removes direct buyer and recipient identifiers and redacts free-text event notes while preserving voucher and event rows. Retention anonymization clears voucher personalization and redacts event notes in a transaction. Failed database operations are reported so erasure can be retried. Promotion limits per customer store guest uses under a keyed hash of the email address (`h:…`, never the address); plain-text keys of earlier versions are converted by the daily maintenance and by the eraser, and a deleted promotion takes its usage rows with it.

Delivery and resend audit entries record recipient counts or recipient roles, not email addresses. Do not put customer data, tokens, or full signed URLs in application debug logs.

Site Health keeps a short log of failed Voucher Captain mails (option `vcap_mail_failures`; other mails of the site are not recorded): the time, the masked recipient (`j***@example.com`), the kind of mail and the error text with any address masked – no subject (it may contain names) and no content. Entries older than 7 days are removed when the log is written and by the daily maintenance (at most 20 are kept), and the privacy eraser removes entries naming the erased address.

## CI coverage

The security integration script exercises 501-record export and erasure, retention anonymization, scanner permissions, rate limiting, guest-link signing, code visibility on order pages, the failed-mail log, selected admin nonce/capability guards, and the PHP redemption-service idempotency contract. The rate-limit script covers atomic counting, the site-wide brake with valid codes, IPv6 /64 and WooCommerce's Store API coupon entry; the redemption script covers REST targets. CI also runs Composer vulnerability auditing, scoped WordPress Coding Standards and PHPStan over the API contract, and the `WordPress.Security.*` sniffs over the whole plugin (blocking, job `quality-wide`).

External gateway redirect/webhook flows, browser accessibility and connectors installed in separate repositories remain release-time checks.
