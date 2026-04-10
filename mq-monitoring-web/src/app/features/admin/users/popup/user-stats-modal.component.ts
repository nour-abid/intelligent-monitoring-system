import {
  Component,
  computed,
  inject,
  input,
  OnInit,
  output,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';

import { SurveillanceService } from '../../../../core/services/surveillance.service';
import { TimelineResponse } from '../../../surveillance/models/timeline.model';
import { KpiCardsComponent, KpiCardDef } from '../../../surveillance/components/kpi-cards/kpi-cards.component';
import { ActivityChartComponent } from '../../../surveillance/components/activity-chart/activity-chart.component';
import { ActivityStat } from '../../../surveillance/models/overview.model';
import {
  activityColor,
  activityBadgeClass,
} from '../../../surveillance/utils/activity-colors.util';
import { formatDuration } from '../../../../core/utils/duration.util';

type LoadState = 'loading' | 'success' | 'error';

interface ActivityRow {
  activity: string;
  total_sec: number;
  segment_count: number;
  share: number;
  formatted: string;
  color: string;
  badgeClass: string;
}

@Component({
  selector: 'app-user-stats-modal',
  standalone: true,
  imports: [RouterLink, KpiCardsComponent, ActivityChartComponent],
  templateUrl: './user-stats-modal.component.html',
  styleUrl:    './user-stats-modal.component.scss',
})
export class UserStatsModalComponent implements OnInit {
  private readonly service = inject(SurveillanceService);

  /** The surveillance identity key for this user. */
  readonly identity = input.required<string>();
  /** Emitted when the user requests the modal to close. */
  readonly closeModal = output<void>();

  readonly loadState = signal<LoadState>('loading');
  readonly errorMsg  = signal<string>('');
  readonly timeline  = signal<TimelineResponse | null>(null);

  // ── Derived ──────────────────────────────────────────────────────────────

  readonly totalSec = computed<number>(() => {
    const t = this.timeline();
    if (!t) return 0;
    return t.segments.reduce((sum, seg) => sum + seg.duration_sec, 0);
  });

  readonly activityRows = computed<ActivityRow[]>(() => {
    const t = this.timeline();
    if (!t) return [];

    const total = this.totalSec();
    const secs:   Record<string, number> = {};
    const counts: Record<string, number> = {};
    for (const seg of t.segments) {
      secs[seg.activity]   = (secs[seg.activity]   ?? 0) + seg.duration_sec;
      counts[seg.activity] = (counts[seg.activity] ?? 0) + 1;
    }

    return Object.entries(secs)
      .sort(([, a], [, b]) => b - a)
      .map(([activity, activity_sec]) => ({
        activity,
        total_sec:     activity_sec,
        segment_count: counts[activity] ?? 0,
        share:         total > 0 ? activity_sec / total : 0,
        formatted:     formatDuration(activity_sec),
        color:         activityColor(activity),
        badgeClass:    activityBadgeClass(activity),
      }));
  });

  readonly activityStats = computed<ActivityStat[]>(() =>
    this.activityRows().map((r) => ({
      activity:  r.activity,
      total_sec: r.total_sec,
      share:     r.share,
      formatted: r.formatted,
    }))
  );

  readonly maxSegmentCount = computed<number>(() => {
    const rows = this.activityRows();
    return rows.length > 0 ? Math.max(...rows.map((r) => r.segment_count)) : 1;
  });

  /** Width % for the segment-count bar relative to the busiest activity. */
  segPct(count: number): string {
    const max = this.maxSegmentCount();
    return `${((count / max) * 100).toFixed(1)}%`;
  }

  readonly kpiCards = computed<KpiCardDef[]>(() => {
    const tl      = this.timeline();
    const rows    = this.activityRows();
    const dominant = rows[0];
    if (!tl) return [];
    return [
      {
        label: 'Tracked Time',
        value: formatDuration(this.totalSec()),
        sub:   `${tl.count} segment${tl.count !== 1 ? 's' : ''}`,
        icon:  'M12 2v20M2 12h20',
        theme: 'primary',
      },
      {
        label: 'Segments',
        value: String(tl.count),
        sub:   'recorded events',
        icon:  'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2',
        theme: 'neutral',
      },
      {
        label: 'Activities',
        value: String(rows.length),
        sub:   rows.map((r) => r.activity.replace(/_/g, ' ')).join(', ') || '—',
        icon:  'M22 12h-4l-3 9L9 3l-3 9H2',
        theme: 'success',
      },
      {
        label: 'Dominant',
        value: dominant ? dominant.activity.replace(/_/g, ' ') : '—',
        sub:   dominant ? `${(dominant.share * 100).toFixed(1)}% of time` : 'No data',
        icon:  'M18 20V10M12 20V4M6 20v-6',
        theme: 'warning',
      },
    ];
  });

  // ── Lifecycle ─────────────────────────────────────────────────────────────

  ngOnInit(): void {
    this.service.getTimeline(this.identity(), {}).subscribe({
      next: (data) => {
        this.timeline.set(data);
        this.loadState.set('success');
      },
      error: () => {
        this.errorMsg.set('Unable to load statistics. Please try again.');
        this.loadState.set('error');
      },
    });
  }

  // ── Public ────────────────────────────────────────────────────────────────

  close(): void {
    this.closeModal.emit();
  }

  retry(): void {
    this.loadState.set('loading');
    this.errorMsg.set('');
    this.ngOnInit();
  }
}
