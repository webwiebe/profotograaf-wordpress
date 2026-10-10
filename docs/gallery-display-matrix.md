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

## Options

| Option (block / shortcode) | Values | Default when unset | Plugin markup | CSS var (Reserved_Space) | embed.js |
|---|---|---|---|---|---|
| `layout` / `layout` | `grid`, `masonry`, `slideshow`; empty | site setting `default_layout` (`grid`) | `data-layout` always written | picks the column defaults and tile shape | `pickLayout()`: attribute, else the gallery's platform layout, else `grid`. Platform layouts such as `parallax` or `instagram` fall back to `grid` |
| `columns` / `columns` | 1 to 8 (embed accepts 1 to 12) | grid: `auto-fill` of 140px minimum tiles; masonry: 220px wide columns | `data-columns` | `--pf-ar` (columns x rows) | `--cols`; `grid-template-columns` or `column-count` |
| `columnsTablet` / `columns_tablet` | 1 to 8 | the desktop value (no automatic step down) | `data-columns-tablet` | `--pf-ar-t`, estimate min(desktop, 3 grid / 2 masonry) | `--cols-tablet` at 900px and below |
| `columnsMobile` / `columns_mobile` | 1 to 4 | the tablet or desktop value | `data-columns-mobile` | `--pf-ar-m`, estimate min(tablet, 2 grid / 1 masonry) | `--cols-mobile` at 600px and below |
| `gap` / `gap` | 0 to 96 px | 8px | `data-gap` | ignored | `--gap`; a gap of 0 also sets `--radius:0` |
| `ratio` / `ratio` | `original`, `1-1`, `4-3`, `3-2`, `16-9`, `3-4`, `2-3` | grid: square tiles; masonry: each photo's own shape; slideshow: 3:2 stage | `data-ratio`, `-` written as `:` | tile shape of the estimate | `--ratio`, crops with `object-fit: cover`. `original` does not parse, so a grid stays square |
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
| block duotone (`style.color.duotone`) | preset or two colours | none | WordPress adds a filter rule for `.wp-block-profotograaf-gallery img`, and the plugin drops `data-duotone` | none | none: the photos are in the shadow root, so the WordPress filter cannot reach them |
| `align` | `wide`, `full` | content width of the theme | `alignwide` / `alignfull` class | box width only | draws into whatever width the host has |
| color, typography, border, spacing | block supports | theme | inline style on the host `div` | none | `:host{all:initial}` resets inherited text styles; only the host box (background, border, padding, margin) shows |
| `data-radius` | 0 to 64 px | 4px | never written by the plugin | none | `--radius` on tiles |

## States

| State | Plugin markup | CSS var | embed.js |
|---|---|---|---|
| Drawn | host with data attributes | ratio box until content sets the height | shadow root with tiles |
| Platform error (non-200) or script error | fallback link stays | box held 8 seconds, then released | sets `data-pf-ready`, draws nothing |
| Gallery with no photos | fallback link stays | box held 8 seconds, then released | draws nothing |
| All photos excluded | fallback link stays | as above | draws nothing |
| PNG photos (professionals#2330) | as drawn | as drawn | the API leaves the photos out, so the gallery counts as empty |
| No JavaScript | fallback link and `noscript` note | released at once by the `noscript` rule | none |
| Gallery deleted on the platform | notice for editors, nothing extra for visitors | as above | draws nothing |

## Combinations that matter

Testing every product of the options is neither possible nor useful. These
are the pairs and triples where one option changes what another does.

| Combination | Why it matters | Covered by |
|---|---|---|
| layout x lightbox | grid and masonry tiles open the lightbox; the slideshow stage opens it too; with the lightbox off the slideshow tile is inert | `gallery-display.spec.js` (grid, slideshow), `sample-pages.spec.js` |
| lightbox x linkTo x linkNewTab | `linkTo` only applies with the lightbox off | `sample-pages.spec.js` (`combo-link-page`), `embed-options.spec.js` |
| layout x perPage x loadMore | slideshow ignores both; grid and masonry show one page with or without the button | `gallery-display.spec.js`, `embed-layout.spec.js`, `sample-pages.spec.js` (`combo-load-more`, `combo-first-page`) |
| loadMore x Reserved_Space | the box must grow with Show more and never clip | `embed-layout.spec.js`, `embed-cls.spec.js` |
| layout x ratio | grid crops to the ratio (square without one), masonry keeps each photo's shape unless a ratio is set, slideshow letterboxes inside 3:2 | `sample-pages.spec.js` (`grid-landscape`, `grid-portrait`, `slideshow-portrait`) |
| ratio `original` x grid | `original` is offered for every layout but a grid stays square | `sample-pages.spec.js` (`grid-landscape`) |
| columns x breakpoints | tablet and phone values do not step down by themselves | `embed-options.spec.js`, `sample-pages.spec.js` (`combo-wide` at 390px) |
| columns x photo count | a gallery with fewer photos than columns leaves empty cells | `sample-pages.spec.js` (`grid-few`, `masonry-few`) |
| align x layout | wide and full change the width the columns divide; full width has no side gutter | `sample-pages.spec.js` (`combo-wide`, `combo-full`) |
| duotone (site) x duotone (block) | the block value turns the site value off and draws nothing itself | `sample-pages.spec.js` (`combo-duotone`) |
| gap 0 x radius | a gap of 0 squares the corners | `embed-options.spec.js`, `sample-pages.spec.js` (`combo-flush`) |
| captions x ratio | overlay captions sit on cropped tiles; captions below add height masonry must balance | `sample-pages.spec.js` (`combo-captions`) |
| empty or failed x layout | the reserved box shows as a blank area until it is released | `gallery-display.spec.js`, `sample-pages.spec.js` (`slideshow-empty`, `combo-empty`) |

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
