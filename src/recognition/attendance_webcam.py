import os
import time
import sys
from pathlib import Path
from collections import deque


# --- make project imports work in both script mode and module mode ---
SCRIPT_DIR = Path(__file__).resolve().parent          # .../src/recognition
SRC_DIR = SCRIPT_DIR.parent                           # .../src
PROJECT_ROOT = SRC_DIR.parent                         # project root

for p in (SCRIPT_DIR, SRC_DIR, PROJECT_ROOT):
    sp = str(p)
    if sp not in sys.path:
        sys.path.insert(0, sp)

import cv2
import numpy as np
from insightface.app import FaceAnalysis

try:
    import torch
    import torch.nn.functional as F
    TORCH_AVAILABLE = True
except ImportError:
    TORCH_AVAILABLE = False

try:
    from ultralytics import YOLO  
    YOLO_AVAILABLE = True
except ImportError:
    YOLO_AVAILABLE = False


# --- local imports: support both launch styles ---
try:
    # Script mode: python src/recognition/attendance_webcam.py
    from config import config
    from logger import logger
    from database import init_db, load_today_checkins, log_checkin_once_per_day
    from recognition import load_known_embeddings, match_identity
    from liveness import (
        is_face_sharp,
        preprocess_face_for_antispoof,
        real_prob_from_ultralytics_result,
        real_prob_from_minifasnet_result, 
        is_live_track
    )
    from tracker import init_tracker, update_tracks, find_closest_detection, get_stable_label
    from anti_spoof_predict import AntiSpoofPredict
    from generate_patches import CropImage

except ModuleNotFoundError:
    # Module mode: python -m src.recognition.attendance_webcam
    from src.recognition.config import config
    from src.recognition.logger import logger
    from src.recognition.database import init_db, load_today_checkins, log_checkin_once_per_day
    from src.recognition.recognition import load_known_embeddings, match_identity
    from src.recognition.liveness import (
        is_face_sharp,
        preprocess_face_for_antispoof,
        real_prob_from_ultralytics_result,
        real_prob_from_minifasnet_result,  
        is_live_track
    )
    from src.recognition.tracker import init_tracker, update_tracks, find_closest_detection, get_stable_label
    from anti_spoof_predict import AntiSpoofPredict
    from generate_patches import CropImage
    from src.utils.track_state import TrackStateStore


# ------------------------------------------------------------
# MiniFASNet helpers (weights + path validation)
# ------------------------------------------------------------
def _safe_load_state_dict(model, ckpt_path, device):
    """
    Loads checkpoints robustly (plain state_dict / wrapped dict / DataParallel prefixes).
    """
    if not TORCH_AVAILABLE:
        raise RuntimeError("PyTorch is not available")

    try:
        ckpt = torch.load(str(ckpt_path), map_location=device, weights_only=True)
    except TypeError:
        # Older torch versions don't support weights_only
        ckpt = torch.load(str(ckpt_path), map_location=device)

    if isinstance(ckpt, dict):
        if "state_dict" in ckpt and isinstance(ckpt["state_dict"], dict):
            state = ckpt["state_dict"]
        elif "model" in ckpt and isinstance(ckpt["model"], dict):
            state = ckpt["model"]
        else:
            state = ckpt
    else:
        state = ckpt

    cleaned = {}
    for k, v in state.items():
        nk = k
        if nk.startswith("module."):
            nk = nk[len("module."):]
        if nk.startswith("model."):
            nk = nk[len("model."):]
        cleaned[nk] = v

    missing, unexpected = model.load_state_dict(cleaned, strict=False)
    model.eval()

    if missing:
        logger.warning(
            f"MiniFASNet missing keys ({len(missing)}): "
            f"{missing[:8]}{' ...' if len(missing) > 8 else ''}"
        )
    if unexpected:
        logger.warning(
            f"MiniFASNet unexpected keys ({len(unexpected)}): "
            f"{unexpected[:8]}{' ...' if len(unexpected) > 8 else ''}"
        )

    return model


def _build_minifasnet_models():
    """
    Loads MiniFASNet weights and validates they can be loaded.
    We still use AntiSpoofPredict for actual inference (patch crops), but this validates paths/checkpoints.
    """
    if not TORCH_AVAILABLE:
        raise RuntimeError("PyTorch is not installed. Install torch to use MiniFASNet.")

    script_dir = Path(__file__).resolve().parent          # .../src/recognition
    src_dir = script_dir.parent                           # .../src
    project_root = src_dir.parent                         # project root
    model_lib_dir = src_dir / "model_lib"

    for p in (script_dir, src_dir, model_lib_dir):
        if str(p) not in sys.path:
            sys.path.insert(0, str(p))

    import_errs = []
    try:
        # preferred (your working layout)
        from model_lib.MiniFASNet import MiniFASNetV2, MiniFASNetV1SE
        imported_from = str(model_lib_dir / "MiniFASNet.py")
    except Exception as e1:
        import_errs.append(f"model_lib.MiniFASNet import failed: {e1}")
        try:
            # fallback local copy if present
            from model_lib.MiniFASNet import MiniFASNetV2, MiniFASNetV1SE
            imported_from = str(script_dir / "MiniFASNet.py")
        except Exception as e2:
            import_errs.append(f"local MiniFASNet.py import failed: {e2}")
            raise RuntimeError("Could not import MiniFASNet classes. " + " | ".join(import_errs))

    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    candidate_dirs = [
        script_dir,                                   # src/recognition/
        src_dir / "anti_spoof_models",                # src/anti_spoof_models/
        project_root / "resources" / "anti_spoof_models",
    ]

    v2_names = ["2.7_80x80_MiniFASNetV2.pth"]
    v1se_names = [
        "4.0_0_80x80_MiniFASNetV1SE.pth",
        "4_0_0_80x80_MiniFASNetV1SE.pth",
    ]

    def find_weight(possible_names):
        for d in candidate_dirs:
            for name in possible_names:
                p = d / name
                if p.exists():
                    return p
        return None

    w_v2 = find_weight(v2_names)
    w_v1se = find_weight(v1se_names)

    if w_v2 is None or w_v1se is None:
        debug_dirs = [str(d) for d in candidate_dirs]
        raise RuntimeError(
            "MiniFASNet weight file(s) missing. "
            f"Found V2={w_v2}, V1SE={w_v1se}. Checked dirs: {debug_dirs}"
        )

    # Validate checkpoints load successfully (catches architecture mismatch early)
    m1 = MiniFASNetV2(conv6_kernel=5, num_classes=3).to(device)
    m2 = MiniFASNetV1SE(conv6_kernel=5, num_classes=3).to(device)
    _safe_load_state_dict(m1, w_v2, device)
    _safe_load_state_dict(m2, w_v1se, device)

    logger.info(f"MiniFASNet loaded on {device}: {w_v2.name}, {w_v1se.name}")
    logger.info(f"MiniFASNet classes imported from: {imported_from}")

    return {
        "type": "minifasnet_ensemble",
        "device": device,
        "models": [m1, m2],  # validation only; inference uses AntiSpoofPredict below
        "weights": [str(w_v2), str(w_v1se)],
        "imported_from": imported_from,
    }


def _run_minifasnet_ensemble(spoof_bundle, face_crop_bgr):
    """
    Legacy fallback (unused in main flow after patch-crop AntiSpoofPredict integration).
    Kept for debugging.
    """
    if spoof_bundle is None or face_crop_bgr is None or face_crop_bgr.size == 0:
        return None
    if not TORCH_AVAILABLE:
        return None

    x = preprocess_face_for_antispoof(face_crop_bgr)
    if x is None:
        return None

    if isinstance(x, np.ndarray):
        arr = x
        if arr.ndim != 3 or arr.shape[2] != 3:
            logger.debug(f"Unexpected anti-spoof preprocessed shape: {arr.shape}")
            return None

        arr = arr.astype(np.float32)
        arr = (arr - 127.5) / 128.0
        arr = np.transpose(arr, (2, 0, 1))
        arr = np.expand_dims(arr, axis=0)
        tensor = torch.from_numpy(arr)
    elif torch.is_tensor(x):
        tensor = x.float()
        if tensor.ndim == 3:
            tensor = tensor.unsqueeze(0)
        elif tensor.ndim != 4:
            logger.debug(f"Unexpected anti-spoof tensor ndim: {tensor.ndim}")
            return None
    else:
        logger.debug(f"Unsupported anti-spoof preprocess output type: {type(x)}")
        return None

    tensor = tensor.to(spoof_bundle["device"])

    probs = []
    with torch.no_grad():
        for m in spoof_bundle["models"]:
            out = m(tensor)
            if isinstance(out, (tuple, list)):
                out = out[0]
            p = F.softmax(out, dim=1).detach().cpu().numpy()
            probs.append(p)

    if not probs:
        return None

    mean_prob = np.mean(np.concatenate(probs, axis=0), axis=0).astype(np.float32)
    return mean_prob


# ------------------------------------------------------------
# RTSP (final improved version)
# ------------------------------------------------------------
import os
import time
import cv2


def open_rtsp_capture(url: str):
    """
    Open RTSP stream with low-latency settings and basic validation.
    """
    # Force RTSP over TCP (most important for packet-loss reduction)
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp"

    cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)

    # Low-latency buffer (may be ignored on some OpenCV builds)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

    # Usually unsupported for IP/RTSP cameras, but harmless
    try:
        cap.set(cv2.CAP_PROP_AUTOFOCUS, 0)
    except Exception:
        pass

    if not cap.isOpened():
        raise RuntimeError(f"Failed to open RTSP stream: {url}")

    return cap


def read_frame_with_reconnect(cap, url: str, max_retries: int = 3, retry_delay: float = 0.3):
    """
    Read one frame. If read fails, reconnect and retry.
    Returns: (cap, ok, frame)
    """
    for _ in range(max_retries):
        ok, frame = cap.read()
        if ok and frame is not None and getattr(frame, "size", 0) > 0:
            return cap, True, frame

        # reconnect on failed read
        try:
            cap.release()
        except Exception:
            pass

        time.sleep(retry_delay)

        try:
            cap = open_rtsp_capture(url)
        except Exception:
            cap = None
            time.sleep(retry_delay)

    return cap, False, None


def main():
    logger.info("STARTING attendance (RTSP + InsightFace + DeepSORT + MiniFASNet)")
    logger.info(f"ROOT: {config['paths']['emb_dir'].parent.parent}")
    logger.info(f"EMB_DIR: {config['paths']['emb_dir']}")
    logger.info(f"DB: {config['paths']['db_path']}")

    # ------------------------------------------------------------
    # Safe tuning defaults (prevents KeyErrors if config.yaml misses fields)
    # ------------------------------------------------------------
    tuning = config.get("tuning", {})

    MAX_FACES = int(tuning.get("max_faces", 5))
    DET_SCORE_THR = float(tuning.get("det_score_thr", 0.55))
    MIN_FACE_SIZE = int(tuning.get("min_face_size", 60))

    SMOOTH_WINDOW = int(tuning.get("smooth_window", 10))
    SPOOF_WINDOW = int(tuning.get("spoof_window", 10))
    ANTI_SPOOF_EVERY_N = int(tuning.get("anti_spoof_every_n_frames", 5))

    ABSENT_AFTER_SEC = float(tuning.get("absent_after_sec", 2.0))
    MIN_HITS = int(tuning.get("min_hits", 3))
    PRESENT_AFTER_SEC = float(tuning.get("present_after_sec", 1.0))

    # stronger phone/screen rejection
    DET_SCORE_THR_STRICT = float(tuning.get("det_score_thr_strict", 0.65))
    MIN_FACE_SIZE_LIVE = int(tuning.get("min_face_size_live", 90))
    MIN_FACE_ASPECT = float(tuning.get("min_face_aspect", 0.65))
    MAX_FACE_ASPECT = float(tuning.get("max_face_aspect", 1.60))
    MIN_FACE_AREA_RATIO = float(tuning.get("min_face_area_ratio", 0.015))
    BLUR_THRESHOLD = float(tuning.get("blur_threshold", 20.0))

    # stronger recognition gate
    REC_MIN_SIM = float(tuning.get("rec_min_sim", 0.72))
    REC_MIN_MARGIN = float(tuning.get("rec_min_margin", 0.08))

    # ------------------------------------------------------------
    # Load known embeddings + DB
    # ------------------------------------------------------------
    known = load_known_embeddings()
    logger.info(f"Known identities: {list(known.keys())}")

    conn = init_db()
    present_set, checkin_time = load_today_checkins(conn)

    # ------------------------------------------------------------
    # Load InsightFace
    # ------------------------------------------------------------
    logger.info("Loading InsightFace model (buffalo_l)...")
    try:
        providers = ["CUDAExecutionProvider", "CPUExecutionProvider"]
        app = FaceAnalysis(name="buffalo_l", providers=providers)
        app.prepare(ctx_id=0, det_size=(640, 640))
        logger.info("InsightFace initialized with CUDA provider.")
    except Exception as e:
        logger.warning(f"CUDA not available: {e}, falling back to CPU.")
        app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
        app.prepare(ctx_id=-1, det_size=(640, 640))
        logger.info("InsightFace initialized with CPU provider.")

    # ------------------------------------------------------------
    # Init tracker
    # ------------------------------------------------------------
    deepsort = init_tracker()
    logger.info("DeepSORT tracker initialized.")

    # ------------------------------------------------------------
    # Anti-spoofing setup (MiniFASNet + CropImage)
    # ------------------------------------------------------------
    spoof_model = None
    ANTI_SPOOF_ENABLED = False
    ANTI_SPOOF_MODEL_TYPE = None
    antispoof_predictor = None
    cropper = None

    try:
        spoof_model = _build_minifasnet_models()
        ANTI_SPOOF_ENABLED = True
        ANTI_SPOOF_MODEL_TYPE = "minifasnet"

        try:
            antispoof_predictor = AntiSpoofPredict(device_id=0)
        except Exception as e_cuda:
            logger.warning(f"AntiSpoofPredict CUDA init failed, falling back to CPU: {e_cuda}")
            antispoof_predictor = AntiSpoofPredict(device_id=-1)

        cropper = CropImage()

        print(f"[ANTI_SPOOF] enabled=True, type={ANTI_SPOOF_MODEL_TYPE}, weights={spoof_model.get('weights')}")
        logger.info("Anti-spoofing ENABLED (MiniFASNet ensemble + CropImage patches).")
    except Exception as e:
        ANTI_SPOOF_ENABLED = False
        ANTI_SPOOF_MODEL_TYPE = None
        spoof_model = None
        antispoof_predictor = None
        cropper = None
        print("[ANTI_SPOOF] enabled=False, type=None, weights=None")
        print(f"[ANTI_SPOOF ERROR] {repr(e)}")
        logger.exception("MiniFASNet initialization failed")
        logger.warning("System will still recognize faces, but liveness enforcement is OFF.")

    logger.info("Pipeline: InsightFace -> DeepSORT -> CropImage -> MiniFASNet -> Attendance")
    # ------------------------------------------------------------
    # RTSP
    # ------------------------------------------------------------
    rtsp_url = config["camera"]["rtsp_url"]
    cap = open_rtsp_capture(rtsp_url)
    logger.info(f"RTSP open: {cap.isOpened()}")
    if not cap.isOpened():
        raise SystemExit("❌ Cannot open RTSP stream. Check URL/credentials.")

    my_tracks = {}
    frame_count = 0
    consecutive_frame_fails = 0
    max_consecutive_fails = 15
    last_emb = None

    # Optional: detect frozen/repeated frames (common with unstable RTSP)
    last_frame_sig = None
    same_frame_count = 0
    max_same_frames = 20  # reconnect if exact same frame repeats too long

    try:
        while True:
            ok, frame = cap.read()

            # Basic invalid frame checks
            invalid = (
                (not ok) or
                (frame is None) or
                (not hasattr(frame, "shape")) or
                (frame.size == 0)
            )

            if invalid:
                consecutive_frame_fails += 1
                if consecutive_frame_fails % 5 == 1:
                    logger.warning(
                        f"No/invalid frame ({consecutive_frame_fails}/{max_consecutive_fails}) - retrying..."
                    )

                if consecutive_frame_fails > max_consecutive_fails:
                    logger.warning("Stream loss detected. Reconnecting RTSP...")
                    try:
                        cap.release()
                    except Exception:
                        pass

                    time.sleep(0.8)
                    cap = open_rtsp_capture(rtsp_url)
                    consecutive_frame_fails = 0
                    same_frame_count = 0
                    last_frame_sig = None

                    if not cap.isOpened():
                        logger.error("Failed to reconnect RTSP. Retrying in loop...")
                        time.sleep(1.0)
                        continue

                time.sleep(0.03)
                continue

            # Frame received successfully
            consecutive_frame_fails = 0

            # Optional tiny sanity check for corrupted tiny frames
            h, w = frame.shape[:2]
            if h < 50 or w < 50:
                logger.warning(f"Corrupted/small frame ignored: {w}x{h}")
                time.sleep(0.01)
                continue

            # Frozen frame detection (cheap signature)
            # Uses a few pixels + shape to avoid heavy hashing
            try:
                sig = (
                    h, w,
                    int(frame[0, 0, 0]), int(frame[h // 2, w // 2, 0]),
                    int(frame[-1, -1, 0])
                )
                if sig == last_frame_sig:
                    same_frame_count += 1
                else:
                    same_frame_count = 0
                    last_frame_sig = sig

                if same_frame_count > max_same_frames:
                    logger.warning("Frozen RTSP frames detected. Reconnecting...")
                    try:
                        cap.release()
                    except Exception:
                        pass
                    time.sleep(0.5)
                    cap = open_rtsp_capture(rtsp_url)
                    same_frame_count = 0
                    last_frame_sig = None
                    continue
            except Exception:
                # If signature fails for any reason, do not block processing
                pass

            now = time.time()
            frame_count += 1



            # ------------------------------
            # Face detection / embedding extraction
            # ------------------------------
            inference_start = time.time()
            faces = app.get(frame, max_num=MAX_FACES)
            inference_end = time.time()
            print(f"Inference time: {inference_end - inference_start:.4f}s")

            detections = []
            h, w = frame.shape[:2]
            frame_area = max(1, h * w)

            for face in faces:
                # Base detector threshold
                if float(face.det_score) < DET_SCORE_THR:
                    continue

                x1, y1, x2, y2 = [int(v) for v in face.bbox]
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(w - 1, x2), min(h - 1, y2)

                fw = x2 - x1
                fh = y2 - y1
                cv2.rectangle(frame, (x1, y1), (x2, y2), (255, 0, 255), 1)
                cv2.putText(frame, f"det:{float(face.det_score):.2f}", (x1, max(0, y1 - 5)),
                cv2.FONT_HERSHEY_SIMPLEX, 0.45, (255, 0, 255), 1)
                # Original minimum face size gate
                if fw < MIN_FACE_SIZE or fh < MIN_FACE_SIZE:
                    continue

                # -------------------------------------------------
                # Stronger phone/screen rejection
                # -------------------------------------------------
                face_area = max(1, fw * fh)
                face_area_ratio = face_area / float(frame_area)
                aspect = fw / float(max(1, fh))

                # 1) stricter confidence gate for attendance-quality faces
                if float(face.det_score) < DET_SCORE_THR_STRICT:
                    continue

                # 2) larger minimum size for "live/attendance" acceptance
                if fw < MIN_FACE_SIZE_LIVE or fh < MIN_FACE_SIZE_LIVE:
                    continue

                # 3) reject weird aspect ratios (phone/screen detections can be odd)
                if aspect < MIN_FACE_ASPECT or aspect > MAX_FACE_ASPECT:
                    continue

                # 4) reject tiny face region relative to frame (common for faces on phone screens)
                if face_area_ratio < MIN_FACE_AREA_RATIO:
                    continue

                face_crop = frame[y1:y2, x1:x2]
                if face_crop.size == 0:
                    continue

                # 5) sharpness gate
                if not is_face_sharp(face_crop, blur_threshold=BLUR_THRESHOLD):
                    continue

                embedding = face.normed_embedding if hasattr(face, "normed_embedding") else face.embedding
                embedding = np.asarray(embedding, dtype=np.float32).reshape(-1)

                detections.append({
                    "x1": x1,
                    "y1": y1,
                    "x2": x2,
                    "y2": y2,
                    "score": float(face.det_score),
                    "embedding": embedding,
                })

            # ------------------------------
            # Tracker update
            # ------------------------------
            tracks = update_tracks(deepsort, detections, frame)
            seen_tracks = set()

            for track in tracks:
                if hasattr(track, "is_confirmed") and not track.is_confirmed():
                    continue

                track_id = track.track_id
                bbox = track.to_ltrb()
                track_bbox = {
                    "x1": float(bbox[0]),
                    "y1": float(bbox[1]),
                    "x2": float(bbox[2]),
                    "y2": float(bbox[3]),
                }

                if track_id not in my_tracks:
                    my_tracks[track_id] = {
                        "bbox": track_bbox,
                        "votes": deque([], maxlen=SMOOTH_WINDOW),
                        "last_label": "Unknown",
                        "last_sim": 0.0,
                        "last_sim2": 0.0,
                        "first_seen_time": now,
                        "last_seen_time": now,
                        "spoof_votes": deque([], maxlen=SPOOF_WINDOW),
                        "last_real_prob": 0.0,
                        "embedding": None,
                    }
                else:
                    my_tracks[track_id]["bbox"] = track_bbox
                    my_tracks[track_id]["last_seen_time"] = now

                seen_tracks.add(track_id)
                my_track = my_tracks[track_id]

                det_match = find_closest_detection(track_bbox, detections)
                if not (det_match and isinstance(det_match, dict)):
                    continue

                # ------------------------------
                # Anti-spoof every N frames
                # ------------------------------
                anti_spoof_every = max(1, ANTI_SPOOF_EVERY_N)
                if frame_count % anti_spoof_every == 0:
                    dx1 = max(0, int(det_match["x1"]))
                    dy1 = max(0, int(det_match["y1"]))
                    dx2 = min(w - 1, int(det_match["x2"]))
                    dy2 = min(h - 1, int(det_match["y2"]))
                    face_crop = frame[dy1:dy2, dx1:dx2]

                    prob_real = 0.0
                    if ANTI_SPOOF_ENABLED and face_crop.size != 0:
                        try:
                            if ANTI_SPOOF_MODEL_TYPE == "minifasnet":
                                if antispoof_predictor is None or cropper is None:
                                    raise RuntimeError("AntiSpoofPredict/CropImage not initialized")

                                bbox_xywh = [dx1, dy1, max(1, dx2 - dx1), max(1, dy2 - dy1)]

                                # Scaled patch crops matching weight filenames
                                img1 = cropper.crop(
                                    org_img=frame,
                                    bbox=bbox_xywh,
                                    scale=2.7,
                                    out_w=80,
                                    out_h=80,
                                    crop=True
                                )
                                img2 = cropper.crop(
                                    org_img=frame,
                                    bbox=bbox_xywh,
                                    scale=4.0,
                                    out_w=80,
                                    out_h=80,
                                    crop=True
                                )

                                pred1 = antispoof_predictor.predict(img1, spoof_model["weights"][0])
                                pred2 = antispoof_predictor.predict(img2, spoof_model["weights"][1])

                                pred = pred1 + pred2
                                pred = pred / (pred.sum() + 1e-8)

                                print(f"[ANTI_SPOOF RAW] pred={pred} argmax={int(np.argmax(pred))}")
                                prob_real = float(pred[0][1])  # class 1 = real/live
                                print(f"[ANTI_SPOOF] real_prob={prob_real:.3f}")

                            elif ANTI_SPOOF_MODEL_TYPE == "ultralytics":
                                preprocessed = preprocess_face_for_antispoof(face_crop)
                                pred = spoof_model(preprocessed)
                                prob_real = real_prob_from_ultralytics_result(pred)
                            else:
                                prob_real = 0.0

                        except Exception as e:
                            print(f"[ANTI_SPOOF ERROR] track={track_id} err={repr(e)}")
                            logger.warning(f"Anti-spoof inference failed for track {track_id}: {e}")
                            prob_real = 0.0
                    else:
                        # If anti-spoof disabled, do not force fake
                        prob_real = 1.0

                    my_track["last_real_prob"] = float(prob_real)
                    my_track["spoof_votes"].append(float(prob_real))

                if not ANTI_SPOOF_ENABLED and len(my_track["spoof_votes"]) == 0:
                    my_track["spoof_votes"].append(1.0)
                    my_track["last_real_prob"] = 1.0

                # ------------------------------
                # Liveness gate
                # ------------------------------
                live_ok = is_live_track(my_track, tuning) if ANTI_SPOOF_ENABLED else True

                # ------------------------------
                # Recognition
                # ------------------------------
                emb = det_match.get("embedding", None)
                if emb is None:
                    continue

                emb = np.asarray(emb, dtype=np.float32).reshape(-1)
                last_emb = emb

                label, sim, sim2 = match_identity(emb, known)

                # Stronger recognition acceptance gate
                margin = float(sim - sim2)
                if (label is None) or (label == "Unknown") or (sim < REC_MIN_SIM) or (margin < REC_MIN_MARGIN):
                    label = "Unknown"

                my_track["last_label"] = label
                my_track["last_sim"] = float(sim)
                my_track["last_sim2"] = float(sim2)
                my_track["embedding"] = emb

                # Only vote when BOTH recognized and live
                if label != "Unknown" and live_ok:
                    my_track["votes"].append(label)
                else:
                    my_track["votes"].append("Unknown")

            # ------------------------------
            # Remove stale tracks
            # ------------------------------
            for track_id in list(my_tracks.keys()):
                if track_id not in seen_tracks:
                    if now - my_tracks[track_id]["last_seen_time"] > ABSENT_AFTER_SEC:
                        del my_tracks[track_id]

            # ------------------------------
            # Attendance logging
            # ------------------------------
            for track_id, track in my_tracks.items():
                stable_label, count = get_stable_label(track["votes"])
                live_ok = is_live_track(track, tuning) if ANTI_SPOOF_ENABLED else True

                if stable_label != "Unknown" and count >= MIN_HITS and live_ok:
                    if now - track["first_seen_time"] >= PRESENT_AFTER_SEC:
                        if stable_label not in present_set:
                            t = log_checkin_once_per_day(conn, stable_label)
                            present_set.add(stable_label)
                            checkin_time[stable_label] = t

            # ------------------------------
            # Visualization
            # ------------------------------
            for track_id, track in my_tracks.items():
                x1, y1, x2, y2 = (
                    track["bbox"]["x1"], track["bbox"]["y1"],
                    track["bbox"]["x2"], track["bbox"]["y2"]
                )
                stable_label, count = get_stable_label(track["votes"])
                sim = track["last_sim"]
                sim2 = track["last_sim2"]
                real_prob = track.get("last_real_prob", 0.0)
                live_ok = is_live_track(track) if ANTI_SPOOF_ENABLED else True

                if live_ok:
                    color = (0, 255, 0) if stable_label != "Unknown" else (0, 255, 255)
                else:
                    color = (0, 0, 255)

                if ANTI_SPOOF_ENABLED and not live_ok:
                    display_label = "FAKE"
                else:
                    display_label = stable_label if stable_label != "Unknown" else track.get("last_label", "Unknown")

                cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), color, 2)
                cv2.putText(
                    frame,
                    f"{display_label} ({sim:.2f}) d={(sim - sim2):.2f} v={count}",
                    (int(x1), int(max(0, y1 - 20))),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.6,
                    color,
                    2,
                )

                if ANTI_SPOOF_ENABLED:
                    liveness_text = f"Live:{real_prob:.2f}" if live_ok else f"FAKE:{real_prob:.2f}"
                    liveness_color = (0, 255, 0) if live_ok else (0, 0, 255)
                else:
                    liveness_text = "Liveness:OFF"
                    liveness_color = (0, 255, 255)

                cv2.putText(
                    frame,
                    liveness_text,
                    (int(x1), int(max(0, y1 - 5))),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.5,
                    liveness_color,
                    2,
                )

                if stable_label in checkin_time and checkin_time[stable_label]:
                    cv2.putText(
                        frame,
                        f"Checked-in: {checkin_time[stable_label]}",
                        (int(x1), int(y2 + 18)),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.5,
                        (0, 255, 0),
                        2,
                    )

            cv2.putText(
                frame,
                f"Tracks: {len(my_tracks)} | Frame: {frame_count} | Checked-in: {', '.join(sorted(present_set)) or 'None'}",
                (20, 30),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                (255, 255, 255),
                2,
            )

            cv2.imshow("Attendance (RTSP + InsightFace + Tracking + MiniFASNet)", frame)
            if cv2.waitKey(1) & 0xFF in (27, ord("q")):
                break

    except KeyboardInterrupt:
        logger.info("Ctrl+C detected. Shutting down gracefully...")

    finally:
        logger.info("Cleaning up...")
        try:
            cap.release()
        except Exception:
            pass
        cv2.destroyAllWindows()
        try:
            conn.close()
        except Exception:
            pass
        logger.info("Done.")

    if last_emb is not None:
        print("Embedding norm:", float(np.linalg.norm(last_emb)))
        print("Known embeddings loaded:", len(known))
        label, sim, sim2 = match_identity(last_emb, known)
        print("Match result:", label, sim, sim2)
    else:
        print("No embedding was computed (no valid face/track matched).")


if __name__ == "__main__":
    main()