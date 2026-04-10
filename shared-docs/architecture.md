# MQ Monitoring — System Architecture (V1)

> **Scope:** Surveillance analytics module, authentication, and role-based
> access control.
>
> **Out of scope for this document:**
> - Face enrollment and anti-spoofing (`src/recognition/`) — handled by a
>   separate check-in pipeline and not exposed through the analytics dashboard.
> - Model training pipelines (`train_activity.py`, YOLO fine-tuning).

---

## 1. Layer Overview

```
┌─────────────────────────────────────────────────────────────────┐
│  Python Runtime  (surveillance/)                                │
│  Camera feed → YOLO detection → DeepSORT tracking →            │
│  ArcFace identity resolution → Activity classification →        │
│  EventLogger → surveillance_events (SQLite / CSV)              │
└───────────────────────────┬─────────────────────────────────────┘
                            │  append-only writes
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  SQLite database   (logs/surveillance_events.db)               │
│  Shared file accessible by both Python and Laravel.            │
│  Schema is fixed by the Python runtime — Laravel reads only.   │
└───────────────────────────┬─────────────────────────────────────┘
                            │  read-only SQL queries
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  Laravel 12 API  (mq-monitoring-api/)                          │
│  SurveillanceAnalyticsController                               │
│  SurveillanceAnalyticsService                                  │
│  FormRequest validation                                         │
│  JSON responses                                                 │
└───────────────────────────┬─────────────────────────────────────┘
                            │  HTTP / JSON over localhost proxy
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  Angular 21 SPA  (mq-monitoring-web/)                          │
│  SurveillanceService (HttpClient)                              │
│  Dashboard page — overview + identities                        │
│  Identity detail page — per-identity timeline                  │
│  Activity chart, identity table, timeline, KPI cards           │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Layer Responsibilities

### 2.1  Python Runtime

| Concern | Detail |
|---|---|
| **Frame ingestion** | Reads from a local camera or video file |
| **Person detection** | YOLO11 object detector (yolo11s.pt / yolo11n.pt) |
| **Person tracking** | DeepSORT — produces integer `track_id` values per run session |
| **Identity resolution** | ArcFace ONNX model, cosine-similarity match against enrolled embeddings; liveness check via MiniFASNet |
| **Activity classification** | Secondary YOLO model trained on 5 classes: `Working`, `Meeting`, `Inactive`, `Using_Phone`, `Unknown` |
| **Event logging** | `EventLogger` writes closed activity segments (one row per segment) to SQLite and/or CSV |
| **Boundary** | Python **never reads** from the analytics database; flow is append-only |

### 2.2  SQLite Database

- Single `.db` file on disk, shared via filesystem.
- **Schema owner:** Python runtime — Laravel must never alter the schema.
- **Table:** `surveillance_events` (see [api-contract.md](api-contract.md) for column definitions).
- **Access pattern:** PHP opens the file read-only via PDO; Python appends rows via `sqlite3`.
- **Transitional:** SQLite is suitable for single-machine demos and PFE
  evaluation. A migration to PostgreSQL or MySQL is planned for
  multi-site deployments (see [v1-limitations-and-roadmap.md](v1-limitations-and-roadmap.md)).

### 2.3  Laravel 12 API

| Concern | Detail |
|---|---|
| **Authentication** | Laravel Sanctum token authentication. `POST /api/auth/login` issues a bearer token; `GET /api/auth/me` returns the current user profile. All surveillance endpoints require a valid token via `auth:sanctum` middleware. |
| **Role-based scoping** | `SurveillanceAnalyticsService::resolveScope()` derives the allowed identity list from the authenticated user's role. `admin` → unrestricted; `superviseur` → assigned employees + self; `viewer` → self only. |
| **User management** | `UserController` exposes admin-only CRUD (list, create, partial update) under `GET/POST/PATCH /api/users`. Guarded by both `auth:sanctum` and `EnsureAdmin` middleware. |
| **Validation** | `FormRequest` classes enforce field types, date ordering, and enum membership before any query runs |
| **Business logic** | `SurveillanceAnalyticsService` executes aggregation queries and shapes results |
| **Transport** | JSON responses over HTTP; Angular dev proxy forwards `/api/*` to Laravel |
| **Write safety** | Surveillance controllers perform **zero write operations** — all event mutations live in Python |
| **Error surface** | `503 Service Unavailable` when the SQLite file is absent or locked; `422 Unprocessable Entity` for validation failures; `401` for unauthenticated requests; `403` for out-of-scope identity requests |

### 2.4  Angular 21 SPA

| Concern | Detail |
|---|---|
| **Auth module** | `AuthService` manages the current user signal (`auth.user()`) and bearer token storage. `APP_INITIALIZER` calls `auth.bootstrap()` before any route guard evaluates. `authInterceptor` attaches the `Authorization: Bearer` header to all `/api` requests. |
| **Route guards** | `authGuard` redirects unauthenticated users to `/login`. `adminGuard` restricts `/admin/users` to the `admin` role. Guards are UX convenience only — the backend is the enforceable boundary. |
| **Admin module** | `/admin/users` page: user list with client-side search, status filter (All/Active/Inactive), pagination, inline stats popup, and user create/edit form. |
| **Surveillance module** | `/surveillance` dashboard: role-aware KPIs, activity chart, identity table; supervisor-only team panel with per-employee metrics. `/surveillance/identity/:name`: full report with dual charts, timeline, activity breakdown table, and CSV export. |
| **HTTP layer** | `SurveillanceService` and `UserService` — `HttpClient`-based injectables. |
| **Parameter encoding** | `toHttpParams()` helper: booleans → `"1"`/`"0"`, arrays → repeated `key[]` params (Laravel convention) |
| **Date handling** | UI inputs hold bare `YYYY-MM-DD` strings; `toStartOfDay()` / `toEndOfDay()` expand to `YYYY-MM-DD HH:MM:SS` before requests are sent |
| **State management** | Angular signals (`signal`, `computed`) — no NgRx or external store |
| **Routing** | `withComponentInputBinding()` binds route params directly to component inputs |

---

## 3. Data Flow — Request Lifecycle

```
User selects date range → clicks "Load"
(Dashboard opens in idle state — data loads only on explicit user action.)
    │
    ▼
dashboard.component.ts
  filterStart → toStartOfDay() → "2026-03-24 00:00:00"
  filterEnd   → toEndOfDay()   → "2026-03-24 23:59:59"
    │
    ▼
SurveillanceService.getOverview(params)     ─┐  forkJoin
SurveillanceService.getIdentities(params)   ─┤  (parallel)
  → toHttpParams() serialises booleans and arrays
  → HttpClient GET /api/monitoring/surveillance/overview?start=...&end=...
                GET /api/monitoring/surveillance/identities?start=...&end=...
    │
    ▼  (via Angular dev proxy :4200 → Laravel :8081)
OverviewRequest / IdentitiesRequest (FormRequest validation)
    │
    ▼
SurveillanceAnalyticsService
  → SQL GROUP BY queries on surveillance_events
  → returns meta + totals, distribution, identities[]
    │
    ▼
JSON response → Angular signals → computed KPI cards, chart, table
```

---

## 4. Why This Structure Is Production-Minded

1. **Separation of concerns at process boundaries.**  
   Python owns data production; Laravel owns read aggregation; Angular owns
   presentation. None of these layers has write access to another's state.

2. **Schema contract is version-explicit.**  
   `event_logger.py` documents each column with a stability guarantee
   ("append-only; do not reorder"). Laravel queries only stable columns.

3. **Validation at the API boundary.**  
   `FormRequest` classes reject invalid inputs before any SQL executes,
   preventing injection vectors and providing clear 422 error shapes.

4. **Typed frontend contract.**  
   Angular models (`OverviewResponse`, `IdentityEntry`, `TimelineSegment`)
   mirror the exact JSON shape — TypeScript will surface contract drift at
   compile time.

5. **No tight coupling between Python and Laravel.**  
   The shared SQLite file is the only integration point. Either layer can be
   swapped (e.g., replace Laravel with another API framework) without
   touching the other.

---

## 5. Directory Reference

```
intelligent-monitoring-system/
├── surveillance/          Python runtime — detection, tracking, activity, logging
├── src/recognition/       Face enrollment, embedding generation, ArcFace ONNX
│                          (check-in / anti-spoof pipeline — separate from analytics)
├── models/                ONNX and PyTorch model files
├── logs/
│   ├── surveillance_events.db    Shared SQLite database (written by Python, read by Laravel)
│   └── surveillance_events.csv   Human-readable mirror of the SQLite events
├── mq-monitoring-api/     Laravel 12 API  [dev port: 8081]
│   ├── app/Http/Controllers/Auth/           Login, me
│   ├── app/Http/Controllers/Monitoring/     Surveillance analytics, CSV export
│   ├── app/Http/Controllers/Users/          Admin user management
│   ├── app/Http/Requests/Monitoring/        FormRequest validation
│   ├── app/Http/Middleware/                  EnsureAdmin
│   ├── app/Services/Monitoring/             SurveillanceAnalyticsService (query + scoping)
│   └── app/Models/                          User model
├── mq-monitoring-web/     Angular 21 SPA  [dev port: 4200, proxies /api → :8081]
│   └── src/app/
│       ├── core/auth/             AuthService, authGuard, adminGuard, authInterceptor
│       ├── core/interceptors/     Auth header + base URL interceptors
│       ├── features/auth/         Login page
│       ├── features/admin/users/  Admin user management page
│       └── features/surveillance/
│           ├── pages/             dashboard/, identity-detail/
│           ├── components/        activity-chart/, identity-table/, timeline/, kpi-cards/
│           ├── services/          surveillance.service.ts
│           ├── models/            overview.model.ts, identities.model.ts, timeline.model.ts
│           └── utils/             activity-colors.util.ts
└── shared-docs/           Cross-module documentation (this folder)
```
