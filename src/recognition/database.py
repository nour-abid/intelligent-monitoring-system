import sqlite3
from datetime import datetime
import time
from config import config
from logger import logger

def today_str():
    return datetime.now().strftime("%Y-%m-%d")

def init_db():
    db_path = config['paths']['db_path']
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(db_path)
    cur = conn.cursor()
    cur.execute("PRAGMA journal_mode=WAL;")
    cur.execute("PRAGMA synchronous=NORMAL;")
    cur.execute("""
    CREATE TABLE IF NOT EXISTS attendance_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts REAL NOT NULL,
        ts_text TEXT NOT NULL,
        person TEXT NOT NULL,
        event TEXT NOT NULL
    )
    """)
    cur.execute("""
    CREATE UNIQUE INDEX IF NOT EXISTS uniq_checkin_per_day
    ON attendance_events(person, event, substr(ts_text, 1, 10))
    """)
    conn.commit()
    return conn

def load_today_checkins(conn):
    cur = conn.cursor()
    cur.execute("""
        SELECT person, MIN(ts_text) AS first_time
        FROM attendance_events
        WHERE event='check_in' AND substr(ts_text,1,10)=?
        GROUP BY person
    """, (today_str(),))
    rows = cur.fetchall()
    present_set = set()
    checkin_time = {}
    for person, ts_text in rows:
        present_set.add(person)
        checkin_time[person] = ts_text
    if rows:
        logger.info(f"Loaded {len(rows)} check-ins for today from DB.")
    return present_set, checkin_time

def get_today_checkin_time(conn, person: str):
    cur = conn.cursor()
    cur.execute("""
        SELECT MIN(ts_text)
        FROM attendance_events
        WHERE person=? AND event='check_in' AND substr(ts_text,1,10)=?
    """, (person, today_str()))
    row = cur.fetchone()
    return row[0] if row and row[0] else None

def log_checkin_once_per_day(conn, person: str):
    now = time.time()
    now_text = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    cur = conn.cursor()
    try:
        cur.execute(
            "INSERT INTO attendance_events (ts, ts_text, person, event) VALUES (?, ?, ?, ?)",
            (now, now_text, person, "check_in"),
        )
        conn.commit()
        logger.info(f"{now_text} | {person} -> check_in")
        return now_text
    except sqlite3.IntegrityError:
        existing = get_today_checkin_time(conn, person)
        logger.info(f"{person} already checked-in today (skipped)")
        return existing or now_text