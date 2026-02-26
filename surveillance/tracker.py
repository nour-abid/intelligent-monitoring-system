from deep_sort_realtime.deepsort_tracker import DeepSort

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