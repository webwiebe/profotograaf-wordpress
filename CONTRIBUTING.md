# Contributing

## Layout

```
profotograaf.php          plugin header and bootstrap, rarely edited
includes/                 classes, autoloaded
includes/modules/         one file per feature module, discovered automatically
blocks/<name>/            one folder per block, discovered automatically
build/                    block build output (generated, not committed)
assets/admin/             plain CSS and JS the plugin ships as is
assets/wporg/             directory banner, icon and screenshots (not in the zip)
languages/                .pot, .po and .mo files
tests/unit/               PHPUnit with Brain Monkey, no WordPress needed
tests/e2e/                Playwright against a real WordPress in Docker
bin/                      release and build helpers
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

Add `blocks/<name>/block.json` with its `index.js`, `render.php` and styles. `npm run build` (`@wordpress/scripts`) writes `build/<name>/`. `Modules\Blocks` registers every `blocks/*/block.json`, from `build/<name>` when it exists. Nothing else changes. Blocks use the text domain `profotograaf`.

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
| `profotograaf_origin_sync_endpoint` | filter | Supplies the path that writes the allowed embed origins, once the platform allows it |

## Talking to the platform

Use `Api_Client`. Do not call `wp_remote_*` yourself: the client adds the bearer token, refreshes it when needed, sets timeouts and turns every failure into a `WP_Error`.

```php
$rows = $plugin->api()->list_galleries();   // list of arrays, or WP_Error
$done = $plugin->api()->post_lead( $lead ); // array{id,duplicate}, or WP_Error
$body = $plugin->api()->request( 'GET', '/api/v1/some/path' ); // anything else
```

A `WP_Error` from the client has data `status` (0 when no response came) and `retryable`. A background job retries when `retryable` is true and gives up otherwise. Read `Api_Client`'s class comment for the error codes.

Every endpoint, field and header comes from the platform's documentation and code, not from guesswork. The contracts are `docs/features.md`, `docs/embed-api.md`, `docs/embed-js.md` and `docs/oembed-and-framing.md` in the platform repository. The plugin's token holds the scopes `galleries:read` and `leads:write`. A call outside those scopes is refused with 403, which is why marking a gallery embeddable and syncing embed origins go through filters until the platform offers them to the token.

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

The unit and lint tools run in Docker, so nothing needs installing except Docker and Node.

```sh
make install     # composer and npm dependencies
make lint        # PHPCS with the WordPress Coding Standards
make test        # PHPUnit
make build       # blocks
make e2e         # release copy in WordPress on Docker, Playwright, Plugin Check
make pot         # regenerate languages/profotograaf.pot
```

Do not run `phpcbf` and commit the result unread. It rewrites string values it considers misspellings, for example `'wordpress'` to `'WordPress'`, and the platform's client id is the lowercase one.

`WP_VERSION` and `PHP_VERSION` pick the WordPress and PHP image for `make e2e`.

## Continuous integration

GitHub Actions on GitHub-hosted runners:

- `ci.yml`: PHPCS, PHPUnit on PHP 8.1 to 8.4, block build, Plugin Check, and the E2E tests on the current and previous two WordPress versions.
- `release.yml`: a `v*` tag builds the zip, attaches it to a GitHub release and deploys to the wordpress.org SVN when the `SVN_USERNAME` and `SVN_PASSWORD` secrets exist.
- `wp-tested-up-to.yml`: weekly, installs a newer WordPress release, runs the tests and opens a pull request that raises "Tested up to".

## Releasing

1. Change the version in `profotograaf.php` (header and constant), `readme.txt` (Stable tag and changelog) and `package.json`. `bin/check-version.sh` checks they agree.
2. Merge, then tag: `git tag v1.2.3 && git push origin v1.2.3`.
3. Update the changelog in `readme.txt` before tagging, not after.

## Translations

Run `make pot` after changing strings. Dutch lives in `languages/profotograaf-nl_NL.po`; `make mo` compiles it. Other languages come from translate.wordpress.org once the plugin is in the directory.
