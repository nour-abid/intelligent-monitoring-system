import numpy as np
from config import config
from logger import logger


def l2_normalize(x: np.ndarray, eps: float = 1e-10) -> np.ndarray:
    x = np.asarray(x, dtype=np.float32).reshape(-1)
    n = float(np.linalg.norm(x))
    return x / (n + eps)


def cosine_sim(a: np.ndarray, b: np.ndarray) -> float:
    # assumes both are normalized; dot == cosine similarity
    return float(np.dot(a, b))


def load_known_embeddings() -> dict:
    emb_dir = config["paths"]["emb_dir"]
    known = {}

    files = list(emb_dir.glob("*.npy"))
    if not files:
        raise RuntimeError(f"No embeddings found in: {emb_dir.resolve()}")

    for p in files:
        try:
            vec = np.load(p)
            vec = l2_normalize(vec)
            known[p.stem] = vec
        except Exception as e:
            logger.warning(f"Skipping bad embedding file {p.name}: {e}")

    if not known:
        raise RuntimeError(f"All embeddings failed to load from: {emb_dir.resolve()}")

    # optional debug info
    any_name = next(iter(known))
    logger.info(f"Loaded {len(known)} known embeddings (dim={known[any_name].shape[0]})")

    return known


def match_identity(emb: np.ndarray, known: dict):
    if emb is None or not known:
        return "Unknown", -1.0, -1.0

    # ✅ normalize live embedding too (critical)
    emb = l2_normalize(emb)

    sims = [(name, cosine_sim(emb, ref)) for name, ref in known.items()]
    sims.sort(key=lambda x: x[1], reverse=True)

    best_name, best_sim = sims[0]
    second_sim = sims[1][1] if len(sims) > 1 else -1.0

    sim_threshold = config["recognition"]["sim_threshold"]
    margin = config["recognition"]["margin"]

    # debug text (won’t crash)
    if len(sims) > 1:
        match_info = (
            f"Similarities: {best_name}={best_sim:.3f}, "
            f"{sims[1][0]}={second_sim:.3f}, margin={best_sim - second_sim:.3f}"
        )
    else:
        match_info = f"Similarities: {best_name}={best_sim:.3f} (only 1 identity)"

    if best_sim >= sim_threshold and (best_sim - second_sim) >= margin:
        logger.debug(f"[MATCH] {match_info} ✓ RECOGNIZED")
        return best_name, best_sim, second_sim

    # rejected
    if best_sim < sim_threshold:
        reason = f"(sim {best_sim:.3f} < threshold {sim_threshold})"
    else:
        reason = f"(margin {best_sim - second_sim:.3f} < required {margin})"
    logger.debug(f"[MATCH] {match_info} ✗ REJECTED {reason}")
    return "Unknown", best_sim, second_sim