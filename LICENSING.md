# Licensing (WP License Captain)

Voucher Captain integrates the signed WP License Captain client (PRs #10–#13, merged into the work branch).

## Editions

`bin/build.sh` writes the edition into `voucher-captain.php`. The premium features are listed in
`config/premium-features.php` (entitlement keys `voucher.{key}`): `promotions`, `referrals`, `topup`, `reports`,
`passcaptain`; their labels are translated at runtime (`Entitlements::label()`).

| Edition | Build | Premium features | License page / updater |
|---|---|---|---|
| **free** | `VCAP_EDITION=free bin/build.sh` → `define( 'VCAP_EDITION', 'free' )` | permanently locked for new configuration, new sales and customer screens; hint "included in Voucher Captain Premium" | none; no contact with any license server; header `Update URI: false` (`VCAP_FREE_UPDATE_URI` sets a vendor URL instead), so no WordPress.org plugin of the same name is offered as an update |
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
| reports | campaign and partner report (tab "Campaigns and codes" shows the hint, its CSV export answers with the hint) | outstanding value at a date, redemption journal, Pass Captain reconciliation and their CSV exports – the accounting record of sold vouchers (year-end closing) is never locked |
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

**Server signature (Ed25519):** besides the HMAC (installation secret – it protects transport and the cache, but the
shop knows the secret), every answer of WP License Captain carries `signature_ed25519` over
`wplc-response-v1\n{installation UUID}\n{JSON of data}` (server branch `claude/youthful-bardeen-7ejd7a`, 150fb17 –
must be merged and deployed on the production licence server before a release, RELEASE.md). The premium build pins
the server's public key set (`VCAP_LICENSE_PUBLIC_KEY`, from `wp wplc signing-key --field=public_key`; `bin/build.sh`
refuses a tag build without it and checks every key: base64, 32 bytes). The client then accepts answers and offline
snapshots only with a valid server signature for exactly this installation – a server of one's own, written options or
a snapshot copied from another shop unlock nothing (`LicenseClient::server_signature_match()`, test vectors in
`tests/run.php`, forged-snapshot check in `license-acceptance`). A malformed key in the set fails closed (nothing
verifies). The release workflow compares the pinned set with the production server's public route
`GET /wp-json/wplc/v1/signing-keys` and stops when the server's current key is not pinned (`bin/release-gate.sh keys`).

### Key rotation

The update channel needs a valid server signature too, so a server key that an installed build does not pin locks the
shop out of its rights **and** of the update that would fix it. Therefore the key is changed only with overlap:

- **Key set.** `VCAP_LICENSE_PUBLIC_KEY` may hold several keys separated by commas or whitespace – normally the
  current and the next key. The server names its key in `key_id` (first 16 hex characters of SHA-256 of the raw
  public key); the client tries the pinned key with that id first, then every other pinned key. While the server has a
  next key it signs every answer with both keys (`signatures: [{key_id, signature_ed25519}, …]`, current first, plus
  the old single fields for older builds); any signature made with a pinned key is enough. The offline snapshot keeps
  the signature that matched and its `key_id`.
- **Procedure.**
  1. On the licence server: `wp wplc signing-key --next --field=public_key` (or a seed in
     `WPLC_RESPONSE_SIGNING_KEY_NEXT`, recommended for production). Keep an offline backup of the secret.
  2. Set the repository variable `VCAP_LICENSE_PUBLIC_KEY` to `<current>,<next>` and release (`bin/release-gate.sh
     keys` checks that the current key is pinned and warns when the server's next key is not).
  3. Wait until the customers run that release (licence server statistics of product versions; the old build stops
     working at step 4).
  4. `wp wplc signing-key --promote` (with constants: move the value of `WPLC_RESPONSE_SIGNING_KEY_NEXT` into
     `WPLC_RESPONSE_SIGNING_KEY` and remove the former). Then set the variable to the new key alone, or already to
     `<new current>,<new next>` for the following rotation.
- **Emergency (key lost or compromised).** Keep a pinned next key in every release: then promote it at once – shops
  on a build that pins it continue without an update; the compromised key is gone from the server (and from the
  pinned set of the next release). Without a pinned spare, `wp wplc signing-key --rotate` (or a new constant) plus a
  release with the new key is the only way; shops whose build pins only the old key are locked and receive no update
  until the plugin is reinstalled manually with the new premium ZIP (notify the customers). A compromised key must be
  replaced even so – whoever holds it can unlock any shop that pins it.

**Uninstall:** with "Delete data on uninstall" the site's activation is released on the licence server first
(best effort, 5 s; `uninstall.php`, `license-acceptance` → `shop-uninstall`), then the credentials are deleted.

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
