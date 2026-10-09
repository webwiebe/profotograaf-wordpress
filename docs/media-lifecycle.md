# Media lifecycle sync with Profotograaf

Design note for issue #46 (spike, part of epic #48). No code ships with this note. It answers the four rules in the issue, lists the child issues to open and the questions that need an owner decision.

It builds on `docs/media-source.md` (#44). The media source it describes is on main: the `media_source` setting, `Photo_Catalogue`, `Photo_Importer` (with the per-photo lock and the redirect guard), the `Photo_Rest` routes (list, import, re-import), the signed file route, the inserter category, the Import screen under Media, the media modal tab, the featured image and the Source field in the attachment details.

## Recommendation

1. Flag first, never delete. A change feed poll and a reconcile step mark imported copies with a state (`missing`, `changed`, `conflict`) and nothing else. Every write to an attachment, including removal, is a click by a person with `edit_post` on that attachment.
2. The platform wins only in what the plugin shows. The plugin never writes remote state into a copy and never writes local state to a platform photo. The one exception is the opt-in upload, which only creates photos.
3. Poll the change feed (`GET /api/v1/embed/changes`) from WP-Cron once an hour, with a manual "Check now", and show "last checked" so a host without cron is visible instead of silently stale.
4. Reconcile against the full lists (`GET /api/v1/embed/galleries` and `GET /api/v1/embed/photos`) as well as the feed. The feed reports deletions. It does not report that a gallery stopped serving (embedding switched off, password, expiry), so absence from the lists is the second signal.
5. Upload from the Media Library is opt-in at three levels: a site setting, a linked gallery, and the files a person sends. It needs `galleries:write`. The plugin stores the granted scope list and asks for a reconnect only on sites that switch upload on, so no existing site sees a new prompt.
6. Build it in the order of the child issue list at the end. The first four issues give the flagging and the poll. The upload comes after.

## What the platform gives today

Read from `docs/embed-api.md` and `docs/public-image-urls.md` in `wiebe-xyz/professionals`, from the closed platform issue #1869, and from `internal/features/gallery/embed_changes.go` and `embed_changes_store.go` in that repository. Where a fact was not in the docs or the code, the table says "not found".

| Need | Route and fields | Notes |
| --- | --- | --- |
| Change feed | `GET /api/v1/embed/changes?since=<cursor>&limit=<n>`, scope `galleries:read`, `private, no-store`. Answer `{ cursor, has_more, resync, galleries: { created, updated, deleted }, photos: { created, updated, deleted } }`. Gallery entries are id strings. Photo entries are `{ id, gallery_id }`. `limit` defaults to 500, maximum 1000. | The cursor is opaque. `has_more` means call again at once. At-least-once delivery: an idle cursor trails the present by 5 seconds, so an entry can arrive twice and applying it twice does nothing. When one id arrives as deleted and later as created or updated, the later entry wins. |
| Resync hint | Same route, `resync: true`, empty lists and a fresh `cursor`. | Sent for a missing `since`, a cursor this feed did not issue, one in the future, or one older than 90 days (`SyncTombstoneRetention`). The client lists everything once and polls from the new cursor. The cursor is taken before the listing, so nothing falls in the gap. |
| What `photos.deleted` means | Photos deleted from the library (`gallery_id` empty) and photos taken out of a gallery (`gallery_id` is the gallery they left). | A photo moved out of a gallery's folder shows as `updated`, not `deleted`. The store code writes these rows when a photo is detached, replaced by a publish, or deleted. |
| What `galleries.deleted` means | "Removed from sharing". | In `embed_changes_store.go` the only writer I found is the one that runs when a backing folder is soft-deleted (`embedTombstoneFolderGalleries`). Whether deleting a gallery directly writes a row: not found. |
| Created and updated | Gallery: `updated_at` moved. Photo: ready photos in a live gallery whose `updated_at` moved. A gallery is `created` when `created_at` is at or after the cursor, else `updated`. | A feed entry carries ids only. It carries no field values, so the plugin reads the new data from the photo list. |
| Not in the feed | Switching embedding off, adding a password, expiry, proofing mode, client-only. | `docs/embed-api.md` lists these as making a gallery unavailable. The feed has no kind for them. A photo in such a gallery stops being listed by `GET /api/v1/embed/photos`. Whether the gallery's `updated_at` moves when embedding is switched off: not found. |
| Gallery state | `GET /api/v1/embed/galleries`, scope `galleries:read`, up to 500 rows, `no-store`. Row: `id`, `slug`, `title`, `url`, `embeddable`, `available`, `photo_count`, `cover_url`, `updated_at`. | `available` is the working state. A gallery over 500 would be cut off, so absence from this list proves nothing when the list has 500 rows. |
| Photo list | `GET /api/v1/embed/photos?q=&updated_since=&limit=&offset=`, scope `galleries:read`, `limit` default 50 and maximum 200, newest first, `no-store`. Answer `{ photos, total, limit, offset }`. A photo has `id`, `width`, `height`, `alt`, `title`, `caption`, `images`, `crop`, `gallery_id`, `gallery_title`, `thumbnail_url`, `full_url`. | Lists embeddable galleries only. A photo in several galleries is listed once. `updated_since` (RFC 3339) keeps a photo added at or after the cutoff or whose gallery changed at or after it. Editing only a photo's alt text does not move it. |
| Per-gallery photo list | `GET /api/v1/embed/galleries/{id}/photos`, same scope. 404 for unknown, foreign, not embeddable, password, expired, client-only and proofing galleries. | Gives a definite answer for one gallery without listing everything. |
| File version | The 12 hex characters in `/share/img/{asset}/web-{version}.jpg`. A hash of object keys, original size, dimensions, crop and the watermark flag (`public-image-urls.md`). | Changes when the photo is reprocessed or re-uploaded, when the crop changes and when the gallery's watermark is toggled. It ignores `updated_at`, so reordering does not change it. It ignores title and caption. `Photo_Catalogue::normalise()` already reads it as `version`. |
| Create gallery | `POST /api/v1/embed/galleries`, scope `galleries:write`. Body `{"title": "..."}`, 1 to 200 characters after trimming, else 400. Answer 201 with one row of the gallery list. | The gallery is private. Embedding stays a separate switch (`PUT /api/v1/embed/galleries/{id}/embeddable`, scope `galleries:embed`). |
| Upload photo | `POST /api/v1/embed/galleries/{id}/photos`, scope `galleries:write`, `multipart/form-data` with one `file` part. 404 for a gallery the account does not own, 400 for a type other than image, video or RAW, 413 when the file would pass the plan's storage quota. Answer 202 `{ id, gallery_id, filename, status: "uploaded" }`. | The photo is processed in the background. It appears in the photo list and as `created` in the feed when ready. The scope covers only these two POST routes. It does not cover the feed, deleting, gallery settings or the library tree. |
| Scope report | The token and refresh responses (`POST /api/v1/auth/devices/token` and `/refresh`) carry `scope`, a space separated list of the stored scopes (#1869). | Scopes are stored on the device row at pairing. A site paired before `galleries:write` existed keeps its old list until it pairs again. |
| Update or delete a photo or gallery with the plugin token | Not found. `galleries:write` covers create and upload only. | The plugin cannot propagate an edit or a removal to the platform. That fits the rule that deleting a copy in WordPress deletes nothing on the platform. |
| Webhook | Not found. #1869 says "A webhook can follow later". | Polling is the only mechanism. |
| Duplicate detection on upload | Not found. No client reference, idempotency key or content hash is documented on the upload route. | See "Failure handling" for what the plugin does about a lost answer. |
| Maximum file size of an upload | Not found. Only the storage quota check (413) is documented. | The plugin sends no file the host cannot read into memory (see "Rule 4"). |

## What the plugin has today

- `Photo_Importer` stores `_profotograaf_photo_id`, `_profotograaf_gallery_id` and `_profotograaf_version` on an attachment. `find()` and `find_many()` look photos up by id. `reimport()` replaces the file with the current `web` variant under a per-photo lock, keeps the attachment id, title, caption and alt, and answers `profotograaf_photo_gone` when the catalogue no longer lists the photo.
- `Photo_Catalogue` stores the list in the option `profotograaf_photo_catalogue` (`photos`, `stale`, `fetched_at`, `dropped`), fills it from `GET /api/v1/embed/photos` in pages of 200, caps it at 2000 photos (`MAX_PHOTOS`) and keeps the stored copy, flagged stale, when a refresh fails. `Photo_Catalogue_Refresh` runs it twice a day while `media_source` is on and the site is connected.
- `Media_Import_Screen` adds the grid filter (`filter_query()` with a `meta_query` on `_profotograaf_photo_id`) and the Source field with the Re-import button (`source_field()`).
- `Connection` stores `scope_revision` and `embed_denied`. `needs_reconnect()` compares `scope_revision` with `Config::SCOPE_REVISION` (2). It stores no scope list, and nothing reads `scope` from the token responses yet.
- `Cron_Health` records a heartbeat when a lead delivery event runs (`Delivery::SWEEP` and `Delivery::HOOK`), judges cron stale after three hours (`STALE_AFTER`), and reports through Site Health and a settings page notice that shows only while leads are waiting. It also builds the crontab line (`cron_line()`).
- `Admin_Notices` has notice kinds with a per-episode signature that a user can dismiss.

## Rule 1: removal and change on the platform

### States

An imported attachment has one sync state, stored in `_profotograaf_sync_state`. A copy with no state value is `synced`.

| State | Meaning | Clears when |
| --- | --- | --- |
| `synced` | The platform lists the photo and its file version matches the copy. | |
| `changed` | The platform lists the photo with a different file version, crop or text. The file or text on the platform is newer. | The author re-imports, or chooses "Keep my copy". |
| `missing` | The platform no longer lists the photo. A reason is stored next to it (below). | The photo is listed again (reasons `unavailable` and `left_gallery` only), the author chooses "Keep", or the author removes the copy. |
| `conflict` | `changed`, and the copy was also edited in WordPress (Rule 2). | The author resolves it. |
| `kept` | The author decided to keep the copy as its own file. | Never. The plugin stops watching this attachment. A "Watch again" link in the details sets it back to `synced`. |

### Reasons for `missing`

The reason makes the message useful. It comes from the strongest signal available.

| Reason | Signal | Message to the author |
| --- | --- | --- |
| `deleted` | A feed entry in `photos.deleted` with empty `gallery_id`. | "This photo was deleted on Profotograaf." |
| `left_gallery` | A feed entry in `photos.deleted` with a `gallery_id`, and the photo is not in the photo list. A photo that is still listed (in another gallery) is not missing. The plugin updates `_profotograaf_gallery_id` to the listed gallery. | "This photo was taken out of the gallery X on Profotograaf." |
| `gallery_deleted` | The gallery id is in `galleries.deleted`, or the gallery id is not in `GET /api/v1/embed/galleries` and that list has fewer than 500 rows. | "The gallery X was removed on Profotograaf." |
| `unavailable` | The gallery is in the gallery list with `available: false` or `embeddable: false`. | "The gallery X is not available for embedding right now (embedding switched off, a password, an expiry date or proofing mode)." Likely reversible. |
| `unknown` | The photo is absent from a complete photo list and none of the above applies. | "Profotograaf no longer lists this photo." |

`unavailable` and `left_gallery` clear on their own when the photo is listed again, because both have an ordinary way back. `deleted`, `gallery_deleted` and `unknown` stay until the author acts. A later `created` or `updated` feed entry for the same photo id clears any state, because the platform says that the later entry wins.

### Detection

One run does the following, in this order (details in Rule 3):

1. Read the feed from the stored cursor. Collect the photo ids and gallery ids in all lists.
2. Keep the entries whose photo id belongs to an imported attachment (`Photo_Importer::find_many()`), and the gallery ids found in `_profotograaf_gallery_id`.
3. Read `GET /api/v1/embed/galleries` once for the state of those galleries.
4. For `updated` entries, `created` entries and entries whose gallery changed, read `GET /api/v1/embed/photos?updated_since=<cutoff>` and compare each listed imported photo with the copy: file version, crop (through the version), title, caption, alt.
5. For each imported photo whose id is neither listed nor explained by a feed entry and whose gallery is available, call `GET /api/v1/embed/galleries/{id}/photos` for that gallery. Deciding from a single complete answer avoids a false flag.
6. Write the state and update the count option.

Guards against false flags, because a flag the author must dismiss costs trust:

- Nothing is flagged from a stale catalogue, a failed request or a response with an error. The state stays as it was.
- Absence from the full photo list counts only when the catalogue did not drop photos (`dropped` is 0) and the stored list is not stale. The catalogue holds at most 2000 photos, so a bigger library cannot use absence and relies on feed entries and step 5.
- Absence from the gallery list counts only below 500 rows.
- After a pairing the cursor is reset. If the new account lists none of the imported photos and at least one copy exists, the run flags nothing, pauses the sync and shows a one-time notice: "This Profotograaf account has none of the photos imported on this site. Syncing is paused." with a Resume button. This protects against flagging every copy after the site connects to a different account. The plugin stores no account id today (only the device id), so this is a heuristic. Owner question 6 asks whether the platform can supply one.

### What the author can do

All actions need `edit_post` on the attachment and run from the attachment details, the Import screen's attention list, or the bulk actions in the library list.

| Action | Effect |
| --- | --- |
| Keep | Sets `kept`. Records who and when in `_profotograaf_sync_decision`. The copy is the site's own file from then on. |
| Remove | Opens a confirmation that names the number of posts that use the file (a bounded `LIKE` search of `post_content` for the attachment id, run only when the author asks) and says that the file on Profotograaf is not touched. After confirmation it calls `wp_delete_attachment()`. Core deletes immediately unless `MEDIA_TRASH` is defined, in which case the confirmation says "move to the trash". The plugin never deletes in a cron job, a REST poll or an activation hook. |
| Remove all that are missing | Bulk version on the Import screen. One confirmation shows the count and a sample of file names. It processes in batches of 20 through REST and can be stopped. |
| Re-import | Existing action (#102). Allowed for `changed`. For `conflict` it opens the conflict dialog. Not offered for `missing`. |
| Dismiss the notice | Dismisses the admin notice for this count only (the existing notice signature mechanism). The flag stays. |

A copy that is in use stays in use whatever its state. The front end reads the file from the Media Library, as `docs/media-source.md` says, so a platform deletion changes nothing on the live pages until the author removes the copy.

## Rule 2: a photo changes on both sides

### Detecting a local change

The importer records a baseline at import and at re-import:

- `_profotograaf_file_hash`: `sha1_file()` of the stored original at the time the plugin wrote it.
- `_profotograaf_text_hash`: a hash of `title`, `caption` and `alt` as the plugin wrote them.

An attachment is edited locally when the file hash of the current original differs (the Media Library image editor writes a new file and the `_wp_attachment_backup_sizes` meta) or when the text hash of the current title, caption and alt differs. Copies imported before this change have no baseline. For those, the first run records the current values as the baseline and treats the copy as unedited. That can miss an edit made earlier, and the note in the UI for those says "edits made before the update are not detected".

The hash covers the original file only. The generated sizes follow from it.

### Outcomes

| Platform changed | Edited in WordPress | Result |
| --- | --- | --- |
| No | No | `synced`. |
| Yes (file version differs) | No | `changed`. The details show "A newer version of this photo exists on Profotograaf" and Re-import. |
| Yes (only title, caption or alt differ) | No | `changed` with a text difference. "Update text" copies the platform text into the attachment. |
| No | Yes | `synced`. The platform has nothing newer. The details show nothing extra. A local edit is the author's own. |
| Yes | Yes | `conflict`. |

The plugin never applies a remote change by itself, so "last write wins" never happens. A `changed` state waits for a click.

### Resolving a conflict

The dialog shows the copy on the site next to the current platform `web` image (thumbnail URLs from the catalogue) and, for text, the three values per field: baseline, site, platform. Three choices:

1. Keep mine. Records the platform's current file version as accepted (`_profotograaf_version` takes the platform value, hashes take the current local values) and sets `synced`. Later platform changes flag again.
2. Use Profotograaf's version. Runs `Photo_Importer::reimport()`. The local edit of the file is lost, so the dialog says so and offers to download the current file first.
3. Keep both. Duplicates the attachment (file copy and meta, without the `_profotograaf_*` link keys), then runs the re-import on the original attachment. The duplicate is an ordinary file with no link to the platform. This costs one extra file on disk, which the dialog states.

Text fields resolve per field: a field edited on only one side takes that side without a dialog, only on the author's "Update text" click. A platform value of `''` never replaces a non-empty local value. This matters because the platform's `alt` is empty for every photo today (`embed-api.md`).

### Upload direction

The upload sends a file once and creates a platform photo. After the upload nothing sends later edits, because the token has no update route (not found in the platform docs). The copy and the platform photo then diverge. This note treats it as expected, and the details show "Uploaded to Profotograaf on <date>. Later edits stay on this site." The Re-import action is not offered for a file with origin `upload`, because the platform only serves a 1600 px `web` variant and re-importing would replace the full-size original on the site with it.

## Rule 3: polling the change feed from WP-Cron

### Schedule and run

- Event `profotograaf_poll_changes`, schedule `hourly`, scheduled by a module in the way `Photo_Catalogue_Refresh` does: only while `media_source` is on and the site is connected, removed on disconnect, removed on deactivation and uninstall (all `profotograaf_` events are cleared already).
- The run takes a lock (an option with a TTL, like `Photo_Importer::LOCK_PREFIX`) so two cron requests never overlap.
- It reads at most 20 feed pages or 20 seconds per run, whichever comes first, following `has_more` with `limit=500`. The cursor option is written after each applied page. At-least-once delivery makes a repeated page harmless. A run that stops on the time budget continues on the next run.
- Entries are filtered to imported photos before any other work. A site with 40 imported photos and a photographer with 20000 photos reads many feed pages and writes almost nothing.
- Then the steps from "Detection". The catalogue is refreshed from the same data (`updated_since`) instead of a second full listing, when the feed reported changes for its galleries.
- A run records `profotograaf_changes_last_ok` (unix time) and the cursor. A failed run records the error and leaves both as they were.
- After `resync: true`: store the new cursor first, then run `Photo_Catalogue::refresh()`, read the gallery list and reconcile every imported attachment against the complete lists in batches of 50 through single cron events.

Interval reasoning: the feed is `no-store` and the five-minute cache of the public gallery JSON does not apply to token routes, so the feed shows a change as soon as the platform commits it (plus the 5 seconds of lag). One hour keeps the load low and matches how fast an author reacts to a flag. The interval is a constant and a filter so it can change after real use.

### Hosts with cron disabled

`Cron_Health` already detects this for lead delivery. Its logic is a heartbeat plus `DISABLE_WP_CRON`. The poll uses the same signal and adds three things.

1. The poll event calls the heartbeat too (`add_action( 'profotograaf_poll_changes', array( $health, 'beat' ), 1 )`), so one working cron proves both.
2. The messages in `Cron_Health` talk about leads only, and the settings page notice appears only while leads are waiting. A child issue generalises both: the Site Health test and the notice mention "photo changes" when the media source is on and `profotograaf_changes_last_ok` is older than `STALE_AFTER`, and they show the crontab line from `cron_line()`.
3. A fallback that works without cron:
   - "Check now" button on the Import screen and in the settings page. It calls a REST route (`POST /profotograaf/v1/sync/run`, `upload_files`) that runs one bounded poll.
   - Opportunistic poll from the admin: when an editor opens the Media Library, the Import screen or the settings page and the last successful run is older than 6 hours, the page script calls that route once in the background (throttled by a transient). The page does not wait for it. The front end never polls (CONTRIBUTING.md: front-end requests never wait on the platform).
   - The details and the Import screen always show "Last checked <relative time>", and "never" when no run succeeded, so a stale site is visible.

A host without cron and without admin visits is not covered. Nothing on the front end may fill the gap, and a platform webhook does not exist (not found). The Site Health test names the problem in that case.

## Rule 4: opt-in upload from the Media Library

### Opt-in levels

1. Site setting `media_upload` (default off, in the schema next to `media_source`, removed by uninstall). Off means no UI and no request.
2. Linked galleries, stored in the option `profotograaf_upload_links`: a list of `{ gallery_id, title, created_by, created_at, auto }`. A link is created in the plugin settings in one of two ways: "Create a gallery" (`POST /api/v1/embed/galleries` with a title) or "Use an existing gallery" (chosen from `GET /api/v1/embed/galleries`, with a confirmation that photos land in that gallery). Unlinking removes the entry and leaves the platform gallery and its photos untouched.
3. Files. A file goes to Profotograaf only after a person assigns it:
   - the field "Send to Profotograaf" on the attachment details (a select with the linked galleries),
   - the bulk action "Send to Profotograaf" in the library list view,
   - optional per link: "Send new uploads automatically" (`auto`, default off) for uploads by users with `upload_files`, limited to images.

   There is no default gallery, so a file is never sent without one of these three choices. This is the "synced folder" behaviour without making every upload public.

Photos created through this route are private to the photographer until the photographer switches embedding on for the gallery (`galleries:embed`, separate). Nothing in this feature changes who can see a photo.

### What is sent

- The original image (`wp_get_original_image_path()`, so the pre-scaled file) as the `file` part, with the file name. It carries any EXIF data in it, GPS position included. The settings text and External services entry say so. Whether the platform strips EXIF: not found.
- Only `image/jpeg`, `image/png`, `image/webp`, `image/gif` and `image/heic` files by plugin rule. The platform accepts image, video and RAW (`embed-api.md`). The plugin leaves video and RAW out of the first version because `Transport::send()` takes the body as one string and so needs the whole multipart body in memory. A child issue sets a size cap derived from `wp_convert_hr_to_bytes( WP_MEMORY_LIMIT )` and tests a large file on the matrix.
- Nothing else leaves the site: no post content, user data or site data. The gallery title is sent for a created gallery.

### Queue and state

- Per attachment: `_profotograaf_upload_gallery` (target gallery id), `_profotograaf_upload_state` (`queued`, `uploading`, `uploaded`, `failed`, `skipped`), `_profotograaf_upload_error` (a translated message), `_profotograaf_upload_attempts`, `_profotograaf_uploaded_at`.
- Success stores the answer's `id` in `_profotograaf_photo_id` and `gallery_id` in `_profotograaf_gallery_id`, and sets `_profotograaf_origin` to `upload`. `Photo_Importer::find()` then returns this attachment when the photo shows up in the catalogue, so the catalogue never creates a second attachment for a photo this site uploaded. Imports set `_profotograaf_origin` to `import`. A missing value means `import`.
- A worker event (`profotograaf_upload_queue`) takes one file per run and schedules the next single event, with the backoff shape of `Leads\Queue` (`BASE_DELAY` 60 seconds up to `MAX_DELAY`, `MAX_ATTEMPTS`). Whether to reuse `Leads\Queue` with a new job store or write a small meta-backed queue is decided in the child issue after reading that class. No upload happens inside an admin request.
- 413 (quota) is final for that file: state `failed`, message "Your Profotograaf storage is full." and a link to the account. 404 (gallery gone or foreign) disables that link and shows a notice. 400 (type) is final for that file. 403 is the scope problem below. 5xx, 429 and network errors retry.

### Scope and the reconnect flow

Today `Config::SCOPES` is `galleries:read leads:write galleries:embed`. `Pairing::start()` sends it as `scope`. The comment in `Config` says the platform grants the scopes registered for the client id, so the request is what the approval page shows. `Connection::needs_reconnect()` compares `scope_revision` with `Config::SCOPE_REVISION` and shows the prompt to every site below it.

Two things follow:

- Raising `SCOPE_REVISION` for `galleries:write` would show a reconnect prompt to every connected site, including those that never upload. The design leaves `SCOPE_REVISION` at 2.
- The decision needs the scope list the token really holds. `docs/embed-api.md` says the `scope` field of the token and refresh responses is that list, and that the plugin should read it. The plugin does not store it yet.

Design:

1. `Connection::save_tokens()` stores `scope` (the string, from pairing and from every refresh). `Connection::has_scope( string $scope )` answers from it. A connection with no stored scope (paired before this change) refreshes once, which fills it. The first refresh after the update fills the value without a prompt.
2. `Config::UPLOAD_SCOPE = 'galleries:write'`. The pairing request adds it only when started from the upload panel: `Pairing::start( array $extra_scopes = array() )`. Whether the platform grants fewer scopes when fewer are requested: not found. The panel therefore does not promise that other sites keep a narrower token.
3. The upload panel shows one of three states: connected with the scope (controls), connected without it ("Sending files needs a new permission. Connect this site again." with the existing pairing UI), not connected (the normal connect flow).
4. A 403 from a write route sets a `write_denied` flag next to `embed_denied`, the way `Api_Client::mark_embeddable()` handles `galleries:embed`, and the panel shows the same reconnect state. A successful pairing with the scope in the response clears the flag.
5. After the reconnect the queue resumes. Files in `queued` stay queued while the scope is missing, and the panel says how many wait.

The reconnect reuses the pairing the settings page already has (device authorization, RFC 8628). Whether the platform replaces the stored scopes when the same `device_id` pairs again: not found. The existing `galleries:embed` reconnect flow depends on it, and the platform docs say "re-pairs, which stores the current list", so this note relies on that. The E2E mock platform in `tests/e2e/mock-platform.mjs` gets the matching routes.

## Data model

### Post meta on attachments

Names start with `_profotograaf_`. They stay on attachments when the plugin is deleted, as the existing keys do (imported photos are the site's content).

| Key | Written by | Value |
| --- | --- | --- |
| `_profotograaf_photo_id` | importer, upload | Existing. Platform photo id. |
| `_profotograaf_gallery_id` | importer, upload, reconcile | Existing. Updated when a photo is listed in another gallery. |
| `_profotograaf_version` | importer, re-import, "Keep mine" | Existing. File version (12 hex characters) the copy matches. |
| `_profotograaf_origin` | importer, upload | New. `import` or `upload`. |
| `_profotograaf_file_hash` | importer, re-import | New. `sha1` of the stored original, the conflict baseline. |
| `_profotograaf_text_hash` | importer, re-import, "Update text" | New. Hash of title, caption and alt as written. |
| `_profotograaf_sync_state` | reconcile, author actions | New. `changed`, `missing`, `conflict`, `kept`. Absent means `synced`. |
| `_profotograaf_sync_reason` | reconcile | New. `deleted`, `left_gallery`, `gallery_deleted`, `unavailable`, `unknown`. |
| `_profotograaf_sync_since` | reconcile | New. Unix time of the first run that set the state. |
| `_profotograaf_remote_version` | reconcile | New. The newer file version the platform lists (state `changed`). |
| `_profotograaf_sync_decision` | author actions | New. `{ action, user, at }` for `kept` and "Keep mine". |
| `_profotograaf_upload_gallery`, `_profotograaf_upload_state`, `_profotograaf_upload_error`, `_profotograaf_upload_attempts`, `_profotograaf_uploaded_at` | upload | New. See Rule 4. |

The grid filter reads `_profotograaf_sync_state` with a `meta_query` through the existing `ajax_query_attachments_args` hook.

### Options

All start with `profotograaf_`, so `uninstall.php` already removes them. Every one is stored with autoload off.

| Option | Value |
| --- | --- |
| `profotograaf_settings` (existing) | New keys `media_upload` (bool, default false). Nothing new for the sync itself, which follows `media_source`. |
| `profotograaf_changes_cursor` | The opaque feed cursor. Deleted on disconnect. |
| `profotograaf_changes_last_ok` | Unix time of the last run that finished without error. |
| `profotograaf_changes_status` | Last run result: counts applied, last error code, paused flag. |
| `profotograaf_sync_counts` | `{ changed, missing, conflict }`, kept up to date at every state change, so the notice and the Import screen read one option and never count meta on a page load. |
| `profotograaf_changes_lock` | Run lock with TTL. |
| `profotograaf_upload_links` | Linked galleries (Rule 4). |
| `profotograaf_connection` (existing) | New `scope` string and `write_denied` flag. |

Events: `profotograaf_poll_changes` (hourly), `profotograaf_upload_queue` (single events).

## UI touch points

- Attachment details. The existing Source field gets a status line under the gallery link: nothing for `synced`; for the other states the message of the reason, the date, and the buttons for that state (Keep, Remove, Re-import, Resolve). Text states use `role="status"` and the buttons are real buttons with visible focus, like the Re-import button. The state is never shown with a colour alone.
- Media grid. The existing filter gets the values "Profotograaf photos", "Needs attention" (`changed`, `missing`, `conflict`) and "Waiting to upload". The list view gets a "Profotograaf" column with a short status, and the bulk actions "Send to Profotograaf" and "Stop watching" (sets `kept`).
- Import screen. A top section "Imported photos that need attention" with the count by state, the "Check now" button, "Last checked" and the bulk removal. Empty when nothing needs attention.
- Admin notice. One new kind in `Admin_Notices` ("Profotograaf: N imported photos need attention"), shown on the Media screens and the settings page only, with the signature `sync:<missing>:<changed>:<conflict>`, so a new problem shows again after a dismissal and the same problem does not. It links to the filtered library.
- Site Health. A test for the poll: good when the last run succeeded within `STALE_AFTER`, recommended when the host runs no cron, with the crontab line. A second test for pending uploads that failed.
- Settings page. A "Photo changes" line (last checked, paused state, Resume) and the "Upload" panel (setting, linked galleries, reconnect state).
- Not in scope: a notice inside the block editor for a post that uses a flagged photo. It needs a post-content scan per save. It is listed as a possible later issue.

Strings use `__()` and, in JavaScript, `@wordpress/i18n` with `wp_set_script_translations`, like the existing screens.

## Privacy and External services

New outbound calls. All come from the server, carry the access token and happen only after the owner opted in.

| Call | When | Data sent | Data received |
| --- | --- | --- | --- |
| `GET /api/v1/embed/changes` | Hourly while `media_source` is on, and on "Check now" | The cursor | Ids of changed galleries and photos |
| `GET /api/v1/embed/galleries` and `/photos?updated_since=` | Same runs | Query parameters | Gallery and photo rows already listed in the media source entry |
| `POST /api/v1/embed/galleries` | When the owner creates a linked gallery | The gallery title | The new gallery row |
| `POST /api/v1/embed/galleries/{id}/photos` | For each file a person sent while `media_upload` is on | The original image file with its file name (and its EXIF data) | The photo id |

Required in the same PRs that add the calls:

- `readme.txt` "External services": a "Photo changes (only if you opt in to the photo library)" entry and a "Sending files to Profotograaf (only if you opt in)" entry with the same detail level as the existing "Photo library in the editor" entry, including the EXIF sentence and the sentence that photos of people sent to the platform are personal data the site owner is responsible for.
- The FAQ entry "What happens when I remove a photo or gallery on Profotograaf?" in `readme.txt` currently says the plugin does not remove or change copies and that Re-import reports the photo as gone. It needs the new flags. The entry on deleting in WordPress stays true.
- The privacy text and the exporter (#26) mention the sync state keys and the upload links, and that the plugin sends files only after a person assigns them.
- The README documents the crontab line for hosts without cron.
- `uninstall.php` already removes the `profotograaf_` options and events. The per-attachment meta stays, as for imports. A test checks the new option names are covered by the prefix.
- Telemetry (`docs/telemetry.md`): no new events. The sync sends no ids or titles to telemetry. If counts of states are wanted later, that is a separate decision with its own consent text.

## Failure handling

| Failure | Behaviour |
| --- | --- |
| Feed 401 or token refresh failure | Existing connection handling (`profotograaf_refresh_failed`, revoked state). The poll stops without touching states and resumes after reconnect. |
| Feed 403 | Treated as the scope problem; the poll writes `profotograaf_changes_status` and shows the reconnect state. The feed needs only `galleries:read`, which every paired site holds, so this is rare. |
| 5xx, 429, timeout | The run ends, the cursor stays, the next run retries. Error counts go to the logger (Logger::warning). After three failed runs in a row a Site Health item and, after 24 hours, the admin notice the existing refresh failure uses. |
| Malformed answer | Same as a failed request. No state changes. |
| Cursor rejected or expired | The answer is `resync: true`. The plugin takes the new cursor and reconciles against the complete lists. |
| Catalogue truncated (`dropped` above 0) or gallery list at 500 rows | Absence is not used as a signal. Feed entries and per-gallery lookups still work. The Import screen says the library is larger than the plugin can list. |
| Run interrupted | The cursor is written after each applied page, so the next run repeats at most one page. Applying an entry twice does nothing. |
| Two runs at once | The lock stops the second. A stale lock expires with its TTL. |
| Account changed | The pause heuristic in "Detection". The cursor and the counts are deleted on `profotograaf_disconnected`. States already set stay, because the author has not decided on them. |
| Attachment deleted by the author | Its meta goes with it. Counts are recomputed on the next run. |
| Upload: lost answer | The platform has no idempotency key (not found), so a request that was accepted but whose answer was lost can upload twice on retry. The plugin sets state `uploading` before the request. After a timeout it does not retry blindly. It looks for a photo with the same file name in the gallery (`GET /api/v1/embed/galleries/{id}/photos?q=<name>`, which searches title, caption and alt; whether `title` equals the upload file name: not found, to be checked in the child issue) and keeps a duplicate possible only when that check is inconclusive. The failed state says the file may exist on Profotograaf. |
| Upload: file unreadable, too large for memory, missing | State `failed` with a translated message, no retry. |
| Upload: quota (413), type (400), gallery gone (404), scope (403) | See Rule 4. |
| Front end | Nothing in this design runs on a front-end request. Copies keep serving from the Media Library whatever the state. |
| Multisite | Options, cursor, links and events are per site, like the connection. The network page shows no sync state. |

## Proposed child issues

Not created. The order is the dependency order. "Platform" names what the issue needs from `wiebe-xyz/professionals` beyond what exists.

| # | Title | Scope | Depends on | Platform dependency |
| --- | --- | --- | --- | --- |
| 1 | Store the granted scope list on the connection | `Connection::save_tokens()` keeps `scope` from the pairing and refresh responses, `has_scope()`, refresh once after update to fill it, tests with the fake transport, mock platform returns `scope` | none | None. The `scope` field is documented in `embed-api.md`. Verify against the live host |
| 2 | Record the conflict baseline at import | Importer and re-import write `_profotograaf_origin`, `_profotograaf_file_hash`, `_profotograaf_text_hash`, backfill on first run for old copies, tests | none | None |
| 3 | Reconcile engine for imported copies | Pure class that takes the catalogue, the gallery list, feed entries and the imported set and returns state changes with reasons, including the guards (stale, truncated, 500 rows, account pause), `_profotograaf_sync_*` meta, `profotograaf_sync_counts`, unit tests for every row of the reason table | 2 | None |
| 4 | Poll the change feed from WP-Cron | `Api_Client::changes()`, cursor and status options, lock, bounded run, resync path, `profotograaf_poll_changes` module, heartbeat into `Cron_Health`, mock platform route, tests | 3 | None. Feed exists (#1869) |
| 5 | Cover hosts without cron | "Check now" REST route, opportunistic background call from the admin screens, "last checked" text, `Cron_Health` and Site Health messages generalised beyond leads, README crontab text | 4 | None |
| 6 | Flag actions on attachments | REST routes for Keep, Remove (with the used-in count), bulk removal in batches, Stop watching; confirmation UI; attachment details status line and buttons; capability checks; tests | 3 | None |
| 7 | Show what needs attention | Grid filter values and list column, Import screen attention section, admin notice kind, Site Health item, count option | 4, 6 | None |
| 8 | Resolve conflicts | Conflict dialog, Keep mine, Use Profotograaf's version, Keep both, per-field text resolution, "Update text", E2E | 2, 6 | None |
| 9 | Document the lifecycle for users | `readme.txt` External services, FAQ changes, Privacy, changelog, README, privacy text and exporter, screenshots through the #120 spec | 7, 8 | None |
| 10 | Add `galleries:write` and the reconnect state | `Config::UPLOAD_SCOPE`, `Pairing::start( $extra_scopes )`, `write_denied` flag, upload panel states, tests | 1 | Check whether a narrower scope request yields a narrower token (not found) |
| 11 | API client for gallery creation and upload | `Api_Client::create_gallery()` and `upload_photo()` with multipart body, error mapping for 400, 403, 404, 413, memory cap, mock platform routes | 10 | None. Routes exist (#1869) |
| 12 | Upload links and queue | `media_upload` setting, `profotograaf_upload_links`, per-attachment field and bulk action, optional automatic send, queue and states, `origin` handling in `find()` and Re-import, Site Health item, tests | 11 | Idempotency or a client reference on the upload (P3 below) improves the lost-answer case |
| 13 | Document the upload | `readme.txt` External services and Privacy (EXIF sentence), README, uninstall test, screenshots | 12 | None |
| 14 | E2E and compatibility run | Specs on the CI matrix (WordPress 6.9, 7.0, 7.1) against the mock platform for flagging, keep, remove, conflict, cron-less check and upload with a missing scope | 7, 8, 12 | None |

Issues 1 to 9 deliver rules 1 to 3. Issues 10 to 13 deliver rule 4 and can start after issue 1 in parallel with 3 to 9.

Platform issues to open in `wiebe-xyz/professionals` (proposed, not created):

- P1: Deleting a gallery directly writes a tombstone to the change feed. The only writer found is the folder deletion (`embedTombstoneFolderGalleries`).
- P2: The feed reports when a gallery stops or resumes serving (embedding off, password, expiry, proofing, client-only), for example as `galleries.updated` with a state field or as a new list `galleries.unavailable`. Today the plugin infers it from the gallery list.
- P3: An idempotency key or a `client_ref` form field on `POST /api/v1/embed/galleries/{id}/photos` that is echoed in the photo list.
- P4: Document the maximum upload size and whether EXIF is kept on the stored original.
- P5: An account identifier in the token response (and the feed), so the plugin can tell a changed account from deleted photos.
- P6: Whether a title or caption edit moves the photo's `updated_at` for the feed and for `updated_since` (the docs say an alt text edit does not). Without it the plugin catches text changes only through a full list.
- P7: A webhook (named as possible in #1869), which removes the polling delay and the cron dependency.
- P8: Update and delete routes for a photo under a separate scope, only if the owner wants two-way sync (owner question 3).

## Open questions for the owner

1. Flag only, or may a copy be removed automatically after a long time? This note says flag only and never delete without a click. Is that final, including for a photo deleted on Profotograaf because the client asked for removal (a privacy request)? That case may need a faster path, such as a high-priority notice.
2. Polling once an hour (with a manual check) is the default here. Is the delay acceptable, or should it be shorter at the cost of more load on the platform and more cron runs on small hosts?
3. Should the upload ever change or delete remote photos, or stay create-only? Create-only needs no new scope and no update route. Two-way sync needs P8, conflict rules in both directions and a stronger privacy text.
4. Upload target: may a person send files into any existing gallery of the account (including a client gallery), or only into galleries the plugin created? This note allows both with a confirmation. Limiting to created galleries is simpler to explain.
5. Should the upload send the original file with its EXIF data, or should the plugin strip location data first? Stripping needs an image library step and changes the file.
6. When the platform gives no account id (P5), is the "no imported photo found, pause" heuristic enough, or should the plugin ask the owner to confirm after every reconnect?
7. Should the sync run when `media_source` is off but imported copies exist (for example after the owner switched the library off)? This note says no, because it needs the catalogue calls the setting controls.
8. Does the lifecycle sync need its own switch, or does `media_source` cover the consent? The calls carry the same token and no personal data, and the External services text discloses them, so this note ties it to `media_source`.
9. Which roles see the flags and may remove copies: `edit_post` on the attachment (the default here) or administrators and editors only?
