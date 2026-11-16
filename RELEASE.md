# Release process

## Versions

Semantic versioning: `MAJOR.MINOR.PATCH`.

- **PATCH** – fixes only; no new settings, no schema change, no change to the public API (`API.md`, `vcap/v1`).
- **MINOR** – new features, new settings or filters, additive schema changes (`VCAP_DB_VERSION` is bumped).
- **MAJOR** – anything that breaks a documented filter, the REST/PHP redemption contract or stored data.

The version lives in three places that must agree – `bin/build.sh` refuses to build otherwise:

1. plugin header `Version:` in `voucher-captain.php`,
2. `define( 'VCAP_VERSION', … )` in the same file,
3. `Stable tag:` in `readme.txt`.

**Rollback to an older release** (support instructions): install the older ZIP *over* the current one (Plugins → Add
New → Upload, "Replace current with uploaded"), or deactivate and activate again afterwards. Never delete the plugin
to go back: "Delete" runs `uninstall.php`, which – with "Delete data on uninstall" – drops the voucher tables. A release that does not know the deactivation marker (1.1.0 and older) leaves
the coupons suspended by a deactivation as drafts; the next update to a current release publishes them again on its
first request (also without an activation, e.g. by upload or auto-update).

## Changelog

`readme.txt` → `== Changelog ==` is the changelog. Work in progress collects under `= Unreleased =`; at release
time that heading becomes `= X.Y.Z =` (the release workflow uses it as the release notes). Entries start with **New**,
**Fix** or **Fix (security)** and describe the effect for the shop.

The release workflow (job `version`, `bin/release-gate.sh version|newer`) refuses a tag when header, `VCAP_VERSION`
or `Stable tag` differ from the tag, when an `= Unreleased =` section is left in the changelog or the upgrade notice,
when `= X.Y.Z =` is missing in `== Changelog ==` or in `== Upgrade Notice ==` (at most 300 characters), when the
version is not greater than the newest existing `vX.Y.Z` tag (shops would not be offered it).

## Cutting a release

1. Merge the release branch into `main`; CI (`Plugin smoke tests`) must be green, including the multisite,
   minimum-versions, ecosystem, upgrade, Pass Captain, quality-wide and browser jobs. Tag only a
   commit on `main`: the release workflow refuses a tag whose commit is not on `main` and checks exactly that commit.
2. Work through `RELEASE_CHECKLIST.md` (the manual checks).
3. Bump the version in the three places above, rename `= Unreleased =` to `= X.Y.Z =` in `== Changelog ==` and in
   `== Upgrade Notice ==` (one or two sentences, at most 300 characters: what a shop must do after updating, or
   "Bug fixes; no action needed."), regenerate `languages/voucher-captain.pot` (`php bin/make-pot.php`, the header
   names the version), check that `Tested up to`, `Requires at least`, `Requires PHP` and the WooCommerce lines of `readme.txt`
   match the plugin header, commit.
4. Tag that commit and push the tag:

   ```bash
   git tag -a vX.Y.Z -m "Voucher Captain X.Y.Z"
   ```

   ```bash
   git push origin vX.Y.Z
   ```

5. The `Release` workflow checks the version first (job `version`, see Changelog), then that the tagged commit is
   on `main` and waits for the push run of `Plugin smoke tests` on it (job `ci-green`, up to about two hours). It
   stops unless the run finished with *success* **and every job of it** succeeded (or was skipped) – a job with
   `continue-on-error` that failed counts as failed (there is no report-only job at the moment). No run, a
   failed or a cancelled run blocks the release (re-run CI, then re-run the release workflow). It then builds the ZIP twice
   (identical SHA-256 required) and creates a **draft** GitHub release with `voucher-captain.zip`, its `.sha256` file
   and a CycloneDX SBOM (`voucher-captain.cdx.json`, `bin/sbom.php`, SECURITY.md). There is one build: the free core
   without licensing or update code and without an `Update URI` header (docs/FREE-PREMIUM.md); updates reach shops
   through WordPress.org.
   Release smoke (workflow `Release smoke`, run by hand with the tag): installs the draft's ZIP on a throw-away shop
   over the previous published release and checks version, features, no license page and no `Update URI` header
   (`tests/release-smoke.php`). Do not publish anything before it is green.
6. Publish the GitHub draft and the same ZIP on WordPress.org.

### Emergency: a release that must not reach (more) shops

- **Problems reported right after publishing:** set the GitHub release back to draft, fix the cause and release
  X.Y.Z+1 through the normal path; shops on WordPress.org get the fixed version as an ordinary update.
- **Security fix:** follow SECURITY.md (advisory, reporting duties), release a patch version through the normal path;
  the release smoke still runs.

## Rollback (shops)

A shop goes back to the previous version by installing the previous release ZIP over the current one (from the GitHub
release). Take a
database backup before every update – rollback details per schema version are in COMPATIBILITY.md → Updates,
downgrades and rollback. Newer tables and columns are kept and ignored by the older version; a newer
`vcap_db_version` is never lowered by 1.2.0 and later. The CI job `upgrade` checks each path with data on every push:
last release → current ZIP and previous schema → current, each followed by a rollback and the update again.

## Static analysis

| Job | Scope | Configuration | Blocking |
| --- | --- | --- | --- |
| `quality` | `src/API/V1`, `src/Contracts` – PHPCS WordPress and WooCommerce-Core, PHPStan level 8 | `phpstan.neon.dist` | yes |
| `quality-wide` | whole plugin (`voucher-captain.php`, `uninstall.php`, `config/`, `src/`, `templates/`) – PHPCS WordPress-Extra incl. `WordPress.WP.I18n` (text domain `voucher-captain`), `WordPress.Security.*`, prefix check; PHPStan level 3 | `phpcs.xml.dist`, `phpstan-wide.neon.dist` + `phpstan-baseline.neon` | `WordPress.Security.*`: yes (every finding); all other findings: must not grow against the base commit (ratchet), the full report is informational |
| `plugin-check` | installed release ZIP – WordPress Plugin Check (PCP), static and runtime checks | – | errors: yes (every finding of type ERROR); warnings: informational |

The wide scans were introduced on an existing code base. Security findings block at once; the other findings are
technical debt that may only shrink: the job counts the PHPCS and PHPStan findings of the commit and of its base (pull
request base, or the previous commit of a push) and fails when either count grows. Plan:

- **PHPCS**: `phpcs.xml.dist` excludes only formatting rules (alignment, Yoda conditions, doc-comment style, short
  ternaries – listed there as debt) and the PSR-4 file names. Security, I18n, deprecated-API and prefix sniffs are never
  excluded. The job writes the sources summary to the job summary and uploads the full report (artifact
  `phpstan-baseline`, files `phpcs-*.txt`, `phpcs.json`). Work them off by category; remove a debt exclusion together
  with its clean-up commit.
- **PHPStan**: the job uploads a baseline of the current findings (`phpstan-baseline.generated.neon`). Review it and
  commit it as `phpstan-baseline.neon`; from then on every new finding is visible in the step "PHPStan level 3". Never
  add baseline entries by hand for new code.
- **Plugin Check**: the result table is in the job summary and the artifact `plugin-check`. Since A8 (16.11.2026,
  the run on a86d877 had 0 errors and about 200 warnings) every ERROR fails the job and so blocks merges and releases;
  warnings stay informational and are worked off before the wordpress.org submission.
- When a scan has no findings left (or only baselined ones), make its whole report blocking (for Plugin Check: fail
  on warnings too).

## Third-party licences

The release ZIP bundles Composer packages (`vendor/`) and dompdf's fonts. `bin/build.sh` keeps every `LICENSE*`,
`LICENCE*`, `COPYING*`, `NOTICE*` and `AUTHORS*` file of the packages, copies the texts in `licenses/` (GPL-3.0,
required by the LGPL-3.0 packages; DejaVu/Bitstream Vera font licence) and writes `THIRD-PARTY-NOTICES.txt` from the
installed packages' Composer metadata (`bin/third-party-notices.php`). The build fails when a bundled package has no
licence file; the CI job `quality` checks the finished ZIP again (`--check-zip`). When a dependency is added, check its
licence is GPL-compatible and update the list in `README.md`.

## Reproducible ZIP

`bin/build.sh` builds `build/voucher-captain.zip` and `build/voucher-captain.zip.sha256`. Identical sources give a
byte-identical ZIP: file times are set to `SOURCE_DATE_EPOCH` (default: time of the last commit; the POT header uses
it too), entries are sorted, extra attributes are left out and the Composer autoloader suffix is fixed. Anyone can
rebuild a tag and compare the hash with the published one:

```bash
git checkout vX.Y.Z && bin/build.sh && cat build/voucher-captain.zip.sha256
```
