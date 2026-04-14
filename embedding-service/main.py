"""
Face Embedding Microservice — FastAPI entry point.

Exposes two endpoints consumed by the Laravel GenerateFaceEmbeddingJob:

  POST /embed
    Generate a 512-d InsightFace buffalo_l embedding for one enrollment photo.
    Input:  { photo_id, image_path, surveillance_identity }
    Output: success or failure JSON (see schema below)

  POST /gallery/update
    Average a set of embedding vectors and write the result to
    {EMBEDDINGS_DIR}/{surveillance_identity}.npy  — the file read by the
    surveillance runtime's IdentityMatcher.
    Input:  { surveillance_identity, vectors: float[][] }
    Output: { success, surveillance_identity, vector_count, npy_path }

  GET /health
    Readiness probe — returns {"status":"ok"} immediately (model NOT pre-loaded).

Configuration (environment variables):
  EMBEDDINGS_DIR   Absolute path to the directory that holds *.npy gallery files.
                   Default: "../models/embeddings" (relative to this file).
  HOST             Bind address. Default: 0.0.0.0
  PORT             Listen port. Default: 8765

Run:
  cd embedding-service
  uvicorn main:app --host 0.0.0.0 --port 8765 --workers 1
"""

from __future__ import annotations

import logging
import os
from pathlib import Path

import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, field_validator

from embedder import Embedder

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
)
log = logging.getLogger("embedding_service")

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
_THIS_DIR = Path(__file__).resolve().parent
EMBEDDINGS_DIR = Path(
    os.getenv("EMBEDDINGS_DIR", str(_THIS_DIR.parent / "models" / "embeddings"))
)
log.info("EMBEDDINGS_DIR = %s", EMBEDDINGS_DIR)

# ---------------------------------------------------------------------------
# Shared embedder instance (lazy-loads buffalo_l on first /embed call)
# ---------------------------------------------------------------------------
_embedder = Embedder()

# ---------------------------------------------------------------------------
# FastAPI app
# ---------------------------------------------------------------------------
app = FastAPI(title="Face Embedding Service", version="1.0.0")


# ── Request / Response schemas ───────────────────────────────────────────────

class EmbedRequest(BaseModel):
    photo_id: int
    image_path: str
    surveillance_identity: str

    @field_validator("image_path")
    @classmethod
    def path_must_exist(cls, v: str) -> str:
        if not Path(v).is_file():
            raise ValueError(f"image_path does not exist: {v}")
        return v

    @field_validator("surveillance_identity")
    @classmethod
    def identity_not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("surveillance_identity must not be empty")
        return v.strip()


class GalleryUpdateRequest(BaseModel):
    surveillance_identity: str
    vectors: list[list[float]]

    @field_validator("vectors")
    @classmethod
    def vectors_not_empty(cls, v: list) -> list:
        if not v:
            raise ValueError("vectors must contain at least one element")
        return v

    @field_validator("surveillance_identity")
    @classmethod
    def identity_not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("surveillance_identity must not be empty")
        return v.strip()


# ── Endpoints ────────────────────────────────────────────────────────────────

@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.post("/embed")
def embed(req: EmbedRequest) -> dict:
    """
    Detect the face in req.image_path and return its 512-d embedding.

    Always returns HTTP 200 — success/failure is indicated by the 'success'
    field in the body. HTTP 4xx/5xx are reserved for protocol-level errors
    (bad JSON, file not found at validation time, etc.).
    """
    log.info(
        "/embed photo_id=%d identity=%s path=%s",
        req.photo_id,
        req.surveillance_identity,
        req.image_path,
    )
    result = _embedder.embed(req.image_path)
    return result.to_dict(req.photo_id, req.surveillance_identity)


@app.post("/gallery/update")
def gallery_update(req: GalleryUpdateRequest) -> dict:
    """
    Average req.vectors and write the L2-normalised result to
    {EMBEDDINGS_DIR}/{req.surveillance_identity}.npy.

    The filename stem (without .npy) MUST match User.surveillance_identity
    exactly — that is the key the IdentityMatcher uses to look up identities.
    """
    log.info(
        "/gallery/update identity=%s vectors=%d",
        req.surveillance_identity,
        len(req.vectors),
    )

    # Validate dimensionality consistency.
    dims = {len(v) for v in req.vectors}
    if len(dims) != 1:
        raise HTTPException(
            status_code=422,
            detail=f"All vectors must have the same dimension; got: {dims}",
        )

    gallery_vec = _embedder.compute_gallery_vector(req.vectors)

    EMBEDDINGS_DIR.mkdir(parents=True, exist_ok=True)
    npy_path = EMBEDDINGS_DIR / f"{req.surveillance_identity}.npy"
    np.save(str(npy_path), gallery_vec)

    log.info(
        "Gallery written: %s  dim=%d  from %d vector(s)",
        npy_path,
        len(gallery_vec),
        len(req.vectors),
    )

    return {
        "success": True,
        "surveillance_identity": req.surveillance_identity,
        "vector_count": len(req.vectors),
        "npy_path": str(npy_path),
    }
