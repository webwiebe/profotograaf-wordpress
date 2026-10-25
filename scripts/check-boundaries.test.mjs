// The boundaries gate's own test. It proves the gate can fail: a new layer
// violation fails, a baselined one that was fixed fails as stale, and code that
// stays inside the rules passes.

import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { compare, findViolations, layerOf, references } from "./check-boundaries.mjs";

const script = fileURLToPath(new URL("./check-boundaries.mjs", import.meta.url));

function repo(files, baseline = "") {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "boundaries-"));
  for (const [rel, body] of Object.entries({ ...files, "scripts/boundaries-baseline.txt": baseline })) {
    fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true });
    fs.writeFileSync(path.join(root, rel), body);
  }
  return root;
}

const run = (root, ...args) => spawnSync("node", [script, ...args], { cwd: root, encoding: "utf8" });

test("layerOf maps folders to layers", () => {
  assert.equal(layerOf("includes/class-a.php"), "core");
  assert.equal(layerOf("includes/leads/class-a.php"), "Leads");
  assert.equal(layerOf("includes/admin/class-a.php"), "Admin");
  assert.equal(layerOf("includes/cli/class-a.php"), "Cli");
  assert.equal(layerOf("includes/modules/class-a.php"), "Modules");
});

test("core reads the relative and the full namespace, other layers only the full one", () => {
  assert.deepEqual(references("<?php Modules\\Foo::run(); use Profotograaf\\Leads\\Queue;", "core"), ["Modules Foo", "Leads Queue"]);
  assert.deepEqual(references("<?php Modules\\Foo::run(); use Profotograaf\\Cli\\Registrar;", "Leads"), ["Cli Registrar"]);
});

test("a comment mention is not a reference", () => {
  assert.deepEqual(references("<?php // see Profotograaf\\Modules\\Foo\n/* Modules\\Bar */", "core"), []);
});

test("core using a feature layer is a violation", () => {
  const root = repo({ "includes/class-a.php": "<?php namespace Profotograaf; use Profotograaf\\Leads\\Queue; new Queue();" });
  assert.deepEqual(findViolations(root), ["core -> Leads includes/class-a.php Queue"]);
});

test("modules may use core, leads and admin", () => {
  const root = repo({
    "includes/modules/class-a.php": "<?php namespace Profotograaf\\Modules; use Profotograaf\\Leads\\Queue; use Profotograaf\\Admin\\Panel; use Profotograaf\\Plugin;",
  });
  assert.deepEqual(findViolations(root), []);
});

test("admin and cli may not use each other", () => {
  const root = repo({
    "includes/admin/class-a.php": "<?php namespace Profotograaf\\Admin; use Profotograaf\\Cli\\Registrar;",
    "includes/cli/class-b.php": "<?php namespace Profotograaf\\Cli; use Profotograaf\\Admin\\Panel;",
  });
  assert.deepEqual(findViolations(root), [
    "Admin -> Cli includes/admin/class-a.php Registrar",
    "Cli -> Admin includes/cli/class-b.php Panel",
  ]);
});

test("compare reports new and stale", () => {
  assert.deepEqual(compare(["a", "b"], ["b", "c"]), { added: ["a"], stale: ["c"] });
});

test("the script exits 1 on a new violation and names it", () => {
  const root = repo({ "includes/class-a.php": "<?php namespace Profotograaf; Modules\\Foo::run();" });
  const result = run(root);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /NEW\s+core -> Modules includes\/class-a\.php Foo/);
});

test("the script exits 1 on a stale baseline entry", () => {
  const root = repo({ "includes/class-a.php": "<?php namespace Profotograaf;" }, "core -> Modules includes/class-a.php Foo\n");
  const result = run(root);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /STALE\s+core -> Modules/);
});

test("the script exits 0 when the violations equal the baseline", () => {
  const root = repo(
    { "includes/class-a.php": "<?php namespace Profotograaf; Modules\\Foo::run();" },
    "# comment\ncore -> Modules includes/class-a.php Foo\n",
  );
  assert.equal(run(root).status, 0);
});

test("--report prints the violations and exits 0", () => {
  const root = repo({ "includes/class-a.php": "<?php namespace Profotograaf; Modules\\Foo::run();" });
  const result = run(root, "--report");
  assert.equal(result.status, 0);
  assert.match(result.stdout, /NEW\s+core -> Modules/);
  assert.match(result.stdout, /report only/);
});

test("--update-baseline writes the violations and the next check passes", () => {
  const root = repo({ "includes/class-a.php": "<?php namespace Profotograaf; Modules\\Foo::run();" });
  assert.equal(run(root, "--update-baseline").status, 0);
  assert.equal(run(root).status, 0);
});

test("the committed baseline matches the repo", () => {
  const root = path.resolve(path.dirname(script), "..");
  const result = run(root);
  assert.equal(result.status, 0, result.stdout);
});
