# Profotograaf

Show your [Profotograaf](https://profotograaf.nl) galleries on your own WordPress site, give clients a way in, and send form enquiries to your Profotograaf inbox.

The plugin is GPL-2.0-or-later and is published in the WordPress plugin directory. This repository holds its code. The platform side lives elsewhere.

- Install from the plugin directory, or download the zip from the [releases](https://github.com/webwiebe/profotograaf-wordpress/releases).
- Open Settings > Profotograaf and click "Connect to Profotograaf".
- Requirements: WordPress 6.9 or later (current and previous two major versions), PHP 8.1 or later.

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for how the code is organised and how to run the checks.

## What it does today

- Connects a site to a Profotograaf account with the device pairing flow. You approve the connection on profotograaf.nl, so your password never reaches WordPress.
- When you start a connection, sends the site address, site title, administrator email address and site language (en, nl, de or fr) to Profotograaf, which uses them to prefill the sign-up form if you create an account. The settings page says so next to the Connect button, and `readme.txt` (External services) lists the full request.
- Keeps the connection alive in the background. Disconnect revokes the site on the platform and deletes the local tokens.
- Adds the site's origin to the photographer's allowed embed origins on connect.
- Provides an authenticated API client that features build on: gallery list, lead posting.
- Has an opt-in `media_source` setting (General tab, off by default). Features that offer Profotograaf photos in the editor check `Settings::media_source_enabled()` first. While it is off the plugin makes no photo list, gallery or image download requests for it. When it is on, the server calls `/api/v1/embed/photos` and `/share/img/` on profotograaf.nl, editors' browsers load thumbnails from profotograaf.nl, and imported photos are stored in the Media Library. See [docs/media-source.md](docs/media-source.md) and the External services section of `readme.txt`.

Blocks, the shortcode, oEmbed and the form bridges are added as modules.

## Profotograaf photos in the editor

Optional. Settings > Profotograaf > General > "Use Profotograaf photos in the editor" is off by default. While it is off the plugin makes no photo requests and shows no photo library. It needs a connected site, and only users who can upload files see or use it.

Where the photos show up:

- **Block inserter.** Add block > Media > Profotograaf. One click inserts the photo as an Image block.
- **Media > Import from Profotograaf.** Search, filter by gallery, select several photos (up to 50 per request) and import them with progress and per-photo errors. The Media Library gets a Source filter (Profotograaf).
- **Media modal.** A Profotograaf tab in Add media, which feeds the Gallery block, the Image block and the classic editor.
- **Featured image.** Choose a Profotograaf photo in the media modal from the Featured image panel.
- **Attachment details.** An imported photo shows its gallery, with a link to it on Profotograaf, and a Re-import button that replaces the file with the current web size and keeps the Media Library item and its id.

What to know:

- Only photos of galleries that are available and have "Allow embedding on other websites" switched on are offered.
- Photos are imported in the `web` variant, at most 1600 px on the long side. Originals are not available. A watermarked gallery stays watermarked.
- Importing is once per photo. Using the same photo again reuses the copy.
- The site stores a normal attachment per photo, with the hidden fields `_profotograaf_photo_id`, `_profotograaf_gallery_id` and `_profotograaf_version`, and a list of your photos in the options table (refreshed twice a day, used when the platform cannot be reached).
- A photo that is removed or unpublished on Profotograaf drops out of the list at the next refresh. Copies already in the Media Library stay and keep working. Re-import then reports that the photo is no longer available.
- Deleting a photo in WordPress deletes nothing on Profotograaf.
- Deleting the plugin removes the list and the setting and leaves the imported photos.
- Browsing reads the stored list. The server calls `/api/v1/embed/photos` to build it, and downloads from `/share/img/` when a photo is imported. See the External services section of `readme.txt` for every request.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
