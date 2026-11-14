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
to go back: "Delete" runs `uninstall.php`, which releases the license activation and – with "Delete data on
uninstall" – drops the voucher tables. A release that does not know the deactivation marker (1.1.0 and older) leaves
the coupons suspended by a deactivation as drafts; the next update to a current release publishes them again on its
first request (also without an activation, e.g. by upload or auto-update).

## Changelog

`readme.txt` → `== Changelog ==` is the changelog. Work in progress collects under `= Unreleased =`; at release
time that heading becomes `= X.Y.Z =` (the release workflow uses it as the release notes). Entries start with **New**,
**Fix** or **Fix (security)** and describe the effect for the shop.

The release workflow (job `version`, `bin/release-gate.sh version|newer`) refuses a tag when header, `VCAP_VERSION`
or `Stable tag` differ from the tag, when an `= Unreleased =` section is left in the changelog or the upgrade notice,
when `= X.Y.Z =` is missing in `== Changelog ==` or in `== Upgrade Notice ==` (at most 300 characters), when the
version is not greater than the newest existing `vX.Y.Z` tag (shops would not be offered it) and when the premium ZIP
of that version is already in the release storage.

## Cutting a release

1. Merge the release branch into `main`; CI (`Plugin smoke tests`) must be green, including the multisite,
   minimum-versions, ecosystem, license-acceptance, Pass Captain and browser jobs. Tag only a commit that was pushed to
   `main` (or another branch CI runs on): the release workflow checks exactly that commit.
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

   Before the first premium release and after every change of the licence server: the production licence server runs
   WP License Captain with Ed25519 response signatures and the route `GET /wplc/v1/signing-keys` (branch
   `claude/youthful-bardeen-7ejd7a`, commit 150fb17 or newer, merged and deployed) – the same commit the CI job
   `license-acceptance` and the manifest step of the release workflow use.
5. The `Release` workflow checks the version first (job `version`, see Changelog), then waits for the push run of `Plugin smoke tests` on the tagged commit (job `ci-green`,
   up to about two hours) and stops unless it finished with *success*; no run, a failed or a cancelled run blocks the
   release (re-run CI, then re-run the release workflow). It then builds the free and the premium ZIP twice each
   (identical SHA-256 required), checks that the premium ZIP pins the production licence server's current signing key
   (`bin/release-gate.sh keys`: `GET <VCAP_LICENSE_SERVER>/wp-json/wplc/v1/signing-keys`; a mismatch stops the release,
   because every shop would reject the server and the update that could fix it) and creates a **draft** GitHub release with `voucher-captain.zip`, `voucher-captain-premium-X.Y.Z.zip`,
   their `.sha256` files and the licence-manager manifest `voucher-captain-X.Y.Z.json` (made by WP License
   Captain's `tools/create-release-manifest.php`: version, HTTPS package URL, SHA-256, required PHP/WordPress,
   tested WordPress, release notes). The package URL is the repository variable `WPLC_PACKAGE_BASE_URL` + the ZIP
   name; without the variable the workflow warns and leaves the manifest out. Review and publish the draft.
   Editions (LICENSING.md): the free ZIP is built with `VCAP_EDITION=free`, the premium ZIP with
   `VCAP_EDITION=premium VCAP_LICENSE_SERVER=https://<licence server> VCAP_LICENSE_PUBLIC_KEY=<wp wplc signing-key --field=public_key>`
   (repository variables, both required for tag builds; during a key rotation `VCAP_LICENSE_PUBLIC_KEY` holds
   `<current>,<next>` – see "Signing key rotation" below; optional
   `VCAP_UPDATE_URI`, default `<server>/voucher-captain`). A tag build without `VCAP_EDITION` fails, and the
   release workflow stops when the repository variable `VCAP_LICENSE_SERVER` is not set (a local premium build
   without it points to the placeholder `https://licenses.example.com`).
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

## Signing key rotation

Premium builds pin the licence server's Ed25519 public key set (LICENSING.md → Key rotation). A key the installed
builds do not pin locks shops out of their rights and of updates, so:

1. Prepare: `wp wplc signing-key --next --field=public_key` on the licence server (or `WPLC_RESPONSE_SIGNING_KEY_NEXT`
   in `wp-config.php`); the server now signs with both keys. Back up the secret offline.
2. Release with both keys pinned: repository variable `VCAP_LICENSE_PUBLIC_KEY=<current>,<next>`.
3. Wait until the customers run a build that pins the next key.
4. Promote: `wp wplc signing-key --promote` (constants: move the next value into `WPLC_RESPONSE_SIGNING_KEY`).
   The release check passes with the new key as current; drop the old key from the variable with the next release.

Emergency (key lost or compromised): promote the pinned next key at once (step 4) and prepare a new next key for the
following release. Without a pinned next key: new key on the server (`--rotate` or new constant), release with the new
key, and tell premium customers to reinstall the plugin manually from the new premium ZIP – their old build rejects the
server and cannot update itself. Keep a pinned next key in every release to avoid this.

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
