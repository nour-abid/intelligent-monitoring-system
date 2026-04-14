"""
attendance_webcam.py
====================
Real-time employee attendance and activity monitoring pipeline.

Pipeline overview
-----------------
Camera (RTSP)
  └─► InsightFace buffalo_l ── face detection + 512-d embedding extraction
        └─► Quality gates   ── size / sharpness / aspect / brightness filters
              └─► DeepSORT  ── multi-person tracking with Kalman + appearance
                    └─► MiniFASNet ensemble ── liveness / anti-spoofing check
                          └─► Cosine similarity ── identity recognition
                                └─► YOLO11 ActivitySmoother ── activity classification
                                      └─► SQLite ── attendance logging

Key design choices
------------------
- InsightFace performs detection AND embedding in a single ``app.get()`` call,
  removing the need for a separate detector.
- Anti-spoofing runs every ``ANTI_SPOOF_EVERY_N`` frames (not every frame) to
  reduce GPU utilisation while maintaining reliable liveness decisions.
- Recognition runs every ``RECOG_EVERY_N`` frames; the last confirmed label is
  reused between runs to avoid redundant cosine-similarity computations.
- All per-track predictions use a fixed-length majority-vote deque to suppress
  single-frame noise (flicker).
- YOLO activity detection is matched to DeepSORT tracks via IoU overlap and
  further smoothed through ``ActivitySmoother``.
- RTSP stream includes pixel-signature frozen-frame detection and automatic
  reconnection on both packet loss and full stream failure.
- YOLO activity detection is optional: the rest of the pipeline runs normally
  when ``config["paths"]["activity_model"]`` is absent or the file is missing.

Configuration
-------------
All tuneable thresholds live under ``config["tuning"]`` (see config.py).
To enable activity detection after training finishes, add::

    config["paths"]["activity_model"] = "models/activity/best.pt"

Usage
-----
    python src/recognition/attendance_webcam.py      # script mode
    python -m src.recognition.attendance_webcam      # module mode

Author  : <your name>
Project : Intelligent Real-Time Workplace Monitoring System — PFE 2026
"""

from __future__ import annotations

import os
import sys
import time
from collections import Counter, deque
from pathlib import Path
from typing import Dict, Optional, Tuple

# ---------------------------------------------------------------------------
# Path bootstrap — make all sub-packages importable regardless of launch mode.
# ---------------------------------------------------------------------------
_SCRIPT_DIR   = Path(__file__).resolve().parent   # …/src/recognition
_SRC_DIR      = _SCRIPT_DIR.parent                # …/src
_PROJECT_ROOT = _SRC_DIR.parent                   # project root

for _p in (_SCRIPT_DIR, _SRC_DIR, _PROJECT_ROOT):
    if str(_p) not in sys.path:
        sys.path.insert(0, str(_p))

import cv2
import numpy as np
from insightface.app import FaceAnalysis

# ---------------------------------------------------------------------------
# Optional heavy dependencies — gracefully disabled when absent.
# ---------------------------------------------------------------------------
try:
    import torch
    TORCH_AVAILABLE = True
except ImportError:
    TORCH_AVAILABLE = False

try:
    from ultralytics import YOLO
    YOLO_AVAILABLE = True
except ImportError:
    YOLO_AVAILABLE = False

# ---------------------------------------------------------------------------
# Local imports — dual-mode support (script vs. module launch).
# ---------------------------------------------------------------------------
try:
    # Script mode: python src/recognition/attendance_webcam.py
    from config import config
    from logger import logger
    from database import init_db, load_today_checkins, log_checkin_once_per_day
    from src.recognition.recognition import load_known_embeddings, match_identity
    from liveness import (
        is_face_sharp,
        preprocess_face_for_antispoof,
        real_prob_from_ultralytics_result,
        real_prob_from_minifasnet_result,
        is_live_track,
    )
    from tracker import init_tracker, update_tracks, find_closest_detection, get_stable_label
    from activity_labels import map_activity
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
        is_live_track,
    )
    from src.recognition.tracker import (
        init_tracker, update_tracks, find_closest_detection, get_stable_label,
    )
    from src.recognition.activity_labels import map_activity
    from anti_spoof_predict import AntiSpoofPredict
    from generate_patches import CropImage

try:
    from src.utils.track_state import TrackStateStore
except ModuleNotFoundError:
    from utils.track_state import TrackStateStore

# ── Daily identity store — Camera A → Camera B runtime bridge ────────────────
# Non-fatal: if the surveillance package is not importable in this environment,
# check-in continues normally and the Camera B bridge is simply disabled.
try:
    from surveillance.shared.daily_identity_store import DailyIdentityStore as _DailyIdentityStore
    _DAILY_STORE_AVAILABLE = True
except Exception:
    _DailyIdentityStore    = None  # type: ignore[assignment,misc]
    _DAILY_STORE_AVAILABLE = False


# ===========================================================================
# Module-level constants
# ===========================================================================

# Maps YOLO class index → human-readable activity label.
# Must stay in sync with the class order used during training.
ACTIVITY_NAMES: Dict[int, str] = {
    0: "Meeting",
    1: "Idle",
    2: "Sleeping",
    3: "Talking_With_Phone",
    4: "Scrolling",
    5: "Working",
}

# BGR overlay colours (chosen for contrast on dark backgrounds).
_CLR_KNOWN    = (0,   255,   0)    # green   — identified live person
_CLR_UNKNOWN  = (0,   255, 255)    # yellow  — unidentified person
_CLR_FAKE     = (0,     0, 255)    # red     — spoof / liveness failure
_CLR_DEBUG    = (255,   0, 255)    # magenta — raw InsightFace bbox (debug)
_CLR_ACTIVITY = (255, 200,   0)    # gold    — activity label


# ===========================================================================
# ActivitySmoother
# ===========================================================================

class ActivitySmoother:
    """
    Per-track temporal majority-vote smoother for YOLO activity predictions.

    YOLO runs on individual frames, which can be noisy — a person Working may
    produce a single Scrolling prediction during a quick hand movement.
    ``ActivitySmoother`` buffers the last ``window`` raw predictions per track
    and returns the class that received the most votes, suppressing transient
    mis-classifications without introducing significant latency.

    Parameters
    ----------
    window : int
        Rolling buffer length in frames. At 25 fps, ``window=10`` covers ~0.4 s —
        responsive enough to catch genuine activity changes, stable enough to
        ignore single-frame outliers.

    Example
    -------
    >>> smoother = ActivitySmoother(window=5)
    >>> for cls in [5, 5, 1, 5, 5]:     # four "Working", one "Idle"
    ...     result = smoother.update(track_id=7, class_id=cls)
    >>> result
    5   # Working wins the majority vote
    """

    def __init__(self, window: int = 10) -> None:
        self._buffers: Dict[int, deque] = {}
        self.window = window

    # ------------------------------------------------------------------
    def update(self, track_id: int, class_id: int) -> int:
        """
        Append *class_id* to the buffer for *track_id* and return the winner.

        Parameters
        ----------
        track_id : int
            DeepSORT track identifier.
        class_id : int
            Raw YOLO class index predicted for this frame.

        Returns
        -------
        int
            Majority-voted (smoothed) class index.
        """
        if track_id not in self._buffers:
            self._buffers[track_id] = deque(maxlen=self.window)
        self._buffers[track_id].append(class_id)
        return Counter(self._buffers[track_id]).most_common(1)[0][0]

    # ------------------------------------------------------------------
    def clear(self, track_id: int) -> None:
        """Discard the buffer for a track that has been removed by DeepSORT."""
        self._buffers.pop(track_id, None)


# ===========================================================================
# MiniFASNet helpers
# ===========================================================================

def _safe_load_state_dict(
    model: "torch.nn.Module",
    ckpt_path: Path,
    device: "torch.device",
) -> "torch.nn.Module":
    """
    Load a MiniFASNet checkpoint into *model* robustly.

    Handles three common serialisation patterns produced by different training
    frameworks:

    1. **Plain state dict** — ``torch.load()`` returns a ``dict`` of tensors.
    2. **Wrapped dict** — checkpoint has a ``"state_dict"`` or ``"model"`` key.
    3. **DataParallel prefix** — keys start with ``"module."`` or ``"model."``.

    After loading, the model is set to evaluation mode.

    Parameters
    ----------
    model : torch.nn.Module
        Freshly instantiated MiniFASNet (weights not yet applied).
    ckpt_path : Path
        Path to the ``.pth`` weight file.
    device : torch.device
        Target device (``cuda`` or ``cpu``).

    Returns
    -------
    torch.nn.Module
        The same model instance with weights loaded, in eval mode.

    Raises
    ------
    RuntimeError
        If PyTorch is not installed.
    """
    if not TORCH_AVAILABLE:
        raise RuntimeError("PyTorch is required for MiniFASNet but is not installed.")

    # weights_only=True is the secure default in PyTorch ≥ 2.0; fall back for
    # older versions that do not support the keyword argument.
    try:
        ckpt = torch.load(str(ckpt_path), map_location=device, weights_only=True)
    except TypeError:
        ckpt = torch.load(str(ckpt_path), map_location=device)

    # Unwrap nested checkpoint formats.
    if isinstance(ckpt, dict):
        state = (
            ckpt.get("state_dict")
            or ckpt.get("model")
            or ckpt
        )
    else:
        state = ckpt

    # Strip DataParallel / model-wrapper key prefixes.
    cleaned = {
        k.removeprefix("module.").removeprefix("model."): v
        for k, v in state.items()
    }

    missing, unexpected = model.load_state_dict(cleaned, strict=False)
    model.eval()

    if missing:
        logger.warning(
            "MiniFASNet: %d missing key(s) — %s%s",
            len(missing), missing[:8], " …" if len(missing) > 8 else "",
        )
    if unexpected:
        logger.warning(
            "MiniFASNet: %d unexpected key(s) — %s%s",
            len(unexpected), unexpected[:8], " …" if len(unexpected) > 8 else "",
        )

    return model


# ---------------------------------------------------------------------------

def _build_minifasnet_models() -> dict:
    """
    Instantiate the two-model MiniFASNet anti-spoofing ensemble.

    The ensemble consists of:

    - **MiniFASNetV2**  (2.7× crop) — captures fine texture in a tight face crop.
    - **MiniFASNetV1SE** (4.0× crop) — captures context (forehead, shoulders) that
      reveals screen/print artefacts not visible in the tight crop.

    Averaging the two softmax outputs yields more robust liveness decisions than
    either model alone.

    Weight files are searched in the following directories (order matters):

    1. ``src/recognition/``
    2. ``src/anti_spoof_models/``
    3. ``<project_root>/resources/anti_spoof_models/``

    Returns
    -------
    dict
        ``type``          — ``"minifasnet_ensemble"``
        ``device``        — ``torch.device`` used
        ``models``        — ``[MiniFASNetV2_instance, MiniFASNetV1SE_instance]``
        ``weights``       — ``[str(path_v2), str(path_v1se)]``
        ``imported_from`` — resolved path of the MiniFASNet source file

    Raises
    ------
    RuntimeError
        If PyTorch is unavailable, MiniFASNet classes cannot be imported,
        or the weight files are not found in any searched directory.
    """
    if not TORCH_AVAILABLE:
        raise RuntimeError("PyTorch is required for MiniFASNet but is not installed.")

    script_dir    = Path(__file__).resolve().parent
    src_dir       = script_dir.parent
    project_root  = src_dir.parent
    model_lib_dir = src_dir / "model_lib"

    for _p in (script_dir, src_dir, model_lib_dir):
        if str(_p) not in sys.path:
            sys.path.insert(0, str(_p))

    # Try package import first; fall back to a local copy alongside this file.
    _errors: list[str] = []
    try:
        from model_lib.MiniFASNet import MiniFASNetV2, MiniFASNetV1SE
        imported_from = str(model_lib_dir / "MiniFASNet.py")
    except Exception as _e1:
        _errors.append(f"model_lib: {_e1}")
        try:
            from MiniFASNet import MiniFASNetV2, MiniFASNetV1SE  # type: ignore[no-redef]
            imported_from = str(script_dir / "MiniFASNet.py")
        except Exception as _e2:
            _errors.append(f"local: {_e2}")
            raise RuntimeError(
                "Cannot import MiniFASNet classes. Attempts: " + " | ".join(_errors)
            ) from _e2

    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    search_dirs = [
        script_dir,
        src_dir / "anti_spoof_models",
        project_root / "resources" / "anti_spoof_models",
    ]

    def _find(names: list[str]) -> Optional[Path]:
        for d in search_dirs:
            for name in names:
                p = d / name
                if p.exists():
                    return p
        return None

    w_v2   = _find(["2.7_80x80_MiniFASNetV2.pth"])
    w_v1se = _find(["4.0_0_80x80_MiniFASNetV1SE.pth", "4_0_0_80x80_MiniFASNetV1SE.pth"])

    if w_v2 is None or w_v1se is None:
        raise RuntimeError(
            f"MiniFASNet weight(s) missing — V2={w_v2}, V1SE={w_v1se}. "
            f"Searched: {[str(d) for d in search_dirs]}"
        )

    m_v2   = _safe_load_state_dict(
        MiniFASNetV2(conv6_kernel=5, num_classes=3).to(device), w_v2, device
    )
    m_v1se = _safe_load_state_dict(
        MiniFASNetV1SE(conv6_kernel=5, num_classes=3).to(device), w_v1se, device
    )

    logger.info("MiniFASNet ensemble loaded on %s: %s, %s", device, w_v2.name, w_v1se.name)
    logger.info("MiniFASNet source: %s", imported_from)

    return {
        "type":          "minifasnet_ensemble",
        "device":        device,
        "models":        [m_v2, m_v1se],
        "weights":       [str(w_v2), str(w_v1se)],
        "imported_from": imported_from,
    }


# ===========================================================================
# RTSP helpers
# ===========================================================================

def _open_rtsp(url: str) -> cv2.VideoCapture:
    """
    Open an RTSP stream with settings optimised for low-latency inference.

    Forces TCP transport via the FFMPEG environment variable, which is more
    reliable than the default UDP path on WiFi networks — especially important
    for H.264 streams where a single lost UDP packet corrupts an entire GOP.

    The internal OpenCV frame buffer is set to 3 to balance between underrun
    artefacts (buffer too small) and processing stale frames (buffer too large).

    Parameters
    ----------
    url : str
        Full RTSP URL, e.g. ``rtsp://admin:pass@192.168.1.10:554/stream1``.

    Returns
    -------
    cv2.VideoCapture
        Opened capture object (caller must check ``isOpened()``).
    """
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp"
    cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 3)

    try:
        cap.set(cv2.CAP_PROP_AUTOFOCUS, 0)  # disable autofocus where supported
    except Exception:
        pass

    return cap


# ===========================================================================
# Activity model loader
# ===========================================================================

def _load_activity_model() -> Optional["YOLO"]:
    """
    Load the YOLO11 activity-classification model if configured.

    Reads the model path from ``config["paths"]["activity_model"]``.  Returns
    ``None`` (and logs a warning) in any of these cases:

    - ``ultralytics`` is not installed.
    - The config key is absent (model not yet trained).
    - The file does not exist on disk.
    - The model fails to load for any reason.

    When ``None`` is returned, the calling code disables activity detection for
    the session while the rest of the pipeline continues normally.

    Returns
    -------
    YOLO | None
    """
    if not YOLO_AVAILABLE:
        logger.warning("ultralytics not installed — activity detection disabled.")
        return None

    raw = config.get("paths", {}).get("activity_model")
    if not raw:
        logger.warning("config['paths']['activity_model'] not set — activity detection disabled.")
        return None

    path = Path(raw)
    if not path.exists():
        logger.warning("Activity model not found at %s — disabled.", path)
        return None

    try:
        model = YOLO(str(path))
        logger.info("Activity model loaded: %s", path)
        return model
    except Exception as exc:
        logger.warning("Failed to load activity model (%s): %s", path, exc)
        return None


# ===========================================================================
# Visualisation helper
# ===========================================================================

def _draw_track(
    frame: np.ndarray,
    track: dict,
    track_id: int,
    activity_per_track: Dict[int, int],
    tuning: dict,
    anti_spoof_enabled: bool,
    checkin_time: Dict[str, str],
    activity_enabled: bool,
) -> None:
    """
    Render all per-track annotations onto *frame* (in-place).

    Layers drawn (top → bottom, relative to the bounding box):

    1. Identity label with cosine-similarity score and vote count  (above box)
    2. Liveness probability or ``Liveness:OFF``                    (just above box)
    3. Bounding rectangle (colour encodes state)
    4. Check-in timestamp                                          (below box)
    5. Activity label                                              (below check-in)

    Colour legend
    -------------
    Green  — identified, live person.
    Yellow — unidentified person (or anti-spoof disabled).
    Red    — spoof / liveness failure.

    Parameters
    ----------
    frame : np.ndarray
        BGR image to annotate (modified in-place).
    track : dict
        Mutable track-state dict from ``my_tracks``.
    track_id : int
        DeepSORT track identifier (used to look up activity).
    activity_per_track : dict
        Maps track_id → smoothed YOLO class index.
    tuning : dict
        Forwarded to ``is_live_track`` for threshold lookup.
    anti_spoof_enabled : bool
        When ``False``, liveness is assumed True for all tracks.
    checkin_time : dict
        Maps employee name → check-in timestamp string for display.
    activity_enabled : bool
        Whether a YOLO activity model is loaded for this session.
    """
    x1, y1 = int(track["bbox"]["x1"]), int(track["bbox"]["y1"])
    x2, y2 = int(track["bbox"]["x2"]), int(track["bbox"]["y2"])

    stable_label, vote_count = get_stable_label(track["votes"])
    sim       = track["last_sim"]
    sim2      = track["last_sim2"]
    real_prob = track.get("last_real_prob", 0.0)
    live_ok   = is_live_track(track, tuning) if anti_spoof_enabled else True

    # ── Colour & display label ────────────────────────────────────────
    if anti_spoof_enabled and not live_ok:
        color, display = _CLR_FAKE, "FAKE"
    elif stable_label != "Unknown":
        color, display = _CLR_KNOWN, stable_label
    else:
        color, display = _CLR_UNKNOWN, track.get("last_label", "Unknown")

    # ── Identity text (above box) ─────────────────────────────────────
    cv2.putText(
        frame,
        f"{display} ({sim:.2f}) margin={(sim - sim2):.2f} votes={vote_count}",
        (x1, max(0, y1 - 20)),
        cv2.FONT_HERSHEY_SIMPLEX, 0.6, color, 2,
    )

    # ── Liveness text (just above box) ───────────────────────────────
    if anti_spoof_enabled:
        lv_text  = f"Live:{real_prob:.2f}" if live_ok else f"FAKE:{real_prob:.2f}"
        lv_color = _CLR_KNOWN if live_ok else _CLR_FAKE
    else:
        lv_text, lv_color = "Liveness:OFF", _CLR_UNKNOWN

    cv2.putText(
        frame, lv_text,
        (x1, max(0, y1 - 5)),
        cv2.FONT_HERSHEY_SIMPLEX, 0.5, lv_color, 2,
    )

    # ── Bounding box ──────────────────────────────────────────────────
    cv2.rectangle(frame, (x1, y1), (x2, y2), color, 2)

    # ── Check-in timestamp (below box) ───────────────────────────────
    if stable_label in checkin_time and checkin_time[stable_label]:
        cv2.putText(
            frame, f"Checked-in: {checkin_time[stable_label]}",
            (x1, y2 + 18),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, _CLR_KNOWN, 2,
        )

    # ── Activity label (below check-in) ──────────────────────────────
    if activity_enabled and track_id in activity_per_track:
        act_name = map_activity(ACTIVITY_NAMES.get(activity_per_track[track_id], "Unknown"))
        cv2.putText(
            frame, f"Activity: {act_name}",
            (x1, y2 + 40),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, _CLR_ACTIVITY, 2,
        )


# ===========================================================================
# Main entry point
# ===========================================================================

def main() -> None:
    """
    Initialise all subsystems and run the real-time monitoring loop.

    Initialisation order
    --------------------
    1. Read tuning parameters from config.
    2. Load enrolled embeddings and today's attendance records from SQLite.
    3. Start InsightFace (CUDA → CPU fallback).
    4. Start DeepSORT tracker.
    5. Attempt to load YOLO activity model (non-fatal if absent).
    6. Attempt to load MiniFASNet ensemble (non-fatal; liveness disabled on failure).
    7. Open RTSP stream.
    8. Enter the main processing loop.

    The loop exits on:
    - ``q`` or ``Esc`` keypress.
    - ``KeyboardInterrupt`` (Ctrl-C).

    Resources (camera, OpenCV windows, DB connection) are always released in
    the ``finally`` block even if an exception propagates.
    """
    logger.info("=" * 60)
    logger.info("STARTING Intelligent Monitoring System")
    logger.info("  ROOT   : %s", _PROJECT_ROOT)
    logger.info("  EMB_DIR: %s", config["paths"]["emb_dir"])
    logger.info("  DB     : %s", config["paths"]["db_path"])
    logger.info("=" * 60)

    # ── Tuning parameters ────────────────────────────────────────────
    tuning = config.get("tuning", {})

    MAX_FACES            = int(tuning.get("max_faces",               5))
    DET_SCORE_THR        = float(tuning.get("det_score_thr",         0.55))
    DET_SCORE_THR_STRICT = float(tuning.get("det_score_thr_strict",  0.65))
    MIN_FACE_SIZE        = int(tuning.get("min_face_size",           60))
    MIN_FACE_SIZE_LIVE   = int(tuning.get("min_face_size_live",      90))
    MIN_FACE_ASPECT      = float(tuning.get("min_face_aspect",       0.65))
    MAX_FACE_ASPECT      = float(tuning.get("max_face_aspect",       1.60))
    MIN_FACE_AREA_RATIO  = float(tuning.get("min_face_area_ratio",   0.015))
    BLUR_THRESHOLD       = float(tuning.get("blur_threshold",        20.0))
    MIN_BRIGHTNESS       = float(tuning.get("min_brightness",        20.0))

    SMOOTH_WINDOW        = int(tuning.get("smooth_window",           10))
    SPOOF_WINDOW         = int(tuning.get("spoof_window",            10))
    ANTI_SPOOF_EVERY_N   = int(tuning.get("anti_spoof_every_n_frames", 5))
    RECOG_EVERY_N        = int(tuning.get("recog_every_n_frames",    5))
    ACTIVITY_EVERY_N     = int(tuning.get("activity_every_n_frames", 2))

    ABSENT_AFTER_SEC     = float(tuning.get("absent_after_sec",      2.0))
    PRESENT_AFTER_SEC    = float(tuning.get("present_after_sec",     1.0))
    MIN_HITS             = int(tuning.get("min_hits",                3))

    REC_MIN_SIM          = float(tuning.get("rec_min_sim",           0.72))
    REC_MIN_MARGIN       = float(tuning.get("rec_min_margin",        0.08))

    # ── Enrolled embeddings + attendance DB ─────────────────────────
    known = load_known_embeddings(config["paths"]["emb_dir"])
    logger.info("Enrolled identities: %s", list(known.keys()))

    conn = init_db()
    present_set, checkin_time = load_today_checkins(conn)

    # ── InsightFace ──────────────────────────────────────────────────
    # buffalo_l provides detection, landmark alignment, embedding, and
    # gender/age estimation in a single model pack. We only use detection
    # + normed_embedding; the other outputs are computed lazily.
    logger.info("Loading InsightFace buffalo_l …")
    try:
        app = FaceAnalysis(
            name="buffalo_l",
            providers=["CUDAExecutionProvider", "CPUExecutionProvider"],
        )
        app.prepare(ctx_id=0, det_size=(640, 640))
        logger.info("InsightFace running on CUDA.")
    except Exception as _e:
        logger.warning("CUDA unavailable (%s) — falling back to CPU.", _e)
        app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
        app.prepare(ctx_id=-1, det_size=(640, 640))
        logger.info("InsightFace running on CPU.")

    # ── DeepSORT tracker ─────────────────────────────────────────────
    deepsort = init_tracker()
    logger.info("DeepSORT tracker ready.")

    # ── YOLO activity detection (optional) ──────────────────────────
    activity_model    = _load_activity_model()
    ACTIVITY_ENABLED  = activity_model is not None
    activity_smoother = ActivitySmoother(window=10)
    logger.info("Activity detection: %s", "ENABLED" if ACTIVITY_ENABLED else "DISABLED")

    # ── MiniFASNet anti-spoofing ensemble (non-fatal) ────────────────
    spoof_model           = None
    ANTI_SPOOF_ENABLED    = False
    ANTI_SPOOF_MODEL_TYPE = None
    antispoof_predictor   = None
    cropper               = None

    try:
        spoof_model           = _build_minifasnet_models()
        ANTI_SPOOF_ENABLED    = True
        ANTI_SPOOF_MODEL_TYPE = "minifasnet"

        try:
            antispoof_predictor = AntiSpoofPredict(device_id=0)
        except Exception as _e:
            logger.warning("AntiSpoofPredict CUDA failed (%s) — using CPU.", _e)
            antispoof_predictor = AntiSpoofPredict(device_id=-1)

        cropper = CropImage()
        logger.info("Anti-spoofing ENABLED (MiniFASNet ensemble).")

    except Exception:
        logger.exception("MiniFASNet init failed — liveness checks are OFF for this session.")

    # ── Daily identity store (Camera A → Camera B bridge) ────────────────────
    # Initialised once per session.  Errors are caught so that a DB problem
    # never prevents attendance logging.
    _daily_store = None
    if _DAILY_STORE_AVAILABLE:
        try:
            _daily_store = _DailyIdentityStore()
            logger.info("DailyIdentityStore ready — check-in embeddings will be shared with Camera B.")
        except Exception:
            logger.warning(
                "DailyIdentityStore init failed — Camera B bridge is disabled for this session."
            )

    # ── RTSP stream ──────────────────────────────────────────────────
    rtsp_url = config["camera"]["rtsp_url"]
    cap      = _open_rtsp(rtsp_url)
    if not cap.isOpened():
        raise SystemExit(f"Cannot open RTSP stream: {rtsp_url}")

    logger.info(
        "Pipeline active: InsightFace → DeepSORT → MiniFASNet → YOLO → SQLite"
    )

    # ── Per-session state ────────────────────────────────────────────
    # my_tracks: track_id → {bbox, votes, last_label, last_sim, …}
    # activity_per_track: track_id → smoothed YOLO class index
    my_tracks:          Dict[int, dict] = {}
    activity_per_track: Dict[int, int]  = {}

    frame_count             = 0
    consecutive_frame_fails = 0
    _MAX_FRAME_FAILS        = 15
    last_emb: Optional[np.ndarray] = None   # retained only for post-run diagnostics

    _last_frame_sig: Optional[tuple] = None
    _same_frame_count = 0
    _MAX_SAME_FRAMES  = 20

    # ================================================================
    # Main loop
    # ================================================================
    try:
        while True:
            ok, frame = cap.read()

            # ── Frame validity gate ───────────────────────────
            if not ok or frame is None or not hasattr(frame, "shape") or frame.size == 0:
                consecutive_frame_fails += 1
                if consecutive_frame_fails % 5 == 1:
                    logger.warning(
                        "Invalid frame (%d/%d) — retrying…",
                        consecutive_frame_fails, _MAX_FRAME_FAILS,
                    )

                if consecutive_frame_fails > _MAX_FRAME_FAILS:
                    logger.warning("Stream loss — reconnecting RTSP…")
                    try:
                        cap.release()
                    except Exception:
                        pass
                    time.sleep(0.8)
                    cap = _open_rtsp(rtsp_url)
                    consecutive_frame_fails = 0
                    _same_frame_count       = 0
                    _last_frame_sig         = None
                    if not cap.isOpened():
                        logger.error("Reconnect failed — will retry next iteration.")
                        time.sleep(1.0)

                time.sleep(0.03)
                continue

            consecutive_frame_fails = 0
            h, w = frame.shape[:2]

            if h < 50 or w < 50:
                logger.warning("Frame too small (%dx%d) — skipping.", w, h)
                time.sleep(0.01)
                continue

            # ── Brightness gate ───────────────────────────────
            # Reject underexposed frames before running any model;
            # InsightFace degrades heavily under low-light conditions.
            try:
                if cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY).mean() < MIN_BRIGHTNESS:
                    if frame_count % 100 == 0:
                        logger.warning("Frame too dark — skipping inference.")
                    continue
            except Exception:
                pass

            # ── Frozen-stream detection ───────────────────────
            # A cheap pixel-signature check that catches RTSP streams
            # that have silently stopped updating their frame content.
            try:
                sig = (h, w,
                       int(frame[0, 0, 0]),
                       int(frame[h // 2, w // 2, 0]),
                       int(frame[-1, -1, 0]))

                if sig == _last_frame_sig:
                    _same_frame_count += 1
                else:
                    _same_frame_count = 0
                    _last_frame_sig   = sig

                if _same_frame_count > _MAX_SAME_FRAMES:
                    logger.warning("Frozen stream detected — reconnecting RTSP…")
                    try:
                        cap.release()
                    except Exception:
                        pass
                    time.sleep(0.5)
                    cap = _open_rtsp(rtsp_url)
                    _same_frame_count = 0
                    _last_frame_sig   = None
                    continue
            except Exception:
                pass

            now = time.time()
            frame_count += 1

            # ════════════════════════════════════════════════
            # Stage 1 — Face detection + embedding extraction
            # ════════════════════════════════════════════════
            _t0   = time.time()
            faces = app.get(frame, max_num=MAX_FACES)
            if frame_count % 100 == 0:
                logger.debug(
                    "InsightFace: %.1f ms — %d face(s) (frame %d)",
                    (time.time() - _t0) * 1000, len(faces), frame_count,
                )

            detections: list[dict] = []
            frame_area = max(1, h * w)

            for face in faces:
                if float(face.det_score) < DET_SCORE_THR:
                    continue

                x1, y1, x2, y2 = (int(v) for v in face.bbox)
                x1, y1 = max(0, x1), max(0, y1)
                x2, y2 = min(w - 1, x2), min(h - 1, y2)
                fw, fh = x2 - x1, y2 - y1

                # Magenta thin rect shows every raw InsightFace detection
                # (before quality gates) — useful during calibration.
                cv2.rectangle(frame, (x1, y1), (x2, y2), _CLR_DEBUG, 1)
                cv2.putText(
                    frame, f"det:{face.det_score:.2f}",
                    (x1, max(0, y1 - 5)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.45, _CLR_DEBUG, 1,
                )

                # ── Quality gates (cheapest first) ────────────
                if fw < MIN_FACE_SIZE or fh < MIN_FACE_SIZE:
                    continue
                if float(face.det_score) < DET_SCORE_THR_STRICT:
                    continue
                if fw < MIN_FACE_SIZE_LIVE or fh < MIN_FACE_SIZE_LIVE:
                    continue

                aspect = fw / max(1, fh)
                if not (MIN_FACE_ASPECT <= aspect <= MAX_FACE_ASPECT):
                    continue
                if (fw * fh) / frame_area < MIN_FACE_AREA_RATIO:
                    continue

                face_crop = frame[y1:y2, x1:x2]
                if face_crop.size == 0:
                    continue
                if not is_face_sharp(face_crop, blur_threshold=BLUR_THRESHOLD):
                    continue

                # normed_embedding is L2-normalised; fall back to raw embedding
                # if the attribute is absent (older insightface versions).
                emb = np.asarray(
                    getattr(face, "normed_embedding", face.embedding),
                    dtype=np.float32,
                ).reshape(-1)

                detections.append({
                    "x1": x1, "y1": y1, "x2": x2, "y2": y2,
                    "score": float(face.det_score),
                    "embedding": emb,
                })

            # ════════════════════════════════════════════════
            # Stage 2 — Multi-person tracking (DeepSORT)
            # ════════════════════════════════════════════════
            tracks      = update_tracks(deepsort, detections, frame)
            seen_tracks: set[int] = set()

            for track in tracks:
                if hasattr(track, "is_confirmed") and not track.is_confirmed():
                    continue

                track_id   = track.track_id
                ltrb       = track.to_ltrb()
                track_bbox = {
                    "x1": float(ltrb[0]), "y1": float(ltrb[1]),
                    "x2": float(ltrb[2]), "y2": float(ltrb[3]),
                }

                # Initialise state for newly seen tracks.
                if track_id not in my_tracks:
                    my_tracks[track_id] = {
                        "bbox":            track_bbox,
                        "votes":           deque(maxlen=SMOOTH_WINDOW),
                        "last_label":      "Unknown",
                        "last_sim":        0.0,
                        "last_sim2":       0.0,
                        "first_seen_time": now,
                        "last_seen_time":  now,
                        "spoof_votes":     deque(maxlen=SPOOF_WINDOW),
                        "last_real_prob":  0.0,
                        "embedding":       None,
                    }
                else:
                    my_tracks[track_id]["bbox"]           = track_bbox
                    my_tracks[track_id]["last_seen_time"] = now

                seen_tracks.add(track_id)
                my_track = my_tracks[track_id]

                # Match this track to the nearest InsightFace detection by centroid.
                det_match = find_closest_detection(track_bbox, detections)
                if not isinstance(det_match, dict):
                    continue

                # ════════════════════════════════════════════
                # Stage 3 — Liveness / anti-spoofing (MiniFASNet)
                # ════════════════════════════════════════════
                if frame_count % max(1, ANTI_SPOOF_EVERY_N) == 0:
                    dx1 = max(0, int(det_match["x1"]))
                    dy1 = max(0, int(det_match["y1"]))
                    dx2 = min(w - 1, int(det_match["x2"]))
                    dy2 = min(h - 1, int(det_match["y2"]))
                    face_crop = frame[dy1:dy2, dx1:dx2]

                    # Default to 1.0 (live) so that missing anti-spoof
                    # does not block attendance logging.
                    prob_real = 1.0

                    if ANTI_SPOOF_ENABLED and face_crop.size != 0:
                        try:
                            if ANTI_SPOOF_MODEL_TYPE == "minifasnet":
                                bbox_xywh = [dx1, dy1,
                                             max(1, dx2 - dx1),
                                             max(1, dy2 - dy1)]

                                # Two crops at different scales (see module docstring).
                                img1 = cropper.crop(
                                    org_img=frame, bbox=bbox_xywh,
                                    scale=2.7, out_w=80, out_h=80, crop=True,
                                )
                                img2 = cropper.crop(
                                    org_img=frame, bbox=bbox_xywh,
                                    scale=4.0, out_w=80, out_h=80, crop=True,
                                )

                                p1 = antispoof_predictor.predict(img1, spoof_model["weights"][0])
                                p2 = antispoof_predictor.predict(img2, spoof_model["weights"][1])

                                # Ensemble: sum softmax outputs then renormalise.
                                pred      = p1 + p2
                                pred      = pred / (pred.sum() + 1e-8)
                                prob_real = float(pred[0][1])  # index 1 = live class

                            elif ANTI_SPOOF_MODEL_TYPE == "ultralytics":
                                preprocessed = preprocess_face_for_antispoof(face_crop)
                                prob_real    = real_prob_from_ultralytics_result(
                                    spoof_model(preprocessed)
                                )

                        except Exception as _e:
                            logger.warning("Anti-spoof error (track %d): %s", track_id, _e)
                            prob_real = 0.0   # treat as spoof on error

                    my_track["last_real_prob"] = prob_real
                    my_track["spoof_votes"].append(prob_real)

                # Seed the spoof buffer on the very first frame when disabled
                # so that downstream ``is_live_track`` calls see a full buffer.
                if not ANTI_SPOOF_ENABLED and not my_track["spoof_votes"]:
                    my_track["spoof_votes"].append(1.0)
                    my_track["last_real_prob"] = 1.0

                live_ok = is_live_track(my_track, tuning) if ANTI_SPOOF_ENABLED else True

                # ════════════════════════════════════════════
                # Stage 4 — Identity recognition (throttled)
                # ════════════════════════════════════════════
                emb = det_match.get("embedding")
                if emb is None:
                    continue
                emb = np.asarray(emb, dtype=np.float32).reshape(-1)

                if frame_count % RECOG_EVERY_N == 0:
                    last_emb = emb   # saved for post-run diagnostics

                    label, sim, sim2 = match_identity(
                        emb, known,
                        sim_threshold=REC_MIN_SIM,
                        margin=REC_MIN_MARGIN,
                    )
                    margin = sim - sim2

                    # Reject weak or ambiguous matches.
                    if not label or label == "Unknown" \
                            or sim < REC_MIN_SIM or margin < REC_MIN_MARGIN:
                        label = "Unknown"

                    my_track.update(
                        last_label=label,
                        last_sim=float(sim),
                        last_sim2=float(sim2),
                        embedding=emb,
                    )

                # Vote with the most recently confirmed label.
                # Only confirmed + live detections contribute positive votes.
                vote = (
                    my_track["last_label"]
                    if my_track["last_label"] != "Unknown" and live_ok
                    else "Unknown"
                )
                my_track["votes"].append(vote)

            # ════════════════════════════════════════════════
            # Stage 5 — Activity detection (YOLO11, optional)
            # ════════════════════════════════════════════════
            if ACTIVITY_ENABLED and frame_count % ACTIVITY_EVERY_N == 0:
                try:
                    for box in activity_model(frame, verbose=False)[0].boxes:
                        if float(box.conf) < 0.30:
                            continue

                        bx1, by1, bx2, by2 = (int(v) for v in box.xyxy[0])
                        cls_id = int(box.cls)

                        # Match YOLO box to the DeepSORT track with the highest IoU.
                        best_tid: Optional[int] = None
                        best_iou = 0.20  # minimum acceptance threshold

                        for tid, tr in my_tracks.items():
                            tx1, ty1 = tr["bbox"]["x1"], tr["bbox"]["y1"]
                            tx2, ty2 = tr["bbox"]["x2"], tr["bbox"]["y2"]

                            inter_w = max(0, min(bx2, tx2) - max(bx1, tx1))
                            inter_h = max(0, min(by2, ty2) - max(by1, ty1))
                            inter   = inter_w * inter_h
                            union   = (
                                (bx2 - bx1) * (by2 - by1)
                                + (tx2 - tx1) * (ty2 - ty1)
                                - inter
                            )
                            iou = inter / max(1, union)

                            if iou > best_iou:
                                best_iou, best_tid = iou, tid

                        if best_tid is not None:
                            activity_per_track[best_tid] = \
                                activity_smoother.update(best_tid, cls_id)

                except Exception as _e:
                    logger.warning("Activity detection error: %s", _e)

            # ── Stale track removal ───────────────────────────
            for tid in list(my_tracks):
                if tid not in seen_tracks:
                    if now - my_tracks[tid]["last_seen_time"] > ABSENT_AFTER_SEC:
                        del my_tracks[tid]
                        activity_smoother.clear(tid)
                        activity_per_track.pop(tid, None)

            # ── Attendance logging ────────────────────────────
            for tid, track in my_tracks.items():
                stable_label, count = get_stable_label(track["votes"])
                live_ok = is_live_track(track, tuning) if ANTI_SPOOF_ENABLED else True

                if (
                    stable_label != "Unknown"
                    and count >= MIN_HITS
                    and live_ok
                    and now - track["first_seen_time"] >= PRESENT_AFTER_SEC
                    and stable_label not in present_set
                ):
                    ts = log_checkin_once_per_day(conn, stable_label)
                    present_set.add(stable_label)
                    checkin_time[stable_label] = ts
                    logger.info("CHECK-IN: %s at %s", stable_label, ts)

                    # ── Daily identity store hook ─────────────────────────
                    # Persist the fresh embedding so Camera B can use it for
                    # identity continuity throughout the day.  The guard on
                    # track["embedding"] makes this a no-op when the
                    # recognition stage has not yet run for this track.
                    if _daily_store is not None and track.get("embedding") is not None:
                        try:
                            _daily_store.add_identity(
                                employee_id=stable_label,
                                embedding=track["embedding"],
                                confidence=track["last_sim"],
                            )
                        except Exception:
                            logger.warning(
                                "DailyIdentityStore: failed to store embedding for %s.",
                                stable_label,
                            )

            # ── Overlay rendering ─────────────────────────────
            for tid, track in my_tracks.items():
                _draw_track(
                    frame=frame,
                    track=track,
                    track_id=tid,
                    activity_per_track=activity_per_track,
                    tuning=tuning,
                    anti_spoof_enabled=ANTI_SPOOF_ENABLED,
                    checkin_time=checkin_time,
                    activity_enabled=ACTIVITY_ENABLED,
                )

            # HUD status bar (top-left corner)
            cv2.putText(
                frame,
                (f"Tracks: {len(my_tracks)}  |  Frame: {frame_count}  |  "
                 f"Present: {', '.join(sorted(present_set)) or 'None'}"),
                (20, 30),
                cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2,
            )

            cv2.imshow("Intelligent Monitoring System", frame)
            if cv2.waitKey(1) & 0xFF in (27, ord("q")):
                logger.info("Exit key pressed — stopping.")
                break

    except KeyboardInterrupt:
        logger.info("KeyboardInterrupt — shutting down gracefully.")

    finally:
        logger.info("Releasing resources…")
        try:
            cap.release()
        except Exception:
            pass
        cv2.destroyAllWindows()
        try:
            conn.close()
        except Exception:
            pass
        logger.info("Shutdown complete.")

    # ── Post-run diagnostics ──────────────────────────────────────────
    # Logged at DEBUG level so they never appear in production logs but
    # are available when diagnosing recognition accuracy issues.
    if last_emb is not None:
        label, sim, sim2 = match_identity(last_emb, known)
        logger.debug(
            "Last embedding — norm=%.4f  match=%s  sim=%.3f  margin=%.3f",
            float(np.linalg.norm(last_emb)), label, sim, sim - sim2,
        )
    else:
        logger.debug("No embeddings were processed in this session.")


# ===========================================================================
# Entry point
# ===========================================================================
if __name__ == "__main__":
    main()