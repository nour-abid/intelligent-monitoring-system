# =============================================================================
# DEPRECATED — DO NOT USE
# =============================================================================
# This file is an older runtime superseded by attendance_webcam.py.
#
# Known breakages:
#   - Imports `match_detections_to_tracks` from tracker.py, which was never
#     defined there.  Running this file raises an ImportError immediately.
#   - Anti-spoofing, YOLO activity smoothing, DeepSORT, and frozen-frame
#     detection are all absent or incomplete vs. the active runtime.
#
# Canonical runtime: src/recognition/attendance_webcam.py
# =============================================================================

import os
import time
import sys
import cv2
import numpy as np
from insightface.app import FaceAnalysis

try:
    from ultralytics import YOLO
    YOLO_AVAILABLE = True
except ImportError:
    YOLO_AVAILABLE = False
    print("[WARN] ultralytics not installed. YOLO anti-spoof fallback disabled.")
from collections import deque

from config import config
from logger import logger
from database import init_db, load_today_checkins, log_checkin_once_per_day
from recognition import load_known_embeddings, match_identity
from liveness import is_face_sharp, preprocess_face_for_antispoof, real_prob_from_ultralytics_result, real_prob_from_minifasnet_result, is_live_track
from tracker import match_detections_to_tracks, get_stable_label

def open_rtsp_capture(url: str):
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = (
        "rtsp_transport;tcp|stimeout;10000000|max_delay;500000"
    )
    cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
    cap.set(cv2.CAP_PROP_AUTOFOCUS, 0)
    print("[INFO] Warming up RTSP stream...", end=" ", flush=True)
    warmup_count = 0
    for _ in range(15):
        ok, frame = cap.read()
        if ok and frame is not None:
            warmup_count += 1
        time.sleep(0.02)
    print(f"Got {warmup_count}/15 frames")
    time.sleep(0.5)
    return cap

def main():
    logger.info("STARTING attendance (RTSP + InsightFace)")
    logger.info(f"ROOT: {config['paths']['emb_dir'].parent.parent}")
    logger.info(f"EMB_DIR: {config['paths']['emb_dir']}")
    logger.info(f"DB: {config['paths']['db_path']}")
    known = load_known_embeddings()
    logger.info(f"Known identities: {list(known.keys())}")
    conn = init_db()
    present_set, checkin_time = load_today_checkins(conn)
    logger.info("Loading InsightFace model (buffalo_l)...")
    app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    app.prepare(ctx_id=-1, det_size=(640, 640))
    logger.info("InsightFace model loaded.")
    spoof_model = None
    ANTI_SPOOF_ENABLED = False
    ANTI_SPOOF_MODEL_TYPE = None
    logger.info("Pipeline: Face Detection → Recognition (ArcFace embeddings)")
    logger.info("Anti-spoofing disabled. Rely on embedding quality for accuracy.")
    rtsp_url = config['camera']['rtsp_url']
    cap = open_rtsp_capture(rtsp_url)
    logger.info(f"RTSP open: {cap.isOpened()}")
    if not cap.isOpened():
        raise SystemExit("❌ Cannot open RTSP stream. Check URL/credentials.")
    tracks = {}
    next_track_id = 0
    frame_count = 0
    consecutive_frame_fails = 0
    max_consecutive_fails = 15
    try:
        while True:
            ok, frame = cap.read()
            if not ok or frame is None:
                consecutive_frame_fails += 1
                if consecutive_frame_fails % 5 == 1:
                    logger.warning(f"No frame ({consecutive_frame_fails}/{max_consecutive_fails}) - retrying...")
                if consecutive_frame_fails > max_consecutive_fails:
                    logger.warning("Stream loss detected. Reconnecting...")
                    cap.release()
                    time.sleep(1)
                    cap = open_rtsp_capture(rtsp_url)
                    consecutive_frame_fails = 0
                    if not cap.isOpened():
                        logger.error("Failed to reconnect. Exiting.")
                        break
                time.sleep(0.05)
                continue
            consecutive_frame_fails = 0
            now = time.time()
            frame_count += 1
            faces = app.get(frame, max_num=config['tuning']['max_faces'])
            detections = []
            h, w = frame.shape[:2]
            for face in faces:
                if face.det_score < config['tuning']['det_score_thr']:
                    continue
                x1, y1, x2, y2 = [int(v) for v in face.bbox]
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(w - 1, x2), min(h - 1, y2)
                if (x2 - x1) < config['tuning']['min_face_size'] or (y2 - y1) < config['tuning']['min_face_size']:
                    continue
                face_crop = frame[y1:y2, x1:x2]
                if not is_face_sharp(face_crop, blur_threshold=20.0):
                    continue
                detections.append({
                    "x1": x1, "y1": y1, "x2": x2, "y2": y2,
                    "score": face.det_score,
                    "embedding": face.embedding
                })
            matched_pairs, unmatched_dets, unmatched_track_ids = match_detections_to_tracks(
                detections, tracks, dist_threshold=100
            )
            seen_tracks = set()
            for track_id, det_idx in matched_pairs:
                det = detections[det_idx]
                track = tracks[track_id]
                track["bbox"] = {k: det[k] for k in ["x1", "y1", "x2", "y2"]}
                track["last_seen_time"] = now
                seen_tracks.add(track_id)
                if ANTI_SPOOF_ENABLED and frame_count % config['tuning']['anti_spoof_every_n_frames'] == 0:
                    x1, y1, x2, y2 = det["x1"], det["y1"], det["x2"], det["y2"]
                    face = frame[y1:y2, x1:x2]
                    if face.size > 0:
                        try:
                            face_preprocessed = preprocess_face_for_antispoof(face, target_size=80)
                            if face_preprocessed is not None:
                                if ANTI_SPOOF_MODEL_TYPE == 'minifasnet':
                                    prediction = spoof_model.predict(face_preprocessed)
                                    real_prob = real_prob_from_minifasnet_result(prediction)
                                elif ANTI_SPOOF_MODEL_TYPE == 'yolo':
                                    spoof_results = spoof_model.predict(face_preprocessed, verbose=False)
                                    if spoof_results and len(spoof_results) > 0:
                                        r0 = spoof_results[0]
                                        real_prob = real_prob_from_ultralytics_result(r0)
                                    else:
                                        real_prob = 0.0
                                else:
                                    real_prob = 0.0
                                track["last_real_prob"] = real_prob
                                track["spoof_votes"].append(real_prob)
                            else:
                                track["last_real_prob"] = 0.0
                                track["spoof_votes"].append(0.0)
                        except Exception as e:
                            logger.warning(f"Anti-spoof inference failed: {e}")
                            track["last_real_prob"] = 0.0
                            track["spoof_votes"].append(0.0)
                elif not ANTI_SPOOF_ENABLED and frame_count % config['tuning']['anti_spoof_every_n_frames'] == 0:
                    track["last_real_prob"] = 0.95
                    track["spoof_votes"].append(0.95)
                should_compute_embedding = is_live_track(track)
                if should_compute_embedding:
                    emb = det["embedding"]
                    label, sim, sim2 = match_identity(emb, known)
                    track["last_label"] = label
                    track["last_sim"] = sim
                    track["last_sim2"] = sim2
                    if label != "Unknown":
                        track["votes"].append(label)
                else:
                    pass
            for det_idx in unmatched_dets:
                det = detections[det_idx]
                x1, y1, x2, y2 = det["x1"], det["y1"], det["x2"], det["y2"]
                face = frame[y1:y2, x1:x2]
                label, sim, sim2 = "Unknown", 0.0, 0.0
                votes = deque([], maxlen=config['tuning']['smooth_window'])
                spoof_votes = deque([], maxlen=config['tuning']['spoof_window'])
                real_prob = 0.0
                if ANTI_SPOOF_ENABLED and face.size > 0:
                    try:
                        face_preprocessed = preprocess_face_for_antispoof(face, target_size=80)
                        if face_preprocessed is not None:
                            if ANTI_SPOOF_MODEL_TYPE == 'minifasnet':
                                prediction = spoof_model.predict(face_preprocessed)
                                real_prob = real_prob_from_minifasnet_result(prediction)
                            elif ANTI_SPOOF_MODEL_TYPE == 'yolo':
                                spoof_results = spoof_model.predict(face_preprocessed, verbose=False)
                                if spoof_results and len(spoof_results) > 0:
                                    r0 = spoof_results[0]
                                    real_prob = real_prob_from_ultralytics_result(r0)
                                else:
                                    real_prob = 0.0
                            else:
                                real_prob = 0.0
                            spoof_votes.append(real_prob)
                        else:
                            spoof_votes.append(0.0)
                            real_prob = 0.0
                    except Exception as e:
                        logger.warning(f"Anti-spoof inference failed: {e}")
                        spoof_votes.append(0.0)
                        real_prob = 0.0
                elif not ANTI_SPOOF_ENABLED:
                    real_prob = 0.95
                    spoof_votes.append(0.95)
                should_compute_embedding = (real_prob >= config['tuning']['real_thr']) or is_live_track({
                    "spoof_votes": spoof_votes
                })
                if should_compute_embedding:
                    try:
                        emb = det["embedding"]
                        label, sim, sim2 = match_identity(emb, known)
                        if label != "Unknown":
                            votes.append(label)
                    except Exception as e:
                        logger.warning(f"Embedding/matching failed: {e}")
                        label, sim, sim2 = "Unknown", 0.0, 0.0
                else:
                    label, sim, sim2 = "Unknown", 0.0, 0.0
                new_track_id = next_track_id
                next_track_id += 1
                tracks[new_track_id] = {
                    "bbox": {k: det[k] for k in ["x1", "y1", "x2", "y2"]},
                    "votes": votes,
                    "last_label": label,
                    "last_sim": sim,
                    "last_sim2": sim2,
                    "first_seen_time": now,
                    "last_seen_time": now,
                    "spoof_votes": spoof_votes,
                    "last_real_prob": real_prob,
                }
                seen_tracks.add(new_track_id)
            for track_id in unmatched_track_ids:
                track = tracks[track_id]
                if now - track["last_seen_time"] > config['tuning']['absent_after_sec']:
                    del tracks[track_id]
            seen_identities = set()
            for track_id, track in tracks.items():
                stable_label, count = get_stable_label(track["votes"])
                if stable_label != "Unknown" and count >= config['tuning']['min_hits'] and is_live_track(track):
                    seen_identities.add(stable_label)
                    if now - track["first_seen_time"] >= config['tuning']['present_after_sec']:
                        if stable_label not in present_set:
                            t = log_checkin_once_per_day(conn, stable_label)
                            present_set.add(stable_label)
                            checkin_time[stable_label] = t
            identity_to_tracks = {}
            for track_id, track in tracks.items():
                stable_label, _ = get_stable_label(track["votes"])
                if stable_label != "Unknown":
                    if stable_label not in identity_to_tracks:
                        identity_to_tracks[stable_label] = []
                    identity_to_tracks[stable_label].append(track_id)
            for identity, track_list in identity_to_tracks.items():
                last_seen_any = max((tracks[tid]["last_seen_time"] for tid in track_list), default=0)
                if now - last_seen_any > config['tuning']['absent_after_sec']:
                    for tid in track_list:
                        tracks[tid]["votes"].clear()
            for track_id, track in tracks.items():
                x1, y1, x2, y2 = track["bbox"]["x1"], track["bbox"]["y1"], track["bbox"]["x2"], track["bbox"]["y2"]
                stable_label, count = get_stable_label(track["votes"])
                sim = track["last_sim"]
                sim2 = track["last_sim2"]
                real_prob = track.get("last_real_prob", 0.0)
                is_live = is_live_track(track)
                if not is_live:
                    color = (0, 0, 255)
                elif stable_label != "Unknown":
                    color = (0, 255, 0)
                else:
                    color = (0, 255, 255)
                cv2.rectangle(frame, (x1, y1), (x2, y2), color, 2)
                cv2.putText(
                    frame,
                    f"{stable_label} ({sim:.2f}) d={(sim - sim2):.2f} v={count}",
                    (x1, max(0, y1 - 20)),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.6,
                    color,
                    2,
                )
                liveness_text = f"Live:{real_prob:.2f}" if is_live else "FAKE"
                liveness_color = (0, 255, 0) if is_live else (0, 0, 255)
                cv2.putText(
                    frame,
                    liveness_text,
                    (x1, max(0, y1 - 5)),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.5,
                    liveness_color,
                    2,
                )
                if stable_label in checkin_time and checkin_time[stable_label]:
                    cv2.putText(
                        frame,
                        f"Checked-in: {checkin_time[stable_label]}",
                        (x1, y2 + 18),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.5,
                        (0, 255, 0),
                        2,
                    )
            cv2.putText(
                frame,
                f"Tracks: {len(tracks)} | Frame: {frame_count} | Checked-in: {', '.join(sorted(present_set)) or 'None'}",
                (20, 30),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                (255, 255, 255),
                2,
            )
            cv2.imshow("Attendance (RTSP + InsightFace + Tracking)", frame)
            if cv2.waitKey(1) & 0xFF in (27, ord("q")):
                break
    except KeyboardInterrupt:
        logger.info("Ctrl+C detected. Shutting down gracefully...")
    finally:
        logger.info("Cleaning up...")
        cap.release()
        cv2.destroyAllWindows()
        conn.close()
        logger.info("Done.")

if __name__ == "__main__":
    main()