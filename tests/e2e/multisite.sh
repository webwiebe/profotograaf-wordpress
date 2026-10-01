#!/usr/bin/env bash
# Turns the site started by up.sh into a subdirectory network, creates two
# subsites and activates the plugin for the whole network.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$root/tests/e2e/docker-compose.yml")
port="${WP_PORT:-8080}"
wp() { "${compose[@]}" run --rm -T cli wp "$@"; }

wp plugin deactivate profotograaf
wp core multisite-convert --title="Profotograaf network"
wp site create --slug=one --title="Subsite One" --email=admin@example.com
wp site create --slug=two --title="Subsite Two" --email=admin@example.com
wp plugin activate profotograaf --network
echo "Network ready on http://localhost:${port}"
