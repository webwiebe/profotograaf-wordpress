#!/usr/bin/env python3
"""Generates the icons and banners for the wordpress.org plugin page from the
Profotograaf brand files in assets/wporg/source, and the placeholder screenshots
1 to 4. Keep the file names and sizes (see assets/wporg/README.md). Run: python3 assets/wporg/generate.py (needs Pillow).
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parent
SOURCE = OUT / "source"
PAPER = (246, 246, 243)
INK = (29, 35, 39)
CREAM = (250, 246, 238)
ACCENT = (201, 106, 63)
SAND = (232, 220, 200)


def font(size):
    return ImageFont.load_default(size=size)


def icon(size):
    """The site's camera icon (source/brand-icon-512.png), scaled down."""
    source = Image.open(SOURCE / "brand-icon-512.png").convert("RGB")
    return source.resize((size, size), Image.LANCZOS)


def banner(width, height):
    """The ProFotograaf wordmark and the camera icon on the site's light background."""
    scale = width / 772
    image = Image.new("RGB", (width, height), PAPER)
    mark = Image.open(SOURCE / "brand-wordmark.png").convert("RGBA")
    mark = mark.crop(mark.getchannel("A").getbbox())
    mark_width = round(430 * scale)
    mark = mark.resize((mark_width, round(mark.height * mark_width / mark.width)), Image.LANCZOS)
    image.paste(mark, (round(60 * scale), (height - mark.height) // 2), mark)
    size = round(height * 0.62)
    camera = icon(size)
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=size // 5, fill=255)
    image.paste(camera, (width - size - round(60 * scale), (height - size) // 2), mask)
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
