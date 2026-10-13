#!/usr/bin/env bash
# Checks the release zips in dist/ (bin/build-release.sh --zip): the versioned
# zip exists next to the stable name and both are the same file, the zip holds
# one folder named profotograaf with the plugin file, the readme, the built
# blocks and the translations, and none of the development files.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
stable="$root/dist/profotograaf.zip"
versioned="$root/dist/profotograaf-$version.zip"

fail() {
	echo "Release zip: $1" >&2
	exit 1
}

[[ -f "$stable" ]] || fail "dist/profotograaf.zip is missing"
[[ -f "$versioned" ]] || fail "dist/profotograaf-$version.zip is missing"
cmp -s "$stable" "$versioned" || fail "the versioned zip differs from profotograaf.zip"

listing="$(unzip -Z1 "$versioned")"
top="$(echo "$listing" | cut -d/ -f1 | sort -u)"
[[ "$top" == "profotograaf" ]] || fail "the zip must hold one folder named profotograaf, found: $top"

for required in profotograaf.php readme.txt uninstall.php includes/class-plugin.php build/gallery/block.json build/full-page-gallery/block.json languages/profotograaf.pot; do
	echo "$listing" | grep -qx "profotograaf/$required" || fail "profotograaf/$required is missing"
done
echo "$listing" | grep -q '^profotograaf/build/.*\.js$' || fail "the zip holds no built block scripts"

for unwanted in '\.git/' 'node_modules/' 'tests/' 'vendor/' 'blocks/' 'docs/review/' 'composer\.json' 'package\.json' '\.github/'; do
	if echo "$listing" | grep -Eq "^profotograaf/${unwanted}"; then
		fail "the zip holds a development file matching ${unwanted}"
	fi
done

bytes="$(wc -c <"$versioned" | tr -d ' ')"
((bytes < 5 * 1024 * 1024)) || fail "the zip is $bytes bytes, over the 5 MB limit"
echo "Release zip profotograaf-$version.zip is complete ($bytes bytes)."
