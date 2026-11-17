#!/usr/bin/env bash
# Release gates of .github/workflows/release.yml (RELEASE.md → Cutting a release). Every failed check prints a
# GitHub "::error::" line and exits non-zero.
#
#   bin/release-gate.sh version X.Y.Z
#       plugin header Version, VCAP_VERSION and readme "Stable tag" are X.Y.Z; readme.txt has a non-empty
#       "= X.Y.Z =" section in "== Changelog ==" and in "== Upgrade Notice ==" (at most 300 characters, the
#       WordPress.org limit) and no "= Unreleased =" section is left.
#   bin/release-gate.sh newer X.Y.Z
#       X.Y.Z is greater than every other vX.Y.Z tag of the repository (the tags must be fetched); passes when there
#       is no earlier tag (first release).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

fail() {
	echo "::error::$*" >&2
	exit 1
}

# Lines of the "= <version> =" subsection of a readme section ("Changelog", "Upgrade Notice"), without the heading.
readme_section() {
	awk -v sec="== $1 ==" -v v="= $2 =" '/^== /{s=$0; f=0; next} s==sec && $0==v {f=1; next} /^= /{f=0} f' "$ROOT/readme.txt"
}

check_version() {
	local version="$1" header const stable notice length
	printf '%s' "$version" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' || fail "Release version '$version' is not X.Y.Z."
	header="$(sed -n 's/^ \* Version: *//p' "$ROOT/voucher-captain.php" | tr -d '[:space:]')"
	const="$(sed -n "s/^define( 'VCAP_VERSION', '\([^']*\)' );/\1/p" "$ROOT/voucher-captain.php")"
	stable="$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt" | tr -d '[:space:]')"
	if [ "$header" != "$version" ] || [ "$const" != "$version" ] || [ "$stable" != "$version" ]; then
		fail "Tag version $version does not match the plugin: header '$header', VCAP_VERSION '$const', readme Stable tag '$stable'."
	fi
	if grep -qiE '^=+ *unreleased *=+ *$' "$ROOT/readme.txt"; then
		fail "readme.txt still has an '= Unreleased =' section (Changelog or Upgrade Notice): rename it to '= $version =' (RELEASE.md)."
	fi
	readme_section 'Changelog' "$version" | grep -q '[^[:space:]]' || fail "readme.txt has no changelog entries under '= $version =' in == Changelog ==."
	notice="$(readme_section 'Upgrade Notice' "$version" | tr '\n' ' ' | sed 's/  */ /g; s/^ //; s/ $//')"
	[ -n "$notice" ] || fail "readme.txt has no upgrade notice '= $version =' in == Upgrade Notice == (one or two sentences: what a shop must do after updating, or that nothing is needed)."
	length="$(printf '%s' "$notice" | LC_ALL=C.UTF-8 wc -m | tr -d '[:space:]')"
	[ "$length" -le 300 ] || fail "The upgrade notice for $version has $length characters; WordPress shows at most 300."
	echo "Version $version: header, VCAP_VERSION, Stable tag, changelog and upgrade notice ($length characters) agree."
}

check_newer() {
	local version="$1" previous highest
	previous="$(git -C "$ROOT" tag -l 'v*' | sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | grep -vxF "$version" | sort -V | tail -n 1 || true)"
	if [ -z "$previous" ]; then
		echo "No earlier vX.Y.Z tag: first release $version."
		return 0
	fi
	highest="$(printf '%s\n%s\n' "$previous" "$version" | sort -V | tail -n 1)"
	[ "$highest" = "$version" ] || fail "Version $version is not greater than the newest release tag v$previous: shops on v$previous would not be offered it as an update."
	echo "Version $version is newer than the last release tag v$previous."
}

case "${1:-}" in
	version) check_version "${2:?version}" ;;
	newer) check_newer "${2:?version}" ;;
	*)
		echo "Usage: bin/release-gate.sh version|newer X.Y.Z" >&2
		exit 2
		;;
esac
