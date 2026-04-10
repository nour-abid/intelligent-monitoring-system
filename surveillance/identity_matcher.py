"""
Identity matcher - Camera B (Surveillance).

Uses ArcFace ONNX + MediaPipe Face Detection to identify persons on the
surveillance stream.  Completely independent of the Camera A check-in
pipeline (``src/recognition/``): own ONNX session, own embedding load,
own threshold, own per-track throttle counters.
"""

from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Dict, Optional, TYPE_CHECKING

import cv2
import numpy as np

# ---------------------------------------------------------------------------
# Daily identity store — Camera A → Camera B bridge (non-fatal import)
# ---------------------------------------------------------------------------
try:
    from surveillance.shared.daily_identity_store import DailyIdentityStore as _DailyIdentityStore
    _DAILY_STORE_AVAILABLE = True
except Exception:
    _DailyIdentityStore    = None  # type: ignore[assignment,misc]
    _DAILY_STORE_AVAILABLE = False

if TYPE_CHECKING:
    from surveillance.state_manager import SurveillanceTrackState, StateManager

log = logging.getLogger("surveillance.identity")

_ROOT = Path(__file__).resolve().parent.parent


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _l2_normalize(x: np.ndarray, eps: float = 1e-10) -> np.ndarray:
    x = np.asarray(x, dtype=np.float32).reshape(-1)
    return x / (float(np.linalg.norm(x)) + eps)


def _arcface_preprocess(face_bgr: np.ndarray) -> np.ndarray:
    """Kept for API compatibility — no longer used internally."""
    face_rgb = cv2.cvtColor(face_bgr, cv2.COLOR_BGR2RGB)
    face_rgb = cv2.resize(face_rgb, (112, 112))
    x = (face_rgb.astype(np.float32) - 127.5) / 128.0
    return np.expand_dims(x, axis=0)   # (1, 112, 112, 3)


# ---------------------------------------------------------------------------
# IdentityMatcher
# ---------------------------------------------------------------------------

class IdentityMatcher:
    """Face-based identity matcher for Camera B surveillance tracks.

    Lazy-loaded: all heavy resources are initialized on the first
    try_match() call. Failures degrade gracefully to a no-op.
    """

    def __init__(
        self,
        embeddings_dir: str | Path,
        model_path: str | Path,
        threshold: float = 0.50,
        margin: float = 0.06,
        face_every_n_frames: int = 30,
        min_face_px: int = 40,
        bbox_expand_ratio: float = 0.2,
        min_person_crop_px: int = 60,
        face_fail_cooldown_threshold: int = 3,
        face_fail_cooldown_frames: int = 60,
        daily_store_threshold: Optional[float] = None,
        daily_store_boost: float = 0.03,
        daily_store_refresh_sec: float = 60.0,
    ) -> None:
        self._embeddings_dir = _ROOT / embeddings_dir
        self._model_path = _ROOT / model_path
        self._threshold = threshold
        self._margin = margin
        self._face_every_n_frames = face_every_n_frames
        self._min_face_px = min_face_px
        self._bbox_expand_ratio = bbox_expand_ratio
        self._min_person_crop_px = min_person_crop_px
        self._fail_cooldown_threshold = face_fail_cooldown_threshold
        self._fail_cooldown_frames = face_fail_cooldown_frames
        self._counters: Dict[int, int] = {}
        # Per-track consecutive failure counter and frame-based cooldown ceiling.
        self._fail_counts: Dict[int, int] = {}
        self._cooldown_until: Dict[int, int] = {}
        self._insightface_app = None
        self._known: Optional[Dict[str, np.ndarray]] = None
        self._ready = False
        self._failed = False
        # ── Daily identity store (Camera A → Camera B bridge) ─────────────
        # Defaults to the same threshold as the static gallery when not set.
        self._daily_store_threshold: float = (
            daily_store_threshold if daily_store_threshold is not None else threshold
        )
        self._daily_store_boost: float = daily_store_boost
        self._daily_store_refresh_sec: float = daily_store_refresh_sec
        self._daily_embs: Dict[str, np.ndarray] = {}
        self._daily_last_refresh: float = 0.0
        self._daily_store = None          # initialised lazily inside _lazy_init()
        self._daily_store_failed = False
        # Per-track consecutive daily-CANDIDATE hit counter.
        # Maps track_id -> (candidate_name, consecutive_hit_count).
        self._daily_candidate_hits: Dict[int, tuple] = {}

    # ------------------------------------------------------------------
    # Lazy init
    # ------------------------------------------------------------------

    def _lazy_init(self) -> bool:
        if self._ready:
            return True
        if self._failed:
            return False
        try:
            self._load_insightface()
            self._load_embeddings()
            self._ready = True
            log.info(
                "IdentityMatcher ready: %d known identities, "
                "threshold=%.2f, face_every=%d frames",
                len(self._known), self._threshold, self._face_every_n_frames,
            )
            self._init_daily_store()   # non-fatal Camera A bridge
            return True
        except Exception as exc:
            log.warning("IdentityMatcher init failed (%s); identity matching disabled.", exc)
            self._failed = True
            return False

    def _load_insightface(self) -> None:
        """Load InsightFace buffalo_l — same model as the check-in pipeline."""
        from insightface.app import FaceAnalysis
        app = FaceAnalysis(
            name="buffalo_l",
            providers=["CUDAExecutionProvider", "CPUExecutionProvider"],
        )
        # det_size=(320,320) is sufficient for person crops (150-300px input).
        # buffalo_l at 640x640 on small crops wastes 4x compute for no gain.
        app.prepare(ctx_id=0, det_size=(320, 320))
        self._insightface_app = app
        log.info("[IdentityMatcher] InsightFace buffalo_l loaded.")

    def _load_embeddings(self) -> None:
        known: Dict[str, np.ndarray] = {}
        for p in sorted(self._embeddings_dir.glob("*.npy")):
            try:
                vec = np.load(str(p)).astype(np.float32).reshape(-1)
                known[p.stem] = _l2_normalize(vec)
            except Exception as e:
                log.warning("Skipping bad embedding %s: %s", p.name, e)
        if not known:
            raise RuntimeError(f"No valid embeddings in {self._embeddings_dir}")
        self._known = known

    # _load_face_detector removed — InsightFace handles detection internally.

    def _init_daily_store(self) -> None:
        """Initialise the DailyIdentityStore bridge (non-fatal)."""
        if not _DAILY_STORE_AVAILABLE or self._daily_store_failed:
            return
        try:
            self._daily_store = _DailyIdentityStore()
            log.info(
                "[DAILY_STORE] bridge ready — "
                "threshold=%.2f  boost=+%.3f  refresh=%.0fs",
                self._daily_store_threshold,
                self._daily_store_boost,
                self._daily_store_refresh_sec,
            )
        except Exception as exc:
            log.warning("[DAILY_STORE] init failed (%s) — Camera A bridge disabled.", exc)
            self._daily_store_failed = True

    def _get_daily_embs(self) -> Dict[str, np.ndarray]:
        """Return cached L2-normalised daily profile embeddings, refreshing if stale.

        Reads from the DailyIdentityStore at most every
        ``_daily_store_refresh_sec`` seconds.  Between refreshes the cached
        ``_daily_embs`` dict is returned directly — zero SQLite access per frame.
        Returns an empty dict when the store is unavailable or empty today.
        """
        if self._daily_store is None:
            return {}
        now = time.time()
        if now - self._daily_last_refresh < self._daily_store_refresh_sec:
            return self._daily_embs
        try:
            profiles = self._daily_store.get_identities_for_today()
            self._daily_embs = {
                name: _l2_normalize(p.embedding)
                for name, p in profiles.items()
            }
            self._daily_last_refresh = now
            if profiles:
                log.info(
                    "[DAILY_STORE] cache refreshed — %d profile(s): %s",
                    len(profiles), sorted(profiles.keys()),
                )
        except Exception:
            log.warning("[DAILY_STORE] cache refresh failed — using stale profiles.")
        return self._daily_embs

    # ------------------------------------------------------------------
    # Inference helpers
    # ------------------------------------------------------------------

    def _detect_and_embed(self, crop_bgr: np.ndarray) -> Optional[np.ndarray]:
        """Run InsightFace on crop_bgr; return L2-normalised embedding or None."""
        if crop_bgr is None or crop_bgr.size == 0 or crop_bgr.shape[0] < 20 or crop_bgr.shape[1] < 20:
            return None
        faces = self._insightface_app.get(crop_bgr)
        if not faces:
            return None
        # Pick the largest detected face
        face = max(faces, key=lambda f: (f.bbox[2] - f.bbox[0]) * (f.bbox[3] - f.bbox[1]))
        fw = face.bbox[2] - face.bbox[0]
        fh = face.bbox[3] - face.bbox[1]
        if fw < self._min_face_px or fh < self._min_face_px:
            return None
        emb = np.asarray(
            getattr(face, "normed_embedding", face.embedding),
            dtype=np.float32,
        ).reshape(-1)
        return _l2_normalize(emb)

    def _match(self, emb: np.ndarray):
        """Return (best_name, best_sim, second_sim) against known embeddings."""
        sims = [(name, float(np.dot(emb, ref))) for name, ref in self._known.items()]
        sims.sort(key=lambda x: x[1], reverse=True)
        best_name, best_sim = sims[0]
        second_sim = sims[1][1] if len(sims) > 1 else -1.0
        return best_name, best_sim, second_sim

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def try_match(
        self,
        state: "SurveillanceTrackState",
        frame: np.ndarray,
        state_mgr: "StateManager",
    ) -> None:
        """Attempt face-based identity match for state using the current frame.

        Throttled to run every face_every_n_frames frames per track.
        On a confident match calls state_mgr.set_identity().
        Below-threshold results leave existing identity unchanged.
        """
        if not self._lazy_init():
            return

        tid = state.track_id
        cnt = self._counters.get(tid, 0) + 1
        self._counters[tid] = cnt
        
        # Check if throttled (early skip)
        if cnt % self._face_every_n_frames != 0:
            log.debug(
                "[MATCH] track_id=%s | status=SKIPPED | reason=throttle | frame=%d/%d",
                tid, cnt, self._face_every_n_frames,
            )
            return

        # Failure cooldown: track is in backoff after repeated failed attempts.
        # _cooldown_until stores the cnt value at which the track may retry.
        if cnt < self._cooldown_until.get(tid, 0):
            log.debug(
                "[MATCH] track_id=%s | status=SKIPPED | reason=failure_cooldown | "
                "resume_at=%d | current=%d",
                tid, self._cooldown_until[tid], cnt,
            )
            return

        if state.bbox is None:
            log.debug("[MATCH] track_id=%s | status=SKIPPED | reason=no_bbox", tid)
            return

        H, W = frame.shape[:2]
        x1, y1, x2, y2 = (int(v) for v in state.bbox)
        x1, y1 = max(0, x1), max(0, y1)
        x2, y2 = min(W, x2), min(H, y2)
        if x2 <= x1 or y2 <= y1:
            log.debug(
                "[MATCH] track_id=%s | status=SKIPPED | reason=degenerate_bbox", tid
            )
            return
        crop_h, crop_w = (y2 - y1), (x2 - x1)

        if crop_h < self._min_person_crop_px or crop_w < self._min_person_crop_px:
            log.debug(
                "[MATCH] track_id=%s | status=REJECTED | reason=person_crop_too_small | "
                "crop_size=%dx%d | min=%d",
                tid, crop_w, crop_h, self._min_person_crop_px,
            )
            return

        # Expand bbox to help small crops
        expand_px_h = int(crop_h * self._bbox_expand_ratio)
        expand_px_w = int(crop_w * self._bbox_expand_ratio)
        ex1 = max(0, x1 - expand_px_w)
        ey1 = max(0, y1 - expand_px_h)
        ex2 = min(W, x2 + expand_px_w)
        ey2 = min(H, y2 + expand_px_h)
        person_crop = frame[ey1:ey2, ex1:ex2]
        try:
            emb = self._detect_and_embed(person_crop)
        except Exception as exc:
            log.debug(
                "[MATCH] track_id=%s | status=REJECTED | reason=embedding_failed | "
                "error=%s",
                tid, exc,
            )
            return

        if emb is None:
            # Count consecutive no_face outcomes; apply cooldown after threshold.
            _no_face_fails = self._fail_counts.get(tid, 0) + 1
            self._fail_counts[tid] = _no_face_fails
            if _no_face_fails >= self._fail_cooldown_threshold:
                self._cooldown_until[tid] = cnt + self._fail_cooldown_frames
                log.debug(
                    "[MATCH] track_id=%s | status=COOLDOWN | reason=repeated_no_face | "
                    "fail_count=%d | resume_at_frame=%d",
                    tid, _no_face_fails, self._cooldown_until[tid],
                )
            log.debug(
                "[MATCH] track_id=%s | status=REJECTED | reason=no_face_detected | "
                "crop_size=%dx%d",
                tid, crop_w, crop_h,
            )
            return

        # ── Static gallery match (existing logic, unchanged) ────────────
        best_name, best_sim, second_sim = self._match(emb)
        margin_val = best_sim - second_sim

        # ── Daily store supplementary match ──────────────────────────────
        # Uses today's high-quality Camera A embeddings as a second opinion.
        # Three outcomes:
        #   CONFIRM  — both sources agree → confidence boosted by _daily_store_boost.
        #   CANDIDATE— static match failed but daily store passed → daily drives.
        #   silent   — disagreement while static already passed → static wins.
        #
        # Results feed into state_mgr.set_identity() exactly as static matches do;
        # the existing candidate-buffer and confirmed-vault logic handles promotion.
        effective_name = best_name
        effective_sim  = best_sim
        daily_used     = False

        daily_embs = self._get_daily_embs()
        if daily_embs:
            daily_sims = sorted(
                ((name, float(np.dot(emb, ref))) for name, ref in daily_embs.items()),
                key=lambda x: x[1], reverse=True,
            )
            daily_name, daily_sim = daily_sims[0]

            if daily_sim >= self._daily_store_threshold:
                if daily_name == best_name:
                    # Both sources agree — apply a small fixed confidence boost.
                    effective_sim = min(1.0, best_sim + self._daily_store_boost)
                    log.debug(
                        "[MATCH] track_id=%s | daily_store=CONFIRM | identity=%s | "
                        "static_sim=%.4f  boost=+%.3f  effective_sim=%.4f",
                        tid, best_name, best_sim,
                        self._daily_store_boost, effective_sim,
                    )
                elif best_sim < self._threshold:
                    # Static match failed; daily store is the only evidence.
                    # Two safety gates apply:
                    #   1. Elevated threshold: daily_sim must exceed the configured
                    #      threshold by _DAILY_CANDIDATE_EXTRA (default +0.05).
                    #   2. Temporal consistency: the same daily name must appear in
                    #      at least _DAILY_CANDIDATE_MIN_HITS consecutive matching
                    #      frames before it is forwarded to set_identity().
                    _DAILY_CANDIDATE_EXTRA    = 0.05
                    _DAILY_CANDIDATE_MIN_HITS = 2
                    _elevated_thr = self._daily_store_threshold + _DAILY_CANDIDATE_EXTRA

                    if daily_sim >= _elevated_thr:
                        prev_name, prev_hits = self._daily_candidate_hits.get(tid, ("", 0))
                        new_hits = prev_hits + 1 if daily_name == prev_name else 1
                        self._daily_candidate_hits[tid] = (daily_name, new_hits)

                        if new_hits >= _DAILY_CANDIDATE_MIN_HITS:
                            effective_name = daily_name
                            effective_sim  = daily_sim
                            daily_used     = True
                            log.info(
                                "[MATCH] track_id=%s | daily_store=CANDIDATE_ACCEPTED | "
                                "identity=%s | daily_sim=%.4f | hits=%d/%d",
                                tid, daily_name, daily_sim,
                                new_hits, _DAILY_CANDIDATE_MIN_HITS,
                            )
                        else:
                            log.debug(
                                "[MATCH] track_id=%s | daily_store=CANDIDATE_PENDING | "
                                "identity=%s | daily_sim=%.4f | hits=%d/%d "
                                "(waiting for temporal consistency)",
                                tid, daily_name, daily_sim,
                                new_hits, _DAILY_CANDIDATE_MIN_HITS,
                            )
                    else:
                        self._daily_candidate_hits.pop(tid, None)
                        log.debug(
                            "[MATCH] track_id=%s | daily_store=CANDIDATE_REJECTED | "
                            "reason=below_elevated_threshold | identity=%s | "
                            "daily_sim=%.4f < elevated_thr=%.2f",
                            tid, daily_name, daily_sim, _elevated_thr,
                        )
                # else: sources disagree and static already passed — static wins silently.

        # ── Decision ─────────────────────────────────────────────────────
        # The margin gate is skipped on the daily-only path: Camera A embeddings
        # are high-quality so the second-best delta from the static gallery
        # is not the right discriminator when the daily store drives the match.
        if effective_sim >= self._threshold and (daily_used or margin_val >= self._margin):
            state_mgr.set_identity(
                tid, effective_name, confidence=effective_sim, source="face"
            )
            # Successful match — reset failure cooldown so the track is checked normally.
            self._fail_counts.pop(tid, None)
            self._cooldown_until.pop(tid, None)
            log.debug(
                "[MATCH] track_id=%s | status=ACCEPTED | identity=%s | "
                "best_sim=%.4f | second_sim=%.4f | margin=%.4f",
                tid, effective_name, effective_sim, second_sim, margin_val,
            )
        else:
            if best_sim < self._threshold:
                reject_reason = "below_threshold"
                # Count consecutive below-threshold outcomes; apply cooldown.
                _bt_fails = self._fail_counts.get(tid, 0) + 1
                self._fail_counts[tid] = _bt_fails
                if _bt_fails >= self._fail_cooldown_threshold:
                    self._cooldown_until[tid] = cnt + self._fail_cooldown_frames
                    log.debug(
                        "[MATCH] track_id=%s | status=COOLDOWN | "
                        "reason=repeated_below_threshold | "
                        "fail_count=%d | resume_at_frame=%d",
                        tid, _bt_fails, self._cooldown_until[tid],
                    )
            else:
                reject_reason = "margin_too_small"

            log.debug(
                "[MATCH] track_id=%s | status=REJECTED | reason=%s | "
                "identity=%s | best_sim=%.4f | second_sim=%.4f | margin=%.4f | "
                "thr=%.2f | min_margin=%.2f",
                tid, reject_reason, best_name, best_sim, second_sim, margin_val,
                self._threshold, self._margin,
            )

    def remove_track(self, track_id: int) -> None:
        """Release throttle counter and daily candidate state when a track is removed."""
        self._counters.pop(track_id, None)
        self._daily_candidate_hits.pop(track_id, None)
        self._fail_counts.pop(track_id, None)
        self._cooldown_until.pop(track_id, None)
