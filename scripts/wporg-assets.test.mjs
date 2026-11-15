// The wordpress.org art rules as a test: the icon and banner files exist in
// assets/wporg, are real PNG files and have the exact pixel sizes the plugin
// directory asks for. The size is read from the PNG header, so no image
// library is needed.

import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const dir = fileURLToPath(new URL("../assets/wporg/", import.meta.url));
const SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

const REQUIRED = {
  "icon-128x128.png": [128, 128],
  "icon-256x256.png": [256, 256],
  "banner-772x250.png": [772, 250],
  "banner-1544x500.png": [1544, 500],
};

function pngSize(file) {
  const head = fs.readFileSync(file).subarray(0, 24);
  assert.ok(head.subarray(0, 8).equals(SIGNATURE), `${path.basename(file)} is not a PNG file`);
  assert.equal(head.subarray(12, 16).toString("ascii"), "IHDR", `${path.basename(file)} has no IHDR chunk`);
  return [head.readUInt32BE(16), head.readUInt32BE(20)];
}

for (const [name, [width, height]] of Object.entries(REQUIRED)) {
  test(`${name} is a PNG of exactly ${width} x ${height}`, () => {
    const file = path.join(dir, name);
    assert.ok(fs.existsSync(file), `${name} is missing from assets/wporg`);
    assert.deepEqual(pngSize(file), [width, height]);
  });
}

test("every screenshot in assets/wporg is a PNG", () => {
  for (const name of fs.readdirSync(dir).filter((n) => /^screenshot-\d+\.png$/.test(n))) {
    pngSize(path.join(dir, name));
  }
});
