import test from "node:test";
import assert from "node:assert/strict";
import { problems } from "./check-block-directory-zip.mjs";

const SLUG = "profotograaf-gallery-block";
const names = [
  `${SLUG}.php`, "readme.txt", "build/embed.js", "build/gallery/block.json", "build/gallery/index.js",
].map((n) => `${SLUG}/${n}`);

const files = (over = {}) => ({
  [`${SLUG}/${SLUG}.php`]: " * Version:           1.0.0\n<?php\n",
  [`${SLUG}/readme.txt`]: "Stable tag: 1.0.0\n",
  [`${SLUG}/build/gallery/block.json`]: JSON.stringify({ name: `${SLUG}/gallery`, version: "1.0.0", textdomain: SLUG }),
  ...over,
});
const check = (list, map) => problems(list, (p) => map[p] ?? "");

test("a complete zip has no problems", () => {
  assert.deepEqual(check(names, files()), []);
});

test("a missing file is reported", () => {
  assert.deepEqual(check(names.filter((n) => !n.endsWith("embed.js")), files()), ["missing build/embed.js"]);
});

test("an admin menu is reported", () => {
  const map = files({ [`${SLUG}/${SLUG}.php`]: " * Version:           1.0.0\nadd_options_page();" });
  assert.match(check(names, map)[0], /adds an admin screen/);
});

test("differing versions are reported", () => {
  assert.match(check(names, files({ [`${SLUG}/readme.txt`]: "Stable tag: 2.0.0\n" }))[0], /versions differ/);
});

test("a second block is reported", () => {
  assert.match(check([...names, `${SLUG}/build/other/block.json`], files())[0], /expected one block.json/);
});

test("files outside the plugin folder are reported", () => {
  assert.match(check([...names, "stray.php"], files())[0], /files outside/);
});
