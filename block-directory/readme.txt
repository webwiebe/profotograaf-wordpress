=== Profotograaf Gallery Block ===
Contributors: profotograaf
Tags: gallery, client gallery, photographer, portfolio, proofing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a Profotograaf client gallery on your site. Paste the gallery link and choose a grid, masonry or slideshow layout.

== Description ==

One block, no settings. Add the Client gallery block, paste the link of a gallery from your [Profotograaf](https://profotograaf.nl) account and pick a layout. The gallery must have "Allow embedding on other websites" switched on in Profotograaf.

The gallery script ships inside this plugin. Visitors see a link to the gallery until the script has drawn the photos, and when JavaScript is off.

The full Profotograaf plugin adds a gallery picker, a full-page gallery block, page builder support and lead capture.

== External services ==

The gallery is loaded from the Profotograaf service (https://profotograaf.nl, [terms](https://profotograaf.nl/terms), [privacy policy](https://profotograaf.nl/privacy)).

* When a page with the block is saved or viewed, your server asks https://profotograaf.nl/oembed (or the host of the pasted gallery link) which gallery the link points to. It sends the pasted link. The answer is kept for 12 hours.
* When a visitor views the page, the visitor's browser requests the gallery photos from https://profotograaf.nl/api/v1/embed/galleries/{id} and the photo files from the Profotograaf image host. The browser sends no cookies. The embed script counts a view and honours Do Not Track and Global Privacy Control.

== Installation ==

1. Add the Client gallery block to a post or page.
2. Paste the gallery link.

== Changelog ==

= 0.1.0 =
* First release.
