import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { FLOORS_FILE, SLACK_PP, floorTooTight, judge, phpTotal, tsTotal } from "./coverage-floor.mjs";

const script = fileURLToPath(new URL("./coverage-floor.mjs", import.meta.url));
const root = fileURLToPath(new URL("..", import.meta.url));

test("tsTotal reads the line percentage from a vitest summary", () => {
  assert.equal(tsTotal({ total: { lines: { pct: 91.5 } } }), 91.5);
});

test("phpTotal uses the project metrics, the last <metrics> element", () => {
  const xml = `<coverage><project><file name="a"><metrics statements="10" coveredstatements="5"/></file>
    <metrics files="1" statements="20" coveredstatements="15"/></project></coverage>`;
  assert.equal(phpTotal(xml), 75);
  assert.throws(() => phpTotal("<coverage/>"));
});

test("a drop under the floor is a warning, never a failure", () => {
  const [r] = judge({ ts: 70 }, { ts: 90 });
  assert.equal(r.level, "warning");
  assert.match(r.message, /below the 90% floor/);
});

test("a comfortable margin is ok, a large one asks for the floor to rise", () => {
  assert.equal(judge({ ts: 91 }, { ts: 90 })[0].level, "ok");
  assert.equal(judge({ ts: 99 }, { ts: 90 })[0].level, "notice");
});

test("a suite without a floor warns", () => {
  assert.equal(judge({ php: 50 }, {})[0].level, "warning");
});

test("a floor closer than 1pp to the measured value is flagged", () => {
  assert.deepEqual(floorTooTight({ ts: 90.5 }, { ts: 90 }), [["ts", 90]]);
  assert.deepEqual(floorTooTight({ ts: 95 }, { ts: 90 }), []);
  assert.equal(SLACK_PP, 1);
});

test("the recorded floors are sane numbers", () => {
  const floors = JSON.parse(fs.readFileSync(`${root}${FLOORS_FILE}`, "utf8"));
  for (const [suite, floor] of Object.entries(floors)) {
    assert.ok(floor > 0 && floor <= 99, `${suite} floor ${floor} must be in (0, 99]`);
  }
});

test("the CLI exits 0 even when coverage is far below the floor", () => {
  const tmp = fs.mkdtempSync(`${root}/.covfloor-`);
  try {
    fs.writeFileSync(`${tmp}/s.json`, JSON.stringify({ total: { lines: { pct: 1 } } }));
    const res = spawnSync(process.execPath, [script, "--ts", `${tmp}/s.json`], { cwd: root, encoding: "utf8" });
    assert.equal(res.status, 0);
    assert.match(res.stdout, /::warning::ts coverage fell/);
  } finally {
    fs.rmSync(tmp, { recursive: true, force: true });
  }
});
