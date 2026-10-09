#!/usr/bin/env python3
"""Draws the scenic mock photos the wordpress.org screenshots use.

Run: python3 tests/e2e/fixtures/photos/generate.py   (needs Pillow and numpy)

Each scene is original, procedural artwork: a sky gradient, a sun or moon, layered
hills and a little grain. It writes <id>-web.jpg (1280x853) and <id>-thumb.jpg
(400x400) for the ids listed in SCENES. The mock platform serves them after
POST /__scenic (see tests/e2e/mock-platform.mjs). The output is committed, so
this only has to run when a scene changes.
"""
import math
import random
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = Path(__file__).parent
W, H = 1280, 853

# id: (top sky, horizon sky, sun colour, sun x, sun y, hill colours far to near)
SCENES = {
    'p-1': ((246, 190, 140), (255, 235, 200), (255, 250, 230), 0.68, 0.46, [(214, 150, 118), (150, 110, 96), (88, 72, 78)]),
    'p-2': ((150, 175, 196), (226, 232, 232), (250, 250, 245), 0.30, 0.40, [(170, 190, 200), (120, 148, 162), (74, 100, 116)]),
    'p-3': ((236, 170, 96), (250, 214, 150), (255, 240, 200), 0.20, 0.52, [(196, 112, 52), (150, 76, 36), (92, 48, 30)]),
    'p-4': ((38, 52, 96), (240, 150, 110), (255, 226, 190), 0.50, 0.60, [(86, 70, 104), (52, 50, 84), (26, 30, 56)]),
    'p-5': ((130, 190, 232), (222, 240, 240), (255, 255, 240), 0.78, 0.30, [(150, 200, 120), (104, 168, 92), (62, 124, 70)]),
    'p-6': ((176, 196, 170), (232, 238, 214), (252, 250, 232), 0.40, 0.34, [(128, 154, 110), (84, 112, 82), (44, 70, 56)]),
    'p-7': ((240, 196, 150), (252, 232, 196), (255, 248, 224), 0.62, 0.38, [(232, 190, 130), (212, 160, 100), (180, 126, 82)]),
    'p-8': ((24, 42, 94), (96, 132, 190), (226, 236, 255), 0.26, 0.28, [(52, 74, 124), (34, 50, 96), (18, 28, 60)]),
    'p-9': ((190, 214, 236), (246, 236, 214), (255, 250, 232), 0.55, 0.42, [(188, 168, 112), (146, 142, 84), (98, 108, 64)]),
}


def lerp(a, b, t):
    return tuple(a[i] + (b[i] - a[i]) * t for i in range(3))


def ridge(rng, base, amp, width):
    phases = [rng.uniform(0, math.tau) for _ in range(3)]
    freqs = [rng.uniform(0.8, 1.6), rng.uniform(2.2, 3.6), rng.uniform(5, 8)]
    return [
        base + amp * sum(math.sin(math.tau * f * x / width + p) / (i + 1) for i, (f, p) in enumerate(zip(freqs, phases)))
        for x in range(width)
    ]


def scene(index, spec):
    top, horizon, sun_colour, sun_x, sun_y, hills = spec
    rng = random.Random(index * 7919)
    sky = np.zeros((H, W, 3), dtype=np.float32)
    for y in range(H):
        sky[y, :, :] = lerp(top, horizon, min(1.0, y / (H * 0.7)) ** 1.3)
    img = Image.fromarray(sky.clip(0, 255).astype('uint8'))

    glow = Image.new('RGB', (W, H), (0, 0, 0))
    gd = ImageDraw.Draw(glow)
    cx, cy = int(W * sun_x), int(H * sun_y)
    gd.ellipse((cx - 150, cy - 150, cx + 150, cy + 150), fill=tuple(int(c * 0.45) for c in sun_colour))
    glow = glow.filter(ImageFilter.GaussianBlur(70))
    summed = np.asarray(img, dtype=np.int16) + np.asarray(glow, dtype=np.int16)
    img = Image.fromarray(np.clip(summed, 0, 255).astype('uint8'))
    ImageDraw.Draw(img).ellipse((cx - 34, cy - 34, cx + 34, cy + 34), fill=sun_colour)
    img = img.filter(ImageFilter.GaussianBlur(1.2))

    d = ImageDraw.Draw(img)
    for layer, colour in enumerate(hills):
        base = H * (0.58 + layer * 0.12)
        line = ridge(rng, base, 28 + layer * 14, W)
        d.polygon([(0, H)] + [(x, y) for x, y in enumerate(line)] + [(W, H)], fill=colour)
        if layer < 2:
            img = Image.blend(img, img.filter(ImageFilter.GaussianBlur(6)), 0.35)
            d = ImageDraw.Draw(img)

    arr = np.asarray(img, dtype=np.float32)
    yy, xx = np.mgrid[0:H, 0:W]
    vignette = 1 - 0.28 * (((xx - W / 2) / (W / 2)) ** 2 + ((yy - H / 2) / (H / 2)) ** 2) / 2
    arr = arr * vignette[..., None] + np.random.default_rng(index).normal(0, 3.2, arr.shape)
    return Image.fromarray(arr.clip(0, 255).astype('uint8'))


def main():
    for index, (photo_id, spec) in enumerate(SCENES.items(), start=1):
        img = scene(index, spec)
        img.save(HERE / f'{photo_id}-web.jpg', quality=62, optimize=True, progressive=True)
        left = (W - H) // 2
        thumb = img.crop((left, 0, left + H, H)).resize((400, 400), Image.LANCZOS)
        thumb.save(HERE / f'{photo_id}-thumb.jpg', quality=70, optimize=True)


if __name__ == '__main__':
    main()
