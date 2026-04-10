from __future__ import annotations

from pathlib import Path
from typing import Dict, Tuple, Union
import numpy as np

# package-safe logger import
try:
    from src.recognition.logger import logger
except Exception:
    import logging
    logger = logging.getLogger("recognition")


Array = np.ndarray
PathLike = Union[str, Path]


def l2_normalize(x: Array, eps: float = 1e-10) -> Array:
    """L2-normalize a vector."""
    x = np.asarray(x, dtype=np.float32).reshape(-1)
    n = float(np.linalg.norm(x))
    return x / (n + eps)


def cosine_sim(a: Array, b: Array) -> float:
    """Cosine similarity for normalized vectors (dot product)."""
    return float(np.dot(a, b))


def load_known_embeddings(emb_dir: PathLike) -> Dict[str, Array]:
    """
    Load face embeddings from .npy files.
    Each file name (stem) is the identity label: <name>.npy
    Returns: {name: normalized_embedding_vector}
    """
    emb_dir = Path(emb_dir)
    known: Dict[str, Array] = {}

    files = sorted(emb_dir.glob("*.npy"))
    if not files:
        raise RuntimeError(f"No embeddings found in: {emb_dir.resolve()}")

    for p in files:
        try:
            vec = np.load(str(p)).astype(np.float32).reshape(-1)
            vec = l2_normalize(vec)
            known[p.stem] = vec
        except Exception as e:
            logger.warning(f"Skipping bad embedding file {p.name}: {e}")

    if not known:
        raise RuntimeError(f"All embeddings failed to load from: {emb_dir.resolve()}")

    any_name = next(iter(known))
    logger.info(f"Loaded {len(known)} embeddings (dim={known[any_name].shape[0]}) from {emb_dir}")
    return known


def match_identity(
    emb: Array,
    known: Dict[str, Array],
    sim_threshold: float = 0.72,
    margin: float = 0.08,
) -> Tuple[str, float, float]:
    """
    Match a live embedding against known embeddings.

    Args:
        emb: live embedding (any shape, will be flattened and normalized)
        known: dict of known embeddings (already normalized)
        sim_threshold: minimum cosine similarity to accept
        margin: minimum difference between best and second-best

    Returns:
        (label, best_sim, second_sim)
    """
    if emb is None or not known:
        return "Unknown", -1.0, -1.0

    emb_n = l2_normalize(emb)

    sims = [(name, cosine_sim(emb_n, ref)) for name, ref in known.items()]
    sims.sort(key=lambda x: x[1], reverse=True)

    best_name, best_sim = sims[0]
    second_sim = sims[1][1] if len(sims) > 1 else -1.0

    if best_sim >= sim_threshold and (best_sim - second_sim) >= margin:
        logger.info(
            "[MATCH] ✓ %s  sim=%.3f  second=%.3f  margin=%.3f",
            best_name, best_sim, second_sim, best_sim - second_sim,
        )
        return best_name, best_sim, second_sim

    logger.warning(
        "[MATCH] ✗ REJECTED best=%s sim=%.3f second=%.3f margin=%.3f  (need sim>=%.2f margin>=%.2f)",
        best_name, best_sim, second_sim, best_sim - second_sim, sim_threshold, margin,
    )
    return "Unknown", best_sim, second_sim