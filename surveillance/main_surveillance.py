"""
Camera B - Surveillance / Activity Monitor
==========================================

Loads the YOLO activity model (``surveillance/models/best.pt``), tracks
persons with DeepSORT, maintains per-track state (identity + current
activity + duration) via the supporting modules, and logs every activity
state-change to a CSV file.

Nothing in this file touches the facial check-in pipeline (src/recognition/).
Identity defaults to ``"Unknown"``.  Face-based identity matching is wired
in through ``identity_matcher.py`` which is currently a no-op stub.

Run
---
    python -m surveillance.main_surveillance
or
    python surveillance/main_surveillance.py
"""

from __future__ import annotations

import logging
import sys
import time
from pathlib import Path
from typing import Dict, List, Optional

import cv2
import yaml
from ultralytics import YOLO

# ---------------------------------------------------------------------------
# Path setup --- makes ``surveillance.*`` importable regardless of cwd
# ---------------------------------------------------------------------------
_HERE = Path(__file__).resolve().parent        # .../surveillance/
_ROOT = _HERE.parent                            # project root

for _p in [str(_ROOT), str(_ROOT / "src"), str(_HERE.parent)]:
    if _p not in sys.path:
        sys.path.insert(0, _p)

# ---------------------------------------------------------------------------
# Surveillance-internal imports (no check-in code imported here)
# ---------------------------------------------------------------------------
from surveillance.tracker import init_activity_tracker, update_tracks_activity
from surveillance.state_manager import StateManager, SurveillanceTrackState
from surveillance.activity_logic import should_switch_activity
from surveillance.repositories.surveillance_repository import SurveillanceRepository
from surveillance.identity_matcher import IdentityMatcher
from surveillance.clip_writer import ClipManager

# Optional: checked-in identity bridge (Camera A → Camera B).
# Guarded so that missing/unavailable store never breaks the surveillance loop.
try:
    from surveillance.shared.daily_identity_store import DailyIdentityStore as _DailyIdentityStore
    _DAILY_STORE_AVAILABLE = True
except Exception:
    _DailyIdentityStore = None  # type: ignore[assignment,misc]
    _DAILY_STORE_AVAILABLE = False

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s --- %(message)s",
)
logging.getLogger("surveillance.identity").setLevel(logging.DEBUG)
log = logging.getLogger("surveillance")

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
DEFAULT_ACTIVITY_CLASSES: Dict[int, str] = {
    0: "Inactive",
    1: "Using_Phone",
    2: "Working",
}

# BGR colours per activity for the overlay rectangle
_ACTIVITY_COLORS: Dict[str, tuple] = {
    "Inactive":    (128, 128, 128),   # grey
    "Using_Phone": (  0,   0, 255),   # red
    "Working":     (  0, 255,   0),   # green
    "Unknown":     (  0, 255, 255),   # yellow
}


# ===========================================================================
# Settings
# ===========================================================================

def _load_settings(path: Path) -> dict:
    with open(path, "r", encoding="utf-8") as fh:
        return yaml.safe_load(fh) or {}


# ===========================================================================
# Video capture
# ===========================================================================

def _open_capture(source, retry_interval_sec: float = 5.0) -> cv2.VideoCapture:
    """Open a webcam index, RTSP, HTTP/IP-Webcam, or file path.

    HTTP streams (IP Webcam) and RTSP streams use CAP_FFMPEG with a
    reduced buffer to minimise latency.  Falls back to a plain index
    for integer sources.  Retries indefinitely on failure, logging
    each attempt, so the process stays alive while the camera is
    temporarily unreachable.
    """
    import os

    is_network = isinstance(source, str) and (
        source.lower().startswith("rtsp")
        or source.lower().startswith("http")
    )

    while True:
        try:
            if is_network:
                if source.lower().startswith("rtsp"):
                    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp"
                else:
                    # HTTP / MJPEG — tell FFmpeg not to buffer input frames.
                    # Without fflags=nobuffer, FFmpeg queues several decoded
                    # MJPEG frames internally; by the time the main loop calls
                    # cap.read() again, those stale frames are returned first
                    # causing visible action-display lag.
                    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = (
                        "fflags;nobuffer|analyzeduration;0|probesize;32"
                    )
                cap = cv2.VideoCapture(source, cv2.CAP_FFMPEG)
            else:
                idx = int(source) if str(source).lstrip("-").isdigit() else source
                cap = cv2.VideoCapture(idx)

            if cap.isOpened():
                cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                log.info("Video source opened: %s", source)
                return cap

            cap.release()
        except Exception as exc:
            log.error("Exception while opening video source %r: %s", source, exc)

        log.warning(
            "Cannot open video source %r — retrying in %.0f s ...",
            source, retry_interval_sec,
        )
        time.sleep(retry_interval_sec)


# ===========================================================================
# Overlay drawing
# ===========================================================================

def _fmt_duration(seconds: float) -> str:
    """Format seconds as HH:MM:SS."""
    s = int(seconds)
    h, rem = divmod(s, 3600)
    m, sec = divmod(rem, 60)
    return f"{h:02d}:{m:02d}:{sec:02d}"


def _draw_overlays(
    frame,
    states: Dict[int, SurveillanceTrackState],
    line_thickness: int,
    font_scale: float,
) -> None:
    """Draw bounding boxes and activity labels for all active tracks."""
    H, W = frame.shape[:2]
    
    for tid, state in states.items():
        if state.bbox is None:
            continue

        x1, y1, x2, y2 = (int(v) for v in state.bbox)
        duration_str = _fmt_duration(time.time() - state.activity_start_time)
        label = (
            f"Track {tid} | {state.identity_name} "
            f"| {state.current_activity} | {duration_str}"
        )

        color = _ACTIVITY_COLORS.get(state.current_activity, _ACTIVITY_COLORS["Unknown"])

        # Bounding box
        cv2.rectangle(frame, (x1, y1), (x2, y2), color, line_thickness)

        # Text background for readability
        (tw, th), baseline = cv2.getTextSize(
            label, cv2.FONT_HERSHEY_SIMPLEX, font_scale, 1
        )
        
        # Compute label position with boundary clipping
        # Try to place above the box first
        text_x = x1 + 2
        text_y = y1 - 6
        
        # Text background box corners (top-left to bottom-right)
        bg_left = text_x - 2
        bg_right = text_x + tw + 2
        bg_top = text_y - th - baseline - 2
        bg_bottom = text_y + baseline
        
        # Adjust horizontally if text overflows right edge
        if bg_right > W:
            overshoot = bg_right - W
            text_x = max(2, text_x - overshoot)
            bg_left = text_x - 2
            bg_right = text_x + tw + 2
        
        # Adjust vertically: if above top, move below the bbox instead
        if bg_top < 0:
            text_y = y2 + 6 + th
            bg_top = text_y - th - baseline - 2
            bg_bottom = text_y + baseline
            # Clamp bottom if it overflows
            if bg_bottom > H:
                text_y = max(th + 6, H - baseline)
                bg_top = text_y - th - baseline - 2
                bg_bottom = text_y + baseline
        
        # Draw background rectangle
        cv2.rectangle(
            frame,
            (int(bg_left), int(bg_top)),
            (int(bg_right), int(bg_bottom)),
            (0, 0, 0),
            -1,
        )
        
        # Draw text
        cv2.putText(
            frame,
            label,
            (int(text_x), int(text_y)),
            cv2.FONT_HERSHEY_SIMPLEX,
            font_scale,
            color,
            1,
            cv2.LINE_AA,
        )


# ===========================================================================
# Activity---track matching
# ===========================================================================

def _bbox_iou(a: tuple, b: tuple) -> float:
    """Compute IoU between two (x1, y1, x2, y2) bounding boxes."""
    ix1 = max(a[0], b[0])
    iy1 = max(a[1], b[1])
    ix2 = min(a[2], b[2])
    iy2 = min(a[3], b[3])
    if ix2 <= ix1 or iy2 <= iy1:
        return 0.0
    inter = (ix2 - ix1) * (iy2 - iy1)
    area_a = max(1.0, (a[2] - a[0]) * (a[3] - a[1]))
    area_b = max(1.0, (b[2] - b[0]) * (b[3] - b[1]))
    return inter / max(1.0, area_a + area_b - inter)


def _match_activity_to_track(
    track_bbox: tuple,
    raw_dets: list,
    iou_threshold: float = 0.45,
    exclusive_owner: dict | None = None,
    track_id: int | None = None,
) -> str:
    """Return the activity label from the raw detection with highest IoU.

    Two stricter rules over the original implementation:

    1. **Center-containment guard** — the detection centroid must lie inside
       the track bbox.  A neighbour sitting close by can overlap 20-30% of
       the track box, but the *centre* of their detection almost never falls
       inside the other person's box.  Requiring containment eliminates
       the dominant bleed path.

    2. **Exclusive-ownership check** — if ``exclusive_owner`` is supplied
       (a dict mapping det_index → best_track_id built before the per-track
       loop), only the detection whose best-matching track is *this* track
       is eligible.  This prevents two adjacent tracks from both claiming
       the same detection.

    Falls back to ``"Unknown"`` when no detection passes both gates.
    """
    if not raw_dets:
        return "Unknown"

    tx1, ty1, tx2, ty2 = track_bbox
    best_iou = 0.0
    best_activity = "Unknown"

    for di, det in enumerate(raw_dets):
        # Exclusive-ownership gate: skip this detection if it belongs
        # to a different track.
        if exclusive_owner is not None and track_id is not None:
            if exclusive_owner.get(di) != track_id:
                continue

        # Center-containment guard: detection centroid must be inside track box.
        dc_x = (det["x1"] + det["x2"]) * 0.5
        dc_y = (det["y1"] + det["y2"]) * 0.5
        if not (tx1 <= dc_x <= tx2 and ty1 <= dc_y <= ty2):
            continue

        ix1 = max(tx1, det["x1"])
        iy1 = max(ty1, det["y1"])
        ix2 = min(tx2, det["x2"])
        iy2 = min(ty2, det["y2"])

        if ix2 <= ix1 or iy2 <= iy1:
            continue

        inter = (ix2 - ix1) * (iy2 - iy1)
        area_t = max(1.0, (tx2 - tx1) * (ty2 - ty1))
        area_d = max(1.0, (det["x2"] - det["x1"]) * (det["y2"] - det["y1"]))
        iou = inter / max(1.0, area_t + area_d - inter)

        if iou > best_iou:
            best_iou = iou
            best_activity = det["activity"]

    return best_activity if best_iou >= iou_threshold else "Unknown"


# ===========================================================================
# Main loop
# ===========================================================================

def main() -> None:
    settings_path = _HERE / "config" / "settings.yaml"
    cfg = _load_settings(settings_path)

    # --- Config values ---
    video_source        = cfg.get("video_source", 0)
    model_path          = _ROOT / cfg.get("model_path", "surveillance/models/best.pt")
    conf_threshold      = float(cfg.get("conf_threshold", 0.4))
    activity_conf_threshold = float(cfg.get("activity_conf_threshold", 0.35))
    activity_conf_overrides: dict = {
        k: float(v)
        for k, v in cfg.get("activity_conf_overrides", {}).items()
    }
    imgsz               = int(cfg.get("imgsz", 640))
    frame_drain_count   = int(cfg.get("frame_drain_count", 4))
    activity_iou_thr    = float(cfg.get("activity_iou_threshold", 0.45))
    activity_stable_frames = int(cfg.get("activity_stable_frames", 8))
    working_to_inactive_extra = int(cfg.get("working_to_inactive_extra_frames", 3))
    working_phone_extra        = int(cfg.get("working_phone_extra_frames", 2))
    unknown_inactive_extra     = int(cfg.get("unknown_inactive_extra_frames", 2))
    track_max_missing   = int(cfg.get("track_max_missing_frames", 30))
    tracker_n_init      = int(cfg.get("tracker_n_init", 2))
    save_csv            = bool(cfg.get("save_log_csv", True))
    log_csv_path        = _ROOT / cfg.get("log_csv_path", "logs/surveillance_events.csv")
    show_window         = bool(cfg.get("show_window", True))
    line_thickness      = int(cfg.get("line_thickness", 2))
    font_scale          = float(cfg.get("font_scale", 0.6))
    debug_overlay       = bool(cfg.get("debug_overlay", False))
    arcface_model       = cfg.get("arcface_model_path", "models/arcface/arcface.onnx")
    identity_emb_dir    = cfg.get("identity_embeddings_dir", "models/embeddings")
    id_threshold        = float(cfg.get("identity_match_threshold", 0.50))
    id_margin           = float(cfg.get("identity_match_margin", 0.06))
    face_every_n        = int(cfg.get("face_every_n_frames", 30))
    bbox_expand_ratio   = float(cfg.get("bbox_expand_ratio", 0.2))
    min_person_crop_px  = int(cfg.get("min_person_crop_px", 60))
    face_fail_cooldown_threshold = int(cfg.get("face_fail_cooldown_threshold", 3))
    face_fail_cooldown_frames    = int(cfg.get("face_fail_cooldown_frames", 60))
    reassoc_max_gap     = float(cfg.get("reassoc_max_gap_sec", 5.0))
    reassoc_max_dist    = float(cfg.get("reassoc_max_center_dist_px", 200.0))
    reassoc_min_score   = float(cfg.get("reassoc_min_score", 0.25))
    reassoc_fc_max_gap  = float(cfg.get("reassoc_face_confirmed_max_gap_sec", 15.0))
    reassoc_fc_min_score = float(cfg.get("reassoc_face_confirmed_min_score", 0.15))
    track_maturity_frames     = int(cfg.get("track_maturity_frames", 10))
    checkedin_refresh_sec = float(cfg.get("daily_store_refresh_sec", 60.0))

    # Activity class map: normalise keys to int
    raw_classes = cfg.get("activity_classes", DEFAULT_ACTIVITY_CLASSES)
    activity_classes: Dict[int, str] = {
        int(k): str(v) for k, v in raw_classes.items()
    }

    # --- Model ---
    if not Path(model_path).exists():
        raise FileNotFoundError(
            f"Activity model not found: {model_path}\n"
            "Place best.pt at surveillance/models/best.pt or update "
            "settings.yaml --- model_path"
        )
    yolo_activity = YOLO(str(model_path))
    log.info(f"Activity model loaded: {model_path}")
    # Always derive class names from the loaded model so the mapping
    # stays consistent regardless of what is written in settings.yaml.
    # This fixes decoding when the model is replaced (e.g. 4-class → 3-class).
    activity_classes = {int(k): str(v) for k, v in yolo_activity.model.names.items()}
    log.info("Activity classes from model: %s", activity_classes)

    # --- DeepSORT tracker ---
    tracker = init_activity_tracker(
        max_age=max(track_max_missing, 30),
        n_init=tracker_n_init,
    )
    log.info("DeepSORT activity tracker initialized")

    # --- Reassociation cache ---
    from surveillance.state_manager import ReassociationCache
    reassoc_cache = ReassociationCache(
        max_gap_sec=reassoc_max_gap,
        max_center_dist_px=reassoc_max_dist,
        min_score=reassoc_min_score,
        face_confirmed_max_gap_sec=reassoc_fc_max_gap,
        face_confirmed_min_score=reassoc_fc_min_score,
    )

    # --- State manager ---
    state_mgr = StateManager(
        max_missing_frames=track_max_missing,
        reassoc_cache=reassoc_cache,
    )

    # --- Checked-in identity store (read-only; used to feed StateManager) ---
    _checkedin_store = None
    if _DAILY_STORE_AVAILABLE:
        try:
            _checkedin_store = _DailyIdentityStore()
            log.info("DailyIdentityStore opened for checked-in cohort tracking.")
        except Exception as _exc:
            log.warning("DailyIdentityStore unavailable; checked-in persistence inactive: %s", _exc)

    # --- Identity matcher ---
    identity_matcher = IdentityMatcher(
        embeddings_dir=identity_emb_dir,
        model_path=arcface_model,
        threshold=id_threshold,
        margin=id_margin,
        face_every_n_frames=face_every_n,
        bbox_expand_ratio=bbox_expand_ratio,
        min_person_crop_px=min_person_crop_px,
        face_fail_cooldown_threshold=face_fail_cooldown_threshold,
        face_fail_cooldown_frames=face_fail_cooldown_frames,
    )

    # --- Event repository (CSV backend by default) ---
    event_logger: Optional[SurveillanceRepository] = None
    if save_csv:
        event_logger = SurveillanceRepository(log_csv_path)

    # --- Alert clip capture ---
    clip_mgr: Optional[ClipManager] = None
    if bool(cfg.get("clip_capture_enabled", True)):
        clip_mgr = ClipManager(cfg, video_source)

    # --- Video capture ---
    cap = _open_capture(video_source)

    log.info(
        "[SURVEILLANCE] runtime — imgsz=%d  conf=%.2f  face_every_n=%d  "
        "frame_drain_count=%d  track_maturity=%d  activity_stable=%d  "
        "activity_iou_thr=%.2f  working_to_inactive_extra=%d  "
        "working_phone_extra=%d  unknown_inactive_extra=%d",
        imgsz, conf_threshold, face_every_n,
        frame_drain_count, track_maturity_frames, activity_stable_frames,
        activity_iou_thr, working_to_inactive_extra, working_phone_extra,
        unknown_inactive_extra,
    )

    frame_count = 0
    fps_timer   = time.time()
    fps_text    = "FPS: --"
    consecutive_failures = 0
    # Trigger an immediate refresh on the first processed frame.
    _checkedin_last_refresh: float = 0.0
    # Heartbeat: write in-progress suspicious events to DB periodically so
    # the alert evaluator can query them without waiting for track_lost.
    _heartbeat_last: float = 0.0
    _heartbeat_interval: float = float(cfg.get("alert_flush_interval_sec", 25.0))

    try:
        while True:
            ok, frame = cap.read()
            if not ok or frame is None:
                consecutive_failures += 1
                if consecutive_failures >= 3:
                    log.warning(
                        "Stream dropped after %d consecutive failures — "
                        "attempting reconnect to %r ...",
                        consecutive_failures, video_source,
                    )
                    cap.release()
                    cap = _open_capture(video_source)
                    consecutive_failures = 0
                else:
                    log.warning(f"Frame read failed ({consecutive_failures}/3); retrying...")
                time.sleep(0.05)
                continue

            consecutive_failures = 0

            # Drain stale frames buffered while the previous iteration was
            # processing.  cap.grab() on an FFmpeg-buffered MJPEG frame
            # returns in <1 ms.  Once the internal queue is empty it has to
            # wait for the camera (~33 ms at 30 fps).  We use that timing
            # gap as the stop signal so we never block here longer than one
            # live-frame interval.
            _drained = 0
            while _drained < frame_drain_count:
                _grab_t0 = time.perf_counter()
                if not cap.grab():
                    break
                if time.perf_counter() - _grab_t0 > 0.002:  # >2 ms → waited for live frame
                    break
                _drained += 1
            if _drained:
                _, frame = cap.retrieve()

            # Pre-event ring buffer for alert clip capture.
            if clip_mgr is not None:
                clip_mgr.push_frame(frame)

            frame_count += 1
            H, W = frame.shape[:2]

            # -- FPS calculation (every 30 frames) --
            if frame_count % 30 == 0:
                elapsed = time.time() - fps_timer
                fps_text = f"FPS: {30 / max(elapsed, 1e-6):.1f}"
                fps_timer = time.time()

            # -- Checked-in cohort refresh (time-gated) --
            _ts_now = time.time()
            if _checkedin_store is not None and (_ts_now - _checkedin_last_refresh) >= checkedin_refresh_sec:
                try:
                    _daily_profiles = _checkedin_store.get_identities_for_today()
                    _names = set(_daily_profiles.keys())
                    state_mgr.update_checkedin_names(_names)
                    log.info(
                        "[CHECKEDIN] cohort refreshed: %d identity/-ies: %s",
                        len(_names), sorted(_names) or "(none)",
                    )
                except Exception as _exc:
                    log.warning("[CHECKEDIN] refresh failed (persistence inactive this cycle): %s", _exc)
                _checkedin_last_refresh = _ts_now

            # ------ A. YOLO activity inference ------------------------------------------------------------------------------------------
            raw_dets: List[dict] = []
            try:
                results = yolo_activity.predict(
                    frame, imgsz=imgsz, conf=conf_threshold, verbose=False
                )[0]
            except Exception as exc:
                log.warning(f"YOLO inference error (skipping frame): {exc}")
                results = None

            if results is not None and results.boxes is not None:
                for b in results.boxes:
                    cls_idx = int(b.cls[0])
                    score   = float(b.conf[0])
                    
                    # Reject weak activity predictions below per-class or global threshold.
                    cls_name = activity_classes.get(cls_idx, "Unknown")
                    required_conf = activity_conf_overrides.get(
                        cls_name, activity_conf_threshold
                    )
                    if score < required_conf:
                        activity_label = "Unknown"
                    else:
                        activity_label = cls_name
                    
                    x1, y1, x2, y2 = b.xyxy[0].tolist()

                    # Clamp to frame bounds
                    x1 = max(0.0, min(float(W - 1), x1))
                    y1 = max(0.0, min(float(H - 1), y1))
                    x2 = max(0.0, min(float(W - 1), x2))
                    y2 = max(0.0, min(float(H - 1), y2))

                    raw_dets.append({
                        "x1": x1, "y1": y1, "x2": x2, "y2": y2,
                        "score": score,
                        "raw_label": cls_name,
                        "activity": activity_label,
                    })
                    
                    # Debug overlay: raw YOLO class/confidence
                    if debug_overlay:
                        x1i, y1i = int(x1), int(y1)
                        debug_txt = f"[{cls_idx}] {score:.2f}"
                        cv2.putText(
                            frame, debug_txt,
                            (x1i, y1i - 3),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.4, (200, 200, 200), 1
                        )

            # ------ B. Track ------------------------------------------------------------------------------------------------------------------------------------------------
            tracks = update_tracks_activity(tracker, raw_dets, frame)

            # Build exclusive-ownership map: for each raw detection, record
            # which confirmed track has the highest IoU.  A detection is
            # later only eligible for that one track — no other track may
            # claim it, preventing the same YOLO box from assigning identical
            # activity to two neighbouring persons.
            _det_owner: dict = {}  # det_index -> track_id of best-matching track
            if raw_dets:
                _det_best_iou: dict = {}   # det_index -> best IoU seen so far
                for _tr in tracks:
                    if hasattr(_tr, "is_confirmed") and not _tr.is_confirmed():
                        continue
                    _ltrb = _tr.to_ltrb()
                    _tbbox = (float(_ltrb[0]), float(_ltrb[1]),
                              float(_ltrb[2]), float(_ltrb[3]))
                    _tx1, _ty1, _tx2, _ty2 = _tbbox
                    for _di, _det in enumerate(raw_dets):
                        _ix1 = max(_tx1, _det["x1"])
                        _iy1 = max(_ty1, _det["y1"])
                        _ix2 = min(_tx2, _det["x2"])
                        _iy2 = min(_ty2, _det["y2"])
                        if _ix2 <= _ix1 or _iy2 <= _iy1:
                            continue
                        _inter = (_ix2 - _ix1) * (_iy2 - _iy1)
                        _area_t = max(1.0, (_tx2 - _tx1) * (_ty2 - _ty1))
                        _area_d = max(1.0, (_det["x2"] - _det["x1"]) * (_det["y2"] - _det["y1"]))
                        _iou = _inter / max(1.0, _area_t + _area_d - _inter)
                        if _iou > _det_best_iou.get(_di, 0.0):
                            _det_best_iou[_di] = _iou
                            _det_owner[_di] = _tr.track_id

            active_ids: set = set()
            now = time.time()

            for tr in tracks:
                if hasattr(tr, "is_confirmed") and not tr.is_confirmed():
                    continue

                tid  = tr.track_id
                ltrb = tr.to_ltrb()
                bbox = (float(ltrb[0]), float(ltrb[1]),
                        float(ltrb[2]), float(ltrb[3]))

                active_ids.add(tid)

                # ------ C. State update ---------------------------------------------------------------------------------------------------------------
                is_new = tid not in state_mgr._states
                state = state_mgr.get_or_create(tid)
                state.bbox           = bbox
                state.last_seen_time = now

                # Short-term reassociation for brand-new Unknown tracks
                if is_new and state.identity_name == "Unknown":
                    reassoc_cache.try_reassociate(state)

                # ------ C1. Detector label -----------------------------------------------
                detected_act = _match_activity_to_track(
                    bbox, raw_dets,
                    iou_threshold=activity_iou_thr,
                    exclusive_owner=_det_owner,
                    track_id=tid,
                )
                # When no YOLO detection overlaps this track (IoU < threshold),
                # _match_activity_to_track returns "Unknown".  Passing that
                # "Unknown" to the smoother treats it as a new challenger and
                # resets pending_count, breaking any in-progress streak for the
                # real label.  Instead, hold the pending challenger (or the
                # current confirmed activity) so detection gaps are transparent
                # to the streak counter.
                if detected_act == "Unknown":
                    detected_act = (
                        state.pending_activity
                        if state.pending_activity not in (None, "Unknown")
                        else state.current_activity
                    )

                # ------ C2. Smoothed / committed label -----------------------------------------
                committed_act = should_switch_activity(
                    state, detected_act, activity_stable_frames,
                    working_to_inactive_extra=working_to_inactive_extra,
                    working_phone_extra=working_phone_extra,
                    unknown_identity_inactive_extra=unknown_inactive_extra,
                    identity_known=(state.identity_name != "Unknown"),
                )

                # ------ C2b. Track maturity gate -----------------------------------------
                # Fresh tracks (tr.hits < track_maturity_frames) have unreliable
                # detections: partial bboxes, degenerate crops, face-too-small.
                # Suppress activity *transitions* on immature tracks to prevent
                # visible flicker — but only when the track already has a known
                # current activity.  If the track is still "Unknown", allow
                # the first stable non-Unknown result through so the track can
                # surface its activity instead of staying Unknown indefinitely.
                if tr.hits < track_maturity_frames:
                    if state.current_activity != "Unknown":
                        # Track has a confirmed label — don't let noisy frames
                        # switch it before maturity.
                        committed_act = state.current_activity
                    # else: current_activity is "Unknown" — let the smoother's
                    # result through so the first stable label can surface.

                # ------ C3. Final activity — direct from smoother -------------------------
                # Meeting is no longer emitted by surveillance; activity comes
                # solely from the 3-class detector (Inactive / Using_Phone / Working).
                final_activity = committed_act

                # Debug log — emitted at DEBUG level every frame per track
                _raw_match = max(
                    raw_dets,
                    key=lambda d: _bbox_iou(bbox, (d["x1"], d["y1"], d["x2"], d["y2"])),
                    default=None,
                )
                _raw_label = _raw_match["raw_label"] if _raw_match else "Unknown"
                _raw_conf  = _raw_match["score"]     if _raw_match else 0.0
                log.debug(
                    "[ACT] track_%d | raw=%s/%.2f | detector=%s | final=%s",
                    tid, _raw_label, _raw_conf, detected_act, final_activity,
                )

                # Capture current activity before potential transition for clip manager.
                _prev_activity = state.current_activity

                # If activity changed, close old event and start new
                if final_activity != state.current_activity:
                    closed = state.close_current_activity(end_time=now)
                    if closed is not None and event_logger is not None:
                        event_logger.log_event(
                            track_id=tid,
                            identity_name=state.identity_name,
                            activity=closed.activity,
                            start_time=closed.start_time,
                            end_time=closed.end_time,
                            identity_confidence=state.identity_confidence,
                            identity_source=state.identity_source,
                            event_trigger="activity_change",
                        )
                    state.start_activity(final_activity, start_time=now)

                # Notify clip manager each frame for every confirmed track.
                if clip_mgr is not None:
                    clip_mgr.on_track_update(
                        track_id=tid,
                        identity_name=state.identity_name,
                        current_activity=state.current_activity,
                        prev_activity=_prev_activity,
                        activity_start_time=state.activity_start_time,
                        frame=frame,
                        now=now,
                    )

                # ------ D. Identity matching (ArcFace + MediaPipe) ----------
                identity_matcher.try_match(state, frame, state_mgr)

            # ------ E. Remove stale tracks ------------------------------------------------------------------------------------------------------
            removed_states = state_mgr.remove_stale(active_ids)
            for state in removed_states:
                if clip_mgr is not None:
                    clip_mgr.on_track_lost(state.track_id)
                identity_matcher.remove_track(state.track_id)
                closed = state.close_current_activity(end_time=now)
                if closed is not None and event_logger is not None:
                    event_logger.log_event(
                        track_id=state.track_id,
                        identity_name=state.identity_name,
                        activity=closed.activity,
                        start_time=closed.start_time,
                        end_time=closed.end_time,
                        identity_confidence=state.identity_confidence,
                        identity_source=state.identity_source,
                        event_trigger="track_lost",
                    )
                log.info(
                    f"Track {state.track_id} ({state.identity_name}) lost --- "
                    f"last: {state.current_activity}"
                )

            # ------ E2. Heartbeat flush — write in-progress suspicious events -----
            # Events are normally only committed when a track ends or changes
            # activity.  For the alert evaluator to detect long-running phone/
            # inactive sessions, we periodically write a partial row to the DB.
            if event_logger is not None and (now - _heartbeat_last) >= _heartbeat_interval:
                _heartbeat_last = now
                _alertable = {"Using_Phone", "Inactive"}
                for _tid, _state in state_mgr.get_all().items():
                    if _state.identity_name == "Unknown":
                        continue
                    if _state.current_activity not in _alertable:
                        continue
                    _elapsed = now - _state.activity_start_time
                    if _elapsed < _heartbeat_interval:
                        continue  # not long enough to be worth flushing yet
                    try:
                        event_logger.log_event(
                            track_id=_state.track_id,
                            identity_name=_state.identity_name,
                            activity=_state.current_activity,
                            start_time=_state.activity_start_time,
                            end_time=now,
                            identity_confidence=_state.identity_confidence,
                            identity_source=_state.identity_source,
                            event_trigger="heartbeat",
                        )
                        log.info(
                            "[HEARTBEAT] wrote %.0fs of %s for %s",
                            _elapsed, _state.current_activity, _state.identity_name,
                        )
                    except Exception as _hb_exc:
                        log.warning("[HEARTBEAT] write failed: %s", _hb_exc)

            # ------ F. Draw overlays ------------------------------------------------------------------------------------------------------------------------
            _draw_overlays(frame, state_mgr.get_all(), line_thickness, font_scale)
            cv2.putText(
                frame, fps_text,
                (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255,   0), 2,
            )

            if show_window:
                cv2.imshow("Surveillance --- Activity Monitor", frame)
                key = cv2.waitKey(1) & 0xFF
                if key in (ord("q"), 27):   # q or Esc
                    log.info("Exit requested by user (q/Esc).")
                    break

    finally:
        # Flush all remaining open tracks
        now = time.time()
        for tid, state in list(state_mgr.get_all().items()):
            closed = state.close_current_activity(end_time=now)
            if closed is not None and event_logger is not None:
                event_logger.log_event(
                    track_id=tid,
                    identity_name=state.identity_name,
                    activity=closed.activity,
                    start_time=closed.start_time,
                    end_time=closed.end_time,
                    identity_confidence=state.identity_confidence,
                    identity_source=state.identity_source,
                    event_trigger="session_end",
                )
                log.info(f"[FINAL] Track {tid} ({state.identity_name}): {closed.activity} ({closed.duration_sec:.1f}s)")
        
        cap.release()
        cv2.destroyAllWindows()
        if event_logger is not None:
            event_logger.close()
        if clip_mgr is not None:
            clip_mgr.flush_all()
        log.info("Surveillance stopped.")


if __name__ == "__main__":
    main()
