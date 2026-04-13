// ── Raw API response shapes ───────────────────────────────────────────────────

/** One activity segment in the timeline. */
export interface TimelineSegment {
  /** 'YYYY-MM-DD HH:MM:SS' local time */
  timestamp_start: string;
  /** 'YYYY-MM-DD HH:MM:SS' local time */
  timestamp_end: string;
  duration_sec: number;
  /** DeepSORT track ID (session-scoped integer) */
  track_id: number;
  activity: string;
  /** ArcFace cosine-similarity score [0, 1] */
  identity_confidence: number;
  /** 'face' | 'reassoc_face_confirmed' | 'reassoc_short_term' | 'manual' | 'none' */
  identity_source: string;
  /** 'activity_change' | 'track_lost' | 'session_end' */
  event_trigger: string;
}

/** Response from GET /api/monitoring/surveillance/identities/{name}/timeline */
export interface TimelineResponse {
  identity: string;
  count: number;
  segments: TimelineSegment[];
}

/** Query parameters accepted by the timeline endpoint. */
export interface TimelineParams {
  start?: string;
  end?: string;
  include_triggers?: string[];
}

/** Response from GET /api/monitoring/surveillance/identities/{name}/summary */
export interface IdentityPersonalSummary {
  working_sec:  number;
  phone_sec:    number;
  inactive_sec: number;
  other_sec:    number;
  total_sec:    number;
  /**
   * Backend-computed: working_sec / (working_sec + phone_sec + inactive_sec) × 100.
   * Integer 0–100, or null when all three are zero.
   */
  focus_score: number | null;
}

/** One day entry in the per-day breakdown response. */
export interface IdentityDayEntry {
  date:         string;   // 'YYYY-MM-DD'
  working_sec:  number;
  phone_sec:    number;
  inactive_sec: number;
  other_sec:    number;
  total_sec:    number;
  focus_score:  number | null;
}

/** Response from GET /api/monitoring/surveillance/identities/{name}/daily */
export interface IdentityDailyResponse {
  days: IdentityDayEntry[];
}
