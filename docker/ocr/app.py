"""Small HTTP wrapper around the PaddleOCR Python SDK.

Laravel (PHP) can't call the Python SDK directly, so this service exposes it
over HTTP: POST an image to /ocr and get the recognised text back as JSON.

With redact=1 it also returns a privacy-masked copy of the image (see
redact.py): all text except the labels listed in `keep`, faces and barcodes
blacked out, reusing the text boxes OCR just found.
"""

import base64
import json
import os
import tempfile
import threading
from pathlib import Path

import cv2
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from paddleocr import PaddleOCR

from redact import redact

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


def read(path: str, correct_orientation: bool) -> dict:
    lines, polys, turned = [], [], None
    for page in ocr.predict(path, use_doc_orientation_classify=correct_orientation):
        preprocessed = page.get("doc_preprocessor_res")
        if correct_orientation and preprocessed:
            turned = preprocessed["output_img"]  # the boxes refer to this image
        for text, score, box, poly in zip(page["rec_texts"], page["rec_scores"], page["rec_boxes"], page["rec_polys"]):
            lines.append({
                "text": text,
                "confidence": round(float(score), 4),
                "box": [int(v) for v in box],  # [x_min, y_min, x_max, y_max]
            })
            polys.append({"text": text, "poly": [[int(x), int(y)] for x, y in poly]})

    # Short fragments (1-2 characters) are noisy either way, so leave them out.
    words = [line["confidence"] for line in lines if len(line["text"].strip()) >= 3]
    return {
        "text": "\n".join(line["text"] for line in lines),
        "lines": lines,
        "confidence": round(sum(words) / len(words), 4) if words else 0.0,
        # For redaction only, not returned.
        "_polys": polys,
        "_turned": turned,
    }


def parse_keep(raw: str) -> list[str]:
    if not raw:
        return []
    try:
        keep = json.loads(raw)
        assert isinstance(keep, list) and all(isinstance(label, str) for label in keep)
        return keep
    except (ValueError, AssertionError):
        raise HTTPException(status_code=422, detail="keep must be a JSON list of label strings.")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
def run_ocr(file: UploadFile = File(...), redact_image: bool = Form(False, alias="redact"), keep: str = Form("")):
    keep_labels = parse_keep(keep)
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in ALLOWED_SUFFIXES:
        raise HTTPException(status_code=422, detail=f"Unsupported file type '{suffix}'.")

    data = file.file.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise HTTPException(status_code=413, detail="File is larger than 10 MB.")

    with tempfile.NamedTemporaryFile(suffix=suffix) as tmp:
        tmp.write(data)
        tmp.flush()
        with ocr_lock:
            result = read(tmp.name, correct_orientation=ORIENTATION == "1")
            result["orientation_retried"] = False

            if ORIENTATION == "auto" and result["confidence"] < RETRY_BELOW:
                retry = read(tmp.name, correct_orientation=True)
                # Keep whichever pass read better.
                if retry["confidence"] > result["confidence"]:
                    result = retry
                result["orientation_retried"] = True

            if redact_image:
                image = result["_turned"] if result["_turned"] is not None else cv2.imread(tmp.name)
                masked, counts = redact(image, result["_polys"], keep_labels)
                ok, jpeg = cv2.imencode(".jpg", masked, [cv2.IMWRITE_JPEG_QUALITY, 85])
                result["redacted"] = {"image": base64.b64encode(jpeg.tobytes()).decode() if ok else "", "covered": counts}

    del result["_polys"], result["_turned"]
    return result
