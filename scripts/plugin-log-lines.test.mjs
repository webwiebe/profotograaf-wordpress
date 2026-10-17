// The debug-log filter of tests/e2e/zip-install.sh. Core update checks that
// cannot reach wordpress.org are dropped; every other line stays, so a warning
// from the plugin still fails the zip install check.

import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const script = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../tests/e2e/plugin-log-lines.sh");
const filter = (log) => spawnSync("bash", [script], { input: log, encoding: "utf8" });

const core = "[17-Oct-2026 04:00:00 UTC] PHP Warning:  wp_version_check(): An unexpected error occurred. Something may be wrong with WordPress.org.\n";
const plugin = "[17-Oct-2026 04:00:01 UTC] PHP Warning:  Undefined variable $x in /var/www/html/wp-content/plugins/profotograaf/includes/class-a.php on line 12\n";

test("a core update check warning is dropped", () => {
  const run = filter(core);
  assert.equal(run.status, 0);
  assert.equal(run.stdout, "");
});

test("a plugin warning stays, next to a core one", () => {
  const run = filter(core + plugin);
  assert.equal(run.status, 0);
  assert.equal(run.stdout, plugin);
});

test("a plugin warning that names an update function without the core message stays", () => {
  const line = "[17-Oct-2026 04:00:02 UTC] PHP Warning:  wp_update_plugins(): Undefined array key in profotograaf/includes/x.php on line 3\n";
  assert.equal(filter(line).stdout, line);
});

test("an empty log stays empty", () => {
  const run = filter("");
  assert.equal(run.status, 0);
  assert.equal(run.stdout, "");
});
