#!/usr/bin/env bash
# Builds an installable release ZIP: build/voucher-captain.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="voucher-captain"
OUT="$ROOT/build"
DEST="$OUT/$SLUG"

# Release rules (RELEASE.md): header Version, VCAP_VERSION and readme "Stable tag" must agree.
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/voucher-captain.php" | tr -d '[:space:]')"
CONST="$(sed -n "s/^define( 'VCAP_VERSION', '\([^']*\)' );/\1/p" "$ROOT/voucher-captain.php")"
STABLE="$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt" | tr -d '[:space:]')"
if [ -z "$VERSION" ] || [ "$VERSION" != "$CONST" ] || [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: header '$VERSION', VCAP_VERSION '$CONST', readme Stable tag '$STABLE'." >&2
	exit 1
fi

# Reproducible: identical sources give a byte-identical ZIP (fixed timestamps, sorted entries, no extra
# attributes, fixed Composer autoloader suffix). Defaults to the time of the last commit.
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
	SOURCE_DATE_EPOCH="$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || date +%s)"
fi
export SOURCE_DATE_EPOCH

rm -rf "$OUT"
mkdir -p "$DEST"

php "$ROOT/bin/make-pot.php"
php "$ROOT/bin/make-mo.php"

# Copy plugin files (no dev tooling).
cp -R "$ROOT/assets" "$ROOT/languages" "$ROOT/src" "$ROOT/templates" "$DEST/"
cp "$ROOT/voucher-captain.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/wpml-config.xml" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$DEST/"

# Production dependencies only.
( cd "$DEST" && composer config --no-interaction autoloader-suffix "VoucherCaptain${VERSION//./_}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
# Libraries other plugins also bundle (WooCommerce 8.x: sabberworm/php-css-parser) get our own namespace.
php "$ROOT/bin/scope-vendor.php" "$DEST/vendor"
( cd "$DEST" && composer dump-autoload --no-dev --optimize --no-interaction --quiet )
if grep -rqE '(^|[^\\])Sabberworm\\CSS' "$DEST/vendor" --include=*.php --exclude-dir=composer; then
	echo "Unscoped Sabberworm references left in the build." >&2
	exit 1
fi
rm -f "$DEST/composer.lock"

# Strip VCS data, tests and docs from vendor.
find "$DEST/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs -o -name doc -o -name examples \) -prune -exec rm -rf {} +
find "$DEST/vendor" -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' -o -name 'phpcs.xml*' -o -name 'phpstan*' \) -delete

find "$DEST" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +
( cd "$OUT" && find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -qX -@ "$SLUG.zip" )
( cd "$OUT" && sha256sum "$SLUG.zip" > "$SLUG.zip.sha256" )
echo "Built $OUT/$SLUG.zip $VERSION ($(du -h "$OUT/$SLUG.zip" | cut -f1), sha256 $(cut -d' ' -f1 "$OUT/$SLUG.zip.sha256"))"
