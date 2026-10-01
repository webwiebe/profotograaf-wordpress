#!/usr/bin/env node
// Translation gates.
//
//   node scripts/check-i18n.mjs strings [root]   user-facing text in blocks/ and
//                                                assets/ must go through __().
//   node scripts/check-i18n.mjs pot <committed> <fresh>
//                                                the committed .pot must equal
//                                                a freshly generated one.
//
// The strings scan is a heuristic over source text. It looks for JSX text, for
// literals in user-facing props and object keys (label, title, help ...) and for
// literals written to the DOM (textContent, innerHTML, alert). Append
// `// i18n-ignore` to a line that holds text which is not shown to a person.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SCAN_DIRS = ["blocks", "assets"];
const SOURCE_RE = /\.(tsx?|jsx?|mjs)$/;
const SKIP_RE = /(\.test\.|\.d\.ts$|\/test-support\/)/;
const TEXT_PROPS = "label|title|help|placeholder|alt|aria-label|ariaLabel|description|tooltip|message|text";
// A prop or key, a colon or equals sign, an optional `{`, then a quoted literal.
const PROP_RE = new RegExp(`(?<![\\w$.-])(?:${TEXT_PROPS})\\s*[:=]\\s*(?:\\{\\s*)?(['"\`])((?:\\\\.|(?!\\1).)*)\\1`, "g");
const DOM_RE = /(?:\.(?:textContent|innerText|innerHTML)\s*=\s*|\b(?:alert|confirm)\(\s*)(['"`])((?:\\.|(?!\1).)*)\1/g;
// Text between a closing `>` and the next `<`, with no braces: JSX children.
const JSX_TEXT_RE = /(?<![=\-\s])>([^<>{}\n;()]*[A-Za-z]{2,}[^<>{}\n;()]*)</g;

/** True when the literal reads like text a person sees: several words, or a capitalised word. */
function isText(value) {
  const v = value.trim();
  return /^[A-Z][a-z]/.test(v) || (/\s/.test(v) && /[A-Za-z]{2,}/.test(v));
}

function lineOf(source, index) {
  return source.slice(0, index).split("\n").length;
}

export function findHardcodedStrings(source, file) {
  const lines = source.split("\n");
  const hits = [];
  const add = (index, raw) => {
    const line = lineOf(source, index);
    if (/i18n-ignore/.test(lines[line - 1] ?? "")) return;
    hits.push({ file, line, text: raw.trim() });
  };
  for (const m of source.matchAll(PROP_RE)) {
    if (isText(m[2])) add(m.index, m[2]);
  }
  for (const m of source.matchAll(DOM_RE)) {
    if (isText(m[2])) add(m.index, m[2]);
  }
  if (/\.(tsx|jsx)$/.test(file)) {
    for (const m of source.matchAll(JSX_TEXT_RE)) add(m.index, m[1]);
  }
  return hits.sort((a, b) => a.line - b.line);
}

function walk(dir, out) {
  if (!fs.existsSync(dir)) return;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else out.push(full);
  }
}

export function scanTree(root) {
  const hits = [];
  for (const dir of SCAN_DIRS) {
    const files = [];
    walk(path.join(root, dir), files);
    for (const full of files.sort((a, b) => a.localeCompare(b))) {
      const rel = path.relative(root, full).split(path.sep).join("/");
      if (!SOURCE_RE.test(rel) || SKIP_RE.test(rel)) continue;
      hits.push(...findHardcodedStrings(fs.readFileSync(full, "utf8"), rel));
    }
  }
  return hits;
}

function normalisePot(text) {
  return text
    .replace(/\r\n/g, "\n")
    .split("\n")
    .filter((l) => !l.startsWith('"POT-Creation-Date:'));
}

/** Returns null when the files match, otherwise a message naming the first difference. */
export function comparePot(committed, fresh) {
  const a = normalisePot(committed);
  const b = normalisePot(fresh);
  const length = Math.max(a.length, b.length);
  for (let i = 0; i < length; i += 1) {
    if (a[i] !== b[i]) {
      return `first difference at line ${i + 1}\n  committed: ${a[i] ?? "(end of file)"}\n  generated: ${b[i] ?? "(end of file)"}`;
    }
  }
  return null;
}

function main(argv) {
  const [mode, first, second] = argv;
  if (mode === "strings") {
    const hits = scanTree(first ?? ".");
    if (hits.length === 0) {
      console.log("i18n strings: ok");
      return 0;
    }
    console.error(`i18n strings: ${hits.length} user-facing string(s) not wrapped in __()`);
    for (const h of hits) console.error(`  ${h.file}:${h.line}  ${h.text}`);
    return 1;
  }
  if (mode === "pot" && first && second) {
    const diff = comparePot(fs.readFileSync(first, "utf8"), fs.readFileSync(second, "utf8"));
    if (diff === null) {
      console.log("i18n pot: up to date");
      return 0;
    }
    console.error(`i18n pot: ${first} is stale, run make i18n and commit languages/\n${diff}`);
    return 1;
  }
  console.error("usage: check-i18n.mjs strings [root] | pot <committed> <fresh>");
  return 2;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  process.exit(main(process.argv.slice(2)));
}
