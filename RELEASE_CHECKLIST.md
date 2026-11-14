# Release acceptance checklist

## Automated checks

Each line names the CI job (`.github/workflows/plugin-smoke.yml`) that proves it; tick it when that job is green on
the release commit. The `Release` workflow enforces this: its job `ci-green` refuses to build a tag whose commit is
not on `main` or has no push run of `Plugin smoke tests` in which every blocking job succeeded (RELEASE.md → Cutting a
release).

- [ ] Smoke tests and PHP lint on PHP 8.1 and 8.4; release ZIP reproducible (two builds, same SHA-256) – `smoke`.
- [ ] Integration suites on MySQL 8.4 and MariaDB 11.4, each with HPOS and legacy order storage, plus parallel checkout – `wordpress-integration`.
- [ ] Release ZIP installs, activates and updates in place (tables and voucher rows kept) on WordPress 7.1.4 / WooCommerce 11.2.2 – `wordpress-integration`.
- [ ] Default uninstall keeps plugin data; `vcap_delete_on_uninstall=yes` removes the tables – `wordpress-integration`.
- [ ] Oldest supported platform (WordPress 6.4, WooCommerce 8.9, PHP 8.1, no Yoast SEO) – `minimum-versions`.
- [ ] Multisite: network activation, a site added later, per-site uninstall – `multisite`.
- [ ] Voucher Captain + WP Pass Captain (pinned release v1.4.0, `main` – both must run – and the work branch, which may be skipped with a warning): gift cards in the ticket checkout, ticket voucher exchange, refunds, shared scanner – `pass-captain`.
- [ ] Updates with data from the last release to the free and the premium ZIP, from the previous schema, and free → premium of the same version, each with rollback – `upgrade`.
- [ ] Browser: product page, classic and block checkout (block: gift card paying after tax with payment row, balance and total, single-use voucher warning in its live region, store credit link), customer account, shortcodes, admin with Yoast SEO, axe accessibility and keyboard checks – `browser-e2e`.
- [ ] Real gateways and currency switchers from wordpress.org next to Voucher Captain (Stripe, PayPal Payments, WOOCS, CURCY, WCML): purchase, voucher payment, refund, currency conversion, and "Multi-purpose vouchers as payment" (partial and full payment, foreign currency) – `ecosystem`.
- [ ] Licensing against a real WP License Captain server: activation, premium feature locks, offline cache, install of the premium ZIP from the release storage, update through the download grant, wrong SHA-256 refused – `license-acceptance`.
- [ ] Composer audit, scoped PHPCS (WordPress and WooCommerce standards) and PHPStan on the API and contracts; the free and the premium release ZIP each have a licence file for every bundled package, `THIRD-PARTY-NOTICES.txt` and a valid SBOM – `quality` (weekly also `dependency-audit` in `scheduled.yml`).
- [ ] No `WordPress.Security.*` finding and no growth of the PHPCS/PHPStan findings on the whole plugin – `quality-wide` (blocking); review the report-only WordPress Plugin Check – `plugin-check` (`ci-green` prints its failures as a warning).
- [ ] The draft has a CycloneDX SBOM per ZIP (`*.cdx.json`); the weekly `dependency-audit` (`scheduled.yml`: Composer, npm, OSV for jsQR and other bundled libraries) is green.
- [ ] Newest PHP/WordPress/WooCommerce and WordPress nightly – `forward-compatibility` in `scheduled.yml` (informational; investigate failures before release).

## Manual release checks

- [ ] Minimum licence server version: the production licence server runs WP License Captain with Ed25519 response signatures and `GET /wplc/v1/signing-keys` (commit 150fb17 or newer – the commit pinned in `plugin-smoke.yml` and `release.yml`, merged into WP License Captain's default branch and deployed; the release workflow refuses an unmerged pin); `wp wplc signing-key` there shows the key in the repository variable `VCAP_LICENSE_PUBLIC_KEY` (the release workflow checks it again).
- [ ] Release smoke against the production licence server: stage `activate` green before anything is published, stage `update` green right after the upload to the release storage (workflow `release-smoke.yml`, RELEASE.md steps 5–6; on failure RELEASE.md → Emergency).
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
