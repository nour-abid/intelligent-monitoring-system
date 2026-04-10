"""
Manual test for DailyIdentityStore.
====================================

Run from the project root:

    python test_daily_identity.py

What this script verifies:
  1. DB is created automatically (no pre-existing file required)
  2. A fake embedding round-trips through BLOB storage with bit-exact fidelity
  3. Two different employees can coexist for the same day
  4. A second add_identity call for the same employee replaces the first (UPSERT)
  5. get_identities_for_today() returns only present employees
  6. cleanup_old_entries() removes old rows and leaves today's alone
  7. The store handles an invalid employee_id gracefully (no crash)
  8. Confidence and checkin_ts fields are preserved exactly
"""

from __future__ import annotations

import logging
import sys
import time
from pathlib import Path

import numpy as np

# ---------------------------------------------------------------------------
# Path setup so this script works from the project root
# ---------------------------------------------------------------------------
_ROOT = Path(__file__).resolve().parent
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))

from surveillance.shared.daily_identity_store import DailyIdentityStore, DailyProfile

logging.basicConfig(
    level=logging.DEBUG,
    format="%(asctime)s [%(levelname)s] %(name)s — %(message)s",
)
log = logging.getLogger("test_daily_identity")

# Use an isolated test database — never touches the real one
_TEST_DB = _ROOT / "surveillance" / "data" / "_test_daily_identity.db"


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _fresh_store() -> DailyIdentityStore:
    """Return a store backed by the test DB (created fresh each test run)."""
    if _TEST_DB.exists():
        _TEST_DB.unlink()
    return DailyIdentityStore(db_path=_TEST_DB)


def _random_embedding(dim: int = 512) -> np.ndarray:
    """Return a random L2-normalised float32 embedding."""
    v = np.random.randn(dim).astype(np.float32)
    return v / (np.linalg.norm(v) + 1e-10)


def _assert(condition: bool, msg: str) -> None:
    if not condition:
        log.error("FAIL — %s", msg)
        sys.exit(1)
    log.info("PASS — %s", msg)


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

def test_round_trip_embedding() -> None:
    """Embedding stored and retrieved must be bit-exact."""
    store = _fresh_store()
    emb_in = _random_embedding()
    store.add_identity("Amir", emb_in, confidence=0.82)

    profiles = store.get_identities_for_today()
    _assert("Amir" in profiles, "Amir profile is present after add_identity")

    emb_out = profiles["Amir"].embedding
    _assert(
        np.array_equal(emb_in, emb_out),
        "Embedding round-trips with bit-exact fidelity",
    )
    _assert(
        emb_out.dtype == np.float32,
        "Retrieved embedding has dtype float32",
    )
    _assert(
        emb_out.shape == (512,),
        f"Retrieved embedding has shape (512,), got {emb_out.shape}",
    )
    store.close()


def test_confidence_and_timestamp_preserved() -> None:
    """Confidence and checkin_ts fields are stored and returned correctly."""
    store = _fresh_store()
    emb = _random_embedding()
    store.add_identity("Nour", emb, confidence=0.7531)

    profiles = store.get_identities_for_today()
    p = profiles["Nour"]

    _assert(
        abs(p.confidence - 0.7531) < 1e-6,
        f"Confidence preserved (expected 0.7531, got {p.confidence})",
    )
    _assert(
        len(p.checkin_ts) == 19,  # "YYYY-MM-DD HH:MM:SS"
        f"checkin_ts format is YYYY-MM-DD HH:MM:SS (got {p.checkin_ts!r})",
    )
    _assert(
        p.employee_id == "Nour",
        "employee_id field matches",
    )
    store.close()


def test_multiple_employees() -> None:
    """Two different employees coexist for the same day."""
    store = _fresh_store()
    store.add_identity("Amir", _random_embedding(), confidence=0.80)
    store.add_identity("Nour", _random_embedding(), confidence=0.75)

    profiles = store.get_identities_for_today()
    _assert(len(profiles) == 2, "Two profiles returned for two employees")
    _assert("Amir" in profiles, "Amir is present")
    _assert("Nour" in profiles, "Nour is present")
    store.close()


def test_upsert_replaces_earlier_checkin() -> None:
    """A second add_identity for the same employee replaces the first."""
    store = _fresh_store()
    emb1 = _random_embedding()
    emb2 = _random_embedding()

    store.add_identity("Amir", emb1, confidence=0.70)
    time.sleep(0.05)  # ensure created_at differs
    store.add_identity("Amir", emb2, confidence=0.88)

    profiles = store.get_identities_for_today()
    _assert(len(profiles) == 1, "Only one row for Amir (UPSERT replaced)")

    p = profiles["Amir"]
    _assert(
        abs(p.confidence - 0.88) < 1e-6,
        f"Latest confidence (0.88) is stored, not the first (0.70). Got {p.confidence}",
    )
    _assert(
        np.array_equal(p.embedding, emb2),
        "Latest embedding (emb2) is stored, not the first",
    )
    store.close()


def test_empty_result_when_no_checkins() -> None:
    """get_identities_for_today returns empty dict when no check-ins today."""
    store = _fresh_store()
    profiles = store.get_identities_for_today()
    _assert(len(profiles) == 0, "Empty dict when no check-ins today")
    store.close()


def test_cleanup_removes_old_rows() -> None:
    """cleanup_old_entries removes yesterday's rows; today's remain."""
    from datetime import date, timedelta

    store = _fresh_store()

    # Manually insert a fake "yesterday" row directly via SQL (bypasses today's
    # date logic in add_identity, which always uses date.today()).
    yesterday = (date.today() - timedelta(days=1)).isoformat()
    fake_emb = _random_embedding().tobytes()
    conn = store._connect()
    conn.execute(
        "INSERT INTO daily_profiles (employee_id, date, embedding, confidence, created_at) "
        "VALUES (?, ?, ?, ?, ?)",
        ("OldEmployee", yesterday, fake_emb, 0.60, "2000-01-01 08:00:00"),
    )
    conn.commit()

    # Add a today row normally
    store.add_identity("TodayEmployee", _random_embedding(), confidence=0.77)

    # Verify both are present before cleanup
    cur = conn.execute("SELECT COUNT(*) FROM daily_profiles")
    _assert(cur.fetchone()[0] == 2, "2 rows present before cleanup")

    # Cleanup
    deleted = store.cleanup_old_entries(days=1)
    _assert(deleted == 1, f"cleanup_old_entries deleted 1 old row (got {deleted})")

    # Only today's row should remain
    profiles = store.get_identities_for_today()
    _assert("TodayEmployee" in profiles, "Today's employee is still present")
    _assert("OldEmployee" not in profiles, "Yesterday's employee is removed")
    store.close()


def test_invalid_employee_id_does_not_crash() -> None:
    """An empty or whitespace employee_id is skipped without exception."""
    store = _fresh_store()
    try:
        store.add_identity("", _random_embedding(), confidence=0.5)
        store.add_identity("   ", _random_embedding(), confidence=0.5)
    except Exception as exc:
        _assert(False, f"Unexpected exception for invalid employee_id: {exc}")
    profiles = store.get_identities_for_today()
    _assert(len(profiles) == 0, "No profiles inserted for invalid employee_ids")
    store.close()


def test_non_512d_embedding_stored_correctly() -> None:
    """Embeddings with non-standard shapes are flattened before storage."""
    store = _fresh_store()
    # 2D array (e.g. (1, 512) from a batched inference output)
    emb_2d = _random_embedding(512).reshape(1, 512)
    store.add_identity("BatchEmb", emb_2d, confidence=0.65)

    profiles = store.get_identities_for_today()
    _assert("BatchEmb" in profiles, "BatchEmb profile present")
    _assert(
        profiles["BatchEmb"].embedding.shape == (512,),
        f"2D input flattened to (512,) on storage (got {profiles['BatchEmb'].embedding.shape})",
    )
    store.close()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main() -> None:
    log.info("=" * 60)
    log.info("DailyIdentityStore — manual test suite")
    log.info("Test DB: %s", _TEST_DB)
    log.info("=" * 60)

    tests = [
        test_round_trip_embedding,
        test_confidence_and_timestamp_preserved,
        test_multiple_employees,
        test_upsert_replaces_earlier_checkin,
        test_empty_result_when_no_checkins,
        test_cleanup_removes_old_rows,
        test_invalid_employee_id_does_not_crash,
        test_non_512d_embedding_stored_correctly,
    ]

    for t in tests:
        log.info("--- %s ---", t.__name__)
        t()

    # Clean up the test DB file
    if _TEST_DB.exists():
        _TEST_DB.unlink()
        log.debug("Test DB removed: %s", _TEST_DB)

    log.info("=" * 60)
    log.info("All tests passed.")
    log.info("=" * 60)


if __name__ == "__main__":
    main()
