# Documents

The documents in this folder describe how the plugin behaves and how it is checked. They are the spec layer of this repo (the minimal tier of the estate spec standard): an index, a Drift table and, per change, a proof contract in the issue.

All documents were last verified against the tree on 2026-10-17 at bd2bf0e.

The code is the fact. Where a document and the code disagree, a row goes into the Drift table below, then the document that is wrong gets fixed, or an issue is opened when the code is what is wrong. A PR that changes behaviour updates the document it touches and its Drift rows in the same PR.

## Index

| Document | Status | What it covers |
|---|---|---|
| [backup-and-pwa.md](backup-and-pwa.md) | Shipped | Copying Media Library files to private Profotograaf galleries, and linking clients to installable share pages. |
| [directory-submission.md](directory-submission.md) | Partial | The wordpress.org plugin directory submission checklist, guideline status and release steps. |
| [full-page-gallery.md](full-page-gallery.md) | Shipped | The full-page gallery block: presets, section vocabulary, sequencer rules, REST preview, markup contract and view beacon. |
| [gallery-display-matrix.md](gallery-display-matrix.md) | Shipped | Every gallery block and shortcode display option, the layer that applies it, states and E2E coverage. |
| [media-lifecycle.md](media-lifecycle.md) | Shipped | Syncing imported photo copies with Profotograaf: removals, changes, conflicts, change-feed polling and opt-in upload. |
| [media-source.md](media-source.md) | Historical | The design note for offering Profotograaf photos as a WordPress media source. |
| [quality.md](quality.md) | Shipped | Every CI quality gate, its threshold, baseline and refresh command. |
| [telemetry.md](telemetry.md) | Shipped | Opt-in usage and error telemetry: data, destinations, batching, consent and retention. |
| [virtual-attachments.md](virtual-attachments.md) | Shipped | Virtual attachments: what was built, the WordPress compatibility matrix, limits, conversion and removal. |

`review/` holds screenshots taken for reviews. They are results, not specs.

## Drift

| Claim | Source | Tree |
|---|---|---|
| Only `galleries:read`, `leads:write` and `galleries:embed` are requested, and `galleries:write` is requested only when the backup is switched on (L23, L152, L209) | backup-and-pwa.md | `Config::SCOPES` has four entries, `galleries:write` included, requested at every pairing (`includes/class-config.php:32`) |
| Backup takes image types only, without video (L57, L204) | backup-and-pwa.md | `IMAGE_TYPES` adds heif and tiff, `RAW_TYPES` adds about 15 RAW types, any `video/*` is accepted (`includes/class-backup-eligibility.php:57-130`) |
| One private gallery per year, titled `{site title} backup {year}` (L77, L98) | backup-and-pwa.md | One gallery per month, titled `"%1$s copy %2$s"` (`includes/class-backup-galleries.php:15-80`) |
| The panel button is "Back up now" and the Site Health test is "Profotograaf backup is running" (L93, L95) | backup-and-pwa.md | The panel says "Copy now" (`includes/admin/class-backup-panel.php:26`); three tests are labelled "copy permission", "copy schedule" and "copy queue" (`includes/class-backup-health.php:67-75`) |
| `Connection` stores no scope list (L45, L212) | media-lifecycle.md | `Connection` stores `scopes` with `has_scope()` and `scopes_known()` (`includes/class-connection.php:63-109`); the option key is `scopes`, a list, not `scope` (L216, L260) |
| `galleries:write` is added only when pairing starts from the upload panel (L207, L217) | media-lifecycle.md | It is in `Config::SCOPES` for every pairing (`includes/class-config.php:32`) |
| `Cron_Health` talks about leads only (L46) | media-lifecycle.md | It has a poll test, `POLL_TEST = 'profotograaf_poll'` (`includes/class-cron-health.php:40`) |
| Upload sends jpeg, png, webp, gif and heic (L195) | media-lifecycle.md | `Upload_Queue::MIME_TYPES` has jpeg, png, webp and gif (`includes/class-upload-queue.php:82`) |
| The original is sent with EXIF data, GPS included (L194, L285) | media-lifecycle.md | GPS position is removed first (`includes/class-location-stripper.php`, `includes/class-settings-schema.php:123`) |
| Later edits of a sent file stay on the site (L35, L148) | media-lifecycle.md | Title, caption and alt text changes are sent too (`META_TEXT_PUSH`, `includes/class-upload-queue.php:56`) |
| Upload states are queued, uploading, uploaded, failed, skipped (L200) | media-lifecycle.md | Four states, no `skipped` (`includes/class-upload-queue.php:59-62`) |
| The worker backs off with `BASE_DELAY` and `MAX_DELAY` (L202) | media-lifecycle.md | `Upload_Queue` has `MAX_ATTEMPTS` 8 and `RUN_GAP` 5; not established whether it reuses `Leads\Queue` (`includes/class-upload-queue.php:73-79`) |
| The plugin asks for three scopes at connect (L49) | directory-submission.md | Four (`includes/class-config.php:32`) |
| Guideline 10 is open and the Free-plan credit is an opt-in settings option (L23, L75) | directory-submission.md | The Free plan shows a link the owner accepts once with a required checkbox (`includes/class-link-consent.php`, option `profotograaf_link_consent`) |
| The rename away from "Profotograaf" is a step in this change, with one Plugin Check warning (L30, L66-75) | directory-submission.md | Name, readme title and `.po` headers read "Profotograaf"; the warning count is not established |
| The notice shows only on the plugin's own page (L24) | directory-submission.md | Backup and sync notices show elsewhere (`includes/class-sync-attention.php`); not fully verified |
| `languages/` holds Dutch only (L40) | directory-submission.md | It also holds de_DE, es_ES, fr_FR and it_IT |
| The plugin header reads `GPLv2 or later` (L14) | directory-submission.md | `GPL-2.0-or-later` in `profotograaf.php:11`; `readme.txt` reads `GPLv2 or later` |
| The release example is `git tag v0.1.0` (L76) | directory-submission.md | The plugin is at 0.2.3 (`profotograaf.php:6`) |
| `Virtual_Attachments_Test.php` has 30 unit tests (L28) | virtual-attachments.md | 57 `test_*` methods |
| `GET /profotograaf/v1/photos` needs `edit_posts` (L153) | media-source.md | `upload_files` through `can_upload()` (`includes/class-photo-rest.php:183`) |
| Search covers title, caption and gallery title; the route has `search`, `page`, `per_page` (L106) | media-source.md | Search also covers `alt`; the route has a `gallery` parameter and `per_page` is capped at 100 (`includes/class-photo-catalogue.php:173`, `includes/class-photo-rest.php:13`) |
| The signed file URL ends in `profotograaf-{assetId}-{version}.jpg` (L129) | media-source.md | The route is `/photos/{id}/file/{name}.jpg` with `exp` and `sig` query args (`includes/class-photo-file-route.php:106`) |
| The srcset filter returns `thumb` and `web`; `wp_get_attachment_metadata` returns both sizes (L81, L83) | media-source.md | The srcset filter returns `web` only; there is no `wp_get_attachment_metadata` filter (`includes/trait-virtual-attachment-filters.php:73-84`) |
| No code ships with this note and the child issues are not created (L3, L9, L96, L147) | media-source.md | Import, inserter category, modal, file route and virtual attachments ship; virtual-attachments.md supersedes the note |
| The plugin markup is one `div` with a fallback link and a `noscript` note, drawn by embed.js only (L10-13, L24-29) | gallery-display-matrix.md | The server also writes a declarative shadow root (`includes/class-server-gallery.php:12-16`, `includes/class-gallery-renderer.php:326-332`) |
| `Reserved_Space` holds the space until the gallery is drawn (L16-23) | gallery-display-matrix.md | A gallery the server drew reserves no space (`includes/class-gallery-renderer.php:325`) |
| The block `layout` values are grid, masonry, slideshow and empty (L73) | gallery-display-matrix.md | The enum also has `auto` (`blocks/gallery/block.json`) |
| Four sample pages and seven sample galleries (L159-164) | gallery-display-matrix.md | Also "Sample: Spacing and shape", "Sample: Full page" and a "Sample – Fifty" gallery (`tests/e2e/sample-pages.js:15-34`) |
| `E2E_SCREENSHOT_DIR=docs/review` works with `sample-pages` (L166-170) | gallery-display-matrix.md | The variable is read in `tests/e2e/full-page.spec.js:123`; not established for `sample-pages.spec.js` |
| The usage batch counts token refreshes (`refresh_success`, `refresh_retried`) (L21, L114-115) | telemetry.md | Nothing increments them; both are always 0 (`includes/class-telemetry-sender.php:54-58`) |
| A failed event is retried 3 times with backoff 5 s, 10 s, 30 s (L95) | telemetry.md | `MAX_ATTEMPTS` is 3 including the first try; the 30 s step is never used (`includes/class-telemetry-sender.php:312-322`) |
| The owner can delete telemetry through the `profotograaf_clear_telemetry` filter (L185) | telemetry.md | The filter does not exist; `clear_queue()` clears the local queue only |
| Module slugs `gallery_block`, `lead_forms`, `client_gallery` (L17, L112) | telemetry.md | Slugs follow the class names: `gallery_embed`, `leads`, `client_galleries`, `full_page_gallery` (`includes/class-telemetry-sender.php:372`) |
| The setting description text and a link to `docs/telemetry.md` (L213, L214) | telemetry.md | Other wording, and the link is `Settings_Schema::TELEMETRY_DOC_URL` labelled "What is shared" (`includes/class-settings-schema.php:52`, `:193`) |
| A fingerprint seen more than once per minute is sent once per batch (L41, L126, L294) | telemetry.md | One report per fingerprint per 24 h and at most 10 new fingerprints per hour (`includes/class-error-reporter.php:24-31`) |
| Uninstall removes all telemetry options (L257-259) | telemetry.md | Nothing is removed when `keep_data_on_uninstall` is on (`uninstall.php:25-27`) |
| Disconnect discards all queued telemetry (L250) | telemetry.md | The disconnect event itself is queued afterwards (`includes/modules/class-error-reporting.php:36-37`) |
| The PHPStan baseline holds 31 errors (L18) | quality.md | 31 entries summing to 45 errors (`phpstan-baseline.neon`) |
| PHPStan runs with `--memory-limit=1G` (L37) | quality.md | 2G (`.github/workflows/ci.yml`, `Makefile:25`) |
| Everything runs on every pull request and push (L3) | quality.md | `paths-ignore` skips Markdown and `docs/` changes (`.github/workflows/ci.yml`) |
| `make quality` runs the same checks as CI (L3) | quality.md | CI also runs `pnpm audit`, `bin/check-version.sh`, `make i18n-check` and the zip install job; `make quality` runs none of them |
| Leads, Admin and Cli use neither the modules nor each other (L31) | quality.md | Leads may not use Admin, Cli or Modules; Admin and Cli may use Leads (`scripts/check-boundaries.mjs:37-41`) |
| File length covers TS and PHP sources (L28) | quality.md | `.mjs` is included too (`scripts/check-file-length.mjs:31`) |
| `assets/admin/*.js` is about 80 lines (L73) | quality.md | 5 files, 638 lines |
| Block build, Plugin Check and E2E run on WordPress 6.9, 7.0 and 7.1 (L41) | quality.md | Only E2E has the version matrix (`.github/workflows/e2e.yml:58-66`) |
| Two pins live outside the manifests (L69) | quality.md | `pnpm-workspace.yaml` also sets `auditConfig.ignoreGhsas` for GHSA-vfj7-8cjw-p6xm |

`full-page-gallery.md` has no drift. Where the tree cannot answer, the row says `not established`. The rows describe the documents as they stand; the documents themselves are corrected in follow-up PRs.

## Related

- [CONTRIBUTING.md](../CONTRIBUTING.md) for the layout, the extension points and CI.
- [AGENTS.md](../AGENTS.md) for the rule agents follow when they change behaviour.
