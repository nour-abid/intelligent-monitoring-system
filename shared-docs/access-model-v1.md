# MQ Monitoring — Access Model V1

> **Status:** Implemented — Phase 1 complete.
> This document describes the access model **as it currently exists** in the
> running codebase.
> See the Phase 1 smoke test record at
> [admin-access-smoke-test.md](admin-access-smoke-test.md) for verification results.

---

## 1. Purpose

This document defines the three roles, the scope rules that determine what
each role can see, the hierarchy model, and the identity-matching convention
used in the current V1 implementation.

---

## 2. Roles

### `admin`

The system administrator. Full visibility and full management rights.

| Capability | Granted |
|---|---|
| View all surveillance data (all identities, all date ranges) | ✅ |
| Manage users (create, edit, deactivate) | ✅ |
| Assign employees to superviseurs | ✅ |
| View the dashboard for any identity | ✅ |
| Delete or reset data | — (out of scope for V1) |

There is typically one admin for the deployment. The first admin account is
seeded or created via artisan during setup.

---

### `superviseur`

A team lead or department head. Sees only the employees assigned to them.

| Capability | Granted |
|---|---|
| View surveillance data for **assigned employees only** | ✅ |
| View their own identity timeline | ✅ |
| Manage user accounts | ❌ |
| Assign employees to other superviseurs | ❌ |
| View data for employees not assigned to them | ❌ |

A superviseur with no assigned employees sees an empty dashboard — not an
error, just no data.

---

### `viewer`

Read-only observer with self-scoped visibility. Can see only their own
surveillance data.

| Capability | Granted |
|---|---|
| View their own surveillance data (self-identity timeline only) | ✅ |
| View other employees' data | ❌ |
| Manage user accounts | ❌ |
| Assign employees | ❌ |

---

## 3. Scope Rules

### Admin

No filtering applied. All three API endpoints return unscoped results.

### Superviseur

Endpoints filter results to identities for which the authenticated user is the
assigned supervisor.

Concretely, the API enforces:

```
identities returned ⊆ { identity_name | users.supervisor_id = auth()->id() }
```

This filter is applied server-side in `SurveillanceAnalyticsService` based on
the authenticated user. The Angular frontend may reflect this by hiding
navigation elements, but **the backend filter is the enforceable boundary.**

### Viewer

Endpoints filter results to the authenticated user's own identity only.

Concretely, the API enforces:

```
identities returned ⊆ { identity_name | identity_name = auth()->user()->name }
```

The `users.name` column is matched exactly (case-sensitive) against
`surveillance_events.identity_name`. A viewer cannot see other employees' data
or the global overview.

### Unassigned employees

Employees with `supervisor_id = null` are visible to `admin` only. They do not
appear in any superviseur dashboard. The `viewer` role always sees only themself
(if that person has an identity entry in the surveillance events); unassigned
status does not apply to the viewer scoping model.

---

## 4. First Hierarchy Model

### Design

The `users` table carries a nullable self-referential `supervisor_id`:

```
users
  id               bigint PK
  name             string
  email            string  UNIQUE
  password         string  (hashed)
  role             enum('admin', 'superviseur', 'viewer')
  supervisor_id    bigint  NULL → FK → users(id)
  ...standard Laravel timestamps...
```

### Rules

| Rule | Value |
|---|---|
| One employee → zero or one supervisor | `supervisor_id` is nullable |
| One supervisor → many employees | Standard one-to-many FK |
| A supervisor is themselves a `users` row | No separate table in V1 |
| An admin has no `supervisor_id` | (`null` — not under any supervisor) |
| Nested hierarchies (superviseur of superviseurs) | Not supported in V1 |

### What "employee" means in V1

There is no separate `employees` table in V1. Any user whose `supervisor_id`
is set is, by convention, an "employee" for the purposes of surveillance
scoping.

> **Identity name matching:** The surveillance runtime stores names such as
> `"bellaaj"` in `identity_name`. Each `users` row has a separate
> `surveillance_identity` column (nullable string) that the admin sets
> explicitly. The backend uses **`users.surveillance_identity`** — not
> `users.name` — to map a user account to surveillance event rows.
> A user with a null or empty `surveillance_identity` will not match any
> surveillance events, and a viewer with that state sees an empty dashboard
> rather than an error.

---

## 5. Non-Goals (Phase 1 Scope Boundary)

The following are explicitly **not** part of Phase 1:

| Item | Why deferred |
|---|---|
| Teams, departments, or groups | Adds schema complexity; one-to-many supervisor is sufficient for V1 |
| Multi-supervisor per employee | Not required yet; nullable single FK is the simplest model |
| Employee entity separate from users | Premature — users table covers the V1 use case |
| Audit logging of who queried what | Useful, but not blocking; defer to phase 2 |
| Row-level security in the database | Backend Laravel filtering is sufficient for the current single-DB architecture |
| Angular route guards as access enforcement | Guards are UX only; backend always re-validates |
| Self-service password reset / email flow | Out of scope for closed-network lab deployment |
| OAuth / SSO | Out of scope for V1 |
| API rate limiting | Out of scope for V1 |
| Data visibility for the employee themselves | No self-service portal in V1 |

---

## 6. What Was Implemented in Phase 1

All items were completed. The guiding principle — backend enforcement as the
source of truth — was upheld throughout.

| Item | Status |
|---|---|
| Laravel Sanctum token authentication | ✅ Done |
| `role`, `supervisor_id`, `surveillance_identity`, `is_active` columns on `users` | ✅ Done |
| `resolveScope()` in `SurveillanceAnalyticsService` — role-based identity filtering | ✅ Done |
| `POST /api/auth/login` — credential exchange for Sanctum token | ✅ Done |
| `GET /api/auth/me` — authenticated user profile | ✅ Done |
| Admin user management endpoints (list, create, update) under `EnsureAdmin` middleware | ✅ Done |
| Angular `AuthService` with `APP_INITIALIZER` bootstrap | ✅ Done |
| `authGuard` and `adminGuard` route guards | ✅ Done |
| `authInterceptor` attaching `Authorization: Bearer` header | ✅ Done |
| Angular admin users page with create/edit/deactivate/assign | ✅ Done |
| Angular login page | ✅ Done |

**API contract impact:** The three surveillance endpoints' response shapes are
unchanged. Only the access gate changed — a valid `Authorization: Bearer`
token is now required and the backend filters results by role:

| Condition | Behaviour |
|---|---|
| No token | `401 Unauthorized` |
| Valid token, `admin` | Full results — unrestricted |
| Valid token, `superviseur` | Results filtered to assigned employees + self |
| Valid token, `viewer` | Results filtered to own `surveillance_identity` |
| No assignments (superviseur) | Empty results — not an error |
| No `surveillance_identity` set (viewer) | Empty results — not an error |

See [admin-access-smoke-test.md](admin-access-smoke-test.md) for the full
10/10 verification record.

---

## 7. Open Questions — Resolved

| Question | Resolution |
|---|---|
| How does `identity_name` in events link to the user account? | Via `users.surveillance_identity` (explicitly set by admin per user). |
| Should a superviseur be able to see their own identity timeline? | Yes — implemented: `resolveScope()` includes the supervisor’s own `surveillance_identity` alongside assigned employees. |
| Can a user have multiple roles? | No — single `role` enum column. |
| Who creates the initial admin account? | `php artisan db:seed` seeds the first admin. |
| Token expiry policy? | Sanctum default (no expiry unless configured via `sanctum.expiration`). |
