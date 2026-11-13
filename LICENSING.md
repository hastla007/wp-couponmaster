# Licensing (WP License Captain)

Voucher Captain integrates the signed WP License Captain client (PRs #10–#13, merged into the work branch).

## Editions

`bin/build.sh` writes the edition into `voucher-captain.php`. The premium features are listed in
`config/premium-features.php` (entitlement keys `voucher.{key}`): `promotions`, `referrals`, `topup`, `reports`,
`passcaptain`; their labels are translated at runtime (`Entitlements::label()`).

| Edition | Build | Premium features | License page / updater |
|---|---|---|---|
| **free** | `VCAP_EDITION=free bin/build.sh` → `define( 'VCAP_EDITION', 'free' )` | permanently locked for new configuration, new sales and customer screens; hint "included in Voucher Captain Premium" | none; no contact with any license server |
| **premium** | `VCAP_EDITION=premium VCAP_LICENSE_SERVER=https://licenses.example.com bin/build.sh` → `VCAP_EDITION 'premium'`, `VCAP_LICENSE_REQUIRED`, `VCAP_LICENSE_SERVER`, header `Update URI` | on while the license grants them (fail-closed); hint "needs an active license" + link | Vouchers → License; updates only from the license server |
| **development** | no `VCAP_EDITION` (git checkout, CI integration jobs) | all on | none |

- `VCAP_LICENSE_SERVER` (premium): `https://host[:port]`, no path. Default placeholder `https://licenses.example.com`
  (the build warns) – release builds must set the production licence server. `VCAP_UPDATE_URI` (optional) sets the
  `Update URI` header, default `<server>/voucher-captain`; WordPress then never offers a WordPress.org plugin of the
  same name as an update.
- A tag build (`GITHUB_REF_TYPE=tag`) without `VCAP_EDITION` fails: release ZIPs are always free or premium.
- No filter unlocks anything in the premium build: `vcap_license_required` is only read in development checkouts
  (tests use it to lock, it cannot unlock), `vcap_premium_feature_allowed` no longer exists, the feature list is not
  filterable, the licence server option is ignored when `VCAP_LICENSE_SERVER` is set.

## What a locked feature stops – and what keeps working

A lock (free edition, premium without an active license, snapshot older than 14 days) stops only what is new. What was
sold earlier is a legal obligation and keeps being processed:

| Feature | Locked: stopped | Always registered (lifecycle) |
|---|---|---|
| topup | product setting, code field, add to cart; top-up products are not purchasable | booking of paid top-up lines onto the card (never a new card), reversal on cancellation/refund, ledger labels |
| referrals | My Account block, `[vcap_referral]`/block, settings; referral codes are refused | rewards for orders that already used a code, withdrawal on cancellation/refund, retry hook |
| promotions | no promotion added to carts, no new bonus voucher, settings read-only, notice on the promotions screen | coupon type `vcap_campaign`/virtual coupon (orders keep their discount when recalculated), usage holds/records/reverts, release of abandoned holds, bonus withdrawal |
| reports | Reports page shows the hint, CSV export answers with the hint | – |
| passcaptain | ticket-voucher product settings, capacity checks, new ticket-voucher sales (not purchasable), shared scanner | voucher provider for the Pass Captain checkout (quote/reserve/commit/release/refund), channel `passcaptain`, ticket exchange (redeem page, admin, account), revocation, emails/placeholders |

Tests: `tests/license-lifecycle-integration.php` (development build with the test lock, free ZIP, premium ZIP before
activation; Pass Captain part in the `pass-captain` job), `tests/run.php` → Editions.

## Client

| Part | Behaviour | Tests |
|---|---|---|
| Activation (Vouchers → License, premium only) | License key sent once and never stored; the licence server is fixed and shown read-only. Stored (not autoloaded): installation id, installation secret, activation id. Answers must carry a valid HMAC signature (installation secret), otherwise nothing is stored. Deactivation asks for confirmation and removes the local credential after the server confirms. The page shows status, paid-through date (site time zone), websites and the rights as a translated list. | `tests/run.php` → License |
| Fail-closed | Denied when: not activated, no snapshot, snapshot signature wrong, snapshot older than its expiry (max. 14 days), license status not `active`, right missing/false/0, key of another product. | License |
| Offline cache | Page loads never call the server: the gate reads the HMAC-signed snapshot. It is refreshed twice a day (`vcap_license_refresh`, premium only) and on the license page. Network failures and 5xx keep the snapshot until it expires; 4xx (e.g. revoked) and an inactive license delete it. | License |
| Transport | `wp_safe_remote_request()`/`wp_safe_remote_get()`: no requests to internal addresses; only the licence server fixed at build time may resolve to one (`http_request_host_is_external` for exactly that host, e.g. the CI server on 127.0.0.1). | License |
| Premium updates (`PremiumUpdater`) | Offered also from WP-Cron (no user check); answer cached 12 h (failure 1 h, timeout 5 s; key: version, server, activation, license status; "Check again" bypasses it). Only one-time download grants of the licence server are offered; any other offer for the plugin is removed. Download via the grant (302) to an HTTPS host on the allow-list (licence server host, filter `wplc_client_update_hosts`, never IP addresses); SHA-256 of the ZIP must match before WordPress installs it. "View details" shows version, requirements and release notes from the licence server. | `tests/run.php` → Premium updates |
| Support | Site Health → Info → "Voucher Captain": edition, license status, snapshot age, locked features, DB version, schema error, Pass Captain version, payment-after-tax. License and update errors go to the WooCommerce log, source `voucher-captain`. | – |

**Open (server side):** WP License Captain signs answers with the HMAC of the installation secret the client generated
itself; it has no asymmetric response signing (e.g. Ed25519 with a public key in the build). Whoever can write the
options can therefore forge a snapshot. Needs server work in `hastla007/wp-license` (sign envelopes and update
manifests with a private key, publish the public key) before the client can verify it.

## Acceptance against a real licence server (CI job `license-acceptance`)

On every push the job runs WP License Captain (`hastla007/wp-license`, pinned commit) on WordPress + WooCommerce over
HTTPS as `https://licenses.test` (own test CA) and a second shop site (`tests/license-acceptance.php`). The premium
ZIPs are built with `VCAP_LICENSE_SERVER=https://licenses.test`.

1. Release storage: two premium releases built by `bin/build.sh` (the current version and 99.0.0), manifests made by
   the server's `tools/create-release-manifest.php`, announced by `tools/license-server/wplc-release-registry.php`.
2. New installation of the current release downloaded from the storage, SHA-256 checked against the manifest.
3. Before activation every premium feature is locked, the server and `Update URI` are fixed, no filter unlocks; the
   lifecycle test runs on the locked premium build (refunds reverse top-ups, rewards, bonus vouchers, promotion holds).
4. A paid WooCommerce order on the server issues the license; a wrong key is refused; activation with the real key
   stores only installation id, secret and activation id (not autoloaded, key stored nowhere).
5. Plan rights unlock the features, a right set to `false` stays locked; the next request registers the features
   from the snapshot without calling the server.
6. Server unreachable (TLS endpoint stopped): the signed snapshot keeps the rights.
7. License suspended on the server: locked at the next refresh, snapshot deleted; reactivated: unlocked again.
8. Update: offered without a current user (cron) with a one-time grant and kept from the cache at the next refresh;
   a manifest with a wrong SHA-256 stops the installation (`wplc_checksum_mismatch`, version unchanged); with the real
   SHA-256 99.0.0 installs and keeps activation/rights.
9. Deactivation removes credentials and snapshot and locks the features.

The free ZIP is checked in the `wordpress-integration` job (`VCAP_EDITION=free`, lifecycle test in mode `free`).

Still a live-operation task, not code: the production licence server with its storage host and the real catalog
(plans and rights per SKU) – RELEASE.md step 6.
