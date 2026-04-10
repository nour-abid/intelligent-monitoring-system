#!/usr/bin/env python3
"""
test_insert_event.py

Tiny utility for inserting one surveillance event row to test realtime alert
detection and response time.

Usage:
    python test_insert_event.py IDENTITY ACTIVITY DURATION_SEC
    python test_insert_event.py yessine.gargouri Using_Phone 360
    python test_insert_event.py amine.elleuch Inactive 660

Output includes:
    - Insert timestamp (wall clock)
    - Event start/end times
    - Activity & identity
    - Row ID for deletion if needed
"""

import argparse
import sqlite3
import sys
from datetime import datetime, timedelta
from pathlib import Path

DB_PATH = Path(
    r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system\logs\surveillance_events.db"
)

VALID_ACTIVITIES = ["Working", "Meeting", "Inactive", "Using_Phone", "Unknown"]


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Insert one surveillance event row for realtime alert testing."
    )
    parser.add_argument(
        "identity",
        help="identity_name (e.g., yessine.gargouri, amine.elleuch)",
    )
    parser.add_argument(
        "activity",
        help=f"Activity label: {', '.join(VALID_ACTIVITIES)}",
    )
    parser.add_argument(
        "duration",
        type=int,
        help="Duration in seconds (e.g., 360 for 6 min, 660 for 11 min)",
    )
    args = parser.parse_args()

    # Validate
    if args.activity not in VALID_ACTIVITIES:
        print(f"ERROR: activity must be one of {VALID_ACTIVITIES}")
        sys.exit(1)

    if args.duration <= 0:
        print("ERROR: duration_sec must be > 0")
        sys.exit(1)

    if not DB_PATH.exists():
        print(f"ERROR: surveillance DB not found at {DB_PATH}")
        sys.exit(1)

    # Generate timestamps
    now = datetime.now()
    end = now + timedelta(seconds=args.duration)

    # Insert
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
                now.strftime("%Y-%m-%d %H:%M:%S"),
                end.strftime("%Y-%m-%d %H:%M:%S"),
                float(args.duration),
                9999,  # Dummy track_id for manual test
                args.identity,
                0.85,  # Dummy confidence
                "test",
                args.activity,
                "activity_change",
            ),
        )
        con.commit()
        row_id = cursor.lastrowid
    finally:
        con.close()

    # Print test details
    print()
    print("=" * 70)
    print("REALTIME ALERT TEST EVENT INSERTED")
    print("=" * 70)
    print(f"Row ID              : {row_id}")
    print(f"Insert time (NOW)   : {datetime.now().isoformat()}")
    print(f"Event start         : {now.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Event end           : {end.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Duration            : {args.duration} sec ({args.duration / 60:.1f} min)")
    print(f"Identity            : {args.identity}")
    print(f"Activity            : {args.activity}")
    print("=" * 70)
    print()
    print("Watch for alert in the dashboard around this time.")
    print("To delete this test row if needed:")
    print(f"  DELETE FROM surveillance_events WHERE id = {row_id};")
    print()


if __name__ == "__main__":
    main()
