import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { SurveillanceService } from '../../../../core/services/surveillance.service';
import { OverviewResponse, OverviewParams } from '../../models/overview.model';
import { IdentityEntry, IdentityRow } from '../../models/identities.model';
import { ActivityStat } from '../../models/overview.model';
import { KpiCardsComponent, KpiCardDef } from '../../components/kpi-cards/kpi-cards.component';
import { IdentityTableComponent } from '../../components/identity-table/identity-table.component';
import { toDateInputValue, formatDuration, toStartOfDay, toEndOfDay, DatePresetKey, datePreset } from '../../../../core/utils/duration.util';
import { AuthService } from '../../../../core/services/auth.service';
import { ChatContextService } from '../../../../core/services/chat-context.service';
import { DashboardSummaryResponse } from '../../models/summary.model';
import { ActivityDistributionItem } from '../../models/summary.model';
import { AlertSummaryComponent } from '../../components/alert-summary/alert-summary.component';
import { ActivityDoughnutChartComponent } from '../../components/activity-doughnut-chart/activity-doughnut-chart.component';
import { ActivityStackedBarComponent } from '../../components/activity-stacked-bar/activity-stacked-bar.component';
import { ActivityEvolutionChartComponent } from '../../components/activity-evolution-chart/activity-evolution-chart.component';
import { SmartGuidanceComponent, GuidanceMessage } from '../../components/smart-guidance/smart-guidance.component';
import { PerformanceCoachComponent } from '../../components/performance-coach/performance-coach.component';

type LoadState = 'idle' | 'loading' | 'success' | 'error';

interface EmployeeMetric {
  name:              string;
  total_sec:         number;
  totalFormatted:    string;
  workingFormatted:  string;
  inactiveFormatted: string;
  phoneFormatted:    string;
  meetingFormatted:  string;
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    FormsModule,
    KpiCardsComponent,
    IdentityTableComponent,
    AlertSummaryComponent,
    ActivityDoughnutChartComponent,
    ActivityStackedBarComponent,
    ActivityEvolutionChartComponent,
    SmartGuidanceComponent,
    PerformanceCoachComponent,
  ],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private readonly service     = inject(SurveillanceService);
  private readonly router      = inject(Router);
  private readonly authService = inject(AuthService);
  private readonly ctxSvc      = inject(ChatContextService);

  readonly isSuperviseur = computed(() => this.authService.user()?.role === 'superviseur');
  readonly isAdmin       = computed(() => this.authService.user()?.role === 'admin');
  readonly isViewer      = computed(() => (this.authService.user()?.role ?? 'viewer') === 'viewer');

  // ── Template utilities ──────────────────────────────────────────────────
  readonly formatDuration = formatDuration;

  // ── Date range filter ────────────────────────────────────────────────────
  readonly today = toDateInputValue(new Date());

  filterStart    = '';
  filterEnd      = '';
  activePreset: DatePresetKey | 'custom' | '' = '';
  includeUnknown = false;

  // ── Load state ──────────────────────────────────────────────────────────
  readonly loadState   = signal<LoadState>('idle');
  readonly errorMsg    = signal<string>('');
  readonly exportState = signal<'idle' | 'loading' | 'error'>('idle');

  // ── Raw data signals ────────────────────────────────────────────────────
  readonly overview    = signal<OverviewResponse | null>(null);
  readonly identities  = signal<IdentityEntry[]>([]);  readonly summary     = signal<DashboardSummaryResponse | null>(null);
  // ── Derived / adapted signals ───────────────────────────────────────────
  readonly activityStats = computed<ActivityStat[]>(() => {
    const ov = this.overview();
    return ov ? this.service.toActivityStats(ov) : [];
  });

  /** Single source of truth for activity distribution: summary.charts.activity_distribution.
   *  Falls back to [] when summary is unavailable (server down / null). */
  readonly activityDistributionItems = computed<ActivityDistributionItem[]>(() =>
    this.summary()?.charts.activity_distribution ?? []
  );

  /** Activity evolution points — empty array when summary is unavailable. */
  readonly activityEvolutionPoints = computed(() =>
    this.summary()?.charts.activity_evolution ?? []
  );

  readonly identityRows = computed<IdentityRow[]>(() =>
    this.service.toIdentityRows(this.identities()),
  );

  /** Working-time productivity score: percentage + Excellent/Good/Moderate/Low label. */
  readonly productivityScore = computed<{ pct: number; label: string } | null>(() => {
    const dist    = this.activityDistributionItems();
    const working = dist.find(d => d.activity === 'Working');
    if (!dist.length || !working) return null;
    const pct   = Math.round(working.share * 100);
    const label = pct >= 75 ? 'Excellent' : pct >= 55 ? 'Good' : pct >= 35 ? 'Moderate' : 'Low';
    return { pct, label };
  });

  /** 4 primary KPI cards at the top of the dashboard. */
  readonly primaryKpiCards = computed<KpiCardDef[]>(() => {
    const ov    = this.overview();
    const stats = this.activityStats();
    const sum   = this.summary();
    const rows  = this.identities();

    const activeEmployees = sum?.kpis.active_employees
      ?? rows.filter(r => r.identity_name !== 'Unknown' && r.total_sec > 0).length;
    const totalAlerts = sum?.kpis.total_alerts ?? 0;

    return [
      {
        label: 'Observed Time',
        value: ov ? formatDuration(ov.total_sec) : '—',
        sub:   ov ? `${ov.event_count} events recorded` : undefined,
        icon:  'M12 2v20M2 12h20',
        theme: 'primary',
      },
      {
        label: 'Active Employees',
        value: String(activeEmployees),
        sub:   'observed in period',
        icon:  'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 1-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        theme: 'neutral',
      },
      {
        label: 'Total Alerts',
        value: String(totalAlerts),
        sub:   totalAlerts > 0 ? 'behavioral alerts fired' : 'no alerts in period',
        icon:  'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
        theme: totalAlerts > 0 ? 'warning' : 'neutral',
      },
      {
        label: 'Top Activity',
        value: stats[0]?.activity.replace(/_/g, ' ') ?? '—',
        sub:   stats[0] ? `${(stats[0].share * 100).toFixed(1)}% of session` : undefined,
        icon:  'M22 12h-4l-3 9L9 3l-3 9H2',
        theme: 'success',
      },
    ];
  });

  /** Compact secondary stat strip: contextual counts below primary KPIs. */
  readonly secondaryKpis = computed(() => {
    const ov    = this.overview();
    const rows  = this.identities();
    const sum   = this.summary();
    const ps    = this.productivityScore();
    const named = rows.filter(r => r.identity_name !== 'Unknown').length;
    return [
      { label: 'Events Recorded', value: ov  ? String(ov.event_count)        : '—' },
      { label: 'Identified',      value: String(named)                               },
      { label: 'Late Arrivals',   value: sum ? String(sum.kpis.late_arrivals) : '—' },
      { label: 'Early Leaves',    value: sum ? String(sum.kpis.early_leaves)  : '—' },
      { label: 'Productivity',    value: ps  ? `${ps.pct}% · ${ps.label}`    : '—' },
    ];
  });

  /** Decision-oriented interpretive insights derived from loaded data (max 4). */
  readonly insights = computed<string[]>(() => {
    const result: string[] = [];
    const dist = this.activityDistributionItems();
    const evol = this.activityEvolutionPoints();
    const ids  = this.identities().filter(r => r.identity_name !== 'Unknown' && r.total_sec > 0);
    const sum  = this.summary();

    // 1. Productivity level
    const working = dist.find(d => d.activity === 'Working');
    if (dist.length > 0) {
      if (working && working.share > 0) {
        const pct   = Math.round(working.share * 100);
        const level = pct >= 75 ? 'Excellent' : pct >= 55 ? 'Good' : pct >= 35 ? 'Moderate' : 'Low';
        result.push(`Productivity is ${level} — ${pct}% of observed time is Working`);
      } else {
        result.push('No work activity recorded in this period');
      }
    }

    // 2. Alert dominance
    if (sum) {
      const total = sum.kpis.total_alerts;
      if (total === 0) {
        result.push('No behavioral alerts fired in this period');
      } else {
        const byType = sum.charts.alerts_by_type ?? [];
        const top    = byType[0];
        if (top) {
          result.push(`${total} alert${total !== 1 ? 's' : ''} fired — most common: ${top.type.replace(/_/g, ' ')}`);
        } else {
          result.push(`${total} behavioral alert${total !== 1 ? 's' : ''} fired in this period`);
        }
      }
    }

    // 3. Activity coverage (flag sparse data)
    if (evol.length > 1) {
      const active   = evol.filter(p => p.working + p.meeting + p.inactive + p.using_phone > 0).length;
      const covPct   = Math.round((active / evol.length) * 100);
      const isHourly = evol[0].label.includes(' ');
      const unit     = isHourly ? 'hour' : 'day';
      const plural   = evol.length !== 1 ? 's' : '';
      if (covPct < 60) {
        result.push(`Sparse data — activity on only ${active} of ${evol.length} ${unit}${plural} (${covPct}% coverage)`);
      } else {
        result.push(`Activity covered ${active} of ${evol.length} ${unit}${plural} (${covPct}% coverage)`);
      }
    }

    // 4. High phone usage flag
    if (ids.length > 0) {
      const flagged = ids
        .map(r => ({ name: r.identity_name, phonePct: r.total_sec > 0 ? (r.activities['Using_Phone'] ?? 0) / r.total_sec : 0 }))
        .filter(r => r.phonePct > 0.20)
        .sort((a, b) => b.phonePct - a.phonePct);
      if (flagged.length > 0) {
        result.push(`High phone usage — ${flagged[0].name} spent ${Math.round(flagged[0].phonePct * 100)}% of time on phone`);
      }
    }

    return result.slice(0, 4);
  });

  /** Rule-based anomaly detection — deterministic, no ML, uses only loaded data. */
  readonly anomalies = computed<Array<{ text: string; severity: 'good' | 'warning' | 'critical' }>>(() => {
    const result: Array<{ text: string; severity: 'good' | 'warning' | 'critical' }> = [];
    const ids  = this.identities().filter(r => r.identity_name !== 'Unknown' && r.total_sec > 0);
    const evol = this.activityEvolutionPoints();
    const sum  = this.summary();

    // 1. High phone usage — flag anyone > 25% phone share, compare against team mean
    if (ids.length > 0) {
      const teamTotalSec  = ids.reduce((s, r) => s + r.total_sec, 0);
      const teamPhoneSec  = ids.reduce((s, r) => s + (r.activities['Using_Phone'] ?? 0), 0);
      const teamPhoneMean = teamTotalSec > 0 ? teamPhoneSec / teamTotalSec : 0;

      for (const r of ids) {
        const pct = r.total_sec > 0 ? (r.activities['Using_Phone'] ?? 0) / r.total_sec : 0;
        // Flag if absolute threshold exceeded OR clearly above team average (1.5×)
        if (pct > 0.25 || (pct > 0.15 && teamPhoneMean > 0 && pct > teamPhoneMean * 1.5)) {
          const severity = pct > 0.40 ? 'critical' : 'warning';
          result.push({ text: `High phone usage detected for ${r.identity_name} (${Math.round(pct * 100)}% of observed time)`, severity });
        }
      }
    }

    // 2. Mostly inactive — flag anyone > 40% inactive share
    if (ids.length > 0) {
      for (const r of ids) {
        const pct = r.total_sec > 0 ? (r.activities['Inactive'] ?? 0) / r.total_sec : 0;
        if (pct > 0.40) {
          const severity = pct > 0.60 ? 'critical' : 'warning';
          result.push({ text: `Mostly inactive profile detected for ${r.identity_name} (${Math.round(pct * 100)}% inactive)`, severity });
        }
      }
    }

    // 3. Sparse tracking — flag when active buckets < 60% of total
    if (evol.length > 1) {
      const active = evol.filter(p => p.working + p.meeting + p.inactive + p.using_phone > 0).length;
      const covPct = active / evol.length;
      if (covPct < 0.60) {
        const isHourly = evol[0].label.includes(' ');
        const unit     = isHourly ? 'hours' : 'days';
        const severity = covPct < 0.35 ? 'critical' : 'warning';
        result.push({ text: `Incomplete tracking: activity recorded on only ${active}/${evol.length} ${unit} (${Math.round(covPct * 100)}% coverage)`, severity });
      }
    }

    // 4. Alert concentration — flag when one type is > 60% of all alerts
    if (sum && sum.kpis.total_alerts > 0) {
      const byType = sum.charts.alerts_by_type ?? [];
      if (byType.length > 0) {
        const top    = byType[0];
        const share  = top.count / sum.kpis.total_alerts;
        if (share > 0.60) {
          const displayType = top.type.replace(/_/g, ' ');
          const severity    = share > 0.80 ? 'critical' : 'warning';
          result.push({ text: `${displayType} is the dominant alert type (${Math.round(share * 100)}% of ${sum.kpis.total_alerts} alerts)`, severity });
        }
      }
    }

    // 5. Activity drop — compare last 25% of buckets vs first 50%
    if (evol.length >= 4) {
      const activeBuckets = evol.filter(p => p.working + p.meeting + p.inactive + p.using_phone > 0);
      if (activeBuckets.length >= 3) {
        const half      = Math.floor(activeBuckets.length / 2);
        const early     = activeBuckets.slice(0, half);
        const recent    = activeBuckets.slice(-Math.max(1, Math.floor(activeBuckets.length / 4)));
        const earlyAvg  = early.reduce((s, p)  => s + p.working + p.meeting + p.inactive + p.using_phone, 0) / early.length;
        const recentAvg = recent.reduce((s, p) => s + p.working + p.meeting + p.inactive + p.using_phone, 0) / recent.length;
        if (earlyAvg > 0 && recentAvg < earlyAvg * 0.40) {
          const lastLabel = recent[recent.length - 1].label;
          result.push({ text: `Activity dropped significantly in recent buckets (around ${lastLabel})`, severity: 'warning' });
        }
      }
    }

    return result.slice(0, 5);
  });

  /** Operational Guidance for admin and superviseur roles. Viewer/employee sees Performance Coach. */
  readonly smartGuidance = computed<GuidanceMessage[]>(() => {
    const role = this.authService.user()?.role ?? 'viewer';
    const msgs: GuidanceMessage[] = [];

    // Viewer sees Performance Coach card — skip text guidance entirely.
    if (role === 'viewer') return msgs;

    const ids  = this.identities().filter(r => r.identity_name !== 'Unknown' && r.total_sec > 0);
    const sum  = this.summary();
    const dist = this.activityDistributionItems();
    const anom = this.anomalies();

    if (role === 'admin') {
      // ── Operational / strategic ──────────────────────────────────
      const criticalCount = anom.filter(a => a.severity === 'critical').length;
      if (criticalCount > 0) {
        msgs.push({ category: 'insight', text: `${criticalCount} critical anomaly pattern${criticalCount > 1 ? 's' : ''} detected — immediate operational review recommended` });
      }

      const phoneFlagged = ids.filter(r => r.total_sec > 0 && (r.activities['Using_Phone'] ?? 0) / r.total_sec > 0.25).length;
      if (phoneFlagged >= 2) {
        msgs.push({ category: 'advice', text: `Phone-related anomalies are widespread (${phoneFlagged} employees) — consider reviewing acceptable-use policies` });
      }

      if (anom.some(a => a.text.includes('Incomplete tracking'))) {
        msgs.push({ category: 'advice', text: 'Tracking coverage is incomplete — verify that monitoring is running continuously for all targets' });
      }

      if (sum && sum.kpis.total_alerts > 0) {
        const byType = sum.charts.alerts_by_type ?? [];
        if (byType.length > 0 && byType[0].count / sum.kpis.total_alerts > 0.60) {
          msgs.push({ category: 'insight', text: `Alerts concentrate on "${byType[0].type.replace(/_/g, ' ')}" — targeted intervention may reduce the overall alert rate` });
        }
      }

      if (msgs.length === 0) {
        msgs.push({ category: 'recognition', text: 'Operations appear stable — no critical anomalies or dominant alert patterns detected in this period' });
      }

    } else {
      // ── superviseur — coaching / team follow-up ──────────────────
      const phoneCount    = ids.filter(r => (r.activities['Using_Phone'] ?? 0) / r.total_sec > 0.25).length;
      const inactiveCount = ids.filter(r => (r.activities['Inactive']    ?? 0) / r.total_sec > 0.40).length;

      if (phoneCount > 0) {
        msgs.push({ category: 'advice', text: `${phoneCount} team member${phoneCount > 1 ? 's' : ''} show elevated phone usage — consider a brief discussion on focus time` });
      }
      if (inactiveCount > 0) {
        msgs.push({ category: 'advice', text: `${inactiveCount} employee${inactiveCount > 1 ? 's' : ''} have above-average inactivity — a one-on-one check-in may help` });
      }

      if (sum && sum.kpis.late_arrivals > 0) {
        msgs.push({ category: 'insight', text: `${sum.kpis.late_arrivals} late arrival${sum.kpis.late_arrivals > 1 ? 's' : ''} this period — track whether this is a recurring pattern` });
      }

      const workingItem = dist.find(d => d.activity === 'Working');
      if (workingItem && workingItem.share >= 0.55) {
        msgs.push({ category: 'recognition', text: `Team working time is solid at ${Math.round(workingItem.share * 100)}% — keep supporting this momentum` });
      }

      if (phoneCount === 0 && inactiveCount === 0 && msgs.length === 0) {
        msgs.push({ category: 'recognition', text: 'No major behavioral issues detected in your team this period — strong collective performance' });
      }
    }

    return msgs.slice(0, 4);
  });

  readonly employeeRows = computed<EmployeeMetric[]>(() =>
    this.identities()
      .filter(r => r.identity_name !== 'Unknown')
      .map(r => ({
        name:              r.identity_name,
        total_sec:         r.total_sec,
        totalFormatted:    formatDuration(r.total_sec),
        workingFormatted:  formatDuration(r.activities['Working']     ?? 0),
        inactiveFormatted: formatDuration(r.activities['Inactive']    ?? 0),
        phoneFormatted:    formatDuration(r.activities['Using_Phone'] ?? 0),
        meetingFormatted:  formatDuration(r.activities['Meeting']     ?? 0),
      }))
      .sort((a, b) => b.total_sec - a.total_sec),
  );

  readonly teamKpis = computed<KpiCardDef[]>(() => {
    const rows = this.employeeRows();
    if (!rows.length) return [];

    const activeCount = rows.filter(r => r.total_sec > 0).length;
    const totalSec    = rows.reduce((s, r) => s + r.total_sec, 0);
    const workSec     = this.identities().reduce((s, r) => s + (r.activities['Working']     ?? 0), 0);
    const inactiveSec = this.identities().reduce((s, r) => s + (r.activities['Inactive']    ?? 0), 0);
    const phoneSec    = this.identities().reduce((s, r) => s + (r.activities['Using_Phone'] ?? 0), 0);
    const workPct     = totalSec > 0 ? Math.round(workSec / totalSec * 100) : 0;
    const atRiskPct   = totalSec > 0 ? Math.round((inactiveSec + phoneSec) / totalSec * 100) : 0;

    return [
      {
        label: 'Team Size',
        value: String(rows.length),
        sub:   `${activeCount} active in period`,
        icon:  'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 1-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        theme: 'primary',
      },
      {
        label: 'Working Time',
        value: `${workPct}%`,
        sub:   formatDuration(workSec) + ' total',
        icon:  'M22 12h-4l-3 9L9 3l-3 9H2',
        theme: 'success',
      },
      {
        label: 'Inactive / Phone',
        value: `${atRiskPct}%`,
        sub:   formatDuration(inactiveSec + phoneSec) + ' total',
        icon:  'M12 8v4l3 3m0 0-3 3m3-3H9',
        theme: atRiskPct > 30 ? 'warning' : 'neutral',
      },
      {
        label: 'Team Total Time',
        value: formatDuration(totalSec),
        sub:   `across ${rows.length} employee${rows.length !== 1 ? 's' : ''}`,
        icon:  'M12 2v20M2 12h20',
        theme: 'neutral',
      },
    ];
  });

  // ── Public actions ──────────────────────────────────────────────────────

  /** Apply a named date preset and immediately load data for that range. */
  setPreset(key: DatePresetKey): void {
    const r = datePreset(key);
    this.filterStart  = r.start;
    this.filterEnd    = r.end;
    this.activePreset = key;
    this.load();
  }

  /** Enable custom date range mode (disables preset, allows manual input). */
  setCustom(): void {
    this.activePreset = 'custom';
  }

  onStartChange(v: string): void {
    this.filterStart = v;
    if (this.activePreset !== 'custom') {
      this.activePreset = '';
    }
  }

  onEndChange(v: string): void {
    this.filterEnd = v;
    if (this.activePreset !== 'custom') {
      this.activePreset = '';
    }
  }

  /** Returns a human-readable date range label matching the active filter. */
  private buildDateLabel(): string {
    const presetLabels: Record<string, string> = {
      today:     'Today',
      yesterday: 'Yesterday',
      last7:     'Last 7 days',
      month:     'This month',
    };
    if (this.activePreset && this.activePreset !== 'custom' && presetLabels[this.activePreset]) {
      return presetLabels[this.activePreset];
    }
    if (this.filterStart && this.filterEnd) {
      const fmt = (s: string) => s.split('-').reverse().join('/'); // YYYY-MM-DD → DD/MM/YYYY
      return `${fmt(this.filterStart)} to ${fmt(this.filterEnd)}`;
    }
    return 'Custom range';
  }

  load(): void {
    if (this.filterStart && this.filterEnd && this.filterStart > this.filterEnd) {
      this.errorMsg.set('Start date cannot be after end date.');
      this.loadState.set('error');
      return;
    }

    this.loadState.set('loading');
    this.errorMsg.set('');

    const dateRange = (this.filterStart && this.filterEnd)
      ? { start: toStartOfDay(this.filterStart), end: toEndOfDay(this.filterEnd) }
      : {};

    const sharedParams = {
      ...dateRange,
      ...(this.isAdmin() ? { include_unknown: this.includeUnknown } : {}),
    };

    forkJoin({
      overview:   this.service.getOverview(sharedParams),
      identities: this.service.getIdentities(sharedParams),
      summary:    this.service.getSummary(sharedParams).pipe(catchError(() => of(null))),
    }).subscribe({
      next: ({ overview, identities, summary }) => {
        this.overview.set(overview);
        this.identities.set(identities);
        this.summary.set(summary);
        this.loadState.set('success');
        // Sync chat context with the active dashboard date range
        this.ctxSvc.pushDashboardRange(
          this.buildDateLabel(),
          toStartOfDay(this.filterStart),
          toEndOfDay(this.filterEnd),
        );
      },
      error: (err: unknown) => {
        const message =
          err instanceof Error
            ? err.message
            : 'Unable to load surveillance data. Please try again.';
        this.errorMsg.set(message);
        this.loadState.set('error');
      },
    });
  }

  ngOnInit(): void {
    // Default to today on initial load
    this.setPreset('today');
    this.load();
  }

  openIdentity(name: string): void {
    this.router.navigate(['/surveillance/identity', name]);
  }

  /** Download the current view as a formatted Excel workbook. */
  exportXlsx(): void {
    if (this.exportState() === 'loading') return;
    this.exportState.set('loading');

    const params: OverviewParams = {};
    if (this.filterStart) params.start = toStartOfDay(this.filterStart);
    if (this.filterEnd)   params.end   = toEndOfDay(this.filterEnd);
    if (this.isAdmin())   params.include_unknown = this.includeUnknown;

    this.service.exportOverviewXlsx(params).subscribe({
      next: (blob) => {
        const ts       = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
        const filename = `overview_report_${ts}.xlsx`;
        const url  = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href     = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        this.exportState.set('idle');
      },
      error: () => {
        this.exportState.set('error');
        setTimeout(() => this.exportState.set('idle'), 3000);
      },
    });
  }
}
