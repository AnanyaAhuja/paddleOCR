"""Small HTTP wrapper around the PaddleOCR Python SDK.

Laravel (PHP) can't call the Python SDK directly, so this service exposes it
over HTTP: POST an image to /ocr and get the recognised text back as JSON.
"""

import os
import tempfile
import threading
from pathlib import Path

from fastapi import FastAPI, File, HTTPException, UploadFile
from paddleocr import PaddleOCR

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
    lines = []
    for page in ocr.predict(path, use_doc_orientation_classify=correct_orientation):
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
    }


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
def run_ocr(file: UploadFile = File(...)):
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

    return result
