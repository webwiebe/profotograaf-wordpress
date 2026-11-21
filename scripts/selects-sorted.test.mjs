// The selects gate's own test. It proves the gate can fail: an editor file that
// imports SelectControl, a PHP file that prints a select, and an Elementor or
// Divi select without Select_Options all show up. It also runs on the real tree,
// so a new unsorted select fails `pnpm test:gates` (#407).

import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { findUnsorted } from "./check-selects-sorted.mjs";

function repo(files) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "selects-sorted-"));
  for (const [rel, body] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true });
    fs.writeFileSync(path.join(root, rel), body);
  }
  return root;
}

test("a block that imports SelectControl itself fails", () => {
  const root = repo({ "blocks/gallery/new-panel.tsx": "import { SelectControl } from '@wordpress/components';" });
  assert.deepEqual(findUnsorted(root).map((f) => f.file), ["blocks/gallery/new-panel.tsx"]);
});

test("the wrapper, tests and test support may name SelectControl", () => {
  const root = repo({
    "blocks/select-order/sorted-select.tsx": "import { SelectControl } from '@wordpress/components';",
    "blocks/gallery/panel.test.tsx": "SelectControl",
    "blocks/test-support/wp-mocks.tsx": "SelectControl",
    "blocks/gallery/panel.tsx": "import { SortedSelect } from '../select-order/sorted-select';",
  });
  assert.deepEqual(findUnsorted(root), []);
});

test("a PHP file that prints a select without Select_Options fails", () => {
  const root = repo({
    "includes/admin/class-new.php": "<?php echo '<select name=\"a\"></select>';",
    "includes/class-elementor.php": "<?php $this->add_control( 'x', array( 'type' => Controls_Manager::SELECT ) );",
    "includes/class-divi.php": "<?php return array( 'type' => 'select' );",
  });
  assert.deepEqual(
    findUnsorted(root).map((f) => f.file),
    ["includes/admin/class-new.php", "includes/class-divi.php", "includes/class-elementor.php"],
  );
});

test("a PHP file that sorts or names a natural order passes", () => {
  const root = repo({
    "includes/admin/class-sorted.php": "<?php Select_Options::sorted( $a ); echo '<select></select>';",
    "includes/admin/class-natural.php": "<?php Select_Options::natural( $a, 'sizes' ); echo '<select></select>';",
    "includes/class-no-select.php": "<?php echo 'selected';",
  });
  assert.deepEqual(findUnsorted(root), []);
});

test("every select of the plugin goes through the sorting helpers", () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
  assert.deepEqual(findUnsorted(root), []);
});
