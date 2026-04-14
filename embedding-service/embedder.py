"""
Face embedder — wraps InsightFace buffalo_l for enrollment photo processing.

Responsibilities:
  - Detect faces in an image using InsightFace's built-in detector.
  - Reject images with no face or more than one face.
  - Reject low-quality detections (det_score < MIN_QUALITY).
  - Return the L2-normalised 512-d embedding (normed_embedding from buffalo_l).
  - Compute the L2-normalised mean of multiple embeddings for gallery writes.

This module is intentionally free of FastAPI / HTTP concerns so it can be
unit-tested independently.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional

import cv2
import numpy as np

log = logging.getLogger("embedding_service.embedder")

# InsightFace det_score threshold. Scores below this are deemed LOW_QUALITY.
# buffalo_l det_score is in [0,1]; frontal clear faces typically score > 0.7.
MIN_QUALITY: float = 0.50

# ── Pose classification ────────────────────────────────────────────────────
# InsightFace returns face.pose as [pitch, yaw, roll] in degrees.
#   pitch > 0 → chin down (looking down)   pitch < 0 → chin up (looking up)
#   yaw   > 0 → face turned right           yaw   < 0 → face turned left
# These thresholds define the boundary between "forward" and a directional pose.
_YAW_THRESHOLD: float   = 15.0   # degrees  (horizontal turn)
_PITCH_THRESHOLD: float = 12.0   # degrees  (vertical tilt)

# Canonical pose labels — must match REQUIRED_POSES keys in the Angular
# component and the pose_coverage logic in GenerateFaceEmbeddingJob.
POSE_LABELS = ("forward", "left", "right", "up", "down")

# ── Frame-validation thresholds ───────────────────────────────────────────
# These govern the real-time camera feedback, not embedding quality.
_MIN_FACE_AREA_RATIO: float = 0.035   # face bbox area as fraction of frame area
_MAX_CENTER_OFFSET: float   = 0.28    # normalised distance from frame centre (per axis)
_MIN_SHARPNESS: float       = 18.0    # Laplacian variance on face ROI (blurry < 10)


def _classify_pose(pose_angles) -> str:
    """
    Map InsightFace pose angles to one of the five canonical labels.

    pose_angles: sequence of at least [pitch, yaw] in degrees.
    Returns 'forward' as a safe fallback when pose is unavailable.
    """
    if pose_angles is None or len(pose_angles) < 2:
        return "forward"

    pitch = float(pose_angles[0])
    yaw   = float(pose_angles[1])

    abs_yaw   = abs(yaw)
    abs_pitch = abs(pitch)

    # If both axes are within threshold → frontal (forward).
    if abs_yaw < _YAW_THRESHOLD and abs_pitch < _PITCH_THRESHOLD:
        return "forward"

    # Dominant axis determines the primary pose direction.
    if abs_yaw >= abs_pitch:
        return "left" if yaw < 0 else "right"
    else:
        return "up" if pitch < 0 else "down"


class EmbedResult:
    """Value object returned by Embedder.embed()."""

    __slots__ = (
        "success",
        "embedding",
        "embedding_dim",
        "quality_score",
        "face_count",
        "failure_reason",
        "detected_pose",
    )

    def __init__(
        self,
        *,
        success: bool,
        embedding: Optional[list[float]] = None,
        embedding_dim: int = 0,
        quality_score: Optional[float] = None,
        face_count: int = 0,
        failure_reason: Optional[str] = None,
        detected_pose: Optional[str] = None,
    ) -> None:
        self.success = success
        self.embedding = embedding
        self.embedding_dim = embedding_dim
        self.quality_score = quality_score
        self.face_count = face_count
        self.failure_reason = failure_reason
        self.detected_pose = detected_pose

    def to_dict(self, photo_id: int, surveillance_identity: str) -> dict:
        base = {
            "photo_id": photo_id,
            "surveillance_identity": surveillance_identity,
            "face_count": self.face_count,
            "detected_pose": self.detected_pose,
        }
        if self.success:
            return {
                **base,
                "success": True,
                "embedding": self.embedding,
                "embedding_dim": self.embedding_dim,
                "model_name": "buffalo_l",
                "quality_score": self.quality_score,
            }
        return {
            **base,
            "success": False,
            "failure_reason": self.failure_reason,
        }


def _l2_normalize(vec: np.ndarray, eps: float = 1e-10) -> np.ndarray:
    vec = vec.astype(np.float32).reshape(-1)
    return vec / (float(np.linalg.norm(vec)) + eps)


class Embedder:
    """
    Singleton-style InsightFace wrapper.

    Loaded lazily on first call to embed() so the FastAPI process starts fast
    and model loading errors don't prevent the health endpoint from responding.
    """

    def __init__(self) -> None:
        self._app = None

    def _ensure_loaded(self) -> None:
        if self._app is not None:
            return
        from insightface.app import FaceAnalysis

        log.info("[Embedder] Loading buffalo_l …")
        app = FaceAnalysis(
            name="buffalo_l",
            providers=["CUDAExecutionProvider", "CPUExecutionProvider"],
        )
        # 320×320 det_size is sufficient for enrollment photos (single face,
        # moderate resolution). Saves memory vs 640×640.
        app.prepare(ctx_id=0, det_size=(320, 320))
        self._app = app
        log.info("[Embedder] buffalo_l ready.")

    def embed(self, image_path: str) -> EmbedResult:
        """
        Detect exactly one face in image_path and return its embedding.

        Returns an EmbedResult(success=False) with an appropriate failure_reason
        for images with no face, multiple faces, or a low detection score.
        Never raises — all errors are caught and returned as INTERNAL_ERROR.
        """
        try:
            self._ensure_loaded()
        except Exception as exc:
            log.exception("[Embedder] Failed to load buffalo_l")
            return EmbedResult(success=False, failure_reason=f"INTERNAL_ERROR: {exc}")

        try:
            img_bgr = cv2.imread(image_path)
            if img_bgr is None:
                return EmbedResult(
                    success=False,
                    failure_reason="INTERNAL_ERROR: cannot read image file",
                )

            img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
            faces = self._app.get(img_rgb)

            face_count = len(faces)

            if face_count == 0:
                log.info("[Embedder] NO_FACE in %s", image_path)
                return EmbedResult(success=False, failure_reason="NO_FACE", face_count=0)

            if face_count > 1:
                log.info("[Embedder] MULTIPLE_FACES (%d) in %s", face_count, image_path)
                return EmbedResult(
                    success=False, failure_reason="MULTIPLE_FACES", face_count=face_count
                )

            face = faces[0]
            det_score = float(face.det_score)

            if det_score < MIN_QUALITY:
                log.info(
                    "[Embedder] LOW_QUALITY det_score=%.3f in %s",
                    det_score,
                    image_path,
                )
                return EmbedResult(
                    success=False,
                    failure_reason="LOW_QUALITY",
                    face_count=1,
                    quality_score=det_score,
                )

            # normed_embedding is already L2-normalised by InsightFace.
            # _l2_normalize is applied for safety (idempotent on unit vectors).
            raw = getattr(face, "normed_embedding", face.embedding)
            vec = _l2_normalize(np.asarray(raw, dtype=np.float32))

            # Classify head pose from InsightFace's [pitch, yaw, roll] angles.
            pose_angles  = getattr(face, "pose", None)
            detected_pose = _classify_pose(pose_angles)
            log.info(
                "[Embedder] OK det_score=%.3f dim=%d pose=%s (raw=%s)",
                det_score, len(vec), detected_pose,
                [round(float(a), 1) for a in pose_angles] if pose_angles is not None else None,
            )
            return EmbedResult(
                success=True,
                embedding=vec.tolist(),
                embedding_dim=len(vec),
                quality_score=det_score,
                face_count=1,
                detected_pose=detected_pose,
            )

        except Exception as exc:
            log.exception("[Embedder] Unexpected error on %s", image_path)
            return EmbedResult(success=False, failure_reason=f"INTERNAL_ERROR: {exc}")

    def compute_gallery_vector(self, vectors: list[list[float]]) -> np.ndarray:
        """
        Compute the L2-normalised mean of a list of 512-d embedding vectors.

        This is the vector written to {identity}.npy for the surveillance runtime.
        An average of unit vectors is NOT itself a unit vector, so renormalization
        is required before saving.
        """
        arr = np.array(vectors, dtype=np.float32)  # (N, 512)
        mean = arr.mean(axis=0)
        return _l2_normalize(mean)

    def validate_frame(self, image_bytes: bytes, expected_pose: str) -> dict:
        """
        Lightweight liveness check for the guided camera capture UI.

        Detects a face, checks geometry and sharpness, and classifies head pose.
        Does NOT compute embeddings — only the detector runs.

        Args:
            image_bytes:   Raw JPEG/PNG bytes from the browser canvas.
            expected_pose: One of POSE_LABELS or 'any' (skip pose matching).

        Returns a dict compatible with the FrameValidation TS interface.
        """
        _FAIL = lambda msg: {
            "ready": False, "message": msg, "face_count": 0,
            "predicted_pose": None, "matches_expected": False,
            "yaw": 0.0, "pitch": 0.0, "roll": 0.0,
            "quality_score": 0.0, "face_size_ratio": 0.0, "sharpness": 0.0,
        }

        try:
            self._ensure_loaded()
        except Exception as exc:
            return _FAIL(f"Model not ready: {exc}")

        try:
            arr = np.frombuffer(image_bytes, np.uint8)
            img_bgr = cv2.imdecode(arr, cv2.IMREAD_COLOR)
            if img_bgr is None:
                return _FAIL("Cannot decode image")

            # Downscale if large — keeps detection fast without quality loss.
            img_h, img_w = img_bgr.shape[:2]
            scale = min(1.0, 640.0 / max(img_h, img_w, 1))
            if scale < 1.0:
                img_bgr = cv2.resize(
                    img_bgr,
                    (int(img_w * scale), int(img_h * scale)),
                    interpolation=cv2.INTER_AREA,
                )
                img_h, img_w = img_bgr.shape[:2]

            img_rgb  = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
            faces    = self._app.get(img_rgb)
            n_faces  = len(faces)

            if n_faces == 0:
                return _FAIL("No face detected — look at the camera")
            if n_faces > 1:
                return _FAIL(f"{n_faces} faces detected — only one person allowed")

            face      = faces[0]
            det_score = float(face.det_score)
            bbox      = face.bbox  # [x1, y1, x2, y2] in (possibly scaled) pixels

            face_bw = float(bbox[2] - bbox[0])
            face_bh = float(bbox[3] - bbox[1])
            area_ratio = (face_bw * face_bh) / (img_w * img_h)

            face_cx   = (bbox[0] + bbox[2]) / 2.0
            face_cy   = (bbox[1] + bbox[3]) / 2.0
            offset_x  = abs(face_cx - img_w / 2.0) / img_w
            offset_y  = abs(face_cy - img_h / 2.0) / img_h

            # Sharpness: Laplacian variance on the greyscale face crop.
            x1 = max(0, int(bbox[0]));  x2 = min(img_w, int(bbox[2]))
            y1 = max(0, int(bbox[1]));  y2 = min(img_h, int(bbox[3]))
            gray     = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
            roi      = gray[y1:y2, x1:x2]
            sharpness = float(cv2.Laplacian(roi, cv2.CV_64F).var()) if roi.size > 0 else 0.0

            pose_angles   = getattr(face, "pose", None)
            predicted_pose = _classify_pose(pose_angles)
            yaw   = float(pose_angles[1]) if pose_angles is not None and len(pose_angles) > 1 else 0.0
            pitch = float(pose_angles[0]) if pose_angles is not None else 0.0
            roll  = float(pose_angles[2]) if pose_angles is not None and len(pose_angles) > 2 else 0.0

            matches_pose = (expected_pose == "any") or (predicted_pose == expected_pose)

            quality_ok = det_score  >= MIN_QUALITY
            size_ok    = area_ratio >= _MIN_FACE_AREA_RATIO
            center_ok  = offset_x   <  _MAX_CENTER_OFFSET and offset_y < _MAX_CENTER_OFFSET
            sharp_ok   = sharpness  >= _MIN_SHARPNESS
            pose_ok    = matches_pose

            if not quality_ok:
                message = "Face quality too low — improve lighting or move closer"
            elif not size_ok:
                message = "Move closer to the camera"
            elif not center_ok:
                message = "Center your face inside the guide oval"
            elif not sharp_ok:
                message = "Hold still — image is blurry"
            elif not pose_ok:
                message = f"Match the guide pose  (detected: {predicted_pose})"
            else:
                message = "Ready — hold this pose"

            return {
                "ready":            quality_ok and size_ok and center_ok and sharp_ok and pose_ok,
                "message":          message,
                "face_count":       n_faces,
                "predicted_pose":   predicted_pose,
                "matches_expected": matches_pose,
                "yaw":              round(yaw,        1),
                "pitch":            round(pitch,       1),
                "roll":             round(roll,        1),
                "quality_score":    round(det_score,   3),
                "face_size_ratio":  round(area_ratio,  3),
                "sharpness":        round(sharpness,   1),
            }

        except Exception as exc:
            log.exception("[Embedder] validate_frame error")
            return _FAIL(f"Validation error: {exc}")
