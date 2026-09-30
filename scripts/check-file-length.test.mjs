// The file-length gate's own test. It covers the four ratchet properties on the
// pure decision, then runs the real script against a throwaway git repo so the
// wiring (file discovery, exemptions, baseline IO, exit code) is exercised too.

import test from "node:test";
import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import {
  LIMIT,
  countLines,
  evaluate,
  formatBaseline,
  isSource,
  parseBaseline,
} from "./check-file-length.mjs";

const script = fileURLToPath(new URL("./check-file-length.mjs", import.meta.url));
const map = (o) => new Map(Object.entries(o));

test("the limit is the estate value", () => {
  assert.equal(LIMIT, 500);
});

test("1. a new violation fails", () => {
  const p = evaluate(map({ "includes/a.php": 501 }), map({}));
  assert.equal(p.length, 1);
  assert.match(p[0], /^NEW/);
});

test("a file at the limit passes", () => {
  assert.deepEqual(evaluate(map({ "includes/a.php": 500 }), map({})), []);
});

test("2. a baselined file that got worse fails", () => {
  const p = evaluate(map({ "a.php": 620 }), map({ "a.php": 600 }));
  assert.match(p[0], /^WORSE/);
});

test("an unchanged baselined file passes", () => {
  assert.deepEqual(evaluate(map({ "a.php": 600 }), map({ "a.php": 600 })), []);
});

test("3. a baselined file that shrank but is still over fails as stale", () => {
  const p = evaluate(map({ "a.php": 550 }), map({ "a.php": 600 }));
  assert.match(p[0], /^STALE/);
});

test("3. a baselined file now under the limit fails as stale", () => {
  const p = evaluate(map({ "a.php": 400 }), map({ "a.php": 600 }));
  assert.match(p[0], /^STALE/);
});

test("3. a deleted baselined file fails as stale", () => {
  const p = evaluate(map({}), map({ "a.php": 600 }));
  assert.match(p[0], /^STALE/);
});

test("exemptions and suffixes", () => {
  assert.equal(isSource("includes/class-a.php"), true);
  assert.equal(isSource("blocks/gallery/edit.tsx"), true);
  assert.equal(isSource("scripts/x.mjs"), true);
  assert.equal(isSource("tests/unit/A_Test.php"), false);
  assert.equal(isSource("vendor/x/y.php"), false);
  assert.equal(isSource("blocks/a.test.ts"), false);
  assert.equal(isSource("blocks/globals.d.ts"), false);
  assert.equal(isSource("build/gallery/index.js"), false);
  assert.equal(isSource("README.md"), false);
});

test("line counting ignores the trailing newline", () => {
  assert.equal(countLines(""), 0);
  assert.equal(countLines("a\nb\n"), 2);
  assert.equal(countLines("a\nb"), 2);
});

test("the baseline round-trips", () => {
  const text = formatBaseline(map({ "b.php": 700, "a.php": 501, "c.php": 10 }));
  assert.deepEqual([...parseBaseline(text)], [
    ["a.php", 501],
    ["b.php", 700],
  ]);
});

function run(cwd, ...args) {
  return spawnSync(process.execPath, [script, ...args], { cwd, encoding: "utf8" });
}

function makeRepo() {
  const dir = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "flen-")));
  execFileSync("git", ["init", "-q"], { cwd: dir });
  return dir;
}

const lines = (n) => `${"x\n".repeat(n)}`;

test("end to end: fails on a new long file, passes once baselined, fails when stale", () => {
  const dir = makeRepo();
  try {
    fs.mkdirSync(path.join(dir, "includes"));
    fs.mkdirSync(path.join(dir, "tests"));
    fs.writeFileSync(path.join(dir, "includes/long.php"), lines(501));
    fs.writeFileSync(path.join(dir, "tests/long.php"), lines(900));

    const failing = run(dir);
    assert.equal(failing.status, 1, "a new violation must fail the build");
    assert.match(failing.stderr, /NEW\s+includes\/long\.php/);
    assert.doesNotMatch(failing.stderr, /tests\/long\.php/, "tests are exempt");

    assert.equal(run(dir, "--update-baseline").status, 0);
    assert.equal(run(dir).status, 0, "a baselined file passes");

    fs.writeFileSync(path.join(dir, "includes/long.php"), lines(502));
    assert.equal(run(dir).status, 1, "growing a baselined file fails");

    fs.writeFileSync(path.join(dir, "includes/long.php"), lines(100));
    const stale = run(dir);
    assert.equal(stale.status, 1, "a now-clean baselined file fails as stale");
    assert.match(stale.stderr, /STALE/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
