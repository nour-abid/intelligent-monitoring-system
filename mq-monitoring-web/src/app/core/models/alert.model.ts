/**
 * Metadata passed into ReplayModalComponent so the Evidence Viewer
 * panel can display identity, event, and timing details alongside
 * the video.
 *
 * All fields are optional so the modal degrades gracefully when only
 * a subset is available (e.g. alert-dropdown context vs. highlight context).
 */
export interface EvidenceMeta {
  /** Recognised identity name, e.g. "Amir". */
  identity_name?: string;
  /**
   * Raw event/alert type slug.
   * Accepted values: 'phone' | 'Using_Phone' | 'inactive' | 'Inactive' |
   *                  'late_arrival' | 'early_leave'.
   */
  event_type?: string;
  /** ISO-8601 timestamp of when the suspicious event began. */
  started_at?: string;
  /** ISO-8601 timestamp of when the event window ended. */
  ended_at?: string;
  /** Accumulated alert duration in minutes. */
  duration_minutes?: number;
  /** Face-recognition confidence in [0, 1]. */
  confidence?: number;
}

export interface AlertItem {
  /** Client-generated UUID for list tracking. */
  id: string;
  /** Server-persisted row ID; set for history items, undefined for live realtime. */
  serverId?: number;
  /** 'inactive' | 'phone' | 'late_arrival' | 'early_leave' */
  type: 'inactive' | 'phone' | 'late_arrival' | 'early_leave';
  /** Surveillance identity name (e.g. "Amir"). */
  identity: string;
  /** Accumulated minutes detected in the evaluation window. */
  duration_minutes: number;
  /** Configured threshold that was crossed. */
  threshold_minutes: number;
  /** ISO-8601 timestamp from the server. */
  timestamp: string;
  /** Whether the user has seen this alert. */
  read: boolean;
}

/** Shape of a single item returned by GET /api/alerts. */
export interface AlertHistoryRow {
  id: number;
  identity_name: string;
  alert_type: string;
  duration_minutes: number;
  threshold_minutes: number;
  fired_at: string;
}

/**
 * Discriminated state for a replay request.
 *   idle       — no request made yet
 *   loading    — waiting for backend / clip generation
 *   ready      — objectURL is available for the <video> element
 *   unavailable — backend returned 404 (no source registered)
 *   error      — network error or clip generation failure
 */
export type ReplayState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ready';       objectUrl: string }
  | { status: 'unavailable'; reason: string }
  | { status: 'error';       reason: string };
