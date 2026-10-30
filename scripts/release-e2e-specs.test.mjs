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

test("server-drawn markup and the embed pin select the parity specs", () => {
  for (const file of ["includes/class-declarative-renderer.php", "embed-package/profotograaf-embed-0.6.0.tgz"]) {
    const specs = specsFor([file]);
    assert.ok(specs.includes("tests/e2e/embed-parity.spec.js"), file);
    assert.ok(specs.includes("tests/e2e/no-remote-code.spec.js"), file);
  }
});

test("the full-page block and the backup code select their own specs", () => {
  assert.deepEqual(specsFor(["includes/class-full-page-renderer.php"]), ["tests/e2e/full-page.spec.js"]);
  assert.deepEqual(specsFor(["includes/class-backup-worker.php"]), ["tests/e2e/backup-status.spec.js", "tests/e2e/backup.spec.js"]);
});

test("the page builder code selects the Elementor spec", () => {
  assert.deepEqual(specsFor(["includes/modules/class-elementor.php"]), ["tests/e2e/elementor.spec.js"]);
  assert.deepEqual(specsFor(["includes/class-elementor-gallery-widget.php", "includes/class-builder-gallery.php"]), ["tests/e2e/elementor.spec.js"]);
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

test("the E2E concurrency group stays short for a release run with many specs", () => {
  const workflow = fs.readFileSync(".github/workflows/e2e.yml", "utf8");
  const group = /^ {2}group: (.*)$/m.exec(workflow)?.[1] ?? "";
  assert.ok(!/inputs\.specs\s*\|\|/.test(group), "the group must not hold the spec list, a long list makes the run fail at startup");
});
