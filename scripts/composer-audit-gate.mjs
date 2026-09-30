#!/usr/bin/env node
// Fails on high and critical Composer advisories only.
//
// `composer audit` exits non-zero for any advisory at all, including low and
// medium ones nobody can act on the same day. This reads its JSON and blocks
// on high and critical. An advisory with no severity is treated as blocking,
// because "unrated" must not read as "harmless". Lower severities are printed
// as warnings.
//
//   composer audit --format=json | node scripts/composer-audit-gate.mjs

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const BLOCKING = new Set(["high", "critical"]);
const KNOWN = new Set(["low", "medium", "moderate", "high", "critical"]);

/** Flattens `composer audit --format=json` into [{ pkg, id, title, severity }]. */
export function findings(report) {
  const advisories = report.advisories ?? {};
  // Composer prints [] rather than {} when there are none.
  const groups = Array.isArray(advisories) ? [] : Object.entries(advisories);
  return groups.flatMap(([pkg, list]) =>
    (Array.isArray(list) ? list : Object.values(list)).map((a) => ({
      pkg,
      id: a.advisoryId ?? a.cve ?? "unknown",
      title: a.title ?? "",
      severity: KNOWN.has(String(a.severity ?? "").toLowerCase()) ? String(a.severity).toLowerCase() : "unrated",
    })),
  );
}

export function judge(report) {
  const all = findings(report);
  const blocking = all.filter((f) => BLOCKING.has(f.severity) || f.severity === "unrated");
  return { blocking, ignored: all.filter((f) => !blocking.includes(f)) };
}

function main() {
  const raw = fs.readFileSync(0, "utf8");
  const { blocking, ignored } = judge(JSON.parse(raw));
  for (const f of ignored) console.log(`::warning::composer advisory (${f.severity}) ${f.pkg} ${f.id} ${f.title}`);
  if (blocking.length === 0) {
    console.log(`composer audit: no high or critical advisories (${ignored.length} lower)`);
    return;
  }
  console.error(`composer audit: ${blocking.length} high, critical or unrated advisories`);
  for (const f of blocking) console.error(`  ${f.severity}  ${f.pkg}  ${f.id}  ${f.title}`);
  process.exit(1);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  main();
}
