#!/usr/bin/env node
// Layer boundaries for the PHP in includes/. The folders are namespaces:
//
//   includes/*.php       Profotograaf          core
//   includes/leads/      Profotograaf\Leads
//   includes/admin/      Profotograaf\Admin
//   includes/cli/        Profotograaf\Cli
//   includes/modules/    Profotograaf\Modules  features, free to use everything
//
// Core is the lowest layer and knows no other layer. Leads, Admin and Cli are
// siblings that sit above core and do not know the modules or each other
// (Admin and Cli never use one another). The rules are in RULES.
//
// Existing violations are frozen in scripts/boundaries-baseline.txt, one
// `<from> -> <to> <path> <class>` per line. It can only shrink:
//
//   - a violation NOT in the baseline                -> FAIL (new)
//   - a baselined violation that is gone             -> FAIL (stale: remove it)
//
//   node scripts/check-boundaries.mjs                    # check
//   node scripts/check-boundaries.mjs --report           # same output, exit 0
//   node scripts/check-boundaries.mjs --update-baseline  # rewrite the baseline
//
// CI runs it with --report until the owner makes the dimension blocking. The
// gate is covered by scripts/check-boundaries.test.mjs. Run from the repo root.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const BASELINE_PATH = "scripts/boundaries-baseline.txt";
export const ROOTS = ["includes", "profotograaf.php", "uninstall.php"];
export const LAYERS = ["Admin", "Cli", "Leads", "Modules"];

// importer layer -> layers it may not reference
export const RULES = {
  core: ["Admin", "Cli", "Leads", "Modules"],
  Leads: ["Admin", "Cli", "Modules"],
  Admin: ["Cli", "Modules"],
  Cli: ["Admin", "Modules"],
};

export function layerOf(rel) {
  const m = /^includes\/(admin|cli|leads|modules)\//.exec(rel);
  return m ? m[1][0].toUpperCase() + m[1].slice(1) : "core";
}

export function phpFiles(root, entries = ROOTS) {
  const out = [];
  const walk = (rel) => {
    const abs = path.join(root, rel);
    if (!fs.existsSync(abs)) return;
    if (fs.statSync(abs).isDirectory()) for (const name of fs.readdirSync(abs)) walk(path.join(rel, name));
    else if (rel.endsWith(".php")) out.push(rel.split(path.sep).join("/"));
  };
  entries.forEach(walk);
  return out.sort((a, b) => a.localeCompare(b));
}

export function stripComments(src) {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:'"])\/\/[^\n]*/g, "$1").replace(/^\s*#[^\n]*/gm, "");
}

// Classes of other layers that `src` refers to, as `<layer> <Class>` pairs.
// Core code writes the sibling namespace relative to its own (`Modules\Foo`);
// every other layer has to write the full `Profotograaf\Modules\Foo`.
export function references(src, layer) {
  const code = stripComments(src);
  const prefix = layer === "core" ? "(?:Profotograaf\\\\)?" : "Profotograaf\\\\";
  const re = new RegExp(`(?<![\\w\\\\])${prefix}(${LAYERS.join("|")})\\\\([A-Z][A-Za-z0-9_]*)`, "g");
  return [...code.matchAll(re)].map((m) => `${m[1]} ${m[2]}`);
}

export function findViolations(root, entries = ROOTS) {
  const found = new Set();
  for (const rel of phpFiles(root, entries)) {
    const layer = layerOf(rel);
    const banned = RULES[layer] ?? [];
    for (const ref of references(fs.readFileSync(path.join(root, rel), "utf8"), layer)) {
      const [to, cls] = ref.split(" ");
      if (to !== layer && banned.includes(to)) found.add(`${layer} -> ${to} ${rel} ${cls}`);
    }
  }
  return [...found].sort((a, b) => a.localeCompare(b));
}

export function readBaseline(file) {
  if (!fs.existsSync(file)) return [];
  return fs
    .readFileSync(file, "utf8")
    .split("\n")
    .filter((l) => l.trim() && !l.startsWith("#"));
}

export function compare(found, baseline) {
  const base = new Set(baseline);
  const now = new Set(found);
  return { added: found.filter((f) => !base.has(f)), stale: baseline.filter((b) => !now.has(b)) };
}

const HEADER = `# Layer violations in includes/ (see scripts/check-boundaries.mjs for the rules).
# This list may only SHRINK. Regenerate with:
#   node scripts/check-boundaries.mjs --update-baseline
#
# <from> -> <to> <path> <class>
`;

function main() {
  const args = process.argv.slice(2);
  const root = process.cwd();
  const found = findViolations(root);
  if (args.includes("--update-baseline")) {
    fs.writeFileSync(path.join(root, BASELINE_PATH), HEADER + found.map((f) => f + "\n").join(""));
    console.log(`boundaries: baseline written with ${found.length} entries`);
    return 0;
  }
  const { added, stale } = compare(found, readBaseline(path.join(root, BASELINE_PATH)));
  for (const a of added) console.log(`NEW    ${a}`);
  for (const s of stale) console.log(`STALE  ${s} (gone: remove it from the baseline)`);
  const report = args.includes("--report");
  console.log(`boundaries: ${found.length} violations, ${added.length} new, ${stale.length} stale${report ? " (report only)" : ""}`);
  return !report && (added.length || stale.length) ? 1 : 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) process.exit(main());
