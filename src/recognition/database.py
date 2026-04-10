"""
Attendance persistence — PostgreSQL backend (psycopg 3).

Schema
------
attendance.attendance_events (
    id     BIGSERIAL        PRIMARY KEY,
    ts     DOUBLE PRECISION NOT NULL,   -- Unix epoch float, preserved for compat
    ts_at  TIMESTAMPTZ      NOT NULL,   -- authoritative timestamp (replaces ts_text)
    person TEXT             NOT NULL,
    event  TEXT             NOT NULL
)
UNIQUE INDEX on (person, event, (ts_at AT TIME ZONE 'UTC')::DATE)  -- one check-in per UTC day

Connection
----------
Reads the same env-var names used by the Laravel .env:
  DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

Public function signatures are unchanged from the SQLite version.
Return values that were previously ts_text strings remain 'YYYY-MM-DD HH:MM:SS'
local-time strings so that callers (main.py, attendance_webcam.py) need no edits.
"""

import os
import time
from datetime import datetime, timezone

import psycopg
from psycopg.errors import UniqueViolation

from config import config  # noqa: F401 — imported for side-effects / future use
from logger import logger


# ---------------------------------------------------------------------------
# Connection helpers
# ---------------------------------------------------------------------------

def _build_conninfo() -> str:
    """Build a psycopg conninfo string from environment variables."""
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = os.environ.get("DB_PORT", "5432")
    dbname = os.environ.get("DB_DATABASE", "mq_monitoring")
    user = os.environ.get("DB_USERNAME", "mq_user")
    pw = os.environ.get("DB_PASSWORD", "")
    return f"host={host} port={port} dbname={dbname} user={user} password={pw}"


# ---------------------------------------------------------------------------
# DDL (two statements — executed separately; psycopg execute() is single-stmt)
# ---------------------------------------------------------------------------

_CREATE_SCHEMA = """
CREATE SCHEMA IF NOT EXISTS attendance
"""

_CREATE_TABLE = """
CREATE TABLE IF NOT EXISTS attendance.attendance_events (
    id     BIGSERIAL        PRIMARY KEY,
    ts     DOUBLE PRECISION NOT NULL,
    ts_at  TIMESTAMPTZ      NOT NULL,
    person TEXT             NOT NULL,
    event  TEXT             NOT NULL
)
"""

_CREATE_INDEX = """
CREATE UNIQUE INDEX IF NOT EXISTS uniq_checkin_per_day
    ON attendance.attendance_events (person, event, ((ts_at AT TIME ZONE 'UTC')::DATE))
"""


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def today_str() -> str:
    """Return today's date as 'YYYY-MM-DD' in UTC to match stored ts_at values."""
    return datetime.now(timezone.utc).date().isoformat()


def _ts_to_local_str(ts_aware: datetime) -> str:
    """Convert a timezone-aware datetime to a local 'YYYY-MM-DD HH:MM:SS' string."""
    return ts_aware.astimezone().strftime("%Y-%m-%d %H:%M:%S")


# ---------------------------------------------------------------------------
# Public API (signatures unchanged from the SQLite version)
# ---------------------------------------------------------------------------

def init_db() -> psycopg.Connection:
    """Open a PostgreSQL connection, create schema/table/index if absent, return connection.

    The connection uses psycopg 3's default autocommit=False mode.
    All write operations in this module commit explicitly.
    """
    conninfo = _build_conninfo()
    conn = psycopg.connect(conninfo)
    with conn.cursor() as cur:
        cur.execute(_CREATE_SCHEMA)
        cur.execute(_CREATE_TABLE)
        cur.execute(_CREATE_INDEX)
    conn.commit()
    logger.info(
        "Attendance DB ready (PostgreSQL @ %s/%s)",
        os.environ.get("DB_HOST", "127.0.0.1"),
        os.environ.get("DB_DATABASE", "mq_monitoring"),
    )
    return conn


def load_today_checkins(conn: psycopg.Connection):
    """Return (present_set, checkin_time) for today's check-ins.

    present_set   : set of person names that have checked in today.
    checkin_time  : dict mapping person -> first check-in as 'YYYY-MM-DD HH:MM:SS'
                    local-time string (same format as the former ts_text column).
    """
    today = today_str()
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT person, MIN(ts_at) AS first_time
            FROM attendance.attendance_events
            WHERE event = 'check_in'
              AND (ts_at AT TIME ZONE 'UTC')::DATE = %s::DATE
            GROUP BY person
            """,
            (today,),
        )
        rows = cur.fetchall()

    present_set: set = set()
    checkin_time: dict = {}
    for person, first_time in rows:
        present_set.add(person)
        checkin_time[person] = _ts_to_local_str(first_time)

    if rows:
        logger.info("Loaded %d check-in(s) for today from DB.", len(rows))
    return present_set, checkin_time


def get_today_checkin_time(conn: psycopg.Connection, person: str):
    """Return the first check-in time for *person* today, or None.

    Returns a 'YYYY-MM-DD HH:MM:SS' local-time string to preserve the
    interface previously provided by the SQLite version.
    """
    today = today_str()
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT MIN(ts_at)
            FROM attendance.attendance_events
            WHERE person = %s
              AND event = 'check_in'
              AND (ts_at AT TIME ZONE 'UTC')::DATE = %s::DATE
            """,
            (person, today),
        )
        row = cur.fetchone()

    if row and row[0] is not None:
        return _ts_to_local_str(row[0])
    return None


def log_checkin_once_per_day(conn: psycopg.Connection, person: str) -> str:
    """Insert a check_in row for *person* if none exists today.

    Returns the first check-in time for today as a 'YYYY-MM-DD HH:MM:SS'
    local-time string. If the person already checked in today the existing
    timestamp is returned and no insert is performed.

    The unique index on (person, event, (ts_at AT TIME ZONE 'UTC')::DATE) enforces the
    one-per-day constraint at the database level; UniqueViolation is caught
    and treated as a no-op (same behaviour as the former SQLite version).
    """
    now_epoch = time.time()
    now_dt = datetime.now(tz=timezone.utc)
    now_text = _ts_to_local_str(now_dt)

    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO attendance.attendance_events (ts, ts_at, person, event)
                VALUES (%s, %s, %s, %s)
                """,
                (now_epoch, now_dt, person, "check_in"),
            )
        conn.commit()
        logger.info("%s | %s -> check_in", now_text, person)
        return now_text
    except UniqueViolation:
        conn.rollback()
        existing = get_today_checkin_time(conn, person)
        logger.info("%s already checked-in today (skipped)", person)
        return existing or now_text