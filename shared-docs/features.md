# MQ Monitoring — Implemented Features

> **Scope:** Documents what is currently built and working in the product.
> Does not include planned or speculative features.
> Last updated: 2026-03-25.

---

## 1. Authentication & Session Management

| Feature | Status | Notes |
|---|---|---|
| Login page with email + password form | ✅ | Dark-themed, responsive. Visible error on bad credentials or deactivated account. |
| Laravel Sanctum bearer-token issuance | ✅ | `POST /api/auth/login` returns `{ token, user }` |
| Session bootstrap on app load | ✅ | `APP_INITIALIZER` calls `GET /api/auth/me` before any route guard evaluates; no visible flash on refresh |
| Token attached to all API requests | ✅ | `authInterceptor` adds `Authorization: Bearer` header automatically |
| Automatic redirect to `/login` on `401` | ✅ | `authInterceptor` clears local state; `authGuard` redirects unauthenticated navigation |
| Role-based route guard | ✅ | `adminGuard` protects `/admin/users`; non-admin users redirected to `/surveillance` |
| Client-side logout | ✅ | Clears token and user signal; redirects to `/login` |
| Inactive account rejection | ✅ | Users with `is_active = false` receive `403` on login |

---

## 2. Admin — User Management

> Route: `/admin/users` — visible only to users with `role = 'admin'`.

| Feature | Status | Notes |
|---|---|---|
| User list table | ✅ | Columns: name, email, role, supervisor, `surveillance_identity`, active status |
| Client-side search | ✅ | Filters by name or email; instant, no re-fetch |
| Status filter (All / Active / Inactive) | ✅ | Client-side signal-based filter |
| Pagination | ✅ | Configurable page size; adapts to filtered result count |
| Create user | ✅ | Form: name, email, password, role, supervisor (if viewer), `surveillance_identity` |
| Edit user (partial update) | ✅ | Same fields as create; `PATCH /api/users/{id}` |
| Activate / Deactivate user | ✅ | Toggle button; sends `PATCH { is_active }` |
| Assign superviseur | ✅ | Dropdown shows only `superviseur`-role users; validated server-side |
| Set `surveillance_identity` | ✅ | Links a user account to their surveillance name for scope resolution |
| Stats popup (eye-icon button) | ✅ | Modal overlay showing the user's live activity overview for today — uses surveillance analytics API scoped to that identity |

---

## 3. Surveillance Analytics — Dashboard

> Route: `/surveillance` — accessible by all authenticated roles.
> Data is role-scoped on the backend before reaching the UI.

| Feature | Status | Notes |
|---|---|---|
| Explicit-load model | ✅ | Dashboard opens idle; user selects date range then clicks **Load** |
| Date presets | ✅ | Today, Yesterday, Last 7 days, This month |
| Custom date range (from / to pickers) | ✅ | Same-day correct via `toStartOfDay` / `toEndOfDay` helpers |
| Include unknown identities toggle | ✅ | Checkbox; controls `include_unknown` query param |
| KPI cards — Total Time, Events, Top Activity, Identities | ✅ | Derived from overview + identities API responses |
| Activity distribution bar/chart | ✅ | Proportional bars per activity; colour-coded |
| Identity summary table | ✅ | Rows: name, total time, top activity, event count; click to open full report |
| Role-aware page subtitle | ✅ | Superviseur sees "Team activity overview…"; admin/viewer sees standard text |
| Supervisor team panel | ✅ | Shown only for `superviseur` role; see §4 |
| Error state on API failure | ✅ | Friendly error panel with Retry button |
| Loading state | ✅ | Spinner and disabled Load button during fetch |
| Empty / idle state | ✅ | Instructional panel before first load |

---

## 4. Surveillance Analytics — Supervisor Team Panel

> Shown inside `/surveillance` only when `auth.user().role === 'superviseur'`.

| Feature | Status | Notes |
|---|---|---|
| Team KPI cards | ✅ | Team Size (with active count), Working Time %, Inactive/Phone %, Team Total Time |
| Ranked employee table | ✅ | One row per assigned employee, sorted by total time descending |
| Per-employee activity columns | ✅ | Working, Inactive, Phone — pre-formatted durations as coloured badges |
| "View Report" button per employee | ✅ | Navigates to `/surveillance/identity/:name` for that employee |
| Empty state | ✅ | Message shown if no employees have activity in the selected period |

---

## 5. Surveillance Analytics — Identity Full Report

> Route: `/surveillance/identity/:name` — accessible by all roles (scope-enforced on backend).

| Feature | Status | Notes |
|---|---|---|
| Per-identity activity duration bar chart | ✅ | Total time per activity for the selected window |
| Per-identity segment count bar chart | ✅ | Event row count per activity (side-by-side with duration chart) |
| Timeline flow strip | ✅ | Horizontal colour-coded segment visualisation ordered by time |
| Activity breakdown table | ✅ | Activity, duration, segment count, share % — sorted by duration |
| Ordered segments list | ✅ | Full list of activity segments with start/end times, confidence, source, trigger |
| Date range filter on detail page | ✅ | Independent from the dashboard filter; Clear button resets to full history |
| **Export CSV** button | ✅ | Downloads `;`-delimited CSV for the current identity and date window; see §6 |
| Back navigation | ✅ | "← Back" link returns to the dashboard with previous state intact |
| Scope enforcement | ✅ | Backend returns `403 Forbidden` if the identity is outside the user's scope; Angular shows error state |

---

## 6. CSV Export

| Feature | Status | Notes |
|---|---|---|
| Endpoint | ✅ | `GET /api/monitoring/surveillance/identities/{name}/export/csv` |
| Authentication | ✅ | Requires valid bearer token; scope-enforced (403 if out-of-scope) |
| Date filter applied | ✅ | Same `start`/`end` parameters as the timeline endpoint |
| Downloaded via Angular HTTP (not raw link) | ✅ | Uses `responseType: 'blob'` + programmatic anchor; auth header is always sent |
| File naming | ✅ | `<identity_name>_timeline.csv` |
| Delimiter | ✅ | `;` (semicolon) — compatible with European-locale Microsoft Excel |
| Columns | ✅ | `timestamp_start`, `timestamp_end`, `duration_sec`, `activity`, `identity_confidence`, `identity_source`, `event_trigger` |
| Header row | ✅ | First row of the CSV |

---

## 7. Python Surveillance Runtime

> This module runs separately and is not part of the web application.
> It is documented here for completeness.

| Feature | Status | Notes |
|---|---|---|
| Camera / video feed ingestion | ✅ | Configurable source |
| Person detection | ✅ | YOLO11 (`yolo11s.pt` / `yolo11n.pt`) |
| Multi-person tracking | ✅ | DeepSORT — produces per-session `track_id` |
| Face-based identity resolution | ✅ | ArcFace ONNX, cosine similarity, enrolled embeddings under `models/embeddings/` |
| Liveness check | ✅ | MiniFASNet anti-spoofing applied at identity resolution time |
| Activity classification | ✅ | Secondary YOLO model; 5 classes: `Working`, `Meeting`, `Inactive`, `Using_Phone`, `Unknown` |
| SQLite event logging | ✅ | Append-only writes to `logs/surveillance_events.db` |
| CSV mirror | ✅ | Human-readable copy at `logs/surveillance_events.csv` |

---

## 8. What Is Not Implemented

| Item | Notes |
|---|---|
| Bulk or overview CSV export | Only single-identity timeline export exists |
| Scheduled / emailed reports | No queue-based reporting |
| PDF export | Not implemented |
| Time-series / trend analytics | All queries are window-aggregate; no day-by-day breakdown |
| Multi-camera or multi-site scoping | Database has no `camera_id` or `site_id` column |
| Audit log (who queried what) | No server-side request logging per user |
| AI/LLM-generated narrative summaries | Not in scope for V1 |
| Self-service password reset | Not implemented |
| OAuth / SSO | Not in scope for V1 |

Full details: [v1-limitations-and-roadmap.md](v1-limitations-and-roadmap.md).
