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
| `screenshot-1.png` to `screenshot-4.png` | any, about 1280x800 | the numbered captions in the `== Screenshots ==` section of `readme.txt` |

To regenerate the placeholders: `python3 assets/wporg/generate.py` (needs Pillow).
To replace one, overwrite the PNG. When you change the number of screenshots, change
the captions in `readme.txt` to match.
