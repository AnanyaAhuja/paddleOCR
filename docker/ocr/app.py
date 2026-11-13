"""Small HTTP wrapper around the PaddleOCR Python SDK.

Laravel (PHP) can't call the Python SDK directly, so this service exposes it
over HTTP: POST an image to /ocr and get the recognised text back as JSON.

With card=1 the ID card is first cut out of the photo, flattened and turned
upright (card mask), and the text is read from that card. With zones, each
zone of the card (fractions of its width and height) is also cut out,
enlarged and read on its own (field masks), for small print.
"""

import base64
import json
import os
import threading
from pathlib import Path

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from paddleocr import PaddleOCR

from card import find_card

ALLOWED_SUFFIXES = {".png", ".jpg", ".jpeg", ".bmp", ".webp", ".tif", ".tiff"}
MAX_BYTES = 10 * 1024 * 1024  # 10 MB

# Page-orientation correction (pages uploaded at 90/180/270 degrees):
#   auto - plain pass first; retry with correction only when it reads badly
#   1    - always correct (about twice the OCR time under emulation)
#   0    - never correct
ORIENTATION = os.getenv("OCR_DOC_ORIENTATION", "auto")

# Average confidence of text lines below which a page is retried with
# orientation correction. Measured on the fixtures: upright pages 0.90-1.00,
# pages turned 90 or 180 degrees 0.37-0.54.
RETRY_BELOW = float(os.getenv("OCR_RETRY_BELOW", "0.75"))


def build_ocr() -> PaddleOCR:
    # Mobile models keep memory and CPU use low, which suits a POC in Docker.
    return PaddleOCR(
        lang=os.getenv("OCR_LANG", "en"),
        text_detection_model_name=os.getenv("OCR_DET_MODEL", "PP-OCRv5_mobile_det"),
        text_recognition_model_name=os.getenv("OCR_REC_MODEL", "en_PP-OCRv5_mobile_rec"),
        # Loaded unless switched off; each call decides whether to use it.
        use_doc_orientation_classify=ORIENTATION != "0",
        use_doc_unwarping=False,
        use_textline_orientation=False,
        # oneDNN is PaddlePaddle's fast CPU backend. 3.3.x crashes with it on
        # these models ("ConvertPirAttribute2RuntimeAttribute not support").
        enable_mkldnn=os.getenv("OCR_ENABLE_MKLDNN", "0") == "1",
    )


app = FastAPI(title="PaddleOCR service")
ocr = build_ocr()
ocr_lock = threading.Lock()  # the predictor is not safe to share across threads


ZONE_SCALE = 2  # zones are enlarged before reading: small print reads better


def read(image: np.ndarray, correct_orientation: bool) -> dict:
    """OCR one image. "upright" is the image as read (turned when corrected)."""
    lines, angle, upright = [], 0, image
    for page in ocr.predict(image, use_doc_orientation_classify=correct_orientation):
        preprocessed = page.get("doc_preprocessor_res")
        if correct_orientation and preprocessed:
            angle, upright = int(preprocessed["angle"]), preprocessed["output_img"]
        for text, score, box in zip(page["rec_texts"], page["rec_scores"], page["rec_boxes"]):
            lines.append({
                "text": text,
                "confidence": round(float(score), 4),
                "box": [int(v) for v in box],  # [x_min, y_min, x_max, y_max]
            })

    # Short fragments (1-2 characters) are noisy either way, so leave them out.
    words = [line["confidence"] for line in lines if len(line["text"].strip()) >= 3]
    return {
        "text": "\n".join(line["text"] for line in lines),
        "lines": lines,
        "confidence": round(sum(words) / len(words), 4) if words else 0.0,
        "angle": angle,
        "upright": upright,
    }


def read_page(image: np.ndarray) -> dict:
    """The whole photo, with orientation correction as configured."""
    result = read(image, correct_orientation=ORIENTATION == "1")
    result["orientation_retried"] = False

    if ORIENTATION == "auto" and result["confidence"] < RETRY_BELOW:
        retry = read(image, correct_orientation=True)
        # Keep whichever pass read better.
        if retry["confidence"] > result["confidence"]:
            result = retry
        result["orientation_retried"] = True
    return result


def read_zones(card: np.ndarray, zones: list[dict]) -> dict:
    h, w = card.shape[:2]
    out = {}
    for zone in zones:
        x0, y0, x1, y1 = zone["box"]
        crop = card[max(0, int(y0 * h)):int(y1 * h), max(0, int(x0 * w)):int(x1 * w)]
        if crop.size == 0:
            continue
        crop = cv2.resize(crop, None, fx=ZONE_SCALE, fy=ZONE_SCALE, interpolation=cv2.INTER_CUBIC)
        result = read(crop, correct_orientation=False)
        out[zone["name"]] = {"text": result["text"], "confidence": result["confidence"]}
    return out


def jpeg_base64(image: np.ndarray) -> str:
    ok, data = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 90])
    return base64.b64encode(data.tobytes()).decode() if ok else ""


def parse_zones(raw: str) -> list[dict]:
    if not raw:
        return []
    try:
        zones = json.loads(raw)
        assert isinstance(zones, list)
        for zone in zones:
            assert isinstance(zone["name"], str) and len(zone["box"]) == 4
            assert all(0 <= float(v) <= 1 for v in zone["box"])
        return zones
    except (ValueError, KeyError, TypeError, AssertionError):
        raise HTTPException(status_code=422, detail='zones must be a JSON list of {"name": str, "box": [x0, y0, x1, y1]} with fractions 0-1.')


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
def run_ocr(file: UploadFile = File(...), card: bool = Form(False), zones: str = Form("")):
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in ALLOWED_SUFFIXES:
        raise HTTPException(status_code=422, detail=f"Unsupported file type '{suffix}'.")

    data = file.file.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise HTTPException(status_code=413, detail="File is larger than 10 MB.")

    image = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise HTTPException(status_code=422, detail="Could not read the image.")
    zone_list = parse_zones(zones)

    with ocr_lock:
        found, how = find_card(image) if card else (None, "")

        if found is None:
            result = read_page(image)
            if card:
                result["card"] = {"found": False, "reason": how}
        else:
            # Read the flat card, letting the classifier turn it upright.
            result = read(found.image, correct_orientation=True)
            result["orientation_retried"] = False
            result["card"] = {
                "found": True,
                "method": found.method,
                "corners": found.corners,
                "angle": result["angle"],
                "image": jpeg_base64(result["upright"]),
            }
            if zone_list:
                result["zones"] = read_zones(result["upright"], zone_list)

    del result["upright"], result["angle"]
    return result
