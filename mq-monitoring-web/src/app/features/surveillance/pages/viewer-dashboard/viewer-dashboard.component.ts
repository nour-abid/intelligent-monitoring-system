import { Component, OnInit, signal, computed, effect, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { forkJoin, catchError, of } from 'rxjs';

import { AuthService } from '@app/shared/auth/auth.service';
import { BusinessDayFilterService, DateRangeResult } from '@app/shared/services/business-day-filter.service';
import { SurveillanceAnalyticsService } from '@app/shared/services/surveillance-analytics.service';

import { PersonalStatsCardsComponent } from './components/personal-stats-cards/personal-stats-cards.component';
import { PersonalMetricsStripComponent } from './components/personal-metrics-strip/personal-metrics-strip.component';
import { PersonalTimeSeriesComponent } from './components/personal-time-series/personal-time-series.component';
import { PersonalActivityBreakdownComponent } from './components/personal-activity-breakdown/personal-activity-breakdown.component';
import { PersonalDailyBreakdownComponent } from './components/personal-daily-breakdown/personal-daily-breakdown.component';
import { PersonalInsightsBoxComponent } from './components/personal-insights-box/personal-insights-box.component';

export interface ViewerDashboardState {
  loading: 'idle' | 'loading' | 'success' | 'error';
  error?: string;
  overviewData?: any;
  timelineData?: any;
  summaryData?: any;
}

/**
 * ViewerDashboardComponent
 *
 * Personal self-insight dashboard for viewer role.
 * Shows only the current user's activity metrics and trends.
 *
 * **Key Differences from Admin/Supervisor Dashboard:**
 * - No team-level metrics (identified count, active employees, etc.)
 * - No multi-user analytics (activity by employee, identity summary table)
 * - Business-day-aware date filtering (Mon-Fri, exclude holidays)
 * - Personal time-series with metric toggle
 * - Personal coaching & insights only
 * - Mobile-first clean layout
 *
 * **Role Model:**
 * - Admin: sees everything
 * - Supervisor: sees own + supervised employees
 * - VIEWER: sees only own data (this component)
 */
@Component({
  selector: 'app-viewer-dashboard',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    PersonalStatsCardsComponent,
    PersonalMetricsStripComponent,
    PersonalTimeSeriesComponent,
    PersonalActivityBreakdownComponent,
    PersonalDailyBreakdownComponent,
    PersonalInsightsBoxComponent,
  ],
  templateUrl: './viewer-dashboard.component.html',
  styleUrls: ['./viewer-dashboard.component.scss'],
})
export class ViewerDashboardComponent implements OnInit {
  private readonly authService = inject(AuthService);
  private readonly businessDayFilter = inject(BusinessDayFilterService);
  private readonly analyticsService = inject(SurveillanceAnalyticsService);

  // ─────────────────────────────────────────────────────────────────────────
  // Signals & Computed State
  // ─────────────────────────────────────────────────────────────────────────

  // Current user identity
  readonly userIdentity = computed(() => this.authService.user()?.surveillance_identity || 'unknown');
  readonly userName = computed(() => this.authService.user()?.name || 'User');

  // Date range controls
  readonly datePreset = signal<'today' | 'yesterday' | 'week' | 'month' | 'last7' | 'last30'>('last7');
  readonly excludeWeekends = signal(true);
  readonly customStartDate = signal<Date | null>(null);
  readonly customEndDate = signal<Date | null>(null);

  // Metric toggle for time-series chart
  readonly selectedMetric = signal<'working_time' | 'phone_usage' | 'inactivity' | 'focus_score'>('working_time');

  // Computed date range based on preset or custom
  readonly dateRange = computed(() => {
    const customStart = this.customStartDate();
    const customEnd = this.customEndDate();

    if (customStart && customEnd) {
      return this.businessDayFilter.getCustomRange(
        customStart,
        customEnd,
        this.excludeWeekends()
      );
    }

    return this.businessDayFilter.getDateRange(
      this.datePreset(),
      this.excludeWeekends()
    );
  });

  // API response signals
  readonly dashboardState = signal<ViewerDashboardState>({ loading: 'idle' });
  readonly overview = computed(() => this.dashboardState().overviewData);
  readonly timeline = computed(() => this.dashboardState().timelineData);
  readonly summary = computed(() => this.dashboardState().summaryData);

  // ─────────────────────────────────────────────────────────────────────────
  // Lifecycle
  // ─────────────────────────────────────────────────────────────────────────

  ngOnInit(): void {
    // Auto-refresh when filters change
    effect(
      () => {
        this.dateRange(); // Dependency
        this.loadDashboardData();
      },
      { allowSignalWrites: true }
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Data Loading
  // ─────────────────────────────────────────────────────────────────────────

  private loadDashboardData(): void {
    const userIdentity = this.userIdentity();
    if (userIdentity === 'unknown') {
      this.dashboardState.set({
        loading: 'error',
        error: 'User identity not found. Please log in again.',
      });
      return;
    }

    const range = this.dateRange();
    const startStr = this.businessDayFilter.formatDate(range.start, 'yyyy-MM-dd HH:MM:SS');
    const endStr = this.businessDayFilter.formatDate(range.end, 'yyyy-MM-dd HH:MM:SS');

    this.dashboardState.set({ loading: 'loading' });

    forkJoin({
      overview: this.analyticsService.getOverview(startStr, endStr, false, []).pipe(
        catchError((err) => {
          console.error('Overview API error:', err);
          return of(null);
        })
      ),
      timeline: this.analyticsService.getTimeline(userIdentity, startStr, endStr, []).pipe(
        catchError((err) => {
          console.error('Timeline API error:', err);
          return of(null);
        })
      ),
      summary: this.analyticsService.getSummary(startStr, endStr, false, []).pipe(
        catchError((err) => {
          console.error('Summary API error:', err);
          return of(null);
        })
      ),
    }).subscribe({
      next: (results) => {
        if (results.overview && results.timeline && results.summary) {
          this.dashboardState.set({
            loading: 'success',
            overviewData: results.overview,
            timelineData: results.timeline,
            summaryData: results.summary,
          });
        } else {
          this.dashboardState.set({
            loading: 'error',
            error: 'Failed to load some dashboard data',
          });
        }
      },
      error: (err) => {
        console.error('Dashboard load error:', err);
        this.dashboardState.set({
          loading: 'error',
          error: `Failed to load dashboard: ${err?.message || 'Unknown error'}`,
        });
      },
    });
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Filter Controls
  // ─────────────────────────────────────────────────────────────────────────

  selectPreset(preset: 'today' | 'yesterday' | 'week' | 'month' | 'last7' | 'last30'): void {
    this.datePreset.set(preset);
    this.customStartDate.set(null);
    this.customEndDate.set(null);
  }

  toggleWeekendFilter(): void {
    this.excludeWeekends.update((val) => !val);
  }

  setCustomRange(start: Date, end: Date): void {
    this.customStartDate.set(start);
    this.customEndDate.set(end);
  }

  selectMetric(metric: 'working_time' | 'phone_usage' | 'inactivity' | 'focus_score'): void {
    this.selectedMetric.set(metric);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Template Helpers
  // ─────────────────────────────────────────────────────────────────────────

  get currentPresetLabel(): string {
    return this.dateRange().description;
  }

  get isLoading(): boolean {
    return this.dashboardState().loading === 'loading';
  }

  get isError(): boolean {
    return this.dashboardState().loading === 'error';
  }

  get errorMessage(): string | undefined {
    return this.dashboardState().error;
  }
}
