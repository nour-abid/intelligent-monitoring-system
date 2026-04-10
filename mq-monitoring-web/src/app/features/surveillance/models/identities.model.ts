// ── Raw API response shapes ───────────────────────────────────────────────────

/** One identity entry inside IdentitiesResponse. */
export interface IdentityEntry {
  identity_name: string;
  /** Total duration in seconds across all activities. */
  total_sec: number;
  /** Number of event rows for this identity. */
  event_count: number;
  /** Per-activity duration totals (seconds), sorted by descending time. */
  activities: Record<string, number>;
}

/** Response from GET /api/monitoring/surveillance/identities */
export interface IdentitiesResponse {
  identities: IdentityEntry[];
}

/** Query parameters accepted by the identities endpoint. */
export interface IdentitiesParams {
  start?: string;            // Omit for all dates.
  end?: string;
  include_unknown?: boolean;
  include_triggers?: string[];
  identity?: string;
}

// ── UI-adapted model ──────────────────────────────────────────────────────────

/**
 * Flat table row for the identity summary table.
 * Computed by SurveillanceService.toIdentityRows().
 */
export interface IdentityRow extends IdentityEntry {
  /** Activity name that accounts for the most time. */
  top_activity: string;
  /** Human-readable total duration, e.g. '2h 15m'. */
  total_sec_formatted: string;
}
