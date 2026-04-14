"""
tracker.py
==========
DeepSORT initialisation and helper utilities used by attendance_webcam.py.

Public API
----------
init_tracker()              — create and return a configured DeepSort instance
update_tracks(...)          — push a frame's detections into DeepSORT
get_centroid(bbox_dict)     — (x, y) centre of a bounding-box dict
centroid_distance(b1, b2)   — Euclidean distance between two bbox centres
find_closest_detection(...) — nearest detection to a given track bbox
get_stable_label(deque)     — majority-voted identity label from a vote deque
"""

from __future__ import annotations

from collections import Counter
from typing import Any, Dict, List, Optional, Sequence, Tuple, Union

import numpy as np
from deep_sort_realtime.deepsort_tracker import DeepSort

from config import config
from logger import logger


def init_tracker() -> DeepSort:
    """Create and return a DeepSort tracker configured from config.py."""
    return DeepSort(
        max_age=config["tracking"]["max_age"],
        n_init=3,
        nms_max_overlap=1.0,
        max_cosine_distance=0.4,
        nn_budget=100,
        embedder=None,
        half=True,
    )


def update_tracks(
    deepsort: DeepSort,
    detections: List[Dict[str, Any]],
    frame: Optional[np.ndarray] = None,
) -> list:
    """Push *detections* into *deepsort* and return the updated track list.

    Parameters
    ----------
    deepsort:
        A DeepSort instance returned by :func:`init_tracker`.
    detections:
        List of dicts with keys ``x1``, ``y1``, ``x2``, ``y2``,
        ``score``, and ``embedding``.  Entries missing ``embedding``
        are silently skipped.
    frame:
        Optional BGR frame passed to DeepSORT for visual re-ID (unused when
        ``embedder=None``).

    Returns
    -------
    list
        Active DeepSORT tracks after the update.
    """
    dets: List[Tuple[List[float], float, str]] = []
    embeds: List[np.ndarray] = []

    for d in detections:
        if not isinstance(d, dict):
            logger.warning("Invalid detection format: %s", d)
            continue

        emb = d.get("embedding")
        if emb is None:
            continue

        x1, y1, x2, y2 = d["x1"], d["y1"], d["x2"], d["y2"]
        score = float(d["score"])
        ltwh = [float(x1), float(y1), float(x2 - x1), float(y2 - y1)]
        dets.append((ltwh, score, "face"))
        embeds.append(np.asarray(emb, dtype=np.float32).reshape(-1))

    if not dets:
        return []

    return deepsort.update_tracks(dets, embeds=embeds, frame=frame)


# ── Geometry helpers ─────────────────────────────────────────────────────────

def get_centroid(bbox_dict: Dict[str, float]) -> Tuple[float, float]:
    """Return the (x, y) centre of a bounding-box dict."""
    x1, y1, x2, y2 = bbox_dict["x1"], bbox_dict["y1"], bbox_dict["x2"], bbox_dict["y2"]
    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0)


def centroid_distance(
    bbox1: Dict[str, float],
    bbox2: Dict[str, float],
) -> float:
    """Euclidean pixel distance between the centres of two bounding boxes."""
    c1 = get_centroid(bbox1)
    c2 = get_centroid(bbox2)
    return float(np.sqrt((c1[0] - c2[0]) ** 2 + (c1[1] - c2[1]) ** 2))


def find_closest_detection(
    track_bbox: Dict[str, float],
    detections: Sequence[Union[Dict[str, float], Sequence[float]]],
) -> Optional[Any]:
    """Return the detection in *detections* whose centre is nearest to *track_bbox*.

    Accepts detections as either dicts (``x1``/``y1``/``x2``/``y2`` keys) or
    4-element sequences ``[x1, y1, x2, y2]``.  Returns ``None`` when
    *detections* is empty or all entries have an unrecognised format.
    """
    min_dist = float("inf")
    closest_det = None
    for det in detections:
        if isinstance(det, dict):
            det_bbox: Dict[str, float] = {
                "x1": det["x1"], "y1": det["y1"],
                "x2": det["x2"], "y2": det["y2"],
            }
        elif isinstance(det, (list, tuple)) and len(det) >= 4:
            det_bbox = {"x1": det[0], "y1": det[1], "x2": det[2], "y2": det[3]}
        else:
            continue
        dist = centroid_distance(track_bbox, det_bbox)
        if dist < min_dist:
            min_dist = dist
            closest_det = det
    return closest_det


# ── Label stabilisation ──────────────────────────────────────────────────────

def get_stable_label(votes_deque: Sequence[str]) -> Tuple[str, int]:
    """Return the majority-voted identity label from a rolling vote deque.

    Parameters
    ----------
    votes_deque:
        Sequence of per-frame identity predictions (e.g. ``"Amir"`` or
        ``"Unknown"``).

    Returns
    -------
    (label, hit_count)
        ``("Unknown", 0)`` when no identity reaches *min_hits* or when
        more than 60 % of votes are ``"Unknown"``.
    """
    min_hits = config["tuning"]["min_hits"]
    if not votes_deque:
        return "Unknown", 0

    unknown_ratio = votes_deque.count("Unknown") / len(votes_deque)
    if unknown_ratio > 0.6:
        return "Unknown", 0

    recognized = [v for v in votes_deque if v != "Unknown"]
    for label, count in Counter(recognized).most_common():
        if count >= min_hits:
            return label, count
    return "Unknown", 0