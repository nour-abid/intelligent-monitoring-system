import numpy as np
from deep_sort_realtime.deepsort_tracker import DeepSort
from logger import logger

from deep_sort_realtime.deepsort_tracker import DeepSort

def init_tracker():
    from config import config
    return DeepSort(
        max_age=config['tracking']['max_age'],
        n_init=3,
        nms_max_overlap=1.0,
        max_cosine_distance=0.4,
        nn_budget=100,
        embedder=None,   # ✅ no internal embedder
        half=True
    )
# tracker.py
def update_tracks(deepsort, detections, frame=None):
    """
    detections: list of dicts with 'x1','y1','x2','y2','score','embedding'
    returns: DeepSORT tracks
    """
    dets = []
    embeds = []

    for d in detections:
        if not isinstance(d, dict):
            logger.warning(f"Invalid detection format: {d}")
            continue

        emb = d.get("embedding", None)
        if emb is None:
            continue

        x1, y1, x2, y2 = d["x1"], d["y1"], d["x2"], d["y2"]
        score = float(d["score"])

        ltwh = [float(x1), float(y1), float(x2 - x1), float(y2 - y1)]
        dets.append((ltwh, score, "face"))

        emb = np.asarray(emb, dtype=np.float32).reshape(-1)
        embeds.append(emb)

    if not dets:
        return []

    return deepsort.update_tracks(dets, embeds=embeds, frame=frame)


def get_centroid(bbox_dict):
    x1, y1, x2, y2 = bbox_dict["x1"], bbox_dict["y1"], bbox_dict["x2"], bbox_dict["y2"]
    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0)

def centroid_distance(bbox1, bbox2):
    c1 = get_centroid(bbox1)
    c2 = get_centroid(bbox2)
    return np.sqrt((c1[0] - c2[0]) ** 2 + (c1[1] - c2[1]) ** 2)

def find_closest_detection(track_bbox, detections):
    min_dist = float('inf')
    closest_det = None
    for det in detections:
        if isinstance(det, dict):
            det_bbox = {'x1': det['x1'], 'y1': det['y1'], 'x2': det['x2'], 'y2': det['y2']}
        elif isinstance(det, (list, tuple)) and len(det) >= 4:
            det_bbox = {'x1': det[0], 'y1': det[1], 'x2': det[2], 'y2': det[3]}
        else:
            continue
        dist = centroid_distance(track_bbox, det_bbox)
        if dist < min_dist:
            min_dist = dist
            closest_det = det
    return closest_det

def get_stable_label(votes_deque):
    from config import config
    min_hits = config['tuning']['min_hits']
    if not votes_deque:
        return "Unknown", 0
    recognized = [v for v in votes_deque if v != "Unknown"]
    unknown_ratio = votes_deque.count("Unknown") / len(votes_deque)

    if unknown_ratio > 0.6:
        return "Unknown", 0
    from collections import Counter
    label_counts = Counter(recognized)
    for label, count in label_counts.most_common():
        if count >= min_hits:
            return label, count
    return "Unknown", 0