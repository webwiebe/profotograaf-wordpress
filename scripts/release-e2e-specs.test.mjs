import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { specsFor } from "./release-e2e-specs.mjs";

test("a docs, translation, workflow or unit test change selects no spec", () => {
  assert.deepEqual(specsFor(["README.md", "docs/directory-submission.md", "readme.txt", "languages/profotograaf.pot", ".github/workflows/ci.yml", "tests/unit/Settings_Test.php"]), []);
});

test("a gallery renderer change selects the gallery and embed specs", () => {
  const specs = specsFor(["includes/class-gallery-renderer.php"]);
  assert.ok(specs.includes("tests/e2e/gallery.spec.js"));
  assert.ok(specs.includes("tests/e2e/embed-options.spec.js"));
  assert.ok(!specs.includes("tests/e2e/backup.spec.js"));
});

test("the full-page block and the backup code select their own specs", () => {
  assert.deepEqual(specsFor(["includes/class-full-page-renderer.php"]), ["tests/e2e/full-page.spec.js"]);
  assert.deepEqual(specsFor(["includes/class-backup-worker.php"]), ["tests/e2e/backup-status.spec.js", "tests/e2e/backup.spec.js"]);
});

test("source that no entry names falls back to the smoke set", () => {
  assert.deepEqual(specsFor(["includes/class-logger.php"]), ["tests/e2e/gallery.spec.js", "tests/e2e/plugin-compat.spec.js", "tests/e2e/settings.spec.js"]);
});

test("a block.json change counts as source", () => {
  assert.ok(specsFor(["blocks/gallery/block.json"]).includes("tests/e2e/gallery.spec.js"));
});

test("a changed spec runs itself", () => {
  assert.deepEqual(specsFor(["tests/e2e/leads.spec.js"]), ["tests/e2e/leads.spec.js"]);
});

test("every selected spec exists", () => {
  const files = fs.readdirSync("includes").map((f) => `includes/${f}`);
  for (const spec of specsFor([...files, ...fs.readdirSync("blocks").map((f) => `blocks/${f}/index.ts`)])) {
    assert.ok(fs.existsSync(spec), `${spec} does not exist`);
  }
});
