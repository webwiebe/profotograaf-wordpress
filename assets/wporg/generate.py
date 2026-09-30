#!/usr/bin/env python3
"""Generates the placeholder artwork for the wordpress.org plugin page.

Everything here is original, simple geometry made for this plugin. Replace the
PNG files with final artwork whenever you like; keep the file names and sizes
(see assets/wporg/README.md). Run: python3 assets/wporg/generate.py (needs Pillow).
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parent
INK = (29, 35, 39)
CREAM = (250, 246, 238)
ACCENT = (201, 106, 63)
SAND = (232, 220, 200)


def font(size):
    return ImageFont.load_default(size=size)


def gradient(width, height, top, bottom):
    image = Image.new("RGB", (width, height), top)
    draw = ImageDraw.Draw(image)
    for y in range(height):
        t = y / max(1, height - 1)
        colour = tuple(round(top[i] + (bottom[i] - top[i]) * t) for i in range(3))
        draw.line([(0, y), (width, y)], fill=colour)
    return image


def aperture(draw, cx, cy, radius, fill, hole):
    """A lens: a ring, an inner disc and a highlight."""
    draw.ellipse([cx - radius, cy - radius, cx + radius, cy + radius], fill=fill)
    inner = radius * 0.72
    draw.ellipse([cx - inner, cy - inner, cx + inner, cy + inner], fill=hole)
    core = radius * 0.34
    draw.ellipse([cx - core, cy - core, cx + core, cy + core], fill=fill)
    glint = radius * 0.11
    gx, gy = cx - radius * 0.14, cy - radius * 0.14
    draw.ellipse([gx - glint, gy - glint, gx + glint, gy + glint], fill=hole)


def icon(size):
    scale = 4
    big = size * scale
    image = Image.new("RGB", (big, big), CREAM)
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle([0, 0, big, big], radius=big // 5, fill=INK)
    aperture(draw, big / 2, big / 2, big * 0.34, ACCENT, INK)
    return image.resize((size, size), Image.LANCZOS)


def banner(width, height):
    scale = width / 772
    image = gradient(width, height, INK, (52, 60, 66))
    draw = ImageDraw.Draw(image)
    aperture(draw, width * 0.80, height * 0.52, height * 0.36, ACCENT, INK)
    draw.text((width * 0.06, height * 0.30), "Profotograaf", font=font(round(46 * scale)), fill=CREAM)
    draw.text((width * 0.06, height * 0.56), "Galleries, client access and enquiries", font=font(round(19 * scale)), fill=SAND)
    draw.text((width * 0.06, height * 0.84), "Placeholder artwork, replace before release", font=font(round(11 * scale)), fill=(150, 158, 165))
    return image


def screenshot(index, title):
    width, height = 1280, 800
    image = Image.new("RGB", (width, height), CREAM)
    draw = ImageDraw.Draw(image)
    draw.rectangle([0, 0, width, 56], fill=INK)
    draw.text((28, 14), "Profotograaf", font=font(24), fill=CREAM)
    draw.rounded_rectangle([90, 120, width - 90, height - 90], radius=14, fill=(255, 255, 255), outline=SAND, width=3)
    draw.text((130, 160), title, font=font(38), fill=INK)
    for row in range(3):
        top = 260 + row * 96
        draw.rounded_rectangle([130, top, width - 130, top + 64], radius=8, fill=(244, 238, 228))
    draw.rounded_rectangle([130, 570, 400, 636], radius=8, fill=ACCENT)
    draw.text((width - 640, height - 60), "Placeholder screenshot %d, replace with a real capture" % index, font=font(18), fill=(120, 120, 120))
    return image


def main():
    icon(128).save(OUT / "icon-128x128.png", optimize=True)
    icon(256).save(OUT / "icon-256x256.png", optimize=True)
    banner(772, 250).save(OUT / "banner-772x250.png", optimize=True)
    banner(1544, 500).save(OUT / "banner-1544x500.png", optimize=True)
    titles = [
        "Connect your site to Profotograaf",
        "Place a gallery with the gallery block",
        "Send clients to their galleries",
        "Forward form enquiries to your inbox",
    ]
    for index, title in enumerate(titles, start=1):
        screenshot(index, title).save(OUT / ("screenshot-%d.png" % index), optimize=True)


if __name__ == "__main__":
    main()
