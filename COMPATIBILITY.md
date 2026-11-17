# Compatibility matrix

## Tested platform

The CI integration suite installs WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8 and Voucher Captain together. It runs against MySQL 9.7 and MariaDB 12.3 (both LTS), with both WooCommerce HPOS and legacy post storage. PHP lint and smoke checks run on PHP 8.5.

## Minimum versions vs. test baseline

| | Minimum (plugin header, enforced) | Test baseline |
|---|---|---|
| WordPress | 7.0 (owner decision 17.11.2026, the same for all Captain plugins: older WordPress emits core deprecations on PHP 8.5) | 7.1.4 |
| WooCommerce | 11.2 (owner decision 17.11.2026; 8.9 was the first version with the `woocommerce_coupon_get_items_to_apply` filter used by promotions, purchase rules and SKU rules – verified against the 8.8.0–10.0.0 releases; older versions show an admin notice and the plugin stays inactive; gift card and store credit coupons are then refused at checkout, because nothing would deduct their balance) | 11.2.2 |
| PHP | 8.5 (owner decision 16.11.2026, the same for all Captain plugins; checked on load) | 8.5 |
| Database | MySQL 9.7 / MariaDB 12.3 (LTS; an older server is not blocked – Site Health shows a recommendation, filter `vcap_minimum_db_versions`) | MySQL 9.7, MariaDB 12.3 |
| Yoast SEO | not required | 28.8 (optional, tested alongside) |

The CI job `minimum-versions` installs WordPress 7.0 and WooCommerce 11.2.0 on PHP 8.5 **without** Yoast SEO and runs the full integration suite, so the minimums are tested, not assumed. Update both columns with every release.

## Multisite

Supported per site. Each site has its own voucher tables (`{site prefix}vcap_*`), settings, WooCommerce coupons and private upload folder; vouchers are never shared between sites. Network activation sets up the first 25 sites in its own request; every further or later-created site is set up on its first request. Network deactivation walks through every site in batches of 100 and suspends its balance coupons (sites that were never set up are skipped). Should it stop half-way (time-out), the plugin stays network-active: run it again (each site is idempotent); a site it already handled publishes its coupons again by itself 15 minutes after the last site was worked on, as long as the plugin stays active. Deleting a site drops its voucher tables (`wpmu_drop_tables`) – only while the plugin is network-active; a plugin active on single sites only is not loaded in the network admin. MySQL named locks (deliveries, promotion holds) carry a hash of the database name and the site's table prefix, so sites of a network and shops sharing a MySQL server never block each other. A network uninstall removes data only on the sites whose own "Delete data on uninstall" setting is enabled – the others keep their tables. CI job `multisite` checks network activation, a site created afterwards, the deletion of a site (tables dropped), the suspension of a subsite's gift card on network deactivation and the per-site uninstall. Data stored with users (shared by all sites: referral data of earlier versions, the email verification proof of personal vouchers `_vcap_verified_email` / `_vcap_email_changed`) is removed only when every site opted in. Data deletion also removes the settings of the plugin's emails (`woocommerce_vcap_*_settings`). The redemption journal is part of the bookkeeping records (retention: Germany 8 or 10 years, Austria 7 years) – export it (Reports → Journal, or the link next to the setting) before deleting the plugin.

## Updates, downgrades and rollback

- The schema is upgraded after an update by one request at a time (upgrade lock in the options table, taken over after five minutes if its request died); other requests keep working with the old schema meanwhile. Shop requests (front end, REST) never do the upgrade work: they queue the Action Scheduler action `vcap_migrate`; admin, cron and WP-CLI requests continue it as well. Tables are created and verified once per upgrade; data migrations then run in batches with a time budget without further DDL; `vcap_db_version` is set only when the schema (tables, columns, unique keys and indexes) is verified complete. A first installation whose tables could not be completed retries only the schema (every five minutes), not the whole activation.
- Schema 1.4.0 (release after 1.1.0): promotion uses (`vcap_promotion_usage`) and claims (`vcap_claims`) get their own tables, vouchers remember who already received them (`delivered_roles`), the ledger gets the indexes `voucher_order` and `created_at`. Old `_vcap_used_by` post meta and `_vcap_claim_*` options are moved in the background and counted until then.
- Schema 1.5.0: indexes for the daily maintenance and deliveries on vouchers (`status_expires`, `status_updated`, `expires_at`, `delivery_due`; the plain `status` index is dropped), a covering ledger index `voucher_ledger` (replaces `voucher_id`), the ledger column `actor_email_hash` (indexed; hash of a note that is an e-mail address, for privacy exports and erasures) and the code pool index `batch_used`. Existing ledger rows get their hash in batches (migration, then the daily maintenance from the cursor `vcap_event_hash_cursor`, which also covers rows an older version writes after a rollback); rows after the cursor are matched by their note meanwhile. A rollback to 1.4 keeps working on this schema (the dropped indexes only cost speed).
- Schema 1.6.0: the ledger column `source` (admin, cron, rest, scanner, checkout, cli, web – where a history entry came from). Earlier rows keep an empty source; no data migration. The plugin writes the column only once it exists, and a rollback to 1.5 keeps working (the column has a default). History notes written from this version on may be coded (`@vcap:{…}`, translated when shown by `VCAP\Note`); older versions show them as raw text.
- Schema 1.7.0: three counter tables. `vcap_promotion_counts` holds the number of uses per promotion (kept with `vcap_promotion_usage` on every use added or given back; the checkout counts under the promotion's lock), so carts read one row instead of counting all uses; the migration fills it from the usage table and the daily maintenance recounts promotions whose counter differs. `vcap_rate_limits` (rate limit counters per window) and `vcap_scan_keys` (scanner idempotency keys with their answer) replace the `vcap_rl_*` and `_vcap_scan_*` rows in `wp_options`; counters of running windows and keys younger than two days are moved in batches (migration, then the daily maintenance for an hour), and while options may be left a scanner key is also looked up as option. Until the switch (another request still upgrading) the old storage is used. A rollback to 1.6 keeps working on this schema: it counts and stores in `wp_options` again (the tables are ignored); installing a release over the current one (rollback or update) flags a recount, so after the next update the first admin, cron or WP-CLI request recounts the promotions whose counter misses uses 1.6 recorded (`Usage::reconcile_if_due()`; a rollback by copying files is corrected by the daily recount or `wp vcap migrate`), leftover rate limit options are purged and scanner keys written by 1.6 are not looked up any more (they only guard repeats within minutes). Uninstall drops the tables only when data deletion is enabled.
- Schema 1.8.0 (follows 1.7.0): the ledger columns `till` (till/branch of the scanner device) and `receipt` (receipt number) of counter redemptions and their cancellations. Counter redemptions booked before carried both only in their note (`… · Receipt 4711 · Till Shop 1`); the migration reads them from the note in batches (cursor `vcap_event_till_cursor`; labels in English, German and the site language; a note cut at 250 characters may have lost them) and a cancellation takes the till of the redemption it cancels. The plugin writes the columns only once they exist, and a rollback to 1.7 keeps working (the columns have defaults; the till report then shows those rows as "no till set").
- A newer `vcap_db_version` than the running code expects is never lowered; administrators see a notice. Releases up to 1.1.0 do not have this guard and set the version back when they run on a newer database.
- **Rollback:** take a database backup before every update. To go back, install the previous release ZIP over the current one – never delete the plugin for it: deleting runs `uninstall.php` (with "Delete data on uninstall" the tables dropped). Coupons suspended by a deactivation stay drafts under 1.1.0 and older; the next update publishes them again on its first request, also without an activation. It keeps working on the newer tables (new columns and tables are ignored), but it counts promotion uses and claims only from its own storage, so usage limits can be exceeded while it runs; uses it records are moved again by the next update. For a clean rollback restore the backup together with the previous release.
- CI job `upgrade` installs a previous version with data, updates to the current ZIP, verifies data and schema, rolls back, writes data with the old version and updates again – from the last release (newest `vX.Y.Z` tag; until the first tag the 1.1.0 release, schema 1.3.0 – the schema 1.1.0 shops actually run) to the current ZIP, and from the schema right before the last schema change. There is one ZIP: the free core (docs/FREE-PREMIUM.md); license data of pre-release licensed builds is removed on the update. Rollback steps for shops: RELEASE.md → Rollback.

## Caching and page builders

- The redeem form needs no nonce for guests (rate limited, like the balance check), so an empty guest form – also in a header/footer template part, widget or builder layout – keeps the page cacheable. A page with the form is kept out of caches (`DONOTCACHEPAGE` plus no-cache headers, decided on `template_redirect` before any output) only while it shows something of this visitor: a code from a redeem link (`?vcap_code=` or the session), a pending step of a connector, the result of a sent form, or the nonce of a logged-in customer. A balance result and "My vouchers" set `DONOTCACHEPAGE` wherever they render; action `vcap_no_cache` fires for cache plugins that need their own call.
- Redeem links (`?vcap_code=`): the code moves into the WooCommerce session or, for a visitor without one, into the short-lived HTTP-only cookie `vcap_redeem_code` (30 minutes), and the visitor is redirected to the URL without the code but with a random query argument `vcap_nc=…` – a URL no page cache has a copy of, so the first view is always built for this visitor (cache MISS). The form shows the code only where the no-cache headers could still be sent (before any output, `template_redirect`) or on the configured redeem page itself; a form that only a theme file or builder renders after the headers went out stays empty, so a proxy cache can never keep one visitor's code for everybody. Cache setup:
  - **WP Rocket:** nothing to do – the cookie is added to `rocket_cache_reject_cookies` (cached pages are not served while it is set).
  - **LiteSpeed Cache:** honours `DONOTCACHEPAGE` and the no-cache headers. Optionally add `vcap_redeem_code` under *Cache → Excludes → Do Not Cache Cookies*, so reloads of the redeem page also bypass the cache while the cookie lives.
  - **Varnish / nginx FastCGI cache:** honour `Cache-Control: no-cache, must-revalidate, max-age=0` (sent by WordPress' `nocache_headers()`); in a custom VCL also `return (pass)` when `req.http.Cookie ~ "vcap_redeem_code"`, and keep query strings in the cache key (do not strip unknown arguments like `vcap_nc`).
  - **Cloudflare (APO or a "Cache Everything" rule):** add a bypass for the cookie `vcap_redeem_code` (APO: "Bypass cache on cookie"; Cache Rules: *Cookie contains vcap_redeem_code → Bypass cache*) and do not ignore the query string for HTML pages.
- Where the redeem form is: the configured redeem page, the page content (also inside synced patterns, nested), Elementor data of the page, and site-wide block templates/template parts, Elementor/Divi/Beaver Builder layouts and block/text widgets (looked up once a day and after saving such content). Not detected: forms that only a theme file or another builder renders – filters `vcap_page_has_redeem_form` / `vcap_site_has_redeem_form` return `true` for them.
- Balance check and product preview need no nonce for guests (read-only, rate limited), so cached product and balance pages keep working. A balance check is evaluated once per request; its result shows in the form that was sent.
- Product pages are the same for every visitor: country-specific redemption conditions are all listed, each under its billing country, instead of the variant of the visitor's (geolocated) country.
- Editor and builder previews (block editor, Elementor, Divi, Beaver Builder, Bricks, Oxygen, Customizer, post preview; filter `vcap_is_editor_preview`) show example notes instead of an editor's own vouchers, store credit or referral code and change nothing; the block preview in the editor is not clickable.
- Shortcode and block output is wrapped in `div.woocommerce` (WooCommerce button, notice and table styles on any page); buttons get `wp-element-button` in block themes. Block output escapes `[`/`]`, so text from visitors cannot run shortcodes.
- Blocks are registered from `assets/blocks/*/block.json` (apiVersion 3; the storefront style is enqueued only where a block renders, `editorStyle` in the editor). Storefront CSS/JS are enqueued where the output renders (shortcodes, blocks, voucher fields), so `[product_page]` and page-builder add-to-cart widgets work; on shop pages and product grids with voucher products the script is printed as well, for quick views loaded by AJAX.

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

## PDF fonts, scripts and images

- **Fonts:** the PDFs use the bundled DejaVu fonts (Latin, Greek, Cyrillic, many symbols) and the PDF core fonts. Characters missing in the chosen font come from DejaVu Sans. There is **no Chinese, Japanese or Korean font** and no colour emoji: such characters have no glyph.
- **Own and fallback fonts:** filter `vcap_pdf_fonts` registers TTF files – `[ 'Family' => [ 'normal' => '/path/Font.ttf', 'bold' => …, 'italic' => …, 'bold_italic' => … ] ]` (inside the WordPress folder, so dompdf may read them and the designer can load them as web fonts). Families named with the filter `vcap_pdf_fallback_fonts` (e.g. `[ 'Noto Sans SC' ]`, registered with `vcap_pdf_fonts`) are tried after DejaVu Sans for every text, so Chinese names print with e.g. Noto Sans CJK. Large CJK fonts make each PDF bigger and slower.
- **Warnings:** a customer who enters characters without a glyph (or right-to-left text) gets a notice when adding the voucher to the cart. In the PDF, emoji without a glyph are left out; other missing characters print as empty boxes.
- **Right-to-left scripts:** dompdf has no bidirectional layout and no Arabic shaping. Hebrew and Arabic print left to right, Arabic letters unjoined. Not supported for voucher texts.
- **Images:** JPEG, PNG and GIF (WebP only where PHP's GD has WebP support); AVIF, HEIC, SVG and other formats cannot be chosen in the designer and are left out of the PDF. Images are read from the local uploads folder. With media offloaded to a cloud storage or CDN (WP Offload Media, WP-Stateless), name the image hosts with the filter `vcap_pdf_remote_hosts` (`[ 'cdn.example.com' ]`): images whose local file is missing are then downloaded from those hosts only (`wp_safe_remote_get`, cached for an hour). A missing image is logged (WooCommerce → Status → Logs, source `voucher-captain`), shown in the designer, and Site Health checks the template images.
- **QR codes and barcodes** are drawn as vector graphics (SVG) and need no GD. EAN-13 and Code 39 values that the type cannot carry (generated voucher codes are longer than 13 digits) are printed as Code 128. QR codes get a white quiet zone of 4 modules and white light modules, barcodes 10 white modules left and right; the opacity of these elements is ignored. The designer warns about QR modules below 0.33 mm, bar modules below 0.19 mm and a contrast below 4.5:1 to white. The browser camera scanner reads Code 128 only where the browser has `BarcodeDetector` (Chrome/Android); on iOS and in Firefox it reads QR codes only.
- **Large PNG images:** transparent PNGs are composed pixel by pixel by dompdf (seconds per PDF). The renderer stores a copy reduced to 200 dpi of the box size (PNGs without transparency as JPEG) in the private folder (`img/`) and uses it for every later PDF; without GD the original is used. The designer warns about transparent PNGs over 500 KB. Customer previews of the same personalisation are cached for 10 minutes.
- **Print shops:** the page option *Bleed (mm)* (0–10) makes the PDF larger by this margin on every side; the background colour and image and images, shapes and lines touching a page edge reach into it, *Crop marks* adds marks at the corners (from 2 mm bleed). The designer shows a dashed 5 mm safety line. *Vouchers → bulk action "Print on A4 sheets"* sets the selected vouchers (at most 100 of one page size) side by side on A4 (`PdfRenderer::render_many( $vouchers, true )`).

## Private folder and web servers (nginx)

Voucher PDFs, fonts and temp files are kept in `wp-content/uploads/vcap-<random>` (the name is stored in the option `vcap_private_dir`; the folder `vcap` of earlier versions is renamed on update). Temp PDFs get a random 24-character prefix and are attached to mails under their configured name (`wp_mail()` attachment keys, WordPress 6.2+); the daily maintenance deletes temp PDFs, previews, downloaded images (`img-*`), unfinished writes (`*.part`) and dompdf's temp images (`ca_dompdf_img_*`) older than one hour.

Apache reads the `.htaccess` files of the folder (`Require all denied`, with the 2.2 syntax as fallback). **nginx ignores them**: block the folder in the server configuration, e.g.

```nginx
location ~* ^/wp-content/uploads/vcap(-[a-z0-9]+)?/ {
    deny all;
    return 404;
}
```

*Tools → Site Health* writes a test file into the folder and requests it over HTTP: if it can be downloaded (status 200), the check "The private voucher folder can be opened from the web" is critical.

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

## WooCommerce Analytics (reports)

Voucher Captain has no report of its own in WooCommerce → Analytics. What Analytics shows: orders that buy voucher
products count as normal product revenue (the voucher product appears under Products), and vouchers redeemed as
WooCommerce coupons appear under Coupons with the discount they gave. Vouchers settled as a payment after tax
(setting "Multi-purpose vouchers as payment") are not coupons and are not listed there; neither are vouchers issued
without an order, imported cards, store credit or redemptions at the counter or through the API. The voucher figures
therefore live in Vouchers → Reports: "Overview" (sold, issued without order, redeemed, open, expired, best-selling
products per period), "Outstanding value" (liability at a date), "Redemption journal" (every booking) and the
campaign and till reports, each exportable as CSV. See TAX.md for how sale and redemption are booked.

## Physical gift cards

A voucher product with "Physical card" is shipped like any product (leave "Virtual" unticked). Its vouchers take the
code from the product's imported batch (Vouchers → Import codes: the codes printed on the cards), are not emailed and
stay inactive until the order reaches "Completed" (filter `vcap_physical_activation_statuses`, e.g. a "shipped" status
of a shipping plugin). Print data for card printers (code lists with layout, barcodes) is not part of the free core.

## Checkout and product fields

Voucher fields use WooCommerce product add-to-cart hooks and cart/order-item data. The integration suite checks field definitions, cart data persistence, order-item persistence, Store API reservation, paid-order commit, payment retry behavior, and both HPOS and legacy order CRUD. Voucher Captain declares HPOS and Cart/Checkout Blocks compatibility. Block-based add-to-cart requests (Store API `cart/add-item`) accept the voucher fields as `vcap_fields` (object of field key ⇒ value); they are validated like the product form (required fields, email/date/number/select rules) and refused with the error code `vcap_invalid_voucher_fields`. A customer-chosen amount (gift cards and top-ups with "Customer chooses the amount") is sent as `vcap_amount` (typed amount) or `vcap_amount_preset` (one of the suggested amounts); it is checked against minimum, maximum and step and refused with `400 vcap_invalid_amount` (M12-20). Store API requests never read the amount from `$_REQUEST`. A top-up adds its card code as `vcap_topup_code`, e.g. `{"id": 123, "quantity": 1, "vcap_topup_code": "GIFT-…", "vcap_amount": "40"}`. Products with required or custom fields link to the product page instead of adding to the cart from product lists.

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
