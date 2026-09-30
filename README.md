# Profotograaf for WordPress

Show your [Profotograaf](https://profotograaf.nl) galleries on your own WordPress site, give clients a way in, and send form enquiries to your Profotograaf inbox.

The plugin is GPL-2.0-or-later and is published in the WordPress plugin directory. This repository holds its code. The platform side lives elsewhere.

- Install from the plugin directory, or download the zip from the [releases](https://github.com/webwiebe/profotograaf-wordpress/releases).
- Open Settings > Profotograaf and click "Connect to Profotograaf".
- Requirements: WordPress 6.9 or later (current and previous two major versions), PHP 8.1 or later.

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for how the code is organised and how to run the checks.

## What it does today

- Connects a site to a Profotograaf account with the device pairing flow. You approve the connection on profotograaf.nl, so your password never reaches WordPress.
- Keeps the connection alive in the background and disconnects on request.
- Provides an authenticated API client that features build on: gallery list, lead posting.

Blocks, the shortcode, oEmbed and the form bridges are added as modules.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
