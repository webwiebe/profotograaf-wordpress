#!/usr/bin/env bash
# Builds the single-block plugin for the Block Directory and packs it.
#
# The block is built with wp-scripts into block-directory/build/gallery, the
# embed script of @profotograaf/embed is copied next to it, and
# `wp-scripts plugin-zip` packs the files listed in block-directory/package.json.
# The zip lands in dist/profotograaf-gallery-block.zip.
#
# Run from the repo root after `pnpm install`.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dir="$root/block-directory"
scripts="$root/node_modules/@wordpress/scripts/bin/wp-scripts.js"
embed="$root/node_modules/@profotograaf/embed/dist/embed.js"

[[ -f "$embed" ]] || { echo "@profotograaf/embed has no dist/embed.js: run pnpm install." >&2; exit 1; }

cd "$root"
node "$scripts" build --config=block-directory/webpack.config.js --webpack-src-dir=block-directory/src --output-path=block-directory/build
cp "$embed" "$dir/build/embed.js"

mkdir -p "$root/dist"
rm -f "$root/dist/profotograaf-gallery-block.zip"
(cd "$dir" && node "$scripts" plugin-zip)
mv "$dir/profotograaf-gallery-block.zip" "$root/dist/profotograaf-gallery-block.zip"
node "$root/scripts/check-block-directory-zip.mjs" "$root/dist/profotograaf-gallery-block.zip"
