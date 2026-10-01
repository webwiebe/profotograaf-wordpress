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
* **Lead capture.** Send enquiries from Contact Form 7, WPForms and Gravity Forms to your Profotograaf inbox. The form never waits on Profotograaf.

You need a Profotograaf account. The plugin does nothing until you connect it.

The block editor scripts are built from the source in the `blocks` folder of the public repository: https://github.com/webwiebe/profotograaf-wordpress. Run `npm ci && npm run build` to build them.

= Privacy =

The plugin sets no cookies and does not track your visitors. See "External services" for exactly what is sent and when.

**What the plugin stores.** The connection to Profotograaf (tokens and a random identifier of this installation) and your settings are stored in the WordPress options table. When you switch on a form, each submission is stored there as well, with the name, email address, phone, date, message and other fields of the form, until it is delivered to Profotograaf. A submission that cannot be delivered is kept for the retention period in the lead settings (7 days by default), so you can export or retry it.

**Suggested policy text.** The plugin adds text for your privacy policy under Settings, Privacy, Policy Guide. It covers the galleries, enquiries and the connection.

**Export and erase.** Tools, Export Personal Data includes the waiting and failed enquiries of an email address, and Tools, Erase Personal Data removes them. The email address is matched without regard to case. Enquiries that were already delivered are held by Profotograaf and covered by its privacy policy.

**Uninstall.** Deleting the plugin removes the stored connection, settings and queued enquiries, unless you chose to keep the data on uninstall in the settings.

== External services ==

This plugin connects to Profotograaf (https://profotograaf.nl), the photography platform you sign in to. The plugin needs it to work. Profotograaf is operated by the plugin author.

Terms of service: https://profotograaf.nl/terms
Privacy policy: https://profotograaf.nl/privacy

= Connecting your site =

When you click "Connect to Profotograaf" on the settings page, the plugin sends a request to `https://profotograaf.nl/api/v1/auth/devices/initiate` with the site title, the site's host name, the plugin version and a random identifier of this installation. You confirm the connection on profotograaf.nl. While you wait, the plugin checks `https://profotograaf.nl/api/v1/auth/devices/token` every few seconds. Afterwards it calls `https://profotograaf.nl/api/v1/auth/devices/refresh` in the background to keep the connection alive, and `https://profotograaf.nl/api/v1/auth/devices/signout` when you disconnect. The connection request names the permissions the plugin asks for: reading your galleries, sending enquiries and switching embedding on for a gallery. These calls carry the access token the plugin stores for your account.

= Your galleries =

In the block editor and on the settings page, the plugin asks `https://profotograaf.nl/api/v1/embed/galleries` for the list of your galleries (title, link, photo count and a cover picture). Only you, signed in to WordPress with an administrator or editor account, can see this list.

When you pick a gallery in the gallery block that does not allow embedding yet, the plugin sends `PUT https://profotograaf.nl/api/v1/embed/galleries/<gallery id>/embeddable` to switch "Allow embedding on other websites" on for that one gallery. Nothing else about the gallery changes. If your connection was made before this permission existed, Profotograaf refuses the call and the plugin asks you to connect again.

= Showing a gallery on your site =

A page that contains a Profotograaf gallery makes the visitor's browser load the embed script from `https://profotograaf.nl/share/embed/` and the gallery data and photos from `https://profotograaf.nl`. Profotograaf receives the visitor's IP address and browser details in the way any web server does. When the gallery scrolls into view, the script also reports one anonymous view (the gallery, the host name of your site) to `https://profotograaf.nl/share/embed/view`. It does not report a view when the visitor's browser sends Do Not Track or Global Privacy Control.

Pasting a gallery link into the editor makes WordPress ask `https://profotograaf.nl/oembed` for the embed code of that gallery.

= Client galleries block =

The "Find your gallery" block is a plain link. It sends nothing to Profotograaf and asks for nothing while a visitor views your page. When a visitor clicks the button, their browser opens your client portal on profotograaf.nl (`https://profotograaf.nl/<your address>/client`), or on your own domain or Profotograaf subdomain when you entered one. The portal is where clients sign in, and the Profotograaf privacy policy covers that visit.

= Sending enquiries =

When you switch on a form in the lead settings, every submission of that form is sent to `https://profotograaf.nl/api/v1/leads`: the name, email address, phone, date, message and the other fields of the form, the address of the page it was sent from, and the name of the form. Nothing is sent for forms you have not switched on.

= Anonymous usage data (only if you opt in) =

Telemetry is off until you switch on "Share anonymous usage data" in the advanced settings or answer the prompt in the admin. Once it is on, the plugin sends one batch every 24 hours with a POST request to `https://bugbarn.wiebe.xyz/api/v1/ingest`, the self-hosted BugBarn error tracker operated by the plugin author. A host can replace the address with the `profotograaf_telemetry_endpoint` filter. Nothing is sent when you have not opted in, when the request carries Do Not Track or Global Privacy Control, or when the endpoint is empty.

The batch holds these fields and nothing else: a random identifier of this installation (replaced when you disconnect), the plugin version, the WordPress and PHP version (major and minor only), the site language, the names of the plugin's active modules, the number of failed token refreshes, delivered and failed enquiries in the last 24 hours, and a count per error code. Your site address, visitors, galleries, enquiries and tokens are never sent. The data is kept for 13 months. Turning the setting off or disconnecting deletes what is waiting to be sent. Details: https://github.com/webwiebe/profotograaf-wordpress/blob/main/docs/telemetry.md

BugBarn: https://github.com/wiebe-xyz/bugbarn

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

Click Disconnect on the settings page. To also remove this site from your account, open Connected apps in your Profotograaf account settings.

== Screenshots ==

1. Connect your site to Profotograaf from Settings > Profotograaf.
2. Place a gallery with the gallery block.
3. Send clients to their galleries with the "Find your gallery" block.
4. Forward form enquiries to your Profotograaf inbox.

== Changelog ==

= 0.1.0 =
* First version: connect to Profotograaf, settings page and the API client other features build on.
* New: "Find your gallery" block that links clients to the client portal.
* Dutch (nl_NL) and English.

== Upgrade Notice ==

= 0.1.0 =
First release.
