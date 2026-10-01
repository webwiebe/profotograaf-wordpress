# Profotograaf as a media source in WordPress

Design note for issue #44 (spike, part of epic #48). No code ships with this note. It answers the questions in the issue, lists the child issues to open and the questions that need an owner decision.

## Recommendation

1. Ship an opt-in import first. A Profotograaf photo becomes a normal Media Library attachment the moment an editor uses it, with the platform photo id stored in post meta. Every core feature that needs an attachment id then works (featured image, Gallery block, lightbox, duotone, srcset, REST `media`, other plugins).
2. Offer the photos in two places: a "Profotograaf" category in the block inserter media tab (single photo per click) and an "Import from Profotograaf" screen under Media with multi-select. A tab in the classic media modal follows later, because it is the only way to feed the Gallery block several photos at once.
3. Build a throwaway prototype of virtual attachments after that, behind the same setting. Do not ship it before the unpublish behaviour (section "Question 2: virtual attachment") has a platform answer.
4. Keep the Profotograaf gallery block for live embeds and client delivery. It renders the platform's own layouts and views, which the core blocks do not.

## What the platform gives today

Read from `docs/embed-api.md` and `docs/public-image-urls.md` in the platform repository, and from this plugin's `Api_Client`.

| Fact | Consequence for this feature |
| --- | --- |
| Photos are only listed per gallery: `GET /api/v1/embed/galleries/{id}`, public, no credentials, ETag, `s-maxage=300`. | No library-wide photo list, no search, no paging on the platform. The plugin has to build those. |
| `GET /api/v1/embed/galleries` (token, scope `galleries:read`) lists up to 500 galleries with `embeddable` and `available`. | Only galleries with embedding switched on and currently available expose photos. A photographer who never opted a gallery in sees an empty media tab. |
| A photo has `id`, `width`, `height`, `alt` (always empty today), `title`, `caption`, `images[]` (`thumb` 400 px square, `web` fitting inside 1600 px) and an optional `crop`. | Alt text falls back to `title` or `caption`. The largest file available is 1600 px on the long edge. Originals are not served. |
| Image URLs are `/share/img/{asset}/{variant}-{version}.jpg`, `Cache-Control: immutable` for a year, `Cross-Origin-Resource-Policy: cross-origin`. The docs list no `Access-Control-Allow-Origin` for images. | A browser `fetch()` from the WordPress admin to these URLs probably fails CORS. This matters for how core imports an inserter item (see "Block inserter"). Needs a check against the live host. |
| The file name carries the asset id in the path but the last segment is `web-{version}.jpg`. | The file name alone cannot identify the photo after an upload. |
| Unpublishing, adding a password or switching embedding off makes the origin answer 404 at once. Edge and browser copies live on for up to a year. | Hot-linked photos break unpredictably after an unpublish. Imported copies stay. |
| The `web` variant of a watermarked gallery carries the mark, and `thumb` is not served. | Imports from a watermarked gallery carry the mark. The thumbnail preview in the picker needs a fallback to `web`. |
| The plugin token holds `galleries:read` and `leads:write`. `galleries:embed` exists for tokens paired after #1754. | Reading photos needs no new scope, because the per-gallery endpoint is public. A library-wide endpoint would need a new one. |

## What WordPress offers (verified)

Verified against `WordPress/gutenberg` trunk and tag `v22.0.0` (the generation WordPress 6.9 bundles) and `WordPress/wordpress-develop` trunk, read in October 2026. The plugin requires WordPress 6.9 and CI covers 6.9, 7.0 and 7.1.

| API | What it does | Version |
| --- | --- | --- |
| `wp.data.dispatch( 'core/block-editor' ).registerInserterMediaCategory( category )` | Adds a tab to the inserter media panel. Required fields: unique `name`, `labels.name`, `mediaType` (`image`, `audio` or `video`), `fetch`. Also reads `labels.search_items`, `getReportUrl`, `emptyMessage`. The action forces `isExternalResource: true`. Present in tag v22.0.0 and trunk, so present on the 6.9 baseline. I recall it arriving with WordPress 6.0 (Openverse) but found no changelog line to confirm that. | 6.9 and later verified |
| `fetch( { per_page, page, search } )` | Core asks for 20 items per page. `page` is left out for page 1. It returns an array or `{ mediaItems, totalItems, totalPages }`. The pager shows only when `totalPages` is above 1. | same |
| Item shape | `url`, `previewUrl`, `alt`, `caption`, `title`, `id`, `sourceId`, `type`. Insertion builds a `core/image` block from `id`, `url`, `alt` and `caption`. `sourceId` is typed but the insertion code I read does not use it. | same |
| Insertion of an external item | One click inserts one item (no multi-select). With no `id`, core calls `window.fetch( url )`, wraps the blob in a `File` named after the URL's last path segment, and calls the editor's `mediaUpload` with `additionalData: { caption }`. That uploads to the Media Library through REST and inserts the block with the new id and url. If the fetch fails (for example CORS) or the user cannot upload, core shows an "Insert external image" dialog and inserts the block with the remote URL and no id. An item with an `id` is inserted as is, with no upload. | same |
| Error handling | `useMediaResults` awaits `category.fetch` without a `try` or `catch`. A rejected promise leaves the spinner on forever and logs an unhandled rejection. | same |
| `image_downsize` filter | Short-circuits `image_downsize()`. Returns `[ url, width, height, is_intermediate ]`. Used by `wp_get_attachment_image_src()` and by `wp_prepare_attachment_for_js()` (the media modal). | 2.5 |
| `wp_get_attachment_url` filter | Last step of `wp_get_attachment_url()`. Receives the URL built from `_wp_attached_file` and the upload directory. | 2.1 |
| `wp_get_attachment_metadata` filter | Supplies `width`, `height`, `file` and `sizes`. | 2.1 |
| `wp_calculate_image_srcset_meta`, `wp_calculate_image_srcset` filters | `wp_calculate_image_srcset()` returns false when `$image_meta['sizes']` is empty or `file` is shorter than four characters. It builds each source from the directory of the `src` plus the size's `file`. The second filter lets code replace the whole source list. | 4.4 (meta filter 4.5) |
| `wp_image_src_get_dimensions` filter | Dimension lookup used by `width` and `height` attributes in content images. | 5.5 |
| `wp_prepare_attachment_for_js` filter | Shapes the attachment for the media modal. | 3.5 |
| `ajax_query_attachments_args` filter | Alters the query behind the media modal grid. Used to add a "source" filter to imported photos. | present in current core |
| `set_post_thumbnail()` | Requires that the id resolves to a post and that `wp_get_attachment_image( $id, 'thumbnail' )` returns markup. A virtual attachment passes when the filters above give it a URL. | present in current core |
| REST `POST /wp/v2/media/{id}/edit` and `/sideload` | The edit route reads the file on disk. A virtual attachment has none, so rotate and crop in the Media Library fail for it. | present in current core |
| `media_handle_sideload()`, `download_url()` | Server-side import from a URL. | 2.6 and later |
| Media modal (`wp.media`, Backbone) | No public registration API for a new source. A tab means extending `wp.media.view.MediaFrame.Select` or `Post` with a router item and a custom state. The Backbone API is stable in practice and thinly documented. | 3.5 and later |

Not verified: behaviour of each feature below on WordPress 7.0 and 7.1 (the source read covers trunk and the 6.9 generation), how the Image block crop and lightbox treat an attachment with an unusual URL, and how WordPress 7.x client-side media processing treats attachments without files. Child issue 9 covers these with real tests on the CI matrix.

## Question 1: reference by URL or import

| | Reference by URL (no id) | Import into the Media Library |
| --- | --- | --- |
| Image block, duotone, crop box, caption, lightbox, theme styles | Work. The block holds a URL. | Work. |
| Featured image | Impossible. `_thumbnail_id` needs an attachment. | Works. |
| `srcset`, `sizes`, `wp_get_attachment_image`, REST `media`, plugins keyed on ids | Missing. | Work. Core generates the sizes from the 1600 px file. |
| Gallery block through the media modal | Impossible. The modal returns attachments. | Works. |
| Survives an unpublish or an outage on the platform | No. Images 404 on the live site. | Yes. |
| Duplicates bytes | No. | Yes, one 1600 px JPEG per photo plus WordPress sizes. |
| Photographer keeps control (removal, licence) | Yes. | No, unless #46 syncs removals. |
| Page speed | Browser loads from the platform CDN with a year of caching. | Loads from the site. |

Recommendation: import. Reference by URL is what core already falls back to when an import fails, so it needs no code from this plugin. The cost of import is storage and the loss of live control. Keeping the link to the photo (`_profotograaf_photo_id`) lets a later sync (#46) find the copies.

Import details to settle in the importer issue:

- Idempotent by photo id: a second use of the same photo returns the existing attachment. Core's own inserter flow cannot do that (it uploads again every time), so the category's `fetch` returns the existing `id` for photos already imported. Core then inserts without an upload.
- Meta: `_profotograaf_photo_id`, `_profotograaf_gallery_id`, `_profotograaf_version` (the 12 character image version from the URL). Names start with `profotograaf_` or `_profotograaf_` so `uninstall.php` can remove them. Imported attachments stay on uninstall because they are the site's content.
- Fields: title from `title`, caption from `caption`, alt from `alt` and then `title`.
- Which variant: `web`, the largest served.

## Question 2: virtual attachment

A virtual attachment is an `attachment` post with `post_mime_type` `image/jpeg`, the platform photo id in meta, stored width, height and size list in `_wp_attachment_metadata`, and URLs supplied by filters. It is the model media offload plugins use.

What works, from the source read above:

- `wp_get_attachment_url` filter returns the platform `web` URL.
- `wp_get_attachment_metadata` returns `width`, `height`, `file` and a `sizes` array with `thumb` and `web`.
- `image_downsize` returns the right variant for a requested size.
- `wp_calculate_image_srcset` filter returns `thumb` and `web` as the candidate sources, since the platform URLs do not share a directory with each other and core's own builder would make wrong URLs from `dirname( $src ) . '/' . $size['file']`.
- `set_post_thumbnail()` accepts it, so #45 works.
- Duotone, crop box (CSS), caption, lightbox and theme styles work on any image URL.

What breaks or needs a decision:

- Unpublish. The platform stops serving a photo when its gallery leaves the embeddable state, and the URLs are public on purpose. Every virtual attachment of that gallery then 404s on every page that uses it. Import avoids this. A virtual model needs either a platform signal (the `available` flag on the gallery list is a start) or an automatic switch to a real import when a gallery is about to go away. Needs the owner (open question 3).
- File-based operations: Media Library rotate and crop (`/edit` route), `wp_get_original_image_path()`, image optimisers, WebP converters, backups, `wp media regenerate`, export and import. Most skip or fail quietly. A `get_attached_file` filter pointing at a missing path changes nothing for them.
- Coexistence with a site that already runs an offload plugin, which hooks the same filters. Priority and a "convert to local" action are needed.
- Front-end requests must never wait on the platform (CONTRIBUTING.md). Virtual attachments pass this only if every filter reads stored meta and never calls the API. A cron job refreshes the stored data.
- The 1600 px cap applies to the displayed file too. Import has the same cap, so it is no worse here.
- Deleting the attachment in WordPress deletes nothing on the platform. That is correct. It should be said in the UI.

Recommendation: prototype it as a spike behind the opt-in setting after import ships, run the matrix in child issue 9, and decide from the results and the unpublish answer. A "convert to local" action (turn a virtual attachment into an import) belongs to the same spike and gives photographers an exit.

## Question 3: search, multi-select, paging, unreachable platform

### Search and paging

The platform lists photos per gallery only. The plugin builds a catalogue:

- Source list: embeddable and available galleries from the existing gallery list (#17's `Gallery_Index` already remembers up to 500).
- Photos: one public `GET /embed/galleries/{id}` per gallery, with `If-None-Match` and the platform's five minute freshness. Stored in a transient keyed by gallery id and `version`, refreshed by a cron job and by the editor on demand. The editor never waits on more than one gallery fetch at a time.
- A REST route `GET /profotograaf/v1/photos?search=&page=&per_page=` (`edit_posts`, like `/galleries`) searches `title`, `caption` and the gallery title over the catalogue, orders by gallery `updated_at` then position, slices the page and returns `{ items, totalItems, totalPages }` with the item shape above.
- The inserter category's `fetch` calls this route through `apiFetch` and returns the same totals, which gives core's pager.

Typing a gallery name lists that gallery's photos. The inserter offers no filter dropdown, so this is the only gallery filter there. The Import screen can have a real gallery filter.

The cost is one fetch per gallery to build the first catalogue. It scales to the low hundreds of galleries. Beyond that the platform needs a library-wide listing (platform issue P2).

### Multi-select

The inserter inserts one item per click. Dragging also takes one. Multi-select needs the Import screen (select many, "Import") or the media modal tab, which feeds the Gallery block and the classic editor.

### Platform unreachable

- Core does not catch a rejected `fetch`. The category's `fetch` therefore catches everything itself, returns `[]` and records the failure. Without that the spinner never stops.
- The REST route maps platform errors with the existing translated messages (#10, #11, #18) and serves the stale catalogue when one exists, flagged `stale: true`. The editor shows a non-blocking notice.
- Failures go through the logger (#14) and show in Site Health (#15). An unreachable platform becomes an admin notice (#16) only after repeated failures.
- Thumbnails come from the platform's CDN in the editor's browser. A failed thumbnail falls back to the `web` URL, as core does in its preview component.
- Photos that were already imported keep working: they are local files.

## Block inserter: the import path

Core fetches the `url` from the editor's browser. For platform URLs that probably fails CORS (see the platform table), and core then inserts a hot-linked image with a warning dialog and no id. Two ways out:

- A. The plugin serves the file. Each item's `url` is a short-lived signed same-origin REST URL (`.../profotograaf/v1/photos/{id}/profotograaf-{assetId}-{version}.jpg`). The route downloads from the platform with `download_url()` and streams it. Core's fetch then works, core's `mediaUpload` does the upload, and a `rest_after_insert_attachment` hook reads the asset id from the uploaded file name and writes the meta. No platform change. The cost is one PHP request per insertion and the HMAC signing code.
- B. The platform adds `Access-Control-Allow-Origin: *` to `/share/img/*` and a file name that carries the asset id. The plugin sends the platform URL straight through. Simpler plugin code, a platform dependency.

Recommendation: A for the first release so nothing waits on the platform, then switch to B when it exists.

## Media modal

The modal feeds the Gallery block ("Add media"), the classic editor and the featured image box. A tab is a Backbone extension (router item, state, custom attachments collection whose `sync` calls the catalogue route). Selecting items triggers the import and swaps the models for real attachments before `select` fires. It is the largest piece and the least documented, so it comes after the inserter and the Import screen. The Import screen plus a "Source: Profotograaf" filter in the modal grid (through `ajax_query_attachments_args`) covers most of the need earlier, because imported photos then show up in the modal like any other upload.

## Privacy and consent

- Opt-in setting `media_source` in the settings schema (#27), default off, removed by `uninstall.php`. Nothing registers a tab or contacts the platform for photos while it is off.
- New outbound calls need an External services entry in `readme.txt` in the same PR: server-side `GET /api/v1/embed/galleries/{id}` (gallery id, no personal data), server-side image downloads from `/share/img/`, and the editor's browser loading thumbnails from `profotograaf.nl` (IP address and browser details, like any web server).
- The privacy policy text and exporter from #26 should mention that imported photos are stored on the site.
- Imported copies of client photos belong to the site. The importer only touches galleries the platform marks embeddable, which is the photographer's own publication decision.

## Proposed child issues

Not created. Order is the dependency order. "Platform" names the dependency on the platform repository.

| # | Title | Scope | Platform dependency |
| --- | --- | --- | --- |
| 1 | Add the media source opt-in setting | `media_source` in `Settings_Schema`, settings page toggle with explanation, `uninstall.php`, README and readme.txt External services entry, tests for the default off state | None |
| 2 | Build the photo catalogue from embeddable galleries | Class that reads galleries from `Gallery_Index`, fetches each public gallery with ETag, stores photos in transients, stale on error, cron refresh, logger for failures, unit tests with the fake transport | None (uses the public embed endpoint) |
| 3 | REST route for browsing photos | `GET /profotograaf/v1/photos` with search, paging, totals, `edit_posts`, translated error mapping, `stale` flag | None |
| 4 | Import a photo into the Media Library | Importer with `download_url` and `media_handle_sideload`, idempotent by photo id, meta keys, title, caption, alt fallback, `profotograaf_photo_imported` action, tests for duplicate and failure paths | None |
| 5 | Add a Profotograaf category to the block inserter | `registerInserterMediaCategory`, fetch that never rejects, returns `id` for imported photos, signed same-origin file route plus attachment hook (path A), `@wordpress/i18n` strings and `wp_set_script_translations`, E2E on WordPress 6.9, 7.0 and 7.1 | None for path A. Optional P1 for path B |
| 6 | Add an "Import from Profotograaf" screen under Media | Paged grid with gallery filter, search, multi-select, bulk import with progress and per-photo errors, "Source" filter in the media modal through `ajax_query_attachments_args`, imported badge in attachment details | None |
| 7 | Show imported photos' origin and offer re-import | Attachment details field, link to the gallery, re-import action. Feeds #46 | #46 design for removals |
| 8 | Add a Profotograaf tab to the media modal | Backbone router item and state, multi-select, imports on select, works for Gallery block "Add media" and the featured image box | None |
| 9 | Spike: virtual attachments prototype and compatibility matrix | Prototype with the filters in this note behind a hidden flag, test Image block (crop, duotone, lightbox, caption), Gallery block, featured image, REST `media`, srcset, Media Library edit, an offload plugin, unpublish and outage behaviour, on WordPress 6.9, 7.0 and 7.1. Ends in a go or no-go note. Includes "convert to local" | Needs the answer to the unpublish question (P4) |
| 10 | Set the featured image from a Profotograaf photo | This is #45. Depends on issues 4 and 5 for the attachment id | None |
| 11 | Document the media source for users | README, readme.txt FAQ, privacy text, screenshots | None |

Platform issues to open in `wiebe-xyz/professionals` (proposed, not created):

- P1: `Access-Control-Allow-Origin: *` on `/share/img/*` and a stable download file name containing the asset id. Removes the proxy route in issue 5.
- P2: Library-wide photo listing for the plugin token with paging and text search (new scope, for example `photos:read`). Needed above a few hundred galleries.
- P3: A larger served variant or an original for the plugin token. Without it imports stay at 1600 px.
- P4: A way to learn that a gallery stopped serving public images (webhook, or `available` plus a change feed). Needed for #46 and for the virtual attachment decision.
- P5: Populate `alt` (#1695 in the platform tracker). Until then imports use the title.

## Open questions for the owner

1. Is the 1600 px `web` variant enough for imported photos, or should photographers be able to import the original (P3, new scope, storage cost on the site)?
2. Should the media tab list only galleries with "Allow embedding on other websites" switched on, as the platform allows today, or should the platform offer a separate "available to WordPress media" switch per gallery or per photo? Switching embedding on also makes the gallery embeddable by anyone.
3. Virtual attachments: when a gallery stops serving, is a broken image on the site acceptable for a while, or must the plugin import everything it references before that can happen?
4. Should removal on the platform ever remove or flag the imported copy (#46), or are copies the site's own content from the moment of import?
5. Is the plugin allowed to write attachments for users with only `edit_posts`, or does importing need `upload_files`? This note assumes `upload_files`, matching core.
6. Path A (plugin streams the file) or waiting for P1 (platform CORS and file name)? Path A ships without the platform and costs a PHP request per insertion.
7. Does the `crop` rectangle apply to the `web` variant on the platform side already? The embed docs name it for the embed renderer only.
8. Should the Profotograaf category appear for all roles that can edit posts, or only administrators and editors, as the gallery picker does today?
