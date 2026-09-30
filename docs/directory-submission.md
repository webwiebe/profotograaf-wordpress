# wordpress.org directory submission

Checklist for submitting Profotograaf to the plugin directory. It maps each
[detailed plugin guideline](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
to where this plugin meets it, records the Plugin Check result and lists the steps that need a person.

Status legend: **Met** means the code or docs meet it today. **Review risk** means it is met
as far as we can tell but a reviewer may ask about it. **Open** needs work before submitting.

## Guidelines

| # | Guideline | Status | Where |
|---|---|---|---|
| 1 | GPL compatible | Met | `LICENSE` (GPL-2.0), `License: GPLv2 or later` in `readme.txt` and the plugin header. Only WordPress packages and our own code ship in the zip. The composer dev tools stay out (`.distignore`). |
| 2 | Developers are responsible for the files | Met | `.distignore` decides what ships. `bin/build-release.sh` builds the zip from it and the release workflow only publishes that build. `composer.json` requires no runtime packages. |
| 3 | A stable version is available in the directory | Met | The directory serves the tag in `Stable tag` (`readme.txt`), checked against the plugin header by `bin/check-version.sh` in CI and on release. |
| 4 | Code is human readable | Met | No minified or obfuscated source. The block JavaScript ships as the `wp-scripts` build of `blocks/*` and the source folder `blocks/` is published in the GitHub repository, linked from the `readme.txt` description. Build steps are in `CONTRIBUTING.md` (`npm ci && npm run build`). |
| 5 | No trialware | Met | Nothing is locked or time limited inside the plugin. The Profotograaf plans are a service, see guideline 6. |
| 6 | Software as a service is permitted | Met | The plugin needs a Profotograaf account, which is a real hosted service (client galleries, embeds, enquiry inbox) and not a licence check. The readme says so in the description and the External services section. The Client galleries block and the settings page work without the service being reachable. |
| 7 | No tracking without consent | Met | The plugin sets no cookies and loads nothing from Profotograaf in wp-admin until the photographer clicks Connect. The Client galleries block is a plain link and makes no request while a visitor views the page. The embed view counter (gallery block) honours Do Not Track and Global Privacy Control and is described in External services. |
| 8 | No executable code from third party systems | Review risk | The gallery block loads the embed script from `https://profotograaf.nl/share/embed/`, which is our own service and the point of the embed. It is named in External services with its purpose. If a reviewer objects, the fallback is to ship the embed script inside the plugin (`docs/embed-js.md` in the platform repository describes it). Plugin updates and licence checks never use it. |
| 9 | Nothing illegal or offensive | Met | |
| 10 | No credits or links on the public site without opt-in | Review risk | The embed API returns a `badge` for photographers on the Free plan, which the embed renders as a "Made with Profotograaf" credit. That is set by the plan on the platform. Confirm before submitting that the WordPress embed shows it only when the photographer has opted in (a settings option, off by default in the plugin), or state in the readme that it belongs to the Free plan. The client galleries block adds no credit. |
| 11 | No hijacking of the admin | Met | One menu entry under Settings, one notice only on the plugin's own page. No ads, upsells or nags anywhere in wp-admin. |
| 12 | The readme does not spam | Met | Five tags, no competitor names, no affiliate links. Every link is to Profotograaf's terms, privacy policy or documentation. |
| 13 | Use WordPress' default libraries | Met | Blocks use `@wordpress/*` packages that `wp-scripts` maps to the WordPress handles. HTTP goes through `wp_remote_request` (`includes/class-wp-transport.php`). No bundled copy of jQuery or another core library. |
| 14 | Avoid frequent commits | Process | Release with the tag workflow only for real releases. Do not deploy every commit. |
| 15 | Increment the version for each release | Met | `bin/check-version.sh` fails a release when the tag, header and `Stable tag` differ. |
| 16 | A complete plugin at submission | Open | Submit when the gallery block, shortcode, oEmbed and lead bridges of the epic are merged. Each is named in the readme. |
| 17 | Respect trademarks | Met | The restricted term "wordpress" is out of the name: `Plugin Name` in `profotograaf.php`, the `readme.txt` title and the `Project-Id-Version` of the language files all read "Profotograaf". The slug `profotograaf` is fine. |
| 18 | Directory rights | Met | Nothing to do. |

Other requirements from the review team:

| Requirement | Status | Where |
|---|---|---|
| `readme.txt` with an External services section | Met | Names every endpoint, what is sent and when, with links to https://profotograaf.nl/terms and https://profotograaf.nl/privacy. Any new outbound call must be added there in the same change. |
| Escaping, nonces, capability checks | Met | `includes/modules/class-settings-page.php`: every action is an `admin_post` or `admin_ajax` handler that checks `manage_options` and a nonce first (tested in `tests/unit/Settings_Page_Test.php`, forged action in `tests/e2e/settings.spec.js`). Output of the client galleries block goes through `esc_html`, `esc_url` and `get_block_wrapper_attributes` (`tests/unit/Client_Galleries_Test.php`). |
| Prefixes | Met | PHP namespace `Profotograaf`, options, hooks, transients and cron events start with `profotograaf_`. Blocks use the `profotograaf/` namespace. |
| Translatable strings | Met | Text domain `profotograaf`, `languages/` holds the `.pot`, Dutch `nl_NL` `.po` and `.mo`. |
| PHP 8.1+, current and previous two WordPress majors | Met | PHPUnit runs on PHP 8.1 to 8.4, the E2E job on WordPress 7.1, 7.0 and 6.9. `Requires at least: 6.9`. The weekly `wp-tested-up-to` workflow keeps `Tested up to` current. |
| Clean uninstall | Met | `uninstall.php` removes every `profotograaf_` option, transient and cron event. |
| Plugin URI and Author URI resolve | Open | Check both headers point at pages that exist on the day of submission. |
| `Contributors` are wordpress.org usernames | Open | `readme.txt` lists `profotograaf`. Replace it with the wordpress.org username of the account that submits, or create that account under this name. |


## Permissions and reconnecting

The plugin asks for three scopes when it connects: `galleries:read`, `leads:write`
and `galleries:embed` (`Config::SCOPES`). The platform decides the grant from the
`wordpress` client registration, so the request is what the approval page shows.
`galleries:embed` lets the gallery block switch "Allow embedding on other websites" on
for the one gallery a photographer picks, through
`PUT /api/v1/embed/galleries/{id}/embeddable`. It is named in the External services
section of `readme.txt`. The plugin never widens the grant on its own: a connection
made before the scope existed answers 403 to that call, the plugin says so, and the
settings page and an admin notice ask the photographer to connect again. A reviewer
can test this by connecting, then switching a gallery off in Profotograaf and picking
it in the block.

## Plugin Check

Run against the release copy (`dist/profotograaf`) with Plugin Check 2.1.0 on WordPress 7.1, on the
Client galleries branch (`make plugin-check`, the same script CI runs):

- **Errors: 0.**
- **Warnings: 1**, `trademarked_term` on `readme.txt` and `profotograaf.php`, guideline 17 above. The readme
  is renamed in this change, the plugin header follows with the rename.

Re-run `make plugin-check` after every change to a module, and after the rename. CI fails on any error.

## Steps for a person

1. Replace the placeholder artwork in `assets/wporg/` (see the README there). Optional for the review, wanted before the plugin page is public.
2. Do the open items above: rename the plugin (guideline 17), settle the Free plan credit (guideline 10), fix `Contributors`.
3. Merge the rest of the epic, then tag a release: `git tag v0.1.0 && git push origin v0.1.0`. The release workflow attaches `profotograaf.zip` to the GitHub release. It skips the wordpress.org deploy because the secrets do not exist yet.
4. Submit that zip at https://wordpress.org/plugins/developers/add/ from the account that will own the plugin. The review queue takes days to weeks and the review team replies by email. Answer within a few days, replies that come late close the request.
5. When approved, wordpress.org creates the SVN repository at `https://plugins.svn.wordpress.org/profotograaf/`. Add the two repository secrets, `SVN_USERNAME` (the wordpress.org username) and `SVN_PASSWORD` (the wordpress.org password or an SVN password):

   ```sh
   gh secret set SVN_USERNAME --repo webwiebe/profotograaf-wordpress
   gh secret set SVN_PASSWORD --repo webwiebe/profotograaf-wordpress
   ```

6. Re-run the release workflow for the tag (or tag the next version). The deploy step now runs: it pushes the plugin to `trunk`, tags it and uploads `assets/wporg/` as the directory artwork.
7. Check https://wordpress.org/plugins/profotograaf/ a few minutes later: banner, icon, screenshots, the External services section and the Dutch translation request on translate.wordpress.org.
