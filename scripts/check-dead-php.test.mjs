// The PHP dead-code gate's own test. It proves the gate can fail: a new unused
// symbol fails, a baselined symbol that is used again fails as stale, and a
// symbol used from a hook string, another file or the module directory passes.

import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { compare, definitions, findDead } from "./check-dead-php.mjs";

const script = fileURLToPath(new URL("./check-dead-php.mjs", import.meta.url));

function repo(files, baseline = "") {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "dead-php-"));
  for (const [rel, body] of Object.entries({ ...files, "scripts/dead-php-baseline.txt": baseline })) {
    fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true });
    fs.writeFileSync(path.join(root, rel), body);
  }
  return root;
}

const run = (root, ...args) => spawnSync("node", [script, ...args], { cwd: root, encoding: "utf8" });

test("definitions finds classes, functions and constants and skips magic methods", () => {
  const found = definitions(`<?php
class Box {
	const SIZE = 1;
	public function open() {}
	public function __construct() {}
}`);
  assert.deepEqual(found.map((d) => `${d.kind} ${d.name}`).sort((a, b) => a.localeCompare(b)), ["class Box", "const SIZE", "function open"]);
});

test("a symbol used from another file or from a hook string is alive", () => {
  const root = repo({
    "includes/class-a.php": "<?php class A { public function used() {} public function hooked() {} public function unused_one() {} }",
    "includes/class-b.php": "<?php $a->used(); add_action( 'init', array( $this, 'hooked' ) ); new A();",
  });
  assert.deepEqual(findDead(root), ["function unused_one includes/class-a.php"]);
});

test("a comment mention does not keep a symbol alive", () => {
  const root = repo({
    "includes/class-a.php": "<?php class A { public function ghost() {} }",
    "includes/class-b.php": "<?php // calls ghost() someday\nnew A();",
  });
  assert.deepEqual(findDead(root), ["function ghost includes/class-a.php"]);
});

test("a symbol only a unit test uses is alive", () => {
  const root = repo({
    "includes/class-a.php": "<?php class A { public static function seam() {} } new A();",
    "tests/unit/A_Test.php": "<?php A::seam();",
  });
  assert.deepEqual(findDead(root), []);
});

test("module classes are discovered by directory and never reported", () => {
  const root = repo({ "includes/modules/class-thing.php": "<?php class Thing {}" });
  assert.deepEqual(findDead(root), []);
});

test("compare reports new findings and stale baseline entries", () => {
  assert.deepEqual(compare(["a", "b"], ["b", "c"]), { added: ["a"], stale: ["c"] });
});

test("the script exits 1 on a new finding and names it", () => {
  const root = repo({ "includes/class-a.php": "<?php class A { public function dead() {} } new A();" });
  const result = run(root);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /NEW\s+function dead includes\/class-a\.php/);
});

test("the script exits 1 on a stale baseline entry", () => {
  const root = repo(
    { "includes/class-a.php": "<?php class A { public function live() {} } new A(); (new A())->live();" },
    "function live includes/class-a.php\n",
  );
  const result = run(root);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /STALE\s+function live/);
});

test("the script exits 0 when the findings equal the baseline", () => {
  const root = repo(
    { "includes/class-a.php": "<?php class A { public function dead() {} } new A();" },
    "# comment\nfunction dead includes/class-a.php\n",
  );
  assert.equal(run(root).status, 0);
});

test("--report prints the findings and exits 0", () => {
  const root = repo({ "includes/class-a.php": "<?php class A { public function dead() {} } new A();" });
  const result = run(root, "--report");
  assert.equal(result.status, 0);
  assert.match(result.stdout, /NEW\s+function dead/);
  assert.match(result.stdout, /report only/);
});

test("--update-baseline writes the findings and the next check passes", () => {
  const root = repo({ "includes/class-a.php": "<?php class A { public function dead() {} } new A();" });
  assert.equal(run(root, "--update-baseline").status, 0);
  assert.equal(run(root).status, 0);
});

test("the committed baseline matches the repo", () => {
  const root = path.resolve(path.dirname(script), "..");
  const result = run(root);
  assert.equal(result.status, 0, result.stdout);
});
