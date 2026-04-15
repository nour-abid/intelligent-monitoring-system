import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { tap } from 'rxjs/operators';

export interface ChatTurn {
  role: 'user' | 'assistant';
  text: string;
}

/** Raw chart dataset returned by the backend after resolving a chartspec. */
export interface ChartData {
  type:    string;
  title:   string;
  labels:  string[];
  values:  number[];
  colors:  string[];
  unit?:   string;  // 'sec' | '%' | 'count'
}

export interface ChatResponse {
  reply?: string;
  error?: string;
  /** Server-resolved chart dataset (null when the reply has no chartspec). */
  chart?: ChartData | null;
}

export interface ReportResponse {
  report?:   string;
  error?:    string;
  /** Structured fallback when AI is temporarily unavailable. */
  fallback?: boolean;
  message?:  string;
  reason?:   string;
}

export interface AiContextResponse {
  identities: string[];
  context: string;
  label: string;
}

@Injectable({ providedIn: 'root' })
export class AiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/ai';

  /** In-memory report cache keyed by the serialized data payload. TTL: 10 min. */
  private readonly _reportCache = new Map<string, { report: string; ts: number }>();
  private readonly CACHE_TTL = 10 * 60 * 1000;

  /**
   * POST /api/ai/chat
   * Sends the user message with optional data context and prior turn history.
   * The identity param narrows chart generation scope to the selected employee.
   */
  sendMessage(
    message: string,
    context: string,
    history: ChatTurn[],
    identity?: string,
    dateStart?: string,
    dateEnd?: string,
  ): Observable<ChatResponse> {
    return this.http.post<ChatResponse>(`${this.base}/chat`, {
      message,
      context: context || undefined,
      history: history.length ? history : undefined,
      identity: identity && identity !== '' ? identity : undefined,
      date_start: dateStart || undefined,
      date_end:   dateEnd   || undefined,
    });
  }

  /**
   * POST /api/ai/report
   * Generates an AI-formulated analytical report from a serialised data snapshot.
   * Results are cached in-memory for 10 minutes to avoid redundant API calls.
   */
  generateReport(data: string): Observable<ReportResponse> {
    const cached = this._reportCache.get(data);
    if (cached && Date.now() - cached.ts < this.CACHE_TTL) {
      return of({ report: cached.report });
    }
    return this.http.post<ReportResponse>(`${this.base}/report`, { data }).pipe(
      tap(res => {
        if (!res.fallback && res.report) {
          this._reportCache.set(data, { report: res.report, ts: Date.now() });
        }
      }),
    );
  }

  /**
   * GET /api/ai/context?identity=...&start=...&end=...
   * Fetches surveillance data formatted as AI context + identity list.
   * Pass start/end (ISO strings) to match the dashboard's active date range.
   */
  getContext(identity?: string, start?: string, end?: string): Observable<AiContextResponse> {
    const params: Record<string, string> = {};
    if (identity) params['identity'] = identity;
    if (start)    params['start']    = start;
    if (end)      params['end']      = end;
    return this.http.get<AiContextResponse>(`${this.base}/context`, { params });
  }
}
