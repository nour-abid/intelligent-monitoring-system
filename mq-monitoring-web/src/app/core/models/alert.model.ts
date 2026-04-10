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
