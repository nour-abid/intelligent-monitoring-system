from pathlib import Path
import cv2
import numpy as np
import mediapipe as mp
import onnxruntime as ort

EMP_DIR = Path("data/employees")
OUT_DIR = Path("models/embeddings")
MODEL_PATH = Path("models/arcface/arcface.onnx")

PERSON_MIN = 3  # minimum usable images per employee

def l2_normalize(x, eps=1e-10):
    return x / (np.linalg.norm(x) + eps)

def preprocess(face_bgr, size=112):
    # Model expects NHWC float32: [1,112,112,3]
    face_rgb = cv2.cvtColor(face_bgr, cv2.COLOR_BGR2RGB)
    face_rgb = cv2.resize(face_rgb, (size, size))
    x = face_rgb.astype(np.float32)
    x = (x - 127.5) / 128.0
    x = np.expand_dims(x, axis=0)  # NHWC
    return x

def crop_from_bbox(img, bbox_rel):
    h, w = img.shape[:2]
    x1 = int(bbox_rel.xmin * w)
    y1 = int(bbox_rel.ymin * h)
    bw = int(bbox_rel.width * w)
    bh = int(bbox_rel.height * h)
    x2 = x1 + bw
    y2 = y1 + bh

    x1, y1 = max(0, x1), max(0, y1)
    x2, y2 = min(w - 1, x2), min(h - 1, y2)
    return x1, y1, x2, y2

def create_onnx_session(model_path: Path):
    # Try GPU first, fallback to CPU if DLLs / provider fail
    providers = ["CUDAExecutionProvider", "CPUExecutionProvider"]
    try:
        sess = ort.InferenceSession(str(model_path), providers=providers)
        return sess
    except Exception as e:
        print("[WARN] GPU providers failed, using CPU only.")
        print("       Reason:", e)
        sess = ort.InferenceSession(str(model_path), providers=["CPUExecutionProvider"])
        return sess

def main():
    assert MODEL_PATH.exists(), f"Missing model: {MODEL_PATH}"
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    # ---- ONNX session + I/O names (this is what your script was missing) ----
    sess = create_onnx_session(MODEL_PATH)
    inp_name = sess.get_inputs()[0].name
    out_name = sess.get_outputs()[0].name
    # -----------------------------------------------------------------------

    # ---- Face detector (MediaPipe) ----
    mp_face = mp.solutions.face_detection
    detector = mp_face.FaceDetection(model_selection=0, min_detection_confidence=0.6)

    employees = [p for p in EMP_DIR.iterdir() if p.is_dir()]
    if not employees:
        print(f"No employees found in {EMP_DIR.resolve()}")
        return

    for emp in sorted(employees):
        img_paths = list(emp.glob("*.jpg")) + list(emp.glob("*.png")) + list(emp.glob("*.jpeg"))
        if not img_paths:
            print(f"[SKIP] {emp.name}: no images")
            continue

        embs = []
        for ip in img_paths:
            img = cv2.imread(str(ip))
            if img is None:
                print(f"[WARN] {emp.name}: cannot read {ip.name}")
                continue

            rgb = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
            res = detector.process(rgb)
            if not res.detections:
                print(f"[WARN] {emp.name}: no face {ip.name}")
                continue

            # pick most confident face
            det = max(res.detections, key=lambda d: d.score[0])
            x1, y1, x2, y2 = crop_from_bbox(img, det.location_data.relative_bounding_box)
            face = img[y1:y2, x1:x2]
            if face.size == 0:
                print(f"[WARN] {emp.name}: empty crop {ip.name}")
                continue

            x = preprocess(face, 112)
            emb = sess.run([out_name], {inp_name: x})[0][0]  # (512,)
            emb = l2_normalize(emb.astype(np.float32))
            embs.append(emb)

        if len(embs) < PERSON_MIN:
            print(f"[WARN] {emp.name}: only {len(embs)} usable images (need >= {PERSON_MIN})")
            continue

        mean_emb = l2_normalize(np.mean(np.stack(embs), axis=0))
        out_path = OUT_DIR / f"{emp.name}.npy"
        np.save(out_path, mean_emb)
        print(f"[OK] {emp.name}: saved -> {out_path}")

if __name__ == "__main__":
    main()
