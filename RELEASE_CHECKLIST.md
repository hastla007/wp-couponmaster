# Release acceptance checklist

## Automated checks

Each line names the CI job (`.github/workflows/plugin-smoke.yml`) that proves it; tick it when that job is green on
the release commit.

- [ ] Smoke tests and PHP lint on PHP 8.1 and 8.4; release ZIP reproducible (two builds, same SHA-256) – `smoke`.
- [ ] Integration suites on MySQL 8.4 and MariaDB 11.4, each with HPOS and legacy order storage, plus parallel checkout – `wordpress-integration`.
- [ ] Release ZIP installs, activates and updates in place (tables and voucher rows kept) on WordPress 7.1.4 / WooCommerce 11.2.2 – `wordpress-integration`.
- [ ] Default uninstall keeps plugin data; `vcap_delete_on_uninstall=yes` removes the tables – `wordpress-integration`.
- [ ] Oldest supported platform (WordPress 6.4, WooCommerce 8.9, PHP 8.1, no Yoast SEO) – `minimum-versions`.
- [ ] Multisite: network activation, a site added later, per-site uninstall – `multisite`.
- [ ] Voucher Captain + WP Pass Captain (released `main` and the work branch): gift cards in the ticket checkout, ticket voucher exchange, refunds, shared scanner – `pass-captain`.
- [ ] Browser: product page, classic and block checkout, customer account, shortcodes, admin with Yoast SEO, axe accessibility and keyboard checks – `browser-e2e`.
- [ ] Composer audit, scoped PHPCS (WordPress and WooCommerce standards) and PHPStan – `quality` (weekly also `dependency-audit` in `scheduled.yml`).
- [ ] Newest PHP/WordPress/WooCommerce and WordPress nightly – `forward-compatibility` in `scheduled.yml` (informational; investigate failures before release).

## Manual release checks

- [ ] Complete a real gateway payment, redirect return, webhook retry, and refund on the supported gateway set. Plugin-level flows with Stripe and PayPal Payments installed are automated (CI job `ecosystem`); this check covers the provider's real sandbox/live account.
- [ ] With WooCommerce Subscriptions (commercial, not in CI) installed: a renewal issues vouchers only when *Subscription renewals* is on, and renewal orders carry no `_vcap_*` meta.
- [ ] Check Yoast SEO admin, checkout, and customer account pages in a browser.
- [ ] Review keyboard and screen-reader flows for voucher purchase, balance check, account downloads, and scanner actions.
- [ ] Approve the customer email texts in `EMAILS.md` (generated, EN/DE/AT; CI job `smoke` fails when it is out of date). Translation coverage and placeholders are checked automatically (`tests/run.php` → Translations).
- [ ] Verify translations and customer-facing voucher expiry, transferability, residual value, and redemption terms for the store's legal policy.
- [ ] Have the merchant's tax/accounting adviser approve voucher tax and refund treatment for each voucher type.

## Cross-plugin release gate

| Installation | Required check | Status |
| --- | --- | --- |
| Voucher Captain alone | Activate, issue, deliver, redeem, refund, export/erase privacy data, uninstall | CI (`wordpress-integration`, `browser-e2e`); real gateway checks remain manual |
| WP Pass Captain alone | Activate and complete its normal ticket lifecycle | Run in the WP Pass Captain repository |
| Both plugins | Install/activate together; pay with a gift card in the ticket checkout; exchange a ticket voucher; refunds and cancellations give vouchers back; shared scanner | CI `pass-captain` against Pass Captain `main` and the work branch |

Do not advertise a Learnboating connector as supported until its external contract and the combined installation pass.
