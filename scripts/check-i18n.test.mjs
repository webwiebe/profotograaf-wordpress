import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { comparePot, findHardcodedStrings, scanTree } from "./check-i18n.mjs";

const script = fileURLToPath(new URL("./check-i18n.mjs", import.meta.url));
const found = (src, file = "blocks/x/edit.tsx") => findHardcodedStrings(src, file).map((f) => f.text);

test("a translated label passes", () => {
  assert.deepEqual(found("<TextControl label={ __( 'Heading', 'profotograaf' ) } />"), []);
});

test("a literal label prop is flagged", () => {
  assert.deepEqual(found("<TextControl label=\"Heading\" />"), ["Heading"]);
  assert.deepEqual(found("<TextControl label={ 'Heading' } />"), ["Heading"]);
});

test("each user-facing prop name is checked", () => {
  for (const prop of ["title", "help", "placeholder", "alt", "aria-label", "description", "tooltip"]) {
    assert.deepEqual(found(`<X ${prop}="Open the gallery" />`), ["Open the gallery"], prop);
  }
});

test("an object property with a user-facing key is flagged", () => {
  assert.deepEqual(found("const options = [ { label: 'Grid', value: 'grid' } ];", "blocks/x/helpers.ts"), ["Grid"]);
});

test("a literal value that is not text is ignored", () => {
  assert.deepEqual(found("<X label={ name } title={ '' } alt=\"\" />"), []);
  assert.deepEqual(found("const a = { label: '123', value: 'grid' };", "blocks/x/helpers.ts"), []);
});

test("JSX text between tags is flagged", () => {
  assert.deepEqual(found("<p>Open my gallery</p>"), ["Open my gallery"]);
  assert.deepEqual(found("<p>{ __( 'Open', 'profotograaf' ) }</p>"), []);
});

test("TypeScript generics do not look like JSX text", () => {
  assert.deepEqual(found("const a: Array< string > = []; function f( x: Partial< Foo > ) {}"), []);
});

test("DOM text assignments with a literal are flagged", () => {
  assert.deepEqual(found("status.textContent = 'Something failed';", "assets/admin/a.js"), ["Something failed"]);
  assert.deepEqual(found("el.innerHTML = \"Saved now\";", "assets/admin/a.js"), ["Saved now"]);
  assert.deepEqual(found("window.alert( 'Are you sure' );", "assets/admin/a.js"), ["Are you sure"]);
  assert.deepEqual(found("status.textContent = config.i18n.error;", "assets/admin/a.js"), []);
});

test("a line marked i18n-ignore passes", () => {
  assert.deepEqual(found("<X label=\"Heading\" /> // i18n-ignore"), []);
});

test("findings carry the line number", () => {
  const [hit] = findHardcodedStrings("const a = 1;\n<X label=\"Heading\" />\n", "blocks/x/edit.tsx");
  assert.equal(hit?.line, 2);
});

test("scanTree reads blocks and assets, skips tests and declarations", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "i18n-"));
  const write = (p, body) => {
    fs.mkdirSync(path.dirname(path.join(dir, p)), { recursive: true });
    fs.writeFileSync(path.join(dir, p), body);
  };
  write("blocks/a/edit.tsx", "<X label=\"Heading\" />");
  write("blocks/a/edit.test.tsx", "<X label=\"Heading\" />");
  write("blocks/test-support/s.ts", "const a = { label: 'Heading' };");
  write("blocks/globals.d.ts", "declare const a: { label: 'Heading' };");
  write("assets/admin/a.js", "el.textContent = 'Failed here';");
  write("assets/admin/a.css", "a { content: 'Not scanned'; }");
  const hits = scanTree(dir);
  assert.deepEqual(hits.map((h) => h.file).sort((a, b) => a.localeCompare(b)), ["assets/admin/a.js", "blocks/a/edit.tsx"]);
});

const pot = (date, body = "msgid \"A\"\nmsgstr \"\"\n") =>
  `msgid ""\nmsgstr ""\n"POT-Creation-Date: ${date}\\n"\n"X-Domain: profotograaf\\n"\n\n${body}`;

test("pot files that differ only in the creation date match", () => {
  assert.equal(comparePot(pot("2026-01-01T00:00:00+00:00"), pot("2026-02-02T00:00:00+00:00")), null);
});

test("a stale pot is reported with the first differing line", () => {
  const msg = comparePot(pot("2026-01-01"), pot("2026-01-01", "msgid \"B\"\nmsgstr \"\"\n"));
  assert.match(msg, /msgid "A"/);
  assert.match(msg, /msgid "B"/);
});

test("line endings do not matter", () => {
  assert.equal(comparePot(pot("a").replace(/\n/g, "\r\n"), pot("b")), null);
});

test("the pot subcommand exits 1 on a stale file and 0 on a fresh one", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "i18n-"));
  const a = path.join(dir, "a.pot");
  const b = path.join(dir, "b.pot");
  fs.writeFileSync(a, pot("1"));
  fs.writeFileSync(b, pot("2"));
  assert.equal(spawnSync(process.execPath, [script, "pot", a, b]).status, 0);
  fs.writeFileSync(b, pot("2", "msgid \"B\"\nmsgstr \"\"\n"));
  assert.equal(spawnSync(process.execPath, [script, "pot", a, b]).status, 1);
});

test("the strings subcommand exits 1 on a hardcoded string", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "i18n-"));
  fs.mkdirSync(path.join(dir, "blocks"), { recursive: true });
  fs.writeFileSync(path.join(dir, "blocks/e.tsx"), "<X label=\"Heading\" />");
  assert.equal(spawnSync(process.execPath, [script, "strings", dir]).status, 1);
  fs.writeFileSync(path.join(dir, "blocks/e.tsx"), "<X label={ __( 'Heading', 'profotograaf' ) } />");
  assert.equal(spawnSync(process.execPath, [script, "strings", dir]).status, 0);
});

test("the real repo has no hardcoded strings", () => {
  assert.deepEqual(scanTree("."), []);
});
