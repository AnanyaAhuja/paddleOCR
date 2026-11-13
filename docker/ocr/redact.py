"""Privacy mask: a copy of a document photo with personal data blacked out.

Default deny: every line of text OCR found is covered, except lines that read
as one of the printed labels the caller lists as safe to show ("COGNOME /
SURNAME"). A label OCR misread is covered too, so mistakes err on the side of
hiding. Faces (the holder's photo, also the small ghost photo on the back)
and barcodes (they encode the fiscal code) are covered as well.

Faces are found with OpenCV's YuNet model (downloaded at image build); the
classic Haar detector both missed small faces and matched textured
backgrounds.
"""

import os
import re
from difflib import SequenceMatcher

import cv2
import numpy as np

LABEL_SIMILARITY = 0.85  # how close a line must read to a listed label
PAD = 4  # pixels around each covered area
FACE_GROW = 0.35  # faces: also cover hair and chin
FACE_SIDE = 1600  # faces are searched on a copy at most this big
FACE_MODEL = os.getenv("FACE_MODEL", "/app/models/face_detection_yunet_2023mar.onnx")

_faces = cv2.FaceDetectorYN.create(FACE_MODEL, "", (320, 320), score_threshold=0.6, nms_threshold=0.3, top_k=50)
_barcodes = cv2.barcode.BarcodeDetector()


def redact(image: np.ndarray, lines: list[dict], keep: list[str]) -> tuple[np.ndarray, dict]:
    """lines: [{"text": str, "poly": [[x, y] x 4]}] in the coordinates of image."""
    out = image.copy()
    labels = [_letters(label) for label in keep if _letters(label)]
    counts = {"text": 0, "kept_labels": 0, "faces": 0, "barcodes": 0}

    for line in lines:
        if _is_label(line["text"], labels):
            counts["kept_labels"] += 1
            continue
        _cover(out, np.array(line["poly"], dtype=np.float32))
        counts["text"] += 1

    for x, y, w, h in _find_faces(image):
        gx, gy = w * FACE_GROW, h * FACE_GROW
        _cover(out, np.array([[x - gx, y - gy], [x + w + gx, y - gy], [x + w + gx, y + h + gy], [x - gx, y + h + gy]], dtype=np.float32))
        counts["faces"] += 1

    for quad in _find_barcodes(image):
        _cover(out, quad)
        counts["barcodes"] += 1

    return out, counts


def _letters(text: str) -> str:
    return re.sub(r"[^A-Z]", "", text.upper())


def _is_label(text: str, labels: list[str]) -> bool:
    letters = _letters(text)
    # Lines with digits are values (dates, numbers), never labels.
    if not letters or re.search(r"\d", text):
        return False
    return any(SequenceMatcher(None, letters, label).ratio() >= LABEL_SIMILARITY for label in labels)


def _cover(image: np.ndarray, quad: np.ndarray) -> None:
    center = quad.mean(axis=0)
    # Grow the shape by PAD pixels away from its centre.
    direction = quad - center
    length = np.linalg.norm(direction, axis=1, keepdims=True)
    grown = quad + direction / np.maximum(length, 1) * PAD * 1.5
    cv2.fillPoly(image, [grown.round().astype(np.int32)], (0, 0, 0))


def _find_faces(image: np.ndarray) -> list[tuple[int, int, int, int]]:
    """Face boxes in the image's coordinates, also for photos turned 90/180/270."""
    h, w = image.shape[:2]
    scale = min(1.0, FACE_SIDE / max(h, w))
    small = cv2.resize(image, (round(w * scale), round(h * scale)), interpolation=cv2.INTER_AREA) if scale < 1 else image
    sh, sw = small.shape[:2]
    found = []

    for turns in range(4):
        rotated = np.ascontiguousarray(np.rot90(small, turns))
        _faces.setInputSize((rotated.shape[1], rotated.shape[0]))
        _, faces = _faces.detect(rotated)
        for face in faces if faces is not None else []:
            x, y, fw, fh = face[:4]
            corners = np.array([[x, y], [x + fw, y], [x + fw, y + fh], [x, y + fh]], dtype=np.float32)
            corners = _unrotate(corners, turns, sw, sh) / scale
            xs, ys = corners[:, 0], corners[:, 1]
            found.append((int(xs.min()), int(ys.min()), int(xs.max() - xs.min()), int(ys.max() - ys.min())))
    return found


def _unrotate(points: np.ndarray, turns: int, w: int, h: int) -> np.ndarray:
    """Points in np.rot90(image, turns) back to the image (width w, height h)."""
    for done in range(turns, 0, -1):
        # np.rot90 turns counter-clockwise: (x, y) came from (w' - 1 - y, x),
        # where w' is the width of the image before that turn.
        before_width = w if (done - 1) % 2 == 0 else h
        points = np.stack([before_width - 1 - points[:, 1], points[:, 0]], axis=1)
    return points


def _find_barcodes(image: np.ndarray) -> list[np.ndarray]:
    try:
        ok, points = _barcodes.detect(image)
    except cv2.error:
        return []
    return [quad.astype(np.float32) for quad in points] if ok and points is not None else []
