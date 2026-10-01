// Guards phpcs.xml.dist so the WordPress i18n sniffs stay on. Without the
// text domain the sniff accepts any domain, and an exclusion would let
// untranslatable strings through without a failing build.

import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const xml = fs.readFileSync("phpcs.xml.dist", "utf8").replace(/<!--[\s\S]*?-->/g, "");

test("the WordPress i18n sniff is enabled with the plugin text domain", () => {
  const rule = /<rule ref="WordPress\.WP\.I18n">([\s\S]*?)<\/rule>/.exec(xml);
  assert.ok(rule, "WordPress.WP.I18n rule missing");
  assert.match(rule[1], /<element value="profotograaf"\/>/);
});

test("no i18n sniff is excluded or downgraded", () => {
  assert.doesNotMatch(xml, /<exclude name="WordPress\.WP\.I18n/);
  assert.doesNotMatch(xml, /<rule ref="WordPress\.WP\.I18n[^"]*">\s*<severity>0<\/severity>/);
});

test("the plugin code is scanned", () => {
  for (const dir of ["includes", "blocks", "profotograaf.php"]) {
    assert.match(xml, new RegExp(`<file>${dir}</file>`), dir);
  }
});
