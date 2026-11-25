# Block patterns

The plugin registers seven patterns in two categories, Profotograaf and Photography
(`patterns/*.php`, loaded by `includes/modules/class-patterns.php`; the file name is the slug):
portfolio grid, masonry showcase, client portal call to action, contact page with enquiry
form, wedding story with slideshow, services with gallery and enquiry form, and testimonials
with photos. Text goes through `__()` and lands in the .pot.

## Pattern Directory versions

`pattern-directory/` holds the same seven patterns built from core blocks only, because the
wordpress.org Pattern Directory takes no patterns that need a plugin. Each gallery is a dashed
placeholder with a link to the single-block plugin (`profotograaf-gallery-block`), and forms and
the client portal entry are plain text or core buttons. The folder is not in the plugin zip
(`.distignore`) and has no translations.

`tests/unit/Pattern_Directory_Test.php` fails when a plugin pattern has no core-only version or
when a core-only version uses a block outside core.

To submit, paste the block markup of one file at https://wordpress.org/patterns/new-pattern/
with the wordpress.org account of the plugin (wp#332). The placeholder link resolves once
the block plugin is listed.

## Checks

- `tests/unit/Patterns_Test.php`: slugs, categories, translated text, known block attributes.
- `tests/e2e/patterns-tt5.spec.js`: every registered pattern becomes a page that renders for a
  visitor on Twenty Twenty-Five without PHP notices. The client portal pattern is one dynamic
  block and shows nothing until the site is connected, so only its status and notices are checked.
