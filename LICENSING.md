# Licensing (WP License Captain)

Voucher Captain integrates the signed WP License Captain client (PRs #10–#13, merged into the work branch).

| Part | Behaviour | Tests |
|---|---|---|
| Activation (Settings → License) | HTTPS server URL and license key; the key is sent once and never stored. Stored (not autoloaded): installation id, installation secret, activation id. Answers must carry a valid HMAC signature (installation secret), otherwise nothing is stored. Deactivation removes the local credential after the server confirms. | `tests/run.php` → License |
| Premium gate (`VCAP\Licensing\Entitlements`) | **Enforced only in the premium edition** (`VCAP_LICENSE_REQUIRED`, set by `VCAP_EDITION=premium bin/build.sh`; filter `vcap_license_required`). Feature list in `config/premium-features.php` (entitlement keys `voucher.{key}`): promotions, referrals, topup, reports, passcaptain – **proposal, to be confirmed by the product owner**. Locked features are not registered; an admin notice links to the license page. | License |
| Fail-closed | Denied when: not activated, no snapshot, snapshot signature wrong, snapshot older than its expiry (max. 14 days), license status not `active`, right missing/false/0, key of another product. | License (14 checks) |
| Offline cache | Page loads never call the server: the gate reads the HMAC-signed snapshot. It is refreshed twice a day (`vcap_license_refresh`) and on the license page. Network failures and 5xx keep the snapshot until it expires; 4xx (e.g. revoked) and an inactive license delete it. | License |
| Premium updates (`PremiumUpdater`) | Update offer from the license server; download only via its one-time grant (302 redirect) to an HTTPS host on the allow-list (license server host, filter `wplc_client_update_hosts`, never IP addresses); SHA-256 of the ZIP must match before WordPress installs it. | `tests/run.php` → Premium updates |

Still open (needs the real infrastructure): acceptance against a reachable license server with real release packages
(activation, gate, offline behaviour, update installation) – see TODO.md and RELEASE.md step 6.
