#!/usr/bin/env bash
# Copies the plugin into dist/profotograaf/ without development files (see
# .distignore) and, with --zip, packs dist/profotograaf.zip. Build the blocks
# first: `npm ci && npm run build`.
#
# --wporg builds the wordpress.org flavour into the same folder: without the
# GitHub update source (includes/class-github-updates.php, the Update URI
# header and the module that registers it), which wordpress.org does not allow
# in a hosted plugin. The default build and the GitHub zip keep it.
set -euo pipefail

wporg=0
zip=0
for arg in "$@"; do
	case "$arg" in
		--wporg) wporg=1 ;;
		--zip) zip=1 ;;
		*) echo "Unknown argument: $arg" >&2; exit 2 ;;
	esac
done
if [[ $wporg -eq 1 && $zip -eq 1 ]]; then
	echo "--wporg builds the folder the wordpress.org deploy publishes; it has no zip." >&2
	exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="profotograaf"
out="${root:?}/dist"

# Sync in place instead of deleting the folder: a container that bind mounts
# dist/profotograaf keeps seeing the current files.
mkdir -p "$out/$slug"
excludes=(--exclude-from="$root/.distignore")
if [[ $wporg -eq 1 ]]; then
	excludes+=(--exclude=/includes/class-github-updates.php --exclude=/includes/modules/class-update-source.php)
fi
rsync -a --delete --delete-excluded "${excludes[@]}" "$root/" "$out/$slug/"
if [[ $wporg -eq 1 ]]; then
	sed -i.bak '/^ \* Update URI:/d' "$out/$slug/profotograaf.php"
	rm "$out/$slug/profotograaf.php.bak"
fi

if [[ $zip -eq 1 ]]; then
	# The stable name serves releases/latest/download/profotograaf.zip; the
	# versioned copy is the same file for a download that must name its version.
	version="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
	rm -f "${out:?}/${slug:?}.zip" "${out:?}/${slug:?}-"*.zip
	(cd "$out" && zip -qr "$slug.zip" "$slug")
	cp "$out/$slug.zip" "$out/$slug-$version.zip"
	echo "Built $out/$slug.zip and $out/$slug-$version.zip"
else
	echo "Built $out/$slug$([[ $wporg -eq 1 ]] && echo ' (wordpress.org flavour)')"
fi
