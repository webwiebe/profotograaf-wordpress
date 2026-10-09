#!/usr/bin/env python3
"""Compresses the captured screenshots (screenshot-5.png to screenshot-7.png).

Run: python3 assets/wporg/optimise.py   (needs Pillow)

Re-saves each capture as a PNG with maximum compression. The pixels stay
unchanged: a 256-colour palette bands the sky gradients in the photos, so no
colour reduction happens. The files stay under 200 KB. It is safe to run twice.
"""
from pathlib import Path

from PIL import Image

HERE = Path(__file__).parent
CAPTURED = [HERE / f'screenshot-{n}.png' for n in (5, 6, 7)]


def main():
    for path in CAPTURED:
        if not path.exists():
            raise SystemExit(f'{path.name} is missing, run the capture first')
        before = path.stat().st_size
        Image.open(path).convert('RGB').save(path, optimize=True, compress_level=9)
        print(f'{path.name}: {before // 1024} KB -> {path.stat().st_size // 1024} KB')


if __name__ == '__main__':
    main()
