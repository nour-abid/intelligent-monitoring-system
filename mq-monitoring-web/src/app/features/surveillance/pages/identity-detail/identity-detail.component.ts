import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { SurveillanceService } from '../../../../core/services/surveillance.service';
import { ChatContextService } from '../../../../core/services/chat-context.service';
import { EvidenceMeta } from '../../../../core/models/alert.model';
import { TimelineResponse, TimelineParams, IdentityPersonalSummary, IdentityDayEntry, IdentityDailyResponse } from '../../models/timeline.model';
import { EmployeeHighlight, HighlightsParams } from '../../models/highlight.model';
import { TimelineComponent } from '../../components/timeline/timeline.component';
import { ReplayModalComponent } from '../../../../shared/components/replay-modal/replay-modal.component';
import { AiReportModalComponent } from '../../../../shared/components/ai-report-modal/ai-report-modal.component';
import { ActivityChartComponent } from '../../components/activity-chart/activity-chart.component';
import { KpiCardsComponent, KpiCardDef } from '../../components/kpi-cards/kpi-cards.component';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';
import {
  activityColor,
  activityBadgeClass,
} from '../../utils/activity-colors.util';
import { ActivityStat } from '../../models/overview.model';
import { formatDuration, toStartOfDay, toEndOfDay, DatePresetKey, datePreset } from '../../../../core/utils/duration.util';
import { AuthService } from '../../../../core/services/auth.service';
import { SmartGuidanceComponent, GuidanceMessage } from '../../components/smart-guidance/smart-guidance.component';
import { PerformanceCoachComponent } from '../../components/performance-coach/performance-coach.component';

type LoadState = 'loading' | 'success' | 'error';
type MetricKey = 'working_time' | 'phone_usage' | 'inactivity' | 'focus_score';

/** Per-day metric aggregates derived from timeline segments for the viewer time-series. */
interface DayMetric {
  date:         string;
  working_sec:  number;
  phone_sec:    number;
  inactive_sec: number;
  total_sec:    number;
  focus_score:  number;
}

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
  imports: [RouterLink, FormsModule, DatePipe, TimelineComponent, DurationPipe, KpiCardsComponent, ActivityChartComponent, ReplayModalComponent, SmartGuidanceComponent, PerformanceCoachComponent, AiReportModalComponent],
  templateUrl: './identity-detail.component.html',
  styleUrl: './identity-detail.component.scss',
})
export class IdentityDetailComponent {
  private readonly service     = inject(SurveillanceService);
  private readonly authService = inject(AuthService);
  private readonly ctxSvc      = inject(ChatContextService);

  readonly role = computed(() => this.authService.user()?.role ?? 'viewer');

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
  readonly replayMeta    = signal<EvidenceMeta | null>(null);

  // ── AI report ───────────────────────────────────────────────────────
  readonly aiReportOpen       = signal(false);
  readonly aiReportData       = signal('');
  readonly aiReportTitle      = signal('');
  /** Remaining cooldown seconds after a report attempt. Button disabled while > 0. */
  readonly aiReportCooldownSec = signal(0);
  private _cooldownTimer: ReturnType<typeof setInterval> | null = null;

  // ── Viewer time-series metric toggle ─────────────────────────────────────
  readonly selectedMetric = signal<MetricKey>('working_time');

  // ── Viewer backend-aggregated data ─────────────────────────────────
  /** Populated for viewer role only; null until first successful fetch. */
  readonly personalSummary = signal<IdentityPersonalSummary | null>(null);
  /** Populated for viewer role only; null until first successful fetch. */
  readonly personalDaily = signal<IdentityDayEntry[] | null>(null);

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

  /** ActivityStat[] shape expected by ActivityChartComponent — derived from activityTotals.
   *  "Meeting" is excluded: that activity no longer exists in the system. */
  readonly activityStats = computed<ActivityStat[]>(() =>
    this.activityTotals()
      .filter((t) => t.activity !== 'Meeting')
      .map((t) => ({
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

  /** KPI summary cards — role-aware. Viewer sees personal metrics; admin/supervisor see operational counts. */
  readonly kpiCards = computed<KpiCardDef[]>(() => {
    const tl      = this.timeline();
    const totals  = this.activityTotals();
    const dominant = totals[0];
    if (!tl) return [];

    if (this.role() === 'viewer') {
      const summary     = this.personalSummary();
      const workSec     = summary?.working_sec  ?? 0;
      const phoneSec    = summary?.phone_sec    ?? 0;
      const inactiveSec = summary?.inactive_sec ?? 0;
      const total       = summary?.total_sec    ?? 0;
      // Use backend-computed focus_score; fall back to '—' while loading.
      const focusScore  = summary?.focus_score ?? null;
      return [
        {
          label: 'Working Time',
          value: formatDuration(workSec),
          sub:   total > 0 ? `${((workSec / total) * 100).toFixed(0)}% of tracked time` : '—',
          icon:  'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
          theme: 'primary',
        },
        {
          label: 'Phone Usage',
          value: formatDuration(phoneSec),
          sub:   total > 0 ? `${((phoneSec / total) * 100).toFixed(0)}% of tracked time` : 'None detected',
          icon:  'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.15 12a19.79 19.79 0 0 1 3.07-8.63A2 2 0 0 1 9 2h2a2 2 0 0 1 2 1.72c.12.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L12.09 9a16 16 0 0 0 6.91 6.91l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.58 2.81.7A2 2 0 0 1 22 16.92z',
          theme: phoneSec > 0 && total > 0 && (phoneSec / total) > 0.25 ? 'warning' : 'neutral',
        },
        {
          label: 'Inactivity',
          value: formatDuration(inactiveSec),
          sub:   total > 0 ? `${((inactiveSec / total) * 100).toFixed(0)}% of tracked time` : 'None detected',
          icon:  'M10 9v6m4-6v6',
          theme: inactiveSec > 0 && total > 0 && (inactiveSec / total) > 0.40 ? 'warning' : 'neutral',
        },
        {
          label: 'Focus Score',
          value: focusScore !== null ? `${focusScore}%` : '—',
          sub:   focusScore === null ? 'No data'
               : focusScore >= 70    ? 'Strong focus pattern'
               : focusScore >= 40    ? 'Moderate focus'
               :                      'Focus needs attention',
          icon:  'M22 12h-4l-3 9L9 3l-3 9H2',
          theme: focusScore === null ? 'neutral'
               : focusScore >= 70   ? 'success'
               : focusScore >= 40   ? 'neutral'
               :                     'warning',
        },
      ];
    }

    // Admin / superviseur — operational view with segment counts
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

  /** Daily activity aggregates for the viewer time-series chart.
   *  Uses backend-computed data when available (viewer), else derives from segments. */
  readonly dailyMetrics = computed<DayMetric[]>(() => {
    const beDays = this.personalDaily();
    if (beDays !== null) {
      // Viewer path: use backend-computed data directly.
      return beDays.map(d => ({
        date:         d.date,
        working_sec:  d.working_sec,
        phone_sec:    d.phone_sec,
        inactive_sec: d.inactive_sec,
        total_sec:    d.total_sec,
        focus_score:  d.focus_score ?? 0,
      }));
    }
    // Admin / supervisor path: derive from segments (no backend daily call).
    const t = this.timeline();
    if (!t) return [];
    const byDay: Record<string, DayMetric> = {};
    for (const seg of t.segments) {
      const date = seg.timestamp_start.slice(0, 10);
      if (!byDay[date]) {
        byDay[date] = { date, working_sec: 0, phone_sec: 0, inactive_sec: 0, total_sec: 0, focus_score: 0 };
      }
      const d = byDay[date];
      d.total_sec += seg.duration_sec;
      if (seg.activity === 'Working' || seg.activity === 'Meeting') {
        d.working_sec += seg.duration_sec;
      } else if (seg.activity === 'Using_Phone') {
        d.phone_sec += seg.duration_sec;
      } else if (seg.activity === 'Inactive') {
        d.inactive_sec += seg.duration_sec;
      }
    }
    return Object.values(byDay)
      .sort((a, b) => a.date.localeCompare(b.date))
      .map(d => ({
        ...d,
        focus_score: d.total_sec > 0
          ? Math.max(0, Math.min(100, Math.round((d.working_sec / d.total_sec) * 100 - (d.phone_sec / d.total_sec) * 50)))
          : 0,
      }));
  });

  /** Unit label for the currently selected time-series metric. */
  readonly metricUnit = computed<string>(() => {
    const units: Record<MetricKey, string> = {
      working_time: 'h', phone_usage: 'min', inactivity: 'min', focus_score: '%',
    };
    return units[this.selectedMetric()];
  });

  /** SVG coordinate points for the time-series polyline. Plotting area: x 44–572, y 16–156. */
  readonly timeSeriesData = computed<Array<{ x: number; y: number; value: number; date: string }>>(() => {
    const days   = this.dailyMetrics();
    const metric = this.selectedMetric();
    if (!days.length) return [];
    const values = days.map(d => this.metricRawValue(d, metric));
    const maxVal = Math.max(...values, 0.001);
    const W = 528, H = 140, PAD_L = 44, PAD_T = 16;
    return days.map((d, i) => {
      const x = days.length === 1 ? PAD_L + W / 2 : PAD_L + (i / (days.length - 1)) * W;
      const y = PAD_T + H - (values[i] / maxVal) * H;
      return { x, y, value: values[i], date: d.date };
    });
  });

  /** Points string for SVG <polyline points="…">. */
  readonly timeSeriesPolyline = computed<string>(() =>
    this.timeSeriesData().map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
  );

  /** Y-axis max label for the time-series chart. */
  readonly timeSeriesMaxLabel = computed<string>(() => {
    const days   = this.dailyMetrics();
    const metric = this.selectedMetric();
    if (!days.length) return '0';
    const max  = Math.max(...days.map(d => this.metricRawValue(d, metric)));
    const unit = this.metricUnit();
    return `${max % 1 === 0 ? max : max.toFixed(1)}${unit}`;
  });

  /** Rule-based behavior insights for this individual — deterministic, no ML.
   *  Viewer branch uses backend focus_score for consistency. */
  readonly userInsights = computed<Array<{ text: string; severity: 'info' | 'warning' | 'critical' }>>(() => {
    const totals   = this.activityTotals();
    const tl       = this.timeline();
    const isViewer = this.role() === 'viewer';
    const result: Array<{ text: string; severity: 'info' | 'warning' | 'critical' }> = [];
    if (!tl || totals.length === 0) return result;

    const find = (act: string) => totals.find(t => t.activity === act);

    if (isViewer) {
      // ── Viewer insights: use backend summary / daily for consistency ──
      const summary = this.personalSummary();
      const days    = this.personalDaily();

      if (summary) {
        const denom   = summary.working_sec + summary.phone_sec + summary.inactive_sec;
        const phoneShare    = denom > 0 ? summary.phone_sec    / denom : 0;
        const inactiveShare = denom > 0 ? summary.inactive_sec / denom : 0;
        const score         = summary.focus_score;

        // 1. Low focus score
        if (score !== null && score < 40) {
          result.push({
            text: `Your Focus Score is ${score}% — working time is low relative to phone use and inactivity.`,
            severity: score < 20 ? 'critical' : 'warning',
          });
        } else if (score !== null && score >= 70) {
          result.push({
            text: `Strong Focus Score of ${score}% — keep it up!`,
            severity: 'info',
          });
        }

        // 2. High phone usage
        if (phoneShare > 0.25) {
          result.push({
            text: `Phone usage is ${(phoneShare * 100).toFixed(0)}% of your core tracked time (${formatDuration(summary.phone_sec)}).`,
            severity: phoneShare > 0.40 ? 'critical' : 'warning',
          });
        }

        // 3. High inactivity
        if (inactiveShare > 0.40) {
          result.push({
            text: `Inactivity accounts for ${(inactiveShare * 100).toFixed(0)}% of your core tracked time (${formatDuration(summary.inactive_sec)}).`,
            severity: inactiveShare > 0.60 ? 'critical' : 'warning',
          });
        }
      }

      // 4. Period-over-period focus trend (requires at least 4 days)
      if (days && days.length >= 4) {
        const half        = Math.floor(days.length / 2);
        const earlyDays   = days.slice(0, half).filter(d => d.focus_score !== null);
        const recentDays  = days.slice(-half).filter(d => d.focus_score !== null);
        if (earlyDays.length && recentDays.length) {
          const earlyAvg  = earlyDays.reduce((s, d) => s + (d.focus_score ?? 0), 0) / earlyDays.length;
          const recentAvg = recentDays.reduce((s, d) => s + (d.focus_score ?? 0), 0) / recentDays.length;
          const delta     = recentAvg - earlyAvg;
          if (delta <= -15) {
            result.push({
              text: `Your Focus Score has dropped by ${Math.abs(delta).toFixed(0)} points in the second half of this period compared to the first half.`,
              severity: 'warning',
            });
          } else if (delta >= 15) {
            result.push({
              text: `Your Focus Score improved by ${delta.toFixed(0)} points compared to the first half of this period — great progress!`,
              severity: 'info',
            });
          }
        }
      }

      return result.slice(0, 5);
    }

    // ── Admin / superviseur insights (original rules) ──
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

    // 3. Dominant activity imbalance
    const dominant = totals[0];
    if (dominant && dominant.share > 0.75 && totals.length > 1) {
      result.push({ text: `Activity is heavily concentrated: ${dominant.activity.replace(/_/g, ' ')} accounts for ${(dominant.share * 100).toFixed(0)}% of all tracked time`, severity: 'info' });
    }

    // 4. No productive activity
    const working = find('Working');
    const productiveSec = working?.total_sec ?? 0;
    const total = this.totalSec();
    if (total > 0 && productiveSec / total < 0.10) {
      result.push({ text: 'Very low productive activity — Working represents less than 10% of tracked time', severity: 'critical' });
    }

    // 5. Activity drop over time
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

  /** Operational Guidance for admin and superviseur roles only. Viewer sees Performance Coach. */
  readonly smartGuidance = computed<GuidanceMessage[]>(() => {
    const totals = this.activityTotals();
    const role   = this.role();
    const name   = this.name();
    const msgs: GuidanceMessage[] = [];

    // Viewer/employee sees the Performance Coach card — no text guidance needed.
    if (role === 'viewer' || totals.length === 0) return msgs;

    const find     = (act: string) => totals.find(t => t.activity === act);
    const working  = find('Working');
    const phone    = find('Using_Phone');
    const inactive = find('Inactive');
    const wPct     = Math.round((working?.share  ?? 0) * 100);
    const phPct    = Math.round((phone?.share    ?? 0) * 100);
    const inPct    = Math.round((inactive?.share ?? 0) * 100);

    if (role === 'admin') {
      // Operational — accuracy + compliance lens
      if (working && working.share >= 0.55) {
        msgs.push({ category: 'recognition', text: `Working activity is strong at ${wPct}% — this profile shows productive patterns` });
      } else if (!working || working.share < 0.20) {
        msgs.push({ category: 'insight', text: `Working time is very low (${wPct}%) — verify whether tasks are captured correctly for this profile` });
      }
      if (phone && phone.share > 0.25) {
        msgs.push({ category: 'advice', text: `Phone usage at ${phPct}% exceeds recommended thresholds — verify compliance with acceptable-use policy` });
      }
      if (inactive && inactive.share > 0.40) {
        msgs.push({ category: 'advice', text: `Inactivity is elevated at ${inPct}% — consider checking workload allocation or monitoring accuracy` });
      }
      if (msgs.length === 0) {
        msgs.push({ category: 'recognition', text: 'No critical behavioral issues detected for this profile in the selected period' });
      }

    } else {
      // superviseur — coaching / action-oriented tone
      if (phone && phone.share > 0.25) {
        msgs.push({ category: 'advice', text: `${name} shows elevated phone usage at ${phPct}% — consider a conversation about focused work time` });
      }
      if (inactive && inactive.share > 0.40) {
        msgs.push({ category: 'advice', text: `Inactivity is high for ${name} at ${inPct}% — a one-on-one check-in may help uncover any blockers` });
      }
      if (working && working.share >= 0.55) {
        msgs.push({ category: 'recognition', text: `${name} shows healthy working patterns at ${wPct}% — positive reinforcement is appropriate` });
      }
      if (msgs.length === 0) {
        msgs.push({ category: 'recognition', text: `${name}'s activity patterns look balanced — no issues to flag this period` });
      }
    }

    return msgs.slice(0, 4);
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
    // Reset viewer backend data so KPIs don't show stale values during reload.
    this.personalSummary.set(null);
    this.personalDaily.set(null);
    this.fetchTimeline(this.name());
    this.fetchHighlights(this.name());
  }

  clearFilter(): void {
    this.filterStart  = '';
    this.filterEnd    = '';
    this.activePreset = '';
    this.filterMode   = 'overview';
    this.personalSummary.set(null);
    this.personalDaily.set(null);
    this.fetchTimeline(this.name());
    this.fetchHighlights(this.name());
  }

  /** Returns the raw numeric value for a DayMetric in chart units (h / min / %). */
  private metricRawValue(day: DayMetric, m: MetricKey): number {
    switch (m) {
      case 'working_time': return day.working_sec / 3600;
      case 'phone_usage':  return day.phone_sec / 60;
      case 'inactivity':   return day.inactive_sec / 60;
      case 'focus_score':  return day.focus_score;
    }
  }

  /** Formats a YYYY-MM-DD string as DD/MM for SVG axis labels. */
  formatDateLabel(date: string): string {
    return `${date.slice(8)}/${date.slice(5, 7)}`;
  }

  /** Download a formatted Excel report for this employee. */
  exportXlsx(): void {
    const params: TimelineParams = {};
    if (this.filterStart) params.start = toStartOfDay(this.filterStart);
    if (this.filterEnd)   params.end   = toEndOfDay(this.filterEnd);

    this.service.exportPersonXlsx(this.name(), params).subscribe({
      next: (blob) => {
        const ts       = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
        const filename = `${this.name()}_report_${ts}.xlsx`;
        const url  = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href     = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      },
      error: (err) => {
        console.error('Excel export failed:', err);
        this.errorMsg.set('Failed to export Excel report. Please try again.');
      },
    });
  }

  /** Open the AI-formulated report modal with current employee data as context. */
  openAiReport(): void {
    const totals  = this.activityTotals();
    const tl      = this.timeline();
    if (!tl) return;

    const rangeLabel = this.filterStart
      ? `${this.filterStart} to ${this.filterEnd || 'now'}`
      : 'all available data';

    const rangeStr = this.filterStart ? ` — ${rangeLabel}` : '';
    this.aiReportTitle.set(`${this.name()}${rangeStr}`);

    const lines: string[] = [
      `EMPLOYEE REPORT`,
      `Employee: ${this.name()}`,
      `Period:   ${rangeLabel}`,
    ];

    if (this.role() === 'viewer') {
      // Viewer: use backend-computed summary for accuracy; omit engineer metrics.
      const summary = this.personalSummary();
      lines.push(`Total tracked time: ${formatDuration(this.totalSec())}`);
      if (summary) {
        lines.push(
          ``,
          `PERSONAL METRICS:`,
          `  Working Time:  ${formatDuration(summary.working_sec)}`,
          `  Phone Usage:   ${formatDuration(summary.phone_sec)}`,
          `  Inactivity:    ${formatDuration(summary.inactive_sec)}`,
          `  Focus Score:   ${summary.focus_score !== null ? summary.focus_score + '%' : 'N/A'}`,
        );
      }
      lines.push(``, `ACTIVITY DISTRIBUTION:`);
      totals.forEach(t =>
        lines.push(`  ${t.activity.replace(/_/g, ' ')}: ${t.formatted} (${(t.share * 100).toFixed(1)}%)`)
      );
    } else {
      // Admin / superviseur: include full operational detail.
      lines.push(
        `Total tracked time: ${formatDuration(this.totalSec())}`,
        `Total segments: ${tl.count}`,
        ``,
        `ACTIVITY BREAKDOWN:`,
        ...totals.map(t =>
          `  ${t.activity.replace(/_/g, ' ')}: ${t.formatted} (${(t.share * 100).toFixed(1)}%, ${t.segment_count} segments)`
        ),
      );
    }

    const highlights = this.highlights();
    if (highlights.length) {
      lines.push(``, `NOTABLE INCIDENTS (${highlights.length}):`);
      highlights.slice(0, 5).forEach(h => {
        lines.push(`  - ${h.dominant_issue.replace(/_/g, ' ')} at ${h.window_start}` +
          (h.phone_duration_min   ? ` (phone: ${h.phone_duration_min.toFixed(1)}min)` : '') +
          (h.inactive_duration_min ? ` (inactive: ${h.inactive_duration_min.toFixed(1)}min)` : ''));
      });
    }

    const insights = this.userInsights();
    if (insights.length) {
      lines.push(``, `AUTOMATED INSIGHTS:`);
      insights.forEach(i => lines.push(`  [${i.severity.toUpperCase()}] ${i.text}`));
    }

    this.aiReportData.set(lines.join('\n'));
    this.aiReportOpen.set(true);
  }

  /** Called when the AI report modal closes. Closes the modal and starts a 10-second cooldown. */
  onAiReportClosed(): void {
    this.aiReportOpen.set(false);
    if (this._cooldownTimer) clearInterval(this._cooldownTimer);
    this.aiReportCooldownSec.set(10);
    this._cooldownTimer = setInterval(() => {
      const remaining = this.aiReportCooldownSec() - 1;
      if (remaining <= 0) {
        clearInterval(this._cooldownTimer!);
        this._cooldownTimer = null;
        this.aiReportCooldownSec.set(0);
      } else {
        this.aiReportCooldownSec.set(remaining);
      }
    }, 1000);
  }

  fetchHighlights(identityName: string): void {
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
    this.replayMeta.set({
      identity_name: this.name(),
      event_type:    h.dominant_issue,
      started_at:    h.window_start,
      ended_at:      h.window_end,
    });
  }

  closeReplay(): void {
    this.replayAlertId.set(null);
    this.replayMeta.set(null);
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

        // Update chatbot context with the freshly loaded employee data
        const dateLabel = this.filterStart
          ? `${this.filterStart}${this.filterEnd ? ' to ' + this.filterEnd : ''}`
          : 'all time';
        const ctxLines = [
          `Employee: ${identityName}`,
          `Period: ${dateLabel}`,
          `Total segments: ${data.count}`,
          ...data.segments.reduce((acc: Record<string, number>, s) => {
            acc[s.activity] = (acc[s.activity] ?? 0) + s.duration_sec; return acc;
          }, {} as Record<string, number>)
            ? Object.entries(
                data.segments.reduce((acc: Record<string, number>, s) => {
                  acc[s.activity] = (acc[s.activity] ?? 0) + s.duration_sec; return acc;
                }, {} as Record<string, number>)
              ).map(([act, sec]) => `  ${act}: ${formatDuration(sec)}`)
            : [],
        ];
        this.ctxSvc.setContext(ctxLines.join('\n'), `${identityName} — ${dateLabel}`);
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

    // Viewer: also fetch backend-aggregated summary and daily breakdown.
    if (this.role() === 'viewer') {
      this.service.getPersonalSummary(identityName, params).subscribe({
        next: (data) => this.personalSummary.set(data),
        error: ()     => { /* non-critical; KPI cards degrade gracefully */ },
      });
      this.service.getPersonalDaily(identityName, params).subscribe({
        next: (data) => this.personalDaily.set(data.days),
        error: ()     => { /* non-critical; time-series degrades gracefully */ },
      });
    }
  }
}
