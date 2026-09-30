#!/usr/bin/env node
// Diff coverage: of the lines this change adds or edits, how many are executed
// by the tests? The blocking coverage gate, because it asks the useful question
// ("is what I wrote today tested?"), is immune to deleting tested code, and
// works the same at any legacy coverage level.
//
//   node scripts/diff-coverage.mjs --base <sha> --format istanbul \
//        --coverage coverage/coverage-final.json --include '^blocks/.*\.tsx?$' \
//        --exclude '\.test\.|/test-support/|\.d\.ts$'
//   node scripts/diff-coverage.mjs --base <sha> --format clover \
//        --coverage build/clover.xml --include '^(includes/.*|profotograaf|uninstall)\.php$'
//
// Threshold 80% of changed executable lines. Diffs with fewer than 10 changed
// executable lines are exempt, so a one-line fix never fails. Lines that hold
// no statement (comments, blank lines, braces, type-only code) are not counted.
// A gated file missing from the coverage report counts every changed line as
// uncovered, because "no data" must not read as "nothing to cover".
//
// An empty or all-zero --base (a tag push, a new branch) has nothing to diff
// against, and the gate reports that and passes.

import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const THRESHOLD = 80;
export const MIN_LINES = 10;

/** Parses `git diff -U0` output into Map<file, Set<newLineNumber>>. */
export function parseDiff(text) {
  const changed = new Map();
  let file = ""; // empty while inside a deleted file
  for (const line of text.split("\n")) {
    if (line.startsWith("+++ ")) {
      file = line === "+++ /dev/null" ? "" : line.replace(/^\+\+\+ b\//, "");
      continue;
    }
    const m = /^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/.exec(line);
    if (!m || file === "") continue;
    const start = Number(m[1]);
    const count = m[2] ? Number(m[2]) : 1;
    if (!changed.has(file)) changed.set(file, new Set());
    for (let i = 0; i < count; i++) changed.get(file).add(start + i);
  }
  return changed;
}

/** Istanbul coverage-final.json to Map<file, Map<line, covered>>. */
export function istanbulHits(json, root) {
  const out = new Map();
  for (const [abs, data] of Object.entries(json)) {
    const lines = new Map();
    for (const [id, loc] of Object.entries(data.statementMap)) {
      const hit = (data.s[id] ?? 0) > 0;
      const line = loc.start.line;
      lines.set(line, (lines.get(line) ?? false) || hit);
    }
    out.set(relative(abs, root), lines);
  }
  return out;
}

/** PHPUnit clover XML to Map<file, Map<line, covered>>. */
export function cloverHits(xml, root) {
  const out = new Map();
  const fileRe = /<file\s+name="([^"]+)"[^>]*>([\s\S]*?)<\/file>/g;
  for (const [, abs, body] of xml.matchAll(fileRe)) {
    const lines = new Map();
    for (const [, num, count] of body.matchAll(/<line\s+num="(\d+)"\s+type="stmt"\s+count="(\d+)"/g)) {
      const line = Number(num);
      lines.set(line, (lines.get(line) ?? false) || Number(count) > 0);
    }
    out.set(relative(abs, root), lines);
  }
  return out;
}

function relative(abs, root) {
  const norm = abs.replaceAll("\\", "/");
  const base = root.replaceAll("\\", "/").replace(/\/$/, "");
  return norm.startsWith(`${base}/`) ? norm.slice(base.length + 1) : norm;
}

/** true, false, or undefined for a line that holds no statement. */
function lineCovered(fileHits, line) {
  if (fileHits === undefined) return false;
  return fileHits.has(line) ? fileHits.get(line) : undefined;
}

/**
 * The decision. `changed` is Map<file, Set<line>>, `hits` is
 * Map<file, Map<line, covered>>, `gated` says which files count.
 */
export function evaluate(changed, hits, gated, { threshold = THRESHOLD, minLines = MIN_LINES } = {}) {
  let total = 0;
  let covered = 0;
  const uncovered = {};
  for (const [file, lines] of changed) {
    if (!gated(file)) continue;
    const fileHits = hits.get(file);
    for (const line of [...lines].sort((a, b) => a - b)) {
      const isCovered = lineCovered(fileHits, line);
      if (isCovered === undefined) continue; // not an executable line
      total += 1;
      if (isCovered) covered += 1;
      else (uncovered[file] ??= []).push(line);
    }
  }
  const pct = total === 0 ? 100 : (covered / total) * 100;
  const exempt = total < minLines;
  return { total, covered, pct, exempt, ok: exempt || pct >= threshold, uncovered, threshold, minLines };
}

export function isEmptyBase(base) {
  return !base || /^0+$/.test(base);
}

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? fallback : process.argv[i + 1];
}

function main() {
  const base = arg("base", "");
  if (isEmptyBase(base)) {
    console.log("diff coverage: no base commit to diff against (tag push or new branch), skipped");
    return;
  }
  const root = arg("root", process.cwd());
  const format = arg("format", "istanbul");
  const coverageFile = arg("coverage");
  if (!coverageFile || !fs.existsSync(coverageFile)) {
    console.error(`diff coverage: coverage report not found: ${coverageFile}`);
    process.exit(2);
  }
  const include = new RegExp(arg("include", ".*"));
  const exclude = arg("exclude") ? new RegExp(arg("exclude")) : null;
  const gated = (file) => include.test(file) && !(exclude && exclude.test(file));

  const diff = execFileSync("git", ["diff", "-U0", "--no-color", "--diff-filter=AMR", base], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 256 * 1024 * 1024,
  });
  const raw = fs.readFileSync(coverageFile, "utf8");
  const hits = format === "clover" ? cloverHits(raw, arg("strip", root)) : istanbulHits(JSON.parse(raw), arg("strip", root));
  const res = evaluate(parseDiff(diff), hits, gated);
  report(res, arg("label", format));
  if (!res.ok) process.exit(1);
}

function report(res, label) {
  const head = `diff coverage (${label}): ${res.covered}/${res.total} changed executable lines, ${res.pct.toFixed(1)}%`;
  if (res.exempt) {
    console.log(`${head}, exempt (under ${res.minLines} changed lines)`);
    return;
  }
  console.log(`${head}, threshold ${res.threshold}%`);
  if (res.ok) return;
  console.error("");
  console.error("Changed lines the tests never execute:");
  for (const [file, lines] of Object.entries(res.uncovered)) {
    console.error(`  ${file}: ${lines.join(", ")}`);
  }
  console.error("");
  console.error("Add tests that run those lines. There is no skip flag.");
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main();
}
