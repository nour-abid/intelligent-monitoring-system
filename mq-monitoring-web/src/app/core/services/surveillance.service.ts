import { inject, Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import {
  OverviewResponse,
  OverviewParams,
  ActivityStat,
} from '../../features/surveillance/models/overview.model';
import {
  IdentitiesResponse,
  IdentitiesParams,
  IdentityEntry,
  IdentityRow,
} from '../../features/surveillance/models/identities.model';
import { TimelineResponse, TimelineParams } from '../../features/surveillance/models/timeline.model';
import { DashboardSummaryResponse, DashboardSummaryParams } from '../../features/surveillance/models/summary.model';
import { HighlightsResponse, HighlightsParams } from '../../features/surveillance/models/highlight.model';
import {
  formatDuration,
} from '../utils/duration.util';

@Injectable({ providedIn: 'root' })
export class SurveillanceService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/monitoring/surveillance';

  // ── Endpoint wrappers ───────────────────────────────────────────────────────

  /** GET /api/monitoring/surveillance/overview */
  getOverview(params: OverviewParams): Observable<OverviewResponse> {
    return this.http.get<OverviewResponse>(`${this.base}/overview`, {
      params: this.toHttpParams(params),
    });
  }

  /** GET /api/monitoring/surveillance/identities → flat IdentityEntry[] */
  getIdentities(params: IdentitiesParams): Observable<IdentityEntry[]> {
    return this.http
      .get<IdentitiesResponse>(`${this.base}/identities`, {
        params: this.toHttpParams(params),
      })
      .pipe(map((r) => r.identities));
  }

  /**
   * GET /api/monitoring/surveillance/identities/{name}/timeline
   * URL-encodes the identity name so names with spaces or special chars work.
   */
  getTimeline(
    identityName: string,
    params: TimelineParams = {},
  ): Observable<TimelineResponse> {
    const encoded = encodeURIComponent(identityName);
    return this.http.get<TimelineResponse>(
      `${this.base}/identities/${encoded}/timeline`,
      { params: this.toHttpParams(params) },
    );
  }

  /**
   * GET /api/monitoring/surveillance/identities/{name}/export/csv
   * Downloads timeline segments as CSV file.
   * Returns blob to enable browser download.
   */
  exportTimelineCsv(
    identityName: string,
    params: TimelineParams = {},
  ): Observable<Blob> {
    const encoded = encodeURIComponent(identityName);
    return this.http.get(
      `${this.base}/identities/${encoded}/export/csv`,
      { params: this.toHttpParams(params), responseType: 'blob' },
    );
  }

  /** GET /api/monitoring/surveillance/summary */
  getSummary(params: DashboardSummaryParams = {}): Observable<DashboardSummaryResponse> {
    return this.http.get<DashboardSummaryResponse>(`${this.base}/summary`, {
      params: this.toHttpParams(params),
    });
  }

  /**
   * GET /api/monitoring/surveillance/identities/{name}/highlights
   * Returns the top "moments forts" for a single employee in the supplied range.
   */
  getHighlights(
    identityName: string,
    params: HighlightsParams = {},
  ): Observable<HighlightsResponse> {
    const encoded = encodeURIComponent(identityName);
    return this.http.get<HighlightsResponse>(
      `${this.base}/identities/${encoded}/highlights`,
      { params: this.toHttpParams(params) },
    );
  }

  // ── Response → UI model adapters ───────────────────────────────────────────

  /**
   * Convert OverviewResponse into a sorted ActivityStat array suitable
   * for the activity chart component.  Already sorted descending from API.
   */
  toActivityStats(response: OverviewResponse): ActivityStat[] {
    return Object.entries(response.totals).map(([activity, total_sec]) => ({
      activity,
      total_sec,
      share: response.distribution[activity] ?? 0,
      formatted: formatDuration(total_sec),
    }));
  }

  /**
   * Convert raw IdentityEntry[] to IdentityRow[] with derived display fields.
   * Results are already sorted by descending total_sec (from Laravel).
   */
  toIdentityRows(entries: IdentityEntry[]): IdentityRow[] {
    return entries.map((entry) => {
      const topActivity =
        Object.keys(entry.activities)[0] ?? 'Unknown';
      return {
        ...entry,
        top_activity: topActivity,
        total_sec_formatted: formatDuration(entry.total_sec),
      };
    });
  }

  // ── Private helpers ─────────────────────────────────────────────────────────

  /**
   * Convert a plain params object to Angular HttpParams.
   * Arrays are serialised as repeated keys: include_triggers[]=x&include_triggers[]=y
   * (matching Laravel's default array query string convention).
   */
  private toHttpParams(
    params: object,
  ): HttpParams {
    let hp = new HttpParams();
    for (const [key, value] of Object.entries(params as Record<string, unknown>)) {
      if (value === undefined || value === null || value === '') continue;
      if (Array.isArray(value)) {
        value.forEach((v) => {
          hp = hp.append(`${key}[]`, String(v));
        });
      } else if (typeof value === 'boolean') {
        hp = hp.set(key, value ? '1' : '0');
      } else {
        hp = hp.set(key, String(value));
      }
    }
    return hp;
  }
}
