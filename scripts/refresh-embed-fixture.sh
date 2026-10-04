#!/usr/bin/env bash
# Rebuilds tests/e2e/fixtures/embed.js from a checkout of the platform
# repository and records the commit it came from.
#
#   scripts/refresh-embed-fixture.sh /path/to/professionals
#
# The checkout should be on the platform's main branch. The script runs the
# platform's own public bundle build, so Node and pnpm must be installed.
set -euo pipefail

platform="${1:-}"
if [ -z "$platform" ] || [ ! -d "$platform/web/src/public/embed" ]; then
	echo "usage: $0 <path to the platform checkout>" >&2
	exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixtures="$root/tests/e2e/fixtures"
built="$platform/internal/features/gallery/assets/js/embed.js"

(cd "$platform" && ./scripts/web-deps.sh && cd web && pnpm build:public)

if [ ! -s "$built" ]; then
	echo "the platform build did not produce $built" >&2
	exit 1
fi

commit="$(git -C "$platform" rev-parse HEAD)"
dirty=""
if [ -n "$(git -C "$platform" status --porcelain -- web/src/public)" ]; then
	dirty=" (with uncommitted changes in web/src/public)"
fi

cp "$built" "$fixtures/embed.js"
printf '%s%s\n' "$commit" "$dirty" > "$fixtures/embed.js.commit"
echo "embed.js refreshed from platform commit $commit$dirty"
