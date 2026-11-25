#!/usr/bin/env bash
# Installs the packed single-block plugin on the stack started by up.sh, runs
# Plugin Check against it, and checks the block on the front end. Fails on any
# Plugin Check error. Needs dist/profotograaf-gallery-block.zip.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$root/tests/e2e/docker-compose.yml")
wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
port="${WP_PORT:-8080}"
slug=profotograaf-gallery-block
unpacked="$root/dist/block-directory-unpacked"

rm -rf "$unpacked" && mkdir -p "$unpacked"
unzip -q "$root/dist/$slug.zip" -d "$unpacked"
"${compose[@]}" cp "$unpacked/$slug" "wordpress:/var/www/html/wp-content/plugins/$slug"

wp plugin install plugin-check --activate
wp plugin activate "$slug"
report="$(wp plugin check "$slug" --format=json 2>&1 || true)"
echo "$report" | tail -n 100
errors="$(echo "$report" | { grep -o '"type":"ERROR"' || true; } | wc -l | tr -d ' ')"
echo "Plugin Check errors: $errors"
[[ "$errors" == "0" ]]

id="$(wp post create --post_status=publish --post_title="Block directory check" --porcelain \
	--post_content='<!-- wp:profotograaf-gallery-block/gallery {"source":"gallery-id-1234","layout":"grid"} /-->')"
html="$(curl -fsS "http://localhost:${port}/?p=${id}")"
echo "$html" | grep -q 'data-profotograaf-gallery="gallery-id-1234"'
echo "$html" | grep -q 'data-layout="grid"'
echo "$html" | grep -q 'data-platform="https://profotograaf.nl"'
echo "Front end renders the block and loads its own copy of the embed script."
