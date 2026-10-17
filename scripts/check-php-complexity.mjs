#!/usr/bin/env node
// PHP complexity ratchet. Runs phpcs with phpcs-complexity.xml.dist (cyclomatic
// complexity 12 and nesting depth 4, the limits oxlint applies to TypeScript)
// and compares the findings to scripts/php-complexity-baseline.txt, one
// `<kind> <function> <path> <value>` per line. It can only shrink:
//
//   - a function not in the baseline           -> FAIL (new)
//   - a baselined function that got worse      -> FAIL (worse)
//   - a baselined function that got better or
//     is gone                                  -> FAIL (stale: lower the entry)
//
//   node scripts/check-php-complexity.mjs                    # check
//   node scripts/check-php-complexity.mjs --report           # same output, exit 0
//   node scripts/check-php-complexity.mjs --update-baseline  # rewrite the baseline
//   node scripts/check-php-complexity.mjs --from-json FILE   # read a phpcs JSON report
//
// CI runs it with --report until the baseline has been burned down. The strict
// mode is covered by scripts/check-php-complexity.test.mjs. Run from the repo root.

import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

// Joined at run time: knip reads a literal "vendor/bin/..." argument as an import.
const PHPCS = path.join("vendor", "bin", "phpcs");
export const BASELINE_PATH = "scripts/php-complexity-baseline.txt";
const KINDS = { "Generic.Metrics.CyclomaticComplexity": "complexity", "Generic.Metrics.NestingLevel": "nesting" };

// A report made in a container names files under /app; keep the part from the plugin root.
function repoPath(file, root) {
  const rel = path.relative(root, file).split(path.sep).join("/");
  if (!rel.startsWith("..")) return rel;
  const tail = /((?:includes|blocks)\/.*|profotograaf\.php|uninstall\.php)$/.exec(file);
  return tail ? tail[1] : rel;
}

// phpcs reports the line of the `function` keyword, so the name is read from there.
function functionName(lines, line) {
  const match = /function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)/.exec(lines.at(line - 1) ?? "");
  return match ? match[1] : `line${line}`;
}

function findingOf(message) {
  const kind = KINDS[message.source.replace(/\.[A-Za-z]+$/, "")];
  const value = /\((\d+)\)|level of (\d+)/.exec(message.message);
  return kind && value ? { kind, value: Number(value[1] || value[2]) } : null;
}

// Turns a phpcs JSON report into `{ "<kind> <function> <path>": value }`.
export function parseReport(report, root) {
  const found = {};
  for (const [file, data] of Object.entries(report.files ?? {})) {
    const rel = repoPath(file, root);
    const lines = fs.readFileSync(path.join(root, rel), "utf8").split("\n");
    for (const m of data.messages ?? []) {
      const finding = findingOf(m);
      if (finding) found[`${finding.kind} ${functionName(lines, m.line)} ${rel}`] = finding.value;
    }
  }
  return found;
}

export function parseBaseline(text) {
  const out = {};
  for (const line of text.split("\n")) {
    if (!line.trim() || line.startsWith("#")) continue;
    const i = line.lastIndexOf(" ");
    out[line.slice(0, i)] = Number(line.slice(i + 1));
  }
  return out;
}

export function formatBaseline(found) {
  const header = `# PHP functions over the complexity or nesting limit (phpcs-complexity.xml.dist).
# This list may only SHRINK. Regenerate with:
#   node scripts/check-php-complexity.mjs --update-baseline
#
# <kind> <function> <path> <value>
`;
  return (
    header +
    Object.keys(found)
      .sort((a, b) => a.localeCompare(b))
      .map((k) => `${k} ${found[k]}\n`)
      .join("")
  );
}

export function compare(found, baseline) {
  const added = [];
  const worse = [];
  const stale = [];
  for (const [key, value] of Object.entries(found)) {
    if (!(key in baseline)) added.push(`${key} ${value}`);
    else if (value > baseline[key]) worse.push(`${key} ${baseline[key]} -> ${value}`);
    else if (value < baseline[key]) stale.push(`${key} ${baseline[key]} -> ${value}`);
  }
  for (const key of Object.keys(baseline)) if (!(key in found)) stale.push(`${key} ${baseline[key]} -> gone`);
  return { added, worse, stale };
}

function loadReport(root, args) {
  const i = args.indexOf("--from-json");
  if (i >= 0) return JSON.parse(fs.readFileSync(args[i + 1], "utf8"));
  const run = spawnSync(PHPCS, ["--standard=phpcs-complexity.xml.dist", "--report=json", "-q"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  });
  // phpcs exits 1 or 2 when it finds violations; no JSON at all is a real failure.
  try {
    return JSON.parse(run.stdout);
  } catch {
    console.error(`phpcs produced no JSON report (exit ${run.status}): ${run.stderr || run.stdout}`);
    process.exit(2);
  }
}

function main() {
  const args = process.argv.slice(2);
  const root = process.cwd();
  const found = parseReport(loadReport(root, args), root);
  const file = path.join(root, BASELINE_PATH);
  if (args.includes("--update-baseline")) {
    fs.writeFileSync(file, formatBaseline(found));
    console.log(`php-complexity: baseline written with ${Object.keys(found).length} entries`);
    return 0;
  }
  const { added, worse, stale } = compare(found, parseBaseline(fs.existsSync(file) ? fs.readFileSync(file, "utf8") : ""));
  for (const a of added) console.log(`NEW    ${a}`);
  for (const w of worse) console.log(`WORSE  ${w}`);
  for (const s of stale) console.log(`STALE  ${s} (lower the baseline entry)`);
  const report = args.includes("--report");
  console.log(
    `php-complexity: ${Object.keys(found).length} findings, ${added.length} new, ${worse.length} worse, ${stale.length} stale${report ? " (report only)" : ""}`,
  );
  return !report && (added.length || worse.length || stale.length) ? 1 : 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) process.exit(main());
