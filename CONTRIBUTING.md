# Contributing

## Layout

```
profotograaf.php          plugin header and bootstrap, rarely edited
includes/                 classes, autoloaded
includes/modules/         one file per feature module, discovered automatically
blocks/<name>/            one folder per block, discovered automatically (TypeScript)
build/                    block build output (generated, not committed)
assets/admin/             plain CSS and JS the plugin ships as is
assets/wporg/             directory banner, icon and screenshots (not in the zip)
languages/                .pot, .po and .mo files
tests/unit/               PHPUnit with Brain Monkey, no WordPress needed
tests/e2e/                Playwright against a real WordPress in Docker
bin/                      release and build helpers
scripts/                  quality gates and their tests (see docs/quality.md)
docs/quality.md           every gate, its threshold, how to update a baseline
```

## Adding a feature without touching shared files

Two extension points make parallel work conflict free. Neither needs an edit to `profotograaf.php`, the loader or the settings page.

### Modules

A module is a class in `includes/modules/class-<name>.php` named `Profotograaf\Modules\<Name>` that implements `Profotograaf\Module`:

```php
namespace Profotograaf\Modules;

use Profotograaf\Module;
use Profotograaf\Plugin;

class Shortcode implements Module {
	public function register( Plugin $plugin ): void {
		add_shortcode( 'profotograaf_gallery', array( $this, 'render' ) );
	}
}
```

`Module_Loader` finds every `class-*.php` file in that folder (the file `class-lead-bridge.php` must hold `Lead_Bridge`) and calls `register()` on `plugins_loaded`. The constructor must not have side effects: hook in `register()`.

The `profotograaf_modules` filter receives the class list, so a module that lives elsewhere can add itself and a site can remove one.

`register()` receives the `Plugin`, which hands out the shared services:

| Call | Returns |
| --- | --- |
| `$plugin->api()` | `Profotograaf\Api_Client` |
| `$plugin->connection()` | `Profotograaf\Connection` (is_connected, status) |
| `$plugin->settings()` | `Profotograaf\Settings` (default_layout) |
| `$plugin->pairing()` | `Profotograaf\Pairing` (connect flow, used by the settings page) |

### Blocks

Add `blocks/<name>/block.json` with its `index.tsx` (or `index.ts`), `render.php` and styles. Block and editor code is TypeScript. `pnpm build` (`@wordpress/scripts`, used only as the bundler) writes `build/<name>/`. `Modules\Blocks` registers every `blocks/*/block.json`, from `build/<name>` when it exists. Nothing else changes. Blocks use the text domain `profotograaf`.

### Hooks other code can use

| Hook | Kind | Meaning |
| --- | --- | --- |
| `profotograaf_modules` | filter | Module class names |
| `profotograaf_block_directories` | filter | Block directories to register |
| `profotograaf_platform_url` | filter | Platform base URL (default `https://profotograaf.nl`), also the `PROFOTOGRAAF_PLATFORM_URL` constant |
| `profotograaf_http_timeout` | filter | Request timeout in seconds, 1 to 30, default 10 |
| `profotograaf_connected` | action | The site just connected |
| `profotograaf_disconnected` | action | The connection ended, argument `user` or `revoked` |
| `profotograaf_loaded` | action | All modules registered |
| `profotograaf_refresh_failed` | action | The background token refresh failed, argument `WP_Error` |
| `profotograaf_mark_embeddable_request` | filter | Supplies the request that marks a gallery embeddable, once the platform allows it |
| `profotograaf_origin_sync_endpoint` | filter | Path that adds this site's origin to the allowed embed origins (default `/api/v1/embed/origins`), or null to only verify |

## Talking to the platform

Use `Api_Client`. Do not call `wp_remote_*` yourself: the client adds the bearer token, refreshes it when needed, sets timeouts and turns every failure into a `WP_Error`.

```php
$rows = $plugin->api()->list_galleries();   // list of arrays, or WP_Error
$done = $plugin->api()->post_lead( $lead ); // array{id,duplicate}, or WP_Error
$body = $plugin->api()->request( 'GET', '/api/v1/some/path' ); // anything else
```

A `WP_Error` from the client has data `status` (0 when no response came) and `retryable`. A background job retries when `retryable` is true and gives up otherwise. Read `Api_Client`'s class comment for the error codes.

Every endpoint, field and header comes from the platform's documentation and code, not from guesswork. The contracts are `docs/features.md`, `docs/embed-api.md`, `docs/embed-js.md` and `docs/oembed-and-framing.md` in the platform repository. A call outside the token's scopes is refused with 403. A token paired before a scope existed gets 403 on that route, so marking a gallery embeddable asks for a new connection and origin sync falls back to reading the frame-ancestors of a public page. Public routes such as `GET /api/v1/embed/script` are called without the bearer, because the platform refuses a scoped token on a route that has no scope.

Front-end requests never wait on the platform. Do slow or fallible work in WP-Cron.

## Rules for wordpress.org

- Every admin action checks `current_user_can()` and a nonce. Escape all output.
- Options and events start with `profotograaf_`. `uninstall.php` removes everything with that prefix.
- Options that hold tokens or per-request data use autoload `false`.
- Every UI string is translatable with the text domain `profotograaf`.
- No tracking without consent, no ads in the admin.
- Anything that sends data to the platform is listed in the "External services" section of `readme.txt`: the endpoint, what is sent, when, and links to the terms and privacy policy. Add your entry in the same pull request.
- Plain, readable code. No obfuscation, no minified sources without the original.

## Checks

pnpm is the only package manager (the version is pinned in `packageManager`; `. scripts/use-pnpm.sh` puts it on PATH through corepack, inside the checkout). PHP tools run in Docker, so nothing needs installing except Docker and Node.

```sh
make install     # composer and pnpm dependencies
make quality     # everything CI runs before a merge, except the Docker E2E
make lint        # PHPCS with the WordPress Coding Standards
make phpstan     # PHPStan level 8 against its baseline
make test        # PHPUnit
make build       # blocks
make e2e         # release copy in WordPress on Docker, Playwright, Plugin Check
make i18n        # regenerate everything in languages/ (see Translations)
make i18n-check  # hardcoded strings and a stale .pot
```

Standards a change has to meet:

- TypeScript is strict, with `noUncheckedIndexedAccess`; `pnpm typecheck` runs `tsc --noEmit`.
- oxlint: complexity 12, at most 60 lines per function, depth 4, 4 parameters. A ratchet baseline holds the existing count at zero, so any new finding fails.
- Files stay under 500 lines (TS and PHP).
- New and changed lines need 80% test coverage (diffs under 10 changed lines are exempt), for both TypeScript (vitest) and PHP (PHPUnit with pcov). Write real tests: they must fail when the code is wrong.
- No dead code (knip), no high or critical advisories (`pnpm audit`, `composer audit`).
- No `continue-on-error`, every action pinned to a version tag, and every release job needs the quality and test jobs.

`docs/quality.md` lists each gate with its threshold and how to update a baseline. Never edit a baseline by hand; each gate has its refresh command that says so when it fails.

Do not run `phpcbf` and commit the result unread. It rewrites string values it considers misspellings, for example `'wordpress'` to `'WordPress'`, and the platform's client id is the lowercase one.

`WP_VERSION` and `PHP_VERSION` pick the WordPress and PHP image for `make e2e`.

### The embed.js fixture

The E2E tests run the platform's real `embed.js`, so layout bugs between the plugin's host div and the gallery the script draws show up here. `tests/e2e/fixtures/embed.js` is a pinned copy of the platform build. `tests/e2e/fixtures/embed.js.commit` holds the platform commit it came from. The mock platform (`tests/e2e/mock-platform.mjs`) serves it at `/share/embed/embed.js` and at the versioned `embed.<12 hex>.js` name, answers `GET /api/v1/embed/galleries/g-e2e` with 19 photos of mixed ratios drawn as SVG, and accepts `POST /share/embed/view`.

The WordPress container points the script at `http://mock-platform:8090`. The browser cannot resolve that name, so `tests/e2e/embed-helpers.js` routes those requests to the mock on `MOCK_PORT`. The specs `embed-layout.spec.js` and `embed-options.spec.js` measure boxes with `getBoundingClientRect` and compare them with each other.

#### Browsers

A plain `make e2e` runs chromium. Set `E2E_BROWSER_MATRIX=1` to add Firefox, WebKit and a Pixel 7 profile (`mobile-chrome`). Those three projects run only `embed-layout.spec.js` and `embed-options.spec.js`. CI sets the variable in the WordPress 7.1 job, so the 6.9 and 7.0 jobs stay on chromium. Install the extra browsers once with `pnpm exec playwright install --with-deps firefox webkit`. `openEmbed` hands the project's touch and user agent settings to the context it creates, because contexts from the `browser` fixture do not inherit them.

#### Accessibility

`embed-a11y.spec.js` runs axe (`@axe-core/playwright`) with the `wcag2a`, `wcag2aa` and `wcag22aa` tags on the gallery host: masonry and grid, at 390 and 1440 px, before and after Show more, and with the lightbox open. It also tabs to the Show more button and presses Enter. Axe is scoped to the host div because the theme is outside this repository. A violation inside the vendored `embed.js` belongs to the platform. Mark that test `test.fixme` with the rule id and the platform gap, as with any other platform gap.

#### Layout shift

`embed-cls.spec.js` sums `layout-shift` entries without recent input from page load through the render and Show more, and asserts the total stays under 0.1. It runs on chromium only. `Reserved_Space` reserves an aspect ratio from the columns, rows and tile shape (see its class comment), so the cases score under 0.01 when the gallery index knows the photo count and when it does not. A stale count that is lower than the real one raises the score, so keep the mock gallery list and the embed payload at the same photo count.

#### Pixel snapshots

`embed-visual.spec.js` compares the gallery element, masonry and grid at 390 and 1440 px, with baselines in `tests/e2e/embed-visual.spec.js-snapshots/`. Baselines depend on the operating system's fonts, so only the Linux ones are committed, and the spec runs only when `E2E_VISUAL=1` on Linux (the CI job for WordPress 7.1). To update them after an intended change, add the label `update-e2e-snapshots` to the pull request or start the "Update E2E snapshots" workflow, download the `e2e-snapshots` artifact, copy the PNG files into the snapshot directory and commit them. Never commit baselines made on macOS.

#### Live canary

`.github/workflows/live-embed-canary.yml` runs `embed-layout.spec.js` every day against the `embed.js` that `https://profotograaf.nl/share/embed/embed.js` serves, instead of the pinned fixture. `EMBED_SCRIPT_URL` does this: `openEmbed` fetches the script request from that URL while the API stays the mock. To try it locally, run `EMBED_SCRIPT_URL=https://profotograaf.nl/share/embed/embed.js pnpm exec playwright test tests/e2e/embed-layout.spec.js`. A failure opens the issue "Live embed.js canary failed", or comments on the open one, and the next pass closes it. A red canary with a green fixture run means the platform changed `embed.js`: refresh the fixture and fix the plugin or report the gap.

#### Refreshing the fixture

Refresh the copy after the platform changes `embed.js`:

```bash
scripts/refresh-embed-fixture.sh /path/to/professionals
```

The script runs the platform's `pnpm build:public`, copies the bundle and records the commit of that checkout. Commit both files. A test marked `test.fixme` names the platform gap it waits for. After a refresh, remove the `fixme` from every test that now passes.

## Continuous integration

GitHub Actions on GitHub-hosted runners:

- `ci.yml`: gate script tests first, then oxlint, tsc, knip, file length, workflow rules, vitest and `pnpm audit`; PHPCS, PHPStan and `composer audit`; PHPUnit on PHP 8.1 to 8.4; coverage gates; block build; Plugin Check; and the E2E tests on the current and previous two WordPress versions. It runs on pull requests, pushes to main, and as a reusable workflow.
- `release.yml`: a `v*` tag runs `ci.yml` first, then builds the zip, attaches it to a GitHub release and deploys to the wordpress.org SVN when the `SVN_USERNAME` and `SVN_PASSWORD` secrets exist.
- `live-embed-canary.yml`: daily, runs the layout spec against the live `embed.js` and keeps one issue for failures.
- `update-e2e-snapshots.yml`: manual or by label, regenerates the Linux snapshot baselines and uploads them as an artifact.
- `wp-tested-up-to.yml`: weekly, installs a newer WordPress release, runs the tests and opens a pull request that raises "Tested up to".
- `dependabot.yml`: weekly, grouped updates for actions, npm and composer.

## Releasing

1. Change the version in `profotograaf.php` (header and constant), `readme.txt` (Stable tag and changelog) and `package.json`. `bin/check-version.sh` checks they agree.
2. Merge, then tag: `git tag v1.2.3 && git push origin v1.2.3`.
3. Update the changelog in `readme.txt` before tagging, not after.

## Translations

Every user-facing string goes through `__()` (PHP) or `@wordpress/i18n` (blocks and scripts) with the text domain `profotograaf`, and gets a `/* translators: */` comment where it has placeholders. PHPCS enforces the WordPress i18n sniffs for PHP. `scripts/check-i18n.mjs strings` fails on text in `blocks/` and `assets/` that skips `__()`: JSX text, literal `label`, `title`, `help`, `placeholder` and similar props, and literals written to `textContent` or `innerHTML`. Put `// i18n-ignore` on a line that holds text no person reads.

After changing strings, run `make i18n` and commit everything it changes in `languages/`:

```sh
make i18n          # pot, po, mo and json in one go
make pot           # build the blocks, then regenerate languages/profotograaf.pot
make po            # merge the .pot into every languages/*.po
make mo            # compile every .po
make json          # one JSON file per translated script, for wp_set_script_translations
make i18n-check    # what CI runs: hardcoded strings and a stale .pot
```

`make pot` reads PHP, `block.json` and the built block scripts in `build/`, because WP-CLI cannot read TypeScript. That is why the `.pot` references `build/gallery/index.js`, and why WordPress finds the JSON by the hash of that path. `make json` deletes the old JSON files first.

CI fails when the committed `.pot` differs from a fresh one (the creation date is ignored). The `.po` and JSON files are not compared, so run `make i18n` before every commit that changes strings.

### Shipped languages

The plugin ships `nl_NL`, `de_DE`, `fr_FR`, `es_ES` and `it_IT` in `languages/`. Dutch is the reference translation. German, French, Spanish and Italian are machine drafts: the `Last-Translator` and `Language-Team` headers say so, and they stay until a native speaker has reviewed the file. A reviewer removes the "machine drafted" note from `Language-Team`, sets `Last-Translator` to their name and sends a pull request.

### Contribute a translation

There are two paths. Use the first for a language the plugin does not ship, or once the plugin is listed in the WordPress.org directory. Use the second to fix or add a shipped language in this repository.

**1. translate.wordpress.org**

1. Open the plugin's project at `https://translate.wordpress.org/projects/wp-plugins/profotograaf/`.
2. Pick your locale and translate or approve strings in the browser. You need a WordPress.org account.
3. Approved translations reach sites as language pack updates, without a plugin release.

Translations approved there override the files in `languages/` for that locale on sites that install the language pack.

**2. A local `.po` file**

1. Run `make i18n` so `languages/profotograaf.pot` and the existing files are current.
2. For a new locale, create `languages/profotograaf-<locale>.po` with the header of the `.pot`, set `Language:` to the WordPress locale (for example `pt_BR`) and set a correct `Plural-Forms:` line for the language. The `Language-Team` header should say "machine drafted, needs human review" when a tool wrote the text.
3. Translate the `msgstr` entries in a `.po` editor such as Poedit, or by hand. Keep `%s`, `%d` and numbered placeholders such as `%1$s` exactly as in the source, and fill every plural form.
4. Run `make i18n`. It merges the `.pot` into every `languages/*.po`, so a new locale needs no Makefile change. It also writes the `.mo` file and the JSON files the block editor loads.
5. Check the result in a browser: run `tests/e2e/up.sh`, set the admin user locale with `wp user update admin --locale=<locale>`, and open the block editor or Settings > Profotograaf. `tests/e2e/editor-translations.spec.js` does this for `nl_NL` and `de_DE`. Add the new locale to its `LOCALES` list.
6. Commit the `.po`, `.mo` and JSON files together and open a pull request.

Other pull requests add strings, so a `.po` file can lag behind the `.pot`. Untranslated strings show in English until someone translates them.
