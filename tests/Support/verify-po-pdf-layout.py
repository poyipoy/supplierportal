"""Read rendered pages at the same scale and record fixed form border positions."""
import json
import re
import subprocess
from pathlib import Path
from PIL import Image, ImageDraw

root = Path(__file__).resolve().parents[2] / "UI-REDESIGN-RESULT/PO-PDF-SAMPLES"
regions = {
    "table_header": (11.5, 78, 198.5, 89),
    "ship_to_box": (11, 240, 97.5, 276),
    "signature_box": (103, 240, 199, 277),
}


def borders(path, region, axis="y"):
    im = Image.open(path).convert("L")
    scale = im.width / 210
    x1, y1, x2, y2 = [round(value * scale) for value in region]
    groups = []
    coordinates = range(y1, y2) if axis == "y" else range(x1, x2)
    for coordinate in coordinates:
        samples = [(x, coordinate) for x in range(x1, x2)] if axis == "y" else [(coordinate, y) for y in range(y1, y2)]
        if sum(im.getpixel(pixel) < 210 for pixel in samples) <= len(samples) * 0.9:
            continue
        if not groups or coordinate > groups[-1][-1] + 1:
            groups.append([coordinate])
        else:
            groups[-1].append(coordinate)
    return [sum(group) / len(group) / scale for group in groups]


evidence = {}
for name, region in regions.items():
    reference = borders(root / "reference-1.png", region)
    actual = borders(root / "single-1.png", region)
    assert len(reference) == len(actual), (name, reference, actual)
    errors = [abs(a - b) for a, b in zip(reference, actual)]
    assert max(errors) <= 1, (name, errors)
    evidence[name] = {
        "reference_y_mm": [round(x, 3) for x in reference],
        "actual_y_mm": [round(x, 3) for x in actual],
        "max_error_mm": round(max(errors), 3),
    }

for name, region in {"table_columns": (9, 80, 201, 82), "ship_to_sides": (9, 251, 100, 270), "signature_columns": (100, 251, 201, 270)}.items():
    reference = borders(root / "reference-1.png", region, "x")
    actual = borders(root / "single-1.png", region, "x")
    assert len(reference) == len(actual), (name, reference, actual)
    errors = [abs(a - b) for a, b in zip(reference, actual)]
    assert max(errors) <= 1, (name, errors)
    evidence[name] = {
        "reference_x_mm": [round(x, 3) for x in reference],
        "actual_x_mm": [round(x, 3) for x in actual],
        "max_error_mm": round(max(errors), 3),
    }

evidence["coverage"] = "Fixed form borders and columns; dynamic content and approved data changes require separate visual/content review."
(root / "layout-measurements.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
print(json.dumps(evidence, indent=2))

content = {}
for name, expected_pages in [("po-reference-layout", 1), ("po-multiple-pages", 6), ("po-long-content", 5)]:
    text = subprocess.check_output(["pdftotext", "-enc", "UTF-8", "-layout", str(root / (name + ".pdf")), "-"]).decode("utf-8")
    pages = text.split("\f")
    if not pages[-1].strip():
        pages.pop()
    assert len(pages) == expected_pages, name
    for label in ["Ship to Address", "Payment Term"]:
        assert sum(label in page for page in pages) == 1, (name, label)
        assert label in pages[-1], (name, label)
    for index, page in enumerate(pages):
        assert re.search(r"Page\s+" + str(index + 1) + r"\s+of\s+" + str(expected_pages), page), (name, index)
    assert "6.00 pcs" in text and "Weight: 60.00 kg" in text, name
    assert "105,500.00" in text, name
    assert "10,550.00" not in text, "Stored price/kg must not become the printed price/pcs."
    if name == "po-multiple-pages":
        for number in range(1, 61):
            assert len(re.findall(r"Ordered material\s+" + format(number, "03d") + r"\b", text)) == 1, number
        assert "37,980,000.00" in pages[-1]
    if name == "po-long-content":
        assert "FINAL_MATERIAL_MARKER" in text and "FINAL_NOTE_MARKER" in text
        assert text.count("Special") == 150, text.count("Special")
    content[name] = {"pages": expected_pages, "closing_last_only": True, "page_numbering": True, "quantity_unit": "pcs", "unit_price_basis": "per pcs", "weight_in_description": True, "content_checks": "passed"}
(root / "content-verification.json").write_text(json.dumps(content, indent=2) + "\n", encoding="utf-8")
print(json.dumps(content, indent=2))

for prefix in ["multi", "long"]:
    files = sorted(root.glob(prefix + "-[0-9]*.png"))
    canvas = Image.new("RGB", (1500, 750 * ((len(files) + 2) // 3)), "#eeeeee")
    for index, path in enumerate(files):
        image = Image.open(path).convert("RGB")
        image.thumbnail((490, 710))
        x, y = (index % 3) * 500 + 5, (index // 3) * 750 + 25
        canvas.paste(image, (x, y))
        ImageDraw.Draw(canvas).text((x, y - 20), path.name, fill="black")
    canvas.save(root / (prefix + "-contact-sheet.png"))

reference = Image.open(root / "reference-1.png").convert("RGB")
actual = Image.open(root / "single-1.png").convert("RGB")
canvas = Image.new("RGB", (reference.width * 2, reference.height + 30), "white")
canvas.paste(reference, (0, 30))
canvas.paste(actual, (reference.width, 30))
draw = ImageDraw.Draw(canvas)
draw.text((10, 10), "REFERENCE PNR261178", fill="black")
draw.text((reference.width + 10, 10), "IMPLEMENTED PO FORM - APPROVED DATA ADAPTATIONS", fill="black")
canvas.save(root / "side-by-side.png")
