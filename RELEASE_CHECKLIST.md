# Release acceptance checklist

## Automated checks

Each line names the CI job (`.github/workflows/plugin-smoke.yml`) that proves it; tick it when that job is green on
the release commit. The `Release` workflow enforces this: its job `ci-green` refuses to build a tag whose commit is
not on `main` or has no push run of `Plugin smoke tests` in which every blocking job succeeded (RELEASE.md → Cutting a
release).

- [ ] Smoke tests and PHP lint on PHP 8.1 and 8.4; no licensing or update code in the shipped files (`tests/license-guard.php`, docs/FREE-PREMIUM.md); release ZIP reproducible (two builds, same SHA-256) – `smoke`.
- [ ] Integration suites on MySQL 8.4 and MariaDB 11.4, each with HPOS and legacy order storage, plus parallel checkout – `wordpress-integration`.
- [ ] Release ZIP installs, activates and updates in place (tables and voucher rows kept) on WordPress 7.1.4 / WooCommerce 11.2.2 – `wordpress-integration`.
- [ ] Default uninstall keeps plugin data; `vcap_delete_on_uninstall=yes` removes the tables – `wordpress-integration`.
- [ ] Oldest supported platform (WordPress 6.4, WooCommerce 8.9, PHP 8.1, no Yoast SEO) – `minimum-versions`.
- [ ] Multisite: network activation, a site added later, per-site uninstall – `multisite`.
- [ ] Voucher Captain + WP Pass Captain (pinned release v1.4.0, `main` – both must run – and the work branch, which may be skipped with a warning): gift cards in the ticket checkout, ticket voucher exchange, refunds, shared scanner – `pass-captain`.
- [ ] Updates with data from the last release and from the previous schema to the current ZIP, each with rollback – `upgrade`.
- [ ] Browser: product page, classic and block checkout (block: gift card paying after tax with payment row, balance and total, single-use voucher warning in its live region, store credit link), customer account, shortcodes, admin with Yoast SEO, axe accessibility and keyboard checks – `browser-e2e`.
- [ ] Real gateways and currency switchers from wordpress.org next to Voucher Captain (Stripe, PayPal Payments, WOOCS, CURCY, WCML): purchase, voucher payment, refund, currency conversion, and "Multi-purpose vouchers as payment" (partial and full payment, foreign currency) – `ecosystem`.
- [ ] Composer audit, scoped PHPCS (WordPress and WooCommerce standards) and PHPStan on the API and contracts; the release ZIP has a licence file for every bundled package, `THIRD-PARTY-NOTICES.txt` and a valid SBOM – `quality` (weekly also `dependency-audit` in `scheduled.yml`).
- [ ] No `WordPress.Security.*` finding and no growth of the PHPCS/PHPStan findings on the whole plugin – `quality-wide` (blocking); WordPress Plugin Check without errors (blocking) – `plugin-check`; review its warnings in the job summary.
- [ ] The draft has a CycloneDX SBOM of the ZIP (`voucher-captain.cdx.json`); the weekly `dependency-audit` (`scheduled.yml`: Composer, npm, OSV for jsQR and other bundled libraries) is green.
- [ ] Newest PHP/WordPress/WooCommerce and WordPress nightly – `forward-compatibility` in `scheduled.yml` (informational; investigate failures before release).

## Manual release checks

- [ ] Release smoke green before the draft is published: the draft's ZIP installed over the previous release, every feature available, no license page, no `Update URI` header (workflow `release-smoke.yml`, RELEASE.md).
- [ ] Before the first wordpress.org submission (A7b, owner): check the name "Voucher Captain", the slug and text domain `voucher-captain` – search wordpress.org/plugins for the name and the slug (`https://wordpress.org/plugins/voucher-captain/` must not exist, also no closed plugin of that slug), look for names that start like ours or contain a third-party trademark (the name must not begin with "WooCommerce" or "WordPress"; "for WooCommerce" at the end is allowed), compare with the trademark registers (DPMA, EUIPO, USPTO) for "Voucher Captain", and confirm that `Contributors: hastla007` in readme.txt is the wordpress.org account that submits. The slug is fixed by the review team at approval and cannot be changed later.
- [ ] The vendor website publishes `/.well-known/security.txt` (RFC 9116) with the reporting channel of SECURITY.md, and SECURITY.md → Supported versions is current.
- [ ] Complete a real gateway payment, redirect return, webhook retry, and refund on the supported gateway set. Plugin-level flows with Stripe and PayPal Payments installed are automated (CI job `ecosystem`); this check covers the provider's real sandbox/live account.
- [ ] With WooCommerce Subscriptions (commercial, not in CI) installed: a renewal issues vouchers only when *Subscription renewals* is on, and renewal orders carry no `_vcap_*` meta.
- [ ] Check Yoast SEO admin, checkout, and customer account pages in a browser.
- [ ] Review keyboard and screen-reader flows for voucher purchase, balance check, account downloads, and scanner actions.
- [ ] Approve the customer email texts in `EMAILS.md` (generated, EN/DE/AT; CI job `smoke` fails when it is out of date). Translation coverage and placeholders are checked automatically (`tests/run.php` → Translations).
- [ ] Legal review per selling country with `LEGAL_TEXTS.md` (generated: shop settings to check, statutory validity per country, fixed plugin texts on validity, transferability, residual value, refunds; CI job `smoke` keeps it current).
- [ ] Verify translations and customer-facing voucher expiry, transferability, residual value, and redemption terms for the store's legal policy.
- [ ] Have the merchant's tax/accounting adviser approve voucher tax and refund treatment for each voucher type.

## Cross-plugin release gate

| Installation | Required check | Status |
| --- | --- | --- |
| Voucher Captain alone | Activate, issue, deliver, redeem, refund, export/erase privacy data, uninstall | CI (`wordpress-integration`, `browser-e2e`); real gateway checks remain manual |
| WP Pass Captain alone | Activate and complete its normal ticket lifecycle | Run in the WP Pass Captain repository |
| Both plugins | Install/activate together; pay with a gift card in the ticket checkout; exchange a ticket voucher; refunds and cancellations give vouchers back; shared scanner | CI `pass-captain` against Pass Captain `main` and the work branch |

Do not advertise a Learnboating connector as supported until its external contract and the combined installation pass.
- [ ] Screen reader listening test (NVDA + VoiceOver) with the protocol in `ACCESSIBILITY.md`; the accessibility tree is checked automatically in `browser-e2e`.

## Plugin Check warnings (state 16.11.2026: 0 errors, 199 warnings)

Checked one by one before the WordPress.org submission (A9). None is a security defect; they are listed so the review answers are ready.

| Count | Code | Assessment |
| --- | --- | --- |
| 125 → 41 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | Table names now use the identifier placeholder `%i` (WordPress ≥ 6.2, minimum is 6.4; 86 statements). The 41 left are placeholder lists built at run time (`IN (%s, %s …)`, `VALUES` groups), SQL fragments from constants (CASE expressions, `FOR UPDATE`, column lists) and transaction statements without a table – values always go through placeholders. |
| 21 → 12 | `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | Same cause (table names, placeholder lists built from `array_fill()`), each line carries a `phpcs:ignore` with the reason. |
| 18 | `PrefixAllGlobals.NonPrefixedVariableFound` | Variables in `templates/`: their names are the documented template API for theme overrides (`{theme}/voucher-captain/`), renaming would break overrides. |
| 15 + 2 | `PrefixAllGlobals.NonPrefixedHooknameFound` / `DynamicHooknameFound` | WooCommerce and WordPress hooks the plugin fires on purpose for compatibility (e.g. `woocommerce_*` in templates); own hooks all start with `vcap_`. |
| 6 + 2 | `PreparedSQLPlaceholders.ReplacementsWrongNumber` / `UnfinishedPrepare` | Placeholder lists built at run time (`IN (%s, %s …)`), checked by the integration tests. |
| 6 | `DirectDatabaseQuery.*` | Own tables without a WordPress API; caching is done in the repository classes where it matters. |
| 1 | `load_plugin_textdomainFound` | The plugin ships its own de_DE/de_AT translations; harmless once translate.wordpress.org serves them. |
| 1 each | `PostNotIn_exclude`, `DiscouragedFunctions`, `NonPrefixedConstantFound` | Admin-only lists with a small exclusion set; reviewed, no change needed. |

## Release probe (16.11.2026, release ZIP of the PR state)

- Upgrade from the released 1.1.0 (schema 1.3.0, built from the release commit `1fb789d`) with fixture data to the current ZIP: data verified; rollback to 1.1.0 still reads the vouchers; forward again keeps the promotion uses (`tests/upgrade-integration.php`, also in CI `upgrade`).
- Uninstall with "Delete data on uninstall" off: all 10 tables stay. With it on: no `vcap_*` table, no `vcap_*` option and no request/template/field-group post left.
- Fresh install, purchase, delivery, redemption and refunds of the ZIP: all 49 integration suites (HPOS and classic) and `browser-e2e` green.

## Load test (16.11.2026, 20 008 vouchers, 51 348 ledger entries, MariaDB 10.11, HPOS)

| Operation | Time | Queries |
| --- | --- | --- |
| Admin list page 1 / page 50 with status filter | 3 ms / 15 ms | 2 |
| Search by code end / by email | 65 ms / 1 ms | 3 / 2 |
| Outstanding value at a date (uncached) | 251 ms | 5 |
| Liability CSV, all 20 000 rows | 579 ms | 244 (batches) |
| Reports overview, 365 days / journal, 30 days | 77 ms / 40 ms | 12 / 19 |
| Code lookup | 0.7 ms | 1 |
| Site Health ledger check | 120 ms | 15 |
| Maintenance "expire overdue" catching up ~1 400 overdue vouchers at once | 2.8 s | 4 232 (batches within the step's time budget; daily only a few) |

Times measured with a bulk-inserted data set (seeding and timing script kept outside the repository); query plans were not analysed separately.
