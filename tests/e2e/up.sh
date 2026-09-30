#!/usr/bin/env bash
# Builds the release copy, starts WordPress and installs it with the plugin
# active. Environment: WP_VERSION (default 7.1), PHP_VERSION (default 8.3),
# WP_PORT (default 8080).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$root/tests/e2e/docker-compose.yml")
port="${WP_PORT:-8080}"

"$root/bin/build-release.sh"

"${compose[@]}" up -d --wait db wordpress mock-platform

# The wordpress image writes wp-config.php on its first start.
for _ in $(seq 1 60); do
	if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
	sleep 2
done

wp() { "${compose[@]}" run --rm -T cli wp "$@"; }

wp core install \
	--url="http://localhost:${port}" \
	--title="Profotograaf E2E" \
	--admin_user=admin \
	--admin_password=password \
	--admin_email=admin@example.com \
	--skip-email
wp plugin activate profotograaf
echo "WordPress ${WP_VERSION:-7.1} is ready on http://localhost:${port}"
