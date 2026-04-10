#!/usr/bin/env python3
"""
Standalone test helper for inserting late attendance check_in records.
Used to safely test the late_arrival alert feature without modifying production code.

This script inserts a single check_in row into the attendance_events table.
Database and schema remain untouched; only new rows are inserted.
"""
import sqlite3
import sys
import time
from datetime import datetime
from pathlib import Path


def main():
    # Parse command-line arguments
    if len(sys.argv) < 2:
        print("Usage: python test_insert_attendance.py <person> [datetime]")
        print()
        print("Arguments:")
        print("  person    Required. Person identifier (e.g., 'Amine elleuch')")
        print("  datetime  Optional. Format: YYYY-MM-DD HH:MM:SS")
        print("            Defaults to today at 08:30:00 if omitted")
        print()
        print("Examples:")
        print("  python test_insert_attendance.py 'Amine elleuch'")
        print("  python test_insert_attendance.py 'Amine elleuch' '2026-03-27 08:30:00'")
        sys.exit(1)
    
    person = sys.argv[1]
    datetime_str = sys.argv[2] if len(sys.argv) > 2 else None
    
    # Use default datetime if not provided
    if datetime_str is None:
        datetime_str = datetime.now().strftime("%Y-%m-%d") + " 08:30:00"
    
    # Parse and validate datetime
    try:
        dt = datetime.strptime(datetime_str, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        print(f"Error: Invalid datetime format.")
        print(f"  Expected: YYYY-MM-DD HH:MM:SS")
        print(f"  Got:      {datetime_str}")
        sys.exit(1)
    
    # Convert to unix timestamp
    ts = int(time.mktime(dt.timetuple()))
    
    # Locate attendance database (relative to script directory)
    db_path = Path(__file__).parent / "data" / "db" / "attendances.db"
    
    if not db_path.exists():
        print(f"Error: Attendance database not found.")
        print(f"  Expected path: {db_path}")
        sys.exit(1)
    
    # Insert record into database
    try:
        conn = sqlite3.connect(str(db_path))
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO attendance_events (ts, ts_text, person, event) VALUES (?, ?, ?, ?)",
            (ts, datetime_str, person, "check_in")
        )
        conn.commit()
        conn.close()
    except sqlite3.Error as e:
        print(f"Error: Failed to insert record into database.")
        print(f"  Details: {e}")
        sys.exit(1)
    
    # Print success message with inserted values
    print(f"✓ Inserted check_in record:")
    print(f"  person:    {person}")
    print(f"  event:     check_in")
    print(f"  ts_text:   {datetime_str}")
    print(f"  ts:        {ts}")


if __name__ == "__main__":
    main()
