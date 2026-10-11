# Virtual attachments: prototype and compatibility matrix

Spike for issue #104, part of epic #48. The design note `docs/media-source.md` (issue #44) recommended building a throwaway prototype of virtual attachments before deciding. This note records what was built, what it did on WordPress 6.9, 7.0 and 7.1, what the platform documentation says about unpublishing, and a go or no-go recommendation.

A virtual attachment is a Media Library attachment whose file is never copied to `wp-content/uploads`. Core functions return the platform's stable image URL, taken from stored post meta.

## Recommendation

Go for a narrow, opt-in version. No-go as the default way to use a Profotograaf photo.

1. Display works. Image block (crop, duotone, lightbox, caption), Gallery block, the `[gallery]` shortcode, featured image, the REST media endpoint and the Media Library modal all rendered from stored data on the three WordPress versions tested, with no platform call on any front-end request.
2. Editing does not work. The REST image editor (`/wp/v2/media/{id}/edit`) fails for every virtual attachment, and the legacy image editor writes a local copy next to an attachment that keeps serving the platform URL. The prototype refuses the legacy editor. The REST failure needs a user-facing path to "convert to local".
3. Unpublish cannot be made safe on the WordPress side alone. The origin answers 404 at once, but edge and browser copies live up to a year and the app does no purge. The plugin can only learn about it by polling. A site that uses virtual attachments shows broken images from the moment of unpublish until the next sync, and cached copies keep showing the photo to some visitors for far longer.
4. Saved block markup keeps the platform URL as text. The prototype rewrites it at render time so that a new image version or a conversion reaches existing posts. That is extra surface that has to be kept working.

Ship the import path from `docs/media-source.md` first. Offer virtual attachments later as an explicit per-photo choice ("Keep on Profotograaf") for sites that want it, with "Convert to local" next to it and the sync running. The proposed child issues are at the end.

## What was built

All of it sat behind a hidden flag that was off by default and had no UI. Issue #151 replaced the flag with a setting (see "Switching it on"). With the setting off, nothing is hooked and no behaviour changes, apart from the Convert to local hooks of issue #152, which act only on attachments that carry the virtual mark, and the block URL refresh of issue #154 (see "Current URLs in blocks").

| File | Role |
| --- | --- |
| `includes/class-virtual-attachments.php` | `Profotograaf\Virtual_Attachments`, the prototype. |
| `includes/trait-virtual-attachment-filters.php` | The filter callbacks, split out to keep the class under the file length limit. |
| `includes/modules/class-virtual-attachments.php` | Module that returns at once when the flag is off, otherwise builds the prototype and registers its hooks. |
| `includes/class-photo-importer.php` | `is_platform_url()` changed from private to public. No other change. |
| `tests/unit/Virtual_Attachments_Test.php` | 30 unit tests. |
| `tests/e2e/virtual-attachments.spec.js` | 9 browser tests against a real WordPress and the mock platform. |
| `tests/e2e/mock-platform.mjs` | Modes `up`, `down`, `unpublished` and `unlisted` (issue #152: the library lists nothing while the images still answer), and a record of API calls. |
| `includes/modules/class-virtual-convert.php` | Issue #152: the "Convert to local" row action, bulk action, details field and notices. |
| `tests/e2e/convert-to-local.spec.js` | Issue #152: browser tests of the conversion, its failure, the gone notice and the setting-off case. Issue #155: the REST edit error, the hidden Edit Image button and the hidden Crop button. |
| `includes/modules/class-virtual-edit-guard.php` | Issue #155: the REST image edit error and the explanation in place of Edit Image and Crop. |
| `blocks/virtual-edit-media/`, `blocks/virtual-edit-editor/` | Issue #155: the scripts for the media modal and the block editor. |
| `includes/class-virtual-sync.php` | Issue #153: `Profotograaf\Virtual_Sync`, the sync from the change feed. |
| `tests/unit/Virtual_Sync_Test.php` | Issue #153: unit tests of the feed sync. |
| `tests/e2e/virtual-sync.spec.js` | Issue #153: the feed sync against WordPress and the mock platform's queued feed pages. |
| `includes/class-virtual-block-urls.php` | Issue #154: `Profotograaf\Virtual_Block_Urls`, the render-time URL rewrite of Image and Gallery blocks and the REST field `profotograaf_urls`. |
| `includes/modules/class-virtual-block-urls.php` | Issue #154: module that registers the rewrite whatever the setting and loads the editor script. |
| `blocks/virtual-urls/` | Issue #154: editor script that updates the `url` attribute of Image blocks, Gallery items included. |
| `tests/unit/Virtual_Block_Urls_Test.php`, `blocks/virtual-urls/*.test.ts` | Issue #154: unit tests of the rewrite, the REST field and the editor script. |
| `tests/e2e/virtual-block-urls.spec.js` | Issue #154: a converted photo (setting off) and a new version, on the front end and in the editor. |

### Switching it on

Since issue #151 the switch is the setting "Keep photos on Profotograaf" (`virtual_attachments` in `profotograaf_settings`, General tab, off by default). The spike's constant `PROFOTOGRAAF_VIRTUAL_ATTACHMENTS` is gone. The filter stays as an override: virtual attachments are on only when `apply_filters( 'profotograaf_virtual_attachments', $setting )` returns the boolean `true`. A truthy string does not count.

With the setting on, Media > Import from Profotograaf shows a "Keep on Profotograaf" button next to Import, with a notice that explains the unpublish behaviour below. It posts the selected photo ids to `POST /profotograaf/v1/photos/keep`, which calls `create()` for each catalogue row. The route is registered only while the setting is on, and its permission check needs `upload_files` and the setting. `GET /profotograaf/v1/photos` marks a kept photo with `kept: true`, and the attachment details say "Kept on Profotograaf" and offer no Re-import. "Convert to local" is described below (issue #152).

### Convert to local

Issue #152 added the UI for `convert_to_local()`, in the module `includes/modules/class-virtual-convert.php` (`Virtual_Convert`):

- A row action "Convert to local" on each virtual attachment in the Media Library list view, and a "Local copy" field with a "Convert to local" button in the attachment details (media modal and attachment edit screen). Both are links to `admin-post.php?action=profotograaf_convert_local` with a nonce per attachment (`profotograaf_convert_local_{id}`).
- A bulk action "Convert to local" in the list view. WordPress checks the `bulk-media` nonce before the plugin sees the selection. Files without the virtual mark are skipped and counted.
- Every conversion needs `upload_files` and `edit_post` on the attachment, the same pattern as "Upload again" in `class-media-upload.php`. `Photo_Importer::reimport()` checks both again.
- The result goes into a transient per user (`profotograaf_convert_result_{user}`, five minutes) and shows once as an admin notice after the redirect: how many converted, a list of the ones that failed with the reason, and how many were skipped.
- Conversion runs `Photo_Importer::reimport()`, the download path of Import and Re-import: the `web` variant on the platform host, no redirects, `wp_handle_sideload()` and `wp_generate_attachment_metadata()`, so the attachment gets the usual sizes. The id stays and `_profotograaf_virtual`, `_profotograaf_virtual_urls` and `_profotograaf_virtual_gone` are removed. Any failure leaves the attachment virtual and unchanged.
- A photo the catalogue no longer lists is downloaded from its stored `web` URL (`reimport()` takes that row as a fallback). That works as long as Profotograaf still serves the image, for example when a gallery stops being embeddable. When the download fails for an attachment marked gone, the message is "Profotograaf no longer serves this photo, so no local copy could be made. It stays kept on Profotograaf."
- Attachments marked gone get a warning with a "Convert to local" button: on their edit screen, and in the Media Library as a list of up to 20 of them. The details field says the same.

Decision for the setting-off case: conversion stays available for every attachment that carries `_profotograaf_virtual`, whatever the setting. Once the setting is off the filters are gone, so such an attachment points at `profotograaf-virtual/...` under uploads, a file that does not exist, and shows no image. Convert to local is the way back, so `Virtual_Convert` registers its hooks unconditionally and checks the mark per attachment (`Virtual_Attachments::is_marked()`, which reads meta only and ignores the setting). The bulk action appears while the setting is on, or while at least one marked attachment exists (one query for one id). With the setting off and no marked attachment, nothing of it shows. The details field then explains that the photo shows no image until it is converted, and the Re-import button stays hidden for marked attachments, because a re-import would leave the virtual marks in place. The keep route, the filters and the sync stay off with the setting, as before. The block URL rewrite of issue #154 is hooked whatever the setting, so posts saved with the platform URL show the local file after a conversion (issue #219).

### Image editing (issue #155)

WordPress cannot edit the image of a virtual attachment, because there is no file on disk. `Virtual_Edit_Guard` (`includes/modules/class-virtual-edit-guard.php`) says so wherever editing is offered, and points to Convert to local. Like `Virtual_Convert` it registers while the setting is off and checks the virtual mark per attachment. Attachments without the mark are left alone.

- REST image editor (`POST /wp/v2/media/{id}/edit`): `rest_request_before_callbacks` answers a virtual attachment with the error code `profotograaf_virtual_not_editable`, status 409, and the message "This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local in the Media Library first, then edit it." The error data holds `attachment_id` and `convert_url` (the nonced Convert to local link, empty when the user may not convert). A user who may not edit the attachment gets core's own answer. The block editor shows the message as "Could not edit image. ..." when an edit is sent anyway.
- Media modal (attachment details, both the sidebar and the two-column view of the Media Library): `wp_prepare_attachment_for_js` adds `profotograafNotEditable` with `convertUrl`, and `build/virtual-edit-media/index.js` replaces the Edit Image button with "This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it." and a Convert to local link.
- Attachment edit screen: the Edit Image button (`#imgedit-open-btn-{id}`) is hidden and the same text and button are printed below the image.
- Block editor: the REST media response carries `profotograaf_virtual` (null, or an object with `convert_url`). `build/virtual-edit-editor/index.js` wraps `core/image` through `editor.BlockEdit`: while an Image block with a virtual attachment is selected, the Crop button is hidden (it has no class, so a style matches its translated label "Crop" under a body class), and the block settings show the same text with a Convert to local link, in the Content tab (WordPress 7.0 and later) and in the Settings panel.
- The scripts load for users with `upload_files` while the setting is on or a virtual attachment exists.
- Not covered: the "Edit Original" button of the classic editor's image details. The legacy editor guard still refuses that path.

### What the class does

- `create( array $photo )` makes an attachment from a catalogue row (the same row `Photo_Catalogue` stores). It needs the flag, the `upload_files` capability, the media source setting and a `web` URL on the platform host. It returns the existing attachment when the photo id is already imported. It stores the platform photo id (the same meta the importer uses), the gallery id, the image version, the alt text, `_wp_attachment_metadata` (width and height of the `web` variant scaled to at most 1600 px, plus a 400 px `thumbnail` size when a `thumb` URL exists), the two URLs in `_profotograaf_virtual_urls` and the marker `_profotograaf_virtual`. `_wp_attached_file` holds a path that does not exist on disk (`profotograaf-virtual/{photo id}/web-{version}.jpg`).
- Filters, all at priority 20 and all reading post meta only: `wp_get_attachment_url` (the `web` URL), `image_downsize` (the `thumb` URL for `thumbnail` and for arrays up to 400 px, otherwise `web` with the stored size), `wp_calculate_image_srcset` (one candidate, the `web` variant), `wp_prepare_attachment_for_js` (adds `profotograafVirtual`), and `load_image_to_edit_path` (refuses the legacy image editor). The `render_block_data` rewrite moved to `Virtual_Block_Urls` in issue #154 (see "Current URLs in blocks").
- `convert_to_local( $id )` calls `Photo_Importer::reimport()`, with the stored `web` URL as a fallback for a photo the catalogue no longer lists. The attachment id stays, the file lands in `uploads`, and the virtual marks are removed. On failure (platform down, photo no longer served) the attachment stays virtual. It acts on any attachment with the virtual mark, also while the setting is off (see "Convert to local").
- `sync( array $catalogue )`, the full sync. For each virtual attachment: a photo listed in the catalogue gets fresh URLs and version and loses a gone mark; a photo missing from a fresh catalogue gets the gone mark once and fires `profotograaf_virtual_attachment_gone`. A stale catalogue (the last refresh failed) changes nothing, and a catalogue that hit its size limit marks nothing gone.
- An hourly cron event `profotograaf_virtual_sync` runs `Virtual_Sync` on the change feed (see "Sync from the change feed"). Since issue #153 the full sync runs only when the feed answers `resync`.
- Filter `profotograaf_virtual_attachments_block_editing` (default `true`) turns the legacy editor guard off. Only the compatibility test uses it, to record the unguarded behaviour.

### Current URLs in blocks

Issue #154. Saved block markup holds the image URL as text, and the block editor keeps it in the `url` attribute. A new image version (from the sync) or a conversion to local changes the attachment URL, so both need the current one.

- `Virtual_Block_Urls::filter_block_data()` on `render_block_data` (priority 20) rewrites the saved `src` of a core Image block, and the `src` and `data-full-url` of each image in a Gallery block saved before WordPress 5.9 (images in the Gallery markup, found by `data-id` or the `wp-image-{id}` class). A current Gallery block holds Image blocks, and WordPress applies the filter to them as well. The URL is the one for the block's `sizeSlug` (`full` for an Image block and `large` for a Gallery block without one), through `wp_get_attachment_image_src()`.
- Only a saved URL on the platform host is replaced, and only for an attachment that carries the photo id meta. That host check comes first and reads no database, so ordinary images cost nothing.
- The module registers the rewrite whatever the setting, so a converted photo shows its local file in old posts also with the setting off (issue #219). An attachment that still carries the virtual mark while the setting is off has no working URL, so its blocks keep the saved one.
- The REST field `profotograaf_urls` on attachments returns `{ full, sizes }` with the same URLs, or null for any other attachment. The editor script `build/virtual-urls/index.js` (loaded on `enqueue_block_editor_assets` for users who can edit posts, with the platform URL in `profotograafVirtualUrls`) finds Image blocks with an attachment id and a platform URL, Gallery items included, asks `/wp/v2/media?include=...&_fields=id,profotograaf_urls` once per attachment and updates `url` where it differs, plus `href` when the block links to the media file. The update adds no undo step. The author saves the post to store the new URL, and until then the render rewrite covers the front end.
- Classic content with a hard-coded `<img>` and the `[gallery]` shortcode are not rewritten. The shortcode builds its markup from the attachment at render time, so it shows the current URL already.

### Sync from the change feed

Issue #153 replaced the twice-daily full catalogue sync with `Virtual_Sync` (`includes/class-virtual-sync.php`), which reads `GET /api/v1/embed/changes` through `Api_Client::changes()`, the client the change feed poll for imported copies uses.

- The cursor is stored in the option `profotograaf_virtual_changes_cursor` (not autoloaded), apart from `profotograaf_changes_cursor` of `Change_Poller`, so each reader moves past only the pages it applied. The cursor is written after each applied page. `profotograaf_virtual_changes_last_ok` holds the time of the last run that finished. Disconnecting deletes both, and uninstall removes them with every other `profotograaf_` option. A run with no virtual attachment makes no request and drops the cursor, so the first attachment kept later starts from a resync.
- A run reads at most 20 pages of 500 entries and follows `has_more`.
- `photos.deleted`: the virtual attachments of that photo are marked gone when the entry has no gallery (deleted from the library) or names the attachment's gallery (taken out of it). An entry for another gallery changes nothing.
- `galleries.deleted` and `galleries.unavailable` (with its `reason`): every virtual attachment of that gallery is marked gone, because the embed route answers 404 for it.
- `photos.created` and `photos.updated`, and `galleries.created`, `galleries.updated` and `galleries.available`: the attachments concerned are refreshed from the photo list (`refresh_for_sync()`, read once per run). A listed photo gets fresh URLs, version, size and gallery, and loses a gone mark, also when the same page listed it as deleted, because the later entry wins. So a gallery that comes back in `available` clears the gone mark of its photos.
- `"resync": true` (no cursor, an unknown one, or one past the 90 day retention): the full sync runs over the whole photo list, then the new cursor is stored.
- The gone mark is the same `_profotograaf_virtual_gone` that the notices of issue #152 read, and `profotograaf_virtual_attachment_gone` fires once per attachment.
- A failed or malformed feed page, and a photo list that fails or comes back stale, change nothing: no attachment is marked, the cursor stays and the page is read again on the next run. The failure is logged as a warning, the level `Change_Poller` uses for the same outage.
- The feed has no entry for a plan change that moves a Free client gallery's expiry into the past (platform `docs/embed-api.md`). Such a photo is caught at the next resync.

### What it never does

The front end never calls the platform. The unit tests assert that no filter performs an HTTP request, and the browser tests assert that rendering a page makes no request to the platform API (the mock records API calls). Deleting a virtual attachment makes no platform call either.

## Compatibility matrix

Result observed in the browser and WP-CLI tests, run on WordPress 6.9.4, 7.0.4 and 7.1.2 (Docker images `wordpress:{6.9,7.0,7.1}-php8.3-apache`, Chromium, mock platform). "Untested" means no run covered it.

| Feature | 6.9 | 7.0 | 7.1 | Notes |
| --- | --- | --- | --- | --- |
| Flag off changes nothing | Works | Works | Works | A marked attachment resolves to the core uploads URL, no hook is registered, a normal import still writes a local file. The cron event stays scheduled after the flag was on once. |
| `wp_get_attachment_url` | Works | Works | Works | Returns the `web` URL. |
| `wp_get_attachment_image_src` / `wp_get_attachment_image` | Works | Works | Works | `full` and `large` give `web` at the stored size, `thumbnail` and a 300 px box give the 400 px `thumb`. The tag carries `src`, `width`, `height`. |
| Image block: render, caption | Works | Works | Works | Caption and figure markup unchanged. |
| Image block: crop (aspect ratio, object-fit) | Works | Works | Works | Style attributes render on the saved image. The crop is CSS, so the image bytes are unchanged. |
| Image block: duotone | Works | Works | Works | The duotone class is present and the filtered image loads. |
| Image block: lightbox | Works | Works | Works | The lightbox opens with the platform URL as source. |
| Gallery block | Works | Works | Works | Three images load from platform URLs. |
| `[gallery]` shortcode | Works | Works | Works | Uses the 400 px `thumb` square crop. |
| Featured image | Works | Works | Works | `set_post_thumbnail` succeeds and the single post page shows one image. |
| `srcset` | Not applicable | Not applicable | Not applicable | `wp_get_attachment_image_srcset` returns false and the tag has no `srcset` or `sizes`. The only other variant is a square crop, so no second candidate has the same aspect ratio. The browser always loads the `web` variant (at most 1600 px). |
| Block editor opens the blocks | Works | Works | Works | No invalid block, four images with platform URLs, the canvas image loads. |
| REST media (`GET /wp/v2/media/{id}`) | Works | Works | Works | `source_url`, `media_details.sizes` (`thumbnail`, `full`) and alt are returned. |
| REST image edit (rotate, crop) | Fails | Fails | Fails | Spike result: `rest_unknown_attachment`, "Unable to get meta information for file." It reads the file on disk. Since issue #155 the plugin answers first with `profotograaf_virtual_not_editable` (409) and the convert link. |
| Media Library modal | Works | Works | Works | The model carries `profotograafVirtual`, and `url`, the `thumbnail` size and the `full` size are platform URLs. The attachment details image loads. |
| Legacy image editor (Edit image) with the guard | Refused | Refused | Refused | The editor panel opens without an image and nothing is written. |
| Legacy image editor without the guard | Inconsistent | Inconsistent | Inconsistent | The editor loads the image by downloading it server side and "Image saved" appears. WordPress writes a rotated local file, changes `_wp_attached_file` and the stored size, and creates backup sizes. The attachment keeps the virtual marker and still serves the platform URL, so the saved edit never shows on the site. |
| Content keeps the saved platform `src` after a version change or conversion | Works with the render filter | Works with the render filter | Works with the render filter | Without the filter, a converted attachment still shows the platform URL on the front end. Since issue #154 the editor updates the block's `url` attribute too, and the filter covers Gallery items. |
| Platform down | Works | Works | Works | Pages return 200 and make no API call. Images fail in the browser. The catalogue goes stale and the sync skips. "Convert to local" returns `profotograaf_import_download` and the attachment stays virtual. |
| Platform 404 (unpublish) | Works | Works | Works | Stored URL answers 404, so images break. The next sync marks the attachment gone (3 of 3 in the test), a second sync marks none again, and the URL is still served. "Convert to local" returns `profotograaf_photo_gone`. |
| Back online | Works | Works | Works | The sync updates the URLs and clears the gone mark. "Convert to local" keeps the id, serves a file from `uploads` and clears the virtual meta. The photo id meta stays. |
| Deleting the attachment | Works | Works | Works | No platform call. |
| Offload stand-in at the default priority (10) | Works | Works | Works | A virtual attachment keeps the platform URL (the prototype's priority 20 runs after it). A local attachment gets the CDN host. |
| Offload stand-in at priority 30 | Rewrites | Rewrites | Rewrites | The stand-in rewrites the platform host to its CDN host, which would 404. |
| Real offload plugins (WP Offload Media, Cloudflare R2 plugins and similar) | Untested | Untested | Untested | Only a stand-in filter was tested. Behaviour on upload, on delete and on the media sync of a real plugin is unknown. |
| WordPress below 6.9 | Untested | Untested | Untested | The plugin requires 6.9. |
| Multisite | Untested | Untested | Untested | |
| Browsers other than Chromium | Untested | Untested | Untested | |
| Watermarked galleries | Untested | Untested | Untested | No watermarked gallery could be made or found for issue #157, see "Real platform run". The platform doc states `thumb` is not served for them. The prototype falls back to `web` when a row has no `thumb` URL, covered by a unit test only. |
| Real platform (not the mock) | Not run | Works | Not run | Run on WordPress 7.1 against profotograaf.nl for issue #157, see "Real platform run". The other rows above still come from the mock platform in `tests/e2e/mock-platform.mjs`. |

One difference between versions: WordPress 6.9 stores an attachment's `image_meta` without the `alt` key that 7.0 and 7.1 add. It had no effect on any result above.

## Unpublish: what the platform documentation says

From `docs/embed-api.md` and `docs/public-image-urls.md` in the platform repository, read in October 2026.

- The origin re-checks publication on every request, so image URLs stop serving the moment a gallery is unpublished, soft-deleted, given a password or expires (`public-image-urls.md`, "Unpublishing and the purge window"). The embed API doc says the same: "the origin itself answers 404 immediately" (`embed-api.md`, "Freshness").
- Copies at the Cloudflare edge or in a browser were sent `immutable` for a year and are not revalidated (`public-image-urls.md`, same section). The doc lists two dashboard-only options: purge by prefix, or a Cache Rule that caps the edge TTL at 7 days. Browser copies "cannot be purged by anyone".
- "The app has no Cloudflare API credentials, so there is no automatic purge on unpublish" (same section). "There is no cache-purge call" (`embed-api.md`, "Freshness").
- The embed JSON reaches a shared cache within 300 seconds, so unpublishing reaches a cached copy of the JSON "within five minutes".
- A request for a stale image version redirects to the current URL, and a request for a missing one answers 404 with `Cache-Control: no-store`.

Signals the plugin can read to learn that a photo is no longer published:

| Signal | Source | What it covers |
| --- | --- | --- |
| `available` is false for a gallery | `GET /api/v1/embed/galleries` (token, `galleries:read`) | Password, expiry, client link, proofing mode, and the embeddable switch ("whether the public route serves it right now"). |
| The photo is missing from `GET /api/v1/embed/photos` | The library-wide list `Photo_Catalogue` already reads | Only embeddable, available galleries are listed. The prototype's sync uses this. |
| `deleted` entries in the change feed `GET /api/v1/embed/changes` | `embed-api.md` | Photos deleted from the library or taken out of a gallery, and galleries "removed from sharing". Kept as tombstones for 90 days, after which the feed answers `"resync": true`. |
| `galleries.unavailable` and `galleries.available` in the change feed | `embed-api.md`, added after this spike | The serving state of every gallery written since the cursor, with a reason (`disabled`, `password`, `proofing`, `client_only`, `expired`). An expiry is listed even though nothing wrote to the gallery. Issue #153 reads both. |

Not found in the documentation or code read: a webhook or push notification to the plugin. The plugin has no way to be told within seconds.

Answer for the spike: unpublishing breaks the image at the origin at once, but copies already cached by an edge or a browser keep being shown for up to a year, and the platform offers no purge. The plugin learns about it only by polling, so a virtual attachment can serve a photo that was withdrawn, and can serve a broken image until the next poll. A site that needs withdrawn photos to disappear quickly should not use virtual attachments for them.

### Correction to the design note

`docs/media-source.md` says the platform URLs "do not share a directory with each other". The platform doc gives `GET /share/img/{asset-id}/{variant}-{version}.jpg`, so `thumb` and `web` of one photo do share a directory. The prototype still returns one `srcset` candidate, because `thumb` is a 400 px square crop of the photo and has a different aspect ratio than `web`.

## Findings that change the plan

1. Core stops at the file system in several places: the REST image editor, the legacy image editor, `wp_get_original_image_path` (returns a path that does not exist), and anything that reads `get_attached_file()`. Plugins that process attachments from disk (image optimisers, regenerate-thumbnails tools, backup plugins) are expected to fail or skip. Not tested.
2. The legacy editor needs a guard. Without it a save leaves the attachment in a half-converted state.
3. Post content stores the URL, so any URL change (new image version, conversion) needs a render-time rewrite or a content update. The prototype's rewrite covered the core Image block only. Issue #154 added Gallery block items and the editor side. Classic content with a hard-coded `<img>` is still not rewritten.
4. The platform's `alt` is always empty today, so virtual attachments carry the photo title as alt text, as imports do.
5. No variant above 1600 px exists on the platform, so a virtual attachment cannot serve a larger original, and neither can an import.

## Real platform run (issue #157)

Run on 2026-10-11 on a local WordPress 7.1.2 site (PHP 8.3) connected to profotograaf.nl, with the plugin from this repository. One gallery was created for it, "Test 157 – Plain" (3 photos, "Test 157 photo 1" to "Test 157 photo 3", embeddable). Its photos were kept with the "Keep on Profotograaf" route, used on a test page with an Image block, a Gallery block, the `[gallery]` shortcode and a featured image. The gallery was unpublished (embeddable switched off through `PUT /api/v1/embed/galleries/{id}/embeddable`) and republished after the measurement. Only WordPress 7.1 was run. A browser was not used: the front end was read as HTML and the image URLs were requested one by one.

| Cell | Observed on the real platform |
| --- | --- |
| Keep on Profotograaf | Works. Three attachments were created with no download. Stored URLs are `https://profotograaf.nl/share/img/{id}/web-{version}.jpg` and `thumb-{version}.jpg`. |
| URL, sizes and srcset | `full`, `large` and `medium` give `web` at 1600 x 1067, `thumbnail` gives the 400 x 400 `thumb`. `srcset` is false and the tag has no `srcset`. The `web` file is 1600 x 1066 pixels and the `thumb` file 400 x 400. |
| Front end | The Image block, Gallery block, featured image and `[gallery]` (400 px `thumb`) all print platform URLs and no upload URL. All six distinct URLs answer 200 `image/jpeg`. |
| Editor and REST | `GET /wp/v2/media/{id}` returns the `web` URL as `source_url` and the `thumb` as `thumbnail`. The media modal model has `profotograafVirtual: true` with the same URLs. |
| Response headers | `cache-control: public, max-age=31536000, immutable`, `cf-cache-status` MISS on a first request and HIT after, `age` counting up. |
| Origin after unpublish | 404 with `cache-control: no-store` and `cf-cache-status: BYPASS`, seen at once through a URL with an extra query string (a separate cache key). |
| Cached copy after unpublish | Kept answering 200 for at least 2 hours, see below. |
| Gone marking | The change feed run 7 seconds after the unpublish marked all 3 attachments gone. The admin notice on the Media Library ("3 photos kept on Profotograaf are no longer listed there and may stop showing on your site. Convert them to local while Profotograaf still serves them.") and the warning on each attachment screen appeared. Front-end HTML still printed the platform URLs. |
| Mark cleared on republish | After the republish a feed run cleared the gone mark on the remaining kept attachments. |
| Convert to local | Worked while the gallery was unpublished, because the `web` image was still in the edge cache and the download got that copy (129034 bytes, the same as the cached file). It also worked on a published gallery. Both runs gave an upload file with the usual sizes, the virtual mark removed and the front end reading the local URL. A conversion of a photo whose `web` URL is not in the edge cache while unpublished was not run on the real platform (the mock covers that failure). |
| REST image edit | Not run again on the real platform. |
| Watermarked gallery | Not tested, see below. |
| Watermark visible on kept images, and kept after Convert to local | Not tested. |

### Edge cache after unpublish

The image measured is the `web` variant of "Test 157 photo 1". It was requested before the unpublish, so it was in the Cloudflare cache (`cf-cache-status: HIT`, `age` 84). The gallery was unpublished at 2026-10-11T13:53:32Z. A single GET went out every 150 seconds from 13:54:00Z, 48 requests in all, in one loop under `timeout 7200`.

- Every request answered 200 with `cf-cache-status: HIT` and `cache-control: public, max-age=31536000, immutable`. The last one, at 15:51:39Z, was 2 hours 0 minutes after the unpublish, with `age: 7171`. The loop ended on its 2 hour limit and never saw a non-200.
- At 15:55Z the `thumb` of the same photo (`age: 7397`) and the `web` image of another photo from the gallery (`age: 7351`) were still served from the edge as well.
- So the cached copy outlasts at least 2 hours. The headers say a year (31536000 seconds), and the platform documentation says there is no purge. The upper bound was not measured. A copy that was not in the edge cache at the time answered 404 at once.
- The gallery was republished at 15:56:51Z.

### What could not be done

- Watermarked gallery. The watermark is the `watermark_enabled` field of `PUT /api/v1/galleries/{id}`. That route needs a logged-in session. With the plugin's device token it answers 403 "this app is not allowed to use this endpoint". The embed routes can read, create, upload to and switch embeddable on a gallery, and nothing in them sets a watermark. No existing gallery on the account listed `web` only, which is how the embed list shows a watermark. Testing it needs a person to switch the watermark on for a test gallery in the Profotograaf app (and a studio watermark has to be set for the account).
- The platform now lists a `large` variant next to `thumb` and `web` for galleries that are not watermarked. The plugin ignores it, so a kept photo still serves at most the `web` size.

## Proposed child issues (not created)

Open these only if the recommendation is accepted.

1. Per-photo choice "Keep on Profotograaf" in the picker, next to the import. Includes the flag becoming a setting, the capability check and copy that explains the unpublish behaviour.
2. "Convert to local" action in the Media Library (single and bulk), plus a notice on attachments marked gone that offers conversion while the photo is still available.
3. Sync on the change feed instead of the full catalogue: use `GET /api/v1/embed/changes` with the cursor, fall back to the full list on `resync`. Also listen for `available` per gallery. Done in issue #153.
4. Editor-side URL refresh: update the `url` attribute of Image blocks and Gallery items in the editor when the attachment URL changes (JavaScript), and rewrite saved URLs in Gallery blocks. Done in issue #154.
5. Block the REST image edit and the "Edit image" button for virtual attachments with a clear message, instead of the generic error. Done in issue #155, see "Image editing".
6. Compatibility tests against real offload and image-optimisation plugins (the untested cells above), on WordPress 6.9, 7.0 and 7.1.
7. Test against the real platform, including a watermarked gallery and an actual unpublish, and measure how long an edge-cached image keeps serving. Done for the unpublish and the cache (see "Real platform run"). The watermarked gallery is still open.
8. Uninstall and deactivation behaviour: what happens to virtual attachments when the plugin is removed (the files do not exist locally, so every image would break). Offer a bulk conversion before removal.
9. Platform request: a webhook or a feed entry when a gallery stops being available, and an optional purge call. Both are absent from the platform documentation read.

## Running the tests

```
make test                       # unit tests
WP_VERSION=7.1 make e2e         # existing browser suite
```

The spec `tests/e2e/virtual-attachments.spec.js` runs with the same Docker stack as the rest of the browser tests. It records each observation in the test output as `OBSERVED label: json`, which is where the matrix values above come from. Set `E2E_COMPOSE_PROJECT` and `WP_PORT` to run it next to another stack.
