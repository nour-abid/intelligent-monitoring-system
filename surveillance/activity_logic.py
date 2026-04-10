"""
Activity smoothing logic for Camera-B surveillance.

A raw YOLO detection is only committed as the new activity when it appears
``stable_frames`` consecutive frames in a row.  This filters transient
mis-detections without embedding magic numbers inside other modules.

The smoothing state is stored directly on ``SurveillanceTrackState``
(``pending_activity`` / ``pending_count``) so no extra data structure is
needed, and the logic here stays a pure function.
"""

from __future__ import annotations

from surveillance.state_manager import SurveillanceTrackState


def should_switch_activity(
    state: SurveillanceTrackState,
    detected_activity: str,
    stable_frames: int,
    working_to_inactive_extra: int = 3,
    working_phone_extra: int = 2,
    unknown_identity_inactive_extra: int = 2,
    identity_known: bool = True,
) -> str:
    """Determine the activity to commit to ``state`` this frame.

    The function mutates ``state.pending_activity`` and
    ``state.pending_count`` in-place as part of the smoothing logic.
    It does **not** mutate ``state.current_activity`` – the caller is
    responsible for applying the returned value.

    Parameters
    ----------
    state:
        Mutable track state whose ``pending_*`` fields track the candidate.
    detected_activity:
        Raw activity label produced by the YOLO model for this frame
        (may be ``"Unknown"`` if no detection matched the track).
    stable_frames:
        Minimum consecutive frames the new activity must be detected before
        committing.  Configured via ``activity_stable_frames`` in
        ``settings.yaml``.
    working_to_inactive_extra:
        Additional frames required on top of ``stable_frames`` before a
        Working → Inactive downgrade is committed.  Prevents a brief desk
        pause or a single misclassified frame from immediately flipping a
        genuinely working person to Inactive.

    Returns
    -------
    The activity label that should become (or remain) ``state.current_activity``
    after this frame.

    Algorithm
    ---------
    - ``"Unknown"`` is treated as *no new evidence*: it never challenges the
      current confirmed activity or the accumulated pending challenger.  The
      function returns the current activity immediately and leaves pending
      state untouched.
    - If ``detected_activity`` equals the current confirmed activity, stay on
      it and reset any pending challenger.
    - Otherwise accumulate consecutive hits for the candidate:
        * Same candidate as last frame → increment ``pending_count``.
        * New candidate              → reset to ``pending_count = 1``.
    - The required threshold is ``stable_frames`` for most transitions, but
      ``stable_frames + working_to_inactive_extra`` for Working → Inactive,
      ``stable_frames + working_phone_extra`` for any transition within the
      {Working, Using_Phone} pair (both directions), and
      ``stable_frames + unknown_identity_inactive_extra`` when committing
      Inactive on a track whose identity is still Unknown (regardless of
      current activity).
    - Commit when ``pending_count >= required_threshold``.
    """
    # Unknown = no new evidence; never let it displace a real activity.
    if detected_activity == "Unknown":
        return state.current_activity

    if detected_activity == state.current_activity:
        # Already on this activity – discard any accumulated challenger
        state.pending_activity = detected_activity
        state.pending_count = 0
        return state.current_activity

    if detected_activity == state.pending_activity:
        state.pending_count += 1
    else:
        # New challenger: restart accumulation
        state.pending_activity = detected_activity
        state.pending_count = 1

    # Class-aware threshold:
    #   Working <-> Inactive: downgrade requires extra evidence.
    #   Working <-> Using_Phone: semantically similar postures; both
    #     directions require extra evidence to avoid flicker.
    #   Inactive on unknown-identity tracks: fresh/unintroduced persons are
    #     more likely to be Working but misclassified; require extra evidence
    #     before committing Inactive for them.
    _WORK_PHONE = {"Working", "Using_Phone"}
    required = stable_frames
    if state.current_activity == "Working" and detected_activity == "Inactive":
        required = stable_frames + working_to_inactive_extra
    elif state.current_activity in _WORK_PHONE and detected_activity in _WORK_PHONE:
        required = stable_frames + working_phone_extra
    elif detected_activity == "Inactive" and not identity_known:
        # Fresh / unintroduced track: Inactive is the easiest label to emit
        # spuriously (nearby person, partial view, brief pause).  Require
        # extra evidence before committing it so a genuinely working but
        # not-yet-identified person does not immediately appear as Inactive.
        required = stable_frames + unknown_identity_inactive_extra

    if state.pending_count >= required:
        # Challenger has been stable long enough – commit it
        state.pending_count = 0
        return detected_activity

    # Not yet stable: keep current confirmed activity
    return state.current_activity
