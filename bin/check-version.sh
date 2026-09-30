#!/usr/bin/env bash
# Fails when the version is not the same in the plugin header, the
# PROFOTOGRAAF_VERSION constant, readme.txt (Stable tag) and package.json.
# With an argument (a tag such as v1.2.3) it must match too.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
header="$(sed -n 's/^ \* Version: *//p' "$root/profotograaf.php" | head -1 | tr -d '[:space:]')"
constant="$(sed -n "s/^define( 'PROFOTOGRAAF_VERSION', '\\(.*\\)' );/\\1/p" "$root/profotograaf.php" | head -1)"
stable="$(sed -n 's/^Stable tag: *//p' "$root/readme.txt" | head -1 | tr -d '[:space:]')"
package="$(sed -n 's/^  "version": "\(.*\)",/\1/p' "$root/package.json" | head -1)"

status=0
for pair in "constant:$constant" "readme Stable tag:$stable" "package.json:$package"; do
	name="${pair%%:*}"
	value="${pair#*:}"
	if [[ "$value" != "$header" ]]; then
		echo "Version mismatch: plugin header says '$header', $name says '$value'" >&2
		status=1
	fi
done
if [[ -n "${1:-}" && "${1#v}" != "$header" ]]; then
	echo "Version mismatch: tag '$1' but the plugin header says '$header'" >&2
	status=1
fi
[[ $status -eq 0 ]] && echo "Version $header is consistent."
exit $status
