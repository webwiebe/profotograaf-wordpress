// Every select sorts alphabetically unless its list has a natural order (owner
// rule 2026-11-21, #407). The order lives in two helpers:
//   blocks/select-order/   SortedSelect and sortChoices for the editor
//   includes/class-select-options.php   Select_Options for PHP
// This gate fails when a select is built without them: a block that imports
// SelectControl itself, or a PHP file that prints a select, registers an
// Elementor or Divi select, but never names Select_Options.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SELECT_WRAPPER_DIR = "blocks/select-order/";
const SELECT_IN_PHP = /<select\b|Controls_Manager::SELECT\b|'type'\s*=>\s*'select'/;
const IMPORTS_SELECT_CONTROL = /\bSelectControl\b/;

function walk(root, dir, out = []) {
  const base = path.join(root, dir);
  if (!fs.existsSync(base)) return out;
  for (const entry of fs.readdirSync(base, { withFileTypes: true })) {
    const rel = path.posix.join(dir, entry.name);
    if (entry.isDirectory()) walk(root, rel, out);
    else out.push(rel);
  }
  return out;
}

const isTestFile = (rel) => /\.test\.[tj]sx?$/.test(rel) || rel.includes("/test-support/");

/** Files that build a select outside the sorting helpers. */
export function findUnsorted(root) {
  const found = [];
  for (const rel of walk(root, "blocks")) {
    if (!/\.tsx?$/.test(rel) || isTestFile(rel) || rel.startsWith(SELECT_WRAPPER_DIR)) continue;
    if (IMPORTS_SELECT_CONTROL.test(fs.readFileSync(path.join(root, rel), "utf8"))) {
      found.push({ file: rel, why: "uses SelectControl directly: use SortedSelect from blocks/select-order/sorted-select" });
    }
  }
  for (const rel of walk(root, "includes")) {
    if (!rel.endsWith(".php") || rel === "includes/class-select-options.php") continue;
    const body = fs.readFileSync(path.join(root, rel), "utf8");
    if (SELECT_IN_PHP.test(body) && !body.includes("Select_Options::")) {
      found.push({ file: rel, why: "builds a select without Select_Options::sorted() or ::natural()" });
    }
  }
  return found.sort((a, b) => a.file.localeCompare(b.file));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
  const found = findUnsorted(root);
  for (const { file, why } of found) console.error(`${file}: ${why}`);
  process.exit(found.length === 0 ? 0 : 1);
}
