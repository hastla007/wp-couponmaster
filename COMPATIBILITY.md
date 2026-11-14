# Compatibility matrix

## Tested platform

The CI integration suite installs WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8 and Voucher Captain together. It runs against MySQL 8.4 and MariaDB 11.4, with both WooCommerce HPOS and legacy post storage. PHP lint and smoke checks run on PHP 8.1 and 8.4.

## Minimum versions vs. test baseline

| | Minimum (plugin header, enforced) | Test baseline |
|---|---|---|
| WordPress | 6.4 | 7.1.4 |
| WooCommerce | 8.9 (first version with the `woocommerce_coupon_get_items_to_apply` filter used by promotions, purchase rules and SKU rules – verified against the 8.8.0–10.0.0 releases; older versions show an admin notice and the plugin stays inactive; gift card and store credit coupons are then refused at checkout, because nothing would deduct their balance) | 11.2.2 |
| PHP | 8.1 | 8.4 |
| Yoast SEO | not required | 28.8 (optional, tested alongside) |

The CI job `minimum-versions` installs WordPress 6.4.5 and WooCommerce 8.9.0 on PHP 8.1 **without** Yoast SEO and runs the full integration suite, so the minimums are tested, not assumed. Update both columns with every release.

## Multisite

Supported per site. Each site has its own voucher tables (`{site prefix}vcap_*`), settings, WooCommerce coupons and private upload folder; vouchers are never shared between sites. Network activation sets up the first 25 sites in its own request; every further or later-created site is set up on its first request. Network deactivation walks through every site in batches of 100 and suspends its balance coupons (sites that were never set up are skipped). Should it stop half-way (time-out), the plugin stays network-active: run it again (each site is idempotent); a site it already handled publishes its coupons again by itself 15 minutes after the last site was worked on, as long as the plugin stays active. Deleting a site drops its voucher tables (`wpmu_drop_tables`) – only while the plugin is network-active; a plugin active on single sites only is not loaded in the network admin. MySQL named locks (deliveries, promotion holds) carry a hash of the database name and the site's table prefix, so sites of a network and shops sharing a MySQL server never block each other. A network uninstall removes data only on the sites whose own "Delete data on uninstall" setting is enabled – the others keep their tables. CI job `multisite` checks network activation, a site created afterwards, the deletion of a site (tables dropped), the suspension of a subsite's gift card on network deactivation and the per-site uninstall. Referral data stored with users (shared by all sites) is removed only when every site opted in.

## Updates, downgrades and rollback

- The schema is upgraded after an update by one request at a time (upgrade lock in the options table, taken over after five minutes if its request died); other requests keep working with the old schema meanwhile. Shop requests (front end, REST) never do the upgrade work: they queue the Action Scheduler action `vcap_migrate`; admin, cron and WP-CLI requests continue it as well. Tables are created and verified once per upgrade; data migrations then run in batches with a time budget without further DDL; `vcap_db_version` is set only when the schema (tables, columns, unique keys and indexes) is verified complete. A first installation whose tables could not be completed retries only the schema (every five minutes), not the whole activation.
- Schema 1.4.0 (release after 1.1.0): promotion uses (`vcap_promotion_usage`) and claims (`vcap_claims`) get their own tables, vouchers remember who already received them (`delivered_roles`), the ledger gets the indexes `voucher_order` and `created_at`. Old `_vcap_used_by` post meta and `_vcap_claim_*` options are moved in the background and counted until then.
- A newer `vcap_db_version` than the running code expects is never lowered; administrators see a notice. Releases up to 1.1.0 do not have this guard and set the version back when they run on a newer database.
- **Rollback:** take a database backup before every update. To go back, install the previous release ZIP over the current one – never delete the plugin for it: deleting runs `uninstall.php` (license released, with "Delete data on uninstall" the tables dropped). Coupons suspended by a deactivation stay drafts under 1.1.0 and older; the next update publishes them again on its first request, also without an activation. It keeps working on the newer tables (new columns and tables are ignored), but it counts promotion uses and claims only from its own storage, so usage limits can be exceeded while it runs; uses it records are moved again by the next update. For a clean rollback restore the backup together with the previous release.
- CI job `upgrade` installs a previous version with data, updates to the current ZIP, verifies data and schema, rolls back, writes data with the old version and updates again – from the last release (newest `vX.Y.Z` tag; until the first tag the 1.1.0 release, schema 1.3.0 – the schema 1.1.0 shops actually run) to the free and to the premium ZIP, from the schema right before the last schema change, and from the free to the premium ZIP of the same version (and back). Rollback steps for shops: RELEASE.md → Rollback.

## Caching and page builders

- The redeem page (the configured page, any page with the redeem block/shortcode, links with `?vcap_code=`) is never cached: `DONOTCACHEPAGE` plus no-cache headers. The redeem form, a balance result and "My vouchers" set `DONOTCACHEPAGE` wherever they render; action `vcap_no_cache` fires for cache plugins that need their own call.
- Balance check and product preview need no nonce for guests (read-only, rate limited), so cached product and balance pages keep working.
- Blocks are registered from `assets/blocks/*/block.json` (apiVersion 3). Storefront CSS/JS are enqueued where the output renders (shortcodes, blocks, voucher fields), so `[product_page]` and page-builder add-to-cart widgets work; a quick view loaded by AJAX needs the script on the page already.

## CSV exports

UTF-8 with BOM, local shop time, translated column headers and values. German admin languages get `;` as separator and a decimal comma (Swiss German: decimal point); filter `vcap_csv_format` changes it. Exports are streamed without a row limit.

## EU target market

Country-specific data is configuration, not code:

| What | Where |
|---|---|
| Currency and main locale of each of the 27 EU countries; verified statutory voucher validity (Germany 3 years to the end of the year, Austria 30 years) | `config/markets.php` (data file), filter `vcap_markets` |
| Statutory validity of further countries (or different rules) | setting *Vouchers → Settings → Statutory validity per country* (`DE: 3 end`, `AT: 30`) – countries without a rule use 3 years to the end of the year |
| Redemption conditions / consumer information per country | setting *Redemption conditions per country*; the billing country decides and the text is fixed on the voucher |
| Currency minor units (ISO 4217) | `config/currencies.php`, filter `vcap_currency_decimals` |
| VAT rates, OSS, place of supply | WooCommerce tax settings only – Voucher Captain stores no rates (TAX.md) |
| Languages | translation files `languages/voucher-captain-{locale}.mo` (included: de_DE, de_AT; POT for further languages) |

*Tools → Site Health* checks every EU country in WooCommerce's selling locations: verified validity, conditions and a
translation (test "Some EU selling countries need voucher settings"). `tests/run.php` covers the configuration.

## Bundled libraries

Voucher Captain bundles dompdf, php-font-lib, php-svg-lib, html5, php-qrcode and php-barcode-generator. Libraries that other plugins also ship are moved into the `VCAP\Vendor` namespace when the release ZIP is built (`bin/scope-vendor.php`), so two versions can never collide. This is currently `sabberworm/php-css-parser`: WooCommerce 8.x bundles an older, incompatible version, and loading both ended in a fatal error. The build fails if an unprefixed reference remains; `tests/scoping-integration.php` checks the installed ZIP.

## Optional integrations

CI job `ecosystem` installs each third-party plugin below from wordpress.org (latest release at run time; the job log
prints the version) next to WooCommerce 11.2.2 and Voucher Captain and runs `tests/ecosystem-integration.php`.

| Integration | Contract | CI coverage |
|---|---|---|
| **WooCommerce Stripe Gateway** (`woocommerce-gateway-stripe`) | Gateway-agnostic: vouchers are issued and balances charged on WooCommerce's paid statuses, never by the gateway itself. | `ecosystem (stripe)`: gateway registered; payment pending at the provider (on-hold) issues nothing; confirmation issues once, a repeated confirmation (webhook + return) not again; gift card + gateway with a failed attempt and a retry charges the card once; refund recorded without calling the provider voids the voucher; full refund of a redemption order gives the balance back. |
| **WooCommerce PayPal Payments** (`woocommerce-paypal-payments`) | as above | `ecosystem (paypal)`: the same flows with the `ppcp-gateway` gateway. |
| WooCommerce Subscriptions | Subscription products may issue vouchers. Renewal issuance is opt-in via `vcap_subscription_renewals` (default off); voucher-only order metadata is removed from renewal orders. | Renewal metadata filtering is tested without the extension (commercial, not installable in CI); a renewal with the real extension is a release acceptance check (`RELEASE_CHECKLIST.md`). Other subscription plugins are not supported out of the box (use `vcap_should_issue_for_order`, ADAPTERS.md 3). |
| **WooCommerce Multilingual** (WCML, `woocommerce-multilingual`, multi-currency without WPML) | Converts order-currency amounts with `unconvert_price_amount()`. | `ecosystem (wcml)`: rate read from WCML (1 EUR = 1.10 USD); a voucher paid with 55 USD is worth 50 EUR; an EUR gift card used on a USD order is charged the EUR value; cancelling gives it back. |
| **WOOCS** (`woocommerce-currency-switcher`) | Converts by dividing by the currency rate from `get_currencies()`. | `ecosystem (woocs)`: the same checks with WOOCS rates. |
| **CURCY** (`woo-multi-currency`, free edition; premium uses the same settings API) | Converts by dividing by the currency rate from `get_list_currencies()` (`WOOMULTI_CURRENCY_F_Data` free, `WOOMULTI_CURRENCY_Data` premium). | `ecosystem (curcy)`: the same checks with CURCY rates. |
| Aelia Currency Switcher | Uses `wc_aelia_cs_convert` to convert the order amount to the shop base currency. | Commercial, not installable in CI; the converter filter contract is exercised with a deterministic test callback. |
| WooPayments Multi-Currency | Converts by dividing by the rate of `WC_Payments_Multi_Currency()->get_enabled_currencies()`. | Not installed in CI; the order rate stored at checkout and the refusal without rate are tested. |
| Other currency switchers | Implement `vcap_amount_to_base_currency`; the rate stored at checkout (order meta `_vcap_exchange_rate`) is used first. Without any known rate money is never taken 1:1 (sale held, credit refund and fixed-value vouchers refused, Site Health warns). | Unknown-provider refusal is tested (`acceptance`, `store-credit`, `compatibility`). |

Real payments (card, PayPal account, redirects, the provider's webhooks against its sandbox) need the shop's own
provider accounts: they are a release acceptance check per shop (`RELEASE_CHECKLIST.md`). Each store should verify its
currency switcher's rate direction and rounding rules with one test order per currency before selling.

## Checkout and product fields

Voucher fields use WooCommerce product add-to-cart hooks and cart/order-item data. The integration suite checks field definitions, cart data persistence, order-item persistence, Store API reservation, paid-order commit, payment retry behavior, and both HPOS and legacy order CRUD. Voucher Captain declares HPOS and Cart/Checkout Blocks compatibility. Block-based add-to-cart requests (Store API `cart/add-item`) accept the voucher fields as `vcap_fields` (object of field key ⇒ value); they are validated like the product form (required fields, email/date/number/select rules) and refused with the error code `vcap_invalid_voucher_fields`. Products with required or custom fields link to the product page instead of adding to the cart from product lists.

The CI job `browser-e2e` runs Playwright against a real shop (WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8): product page fields, keyboard-only use, classic cart/checkout and the cart/checkout blocks with cash on delivery, shortcodes and blocks, My Account, admin screens and the product editor next to Yoast SEO, axe-core accessibility scans of all Voucher Captain markup, and a PHP notice check. External payment gateways, redirects and webhooks, and screen-reader testing by a person remain release acceptance checks.

## WP Pass Captain (tickets)

Release gate: Voucher Captain alone, WP Pass Captain alone and both together.

| Setup | Where it is checked |
|---|---|
| Voucher Captain without Pass Captain | Every suite of this repository's CI (`smoke`, `wordpress-integration`, `minimum-versions`, `browser-e2e`); Pass Captain code paths stay inactive. |
| Pass Captain without Voucher Captain | Pass Captain's own CI (hastla007/wp-passmaster), with a stub voucher provider. |
| Both plugins | CI job `pass-captain`: `tests/passcaptain-integration.php` against Pass Captain `main` and its release branch – vouchers and gift cards in the ticket checkout (reserve, commit, late payment, release, proportional refund, currencies, channels) and ticket vouchers (fixed date or date chosen by the customer, sold-out dates, attendee details, repeat, revoke on void). |

Both plugins keep their own plugin entries and admin menus (each links to the other while both are active; WooCommerce → Settings → Vouchers → Integrations shows the status). They talk only through Pass Captain's versioned contract – the filters `wp_pass_captain_voucher_provider_v1`, `wp_pass_captain_ticket_service_v1` and `wp_pass_captain_checkin_service_v1`, the hold contract `OrderAdapter::HOLD_FULFILMENT` and the reconciliation API `Integrations\Vouchers\Reconciliation` – never through each other's tables (one fallback for older Pass Captain versions, see *Reconciliation*).

**Which ticket to use:** Voucher Captain's voucher type *Ticket* is a simple admission pass without dates or capacity (e.g. a day pass sold in the shop), checked in at Voucher Captain. Events with dates, capacity, seats or admission rules belong in WP Pass Captain – sold there directly or through a ticket voucher. Existing Voucher Captain tickets keep working; while Pass Captain is active the product settings point this out.

**Reconciliation:** *Reports → Pass Captain reconciliation* compares the voucher payments and ticket vouchers in Pass Captain with the redemptions here. What Pass Captain must provide: `PassCaptain\Integrations\Vouchers\Reconciliation::VERSION` ≥ 1 and `Reconciliation::orders( array $args )` (Pass Captain 1.4+, docs/VOUCHERS.md there) – a read-only listing of the orders with a voucher (`limit` ≤ 500, cursors `after_id`/`before_id`, `order` `desc` for the newest first, lookup by `redemption_key`) with `order_id`, `public_id`, `status`, `payment_status`, `payment_provider`, `provider_reference`, `currency`, `exchange_key`, `voucher` (`source`, `redemption_key`, `status`, `purpose`, `amount_minor`, `amount_paid_by_voucher`, `refunded_minor`) and timestamps. Voucher Captain pages through the newest 2,000 orders. Pass Captain versions without this API (e.g. its current `main`) are read from their order table `{prefix}wpcap_orders` as a fallback, only after checking its columns (`order_id`, `public_id`, `status`, `payment_status`, `payment_provider`, `provider_reference`, `currency`, `voucher_snapshot`); the report then shows a notice to update Pass Captain. The fallback will be removed once every supported Pass Captain release has the API. The filter `vcap_passcaptain_reconcile_orders` (rows; `null` keeps the default) overrides both. The CI job `pass-captain` checks the API on Pass Captain's work branch (same findings as the table read) and the fallback on `main`.

**Shared scanner:** *Vouchers → Scanner* looks a code up in Voucher Captain first; codes it does not know that look like Pass Captain tickets (64-character code or ticket URL) are previewed and checked in through Pass Captain's check-in service, with Pass Captain's rules, locks, audit and the `wpcap_checkin` permission. Voucher redemption and ticket check-in stay separate actions.
