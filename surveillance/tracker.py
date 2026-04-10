from deep_sort_realtime.deepsort_tracker import DeepSort

# -------------------------------------------------------------------
# Existing tracker (Camera A / check-in side – DO NOT MODIFY)
# -------------------------------------------------------------------

def init_tracker():
    return DeepSort(
        max_age=120,
        n_init=1,
        max_cosine_distance=0.4,
        embedder="mobilenet",
        half=True,
        bgr=True
    )

def update_tracks_person(deepsort, person_dets, frame):
    dets = []
    for d in person_dets:
        x1, y1, x2, y2 = d["x1"], d["y1"], d["x2"], d["y2"]
        w = max(1.0, x2 - x1)
        h = max(1.0, y2 - y1)
        dets.append(([x1, y1, w, h], float(d["score"]), "person"))
    return deepsort.update_tracks(dets, frame=frame)


# -------------------------------------------------------------------
# Activity tracker (Camera B / surveillance side)
# -------------------------------------------------------------------

def init_activity_tracker(max_age: int = 60, n_init: int = 2) -> DeepSort:
    """DeepSORT instance tuned for Camera-B activity monitoring.

    Parameters
    ----------
    max_age:
        Frames to keep a track alive without a matching detection.
        Should roughly match ``track_max_missing_frames`` in settings.yaml.
    n_init:
        Detections required before a track is confirmed (shown on screen).
    """
    return DeepSort(
        max_age=max_age,
        n_init=n_init,
        max_cosine_distance=0.4,
        embedder="mobilenet",
        half=True,
        bgr=True,
    )


def update_tracks_activity(deepsort: DeepSort, activity_dets: list, frame) -> list:
    """Update DeepSORT with detections from the YOLO activity model.

    All detections are passed as class ``"person"`` because DeepSORT uses
    appearance + IoU for tracking, not class labels.  Activity labels are
    carried in ``activity_dets`` and matched back to confirmed tracks inside
    ``main_surveillance.py``.

    Parameters
    ----------
    deepsort:
        DeepSort instance (from ``init_activity_tracker``).
    activity_dets:
        List of dicts with keys ``x1, y1, x2, y2, score, activity``.
    frame:
        Current BGR frame (numpy array) for appearance embedding.
    """
    dets = []
    for d in activity_dets:
        x1, y1, x2, y2 = d["x1"], d["y1"], d["x2"], d["y2"]
        w = max(1.0, x2 - x1)
        h = max(1.0, y2 - y1)
        dets.append(([x1, y1, w, h], float(d["score"]), "person"))
    return deepsort.update_tracks(dets, frame=frame)