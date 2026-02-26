import sys
import time
from pathlib import Path
from collections import deque

import cv2
import numpy as np
from ultralytics import YOLO
from insightface.app import FaceAnalysis

# --------- make imports work ----------
SCRIPT_DIR = Path(__file__).resolve().parent           # .../surveillance
PROJECT_ROOT = SCRIPT_DIR.parent                       # project root
SRC_DIR = PROJECT_ROOT / "src"

for p in (PROJECT_ROOT, SRC_DIR):
    sp = str(p)
    if sp not in sys.path:
        sys.path.insert(0, sp)

# Config + logger
try:
    from src.recognition.config import config
    from src.recognition.logger import logger
except Exception:
    config = {"camera": {"rtsp_url": ""}, "recognition": {"sim_threshold": 0.72, "margin": 0.08}}
    import logging
    logger = logging.getLogger("surveillance")
    logging.basicConfig(level=logging.INFO)

# Tracker wrapper
try:
    from surveillance.tracker import init_tracker, update_tracks_person
except Exception:
    from tracker import init_tracker, update_tracks_person  # fallback

# Face recognition helpers
from src.recognition.recognition import load_known_embeddings, match_identity


# ------------------------
# Utils: face -> track association
# ------------------------
def point_in_box(cx, cy, box):
    x1, y1, x2, y2 = box
    return (x1 <= cx <= x2) and (y1 <= cy <= y2)


def assign_face_to_track(face_bbox, track_boxes):
    cx = (face_bbox[0] + face_bbox[2]) / 2.0
    cy = (face_bbox[1] + face_bbox[3]) / 2.0

    best_tid = None
    best_area = None
    for tid, tbox in track_boxes.items():
        if point_in_box(cx, cy, tbox):
            area = max(1.0, (tbox[2] - tbox[0]) * (tbox[3] - tbox[1]))
            if best_area is None or area < best_area:
                best_area = area
                best_tid = tid
    return best_tid


# ------------------------
# RTSP helper
# ------------------------
def open_rtsp_capture(url: str):
    import os
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp"
    cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 5)  # a bit more stable
    if not cap.isOpened():
        raise RuntimeError(f"Failed to open RTSP stream: {url}")
    return cap


def main():
    # 1) RTSP source
    rtsp_url = config["camera"]["rtsp_url"]
    if not rtsp_url:
        raise SystemExit("❌ RTSP URL empty. Check config.yaml camera.rtsp_url")

    cap = open_rtsp_capture(rtsp_url)
    logger.info("✅ Surveillance RTSP opened")

    # RTSP recovery state
    consecutive_frame_fails = 0
    max_consecutive_fails = 15

    last_frame_sig = None
    same_frame_count = 0
    max_same_frames = 20

    # 2) YOLOv11 COCO model for PERSON detection
    yolo_person = YOLO("yolo11s.pt")
    logger.info("✅ YOLOv11 person detector loaded (yolo11s.pt)")

    # 3) DeepSORT tracker (FULL BODY)
    deepsort = init_tracker()
    logger.info("✅ DeepSORT initialized")

    # 4) Load known embeddings
    emb_dir = PROJECT_ROOT / "models" / "embeddings"
    known = load_known_embeddings(emb_dir)
    logger.info(f"✅ Loaded known identities: {len(known)} from {emb_dir}")

    # 5) Recognition thresholds from config
    SIM_THR = float(config["recognition"]["sim_threshold"])
    MARGIN_THR = float(config["recognition"]["margin"])
    logger.info(f"✅ Recognition thresholds: sim={SIM_THR} margin={MARGIN_THR}")

    # 6) InsightFace (face detect + embedding) - lighter settings
    logger.info("✅ Loading InsightFace (buffalo_l) ...")
    try:
        providers = ["CUDAExecutionProvider", "CPUExecutionProvider"]
        app = FaceAnalysis(name="buffalo_l", providers=providers)
        app.prepare(ctx_id=0, det_size=(480, 480))
        logger.info("✅ InsightFace CUDA OK")
    except Exception as e:
        logger.warning(f"CUDA not available for InsightFace: {e}, fallback CPU.")
        app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
        app.prepare(ctx_id=-1, det_size=(480, 480))
        logger.info("✅ InsightFace CPU OK")

    # ------------------------
    # Stable identity display (IMPORTANT)
    # ------------------------
    # Give each known person a fixed ID (stable)
    FIXED_EMPLOYEE_ID = {
        "Nour": "EMP_001",
        "Amir": "EMP_002",
        # add more...
    }

    # track_identity: per track vote smoothing (as before)
    track_identity = {}
    ID_WINDOW = int(config.get("tuning", {}).get("identity_window", 20))
    ID_MIN_HITS = int(config.get("tuning", {}).get("identity_min_hits", 6))
    IDENTITY_TIMEOUT_SEC = 15.0

    # identity_to_primary_tid: to avoid duplicates when track_id changes
    identity_to_primary_tid = {}  # name -> tid
    identity_last_seen = {}        # name -> timestamp

    # Person detection tuning
    PERSON_CONF = 0.15
    MIN_W, MIN_H = 60, 120

    # ---- performance controls ----
    YOLO_IMGSZ = 448
    FACE_EVERY_N = 5
    YOLO_EVERY_N = 2
    last_person_dets = []

    # FPS
    frame_count = 0
    fps_last_t = time.time()
    fps_text = ""

    try:
        while True:
            ok, frame = cap.read()

            invalid = (
                (not ok) or
                (frame is None) or
                (not hasattr(frame, "shape")) or
                (frame.size == 0)
            )

            if invalid:
                consecutive_frame_fails += 1
                if consecutive_frame_fails % 5 == 1:
                    logger.warning(f"[RTSP] invalid frame ({consecutive_frame_fails}/{max_consecutive_fails})")

                if consecutive_frame_fails >= max_consecutive_fails:
                    logger.warning("[RTSP] Stream unstable. Reconnecting...")
                    try:
                        cap.release()
                    except Exception:
                        pass
                    time.sleep(0.8)
                    cap = open_rtsp_capture(rtsp_url)
                    consecutive_frame_fails = 0
                    same_frame_count = 0
                    last_frame_sig = None

                time.sleep(0.03)
                continue

            consecutive_frame_fails = 0

            # frozen frame detection
            try:
                h, w = frame.shape[:2]
                sig = (h, w, int(frame[0, 0, 0]), int(frame[h // 2, w // 2, 0]), int(frame[-1, -1, 0]))
                if sig == last_frame_sig:
                    same_frame_count += 1
                else:
                    same_frame_count = 0
                    last_frame_sig = sig

                if same_frame_count >= max_same_frames:
                    logger.warning("[RTSP] Frozen frames detected. Reconnecting...")
                    try:
                        cap.release()
                    except Exception:
                        pass
                    time.sleep(0.6)
                    cap = open_rtsp_capture(rtsp_url)
                    same_frame_count = 0
                    last_frame_sig = None
                    continue
            except Exception:
                pass

            frame_count += 1
            H, W = frame.shape[:2]

            # A) Person Detection (YOLO) with optional skipping
            if frame_count % YOLO_EVERY_N == 0:
                try:
                    res = yolo_person.predict(frame, imgsz=YOLO_IMGSZ, conf=PERSON_CONF, verbose=False)[0]
                except Exception as e:
                    logger.warning(f"[YOLO] predict failed, skipping frame: {repr(e)}")
                    time.sleep(0.01)
                    continue

                person_dets = []
                if res.boxes is not None:
                    for b in res.boxes:
                        if int(b.cls[0]) != 0:
                            continue

                        x1, y1, x2, y2 = b.xyxy[0].tolist()
                        conf = float(b.conf[0])

                        x1 = max(0.0, min(W - 1.0, x1))
                        y1 = max(0.0, min(H - 1.0, y1))
                        x2 = max(0.0, min(W - 1.0, x2))
                        y2 = max(0.0, min(H - 1.0, y2))

                        if (x2 - x1) < MIN_W or (y2 - y1) < MIN_H:
                            continue

                        person_dets.append({"x1": x1, "y1": y1, "x2": x2, "y2": y2, "score": conf})

                last_person_dets = person_dets
            else:
                person_dets = last_person_dets

            # B) Tracking
            tracks = update_tracks_person(deepsort, person_dets, frame)

            # track boxes
            track_boxes = {}
            for tr in tracks:
                if hasattr(tr, "is_confirmed") and not tr.is_confirmed():
                    continue
                x1, y1, x2, y2 = tr.to_ltrb()
                track_boxes[tr.track_id] = (float(x1), float(y1), float(x2), float(y2))

            # C) Face detection only every N frames
            faces = []
            if frame_count % FACE_EVERY_N == 0:
                faces = app.get(frame, max_num=10)

                for face in faces:
                    fx1, fy1, fx2, fy2 = [float(v) for v in face.bbox]
                    emb = face.normed_embedding if hasattr(face, "normed_embedding") else face.embedding
                    emb = np.asarray(emb, dtype=np.float32).reshape(-1)

                    tid = assign_face_to_track((fx1, fy1, fx2, fy2), track_boxes)
                    if tid is None:
                        continue

                    label, sim, sim2 = match_identity(
                        emb, known,
                        sim_threshold=SIM_THR,
                        margin=MARGIN_THR
                    )

                    if tid not in track_identity:
                        track_identity[tid] = {
                            "votes": deque(maxlen=ID_WINDOW),
                            "stable": "Unknown",
                            "last_ts": time.time(),
                            "last_sim": 0.0
                        }

                    mem = track_identity[tid]
                    mem["votes"].append(label)
                    mem["last_ts"] = time.time()
                    mem["last_sim"] = float(sim)

                    # stable label by vote
                    counts = {}
                    for v in mem["votes"]:
                        counts[v] = counts.get(v, 0) + 1
                    best, c = max(counts.items(), key=lambda kv: kv[1])
                    mem["stable"] = best if (best != "Unknown" and c >= ID_MIN_HITS) else "Unknown"

                    # if identity stable, update primary tid mapping
                    if mem["stable"] != "Unknown":
                        name = mem["stable"]
                        identity_to_primary_tid[name] = tid
                        identity_last_seen[name] = time.time()

            # timeout identities per track
            now = time.time()
            for tid in list(track_identity.keys()):
                if now - track_identity[tid]["last_ts"] > IDENTITY_TIMEOUT_SEC:
                    track_identity[tid]["stable"] = "Unknown"

            # cleanup primary identity mapping if not seen recently
            for name in list(identity_last_seen.keys()):
                if now - identity_last_seen[name] > 6.0:  # seconds
                    identity_last_seen.pop(name, None)
                    identity_to_primary_tid.pop(name, None)

            # D) Draw tracks (show FIXED id when recognized)
            active = 0
            for tr in tracks:
                if hasattr(tr, "is_confirmed") and not tr.is_confirmed():
                    continue
                tid = tr.track_id
                x1, y1, x2, y2 = tr.to_ltrb()
                x1, y1, x2, y2 = int(x1), int(y1), int(x2), int(y2)

                name = track_identity.get(tid, {}).get("stable", "Unknown")
                simv = track_identity.get(tid, {}).get("last_sim", 0.0)

                # If this track has a stable name, but it's not the "primary" track for that name,
                # hide it to avoid duplicates when track_id changes.
                if name != "Unknown":
                    primary_tid = identity_to_primary_tid.get(name, tid)
                    if primary_tid != tid:
                        name = "Unknown"

                if name != "Unknown":
                    fixed_id = FIXED_EMPLOYEE_ID.get(name, name)
                    label_text = f"{fixed_id} {name} ({simv:.2f})"
                    color = (0, 255, 0)
                else:
                    label_text = f"Track:{tid}"
                    color = (0, 255, 255)

                cv2.rectangle(frame, (x1, y1), (x2, y2), color, 2)
                cv2.putText(
                    frame,
                    label_text,
                    (x1, max(0, y1 - 10)),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.6,
                    color,
                    2
                )
                active += 1

            # FPS
            dt = time.time() - fps_last_t
            if dt >= 1.0:
                fps = frame_count / dt
                fps_text = f"FPS: {fps:.1f} | Tracks: {active} | Faces: {len(faces)}"
                frame_count = 0
                fps_last_t = time.time()

            cv2.putText(
                frame, fps_text,
                (20, 30),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.8,
                (255, 255, 255),
                2
            )

            cv2.imshow("Surveillance - Stable Identity Label", frame)
            key = cv2.waitKey(1) & 0xFF
            if key in (27, ord("q")):
                break

    finally:
        try:
            cap.release()
        except Exception:
            pass
        cv2.destroyAllWindows()


if __name__ == "__main__":
    main()