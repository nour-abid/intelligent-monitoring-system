import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { SurveillanceService } from '../../../../core/services/surveillance.service';
import { TimelineResponse, TimelineParams } from '../../models/timeline.model';
import { EmployeeHighlight, HighlightsParams } from '../../models/highlight.model';
import { TimelineComponent } from '../../components/timeline/timeline.component';
import { ReplayModalComponent } from '../../../../shared/components/replay-modal/replay-modal.component';
import { ActivityChartComponent } from '../../components/activity-chart/activity-chart.component';
import { KpiCardsComponent, KpiCardDef } from '../../components/kpi-cards/kpi-cards.component';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';
import {
  activityColor,
  activityBadgeClass,
} from '../../utils/activity-colors.util';
import { ActivityStat } from '../../models/overview.model';
import { formatDuration, toStartOfDay, toEndOfDay, DatePresetKey, datePreset } from '../../../../core/utils/duration.util';

type LoadState = 'loading' | 'success' | 'error';

/** Derived per-activity totals computed client-side from the loaded segments. */
interface ActivityTotal {
  activity: string;
  total_sec: number;
  segment_count: number;
  share: number;
  formatted: string;
  color: string;
  badgeClass: string;
}

@Component({
  selector: 'app-identity-detail',
  standalone: true,
  imports: [RouterLink, FormsModule, DatePipe, TimelineComponent, DurationPipe, KpiCardsComponent, ActivityChartComponent, ReplayModalComponent],
  templateUrl: './identity-detail.component.html',
  styleUrl: './identity-detail.component.scss',
})
export class IdentityDetailComponent {
  private readonly service = inject(SurveillanceService);

  // ── Route param ─────────────────────────────────────────────────────────
  // Automatically bound from ':name' by withComponentInputBinding() in app.config.ts.
  readonly name = input.required<string>();

  // ── Optional date-range filter ───────────────────────────────────────────
  filterMode:    'overview' | 'preset' | 'custom' = 'overview';
  filterStart    = '';
  filterEnd      = '';
  activePreset: DatePresetKey | '' = '';

  // ── Load state ───────────────────────────────────────────────────────────
  readonly loadState = signal<LoadState>('loading');
  readonly errorMsg  = signal<string>('');

  // ── Raw timeline data ────────────────────────────────────────────────────
  readonly timeline = signal<TimelineResponse | null>(null);

  // ── Highlights (Moments Forts) ───────────────────────────────────────────
  readonly highlights      = signal<EmployeeHighlight[]>([]);
  readonly highlightsState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  // ── Highlight replay ────────────────────────────────────────────────────
  readonly replayAlertId = signal<number | null>(null);
  readonly replayLabel   = signal('');

  // ── Derived signals ──────────────────────────────────────────────────────

  /** Total duration in seconds across all loaded segments. */
  readonly totalSec = computed<number>(() => {
    const t = this.timeline();
    if (!t) return 0;
    return t.segments.reduce((sum, seg) => sum + seg.duration_sec, 0);
  });

  /** Per-activity aggregated totals, sorted descending by time. */
  readonly activityTotals = computed<ActivityTotal[]>(() => {
    const t = this.timeline();
    if (!t) return [];

    const totalSecs = t.segments.reduce((s, seg) => s + seg.duration_sec, 0);
    const secs:   Record<string, number> = {};
    const counts: Record<string, number> = {};
    for (const seg of t.segments) {
      secs[seg.activity]   = (secs[seg.activity]   ?? 0) + seg.duration_sec;
      counts[seg.activity] = (counts[seg.activity] ?? 0) + 1;
    }

    return Object.entries(secs)
      .sort(([, a], [, b]) => b - a)
      .map(([activity, total_sec]) => ({
        activity,
        total_sec,
        segment_count: counts[activity] ?? 0,
        share:         totalSecs > 0 ? total_sec / totalSecs : 0,
        formatted:     formatDuration(total_sec),
        color:         activityColor(activity),
        badgeClass:    activityBadgeClass(activity),
      }));
  });

  /** ActivityStat[] shape expected by ActivityChartComponent — derived from activityTotals. */
  readonly activityStats = computed<ActivityStat[]>(() =>
    this.activityTotals().map((t) => ({
      activity:  t.activity,
      total_sec: t.total_sec,
      share:     t.share,
      formatted: t.formatted,
    }))
  );

  /** Max segment count — normalises the segment-count bar chart. */
  readonly maxSegmentCount = computed<number>(() => {
    const rows = this.activityTotals();
    return rows.length > 0 ? Math.max(...rows.map((r) => r.segment_count)) : 1;
  });

  /** CSS width percentage for the segment-count bar chart. */
  segPct(count: number): string {
    const m = this.maxSegmentCount();
    return m > 0 ? `${((count / m) * 100).toFixed(1)}%` : '0%';
  }

  /** KPI summary cards for the person statistics header. */
  readonly kpiCards = computed<KpiCardDef[]>(() => {
    const tl      = this.timeline();
    const totals  = this.activityTotals();
    const dominant = totals[0];
    if (!tl) return [];
    return [
      {
        label: 'Total Tracked Time',
        value: formatDuration(this.totalSec()),
        sub:   `${tl.count} segment${tl.count !== 1 ? 's' : ''}`,
        icon:  'M12 2v20M2 12h20',
        theme: 'primary',
      },
      {
        label: 'Segments',
        value: String(tl.count),
        sub:   'recorded activity events',
        icon:  'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2',
        theme: 'neutral',
      },
      {
        label: 'Distinct Activities',
        value: String(totals.length),
        sub:   totals.map((a) => a.activity.replace(/_/g, ' ')).join(', ') || '—',
        icon:  'M22 12h-4l-3 9L9 3l-3 9H2',
        theme: 'success',
      },
      {
        label: 'Dominant Activity',
        value: dominant ? dominant.activity.replace(/_/g, ' ') : '—',
        sub:   dominant ? `${(dominant.share * 100).toFixed(1)}% of tracked time` : 'No data',
        icon:  'M18 20V10M12 20V4M6 20v-6',
        theme: 'warning',
      },
    ];
  });

  /** Rule-based behavior insights for this individual — deterministic, no ML. */
  readonly userInsights = computed<Array<{ text: string; severity: 'info' | 'warning' | 'critical' }>>(() => {
    const totals   = this.activityTotals();
    const tl       = this.timeline();
    const result: Array<{ text: string; severity: 'info' | 'warning' | 'critical' }> = [];
    if (!tl || totals.length === 0) return result;

    const find = (act: string) => totals.find(t => t.activity === act);

    // 1. High phone usage
    const phone = find('Using_Phone');
    if (phone && phone.share > 0.25) {
      const severity = phone.share > 0.40 ? 'critical' : 'warning';
      result.push({ text: `High phone usage: ${(phone.share * 100).toFixed(0)}% of observed time (${phone.formatted})`, severity });
    }

    // 2. High inactivity
    const inactive = find('Inactive');
    if (inactive && inactive.share > 0.40) {
      const severity = inactive.share > 0.60 ? 'critical' : 'warning';
      result.push({ text: `High inactivity: ${(inactive.share * 100).toFixed(0)}% of time logged as Inactive (${inactive.formatted})`, severity });
    }

    // 3. Dominant activity imbalance — one activity is > 75% of all time
    const dominant = totals[0];
    if (dominant && dominant.share > 0.75 && totals.length > 1) {
      result.push({ text: `Activity is heavily concentrated: ${dominant.activity.replace(/_/g, ' ')} accounts for ${(dominant.share * 100).toFixed(0)}% of all tracked time`, severity: 'info' });
    }

    // 4. No productive activity at all
    const working = find('Working');
    const meeting = find('Meeting');
    const productiveSec = (working?.total_sec ?? 0) + (meeting?.total_sec ?? 0);
    const total = this.totalSec();
    if (total > 0 && productiveSec / total < 0.10) {
      result.push({ text: 'Very low productive activity — Working and Meeting together represent less than 10% of tracked time', severity: 'critical' });
    }

    // 5. Activity drop over time — compare first vs last quarter of segments
    const segs = tl.segments;
    if (segs.length >= 8) {
      const quarter   = Math.floor(segs.length / 4);
      const earlySegs = segs.slice(0, quarter);
      const recentSegs = segs.slice(-quarter);
      const earlyAvg  = earlySegs.reduce((s, seg) => s + seg.duration_sec, 0) / earlySegs.length;
      const recentAvg = recentSegs.reduce((s, seg) => s + seg.duration_sec, 0) / recentSegs.length;
      if (earlyAvg > 0 && recentAvg < earlyAvg * 0.40) {
        result.push({ text: `Activity intensity dropped significantly in recent sessions compared to earlier ones`, severity: 'warning' });
      }
    }

    return result.slice(0, 5);
  });

  // ── Lifecycle ────────────────────────────────────────────────────────────

  constructor() {
    // Re-fetch whenever the route param changes (e.g. navigating between
    // identities from a future side-panel).
    effect(() => {
      const n = this.name();
      if (n) {
        this.fetchTimeline(n);
        this.fetchHighlights(n);
      }
    });
  }

  // ── Public actions ───────────────────────────────────────────────────────

  setCustomMode(): void {
    this.filterMode   = 'custom';
    this.activePreset = '';
  }

  /** Apply a named date preset and immediately refresh the timeline. */
  setPreset(key: DatePresetKey): void {
    const r = datePreset(key);
    this.filterStart  = r.start;
    this.filterEnd    = r.end;
    this.activePreset = key;
    this.filterMode   = 'preset';
    this.applyFilter();
  }

  onStartChange(v: string): void { this.filterStart = v; this.activePreset = ''; this.filterMode = 'custom'; }
  onEndChange(v: string):   void { this.filterEnd   = v; this.activePreset = ''; this.filterMode = 'custom'; }

  /** Re-run the timeline query with the current filter values. */
  applyFilter(): void {
    this.fetchTimeline(this.name());
    this.fetchHighlights(this.name());
  }

  clearFilter(): void {
    this.filterStart  = '';
    this.filterEnd    = '';
    this.activePreset = '';
    this.filterMode   = 'overview';
    this.fetchTimeline(this.name());
    this.fetchHighlights(this.name());
  }

  /** Download current report as CSV. */
  exportCsv(): void {
    const params: TimelineParams = {};
    if (this.filterStart) params.start = toStartOfDay(this.filterStart);
    if (this.filterEnd)   params.end   = toEndOfDay(this.filterEnd);

    this.service.exportTimelineCsv(this.name(), params).subscribe({
      next: (blob) => {
        // Generate filename with identity and timestamp
        const timestamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
        const filename = `${this.name()}_report_${timestamp}.csv`;

        // Create download link from blob and trigger download
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      },
      error: (err) => {
        console.error('CSV export failed:', err);
        this.errorMsg.set('Failed to export CSV. Please try again.');
      },
    });
  }

  private fetchHighlights(identityName: string): void {
    this.highlightsState.set('loading');
    this.highlights.set([]);

    const params: HighlightsParams = {};
    if (this.filterStart) params.start = this.filterStart;
    if (this.filterEnd)   params.end   = this.filterEnd;

    this.service.getHighlights(identityName, params).subscribe({
      next: (data) => {
        this.highlights.set(data.highlights);
        this.highlightsState.set('success');
      },
      error: () => {
        this.highlightsState.set('error');
      },
    });
  }

  openHighlightReplay(h: EmployeeHighlight): void {
    if (h.replay_alert_id === null) return;
    this.replayLabel.set(`${this.name()} — ${this.highlightIssueLabel(h.dominant_issue)}`);
    this.replayAlertId.set(h.replay_alert_id);
  }

  closeReplay(): void {
    this.replayAlertId.set(null);
  }

  /** Display label for a dominant_issue slug. */
  highlightIssueLabel(issue: string): string {
    switch (issue) {
      case 'phone':        return 'Phone use';
      case 'inactive':     return 'Inactivity';
      case 'late_arrival': return 'Late arrival';
      case 'early_leave':  return 'Early departure';
      default:             return issue.replace(/_/g, ' ');
    }
  }

  private fetchTimeline(identityName: string): void {
    this.loadState.set('loading');
    this.errorMsg.set('');

    const params: TimelineParams = {};
    if (this.filterStart) params.start = toStartOfDay(this.filterStart);
    if (this.filterEnd)   params.end   = toEndOfDay(this.filterEnd);

    this.service.getTimeline(identityName, params).subscribe({
      next: (data) => {
        this.timeline.set(data);
        this.loadState.set('success');
      },
      error: (err: unknown) => {
        const message =
          err instanceof Error
            ? err.message
            : 'Unable to load timeline. Please try again.';
        this.errorMsg.set(message);
        this.loadState.set('error');
      },
    });
  }
}
