# Viewer Dashboard Redesign Proposal

**Status:** Ready for Implementation  
**Date:** April 13, 2026  
**Risk Level:** Low (new component path, no admin/supervisor changes)  

---

## 1. Problem Statement

**Current Issues:**
- Viewer sees admin/supervisor dashboard with misleading team-level metrics ("identified: 1", "active employees", "total alerts")
- Multi-user analytics (identity summary table, activity by employee) don't apply to single-person view
- Page is dense and mixes operational concerns (team alerts, HR metrics) with personal self-insight
- No business-day-aware filtering (weekends, holidays included)
- Data is all aggregated — viewers need time-series trends for their own behavior

**Example Pain Points:**
- Viewer sees "Total Alerts: 2" but doesn't know what they mean (team-level metric)
- Viewer sees "Identified: 1" (redundant, already filtered to self)
- Viewer sees "Activity by Employee" chart with one bar (pointless visualization)
- Filters include weekends even though work week is Mon-Fri

---

## 2. Proposed Information Architecture

### Viewer Dashboard Sections (Prioritized)

#### **Header: Quick Stats (4 cards)**
1. **Observed Time (Today)**
   - Large: `HH:MM` total duration
   - Subtitle: "Last 5 days avg: HH:MM"
   - Color: Primary (blue)

2. **Top Activity (Today)**
   - Large: Activity name (e.g., "Working")
   - Subtitle: Duration / percentage of day
   - Color: Dynamic (activity color)

3. **Phone Usage (Today)**
   - Large: `MM` minutes
   - Subtitle: "% of observed time"
   - Color: Accent (orange/red if >30min)

4. **Focus Score (Today)**
   - Large: Percentage `XX%`
   - Subtitle: Label (Excellent/Good/Moderate/Low)
   - Color: Green/Yellow/Red based on score

#### **Section: Personal Metrics Bar** (6 items, horizontal scroll on mobile)
1. Late Arrivals (This Month) — count
2. Early Leaves (This Month) — count
3. Inactivity Events (Today) — count or "None"
4. Longest Focus Block (Today) — duration
5. Meetings (Today) — count
6. Total Break Time (Today) — duration

#### **Section: Personal Time-Series Chart**
- **Title:** "Your Activity Trend (Last 7 Days)"
- **Controls:** 
  - Date preset buttons: Today, Last 7, Last 30, Custom
  - **Metric toggle** (4 options):
    - Working Time (primary metric)
    - Phone Usage (minutes)
    - Inactivity (count of events)
    - Focus Score (daily %)
- **Chart:** Line chart with daily values, mobile-responsive
- **Data source:** `/api/monitoring/surveillance/identities/{self}/timeline` grouped by day

#### **Section: Personal Activity Breakdown**
- **Title:** "Your Activity Distribution (Last 7 Days)"
- **Chart:** Doughnut/pie showing Working / Meeting / Inactive / Using_Phone shares
- **Legend:** Shows actual durations (HH:MM) for each
- **Data source:** `/api/monitoring/surveillance/overview` scoped to self

#### **Section: Personal Daily Breakdown** (Collapsible table)
- **Title:** "Day-by-Day Details"
- **Columns:** Date, Observed Time, Top Activity, Phone Usage, Focus Score, Late Arrival, Early Leave
- **Rows:** One row per day (last 7-30 days)
- **Mobile:** Stack columns, show as cards
- **Data source:** Pre-computed from timeline data or new backend aggregation

#### **Section: Personal Insights & Coaching**
- **Title:** "Your Week Insights & Coaching"
- **Content:** 3-5 auto-generated actionable insights:
  - "You had high phone usage (45min) on Wed — consider scheduling focus time"
  - "Good focus this week! 3 blocks of 60+ min focus"
  - "You left 20min early on Tue and Wed — monitor time management"
  - "Latest arrival: 8:15am on Thu (15min late)"
  - "Suggestion: Your most productive time is 10am-12pm — schedule important work then"
- **Rules-based generation** (threshold: phone >30min, inactivity clusters, arrivals, departures)
- **No severity badges** (those are for operations/HR, not personal insight)

#### **Section: Business Day Filter** (Sidebar or sticky header)
- Preset buttons: Today, This Week, Last 7 Days, Last 30, Custom Range
- **Checkbox:** "Exclude Weekends & Holidays" (default: ON)
- **Date picker:** Calendar (highlights business days, grays out weekends/holidays)
- Business day logic: Mon-Fri, exclude predefined holidays

---

## 3. Technical Design

### 3.1 New Components (Angular)

#### **File Structure**
```
mq-monitoring-web/src/app/features/surveillance/pages/
├── dashboard/                          [UNCHANGED - admin/supervisor]
│   ├── dashboard.component.ts
│   ├── dashboard.component.html
│   └── dashboard.component.scss
└── viewer-dashboard/                   [NEW]
    ├── viewer-dashboard.component.ts       (standalone)
    ├── viewer-dashboard.component.html
    ├── viewer-dashboard.component.scss
    ├── components/
    │   ├── personal-stats-cards/          (4 KPI cards)
    │   ├── personal-metrics-strip/        (6 horizontal metrics)
    │   ├── personal-time-series/          (line chart with metric toggle)
    │   ├── personal-activity-breakdown/   (doughnut chart)
    │   ├── personal-daily-breakdown/      (table/card list)
    │   └── personal-insights-box/         (coaching + recommendations)
    └── services/
        └── business-day-filter.service.ts (date filtering utility)
```

#### **New Components**

**1. `viewer-dashboard.component.ts`** (Main container)
- Signals: `startDate`, `endDate`, `excludeWeekends`, `selectedMetric`
- Loads: `forkJoin()` of `overview()` and `timeline()`
- Scoped to current user's `surveillance_identity`
- Stateful filter management
- **Size:** ~150 LOC

**2. `personal-time-series.component.ts`** (Reusable chart)
- Input: `data: DailySummary[]`, `selectedMetric: Signal<string>`
- Renders different line series based on selected metric
- Uses existing chart library (Chart.js or Angular Material)
- **Size:** ~120 LOC

**3. `personal-insights-box.component.ts`** (Insights generation)
- Input: `summary: DashboardSummaryResponse`, `timeline: TimelineEntry[]`
- Computes insights array (5 max)
- No severity levels, only actionable advice
- **Size:** ~100 LOC

**4. `business-day-filter.service.ts`** (Utility)
- Method: `getBusinessDateRange(preset: string, excludeWeekends: boolean): [Date, Date]`
- Hardcoded holidays for 2026 (Tunisia-based or custom)
- Reusable across dashboards
- **Size:** ~80 LOC

### 3.2 Backend Changes (Minimal)

**No new endpoints required.** Existing endpoints already support role-based scoping:
- `/identities/{name}/timeline` — when name = current user's `surveillance_identity`, returns personal timeline
- `/overview` — respects role scope (viewer sees only self)
- `/identities` — respects role scope (viewer sees only self) 

**Existing scope gate in `SurveillanceAnalyticsController::resolveScope()`:**
```php
private function resolveScope(Request $request): ?array
{
    $user = $request->user();

    return match ($user->role) {
        'admin'     => null,  // Unrestricted
        'superviseur' => array_merge(
            [$user->surveillance_identity],
            $user->supervisedEmployees->pluck('surveillance_identity')->toArray()
        ),
        'viewer' => [$user->surveillance_identity],  // Only self
    };
}
```

**Action:** No changes needed. Viewer component calls existing endpoints, backend already scopes correctly.

### 3.3 Routing

#### Option A: Role-Based Route Guard (Recommended)
```typescript
// In routing module
{
  path: 'surveillance/dashboard',
  component: MainDashboardComponent,
  canActivate: [adminSuperviseurGuard],  // admin or superviseur only
},
{
  path: 'surveillance/my-insights',
  component: ViewerDashboardComponent,
  canActivate: [viewerOnlyGuard],  // viewer only
}
```

#### Option B: Conditional Rendering (Alternative)
Keep single route, conditional by role in main dashboard component.
*Less clean but lower refactoring impact.*

**Recommendation:** Option A (separate routes) — cleaner, faster for viewers, clear separation of concerns.

### 3.4 Date Filtering Logic

```typescript
// business-day-filter.service.ts

export interface DateRange {
  start: Date;
  end: Date;
}

export class BusinessDayFilterService {
  private readonly TUNISIA_HOLIDAYS_2026 = [
    '2026-01-14', // Revolution Day
    '2026-03-20', // Independence Day
    '2026-04-09', // Martyrs' Day
    '2026-05-01', // Labour Day
    '2026-07-25', // Republic Day
    '2026-08-13', // Women's Day
    '2026-10-15', // Ben Ali's Ouster (if observed)
    '2026-11-07', // Evacuation Day
  ];

  getBusinessDateRange(
    preset: 'today' | 'week' | 'month' | 'last7' | 'last30',
    excludeWeekends: boolean,
    customStart?: Date,
    customEnd?: Date
  ): DateRange {
    // Logic: calculate date range based on preset
    // If excludeWeekends: remove Sat/Sun from calculation
    // Return tz-aware DateRange
  }

  isBusinessDay(date: Date): boolean {
    const dayOfWeek = date.getDay();
    const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
    const isHoliday = this.TUNISIA_HOLIDAYS_2026.includes(
      date.toISOString().split('T')[0]
    );
    return !isWeekend && !isHoliday;
  }

  excludeWeekendsFromRange(start: Date, end: Date): DateRange {
    // Return only business days within range
  }
}
```

---

## 4. Implementation Steps (In Order)

### Phase 1: Utilities & Services (Risk: Zero)
1. Create `business-day-filter.service.ts` with full test coverage
2. Add unit tests for all date calculations
3. *Expected time: 1-2 hours*

### Phase 2: Viewer-Specific Components (Risk: Low)
4. Create viewer-dashboard layout component (placeholder children)
5. Create personal-stats-cards component
6. Create personal-metrics-strip component
7. Create personal-time-series component with metric toggle
8. Create personal-activity-breakdown component (reuse doughnut chart)
9. Create personal-daily-breakdown component
10. Create personal-insights-box component (hard-coded insights for now)
11. *Expected time: 6-8 hours*

### Phase 3: Integration (Risk: Low)
12. Wire up viewer dashboard with form state and API calls
13. Add role-based routing guard
14. Test viewer workflow end-to-end
15. *Expected time: 2-3 hours*

### Phase 4: Polish & QA (Risk: Zero)
16. Mobile responsive adjustments
17. Performance check (metrics animation, lazy loading)
18. Accessibility review (labels, contrast, keyboard nav)
19. Edge cases (empty data, loading states, error states)
20. *Expected time: 2-3 hours*

**Total Effort:** ~15-20 hours of focused development

---

## 5. Safe Implementation Guarantees

### ✅ No Changes to:
- Admin dashboard experience
- Supervisor dashboard experience
- Authentication model
- API ingestion pipeline
- Existing chart components (only called with new data)
- User model or role logic
- Database schema

### ✅ Changes Isolated To:
- New viewer-dashboard folder (no existing files touched)
- New business-day-filter service (new file, no imports elsewhere)
- New routing guard (if using Option A)
- No modifications to existing dashboard component

### ✅ Backward Compatibility:
- Existing endpoints unchanged
- Existing admin/supervisor routes preserved
- Role-based scope gate already built into backend
- No breaking changes to API contracts

---

## 6. Testing Strategy

### Unit Tests
- `business-day-filter.service.spec.ts`: 20+ test cases for date edge cases
- `personal-insights-box.component.spec.ts`: 10+ test cases for insight generation rules
- `personal-time-series.component.spec.ts`: 8+ test cases for metric toggle

### Integration Tests
- Viewer login → viewed their personal dashboard (not admin dashboard)
- Date filter preset calculations are business-day aware
- API calls scoped to self only (backend verified via role gate)
- Chart renders with personal data
- Insights generate correctly based on activity data

### E2E Tests
- Viewer logs in, clicks through all sections, all elements load
- Metric toggle changes line chart series correctly
- Date preset changes update all widgets
- Mobile view responsive

---

## 7. Migration Plan (Day-of-Deployment)

1. **Feature flag:** Hide new viewer dashboard behind `VIEWER_DASHBOARD_ENABLED` flag
2. **Deploy code** to staging, all tests pass
3. **Smoke test:** Admin/supervisor dashboard still works, no side effects
4. **Per-viewer rollout:** A/B test with 10% of viewers, then 50%, then 100%
5. **Fallback:** If issues, disable flag and viewers see old dashboard
6. **Monitor:** Check error logs, page load times, API latency

---

## 8. Success Metrics

**Post-Deployment Measurement:**
- Viewer session duration increases (more relevant content)
- Viewer bounce rate decreases
- API call latency <200ms (personal data is smaller)
- Zero critical bugs in first 48 hours
- Accessibility score remains >90

---

## 9. Appendix: Data Flow Diagram

```
Viewer Login
    ↓
Route Guard (viewerOnlyGuard)
    ↓
ViewerDashboard Component
    ├─→ API: /identities/{self}/timeline
    │   └─→ Process timeline data for daily aggregation
    ├─→ API: /overview (scoped to self)
    │   └─→ Activity distribution pie chart
    └─→ Computed Insights (from both responses)
        └─→ Display coaching messages
    ↓
    Personal Stats Cards (4 KPIs)
    Personal Metrics Strip (6 metrics)
    Personal Time-Series Chart (metric toggle)
    Personal Activity Breakdown (pie)
    Personal Daily Breakdown (table)
    Personal Insights Box (coaching)
```

---

## 10. Key Decisions Explained

| Decision | Rationale |
|----------|-----------|
| **Separate viewer-dashboard route** | Cleaner UX, faster load, prevents admin UI leaking into viewer view |
| **Reuse existing chart components** | Lower risk, consistent styling, proven in production |
| **No new backend endpoints** | Existing scope gates already handle viewer filtering; API design unchanged |
| **Business-day filtering as utility** | Reusable across dashboards later, centralizes holiday logic |
| **Per-component services** | Decoupled, testable, easy to modify one metric without side effects |
| **No severity badges for insights** | Insights are for personal awareness, not operational alerting (that's HR/ops) |
| **Mobile-first collapse strategy** | Metrics strip scrolls, tables stack into cards, cleaner on small screens |

---

## 11. Files to Create/Modify

### CREATE (New Files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/viewer-dashboard.component.ts`
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/viewer-dashboard.component.html`
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/viewer-dashboard.component.scss`
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-stats-cards/...` (3 files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-metrics-strip/...` (3 files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-time-series/...` (3 files + directive)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-activity-breakdown/...` (3 files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-daily-breakdown/...` (3 files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/components/personal-insights-box/...` (3 files)
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/services/business-day-filter.service.ts`
- `mq-monitoring-web/src/app/features/surveillance/pages/viewer-dashboard/services/business-day-filter.service.spec.ts`
- `mq-monitoring-web/src/app/shared/guards/viewer-only.guard.ts` (or in auth module)
- `mq-monitoring-web/src/app/shared/guards/admin-superviseur.guard.ts` (or in auth module)

### MODIFY (Existing Files)
- `mq-monitoring-web/src/app/app.routes.ts` — Add viewer-dashboard route and guard
- No other modifications to existing files (safe!)

---

## 12. Estimated Deliverables

✅ New viewer-dashboard component (standalone, production-ready)  
✅ 6 focused sub-components (charts, cards, insights)  
✅ Business-day filtering utility (reusable)  
✅ Unit test coverage 80%+ for new code  
✅ No breaking changes to admin/supervisor UX  
✅ Mobile-responsive design  
✅ Accessibility (WCAG 2.1 AA)  

---

**Ready to implement? Confirm and I'll proceed with Phase 1 (utilities).**
