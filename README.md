# MQ Monitoring System

Intelligent workplace monitoring system combining real-time computer-vision
surveillance with a web-based analytics dashboard.

---

## What the Current Build Includes

| Module | Status | Description |
|---|---|---|
| Python CV runtime | ✅ Active | Camera feed → YOLO detection → DeepSORT tracking → ArcFace identity → activity classification → SQLite event log |
| Laravel analytics API | ✅ Built | Read-only surveillance analytics endpoints + auth + admin user management, all over Sanctum bearer tokens |
| Authentication | ✅ Implemented | Laravel Sanctum token auth; login page, session bootstrap, auth interceptor, role-based route guards |
| Admin user management | ✅ Implemented | Admin can create, edit, and deactivate user accounts; assign employees to superviseurs; set `surveillance_identity` |
| Angular dashboard — admin | ✅ Built | `/admin/users`: user list with search, status filter, pagination, stats popup, and inline management actions |
| Angular dashboard — surveillance | ✅ Built | `/surveillance`: role-aware KPI cards, activity chart, identity table; supervisor team panel; per-identity full report; CSV export |

---

## Running Locally

### Prerequisites

| Component | Requirement |
|---|---|
| Python runtime | Python 3.10+, venv activated (`venv/`) |
| Laravel API | PHP >= 8.2, Composer |
| Angular dashboard | Node.js >= 20, npm, `@angular/cli` |

---

### 1 — Python Surveillance Runtime

```bash
# Activate the Python virtual environment
source venv/Scripts/activate   # Windows Git Bash
# or: venv\Scripts\activate    # Windows CMD

# Run the surveillance runtime
python -m surveillance.main_surveillance
```

Events are written to `logs/surveillance_events.db` (SQLite) and mirrored to
`logs/surveillance_events.csv`.

---

### 2 — Laravel Analytics API

> **Must run on port 8081.** The Angular dev proxy is hardcoded to forward
> `/api/*` requests to `http://127.0.0.1:8081`.
>
> **Database migration:** Run `php artisan migrate` once after `composer install`
> to create the `users` table (auth + roles + supervisor assignments).

```bash
cd mq-monitoring-api
composer install          # first time only
php artisan serve --port=8081
```

The API will be available at `http://localhost:8081/api/monitoring/surveillance/`.

**Environment:** The `.env` file is included and pre-configured with the correct
`SURVEILLANCE_DB_PATH` pointing to `logs/surveillance_events.db`.

---

### 3 — Angular Dashboard

```bash
cd mq-monitoring-web
npm install               # first time only
ng serve                  # starts on :4200, proxies /api → :8081
```

Open `http://localhost:4200` in your browser.

The dev proxy is configured in `proxy.conf.json` and wired automatically by
`angular.json` — no manual setup needed.

---

### Port Summary

| Service | Port | Notes |
|---|---|---|
| Angular dev server | 4200 | Serves the SPA; proxies `/api/*` to Laravel |
| Laravel API | 8081 | Must match the Angular proxy target |
| Python runtime | — | Writes directly to SQLite; no HTTP server |

---

## Application Routes

| Route | Role access | Description |
|---|---|---|
| `/login` | Public | Login form. Issues a Sanctum bearer token on success. |
| `/surveillance` | All authenticated roles | Activity overview: role-scoped KPI cards, activity chart, identity table. Superviseur users see a team panel with per-employee metrics. |
| `/surveillance/identity/:name` | All authenticated roles (scoped) | Full per-identity report: dual charts, timeline, activity breakdown, CSV export. Scope-enforced on both frontend and backend. |
| `/admin/users` | `admin` only | User management: create/edit/deactivate users, assign superviseur, set `surveillance_identity`. Includes search, status filter, pagination, and stats popup. |

**Date presets available:** Today · Yesterday · Last 7 days · This month

---

## API Endpoints (brief)

All endpoints are read-only. Full contract: [shared-docs/api-contract.md](shared-docs/api-contract.md).

| Endpoint | Description |
|---|---|
| `GET /api/monitoring/surveillance/overview` | Global activity totals and distribution for a date window |
| `GET /api/monitoring/surveillance/identities` | Per-identity breakdown for a date window |
| `GET /api/monitoring/surveillance/identities/{name}/timeline` | Ordered activity segments for one identity |

---

## Project Structure

```
intelligent-monitoring-system/
├── surveillance/           Python CV runtime (active)
│   ├── config/
│   ├── repositories/       SQLite persistence layer
│   ├── services/           Analytics service
│   └── ...
├── src/                    Face recognition / anti-spoof support code
├── models/                 ArcFace ONNX model + embeddings
├── logs/
│   ├── surveillance_events.db   Shared SQLite database
│   └── surveillance_events.csv  Human-readable mirror
├── mq-monitoring-api/      Laravel 12 REST API
├── mq-monitoring-web/      Angular 21 SPA dashboard
├── shared-docs/            Architecture, API contract, roadmap
└── venv/                   Python virtual environment
```

---

## Documentation

| Doc | Contents |
|---|---|
| [shared-docs/architecture.md](shared-docs/architecture.md) | System layers, data flow, role scoping, design rationale |
| [shared-docs/features.md](shared-docs/features.md) | Complete implemented-feature matrix |
| [shared-docs/access-model-v1.md](shared-docs/access-model-v1.md) | Role definitions, scope rules, identity matching — as implemented |
| [shared-docs/api-contract.md](shared-docs/api-contract.md) | Full endpoint reference: auth, surveillance analytics, user management |
| [shared-docs/v1-limitations-and-roadmap.md](shared-docs/v1-limitations-and-roadmap.md) | Known gaps and next development priorities |
| [shared-docs/demo-flow.md](shared-docs/demo-flow.md) | Step-by-step demo/defense walkthrough |
| [shared-docs/smoke-test-report.md](shared-docs/smoke-test-report.md) | Phase 0 surveillance vertical slice verification |
| [shared-docs/admin-access-smoke-test.md](shared-docs/admin-access-smoke-test.md) | Phase 1 auth/access static verification results |
