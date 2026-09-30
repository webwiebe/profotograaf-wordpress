# Quality gates

Everything below runs in `ci.yml` on every pull request and push to main, and `release.yml` calls `ci.yml` before it releases anything. `make quality` runs the same checks locally (the Docker E2E and Plugin Check stay in `make e2e` and `make plugin-check`).

Every gate script in `scripts/` has a `*.test.mjs` next to it. CI runs `pnpm test:gates` (all of them) before the gates themselves, because a gate that quietly stopped failing looks exactly like a clean repo.

There is no `continue-on-error` anywhere and no skip flag. `scripts/check-workflows.mjs` enforces that.

## The ratchet rule

Baseline-backed gates (oxlint, file length, PHPStan) share four properties:

1. A new violation fails.
2. A violation that got worse fails.
3. A violation that is gone but still listed in the baseline fails (the baseline must shrink with the code).
4. The only way to change a baseline is its refresh command, which shows up as a diff a reviewer can argue with.

All three baselines are at zero except PHPStan (46 errors, all in `phpstan-baseline.neon`).

## Gates

| Gate | Command | Threshold | Baseline and refresh |
| --- | --- | --- | --- |
| Type check | `pnpm typecheck` (TypeScript 7, `tsc --noEmit`) | strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, no errors | none |
| oxlint | `node scripts/oxlint-ratchet.mjs` | complexity 12, max-depth 4, max-params 4, max-lines-per-function 60, plus correctness and a set of recommended rules | `oxlint-baseline.json`, refresh with `node scripts/oxlint-ratchet.mjs --write` |
| oxlint type-aware | `node scripts/oxlint-ratchet.mjs --type-aware` | type-aware rules (oxlint-tsgolint), same ratchet | `oxlint-type-aware-baseline.json`, refresh with `--type-aware --write` |
| knip | `pnpm knip` | no unused files, exports or dependencies | none, config in `knip.json` |
| File length | `pnpm check:file-length` | 500 lines, TS and PHP sources (tests, vendor and generated output are exempt) | `scripts/file-length-baseline.txt`, refresh with `node scripts/check-file-length.mjs --update-baseline` |
| Workflow rules | `node scripts/check-workflows.mjs` | no `continue-on-error`, every action pinned to a version tag or SHA, release jobs have a `needs:` path to a test job and a quality job (through the reusable `ci.yml`) | none |
| vitest | `pnpm test` | all tests pass | none |
| Diff coverage (TS and PHP) | `node scripts/diff-coverage.mjs --base <sha> ...` | 80% of changed executable lines; diffs under 10 changed executable lines are exempt; a changed file missing from the report counts as uncovered | none |
| Coverage cliff guard | `node scripts/coverage-floor.mjs` | non-blocking: warns when total coverage falls below the floor | floors in `scripts/coverage-floors.json`, keep each at least 1pp below measured |
| PHPCS | `vendor/bin/phpcs` | WordPress Coding Standards | none |
| PHPStan | `vendor/bin/phpstan analyse --memory-limit=1G` | level 8 (`szepeviktor/phpstan-wordpress`) | `phpstan-baseline.neon`; `reportUnmatchedIgnoredErrors: true` gives properties 2 and 3. Refresh with `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon --allow-empty-baseline` |
| PHPUnit | `vendor/bin/phpunit` | all tests pass on PHP 8.1 to 8.4 | none |
| pnpm audit | `pnpm audit --audit-level high` | no high or critical advisory | none |
| composer audit | `composer audit --format=json --locked \| node scripts/composer-audit-gate.mjs` | no high, critical or unrated advisory; lower severities are warnings | none |
| Block build, Plugin Check, E2E | `pnpm build`, `make plugin-check`, `make e2e` | must pass on WordPress 6.9, 7.0, 7.1 | none |

## Diff coverage

`diff-coverage.mjs` reads `git diff -U0` against the base (the pull request base, or the commit before a push to main) and joins the changed lines with the coverage report: istanbul JSON from vitest, clover XML from PHPUnit with pcov. Only lines that hold a statement count, so comments, braces and type-only code never fail it. A tag push has no base to diff against, and the gate says so and passes.

To run it locally against main:

```sh
pnpm test:coverage
make coverage-php
node scripts/diff-coverage.mjs --base origin/main --format istanbul \
  --coverage coverage/coverage-final.json --include '^blocks/.*\.tsx?$' \
  --exclude '\.test\.|/test-support/|\.d\.ts$'
node scripts/diff-coverage.mjs --base origin/main --format clover \
  --coverage coverage/php/clover.xml --include '^(includes/.*|profotograaf|uninstall)\.php$'
```

## Updating a baseline

1. Fix the code first. A baseline entry is a debt, not a setting.
2. If the change fixes existing violations, the gate fails until you shrink the baseline: run the refresh command and commit the smaller file.
3. If a new violation is unavoidable, run the refresh command and explain the entry in the pull request. A reviewer reads the baseline diff.

## Dependencies

`.github/dependabot.yml` opens weekly, grouped pull requests for GitHub Actions, npm (pnpm) and Composer: TypeScript with oxlint and knip, `@wordpress/*`, the test tooling, and PHPCS/WPCS and PHPStan each have a group, and remaining dev dependencies share one. Dependabot waits three days after a release before proposing it.

Two pins live outside the manifests: `pnpm-workspace.yaml` overrides `serialize-javascript` to a patched release (a high advisory in a transitive dependency of `@wordpress/scripts`), and `allowBuilds` there lists the packages whose install scripts stay off.

## Known limits

- `assets/admin/*.js` (about 80 lines of plain JS the plugin ships as is) and the Playwright specs under `tests/e2e/` are JavaScript. They are oxlinted but not type-checked. Converting them to TypeScript is future work.
- `pnpm audit --audit-level high` does not block on moderate or low advisories.
