import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { parse } from "yaml";
import { checkAll, checkContinueOnError, checkDeployGraph, checkPins } from "./check-workflows.mjs";

const script = fileURLToPath(new URL("./check-workflows.mjs", import.meta.url));
const repoWorkflows = fileURLToPath(new URL("../.github/workflows", import.meta.url));
const wf = (text) => parse(text);

const graph = (extra = "") => wf(`
jobs:
  lint:
    steps:
      - run: vendor/bin/phpcs
  phpunit:
    steps:
      - run: vendor/bin/phpunit
  release:
    needs: [lint, phpunit]
    steps:
      - run: gh release create v1
${extra}`);

test("a release job that needs a quality job and a test job passes", () => {
  assert.deepEqual(checkDeployGraph("r.yml", graph()), []);
});

test("a release job with no needs fails on both counts", () => {
  const w = wf(`
jobs:
  release:
    steps:
      - run: gh release create v1
`);
  const p = checkDeployGraph("r.yml", w);
  assert.equal(p.length, 2);
  assert.match(p[0], /test job/);
  assert.match(p[1], /quality job/);
});

test("needs only a lint job still fails the test requirement", () => {
  const w = wf(`
jobs:
  lint:
    steps:
      - run: vendor/bin/phpcs
  release:
    needs: lint
    steps:
      - run: gh release create v1
`);
  const p = checkDeployGraph("r.yml", w);
  assert.equal(p.length, 1);
  assert.match(p[0], /test job/);
});

test("a path through an intermediate job counts", () => {
  const w = wf(`
jobs:
  phpunit:
    steps:
      - run: vendor/bin/phpunit && vendor/bin/phpcs
  build:
    needs: phpunit
    steps:
      - run: pnpm build
  deploy:
    needs: build
    steps:
      - run: echo go
`);
  assert.deepEqual(checkDeployGraph("r.yml", w), []);
});

test("a job named like a release is gated even when its script is opaque", () => {
  const w = wf(`
jobs:
  deploy-prod:
    steps:
      - run: ./ship.sh
`);
  assert.equal(checkDeployGraph("r.yml", w).length, 2);
});

test("a reusable workflow is classified by the jobs inside it", () => {
  const ci = wf(`
jobs:
  a:
    steps:
      - run: vendor/bin/phpunit
      - run: vendor/bin/phpstan analyse
`);
  const caller = wf(`
jobs:
  ci:
    uses: ./.github/workflows/ci.yml
  release:
    needs: ci
    steps:
      - run: gh release create v1
`);
  const resolve = (u) => (u.endsWith("ci.yml") ? ci : undefined);
  assert.deepEqual(checkDeployGraph("release.yml", caller, resolve), []);
  assert.equal(checkDeployGraph("release.yml", caller).length, 2, "an unresolved reusable workflow proves nothing");
});

test("continue-on-error is rejected at job and step level", () => {
  const w = wf(`
jobs:
  a:
    continue-on-error: true
    steps:
      - run: x
        continue-on-error: false
`);
  assert.equal(checkContinueOnError("w.yml", w).length, 2);
});

test("action pins", () => {
  const ok = wf(`
jobs:
  a:
    steps:
      - uses: actions/checkout@v7
      - uses: 10up/action-wordpress-plugin-deploy@2.3.0
      - uses: some/action@0123456789abcdef0123456789abcdef01234567
      - uses: ./local
`);
  assert.deepEqual(checkPins("w.yml", ok), []);
  const bad = wf(`
jobs:
  a:
    steps:
      - uses: actions/checkout@main
      - uses: 10up/action-wordpress-plugin-deploy@stable
      - uses: actions/setup-node
`);
  assert.equal(checkPins("w.yml", bad).length, 3);
});

test("the repository's own workflows pass every check", () => {
  const { files, problems } = checkAll(repoWorkflows);
  assert.ok(files.length >= 2);
  assert.deepEqual(problems, []);
});

test("the CLI exits 1 on a bad workflow directory", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "wf-"));
  try {
    fs.writeFileSync(path.join(dir, "bad.yml"), "jobs:\n  release:\n    steps:\n      - run: gh release create v1\n");
    const res = spawnSync(process.execPath, [script, dir], { encoding: "utf8" });
    assert.equal(res.status, 1);
    assert.match(res.stderr, /releases without a needs/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
