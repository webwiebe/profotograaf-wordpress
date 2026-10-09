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

All of it sits behind a hidden flag that is off by default and has no UI. With the flag off, nothing is hooked and no behaviour changes.

| File | Role |
| --- | --- |
| `includes/class-virtual-attachments.php` | `Profotograaf\Virtual_Attachments`, the prototype. |
| `includes/trait-virtual-attachment-filters.php` | The filter callbacks, split out to keep the class under the file length limit. |
| `includes/modules/class-virtual-attachments.php` | Module that returns at once when the flag is off, otherwise builds the prototype and registers its hooks. |
| `includes/class-photo-importer.php` | `is_platform_url()` changed from private to public. No other change. |
| `tests/unit/Virtual_Attachments_Test.php` | 30 unit tests. |
| `tests/e2e/virtual-attachments.spec.js` | 9 browser tests against a real WordPress and the mock platform. |
| `tests/e2e/mock-platform.mjs` | Modes `up`, `down` and `unpublished`, and a record of API calls. |

### Switching it on

The flag is on only when `apply_filters( 'profotograaf_virtual_attachments', defined( 'PROFOTOGRAAF_VIRTUAL_ATTACHMENTS' ) && true === PROFOTOGRAAF_VIRTUAL_ATTACHMENTS )` returns the boolean `true`. A truthy string does not count.

### What the class does

- `create( array $photo )` makes an attachment from a catalogue row (the same row `Photo_Catalogue` stores). It needs the flag, the `upload_files` capability, the media source setting and a `web` URL on the platform host. It returns the existing attachment when the photo id is already imported. It stores the platform photo id (the same meta the importer uses), the gallery id, the image version, the alt text, `_wp_attachment_metadata` (width and height of the `web` variant scaled to at most 1600 px, plus a 400 px `thumbnail` size when a `thumb` URL exists), the two URLs in `_profotograaf_virtual_urls` and the marker `_profotograaf_virtual`. `_wp_attached_file` holds a path that does not exist on disk (`profotograaf-virtual/{photo id}/web-{version}.jpg`).
- Filters, all at priority 20 and all reading post meta only: `wp_get_attachment_url` (the `web` URL), `image_downsize` (the `thumb` URL for `thumbnail` and for arrays up to 400 px, otherwise `web` with the stored size), `wp_calculate_image_srcset` (one candidate, the `web` variant), `wp_prepare_attachment_for_js` (adds `profotograafVirtual`), `load_image_to_edit_path` (refuses the legacy image editor) and `render_block_data` (rewrites the saved `src` of a core Image block that points at a platform URL to the attachment's current URL).
- `convert_to_local( $id )` calls `Photo_Importer::reimport()`. The attachment id stays, the file lands in `uploads`, and the virtual marks are removed. On failure (platform down, photo gone) the attachment stays virtual.
- `sync( array $catalogue )` and a twice-daily cron event `profotograaf_virtual_sync`. For each virtual attachment: a photo listed in the catalogue gets fresh URLs and version and loses a gone mark; a photo missing from a fresh catalogue gets the gone mark once and fires `profotograaf_virtual_attachment_gone`. A stale catalogue (the last refresh failed) changes nothing.
- Filter `profotograaf_virtual_attachments_block_editing` (default `true`) turns the legacy editor guard off. Only the compatibility test uses it, to record the unguarded behaviour.

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
| REST image edit (rotate, crop) | Fails | Fails | Fails | `rest_unknown_attachment`, "Unable to get meta information for file." It reads the file on disk. |
| Media Library modal | Works | Works | Works | The model carries `profotograafVirtual`, and `url`, the `thumbnail` size and the `full` size are platform URLs. The attachment details image loads. |
| Legacy image editor (Edit image) with the guard | Refused | Refused | Refused | The editor panel opens without an image and nothing is written. |
| Legacy image editor without the guard | Inconsistent | Inconsistent | Inconsistent | The editor loads the image by downloading it server side and "Image saved" appears. WordPress writes a rotated local file, changes `_wp_attached_file` and the stored size, and creates backup sizes. The attachment keeps the virtual marker and still serves the platform URL, so the saved edit never shows on the site. |
| Content keeps the saved platform `src` after a version change or conversion | Works with the render filter | Works with the render filter | Works with the render filter | Without the filter, a converted attachment still shows the platform URL on the front end. The block's `url` attribute in the editor stays the old URL (a JavaScript fix is needed, see child issues). |
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
| Watermarked galleries | Untested | Untested | Untested | The platform doc states `thumb` is not served for them. The prototype falls back to `web` when a row has no `thumb` URL, covered by a unit test only. |
| Real platform (not the mock) | Untested | Untested | Untested | Every observation above comes from the mock platform in `tests/e2e/mock-platform.mjs`. |

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

Not found in the documentation or code read: a webhook or push notification to the plugin, and a feed entry for a gallery whose embeddable switch is turned off, that gets a password, expires or enters proofing mode. For those cases only `available` and the photo list change. The plugin has no way to be told within seconds.

Answer for the spike: unpublishing breaks the image at the origin at once, but copies already cached by an edge or a browser keep being shown for up to a year, and the platform offers no purge. The plugin learns about it only by polling, so a virtual attachment can serve a photo that was withdrawn, and can serve a broken image until the next poll. A site that needs withdrawn photos to disappear quickly should not use virtual attachments for them.

### Correction to the design note

`docs/media-source.md` says the platform URLs "do not share a directory with each other". The platform doc gives `GET /share/img/{asset-id}/{variant}-{version}.jpg`, so `thumb` and `web` of one photo do share a directory. The prototype still returns one `srcset` candidate, because `thumb` is a 400 px square crop of the photo and has a different aspect ratio than `web`.

## Findings that change the plan

1. Core stops at the file system in several places: the REST image editor, the legacy image editor, `wp_get_original_image_path` (returns a path that does not exist), and anything that reads `get_attached_file()`. Plugins that process attachments from disk (image optimisers, regenerate-thumbnails tools, backup plugins) are expected to fail or skip. Not tested.
2. The legacy editor needs a guard. Without it a save leaves the attachment in a half-converted state.
3. Post content stores the URL, so any URL change (new image version, conversion) needs a render-time rewrite or a content update. The rewrite covers the core Image block only. Gallery block items and classic content with a hard-coded `<img>` were not rewritten.
4. The platform's `alt` is always empty today, so virtual attachments carry the photo title as alt text, as imports do.
5. No variant above 1600 px exists on the platform, so a virtual attachment cannot serve a larger original, and neither can an import.

## Proposed child issues (not created)

Open these only if the recommendation is accepted.

1. Per-photo choice "Keep on Profotograaf" in the picker, next to the import. Includes the flag becoming a setting, the capability check and copy that explains the unpublish behaviour.
2. "Convert to local" action in the Media Library (single and bulk), plus a notice on attachments marked gone that offers conversion while the photo is still available.
3. Sync on the change feed instead of the full catalogue: use `GET /api/v1/embed/changes` with the cursor, fall back to the full list on `resync`. Also listen for `available` per gallery.
4. Editor-side URL refresh: update the `url` attribute of Image blocks and Gallery items in the editor when the attachment URL changes (JavaScript), and rewrite saved URLs in Gallery blocks.
5. Block the REST image edit and the "Edit image" button for virtual attachments with a clear message, instead of the generic error.
6. Compatibility tests against real offload and image-optimisation plugins (the untested cells above), on WordPress 6.9, 7.0 and 7.1.
7. Test against the real platform, including a watermarked gallery and an actual unpublish, and measure how long an edge-cached image keeps serving.
8. Uninstall and deactivation behaviour: what happens to virtual attachments when the plugin is removed (the files do not exist locally, so every image would break). Offer a bulk conversion before removal.
9. Platform request: a webhook or a feed entry when a gallery stops being available, and an optional purge call. Both are absent from the platform documentation read.

## Running the tests

```
make test                       # unit tests
WP_VERSION=7.1 make e2e         # existing browser suite
```

The spec `tests/e2e/virtual-attachments.spec.js` runs with the same Docker stack as the rest of the browser tests. It records each observation in the test output as `OBSERVED label: json`, which is where the matrix values above come from. Set `E2E_COMPOSE_PROJECT` and `WP_PORT` to run it next to another stack.
