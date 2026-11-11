#!/usr/bin/env bash
# Builds an installable release ZIP: build/wc-voucher-studio.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wc-voucher-studio"
OUT="$ROOT/build"
DEST="$OUT/$SLUG"

rm -rf "$OUT"
mkdir -p "$DEST"

php "$ROOT/bin/make-pot.php"
php "$ROOT/bin/make-mo.php"

# Copy plugin files (no dev tooling).
cp -R "$ROOT/assets" "$ROOT/languages" "$ROOT/src" "$ROOT/templates" "$DEST/"
cp "$ROOT/wc-voucher-studio.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/wpml-config.xml" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$DEST/"

# Production dependencies only.
( cd "$DEST" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
rm -f "$DEST/composer.lock"

# Strip VCS data, tests and docs from vendor.
find "$DEST/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs -o -name doc -o -name examples \) -prune -exec rm -rf {} +
find "$DEST/vendor" -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' -o -name 'phpcs.xml*' -o -name 'phpstan*' \) -delete

( cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" )
echo "Built $OUT/$SLUG.zip ($(du -h "$OUT/$SLUG.zip" | cut -f1))"
