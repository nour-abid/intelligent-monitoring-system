# MQ Monitoring — Demo Flow

> **Audience:** Project defense, evaluation panel, or onboarding demo.
> **Prerequisites:** All three services running locally (see README.md).
> **Duration estimate:** 10–15 minutes for a complete walkthrough.

---

## Setup Before Demo

Start all three services before presenting:

```bash
# Terminal 1 — Laravel API
cd mq-monitoring-api
/c/php/php artisan serve --port=8081

# Terminal 2 — Angular SPA
cd mq-monitoring-web
npx ng serve --proxy-config proxy.conf.json
```

The Python surveillance runtime (`python -m surveillance.main_surveillance`) can be
running or stopped — the demo uses data already recorded in
`logs/surveillance_events.db`.

Open `http://localhost:4200` in a browser. You should see the login page.

---

## Step 1 — Authentication

**What to show:** The protected entry point.

1. Open `http://localhost:4200`.
2. Observe the dark login form — the application is not accessible without credentials.
3. Enter invalid credentials → explain that inactive or wrongly-credentialed accounts are rejected.
4. Log in as the **admin** account (seeded via `php artisan db:seed`).
5. You land on `/surveillance` — the surveillance dashboard.

**Key point to make:** The Angular SPA bootstraps by calling `GET /api/auth/me`
_before_ any route guard runs. The user is never visible in a "logged-out flash"
state on page refresh. The bearer token is stored in Angular's memory, not in
`localStorage`, which avoids XSS token theft.

---

## Step 2 — Admin User Management

**What to show:** The admin has full control over user accounts.

1. Navigate to `/admin/users` (top nav or direct URL).
2. Show the user list with columns: name, email, role, assigned supervisor, surveillance identity, active status.
3. **Demonstrate search:** Type a partial name in the search box — the table filters instantly (client-side, no re-fetch).
4. **Demonstrate status filter:** Click "Inactive" → show only deactivated accounts. Click "All" to restore.
5. **Demonstrate pagination** if there are enough users.
6. **Create a new viewer:** Click "Add User" → fill name, email, role = `viewer`, assign a superviseur, set `surveillance_identity` to a known enrolled name (e.g. `"Nour"`). Save.
7. **Toggle active:** Click the active/inactive toggle on a user row → observe the badge change.
8. **Open stats popup:** Click the eye icon on a user who has surveillance data → a modal appears with their activity overview for today.

**Key point to make:** `surveillance_identity` is what links the user account
to surveillance event records. If a viewer logs in without this field set, they
see an empty (not broken) dashboard — because the backend returns an empty
scope, not an error.

---

## Step 3 — Admin Surveillance Dashboard

**What to show:** Full visibility and the analytics interface.

1. While logged in as admin, navigate to `/surveillance`.
2. Show the **idle state** — data loads only when explicitly requested.
3. Click the **"Today"** preset → click **Load**.
4. Walk through the four KPI cards: Total Time, Events Recorded, Top Activity, Identities tracked.
5. Look at the **activity distribution chart** — point out the colour coding (green = Working, amber = Inactive, red = Using Phone).
6. In the **identity table**, click a name → you navigate to the full identity report.

**Key point to make:** The admin sees all enrolled identities. The backend
applies no identity filter for the admin role.

---

## Step 4 — Full Identity Report

**What to show:** The depth of per-person analytics.

1. From the identity table, click on an enrolled person (e.g. "Amir").
2. Walk through the page layout:
   - **Dual bar charts** at the top: one for total time per activity, one for segment count per activity.
   - **Timeline flow strip** — a colour-coded horizontal band showing the sequence of activities across the day.
   - **Activity breakdown table** — each activity row shows duration, segment count, and share percentage.
   - **Ordered segments list** — every recorded activity segment with its start time, end time, duration, confidence score, identity source (face / reassociation), and event trigger (activity_change / track_lost / session_end).
3. **Apply a date filter** on this page: set a narrower range → click Load → the charts and table refresh.
4. **Export CSV:** Click the "Export CSV" button. A file downloads. Open it in Excel — the `;` delimiter ensures correct column parsing on European-locale machines.

**Key point to make:** The CSV export uses an authenticated HTTP request
(not a raw browser link). The bearer token is automatically attached by the
Angular HTTP interceptor. If a viewer tries to access an identity outside
their scope by typing the URL directly, the backend returns `403 Forbidden`
and Angular shows an error state — not a data leak.

---

## Step 5 — Supervisor Role Experience

**What to show:** The role-scoped view with the team panel.

1. Log out (top-right logout button or `/login`).
2. Log in as a **superviseur** account (one with assigned employees).
3. Navigate to `/surveillance`. Click **"Today"** → **Load**.
4. Observe the page subtitle: "Team activity overview and employee breakdown."
5. Scroll below the standard KPI cards to the **"My Team" section**:
   - A second row of KPI cards: Team Size, Working Time %, Inactive/Phone %, Team Total Time.
   - A ranked employee table with name, total time, and coloured badges for Working / Inactive / Phone time.
   - Each row has a **"View Report"** button.
6. Click "View Report" for one employee → the full identity report opens, scoped to that employee.

**Key point to make:** The backend enforces the scope — the superviseur cannot
see any identities outside their assignment. Typing another identity's URL
manually returns `403 Forbidden`. The Angular UI is a UX convenience; the
backend is the actual gate.

---

## Step 6 — Viewer Role Experience

**What to show:** Self-service read-only access.

1. Log out.
2. Log in as a **viewer** account (one with `surveillance_identity` set).
3. Navigate to `/surveillance`. Click **"Last 7 days"** → **Load**.
4. The dashboard shows only this person's own data — the KPI cards reflect their individual metrics.
5. The identity table shows only one row (themselves).
6. Click their name → full personal report opens.
7. Try navigating to `/surveillance/identity/Amir` (a different person) → the page shows an error state: the backend returned `403`.
8. The viewer has no access to `/admin/users` — the tab is hidden and direct navigation redirects to `/surveillance`.

**Key point to make:** Role enforcement is entirely server-side.
Angular route guards and hidden UI elements are convenience only;
every real restriction is enforced by the Laravel backend.

---

## Step 7 — Python Runtime (optional / brief)

**What to show:** Where the data comes from.

1. Switch to a terminal running the Python surveillance runtime
   (`python -m surveillance.main_surveillance`).
2. Show the live output — detection events, identity assignments, activity segments.
3. Open the SQLite database file (`logs/surveillance_events.db`) with a SQLite viewer
   or run `sqlite3 logs/surveillance_events.db "SELECT * FROM surveillance_events ORDER BY timestamp_start DESC LIMIT 5;"`.
4. Refresh the Angular dashboard — new events appear.

**Key point to make:** Python _writes only_. It never reads from the analytics
database. Laravel _reads only_. The SQLite file is the sole integration point
between the two processes. This clean separation means either layer can be
independently replaced without touching the other.

---

## Talking Points for Q&A

| Question | Short answer |
|---|---|
| Why SQLite, not PostgreSQL? | Intentional for this single-machine evaluation. Migration path is designed and documented — no schema change required in the Python runtime if moved to a host DB. |
| How does identity matching work? | ArcFace ONNX model computes cosine similarity against enrolled face embeddings. Liveness (MiniFASNet) is applied at enrollment and at match time. The analytics dashboard shows the _result_ — it does not re-run inference. |
| Is the access control secure enough for production? | For a closed-lab evaluation, yes. For institutional deployment: an audit log, rate limiting, and a proper DB (not SQLite) would be the next steps. All are documented in the roadmap. |
| What happens if a person is unrecognised? | They are recorded as `identity_name = 'Unknown'` with `identity_source = 'none'`. The dashboard has an "Include unknown" toggle. Admins can see unknown activity; superviseurs and viewers cannot (out of scope). |
| Can I export all identities at once? | Not currently — CSV export is per-identity timeline only. Bulk export is on the roadmap. |
| What activities can the system detect? | Five classes: `Working`, `Meeting`, `Inactive`, `Using_Phone`, `Unknown` (confidence below threshold). |
