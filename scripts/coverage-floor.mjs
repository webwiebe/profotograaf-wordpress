#!/usr/bin/env node
// Total-coverage cliff guard. Deliberately NON-blocking: total coverage
// punishes deleting tested code, so it warns and never fails. The blocking
// coverage gate is scripts/diff-coverage.mjs.
//
// It reads the measured totals, compares them with the floors in
// scripts/coverage-floors.json and writes GitHub annotations plus a step
// summary line:
//
//   - measured below the floor          -> ::warning:: (a cliff: coverage fell)
//   - measured 3pp or more above floor  -> ::notice::  (raise the floor)
//
// A floor sits at least 1.0pp below what was measured, never at it, so a
// refactor that deletes tested code does not trip it.
//
//   node scripts/coverage-floor.mjs --ts coverage/coverage-summary.json --php coverage/php/clover.xml
//   node scripts/coverage-floor.mjs --measure ...     # print measured totals only

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const SLACK_PP = 1.0;
export const RAISE_AT_PP = 3.0;
export const FLOORS_FILE = "scripts/coverage-floors.json";

export function tsTotal(summaryJson) {
  return summaryJson.total.lines.pct;
}

export function phpTotal(xml) {
  const all = [...xml.matchAll(/<metrics\b[^>]*\bstatements="(\d+)"[^>]*\bcoveredstatements="(\d+)"/g)];
  const last = all.at(-1);
  if (!last) throw new Error("no <metrics> element with statements in the clover report");
  const statements = Number(last[1]);
  return statements === 0 ? 100 : (Number(last[2]) / statements) * 100;
}

/** Returns one { level, message } per suite. Pure. */
export function judge(measured, floors) {
  const out = [];
  for (const [suite, pct] of Object.entries(measured)) {
    const floor = floors[suite];
    if (floor === undefined) {
      out.push({ level: "warning", message: `${suite}: no floor recorded in ${FLOORS_FILE}` });
    } else if (pct < floor) {
      out.push({ level: "warning", message: `${suite} coverage fell to ${pct.toFixed(1)}%, below the ${floor}% floor` });
    } else if (pct - floor >= RAISE_AT_PP + SLACK_PP) {
      out.push({ level: "notice", message: `${suite} coverage is ${pct.toFixed(1)}%, the ${floor}% floor can rise to ${Math.floor(pct - SLACK_PP)}%` });
    } else {
      out.push({ level: "ok", message: `${suite} coverage ${pct.toFixed(1)}% (floor ${floor}%)` });
    }
  }
  return out;
}

/** A floor must sit at least SLACK_PP under the measured value. */
export function floorTooTight(measured, floors) {
  return Object.entries(floors).filter(([suite, floor]) => measured[suite] !== undefined && floor > measured[suite] - SLACK_PP);
}

function arg(name) {
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? undefined : process.argv[i + 1];
}

function main() {
  const measured = {};
  if (arg("ts")) measured.ts = tsTotal(JSON.parse(fs.readFileSync(arg("ts"), "utf8")));
  if (arg("php")) measured.php = phpTotal(fs.readFileSync(arg("php"), "utf8"));

  if (process.argv.includes("--measure")) {
    for (const [suite, pct] of Object.entries(measured)) console.log(`${suite} ${pct.toFixed(2)}`);
    return;
  }
  const floors = JSON.parse(fs.readFileSync(FLOORS_FILE, "utf8"));
  const lines = [];
  for (const { level, message } of judge(measured, floors)) {
    console.log(level === "ok" ? message : `::${level}::${message}`);
    lines.push(`- ${message}`);
  }
  for (const [suite, floor] of floorTooTight(measured, floors)) {
    console.log(`::warning::${suite} floor ${floor}% is within 1pp of measured, lower it`);
  }
  if (process.env.GITHUB_STEP_SUMMARY) {
    fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `### Total coverage (cliff guard, non-blocking)\n${lines.join("\n")}\n`);
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main();
}
