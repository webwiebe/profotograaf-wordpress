# Gallery display matrix

Every display option of the gallery block (`blocks/gallery/block.json`) and the
`[profotograaf_gallery]` shortcode, where it takes effect and which
combinations need testing. Use it to decide which layer a display bug belongs
to and which E2E case covers it.

## How a gallery reaches the page

1. **Plugin markup.** `Gallery_Renderer::render()` writes one `div` with
   `data-profotograaf-gallery`, `data-layout` and one `data-*` attribute per
   option that has a value. Inside it sits the fallback link to the gallery on
   Profotograaf and a `noscript` note. The block's own supports (alignment,
   colour, typography, border, spacing, anchor, class) reach the same `div`
   through `get_block_wrapper_attributes()`.
2. **CSS vars.** `Reserved_Space` adds `--pf-ar`, `--pf-ar-t` and `--pf-ar-m`
   to that `div` and a `style` element that turns them into an `aspect-ratio`
   at desktop, at 900px and below, and at 600px and below. This holds space
   until the gallery is drawn, and is released after 8 seconds when nothing
   is drawn.
3. **embed.js.** `https://profotograaf.nl/share/embed/embed.js` (source:
   `web/src/public/embed/` in wiebe-xyz/professionals, vendored for the E2E
   run in `tests/e2e/fixtures/embed.js`) fetches
   `/api/v1/embed/galleries/{id}`, reads the `data-*` attributes and draws the
   gallery in an open shadow root. Its CSS lives inside that root, so neither
   the theme nor block support CSS reaches the photos.

An option resolves block attribute first, then shortcode attribute, then the
site default on the Galleries settings tab. An empty value means "not set
here" and falls through. When nothing sets it, no `data-*` attribute is written
and embed.js uses its own default.

## Platform layouts

A gallery on Profotograaf can use any of 13 layouts, and embed.js draws three.
The table below (`includes/layout-map.json`, read by `Layout_Map` in PHP and
imported by the block editor from `blocks/gallery/layout-map.ts`) says which
one the site draws. It follows wiebe-xyz/professionals#2359.

| Platform layout | Drawn as |
|---|---|
| masonry, justified, mosaic, lighttable | masonry |
| parallax, direct, cinema, filmstrip, slideout | slideshow |
| flickr, instagram, duo, grid | grid |
| anything else (a layout added later) | grid |

When the block and the site setting leave the layout empty, the render writes
the drawn layout in `data-layout`. It reads the platform layout from the cached
gallery index (`Gallery_Index::drawn_layout()`), which the picker and the
background lookup fill from the gallery list, so a page view makes no platform
call. A gallery that is not in the index yet, or was stored before layouts were
kept, renders as a grid and schedules one lookup. A layout set in the block or
in the Galleries settings tab always wins.

The Layout control shows the same mapping as a hint while it is on the site
default: "Parallax on Profotograaf, shown as Slideshow on your site". An
unknown layout says the plugin does not know it and that it is shown as a grid.

When the platform reports the layout it draws (`embed_layout` on the gallery
list rows, wiebe-xyz/professionals#2359), the index stores it and it wins over
the table, in the render and in the hint. Nothing breaks while the platform
does not send it.

## Options

| Option (block / shortcode) | Values | Default when unset | Plugin markup | CSS var (Reserved_Space) | embed.js |
|---|---|---|---|---|---|
| `layout` / `layout` | `grid`, `masonry`, `slideshow`; empty | site setting `default_layout`, itself empty by default (Profotograaf default): the gallery's platform layout mapped by `Layout_Map`, see "Platform layouts" | `data-layout` always written | picks the column defaults and tile shape | `pickLayout()`: attribute, else the gallery's platform layout, else `grid`. Platform layouts such as `parallax` or `instagram` fall back to `grid`, which is why the plugin sends the mapped layout |
| `columns` / `columns` | 1 to 8 (embed accepts 1 to 12) | grid: `auto-fill` of 140px minimum tiles; masonry: 220px wide columns | `data-columns` | `--pf-ar` (columns x rows) | `--cols`; `grid-template-columns` or `column-count` |
| `columnsTablet` / `columns_tablet` | 1 to 8 | with columns set (block, shortcode or site): min(columns, 3), sent as `data-columns-tablet`. Without columns: embed.js default | `data-columns-tablet` | `--pf-ar-t`, the value sent | `--cols-tablet` at 900px and below |
| `columnsMobile` / `columns_mobile` | 1 to 4 | with columns set: min(tablet, 2) for a grid, 1 for masonry, sent as `data-columns-mobile`. Without columns: embed.js default | `data-columns-mobile` | `--pf-ar-m`, the value sent | `--cols-mobile` at 600px and below |
| `gap` / `gap` | 0 to 96 px | 8px | `data-gap` | ignored | `--gap`; a gap of 0 also sets `--radius:0` |
| `ratio` / `ratio` | `original`, `1-1`, `4-3`, `3-2`, `16-9`, `3-4`, `2-3` | grid: square tiles; masonry: each photo's own shape; slideshow: 3:2 stage | `data-ratio`, `-` written as `:` | tile shape of the estimate; masonry always estimates 4:3 | `--ratio`, crops with `object-fit: cover`. Masonry gets no `data-ratio` at all (block, shortcode and the site-wide Photo shape are ignored for it), so embed.js sets no `--ratio` and keeps each photo's shape. `original` does not parse, so a grid stays square (professionals#2339). The block editor hides Original for the grid layout; a stored grid + original block keeps rendering, square |
| `captions` / `captions` | `off`, `below`, `overlay` | `off` | `data-captions` | none | `figcaption.below` or `span.cap`; text is title and caption joined |
| `sort` / `sort` | `newest`, `oldest`, `name`, `random` | platform order (oldest first) | `data-sort` | none | mapped to `reverse`, `default`, `title`, `random` |
| `perPage` / `per_page` | 1 to 200 (embed 1 to 500) | all photos | `data-per-page` | rows of the estimate | first page; with `loadMore` off the rest is dropped |
| `loadMore` / `load_more` | `on`, `off` | `on` | `data-load-more` | none | "Show more" button under grid and masonry; ignored by the slideshow |
| `lightbox` / `lightbox` | `on`, `off` | `on` | `data-lightbox` | none | tile is a `button` that opens the lightbox in the shadow root |
| `excludedPhotoIds` / `exclude` | photo ids, up to 500 | none | `data-exclude`, comma separated | none | photos filtered out before sort and paging |
| `linkTo` / `link_to` | `none`, `page`, `site`, `file` | no link (inert tile) | `data-link-to` | none | used only with the lightbox off; tile becomes an `a` |
| `linkNewTab` / `link_new_tab` | `on`, `off` | `on` | `data-link-new-tab` | none | `target="_blank"` on the tile link |
| `imageText` / `image_text` | list of `{id, caption, alt}` | none | `data-image-text` JSON | none | not read by the embed.js release of the fixture commit |
| site duotone / `duotone` | two hex colours | none | `data-duotone` | none | SVG filter `#pf-duo` on every tile image |
| block duotone (`style.color.duotone`) | preset or two colours | none | `data-duotone`: the plugin resolves a preset through `wp_get_global_settings( array( 'color', 'duotone' ) )`; WordPress still writes its own filter rule for `.wp-block-profotograaf-gallery img`, which finds no photo in the light DOM | none | SVG filter `#pf-duo` on every tile image. A preset that is missing or not two hex colours leaves the site duotone in place; `unset` turns duotone off |
| `align` | `wide`, `full` | `wide` for a new block (`block.json` default; a saved block without the attribute renders wide too). Shortcode: content width of the theme | `alignwide` / `alignfull` class | box width only | draws into whatever width the host has |
| color, typography, border, spacing | block supports | theme | inline style on the host `div` | none | `:host{all:initial}` resets inherited text styles; only the host box (background, border, padding, margin) shows |
| `data-radius` | 0 to 64 px | 4px | never written by the plugin | none | `--radius` on tiles |

## Defaults for a new block

- **Alignment.** `block.json` sets `align` to `wide`, so the editor adds
  `alignwide` and the server render writes the same class on the host `div`.
  A theme without wide support (no `align-wide`, or a block theme with no
  `wideSize`) gives `alignwide` no rule, and the gallery keeps the content
  width.
- **Columns.** `Gallery_Renderer` writes `data-columns-tablet` and
  `data-columns-mobile` whenever it writes `data-columns` and the block,
  shortcode and site settings leave them empty. `Reserved_Space::step_down()`
  holds the numbers, and `style()` estimates with them. The editor shows the
  same values as placeholders in the tablet and phone controls.

## States

embed.js (platform change professionals#2338) sets `data-pf-state` on the host
when it finishes: `drawn`, `empty` or `error`. It also dispatches a bubbling
`profotograaf:state` event with `detail: { state }`. `data-pf-ready` is
unchanged and still marks the start. The stylesheet from `Reserved_Space::rule()`
releases the box at once for `empty` and `error` and keeps it for `drawn`. The
footer watcher (`Empty_Gallery::script()`) marks the host from the state: `empty`
sets `data-pf-empty`, `error` sets `data-profotograaf-failed`. An embed.js that
sets no state keeps the 8 second release timer, and the watcher keeps its
request based detection for it.

| State | Plugin markup | CSS var | embed.js |
|---|---|---|---|
| Drawn | host with data attributes | ratio box until content sets the height | shadow root with tiles, `data-pf-state="drawn"` |
| Platform error (non-200) or script error | fallback link stays, failed look at once | released at once on `data-pf-state="error"` | sets `data-pf-ready` and `data-pf-state="error"`, draws nothing |
| Older embed.js: platform error | fallback link stays | box held 8 seconds, then released | sets `data-pf-ready` only, draws nothing |
| Script does not load | fallback link in the failed look | box held 8 seconds, then released | none |
| Gallery with no photos | fallback link hidden, editor hint | released at once on `data-pf-state="empty"` | `data-pf-state="empty"`, draws nothing |
| All photos excluded | as above | as above | `data-pf-state="empty"`, draws nothing |
| PNG photos (professionals#2330, fixed) | as drawn | as drawn | served through JPEG variants and drawn like any photo |
| No JavaScript | fallback link and `noscript` note | released at once by the `noscript` rule | none |
| Gallery deleted on the platform | notice for editors, nothing extra for visitors | as above | draws nothing |

## Combinations that matter

Testing every product of the options is neither possible nor useful. These
are the pairs and triples where one option changes what another does.

| Combination | Why it matters | Covered by |
|---|---|---|
| layout x lightbox | grid and masonry tiles open the lightbox; the slideshow stage opens it too; with the lightbox off the slideshow tile is inert | `gallery-display.spec.js` (grid, slideshow), `sample-pages.spec.js` |
| lightbox x linkTo x linkNewTab | `linkTo` only applies with the lightbox off | `sample-pages.spec.js` (`combo-link-page`), `embed-options.spec.js` |
| layout x platform layout | a block on the platform default draws a parallax gallery as a slideshow; a block that picks the grid keeps the grid | `gallery-display.spec.js` (mock gallery `g-e2e-parallax`), `Gallery_Layout_Test.php`, `layout-map.test.ts` |
| layout x perPage x loadMore | slideshow ignores both; grid and masonry show one page with or without the button | `gallery-display.spec.js`, `embed-layout.spec.js`, `sample-pages.spec.js` (`combo-load-more`, `combo-first-page`) |
| loadMore x Reserved_Space | the box must grow with Show more and never clip | `embed-layout.spec.js`, `embed-cls.spec.js` |
| layout x ratio | grid crops to the ratio (square without one), masonry ignores the ratio and keeps each photo's shape (the plugin sends none; the editor hides the control and says so), slideshow letterboxes inside 3:2 | `embed-options.spec.js` (masonry with a block ratio and with a site-wide 1:1), `sample-pages.spec.js` (`grid-landscape`, `grid-portrait`, `slideshow-portrait`) |
| ratio `original` x grid | embed.js draws a grid square whatever the ratio, so the editor does not offer Original for a grid. A block that already holds it keeps rendering | `sample-pages.spec.js` (`grid-landscape`) |
| columns x breakpoints | columns alone step down: tablet min(columns, 3), phone min(columns, 2) for a grid and 1 for masonry; an explicit tablet or phone value wins | `gallery-display.spec.js` (5 columns at 1440, 800 and 390px), `embed-options.spec.js`, `sample-pages.spec.js` (`combo-wide` at 390px) |
| columns x photo count | a gallery with fewer photos than columns leaves empty cells | `sample-pages.spec.js` (`grid-few`, `masonry-few`) |
| align x layout | wide and full change the width the columns divide; full width has no side gutter | `sample-pages.spec.js` (`combo-wide`, `combo-full`) |
| duotone (site) x duotone (block) | the block value replaces the site value in `data-duotone`; without a block value the site value stays | `Gallery_Renderer_Test.php`, `gallery-display.spec.js`, `sample-pages.spec.js` (`combo-duotone`) |
| gap 0 x radius | a gap of 0 squares the corners | `embed-options.spec.js`, `sample-pages.spec.js` (`combo-flush`) |
| captions x ratio | overlay captions sit on cropped tiles; captions below add height masonry must balance | `sample-pages.spec.js` (`combo-captions`) |
| empty or failed x layout | the reserved box shows as a blank area until it is released | `embed-empty.spec.js`, `gallery-display.spec.js` (failed platform call), `sample-pages.spec.js` (`slideshow-empty`, `combo-empty`) |

## Sample pages

`tests/e2e/sample-pages.js` creates the pages "Sample: Grid", "Sample:
Masonry", "Sample: Slideshow" and "Sample: Combinations" on a site with
wp-cli. Each block has an anchor that `sample-pages.spec.js` looks up. The
galleries come from the platform by title ("Sample – Landscape", "Sample –
Portrait", "Sample – Mixed", "Sample – Large (40+)", "Sample – Few (1 to 3)",
"Sample – Empty", "Sample – PNG") or from `--<role>=<gallery id>`.

```
node tests/e2e/sample-pages.js --compose=../profotograaf-wordpress-cms/docker-compose.yml
E2E_BASE_URL=https://localhost:8443 E2E_SCREENSHOT_DIR=docs/review \
  pnpm exec playwright test sample-pages --workers=2
```

The pages show the site defaults wherever a block leaves an option empty, so
the Galleries settings tab changes what they look like. The review screenshots
in `docs/review/` were taken with every gallery display setting empty.
