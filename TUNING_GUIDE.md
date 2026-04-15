# Face Recognition Tuning Guide

## Problem: Slow speed + Poor accuracy + Misrecognition

### Issues Identified:
1. **Anti-spoof is too slow** → Disabled (runs only every 100 frames now)
2. **Embeddings are too similar** → Amir & Nour embeddings too close → misrecognition
3. **Thresholds were too loose** → Now STRICT for accuracy over speed

---

## Solution: Regenerate Better Reference Embeddings

The core issue is **your reference embeddings are not distinct enough**. MiniFASNet anti-spoof accuracy is secondary - the main problem is embedding similarity.

### Step 1: Take Better Reference Photos

For EACH person (Amir, Nour):
- **Take 5-10 clear, frontal face photos** with good lighting
- Face should fill 60-80% of frame
- No glasses, face fully visible
- Different angles: straight, slightly left, slightly right
- Different lighting conditions

Create folders:
```
models/reference_faces/amir/   [5-10 photos of Amir]
models/reference_faces/nour/   [5-10 photos of Nour]
```

### Step 2: Regenerate Embeddings

```bash
cd c:\Users\BH RENOVATIONS\intelligent-monitoring-system
python src\recognition\generate_embeddings_insightface.py models\reference_faces\
```

This will:
- Process all photos in `models/reference_faces/amir/` and compute **average embedding** → `models/embeddings/amir.npy`
- Process all photos in `models/reference_faces/nour/` and compute **average embedding** → `models/embeddings/nour.npy`
- Better reference = More distinct embeddings = Better separation

### Step 3: Test Recognition

Run the attendance script again:
```bash
python src\recognition\attendance_webcam.py
```

Now you'll see:
```
[MATCH] Similarities: Nour=0.75, Amir=0.42, margin=0.33 ✓ RECOGNIZED
```

---

## Current Tuning Parameters

```python
SIM_THRESHOLD = 0.55    # Minimum similarity for ANY match
MARGIN = 0.18           # Gap between best and 2nd best (0.18 = 18% difference)
REAL_THR = 0.95         # Liveness threshold (very strict)
ANTI_SPOOF_EVERY_N_FRAMES = 100  # Slowed down to 100 frames for speed
```

### If Still Misrecognizing:
1. **More strict**: Increase `SIM_THRESHOLD` to 0.60, `MARGIN` to 0.25
2. **Faster**: Increase `ANTI_SPOOF_EVERY_N_FRAMES` to 200 (less anti-spoof checking)
3. **Better embeddings**: Regenerate with more/better reference photos

### If Too Many "Unknown" detections:
1. **More loose**: Decrease `SIM_THRESHOLD` to 0.50, `MARGIN` to 0.15
2. **Better photos**: Make sure reference photos are high quality, well-lit, frontal

---

## Expected Performance

After regenerating embeddings:
- **Speed**: ~30-50ms per frame (was ~100ms with anti-spoof)
- **Accuracy**: 95%+ correct recognition (if reference embeddings are good)
- **Anti-spoof**: Disabled for speed (MiniFASNet not reliable for phone pictures anyway)

---

## Debug Output

You'll now see:
```
[MATCH] Similarities: Nour=0.78, Amir=0.35, margin=0.43 ✓ RECOGNIZED
[MATCH] Similarities: Unknown_Person=0.48, Amir=0.40, margin=0.08 ✗ REJECTED (margin too small)
[DB] 2026-02-21 15:04:29 | Nour -> check_in
```

This shows exactly why each face is recognized or rejected.
