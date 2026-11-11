#!/usr/bin/env bash
# Builds an installable release ZIP: build/voucher-captain.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="voucher-captain"
OUT="$ROOT/build"
DEST="$OUT/$SLUG"

rm -rf "$OUT"
mkdir -p "$DEST"

php "$ROOT/bin/make-pot.php"
php "$ROOT/bin/make-mo.php"

# Copy plugin files (no dev tooling).
cp -R "$ROOT/assets" "$ROOT/languages" "$ROOT/src" "$ROOT/templates" "$DEST/"
cp "$ROOT/voucher-captain.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/wpml-config.xml" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$DEST/"

# Production dependencies only.
( cd "$DEST" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
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

( cd "$OUT" && zip -qr "$SLUG.zip" "$SLUG" )
echo "Built $OUT/$SLUG.zip ($(du -h "$OUT/$SLUG.zip" | cut -f1))"
