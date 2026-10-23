# Page builders

The plugin adds a gallery element to Elementor and to Divi 4. Both render through the same path as the gallery block. This document covers what loads, the controls, the rendering and the checks.

## Loading

`Modules\Elementor` waits for the `elementor/loaded` action (or runs at once when it already fired) and adds one callback to `elementor/widgets/register`. The widget class `Elementor_Gallery_Widget` extends an Elementor class, so nothing references it before that callback runs. Without Elementor the module adds an action that never fires, and no widget file and no builder base class is loaded. `Elementor_Test::test_without_elementor_nothing_of_it_is_loaded` asserts this in a separate process.

`Modules\Divi` does the same for Divi 4 on `et_builder_ready` (at once when that action already fired) and creates the module only when `ET_Builder_Module` exists. `Divi_Gallery_Module` extends that class, so it is referenced only from that callback. `Divi_Test::test_without_divi_nothing_of_it_is_loaded` asserts that nothing loads without Divi.

## The Elementor widget

Name `profotograaf_gallery`, title "Profotograaf Gallery", in the Basic category. Panel search keywords: gallery, photo, photography, portfolio, client gallery and profotograaf.

| Control | Stored as | Choices |
|---|---|---|
| Gallery | `gallery_id` | Galleries of the connected account, from the stored gallery list (`Gallery_Index::all()`). Opening Elementor makes no request to Profotograaf. |
| Layout | `layout` | Site default, Automatic, Grid, Masonry, Slideshow (the block's own layouts). |
| Columns | `columns` | Site default, 1 to 8. |
| Columns on tablets | `columns_tablet` | Site default, 1 to 8. |
| Columns on phones | `columns_mobile` | Site default, 1 to 4. |
| Lightbox | `lightbox` | Site default, On, Off. |

An empty value means the site default, as in the block and the shortcode.

## The Divi 4 module

Name "Profotograaf Gallery", slug `profotograaf_gallery`, `vb_support` on. The module has the same six fields as the widget, as select fields on the General tab, with choices from `Builder_Gallery`. The fonts and text design options are off. Divi 5 has a different module API and is not covered.

## Rendering

Both builders call `Builder_Gallery::render()`, which cleans the stored values (`Builder_Gallery::attributes()`) and calls WordPress's `render_block()` for `profotograaf/gallery`. The element therefore gets the same consent check, wrapper and `Gallery_Renderer` output as the block, and the Elementor editor canvas shows the server render. The stored title and link of the gallery are passed as `galleryTitle` and `galleryUrl`, so the fallback link works as in the block.

## Checks

- Unit tests: `Builder_Gallery_Test` (cleaning, block parity, rendering), `Elementor_Test` and `Divi_Test` (loading, registration, controls, render). The builders' base classes are replaced by `tests/stubs/elementor.php` and `tests/stubs/divi.php`, which PHPStan also scans.
- E2E: `tests/e2e/elementor.spec.js` installs a pinned Elementor from wordpress.org, adds the widget to a page and checks the front end. It runs in the nightly E2E workflow with the other specs and skips when wordpress.org cannot be reached.

Divi is a paid theme, so no E2E spec runs it. The Divi module is covered by unit tests only.

## Not covered

- The Elementor add-ons directory listing is a submission by the owner.
- Divi 5 and the Divi visual builder UI. The preview is the server render of `render()`.
- The editor canvas check is limited to the server render. The spec does not drive the Elementor editor UI.
