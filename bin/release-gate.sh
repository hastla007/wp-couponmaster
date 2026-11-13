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
#   bin/release-gate.sh keys <premium ZIP> <https://licence-server | signing-keys.json>
#       the Ed25519 key set pinned in the premium ZIP contains the licence server's current response signing key, read
#       from its public route GET /wp-json/wplc/v1/signing-keys (WP License Captain); a local JSON file of that route
#       can be given instead (tests). Warns when the server's next key is not pinned or a pinned key is unknown there.
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

check_keys() {
	local zip="$1" source="$2" pinned json server_keys current next key
	pinned="$(unzip -p "$zip" voucher-captain/voucher-captain.php | sed -n "s/^define( 'VCAP_LICENSE_PUBLIC_KEY', '\([^']*\)' );/\1/p" || true)"
	[ -n "$pinned" ] || fail "$zip pins no licence server key (VCAP_LICENSE_PUBLIC_KEY)."
	if [ -f "$source" ]; then
		json="$(cat "$source")"
	else
		printf '%s' "$source" | grep -qE '^https://' || fail "Licence server '$source' is not an https URL."
		json="$(curl --fail --silent --show-error --location --proto '=https' --max-time 30 "${source%/}/wp-json/wplc/v1/signing-keys")" \
			|| fail "Could not read the signing keys of the licence server ${source%/}/wp-json/wplc/v1/signing-keys – it must run a WP License Captain version with that route (RELEASE.md)."
	fi
	# Two lines: current and next public key ('' when none); fails on JSON that is not the route's answer.
	server_keys="$(printf '%s' "$json" | php -r '
		$d = json_decode( stream_get_contents( STDIN ), true );
		if ( ! is_array( $d ) || "wplc-response-v1" !== ( $d["context"] ?? "" ) || ! array_key_exists( "current", $d ) ) { exit( 1 ); }
		foreach ( array( "current", "next" ) as $k ) {
			$key = is_array( $d[ $k ] ?? null ) ? (string) ( $d[ $k ]["public_key"] ?? "" ) : "";
			$raw = base64_decode( $key, true );
			if ( "" !== $key && ( false === $raw || 32 !== strlen( $raw ) || substr( hash( "sha256", $raw ), 0, 16 ) !== ( $d[ $k ]["key_id"] ?? "" ) ) ) { exit( 1 ); }
			echo $key, "\n";
		}')" || fail "The licence server's signing-keys answer is not valid: $json"
	current="$(printf '%s\n' "$server_keys" | sed -n 1p)"
	next="$(printf '%s\n' "$server_keys" | sed -n 2p)"
	[ -n "$current" ] || fail "The licence server has no response signing key yet (wp wplc signing-key on the server)."
	case ",$pinned," in
		*",$current,"*) ;;
		*) fail "The licence server signs with $current, which this build does not pin (pinned: $pinned). Every shop would reject its answers and updates – fix the repository variable VCAP_LICENSE_PUBLIC_KEY." ;;
	esac
	if [ -n "$next" ]; then
		case ",$pinned," in
			*",$next,"*) ;;
			*) echo "::warning::The licence server has a next signing key $next that this build does not pin: shops on this version will reject the server once that key is promoted (LICENSING.md → Key rotation)." ;;
		esac
	fi
	for key in ${pinned//,/ }; do
		if [ "$key" != "$current" ] && [ "$key" != "$next" ]; then
			echo "::warning::Pinned key $key is neither the current nor the next key of the licence server."
		fi
	done
	echo "The licence server's current signing key is pinned (pinned set: $pinned)."
}

case "${1:-}" in
	version) check_version "${2:?version}" ;;
	newer) check_newer "${2:?version}" ;;
	keys) check_keys "${2:?premium ZIP}" "${3:?licence server}" ;;
	*)
		echo "Usage: bin/release-gate.sh version|newer X.Y.Z, or bin/release-gate.sh keys <premium ZIP> <https://licence-server>" >&2
		exit 2
		;;
esac
