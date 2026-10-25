// The PHP complexity gate's own test. It proves the gate can fail: a new
// function over the limit fails, a baselined function that got worse fails, and
// one that got better or disappeared fails as stale. The script runs against a
// throwaway directory with a hand-written phpcs JSON report.

import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { compare, formatBaseline, parseBaseline, parseReport } from "./check-php-complexity.mjs";

const script = fileURLToPath(new URL("./check-php-complexity.mjs", import.meta.url));
const COMPLEXITY = "Generic.Metrics.CyclomaticComplexity.MaxExceeded";
const NESTING = "Generic.Metrics.NestingLevel.MaxExceeded";

function repo(baseline = "") {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), "php-complexity-")));
  fs.mkdirSync(path.join(root, "includes"));
  fs.mkdirSync(path.join(root, "scripts"));
  fs.writeFileSync(path.join(root, "includes/class-a.php"), "<?php\nclass A {\n\tpublic function busy() {}\n\tpublic function deep() {}\n}\n");
  fs.writeFileSync(path.join(root, "scripts/php-complexity-baseline.txt"), baseline);
  return root;
}

function report(root, messages) {
  const file = path.join(root, "report.json");
  fs.writeFileSync(file, JSON.stringify({ files: { [path.join(root, "includes/class-a.php")]: { messages } } }));
  return file;
}

const busy = (n) => ({ line: 3, source: COMPLEXITY, message: `Function's cyclomatic complexity (${n}) exceeds allowed maximum of 12` });
const deep = (n) => ({ line: 4, source: NESTING, message: `Function's nesting level (${n}) exceeds allowed maximum of 4` });

const run = (root, json, ...args) =>
  spawnSync("node", [script, "--from-json", json, ...args], { cwd: root, encoding: "utf8" });

test("parseReport names the function from the reported line and keeps the value", () => {
  const root = repo();
  const found = parseReport(JSON.parse(fs.readFileSync(report(root, [busy(14)]), "utf8")), root);
  assert.deepEqual(found, { "complexity busy includes/class-a.php": 14 });
});

test("parseReport maps container paths such as /app/includes/... onto the repo", () => {
  const root = repo();
  const container = { files: { "/app/includes/class-a.php": { messages: [busy(14)] } } };
  assert.deepEqual(parseReport(container, root), { "complexity busy includes/class-a.php": 14 });
});

test("parseReport reads nesting findings too", () => {
  const root = repo();
  const found = parseReport(JSON.parse(fs.readFileSync(report(root, [deep(6)]), "utf8")), root);
  assert.deepEqual(found, { "nesting deep includes/class-a.php": 6 });
});

test("baseline text round-trips", () => {
  const found = { "complexity busy includes/class-a.php": 14, "nesting deep includes/class-a.php": 6 };
  assert.deepEqual(parseBaseline(formatBaseline(found)), found);
});

test("compare separates new, worse and stale", () => {
  const result = compare({ "a x": 5, "b y": 9, "c z": 3 }, { "b y": 7, "c z": 4, "d w": 2 });
  assert.deepEqual(result, { added: ["a x 5"], worse: ["b y 7 -> 9"], stale: ["c z 4 -> 3", "d w 2 -> gone"] });
});

test("the script exits 1 on a new function over the limit", () => {
  const root = repo();
  const result = run(root, report(root, [busy(14)]));
  assert.equal(result.status, 1);
  assert.match(result.stdout, /NEW\s+complexity busy includes\/class-a\.php 14/);
});

test("the script exits 1 when a baselined function gets worse", () => {
  const root = repo("complexity busy includes/class-a.php 14\n");
  const result = run(root, report(root, [busy(15)]));
  assert.equal(result.status, 1);
  assert.match(result.stdout, /WORSE\s+complexity busy includes\/class-a\.php 14 -> 15/);
});

test("the script exits 1 when a baselined function got better", () => {
  const root = repo("complexity busy includes/class-a.php 14\n");
  const result = run(root, report(root, [busy(13)]));
  assert.equal(result.status, 1);
  assert.match(result.stdout, /STALE\s+complexity busy/);
});

test("the script exits 1 when a baselined function is gone", () => {
  const root = repo("complexity busy includes/class-a.php 14\n");
  const result = run(root, report(root, []));
  assert.equal(result.status, 1);
  assert.match(result.stdout, /-> gone/);
});

test("the script exits 0 when the findings equal the baseline", () => {
  const root = repo("# comment\ncomplexity busy includes/class-a.php 14\n");
  assert.equal(run(root, report(root, [busy(14)])).status, 0);
});

test("--report prints the findings and exits 0", () => {
  const root = repo();
  const result = run(root, report(root, [busy(14)]), "--report");
  assert.equal(result.status, 0);
  assert.match(result.stdout, /NEW\s+complexity busy/);
  assert.match(result.stdout, /report only/);
});

test("--update-baseline writes the findings and the next check passes", () => {
  const root = repo();
  const json = report(root, [busy(14), deep(6)]);
  assert.equal(run(root, json, "--update-baseline").status, 0);
  assert.equal(run(root, json).status, 0);
});

// The real run needs phpcs, which only the PHP quality job installs; this part
// stays offline: the baseline is empty, so no function may be added to it.
test("the committed baseline is empty", () => {
  const root = path.resolve(path.dirname(script), "..");
  const baseline = parseBaseline(fs.readFileSync(path.join(root, "scripts/php-complexity-baseline.txt"), "utf8"));
  assert.deepEqual(Object.keys(baseline), []);
});
