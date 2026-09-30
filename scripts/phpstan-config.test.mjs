// Guards phpstan.neon.dist so the baseline stays a ratchet. Without
// reportUnmatchedIgnoredErrors a fixed error would linger in the baseline and
// nothing would fail; an inline ignore would bypass the baseline entirely.

import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const neon = fs.readFileSync("phpstan.neon.dist", "utf8");

test("unmatched ignored errors are reported, so a fixed error must leave the baseline", () => {
  assert.match(neon, /reportUnmatchedIgnoredErrors:\s*true/);
});

test("the level is at least 8", () => {
  const m = /^\s*level:\s*(\d+|max)\s*$/m.exec(neon);
  assert.ok(m, "no level set");
  assert.ok(m[1] === "max" || Number(m[1]) >= 8, `level ${m[1]} is below 8`);
});

test("the baseline is the only source of ignored errors", () => {
  assert.match(neon, /phpstan-baseline\.neon/);
  assert.doesNotMatch(neon, /ignoreErrors:/);
});

test("the baseline file exists", () => {
  assert.ok(fs.existsSync("phpstan-baseline.neon"));
});
