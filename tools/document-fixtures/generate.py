"""Generate synthetic test documents (front/back) with ground truth.

Every document is fictional and marked SPECIMEN. Output goes to
tests/fixtures/documents/<name>/{front,back}.(png|jpg) + meta.json, which is
what `php artisan documents:benchmark` reads.

Run (from the project root):
  docker run --rm -v "$PWD":/work -w /work python:3.12-slim \
    sh -c "apt-get update -qq && apt-get install -y -qq fonts-dejavu-core fonts-noto-core libfribidi0 >/dev/null \
           && pip install -q pillow numpy && python tools/document-fixtures/generate.py"
"""

import json
import random
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont, features

OUT = Path("tests/fixtures/documents")
random.seed(7)
np.random.seed(7)

DEJAVU = "/usr/share/fonts/truetype/dejavu/"
NOTO = "/usr/share/fonts/truetype/noto/"
LAYOUT = ImageFont.Layout.RAQM if features.check("raqm") else ImageFont.Layout.BASIC


def font(name, size):
    path = NOTO + name if name.startswith("Noto") else DEJAVU + name
    return ImageFont.truetype(path, size, layout_engine=LAYOUT)


SANS, BOLD, MONO = "DejaVuSans.ttf", "DejaVuSans-Bold.ttf", "DejaVuSansMono.ttf"
HINDI = "NotoSansDevanagari-Regular.ttf"
INK, MUTED = (25, 30, 40), (90, 98, 112)


def save(name, pages, meta):
    folder = OUT / name
    folder.mkdir(parents=True, exist_ok=True)
    for old in folder.glob("*"):
        old.unlink()
    for side, (image, fmt) in pages.items():
        if fmt == "jpg":
            image.convert("RGB").save(folder / f"{side}.jpg", quality=meta.pop("_jpeg_quality", 70))
        else:
            image.save(folder / f"{side}.png")
    (folder / "meta.json").write_text(json.dumps(meta, indent=2, ensure_ascii=False) + "\n")
    print("wrote", folder)


# --------------------------------------------------------------------------- helpers

def card(size=(1012, 638), tint=(232, 240, 236)):
    """Blank ID-1 card at ~300 dpi with a soft guilloche-like background."""
    w, h = size
    img = Image.new("RGB", size, tint)
    d = ImageDraw.Draw(img)
    for i in range(0, w + h, 14):
        d.line([(i, 0), (i - h, h)], fill=tuple(c - 6 for c in tint), width=1)
    d.rounded_rectangle([2, 2, w - 3, h - 3], radius=36, outline=(150, 160, 160), width=3)
    return img, d


def photo(d, box):
    x0, y0, x1, y1 = box
    d.rectangle(box, fill=(200, 206, 212), outline=(120, 128, 136), width=2)
    cx, w = (x0 + x1) // 2, x1 - x0
    d.ellipse([cx - w * 0.22, y0 + 40, cx + w * 0.22, y0 + 40 + w * 0.48], fill=(150, 158, 166))
    d.pieslice([x0 + 18, y1 - w * 0.55, x1 - 18, y1 + w * 0.45], 180, 360, fill=(150, 158, 166))


def mixed(d, xy, text, size, fill, latin=SANS):
    """Draw Hindi + English text: the Devanagari font has no Latin glyphs,
    so switch fonts per run of script."""
    x, y = xy
    is_deva = lambda ch: "ऀ" <= ch <= "ॿ"
    runs, current, deva = [], "", None
    for ch in text:
        kind = is_deva(ch) if not ch.isspace() and ch not in "/:,.-" else deva
        if deva is not None and kind != deva:
            runs.append((current, deva))
            current = ""
        current += ch
        deva = kind
    runs.append((current, deva))
    for chunk, deva in runs:
        f = font(HINDI if deva else latin, size)
        # Devanagari sits higher in its em box; nudge it to share a baseline.
        d.text((x, y - (size * 0.18 if deva else 0)), chunk, font=f, fill=fill)
        x += d.textlength(chunk, font=f)


def field(d, xy, label, value, label_size=20, value_size=30):
    x, y = xy
    d.text((x, y), label, font=font(SANS, label_size), fill=MUTED)
    d.text((x, y + label_size + 4), value, font=font(BOLD, value_size), fill=INK)


def specimen(img, text="SPECIMEN"):
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).text((img.width * 0.18, img.height * 0.38), text, font=font(BOLD, 110), fill=(200, 40, 40, 55))
    layer = layer.rotate(18, center=(img.width / 2, img.height / 2))
    return Image.alpha_composite(img.convert("RGBA"), layer).convert("RGB")


def perspective_coeffs(src, dst):
    rows = []
    for (x, y), (u, v) in zip(dst, src):
        rows.append([x, y, 1, 0, 0, 0, -u * x, -u * y])
        rows.append([0, 0, 0, x, y, 1, -v * x, -v * y])
    a = np.array(rows, dtype=float)
    b = np.array(src, dtype=float).reshape(8)
    return np.linalg.solve(a, b).tolist()


def photograph(img, skew=0.06, angle=3.5, blur=1.1, seed=0):
    """Make a clean scan look like a phone photo on a desk."""
    rnd = random.Random(seed)
    w, h = img.size
    pad = int(max(w, h) * 0.18)
    canvas = Image.new("RGB", (w + 2 * pad, h + 2 * pad), (118, 92, 70))
    noise = (np.random.normal(0, 9, (canvas.height, canvas.width, 1)) * [1, 0.9, 0.8]).astype(np.int16)
    canvas = Image.fromarray(np.clip(np.asarray(canvas, dtype=np.int16) + noise, 0, 255).astype(np.uint8))
    canvas.paste(img, (pad, pad))

    cw, ch = canvas.size
    j = lambda m: rnd.uniform(-m, m)
    src = [(pad, pad), (pad + w, pad), (pad + w, pad + h), (pad, pad + h)]
    dst = [(pad + j(w * skew), pad + j(h * skew)), (pad + w + j(w * skew), pad + j(h * skew)),
           (pad + w + j(w * skew), pad + h + j(h * skew)), (pad + j(w * skew), pad + h + j(h * skew))]
    canvas = canvas.transform((cw, ch), Image.PERSPECTIVE, perspective_coeffs(src, dst), Image.BICUBIC)
    canvas = canvas.rotate(angle, resample=Image.BICUBIC, fillcolor=(118, 92, 70))

    # Uneven light: brighter top-left, darker bottom-right.
    gx = np.linspace(1.12, 0.78, cw)[None, :, None]
    gy = np.linspace(1.05, 0.85, ch)[:, None, None]
    lit = np.clip(np.asarray(canvas, dtype=float) * gx * gy, 0, 255).astype(np.uint8)
    canvas = Image.fromarray(lit).filter(ImageFilter.GaussianBlur(blur))
    return canvas.resize((int(cw * 0.8), int(ch * 0.8)), Image.BILINEAR)


# --------------------------------------------------------------------------- documents

def id_card():
    front, d = card()
    d.text((40, 28), "REPUBLIC OF ESTOVIA", font=font(BOLD, 34), fill=(20, 70, 60))
    d.text((40, 70), "NATIONAL IDENTITY CARD", font=font(SANS, 24), fill=(20, 70, 60))
    photo(d, (40, 130, 300, 470))
    field(d, (340, 128), "Surname", "MENDES")
    field(d, (340, 198), "Given names", "ANIKA ROSE")
    field(d, (340, 268), "Sex", "F")
    field(d, (520, 268), "Nationality", "ESTOVIAN")
    field(d, (340, 338), "Date of birth", "14.03.1991")
    field(d, (640, 338), "Date of expiry", "12.08.2031")
    field(d, (340, 408), "Document No.", "EST4829173K")
    d.text((40, 500), "Signature", font=font(SANS, 18), fill=MUTED)
    d.line([(40, 570), (300, 545)], fill=INK, width=3)
    front = specimen(front)

    back, d = card(tint=(236, 236, 228))
    field(d, (40, 40), "Address", "12 MAPLE COURT, RIVERSIDE")
    d.text((40, 106), "NORTHGATE 40211", font=font(BOLD, 30), fill=INK)
    field(d, (40, 170), "Date of issue", "13.08.2021")
    field(d, (380, 170), "Issuing authority", "CIVIL REGISTRY NORTHGATE")
    mrz = ["IDESTEST4829173K7<<<<<<<<<<<<<",
           "9103145F3108128EST<<<<<<<<<<<6",
           "MENDES<<ANIKA<ROSE<<<<<<<<<<<<"]
    d.rectangle([0, 400, 1012, 638], fill=(250, 250, 246))
    for i, line in enumerate(mrz):
        d.text((40, 420 + i * 66), line, font=font(MONO, 50), fill=INK)
    back = specimen(back)

    expected = {
        "full_name": "Anika Rose Mendes",
        "date_of_birth": "1991-03-14",
        "sex": "F",
        "id_number": "EST4829173K",
        "nationality": "Estovian",
        "issue_date": "2021-08-13",
        "expiry_date": "2031-08-12",
        "address": "12 Maple Court, Riverside, Northgate 40211",
    }
    return front, back, expected


def resident_card_bilingual():
    hi = lambda size: font(HINDI, size)

    front, d = card(tint=(244, 238, 226))
    d.text((40, 24), "निवासी पहचान पत्र", font=hi(34), fill=(150, 70, 20))
    d.text((40, 76), "RESIDENT IDENTITY CARD", font=font(BOLD, 28), fill=(150, 70, 20))
    photo(d, (40, 140, 290, 460))
    rows = [("नाम / Name", "Priya Sharma"),
            ("जन्म तिथि / DOB", "05/11/1994"),
            ("लिंग / Sex", "महिला / Female"),
            ("राष्ट्रीयता / Nationality", "भारतीय / Indian")]
    for i, (label, value) in enumerate(rows):
        y = 140 + i * 78
        mixed(d, (330, y), label, 20, MUTED)
        mixed(d, (330, y + 30), value, 30, INK)
    d.text((330, 470), "4821 7730 5196", font=font(BOLD, 44), fill=INK)
    mixed(d, (330, 528), "Valid until / वैधता: 31/12/2034", 22, MUTED)
    front = specimen(front)

    back, d = card(tint=(244, 238, 226))
    mixed(d, (40, 30), "पता / Address", 22, MUTED)
    d.text((40, 66), "फ्लैट 7बी, गुलमोहर अपार्टमेंट्स, सेक्टर 21,", font=hi(28), fill=INK)
    d.text((40, 108), "द्वारका, नई दिल्ली 110077", font=hi(28), fill=INK)
    d.text((40, 170), "Flat 7B, Gulmohar Apartments, Sector 21,", font=font(SANS, 28), fill=INK)
    d.text((40, 210), "Dwarka, New Delhi 110077", font=font(SANS, 28), fill=INK)
    mixed(d, (40, 290), "जारी करने की तिथि / Issued: 02/01/2025", 24, MUTED)
    d.text((40, 560), "4821 7730 5196", font=font(BOLD, 34), fill=INK)
    back = specimen(back)

    expected = {
        "full_name": "Priya Sharma",
        "date_of_birth": "1994-11-05",
        "sex": "F",
        "id_number": "482177305196",
        "nationality": "Indian",
        "issue_date": "2025-01-02",
        "expiry_date": "2034-12-31",
        "address": "Flat 7B, Gulmohar Apartments, Sector 21, Dwarka, New Delhi 110077",
    }
    return front, back, expected


def driving_licence():
    front, d = card(tint=(230, 236, 246))
    d.text((40, 26), "DRIVING LICENCE", font=font(BOLD, 36), fill=(30, 50, 120))
    d.text((40, 70), "Republic of Estovia", font=font(SANS, 22), fill=(30, 50, 120))
    photo(d, (40, 120, 290, 440))
    lines = [("1.", "OKAFOR"), ("2.", "DANIEL CHUKWUEMEKA"), ("3.", "22.07.1986  LAGOSVILLE"),
             ("4a.", "05.02.2024"), ("4b.", "04.02.2034"), ("4c.", "TRANSPORT AUTHORITY"),
             ("5.", "OKAFO 860722 D99"), ("8.", "4 HARBOUR ROAD, EASTPORT 30915")]
    for i, (num, value) in enumerate(lines):
        y = 116 + i * 52
        d.text((320, y), num, font=font(SANS, 24), fill=MUTED)
        d.text((380, y - 2), value, font=font(BOLD, 28), fill=INK)
    d.text((320, 545), "9.  AM / A1 / B", font=font(BOLD, 28), fill=INK)
    front = specimen(front)

    back, d = card(tint=(230, 236, 246))
    d.text((40, 24), "9. Category    10. Valid from    11. Valid until    12. Codes", font=font(SANS, 22), fill=MUTED)
    cats = [("AM", "05.02.2024", "04.02.2034", ""), ("A1", "05.02.2024", "04.02.2034", ""),
            ("A", "", "", ""), ("B", "05.02.2024", "04.02.2034", "01"),
            ("C", "", "", ""), ("D", "", "", "")]
    for i, (cat, start, end, code) in enumerate(cats):
        y = 70 + i * 62
        d.line([(40, y - 8), (972, y - 8)], fill=(170, 180, 200), width=1)
        d.text((60, y), cat, font=font(BOLD, 30), fill=INK)
        d.text((230, y), start, font=font(SANS, 28), fill=INK)
        d.text((470, y), end, font=font(SANS, 28), fill=INK)
        d.text((760, y), code, font=font(BOLD, 28), fill=INK)
    d.text((40, 470), "12. Restrictions: 01", font=font(BOLD, 28), fill=INK)
    d.text((40, 520), "Code 01 = corrective lenses required", font=font(SANS, 22), fill=MUTED)
    back = specimen(back)

    expected = {
        "full_name": "Daniel Chukwuemeka Okafor",
        "date_of_birth": "1986-07-22",
        "licence_number": "OKAFO 860722 D99",
        "issue_date": "2024-02-05",
        "expiry_date": "2034-02-04",
        "address": "4 Harbour Road, Eastport 30915",
        "vehicle_classes": ["AM", "A1", "B"],
        "restrictions": "01",
    }
    return front, back, expected


def business_card():
    front = Image.new("RGB", (1050, 600), (24, 38, 52))
    d = ImageDraw.Draw(front)
    d.ellipse([70, 70, 190, 190], outline=(240, 180, 80), width=10)
    d.text((220, 92), "Quillstone Labs", font=font(BOLD, 52), fill=(245, 245, 240))
    d.text((80, 330), "Meera Iyer", font=font(BOLD, 60), fill=(245, 245, 240))
    d.text((80, 410), "Head of Product Engineering", font=font(SANS, 34), fill=(240, 180, 80))

    back = Image.new("RGB", (1050, 600), (246, 244, 238))
    d = ImageDraw.Draw(back)
    rows = ["+91 98450 31277", "meera.iyer@quillstone.io", "www.quillstone.io",
            "3rd Floor, 88 Residency Road,", "Bengaluru 560025"]
    for i, text in enumerate(rows):
        d.text((80, 90 + i * 84), text, font=font(SANS, 40), fill=INK)

    expected = {
        "full_name": "Meera Iyer",
        "job_title": "Head of Product Engineering",
        "company": "Quillstone Labs",
        "phone": "+91 98450 31277",
        "email": "meera.iyer@quillstone.io",
        "website": "www.quillstone.io",
        "address": "3rd Floor, 88 Residency Road, Bengaluru 560025",
    }
    return front, back, expected


def invoice():
    img = Image.new("RGB", (1240, 1754), "white")
    d = ImageDraw.Draw(img)
    d.text((80, 80), "Northwind Analytics Pvt Ltd", font=font(BOLD, 44), fill=INK)
    d.text((80, 140), "401 Lakeview Towers, Powai, Mumbai 400076", font=font(SANS, 24), fill=MUTED)
    d.text((80, 172), "GSTIN 27AACCN4821K1Z9", font=font(SANS, 24), fill=MUTED)
    d.text((880, 80), "TAX INVOICE", font=font(BOLD, 40), fill=(30, 80, 160))

    meta = [("Invoice No.", "INV-2026-0417"), ("Invoice Date", "03 Oct 2026"), ("Due Date", "02 Nov 2026")]
    for i, (k, v) in enumerate(meta):
        d.text((800, 160 + i * 40), k, font=font(SANS, 24), fill=MUTED)
        d.text((990, 160 + i * 40), v, font=font(BOLD, 24), fill=INK)

    d.text((80, 300), "Bill To", font=font(SANS, 24), fill=MUTED)
    d.text((80, 334), "Lumen Retail LLP", font=font(BOLD, 30), fill=INK)
    d.text((80, 374), "22 MG Road, Pune 411001", font=font(SANS, 24), fill=INK)

    items = [("Data pipeline setup", 1, 85000.00), ("Dashboard licences (12 users)", 12, 1500.00),
             ("Monthly support retainer", 1, 25000.00), ("On-site training (half day)", 2, 9000.00)]
    y = 480
    d.rectangle([80, y, 1160, y + 50], fill=(232, 238, 248))
    for x, h in [(96, "Description"), (720, "Qty"), (820, "Rate (₹)"), (1000, "Amount (₹)")]:
        d.text((x, y + 12), h, font=font(BOLD, 24), fill=INK)
    y += 70
    subtotal = 0
    for desc, qty, rate in items:
        amount = qty * rate
        subtotal += amount
        d.text((96, y), desc, font=font(SANS, 24), fill=INK)
        d.text((730, y), str(qty), font=font(SANS, 24), fill=INK)
        d.text((820, y), f"{rate:,.2f}", font=font(SANS, 24), fill=INK)
        d.text((1000, y), f"{amount:,.2f}", font=font(SANS, 24), fill=INK)
        d.line([(80, y + 44), (1160, y + 44)], fill=(220, 224, 230))
        y += 62

    tax = round(subtotal * 0.18, 2)
    total = subtotal + tax
    y += 30
    for label, value, bold in [("Subtotal", subtotal, False), ("IGST @ 18%", tax, False), ("Total (INR)", total, True)]:
        d.text((760, y), label, font=font(BOLD if bold else SANS, 28), fill=INK)
        d.text((1000, y), f"₹{value:,.2f}", font=font(BOLD if bold else SANS, 28), fill=INK)
        y += 50

    d.text((80, 1500), "Payment: NEFT to HDFC Bank A/C 50200012345678, IFSC HDFC0000123", font=font(SANS, 22), fill=MUTED)
    d.text((80, 1540), "Thank you for your business.", font=font(SANS, 22), fill=MUTED)

    expected = {
        "vendor_name": "Northwind Analytics Pvt Ltd",
        "invoice_number": "INV-2026-0417",
        "invoice_date": "2026-10-03",
        "due_date": "2026-11-02",
        "bill_to": "Lumen Retail LLP",
        "currency": "INR",
        "subtotal": subtotal,
        "tax": tax,
        "total": total,
        "line_items": [{"description": desc, "amount": qty * rate} for desc, qty, rate in items],
    }
    return img, expected


def receipt():
    items = [("TOMATO 1KG", 48.00), ("BASMATI RICE 5KG", 612.00), ("AMUL BUTTER 500G", 285.00),
             ("BANANA 12PC", 72.00), ("DISH SOAP 750ML", 149.00), ("BREAD BROWN", 55.00)]
    img = Image.new("RGB", (560, 1180), (250, 249, 244))
    d = ImageDraw.Draw(img)
    mono = lambda s: font(MONO, s)
    y = 30
    for text, size in [("GREEN BASKET MART", 34), ("Shop 14, Linking Road", 20), ("Bandra West, Mumbai 400050", 20), ("GSTIN 27AAGFG1234M1Z2", 20)]:
        w = d.textlength(text, font=mono(size))
        d.text(((560 - w) / 2, y), text, font=mono(size), fill=INK)
        y += size + 14
    y += 10
    d.text((30, y), "Date: 08/11/2026   Time: 18:42", font=mono(22), fill=INK)
    y += 34
    d.text((30, y), "Bill No: GB-118274  Cashier: 07", font=mono(22), fill=INK)
    y += 40
    d.text((30, y), "-" * 38, font=mono(22), fill=INK)
    y += 34
    subtotal = 0
    for name, price in items:
        subtotal += price
        d.text((30, y), name, font=mono(22), fill=INK)
        d.text((410, y), f"{price:>8.2f}", font=mono(22), fill=INK)
        y += 34
    tax = round(subtotal * 0.05, 2)
    total = round(subtotal + tax, 2)
    d.text((30, y), "-" * 38, font=mono(22), fill=INK)
    y += 34
    for label, value in [("SUBTOTAL", subtotal), ("GST 5%", tax), ("TOTAL", total)]:
        d.text((30, y), label, font=mono(26 if label == "TOTAL" else 22), fill=INK)
        d.text((390, y), f"{value:>8.2f}", font=mono(26 if label == "TOTAL" else 22), fill=INK)
        y += 38
    y += 10
    d.text((30, y), "PAID BY UPI  REF 6301 8827 1145", font=mono(22), fill=INK)
    y += 50
    d.text((120, y), "THANK YOU! VISIT AGAIN", font=mono(22), fill=INK)

    # Thermal print fades unevenly.
    img = ImageEnhance.Contrast(img).enhance(0.75)

    expected = {
        "merchant_name": "Green Basket Mart",
        "date": "2026-11-08",
        "time": "18:42",
        "subtotal": subtotal,
        "tax": tax,
        "total": total,
        "payment_method": "UPI",
        "items": [{"description": n, "amount": p} for n, p in items],
    }
    return img, expected


# --------------------------------------------------------------------------- write

def main():
    front, back, expected = id_card()
    save("id-card-scan", {"front": (front, "png"), "back": (back, "png")}, {
        "title": "ID card, clean scan", "type": "id_card", "tags": ["front-back", "scan"],
        "notes": "Address and issue date are only on the back.", "expected": expected})

    save("id-card-photo", {"front": (photograph(front, seed=1), "jpg"), "back": (photograph(back, angle=-4, seed=2), "jpg")}, {
        "title": "ID card, phone photo", "type": "id_card", "tags": ["front-back", "photo", "skew", "blur"],
        "notes": "Same card as id-card-scan: perspective, rotation, blur, uneven light, JPEG q55.",
        "_jpeg_quality": 55, "expected": expected})

    save("id-card-back-rotated", {"front": (front, "png"), "back": (back.rotate(90, expand=True), "png")}, {
        "title": "ID card, back rotated 90°", "type": "id_card", "tags": ["front-back", "rotated"],
        "notes": "Same card as id-card-scan with the back uploaded sideways.", "expected": expected})

    front, back, expected = resident_card_bilingual()
    save("resident-card-hindi", {"front": (front, "png"), "back": (back, "png")}, {
        "title": "Resident card, Hindi + English", "type": "id_card", "tags": ["front-back", "bilingual", "devanagari"],
        "notes": "Labels in Hindi and English; sex is printed as 'महिला / Female'.", "expected": expected})

    front, back, expected = driving_licence()
    save("driving-licence", {"front": (front, "png"), "back": (back, "png")}, {
        "title": "Driving licence", "type": "driving_licence", "tags": ["front-back", "numbered-fields", "table"],
        "notes": "EU-style numbered fields; categories and restriction code on the back.", "expected": expected})

    front, back, expected = business_card()
    save("business-card", {"front": (front, "png"), "back": (back, "png")}, {
        "title": "Business card", "type": "business_card", "tags": ["front-back", "dark-background"],
        "notes": "Name and title on a dark front; all contact details on the back.", "expected": expected})

    img, expected = invoice()
    save("invoice", {"front": (img, "png")}, {
        "title": "Tax invoice", "type": "invoice", "tags": ["single-page", "table", "currency-symbol"],
        "notes": "A4, four line items, ₹ amounts with thousands separators.", "expected": expected})

    img, expected = receipt()
    save("receipt-photo", {"front": (photograph(img, skew=0.04, angle=-2.5, blur=0.9, seed=3), "jpg")}, {
        "title": "Grocery receipt, phone photo", "type": "receipt", "tags": ["single-page", "photo", "thermal", "monospace"],
        "notes": "Faded thermal print, photographed at an angle.", "_jpeg_quality": 65, "expected": expected})

    print("raqm:", features.check("raqm"))


if __name__ == "__main__":
    main()
