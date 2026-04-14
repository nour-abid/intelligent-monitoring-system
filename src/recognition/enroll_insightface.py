"""
DEPRECATED — DO NOT USE
=======================
This script is a duplicate of generate_embeddings_insightface.py with two
regressions:
  1. Hard-codes ``OUT_DIR = Path("models/embeddings")`` — ignores config.py,
     so re-configuring the project path has no effect here.
  2. Only globs .jpg/.jpeg/.png — misses .webp files supported by the pipeline.

Canonical enrollment script: src/recognition/generate_embeddings_insightface.py

Original docstring (preserved for reference)
--------------------------------------------
Re-enroll employees using InsightFace buffalo_l — the SAME model used in the
attendance pipeline.  Run this script once to regenerate models/embeddings/*.npy
and fix the embedding-space mismatch.

Usage (from project root):
    python src/recognition/enroll_insightface.py
"""
from pathlib import Path
import sys
import cv2
import numpy as np

EMP_DIR  = Path("data/employees")
OUT_DIR  = Path("models/embeddings")
PERSON_MIN = 2          # minimum usable images per employee
DET_SIZE   = (640, 640)

def l2_normalize(x: np.ndarray, eps: float = 1e-10) -> np.ndarray:
    return x / (np.linalg.norm(x) + eps)

def build_app():
    """Load InsightFace buffalo_l, same settings as the pipeline."""
    try:
        import insightface
        from insightface.app import FaceAnalysis
    except ImportError:
        sys.exit("[ERROR] insightface not installed.  Run: pip install insightface")

    app = FaceAnalysis(name="buffalo_l", providers=["CUDAExecutionProvider", "CPUExecutionProvider"])
    app.prepare(ctx_id=0, det_size=DET_SIZE)
    return app

def enroll_person(app, emp_dir: Path) -> np.ndarray | None:
    img_paths = sorted(
        list(emp_dir.glob("*.jpg"))
        + list(emp_dir.glob("*.jpeg"))
        + list(emp_dir.glob("*.png"))
    )
    if not img_paths:
        print(f"  [SKIP] no images in {emp_dir}")
        return None

    embs = []
    for ip in img_paths:
        img = cv2.imread(str(ip))
        if img is None:
            print(f"  [WARN] cannot read {ip.name}")
            continue

        faces = app.get(img)
        if not faces:
            print(f"  [WARN] no face detected in {ip.name}")
            continue

        # Pick the largest face (most prominent subject)
        face = max(faces, key=lambda f: (f.bbox[2] - f.bbox[0]) * (f.bbox[3] - f.bbox[1]))
        emb = np.asarray(
            getattr(face, "normed_embedding", face.embedding),
            dtype=np.float32,
        ).reshape(-1)
        emb = l2_normalize(emb)
        embs.append(emb)
        print(f"  [OK ] {ip.name}  det={face.det_score:.2f}")

    if len(embs) < PERSON_MIN:
        print(f"  [FAIL] only {len(embs)} usable image(s) — need >= {PERSON_MIN}")
        return None

    return l2_normalize(np.mean(np.stack(embs, axis=0), axis=0))

def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    employees = sorted([p for p in EMP_DIR.iterdir() if p.is_dir()])
    if not employees:
        sys.exit(f"[ERROR] No employee folders found in {EMP_DIR.resolve()}")

    print(f"Loading InsightFace buffalo_l …")
    app = build_app()
    print(f"Model ready.\n")

    saved = 0
    for emp_dir in employees:
        print(f"Enrolling: {emp_dir.name}")
        mean_emb = enroll_person(app, emp_dir)
        if mean_emb is None:
            continue
        out_path = OUT_DIR / f"{emp_dir.name}.npy"
        np.save(out_path, mean_emb)
        print(f"  → Saved {out_path}  (dim={mean_emb.shape[0]})\n")
        saved += 1

    print(f"\nDone: {saved}/{len(employees)} identities enrolled.")
    if saved == 0:
        print("No embeddings saved — check that data/employees/<name>/ folders contain face images.")

if __name__ == "__main__":
    main()
