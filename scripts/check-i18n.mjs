#!/usr/bin/env node
// Translation gates.
//
//   node scripts/check-i18n.mjs strings [root]   user-facing text in blocks/ and
//                                                assets/ must go through __().
//   node scripts/check-i18n.mjs pot <committed> <fresh>
//                                                the committed .pot must equal
//                                                a freshly generated one.
//   node scripts/check-i18n.mjs po [dir]         every bundled .po in dir (default
//                                                languages) translates every
//                                                string, with no fuzzy entries.
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

const ESCAPES = { n: "\n", t: "\t", r: "\r", '"': '"', "\\": "\\" };

/** Joins a keyword line and its continuation lines into the unquoted value. */
function unquote(parts) {
  return parts.map((p) => p.slice(1, -1).replace(/\\(.)/g, (_, c) => ESCAPES[c] ?? c)).join("");
}

/** Collects the quoted lines of each keyword in one .po entry, and whether it is fuzzy. */
function readBlock(block) {
  const fields = {};
  let fuzzy = false;
  let current = null;
  for (const line of block.split("\n")) {
    if (line.startsWith("#,") && /\bfuzzy\b/.test(line)) fuzzy = true;
    const keyword = /^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$/.exec(line);
    if (keyword) {
      current = keyword[1];
      fields[current] = [keyword[2]];
    } else if (current && /^".*"$/.test(line)) {
      fields[current].push(line);
    } else {
      current = null;
    }
  }
  return { fields, fuzzy };
}

/**
 * Parses a .po file into its entries, skipping the header and obsolete (#~) entries.
 * Each entry has msgctxt, msgid, msgstr (a list, one item per plural form) and fuzzy.
 */
export function parsePo(text) {
  const entries = [];
  for (const block of text.replace(/\r\n/g, "\n").split(/\n\s*\n/)) {
    const { fields, fuzzy } = readBlock(block.trim());
    const msgid = fields.msgid ? unquote(fields.msgid) : "";
    if (msgid === "") continue;
    const msgstr = Object.keys(fields)
      .filter((k) => k.startsWith("msgstr"))
      .sort()
      .map((k) => unquote(fields[k]));
    entries.push({ msgctxt: fields.msgctxt ? unquote(fields.msgctxt) : null, msgid, msgstr, fuzzy });
  }
  return entries;
}

/** Lists the entries a locale would show in English: untranslated or fuzzy. */
export function findUntranslated(text) {
  const out = [];
  for (const e of parsePo(text)) {
    const empty = e.msgstr.length === 0 || e.msgstr.some((s) => s === "");
    if (empty || e.fuzzy) out.push({ msgid: e.msgid, msgctxt: e.msgctxt, reason: e.fuzzy ? "fuzzy" : "untranslated" });
  }
  return out;
}

function checkPo(dir) {
  const files = fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith(".po")).sort() : [];
  if (files.length === 0) {
    console.error(`i18n po: no .po files in ${dir}`);
    return 1;
  }
  let failed = 0;
  for (const file of files) {
    const hits = findUntranslated(fs.readFileSync(path.join(dir, file), "utf8"));
    if (hits.length === 0) continue;
    failed += 1;
    console.error(`i18n po: ${file} has ${hits.length} untranslated or fuzzy string(s)`);
    for (const h of hits) console.error(`  ${h.reason}: ${h.msgctxt ? `[${h.msgctxt}] ` : ""}${h.msgid}`);
  }
  if (failed === 0) {
    console.log(`i18n po: ${files.length} locale(s) fully translated`);
    return 0;
  }
  console.error("Translate the strings in languages/*.po, then run make i18n and commit languages/.");
  return 1;
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
  if (mode === "po") return checkPo(first ?? "languages");
  console.error("usage: check-i18n.mjs strings [root] | pot <committed> <fresh> | po [dir]");
  return 2;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  process.exit(main(process.argv.slice(2)));
}
