"""
activity_labels.py
==================
Canonical activity label abstraction for the attendance recognition pipeline.

The YOLO activity model produces raw class labels tied to training class
indices.  This module maps those raw labels onto a small, stable set of
canonical labels that the rest of the system (UI, database, alerting) can
depend on without knowing model internals.

Canonical labels
----------------
- "Working"      — productive / on-task behaviour
- "Using Phone"  — phone-related activity (talking, scrolling)
- "Inactive"     — idle, distracted, or asleep

Usage::

    from activity_labels import map_activity
    canonical = map_activity("Talking_With_Phone")   # → "Using Phone"
    canonical = map_activity("Idle")                  # → "Inactive"
    canonical = map_activity("Working")               # → "Working"
    canonical = map_activity("Something unknown")     # → "Unknown"

Do NOT edit ``CANONICAL_ACTIVITIES`` or ``RAW_TO_CANONICAL`` without also
updating the training pipeline label definitions.
"""

from __future__ import annotations

# ── Canonical label set ───────────────────────────────────────────────────────
# Order is significant: used for display and reporting.
CANONICAL_ACTIVITIES: list[str] = ["Working", "Using Phone", "Inactive"]

# ── Raw-model → canonical mapping ─────────────────────────────────────────────
# Keys are the exact strings produced by ACTIVITY_NAMES in attendance_webcam.py.
# Any raw label absent from this dict maps to "Unknown" via map_activity().
RAW_TO_CANONICAL: dict[str, str] = {
    # Phone-related
    "Talking_With_Phone": "Using Phone",
    "Scrolling":          "Using Phone",
    # Inactive / distracted
    "Idle":               "Inactive",
    "Sleeping":           "Inactive",
    # Productive
    "Working":            "Working",
    "Meeting":            "Working", 
}


def map_activity(raw_label: str) -> str:
    """Map a raw YOLO activity label to its canonical form.

    Parameters
    ----------
    raw_label:
        The string produced by ``ACTIVITY_NAMES[class_idx]`` in the pipeline.

    Returns
    -------
    str
        A member of :data:`CANONICAL_ACTIVITIES`, or ``"Unknown"`` when
        *raw_label* is not present in :data:`RAW_TO_CANONICAL`.
    """
    return RAW_TO_CANONICAL.get(raw_label, "Inactive")
