# MQ Monitoring — API Contract (V1)

> **Base URL (development):** `http://localhost:4200/api`
> Requests from the Angular SPA are proxied by the Angular dev server to
> `http://localhost:8081` (Laravel).
>
> **Authentication:** All endpoints except `POST /api/auth/login` require a
> valid Sanctum bearer token in the `Authorization` header.
>
> **Surveillance analytics endpoints** are read-only (GET only) and return
> results scoped to the authenticated user's role. Auth and user-management
> endpoints include POST and PATCH operations.

---

## Authentication Endpoints

### `POST /api/auth/login`

Exchanges user credentials for a Sanctum bearer token.

**Authentication required:** No (public endpoint)

#### Request Body

```json
{
  "email":    "admin@example.com",
  "password": "secret"
}
```

#### Response (200 OK)

```json
{
  "token": "1|abc123...",
  "user": {
    "id":                    1,
    "name":                  "Administrateur",
    "email":                 "admin@example.com",
    "role":                  "admin",
    "supervisor_id":         null,
    "is_active":             true,
    "surveillance_identity": null
  }
}
```

#### Error Responses

| Status | Meaning |
|---|---|
| `401 Unauthorized` | Invalid credentials |
| `403 Forbidden` | Account is deactivated (`is_active = false`) |
| `422 Unprocessable Entity` | Validation failed (missing email or password) |

---

### `GET /api/auth/me`

Returns the authenticated user's profile.

**Authentication required:** Yes (`Authorization: Bearer <token>`)

#### Response (200 OK)

Same `user` object shape as the `/login` response.

---

## Shared Conventions

### Authentication

All surveillance and user management endpoints require an `Authorization` header:

```
Authorization: Bearer <sanctum-token>
```

The Angular `authInterceptor` attaches this header automatically to every
request to `/api`. A missing or invalid token receives a `401 Unauthorized`
response. The `authGuard` on protected Angular routes redirects to `/login`
before any API call is made.

### Date Parameters

| Format | Example | Notes |
|---|---|---|
| Date only | `2026-03-24` | Laravel interprets as `2026-03-24 00:00:00` — **avoid for `end`** |
| Full datetime | `2026-03-24 23:59:59` | Preferred; Angular sends this automatically |

> **Angular convention:** The frontend expands bare `YYYY-MM-DD` date inputs
> to `YYYY-MM-DD 00:00:00` (start) and `YYYY-MM-DD 23:59:59` (end) before
> sending requests. This ensures same-day events are never excluded.

### Boolean Parameters

Booleans are sent as `"1"` (true) or `"0"` (false). Laravel's `boolean`
validation rule accepts `1`, `0`, `"1"`, `"0"`, `true`, `false`.

### Array Parameters

Arrays are sent as repeated query-string keys with a `[]` suffix:

```
include_triggers[]=activity_change&include_triggers[]=track_lost
```

This matches Laravel's default array deserialization convention.

### Error Responses

| Status | Meaning |
|---|---|
| `422 Unprocessable Entity` | Validation failed; body contains `errors` object |
| `503 Service Unavailable` | SQLite database unreachable or locked |

---

## Surveillance Analytics Endpoints

> All surveillance endpoints require a valid bearer token. Results are
> automatically scoped to the authenticated user's role:
> - `admin` — full results, no filtering
> - `superviseur` — assigned employees + self
> - `viewer` — self only (`surveillance_identity` of the user's own account)

### `GET /api/monitoring/surveillance/overview`

Returns a global activity breakdown for a time window.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `start` | datestring | ✅ | — | Window start; inclusive |
| `end` | datestring | ✅ | — | Window end; must be ≥ `start` |
| `include_unknown` | boolean | — | `1` (true) | Whether to include rows where `identity_name = 'Unknown'` |
| `include_triggers[]` | string[] | — | all triggers | Filter by event trigger; valid values: `activity_change`, `track_lost`, `session_end` |

#### Response Shape

```json
{
  "meta": {
    "start":            "2026-03-24 00:00:00",
    "end":              "2026-03-24 23:59:59",
    "include_unknown":  true,
    "include_triggers": []
  },
  "totals": {
    "Working":     3720,
    "Inactive":    480,
    "Using_Phone": 120
  },
  "total_sec": 4320,
  "event_count": 14,
  "distribution": {
    "Working":     0.8611,
    "Inactive":    0.1111,
    "Using_Phone": 0.0278
  }
}
```

#### Field Definitions

| Field | Type | Description |
|---|---|---|
| `meta` | object | Echo of the validated request parameters |
| `meta.start` | string | Normalised start datetime as stored (`YYYY-MM-DD HH:MM:SS`) |
| `meta.end` | string | Normalised end datetime |
| `meta.include_unknown` | boolean | Effective value after defaulting |
| `meta.include_triggers` | string[] | Effective trigger filter (empty = all triggers) |
| `totals` | `Record<string, number>` | Summed `duration_sec` per activity label, sorted descending by duration |
| `total_sec` | `number` | Sum of all values in `totals` |
| `event_count` | `number` | Number of **event rows** matched (not unique persons or sessions) |
| `distribution` | `Record<string, number>` | Each activity's share of `total_sec`; values sum to 1.0 |

> **Empty windows:** When no events match the filter, `totals` and `distribution`
> serialize as `[]` (PHP empty array) rather than `{}`. JavaScript's
> `Object.entries([])` behaves identically to `Object.entries({})`, so Angular
> handles this transparently.

---

### `GET /api/monitoring/surveillance/identities`

Returns a per-identity activity breakdown for a time window.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `start` | datestring | ✅ | — | Window start; inclusive |
| `end` | datestring | ✅ | — | Window end; must be ≥ `start` |
| `include_unknown` | boolean | — | `0` (false) | Whether to include the synthetic `Unknown` identity |
| `include_triggers[]` | string[] | — | all triggers | Same as overview endpoint |
| `identity` | string | — | null | When present, restricts results to a single identity name |

#### Response Shape

```json
{
  "meta": {
    "start":           "2026-03-24 00:00:00",
    "end":             "2026-03-24 23:59:59",
    "include_unknown": false,
    "include_triggers": [],
    "identity_filter": null
  },
  "identities": [
    {
      "identity_name": "Amir",
      "total_sec": 2640,
      "event_count": 9,
      "activities": {
        "Working":  2400,
        "Inactive": 240
      }
    },
    {
      "identity_name": "Nour",
      "total_sec": 1680,
      "event_count": 5,
      "activities": {
        "Working":     1320,
        "Using_Phone": 360
      }
    }
  ]
}
```

#### Field Definitions

| Field | Type | Description |
|---|---|---|
| `meta` | object | Echo of the validated request parameters |
| `meta.include_unknown` | boolean | Effective value — defaults to `false` for this endpoint |
| `meta.identity_filter` | string\|null | Echo of the `identity` query param, or `null` |
| `identities` | array | One entry per distinct `identity_name` in the window |
| `identity_name` | string | Resolved employee name, or `"Unknown"` |
| `total_sec` | number | Sum of `duration_sec` for this identity across all matched rows |
| `event_count` | number | Count of matched event rows for this identity |
| `activities` | `Record<string, number>` | Per-activity `duration_sec` totals, sorted descending |

> `identities` is sorted descending by `total_sec`.

---

### `GET /api/monitoring/surveillance/identities/{identityName}/timeline`

Returns ordered activity segments for a single identity.

#### Route Parameter

| Parameter | Constraints | Description |
|---|---|---|
| `identityName` | `[A-Za-z][A-Za-z0-9_\-]*`, max 120 chars, URL-encoded | The identity to retrieve |

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `start` | datestring | — | null (no lower bound) | Optional window start |
| `end` | datestring | — | null (no upper bound) | Optional window end; must be ≥ `start` if both provided |
| `include_triggers[]` | string[] | — | all triggers | Filter by event trigger |

> Omitting both `start` and `end` returns the full history for the identity.

#### Response Shape

```json
{
  "meta": {
    "start":            "2026-03-24 00:00:00",
    "end":              "2026-03-24 23:59:59",
    "include_triggers": []
  },
  "identity": "Amir",
  "count": 3,
  "segments": [
    {
      "timestamp_start":     "2026-03-24 21:52:05",
      "timestamp_end":       "2026-03-24 21:53:41",
      "duration_sec":        96.0,
      "track_id":            3,
      "activity":            "Working",
      "identity_confidence": 0.8814,
      "identity_source":     "face",
      "event_trigger":       "activity_change"
    },
    {
      "timestamp_start":     "2026-03-24 21:53:41",
      "timestamp_end":       "2026-03-24 21:54:10",
      "duration_sec":        29.0,
      "track_id":            3,
      "activity":            "Using_Phone",
      "identity_confidence": 0.8814,
      "identity_source":     "face",
      "event_trigger":       "track_lost"
    }
  ]
}
```

> When no `start`/`end` filter is applied, `meta.start` and `meta.end` are
> `null` and the full history is returned.

#### Field Definitions

| Field | Type | Description |
|---|---|---|
| `identity` | string | Echo of the requested `identityName` |
| `count` | number | Total segments returned |
| `segments` | array | Ordered by `timestamp_start` ascending |
| `timestamp_start` | string | `YYYY-MM-DD HH:MM:SS` local time — start of the activity segment |
| `timestamp_end` | string | `YYYY-MM-DD HH:MM:SS` local time — end of the activity segment |
| `duration_sec` | number | `timestamp_end − timestamp_start` in seconds (≥ 0) |
| `track_id` | number | DeepSORT integer track ID; unique within one Python runtime session, not stable across restarts |
| `activity` | string | See Activity Labels below |
| `identity_confidence` | number | ArcFace cosine-similarity score in `[0, 1]` at time of last accepted identity assignment; `0.0` when `identity_source = "none"` |
| `identity_source` | string | See Identity Source Values below |
| `event_trigger` | string | See Event Trigger Values below |

---

## Enumeration Reference

### Activity Labels

| Value | Display | Meaning |
|---|---|---|
| `Working` | Working | Subject is at workstation, typing, reading, or focused on work |
| `Meeting` | Meeting | Subject is engaged in a group or video meeting |
| `Inactive` | Inactive | Subject is present but not actively working (idle, phone aside) |
| `Using_Phone` | Using Phone | Subject's primary focus is a mobile device |
| `Unknown` | Unknown | Classifier confidence below threshold; label undetermined |

### Identity Source Values

| Value | Meaning |
|---|---|
| `face` | Direct ArcFace cosine-similarity match above threshold |
| `reassoc_face_confirmed` | Confirmed-vault reassociation: identity inherited from a recently-confirmed track |
| `reassoc_short_term` | Short-term position-based reassociation: identity inherited from a spatially-close recent track |
| `manual` | Operator override (reserved for future use) |
| `none` | Identity was never resolved for this track |

### Event Trigger Values

| Value | Meaning |
|---|---|
| `activity_change` | Activity label transitioned mid-session (smoothing threshold met) |
| `track_lost` | DeepSORT evicted the track (person left frame or occlusion timeout) |
| `session_end` | Python process shutdown — final flush of any still-open activity segment |

---

### `GET /api/monitoring/surveillance/identities/{identityName}/export/csv`

Downloads the activity timeline of a single identity as a CSV file.

**Authentication required:** Yes. Scope-enforced — a `403 Forbidden` is
returned if the requested identity is outside the authenticated user's scope.

#### Route Parameter

| Parameter | Constraints | Description |
|---|---|---|
| `identityName` | Same as `/timeline` | The identity to export |

#### Query Parameters

Same as the `/timeline` endpoint (`start`, `end`, `include_triggers[]`).

#### Response

| Header | Value |
|---|---|
| `Content-Type` | `text/csv; charset=utf-8` |
| `Content-Disposition` | `attachment; filename="<name>_timeline.csv"` |

**Delimiter:** `;` (semicolon — compatible with European-locale Excel).

**Columns:** `timestamp_start`, `timestamp_end`, `duration_sec`, `activity`,
`identity_confidence`, `identity_source`, `event_trigger`.

First row is a header row.

---

## SQLite Schema Reference

The `surveillance_events` table is created and owned by the Python runtime.
Laravel reads from it but never alters it.

```sql
CREATE TABLE surveillance_events (
    timestamp_start     TEXT NOT NULL,  -- 'YYYY-MM-DD HH:MM:SS'
    timestamp_end       TEXT NOT NULL,
    duration_sec        REAL NOT NULL,
    track_id            INTEGER NOT NULL,
    identity_name       TEXT NOT NULL,
    identity_confidence REAL NOT NULL,
    identity_source     TEXT NOT NULL,
    activity            TEXT NOT NULL,
    event_trigger       TEXT NOT NULL
);
```

> The CSV file at `logs/surveillance_events.csv` follows the identical column
> order and is kept in sync with the SQLite database by the Python runtime.

---

## User Management Endpoints

> **Admin-only.** All `/api/users` routes require both `auth:sanctum` and the
> `EnsureAdmin` middleware. Non-admin users receive `403 Forbidden`.

### `GET /api/users`

Returns the list of all user accounts.

#### Response (200 OK)

```json
[
  {
    "id":                    1,
    "name":                  "Administrateur",
    "email":                 "admin@example.com",
    "role":                  "admin",
    "supervisor_id":         null,
    "is_active":             true,
    "surveillance_identity": null
  },
  {
    "id":                    3,
    "name":                  "Nour",
    "email":                 "nour@example.com",
    "role":                  "viewer",
    "supervisor_id":         2,
    "is_active":             true,
    "surveillance_identity": "Nour"
  }
]
```

---

### `POST /api/users`

Creates a new user account.

#### Request Body Fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | Display name |
| `email` | string | ✅ | Must be unique |
| `password` | string | ✅ | Min 8 characters |
| `role` | enum | ✅ | `admin`, `superviseur`, or `viewer` |
| `supervisor_id` | number\|null | — | Must reference a user with `role = superviseur` if set |
| `is_active` | boolean | — | Defaults to `true` |
| `surveillance_identity` | string\|null | — | Must match `identity_name` values in surveillance events |

#### Response (201 Created)

The newly created user object (same shape as `GET /api/users` entries).

---

### `PATCH /api/users/{id}`

Partially updates a user account. Used for profile edits, role changes,
supervisor assignment, `surveillance_identity` updates, and toggling `is_active`.

#### Route Parameter

| Parameter | Constraints |
|---|---|
| `id` | Numeric user ID |

#### Request Body

Any subset of the fields from `POST /api/users` (all optional on PATCH).
To remove a supervisor assignment, send `"supervisor_id": null`.
To deactivate/reactivate, send `"is_active": false` or `"is_active": true`.

#### Response (200 OK)

The updated user object.

#### Error Responses

| Status | Meaning |
|---|---|
| `422` | Validation failed (e.g., `supervisor_id` references a non-superviseur, or self-assignment) |
| `404` | User ID not found |

---

## Angular Service Reference

The `SurveillanceService` (`src/app/features/surveillance/services/surveillance.service.ts`)
is the single Angular entry point for all three endpoints. It:

- Accepts typed parameter objects (`OverviewParams`, `IdentitiesParams`, `TimelineParams`)
- Serialises booleans as `"1"`/`"0"` and arrays as `key[]` repeats (via `toHttpParams()`)
- Provides `toActivityStats()` and `toIdentityRows()` adapters for UI model shapes

**Date expansion is the caller's responsibility.**
Page components (`DashboardComponent`, `IdentityDetailComponent`) call
`toStartOfDay()` / `toEndOfDay()` on the bare `YYYY-MM-DD` date picker values
_before_ passing them to the service. The service receives already-expanded
`YYYY-MM-DD HH:MM:SS` strings and forwards them as-is.
