=== Profotograaf for WordPress ===
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

Profotograaf for WordPress connects your WordPress site to your [Profotograaf](https://profotograaf.nl) account, the portfolio, client gallery and enquiry platform for photographers.

* **Connect in one step.** Approve the connection in Profotograaf. Your password never reaches your WordPress site.
* **Gallery block and shortcode.** Pick one of your galleries and place it on any page, in a grid, masonry or slideshow layout.
* **Paste a link.** A pasted gallery link turns into an embedded gallery.
* **Client galleries entry.** A "Find your gallery" block that leads clients to their gallery.
* **Lead capture.** Send enquiries from Contact Form 7, WPForms and Gravity Forms to your Profotograaf inbox. The form never waits on Profotograaf.

You need a Profotograaf account. The plugin does nothing until you connect it.

= Privacy =

The plugin sets no cookies and does not track your visitors. See "External services" for exactly what is sent and when.

== External services ==

This plugin connects to Profotograaf (https://profotograaf.nl), the photography platform you sign in to. The plugin needs it to work. Profotograaf is operated by the plugin author.

Terms of service: https://profotograaf.nl/terms
Privacy policy: https://profotograaf.nl/privacy

= Connecting your site =

When you click "Connect to Profotograaf" on the settings page, the plugin sends a request to `https://profotograaf.nl/api/v1/auth/devices/initiate` with the site title, the site's host name, the plugin version and a random identifier of this installation. You confirm the connection on profotograaf.nl. While you wait, the plugin checks `https://profotograaf.nl/api/v1/auth/devices/token` every few seconds. Afterwards it calls `https://profotograaf.nl/api/v1/auth/devices/refresh` in the background to keep the connection alive, and `https://profotograaf.nl/api/v1/auth/devices/signout` when you disconnect. These calls carry the access token the plugin stores for your account.

= Your galleries =

In the block editor and on the settings page, the plugin asks `https://profotograaf.nl/api/v1/embed/galleries` for the list of your galleries (title, link, photo count and a cover picture). Only you, signed in to WordPress with an administrator or editor account, can see this list.

= Showing a gallery on your site =

A page that contains a Profotograaf gallery makes the visitor's browser load the embed script from `https://profotograaf.nl/share/embed/` and the gallery data and photos from `https://profotograaf.nl`. Profotograaf receives the visitor's IP address and browser details in the way any web server does. When the gallery scrolls into view, the script also reports one anonymous view (the gallery, the host name of your site) to `https://profotograaf.nl/share/embed/view`. It does not report a view when the visitor's browser sends Do Not Track or Global Privacy Control.

Pasting a gallery link into the editor makes WordPress ask `https://profotograaf.nl/oembed` for the embed code of that gallery.

= Sending enquiries =

When you switch on a form in the lead settings, every submission of that form is sent to `https://profotograaf.nl/api/v1/leads`: the name, email address, phone, date, message and the other fields of the form, the address of the page it was sent from, and the name of the form. Nothing is sent for forms you have not switched on.

== Installation ==

1. Install the plugin from the Plugins screen, or upload the zip.
2. Activate it.
3. Open Settings > Profotograaf and click "Connect to Profotograaf".
4. Confirm the code on the Profotograaf page that opens.

== Frequently Asked Questions ==

= Do I need a Profotograaf account? =

Yes. Create one at https://profotograaf.nl.

= Does the plugin store my Profotograaf password? =

No. You approve the connection on profotograaf.nl. The plugin stores an access token and a refresh token in the WordPress options table and removes them when you disconnect or delete the plugin.

= How do I remove the connection completely? =

Click Disconnect on the settings page. To also remove this site from your account, open Connected apps in your Profotograaf account settings.

== Changelog ==

= 0.1.0 =
* First version: connect to Profotograaf, settings page and the API client other features build on.
