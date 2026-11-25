#!/usr/bin/env node
// Checks the zip of the single-block plugin against the Block Directory rules
// that a script can decide: one top-level block, the files a reviewer expects,
// no admin screens, and the same version in the header, the readme and block.json.
//
//   node scripts/check-block-directory-zip.mjs dist/profotograaf-gallery-block.zip

import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const SLUG = "profotograaf-gallery-block";
const REQUIRED = [`${SLUG}.php`, "readme.txt", "build/embed.js", "build/gallery/block.json", "build/gallery/index.js"];
const ADMIN_CALLS = /\b(add_menu_page|add_submenu_page|add_options_page|add_management_page|add_theme_page|register_setting|add_settings_section|admin_notices)\b/;

const run = (args) => execFileSync("unzip", args, { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });

/** Problems with where the files sit and which ones exist. */
function layoutProblems(names) {
  const found = [];
  const bad = names.filter((n) => !n.startsWith(`${SLUG}/`));
  if (bad.length > 0) found.push(`files outside ${SLUG}/: ${bad.slice(0, 3).join(", ")}`);
  const files = names.map((n) => n.slice(SLUG.length + 1));
  for (const file of REQUIRED) {
    if (!files.includes(file)) found.push(`missing ${file}`);
  }
  const blocks = files.filter((f) => f.endsWith("block.json"));
  if (blocks.length !== 1) found.push(`expected one block.json, found ${blocks.length}`);
  return found;
}

/** Problems with the version and naming of the three metadata files. */
function metadataProblems(read) {
  const found = [];
  const header = /^\s*\*\s*Version:\s*(\S+)/m.exec(read(`${SLUG}/${SLUG}.php`))?.[1];
  const stable = /^Stable tag:\s*(\S+)/m.exec(read(`${SLUG}/readme.txt`))?.[1];
  const block = JSON.parse(read(`${SLUG}/build/gallery/block.json`));
  if (header !== stable || header !== block.version) {
    found.push(`versions differ: header ${header}, readme ${stable}, block.json ${block.version}`);
  }
  if (!block.name.startsWith(`${SLUG}/`)) found.push(`block name ${block.name} is outside the ${SLUG}/ namespace`);
  if (block.textdomain !== SLUG) found.push(`block text domain ${block.textdomain} is not ${SLUG}`);
  return found;
}

/** Returns the list of problems with the files of a zip. `read(path)` returns a file's text. */
export function problems(names, read) {
  const found = layoutProblems(names);
  if (found.length > 0) return found;
  const phpFiles = names.filter((n) => n.endsWith(".php"));
  for (const file of phpFiles) {
    if (ADMIN_CALLS.test(read(file))) found.push(`${file.slice(SLUG.length + 1)} adds an admin screen, setting or notice`);
  }
  return [...found, ...metadataProblems(read)];
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const zip = process.argv[2];
  if (!zip) {
    console.error("usage: check-block-directory-zip.mjs <zip>");
    process.exit(2);
  }
  const names = run(["-Z1", zip]).split("\n").filter((n) => n && !n.endsWith("/"));
  const list = problems(names, (path) => run(["-p", zip, path]));
  for (const line of list) console.error(`block directory: ${line}`);
  if (list.length > 0) process.exit(1);
  console.log(`block directory: ${names.length} files, one block, versions agree`);
}
