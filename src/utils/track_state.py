from __future__ import annotations

from dataclasses import dataclass, field
from collections import deque
from typing import Deque, Dict, Optional, Tuple
import time


@dataclass
class TrackState:
    """Runtime state for one tracked person (shared by check-in + surveillance)."""

    track_id: int

    # Identity
    identity: str = "Unknown"                 # final identity you display/log
    face_identity: Optional[str] = None        # identity from face recognition
    face_conf: float = 0.0                    # confidence (cos sim) of face_identity
    reid_identity: Optional[str] = None        # identity from re-id
    reid_conf: float = 0.0

    # Voting / smoothing
    identity_votes: Deque[str] = field(default_factory=lambda: deque(maxlen=20))
    last_identity_update_ts: float = field(default_factory=time.time)

    # Liveness
    liveness: Optional[str] = None             # "LIVE" / "FAKE" / None
    liveness_prob: float = 0.0
    liveness_votes: Deque[bool] = field(default_factory=lambda: deque(maxlen=20))

    # Tracking info
    last_bbox: Optional[Tuple[int, int, int, int]] = None  # xyxy
    last_seen_ts: float = field(default_factory=time.time)

    # Attendance
    checked_in: bool = False
    checkin_ts: Optional[float] = None

    # Activity monitoring
    current_activity: str = "Unknown"
    activity_start_ts: Optional[float] = None
    activity_votes: Deque[str] = field(default_factory=lambda: deque(maxlen=30))
    activity_durations_sec: Dict[str, float] = field(default_factory=dict)

    def mark_seen(self, bbox_xyxy: Tuple[int, int, int, int]) -> None:
        self.last_bbox = bbox_xyxy
        self.last_seen_ts = time.time()

    # ---------- Identity helpers ----------
    def push_identity_vote(self, label: str) -> None:
        self.identity_votes.append(label)
        self.last_identity_update_ts = time.time()

    def stable_identity(self, min_hits: int = 8) -> str:
        """Return stable identity if it wins enough votes, else 'Unknown'."""
        if not self.identity_votes:
            return "Unknown"
        counts: Dict[str, int] = {}
        for v in self.identity_votes:
            counts[v] = counts.get(v, 0) + 1
        best_label, best_count = max(counts.items(), key=lambda x: x[1])
        return best_label if best_count >= min_hits else "Unknown"

    # ---------- Liveness helpers ----------
    def push_liveness_vote(self, is_live: bool, prob: float) -> None:
        self.liveness_votes.append(is_live)
        self.liveness_prob = prob

    def stable_liveness(self, min_hits: int = 8) -> Optional[str]:
        """Return 'LIVE' or 'FAKE' if stable, else None."""
        if not self.liveness_votes:
            return None
        live_hits = sum(1 for v in self.liveness_votes if v)
        fake_hits = len(self.liveness_votes) - live_hits
        if live_hits >= min_hits:
            return "LIVE"
        if fake_hits >= min_hits:
            return "FAKE"
        return None

    # ---------- Activity helpers ----------
    def push_activity_vote(self, activity: str) -> None:
        self.activity_votes.append(activity)

    def stable_activity(self, min_hits: int = 10) -> str:
        if not self.activity_votes:
            return "Unknown"
        counts: Dict[str, int] = {}
        for v in self.activity_votes:
            counts[v] = counts.get(v, 0) + 1
        best_label, best_count = max(counts.items(), key=lambda x: x[1])
        return best_label if best_count >= min_hits else self.current_activity

    def update_activity(self, new_activity: str, now_ts: Optional[float] = None) -> None:
        """Update activity timeline + durations when stable activity changes."""
        now = now_ts if now_ts is not None else time.time()

        # initialize start time
        if self.activity_start_ts is None:
            self.current_activity = new_activity
            self.activity_start_ts = now
            return

        # if same activity, nothing to close
        if new_activity == self.current_activity:
            return

        # close previous activity segment
        duration = max(0.0, now - self.activity_start_ts)
        self.activity_durations_sec[self.current_activity] = (
            self.activity_durations_sec.get(self.current_activity, 0.0) + duration
        )

        # start new activity
        self.current_activity = new_activity
        self.activity_start_ts = now


class TrackStateStore:
    """Keeps TrackState objects indexed by track_id."""

    def __init__(self) -> None:
        self._tracks: Dict[int, TrackState] = {}

    def get(self, track_id: int) -> TrackState:
        if track_id not in self._tracks:
            self._tracks[track_id] = TrackState(track_id=track_id)
        return self._tracks[track_id]

    def remove_stale(self, max_age_sec: float = 5.0) -> None:
        now = time.time()
        to_del = [tid for tid, st in self._tracks.items() if (now - st.last_seen_ts) > max_age_sec]
        for tid in to_del:
            del self._tracks[tid]

    def all(self) -> Dict[int, TrackState]:
        return self._tracks
    