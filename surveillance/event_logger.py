"""
Activity event logger for Camera-B surveillance.

Writes closed activity events to a CSV file.  The class is intentionally
structured so that a SQLite backend can be added later by:
  1. Adding a ``_write_sqlite(row)`` method.
  2. Adding a ``use_sqlite: true`` flag to settings.yaml.
  3. Routing ``log_event`` to the new method.

The public API (``log_event`` / ``close``) will not change.
"""

from __future__ import annotations

import csv
import logging
from datetime import datetime
from pathlib import Path
from typing import Optional

log = logging.getLogger("surveillance.event_logger")

# ---------------------------------------------------------------------------
# Event trigger constants
# Always use one of these three values for the ``event_trigger`` field so
# analytics queries can distinguish row origin without inspecting duration.
# ---------------------------------------------------------------------------
EVENT_TRIGGER_ACTIVITY_CHANGE = "activity_change"
# ^ Activity label transitioned mid-session (smoothing threshold met).
EVENT_TRIGGER_TRACK_LOST      = "track_lost"
# ^ DeepSORT evicted the track (person left frame or occlusion timeout).
EVENT_TRIGGER_SESSION_END     = "session_end"
# ^ Process shutdown – final flush of any still-open activity segment.

# ---------------------------------------------------------------------------
# Canonical schema contract
# ---------------------------------------------------------------------------
# Every row in the persistence store represents ONE closed activity segment:
# a contiguous interval during which a single tracked person performed a
# single activity, as observed by the surveillance pipeline.
#
# Field semantics (stable – preserve column order for CSV compatibility):
#
#   timestamp_start     Wall-clock start of the segment, local time,
#                       format "YYYY-MM-DD HH:MM:SS".
#
#   timestamp_end       Wall-clock end of the segment, same format.
#
#   duration_sec        Elapsed seconds (end − start), formatted to 2 d.p.
#                       Always ≥ 0.  Sub-second events (e.g. flush at switch)
#                       may appear as 0.00.
#
#   track_id            Integer DeepSORT track ID.  Unique within one process
#                       run; not stable across restarts.
#
#   identity_name       Resolved employee name, or "Unknown" when no
#                       confirmed identity exists for the track.
#
#   identity_confidence ArcFace cosine-similarity score in [0, 1] at the
#                       time of the last accepted identity assignment.
#                       Written as 0.0000 when identity_name is "Unknown".
#
#   identity_source     How identity_name was determined:
#                         "face"                   – direct ArcFace match
#                         "reassoc_face_confirmed" – confirmed-vault
#                                                    reassociation inheritance
#                         "reassoc_short_term"     – short-term position-based
#                                                    reassociation inheritance
#                         "manual"                 – operator override
#                         "none"                   – identity never resolved
#
#   activity            Activity label at segment close:
#                         "Working" | "Meeting" | "Inactive" |
#                         "Using_Phone" | "Unknown"
#
#   event_trigger       Why this row was written; use EVENT_TRIGGER_* constants:
#                         "activity_change" – activity transitioned mid-session
#                         "track_lost"      – DeepSORT evicted the track
#                         "session_end"     – process shutdown, final flush
# ---------------------------------------------------------------------------

# CSV column order.  Append-only; do not reorder existing columns.
CSV_COLUMNS = [
    "timestamp_start",
    "timestamp_end",
    "duration_sec",
    "track_id",
    "identity_name",
    "identity_confidence",
    "identity_source",
    "activity",
    "event_trigger",   # why this row was written; see EVENT_TRIGGER_* above
]


class EventLogger:
    """Append-only CSV logger for activity state-change events.

    Parameters
    ----------
    csv_path:
        Destination CSV file.  Parent directories are created automatically.
        If the file already exists the header is *not* written again, so
        logs accumulate across restarts.
    """

    def __init__(self, csv_path: Path) -> None:
        self._path = Path(csv_path)
        self._path.parent.mkdir(parents=True, exist_ok=True)

        # Only write the header on a brand-new file
        write_header = not self._path.exists()

        self._fh = open(self._path, "a", newline="", encoding="utf-8")
        self._writer = csv.DictWriter(self._fh, fieldnames=CSV_COLUMNS)

        if write_header:
            self._writer.writeheader()
            self._fh.flush()

        log.info(f"EventLogger → {self._path}")

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def log_event(
        self,
        *,
        track_id: int,
        identity_name: str,
        activity: str,
        start_time: float,
        end_time: float,
        identity_confidence: float = 0.0,
        identity_source: str = "none",
        event_trigger: str = EVENT_TRIGGER_ACTIVITY_CHANGE,
        flush: bool = True,
    ) -> None:
        """Write one closed activity event row to the CSV.

        Parameters
        ----------
        track_id:             DeepSORT track ID.
        identity_name:        "Unknown" or resolved employee name.
        activity:             Activity label (e.g. "Working").
        start_time:           Epoch timestamp when the activity started.
        end_time:             Epoch timestamp when the activity ended.
        identity_confidence:  Cosine-sim score that produced the identity [0,1].
        identity_source:      "face" | "manual" | "reassoc_face_confirmed" |
                              "reassoc_short_term" | "none".
        event_trigger:        Why this row is being written; use one of the
                              EVENT_TRIGGER_* module constants.
        flush:                Whether to flush after writing (default True).
        """
        duration = max(0.0, end_time - start_time)
        row = {
            "timestamp_start":    _fmt_ts(start_time),
            "timestamp_end":      _fmt_ts(end_time),
            "duration_sec":       f"{duration:.2f}",
            "track_id":           track_id,
            "identity_name":      identity_name,
            "identity_confidence": f"{identity_confidence:.4f}",
            "identity_source":    identity_source,
            "activity":           activity,
            "event_trigger":      event_trigger,
        }
        self._writer.writerow(row)
        if flush:
            self._fh.flush()

        log.debug(
            f"[EVT] Track {track_id} | {identity_name} | {activity} "
            f"| {duration:.1f}s | trigger={event_trigger}"
        )

    def close(self) -> None:
        """Flush and close the underlying file handle."""
        try:
            self._fh.flush()
            self._fh.close()
        except Exception:
            pass


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------

def _fmt_ts(ts: float) -> str:
    """Format a Unix timestamp as a human-readable string for the CSV."""
    return datetime.fromtimestamp(ts).strftime("%Y-%m-%d %H:%M:%S")
