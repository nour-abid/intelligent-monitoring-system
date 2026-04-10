"""
Persistence repository for Camera-B surveillance activity events.

This is the single entry-point for all surveillance write operations.
Runtime code (``main_surveillance.py``) must import and call this class only;
it must not import ``EventLogger`` or any storage backend directly.

Current backend: dual-write â€” CSV (backward safety) + PostgreSQL (primary analytics).

â”€â”€ Persisted event contract â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Each call to ``log_event`` writes ONE closed activity segment row.
See ``event_logger.CSV_COLUMNS`` for the authoritative field list and
``event_logger`` module-level comments for full field semantics.

Summary of fields written per row:
  timestamp_start     â€“ wall-clock start of segment  (TIMESTAMPTZ)
  timestamp_end       â€“ wall-clock end   of segment  (TIMESTAMPTZ)
  duration_sec        â€“ elapsed seconds, always >= 0
  track_id            â€“ DeepSORT track ID (session-scoped integer)
  identity_name       â€“ confirmed employee name, or "Unknown"
  identity_confidence â€“ ArcFace cosine-sim score [0,1], or 0.0000
  identity_source     â€“ "face" | "reassoc_face_confirmed" |
                        "reassoc_short_term" | "manual" | "none"
  activity            â€“ "Working" | "Meeting" | "Inactive" |
                        "Using_Phone" | "Unknown"
  event_trigger       â€“ "activity_change" | "track_lost" | "session_end"
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

PostgreSQL backend:
  Connection credentials are read from DB_HOST / DB_PORT / DB_DATABASE /
  DB_USERNAME / DB_PASSWORD environment variables (same names as Laravel).
  The ``surveillance.surveillance_events`` table is created automatically on
  first run inside the ``surveillance`` schema.
  Reads use PostgreSQL exclusively; old CSV-only rows are not visible to reads.
  The public API (``log_event`` / ``close``) is unchanged.
"""

from __future__ import annotations

import logging
import os
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

import psycopg
from psycopg.rows import dict_row

from surveillance.event_logger import (
    EventLogger,
    EVENT_TRIGGER_ACTIVITY_CHANGE,
    EVENT_TRIGGER_TRACK_LOST,
    EVENT_TRIGGER_SESSION_END,
)

_log = logging.getLogger("surveillance.repository")

# Re-export trigger constants so callers only need to import this module.
__all__ = [
    "SurveillanceRepository",
    "EVENT_TRIGGER_ACTIVITY_CHANGE",
    "EVENT_TRIGGER_TRACK_LOST",
    "EVENT_TRIGGER_SESSION_END",
]

_TS_FMT = "%Y-%m-%d %H:%M:%S"


# ---------------------------------------------------------------------------
# Connection helpers
# ---------------------------------------------------------------------------

def _build_conninfo() -> str:
    """Build a psycopg conninfo string from environment variables."""
    host   = os.environ.get("DB_HOST",     "127.0.0.1")
    port   = os.environ.get("DB_PORT",     "5432")
    dbname = os.environ.get("DB_DATABASE", "mq_monitoring")
    user   = os.environ.get("DB_USERNAME", "mq_user")
    pw     = os.environ.get("DB_PASSWORD", "")
    return f"host={host} port={port} dbname={dbname} user={user} password={pw}"


# ---------------------------------------------------------------------------
# DDL â€” executed as separate statements (psycopg execute() is single-statement)
# ---------------------------------------------------------------------------

_CREATE_SCHEMA = "CREATE SCHEMA IF NOT EXISTS surveillance"

_CREATE_TABLE = """
CREATE TABLE IF NOT EXISTS surveillance.surveillance_events (
    id                  BIGSERIAL        PRIMARY KEY,
    timestamp_start     TIMESTAMPTZ      NOT NULL,
    timestamp_end       TIMESTAMPTZ      NOT NULL,
    duration_sec        DOUBLE PRECISION NOT NULL,
    track_id            INTEGER          NOT NULL,
    identity_name       TEXT             NOT NULL,
    identity_confidence DOUBLE PRECISION NOT NULL,
    identity_source     TEXT             NOT NULL,
    activity            TEXT             NOT NULL,
    event_trigger       TEXT             NOT NULL
)
"""

_CREATE_IDX_TS   = "CREATE INDEX IF NOT EXISTS idx_se_ts_start ON surveillance.surveillance_events (timestamp_start)"
_CREATE_IDX_ID   = "CREATE INDEX IF NOT EXISTS idx_se_identity  ON surveillance.surveillance_events (identity_name)"
_CREATE_IDX_TRIG = "CREATE INDEX IF NOT EXISTS idx_se_trigger   ON surveillance.surveillance_events (event_trigger)"


class SurveillanceRepository:
    """Dual-write persistence repository for Camera-B surveillance events.

    Writes events to both CSV (backward safety) and PostgreSQL (analytics
    store).  All read methods query PostgreSQL so that analytics code gets
    typed, indexed data without re-parsing CSV strings.

    Parameters
    ----------
    csv_path:
        Destination CSV file.  Passed through to ``EventLogger``; parent
        directories are created automatically.
    db_path:
        Accepted for API compatibility; ignored â€” PostgreSQL connection is
        configured via DB_* environment variables.
    """

    def __init__(self, csv_path: Path, db_path: Optional[Path] = None) -> None:
        # CSV backend â€“ kept for backward safety / human-readable audit trail.
        self._logger = EventLogger(csv_path)

        # PostgreSQL backend â€“ primary analytics store.
        # A threading.Lock serialises all DB operations: psycopg 3 connections
        # are not thread-safe by default.
        self._lock = threading.Lock()
        self._db_conn: Optional[psycopg.Connection] = None
        self._connect()
        _init_db(self._db_conn)  # type: ignore[arg-type]
        _log.info(
            "SurveillanceRepository ready (CSV â†’ %s | PostgreSQL â†’ %s/%s)",
            csv_path,
            os.environ.get("DB_HOST", "127.0.0.1"),
            os.environ.get("DB_DATABASE", "mq_monitoring"),
        )

    # ------------------------------------------------------------------
    # Internal connection management
    # ------------------------------------------------------------------

    def _connect(self) -> None:
        """Open a new PostgreSQL connection (or reconnect if stale)."""
        if self._db_conn is not None:
            try:
                with self._db_conn.cursor() as _cur:
                    _cur.execute("SELECT 1")
                return  # connection is healthy
            except Exception:
                _log.warning("SurveillanceRepository: stale connection â€” reconnecting.")
                try:
                    self._db_conn.close()
                except Exception:
                    pass
                self._db_conn = None
        self._db_conn = psycopg.connect(_build_conninfo(), row_factory=dict_row)

    def _conn(self) -> psycopg.Connection:
        """Return a healthy connection, reconnecting if necessary."""
        self._connect()
        return self._db_conn  # type: ignore[return-value]

    # ------------------------------------------------------------------
    # Public API
    # The method signatures here are intentionally stable and must not
    # change when the underlying storage backend changes.
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
        """Persist one closed activity event.

        Forwards to the active storage backend (CSV + PostgreSQL).
        Callers do not need to change when the backend changes.

        Parameters
        ----------
        track_id:             DeepSORT track ID.
        identity_name:        "Unknown" or resolved employee name.
        activity:             Activity label (e.g. "Working").
        start_time:           Epoch timestamp when the activity started.
        end_time:             Epoch timestamp when the activity ended.
        identity_confidence:  Cosine-sim score for the identity [0, 1].
        identity_source:      "face" | "manual" | "reassoc_face_confirmed" |
                              "reassoc_short_term" | "none".
        event_trigger:        Why this row is being written; use one of the
                              EVENT_TRIGGER_* constants re-exported from this
                              module.  Defaults to EVENT_TRIGGER_ACTIVITY_CHANGE.
        flush:                Whether to flush/commit after writing.
        """
        # CSV write â€“ backward safety / human-readable audit trail.
        self._logger.log_event(
            track_id=track_id,
            identity_name=identity_name,
            activity=activity,
            start_time=start_time,
            end_time=end_time,
            identity_confidence=identity_confidence,
            identity_source=identity_source,
            event_trigger=event_trigger,
            flush=flush,
        )

        # PostgreSQL write â€“ primary queryable / analytics store.
        _ts_start = datetime.fromtimestamp(start_time, tz=timezone.utc)
        _ts_end   = datetime.fromtimestamp(end_time,   tz=timezone.utc)
        _dur      = max(0.0, end_time - start_time)

        with self._lock:
            conn = self._conn()
            with conn.cursor() as cur:
                cur.execute(
                    "INSERT INTO surveillance.surveillance_events "
                    "(timestamp_start, timestamp_end, duration_sec, track_id, "
                    " identity_name, identity_confidence, identity_source, "
                    " activity, event_trigger) "
                    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
                    (_ts_start, _ts_end, _dur, track_id,
                     identity_name, identity_confidence, identity_source,
                     activity, event_trigger),
                )
            if flush:
                conn.commit()

    def close(self) -> None:
        """Flush and close all storage backends (CSV and PostgreSQL)."""
        self._logger.close()
        with self._lock:
            if self._db_conn is not None:
                try:
                    self._db_conn.commit()
                    self._db_conn.close()
                except Exception:
                    pass
                finally:
                    self._db_conn = None

    # ------------------------------------------------------------------
    # Read / query API
    # All methods read from the PostgreSQL database so that analytics
    # code gets typed, indexed data.
    # ------------------------------------------------------------------

    def get_events_by_date_range(
        self,
        start_time: datetime,
        end_time: datetime,
    ) -> List[Dict[str, Any]]:
        """Return all events whose segment *start* falls within [start_time, end_time].

        Parameters
        ----------
        start_time:  Inclusive lower bound (local ``datetime``).
        end_time:    Inclusive upper bound (local ``datetime``).

        Returns
        -------
        List of event dicts (see ``_parse_db_row`` for field types).
        Empty list when no rows match.
        """
        rows = self._read_all_rows()
        return [
            r for r in rows
            if start_time <= r["timestamp_start"] <= end_time
        ]

    def get_events_by_identity(
        self,
        identity_name: str,
        start_time: Optional[datetime] = None,
        end_time: Optional[datetime] = None,
    ) -> List[Dict[str, Any]]:
        """Return events for a specific identity, optionally within a time window.

        Parameters
        ----------
        identity_name:  Exact name to match (case-sensitive); use ``"Unknown"``
                        to retrieve unidentified tracks.
        start_time:     Optional inclusive lower bound on ``timestamp_start``.
        end_time:       Optional inclusive upper bound on ``timestamp_start``.

        Returns
        -------
        List of matching event dicts.
        """
        rows = self._read_all_rows()
        result = [r for r in rows if r["identity_name"] == identity_name]
        if start_time is not None:
            result = [r for r in result if r["timestamp_start"] >= start_time]
        if end_time is not None:
            result = [r for r in result if r["timestamp_start"] <= end_time]
        return result

    def get_activity_summary(
        self,
        start_time: datetime,
        end_time: datetime,
        identity_name: Optional[str] = None,
        include_triggers: Optional[Sequence[str]] = None,
    ) -> Dict[str, float]:
        """Aggregate total ``duration_sec`` per activity label within a time window.

        Parameters
        ----------
        start_time:       Inclusive lower bound on ``timestamp_start``.
        end_time:         Inclusive upper bound on ``timestamp_start``.
        identity_name:    When given, restrict to this identity only.
        include_triggers: When given, restrict to rows whose ``event_trigger``
                          is in this sequence (e.g. ``[EVENT_TRIGGER_ACTIVITY_CHANGE]``
                          to exclude incomplete session-end rows).
                          When ``None`` all triggers are included.

        Returns
        -------
        Dict mapping activity label â†’ total seconds (float).
        Activities with no matching rows are not included.
        Example: ``{"Working": 3620.5, "Inactive": 240.0}``
        """
        rows = self.get_events_by_date_range(start_time, end_time)

        if identity_name is not None:
            rows = [r for r in rows if r["identity_name"] == identity_name]

        if include_triggers is not None:
            trigger_set = set(include_triggers)
            rows = [r for r in rows if r["event_trigger"] in trigger_set]

        totals: Dict[str, float] = {}
        for r in rows:
            label = r["activity"]
            totals[label] = totals.get(label, 0.0) + r["duration_sec"]
        return totals

    # ------------------------------------------------------------------
    # Private read helpers (PostgreSQL-backed)
    # ------------------------------------------------------------------

    def _read_all_rows(self) -> List[Dict[str, Any]]:
        """Read all events from the PostgreSQL database.

        Returns typed dicts matching the stable 9-field schema, ordered by
        ``timestamp_start``.  ``timestamp_start`` and ``timestamp_end`` are
        returned as naive local datetimes to preserve the existing caller
        contract (which was built on SQLite TEXT columns parsed via strptime).
        """
        with self._lock:
            conn = self._conn()
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT timestamp_start, timestamp_end, duration_sec, track_id, "
                    "       identity_name, identity_confidence, identity_source, "
                    "       activity, event_trigger "
                    "FROM surveillance.surveillance_events "
                    "ORDER BY timestamp_start"
                )
                raw_rows = cur.fetchall()

        results: List[Dict[str, Any]] = []
        for row in raw_rows:
            try:
                results.append(_parse_db_row(row))
            except (ValueError, TypeError, KeyError) as exc:
                _log.debug("[DB] row skipped â€“ parse error: %s", exc)
        return results


# ---------------------------------------------------------------------------
# PostgreSQL initialiser (private to this module)
# ---------------------------------------------------------------------------

def _init_db(conn: psycopg.Connection) -> None:
    """Create the ``surveillance.surveillance_events`` table and indices if absent."""
    with conn.cursor() as cur:
        cur.execute(_CREATE_SCHEMA)
        cur.execute(_CREATE_TABLE)
        cur.execute(_CREATE_IDX_TS)
        cur.execute(_CREATE_IDX_ID)
        cur.execute(_CREATE_IDX_TRIG)
    conn.commit()


# ---------------------------------------------------------------------------
# DB row parser
# ---------------------------------------------------------------------------

def _parse_db_row(row: Dict[str, Any]) -> Dict[str, Any]:
    """Convert one psycopg dict_row into typed fields.

    ``timestamp_start`` / ``timestamp_end`` are TIMESTAMPTZ values returned by
    psycopg 3 as timezone-aware datetimes.  We convert them to naive local
    datetimes so that the return contract matches the former SQLite TEXT
    implementation (which had no timezone information).
    """
    return {
        "timestamp_start":     row["timestamp_start"].astimezone().replace(tzinfo=None),
        "timestamp_end":       row["timestamp_end"].astimezone().replace(tzinfo=None),
        "duration_sec":        float(row["duration_sec"]),
        "track_id":            int(row["track_id"]),
        "identity_name":       row["identity_name"],
        "identity_confidence": float(row["identity_confidence"]),
        "identity_source":     row["identity_source"],
        "activity":            row["activity"],
        "event_trigger":       row["event_trigger"],
    }


# ---------------------------------------------------------------------------
# Module-level CSV row parser (kept â€” EventLogger still writes CSV)
# ---------------------------------------------------------------------------

# The 9 required column names that constitute the stable schema.
_REQUIRED_COLUMNS = {
    "timestamp_start", "timestamp_end", "duration_sec", "track_id",
    "identity_name", "identity_confidence", "identity_source",
    "activity", "event_trigger",
}


def _parse_row(raw: Dict[str, str], lineno: int) -> Optional[Dict[str, Any]]:
    """Convert one raw CSV DictReader row into typed fields.

    Returns ``None`` (and logs at DEBUG) for any row that:
    - is missing one or more required columns (legacy / pre-schema rows), or
    - contains an unparseable numeric or timestamp value.

    Callers must treat a ``None`` return as "skip this row".
    """
    # Reject rows that are missing any of the 9 stable schema columns.
    if not _REQUIRED_COLUMNS.issubset(raw.keys()):
        missing = _REQUIRED_COLUMNS - raw.keys()
        _log.debug("[CSV] line %d skipped â€“ missing columns: %s", lineno, missing)
        return None

    try:
        return {
            "timestamp_start":    datetime.strptime(raw["timestamp_start"].strip(), _TS_FMT),
            "timestamp_end":      datetime.strptime(raw["timestamp_end"].strip(), _TS_FMT),
            "duration_sec":       float(raw["duration_sec"]),
            "track_id":           int(raw["track_id"]),
            "identity_name":      raw["identity_name"].strip(),
            "identity_confidence": float(raw["identity_confidence"]),
            "identity_source":    raw["identity_source"].strip(),
            "activity":           raw["activity"].strip(),
            "event_trigger":      raw["event_trigger"].strip(),
        }
    except (ValueError, KeyError) as exc:
        _log.debug("[CSV] line %d skipped â€“ parse error: %s", lineno, exc)
        return None


