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
