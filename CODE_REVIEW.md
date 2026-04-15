# Code Review: Attendance Verification System (RTSP + InsightFace + Anti-Spoofing)

## Executive Summary
The script is well-structured and production-ready with good error handling. It successfully integrates InsightFace face recognition, MiniFASNet/YOLO anti-spoofing, and SQLite attendance logging. However, there are several inconsistencies and minor inefficiencies that should be addressed.

---

## 1. Critical Issues

### None Identified
The script has no critical bugs that would prevent operation.

---

## 2. High Priority Issues

### Issue #1: Inconsistent Liveness Checking for New vs. Matched Tracks
**Location**: Lines 606 (new tracks) vs. 551 (matched tracks)
**Severity**: Medium
**Description**:
- **New tracks**: Compute embeddings if `real_prob >= REAL_THR` (single threshold check)
- **Matched tracks**: Compute embeddings only if `is_live_track()` returns True (requires 7/10 votes ≥ REAL_THR)

This asymmetry means:
- A new face with one high anti-spoof score immediately gets ArcFace embedding
- An existing face needs accumulated evidence before embedding is computed

**Impact**: Could lead to embeddings being computed for faces that only briefly appear real, or conversely, missing recognition for genuinely real faces that take time to accumulate enough votes.

**Recommendation**: Align the logic. Options:
1. For new tracks, require at least 2 anti-spoof checks before embedding (add frame counter)
2. Or, accumulate new track embeddings gradually like matched tracks
3. Or, document that new tracks use a different (less strict) liveness criteria

---

### Issue #2: Duplicate MARGIN Definition
**Location**: Lines 22 and 168
**Severity**: Low
**Description**: `MARGIN = 0.08` is defined twice. Line 22 is overwritten by line 168.
**Recommendation**: Remove line 22 (keep line 168 near the function that uses it).

---

### Issue #3: Import Inside Function
**Location**: Line 368 in `get_stable_label()`
**Severity**: Low
**Description**: `from collections import Counter` is imported inside a function. This is called every frame during check-in logic.
**Impact**: Minor performance hit (negligible in practice, but bad practice).
**Recommendation**: Move to line 17 with the other collection imports. Change:
```python
from collections import deque  # Line 17
```
To:
```python
from collections import deque, Counter  # Line 17
```
Then remove `from collections import Counter` from line 368.

---

## 3. Functional Issues

### Issue #4: Anti-Spoof Disabled → Spoof Votes Filled Every Frame
**Location**: Lines 545-548 in matched track update section
**Severity**: Medium
**Description**:
When `ANTI_SPOOF_ENABLED = False`, the code does:
```python
elif not ANTI_SPOOF_ENABLED:
    track["last_real_prob"] = 0.95
    track["spoof_votes"].append(0.95)  # Every frame for every matched track!
```

Since `SPOOF_WINDOW = 10`, this means any matched track becomes "live" after just 10 frames (~0.5 seconds). The spoof_votes deque fills instantly without any actual anti-spoof checking.

**Current Behavior**:
1. Frame 1: spoof_votes = [0.95]
2. Frame 2: spoof_votes = [0.95, 0.95]
3. ...
4. Frame 10: spoof_votes = [0.95, ..., 0.95] → is_live_track() returns True immediately

**Recommendation**:
Option A: Only append when matched track age is > some threshold:
```python
elif not ANTI_SPOOF_ENABLED and frame_count % ANTI_SPOOF_EVERY_N_FRAMES == 0:
    track["last_real_prob"] = 0.95
    track["spoof_votes"].append(0.95)
```

Option B: Only append once per track:
```python
elif not ANTI_SPOOF_ENABLED and len(track["spoof_votes"]) < 1:
    # Only append once per track
```

The first option is better - it maintains consistency with the enabled case.

---

### Issue #5: MiniFASNet Device Initialization
**Location**: Line 405
**Severity**: Low
**Description**: `AntiSpoofPredict(device_id=0)` hardcodes GPU device ID, but InsightFace uses CPU. While the code has a fallback to CPU if CUDA unavailable, this inconsistency could cause confusion.
**Impact**: Works fine in practice (falls back to CPU automatically).
**Recommendation**: Either:
1. Set `device_id=-1` to force CPU (consistent with InsightFace), or
2. Document why `device_id=0` is used despite CPU-only InsightFace

---

## 4. Code Quality Issues

### Issue #6: Missing Null Check in match_identity()
**Location**: Line 177
**Severity**: Low
**Description**:
```python
best_name, best_sim = sims[0]
second_sim = sims[1][1] if len(sims) > 1 else -1.0
```

If `sims` is empty, line 177 will crash with IndexError. Although `sims` should never be empty (there's always ≥1 known embedding), defensive coding would be safer.

**Recommendation**: Add check:
```python
if not sims:
    return "Unknown", 0.0, -1.0

best_name, best_sim = sims[0]
second_sim = sims[1][1] if len(sims) > 1 else -1.0
```

---

### Issue #7: RTSP Stream Initialization Check
**Location**: Line 436-437
**Severity**: Low
**Description**: If RTSP fails to open initially, error message suggests checking URL/credentials, but the actual problem could be network connectivity, port blocking, etc.
**Recommendation**: Improve error message:
```python
if not cap.isOpened():
    error_msg = (
        "❌ Cannot open RTSP stream. Check:\n"
        "  - RTSP URL is correct (test with VLC)\n"
        "  - Camera IP is reachable (ping it)\n"
        "  - Username/password are correct\n"
        f"  - URL: {RTSP_URL}"
    )
    raise SystemExit(error_msg)
```

---

## 5. Performance Considerations

### ✅ Good: Face Tracking with Centroid Matching
**Location**: Lines 325-350
The greedy centroid-distance matching is efficient for up to 6 faces (MAX_FACES). O(n*m) is acceptable.

### ✅ Good: Low-Latency RTSP Configuration
**Location**: Lines 280-305
TCP transport, 5-second timeout, and 1-frame buffer are appropriate for real-time video processing.

### ✅ Good: Selective Anti-Spoof Checking
**Location**: Line 521 (`ANTI_SPOOF_EVERY_N_FRAMES = 5`)
Checking every 5 frames rather than every frame balances detection latency with CPU usage.

### ⚠️ Consider: ArcFace Embedding Computation
Currently, embeddings are computed every frame for live faces. This is already optimized (only for live faces), but consider:
- **Current**: Compute embedding every frame if face is live
- **Alternative**: Compute embedding every N frames (like anti-spoof)

For recognition accuracy, computing every frame is fine since cosine_sim is very fast (~1μs).

---

## 6. Database Operations Review

### ✅ Correct: One Check-in Per Person Per Day
**Location**: Lines 210-214
The UNIQUE constraint `(person, event, substr(ts_text, 1, 10))` ensures exactly one check-in per person per day at the DB level. Excellent.

### ✅ Correct: Graceful IntegrityError Handling
**Location**: Lines 262-274 in `log_checkin_once_per_day()`
When duplicate check-in is attempted, the code catches IntegrityError and returns existing time. Good.

### ✅ Good: WAL Mode
**Location**: Line 197-198
`PRAGMA journal_mode=WAL` and `PRAGMA synchronous=NORMAL` allow app to continue while DB is accessed. Good for multi-reader scenarios.

---

## 7. Anti-Spoofing Implementation Review

### ✅ Good: Flexible Model Support
Lines 400-425 correctly prioritize MiniFASNet over YOLO with fallback logic.

### ✅ Good: Liveness Voting System
Lines 144-153: Requires 7/10 votes ≥ 0.85 to be considered "live". This is strict and appropriate.

### ⚠️ Issue: real_prob_from_minifasnet_result() Edge Cases
**Location**: Lines 116-141
The function has good error handling, but the clamping to [0, 1] (line 133) masks potential issues. If MiniFASNet returns invalid values, clamping hides the problem.

**Recommendation**: Log warnings when clamping occurs:
```python
real_prob = float(prediction[0, 1])
if not (0.0 <= real_prob <= 1.0):
    print(f"[WARN] MiniFASNet returned invalid probability: {real_prob}, clamping to [0,1]")
return max(0.0, min(1.0, real_prob))
```

---

## 8. Face Detection Quality

### ⚠️ Missing: Blur/Quality Check
**Location**: Detection filtering (lines 483-493)
Current filters:
- ✅ DET_SCORE_THR = 0.5 (confidence)
- ✅ MIN_FACE_SIZE = 50 (minimum dimension)
- ❌ No blur/sharpness check

**Impact**: Blurry faces may pass through and cause false positives.

**Recommendation**: Add Laplacian variance check:
```python
def is_face_sharp(face_crop, threshold=20.0):
    """Check if face is sharp enough for recognition"""
    gray = cv2.cvtColor(face_crop, cv2.COLOR_BGR2GRAY)
    laplacian_var = cv2.Laplacian(gray, cv2.CV_64F).var()
    return laplacian_var > threshold

# In face detection loop (lines 482-499):
if not is_face_sharp(face_crop):
    continue
```

---

## 9. Summary of Recommendations

| Priority | Issue | Fix Time |
|----------|-------|----------|
| HIGH | Align new vs. matched track liveness logic | 15 min |
| MEDIUM | Anti-spoof disabled fills spoof_votes too fast | 5 min |
| LOW | Duplicate MARGIN definition | 1 min |
| LOW | Move Counter import to top | 1 min |
| LOW | Add null check in match_identity() | 2 min |
| MEDIUM | Add blur/quality check | 10 min |
| LOW | Improve error messages | 5 min |
| LOW | Log warnings in MiniFASNet result extraction | 5 min |

---

## 10. Performance Metrics (Estimated)

With max 6 faces per frame:
- **InsightFace detection**: ~50-100ms per frame
- **MiniFASNet anti-spoof** (every 5th frame): ~30ms per inference
- **ArcFace embedding**: ~15ms per face (already included in detection)
- **Database operations**: <1ms (async in WAL mode)
- **Rendering**: ~10ms

**Total**: ~100-150ms per frame at 20fps = acceptable for real-time applications.

---

## Conclusion

The script is **production-ready** with only minor issues to address. The architecture is sound, error handling is robust, and performance is acceptable for real-time processing. Implementing the HIGH priority fix would improve consistency, but the system works correctly as-is.

