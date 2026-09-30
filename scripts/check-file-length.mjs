#!/usr/bin/env node
// Fails CI when a source file exceeds the line limit, with a ratcheting
// baseline. Covers PHP, TypeScript and the .mjs scripts.
//
// Long files erode readability and reviewability, so source files are capped at
// LIMIT lines. Files already over it are frozen in
// scripts/file-length-baseline.txt (`<lines> <path>` per line). The baseline
// can only shrink:
//
//   - a file NOT in the baseline that exceeds the limit    -> FAIL (new violation)
//   - a baselined file that grew beyond its recorded size  -> FAIL (worse)
//   - a baselined file now at/under the limit (or deleted) -> FAIL (stale: remove it)
//
// The third rule is what makes it a ratchet. There is no skip flag; the only
// escape hatch is `--update-baseline`, a visible diff a reviewer can argue with.
//
// Tests, translations and generated code are exempt.
//
//   node scripts/check-file-length.mjs                    # check
//   node scripts/check-file-length.mjs --update-baseline  # rewrite the baseline
//
// Run from the repo root.

import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const LIMIT = 500;
export const BASELINE_PATH = "scripts/file-length-baseline.txt";
export const SOURCE_SUFFIXES = [".php", ".ts", ".tsx", ".mjs"];

// A path is exempt when any of these appears in "/" + path.
const EXEMPT_SUBSTRINGS = [
  "/tests/",
  "/vendor/",
  "/node_modules/",
  "/build/",
  "/dist/",
  "/coverage/",
  "/languages/",
  "/.tools/",
  "/.claude/worktrees/",
];
const EXEMPT_SUFFIXES = [".test.ts", ".test.tsx", ".test.mjs", ".d.ts"];

export function isExempt(file) {
  return (
    EXEMPT_SUBSTRINGS.some((s) => `/${file}`.includes(s)) ||
    EXEMPT_SUFFIXES.some((s) => file.endsWith(s))
  );
}

export function isSource(file) {
  return SOURCE_SUFFIXES.some((s) => file.endsWith(s)) && !isExempt(file);
}

export function parseBaseline(text) {
  const out = new Map();
  for (const raw of text.split("\n")) {
    const line = raw.trim();
    if (!line || line.startsWith("#")) continue;
    const m = /^(\d+)\s+(.+)$/.exec(line);
    if (!m) throw new Error(`bad baseline line: ${raw}`);
    out.set(m[2], Number(m[1]));
  }
  return out;
}

export function formatBaseline(measured) {
  const header = [
    `# Files that already exceeded the ${LIMIT}-line limit when the gate landed.`,
    "# This list may only SHRINK. Regenerate with:",
    "#   node scripts/check-file-length.mjs --update-baseline",
    "#",
    "# <lines> <path>",
  ].join("\n");
  const rows = [...measured.entries()]
    .filter(([, n]) => n > LIMIT)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([file, n]) => `${n} ${file}`);
  return `${header}\n${rows.join("\n")}${rows.length ? "\n" : ""}`;
}

/**
 * The whole decision. `measured` maps file -> line count for every gated file
 * that exists, `baseline` maps file -> recorded count. Pure, so the gate is
 * testable without a repo.
 */
export function evaluate(measured, baseline, limit = LIMIT) {
  const problems = [];
  for (const [file, lines] of measured) {
    if (lines <= limit) continue;
    const was = baseline.get(file);
    if (was === undefined) {
      problems.push(`NEW      ${file}: ${lines} lines (limit ${limit})`);
    } else if (lines > was) {
      problems.push(`WORSE    ${file}: ${lines} lines, baseline ${was}`);
    } else if (lines < was) {
      problems.push(`STALE    ${file}: ${lines} lines, baseline ${was}, lower the baseline`);
    }
  }
  for (const [file, was] of baseline) {
    const lines = measured.get(file);
    if (lines === undefined) {
      problems.push(`STALE    ${file}: deleted or no longer gated, remove it from the baseline`);
    } else if (lines <= limit) {
      problems.push(`STALE    ${file}: now ${lines} lines (baseline ${was}), remove it from the baseline`);
    }
  }
  return problems;
}

export function countLines(text) {
  if (text === "") return 0;
  const n = text.split("\n").length;
  return text.endsWith("\n") ? n - 1 : n;
}

export function measureTree(root) {
  const out = execFileSync("git", ["ls-files", "-co", "--exclude-standard"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  });
  const measured = new Map();
  for (const file of out.split("\n")) {
    if (!file || !isSource(file)) continue;
    const full = path.join(root, file);
    if (!fs.existsSync(full)) continue;
    measured.set(file, countLines(fs.readFileSync(full, "utf8")));
  }
  return measured;
}

function main() {
  const root = process.cwd();
  const measured = measureTree(root);
  const baselineFile = path.join(root, BASELINE_PATH);

  if (process.argv.includes("--update-baseline")) {
    fs.mkdirSync(path.dirname(baselineFile), { recursive: true });
    fs.writeFileSync(baselineFile, formatBaseline(measured));
    console.log(`file-length baseline written`);
    return;
  }

  const baseline = fs.existsSync(baselineFile)
    ? parseBaseline(fs.readFileSync(baselineFile, "utf8"))
    : new Map();
  const problems = evaluate(measured, baseline);
  if (problems.length === 0) {
    console.log(`file length: ok (${measured.size} files, limit ${LIMIT})`);
    return;
  }
  console.error(`file length: ${problems.length} problem(s)`);
  for (const p of problems) console.error(`  ${p}`);
  console.error("");
  console.error(`Split the file. If the baseline itself is stale, refresh it with`);
  console.error(`  node scripts/check-file-length.mjs --update-baseline`);
  process.exit(1);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main();
}
