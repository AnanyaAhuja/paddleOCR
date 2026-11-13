"""Card mask: find an ID-1 card (85.6 x 54 mm) in a photo or scan, and cut it
out as a flat, straight rectangle.

Two ways of finding the card are tried and the best rectangle wins:
  - edges: the card's outline in an edge map (photos on a desk, table, wood);
  - background: pixels that differ from the photo's border colour (a card on
    a scanned white page, whose edges are faint).
A photo that is already just the card is used as it is.

Only geometry here; the caller decides whether the card is upside down.
"""

from dataclasses import dataclass

import cv2
import numpy as np

CARD_RATIO = 85.6 / 54.0  # ID-1 width / height
RATIO_RANGE = (1.35, 1.75)  # allows for some perspective
FULL_RATIO_RANGE = (1.48, 1.70)  # a photo that is just the card
MIN_AREA = 0.03  # of the image: smaller than this is not the card
MAX_AREA = 0.85  # bigger is the photo itself (cards filling the photo use "full")
OUT_WIDTH = 1400
OUT_HEIGHT = round(OUT_WIDTH / CARD_RATIO)
DETECT_SIDE = 1000  # detection runs on a downscaled copy


@dataclass
class Card:
    image: np.ndarray  # flat card, landscape, OUT_WIDTH x OUT_HEIGHT
    method: str  # "edges", "background" or "full"
    corners: list  # [[x, y] x 4] in the original image: tl, tr, br, bl
    score: float


def find_card(image: np.ndarray) -> tuple[Card | None, str]:
    """The card, or None and why not ("not found", "several cards")."""
    h, w = image.shape[:2]
    scale = DETECT_SIDE / max(h, w) if max(h, w) > DETECT_SIDE else 1.0
    small = cv2.resize(image, (round(w * scale), round(h * scale)), interpolation=cv2.INTER_AREA) if scale < 1 else image

    candidates = [(quad, score, "edges") for quad, score in _edge_candidates(small)]
    candidates += [(quad, score, "background") for quad, score in _background_candidates(small)]

    if candidates:
        quad, score, method = max(candidates, key=lambda c: c[1])
        # A scan can hold both sides, or another card next to the ID card.
        # Cutting out one would lose the other, so read the page whole.
        if _another_card(quad, score, candidates):
            return None, "several cards"
        corners = _order(quad / scale)
        return Card(_warp(image, corners), method, corners.round().astype(int).tolist(), round(score, 3)), method

    # Already cropped to the card: the whole image has the card's shape. Kept
    # tight so phone photos (16:9 = 1.78) and A4 scans (1.41) don't qualify.
    ratio = max(w, h) / min(w, h)
    if FULL_RATIO_RANGE[0] <= ratio <= FULL_RATIO_RANGE[1]:
        corners = np.array([[0, 0], [w - 1, 0], [w - 1, h - 1], [0, h - 1]], dtype=np.float32)
        return Card(_warp(image, _order(corners)), "full", corners.astype(int).tolist(), 0.0), "full"

    return None, "not found"


def _another_card(best: np.ndarray, best_score: float, candidates) -> bool:
    """Is there a second card-sized outline that doesn't overlap the best one?"""
    best_area = cv2.contourArea(best)
    outline = best.reshape(-1, 1, 2)
    for quad, score, _ in candidates:
        area = cv2.contourArea(quad)
        if score < 0.5 * best_score or not 0.5 * best_area <= area <= 2 * best_area:
            continue
        center = quad.mean(axis=0)
        if cv2.pointPolygonTest(outline, (float(center[0]), float(center[1])), False) < 0:
            return True
    return False


def _edge_candidates(image: np.ndarray):
    gray = cv2.GaussianBlur(cv2.cvtColor(image, cv2.COLOR_BGR2GRAY), (5, 5), 0)
    found = []
    # Faint and strong outlines; closing bridges gaps in a broken outline.
    for low, high in ((30, 90), (60, 180)):
        edges = cv2.dilate(cv2.Canny(gray, low, high), np.ones((3, 3), np.uint8), iterations=2)
        found += _quads(edges, image.shape)
        closed = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, np.ones((9, 9), np.uint8), iterations=2)
        found += _quads(closed, image.shape, filled=True)
    return found


def _background_candidates(image: np.ndarray):
    # Background colour = median of a thin frame around the image.
    blurred = cv2.GaussianBlur(image, (9, 9), 0)
    lab = cv2.cvtColor(blurred, cv2.COLOR_BGR2LAB).astype(np.float32)
    b = max(4, min(image.shape[:2]) // 40)
    frame = np.concatenate([lab[:b].reshape(-1, 3), lab[-b:].reshape(-1, 3), lab[:, :b].reshape(-1, 3), lab[:, -b:].reshape(-1, 3)])
    distance = np.linalg.norm(lab - np.median(frame, axis=0), axis=2)

    found = []
    # A light card on white paper differs by only a few units; a card on
    # wood needs a higher cut so the grain stays background. Try both.
    for cut in (12, 25):
        mask = (distance > cut).astype(np.uint8) * 255
        mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15)), iterations=2)
        mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((7, 7), np.uint8))
        found += _quads(mask, image.shape, filled=True)
    return found


def _quads(binary: np.ndarray, shape, filled: bool = False):
    """Card-shaped rectangles among the outlines in a binary image, scored."""
    h, w = shape[:2]
    mode = cv2.RETR_EXTERNAL if filled else cv2.RETR_LIST
    contours, _ = cv2.findContours(binary, mode, cv2.CHAIN_APPROX_SIMPLE)
    found = []

    for contour in contours:
        area = cv2.contourArea(contour)
        if area < MIN_AREA * w * h or area > MAX_AREA * w * h:
            continue

        rect = cv2.minAreaRect(contour)
        (rw, rh) = rect[1]
        if min(rw, rh) == 0:
            continue
        ratio = max(rw, rh) / min(rw, rh)
        if not RATIO_RANGE[0] <= ratio <= RATIO_RANGE[1]:
            continue

        # How well the outline fills its rectangle: a card is close to 1.
        fill = area / (rw * rh)
        if fill < 0.80:
            continue

        approx = cv2.approxPolyDP(contour, 0.02 * cv2.arcLength(contour, True), True)
        quad = approx.reshape(-1, 2).astype(np.float32) if len(approx) == 4 and cv2.isContourConvex(approx) else cv2.boxPoints(rect)

        # Prefer big, card-shaped, well-filled outlines.
        score = (area / (w * h)) ** 0.5 * fill * (1 - abs(ratio - CARD_RATIO) / CARD_RATIO)
        found.append((quad.astype(np.float32), score))

    return found


def _order(quad: np.ndarray) -> np.ndarray:
    """Corners as tl, tr, br, bl, with the long side on top."""
    quad = np.asarray(quad, dtype=np.float32).reshape(4, 2)
    s, d = quad.sum(axis=1), np.diff(quad, axis=1).ravel()
    ordered = np.array([quad[np.argmin(s)], quad[np.argmin(d)], quad[np.argmax(s)], quad[np.argmax(d)]], dtype=np.float32)

    top = np.linalg.norm(ordered[1] - ordered[0])
    side = np.linalg.norm(ordered[3] - ordered[0])
    if side > top:  # card stands upright in the photo: turn it a quarter
        ordered = np.roll(ordered, -1, axis=0)
    return ordered


def _warp(image: np.ndarray, corners: np.ndarray) -> np.ndarray:
    target = np.array([[0, 0], [OUT_WIDTH - 1, 0], [OUT_WIDTH - 1, OUT_HEIGHT - 1], [0, OUT_HEIGHT - 1]], dtype=np.float32)
    matrix = cv2.getPerspectiveTransform(np.asarray(corners, dtype=np.float32), target)
    return cv2.warpPerspective(image, matrix, (OUT_WIDTH, OUT_HEIGHT), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
