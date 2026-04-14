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


class EmbedResult:
    """Value object returned by Embedder.embed()."""

    __slots__ = (
        "success",
        "embedding",
        "embedding_dim",
        "quality_score",
        "face_count",
        "failure_reason",
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
    ) -> None:
        self.success = success
        self.embedding = embedding
        self.embedding_dim = embedding_dim
        self.quality_score = quality_score
        self.face_count = face_count
        self.failure_reason = failure_reason

    def to_dict(self, photo_id: int, surveillance_identity: str) -> dict:
        base = {
            "photo_id": photo_id,
            "surveillance_identity": surveillance_identity,
            "face_count": self.face_count,
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

            log.info("[Embedder] OK det_score=%.3f dim=%d", det_score, len(vec))
            return EmbedResult(
                success=True,
                embedding=vec.tolist(),
                embedding_dim=len(vec),
                quality_score=det_score,
                face_count=1,
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
