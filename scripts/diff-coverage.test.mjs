// The diff coverage gate's own test: the parsers on real-shaped input, the
// decision on both sides of the threshold and the exemption, and the CLI against
// a throwaway git repo so the exit code is what CI sees.

import test from "node:test";
import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { MIN_LINES, THRESHOLD, cloverHits, evaluate, isEmptyBase, istanbulHits, parseDiff } from "./diff-coverage.mjs";

const script = fileURLToPath(new URL("./diff-coverage.mjs", import.meta.url));

test("constants are the estate values", () => {
  assert.equal(THRESHOLD, 80);
  assert.equal(MIN_LINES, 10);
});

test("parseDiff reads added and changed line ranges, skips deletions", () => {
  const diff = [
    "diff --git a/a.ts b/a.ts",
    "--- a/a.ts",
    "+++ b/a.ts",
    "@@ -1,0 +2,3 @@",
    "+x",
    "@@ -10 +12 @@",
    "+y",
    "@@ -20,2 +22,0 @@",
    "diff --git a/gone.ts b/gone.ts",
    "--- a/gone.ts",
    "+++ /dev/null",
    "@@ -1,3 +0,0 @@",
  ].join("\n");
  const changed = parseDiff(diff);
  assert.deepEqual([...changed.get("a.ts")].sort((a, b) => a - b), [2, 3, 4, 12]);
  assert.equal(changed.has("gone.ts"), false);
});

test("istanbulHits marks a line covered when any statement on it ran", () => {
  const json = {
    "/repo/blocks/a.ts": {
      statementMap: {
        0: { start: { line: 1 }, end: { line: 1 } },
        1: { start: { line: 2 }, end: { line: 2 } },
        2: { start: { line: 2 }, end: { line: 2 } },
      },
      s: { 0: 3, 1: 0, 2: 1 },
    },
  };
  const hits = istanbulHits(json, "/repo");
  assert.deepEqual([...hits.get("blocks/a.ts")], [[1, true], [2, true]]);
});

test("cloverHits reads statement lines and ignores method rows", () => {
  const xml = `<coverage><project><file name="/repo/includes/a.php">
    <line num="3" type="method" name="f" count="1"/>
    <line num="4" type="stmt" count="1"/>
    <line num="5" type="stmt" count="0"/>
  </file></project></coverage>`;
  const hits = cloverHits(xml, "/repo");
  assert.deepEqual([...hits.get("includes/a.php")], [[4, true], [5, false]]);
});

const allGated = () => true;
const lines = (file, from, to) => new Map([[file, new Set(Array.from({ length: to - from + 1 }, (_, i) => from + i))]]);
const hitMap = (file, covered, uncovered) =>
  new Map([[file, new Map([...covered.map((l) => [l, true]), ...uncovered.map((l) => [l, false])])]]);

test("passes at exactly the threshold", () => {
  const changed = lines("a.ts", 1, 10);
  const res = evaluate(changed, hitMap("a.ts", [1, 2, 3, 4, 5, 6, 7, 8], [9, 10]), allGated);
  assert.equal(res.pct, 80);
  assert.equal(res.ok, true);
});

test("fails just under the threshold and names the uncovered lines", () => {
  const changed = lines("a.ts", 1, 10);
  const res = evaluate(changed, hitMap("a.ts", [1, 2, 3, 4, 5, 6, 7], [8, 9, 10]), allGated);
  assert.equal(res.ok, false);
  assert.deepEqual(res.uncovered, { "a.ts": [8, 9, 10] });
});

test("a diff under 10 executable lines is exempt even at 0%", () => {
  const changed = lines("a.ts", 1, 9);
  const res = evaluate(changed, hitMap("a.ts", [], [1, 2, 3, 4, 5, 6, 7, 8, 9]), allGated);
  assert.equal(res.exempt, true);
  assert.equal(res.ok, true);
});

test("lines with no statement do not count, so comments cannot dilute or hurt", () => {
  const changed = lines("a.ts", 1, 30);
  const res = evaluate(changed, hitMap("a.ts", [1, 2, 3, 4, 5, 6, 7, 8, 9, 10], []), allGated);
  assert.equal(res.total, 10);
  assert.equal(res.pct, 100);
});

test("a gated file missing from the coverage report counts as uncovered", () => {
  const changed = lines("new.ts", 1, 12);
  const res = evaluate(changed, new Map(), allGated);
  assert.equal(res.total, 12);
  assert.equal(res.ok, false);
});

test("files outside the gated set are ignored", () => {
  const changed = lines("docs/readme.md", 1, 50);
  const res = evaluate(changed, new Map(), (f) => f.endsWith(".ts"));
  assert.equal(res.total, 0);
  assert.equal(res.ok, true);
});

test("an empty or all-zero base has nothing to diff against", () => {
  assert.equal(isEmptyBase(""), true);
  assert.equal(isEmptyBase("0000000000000000000000000000000000000000"), true);
  assert.equal(isEmptyBase("abc123"), false);
});

function git(dir, ...args) {
  return execFileSync("git", ["-c", "user.email=t@t", "-c", "user.name=t", ...args], { cwd: dir, encoding: "utf8" }).trim();
}

test("end to end: fails on untested new code and passes once it is covered", () => {
  const dir = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "dcov-")));
  try {
    git(dir, "init", "-q");
    fs.mkdirSync(path.join(dir, "blocks"));
    fs.writeFileSync(path.join(dir, "blocks/a.ts"), "export const a = 1;\n");
    git(dir, "add", "-A");
    git(dir, "commit", "-qm", "base");
    const base = git(dir, "rev-parse", "HEAD");

    const body = Array.from({ length: 12 }, (_, i) => `export const v${i} = ${i};`).join("\n");
    fs.writeFileSync(path.join(dir, "blocks/a.ts"), `${body}\n`);
    const cov = (hit) => ({
      [path.join(dir, "blocks/a.ts")]: {
        statementMap: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i, { start: { line: i + 1 }, end: { line: i + 1 } }])),
        s: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i, hit(i)])),
      },
    });
    const run = (report) => {
      const file = path.join(dir, "cov.json");
      fs.writeFileSync(file, JSON.stringify(report));
      return spawnSync(process.execPath, [script, "--base", base, "--coverage", file, "--include", "^blocks/"], { cwd: dir, encoding: "utf8" });
    };

    const bad = run(cov(() => 0));
    assert.equal(bad.status, 1, "untested new code must fail");
    assert.match(bad.stderr, /blocks\/a\.ts/);
    const good = run(cov(() => 1));
    assert.equal(good.status, 0, good.stderr);
    assert.match(good.stdout, /100\.0%/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test("end to end: a zero base passes with a notice", () => {
  const res = spawnSync(process.execPath, [script, "--base", "0000000000000000000000000000000000000000"], { encoding: "utf8" });
  assert.equal(res.status, 0);
  assert.match(res.stdout, /skipped/);
});
