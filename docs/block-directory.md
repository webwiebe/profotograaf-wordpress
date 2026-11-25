# Block Directory plugin

`block-directory/` is a second build target in this repo: the single-block plugin
`profotograaf-gallery-block` for the WordPress Block Directory (professionals#1707, epic
#1704). It is a separate plugin from `profotograaf`, because the Block Directory takes one
top-level block, no admin pages, no settings and no upsell notices.

| Path | What it is |
|---|---|
| `profotograaf-gallery-block.php`, `includes/` | Registers the block and renders it on the server. No admin code. |
| `src/gallery/` | The block: `block.json` (title, keywords for the inserter search), the editor script, the editor style. |
| `webpack.config.js` | The wp-scripts default. The repo root config adds the main plugin's entries, so this plugin has its own. |
| `readme.txt` | The directory readme, with the External services section. |

The block takes a gallery share link or an embed id. A share link is turned into the embed id
through the platform's `/oembed` endpoint, and the answer is cached for twelve hours. The
gallery script is the one of `@profotograaf/embed`, copied to `build/embed.js` and served from
the plugin folder. The script draws the badge when the plan needs one. An empty block shows a
placeholder with a sign-up link (`utm_source=wordpress-block-directory`). The sidebar names the
full plugin in one line.

## Build and check

```
bin/build-block-directory.sh
```

Builds the block, packs `dist/profotograaf-gallery-block.zip` with `wp-scripts plugin-zip` and runs
`scripts/check-block-directory-zip.mjs` (one block.json, no admin calls, matching versions, the
files a reviewer expects). CI also runs `tests/e2e/block-directory-check.sh` on the e2e stack:
Plugin Check must report no errors and a post with the block must render the gallery host and
the plugin's own script.

## Open

- Submission to the directory waits on the wordpress.org account (wp#332).
- Install tracking as its own source (#1705) needs the platform side. The plugin sends no tracking of its own.
- The sidebar links to the full plugin at https://wordpress.org/plugins/profotograaf/, which works once that plugin is listed.
