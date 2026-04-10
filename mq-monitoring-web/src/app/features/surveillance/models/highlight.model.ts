/**
 * A single highlight moment for an employee.
 *
 * Returned by GET /api/monitoring/surveillance/identities/{name}/highlights
 */
export interface EmployeeHighlight {
  /** ISO-8601 timestamp of the first alert in the cluster. */
  timestamp: string;
  /** ISO-8601 start of the enriched activity window (pre-buffer included). */
  window_start: string;
  /** ISO-8601 end of the enriched activity window (post-buffer included). */
  window_end: string;
  /** Composite score — higher = more problematic. */
  score: number;
  /** Slug of the most impactful issue, e.g. 'phone' | 'inactive' | 'late_arrival' | 'early_leave'. */
  dominant_issue: string;
  /** One-line human-readable summary label. */
  summary: string;
  /** Number of alerts in the cluster. */
  alert_count: number;
  /** Server-side behavior_alert ids in this cluster. */
  alert_ids: number[];
  /** Total phone-use duration in this window (minutes). */
  phone_duration_min: number;
  /** Total inactivity duration in this window (minutes). */
  inactive_duration_min: number;
  /** True when at least one alert in the cluster has a registered replay source. */
  replay_available: boolean;
  /** The alert_id to use for replay streaming (null when replay_available is false). */
  replay_alert_id: number | null;
}

export interface HighlightsResponse {
  identity: string;
  period: { start: string; end: string };
  highlights: EmployeeHighlight[];
}

export interface HighlightsParams {
  start?: string;
  end?: string;
}
