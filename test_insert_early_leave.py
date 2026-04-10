#!/usr/bin/env python3
"""
test_insert_early_leave.py

Minimal test helper for safely testing the early_leave alert feature.
Inserts a single surveillance event with a computed end time before the cutoff (17:45).

Database schema and surveillance logic remain untouched.
Only ONE row is inserted per run.

Usage:
    python test_insert_early_leave.py IDENTITY END_TIME_HH:MM
    python test_insert_early_leave.py test_early_real 17:30
    python test_insert_early_leave.py test_early_real 17:00

Arguments:
    IDENTITY       identity_name for the surveillance event (e.g., 'test_early_real')
    END_TIME_HH:MM end time in HH:MM format (must be before 17:45 to trigger alert)

Constraints:
    - end time must be in format HH:MM (24-hour)
    - end time MUST be before 17:45:00 (cutoff = workday_end 18:00 - tolerance 15min)
    - end time should be >= 08:00 (workday start)
    - result will be recorded for TODAY's date

Example sessions:
    # Insert event ending at 17:30 (45 min early)
    python test_insert_early_leave.py test_early_real 17:30

    # Insert event ending at 16:00 (2h early)
    python test_insert_early_leave.py test_early_real 16:00

Output includes:
    - Row ID for deletion/reference if needed
    - Exact event times (today's date with your provided end time)
    - Identity name
    - Query to delete if needed
"""

import argparse
import sqlite3
import sys
from datetime import datetime, timedelta
from pathlib import Path

DB_PATH = Path(
    r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system\logs\surveillance_events.db"
)

CUTOFF_TIME = "17:45:00"  # workday_end (18:00) - tolerance (15 min)
WORKDAY_START = "08:00:00"


def parse_time(time_str: str) -> tuple[int, int]:
    """Parse HH:MM format to (hours, minutes)."""
    try:
        parts = time_str.split(":")
        if len(parts) != 2:
            raise ValueError("Not HH:MM format")
        h, m = int(parts[0]), int(parts[1])
        if not (0 <= h < 24 and 0 <= m < 60):
            raise ValueError("Hours must be 0-23, minutes 0-59")
        return h, m
    except (ValueError, AttributeError):
        raise ValueError(f"Invalid time format: {time_str}. Use HH:MM (24-hour).")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Insert a single surveillance event to test early_leave alert."
    )
    parser.add_argument(
        "identity",
        help="identity_name for the surveillance event (e.g., 'test_early_real')",
    )
    parser.add_argument(
        "end_time",
        help="Event end time in HH:MM format (24-hour). Must be < 17:45.",
    )
    args = parser.parse_args()

    # Validate inputs
    identity = args.identity.strip()
    if not identity:
        print("ERROR: identity cannot be empty")
        sys.exit(1)

    try:
        end_h, end_m = parse_time(args.end_time)
    except ValueError as e:
        print(f"ERROR: {e}")
        sys.exit(1)

    # Validate against cutoff
    end_time_str = f"{end_h:02d}:{end_m:02d}:00"
    if end_time_str >= CUTOFF_TIME:
        print(f"ERROR: end time {end_time_str} must be before cutoff {CUTOFF_TIME}")
        print("       (workday_end 18:00 - tolerance 15min = 17:45:00)")
        sys.exit(1)

    if end_time_str < WORKDAY_START:
        print(f"ERROR: end time {end_time_str} should be >= workday start {WORKDAY_START}")
        sys.exit(1)

    # Check DB exists
    if not DB_PATH.exists():
        print(f"ERROR: surveillance DB not found at {DB_PATH}")
        sys.exit(1)

    # Build today's timestamps
    today = datetime.now().strftime("%Y-%m-%d")
    end_datetime = datetime.strptime(f"{today} {end_time_str}", "%Y-%m-%d %H:%M:%S")

    # Event duration: 30 minutes (arbitrary, represents the last activity segment)
    duration_sec = 30 * 60
    start_datetime = end_datetime - timedelta(seconds=duration_sec)

    start_str = start_datetime.strftime("%Y-%m-%d %H:%M:%S")
    end_str = end_datetime.strftime("%Y-%m-%d %H:%M:%S")

    # Insert single row
    con = sqlite3.connect(str(DB_PATH))
    try:
        cursor = con.execute(
            """
            INSERT INTO surveillance_events
                (timestamp_start, timestamp_end, duration_sec, track_id,
                 identity_name, identity_confidence, identity_source,
                 activity, event_trigger)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                start_str,
                end_str,
                float(duration_sec),
                9999,  # Dummy track_id for manual test
                identity,
                0.90,  # Dummy confidence for test row
                "test_early_leave",
                "Working",
                "session_end",
            ),
        )
        con.commit()
        row_id = cursor.lastrowid
    finally:
        con.close()

    # Print test details
    print()
    print("=" * 70)
    print("EARLY_LEAVE ALERT TEST EVENT INSERTED")
    print("=" * 70)
    print(f"Row ID              : {row_id}")
    print(f"Insert timestamp    : {datetime.now().isoformat()}")
    print(f"Event start         : {start_str}")
    print(f"Event end           : {end_str}")
    print(f"Duration            : {duration_sec} sec ({duration_sec / 60:.0f} min)")
    print(f"Identity            : {identity}")
    print(f"Activity            : Working (session_end)")
    print("=" * 70)
    print()
    print("IMPORTANT: Alert will ONLY fire after 18:00 (workday end).")
    print()
    print("Test procedure:")
    print("  1. Ensure user with surveillance_identity='" + identity + "' exists and is_active=true")
    print("  2. Verify no later surveillance row exists for this identity today")
    print("  3. Wait until system time reaches 18:00:01")
    print("  4. Trigger alert evaluator (scheduler or manual via dashboard/API)")
    print("  5. Watch dashboard for alert broadcast")
    print("  6. Verify alert and cooldown rows in database")
    print()
    print("To delete this test row if needed:")
    print(f"  DELETE FROM surveillance_events WHERE id = {row_id};")
    print()


if __name__ == "__main__":
    main()
