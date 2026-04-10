# Admin / Auth / Access Smoke Test
**Date**: 2026-03-25
**Scope**: static code inspection — auth flow, role-based scoping, admin user management
**Method**: full static trace of relevant Angular + Laravel files

---

## Scenario Results

| # | Scenario | Result | Notes |
|---|---|---|---|
| 1 | Admin can access /admin/users | ✅ PASS | `authGuard` + `adminGuard` client-side; `auth:sanctum` + `EnsureAdmin` server-side |
| 2 | Non-admin cannot access /admin/users | ✅ PASS | `adminGuard` redirects to /surveillance; never reaches API |
| 3 | Admin can create/edit user with `surveillance_identity` | ✅ PASS | Form, service, request validation, and controller all handle the field end-to-end |
| 4 | Admin can assign a viewer to a superviseur | ✅ PASS | `supervisorOptions` filters UI; `UpdateUserRequest` validates role + blocks self-reference |
| 5 | Admin can deactivate / reactivate a user | ✅ PASS | `toggleActive()` PATCH `{is_active}` → backend updates → `fresh()` response consumed |
| 6 | Users page All / Active / Inactive filter | ✅ PASS | `statusFilter` signal + `filteredUsers` computed; client-side, no re-fetch |
| 7 | Viewer login works | ✅ PASS | Public endpoint; `is_active` gated after credential check; `surveillance_identity` in payload |
| 8 | Viewer sees only own surveillance identity data | ✅ PASS | `resolveScope()` returns `[$user->surveillance_identity]`; all three analytics endpoints scoped |
| 9 | Viewer cannot access another identity's timeline | ✅ PASS | Backend `!in_array($identityName, $scope)` → 403; Angular shows error state |
| 10 | Superviseur sees assigned employees + own data | ✅ PASS | Explicitly designed; `resolveScope()` collects employees + supervisor's own identity |

**Overall: 10 / 10 PASS**

---

## Blocker Found and Patched

### Login form inputs had no explicit dark-theme colours
**File**: `mq-monitoring-web/src/app/features/auth/login/login.component.scss`
**Root cause**: `&__input` was missing `background` and `color` — browser defaults (white bg / dark text)
rendered visibly broken on the dark `#161b25` login card after the theme pass.
All users.component.scss form inputs already had these properties set correctly.

**Fix**: Added two lines to `login.component.scss` `&__input`:
```scss
background: var(--color-surface);
color:      var(--color-text-1);
```

---

## Architecture Notes (for reference)

### Auth flow
- `APP_INITIALIZER` calls `auth.bootstrap()` → `GET /api/auth/me` before any route guard evaluates.
  Ensures `auth.user()` is always populated (or cleared) before `adminGuard` reads `.role`.
- `authInterceptor` clears local state on 401; `authGuard` enforces redirect on next navigation.
- No session is created on the server — stateless Sanctum tokens only.

### Scoping design (as implemented)
- `admin`      → `null` (unrestricted)
- `superviseur` → `surveillance_identity[]` of employees where `supervisor_id = self` (non-null/non-empty) + supervisor's own `surveillance_identity` if set
- `viewer`      → `[surveillance_identity]` if set; `[]` if null/empty

### supervisor_id guard (UpdateUserRequest)
- Rejects assignment to a user whose role is not `superviseur`.
- Rejects self-assignment.
- Sending `supervisor_id: null` removes the assignment (always valid).

---

## Safest Next Step

Run both servers and do one manual end-to-end click-through to confirm the static analysis
matches the live behaviour:

```bash
# Terminal 1
cd mq-monitoring-api && /c/php/php artisan serve --port=8081

# Terminal 2
cd mq-monitoring-web && npx ng serve --proxy-config proxy.conf.json
```

Minimal checklist:
1. Open http://localhost:4200 — confirm dark login card with dark inputs (visual fix verified)
2. Log in as admin → confirm /admin/users loads
3. Create one viewer user with a `surveillance_identity` value → check it appears in table sub-line
4. Toggle the viewer inactive → confirm Inactive filter shows them, Active filter hides them
5. Log in as the viewer → confirm only scoped data appears on dashboard
6. As the viewer, manually navigate to a known other identity's timeline → confirm error state
