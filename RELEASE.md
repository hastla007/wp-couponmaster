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

## Changelog

`readme.txt` → `== Changelog ==` is the changelog. Work in progress collects under `= Unreleased =`; at release
time that heading becomes `= X.Y.Z =` (the release workflow refuses a tag without that section and uses it as the
release notes). Entries start with **New**, **Fix** or **Fix (security)** and describe the effect for the shop.

## Cutting a release

1. Merge the release branch into `main`; CI (`Plugin smoke tests`) must be green, including the multisite,
   minimum-versions, ecosystem, license-acceptance, Pass Captain and browser jobs. Tag only a commit that was pushed to
   `main` (or another branch CI runs on): the release workflow checks exactly that commit.
2. Work through `RELEASE_CHECKLIST.md` (the manual checks).
3. Bump the version in the three places above, rename `= Unreleased =` to `= X.Y.Z =`, commit.
4. Tag that commit and push the tag:

   ```bash
   git tag -a vX.Y.Z -m "Voucher Captain X.Y.Z"
   ```

   ```bash
   git push origin vX.Y.Z
   ```

5. The `Release` workflow first waits for the push run of `Plugin smoke tests` on the tagged commit (job `ci-green`,
   up to about two hours) and stops unless it finished with *success*; no run, a failed or a cancelled run blocks the
   release (re-run CI, then re-run the release workflow). It then checks tag ↔ version, builds the free and the premium ZIP twice each (identical SHA-256
   required) and creates a **draft** GitHub release with `voucher-captain.zip`, `voucher-captain-premium-X.Y.Z.zip`,
   their `.sha256` files and the licence-manager manifest `voucher-captain-X.Y.Z.json` (made by WP License
   Captain's `tools/create-release-manifest.php`: version, HTTPS package URL, SHA-256, required PHP/WordPress,
   tested WordPress, release notes). The package URL is the repository variable `WPLC_PACKAGE_BASE_URL` + the ZIP
   name; without the variable the workflow warns and leaves the manifest out. Review and publish the draft.
6. Publish the premium release in the licence manager's release storage:
   - upload `voucher-captain-premium-X.Y.Z.zip` unchanged to the storage behind `WPLC_PACKAGE_BASE_URL` (immutable:
     never overwrite a published version);
   - put `voucher-captain-X.Y.Z.json` into the licence server's manifest directory (`WPLC_RELEASES_DIR`, default
     `wp-content/wplc-releases`). The must-use plugin `tools/license-server/wplc-release-registry.php` (once per
     licence server, copied to `wp-content/mu-plugins/`) announces the newest valid manifest per product through
     `wplc_update_packages`; a storage host other than the licence server goes into `WPLC_RELEASE_HOSTS`;
   - check on a test shop: new installation from the storage URL (SHA-256 against the manifest), update of the
     previous version through the licence download grant. The CI job `license-acceptance` does exactly this on
     every push against a real WP License Captain server (two releases, wrong SHA-256 refused, real one installed).
   Withdrawing a release = removing its manifest; shops on that version are offered the newest remaining one.

## Static analysis

| Job | Scope | Configuration | Blocking |
| --- | --- | --- | --- |
| `quality` | `src/API/V1`, `src/Contracts` – PHPCS WordPress and WooCommerce-Core, PHPStan level 0 | `phpstan.neon.dist` | yes |
| `quality-wide` | whole plugin (`voucher-captain.php`, `uninstall.php`, `config/`, `src/`, `templates/`) – PHPCS WordPress-Extra incl. `WordPress.WP.I18n` (text domain `voucher-captain`), `WordPress.Security.*`, prefix check; PHPStan level 1 | `phpcs.xml.dist`, `phpstan-wide.neon.dist` + `phpstan-baseline.neon` | no (report-only) |
| `plugin-check` | installed release ZIP – WordPress Plugin Check (PCP), static and runtime checks | – | no (report-only) |

The wide scans were introduced on an existing code base and report its findings without turning CI red
(`continue-on-error`). This is technical debt with a plan:

- **PHPCS**: `phpcs.xml.dist` excludes only formatting rules (alignment, Yoda conditions, doc-comment style, short
  ternaries – listed there as debt) and the PSR-4 file names. Security, I18n, deprecated-API and prefix sniffs are never
  excluded. The job writes the sources summary to the job summary and uploads the full report (artifact
  `phpstan-baseline`, files `phpcs-*.txt`, `phpcs.json`). Work them off by category; remove a debt exclusion together
  with its clean-up commit.
- **PHPStan**: the job uploads a baseline of the current findings (`phpstan-baseline.generated.neon`). Review it and
  commit it as `phpstan-baseline.neon`; from then on every new finding is visible in the step "PHPStan level 1". Never
  add baseline entries by hand for new code.
- **Plugin Check**: the result table is in the job summary and the artifact `plugin-check`. Errors there must be
  resolved (or documented as not applicable for a non-wordpress.org premium build) before a wordpress.org submission.
- When a scan has no findings left (or only baselined ones), remove `continue-on-error` from its job so it gates
  releases like the others.

## Reproducible ZIP

`bin/build.sh` builds `build/voucher-captain.zip` and `build/voucher-captain.zip.sha256`. Identical sources give a
byte-identical ZIP: file times are set to `SOURCE_DATE_EPOCH` (default: time of the last commit; the POT header uses
it too), entries are sorted, extra attributes are left out and the Composer autoloader suffix is fixed. Anyone can
rebuild a tag and compare the hash with the published one:

```bash
git checkout vX.Y.Z && bin/build.sh && cat build/voucher-captain.zip.sha256
```
