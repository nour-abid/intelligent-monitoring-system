# Smoke Test Report — V1 Surveillance Vertical Slice
**Date:** 2026-03-25
**Phase:** 0 — validate existing implementation before any architectural changes.
**Status: ALL AUTOMATED CHECKS PASS — NO BLOCKERS FOUND**

---

## 1. Files Inspected

### Laravel
| File | Status |
|---|---|
| `mq-monitoring-api/routes/api.php` | ✅ Clean |
| `mq-monitoring-api/app/Http/Controllers/Monitoring/SurveillanceAnalyticsController.php` | ✅ Clean |
| `mq-monitoring-api/app/Services/Monitoring/SurveillanceAnalyticsService.php` | ✅ Clean |
| `mq-monitoring-api/app/Http/Requests/Monitoring/OverviewRequest.php` | ✅ Clean |
| `mq-monitoring-api/app/Http/Requests/Monitoring/IdentitiesRequest.php` | ✅ Clean |
| `mq-monitoring-api/app/Http/Requests/Monitoring/TimelineRequest.php` | ✅ Clean |
| `mq-monitoring-api/app/Providers/AppServiceProvider.php` | ✅ Clean |
| `mq-monitoring-api/config/database.php` | ✅ Clean |
| `mq-monitoring-api/bootstrap/app.php` | ✅ Clean |
| `mq-monitoring-api/.env` | ⚠️ `APP_URL` port 8080 ≠ proxy port 8081 — harmless (see §7) |

### Angular — TypeScript / Logic
| File | Status |
|---|---|
| `mq-monitoring-web/proxy.conf.json` | ✅ Wired to :8081, referenced in `angular.json` |
| `mq-monitoring-web/src/app/app.routes.ts` | ✅ Clean |
| `mq-monitoring-web/src/app/app.config.ts` | ✅ `withComponentInputBinding()` present |
| `mq-monitoring-web/src/app/core/interceptors/api-base-url.interceptor.ts` | ✅ Dev pass-through (apiBaseUrl = '') |
| `mq-monitoring-web/src/app/core/utils/duration.util.ts` | ✅ All 4 presets + same-day correct |
| `mq-monitoring-web/src/app/shared/pipes/duration.pipe.ts` | ✅ Handles null/undefined |
| `mq-monitoring-web/src/app/features/surveillance/services/surveillance.service.ts` | ✅ Clean |
| `mq-monitoring-web/src/app/features/surveillance/models/overview.model.ts` | ✅ Matches live API |
| `mq-monitoring-web/src/app/features/surveillance/models/identities.model.ts` | ✅ Matches live API |
| `mq-monitoring-web/src/app/features/surveillance/models/timeline.model.ts` | ✅ Matches live API |
| `mq-monitoring-web/src/app/features/surveillance/utils/activity-colors.util.ts` | ✅ All live activities mapped |
| `mq-monitoring-web/src/styles.scss` | ✅ All badge/spinner/state-panel classes defined |

### Angular — Components & Templates
| File | Status |
|---|---|
| `pages/dashboard/dashboard.component.ts` | ✅ Clean |
| `pages/dashboard/dashboard.component.html` | ✅ All states, preset buttons, guards correct |
| `pages/identity-detail/identity-detail.component.ts` | ✅ Clean |
| `pages/identity-detail/identity-detail.component.html` | ✅ Back link, clear button, all states correct |
| `components/kpi-cards/kpi-cards.component.{ts,html}` | ✅ Purely presentational, clean |
| `components/activity-chart/activity-chart.component.{ts,html}` | ✅ Empty state handled |
| `components/identity-table/identity-table.component.{ts,html}` | ✅ stopPropagation on view button correct |
| `components/timeline/timeline.component.{ts,html}` | ✅ All 8 segment fields used |

### Data
| Resource | Status |
|---|---|
| `logs/surveillance_events.db` | ✅ Present (24 KB) — events on 2026-03-24 and 2026-03-25 |

---

## 2. Build & Compilation Checks

| Check | Command | Result |
|---|---|---|
| TypeScript strict compile | `tsc --noEmit --project tsconfig.app.json` | ✅ **Zero errors** |
| Angular full build (dev) | `ng build --configuration development` | ✅ **Zero errors, zero warnings** |
| Lazy chunk output | `dashboard-component`, `identity-detail-component` | ✅ Both generated cleanly |

---

## 3. API Live Tests

Servers at test time: Laravel :8081 ✅ listening, Angular dev server :4200 ✅ listening.
All tests use `Accept: application/json` (replicating Angular `HttpClient` behavior).

### 3.1 Overview — `GET /api/monitoring/surveillance/overview`

| Test | Result |
|---|---|
| HTTP 200 | ✅ |
| `meta`, `totals`, `total_sec`, `event_count`, `distribution` all present | ✅ |
| Numerics are JSON numbers (not strings) | ✅ |
| `include_unknown` defaults to `true` | ✅ |
| Through Angular proxy (:4200) | ✅ |

**Sample (today):**
```json
{"meta":{"start":"2026-03-25 00:00:00","end":"2026-03-25 23:59:59","include_unknown":true,"include_triggers":[]},
 "totals":{"Inactive":2.87},"total_sec":2.87,"event_count":1,"distribution":{"Inactive":1}}
```

### 3.2 Identities — `GET /api/monitoring/surveillance/identities`

| Test | Result |
|---|---|
| HTTP 200 | ✅ |
| `meta` + `identities` array | ✅ |
| Each entry: `identity_name`, `total_sec` (float), `event_count` (int), `activities` (object) | ✅ |
| `include_unknown=0` excludes "Unknown" | ✅ |
| `include_unknown=1` includes "Unknown" | ✅ |
| Sorted descending by `total_sec` | ✅ |
| Through Angular proxy | ✅ |

**Real identity confirmed:** `bellaaj` (total_sec: 77.58, event_count: 3)

### 3.3 Timeline — `GET /api/monitoring/surveillance/identities/{name}/timeline`

| Test | Result |
|---|---|
| HTTP 200 | ✅ |
| `identity`, `count`, `segments` in body | ✅ |
| All 8 segment fields present per segment | ✅ |
| No date filter (full history) — count: 3 | ✅ |
| Same-day date filter (2026-03-24) — count: 3 | ✅ |
| Through Angular proxy | ✅ |

---

## 4. Date Preset Live Results

| Preset | Date Range Sent | HTTP | event_count |
|---|---|---|---|
| Today | `2026-03-25 00:00:00` → `2026-03-25 23:59:59` | ✅ 200 | 1 |
| Yesterday | `2026-03-24 00:00:00` → `2026-03-24 23:59:59` | ✅ 200 | 9 |
| Last 7 days | `2026-03-19 00:00:00` → `2026-03-25 23:59:59` | ✅ 200 | 10 |
| This month | `2026-03-01 00:00:00` → `2026-03-25 23:59:59` | ✅ 200 | 10 |

---

## 5. Edge Case Results

| Case | Expected | Result |
|---|---|---|
| Empty range (far-future date, no rows) | `event_count: 0`, empty totals | ✅ |
| Invalid: end before start | HTTP 422 + JSON `errors.end` | ✅ `{"message":"The end date must be on or after the start date.",...}` |
| Same-day range | HTTP 200 | ✅ |
| `include_unknown=0` | Excludes Unknown | ✅ |
| `include_unknown=1` | Includes Unknown | ✅ |

**Validation note:** Without `Accept: application/json` header, Laravel returns 302 (standard web redirect). Angular `HttpClient` always adds `Accept: application/json`, so it always receives 422 JSON. **Not a bug.**

---

## 6. Angular Template Static Verification

| Check | Result |
|---|---|
| `@if`/`@for` use Angular 17 control-flow (no CommonModule needed) | ✅ |
| `FormsModule` imported in both page components for `ngModel` | ✅ |
| `RouterLink` imported in identity-detail for back link | ✅ |
| `DurationPipe` standalone, imported in identity-table and timeline | ✅ |
| All badge classes referenced in templates exist in `styles.scss` | ✅ |
| `activityColor`/`activityBadgeClass` have fallbacks for unknown labels | ✅ |
| Identity-table view button: `$event.stopPropagation()` prevents double-fire | ✅ |
| Identity-detail: `name()[0]?.toUpperCase()` optional chaining — no crash | ✅ |
| Dashboard `forkJoin` loads overview + identities in parallel | ✅ |
| Dashboard `load()` guard: short-circuits if start > end (no bad API call) | ✅ |
| `identitySelected` output wired to `openIdentity($event)` in dashboard template | ✅ |
| Identity-detail `clearFilter()`: sends request with no `start`/`end` params | ✅ |
| Identity-detail `effect()`: re-fetches on `name` signal change | ✅ |
| Timeline `track` expression: `seg.timestamp_start + seg.track_id` — unique per segment | ✅ |

---

## 7. Behavioral Notes (not bugs)

| Item | Detail |
|---|---|
| **Dashboard opens in idle state (no auto-load)** | `loadState` initialises to `'idle'`. "Today" preset button is highlighted but data only loads when the user explicitly clicks Load or a preset button. By design — an explicit-load page. |
| KPI cards / chart / table only appear after first load | Correct — guarded by `@if (loadState() === 'success')`. |
| `.env` `APP_URL` port 8080 ≠ proxy target 8081 | `APP_URL` is URL-generation metadata only, not the listen port. Laravel must be started with `php artisan serve --port=8081`. Not a code bug. |
| Empty `totals`/`distribution` serialize as `[]` not `{}` | PHP empty associative array serialises to JSON array. `Object.entries([])` returns `[]` — same as `Object.entries({})`. Activity chart correctly shows its empty-state message. Non-blocking. |
| Route constraint `[A-Za-z][A-Za-z0-9_\-]*` | Identity names must start with a letter. `bellaaj` confirmed matching. Acceptable for current enrolled-identity workflow. |
| Interceptor inline comment says `:8080` | Stale doc comment. Actual behaviour (dev pass-through) is correct. |

---

## 8. Final Result Summary

| Area | Result | Notes |
|---|---|---|
| `overview` endpoint | ✅ **PASS** | Correct shape, all presets verified live |
| `identities` endpoint | ✅ **PASS** | Correct structure, Unknown toggling works |
| `timeline` endpoint | ✅ **PASS** | All 8 fields, same-day filter confirmed |
| Angular dev proxy | ✅ **PASS** | All 3 endpoints through :4200 → :8081 |
| Date presets (API layer) | ✅ **PASS** | All 4 presets return 200 |
| Same-day behavior | ✅ **PASS** | start=T00:00:00, end=T23:59:59 accepted |
| Empty range | ✅ **PASS** | Returns zeros, no crash |
| Validation (end < start) | ✅ **PASS** | 422 + JSON error |
| TypeScript compile | ✅ **PASS** | Zero errors |
| Angular full build | ✅ **PASS** | Zero errors, zero warnings |
| Angular SPA routes (HTTP) | ✅ **PASS** | Both routes return 200 from dev server |
| All templates inspected | ✅ **PASS** | No logic errors, all bindings correct |
| All CSS classes verified | ✅ **PASS** | All referenced classes present |
| Activity colour mappings | ✅ **PASS** | Working / Inactive / Using_Phone all mapped |
| Browser UI rendering | ⬜ **PENDING** | Manual browser — no code-level blockers found |
| Browser console errors | ⬜ **PENDING** | Manual browser — no error paths in code |

**No blockers found. No code was modified.**

---

## 9. Files Modified

**None.** The complete V1 surveillance vertical slice passed all automated, live, and static verification checks without requiring any code changes.

---

## 10. Remaining Manual Browser Checklist

> Open browser DevTools (F12 → Console + Network tabs). No blockers expected.

**Dashboard — `http://localhost:4200/surveillance`:**
- [ ] Page renders without blank screen
- [ ] "Today" preset button is highlighted on load (no data shown yet — idle state by design)
- [ ] Click **Load** → KPI cards appear (Total Time, Events Recorded, Top Activity, Identities)
- [ ] Activity chart shows "Inactive" bar for today
- [ ] Click **Yesterday** → identity table shows "bellaaj"; chart shows Using_Phone + Working + Inactive
- [ ] Click **Last 7 days** → data updates
- [ ] Click **This month** → data updates
- [ ] Manually type `2026-03-24` in both date fields → click Load → data appears; preset highlight clears
- [ ] Set start=`2026-03-25`, end=`2026-03-24` → click Load → client-side error shown; no network request fired
- [ ] No errors in Console tab
- [ ] No 4xx / 5xx in Network tab

**Identity Detail — `http://localhost:4200/surveillance/identity/bellaaj`:**
- [ ] Route resolves; heading shows "bellaaj"; avatar shows "B"
- [ ] 3 segments displayed in timeline (Working + 2× Using_Phone)
- [ ] Activity summary pills: Using_Phone + Working
- [ ] Click **Yesterday** → timeline re-fetches with date params; still 3 segments
- [ ] Click **Clear** → Clear button hides; timeline re-fetches with no date params; header shows "All time"
- [ ] Back link (`← Surveillance Dashboard`) navigates to `/surveillance`
- [ ] No errors in Console tab
- [ ] No 4xx / 5xx in Network tab
