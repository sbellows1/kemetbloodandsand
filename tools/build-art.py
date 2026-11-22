"""Build the BGA image sprites in img/ from the publisher's print PDFs (also in img/, not deployed).

Requires Pillow and PyMuPDF:  python -m pip install --user pillow pymupdf
Run from the repo root:       python tools/build-art.py
Then deploy:                  bash tools/deploy-bga.sh img/player-boards.jpg img/power-tiles.jpg img/di-cards.jpg img/acquire-tokens.png img/silver-token.png img/battle-sample.jpg

Outputs
  img/player-boards.jpg  5 Individual boards (Player Board Front), 720 x 516 each, stacked vertically
                         in player-color order: red, orange/yellow, blue, green, black.
  img/power-tiles.jpg    the 64 Power tiles (Punchboard Front pages 1-3), 8 x 8 grid of 195 x 172,
                         numbered row by row in punchboard order (page, row, column).
  img/di-cards.jpg       printed DI cards visible in the Player Aid, five cards in one row.
  img/acquire-tokens.png printed Acquire emblems from Punchboard Front page 4, four colors in one row;
                         the white token fields are transparent so they sit on the Individual board.
  img/silver-token.png   silver Action token illustration from the rulebook.
  img/battle-sample.jpg  battle card illustration shown in the rulebook (shared prototype art).
"""
import io
import re

import pymupdf
from PIL import Image, ImageChops, ImageDraw, ImageFilter

BOARD_W, BOARD_H = 720, 516
TILE_W, TILE_H = 195, 172


def board_images(path):
    """Yield (rgb, alpha) for each board page. The art is CMYK JPEG with a grayscale soft mask."""
    doc = pymupdf.open(path)
    for page in doc:
        xref = page.get_images(full=True)[0][0]
        info = doc.extract_image(xref)
        rgb = Image.open(io.BytesIO(info["image"]))
        if rgb.mode == "CMYK":
            rgb = ImageChops.invert(rgb)  # Adobe CMYK JPEGs are stored inverted
        rgb = rgb.convert("RGB")
        smask = info.get("smask") or 0
        alpha = Image.open(io.BytesIO(doc.extract_image(smask)["image"])).convert("L") if smask else None
        yield rgb, alpha


def build_boards():
    boards = list(board_images("img/Kemet Player Board Front.pdf"))
    sheet = Image.new("RGB", (BOARD_W, BOARD_H * len(boards)), (26, 20, 16))
    for i, (rgb, alpha) in enumerate(boards):
        rgb = rgb.resize((BOARD_W, BOARD_H), Image.LANCZOS)
        mask = alpha.resize((BOARD_W, BOARD_H), Image.LANCZOS) if alpha else None
        sheet.paste(rgb, (0, i * BOARD_H), mask)
    sheet.save("img/player-boards.jpg", quality=82, optimize=True, progressive=True)
    print("img/player-boards.jpg", sheet.size)


def runs(n, blank, min_len=40):
    out, start = [], None
    for i in range(n):
        if not blank(i) and start is None:
            start = i
        elif blank(i) and start is not None:
            out.append((start, i))
            start = None
    if start is not None:
        out.append((start, n))
    return [r for r in out if r[1] - r[0] > min_len]


def build_tiles():
    doc = pymupdf.open("img/Kemet Punchboard Front v5.pdf")
    crops = []
    cols = None
    for page in range(3):
        pix = doc[page].get_pixmap(matrix=pymupdf.Matrix(1.5, 1.5))
        im = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)
        g = im.convert("L")
        w, h = g.size
        px = g.load()
        col_runs = runs(w, lambda x: sum(px[x, y] > 245 for y in range(0, h, 2)) / (h / 2) > 0.97)
        if cols is None:
            cols = col_runs  # page 1 has a clean 4-column grid; reuse it for the others
        row_runs = [r for r in runs(h, lambda y: sum(px[x, y] > 245 for x in range(0, w, 2)) / (w / 2) > 0.97) if 200 < r[1] - r[0] < 260]
        for (y0, y1) in row_runs:
            for (x0, x1) in cols:
                crops.append(im.crop((x0, y0, x1, y1)).resize((TILE_W, TILE_H), Image.LANCZOS))
    per_row = 8
    rows = (len(crops) + per_row - 1) // per_row
    sheet = Image.new("RGB", (TILE_W * per_row, TILE_H * rows), (255, 255, 255))
    for i, c in enumerate(crops):
        sheet.paste(c, ((i % per_row) * TILE_W, (i // per_row) * TILE_H))
    sheet.save("img/power-tiles.jpg", quality=82, optimize=True, progressive=True)
    print("img/power-tiles.jpg", sheet.size, len(crops), "tiles")


def pdf_crop(page, bounds, size):
    """Render a print-sheet rectangle at web resolution, then fit it to a sprite cell."""
    pix = page.get_pixmap(matrix=pymupdf.Matrix(3, 3), clip=pymupdf.Rect(*bounds), alpha=False)
    return Image.frombytes("RGB", (pix.width, pix.height), pix.samples).resize(size, Image.LANCZOS)


def build_cards_and_tokens():
    aid_doc = pymupdf.open("img/B&S Player Aid v DEF EN.pdf")
    aid = aid_doc[4]
    # Prayer, War Fury, Iron Wall, Deadly Wound and Diversion. The prototype's
    # Reinforcement card has no matching face in the provided Player Aid.
    di_rects = [(33, 251, 90, 342), (72, 92, 130, 178), (287, 92, 345, 178),
                (56, 465, 114, 553), (373, 254, 431, 342)]
    di = Image.new("RGB", (120 * len(di_rects), 180), "#f4e4b8")
    for i, rect in enumerate(di_rects):
        di.paste(pdf_crop(aid, rect, (120, 180)), (120 * i, 0))
    di.save("img/di-cards.jpg", quality=87, optimize=True, progressive=True)
    print("img/di-cards.jpg", di.size)

    punch_doc = pymupdf.open("img/Kemet Punchboard Front v5.pdf")
    punch = punch_doc[3]
    # Ruby, Sapphire, Diamond, Onyx: one printed token of each color.
    acquire_rects = [(694, 801, 763, 870), (694, 657, 763, 726),
                     (613, 369, 682, 439), (613, 513, 682, 582)]
    acquire = Image.new("RGBA", (96 * len(acquire_rects), 96))
    for i, rect in enumerate(acquire_rects):
        emblem = pdf_crop(punch, rect, (96, 96)).convert("RGBA")
        # Only the printed diamond and horizontal rule; the token's white field
        # and gold square are redundant on the printed Individual board.
        mask = Image.new("L", (96, 96), 0)
        draw = ImageDraw.Draw(mask)
        draw.polygon([(48, 31), (65, 46), (48, 64), (31, 46)], fill=255)
        draw.rectangle((22, 43, 74, 49), fill=255)
        emblem.putalpha(mask.filter(ImageFilter.GaussianBlur(0.6)))
        acquire.alpha_composite(emblem, (96 * i, 0))
    acquire.save("img/acquire-tokens.png", optimize=True)
    print("img/acquire-tokens.png", acquire.size)

    rules = pymupdf.open("img/kemet rulebook v DEF EN.pdf")
    silver = pdf_crop(rules[4], (262, 124, 284, 146), (128, 128)).convert("RGBA")
    mask = Image.new("L", silver.size, 0)
    ImageDraw.Draw(mask).ellipse((2, 2, 125, 125), fill=255)
    silver.putalpha(mask)
    silver.save("img/silver-token.png", optimize=True)
    print("img/silver-token.png", silver.size)
    # The supplied files show one Battle card face, in the component diagram.
    # Crop its illustration only; the current prototype overlays its own values.
    sample = pdf_crop(rules[2], (522, 526, 559, 562), (260, 190))
    sample.save("img/battle-sample.jpg", quality=88, optimize=True, progressive=True)
    print("img/battle-sample.jpg", sample.size)


if __name__ == "__main__":
    build_boards()
    build_tiles()
    build_cards_and_tokens()
