#!/usr/bin/env bash
# Checks the release zips in dist/ (bin/build-release.sh --zip): the versioned
# zip exists next to the stable name and both are the same file, the zip holds
# one folder named profotograaf with the plugin file, the readme, the built
# blocks and the translations, and none of the development files. The GitHub
# zip carries the update source: the Update URI header and its two files.
#
# With --wporg it checks the wordpress.org flavour instead (bin/build-release.sh
# --wporg): dist/profotograaf holds no update source, and the release workflow
# builds that flavour before the wordpress.org deploy step and deploys that folder.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
stable="$root/dist/profotograaf.zip"
updater_files=(includes/class-github-updates.php includes/modules/class-update-source.php)
versioned="$root/dist/profotograaf-$version.zip"

fail() {
	echo "Release zip: $1" >&2
	exit 1
}

if [[ "${1:-}" == "--wporg" ]]; then
	folder="$root/dist/profotograaf"
	[[ -f "$folder/profotograaf.php" ]] || fail "dist/profotograaf is not built (bin/build-release.sh --wporg)"
	for file in "${updater_files[@]}"; do
		[[ ! -e "$folder/$file" ]] || fail "the wordpress.org build holds $file"
	done
	! grep -q 'Update URI' "$folder/profotograaf.php" || fail "the wordpress.org build declares an Update URI"
	! grep -rq 'Github_Updates' "$folder/includes" || fail "the wordpress.org build refers to Github_Updates"
	workflow="$root/.github/workflows/release.yml"
	build_line="$(grep -n 'bin/build-release.sh --wporg' "$workflow" | head -1 | cut -d: -f1)"
	deploy_line="$(grep -n 'Deploy to wordpress.org' "$workflow" | head -1 | cut -d: -f1)"
	[[ -n "$build_line" && -n "$deploy_line" && "$build_line" -lt "$deploy_line" ]] || fail "release.yml must run bin/build-release.sh --wporg before the wordpress.org deploy step"
	sed -n "${deploy_line},\$p" "$workflow" | grep -q 'BUILD_DIR: dist/profotograaf$' || fail "the wordpress.org deploy step must publish BUILD_DIR dist/profotograaf"
	echo "wordpress.org build has no update source and release.yml deploys it."
	exit 0
fi

[[ -f "$stable" ]] || fail "dist/profotograaf.zip is missing"
[[ -f "$versioned" ]] || fail "dist/profotograaf-$version.zip is missing"
cmp -s "$stable" "$versioned" || fail "the versioned zip differs from profotograaf.zip"

listing="$(unzip -Z1 "$versioned")"
top="$(echo "$listing" | cut -d/ -f1 | sort -u)"
[[ "$top" == "profotograaf" ]] || fail "the zip must hold one folder named profotograaf, found: $top"

for required in profotograaf.php readme.txt uninstall.php includes/class-plugin.php build/gallery/block.json build/full-page-gallery/block.json languages/profotograaf.pot; do
	echo "$listing" | grep -qx "profotograaf/$required" || fail "profotograaf/$required is missing"
done
for file in "${updater_files[@]}"; do
	echo "$listing" | grep -qx "profotograaf/$file" || fail "profotograaf/$file is missing (the GitHub zip carries the update source)"
done
unzip -p "$versioned" profotograaf/profotograaf.php | grep -q '^ \* Update URI: *https://github.com/webwiebe/profotograaf-wordpress' || fail "the GitHub zip lacks the Update URI header"
echo "$listing" | grep -q '^profotograaf/build/.*\.js$' || fail "the zip holds no built block scripts"

for unwanted in '\.git/' 'node_modules/' 'tests/' 'vendor/' 'blocks/' 'docs/review/' 'composer\.json' 'package\.json' '\.github/'; do
	if echo "$listing" | grep -Eq "^profotograaf/${unwanted}"; then
		fail "the zip holds a development file matching ${unwanted}"
	fi
done

bytes="$(wc -c <"$versioned" | tr -d ' ')"
((bytes < 5 * 1024 * 1024)) || fail "the zip is $bytes bytes, over the 5 MB limit"
echo "Release zip profotograaf-$version.zip is complete ($bytes bytes)."
