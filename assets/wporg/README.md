# Directory artwork (placeholders)

These images are shown on the wordpress.org plugin page. The ones in this folder
are simple original placeholders drawn by `generate.py`. Replace them with final
artwork before the first public release. Keep the file names and sizes: the
wordpress.org SVN deploy reads them from here (`ASSETS_DIR: assets/wporg` in
`.github/workflows/release.yml`) and `.distignore` keeps them out of the plugin zip.

| File | Size | Used for |
|---|---|---|
| `icon-128x128.png`, `icon-256x256.png` | 128 and 256 px square | plugin icon |
| `banner-772x250.png`, `banner-1544x500.png` | 772x250 and 1544x500 | plugin page banner |
| `screenshot-1.png` to `screenshot-7.png` | any, about 1280x800 | the numbered captions in the `== Screenshots ==` section of `readme.txt` |

Screenshots 5 to 7 show the media source and are real captures of the plugin. They come from `tests/e2e/wporg-screenshots.spec.js`, which runs against the mock platform (`tests/e2e/mock-platform.mjs`) in scenic mode, so the library lists nine pictures drawn by `tests/e2e/fixtures/photos/generate.py`. To retake them:

```sh
make wporg-screenshots
```

The target builds the blocks, starts the E2E stack (Docker), captures the three PNGs at 1280x800, compresses them with `optimise.py` and stops the stack. The normal `make e2e` run skips the spec. The captures show:

- `screenshot-5.png`: the block inserter, Add block > Media > Profotograaf, with photos listed.
- `screenshot-6.png`: Media > Import from Profotograaf with four photos selected.
- `screenshot-7.png`: the media modal with the Profotograaf tab, opened from the Featured image panel, with one photo selected.

The site title in the captures is a made-up studio name and the administrator is the E2E account, so nothing personal shows. Commit the PNGs after you check them.

To regenerate the placeholders: `python3 assets/wporg/generate.py` (needs Pillow).
To replace one, overwrite the PNG. When you change the number of screenshots, change
the captions in `readme.txt` to match.
