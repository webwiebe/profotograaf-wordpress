#!/usr/bin/env bash
# Runs Plugin Check (the wordpress.org review tool) against the release copy
# on the stack started by up.sh. Fails on any error; warnings are printed.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$root/tests/e2e/docker-compose.yml")
wp() { "${compose[@]}" run --rm -T cli wp "$@"; }

wp plugin install plugin-check --activate
report="$(wp plugin check profotograaf --format=json 2>&1 || true)"
echo "$report" | tail -n 200

errors="$(echo "$report" | grep -o '"type":"ERROR"' | wc -l | tr -d ' ')"
echo "Plugin Check errors: $errors"
[[ "$errors" == "0" ]]
