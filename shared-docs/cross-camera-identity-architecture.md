# Cross-Camera Same-Day Identity Continuity Architecture

---

## 1. Current-State Reading

### 1.1 Camera A — Attendance / Check-in Pipeline

| Aspect | Detail |
|--------|--------|
| Entry point | `src/recognition/attendance_webcam.py` |
| Face detection | InsightFace `buffalo_l` — high resolution, frontal, close-range |
| Embedding | 512-d ArcFace (InsightFace-native) |
| Tracking | DeepSORT (max_age=120, n_init=1) |
| Anti-spoof | MiniFASNet ensemble (2 models, optional) |
| Identity matching | Cosine similarity vs static `models/embeddings/*.npy`, threshold 0.55, margin 0.10 |
| Smoothing | 10-frame majority vote, min_hits=3 |
| Persistence | SQLite `data/db/attendances.db` — 1 check-in/person/day via UNIQUE index |
| Config | `config.yaml` |

**Strengths**: High-quality face crops (large, frontal, well-lit), reliable identity confirmation, liveness verification.

### 1.2 Camera B — Surveillance / Activity Pipeline

| Aspect | Detail |
|--------|--------|
| Entry point | `surveillance/main_surveillance.py` |
| Activity detection | YOLO11 (`surveillance/models/best.pt`), 4 classes |
| Person tracking | DeepSORT (max_age≥30, n_init=2) |
| Face detection | MediaPipe inside expanded person crop |
| Embedding | ArcFace ONNX (`models/arcface/arcface.onnx`) — 512-d |
| Identity matching | Cosine similarity vs static `models/embeddings/*.npy`, threshold 0.45, margin 0.06 |
| State management | `StateManager` — confirmed vault + candidate buffer + contradiction handling |
| Reassociation | `ReassociationCache` — center-distance + IoU + confidence scoring, face-confirmed extended TTL (15s) |
| Persistence | Dual-write: CSV + SQLite via `SurveillanceRepository` |
| Config | `surveillance/config/settings.yaml` |

**Strengths**: Sophisticated identity state machine already exists — confirmed vault, candidate-hit accumulation, contradiction-requires-extra-hits, reassociation cache with face-confirmed trust tiers.

**Weaknesses**: Face crops are small/angled/intermittent → face matching is unreliable; no information flows from Camera A; identity drops to "Unknown" when face is lost and reassociation expires.

### 1.3 The Gap

```
Camera A                          Camera B
──────────                        ──────────
HIGH-quality face crop            SMALL/ANGLED face crop
(InsightFace buffalo_l,           (MediaPipe inside person
 close-range, frontal)             crop, often occluded)

Strong identity confidence        Weaker identity confidence
(threshold 0.55, margin 0.10)     (threshold 0.45, margin 0.06)

Logs to attendances.db            Logs to surveillance_events.db
                                  
         ╳   NO RUNTIME LINK   ╳
```

Both cameras load the same static `models/embeddings/*.npy` files independently, but:
- **Camera A never tells Camera B who checked in today.**
- **Camera A never shares its high-quality live embeddings.**
- **Camera B has no mechanism to boost confidence for expected people.**
- **When Camera B loses a face, identity decays with no way to recover beyond the 15-second reassociation window.**

---

## 2. Target Architecture

### 2.1 Conceptual Model

```
┌──────────────────────────────────────────────────────────────────────────┐
│                     DAILY IDENTITY STORE (new)                          │
│  SQLite file: data/db/daily_identities.db                              │
│  Table: daily_profiles                                                  │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ date | person | checkin_ts | embedding_blob | confidence | source │ │
│  └────────────────────────────────────────────────────────────────────┘ │
└─────────┬────────────────────────────────────────────────┬─────────────┘
          │ WRITE (on check-in)                            │ READ (periodic)
          │                                                │
┌─────────▼─────────┐                          ┌───────────▼──────────────┐
│   CAMERA A        │                          │   CAMERA B               │
│   Check-in        │                          │   Surveillance           │
│                   │                          │                          │
│ 1. Face detect    │                          │ 1. YOLO activity detect  │
│ 2. ArcFace embed  │──── fresh embedding ────>│ 2. DeepSORT tracking     │
│ 3. Identity match │                          │ 3. Face match (enhanced) │
│ 4. Liveness check │                          │    - static embeddings   │
│ 5. Log attendance │                          │    + daily fresh embeds  │
│ 6. ★ Write daily  │                          │    + check-in confidence │
│      profile      │                          │      boost              │
│                   │                          │ 4. State machine         │
│                   │                          │    (vault + candidate +  │
│                   │                          │     contradiction +     │
│                   │                          │     reassociation)      │
│                   │                          │ 5. ★ Extended identity   │
│                   │                          │      persistence for    │
│                   │                          │      checked-in persons │
│                   │                          │ 6. Log events            │
└───────────────────┘                          └──────────────────────────┘
```

### 2.2 Design Principles

1. **Additive, not invasive** — Camera A and Camera B remain independent processes. The daily store is the only coupling point. If the daily store fails, both cameras work exactly as today.
2. **File-based bridge** — SQLite file read by Camera B at startup + periodic refresh (every 30–60s). No IPC, no sockets, no message broker.
3. **Fresh embeddings are supplementary** — Camera B continues matching against static embeddings. Daily fresh embeddings are additional comparison targets with a configurable weight boost.
4. **Check-in awareness is a prior, not a hard constraint** — Knowing someone checked in lets Camera B lower the acceptance threshold or add a confidence bonus, but it never forces an identity assignment.
5. **Identity persistence through tracking continuity** — Once Camera B confirms an identity (with high confidence, assisted by Camera A's check-in data), that identity survives face loss through the existing confirmed vault + reassociation cache, with extended TTLs for checked-in people.

### 2.3 Identity Lifecycle (Target)

```
Time    Camera A                   Daily Store          Camera B
─────────────────────────────────────────────────────────────────────
08:30   Amir walks to Camera A
08:30   Face detected, embedded     
08:31   Identity = "Amir" (0.82)
08:31   Liveness = LIVE
08:31   Attendance logged           
08:31   ★ Write daily profile ───→  Amir: embedding,   
                                    conf=0.82,          
                                    ts=08:31            
                                                        
09:00                                                   Amir enters Camera B FOV
09:00                                                   DeepSORT assigns track_id=7
09:00                                                   Face detected in person crop
09:01                                ←── read ──────    Match against static + ★fresh
09:01                                                   Cosine sim = 0.68 (static)
                                                        Cosine sim = 0.74 (fresh)  ★
                                                        + check-in boost = +0.03   ★
                                                        Effective = 0.77 → ACCEPT
09:01                                                   Vault confirmed: "Amir"
        
09:15                                                   Amir turns away from camera
09:15                                                   No face detected
09:15                                                   Vault identity PERSISTS ★
09:15                                                   (Amir, source=face, conf=0.77)
09:15                                                   Track continues via DeepSORT
        
09:20                                                   Amir blocked by furniture
09:20                                                   Track lost → ReassociationCache
09:20                                                   ★ Extended TTL (checked-in, 30s)
        
09:20:15                                                New track appears nearby
09:20:15                                                Reassociation → "Amir" recovered
        
09:45                                                   Amir's face reappears
09:45                                                   Fresh face match confirms vault
09:45                                                   Identity refreshed: conf=0.76
```

---

## 3. Recommended Phased Implementation Plan

### Phase 1 — Daily Identity Store + Check-in Awareness (Core Bridge)

**Goal**: Camera A writes a daily profile on check-in; Camera B reads it and uses fresh embeddings + check-in presence as supplementary matching evidence.

**Duration**: 3–4 days

**Deliverables**:
1. `surveillance/shared/daily_identity_store.py` — Read/write API for daily profiles (SQLite)
2. Modification to `src/recognition/attendance_webcam.py` — Write daily profile after attendance logging
3. Modification to `surveillance/identity_matcher.py` — Load daily store, use fresh embeddings in matching, apply check-in confidence boost
4. Modification to `surveillance/config/settings.yaml` — New config keys for daily store path + boost parameters

**What changes functionally**:
- Camera B matches face embeddings against BOTH static (enrollment) AND fresh (today's check-in) embeddings
- Best score across both sets is used
- Checked-in people get a small configurable confidence bonus (e.g., +0.03) applied to the final similarity score
- This bonus is enough to resolve borderline matches but cannot promote truly bad matches

**Files involved**:

| File | Change type | What changes |
|------|-------------|--------------|
| `surveillance/shared/__init__.py` | NEW | Empty module init |
| `surveillance/shared/daily_identity_store.py` | NEW | ~120 lines: `DailyIdentityStore` class with `write_profile()`, `read_today_profiles()`, auto-expiry of old days |
| `src/recognition/attendance_webcam.py` | MODIFY | ~10 lines added after attendance log: extract best embedding, call `store.write_profile()` |
| `surveillance/identity_matcher.py` | MODIFY | ~40 lines: load daily store in `_lazy_init()`, merge fresh embeddings into `_match()`, apply check-in boost |
| `surveillance/config/settings.yaml` | MODIFY | ~8 lines: `daily_store_path`, `daily_store_refresh_sec`, `checkin_confidence_boost` |

**Data schema** (daily_profiles table):

```sql
CREATE TABLE IF NOT EXISTS daily_profiles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    date_key       TEXT    NOT NULL,  -- "YYYY-MM-DD"
    person         TEXT    NOT NULL,
    checkin_ts     REAL    NOT NULL,  -- Unix epoch of check-in
    embedding      BLOB    NOT NULL,  -- 512-d float32 (2048 bytes)
    confidence     REAL    NOT NULL,  -- Camera A match confidence
    source         TEXT    NOT NULL DEFAULT 'camera_a_checkin',
    UNIQUE(date_key, person)          -- 1 profile per person per day
);
```

**Concurrency safety**: Camera A writes with WAL mode. Camera B reads with `PRAGMA query_only=ON` (matching the existing dual-database pattern already in the Laravel backend).

---

### Phase 2 — Extended Identity Persistence for Checked-In People

**Goal**: Once Camera B confirms a checked-in person's identity, extend the reassociation window and make the confirmed vault stickier.

**Duration**: 1–2 days

**Deliverables**:
1. Modification to `surveillance/state_manager.py` — Checked-in awareness in ReassociationCache + StateManager
2. Minor wiring in `surveillance/main_surveillance.py` — Pass daily store handle to state_mgr

**What changes functionally**:
- `ReassociationCache.store()` uses a longer TTL for people who are both face-confirmed AND checked-in today (e.g., 30s instead of 15s)
- `StateManager.set_identity()` treats check-in-confirmed matches with slightly elevated trust (fewer candidate-hits required for promotion if the person was seen on Camera A today)
- Net effect: "Amir" persists through longer occlusions if Amir checked in today

**Files involved**:

| File | Change type | What changes |
|------|-------------|--------------|
| `surveillance/state_manager.py` | MODIFY | ~25 lines: `SurveillanceTrackState` gets `is_checked_in_today: bool` field; `ReassociationCache` uses it for TTL extension; `StateManager` exposes `mark_checked_in()` |
| `surveillance/main_surveillance.py` | MODIFY | ~10 lines: After loading daily store, call `state_mgr.mark_checked_in(name)` for each today's profile |
| `surveillance/config/settings.yaml` | MODIFY | ~3 lines: `reassoc_checked_in_max_gap_sec`, `checked_in_promote_hits` |

---

### Phase 3 — Appearance Descriptor Augmentation (Optional / Time-Permitting)

**Goal**: Add a lightweight body/clothing appearance vector to supplement face matching when face is unavailable.

**Duration**: 4–5 days (including testing)

**Deliverables**:
1. `surveillance/shared/appearance_encoder.py` — Lightweight appearance feature extractor
2. Modification to `surveillance/state_manager.py` — Store per-track appearance vector
3. Modification to `surveillance/identity_matcher.py` — Fuse face + appearance scores
4. Modification to `surveillance/shared/daily_identity_store.py` — Optionally store Camera A appearance

**Approach**: Use a color-histogram-based descriptor (HSV histogram of upper/lower body crops) rather than a learned ReID model. Reasons:
- No additional model download or GPU cost
- 1-2ms per frame
- Sufficient for same-day clothing continuity
- Robust to lighting variation via HSV color space
- Does not require training or fine-tuning

**Alternative (heavier, higher quality)**: OSNet-AIN (lightweight ReID network, ~2M parameters, ONNX available). Only pursue if the histogram-based approach proves insufficient during Phase 2 testing.

**Files involved**:

| File | Change type | What changes |
|------|-------------|--------------|
| `surveillance/shared/appearance_encoder.py` | NEW | ~80 lines: `extract_appearance(frame, bbox) -> np.ndarray` — HSV histogram, normalized |
| `surveillance/state_manager.py` | MODIFY | ~15 lines: `appearance_vector` field on `SurveillanceTrackState`, `appearance_sim()` helper |
| `surveillance/identity_matcher.py` | MODIFY | ~20 lines: fusion score = `α * face_sim + (1-α) * appearance_sim` when face available, fall back to appearance-only with elevated threshold |
| `surveillance/shared/daily_identity_store.py` | MODIFY | ~10 lines: optional `appearance_blob` column |
| `surveillance/config/settings.yaml` | MODIFY | ~5 lines: `appearance_weight`, `appearance_only_threshold` |

---

## 4. Risks and Mitigation

### Phase 1 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Camera A and Camera B never run simultaneously (test environment uses 1 camera) | HIGH | Blocks integration testing | Write a standalone `inject_daily_profile.py` test script that writes a daily profile without running Camera A. Test Camera B in isolation against injected profiles. |
| Fresh embedding from Camera A is under different lighting/angle than Camera B sees | MEDIUM | Fresh embedding doesn't help | Keep static embeddings as primary; fresh embedding is supplementary. Best-of-both scoring ensures no regression. |
| SQLite concurrent write from Camera A + read from Camera B causes locking | LOW | Brief read delay on Camera B | WAL journal mode handles this. Camera B reads are periodic (30-60s), not per-frame. |
| `DailyIdentityStore` introduces import dependency between Camera A and surveillance | MEDIUM | Module coupling | Place the store in `surveillance/shared/` — Camera A imports only this one module. Camera A's existing code is otherwise untouched. |
| Confidence boost causes false positives (wrong person accepted due to boost) | LOW | Misidentified surveillance events | Keep boost small (+0.03). The boost cannot push a 0.30 match above the 0.45 threshold; it only resolves genuinely borderline cases (0.42 → 0.45). |

### Phase 2 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Extended reassociation TTL causes identity leak (Person A's identity carried to Person B) | MEDIUM | Wrong identity in events | Gate extension: only extend TTL for face-confirmed + checked-in. The existing center-distance + size-ratio gates still apply. If two people swap positions within 30s, spatial gates should reject. |
| Reduced candidate-hit requirement causes premature promotion | LOW | Weak identities promoted too easily | Only reduce by 1 hit (3→2), and only when the person was seen on Camera A today AND the confidence is above _CONFIDENCE_WEAK. The confirmed vault still requires either strong single-frame or accumulated evidence. |

### Phase 3 Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| HSV histogram is too coarse (two people wearing similar colors) | MEDIUM | False appearance matches | Never use appearance alone for identity assignment. Use it only as a secondary signal to extend reassociation or break ties. Require at least one prior face confirmation. |
| Appearance changes mid-day (jacket removed, sleeves rolled) | MEDIUM | Appearance mismatch | Use rolling mean of last N appearance vectors (not a single snapshot). Set a decay factor so old measurements gradually lose weight. |
| Implementation takes longer than estimated, eats into defense prep | MEDIUM | Time pressure | Phase 3 is explicitly optional. Define a hard cutoff date (2 weeks before soutenance). If Phase 3 is not working by then, defer it and document as "Future Work." |

---

## 5. What to Implement Now (Immediate — This Week)

### 5.1 Daily Identity Store Module

```
surveillance/shared/daily_identity_store.py
```

A self-contained class that:
- Creates/manages a small SQLite database (`data/db/daily_identities.db`)
- Exposes `write_profile(person, embedding, confidence)` — called by Camera A
- Exposes `read_today_profiles() -> Dict[str, DailyProfile]` — called by Camera B
- Auto-deletes profiles older than 2 days (prevents unbounded growth)
- Uses WAL journal mode for safe concurrent access

### 5.2 Camera A Integration (minimal)

In `src/recognition/attendance_webcam.py`, after the existing `log_checkin_once_per_day()` call, add ~8 lines:

```python
# After successful check-in:
if checkin_ts and best_embedding is not None:
    try:
        daily_store.write_profile(
            person=label,
            embedding=best_embedding,
            confidence=best_sim
        )
    except Exception:
        log.warning("Failed to write daily profile for %s", label, exc_info=True)
```

The `best_embedding` is the ArcFace embedding that was used for the identity match — it's already computed. No additional inference needed.

### 5.3 Camera B Integration (IdentityMatcher enhancement)

In `surveillance/identity_matcher.py`:

1. Add `daily_store` parameter to `__init__()` (optional, default `None`)
2. In `_lazy_init()`, load today's profiles: `self._daily_profiles = store.read_today_profiles()`
3. Add a `_refresh_daily()` method called every 60 seconds to pick up new check-ins
4. In `_match()`, compare `emb` against both `self._known` (static) and daily profiles, take best score
5. If best match name is in today's checked-in set, add `checkin_confidence_boost`

### 5.4 Test Script

Create `test_daily_identity.py` — a standalone script that:
1. Writes a fake daily profile for "Amir" with a known embedding
2. Reads it back and verifies the embedding matches
3. Simulates Camera B's matching flow against the profile

This validates the bridge without requiring both cameras to run simultaneously.

---

## 6. What to Defer

### 6.1 Defer to Phase 2 (Next Week, If Phase 1 Works)

- Extended reassociation TTL for checked-in people
- Reduced candidate-hit promotion threshold
- `is_checked_in_today` field on `SurveillanceTrackState`

### 6.2 Defer to Phase 3 (Only If Time Allows, 2+ Weeks Before Defense)

- Appearance descriptor extraction (HSV histograms or learned ReID)
- Appearance-weighted scoring fusion
- Appearance vector in daily store
- Rolling appearance mean per track

### 6.3 Explicitly Defer to Post-Soutenance (Future Work)

| Feature | Reason to defer |
|---------|----------------|
| **Learned ReID model (OSNet-AIN, BoT)** | Adds model management complexity, GPU cost, and integration surface; HSV histograms cover 80% of the use case |
| **Cross-camera track handoff** | Requires camera topology awareness, overlapping FOV calibration, or temporal correlation. Not feasible with 2 independent streams |
| **Embedding refresh during surveillance** | Camera B updating the daily store with its own (lower quality) embeddings risks quality degradation |
| **Multi-embedding gallery per person** | Storing N embeddings from different angles and averaging or doing top-K matching adds storage + matching cost for marginal benefit in a controlled office |
| **Federated identity across N cameras** | Architecture only models 2 cameras; N-camera generalization is an engineering effort beyond the 1-month timeline |
| **Automatic enrollment from Camera A** | Auto-generating `models/embeddings/*.npy` from Camera A's best frames would be powerful but risks data quality regressions if unmonitored |
| **WebSocket real-time bridge** | A WebSocket channel from Camera A → Camera B for immediate identity push would remove the 30-60s polling delay, but is unnecessary for a workplace scenario where people don't teleport between cameras |

---

## 7. Recommended Model/Tool Choice Per Phase

### Phase 1: Daily Identity Store + Check-in Awareness

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| **Storage format** | SQLite (not JSON, not .npy files) | Atomic writes, concurrent read safety (WAL), auto-expiry via SQL, consistent with existing dual-database pattern |
| **Embedding format** | BLOB (raw float32 bytes) | Zero-overhead serialization: `embedding.tobytes()` / `np.frombuffer(blob, dtype=np.float32)`, exactly 2048 bytes per 512-d vector |
| **Polling interval** | 30–60 seconds | Check-ins happen at start of day. No need for sub-second freshness. Minimizes file I/O during surveillance loop |
| **Confidence boost** | +0.03 additive on final cosine-sim | Small enough to avoid false positives. A person with 0.42 sim (borderline) who checked in today gets 0.45 (just at threshold). A person with 0.30 sim gets 0.33 (still rejected). |
| **Copilot model for implementation** | **Sonnet** | This phase is straightforward file I/O + SQLite + minor modifications to existing well-understood code. No complex algorithmic reasoning needed. Fast iteration is more valuable than deep reasoning. |

### Phase 2: Extended Identity Persistence

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| **Reassociation TTL extension** | 30s for checked-in + face-confirmed (up from 15s) | Office occlusions (furniture, other people walking past) typically last 5-20s. 30s covers the realistic worst case without risking cross-person identity leak |
| **Candidate-hit reduction** | 3→2 for checked-in people (keep 3 for everyone else) | The check-in itself is strong corroborating evidence. Two face matches + check-in is comparable trust to three face matches alone |
| **Copilot model for implementation** | **Sonnet** | Changes are localized to state_manager.py. The logic patterns (threshold adjustment, TTL extension) are simple conditionals. |

### Phase 3: Appearance Descriptors (Optional)

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| **Method** | HSV histogram (upper/lower body) first | Zero additional model cost. 1-2ms extraction. Captures clothing color which is the strongest same-day appearance cue. |
| **Fallback** | OSNet-AIN via ONNX if histograms fail | Lightweight (2M params), pre-trained, ONNX-exportable. Only pursue if histogram similarity is too noisy in testing. |
| **fusion weight α** | 0.7 face + 0.3 appearance (when both available) | Face is always the primary modality. Appearance is supplementary. |
| **Appearance-only threshold** | 0.85 (histogram cosine-sim) | Very conservative. Appearance alone should never confirm identity — only extend an existing confirmed vault through an occlusion. |
| **Copilot model for implementation** | **Opus** | Fusion scoring, rolling mean updates, and the interaction between face/appearance signals require careful reasoning about edge cases and potential failure modes. Opus's deeper reasoning is justified here. |

---

## 8. Safest Next Implementation Step

### Step 0: Create the Daily Identity Store Module

**Why this is the safest first step**: It is a brand-new file with zero modifications to existing code. It cannot break anything. It can be tested in complete isolation. Once validated, it becomes the foundation for both Camera A writes (Phase 1b) and Camera B reads (Phase 1c).

**Exact file**: `surveillance/shared/daily_identity_store.py`

**Functional specification**:

```python
class DailyIdentityStore:
    """
    SQLite-backed store for same-day identity profiles.
    Written by Camera A on check-in. Read by Camera B periodically.
    """
    
    def __init__(self, db_path: str = "data/db/daily_identities.db"):
        """Open/create the DB with WAL mode. Auto-create table."""
    
    def write_profile(self, person: str, embedding: np.ndarray, 
                      confidence: float) -> None:
        """Upsert today's profile for person. 
        Replaces if person already checked in today (e.g., re-check-in)."""
    
    def read_today_profiles(self) -> Dict[str, DailyProfile]:
        """Return all profiles for today. 
        DailyProfile = namedtuple with person, embedding, confidence, checkin_ts."""
    
    def cleanup_old(self, keep_days: int = 2) -> int:
        """Delete profiles older than keep_days. Returns count deleted."""
    
    def close(self) -> None:
        """Flush and close connection."""
```

**Validation**: Create `test_daily_identity.py` that writes a profile, reads it back, asserts embedding fidelity (bitwise identical after round-trip through BLOB), asserts date filtering works, asserts cleanup works.

### After Step 0 is validated:

**Step 1a**: Modify `src/recognition/attendance_webcam.py` — Add 8 lines to write daily profile after check-in. Test by running Camera A and verifying a row appears in `daily_identities.db`.

**Step 1b**: Modify `surveillance/identity_matcher.py` — Load daily store, merge into matching. Test by injecting a profile and running Camera B against a test video.

**Step 1c**: Integration test — Run Camera A → check in → run Camera B → verify identity is confirmed faster / with higher confidence than without the daily store.

---

## Appendix A: Architecture Decision Record

### ADR-1: Why SQLite and not shared .npy files?

.npy files would require Camera A to write N files and Camera B to glob-read them. Atomic replacement is tricky on Windows. SQLite's WAL mode gives us atomic writes, concurrent readers, date-based expiry via SQL DELETE, and a single file to manage. The project already uses SQLite for both attendance and surveillance data — this is consistent.

### ADR-2: Why polling (30-60s) and not file-watching or IPC?

File-watching (e.g., `watchdog`) adds a dependency and platform-specific behavior. IPC (sockets, pipes, shared memory) adds coupling and failure modes. In a workplace scenario, the gap between check-in and appearing on Camera B is typically minutes, not seconds. A 30-60s polling interval is indistinguishable from real-time for this use case. Polling is simple, stateless, and debuggable.

### ADR-3: Why additive confidence boost (+0.03) and not threshold reduction?

Reducing the threshold globally (e.g., 0.45 → 0.42 for checked-in people) would weaken the rejection of imposters who happen to have a similar name but different face. An additive boost on the final similarity score means:
- The face still has to look like the person (base similarity must be close to threshold)
- The boost only resolves genuinely ambiguous cases
- The boost can be tuned independently of the base threshold
- The boost is transparent in logs: `raw_sim=0.42, checkin_boost=+0.03, effective=0.45`

### ADR-4: Why not ArcFace + appearance from the start?

Adding appearance descriptors in Phase 1 would double the integration surface:
- Camera A needs to extract appearance from a check-in frame (not just face)
- Camera B needs to extract appearance from every tracked person every N frames
- The daily store schema needs an additional BLOB column
- The matching function needs fusion logic with a tunable α weight
- Edge cases multiply (appearance available but face not, face available but appearance not, both available, neither available)

Starting with ArcFace-only keeps Phase 1 simple: one embedding type, one similarity score, one boost parameter. If Phase 1 proves insufficient, Phase 3 adds appearance as a clean extension.

### ADR-5: Why HSV histogram over learned ReID?

| Factor | HSV Histogram | OSNet-AIN |
|--------|--------------|-----------|
| Inference time | <1ms | 5-15ms |
| Model size | 0 (pure OpenCV) | ~8MB ONNX |
| GPU needed | No | Optional |
| Accuracy | Moderate (clothing color only) | High (texture + shape + color) |
| Robustness to pose | Good (histogram is pose-invariant) | Better (learned features) |
| Implementation effort | ~30 lines | ~80 lines + model download + ONNX runtime |
| Same-day validity | Excellent (clothing doesn't change) | Excellent |

For Phase 3 of a 1-month project, the histogram approach is safer. It can be implemented and tested in a single day. If it fails, the OSNet-AIN fallback is clearly defined and can be swapped in by changing the `extract_appearance()` function without touching any other code.

---

## Appendix B: Quick Reference — Implementation Sequence

```
Week 1 (now):
 ├── Day 1-2: daily_identity_store.py + test_daily_identity.py
 ├── Day 3:   Camera A integration (write profile on check-in)
 └── Day 4:   Camera B integration (read profiles, enhanced matching)

Week 2:
 ├── Day 1:   Integration testing with test video
 ├── Day 2:   Phase 2 — extended reassociation TTL + reduced candidate hits
 └── Day 3:   Phase 2 testing + threshold tuning

Week 3 (if Phase 1+2 stable):
 ├── Day 1-2: Phase 3 — HSV appearance extraction + daily store column
 ├── Day 3:   Phase 3 — fusion scoring in identity_matcher
 └── Day 4-5: Phase 3 testing + tuning

Week 4 (defense prep):
 ├── Demo script + video recording
 ├── Performance benchmarks (FPS with/without identity bridge)
 ├── Report figures showing identity continuity improvement
 └── Limitations section (explicitly documenting deferred items)
```
