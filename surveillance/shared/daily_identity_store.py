"""
Daily Identity Store
====================

A lightweight PostgreSQL-backed bridge between Camera A (attendance / check-in)
and Camera B (surveillance / activity monitoring).

Camera A writes a fresh identity profile immediately after a successful
check-in.  Camera B reads today's profiles periodically and uses them as
supplementary face-matching evidence, enabling prompt and confident identity
recognition on the surveillance stream even when face crops are small.

Design principles
-----------------
* **Isolated** -- no imports from either camera pipeline.  Neither pipeline
  file needs to be modified to wire this up.
* **Additive** -- if this store is empty or unavailable, both cameras continue
  to work exactly as they do today.
* **Concurrency-safe** -- a ``threading.Lock`` serialises all DB operations
  within the same process (psycopg 3 connections are not thread-safe by
  default).
* **Auto-heal** -- creates the schema, table, and index on first use.
  Errors are logged and swallowed so that a DB failure never crashes the
  main pipeline.

Storage
-------
Default connection: reads DB credentials from the same environment variables
used by the Laravel backend (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME,
DB_PASSWORD).  All objects are stored in the ``identity`` schema.

Schema
------
One row per ``(employee_id, date)``.  A re-check-in on the same day replaces
the row with the newer embedding and confidence (UPSERT).

    identity.daily_profiles (
        id          BIGSERIAL        PRIMARY KEY,
        employee_id TEXT             NOT NULL,
        date        DATE             NOT NULL,
        embedding   BYTEA            NOT NULL,  -- float32 raw bytes, 512-d = 2048 bytes
        confidence  DOUBLE PRECISION NOT NULL,
        created_at  TIMESTAMPTZ      NOT NULL
    )
    UNIQUE INDEX on (employee_id, date)
"""

from __future__ import annotations

import logging
import os
import threading
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Dict, Optional, Union

import numpy as np
import psycopg
from psycopg.rows import dict_row

log = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

_TS_FMT: str = "%Y-%m-%d %H:%M:%S"

# Kept for __repr__ / backward-compat with callers that pass db_path=...
_DEFAULT_DB_PATH: Path = (
    Path(__file__).resolve().parent.parent / "data" / "daily_identity_store.db"
)

# ---------------------------------------------------------------------------
# DDL -- three separate statements (psycopg execute() is single-statement)
# ---------------------------------------------------------------------------

_CREATE_SCHEMA = "CREATE SCHEMA IF NOT EXISTS identity"

_CREATE_TABLE = """
CREATE TABLE IF NOT EXISTS identity.daily_profiles (
    id          BIGSERIAL        PRIMARY KEY,
    employee_id TEXT             NOT NULL,
    date        DATE             NOT NULL,
    embedding   BYTEA            NOT NULL,
    confidence  DOUBLE PRECISION NOT NULL,
    created_at  TIMESTAMPTZ      NOT NULL
)
"""

_CREATE_INDEX = """
CREATE UNIQUE INDEX IF NOT EXISTS uix_daily_profiles_employee_date
    ON identity.daily_profiles (employee_id, date)
"""


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
# Public data transfer object
# ---------------------------------------------------------------------------

@dataclass
class DailyProfile:
    """Immutable view of one employee's same-day identity profile.

    Attributes
    ----------
    employee_id : Unique employee identifier (name or ID string).
    embedding   : L2-normalised ArcFace embedding as a float32 ndarray.
                  Shape: ``(512,)`` -- matches the ArcFace ONNX output used by
                  both Camera A (InsightFace buffalo_l) and Camera B (ONNX).
    confidence  : Camera A cosine-similarity score at check-in [0.0, 1.0].
    checkin_ts  : Wall-clock timestamp of the check-in (UTC time string,
                  format ``"YYYY-MM-DD HH:MM:SS"``).
    """

    employee_id: str
    embedding: np.ndarray
    confidence: float
    checkin_ts: str

    def __eq__(self, other: object) -> bool:
        if not isinstance(other, DailyProfile):
            return NotImplemented
        return (
            self.employee_id == other.employee_id
            and np.array_equal(self.embedding, other.embedding)
            and self.confidence == other.confidence
            and self.checkin_ts == other.checkin_ts
        )

    # Explicitly unhashable: the ndarray field is mutable.
    __hash__ = None  # type: ignore[assignment]


# ---------------------------------------------------------------------------
# Main class
# ---------------------------------------------------------------------------

class DailyIdentityStore:
    """PostgreSQL-backed bridge for sharing identity profiles between Camera A and B.

    Typical Camera A usage (write once per check-in)::

        store = DailyIdentityStore()
        store.add_identity("Amir", embedding_array, confidence=0.82)

    Typical Camera B usage (read periodically, e.g. every 30-60 s)::

        store = DailyIdentityStore()
        profiles = store.get_identities_for_today()
        if "Amir" in profiles:
            fresh_emb = profiles["Amir"].embedding
            # compare fresh_emb alongside static enrollment embeddings

    Parameters
    ----------
    db_path : Accepted for API compatibility; ignored -- the PostgreSQL
              connection is configured via DB_* environment variables.
    """

    def __init__(self, db_path: Optional[Union[Path, str]] = None) -> None:
        # Stored for backward-compat only; not used for the connection.
        self._db_path: Path = (
            Path(db_path) if db_path is not None else _DEFAULT_DB_PATH
        )

        # Serialises ALL DB operations within the same process.
        # psycopg 3 connections are not thread-safe; this lock ensures only
        # one thread uses the connection at a time.
        self._lock = threading.Lock()

        # Lazy connection -- opened on first DB call.
        self._conn: Optional[psycopg.Connection] = None

        self._init_db()

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _connect(self) -> psycopg.Connection:
        """Return the open connection, creating or reconnecting it as needed."""
        if self._conn is not None:
            try:
                with self._conn.cursor() as _cur:
                    _cur.execute("SELECT 1")
            except Exception:
                log.warning(
                    "DailyIdentityStore: stale connection detected -- reconnecting."
                )
                try:
                    self._conn.close()
                except Exception:
                    pass
                self._conn = None
        if self._conn is None:
            conn = psycopg.connect(_build_conninfo(), row_factory=dict_row)
            self._conn = conn
        return self._conn

    def _init_db(self) -> None:
        """Create schema, table, and unique index if they do not yet exist."""
        try:
            conn = self._connect()
            with self._lock:
                with conn.cursor() as cur:
                    cur.execute(_CREATE_SCHEMA)
                    cur.execute(_CREATE_TABLE)
                    cur.execute(_CREATE_INDEX)
                conn.commit()
            log.debug("DailyIdentityStore ready (PostgreSQL)")
        except Exception:
            log.exception(
                "DailyIdentityStore: failed to initialise database. "
                "Identity bridge will be unavailable until the error is resolved.",
            )

    # ------------------------------------------------------------------
    # Public write API  (Camera A side)
    # ------------------------------------------------------------------

    def add_identity(
        self,
        employee_id: str,
        embedding: np.ndarray,
        confidence: float,
    ) -> None:
        """Persist a fresh identity profile for today.

        If the employee already has a profile for today (e.g. they passed
        Camera A twice) the existing row is replaced with the newer data.
        This preserves the most recent, highest-quality scan.

        Parameters
        ----------
        employee_id : Employee name or ID string (e.g. ``"Amir"``).  Must be
                      a non-empty string; the call is skipped with a warning
                      if not.
        embedding   : ArcFace face embedding.  Any shape and numeric dtype is
                      accepted -- it will be flattened and cast to ``float32``
                      before storage.
        confidence  : Cosine-similarity score from Camera A's identity match
                      (e.g. ``0.82``).  Stored as-is; no clamping applied.

        Raises
        ------
        Nothing -- all exceptions are caught and logged so that a DB error
        never propagates to the calling check-in pipeline.
        """
        if not isinstance(employee_id, str) or not employee_id.strip():
            log.warning(
                "DailyIdentityStore.add_identity: invalid employee_id %r -- "
                "skipped.",
                employee_id,
            )
            return

        today = date.today()
        now_dt = datetime.now(tz=timezone.utc)

        # Serialise embedding: flatten -> float32 -> raw bytes.
        # Deserialisation: np.frombuffer(blob, dtype=np.float32)
        try:
            emb_arr: np.ndarray = np.asarray(embedding, dtype=np.float32).reshape(-1)
            emb_bytes: bytes = emb_arr.tobytes()
        except Exception:
            log.exception(
                "DailyIdentityStore.add_identity: could not serialise embedding "
                "for employee_id=%r -- skipped.",
                employee_id,
            )
            return

        try:
            conn = self._connect()
            with self._lock:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        INSERT INTO identity.daily_profiles
                            (employee_id, date, embedding, confidence, created_at)
                        VALUES (%s, %s, %s, %s, %s)
                        ON CONFLICT (employee_id, date) DO UPDATE SET
                            embedding  = EXCLUDED.embedding,
                            confidence = EXCLUDED.confidence,
                            created_at = EXCLUDED.created_at
                        """,
                        (employee_id, today, emb_bytes, float(confidence), now_dt),
                    )
                conn.commit()
            log.info(
                "DailyIdentityStore: profile stored -- employee_id=%r "
                "date=%s confidence=%.4f embedding_dim=%d",
                employee_id, today, confidence, int(emb_arr.shape[0]),
            )
        except Exception:
            log.exception(
                "DailyIdentityStore.add_identity: DB write failed for "
                "employee_id=%r -- main pipeline is unaffected.",
                employee_id,
            )

    # ------------------------------------------------------------------
    # Public read API  (Camera B side)
    # ------------------------------------------------------------------

    def get_identities_for_today(self) -> Dict[str, DailyProfile]:
        """Return all identity profiles enrolled today.

        Returns
        -------
        Dict mapping ``employee_id`` (str) to ``DailyProfile``.
        Returns an empty dict when no check-ins have occurred today, or if a
        DB error occurs (the error is logged).

        Notes
        -----
        When an employee has checked in multiple times today, only the most
        recent row is present (the UPSERT in ``add_identity`` guarantees
        uniqueness per ``(employee_id, date)``).
        """
        today = date.today()
        try:
            conn = self._connect()
            with self._lock:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        SELECT employee_id, embedding, confidence, created_at
                        FROM identity.daily_profiles
                        WHERE date = %s
                        """,
                        (today,),
                    )
                    rows = cur.fetchall()
            profiles: Dict[str, DailyProfile] = {}
            for row in rows:
                eid = row["employee_id"]
                try:
                    emb_arr = np.frombuffer(
                        bytes(row["embedding"]), dtype=np.float32
                    ).copy()  # .copy() makes the array writable
                    # created_at is TIMESTAMPTZ -- always format in UTC for consistency
                    checkin_ts = row["created_at"].astimezone(timezone.utc).strftime(_TS_FMT)
                    profiles[eid] = DailyProfile(
                        employee_id=eid,
                        embedding=emb_arr,
                        confidence=float(row["confidence"]),
                        checkin_ts=checkin_ts,
                    )
                except Exception:
                    log.exception(
                        "DailyIdentityStore: failed to deserialise row "
                        "for employee_id=%r -- entry skipped.",
                        eid,
                    )
            log.debug(
                "DailyIdentityStore.get_identities_for_today: "
                "%d profile(s) returned for %s",
                len(profiles), today,
            )
            return profiles
        except Exception:
            log.exception(
                "DailyIdentityStore.get_identities_for_today: DB read failed -- "
                "returning empty dict."
            )
            return {}

    # ------------------------------------------------------------------
    # Public maintenance API  (called at process startup / shutdown)
    # ------------------------------------------------------------------

    def cleanup_old_entries(self, days: int = 1) -> int:
        """Delete profiles older than ``days`` days.

        Parameters
        ----------
        days : Profiles whose ``date`` is at least this many days before
               today are removed.  The default of ``1`` deletes yesterday
               and older, keeping only today's profiles.

        Returns
        -------
        Number of rows deleted.  Returns ``0`` on error (error is logged).
        """
        cutoff = date.today() - timedelta(days=days)
        try:
            conn = self._connect()
            with self._lock:
                with conn.cursor() as cur:
                    cur.execute(
                        "DELETE FROM identity.daily_profiles WHERE date <= %s",
                        (cutoff,),
                    )
                    deleted: int = cur.rowcount
                conn.commit()
            if deleted > 0:
                log.info(
                    "DailyIdentityStore.cleanup_old_entries: "
                    "deleted %d row(s) older than %s",
                    deleted, cutoff,
                )
            else:
                log.debug(
                    "DailyIdentityStore.cleanup_old_entries: "
                    "nothing to delete (cutoff=%s)",
                    cutoff,
                )
            return deleted
        except Exception:
            log.exception(
                "DailyIdentityStore.cleanup_old_entries: DB error -- "
                "0 rows deleted."
            )
            return 0

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def close(self) -> None:
        """Flush and close the PostgreSQL connection.

        Safe to call multiple times.  The instance can no longer be used
        after ``close()`` is called.
        """
        if self._conn is not None:
            try:
                self._conn.commit()
                self._conn.close()
            except Exception:
                pass
            finally:
                self._conn = None
            log.debug("DailyIdentityStore: connection closed.")

    def __repr__(self) -> str:
        return (
            f"DailyIdentityStore("
            f"host={os.environ.get('DB_HOST', '127.0.0.1')!r}, "
            f"dbname={os.environ.get('DB_DATABASE', 'mq_monitoring')!r})"
        )
