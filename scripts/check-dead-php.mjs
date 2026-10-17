#!/usr/bin/env node
// Dead PHP code: a function, method, constant or class in the plugin source
// whose name appears nowhere else in the plugin source.
//
// The estate standard asks for "no new dead code". knip covers the TypeScript;
// this covers the PHP, with no dependency and no network. It matches names as
// text, so a WordPress hook callback written as 'method_name' or
// array( $this, 'method_name' ) counts as a use, and an interface method counts
// as used by the class that implements it. A name that is shared by an
// unrelated symbol is not found: the gate misses some dead code and never
// invents any.
//
// Existing findings are frozen in scripts/dead-php-baseline.txt, one
// `<kind> <name> <path>` per line. It can only shrink:
//
//   - a finding NOT in the baseline          -> FAIL (new)
//   - a baselined finding that is used again -> FAIL (stale: remove it)
//
//   node scripts/check-dead-php.mjs                    # check, fails on drift
//   node scripts/check-dead-php.mjs --report           # same output, exit 0
//   node scripts/check-dead-php.mjs --update-baseline  # rewrite the baseline
//
// CI runs it with --report until the baseline has been burned down (the
// owner's rollout of the dead-code dimension). The strict mode is covered by
// scripts/check-dead-php.test.mjs. Run from the repo root.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const BASELINE_PATH = "scripts/dead-php-baseline.txt";
export const SOURCE_ROOTS = ["includes", "profotograaf.php", "uninstall.php"];

// Module_Loader finds every includes/modules/class-*.php by directory, so those
// classes are used without their name appearing anywhere else.
const DISCOVERED = /^includes\/modules\/class-/;

// Names WordPress, PHP or the plugin loader call without the name appearing.
const IMPLICIT = new Set(["register", "activate", "deactivate", "uninstall"]);

export function phpFiles(root, entries = SOURCE_ROOTS) {
  const out = [];
  const walk = (rel) => {
    const abs = path.join(root, rel);
    if (!fs.existsSync(abs)) return;
    if (fs.statSync(abs).isDirectory()) {
      for (const name of fs.readdirSync(abs)) walk(path.join(rel, name));
    } else if (rel.endsWith(".php")) {
      out.push(rel.split(path.sep).join("/"));
    }
  };
  entries.forEach(walk);
  return out.sort((a, b) => a.localeCompare(b));
}

// Only comments are removed: the 'method' strings that hooks use stay searchable.
export function stripComments(src) {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:'"])\/\/[^\n]*/g, "$1").replace(/^\s*#[^\n]*/gm, "");
}

export function definitions(src) {
  const code = stripComments(src);
  const found = [];
  const add = (kind, re, group = 1) => {
    for (const m of code.matchAll(re)) found.push({ kind, name: m[group] });
  };
  add("class", /^\s*(?:abstract\s+|final\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/gm);
  add("function", /\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/g);
  add("const", /^\s*(?:(?:public|protected|private|final)\s+)*const\s+(?:[A-Za-z_?][A-Za-z0-9_|?]*\s+)?([A-Z_][A-Z0-9_]*)\s*=/gm);
  return found.filter((d) => !d.name.startsWith("__") && !d.name.endsWith("_") && !IMPLICIT.has(d.name));
}

export function findDead(root, entries = SOURCE_ROOTS) {
  const files = phpFiles(root, entries);
  const code = new Map(files.map((f) => [f, stripComments(fs.readFileSync(path.join(root, f), "utf8"))]));
  const dead = [];
  for (const file of files) {
    const seen = new Set();
    for (const { kind, name } of definitions(fs.readFileSync(path.join(root, file), "utf8"))) {
      if (kind === "class" && DISCOVERED.test(file)) continue;
      const key = `${kind} ${name}`;
      if (seen.has(key)) continue;
      seen.add(key);
      const word = new RegExp(`(?<![A-Za-z0-9_])${name}(?![A-Za-z0-9_])`, "g");
      let count = 0;
      for (const text of code.values()) count += (text.match(word) ?? []).length;
      // One occurrence is the definition itself. A function overriding a parent
      // or implementing an interface has a second one in that declaration.
      if (count <= 1) dead.push(`${kind} ${name} ${file}`);
    }
  }
  return dead.sort((a, b) => a.localeCompare(b));
}

export function readBaseline(file) {
  if (!fs.existsSync(file)) return [];
  return fs
    .readFileSync(file, "utf8")
    .split("\n")
    .filter((l) => l.trim() && !l.startsWith("#"));
}

export function compare(dead, baseline) {
  const base = new Set(baseline);
  const now = new Set(dead);
  return {
    added: dead.filter((d) => !base.has(d)),
    stale: baseline.filter((b) => !now.has(b)),
  };
}

const HEADER = `# PHP symbols that nothing else in the plugin source refers to.
# This list may only SHRINK. Regenerate with:
#   node scripts/check-dead-php.mjs --update-baseline
#
# <kind> <name> <path>
`;

function main() {
  const args = process.argv.slice(2);
  const root = process.cwd();
  const dead = findDead(root);
  if (args.includes("--update-baseline")) {
    fs.writeFileSync(path.join(root, BASELINE_PATH), HEADER + dead.map((d) => d + "\n").join(""));
    console.log(`dead-php: baseline written with ${dead.length} entries`);
    return 0;
  }
  const { added, stale } = compare(dead, readBaseline(path.join(root, BASELINE_PATH)));
  for (const a of added) console.log(`NEW    ${a}`);
  for (const s of stale) console.log(`STALE  ${s} (used again or deleted: remove it from the baseline)`);
  const report = args.includes("--report");
  console.log(`dead-php: ${dead.length} findings, ${added.length} new, ${stale.length} stale${report ? " (report only)" : ""}`);
  return !report && (added.length || stale.length) ? 1 : 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) process.exit(main());
