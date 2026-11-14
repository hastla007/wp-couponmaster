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
cp -R "$ROOT/assets" "$ROOT/config" "$ROOT/languages" "$ROOT/src" "$ROOT/templates" "$DEST/"
cp "$ROOT/voucher-captain.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/wpml-config.xml" "$ROOT/LICENSE" "$ROOT/composer.json" "$ROOT/composer.lock" "$DEST/"
# Edition (LICENSING.md):
#   VCAP_EDITION=free     premium features permanently locked (sold goods keep being processed), no license page,
#                         no premium updater; plugin header "Update URI" (VCAP_FREE_UPDATE_URI, default false);
#                         optional VCAP_UPGRADE_URL (https) for the "Upgrade to Premium" link;
#   VCAP_EDITION=premium  premium features need an active WP License Captain license (fail-closed); the license
#                         server is fixed: VCAP_LICENSE_SERVER (https://host, placeholder https://licenses.example.com)
#                         and the plugin header "Update URI" (VCAP_UPDATE_URI, default <server>/voucher-captain);
#   unset/development     no edition constant: every feature on (CI integration tests, local development).
#                         Release builds (tag pipelines) must name free or premium.
EDITION="${VCAP_EDITION:-}"
case "$EDITION" in
	free|premium|development) ;;
	"")
		if [ "${GITHUB_REF_TYPE:-}" = "tag" ]; then
			echo "Release build without VCAP_EDITION: set VCAP_EDITION=free or VCAP_EDITION=premium." >&2
			exit 1
		fi
		EDITION="development"
		;;
	*)
		echo "Unknown VCAP_EDITION '$EDITION' (free, premium or development)." >&2
		exit 1
		;;
esac
if [ "$EDITION" = "free" ]; then
	sed -i "s/^define( 'VCAP_VERSION', .*/&\ndefine( 'VCAP_EDITION', 'free' );/" "$DEST/voucher-captain.php"
	grep -q "define( 'VCAP_EDITION', 'free' );" "$DEST/voucher-captain.php" || { echo "Free edition flag missing" >&2; exit 1; }
	# "Update URI" also in the free build: WordPress (5.8+) then never offers a WordPress.org plugin of the same name as
	# an update. VCAP_FREE_UPDATE_URI (optional): "false" (default, the value WordPress documents for "no updates from
	# WordPress.org") or an https URL of the vendor; the free build installs no update handler of its own.
	FREE_UPDATE_URI="${VCAP_FREE_UPDATE_URI:-false}"
	if [ "$FREE_UPDATE_URI" != "false" ] && ! printf '%s' "$FREE_UPDATE_URI" | grep -qE '^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$'; then
		echo "VCAP_FREE_UPDATE_URI must be 'false' or an https URL, got '$FREE_UPDATE_URI'." >&2
		exit 1
	fi
	sed -i "s|^ \* License URI: .*|&\n * Update URI:        $FREE_UPDATE_URI|" "$DEST/voucher-captain.php"
	grep -q "^ \* Update URI: " "$DEST/voucher-captain.php" || { echo "Update URI header missing" >&2; exit 1; }
	# Optional "Upgrade to Premium" link of the free edition (default: the product page).
	if [ -n "${VCAP_UPGRADE_URL:-}" ]; then
		if ! printf '%s' "$VCAP_UPGRADE_URL" | grep -qE '^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/?=&%-]*)?$'; then
			echo "VCAP_UPGRADE_URL must be an https URL, got '$VCAP_UPGRADE_URL'." >&2
			exit 1
		fi
		sed -i "s|^define( 'VCAP_EDITION', 'free' );|&\ndefine( 'VCAP_UPGRADE_URL', '$VCAP_UPGRADE_URL' );|" "$DEST/voucher-captain.php"
	fi
elif [ "$EDITION" = "premium" ]; then
	SERVER="${VCAP_LICENSE_SERVER:-https://licenses.example.com}"
	SERVER="${SERVER%/}"
	if ! printf '%s' "$SERVER" | grep -qE '^https://[A-Za-z0-9.-]+(:[0-9]+)?$'; then
		echo "VCAP_LICENSE_SERVER must be https://host[:port] without a path, got '$SERVER'." >&2
		exit 1
	fi
	if [ -z "${VCAP_LICENSE_SERVER:-}" ]; then
		echo "Warning: VCAP_LICENSE_SERVER is not set; the premium build uses the placeholder $SERVER." >&2
	fi
	UPDATE_URI="${VCAP_UPDATE_URI:-$SERVER/voucher-captain}"
	if ! printf '%s' "$UPDATE_URI" | grep -qE '^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$'; then
		echo "VCAP_UPDATE_URI must be an https URL, got '$UPDATE_URI'." >&2
		exit 1
	fi
	sed -i "s|^define( 'VCAP_VERSION', .*|&\ndefine( 'VCAP_EDITION', 'premium' );\ndefine( 'VCAP_LICENSE_REQUIRED', true );\ndefine( 'VCAP_LICENSE_SERVER', '$SERVER' );|" "$DEST/voucher-captain.php"
	sed -i "s|^ \* License URI: .*|&\n * Update URI:        $UPDATE_URI|" "$DEST/voucher-captain.php"
	# Ed25519 public key(s) of the license server (wp wplc signing-key --field=public_key): responses and offline
	# snapshots must carry the server's signature for exactly this installation. During a key rotation the set holds
	# the current and the next key (wp wplc signing-key --next --field=public_key), separated by commas or whitespace
	# (LICENSING.md → Key rotation). Every key must be base64 of 32 bytes; the build pins them comma-separated.
	PUBLIC_KEY=""
	KEY_COUNT=0
	for KEY in $(printf '%s' "${VCAP_LICENSE_PUBLIC_KEY:-}" | tr ',' ' '); do
		if ! printf '%s' "$KEY" | grep -qE '^[A-Za-z0-9+/]{43}=$' || [ "$(printf '%s' "$KEY" | base64 -d 2>/dev/null | wc -c)" -ne 32 ]; then
			echo "VCAP_LICENSE_PUBLIC_KEY must hold base64 Ed25519 public keys (44 characters, 32 bytes each, separated by commas or whitespace), got '$KEY'." >&2
			exit 1
		fi
		case ",$PUBLIC_KEY," in
			*",$KEY,"*) continue ;;
		esac
		PUBLIC_KEY="${PUBLIC_KEY:+$PUBLIC_KEY,}$KEY"
		KEY_COUNT=$((KEY_COUNT + 1))
	done
	if [ "$KEY_COUNT" -gt 8 ]; then
		echo "VCAP_LICENSE_PUBLIC_KEY holds $KEY_COUNT keys; at most 8 are used (current and next key)." >&2
		exit 1
	fi
	if [ -n "$PUBLIC_KEY" ]; then
		echo "Pinned license server keys: $KEY_COUNT"
		sed -i "s|^define( 'VCAP_LICENSE_SERVER', .*|&\ndefine( 'VCAP_LICENSE_PUBLIC_KEY', '$PUBLIC_KEY' );|" "$DEST/voucher-captain.php"
		grep -q "VCAP_LICENSE_PUBLIC_KEY" "$DEST/voucher-captain.php" || { echo "License public key missing" >&2; exit 1; }
	elif [ "${GITHUB_REF_TYPE:-}" = "tag" ]; then
		echo "Release build of the premium edition without VCAP_LICENSE_PUBLIC_KEY." >&2
		exit 1
	else
		echo "Warning: VCAP_LICENSE_PUBLIC_KEY is not set; this premium build does not check the server's signature." >&2
	fi
	grep -q "VCAP_LICENSE_REQUIRED" "$DEST/voucher-captain.php" || { echo "Premium flag missing" >&2; exit 1; }
	grep -q "^ \* Update URI: " "$DEST/voucher-captain.php" || { echo "Update URI header missing" >&2; exit 1; }
fi
echo "Edition: $EDITION"

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

# Strip VCS data, tests and docs from vendor – but never licence, copyright or notice files (LGPL/GPL/MIT/Apache
# require them; e.g. picqer/php-barcode-generator ships its LGPL-3.0 text as LICENSE.md).
find "$DEST/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs -o -name doc -o -name examples \) -prune -exec rm -rf {} +
find "$DEST/vendor" -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' -o -name 'phpcs.xml*' -o -name 'phpstan*' \) \
	! -iname 'LICENSE*' ! -iname 'LICENCE*' ! -iname 'COPYING*' ! -iname 'NOTICE*' ! -iname 'AUTHORS*' -delete

# Third-party licences (RELEASE.md): GPL-3.0 text for the LGPL-3.0 packages, DejaVu font licence, and
# THIRD-PARTY-NOTICES.txt from the installed packages; fails when a bundled package has no licence file.
cp -R "$ROOT/licenses" "$DEST/"
php "$ROOT/bin/third-party-notices.php" "$DEST"

find "$DEST" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +
( cd "$OUT" && find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -qX -@ "$SLUG.zip" )
( cd "$OUT" && sha256sum "$SLUG.zip" > "$SLUG.zip.sha256" )
echo "Built $OUT/$SLUG.zip $VERSION ($(du -h "$OUT/$SLUG.zip" | cut -f1), sha256 $(cut -d' ' -f1 "$OUT/$SLUG.zip.sha256"))"
