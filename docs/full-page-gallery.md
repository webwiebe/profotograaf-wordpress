# Full-page gallery

The full-page gallery block (`profotograaf/full-page-gallery`, #194) shows one
Profotograaf gallery as a sequence of sections with different shapes. The
plugin renders it on the server from the public embed payload, so all sections
share one lightbox, the photos are in the HTML with their size, and the page
shows them without JavaScript.

## Using the block

1. Add the "Full-page gallery" block and choose a gallery.
2. Choose a preset: Calm, Editorial or Playful. Each repeats its sequence of
   sections until every photo is shown. Custom lets you set the sections
   yourself. When the gallery has its own layout on Profotograaf, "Use the
   gallery's own layout" appears as well and uses that preset and its picks.
   Without that layout the choice is not offered, and a block that stored it
   uses the default preset.
3. Click a spot in a hero, feature or pair section to pick the photo for it.
   Clear the spot to let the sequence fill it again.
4. In the page settings, under Template, choose "Profotograaf full page" to
   hide the site header and footer. Block themes get a block template and
   classic themes a page template.

Transform in the block toolbar turns a gallery block into a full-page gallery
and back, keeping the gallery. The sample page `sample-full-page`
(tests/e2e/sample-pages.js) shows every preset on 50 photos, and
docs/review/full-page holds its screenshots at 1280 and 390 px.

## Shared vocabulary

`includes/sections.json` holds the section types, the default and the range of
every field, the phone values and the presets. PHP reads it through
`Sections_Schema`, and the editor imports it through
`blocks/full-page-gallery/sections.ts`. The platform share page implements the
same table (wiebe-xyz/professionals#2385).

| Type | Photos | Fields (default) |
|---|---|---|
| `grid` | columns × rows | columns 3, rows 2, ratio `3-2`, gap 8, captions `off` |
| `masonry` | count | columns 3, count 9, gap 8, captions `off` |
| `feature` | count (2 or 3) | count 2, gap 8, captions `off` |
| `hero` | 1 | height 85 (vh), captions `off`, title empty |
| `pair` | 3 | side `left`, mirror on, gap 8, captions `off` |
| `filmstrip` | count | count 8, rowHeight 360 (px), gap 8, captions `off` |
| `text` | 0 | heading, text, align `center` |

Presets repeat their pattern until the photos run out:

| Preset | Pattern | Photos per cycle |
|---|---|---|
| `calm` | grid 3×2, feature 2 | 8 |
| `editorial` (default) | hero, grid 3×2, pair, grid 3×2, feature 3 | 19 |
| `playful` | grid 4×2, pair, filmstrip 8, pair, hero, masonry 3 columns 9 | 32 |
| `custom` | the photographer's own list | any |

Every photo type except `text` also has the spacing and shape fields of the
gallery block (#198), with the same choices:

| Field | Values | Default | Types |
|---|---|---|---|
| `gapRow`, `gapColumn` | 0 to 96 pixels, empty uses `gap` | empty | grid, masonry, feature, pair |
| `radius` | `none`, `subtle` (4px), `rounded` (12px), `full` (circle) | `none` | all; `full` in a grid only, drawn for the 1:1 shape only |
| `hover` | `zoom` (inside the frame), `grow`, `none` | `zoom` | all; hero and filmstrip have no `grow` |
| `hoverStrength` | `subtle`, `strong` | `subtle` | all |
| `frameWidth` | 0 to 24 pixels, 0 is no frame | 0 | all |
| `frameColor` | a 3 or 6 digit hex colour | `#ffffff` | all |
| `shadow` | `none`, `soft`, `strong` | `none` | all but filmstrip |

The editor offers the gap presets of the gallery block (none 0, small 4,
medium 12, large 24) or a custom number for `gap`, `gapRow` and `gapColumn`.
The `gap` range is 0 to 96, as in the gallery block.

A block stores an empty value for a field the photographer left alone.
`Sections_Schema::normalise()` resolves every field through
`Option_Resolver`: the section value, then the plugin setting
`sections_<type>_<field>` (the field in snake case, such as
`sections_grid_gap_row`) when the settings schema has one, then the table.
No section has a plugin setting yet, so today the table default applies.

## Sequencer rules

1. Gallery order, with excluded photos removed first.
2. A picked photo leaves the flow before the sections are filled, so it shows
   once. An unpicked slot takes the next photo in flow order.
3. When fewer photos are left than the next section takes, the remainder
   becomes a grid with `columns = min(remaining, columns)` (the columns of the
   section it replaces, or 3) and as many full rows as fit, then one shorter
   row of the photos left, so no row has an empty cell. One leftover photo
   becomes a hero. The rest keeps the gap, ratio, captions and spacing and
   shape of the section it replaces, where its type has the field.
4. A `text` section takes no photos and never ends the gallery.
5. No photos: the fallback link, as in the gallery block.

A pick the gallery never reaches (its slot is in a later cycle than the
photos fill) still shows: it joins the rest in gallery order. A pick of an
excluded photo, of a photo picked for an earlier slot, or for a slot outside
the pattern is ignored. A custom list without any photo section uses the
default preset. With mirror on, every second `pair` in the sequence flips its
side.

The sequencer is `Section_Sequencer` (PHP). The editor previews it through
`GET /wp-json/profotograaf/v1/galleries/{id}/sections` (`Sections_Rest`),
which takes `preset`, `sections` and `picks` (JSON lists) and `exclude`
(comma separated) and answers `{ preset, galleryPreset, sections }` (`galleryPreset` is the preset the
gallery has on Profotograaf, empty when the payload carries none), every section with its
fields, `slots` and `photos` (`id`, `thumb`, `width`, `height`, `alt`). A
photo of the tail has the slot `''`.

Picks in a preset are keyed by slot, `<cycle>.<section index>.<position>`
(`0.0.0` is the first photo of the first section of the first cycle). In
`custom`, each section keeps its own `photoIds`.

The preset value `gallery` reads the `sections` object of the embed payload
(`Gallery_Layout`): `preset`, `picks` (`slot`, `photo_id`) and, for `custom`,
the `custom` sequence. Block picks win over the picks of the payload. For a
custom layout the picks of the first cycle become the `photoIds` of its
sections. A payload without a known preset gives the default preset.

## Markup contract

The renderer writes this markup and the front-end script
(`blocks/full-page-gallery/view.ts`) reads it. Both build against this section.

```html
<div class="pf-fp alignfull" data-pf-full-page="{gallery id}" data-preset="editorial">
  <section class="pf-fp-s pf-fp-grid" data-type="grid"
           style="--pf-cols:3;--pf-cols-phone:2;--pf-ratio:3/2;--pf-gap:8px">
    <figure class="pf-fp-photo">
      <a class="pf-fp-open" href="{photo page on Profotograaf}" data-pf-index="0">
        <img src="{web}" srcset="{web} 1600w, {large} 2560w" sizes="…"
             width="{w}" height="{h}" alt="{alt}" loading="lazy" decoding="async">
      </a>
      <figcaption>{caption}</figcaption>
    </figure>
    …
  </section>
  <section class="pf-fp-s pf-fp-text" data-type="text" style="--pf-align:center">
    <h2>{heading}</h2>
    <p>{text}</p>
  </section>
  <a class="pf-fp-badge" href="{badge.url}">…</a>
  <script type="application/json" class="pf-fp-data">{…}</script>
</div>
```

- Every section is a `section.pf-fp-s.pf-fp-{type}` with `data-type`. Layout
  values travel as CSS custom properties: `--pf-cols`, `--pf-cols-phone`,
  `--pf-ratio` (`auto` for `original`), `--pf-gap`, `--pf-height` (hero, in
  vh), `--pf-height-phone`, `--pf-row` and `--pf-row-phone` (filmstrip, in px),
  and `--pf-align` (text).
- Spacing and shape (`Full_Page_Look`): `--pf-gap-row` and `--pf-gap-col`
  when the section sets a gap per axis (the CSS falls back to `--pf-gap`),
  `--pf-radius` (`4px`, `12px` or `50%`; a circle outside a 1:1 grid gets the
  type's default corners), `--pf-frame` and `--pf-frame-color` when the frame
  is wider than 0, `data-hover="zoom|grow"` with `data-hover-strength`, and
  `data-shadow="soft|strong"`. The hover effect also follows keyboard focus,
  and is off under `prefers-reduced-motion: reduce`. The styles are in
  `blocks/full-page-gallery/look.css`.
- `pair` sets `data-side="left|right"`. The renderer resolves the mirroring,
  so the script and the CSS read the side as written.
- `filmstrip` sets `tabindex="0"`, `role="region"` and an `aria-label`, and
  wraps its figures in `.pf-fp-track`, the element that scrolls.
- `hero` with a title sets `data-title` and renders the title in
  `.pf-fp-title` over the photo.
- Captions: `data-captions="below|overlay"` on the section when they are on.
- `data-pf-index` is the photo's position in one flat list across all
  sections, in render order. The lightbox steps through that list.
- With the lightbox off, `.pf-fp-open` is a plain link to the photo page (or
  to nothing, following the block's link setting) and has no `data-pf-index`.
- The first section's images load eagerly. A hero in the first section gets
  `fetchpriority="high"`. Every later image has `loading="lazy"`.
- `.pf-fp-badge` is present only when the payload's `badge.show` is set, and
  has the class `prominent` when `badge.prominent` is set.
- `script.pf-fp-data` holds JSON:
  `{ "lightbox": bool, "photos": [ { "src", "srcset", "w", "h", "alt", "caption" } ], "beacon": { "url", "gallery" } }`.
  `photos` is the flat list behind `data-pf-index`.

## Platform

- The renderer reads `GET /api/v1/embed/galleries/{id}` and caches it in a
  transient for 5 minutes, keyed by the gallery id and the payload `version`.
- A watermarked gallery lists only `web`, so the renderer uses only `web`.
- A larger variant for hero and feature photos is
  wiebe-xyz/professionals#2393. The renderer adds it to `srcset` when the
  payload lists it.
- The view beacon is `POST {platform}/share/embed/view`, form-encoded
  `gallery`, `source=embed` and `origin`, sent once when the gallery is
  visible and never under Do Not Track or Global Privacy Control. It becomes a
  documented contract in wiebe-xyz/professionals#2394.
