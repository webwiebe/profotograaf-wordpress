#!/usr/bin/env bash
# Updates an installed copy through the plugin's own update source. A stand-in
# for GitHub serves the current build as the latest release; the installed copy
# is the same build with an older version number, so the update check, the
# package download and the in-place install run for real. Build the zip first:
# bin/build-release.sh --zip. Environment: WP_VERSION, PHP_VERSION.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -n "${GITHUB_RUN_ID:-}" ]]; then
	export COMPOSE_PROJECT_NAME="profotograaf-update-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT:-1}-$$"
fi
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-profotograaf-update}"
compose=(docker compose -f "$root/tests/e2e/docker-compose.zip.yml")
version="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
current="$root/dist/profotograaf-$version.zip"
[[ -f "$current" ]] || { echo "Build the zip first: bin/build-release.sh --zip" >&2; exit 1; }

work="$root/dist/e2e-release"
rm -rf "$work" && mkdir -p "$work/old"
cleanup() { "${compose[@]}" down -v >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT

# The installed copy: the current build, numbered 0.0.1.
(cd "$work/old" && unzip -q "$current" && \
	sed -i.bak -e 's/^\( \* Version: *\).*/\10.0.1/' -e "s/define( 'PROFOTOGRAAF_VERSION', '[^']*' )/define( 'PROFOTOGRAAF_VERSION', '0.0.1' )/" profotograaf/profotograaf.php && \
	rm profotograaf/profotograaf.php.bak && zip -qr ../old.zip profotograaf)
rm -rf "$work/old"

# The latest release, as the GitHub API answers it.
cp "$current" "$work/profotograaf.zip"
cat >"$work/release.json" <<JSON
{"tag_name":"v$version","draft":false,"prerelease":false,"assets":[{"name":"profotograaf.zip","browser_download_url":"http://releases/profotograaf.zip"}]}
JSON

"${compose[@]}" up -d --wait db wordpress releases
for _ in $(seq 1 60); do
	if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
	sleep 2
done

wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
check() {
	if [[ "$3" != "$2" ]]; then
		echo "FAIL $1: wanted '$2', got '$3'" >&2
		exit 1
	fi
	echo "ok   $1"
}

wp core install --url=http://localhost --title="Update check" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
wp plugin install "/zips/e2e-release/old.zip" --activate
cat >"$work/e2e-release-source.php" <<'PHP'
<?php
add_filter( 'profotograaf_github_release_url', static fn() => 'http://releases/release.json' );
// wp_update_plugins() asks Update URI sources only after wordpress.org answered.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
add_filter( 'http_request_host_is_external', static fn( $external, $host ) => 'releases' === $host ? true : $external, 10, 2 );
PHP
# Point the update source at the stand-in and let the stand-in host through the HTTP safety checks.
"${compose[@]}" run --rm -T --entrypoint sh cli -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cp /zips/e2e-release/e2e-release-source.php /var/www/html/wp-content/mu-plugins/'

wp option update profotograaf_e2e_marker kept >/dev/null
check "the installed copy is the old version" "0.0.1" "$(wp plugin get profotograaf --field=version)"

recheck() { wp eval 'delete_site_transient( "update_plugins" ); delete_transient( "profotograaf_github_release" ); wp_update_plugins();' >/dev/null; }
recheck
check "the Plugins screen would offer the update" "$version" "$(wp plugin list --name=profotograaf --field=update_version)"

wp plugin update profotograaf
check "the plugin is updated to the release" "$version" "$(wp plugin get profotograaf --field=version)"
check "the plugin is still active" "active" "$(wp plugin get profotograaf --field=status)"
check "settings survive the update" "kept" "$(wp option get profotograaf_e2e_marker)"

recheck
check "no update is offered at the release version" "" "$(wp plugin list --name=profotograaf --field=update_version)"

log="$("${compose[@]}" exec -T wordpress sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true' | "$root/tests/e2e/plugin-log-lines.sh")"
if [[ -n "$log" ]]; then
	echo "FAIL the debug log is not empty:" >&2
	echo "$log" >&2
	exit 1
fi
echo "ok   the debug log is empty"
echo "Update check passed: 0.0.1 -> $version."
