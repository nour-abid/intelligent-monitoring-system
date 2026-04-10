// ── Raw API response shape ────────────────────────────────────────────────────

/** Response from GET /api/monitoring/surveillance/overview */
export interface OverviewResponse {
  /** Total duration in seconds per activity label, sorted descending. */
  totals: Record<string, number>;
  /** Sum of all activity durations in seconds. */
  total_sec: number;
  /** Total number of event rows counted. */
  event_count: number;
  /** Each activity's fractional share of total_sec (0–1). */
  distribution: Record<string, number>;
}

/** Query parameters accepted by the overview endpoint. */
export interface OverviewParams {
  start?: string;            // 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS'. Omit for all dates.
  end?: string;
  include_unknown?: boolean;
  include_triggers?: string[];
}

// ── UI-adapted models ─────────────────────────────────────────────────────────

/**
 * One activity bar / row in the chart.
 * Derived from OverviewResponse by SurveillanceService.toActivityStats().
 */
export interface ActivityStat {
  activity: string;
  total_sec: number;
  /** Fractional share 0–1. */
  share: number;
  /** Human-readable duration string, e.g. '1h 30m'. */
  formatted: string;
}
