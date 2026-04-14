"""
generate_embeddings_insightface.py
===================================
Canonical enrollment script for the attendance recognition pipeline.

Generates per-identity mean L2-normalised 512-d embedding vectors using
InsightFace buffalo_l (the same model the pipeline uses at runtime) and
saves them as ``<identity>.npy`` files in the configured embeddings
directory (``config["paths"]["emb_dir"]``, default: ``models/embeddings/``).

Expected folder layout under INPUT_DIR::

    data/employees/
        Amir/
            1.jpg
            2.png
        Nour/
            photo.webp

Usage (from project root)::

    python src/recognition/generate_embeddings_insightface.py

Archived alternatives (do not use):
    - enroll_insightface.py   — duplicate; hard-coded paths, no .webp support
    - enroll_arcface_onnx.py  — wrong embedding space (ONNX/MediaPipe)
"""

import cv2
import numpy as np
from pathlib import Path
from insightface.app import FaceAnalysis
from config import config

# ── Configuration ────────────────────────────────────────────────────────────
INPUT_DIR  = Path("data/employees")         # one sub-folder per identity
OUTPUT_DIR = config["paths"]["emb_dir"]     # models/embeddings/ (from config.py)
DET_SIZE   = (640, 640)
MODEL_NAME = "buffalo_l"


def l2_normalize(x, eps=1e-10):
    x = np.asarray(x, dtype=np.float32).reshape(-1)
    return x / (np.linalg.norm(x) + eps)


def main():
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    print(f"Loading InsightFace model: {MODEL_NAME}")
    try:
        app = FaceAnalysis(name=MODEL_NAME, providers=["CUDAExecutionProvider", "CPUExecutionProvider"])
        app.prepare(ctx_id=0, det_size=DET_SIZE)
        print("Using CUDA + CPU providers")
    except Exception as e:
        print(f"CUDA failed ({e}), falling back to CPU")
        app = FaceAnalysis(name=MODEL_NAME, providers=["CPUExecutionProvider"])
        app.prepare(ctx_id=-1, det_size=DET_SIZE)

    if not INPUT_DIR.exists():
        raise RuntimeError(f"Input folder not found: {INPUT_DIR.resolve()}")

    persons = [p for p in INPUT_DIR.iterdir() if p.is_dir()]
    if not persons:
        raise RuntimeError(f"No person folders found in {INPUT_DIR.resolve()}")

    for person_dir in persons:
        person_name = person_dir.name
        embs = []

        image_files = sorted([
            p for p in person_dir.iterdir()
            if p.suffix.lower() in [".jpg", ".jpeg", ".png", ".webp"]
        ])

        if not image_files:
            print(f"[SKIP] {person_name}: no images")
            continue

        print(f"\n[{person_name}] {len(image_files)} image(s)")

        for img_path in image_files:
            img = cv2.imread(str(img_path))
            if img is None:
                print(f"  - failed to read: {img_path.name}")
                continue

            faces = app.get(img)
            if not faces:
                print(f"  - no face detected: {img_path.name}")
                continue

            # choose largest face (safer if background has another face)
            face = max(faces, key=lambda f: (f.bbox[2] - f.bbox[0]) * (f.bbox[3] - f.bbox[1]))

            # ✅ use normed_embedding (this is key)
            emb = getattr(face, "normed_embedding", None)
            if emb is None:
                emb = l2_normalize(face.embedding)

            emb = np.asarray(emb, dtype=np.float32).reshape(-1)

            # sanity checks
            print(f"  - {img_path.name}: shape={emb.shape}, norm={np.linalg.norm(emb):.4f}, det={face.det_score:.3f}")

            if emb.shape[0] != 512:
                print(f"    [WARN] unexpected embedding size {emb.shape[0]} (expected 512), skipping")
                continue

            embs.append(emb)

        if not embs:
            print(f"[FAIL] {person_name}: no valid embeddings extracted")
            continue

        # Average multiple embeddings, then renormalize
        mean_emb = np.mean(np.stack(embs, axis=0), axis=0)
        mean_emb = l2_normalize(mean_emb).astype(np.float32)

        out_path = OUTPUT_DIR / f"{person_name}.npy"
        np.save(out_path, mean_emb)

        print(f"[SAVED] {out_path.name} shape={mean_emb.shape} norm={np.linalg.norm(mean_emb):.4f} from {len(embs)} image(s)")

    print("\nDone.")


if __name__ == "__main__":
    main()