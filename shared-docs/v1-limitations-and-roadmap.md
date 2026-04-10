# MQ Monitoring — V1 Limitations and Roadmap

> **Document purpose:** Honest record of known gaps in the V1 surveillance
> analytics module, suitable for PFE/soutenance review and for guiding
> next development priorities.

---

## V1 Limitations

### L1 — SQLite as the persistence layer

**Status:** Intentional and documented. Python's `EventLogger` was designed
with a clear SQLite migration path (see inline comments in `event_logger.py`).

**Constraints in V1:**
- Single-file database; all processes must share the same filesystem mount.
- No concurrent write safety beyond SQLite's WAL mode.
- Unsuitable for multi-machine or multi-camera deployments.
- No connection pooling; PHP opens the file directly via PDO.

**Impact:** None for a single-machine demo or university evaluation. Would
block a production multi-site deployment.

---

### L2 — No authentication or role management

**Status:** ✅ Resolved — implemented in Phase 1.

Laravel Sanctum token authentication is in place. All three surveillance
endpoints, plus the CSV export endpoint, require a valid bearer token. The
three roles (`admin`, `superviseur`, `viewer`) are enforced server-side.
See [access-model-v1.md](access-model-v1.md) for full scope rules and
[admin-access-smoke-test.md](admin-access-smoke-test.md) for the verification
record.

The constraints below are left as-is for historical reference:

**Former constraints (now resolved):**
- Previously: any HTTP client with network access could query all surveillance data.
- Previously: no concept of `admin`, `superviseur`, or `viewer` roles.
- Previously: no supervisor-to-employee scoping of results.
- Previously: no audit log of who queried what. *(Still no audit log — out of scope for V1; deferred to a future phase.)*

**Remaining gap:** No audit log. Acceptable for V1 but should be addressed before institutional deployment.

---

### L3 — No site, camera, or project scoping

**Status:** Not implemented. The database has no `camera_id`, `site_id`, or
`project_id` column.

**Constraints in V1:**
- All events are implicitly from a single camera / a single surveillance
  session.
- Dashboards cannot be filtered by physical location or sensor.
- Adding multi-camera support would require a schema migration.

**Impact:** Blocks any deployment with more than one camera.

---

### L4 — No data export or reporting

**Status:** ⚠️ Partially resolved.

CSV export is implemented for the identity timeline view:
`GET /api/monitoring/surveillance/identities/{name}/export/csv`
returns a downloadable `;`-delimited CSV file for the current identity and
date filter window. The Angular full-report page includes an **Export CSV**
button that downloads the file via authenticated HTTP.

**Remaining gaps:**
- No overview or cross-identity CSV export.
- No scheduled email or webhook reports.
- No PDF export.

**Impact:** Partial — single-identity CSV export is usable for review and
presentation. A bulk export or scheduled-report feature would require
additional work.

---

### L5 — No time-series or trend analytics

**Status:** Not in scope for V1.

**Constraints in V1:**
- Overview and identities endpoints aggregate over the entire selected window.
- No day-by-day, hour-by-hour, or rolling-average breakdown is available.
- No comparison of one period against a previous period.

**Impact:** Reduces analytical depth; all current queries are session-level
aggregates rather than trends over time.

---

### L6 — No AI assistant or natural-language query

**Status:** Not in scope for V1.

**Constraints in V1:**
- Users must select dates manually and interpret charts themselves.
- No LLM-generated summary or anomaly narrative.
- No conversational interface.

**Impact:** None for V1 demo. Identified as a V2+ differentiator.

---

### L7 — track_id is not persistent across Python restarts

**Status:** Known limitation of DeepSORT.

**Constraints in V1:**
- `track_id` values are session-scoped integers that reset on each Python
  process restart.
- The timeline view groups segments by `identity_name`, not `track_id`, so
  the UI is not affected.
- Cross-session track continuity is handled at the identity level via
  ArcFace reassociation, not via persistent IDs.

**Impact:** Cosmetic — `track_id` is visible in the timeline detail view as
context, but should not be relied upon for identity correlation across sessions.

---

### L8 — `event_count` counts rows, not persons or sessions

**Status:** By design, but worth documenting explicitly.

`event_count` in overview and identities responses is the count of
**event rows** in the `surveillance_events` table that match the filter. It is
**not** a unique-person count, a unique-session count, or a unique-day count.

One person performing five activity transitions in a session will produce five
rows, all counted. This is useful as a proxy for "activity richness" but
should not be misread as headcount.

---

### L9 — No liveness enforcement at query time

**Status:** By design.

Liveness detection (`MiniFASNet`) is applied at enrollment and at the
face-matching step during the Python runtime. The database stores the
**result** of an already-liveness-checked identity assignment. The API does
not re-validate liveness on read.

---

## Roadmap

### R1 — Authentication & Role-Based Access Control ✅ DONE

> Completed in Phase 1. See [access-model-v1.md](access-model-v1.md) and
> [admin-access-smoke-test.md](admin-access-smoke-test.md) for details.

~~Add Laravel Sanctum token authentication. Protect all three surveillance
endpoints with `auth:sanctum` middleware. Roles: `admin`, `superviseur`,
`viewer`. Admin user management endpoints. Angular login page, auth
interceptor, route guards.~~

---

### R2 — Migrate to PostgreSQL or MySQL (priority: high for production)

- Replace the shared SQLite file with a client-server RDBMS.
- Python runtime writes via a database driver (psycopg2 / PyMySQL).
- Laravel connects via its standard Eloquent driver.
- Enables multi-process concurrent writes and proper connection pooling.
- Prerequisite for multi-camera and multi-site deployments.

---

### R3 — Multi-camera and multi-site support (priority: medium)

- Add `camera_id` and `site_id` columns to `surveillance_events`.
- Update all API filters to accept optional `camera` / `site` parameters.
- Update Angular dashboards with camera/site selectors.
- Requires R2 (shared-DB architecture) to be viable.

---

### R4 — Time-series and trend analytics (priority: medium)

- Add a `by_day` / `by_hour` aggregation mode to the overview endpoint.
- Surface a day-by-day activity chart in the Angular dashboard.
- Add week-over-week and month-over-month comparison KPIs.
- No schema change required; this is a query and UI enhancement.

---

### R5 — Export and reporting ⚠️ PARTIAL

> CSV export for individual identity timelines is implemented
> (`GET /api/monitoring/surveillance/identities/{name}/export/csv`).
> The Angular full-report page includes an **Export CSV** button.

Remaining:
- Bulk/overview CSV export.
- Scheduled email or webhook summaries.
- PDF report generation.

---

### R6 — AI assistant / natural-language interface (priority: low / V2+)

- Integrate an LLM (local or API-based) to answer questions about
  surveillance data in natural language.
- Generate narrative summaries: "Amir spent 78% of his time Working on
  2026-03-24, with two phone-use interruptions totalling 4 minutes."
- Surface anomaly detection: flag identities with unusual inactive ratios.
- Requires stable API contract and sufficient historical data volume.

---

### R7 — Persistent track identity across sessions (priority: low)

- Assign a stable UUID per enrolled identity rather than relying on
  session-scoped DeepSORT `track_id`.
- Store the resolved UUID alongside `identity_name` in each event row.
- Enables cross-session timeline continuity and prevents timeline fragmentation
  when a person is briefly occluded between sessions.

---

## Summary Table

| ID | Limitation | Status | Priority to fix | Phase |
|---|---|---|---|---|
| L1 | SQLite persistence | Open | High (production) | V1.5 |
| L2 | No authentication | ✅ Resolved | — | Phase 1 |
| L3 | No multi-camera/site | Open | Medium | V2 |
| L4 | No export/reporting | ⚠️ Partial (timeline CSV) | Medium | V2 |
| L5 | No time-series | Open | Medium | V2 |
| L6 | No AI assistant | Open | Low | V3 |
| L7 | track_id not persistent | Open | Low | V2+ |
| L8 | event_count semantics | Documented | — | — |
| L9 | Liveness not re-checked at query time | By design | — | — |

---

## Recommended Next Steps (ordered)

1. ~~**Run the full V1 demo** with real surveillance data to validate end-to-end
   correctness before any schema or auth changes.~~ ✅ **Done — Phase 0
   smoke test completed 2026-03-25.**

2. ~~**Add Laravel Sanctum authentication** (R1).~~ ✅ **Done — Phase 1
   complete. See [admin-access-smoke-test.md](admin-access-smoke-test.md).**

3. **Add a PostgreSQL migration path** (R2) — needed before any shared-lab or
   multi-user evaluation.

4. **Add time-series aggregation** (R4) — high analytics value, no schema
   change required.

5. **Expand data export** (R5 partial) — bulk/overview CSV or PDF report;
   single-identity CSV already implemented.
