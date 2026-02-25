import cv2
import numpy as np
from collections import deque

try:
    from config import config
    from logger import logger
except ModuleNotFoundError:
    from src.recognition.config import config
    from src.recognition.logger import logger

def is_face_sharp(face_crop, blur_threshold=20.0):
    if face_crop is None or face_crop.size == 0:
        return False
    try:
        gray = cv2.cvtColor(face_crop, cv2.COLOR_BGR2GRAY)
        laplacian_var = cv2.Laplacian(gray, cv2.CV_64F).var()
        return laplacian_var >= blur_threshold
    except Exception:
        return True

def preprocess_face_for_antispoof(face_crop, target_size=80):
    if face_crop is None or face_crop.size == 0:
        return None
    try:
        h, w = face_crop.shape[:2]
        if h < 10 or w < 10:
            return None

        # Add margin (Silent-Face usually benefits from head/background context)
        pad_ratio = 0.25  # try 0.20 ~ 0.35
        pad_x = int(w * pad_ratio)
        pad_y = int(h * pad_ratio)

        # Since we only have the crop (not full frame), pad using border replication
        expanded = cv2.copyMakeBorder(
            face_crop,
            pad_y, pad_y, pad_x, pad_x,
            borderType=cv2.BORDER_REPLICATE
        )

        resized = cv2.resize(expanded, (target_size, target_size), interpolation=cv2.INTER_LINEAR)

        if len(resized.shape) == 2:
            resized = cv2.cvtColor(resized, cv2.COLOR_GRAY2BGR)

        return resized
    except Exception as e:
        logger.warning(f"Failed to preprocess face for anti-spoof: {e}")
        return None

def real_prob_from_ultralytics_result(r0):
    if r0.probs is None:
        return 0.0
    probs = r0.probs.data.cpu().numpy() if hasattr(r0.probs, 'data') else np.array(r0.probs)
    if len(probs) > 1:
        return float(probs[1])
    elif len(probs) > 0:
        return float(probs[0])
    else:
        return 0.0

def real_prob_from_minifasnet_result(prediction):
    if prediction is None or isinstance(prediction, str):
        return 0.0
    try:
        if not isinstance(prediction, np.ndarray):
            prediction = np.array(prediction)
        if prediction.ndim == 2 and prediction.shape[0] == 1 and prediction.shape[1] >= 2:
            real_prob = float(prediction[0, 1])
            return max(0.0, min(1.0, real_prob))
        elif prediction.ndim == 1 and len(prediction) >= 2:
            real_prob = float(prediction[1])
            return max(0.0, min(1.0, real_prob))
        elif prediction.ndim == 1 and len(prediction) == 1:
            return max(0.0, min(1.0, float(prediction[0])))
        else:
            logger.warning(f"Unexpected MiniFASNet output shape: {prediction.shape}")
            return 0.0
    except Exception as e:
        logger.warning(f"Error extracting MiniFASNet probability: {e}")
        return 0.0

def is_live_track(track, tuning=None):
    """
    Decide if a track is live using rolling anti-spoof probabilities.
    Safe defaults are used if config keys are missing.
    """
    tuning = tuning or {}

    spoof_threshold = float(tuning.get("spoof_threshold", 0.55))
    spoof_min_hits = int(tuning.get("spoof_min_hits", 2))
    spoof_window = int(tuning.get("spoof_window", 10))

    vals = [float(v) for v in list(track.get("spoof_votes", [])) if v is not None]
    if len(vals) == 0:
        # If no anti-spoof result yet, don't block immediately
        return True

    positives = sum(1 for v in vals if v >= spoof_threshold)
    avg_real = sum(vals) / max(1, len(vals))

    # Pass if enough positive votes, OR average is decent after a few samples
    if positives >= spoof_min_hits:
        return True

    if len(vals) >= min(3, spoof_window) and avg_real >= spoof_threshold:
        return True

    return False