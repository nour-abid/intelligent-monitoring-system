"""
Event-driven alert clip capture for the surveillance pipeline.

Usage from main_surveillance.py
---------------------------------
    from surveillance.clip_writer import ClipManager

    clip_mgr = ClipManager(cfg, video_source)

    # Per frame (after drain):
    clip_mgr.push_frame(frame)

    # Per confirmed track (after final_activity is resolved):
    clip_mgr.on_track_update(
        track_id, identity_name,
        current_activity, prev_activity,
        activity_start_time, frame, now,
    )

    # On track eviction:
    clip_mgr.on_track_lost(track_id)

    # On process shutdown:
    clip_mgr.flush_all()

Architecture contract
---------------------
• Only clips whose suspicious activity persists past the alert threshold
  are saved to disk.  Candidate sessions that stop before the threshold
  are silently discarded and no file is written.
• One shared rolling frame buffer (ring buffer) is maintained per camera.
  It costs at most pre_buffer_sec * fps frame copies at any time.
• When a clip is confirmed, the ring buffer is snapshotted and flushed
  into a freshly opened VideoWriter.  All subsequent frames for that
  track stream directly into the writer.
• Post-buffer: after the suspicious activity stops, the writer stays open
  for post_buffer_sec additional seconds, then closes and saves the file.
• No video data is stored in PostgreSQL — only the saved file path is
  exposed so the backend metadata layer can reference it.
• All public methods are non-throwing; failures are logged and the main
  loop continues unaffected.
"""

from __future__ import annotations

import json
import logging
import re
import time
import urllib.error
import urllib.request
from collections import deque
from datetime import datetime, timezone
from pathlib import Path
from typing import Deque, Dict, List, Optional, Tuple

import cv2

log = logging.getLogger("surveillance.clip_writer")

# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

def _safe_name(name: str) -> str:
    """Sanitise an identity name or label for safe use in a filename."""
    clean = re.sub(r"[^A-Za-z0-9_-]", "_", name).strip("_")
    return clean[:40] or "unknown"


def _derive_camera_id(video_source: object) -> str:
    """Produce a filesystem-safe camera identifier from the video_source value."""
    raw = str(video_source)
    if raw.isdigit():
        return f"cam_{raw}"
    # Strip protocol prefix then replace non-alphanumeric runs with underscores.
    stripped = re.sub(r"^[a-zA-Z]+://", "", raw)
    stripped = re.sub(r"[^A-Za-z0-9]+", "_", stripped).strip("_")[:40]
    return f"cam_{stripped}" if stripped else "cam_0"


# ---------------------------------------------------------------------------
# Rolling frame buffer (one per camera / ClipManager instance)
# ---------------------------------------------------------------------------

class _RollingBuffer:
    """Fixed-size ring buffer of raw BGR frames with timestamps.

    Holds at most ``maxlen`` (frame_copy, epoch_sec) tuples.  The oldest
    entry is evicted automatically when the buffer is full.
    """

    __slots__ = ("_buf",)

    def __init__(self, maxlen: int) -> None:
        self._buf: Deque[Tuple[object, float]] = deque(maxlen=max(1, maxlen))

    def push(self, frame, ts: float) -> None:
        self._buf.append((frame.copy(), ts))

    def snapshot(self) -> List[Tuple[object, float]]:
        """All buffered (frame, ts) pairs in order, oldest first."""
        return list(self._buf)

    def __len__(self) -> int:
        return len(self._buf)


# ---------------------------------------------------------------------------
# Per-track clip session state machine
# ---------------------------------------------------------------------------

class _ClipSession:
    """State machine for one candidate alert clip per track.

    States
    ------
    watching      Suspicious activity started; alert threshold not yet
                  reached.  No VideoWriter is open — the shared ring buffer
                  provides pre-event coverage.

    writing       Threshold exceeded; VideoWriter is open.  Every incoming
                  frame is written directly into the file.

    post_buffer   Activity stopped; writer remains open; counting down
                  post-buffer frames before the final close-and-save.

    done          Terminal state: clip saved (saved_path is set) or
                  discarded (saved_path is None).
    """

    __slots__ = (
        "track_id", "identity_name", "event_type", "threshold_sec",
        "output_path", "fps", "frame_size", "activity_start_time",
        "_state", "_writer", "_post_remaining", "_saved_path",
    )

    def __init__(
        self,
        track_id: int,
        identity_name: str,
        event_type: str,
        threshold_sec: float,
        post_buffer_frames: int,
        output_path: Path,
        fps: float,
        frame_size: Tuple[int, int],
        activity_start_time: float,
    ) -> None:
        self.track_id           = track_id
        self.identity_name      = identity_name
        self.event_type         = event_type
        self.threshold_sec      = threshold_sec
        self.output_path        = output_path
        self.fps                = fps
        self.frame_size         = frame_size
        self.activity_start_time = activity_start_time
        self._state             = "watching"
        self._writer: Optional[cv2.VideoWriter] = None
        self._post_remaining    = post_buffer_frames
        self._saved_path: Optional[str] = None

    # ── Public properties ────────────────────────────────────────────────

    @property
    def is_done(self) -> bool:
        return self._state == "done"

    @property
    def saved_path(self) -> Optional[str]:
        """Absolute path of the written clip, or None if not yet saved."""
        return self._saved_path

    # ── Frame dispatch ───────────────────────────────────────────────────

    def on_frame(self, frame, ts: float, ring_buffer: _RollingBuffer) -> None:
        """Fed every frame while the suspicious activity is ongoing."""
        if self._state == "watching":
            elapsed = ts - self.activity_start_time
            if elapsed >= self.threshold_sec:
                log.info(
                    "[CLIP] track_%d (%s): %.0f s of %s exceeded threshold — "
                    "confirming clip → %s",
                    self.track_id, self.identity_name, elapsed,
                    self.event_type, self.output_path.name,
                )
                self._open_and_flush_pre(ring_buffer)
                if self._state != "done":   # writer opened successfully
                    self._write_frame(frame)
                    self._state = "writing"
        elif self._state == "writing":
            self._write_frame(frame)

    def on_activity_stopped(self) -> None:
        """Called when the alertable activity transitions to something else."""
        if self._state == "watching":
            log.debug(
                "[CLIP] track_%d (%s): %s stopped before threshold — discarding",
                self.track_id, self.identity_name, self.event_type,
            )
            self._discard()
        elif self._state == "writing":
            if self._post_remaining > 0:
                log.debug(
                    "[CLIP] track_%d: activity stopped — writing %d post-buffer frames",
                    self.track_id, self._post_remaining,
                )
                self._state = "post_buffer"
            else:
                self._close_writer()
        # post_buffer or done states need no additional action

    def on_post_frame(self, frame) -> None:
        """Fed each frame during post-buffer (after activity stopped)."""
        if self._state != "post_buffer":
            return
        self._write_frame(frame)
        self._post_remaining -= 1
        if self._post_remaining <= 0:
            self._close_writer()

    def on_track_lost(self) -> None:
        """Called when the DeepSORT track is evicted mid-clip."""
        if self._state in ("writing", "post_buffer"):
            log.info(
                "[CLIP] track_%d lost mid-write — saving partial clip: %s",
                self.track_id, self.output_path.name,
            )
            self._close_writer()
        elif self._state == "watching":
            self._discard()
        # done: already clean

    # ── Private helpers ──────────────────────────────────────────────────

    def _open_and_flush_pre(self, ring_buffer: _RollingBuffer) -> None:
        """Open the VideoWriter and dump all pre-buffer frames into it."""
        try:
            self.output_path.parent.mkdir(parents=True, exist_ok=True)
            fourcc = cv2.VideoWriter_fourcc(*"mp4v")
            writer = cv2.VideoWriter(
                str(self.output_path), fourcc, self.fps, self.frame_size
            )
            if not writer.isOpened():
                raise OSError(f"cv2.VideoWriter failed to open: {self.output_path}")
            self._writer = writer
        except Exception as exc:
            log.error(
                "[CLIP] track_%d: failed to open VideoWriter for %s: %s",
                self.track_id, self.output_path, exc,
            )
            self._state = "done"
            return

        pre_frames = ring_buffer.snapshot()
        for f, _ts in pre_frames:
            self._write_frame(f)
        log.debug(
            "[CLIP] track_%d: flushed %d pre-buffer frames into %s",
            self.track_id, len(pre_frames), self.output_path.name,
        )

    def _write_frame(self, frame) -> None:
        if self._writer is None or not self._writer.isOpened():
            return
        h, w = frame.shape[:2]
        if (w, h) != self.frame_size:
            frame = cv2.resize(frame, self.frame_size)
        try:
            self._writer.write(frame)
        except Exception as exc:
            log.warning("[CLIP] track_%d: frame write error: %s", self.track_id, exc)

    def _close_writer(self) -> None:
        if self._writer is not None and self._writer.isOpened():
            self._writer.release()
            self._writer = None
        if self.output_path.exists() and self.output_path.stat().st_size > 0:
            self._saved_path = str(self.output_path)
            size_kb = self.output_path.stat().st_size / 1024
            log.info(
                "[CLIP] Saved confirmed clip: %s (%.1f KB)",
                self._saved_path, size_kb,
            )
        else:
            log.warning(
                "[CLIP] Writer closed but output file is empty or missing: %s",
                self.output_path,
            )
            self._try_unlink()
        self._state = "done"

    def _discard(self) -> None:
        if self._writer is not None and self._writer.isOpened():
            self._writer.release()
            self._writer = None
        self._try_unlink()
        self._state = "done"

    def _try_unlink(self) -> None:
        try:
            if self.output_path.exists():
                self.output_path.unlink()
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Clip manager — one per camera, wired into the main loop
# ---------------------------------------------------------------------------

# Activities that warrant clip capture, mapped to their threshold config key.
_ALERTABLE_ACTIVITIES = {
    "Using_Phone": "clip_phone_threshold_sec",
    "Inactive":    "clip_inactive_threshold_sec",
}


class ClipManager:
    """Coordinates the rolling frame buffer and all active per-track clip sessions.

    Parameters (from settings.yaml)
    --------------------------------
    clip_capture_enabled      : boolean — disable entirely if False (default True)
    camera_id                 : string  — override derived camera identifier
    clip_output_dir           : string  — root dir for clip files (default "clips")
    clip_capture_fps          : float   — FPS written into the mp4 (default 15)
    clip_pre_buffer_sec       : int     — rolling buffer length in seconds (default 10)
    clip_post_buffer_sec      : float   — seconds recorded after activity stops (default 5)
    clip_phone_threshold_sec  : float   — Using_Phone duration to confirm clip (default 300)
    clip_inactive_threshold_sec: float  — Inactive duration to confirm clip (default 600)
    clip_api_enabled          : boolean — POST metadata to Laravel after save (default True)
    clip_api_url              : string  — Laravel internal clips endpoint URL
    clip_api_token            : string  — X-Internal-Token shared secret
    clip_api_timeout_sec      : float   — per-request timeout (default 3s)
    """

    def __init__(self, cfg: dict, video_source: object) -> None:
        self._fps = float(cfg.get("clip_capture_fps", 15.0))
        pre_sec   = max(1, int(cfg.get("clip_pre_buffer_sec", 10)))
        post_sec  = float(cfg.get("clip_post_buffer_sec", 5.0))
        self._post_buffer_frames = max(0, int(round(post_sec * self._fps)))

        self._thresholds: Dict[str, float] = {
            "Using_Phone": float(cfg.get("clip_phone_threshold_sec",  5 * 60)),
            "Inactive":    float(cfg.get("clip_inactive_threshold_sec", 10 * 60)),
        }

        camera_id_cfg = cfg.get("camera_id", "")
        self._camera_id = (
            str(camera_id_cfg).strip()
            if camera_id_cfg
            else _derive_camera_id(video_source)
        )

        out_root = cfg.get("clip_output_dir", "clips")
        root = Path(out_root)
        if not root.is_absolute():
            # Resolve relative to the project root (one level above this package).
            root = Path(__file__).resolve().parent.parent / root
        self._output_root = root

        maxlen = pre_sec * max(1, int(self._fps))
        self._ring = _RollingBuffer(maxlen=maxlen)
        self._sessions: Dict[int, _ClipSession] = {}

        # ── Metadata API config ───────────────────────────────────────────
        self._api_enabled = bool(cfg.get("clip_api_enabled", True))
        self._api_url     = str(cfg.get("clip_api_url", ""))
        self._api_token   = str(cfg.get("clip_api_token", ""))
        self._api_timeout = float(cfg.get("clip_api_timeout_sec", 3.0))
        self._pre_buffer_sec  = pre_sec
        self._post_buffer_sec = post_sec

        log.info(
            "[CLIP] ClipManager ready | camera=%s fps=%.1f pre=%ds post=%.1fs "
            "phone_thr=%.0fs inactive_thr=%.0fs dir=%s",
            self._camera_id, self._fps, pre_sec, post_sec,
            self._thresholds["Using_Phone"],
            self._thresholds["Inactive"],
            self._output_root,
        )

    # ── Public API ────────────────────────────────────────────────────────

    def push_frame(self, frame) -> None:
        """Push the current frame into the rolling pre-event buffer.

        Must be called once per processed frame, before on_track_update().
        """
        self._ring.push(frame, time.time())

    def on_track_update(
        self,
        track_id: int,
        identity_name: str,
        current_activity: str,
        prev_activity: str,
        activity_start_time: float,
        frame,
        now: float,
    ) -> None:
        """Called each frame for every confirmed track after activity is resolved.

        Parameters
        ----------
        track_id            DeepSORT track ID.
        identity_name       Resolved identity or "Unknown".
        current_activity    Activity label committed this frame (== state.current_activity).
        prev_activity       Activity label from the previous frame.
        activity_start_time Epoch seconds when current_activity started.
        frame               Current BGR frame.
        now                 Current epoch timestamp (time.time()).
        """
        session = self._sessions.get(track_id)

        # ── Activity transition ──────────────────────────────────────────
        if current_activity != prev_activity:
            if session is not None and not session.is_done:
                if session._state == "writing":
                    # Confirmed clip in progress; start post-buffer.
                    session.on_activity_stopped()
                elif session._state == "watching":
                    # Candidate that never reached threshold; discard.
                    session.on_activity_stopped()
                # "post_buffer" or "done" sessions are left to complete naturally.

            # Start a new session if the incoming activity is alertable.
            threshold = self._thresholds.get(current_activity)
            if threshold is not None:
                # Don't start a new session if the old one is still finishing
                # its post-buffer — it will complete within a few frames.
                existing = self._sessions.get(track_id)
                if existing is None or existing.is_done:
                    try:
                        output_path = self._make_output_path(
                            identity_name, current_activity, now
                        )
                        h, w = frame.shape[:2]
                        new_session = _ClipSession(
                            track_id=track_id,
                            identity_name=identity_name,
                            event_type=current_activity,
                            threshold_sec=threshold,
                            post_buffer_frames=self._post_buffer_frames,
                            output_path=output_path,
                            fps=self._fps,
                            frame_size=(w, h),
                            activity_start_time=activity_start_time,
                        )
                        self._sessions[track_id] = new_session
                        log.debug(
                            "[CLIP] track_%d (%s): candidate started for %s "
                            "(threshold %.0f s)",
                            track_id, identity_name, current_activity, threshold,
                        )
                    except Exception as exc:
                        log.warning(
                            "[CLIP] track_%d: failed to start clip session: %s",
                            track_id, exc,
                        )

        # ── Feed current session ─────────────────────────────────────────
        session = self._sessions.get(track_id)
        if session is None or session.is_done:
            return

        try:
            if session._state in ("watching", "writing"):
                session.on_frame(frame, now, self._ring)
            elif session._state == "post_buffer":
                session.on_post_frame(frame)
        except Exception as exc:
            log.warning("[CLIP] track_%d: frame dispatch error: %s", track_id, exc)

        # Clean up sessions that just completed.
        if session.is_done:
            if session.saved_path:
                log.info(
                    "[CLIP] track_%d (%s): confirmed clip ready at %s",
                    session.track_id, session.identity_name, session.saved_path,
                )
                self._report_to_laravel(session)
            del self._sessions[track_id]

    def on_track_lost(self, track_id: int) -> None:
        """Called when a track is evicted — closes any open writer and saves."""
        session = self._sessions.pop(track_id, None)
        if session is None or session.is_done:
            return
        try:
            session.on_track_lost()
        except Exception as exc:
            log.warning("[CLIP] track_%d: error on track_lost: %s", track_id, exc)
        if session.saved_path:
            log.info(
                "[CLIP] track_%d (%s): partial clip saved on track loss → %s",
                session.track_id, session.identity_name, session.saved_path,
            )
            self._report_to_laravel(session)

    def flush_all(self) -> None:
        """Save all open writers on process shutdown; logs each saved path."""
        for tid in list(self._sessions.keys()):
            session = self._sessions.pop(tid, None)
            if session is None or session.is_done:
                continue
            try:
                session.on_track_lost()
            except Exception as exc:
                log.warning("[CLIP] flush_all: track_%d error: %s", tid, exc)
            if session.saved_path:
                log.info(
                    "[CLIP] flush_all: clip saved → %s", session.saved_path,
                )
                self._report_to_laravel(session)

    # ── Private helpers ──────────────────────────────────────────────────

    def _report_to_laravel(self, session: _ClipSession) -> None:
        """POST clip metadata to the Laravel internal API (non-throwing).

        Failures are logged at WARNING level with full response body; the
        surveillance loop is never interrupted.  A failed POST does NOT delete
        the local file — the clip remains on disk for manual reconciliation.

        One automatic retry is attempted on network-level errors (e.g. brief
        connection refusal at startup).  HTTP-level errors (4xx/5xx) are not
        retried since the server actively refused the payload.
        """
        if not self._api_enabled:
            return
        if not self._api_url:
            log.debug("[CLIP] clip_api_url not configured — skipping metadata POST")
            return
        if not self._api_token:
            log.warning("[CLIP] clip_api_token not set — skipping metadata POST")
            return

        file_path = session.saved_path  # already confirmed non-None at call sites
        assert file_path is not None

        # ── Normalize file_path: relative path with forward slashes ──────
        # Avoids Windows backslashes breaking JSON consumers and keeps paths
        # portable relative to the project root.
        try:
            normalized_path = (
                Path(file_path)
                .relative_to(Path(__file__).resolve().parent.parent)
                .as_posix()
            )
        except ValueError:
            # file_path is outside the project root — keep absolute but normalise slashes.
            normalized_path = Path(file_path).as_posix()

        # ── ISO 8601 UTC timestamps (YYYY-MM-DDTHH:MM:SSZ) ───────────────
        # started_at = when the suspicious activity started (ring-buffer anchor).
        # ended_at   = started_at + pre-buffer + post-buffer duration.
        try:
            def _utc_iso(epoch: float) -> str:
                return (
                    datetime.fromtimestamp(epoch, tz=timezone.utc)
                    .strftime("%Y-%m-%dT%H:%M:%SZ")
                )
            started_at = _utc_iso(session.activity_start_time)
            ended_at   = _utc_iso(
                session.activity_start_time
                + self._pre_buffer_sec
                + self._post_buffer_sec
            )
        except Exception as exc:
            log.warning("[CLIP] _report_to_laravel: timestamp error: %s", exc)
            return

        try:
            file_size = Path(file_path).stat().st_size
        except OSError:
            file_size = None

        payload: dict = {
            "camera_id":       self._camera_id,
            "identity_name":   session.identity_name,
            "event_type":      session.event_type,
            "started_at":      started_at,
            "ended_at":        ended_at,
            "duration_sec":    self._pre_buffer_sec + self._post_buffer_sec,
            "file_path":       normalized_path,
            "file_name":       Path(file_path).name,
            "file_size_bytes": file_size,
            "mime_type":       "video/mp4",
            "pre_buffer_sec":  self._pre_buffer_sec,
            "post_buffer_sec": self._post_buffer_sec,
            "clip_status":     "ready",
        }

        body = json.dumps(payload).encode("utf-8")

        def _do_post() -> int:
            """Execute a single POST; returns HTTP status code on success."""
            req = urllib.request.Request(
                self._api_url,
                data=body,
                method="POST",
                headers={
                    "Content-Type":     "application/json",
                    "Accept":           "application/json",
                    "X-Internal-Token": self._api_token,
                },
            )
            with urllib.request.urlopen(req, timeout=self._api_timeout) as resp:
                return resp.status

        # ── Execute with one retry on network-level failures ─────────────
        for attempt in range(2):
            try:
                status = _do_post()
                log.info(
                    "[CLIP] Metadata POSTed to Laravel (HTTP %d, attempt %d): %s",
                    status, attempt + 1, Path(file_path).name,
                )
                return   # success — stop here
            except urllib.error.HTTPError as exc:
                # Server responded but rejected the request — read body for context.
                try:
                    response_body = exc.read().decode("utf-8", errors="replace")[:500]
                except Exception:
                    response_body = "(unreadable)"
                log.warning(
                    "[CLIP] Laravel API HTTP %d for %s (attempt %d): %s | body: %s",
                    exc.code, Path(file_path).name, attempt + 1, exc.reason, response_body,
                )
                return   # HTTP errors are not retried
            except Exception as exc:
                if attempt == 0:
                    log.warning(
                        "[CLIP] POST attempt 1 failed for %s: %s — retrying once…",
                        Path(file_path).name, exc,
                    )
                    time.sleep(0.5)
                else:
                    log.warning(
                        "[CLIP] POST attempt 2 failed for %s: %s — giving up",
                        Path(file_path).name, exc,
                    )

    def _make_output_path(
        self, identity_name: str, event_type: str, ts: float
    ) -> Path:
        """Build a deterministic output path.

        Pattern:
            <output_root>/<camera_id>/<YYYY>/<MM>/<DD>/<identity>_<event>_<HHMMSS>.mp4
        """
        dt       = datetime.fromtimestamp(ts)
        date_dir = (
            self._output_root
            / self._camera_id
            / dt.strftime("%Y")
            / dt.strftime("%m")
            / dt.strftime("%d")
        )
        safe_id  = _safe_name(identity_name)
        safe_evt = _safe_name(event_type)
        ts_str   = dt.strftime("%H%M%S")
        filename = f"{safe_id}_{safe_evt}_{ts_str}.mp4"
        return date_dir / filename
