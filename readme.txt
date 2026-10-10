=== Profotograaf ===
Contributors: profotograaf
Tags: photography, gallery, client gallery, portfolio, contact form
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show your Profotograaf galleries on your own site, give clients a way in, and send form enquiries to your Profotograaf inbox.

== Description ==

Profotograaf connects your WordPress site to your [Profotograaf](https://profotograaf.nl) account, the portfolio, client gallery and enquiry platform for photographers.

* **Connect in one step.** Approve the connection in Profotograaf. Your password never reaches your WordPress site.
* **Gallery block and shortcode.** Pick one of your galleries and place it on any page, in a grid, masonry or slideshow layout.
* **Paste a link.** A pasted gallery link turns into an embedded gallery.
* **Client galleries entry.** A "Find your gallery" block that leads clients to the client portal, on your Profotograaf address or on your own domain.
* **Photos in the editor.** Optional. Use the photos of your embeddable galleries in posts and pages through the block inserter, the Media Library and the media modal. A photo you use is stored on your site, so it stays when you unpublish it on Profotograaf.
* **Lead capture.** Send enquiries from Contact Form 7, WPForms and Gravity Forms to your Profotograaf inbox. The form never waits on Profotograaf.

You need a Profotograaf account. The plugin does nothing until you connect it.

The block editor scripts are built from the source in the `blocks` folder of the public repository: https://github.com/webwiebe/profotograaf-wordpress. Run `npm ci && npm run build` to build them.

= Privacy =

The plugin sets no cookies and does not track your visitors. See "External services" for exactly what is sent and when.

**What the plugin sends when you connect.** Clicking "Connect to Profotograaf" sends the site address, the site title, the site language and the WordPress administrator email address to Profotograaf, so it can prefill your account if you create one. The settings page says this next to the button. See "External services" for the full request.

**What the plugin stores.** The connection to Profotograaf (tokens and a random identifier of this installation) and your settings are stored in the WordPress options table. When you switch on a form, each submission is stored there as well, with the name, email address, phone, date, message and other fields of the form, until it is delivered to Profotograaf. A submission that cannot be delivered is kept for the retention period in the lead settings (7 days by default), so you can export or retry it.

**Suggested policy text.** The plugin adds text for your privacy policy under Settings, Privacy, Policy Guide. It covers the galleries, enquiries and the connection.

**Export and erase.** Tools, Export Personal Data includes the waiting and failed enquiries of an email address, and Tools, Erase Personal Data removes them. The email address is matched without regard to case. Enquiries that were already delivered are held by Profotograaf and covered by its privacy policy.

**Photo library in the editor (only if you turn it on).** The "Use Profotograaf photos in the editor" setting is off by default. When it is on, your server keeps a list of your photos (id, size, title, caption, gallery and image links) in the options table. A photo an editor adds or imports is downloaded (the web size, at most 1600 px on the long side) and stored in your Media Library like any upload, with the photo and gallery identifiers as hidden fields. It stays on your site until you delete it. Deleting the plugin leaves those photos in place. Visitors are not involved.

**Uninstall.** Deleting the plugin removes the stored connection, settings and queued enquiries, unless you chose to keep the data on uninstall in the settings.

== External services ==

This plugin connects to Profotograaf (https://profotograaf.nl), the photography platform you sign in to. The plugin needs it to work. Profotograaf is operated by the plugin author.

Terms of service: https://profotograaf.nl/terms
Privacy policy: https://profotograaf.nl/privacy

= Connecting your site =

When you click "Connect to Profotograaf" on the settings page, the plugin sends a request to `https://profotograaf.nl/api/v1/auth/devices/initiate` with the site title, the site's host name, the plugin version, the WordPress and PHP versions and a random identifier of this installation. The same request sends the site address, the site title, the WordPress administrator email address and the site language (English, Dutch, German or French; other languages are not sent). Profotograaf shows the site title and email address on its connect page and uses them only to prefill the sign-up form if you create a new account there. A value that is not a valid address is left out. You confirm the connection on profotograaf.nl. While you wait, the plugin checks `https://profotograaf.nl/api/v1/auth/devices/token` every few seconds. Afterwards it calls `https://profotograaf.nl/api/v1/auth/devices/refresh` in the background to keep the connection alive, and `https://profotograaf.nl/api/v1/auth/devices/signout` when you disconnect, which removes this site from the devices connected to your account. The connection request names the permissions the plugin asks for: reading your galleries, sending enquiries and switching embedding on for a gallery. These calls carry the access token the plugin stores for your account.

= Plugin status =

While your site is connected, the plugin sends `POST https://profotograaf.nl/api/v1/auth/devices/status` once a day in the background, and shortly after you open the settings page (at most once an hour). The request carries the access token, the plugin version, the WordPress version, the PHP version and the site address. Profotograaf answers with whether it may ask you for a review and with the settings for sending error reports. The plugin keeps the answer in the WordPress options table. When the request fails, the plugin keeps the last answer and tries again later. Opening the settings page never waits for this request.

= Your galleries =

In the block editor and on the settings page, the plugin asks `https://profotograaf.nl/api/v1/embed/galleries` for the list of your galleries (title, link, photo count and a cover picture). Only you, signed in to WordPress with an administrator or editor account, can see this list.

When you open the Photos panel of a gallery block to leave photos out, the plugin asks `https://profotograaf.nl/api/v1/embed/galleries/<gallery id>/photos` for the photos of that gallery (id, size, title, alt text, caption and image links), 200 at a time, up to 500 photos. Only users who can edit posts see this list.

Only if you turn on "Use Profotograaf photos in the editor", the plugin asks `https://profotograaf.nl/api/v1/embed/photos` for the photos of your galleries that allow embedding (id, size, title, alt text, caption, gallery title and image links), 200 at a time, up to 2000 photos. It does this in the background twice a day and when an editor opens the photo library for the first time. The plugin keeps the list in the WordPress options table, so the editor still works when Profotograaf cannot be reached. Nothing is requested while the setting is off, and visitors of your site never trigger the request.

When you pick a gallery in the gallery block that does not allow embedding yet, the plugin sends `PUT https://profotograaf.nl/api/v1/embed/galleries/<gallery id>/embeddable` to switch "Allow embedding on other websites" on for that one gallery. Nothing else about the gallery changes. If your connection was made before this permission existed, Profotograaf refuses the call and the plugin asks you to connect again.

After you connect, and when you click "Check again" on the settings page, the plugin sends `POST https://profotograaf.nl/api/v1/embed/origins` with this site's address (for example `https://www.example.com`) and the access token. Profotograaf adds that one address to "Sites allowed to embed my pages" in your account, so your galleries may be shown on this site. Addresses you added yourself stay in the list. If your connection was made before this permission existed, the plugin only checks whether your public gallery page already allows this site, and the settings page tells you which address to add.

= Showing a gallery on your site =

A page that contains a Profotograaf gallery makes the visitor's browser load the embed script from `https://profotograaf.nl/share/embed/` and the gallery data and photos from `https://profotograaf.nl`. Profotograaf receives the visitor's IP address and browser details in the way any web server does. When the gallery scrolls into view, the script also reports one anonymous view (the gallery, the host name of your site) to `https://profotograaf.nl/share/embed/view`. It does not report a view when the visitor's browser sends Do Not Track or Global Privacy Control.

Once a day, in the background, the plugin asks `https://profotograaf.nl/api/v1/embed/script` which version of the embed script is current, so pages load the script under its versioned address. The request carries no token and no data about your site or its visitors. When it fails, the plugin keeps the version it knew and tries again the next day.

Pasting a gallery link into the editor makes WordPress ask `https://profotograaf.nl/oembed` for the embed code of that gallery.

= Client galleries block =

The "Find your gallery" block is a plain link. It sends nothing to Profotograaf and asks for nothing while a visitor views your page. When a visitor clicks the button, their browser opens your client portal on profotograaf.nl (`https://profotograaf.nl/<your address>/client`), or on your own domain or Profotograaf subdomain when you entered one. The portal is where clients sign in, and the Profotograaf privacy policy covers that visit.

= Sending enquiries =

When you switch on a form in the lead settings, every submission of that form is sent to `https://profotograaf.nl/api/v1/leads`: the name, email address, phone, date, message and the other fields of the form, the address of the page it was sent from, and the name of the form. Nothing is sent for forms you have not switched on.

= Photo library in the editor (only if you opt in) =

These calls happen only when you switch on "Use Profotograaf photos in the editor" in the general settings. While it is off, none of them is made.

When an editor with permission to upload files browses or searches the photo library in the block editor (Media tab), in the media modal or on the Media > Import from Profotograaf screen, the plugin reads the list it stored. It calls `https://profotograaf.nl/api/v1/embed/photos` itself only to build that list: twice a day in the background and when the list does not exist yet. The request comes from your server and carries the access token the plugin stores for your account. It returns the photos of your galleries that allow embedding (id, size, title, caption, gallery and image links). When an editor adds or imports a photo (the block editor goes through a signed link on your own site), or re-imports one, your server downloads the image (the web size, at most 1600 px on the long side) from `https://profotograaf.nl/share/img/` and stores it in your Media Library as a normal attachment, once per photo. That download carries no token. The attachment keeps the photo and gallery identifiers as hidden fields. Imported photos are your site's content: they stay in the Media Library, with those fields, when you delete the plugin. The editor's browser loads the thumbnails of the photos it shows from `https://profotograaf.nl`, so Profotograaf receives that editor's IP address and browser details in the way any web server does. Visitors of your site are not involved.

= Anonymous usage data (only if you opt in) =

Telemetry is off until you switch on "Share anonymous usage data" in the advanced settings or answer the prompt in the admin. Once it is on, the plugin sends data every 24 hours with POST requests to two services operated by the plugin author: one usage event to FunnelBarn at `https://f.profotograaf.nl/api/v1/events` and one event per error to BugBarn at `https://bb.profotograaf.nl/api/v1/events`. A host can replace the addresses with the `profotograaf_telemetry_usage_endpoint` and `profotograaf_telemetry_endpoint` filters. If a service answers 429 or 503, the plugin waits before it sends again. Nothing is sent when you have not opted in, when the request carries Do Not Track or Global Privacy Control, or when the endpoint is empty.

The usage event holds these fields and nothing else: a random identifier of this installation (replaced when you disconnect), the plugin version, the WordPress and PHP version (major and minor only), the site language, the names of the plugin's active modules, the number of failed token refreshes, delivered and failed enquiries in the last 24 hours, and a count per error code. Your site address, visitors, galleries, enquiries and tokens are never sent. The data is kept for 13 months. Turning the setting off or disconnecting deletes what is waiting to be sent. Details: https://github.com/webwiebe/profotograaf-wordpress/blob/main/docs/telemetry.md

BugBarn: https://bb.profotograaf.nl
FunnelBarn: https://f.profotograaf.nl

= Error events =

When you opt in to telemetry, the plugin also records an error event when a token refresh, an enquiry delivery or the connection fails. An event holds the error code, the HTTP status, the plugin file and line, and the plugin, WordPress and PHP versions. Tokens, email addresses, URLs and file paths are removed from it first, and the same error is reported once per day. Events leave your site only with the daily telemetry run.

== Help translate Profotograaf ==

The plugin ships in English, Dutch, German, French, Spanish and Italian. The German, French, Spanish and Italian texts are machine drafts that a native speaker has not reviewed yet, so corrections are welcome. To translate or improve a language, join the project on translate.wordpress.org, or send a pull request with a `.po` file. The steps are in CONTRIBUTING.md in the public repository: https://github.com/webwiebe/profotograaf-wordpress.

== Installation ==

1. Install the plugin from the Plugins screen, or upload the zip.
2. Activate it.
3. Open Settings > Profotograaf and click "Connect to Profotograaf".
4. Confirm the code on the Profotograaf page that opens.

== Frequently Asked Questions ==

= How do I add the "Find your gallery" block? =

Add the block "Find your gallery" to a page. In the block settings, enter your Profotograaf address (for example `studio`), your Profotograaf subdomain (`studio.profotograaf.nl`) or your own domain (`photos.example.com`). The block works on a site that is not connected to your account. Until you enter an address, only editors see a note and visitors see nothing.

= Which address do I enter for a custom domain? =

The domain you connected in Profotograaf, for example `photos.example.com`. The button then opens `https://photos.example.com/client`, so your clients never leave your own domain.

= Do I need a Profotograaf account? =

Yes. Create one at https://profotograaf.nl.

= Does the plugin store my Profotograaf password? =

No. You approve the connection on profotograaf.nl. The plugin stores an access token and a refresh token in the WordPress options table and removes them when you disconnect or delete the plugin.

= How do I remove the connection completely? =

Click Disconnect on the settings page. The plugin asks Profotograaf to end the connection, which removes this site from the devices connected to your account, and deletes its tokens. If Profotograaf cannot be reached, the plugin deletes its tokens anyway; you can then remove the site under Connected apps in your Profotograaf account settings.

= How do I switch on the Profotograaf photo library? =

Connect the site, then open Settings > Profotograaf, General, and tick "Use Profotograaf photos in the editor". It is off by default, and while it is off the plugin requests no photos and shows no photo library anywhere. Only users who can upload files see the library. Importing needs the same permission.

= Where do I find my Profotograaf photos in the editor? =

In four places. In the block editor, Add block > Media has a Profotograaf category: click a photo to insert it as an Image block. Under Media > Import from Profotograaf you can search, filter by gallery, select several photos and import them in one go. The media modal (Add media, the Gallery block, the Image block, the classic editor) has a Profotograaf tab. In the Featured image panel, open the media modal and choose a Profotograaf photo to use it as the featured image. In the Media Library, the Source filter set to Profotograaf lists the photos you imported.

= Which photos are offered? =

The photos of galleries that are available and have "Allow embedding on other websites" switched on in Profotograaf. A gallery that does not allow embedding shows no photos. Photos are imported in the web size, at most 1600 px on the long side. Originals are not available. A photo of a watermarked gallery carries the watermark. Alt text comes from Profotograaf when it has one, and from the title otherwise.

= What does the plugin store on my site? =

A photo you use is downloaded once and stored in the Media Library as a normal attachment, with the hidden fields `_profotograaf_photo_id`, `_profotograaf_gallery_id` and `_profotograaf_version`. Using the same photo again reuses that copy. The plugin also keeps a list of your photos in the options table so the editor works when Profotograaf cannot be reached. The attachment details show the gallery the photo came from, with a link to it on Profotograaf. Deleting the plugin removes the list and leaves the photos in your Media Library.

= Can I import a photo again? =

Yes. Open the photo in the Media Library and click Re-import in the attachment details. The file is replaced with the current web size, the Media Library item and its id stay the same, and the image sizes are rebuilt. If the photo is gone from Profotograaf you get a notice and the copy stays as it is.

= What happens when I remove a photo or gallery on Profotograaf? =

Imported copies stay on your site and keep working, because they are files in your Media Library. The photo disappears from the library lists after the next refresh (twice a day), so it can no longer be imported, and Re-import tells you it is no longer available. The plugin does not remove or change the copies.

= Does deleting a photo in WordPress delete it on Profotograaf? =

No. The plugin never deletes or changes anything on Profotograaf. Deleting an imported photo in the Media Library removes only the copy on your site, and you can import it again.

= What if Profotograaf cannot be reached? =

The library shows the last saved list with a notice, or an empty list with a message. Photos you already imported keep working and the editor keeps working.

= Does it work on a WordPress multisite network? =

Yes. Every site in the network connects to Profotograaf on its own, because each site pairs with its own account and keeps its own settings. Network activation leaves each site disconnected. Network Admin, Settings, Profotograaf lists every site with its connection state and its failed enquiries, and links to each site's settings page.

== Screenshots ==

1. Connect your site to Profotograaf from Settings > Profotograaf.
2. Place a gallery with the gallery block.
3. Send clients to their galleries with the "Find your gallery" block.
4. Forward form enquiries to your Profotograaf inbox.
5. Add a Profotograaf photo from the Media tab of the block inserter.
6. Select several photos and import them under Media > Import from Profotograaf.
7. Choose a Profotograaf photo as the featured image in the media modal.

== Changelog ==

= 0.1.0 =
* New: the plugin tells Profotograaf its version, the WordPress version and the PHP version when you connect, and once a day while connected. Profotograaf answers with the review prompt and error reporting settings, which the plugin stores. A failed call keeps the last answer.
* Fix: the "Load more photos" button in the media modal tab shows only when more photos are left.
* Fix: the suggested privacy policy text now says that usage data and error reports are sent when the site owner opts in.
* New: user documentation for the photo library (README, FAQ, privacy text) and an External services entry that lists the requests it makes.
* New: choose a Profotograaf photo as the featured image from the Featured image panel. The photo is stored in the Media Library once, the post gets a normal attachment as its featured image, and choosing the same photo on another post reuses that copy.
* New: the attachment details of a photo imported from Profotograaf link to its gallery on Profotograaf and offer a Re-import button. Re-import replaces the file with the current web size, keeps the Media Library item and its id, rebuilds the image sizes and deletes the old files. When the photo is no longer on Profotograaf you get a notice and the copy stays as it is. Route: `POST /profotograaf/v1/photos/reimport`.
* New: a Profotograaf tab in the media modal, which feeds the Gallery block, the Image block, the classic editor and the featured image box. It shows only with "Use Profotograaf photos in the editor" on and for users who can upload files. Search, filter by gallery, select several photos where the screen allows it, and choose the usual button: the photos are stored in the Media Library once and the screen receives normal attachments. If Profotograaf cannot be reached the tab shows a message and the other tabs keep working.
* Fix: importing the same Profotograaf photo twice at the same time creates one Media Library item. A photo download that Profotograaf redirects elsewhere is refused.
* New: a Profotograaf category in the block editor's Media tab (Add block > Media), only with "Use Profotograaf photos in the editor" on and for users who can upload files. Adding a photo stores it in the Media Library once, through a short-lived, signed link on your own site, and adding it again reuses that copy. If Profotograaf cannot be reached the list stays empty and the editor keeps working.
* New: Media > Import from Profotograaf, a screen for users who can upload files, shown only with "Use Profotograaf photos in the editor" on. Search your photos, filter by gallery, select several and import them with progress and per-photo errors. The Media Library gets a Source filter (Profotograaf) and imported photos show their gallery in the attachment details.
* New: REST route `POST /profotograaf/v1/photos/import` (up to 50 photos per request, taken from the stored photo list) and a `gallery` filter on `GET /profotograaf/v1/photos`.
* New: REST route `GET /profotograaf/v1/photos` for editors who can upload files, with search and paging over the photo library. It answers only while "Use Profotograaf photos in the editor" is on, and marks the list as stale when Profotograaf cannot be reached.
* New: import a Profotograaf photo into the Media Library (web size, once per photo, for users who can upload files, only with "Use Profotograaf photos in the editor" on). Imported photos stay when the plugin is deleted.
* New: the photo library behind "Use Profotograaf photos in the editor" reads your embeddable photos from Profotograaf into a stored list, refreshed twice a day while the setting is on. The stored list is used when Profotograaf cannot be reached.
* New: "Use Profotograaf photos in the editor" setting, off by default. Later releases use it to offer your Profotograaf photos in the editor. Nothing is requested while it is off.
* First version: connect to Profotograaf, settings page and the API client other features build on.
* New: "Find your gallery" block that links clients to the client portal.
* New: leave photos out of a gallery block (Photos panel) or shortcode (`exclude`). The editor lists up to 500 photos of the gallery, 200 per request.
* Dutch (nl_NL) and English.
* New: starting a connection sends the site address, site title, administrator email address and site language, so Profotograaf can prefill a new account.
* Connect adds this site to the sites allowed to embed your pages, Disconnect removes the site from your account's devices, and the embed script version is read from a public platform route.

== Upgrade Notice ==

= 0.1.0 =
First release.
