// bin/bugbarn-report.sh posts release markers and CI errors to BugBarn (#251).
// The tests run the script against a local server that records the requests.
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import http from "node:http";
import { tmpdir } from "node:os";
import path from "node:path";
import { after, before, describe, it } from "node:test";
import { fileURLToPath } from "node:url";

const script = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "bin", "bugbarn-report.sh");

let server;
let base;
let requests = [];

before(async () => {
  server = http.createServer((req, res) => {
    let body = "";
    req.on("data", (chunk) => (body += chunk));
    req.on("end", () => {
      requests.push({ method: req.method, url: req.url, headers: req.headers, body: body ? JSON.parse(body) : null });
      res.writeHead(202, { "content-type": "application/json" });
      res.end("{}");
    });
  });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => server.close());

function run(args, env = {}, input = "") {
  requests = [];
  return new Promise((resolve) => {
    const child = spawn("bash", [script, ...args], { env: { PATH: process.env.PATH, ...env } });
    let out = "";
    child.stdout.on("data", (d) => (out += d));
    child.stderr.on("data", (d) => (out += d));
    child.on("close", (code) => resolve({ code, out }));
    // A script that exits without reading stdin (no BugBarn configuration) closes the pipe early.
    child.stdin.on("error", () => {});
    child.stdin.end(input);
  });
}

const configured = () => ({ BUGBARN_ENDPOINT: base, BUGBARN_API_KEY: "key-1", GITHUB_SHA: "abc123", GITHUB_RUN_ID: "42", GITHUB_REPOSITORY: "webwiebe/profotograaf-wordpress" });

describe("bugbarn-report.sh", () => {
  it("posts a release marker for a tag", async () => {
    const { code } = await run(["release", "v1.2.3"], configured());
    assert.equal(code, 0);
    assert.equal(requests.length, 1);
    const [request] = requests;
    assert.equal(request.method, "POST");
    assert.equal(request.url, "/api/v1/releases");
    assert.equal(request.headers["x-bugbarn-api-key"], "key-1");
    assert.equal(request.headers["x-bugbarn-project"], "profotograaf-wordpress");
    assert.deepEqual(request.body, { name: "v1.2.3", environment: "production", version: "1.2.3", commitSha: "abc123" });
  });

  it("posts a CI error as an event of the project", async () => {
    const { code } = await run(["event", "E2E failed (WordPress 7.1)"], configured());
    assert.equal(code, 0);
    const [request] = requests;
    assert.equal(request.url, "/api/v1/events");
    assert.equal(request.headers["x-bugbarn-project"], "profotograaf-wordpress");
    assert.equal(request.body.severityText, "ERROR");
    assert.equal(request.body.body, "E2E failed (WordPress 7.1)");
    assert.equal(request.body.exception.message, "E2E failed (WordPress 7.1)");
    assert.equal(request.body.attributes.environment, "ci");
    assert.equal(request.body.attributes.run, "https://github.com/webwiebe/profotograaf-wordpress/actions/runs/42");
  });

  it("reports one event per PHP error line of a debug log on stdin, and none for notices", async () => {
    const log = [
      "[13-Oct-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/html/wp-content/plugins/profotograaf/x.php:3",
      "[13-Oct-2026 10:00:01 UTC] PHP Deprecated:  something in /var/www/html/wp-includes/y.php on line 2",
      "[13-Oct-2026 10:00:02 UTC] PHP Warning:  Undefined variable $a in /var/www/html/wp-content/plugins/profotograaf/z.php on line 9",
      "[13-Oct-2026 10:00:03 UTC] PHP Warning:  Undefined variable $a in /var/www/html/wp-content/plugins/profotograaf/z.php on line 9",
    ].join("\n");
    const { code } = await run(["debug-log"], configured(), log);
    assert.equal(code, 0);
    assert.equal(requests.length, 2);
    assert.match(requests[0].body.body, /Uncaught Error: boom/);
    assert.match(requests[1].body.body, /Undefined variable \$a/);
  });

  it("does nothing, and succeeds, without an endpoint or key", async () => {
    const { code, out } = await run(["release", "v1.0.0"], {});
    assert.equal(code, 0);
    assert.equal(requests.length, 0);
    assert.match(out, /BugBarn is not configured/);
  });

  it("fails on an unknown command", async () => {
    const { code } = await run(["nope"], configured());
    assert.notEqual(code, 0);
  });

  describe("e2e", () => {
    const spec = (ok) => ({ title: "a spec", ok, tests: [] });
    const results = {
      suites: [
        { title: "gallery.spec.js", file: "gallery.spec.js", specs: [], suites: [{ title: "block", specs: [spec(true), spec(false)] }] },
        { title: "embed-visual.spec.js", file: "embed-visual.spec.js", specs: [spec(false)], suites: [] },
        { title: "backup-status.spec.js", file: "backup-status.spec.js", specs: [spec(true)], suites: [] },
      ],
    };
    const withResults = async (data, fn) => {
      const dir = await mkdtemp(path.join(tmpdir(), "bb-e2e-"));
      const file = path.join(dir, "results.json");
      if (data) await writeFile(file, JSON.stringify(data));
      try {
        return await fn(file);
      } finally {
        await rm(dir, { recursive: true });
      }
    };

    it("names the WordPress version and every failed spec file, and only those", async () => {
      await withResults(results, async (file) => {
        const { code } = await run(["e2e", "7.1", file], configured());
        assert.equal(code, 0);
        const [request] = requests;
        assert.equal(request.body.body, "E2E failed on WordPress 7.1 (wp71): embed-visual.spec.js, gallery.spec.js");
        assert.equal(request.body.exception.message, request.body.body);
        assert.equal(request.body.severityText, "ERROR");
      });
    });

    it("keeps two WordPress versions apart once BugBarn has collapsed the numbers", async () => {
      await withResults(results, async (file) => {
        await run(["e2e", "6.9", file], configured());
        assert.match(requests[0].body.body, /\(wp69\)/);
      });
    });

    it("sends a leg that gates no release at the severity it is given", async () => {
      await withResults(results, async (file) => {
        await run(["e2e", "7.0", file, "WARNING"], configured());
        assert.equal(requests[0].body.severityText, "WARNING");
      });
    });

    it("still reports a run that left no result", async () => {
      await withResults(null, async (file) => {
        const { code } = await run(["e2e", "7.1", file], configured());
        assert.equal(code, 0);
        assert.equal(requests[0].body.body, "E2E failed on WordPress 7.1 (wp71): the run stopped before any spec result");
      });
    });
  });

  it("fails when BugBarn refuses the post, so a broken marker is seen", async () => {
    const { code } = await run(["release", "v1.0.0"], { ...configured(), BUGBARN_ENDPOINT: "http://127.0.0.1:1" });
    assert.notEqual(code, 0);
  });
});
