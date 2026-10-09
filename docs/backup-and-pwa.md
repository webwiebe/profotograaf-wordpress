# Invisible backup and client PWA

Design note for issue #47 (discovery, part of epic #48). No code ships with this note. It answers the three questions in the issue, lists the child issues to open and the questions that need an owner decision.

The media lifecycle (what happens to imported copies when a photo disappears from Profotograaf) belongs to #46 and its note, `docs/media-lifecycle.md`. This note covers the other direction, WordPress to Profotograaf, and the client PWA link. Where the two meet, this note says so and leaves the decision to #46.

## Recommendation

1. Build the backup as an opt-in, separate setting (`backup`), off by default, with three scopes: images used on the site, every image in the Media Library, or images the photographer marks. Recommended default when switched on: images used on the site. Reasons are in "Question 1".
2. Upload the original file of each image through `POST /api/v1/embed/galleries/{id}/photos` into private galleries that the plugin creates and never makes embeddable. Backfill and new uploads share one queue, drained by WP-Cron in small batches.
3. Show the state in WordPress (settings panel, Media Library column, Site Health) and let the platform show the photos as ordinary private galleries.
4. Offer the client PWA as a link, because a PWA installs only from its own origin. The platform makes each delivered gallery's share page installable. The plugin lists those links on its settings page and lets the existing "Find your gallery" block point at one of them. It ships no service worker or manifest.
5. Say plainly in every screen and in readme.txt that the backup is a copy of uploads. It does not follow deletions or edits made later, and it is not a restore tool until the platform offers a way to fetch originals (open question 2).

## What the platform gives today

Read from `docs/embed-api.md`, `docs/uploads.md`, `docs/decisions.md` and `docs/features.md` in `wiebe-xyz/professionals`, from `internal/features/gallery/pwa.go` in the same repository, and from issues professionals#1869 and #1703. "Not found" means I looked in those files and found nothing.

| Fact | Source | Consequence |
| --- | --- | --- |
| `POST /api/v1/embed/galleries` creates a private gallery from `{"title"}` (1 to 200 characters) and answers 201 with `id`, `slug`, `title`, `url`, `embeddable`, `available`, `photo_count`, `updated_at`. | embed-api.md, "POST /api/v1/embed/galleries" | The plugin can create backup galleries without opening a browser. Sharing and the embed switch stay off. |
| `POST /api/v1/embed/galleries/{id}/photos` takes `multipart/form-data` with one `file` part. Answers 202 `{id, gallery_id, filename, status: "uploaded"}`, 404 for a gallery the account does not own, 400 for a type other than image, video or RAW, 413 when the file would pass the plan's storage quota. The photo appears in listings once processed. | embed-api.md | One file per request. A 413 is the quota signal. Processing is asynchronous. |
| Both POST routes need scope `galleries:write`, held by the `wordpress` client. A `galleries:read` token gets 403. A site paired earlier keeps its old scopes, the plugin reads `scope` from the token response and shows a reconnect prompt when `galleries:write` is missing. | embed-api.md, "galleries:write, the token's scope report and the reconnect prompt (#1869)" | This plugin requests `galleries:read`, `leads:write` and `galleries:embed` only (`Config::SCOPES`), and a search for `galleries:write` under `includes/` finds nothing. Every connected site needs a re-pair before the backup can run. |
| Deleting is not covered by `galleries:write`. | embed-api.md, same section | The backup cannot remove anything on the platform. That suits a backup. |
| Upload size cap is 100 MB for the app's presigned flow. The embed upload route says its checks are "the app's own", so the same cap probably applies. | uploads.md, "Size cap"; embed-api.md | Not confirmed for the embed route. Treat 100 MB as the plan and handle a 400 or 413 per file. |
| The processing worker handles one asset per 3 second tick. | uploads.md, "Process" | The doc describes one worker on the writer pod. Whether that is per account or shared by all accounts is not found. A large backfill queues behind everyone else's uploads. |
| `GET /api/v1/embed/changes?since=<cursor>` lists created, updated and deleted galleries and photos, with at-least-once delivery. | embed-api.md | The doc does not say whether private galleries appear. The feed query in `internal/features/gallery/embed_changes_store.go` selects by account and does not filter on the embed switch, so backup galleries and their photos show up in it. The plugin must ignore its own galleries there (see "Interaction with the media source"). |
| The desktop sync API (`POST /api/v1/sync/files`) takes a `sha256`, returns `already_exists: true` for a file already verified at the same path and returns the same session for a retried pending upload. | features.md, "Resumable library and desktop uploads" | This is the idempotent upload the backup would like. The doc names a paired desktop device. Whether the `wordpress` client may call it is not found, and `galleries:write` is documented to cover the two embed POST routes only. The embed upload route documents no idempotency. |
| A rate limit on the embed routes. | | Not found. |
| A route that reports the account's storage usage and cap to a token. | | Not found for the embed API. The sync WebSocket sends `quota` events (usage, cap, plan slug) to desktop devices (features.md). |
| Whether the platform strips GPS or other EXIF from stored originals, and whether two accounts or two uploads of identical bytes are deduplicated. | | Not found. |
| A gallery-level size or photo-count limit. | | Not found. |
| Free storage is 1 GiB. Paid caps are unchanged. The cap is plan storage plus storage packs (+10 GB EUR 1, +100 GB EUR 4, +500 GB EUR 15, +1 TB EUR 25 a month, stackable, on every plan). An upload past the cap is a 413 `storage.cap_reached`. Packs count on `active` and `trialing` subscriptions only. Files are never deleted or hidden when the cap drops. | decisions.md, ADR-025 and ADR-030 | The cap is the only limit the WordPress channel adds. A backup of a large library hits it on Free. |
| WordPress photographers sign up on the existing plans. Embeds are allowed on Free. The only limit the channel adds or removes is the storage cap. | decisions.md, ADR-025; professionals#1703 (closed, decided) | No separate backup plan or quota exists. Backup bytes compete with delivery bytes in one allowance. |
| Metering counts `original_bytes`. | decisions.md, ADR-030 | An original uploaded by the backup counts at its full size. |
| A gallery's share page `/share/g/{slug}` is an installable PWA: dynamic `manifest.webmanifest`, a service worker scoped to `/share/g/{slug}`, offline cache of the page shell and photos, and an install prompt. It is installable when the gallery's mode is not `proofing` and it has no password. The app icon is the photographer's logo, else the gallery's first photo. | `pwa.go` (`galleryInstallable`, `pwaIconURL`, `ManifestHTTP`), docs/spec.md | The PWA already exists per delivered gallery. A link is all the plugin has to provide. A gallery behind a password never installs. |
| The photographer's own app has a PWA with start URL `/`. | decisions.md, locale URL ADR | That is the photographer's app. It is not the client's. |
| The client portal (`/<address>/client`) as an installable PWA. | | Not found. Only the per-gallery share page is documented as installable. |
| The gallery list row carries `url`, the share page address. | embed-api.md | `Gallery_Index` already stores it (`includes/class-gallery-index.php`). |

## Question 1: which media, how often, what the photographer sees

### Which media

Three scopes, one setting `backup_scope`:

| Scope | Selects | Fit |
| --- | --- | --- |
| `used` (recommended default) | Image attachments referenced by a published post or page (featured image, `wp-image-{id}` class or block attribute, gallery block ids) plus attachments the photographer marks | Matches what a photographer calls "my portfolio photos". Skips theme demo images, plugin assets, form uploads and other users' attachments that never appear on the site. Smallest first upload against a 1 GiB Free cap. |
| `all` | Every image attachment in the Media Library | A true backup. Large on sites with years of uploads, and it includes whatever editors or visitors uploaded. |
| `marked` | Attachments with a bulk action "Back up to Profotograaf" (and a checkbox in attachment details) | Full control, no surprises, more work for the photographer. |

A "chosen folder" has no WordPress equivalent. Uploads live in `wp-content/uploads/YYYY/MM` and plugins add their own folders, so the plugin works with attachments and never with directories. The Media Library has no folder concept in core.

Rules for every scope:

- Image MIME types only: `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/avif`, `image/heic`. Video, audio, PDF and documents stay out. The platform accepts video and RAW, but a WordPress backup of those is a separate decision (open question 4).
- Skip attachments imported from Profotograaf (they carry `_profotograaf_photo_id`). Backing them up would send each photo back to the platform it came from and double its storage.
- The file sent is the original upload. Since WordPress 5.3 a large upload is scaled to 2560 px and the original is kept next to it, found with `wp_get_original_image_path()`. When none exists the file at `get_attached_file()` is the original. Generated sizes are never sent.
- Skip files above the cap with a recorded reason `too_large`. Skip files that no longer exist on disk with `missing_file`.
- A filter `profotograaf_backup_should_upload( bool $upload, int $attachment_id )` lets a site exclude attachments. The plugin ships no UI for it.
- Attachments uploaded by other users: included in `all`, and in `used` when used. The settings page says so, because on a multi-author site that can include images the photographer does not own (open question 5).

### How often

One queue, two producers:

- **Initial backfill.** On opt-in the plugin does not scan the library in the request. It stores a cursor (the highest attachment id processed) and schedules a WP-Cron single event. Each run walks the next 200 attachment ids, applies the scope rule, marks eligible ones `queued` in post meta and reschedules itself until the cursor passes the highest id.
- **Incremental.** `add_attachment` marks a new eligible image `queued`. For scope `used`, `save_post` for published content marks the referenced attachments `queued`. Nothing uploads inside the page request. Front-end requests never wait on the platform (CONTRIBUTING.md).
- **Upload worker.** A WP-Cron event every 5 minutes (plus the first run one minute after opt-in) takes `queued` items under a time budget of 20 seconds and a memory check, uploads one file per request, and stops early on a 429, 503, 413 or a transport error. The budget keeps it clear of PHP time limits on shared hosting. About 20 seconds and 100 MB per file gives a modest number of photos per run, so a 5,000 photo library takes days on a site with working cron. That is acceptable for an invisible backup, and the status panel shows the remaining count so nobody guesses.
- **Cron health.** The plugin has `Cron_Health`. When cron does not run, the backup status says so and shows the `*/5` system cron line the class already builds. A site with `DISABLE_WP_CRON` and no system cron backs up nothing, and the panel says that instead of showing a progress bar that never moves.
- **WP-CLI.** `wp profotograaf backup run` drains the queue in the foreground for hosts that allow it (the plugin already has `includes/cli`).
- **Edits and deletions.** Not followed in the first version. A later edit of the same attachment (the WordPress image editor writes new files) does not re-upload. A deletion in WordPress leaves the backup in place, and no delete scope exists. This is a feature of a backup, and the UI says it. Replacing a changed file would need a platform route that replaces or versions a photo, which is not found.

### Where the backup lands on Profotograaf

- Per year, one private gallery titled `{site title} backup {year}` (the year of the attachment's post date). A photo-count limit per gallery is not found, so one gallery for everything would work on paper. Per year keeps each gallery browsable and gives a rule that is easy to explain. Open question 3 asks the owner to choose.
- The plugin stores the gallery ids in an option and creates a gallery on first need.
- The plugin never calls `PUT .../embeddable` on a backup gallery. The `Photo_Catalogue` reads embeddable galleries only, so backup galleries stay out of the media tab and out of gallery pickers. A guard test locks that in.
- Per photo the plugin stores meta `_profotograaf_backup_state` (`queued`, `done`, `skipped`, `failed`), `_profotograaf_backup_photo_id`, `_profotograaf_backup_gallery_id`, `_profotograaf_backup_sha256` and a failure reason and count. `uninstall.php` removes them. The backed-up media stays on both sides.

### Idempotency and retries

The embed upload route documents no idempotency key and no duplicate detection. A request that times out after the platform stored the file would, when retried, store it twice and spend the storage twice. Options:

- A. Ask the platform to let the `wordpress` client use the sha256-checked sync upload (`already_exists`, retry-safe sessions), or to accept `sha256` on the embed route (platform issue P1). Best fix.
- B. Until then, mark an item `uploading` before the request and `done` after. An ambiguous result (timeout, 5xx) goes to `needs_check`. The plugin cannot look for the file afterwards, because `GET /api/v1/embed/photos` lists embeddable galleries only and the backup galleries are private. Recommended: never retry an ambiguous upload automatically, list it in the failures and let the photographer retry it, accepting a rare duplicate.

### What the photographer sees

In WordPress:

- Settings > Profotograaf > Backup: switch, scope, a status line ("1,240 of 3,810 images backed up, 12 waiting for you, last run 4 minutes ago"), a "Back up now" button, a "Retry failed" button, the failure list with reasons, and the total bytes sent. A banner when the quota is reached, with the upgrade link.
- Media Library list view: a "Backup" column (backed up, queued, failed, skipped with reason). Grid view: a line in attachment details with the gallery link.
- Site Health: a test "Profotograaf backup is running" that reports a stuck queue, missing `galleries:write`, or no cron.
- An admin notice only for conditions that need an action: reconnect required, quota reached, repeated failures (reusing #16's rules).

On Profotograaf: private galleries named `{site title} backup {year}`. They look like any private gallery. The app needs no change for the first release. A badge or "Source: WordPress" label would need a platform change (P3, optional).

## Question 2: where the client PWA link appears

What exists: each delivered, passwordless gallery's share page is installable (`pwa.go`). The client opens the share link on a phone and gets the platform's install prompt. The plugin cannot host that PWA: a manifest and service worker must come from the PWA's own origin, and the scope is `/share/g/{slug}` on the platform host. The plugin's job is to put the right link in front of the right person.

Who needs the link:

1. The photographer, to send it to a client after delivery (usually by email or message). A copy button and a QR code on the settings page serve this.
2. The client who visits the photographer's site. Today the "Find your gallery" block sends them to the portal sign-in (`/client`). The portal as an installable PWA is not found.

Candidate placements:

| Place | Verdict |
| --- | --- |
| Settings page, "Client galleries" list | Build first. Reads the stored gallery list (`Gallery_Index`: id, title, `url`, `available`) with no new request. Shows title, share URL, copy button, and whether the gallery is installable. Installable status is mode and password, and the gallery list row documents neither, so the list says "install prompt shows when delivered and not password protected" and does not claim a live state (check against the platform in P2). |
| `profotograaf/client-galleries` block, new optional attribute `gallery` | Build second. The block today renders a link to `/{portal}/client` and needs no platform data. With a gallery chosen it links to that gallery's share URL and says "Open your gallery" in its default copy. Without a gallery nothing changes, so existing pages keep their output. The attribute stores the share URL. |
| Block variation "Open a delivered gallery" | Same block, pre-set attribute, in the inserter. No extra code beyond the variation registration. |
| Widget (legacy widgets) | Skip. Block themes and the block widget screen accept the block. Classic widgets are not worth a second implementation. |
| Plain shortcode `[profotograaf_client_link gallery="..."]` | Optional. The plugin already has a shortcode module. Only worth it if classic-theme sites ask. |
| Automatic floating "Install our app" prompt on the site | Do not build. It would load platform code on every visitor page, which conflicts with the no-tracking and no-wait rules, and the platform has no app for the whole site. |

Copy and behaviour rules for the block: the link opens in the same tab by default, and the platform's page shows its own install prompt. The block makes no claim about installing. The platform install prompt text is the platform's, and the plugin shows no platform text raw (#4 definition of done).

A WordPress-side "install" needs nothing in `readme.txt` External services beyond what the block entry already says, because the block remains a plain link. The settings list reads the stored list only.

## Question 3: plan limits and quota effects

From ADR-025, ADR-030 and professionals#1703:

- No plan exists for the backup. The photographer uses their plan's storage. Free is 1 GiB. A backup in scope `all` of a typical photographer library (several GB) exceeds Free on the first run. The default scope `used` exists to keep the first run small.
- The backup spends the same allowance as delivery galleries. A backup that fills Free blocks the next client delivery upload with a 413 `storage.cap_reached`. This is the main risk of the feature. Mitigations in the plugin:
  - Pause on the first 413 and report "storage full" with the number of items left. Never retry in a loop.
  - Show total bytes queued next to the setting before the photographer switches it on (computed from file sizes on disk). The plan's cap is not readable by a token (not found), so the plugin cannot say "you have 1 GiB left". It shows the sum and links to the plan page.
  - Stop the backfill when a 413 arrives, and keep the incremental queue paused until the photographer presses "Back up now" or the next day.
- Storage packs (ADR-030) give the upgrade path on every plan. Past-due and canceled subscriptions fall back to the plan cap, and files stay. An account that is over its cap keeps its data and cannot add uploads (ADR-025). The backup then pauses and tells the photographer.
- Backup galleries never carry the embed badge, since they are private and not embeddable. The badge rules (ADR-025) do not change.
- Export is never plan-gated (ADR-027). That ADR is about leaving the platform. Whether the app's export or download returns the originals of a backup gallery in a form a photographer can restore from is not found in what I read.
- Rate limits: not found. The plugin sends one request at a time, honours `Retry-After` on 429 and 503 like the telemetry sender does (`Telemetry_Sender` waits on 429 and 503), and never runs two workers at once (a transient lock, as in `Photo_Importer`).
- The platform's processing worker takes one asset per 3 seconds. A 5,000 photo backfill adds about 4 hours of worker time, shared with other uploads. This is for the owner to weigh (open question 6).

## Interaction with the media source

- A backup of an image that was later imported from Profotograaf is skipped (meta check). An image that was backed up and later replaced by an import of the same visual photo is not detected, because no content hash is shared. Not a goal.
- The change feed returns backup galleries and photos. The plugin's consumers (#46) must ignore galleries recorded in the backup gallery option. This is a requirement for #46's design and is noted there by reference to this issue.
- Attachment details show two blocks that never overlap: "Imported from Profotograaf" (existing) and "Backed up to Profotograaf" (new).

## Privacy and consent

- Opt-in. The setting is off by default, in its own section with plain text: "Copies your Media Library images to your Profotograaf account." The telemetry opt-in does not cover it, and neither does the media source opt-in, since the data flows in opposite directions.
- External services. A new readme.txt entry in the same PR as the uploader: the plugin sends image files from the Media Library, the file name, the site title in the gallery name and the access token to `https://profotograaf.nl/api/v1/embed/galleries` and `.../photos`, from the server, only after opt-in, and the images may show people. The privacy policy section (#26's exporter and text) says the same. The uninstall section says the copies on Profotograaf stay.
- The photographer's clients. Photos in the Media Library may show clients and their children. The photographer is the controller and the site owner already holds these files. Sending a copy to Profotograaf makes Profotograaf a processor of those files. The plugin text asks the photographer to check that their privacy policy and client contracts allow this. The platform's data processing terms are not covered by anything I read (not found), so the owner should confirm that the existing terms cover it before release.
- EXIF. Originals can carry GPS coordinates. The plugin sends the file as stored. Whether the platform strips location data is not found (open question 7). The settings text states that metadata is sent too, and a later option can strip GPS before upload with the image editor.
- Multisite. The setting is per site, the token is per site, and the network admin cannot switch it on for all sites.
- Scope and token. `galleries:write` gives the plugin the ability to create galleries and upload photos. It cannot delete (embed-api.md). The token already lives in the connection storage. The write scope is requested only when the photographer switches the backup on, in a re-pair step that explains why. Requesting it for every site at connect time would widen every token for a feature most sites will not use. Whether the platform grants scopes per request is not found (`Config::SCOPES` is a fixed list today), so P4 asks for it.

## Failure handling

| Condition | Plugin behaviour |
| --- | --- |
| Token lacks `galleries:write` | Status "Reconnect to allow backups", Site Health fails, nothing uploads. No retry until the scope is present. |
| 401 | Existing token refresh (`Token_Refresh`). On a second 401, reconnect prompt. Queue untouched. |
| 403 on a write route | Same as missing scope. |
| 404 on a gallery id | The backup gallery was deleted on the platform. Create a new one, record the replacement, requeue items marked `done` in the lost gallery only if the owner confirms (default: no, list them as `gallery_missing`). |
| 413 | Pause, status "Storage full on Profotograaf", notice with the upgrade link, items stay `queued`. Resume on "Back up now" or daily. |
| 400 on a file | Mark `skipped` with `unsupported` or `too_large`. Do not retry. |
| 429, 503 | Stop the run, honour `Retry-After`, otherwise back off 5, 15, 60 minutes. |
| Timeout or 5xx after the request left | Mark `needs_check`, no automatic retry (duplicate risk, see "Idempotency"). |
| File missing or unreadable on disk | `missing_file`, no retry. |
| Platform unreachable | Run ends, the failure goes to the logger (#14) and Site Health (#15). An admin notice appears after repeated failures only (#16). The site is never affected: nothing in this feature runs on a front-end request. |
| PHP memory or time limit | The run checks `memory_get_usage` before each file and the elapsed time before each request. A file larger than the available memory is marked `too_large_for_host`, unless the transport can send it from disk. The transport interface needs a file-upload method for that (child issue 4). |
| Disconnect | Queue is cleared of pending items, meta stays, the settings page says what stays on Profotograaf. |
| Backup switched off | Cron events removed, queue paused, meta kept so switching it on again continues. |

## Proposed child issues

Not created. The order is the dependency order. "Platform" names a dependency on the platform repository.

| # | Title | Scope | Depends on | Platform |
| --- | --- | --- | --- | --- |
| 1 | Request `galleries:write` on demand and show the reconnect prompt | Add the scope when the backup is switched on, read `scope` from token responses, show the reconnect prompt, tests for missing scope. Shared with #46, which needs the same scope for its upload path, so whichever lands first owns it. | none | P4 (optional, scope per request). Otherwise add it to `Config::SCOPES`, which re-pairs every site. |
| 2 | Add the backup opt-in settings and privacy copy | `backup`, `backup_scope` in `Settings_Schema`, settings section with the warning text, readme.txt External services entry, privacy policy text, `uninstall.php`, default-off tests | none | none |
| 3 | Backup eligibility and queue state | Scope rules (`used`, `all`, `marked`), MIME filter, original file lookup, skip imported photos, post meta states, the filter, unit tests for each scope | 2 | none |
| 4 | Api_Client: create gallery and upload photo | `create_gallery()` and `upload_photo()` (multipart through the `Transport` interface, with a file-upload method), error mapping for 400, 403, 404, 413, 429, 5xx, tests with the fake transport | 1 | none, better with P1 |
| 5 | Backup worker: backfill, incremental sync, pause and resume | Cursor backfill in cron, `add_attachment` and `save_post` producers, 5 minute upload cron with time and memory budget, lock, backoff, per-year gallery creation, `wp profotograaf backup run`, tests | 3, 4 | none |
| 6 | Backup status in WordPress | Settings panel, Media Library column, attachment details line, "Back up now" and "Retry failed", bulk action for `marked`, Site Health tests, admin notices | 5 | none |
| 7 | Guard backup galleries out of the media source and the change feed consumers | Test that `Photo_Catalogue` never lists a backup gallery and that nothing sets it embeddable, helper `Backup_Galleries::contains()` for #46 | 5 | none |
| 8 | Client galleries list on the settings page | Share URL, copy button, QR code, note about passwords and proofing | none | P2 (installable flag in the gallery list) |
| 9 | Client galleries block: link to one delivered gallery | Optional `gallery` attribute, picker in the inspector, variation, server render, block.json test, i18n, E2E | 8 | none |
| 10 | Document the backup and the client link | README, readme.txt FAQ, screenshots (coordinate with #120) | 6, 9 | none |
| 11 | E2E: backup against the fake platform | Opt-in, backfill of seeded attachments, quota pause, reconnect prompt | 6 | none |

Platform issues to open in `wiebe-xyz/professionals` (proposed, not created):

- P1: idempotent upload for the `wordpress` client. Allow `sha256` on `POST /api/v1/embed/galleries/{id}/photos` and answer `already_exists` for a verified duplicate, or allow the `wordpress` client on the sha256-checked sync upload. Removes the duplicate risk on retries.
- P2: add `installable` (mode and password) and `mode` to the gallery list rows, so the plugin can state whether the PWA install prompt will appear.
- P3 (optional): mark a gallery as created by the WordPress plugin, so the app can label backup galleries and group them.
- P4: grant `galleries:write` per pairing request, or a way to add a scope to an existing pairing without a full re-pair.
- P5: a read of the account's storage usage and cap for a `galleries:read` token (the quota exists in the sync WebSocket only). Lets the plugin preview "this backup needs 4.2 GB, you have 0.6 GB free".
- P6 (for restore, only if the owner wants it): a stable, authenticated way for the plugin to fetch the original of a backup photo. The embed API serves the 1600 px `web` variant only (embed-api.md).

## Open questions for the owner

1. Is `used` the right default scope, or should "all images" be the default for a true backup? The first costs less storage and covers the portfolio. The second protects images that were never published.
2. Is the feature allowed to be called a backup without a restore path? Today the photographer can find the originals in the app, and the plugin cannot pull them back (P6). The alternative name is "copy to Profotograaf".
3. One gallery per year, one gallery for everything, or one gallery per month?
4. Should video and RAW files in the Media Library ever go to the platform? The platform accepts them. They are large and eat the Free allowance quickly.
5. On multi-author sites, whose uploads are included? The note includes everything an administrator's scope selects. An alternative is only the connected user's own uploads.
6. Is the extra processing load acceptable (one asset per 3 seconds on a shared worker, for a large library)? A rate cap per account, set by the platform, may be needed.
7. Does the platform strip location metadata from stored originals, and does its data processing agreement cover photos of the photographer's clients that arrive through a backup? Both must be answered before the first release.
8. Should free accounts get the backup at all, given the 1 GiB cap? Free accounts could be limited to scope `marked`. The alternative is to leave the cap to do its job (ADR-025 says storage is where a photographer upgrades).
9. Is requesting `galleries:write` at pairing for everyone acceptable (simpler, wider token), or should it wait for P4 (a re-pair step only for sites that switch the backup on)?
10. Where does the client PWA link belong first, the settings list (photographer sends the link) or the block (visitor finds the gallery)? This note recommends the list first, then the block.
11. Should a future platform change make the client portal itself installable? It is not found today, and it would let the existing block carry the install prompt for all of a client's galleries.
