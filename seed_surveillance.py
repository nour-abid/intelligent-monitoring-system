"""
seed_surveillance.py

Seed the surveillance_events SQLite database with realistic, temporally-coherent
test data covering three working days (2026-03-17 Mon, 2026-03-18 Tue, 2026-03-19 Wed).

Usage:
    python seed_surveillance.py           # safe append with idempotency guard
    python seed_surveillance.py --fresh   # delete seed-owned rows first, then insert

The script NEVER drops the table or alters its schema.
"Seed-owned" rows = identity_name IN SEED_IDENTITIES AND
                    timestamp_start BETWEEN SEED_DATE_MIN and SEED_DATE_MAX.
All other rows (Unknown tracks, live runtime rows, etc.) are UNTOUCHED.

Identity-to-user mapping (matches UsersSeeder.php exactly):
    nour.abid        → Nour Abid         (admin)
    amir.dammak      → Amir Dammak       (superviseur)
    bellaaj          → M. Y. Bellaaj     (superviseur)
    yessine.gargouri → Yessine Gargouri  (viewer → Amir Dammak)
    amine.elleuch    → M. A. Elleuch     (viewer → Amir Dammak)
    rahma.boukhris   → Rahma Boukhris    (viewer → M. Y. Bellaaj)

Activity design per person:
    yessine.gargouri  — mostly Working; sparse Using_Phone
    amine.elleuch     — regular Inactive blocks (>10 min); useful for inactivity alerts
    rahma.boukhris    — frequent Using_Phone blocks (>5 min); useful for phone alerts
    amir.dammak       — Meeting-heavy; some Working
    bellaaj           — balanced Working / Meeting / light Inactive
    nour.abid         — light admin presence (two partial days)
"""

import argparse
import sqlite3
from datetime import datetime
from pathlib import Path

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

DB_PATH = Path(
    r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system\logs\surveillance_events.db"
)

# Exclusive date window claimed by this seeder.
SEED_DATE_MIN = "2026-03-17 00:00:00"
SEED_DATE_MAX = "2026-03-19 23:59:59"

SEED_IDENTITIES = [
    "nour.abid",
    "amir.dammak",
    "bellaaj",
    "yessine.gargouri",
    "amine.elleuch",
    "rahma.boukhris",
]

# Base ArcFace cosine-similarity confidence per identity.
# Each segment gets a ±0.007 variation to look natural.
_BASE_CONF = {
    "nour.abid":         0.8742,
    "amir.dammak":       0.8523,
    "bellaaj":           0.9113,
    "yessine.gargouri":  0.7934,
    "amine.elleuch":     0.7658,
    "rahma.boukhris":    0.8312,
}

# ---------------------------------------------------------------------------
# Schedule
# Each entry: (date "YYYY-MM-DD", identity, track_id, [(start, end, activity)])
#
# Rules:
#   • Segments within a day are contiguous (end_n == start_n+1).
#   • Lunch gaps (12:00-13:00) are intentionally absent — no camera coverage.
#   • The LAST segment of each day gets event_trigger = "session_end";
#     all others get "activity_change".
# ---------------------------------------------------------------------------

SCHEDULE = [

    # ── yessine.gargouri: mostly Working, light Using_Phone ──────────────────
    ("2026-03-17", "yessine.gargouri", 40, [
        ("09:00", "09:45", "Working"),
        ("09:45", "09:55", "Using_Phone"),
        ("09:55", "11:15", "Working"),
        ("11:15", "11:25", "Using_Phone"),
        ("11:25", "12:00", "Working"),
        # lunch 12:00-13:00
        ("13:00", "14:30", "Working"),
        ("14:30", "14:40", "Using_Phone"),
        ("14:40", "16:00", "Working"),
        ("16:00", "17:30", "Working"),          # → session_end
    ]),
    ("2026-03-18", "yessine.gargouri", 41, [
        ("09:00", "10:30", "Working"),
        ("10:30", "10:42", "Using_Phone"),
        ("10:42", "12:00", "Working"),
        # lunch
        ("13:00", "15:00", "Working"),
        ("15:00", "15:15", "Using_Phone"),
        ("15:15", "16:45", "Working"),
        ("16:45", "18:00", "Working"),          # → session_end
    ]),
    ("2026-03-19", "yessine.gargouri", 42, [
        ("09:00", "10:00", "Working"),
        ("10:00", "10:08", "Using_Phone"),
        ("10:08", "12:00", "Working"),
        # lunch
        ("13:00", "14:30", "Working"),
        ("14:30", "14:50", "Using_Phone"),
        ("14:50", "17:00", "Working"),          # → session_end
    ]),

    # ── amine.elleuch: frequent Inactive segments (>10 min each) ─────────────
    # These exceed the ALERT_INACTIVE_THRESHOLD_MINUTES=10 window when summed.
    ("2026-03-17", "amine.elleuch", 50, [
        ("09:00", "09:30", "Working"),
        ("09:30", "09:45", "Inactive"),         # 15 min
        ("09:45", "10:45", "Working"),
        ("10:45", "11:05", "Inactive"),         # 20 min ← alert-worthy
        ("11:05", "12:00", "Working"),
        # lunch
        ("13:00", "13:45", "Working"),
        ("13:45", "14:05", "Inactive"),         # 20 min ← alert-worthy
        ("14:05", "15:00", "Working"),
        ("15:00", "15:20", "Inactive"),         # 20 min ← alert-worthy
        ("15:20", "16:30", "Working"),
        ("16:30", "16:45", "Inactive"),         # 15 min
        ("16:45", "17:30", "Working"),          # → session_end
    ]),
    ("2026-03-18", "amine.elleuch", 51, [
        ("09:00", "09:20", "Working"),
        ("09:20", "09:35", "Inactive"),         # 15 min
        ("09:35", "10:20", "Working"),
        ("10:20", "10:45", "Inactive"),         # 25 min ← alert-worthy
        ("10:45", "11:45", "Working"),
        ("11:45", "12:00", "Inactive"),         # 15 min
        # lunch
        ("13:00", "13:50", "Working"),
        ("13:50", "14:15", "Inactive"),         # 25 min ← alert-worthy
        ("14:15", "15:30", "Working"),
        ("15:30", "17:00", "Working"),          # → session_end
    ]),
    ("2026-03-19", "amine.elleuch", 52, [
        ("09:00", "09:30", "Working"),
        ("09:30", "09:55", "Inactive"),         # 25 min ← alert-worthy
        ("09:55", "11:00", "Working"),
        ("11:00", "11:15", "Inactive"),         # 15 min
        ("11:15", "12:00", "Working"),
        # lunch
        ("13:00", "14:00", "Working"),
        ("14:00", "14:20", "Inactive"),         # 20 min ← alert-worthy
        ("14:20", "16:00", "Working"),
        ("16:00", "16:12", "Inactive"),         # 12 min
        ("16:12", "17:30", "Working"),          # → session_end
    ]),

    # ── rahma.boukhris: frequent Using_Phone segments (>5 min each) ──────────
    # These exceed ALERT_PHONE_THRESHOLD_MINUTES=5 and test phone alerts.
    ("2026-03-17", "rahma.boukhris", 60, [
        ("09:00", "09:30", "Working"),
        ("09:30", "09:40", "Using_Phone"),      # 10 min ← alert-worthy
        ("09:40", "10:30", "Working"),
        ("10:30", "10:45", "Using_Phone"),      # 15 min ← alert-worthy
        ("10:45", "12:00", "Working"),
        # lunch
        ("13:00", "13:45", "Working"),
        ("13:45", "14:05", "Using_Phone"),      # 20 min ← alert-worthy
        ("14:05", "15:00", "Working"),
        ("15:00", "15:20", "Using_Phone"),      # 20 min ← alert-worthy
        ("15:20", "16:30", "Working"),
        ("16:30", "16:40", "Using_Phone"),      # 10 min ← alert-worthy
        ("16:40", "17:30", "Working"),          # → session_end
    ]),
    ("2026-03-18", "rahma.boukhris", 61, [
        ("09:00", "09:45", "Working"),
        ("09:45", "10:15", "Using_Phone"),      # 30 min ← major alert
        ("10:15", "11:30", "Working"),
        ("11:30", "11:42", "Using_Phone"),      # 12 min ← alert-worthy
        ("11:42", "12:00", "Working"),
        # lunch
        ("13:00", "14:00", "Working"),
        ("14:00", "14:25", "Using_Phone"),      # 25 min ← alert-worthy
        ("14:25", "15:30", "Working"),
        ("15:30", "16:00", "Working"),
        ("16:00", "16:12", "Using_Phone"),      # 12 min ← alert-worthy
        ("16:12", "18:00", "Working"),          # → session_end
    ]),
    ("2026-03-19", "rahma.boukhris", 62, [
        ("09:00", "09:30", "Working"),
        ("09:30", "09:45", "Using_Phone"),      # 15 min ← alert-worthy
        ("09:45", "11:00", "Working"),
        ("11:00", "11:22", "Using_Phone"),      # 22 min ← alert-worthy
        ("11:22", "12:00", "Working"),
        # lunch
        ("13:00", "14:00", "Working"),
        ("14:00", "14:10", "Using_Phone"),      # 10 min ← alert-worthy
        ("14:10", "17:00", "Working"),          # → session_end
    ]),

    # ── amir.dammak: Meeting-heavy superviseur ────────────────────────────────
    ("2026-03-17", "amir.dammak", 20, [
        ("09:00", "10:00", "Working"),
        ("10:00", "11:30", "Meeting"),          # 90 min
        ("11:30", "12:00", "Working"),
        # lunch
        ("13:00", "14:30", "Meeting"),          # 90 min
        ("14:30", "15:30", "Working"),
        ("15:30", "17:00", "Meeting"),          # 90 min
        ("17:00", "18:00", "Working"),          # → session_end
    ]),
    ("2026-03-18", "amir.dammak", 21, [
        ("09:00", "09:30", "Working"),
        ("09:30", "11:00", "Meeting"),          # 90 min
        ("11:00", "12:00", "Working"),
        # lunch
        ("13:00", "15:00", "Meeting"),          # 120 min
        ("15:00", "16:00", "Working"),
        ("16:00", "17:30", "Meeting"),          # → session_end (90 min)
    ]),
    ("2026-03-19", "amir.dammak", 22, [
        ("09:00", "10:30", "Meeting"),          # 90 min
        ("10:30", "11:30", "Working"),
        ("11:30", "12:00", "Meeting"),
        # lunch
        ("13:00", "14:30", "Working"),
        ("14:30", "17:00", "Meeting"),          # → session_end (150 min)
    ]),

    # ── bellaaj: balanced Working / Meeting / light Inactive ─────────────────
    ("2026-03-17", "bellaaj", 30, [
        ("09:00", "10:30", "Working"),
        ("10:30", "11:00", "Meeting"),
        ("11:00", "12:00", "Working"),
        # lunch
        ("13:00", "13:15", "Inactive"),         # 15 min — post-lunch dip
        ("13:15", "14:30", "Working"),
        ("14:30", "16:00", "Meeting"),
        ("16:00", "18:00", "Working"),          # → session_end
    ]),
    ("2026-03-18", "bellaaj", 31, [
        ("09:00", "09:20", "Inactive"),         # 20 min — late start
        ("09:20", "11:00", "Working"),
        ("11:00", "12:00", "Meeting"),
        # lunch
        ("13:00", "14:30", "Working"),
        ("14:30", "15:30", "Meeting"),
        ("15:30", "16:15", "Working"),
        ("16:15", "16:30", "Inactive"),         # 15 min
        ("16:30", "17:30", "Working"),          # → session_end
    ]),
    ("2026-03-19", "bellaaj", 32, [
        ("09:00", "10:30", "Working"),
        ("10:30", "12:00", "Meeting"),
        # lunch
        ("13:00", "13:20", "Inactive"),         # 20 min — post-lunch dip
        ("13:20", "15:00", "Working"),
        ("15:00", "16:30", "Meeting"),
        ("16:30", "17:00", "Working"),          # → session_end
    ]),

    # ── nour.abid: light admin presence (partial days, 2 days only) ──────────
    ("2026-03-17", "nour.abid", 10, [
        ("10:00", "11:30", "Working"),
        ("11:30", "12:00", "Meeting"),
        # lunch
        ("13:00", "14:30", "Working"),
        ("14:30", "16:00", "Working"),          # → session_end
    ]),
    ("2026-03-18", "nour.abid", 11, [
        ("09:30", "11:00", "Working"),
        ("11:00", "12:00", "Meeting"),
        # lunch
        ("13:00", "15:00", "Working"),          # → session_end
    ]),
]


# ---------------------------------------------------------------------------
# Row builder
# ---------------------------------------------------------------------------

def _build_rows(schedule: list) -> list[tuple]:
    rows: list[tuple] = []
    for date_str, identity, track_id, segments in schedule:
        base_conf = _BASE_CONF[identity]
        n = len(segments)
        for i, (start_hm, end_hm, activity) in enumerate(segments):
            start_dt = datetime.strptime(f"{date_str} {start_hm}", "%Y-%m-%d %H:%M")
            end_dt   = datetime.strptime(f"{date_str} {end_hm}",   "%Y-%m-%d %H:%M")
            duration = (end_dt - start_dt).total_seconds()
            trigger  = "session_end" if i == n - 1 else "activity_change"
            # Slight per-segment confidence variation to look natural
            delta = (i % 3 - 1) * 0.007
            conf  = round(max(0.60, min(0.99, base_conf + delta)), 4)
            rows.append((
                start_dt.strftime("%Y-%m-%d %H:%M:%S"),
                end_dt.strftime("%Y-%m-%d %H:%M:%S"),
                duration,
                track_id,
                identity,
                conf,
                "face",
                activity,
                trigger,
            ))
    return rows


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

INSERT_SQL = """
    INSERT INTO surveillance_events
        (timestamp_start, timestamp_end, duration_sec, track_id,
         identity_name, identity_confidence, identity_source, activity, event_trigger)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
"""

DELETE_SQL = """
    DELETE FROM surveillance_events
    WHERE identity_name IN ({placeholders})
      AND timestamp_start >= ?
      AND timestamp_start <= ?
"""

COUNT_SQL = """
    SELECT COUNT(*) FROM surveillance_events
    WHERE identity_name IN ({placeholders})
      AND timestamp_start >= ?
      AND timestamp_start <= ?
"""


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Seed the surveillance_events SQLite DB with test data."
    )
    parser.add_argument(
        "--fresh",
        action="store_true",
        help="Delete existing seed-owned rows before inserting (safe: only touches"
             " SEED_IDENTITIES rows within the SEED_DATE_RANGE).",
    )
    args = parser.parse_args()

    if not DB_PATH.exists():
        print(f"ERROR: DB not found at {DB_PATH}")
        print("Start the Python surveillance runtime at least once to create it, or:")
        print("  import sqlite3; sqlite3.connect(str(DB_PATH)).execute(...)")
        return

    placeholders = ",".join("?" * len(SEED_IDENTITIES))
    bind_range   = [*SEED_IDENTITIES, SEED_DATE_MIN, SEED_DATE_MAX]

    con = sqlite3.connect(str(DB_PATH))
    try:
        if args.fresh:
            deleted = con.execute(
                DELETE_SQL.format(placeholders=placeholders), bind_range
            ).rowcount
            con.commit()
            print(f"--fresh: removed {deleted} existing seed rows.")

        else:
            # Idempotency guard: skip if the date range is already populated.
            existing = con.execute(
                COUNT_SQL.format(placeholders=placeholders), bind_range
            ).fetchone()[0]
            if existing > 0:
                print(
                    f"Seed data already present ({existing} rows in "
                    f"{SEED_DATE_MIN[:10]} – {SEED_DATE_MAX[:10]}).\n"
                    f"Run with --fresh to wipe and re-seed."
                )
                return

        rows = _build_rows(SCHEDULE)
        con.executemany(INSERT_SQL, rows)
        con.commit()
        print(f"Inserted {len(rows)} seed rows into surveillance_events.")
        print(f"Date range : {SEED_DATE_MIN[:10]} – {SEED_DATE_MAX[:10]}")
        print(f"Identities : {', '.join(SEED_IDENTITIES)}")

    finally:
        con.close()


if __name__ == "__main__":
    main()
