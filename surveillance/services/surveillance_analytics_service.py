"""
Analytics service for Camera-B surveillance events.

Sits between ``SurveillanceRepository`` (persistence / raw rows) and any
future dashboard or API layer.  All aggregation and shaping logic lives here;
no persistence detail leaks upward.

Callers
-------
A future dashboard or REST endpoint imports ``SurveillanceAnalyticsService``
and calls its methods directly.  No raw event dicts or CSV paths are required.

This module is read-only: it never writes to storage.
"""

from __future__ import annotations

import logging
from datetime import datetime
from typing import Any, Dict, List, Optional, Sequence

from surveillance.repositories.surveillance_repository import (
    SurveillanceRepository,
    EVENT_TRIGGER_ACTIVITY_CHANGE,
    EVENT_TRIGGER_TRACK_LOST,
    EVENT_TRIGGER_SESSION_END,
)

_log = logging.getLogger("surveillance.analytics")

# Sentinel used when filtering out Unknown identities.
_UNKNOWN = "Unknown"


class SurveillanceAnalyticsService:
    """Read-side analytics over persisted surveillance activity events.

    All heavy lifting (CSV reading, schema parsing, trigger filtering) is
    delegated to the repository.  This service concerns itself only with
    shaping the results into business-ready summaries.

    Parameters
    ----------
    repository:
        An initialised ``SurveillanceRepository`` instance.
        Dependency-injected so the service is independently testable.
    """

    def __init__(self, repository: SurveillanceRepository) -> None:
        self._repo = repository

    # ------------------------------------------------------------------
    # Public analytics methods
    # ------------------------------------------------------------------

    def get_global_summary(
        self,
        start_time: datetime,
        end_time: datetime,
        include_triggers: Optional[Sequence[str]] = None,
    ) -> Dict[str, Any]:
        """Total seconds per activity across ALL identities in a time window.

        Parameters
        ----------
        start_time:       Inclusive lower bound on event ``timestamp_start``.
        end_time:         Inclusive upper bound on event ``timestamp_start``.
        include_triggers: Optional whitelist of ``event_trigger`` values.
                          ``None`` means include all triggers.

        Returns
        -------
        ::

            {
                "start_time":   datetime,          # query window start
                "end_time":     datetime,           # query window end
                "by_activity":  {                   # seconds per activity label
                    "Working":      3620.5,
                    "Inactive":      240.0,
                    ...
                },
                "total_sec":    float,              # sum of all activity durations
                "event_count":  int,                # number of rows contributing
            }
        """
        # by_activity already returns {label: total_sec} with trigger filtering
        by_activity = self._repo.get_activity_summary(
            start_time, end_time, include_triggers=include_triggers
        )
        total_sec = sum(by_activity.values())

        # Count the raw events that contributed (same filters applied)
        events = self._repo.get_events_by_date_range(start_time, end_time)
        if include_triggers is not None:
            trigger_set = set(include_triggers)
            events = [e for e in events if e["event_trigger"] in trigger_set]

        return {
            "start_time":  start_time,
            "end_time":    end_time,
            "by_activity": by_activity,
            "total_sec":   total_sec,
            "event_count": len(events),
        }

    def get_identity_activity_summary(
        self,
        start_time: datetime,
        end_time: datetime,
        include_unknown: bool = False,
        include_triggers: Optional[Sequence[str]] = None,
    ) -> List[Dict[str, Any]]:
        """Per-identity grouped activity totals within a time window.

        Parameters
        ----------
        start_time:       Inclusive lower bound on event ``timestamp_start``.
        end_time:         Inclusive upper bound on event ``timestamp_start``.
        include_unknown:  When ``False`` (default) rows where
                          ``identity_name == "Unknown"`` are excluded.
                          Pass ``True`` to include them as a distinct group.
        include_triggers: Optional whitelist of ``event_trigger`` values.

        Returns
        -------
        List of per-identity summary dicts, sorted by descending
        ``total_sec``::

            [
                {
                    "identity_name": "Amir",
                    "by_activity": {
                        "Working": 3600.0,
                        "Inactive":  120.0,
                    },
                    "total_sec":   3720.0,   # sum across all activities
                    "event_count": 12,
                },
                ...
            ]
        """
        events = self._repo.get_events_by_date_range(start_time, end_time)

        # Optional trigger filter
        if include_triggers is not None:
            trigger_set = set(include_triggers)
            events = [e for e in events if e["event_trigger"] in trigger_set]

        # Drop Unknown rows if caller does not want them
        if not include_unknown:
            events = [e for e in events if e["identity_name"] != _UNKNOWN]

        # Group by identity
        groups: Dict[str, Dict[str, Any]] = {}
        for ev in events:
            name = ev["identity_name"]
            if name not in groups:
                groups[name] = {"identity_name": name, "by_activity": {}, "total_sec": 0.0, "event_count": 0}
            label = ev["activity"]
            groups[name]["by_activity"][label] = (
                groups[name]["by_activity"].get(label, 0.0) + ev["duration_sec"]
            )
            groups[name]["total_sec"] += ev["duration_sec"]
            groups[name]["event_count"] += 1

        # Sort by total activity time descending (most active person first)
        return sorted(groups.values(), key=lambda g: g["total_sec"], reverse=True)

    def get_identity_timeline(
        self,
        identity_name: str,
        start_time: Optional[datetime] = None,
        end_time: Optional[datetime] = None,
        include_triggers: Optional[Sequence[str]] = None,
    ) -> Dict[str, Any]:
        """Ordered activity segments for one identity, ready for a timeline view.

        Parameters
        ----------
        identity_name:    Exact identity name (case-sensitive).
        start_time:       Optional inclusive lower bound on ``timestamp_start``.
        end_time:         Optional inclusive upper bound on ``timestamp_start``.
        include_triggers: Optional whitelist of ``event_trigger`` values.

        Returns
        -------
        ::

            {
                "identity_name": "Amir",
                "start_time":    datetime | None,   # query bound (None if not given)
                "end_time":      datetime | None,
                "segments": [
                    {
                        "timestamp_start":    datetime,
                        "timestamp_end":      datetime,
                        "duration_sec":       float,
                        "activity":           str,
                        "identity_confidence": float,
                        "identity_source":    str,
                        "event_trigger":      str,
                        "track_id":           int,
                    },
                    ...
                ],
                "total_sec":   float,   # sum of segment durations
                "event_count": int,
            }

        Segments are ordered by ``timestamp_start`` ascending.
        """
        events = self._repo.get_events_by_identity(
            identity_name, start_time=start_time, end_time=end_time
        )

        # Optional trigger filter applied after the repository query
        if include_triggers is not None:
            trigger_set = set(include_triggers)
            events = [e for e in events if e["event_trigger"] in trigger_set]

        # Sort chronologically — repository does not guarantee order
        events.sort(key=lambda e: e["timestamp_start"])

        # Shape each segment: keep fields relevant to a timeline widget
        segments = [
            {
                "timestamp_start":     e["timestamp_start"],
                "timestamp_end":       e["timestamp_end"],
                "duration_sec":        e["duration_sec"],
                "activity":            e["activity"],
                "identity_confidence": e["identity_confidence"],
                "identity_source":     e["identity_source"],
                "event_trigger":       e["event_trigger"],
                "track_id":            e["track_id"],
            }
            for e in events
        ]

        return {
            "identity_name": identity_name,
            "start_time":    start_time,
            "end_time":      end_time,
            "segments":      segments,
            "total_sec":     sum(s["duration_sec"] for s in segments),
            "event_count":   len(segments),
        }
