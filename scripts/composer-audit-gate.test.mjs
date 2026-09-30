import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { findings, judge } from "./composer-audit-gate.mjs";

const script = fileURLToPath(new URL("./composer-audit-gate.mjs", import.meta.url));
const report = (severity) => ({
  advisories: { "acme/pkg": [{ advisoryId: "PKSA-1", title: "Bad thing", severity }] },
  abandoned: [],
});

test("an empty report (composer prints [] for none) passes", () => {
  assert.deepEqual(judge({ advisories: [], abandoned: [] }).blocking, []);
  assert.deepEqual(findings({}), []);
});

test("high and critical block", () => {
  assert.equal(judge(report("high")).blocking.length, 1);
  assert.equal(judge(report("critical")).blocking.length, 1);
});

test("low and medium are reported but do not block", () => {
  for (const s of ["low", "medium", "moderate"]) {
    const r = judge(report(s));
    assert.equal(r.blocking.length, 0, s);
    assert.equal(r.ignored.length, 1, s);
  }
});

test("an advisory without a severity blocks", () => {
  const r = judge({ advisories: { "acme/pkg": [{ advisoryId: "X", title: "t" }] } });
  assert.equal(r.blocking.length, 1);
  assert.equal(r.blocking[0].severity, "unrated");
});

test("severity is case-insensitive", () => {
  assert.equal(judge(report("HIGH")).blocking.length, 1);
});

function run(input) {
  return spawnSync(process.execPath, [script], { input: JSON.stringify(input), encoding: "utf8" });
}

test("the CLI exits 1 on a high advisory and 0 on a medium one", () => {
  assert.equal(run(report("high")).status, 1);
  const ok = run(report("medium"));
  assert.equal(ok.status, 0);
  assert.match(ok.stdout, /::warning::/);
});
