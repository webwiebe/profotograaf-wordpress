#!/usr/bin/env node
// Picks the E2E specs to run before a release from the files the release
// changed (the path-to-spec map of the nightly E2E decision). Pull requests run
// no E2E and the full suite runs nightly, so a release runs only the specs of
// the areas it touched. Docs, translations, tests and workflows select none.
//
//   git diff --name-only <previous-tag> HEAD | node scripts/release-e2e-specs.mjs
//
// Prints the spec paths separated by spaces, or nothing.

import { fileURLToPath } from "node:url";

const gallery = ["gallery", "gallery-display", "embed-layout", "embed-options", "embed-empty", "embed-a11y", "embed-cls"];
const smoke = ["settings", "gallery", "plugin-compat"];

/** [pattern, spec names]. The first matching entry wins; order is most specific first. */
const MAP = [
  [/^includes\/class-full-page-|^blocks\/full-page-gallery\//, ["full-page"]],
  [/^includes\/class-(builder-gallery|elementor-)|^includes\/modules\/class-elementor/, ["elementor"]],
  [/^includes\/class-(gallery|reserved-space|embed-script|shortcode|layout-map|empty-gallery|fallback-link|option-resolver|oembed|section)|^blocks\/gallery\//, gallery],
  [/^includes\/class-virtual-|^includes\/class-photo-(file-route|url-signer)/, ["virtual-attachments", "virtual-block-urls", "virtual-removal", "virtual-sync", "keep-on-profotograaf", "convert-to-local"]],
  [/^includes\/class-backup-/, ["backup", "backup-status"]],
  [/^blocks\/client-(galleries|links)\/|^includes\/modules\/class-client-/, ["client-galleries", "client-links"]],
  [/^includes\/class-(photo-|imported-copies|media-baseline)|^blocks\/(media-modal|import-screen)\//, ["media-import", "media-modal"]],
  [/^includes\/class-(sync-|reconcile|change-poller)/, ["sync-check"]],
  [/^includes\/class-telemetry-/, ["telemetry"]],
  [/^includes\/class-settings|^includes\/admin\//, ["settings"]],
  [/^includes\/class-(upload-)/, ["upload-scope"]],
  [/^blocks\/inserter-category\//, ["inserter-category"]],
  [/^includes\/class-(connection|api-|pairing|platform-status|rest-errors)/, ["settings", "gallery"]],
];

/** Files that never need a browser run. */
const IGNORED = /^(docs\/|languages\/|tests\/(?!e2e\/)|\.github\/|assets\/|bin\/|scripts\/)|\.md$|^readme\.txt$|\.po$|\.pot$|\.mo$|\.json$/;

/** Source that no map entry names: the shared plugin code, covered by the smoke set. */
const SOURCE = /^(includes\/|blocks\/|profotograaf\.php|package\.json|pnpm-lock\.yaml|composer\.lock|build\/)/;

/** The spec paths to run for a list of changed files. */
export function specsFor(files) {
  const names = new Set();
  for (const file of files) {
    if (IGNORED.test(file) && !/^blocks\/.*\.json$/.test(file)) continue;
    const hit = MAP.find(([pattern]) => pattern.test(file));
    if (hit) hit[1].forEach((n) => names.add(n));
    else if (SOURCE.test(file)) smoke.forEach((n) => names.add(n));
    else if (/^tests\/e2e\/(.+)\.spec\.js$/.test(file)) names.add(file.replace(/^tests\/e2e\/|\.spec\.js$/g, ""));
  }
  return [...names].map((n) => `tests/e2e/${n}.spec.js`).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  let input = "";
  process.stdin.setEncoding("utf8");
  for await (const chunk of process.stdin) input += chunk;
  const files = input.split("\n").map((l) => l.trim()).filter(Boolean);
  process.stdout.write(specsFor(files).join(" "));
}
