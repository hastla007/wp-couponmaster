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
   minimum-versions, Pass Captain and browser jobs.
2. Work through `RELEASE_CHECKLIST.md` (the manual checks).
3. Bump the version in the three places above, rename `= Unreleased =` to `= X.Y.Z =`, commit.
4. Tag that commit and push the tag:

   ```bash
   git tag -a vX.Y.Z -m "Voucher Captain X.Y.Z"
   ```

   ```bash
   git push origin vX.Y.Z
   ```

5. The `Release` workflow checks tag ↔ version, builds the free and the premium ZIP twice each (identical SHA-256
   required) and creates a **draft** GitHub release with `voucher-captain.zip`, `voucher-captain-premium-X.Y.Z.zip`,
   their `.sha256` files and the licence-manager manifest `voucher-captain-X.Y.Z.json` (made by WP License
   Captain's `tools/create-release-manifest.php`: version, HTTPS package URL, SHA-256, required PHP/WordPress,
   tested WordPress, release notes). The package URL is the repository variable `WPLC_PACKAGE_BASE_URL` + the ZIP
   name; without the variable the workflow warns and leaves the manifest out. Review and publish the draft.
   Editions (LICENSING.md): the free ZIP is built with `VCAP_EDITION=free`, the premium ZIP with
   `VCAP_EDITION=premium VCAP_LICENSE_SERVER=https://<licence server>` (repository variable; optional
   `VCAP_UPDATE_URI`, default `<server>/voucher-captain`). A tag build without `VCAP_EDITION` fails; without
   `VCAP_LICENSE_SERVER` the premium ZIP points to the placeholder `https://licenses.example.com`.
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

## Reproducible ZIP

`bin/build.sh` builds `build/voucher-captain.zip` and `build/voucher-captain.zip.sha256`. Identical sources give a
byte-identical ZIP: file times are set to `SOURCE_DATE_EPOCH` (default: time of the last commit; the POT header uses
it too), entries are sorted, extra attributes are left out and the Composer autoloader suffix is fixed. Anyone can
rebuild a tag and compare the hash with the published one:

```bash
git checkout vX.Y.Z && bin/build.sh && cat build/voucher-captain.zip.sha256
```
