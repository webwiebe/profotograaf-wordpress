#!/usr/bin/env bash
# Installs dist/profotograaf-<version>.zip on a clean WordPress with
# `wp plugin install` and activates it, then checks the version, the registered
# blocks and the debug log. Build the zip first: bin/build-release.sh --zip.
# Environment: WP_VERSION (default 7.1), PHP_VERSION (default 8.3).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose=(docker compose -f "$root/tests/e2e/docker-compose.zip.yml")
version="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
zip="/zips/profotograaf-$version.zip"
[[ -f "$root/dist/profotograaf-$version.zip" ]] || { echo "Build the zip first: bin/build-release.sh --zip" >&2; exit 1; }

cleanup() { "${compose[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

"${compose[@]}" up -d --wait db wordpress

# The wordpress image writes wp-config.php on its first start.
for _ in $(seq 1 60); do
	if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
	sleep 2
done

wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
check() {
	local name="$1" want="$2" got="$3"
	if [[ "$got" != "$want" ]]; then
		echo "FAIL $name: wanted '$want', got '$got'" >&2
		exit 1
	fi
	echo "ok   $name"
}

wp core install --url=http://localhost --title="Zip install" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
wp plugin install "$zip" --activate

check "the plugin is active" "active" "$(wp plugin get profotograaf --field=status)"
check "the installed version is the header version" "$version" "$(wp plugin get profotograaf --field=version)"
check "the version constant matches" "$version" "$(wp eval 'echo defined( "PROFOTOGRAAF_VERSION" ) ? PROFOTOGRAAF_VERSION : "";')"
check "the gallery block is registered" "yes" "$(wp eval 'echo WP_Block_Type_Registry::get_instance()->is_registered( "profotograaf/gallery" ) ? "yes" : "no";')"
check "the full-page gallery block is registered" "yes" "$(wp eval 'echo WP_Block_Type_Registry::get_instance()->is_registered( "profotograaf/full-page-gallery" ) ? "yes" : "no";')"
check "the plugin can be deactivated and activated again" "active" "$(wp plugin deactivate profotograaf >/dev/null && wp plugin activate profotograaf >/dev/null && wp plugin get profotograaf --field=status)"

log="$("${compose[@]}" exec -T wordpress sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true')"
if [[ -n "$log" ]]; then
	echo "FAIL the debug log is not empty:" >&2
	echo "$log" >&2
	exit 1
fi
echo "ok   the debug log is empty"
echo "Zip install check passed for profotograaf-$version.zip."
