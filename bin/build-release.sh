#!/usr/bin/env bash
# Copies the plugin into dist/profotograaf/ without development files (see
# .distignore) and, with --zip, packs dist/profotograaf.zip. Build the blocks
# first: `npm ci && npm run build`.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="profotograaf"
out="${root:?}/dist"

# Sync in place instead of deleting the folder: a container that bind mounts
# dist/profotograaf keeps seeing the current files.
mkdir -p "$out/$slug"
rsync -a --delete --exclude-from="$root/.distignore" "$root/" "$out/$slug/"

if [[ "${1:-}" == "--zip" ]]; then
	rm -f "${out:?}/${slug:?}.zip"
	(cd "$out" && zip -qr "$slug.zip" "$slug")
	echo "Built $out/$slug.zip"
else
	echo "Built $out/$slug"
fi
