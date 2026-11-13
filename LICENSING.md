# Licensing (WP License Captain)

Voucher Captain integrates the signed WP License Captain client (PRs #10–#13, merged into the work branch).

| Part | Behaviour | Tests |
|---|---|---|
| Activation (Settings → License) | HTTPS server URL and license key; the key is sent once and never stored. Stored (not autoloaded): installation id, installation secret, activation id. Answers must carry a valid HMAC signature (installation secret), otherwise nothing is stored. Deactivation removes the local credential after the server confirms. | `tests/run.php` → License |
| Premium gate (`VCAP\Licensing\Entitlements`) | **Enforced only in the premium edition** (`VCAP_LICENSE_REQUIRED`, set by `VCAP_EDITION=premium bin/build.sh`; filter `vcap_license_required`). Feature list in `config/premium-features.php` (entitlement keys `voucher.{key}`): promotions, referrals, topup, reports, passcaptain – **proposal, to be confirmed by the product owner**. Locked features are not registered; an admin notice links to the license page. | License |
| Fail-closed | Denied when: not activated, no snapshot, snapshot signature wrong, snapshot older than its expiry (max. 14 days), license status not `active`, right missing/false/0, key of another product. | License (14 checks) |
| Offline cache | Page loads never call the server: the gate reads the HMAC-signed snapshot. It is refreshed twice a day (`vcap_license_refresh`) and on the license page. Network failures and 5xx keep the snapshot until it expires; 4xx (e.g. revoked) and an inactive license delete it. | License |
| Premium updates (`PremiumUpdater`) | Update offer from the license server; download only via its one-time grant (302 redirect) to an HTTPS host on the allow-list (license server host, filter `wplc_client_update_hosts`, never IP addresses); SHA-256 of the ZIP must match before WordPress installs it. | `tests/run.php` → Premium updates |

## Acceptance against a real licence server (CI job `license-acceptance`)

On every push the job runs WP License Captain (`hastla007/wp-license`, pinned commit) on WordPress + WooCommerce over
HTTPS as `https://licenses.test` (own test CA) and a second shop site (`tests/license-acceptance.php`):

1. Release storage: two premium releases built by `bin/build.sh` (the current version and 99.0.0), manifests made by
   the server's `tools/create-release-manifest.php`, announced by `tools/license-server/wplc-release-registry.php`.
2. New installation of the current release downloaded from the storage, SHA-256 checked against the manifest.
3. Before activation every premium feature is locked and not registered.
4. A paid WooCommerce order on the server issues the license; a wrong key is refused; activation with the real key
   stores only installation id, secret and activation id (not autoloaded, key stored nowhere).
5. Plan rights unlock the features, a right set to `false` stays locked; the next request registers the features
   from the snapshot without calling the server.
6. Server unreachable (TLS endpoint stopped): the signed snapshot keeps the rights.
7. License suspended on the server: locked at the next refresh, snapshot deleted; reactivated: unlocked again.
8. Update: offered with a one-time grant; a manifest with a wrong SHA-256 stops the installation
   (`wplc_checksum_mismatch`, version unchanged); with the real SHA-256 99.0.0 installs and keeps activation/rights.
9. Deactivation removes credentials and snapshot and locks the features.

Still a live-operation task, not code: the production licence server with its storage host and the real catalog
(plans and rights per SKU, `config/premium-features.php` to be confirmed) – RELEASE.md step 6.
