#!/usr/bin/env node
// Static checks on .github/workflows, so the CI contract cannot rot silently.
//
//   1. Deploy graph: every release or deploy job has a `needs:` path to a test
//      job AND to a quality job. A reusable workflow called with `uses:` counts
//      for whatever its own jobs are.
//   2. No `continue-on-error`. A gate that may fail without failing the build
//      is not a gate.
//   3. Every `uses:` is pinned: a major or full version tag, or a commit SHA.
//      Never a branch such as @main or @stable.
//
//   node scripts/check-workflows.mjs [workflow dir]

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parse } from "yaml";

const TEST_RE = /\b(phpunit|vitest|playwright test|test:e2e|pnpm (run )?test\b|test:gates)/;
const QUALITY_RE = /\b(phpcs|phpstan|oxlint|oxlint-ratchet|tsc\b|pnpm (run )?(lint|typecheck)|check-file-length)/;
const RELEASE_RE = /(gh release create|action-wordpress-plugin-deploy|svn commit)/;
const RELEASE_ID_RE = /^(release|deploy|publish)/i;
const PIN_RE = /@(v?\d+(\.\d+){0,2}|[0-9a-f]{40})$/;

export function jobText(job) {
  return (job.steps ?? []).map((s) => `${s.run ?? ""} ${s.uses ?? ""}`).join("\n");
}

export function needsOf(job) {
  const n = job.needs ?? [];
  return Array.isArray(n) ? n : [n];
}

/**
 * Classify one job. `resolve(uses)` returns the parsed workflow behind a local
 * reusable-workflow reference, or undefined.
 */
export function classify(job, resolve, seen = new Set()) {
  const text = jobText(job);
  const kinds = { test: TEST_RE.test(text), quality: QUALITY_RE.test(text), release: RELEASE_RE.test(text) };
  if (typeof job.uses === "string" && job.uses.startsWith("./")) {
    const called = resolve(job.uses);
    if (called && !seen.has(job.uses)) {
      seen.add(job.uses);
      for (const inner of Object.values(called.jobs ?? {})) {
        const k = classify(inner, resolve, seen);
        kinds.test ||= k.test;
        kinds.quality ||= k.quality;
      }
    }
  }
  return kinds;
}

/** Which kinds of job sit anywhere upstream of `id` in the needs graph. */
function reachable(jobs, kinds, id) {
  const reach = { test: false, quality: false };
  const stack = [...needsOf(jobs[id])];
  const visited = new Set();
  while (stack.length) {
    const dep = stack.pop();
    if (visited.has(dep) || !jobs[dep]) continue;
    visited.add(dep);
    reach.test ||= kinds[dep].test;
    reach.quality ||= kinds[dep].quality;
    stack.push(...needsOf(jobs[dep]));
  }
  return reach;
}

/** Returns a list of problem strings for one workflow. */
export function checkDeployGraph(name, workflow, resolve = () => undefined) {
  const jobs = workflow.jobs ?? {};
  const kinds = Object.fromEntries(Object.entries(jobs).map(([id, job]) => [id, classify(job, resolve)]));
  const problems = [];
  for (const id of Object.keys(jobs)) {
    if (!kinds[id].release && !RELEASE_ID_RE.test(id)) continue;
    const reach = reachable(jobs, kinds, id);
    if (!reach.test) problems.push(`${name}: job "${id}" releases without a needs: path to a test job`);
    if (!reach.quality) problems.push(`${name}: job "${id}" releases without a needs: path to a quality job`);
  }
  return problems;
}

export function checkContinueOnError(name, workflow) {
  const problems = [];
  for (const [id, job] of Object.entries(workflow.jobs ?? {})) {
    if (job["continue-on-error"] !== undefined) problems.push(`${name}: job "${id}" sets continue-on-error`);
    for (const step of job.steps ?? []) {
      if (step["continue-on-error"] !== undefined) {
        problems.push(`${name}: a step in job "${id}" sets continue-on-error`);
      }
    }
  }
  return problems;
}

export function checkPins(name, workflow) {
  const problems = [];
  for (const [id, job] of Object.entries(workflow.jobs ?? {})) {
    const refs = [job.uses, ...(job.steps ?? []).map((s) => s.uses)].filter((u) => typeof u === "string");
    for (const ref of refs) {
      if (ref.startsWith("./")) continue;
      if (!PIN_RE.test(ref)) problems.push(`${name}: job "${id}" uses ${ref}, pin it to a version tag or SHA`);
    }
  }
  return problems;
}

export function checkAll(dir) {
  const files = fs.readdirSync(dir).filter((f) => /\.ya?ml$/.test(f));
  const parsed = Object.fromEntries(files.map((f) => [f, parse(fs.readFileSync(path.join(dir, f), "utf8"))]));
  const resolve = (uses) => parsed[path.basename(uses)];
  const problems = [];
  for (const [name, wf] of Object.entries(parsed)) {
    problems.push(...checkDeployGraph(name, wf, resolve), ...checkContinueOnError(name, wf), ...checkPins(name, wf));
  }
  return { files, problems };
}

function main() {
  const dir = process.argv[2] ?? ".github/workflows";
  const { files, problems } = checkAll(dir);
  if (problems.length === 0) {
    console.log(`workflows: ok (${files.length} files)`);
    return;
  }
  console.error(`workflows: ${problems.length} problem(s)`);
  for (const p of problems) console.error(`  ${p}`);
  process.exit(1);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main();
}
