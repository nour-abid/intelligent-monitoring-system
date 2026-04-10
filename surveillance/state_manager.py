"""
State manager for Camera-B surveillance tracks.

Each tracked person is represented by a ``SurveillanceTrackState``.
``StateManager`` keeps a dict keyed by ``track_id`` and handles orderly
removal of tracks that have been missing for too many frames – returning
the stale states so the caller can log their final activity events.

Identity defaults to ``"Unknown"``.  The ``set_identity`` method is the
clean extension point for later face-based identity matching.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple

_log = logging.getLogger("surveillance.reassoc")

# Thresholds for conservative face-based identity promotion in set_identity().
_CONFIDENCE_WEAK: float = 0.40       # below this, face evidence is discarded as noise
_CONFIDENCE_STRONG: float = 0.50     # immediate single-frame promotion (no contradiction)
_CANDIDATE_PROMOTE_HITS: int = 1     # same-name hits required before candidate is promoted
_CONTRADICTION_EXTRA_HITS: int = 3   # extra hits on top of promote_hits when overwriting confirmed

# Conservative time window for confirmed-vault reassociation.
# Kept short so that spatial plausibility (center-distance gate) stays meaningful.
# A value of 8 s covers brief occlusion / detector dropout without reaching into
# ambiguous multi-person scene changes.  Easy to tune here or via the
# ReassociationCache constructor argument.
_REASSOC_CONFIRMED_MAX_GAP_SEC: float = 8.0

# Checked-in-aware persistence: extra contradiction-hit resistance so that an
# identity already validated by Camera A is harder to displace by a rogue crop.
_CHECKEDIN_EXTRA_CONTRADICTION_HITS: int = 2
# Extended reassociation TTL for identities belonging to today's check-in cohort.
_REASSOC_CHECKEDIN_MAX_GAP_SEC: float = 14.0


# ---------------------------------------------------------------------------
# Data structures
# ---------------------------------------------------------------------------

@dataclass
class ActivityEvent:
    """A closed (finished) activity segment for a single track."""

    activity: str
    start_time: float
    end_time: float

    @property
    def duration_sec(self) -> float:
        return max(0.0, self.end_time - self.start_time)


@dataclass
class SurveillanceTrackState:
    """All runtime state for one tracked person on Camera B.

    Fields
    ------
    track_id         : Stable ID from DeepSORT.
    identity_name    : "Unknown" until identity_matcher provides a name.
    current_activity : Last confirmed (smoothed) activity label.
    pending_activity : Candidate activity accumulating frames (smoothing).
    pending_count    : Consecutive frames the pending candidate has been seen.
    activity_start_time : When the current activity started (epoch seconds).
    last_seen_time   : Last timestamp this track was matched to a detection.
    bbox             : Current bounding box in (x1, y1, x2, y2) xyxy format.
    history          : Ordered list of closed ActivityEvent objects.
    """

    track_id: int

    # ------------------------------------------------------------------
    # Identity fields
    # ------------------------------------------------------------------
    # Updated by identity_matcher (Camera-B face match) or manual assignment.
    # identity_source values: "none" | "face" | "manual"
    identity_name: str = "Unknown"
    identity_confidence: float = 0.0
    identity_source: str = "none"
    identity_last_updated: Optional[float] = None

    # Confirmed (smoothed) activity
    current_activity: str = "Unknown"

    # Smoothing buffer (managed by activity_logic.py)
    pending_activity: str = "Unknown"
    pending_count: int = 0

    # Timing
    activity_start_time: float = field(default_factory=time.time)
    last_seen_time: float = field(default_factory=time.time)

    # Spatial
    bbox: Optional[Tuple[float, float, float, float]] = None

    # History of fully closed activity events
    history: List[ActivityEvent] = field(default_factory=list)

    # Number of successful direct face-match events on this track.
    # Used by ReassociationCache to mark lost entries as high-trust.
    face_match_count: int = 0

    # Sticky vault: the best direct face-match identity seen on this track.
    # Updated by set_identity(source="face") only when confidence >= current vault.
    # Never cleared by failed face checks; only clear_identity() can reset it.
    # ReassociationCache.store() uses this field — not identity_name — for caching.
    confirmed_identity_name: str = ""
    confirmed_identity_confidence: float = 0.0

    # Candidate accumulator: accepted face evidence waits here until it accumulates
    # _CANDIDATE_PROMOTE_HITS (or more when contradicting the confirmed vault).
    # Managed exclusively by StateManager.set_identity().
    candidate_identity_name: str = ""
    candidate_identity_confidence: float = 0.0
    candidate_hits: int = 0

    # Timestamp of the last frame in which a face match call reached this track
    # (regardless of whether it promoted or stayed as a candidate).
    last_good_face_match_time: Optional[float] = None

    # True when the last matcher pass found a usable face crop for this track.
    # Updated via StateManager.note_face_result(); set True inside set_identity().
    face_usable_last_frame: bool = False

    # True when this track's confirmed identity belongs to today's checked-in cohort.
    # Set by StateManager._promote_to_confirmed() via _is_checkedin().
    # Controls stronger contradiction resistance and longer reassociation TTL.
    is_checkedin_confirmed: bool = False

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def close_current_activity(
        self, end_time: Optional[float] = None
    ) -> Optional[ActivityEvent]:
        """Close the current activity segment, store it in history, and return it.

        Returns ``None`` if the current activity is ``"Unknown"`` (nothing
        meaningful to log).
        """
        if self.current_activity == "Unknown":
            return None
        t_end = end_time if end_time is not None else time.time()
        event = ActivityEvent(
            activity=self.current_activity,
            start_time=self.activity_start_time,
            end_time=t_end,
        )
        self.history.append(event)
        return event

    def start_activity(
        self, activity: str, start_time: Optional[float] = None
    ) -> None:
        """Begin a new activity segment, resetting smoothing state."""
        self.current_activity = activity
        self.activity_start_time = start_time if start_time is not None else time.time()
        self.pending_activity = activity
        self.pending_count = 0


# ---------------------------------------------------------------------------
# Short-term identity reassociation cache
# ---------------------------------------------------------------------------

@dataclass
class LostIdentityEntry:
    """Snapshot of a recently-lost known track for reassociation."""
    identity_name: str
    identity_confidence: float
    identity_source: str
    last_track_id: int
    last_seen_time: float
    last_bbox: Tuple[float, float, float, float]
    last_activity: str
    expires_at: float
    # True only when the track's identity was directly face-matched (not inherited
    # from a prior reassociation). Enables stronger persistence for verified persons.
    was_face_confirmed: bool = False
    # Mirrors SurveillanceTrackState.is_checkedin_confirmed at the moment of storage.
    is_checkedin_confirmed: bool = False


def _bbox_center(b: Tuple[float, float, float, float]) -> Tuple[float, float]:
    return ((b[0] + b[2]) * 0.5, (b[1] + b[3]) * 0.5)


def _bbox_area(b: Tuple[float, float, float, float]) -> float:
    return max(1.0, (b[2] - b[0]) * (b[3] - b[1]))


def _bbox_iou(
    a: Tuple[float, float, float, float],
    b: Tuple[float, float, float, float],
) -> float:
    """Compute IoU between two (x1,y1,x2,y2) boxes."""
    ix1 = max(a[0], b[0])
    iy1 = max(a[1], b[1])
    ix2 = min(a[2], b[2])
    iy2 = min(a[3], b[3])
    if ix2 <= ix1 or iy2 <= iy1:
        return 0.0
    inter = (ix2 - ix1) * (iy2 - iy1)
    return inter / (_bbox_area(a) + _bbox_area(b) - inter)


class ReassociationCache:
    """Lightweight cache of recently-lost known identities.

    Scoring uses center-distance as the primary cue.  IoU is an optional
    bonus.  bbox size similarity and identity confidence also contribute.
    Face-match (source="face") always takes priority and will overwrite.
    """

    def __init__(
        self,
        max_gap_sec: float = 5.0,
        max_center_dist_px: float = 200.0,
        size_ratio_range: Tuple[float, float] = (0.3, 3.0),
        min_score: float = 0.25,
        face_confirmed_max_gap_sec: float = _REASSOC_CONFIRMED_MAX_GAP_SEC,
        face_confirmed_min_score: float = 0.15,
        checkedin_max_gap_sec: float = _REASSOC_CHECKEDIN_MAX_GAP_SEC,
    ) -> None:
        self._max_gap_sec = max_gap_sec
        self._max_center_dist_px = max_center_dist_px
        self._size_ratio_range = size_ratio_range
        self._min_score = min_score
        self._face_confirmed_max_gap_sec = face_confirmed_max_gap_sec
        self._face_confirmed_min_score   = face_confirmed_min_score
        self._checkedin_max_gap_sec      = checkedin_max_gap_sec
        self._entries: List[LostIdentityEntry] = []

    def store(self, state: "SurveillanceTrackState") -> None:
        """Save a lost known identity into the cache."""
        # Always prefer the confirmed vault over the current live identity.
        # This ensures that a person who turned away (causing failed face checks)
        # still has their confirmed identity cached for reassociation.
        cache_name = state.confirmed_identity_name or state.identity_name
        cache_conf = (
            state.confirmed_identity_confidence
            if state.confirmed_identity_name
            else state.identity_confidence
        )
        was_face_confirmed = bool(state.confirmed_identity_name)

        if not cache_name or cache_name == "Unknown" or state.bbox is None:
            return

        # Only tracks with a direct face-confirmed vault identity are eligible to
        # donate identity on reassociation.  Unknown-only, candidate-only, and
        # reassociation-inherited tracks must not propagate — weak or unverified
        # identity memory must not chain forward into a new track.
        if not was_face_confirmed:
            _log.debug(
                "[REASSOC] store skipped track=%s identity=%s: "
                "no confirmed face vault (source=%s) — ineligible to donate",
                state.track_id, cache_name, state.identity_source,
            )
            return

        is_checkedin = state.is_checkedin_confirmed
        if was_face_confirmed and is_checkedin:
            ttl = self._checkedin_max_gap_sec
        elif was_face_confirmed:
            ttl = self._face_confirmed_max_gap_sec
        else:
            ttl = self._max_gap_sec
        self._entries.append(LostIdentityEntry(
            identity_name=cache_name,
            identity_confidence=cache_conf,
            identity_source="face" if was_face_confirmed else state.identity_source,
            last_track_id=state.track_id,
            last_seen_time=state.last_seen_time,
            last_bbox=state.bbox,
            last_activity=state.current_activity,
            expires_at=time.time() + ttl,
            was_face_confirmed=was_face_confirmed,
            is_checkedin_confirmed=is_checkedin,
        ))
        _log.debug(
            "[REASSOC] stored track=%s identity=%s face_confirmed=%s checkedin=%s ttl=%.0fs",
            state.track_id, cache_name, was_face_confirmed, is_checkedin, ttl,
        )

    def try_reassociate(
        self,
        new_state: "SurveillanceTrackState",
    ) -> bool:
        """Try to match new_state to a cached lost identity.

        Primary cue: center-to-center pixel distance.
        IoU, size similarity, and identity confidence are bonus/gate cues.
        Returns True and mutates new_state if a match is found.
        """
        if new_state.bbox is None:
            return False

        now = time.time()
        self._entries = [e for e in self._entries if e.expires_at > now]

        new_cx, new_cy = _bbox_center(new_state.bbox)
        area_new = _bbox_area(new_state.bbox)

        best: Optional[LostIdentityEntry] = None
        best_score = -1.0
        best_meta: dict = {}

        for entry in self._entries:
            tid_new = new_state.track_id
            tid_old = entry.last_track_id

            # Per-entry thresholds depend on whether identity was face-confirmed
            # and whether the track belongs to today's checked-in cohort.
            if entry.was_face_confirmed and entry.is_checkedin_confirmed:
                eff_max_gap = self._checkedin_max_gap_sec
                trust_label = "checkedin_confirmed"
            elif entry.was_face_confirmed:
                eff_max_gap = self._face_confirmed_max_gap_sec
                trust_label = "face_confirmed"
            else:
                eff_max_gap = self._max_gap_sec
                trust_label = "regular"
            eff_min_score = self._face_confirmed_min_score if entry.was_face_confirmed else self._min_score

            # ── Gate 1: time ───────────────────────────────────────────
            time_gap = now - entry.last_seen_time
            if time_gap > eff_max_gap:
                _log.debug(
                    "[REASSOC] new=%s vs old=%s [%s] | SKIP | reason=time_expired "
                    "| gap=%.1fs > max=%.1fs",
                    tid_new, tid_old, trust_label, time_gap, eff_max_gap,
                )
                continue

            # ── Gate 2: center distance ────────────────────────────────
            old_cx, old_cy = _bbox_center(entry.last_bbox)
            dist = ((new_cx - old_cx) ** 2 + (new_cy - old_cy) ** 2) ** 0.5
            if dist > self._max_center_dist_px:
                _log.debug(
                    "[REASSOC] new=%s vs old=%s [%s] | SKIP | reason=too_far "
                    "| dist=%.1fpx > max=%.1fpx",
                    tid_new, tid_old, trust_label, dist, self._max_center_dist_px,
                )
                continue

            # ── Gate 3: size ratio ─────────────────────────────────────
            area_old = _bbox_area(entry.last_bbox)
            size_ratio = area_new / area_old
            lo, hi = self._size_ratio_range
            if not (lo <= size_ratio <= hi):
                _log.debug(
                    "[REASSOC] new=%s vs old=%s [%s] | SKIP | reason=size_mismatch "
                    "| ratio=%.2f not in [%.1f, %.1f]",
                    tid_new, tid_old, trust_label, size_ratio, lo, hi,
                )
                continue

            # ── Scoring ────────────────────────────────────────────────
            dist_score  = 1.0 - (dist / self._max_center_dist_px)
            iou         = _bbox_iou(new_state.bbox, entry.last_bbox)
            conf_score  = min(1.0, entry.identity_confidence)

            score = 0.55 * dist_score + 0.25 * conf_score + 0.20 * iou

            _log.debug(
                "[REASSOC] new=%s vs old=%s [%s] | identity=%s | "
                "dist=%.1fpx dist_score=%.3f iou=%.3f conf=%.3f score=%.3f min=%.3f",
                tid_new, tid_old, trust_label, entry.identity_name,
                dist, dist_score, iou, conf_score, score, eff_min_score,
            )

            if score > best_score:
                best_score = score
                best = entry
                best_meta = {"dist": dist, "iou": iou, "gap": time_gap, "trust": trust_label, "min_score": eff_min_score}

        if best is None or best_score < best_meta.get("min_score", self._min_score):
            if best is not None:
                _log.debug(
                    "[REASSOC] new=%s | REJECTED | reason=score_too_low [%s] "
                    "| score=%.3f < min=%.3f",
                    new_state.track_id, best_meta.get("trust", "?"),
                    best_score, best_meta.get("min_score", self._min_score),
                )
            return False

        # ── Contradiction gate ─────────────────────────────────────────────────
        # If the new track already carries a confirmed vault identity that differs
        # from the reassociation candidate, direct face confirmation wins and we
        # must not overwrite it with inherited memory.
        if (new_state.confirmed_identity_name
                and new_state.confirmed_identity_name != best.identity_name):
            _log.debug(
                "[REASSOC] new=%s | REJECTED | reason=contradicts_confirmed_vault "
                "| new_confirmed=%s vs reassoc=%s",
                new_state.track_id, new_state.confirmed_identity_name, best.identity_name,
            )
            return False

        # If the new track has a direct face-sourced live identity that differs,
        # that face evidence is more trustworthy than position-based inheritance.
        if (new_state.identity_source == "face"
                and new_state.identity_name not in ("", "Unknown")
                and new_state.identity_name != best.identity_name):
            _log.debug(
                "[REASSOC] new=%s | REJECTED | reason=contradicts_face_identity "
                "| new_face=%s vs reassoc=%s",
                new_state.track_id, new_state.identity_name, best.identity_name,
            )
            return False
        # ──────────────────────────────────────────────────────────────────────

        new_source = (
            "reassoc_face_confirmed"
            if best.was_face_confirmed
            else "reassoc_short_term"
        )
        self._entries.remove(best)
        new_state.identity_name = best.identity_name
        new_state.identity_confidence = best.identity_confidence
        new_state.identity_source = new_source
        new_state.identity_last_updated = now
        _log.info(
            "[REASSOC] ACCEPTED new=%s <-- old=%s [%s] | identity=%s | "
            "score=%.3f dist=%.1fpx iou=%.3f gap=%.1fs conf=%.3f",
            new_state.track_id, best.last_track_id, best_meta["trust"], best.identity_name,
            best_score, best_meta["dist"], best_meta["iou"],
            best_meta["gap"], best.identity_confidence,
        )
        return True


# ---------------------------------------------------------------------------
# State manager
# ---------------------------------------------------------------------------

class StateManager:
    """Maintains ``SurveillanceTrackState`` objects keyed by ``track_id``.

    Usage per frame
    ---------------
    1. For every confirmed track from DeepSORT, call ``get_or_create(tid)``
       to retrieve (or lazily create) the state object and update it.
    2. After processing all confirmed tracks, call ``remove_stale(active_ids)``
       where ``active_ids`` is the set of track IDs that were confirmed this
       frame.  The method returns a list of states that were removed so the
       caller can log their final events.
    """

    def __init__(
        self,
        max_missing_frames: int = 30,
        reassoc_cache: Optional["ReassociationCache"] = None,
    ) -> None:
        self._states: Dict[int, SurveillanceTrackState] = {}
        # Per-track count of consecutive frames NOT seen
        self._missing: Dict[int, int] = {}
        self.max_missing_frames = max_missing_frames
        self._reassoc = reassoc_cache
        # Set of today's checked-in employee names; updated externally via
        # update_checkedin_names().  Empty set → no checked-in boost (safe default).
        self._checkedin_names: frozenset = frozenset()

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def get_or_create(self, track_id: int) -> SurveillanceTrackState:
        """Return existing state or create a fresh one; resets missing counter."""
        if track_id not in self._states:
            new_state = SurveillanceTrackState(track_id=track_id)
            self._states[track_id] = new_state
        self._missing[track_id] = 0
        return self._states[track_id]

    def update_checkedin_names(self, names) -> None:
        """Refresh the set of today's checked-in employee names.

        Call periodically from the main loop (e.g. once per minute) after
        reading ``DailyIdentityStore.get_identities_for_today()``.  Passing an
        empty collection disables all checked-in persistence bonuses.
        """
        self._checkedin_names = frozenset(names)
        _log.debug("[CHECKEDIN] updated checked-in set: %s", self._checkedin_names)

    def _is_checkedin(self, name: str) -> bool:
        """Return True when *name* is in today's checked-in cohort."""
        return bool(name) and name != "Unknown" and name in self._checkedin_names

    # ------------------------------------------------------------------
    # Private helpers
    # ------------------------------------------------------------------

    def _promote_to_confirmed(
        self,
        state: "SurveillanceTrackState",
        identity_name: str,
        confidence: float,
        source: str,
        now: float,
    ) -> None:
        """Commit identity to both live fields and confirmed vault.

        Called only after sufficient evidence (strong single frame or N
        accumulated candidate hits).  Always overwrites the confirmed vault
        since the caller has already validated the evidence threshold.
        Clears the candidate buffer.
        """
        state.identity_name = identity_name
        state.identity_confidence = confidence
        state.identity_source = source
        state.identity_last_updated = now
        state.face_match_count += 1
        state.confirmed_identity_name = identity_name
        state.confirmed_identity_confidence = confidence
        state.candidate_identity_name = ""
        state.candidate_identity_confidence = 0.0
        state.candidate_hits = 0
        state.is_checkedin_confirmed = self._is_checkedin(identity_name)
        if state.is_checkedin_confirmed:
            _log.debug(
                "[IDENTITY] checkedin-flag set track=%s identity=%s "
                "(extended persistence rules active)",
                state.track_id, identity_name,
            )

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def set_identity(
        self,
        track_id: int,
        identity_name: str,
        confidence: float = 0.0,
        source: str = "face",
    ) -> None:
        """Attach a known identity using a conservative promotion flow.

        Non-face sources (``"manual"``, ``"reassoc_*"``) bypass candidate
        gating and update live fields directly.

        For ``source="face"`` the promotion path is:

        1. ``confidence >= _CONFIDENCE_STRONG`` with no active contradiction
           → immediate single-frame promotion to confirmed vault.
        2. Otherwise: accumulate in the candidate buffer and promote after
           ``_CANDIDATE_PROMOTE_HITS`` (plus ``_CONTRADICTION_EXTRA_HITS``
           when the candidate name conflicts with the confirmed vault).

        ``"Unknown"`` never overwrites a confirmed identity from any source.
        Use ``clear_identity()`` for an explicit full reset.
        """
        if track_id not in self._states:
            return
        state = self._states[track_id]

        # Guard: Unknown must never erase a confirmed identity (all sources).
        if identity_name == "Unknown" and state.confirmed_identity_name:
            _log.debug(
                "[IDENTITY] blocked Unknown downgrade for track=%s "
                "(confirmed=%s source=%s)",
                track_id, state.confirmed_identity_name, source,
            )
            return

        if source != "face":
            # Manual / reassoc sources bypass candidate gating.
            state.identity_name = identity_name
            state.identity_confidence = confidence
            state.identity_source = source
            state.identity_last_updated = time.time()
            return

        # ── Face evidence path ────────────────────────────────────────────────
        now = time.time()
        state.face_usable_last_frame = True
        state.last_good_face_match_time = now

        # Ignore weak / noisy evidence: below this floor the face crop is too
        # uncertain to store even as a candidate.
        if confidence < _CONFIDENCE_WEAK:
            _log.debug(
                "[IDENTITY] ignored weak evidence track=%s identity=%s conf=%.3f < floor=%.2f",
                track_id, identity_name, confidence, _CONFIDENCE_WEAK,
            )
            return

        has_confirmed = bool(state.confirmed_identity_name)
        same_as_confirmed = (identity_name == state.confirmed_identity_name)
        contradicts_confirmed = has_confirmed and not same_as_confirmed

        # Strong single-frame promotion (only when no active contradiction).
        if confidence >= _CONFIDENCE_STRONG and not contradicts_confirmed:
            _log.debug(
                "[IDENTITY] strong-promotion track=%s identity=%s conf=%.3f",
                track_id, identity_name, confidence,
            )
            self._promote_to_confirmed(state, identity_name, confidence, source, now)
            return

        # ── Candidate buffer ───────────────────────────────────────────────────
        # Medium-confidence evidence (or strong evidence contradicting the vault)
        # accumulates here until the required hit count is reached.
        # Checked-in identities require extra hits to be displaced.
        checkedin_extra = (
            _CHECKEDIN_EXTRA_CONTRADICTION_HITS
            if (contradicts_confirmed and state.is_checkedin_confirmed)
            else 0
        )
        required_hits = _CANDIDATE_PROMOTE_HITS + (
            _CONTRADICTION_EXTRA_HITS if contradicts_confirmed else 0
        ) + checkedin_extra
        if checkedin_extra:
            _log.debug(
                "[IDENTITY] checkedin-boost track=%s confirmed=%s challenger=%s "
                "required_hits=%d (extra=%d for checked-in persistence)",
                track_id, state.confirmed_identity_name, identity_name,
                required_hits, checkedin_extra,
            )

        if identity_name == state.candidate_identity_name:
            # Same candidate: accumulate hit, keep best confidence seen so far.
            state.candidate_hits += 1
            if confidence > state.candidate_identity_confidence:
                state.candidate_identity_confidence = confidence
        else:
            # Different candidate name: reset the candidate buffer (candidate set/reset).
            _log.debug(
                "[IDENTITY] candidate-reset track=%s new=%s conf=%.3f "
                "(replaced: %s / %d hits)",
                track_id, identity_name, confidence,
                state.candidate_identity_name or "none", state.candidate_hits,
            )
            state.candidate_identity_name = identity_name
            state.candidate_identity_confidence = confidence
            state.candidate_hits = 1

        _log.debug(
            "[IDENTITY] candidate track=%s identity=%s hits=%d/%d conf=%.3f",
            track_id, identity_name, state.candidate_hits, required_hits, confidence,
        )

        if state.candidate_hits >= required_hits:
            if contradicts_confirmed:
                # Contradiction replacement: enough contradicting hits have
                # accumulated to override the previously confirmed identity.
                _log.info(
                    "[IDENTITY] contradiction-replacement track=%s old=%s new=%s after %d hits",
                    track_id, state.confirmed_identity_name, identity_name, state.candidate_hits,
                )
            else:
                # Candidate promotion: repeated same-name evidence confirms identity.
                _log.debug(
                    "[IDENTITY] candidate-promoted track=%s identity=%s after %d hits",
                    track_id, identity_name, state.candidate_hits,
                )
            self._promote_to_confirmed(
                state, state.candidate_identity_name,
                state.candidate_identity_confidence, source, now,
            )
        elif contradicts_confirmed:
            # Contradiction refusal: challenger has not yet met the elevated
            # required_hits bar; confirmed identity is preserved unchanged.
            _log.debug(
                "[IDENTITY] contradiction-refused track=%s confirmed=%s challenger=%s hits=%d/%d",
                track_id, state.confirmed_identity_name, identity_name,
                state.candidate_hits, required_hits,
            )

    def clear_identity(self, track_id: int) -> None:
        """Fully reset all identity state for a track.

        Clears live fields, confirmed vault, candidate buffer, face-match
        counters, and auxiliary flags.  Use for explicit track resets
        (e.g. track-ID recycling or deliberate identity removal).
        """
        if track_id not in self._states:
            return
        state = self._states[track_id]
        state.identity_name = "Unknown"
        state.identity_confidence = 0.0
        state.identity_source = "none"
        state.identity_last_updated = None
        state.confirmed_identity_name = ""
        state.confirmed_identity_confidence = 0.0
        state.face_match_count = 0
        state.candidate_identity_name = ""
        state.candidate_identity_confidence = 0.0
        state.candidate_hits = 0
        state.last_good_face_match_time = None
        state.face_usable_last_frame = False
        state.is_checkedin_confirmed = False

    def note_face_result(self, track_id: int, usable: bool) -> None:
        """Record whether the face crop was usable in the last matcher pass.

        Called optionally by ``identity_matcher`` to keep
        ``face_usable_last_frame`` accurate even when no match was accepted
        (e.g. no face detected in crop).  No-op if track is unknown.
        """
        state = self._states.get(track_id)
        if state is not None:
            state.face_usable_last_frame = usable

    def remove_stale(self, active_ids: set) -> List[SurveillanceTrackState]:
        """Increment missing counters for absent tracks; remove expired ones.

        Parameters
        ----------
        active_ids:
            Set of ``track_id`` values that appeared in the current frame.

        Returns
        -------
        List of ``SurveillanceTrackState`` objects that were removed.
        Caller should close their activity events and log them.
        """
        removed: List[SurveillanceTrackState] = []

        for tid in list(self._states.keys()):
            if tid not in active_ids:
                self._missing[tid] = self._missing.get(tid, 0) + 1
            # Tracks in active_ids had their counter reset by get_or_create.

            if self._missing.get(tid, 0) > self.max_missing_frames:
                state = self._states.pop(tid)
                self._missing.pop(tid, None)
                removed.append(state)
                # Store known identities in reassoc cache for short-term recovery
                if self._reassoc is not None:
                    self._reassoc.store(state)

        return removed

    def get_all(self) -> Dict[int, SurveillanceTrackState]:
        """Return a snapshot of all currently tracked states."""
        return self._states
