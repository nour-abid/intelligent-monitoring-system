<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DashboardSummaryController
 *
 * Serves a single structured summary for the alert dashboard section.
 * All data is sourced from pre-aggregated TimescaleDB/PostgreSQL analytics views,
 * eliminating full table scans on behavior_alerts and surveillance_events.
 *
 * Analytics views used (analytics schema):
 *   daily_alerts_by_employee          — alert KPIs + all alert charts (identity-scoped)
 *   daily_activity_totals_by_employee — activity/observation charts, active_employees KPI
 *   surveillance_hourly_activity      — observation_trend when range ≤ 2 days (hourly)
 *
 * Role scoping:
 *   - null (admin) → unrestricted across all identity_name values
 *   - array        → filtered to the listed identity_name values
 *
 * Route: GET /api/monitoring/surveillance/summary  [auth:sanctum]
 *
 * Response shape (unchanged):
 *   kpis.total_alerts, kpis.active_employees, kpis.late_arrivals, kpis.early_leaves
 *   charts.alert_trend            [{label, count}]
 *   charts.alerts_by_type         [{type, count}]
 *   charts.top_affected_employees [{identity, display_name, count}]
 *   charts.activity_distribution  [{activity, total_sec, share}]
 *   charts.observation_trend      [{label, count, total_sec}]
 *   charts.top_observed_employees [{identity, display_name, total_sec}]
 */
class DashboardSummaryController extends Controller
{
    private const ANALYTICS_CONN = 'analytics';
    private const TOP_N          = 10;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start'           => ['nullable', 'string'],
            'end'             => ['nullable', 'string'],
            'include_unknown' => ['nullable', 'boolean'],
        ]);

        // Normalise to full-day boundaries so that date-only values (e.g. "2026-03-30")
        // include all records on that day: start → 00:00:00, end → 23:59:59.
        // If the value already contains a time part (space or 'T' separator), keep it as-is.
        $rawStart = $validated['start'] ?? '';
        $rawEnd   = $validated['end']   ?? '';

        $start = ($rawStart !== '')
            ? (preg_match('/[\sT]\d{2}:\d{2}/', $rawStart)
                ? str_replace('T', ' ', $rawStart)   // ISO 8601 → space-separated
                : $rawStart . ' 00:00:00')
            : null;

        $end = ($rawEnd !== '')
            ? (preg_match('/[\sT]\d{2}:\d{2}/', $rawEnd)
                ? str_replace('T', ' ', $rawEnd)
                : $rawEnd . ' 23:59:59')
            : null;

        /** @var \App\Models\User $user */
        $user              = $request->user();
        $allowedIdentities = $this->resolveScope($user);
        // Only admin may request Unknown identities in surveillance charts.
        $includeUnknown    = $user->role === 'admin'
            && filter_var($validated['include_unknown'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'kpis'   => $this->buildKpis($start, $end, $allowedIdentities),
            'charts' => $this->buildCharts($start, $end, $allowedIdentities, $includeUnknown),
        ]);
    }

    // -------------------------------------------------------------------------
    // KPIs
    // -------------------------------------------------------------------------

    private function buildKpis(
        ?string $start,
        ?string $end,
        ?array  $allowedIdentities,
    ): array {
        // Alert KPIs — daily_alerts_by_employee is a pre-aggregated materialized view
        // (GROUP BY day + identity_name + alert_type on behavior_alerts).
        // Three independent aggregation queries; no raw table scan.
        $alertBase = fn () => DB::connection(self::ANALYTICS_CONN)
            ->table('daily_alerts_by_employee')
            ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
            ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
            ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities));

        try {
            $totalAlerts  = (int) $alertBase()->sum('alert_count');
            $lateArrivals = (int) $alertBase()->where('alert_type', 'late_arrival')->sum('alert_count');
            $earlyLeaves  = (int) $alertBase()->where('alert_type', 'early_leave')->sum('alert_count');
        } catch (\Exception $e) {
            Log::warning('DashboardSummary: daily_alerts_by_employee unavailable', [
                'error' => $e->getMessage(),
            ]);
            $totalAlerts = $lateArrivals = $earlyLeaves = 0;
        }

        // Active employees — distinct identities observed in the time window.
        // daily_activity_totals_by_employee is a TimescaleDB continuous aggregate (daily buckets).
        $activeEmployees = (int) DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->where('identity_name', '!=', 'Unknown')
            ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
            ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
            ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
            ->distinct('identity_name')
            ->count('identity_name');

        return [
            'total_alerts'     => $totalAlerts,
            'active_employees' => $activeEmployees,
            'late_arrivals'    => $lateArrivals,
            'early_leaves'     => $earlyLeaves,
        ];
    }

    // -------------------------------------------------------------------------
    // Charts
    // -------------------------------------------------------------------------

    private function buildCharts(
        ?string $start,
        ?string $end,
        ?array  $allowedIdentities,
        bool    $includeUnknown = false,
    ): array {
        return [
            'alert_trend'            => $this->alertTrend($start, $end, $allowedIdentities),
            'alerts_by_type'         => $this->alertsByType($start, $end, $allowedIdentities),
            'top_affected_employees' => $this->topAffectedEmployees($start, $end, $allowedIdentities),
            'activity_distribution'  => $this->activityDistribution($start, $end, $allowedIdentities),
            'observation_trend'      => $this->observationTrend($start, $end, $allowedIdentities, $includeUnknown),
            'top_observed_employees' => $this->topObservedEmployees($start, $end, $allowedIdentities, $includeUnknown),
            'activity_evolution'     => $this->activityEvolution($start, $end, $allowedIdentities, $includeUnknown),
        ];
    }

    /**
     * Alert count per time bucket.
     * Short ranges (≤ 2 days): hourly buckets from raw public.behavior_alerts.
     * Longer ranges: daily buckets from analytics.daily_alerts_by_employee.
     * Scoped by allowedIdentities to preserve role visibility.
     * Returns [{label, count}] in chronological order.
     */
    private function alertTrend(?string $start, ?string $end, ?array $allowedIdentities): array
    {
        if ($this->isHourlyRange($start, $end)) {
            // Hourly path — raw behavior_alerts on the default (public) connection.
            // identity_name column is present on behavior_alerts.
            $rows = DB::table('behavior_alerts')
                ->when($start,                      fn ($q) => $q->where('fired_at', '>=', $start))
                ->when($end,                        fn ($q) => $q->where('fired_at', '<=', $end))
                ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                ->selectRaw(
                    "TO_CHAR(DATE_TRUNC('hour', fired_at), 'YYYY-MM-DD HH24:MI:SS') AS bucket,"
                    . ' COUNT(*) AS cnt'
                )
                ->groupByRaw('1')
                ->orderByRaw('1')
                ->get();
        } else {
            // Daily path — pre-aggregated analytics view.
            try {
                $rows = DB::connection(self::ANALYTICS_CONN)
                    ->table('daily_alerts_by_employee')
                    ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
                    ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
                    ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                    ->selectRaw("TO_CHAR(bucket, 'YYYY-MM-DD') AS bucket, SUM(alert_count) AS cnt")
                    ->groupByRaw('1')
                    ->orderByRaw('1')
                    ->get();
            } catch (\Exception $e) {
                Log::warning('DashboardSummary: alertTrend daily view unavailable', ['error' => $e->getMessage()]);
                $rows = collect();
            }
        }

        return $rows
            ->map(fn ($r) => ['label' => (string) $r->bucket, 'count' => (int) $r->cnt])
            ->values()
            ->all();
    }

    /**
     * Alert count per type, descending.
     * Source: daily_alerts_by_employee aggregated per alert_type, scoped by allowedIdentities.
     * Returns [{type, count}].
     */
    private function alertsByType(?string $start, ?string $end, ?array $allowedIdentities): array
    {
        try {
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->table('daily_alerts_by_employee')
                ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
                ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
                ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                ->selectRaw('alert_type, SUM(alert_count) AS cnt')
                ->groupBy('alert_type')
                ->orderByRaw('cnt DESC')
                ->get();
        } catch (\Exception $e) {
            Log::warning('DashboardSummary: alertsByType view unavailable', ['error' => $e->getMessage()]);
            return [];
        }

        return $rows
            ->map(fn ($r) => ['type' => (string) $r->alert_type, 'count' => (int) $r->cnt])
            ->values()
            ->all();
    }

    /**
     * Top N identities by alert count, descending.
     * Source: daily_alerts_by_employee aggregated per identity_name, scoped.
     * Returns [{identity, display_name, count}].
     */
    private function topAffectedEmployees(?string $start, ?string $end, ?array $allowedIdentities): array
    {
        try {
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->table('daily_alerts_by_employee')
                ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
                ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
                ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                ->selectRaw('identity_name, SUM(alert_count) AS cnt')
                ->groupBy('identity_name')
                ->orderByRaw('cnt DESC')
                ->limit(self::TOP_N)
                ->get();
        } catch (\Exception $e) {
            Log::warning('DashboardSummary: topAffectedEmployees view unavailable', ['error' => $e->getMessage()]);
            return [];
        }

        $identities = $rows->pluck('identity_name')->all();
        $nameMap    = User::whereIn('surveillance_identity', $identities)
            ->pluck('name', 'surveillance_identity')
            ->all();

        return $rows
            ->map(fn ($r) => [
                'identity'     => (string) $r->identity_name,
                'display_name' => $nameMap[$r->identity_name] ?? null,
                'count'        => (int) $r->cnt,
            ])
            ->values()
            ->all();
    }

    /**
     * Total observed seconds by activity class.
     * Source: daily_activity_totals_by_employee aggregated per activity, scoped.
     * Excludes 'Unknown' identities and 'Unknown' activity label.
     * Returns [{activity, total_sec, share}] descending by total_sec.
     */
    private function activityDistribution(?string $start, ?string $end, ?array $allowedIdentities): array
    {
        $rows = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->where('identity_name', '!=', 'Unknown')
            ->where('activity', '!=', 'Unknown')
            ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
            ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
            ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
            ->selectRaw('activity, SUM(total_duration_sec) AS total_sec')
            ->groupBy('activity')
            ->orderByRaw('total_sec DESC')
            ->get();

        $totalSec = $rows->sum('total_sec');

        return $rows
            ->map(fn ($r) => [
                'activity'  => (string) $r->activity,
                'total_sec' => round((float) $r->total_sec, 2),
                'share'     => $totalSec > 0
                    ? round((float) $r->total_sec / $totalSec, 4)
                    : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Per-activity observed seconds grouped per time bucket (hourly or daily).
     * Uses generate_series to produce a complete bucket sequence for the range,
     * LEFT JOINing analytics data so missing buckets are filled with zeros.
     * Returns [{label, working, meeting, inactive, using_phone}] chronologically.
     */
    private function activityEvolution(
        ?string $start,
        ?string $end,
        ?array  $allowedIdentities,
        bool    $includeUnknown,
    ): array {
        $notUnknown = !($allowedIdentities === null && $includeUnknown);

        [$aggWhere, $aggBindings] = $this->seriesAggWhere($start, $end, $notUnknown, $allowedIdentities);
        $actFilter = "activity IN ('Working','Meeting','Inactive','Using_Phone')";
        $aggWhere  = $aggWhere !== '' ? $aggWhere . " AND $actFilter" : "WHERE $actFilter";

        if ($this->isHourlyRange($start, $end)) {
            $sql = "
                WITH series AS (
                    SELECT generate_series(
                        DATE_TRUNC('hour', ?::timestamptz),
                        DATE_TRUNC('hour', ?::timestamptz),
                        '1 hour'::interval
                    ) AS bucket
                ),
                agg AS (
                    SELECT
                        DATE_TRUNC('hour', bucket AT TIME ZONE 'UTC') AS bucket,
                        activity,
                        SUM(total_duration_sec) AS total_sec
                    FROM analytics.surveillance_hourly_activity
                    {$aggWhere}
                    GROUP BY 1, 2
                )
                SELECT
                    TO_CHAR(s.bucket, 'YYYY-MM-DD HH24:MI:SS') AS time_label,
                    SUM(CASE WHEN a.activity = 'Working'     THEN a.total_sec ELSE 0 END)::bigint AS working,
                    SUM(CASE WHEN a.activity = 'Meeting'     THEN a.total_sec ELSE 0 END)::bigint AS meeting,
                    SUM(CASE WHEN a.activity = 'Inactive'    THEN a.total_sec ELSE 0 END)::bigint AS inactive,
                    SUM(CASE WHEN a.activity = 'Using_Phone' THEN a.total_sec ELSE 0 END)::bigint AS using_phone
                FROM series s
                LEFT JOIN agg a ON a.bucket = s.bucket
                GROUP BY s.bucket
                ORDER BY s.bucket
            ";
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->select($sql, array_merge([$start, $end], $aggBindings));
        } elseif ($start !== null && $end !== null) {
            $sql = "
                WITH series AS (
                    SELECT generate_series(
                        DATE_TRUNC('day', ?::timestamptz),
                        DATE_TRUNC('day', ?::timestamptz),
                        '1 day'::interval
                    ) AS bucket
                ),
                agg AS (
                    SELECT
                        DATE_TRUNC('day', bucket AT TIME ZONE 'UTC') AS bucket,
                        activity,
                        SUM(total_duration_sec) AS total_sec
                    FROM analytics.daily_activity_totals_by_employee
                    {$aggWhere}
                    GROUP BY 1, 2
                )
                SELECT
                    TO_CHAR(s.bucket, 'YYYY-MM-DD') AS time_label,
                    SUM(CASE WHEN a.activity = 'Working'     THEN a.total_sec ELSE 0 END)::bigint AS working,
                    SUM(CASE WHEN a.activity = 'Meeting'     THEN a.total_sec ELSE 0 END)::bigint AS meeting,
                    SUM(CASE WHEN a.activity = 'Inactive'    THEN a.total_sec ELSE 0 END)::bigint AS inactive,
                    SUM(CASE WHEN a.activity = 'Using_Phone' THEN a.total_sec ELSE 0 END)::bigint AS using_phone
                FROM series s
                LEFT JOIN agg a ON a.bucket = s.bucket
                GROUP BY s.bucket
                ORDER BY s.bucket
            ";
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->select($sql, array_merge([$start, $end], $aggBindings));
        } else {
            // Fallback: no date range — return sparse results without series fill.
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->table('daily_activity_totals_by_employee')
                ->when($notUnknown,                 fn ($q) => $q->where('identity_name', '!=', 'Unknown'))
                ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                ->whereIn('activity', ['Working', 'Meeting', 'Inactive', 'Using_Phone'])
                ->selectRaw(
                    "TO_CHAR(DATE_TRUNC('day', bucket AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS time_label,"
                    . ' activity, SUM(total_duration_sec) AS total_sec'
                )
                ->groupByRaw('1, 2')
                ->orderByRaw('1')
                ->get();

            $labels = $rows->pluck('time_label')->unique()->sort()->values();
            $index  = [];
            foreach ($rows as $r) {
                $index[$r->time_label][$r->activity] = (int) round((float) $r->total_sec);
            }
            return $labels->map(fn ($label) => [
                'label'       => (string) $label,
                'working'     => $index[$label]['Working']     ?? 0,
                'meeting'     => $index[$label]['Meeting']     ?? 0,
                'inactive'    => $index[$label]['Inactive']    ?? 0,
                'using_phone' => $index[$label]['Using_Phone'] ?? 0,
            ])->values()->all();
        }

        return array_map(fn ($r) => [
            'label'       => (string) $r->time_label,
            'working'     => (int) $r->working,
            'meeting'     => (int) $r->meeting,
            'inactive'    => (int) $r->inactive,
            'using_phone' => (int) $r->using_phone,
        ], $rows);
    }

    /**
     * Total observed seconds grouped per time bucket (hourly or daily).
     * Uses generate_series to produce a complete bucket sequence for the range,
     * LEFT JOINing analytics data so missing buckets are filled with zeros.
     * Returns [{label, count, total_sec}] in chronological order.
     */
    private function observationTrend(
        ?string $start,
        ?string $end,
        ?array  $allowedIdentities,
        bool    $includeUnknown,
    ): array {
        $notUnknown = !($allowedIdentities === null && $includeUnknown);

        [$aggWhere, $aggBindings] = $this->seriesAggWhere($start, $end, $notUnknown, $allowedIdentities);

        if ($this->isHourlyRange($start, $end)) {
            $sql = "
                WITH series AS (
                    SELECT generate_series(
                        DATE_TRUNC('hour', ?::timestamptz),
                        DATE_TRUNC('hour', ?::timestamptz),
                        '1 hour'::interval
                    ) AS bucket
                ),
                agg AS (
                    SELECT
                        DATE_TRUNC('hour', bucket AT TIME ZONE 'UTC') AS bucket,
                        SUM(event_count)        AS event_count,
                        SUM(total_duration_sec) AS total_sec
                    FROM analytics.surveillance_hourly_activity
                    {$aggWhere}
                    GROUP BY 1
                )
                SELECT
                    TO_CHAR(s.bucket, 'YYYY-MM-DD HH24:MI:SS')  AS time_label,
                    COALESCE(a.event_count, 0)::bigint           AS event_count,
                    COALESCE(a.total_sec,   0)::bigint           AS total_sec
                FROM series s
                LEFT JOIN agg a ON a.bucket = s.bucket
                ORDER BY s.bucket
            ";
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->select($sql, array_merge([$start, $end], $aggBindings));
        } elseif ($start !== null && $end !== null) {
            $sql = "
                WITH series AS (
                    SELECT generate_series(
                        DATE_TRUNC('day', ?::timestamptz),
                        DATE_TRUNC('day', ?::timestamptz),
                        '1 day'::interval
                    ) AS bucket
                ),
                agg AS (
                    SELECT
                        DATE_TRUNC('day', bucket AT TIME ZONE 'UTC') AS bucket,
                        SUM(event_count)        AS event_count,
                        SUM(total_duration_sec) AS total_sec
                    FROM analytics.daily_activity_totals_by_employee
                    {$aggWhere}
                    GROUP BY 1
                )
                SELECT
                    TO_CHAR(s.bucket, 'YYYY-MM-DD')    AS time_label,
                    COALESCE(a.event_count, 0)::bigint AS event_count,
                    COALESCE(a.total_sec,   0)::bigint AS total_sec
                FROM series s
                LEFT JOIN agg a ON a.bucket = s.bucket
                ORDER BY s.bucket
            ";
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->select($sql, array_merge([$start, $end], $aggBindings));
        } else {
            // Fallback: no date range — return sparse aggregate without series fill.
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->table('daily_activity_totals_by_employee')
                ->when($notUnknown,                 fn ($q) => $q->where('identity_name', '!=', 'Unknown'))
                ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
                ->selectRaw(
                    "TO_CHAR(DATE_TRUNC('day', bucket AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS time_label,"
                    . ' SUM(event_count) AS event_count, SUM(total_duration_sec) AS total_sec'
                )
                ->groupByRaw('1')
                ->orderByRaw('1')
                ->get();

            return $rows
                ->map(fn ($r) => [
                    'label'     => (string) $r->time_label,
                    'count'     => (int) $r->event_count,
                    'total_sec' => (int) round((float) $r->total_sec),
                ])
                ->values()
                ->all();
        }

        return array_map(fn ($r) => [
            'label'     => (string) $r->time_label,
            'count'     => (int) $r->event_count,
            'total_sec' => (int) $r->total_sec,
        ], $rows);
    }

    /**
     * Top N identities ranked by total observed duration.
     * Source: daily_activity_totals_by_employee aggregated per identity_name, scoped.
     * Respects role scoping and include_unknown flag.
     * Returns [{identity, display_name, total_sec}] descending.
     */
    private function topObservedEmployees(
        ?string $start,
        ?string $end,
        ?array  $allowedIdentities,
        bool    $includeUnknown,
    ): array {
        $rows = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->when(!($allowedIdentities === null && $includeUnknown),
                fn ($q) => $q->where('identity_name', '!=', 'Unknown'))
            ->when($start,                      fn ($q) => $q->where('bucket', '>=', $start))
            ->when($end,                        fn ($q) => $q->where('bucket', '<=', $end))
            ->when($allowedIdentities !== null, fn ($q) => $q->whereIn('identity_name', $allowedIdentities))
            ->selectRaw('identity_name, SUM(total_duration_sec) AS total_sec')
            ->groupBy('identity_name')
            ->orderByRaw('total_sec DESC')
            ->limit(self::TOP_N)
            ->get();

        $identities = $rows->pluck('identity_name')->all();
        $nameMap    = User::whereIn('surveillance_identity', $identities)
            ->pluck('name', 'surveillance_identity')
            ->all();

        return $rows
            ->map(fn ($r) => [
                'identity'     => (string) $r->identity_name,
                'display_name' => $nameMap[$r->identity_name] ?? null,
                'total_sec'    => (int) round((float) $r->total_sec),
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a safe WHERE clause string and its PDO bindings for a raw CTE
     * sub-aggregate query. The two generate_series boundary bindings (start, end)
     * are NOT included here — callers prepend them as the first two positional
     * parameters before spreading $bindings.
     *
     * Returns [string $whereClause, array $bindings].
     * An empty allowedIdentities array produces `1 = 0` (no rows) as intended.
     */
    private function seriesAggWhere(
        ?string $start,
        ?string $end,
        bool    $notUnknown,
        ?array  $allowedIdentities,
    ): array {
        $conditions = [];
        $bindings   = [];

        if ($notUnknown) {
            $conditions[] = "identity_name != 'Unknown'";
        }
        if ($start !== null) {
            $conditions[] = 'bucket >= ?';
            $bindings[]   = $start;
        }
        if ($end !== null) {
            $conditions[] = 'bucket <= ?';
            $bindings[]   = $end;
        }
        if ($allowedIdentities !== null) {
            if (count($allowedIdentities) === 0) {
                $conditions[] = '1 = 0'; // scoped to nothing
            } else {
                $placeholders = implode(',', array_fill(0, count($allowedIdentities), '?'));
                $conditions[] = "identity_name IN ($placeholders)";
                $bindings     = array_merge($bindings, $allowedIdentities);
            }
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $bindings];
    }

    /**
     * Use hourly buckets for ranges up to 2 days (today, yesterday, short custom).
     */
    private function isHourlyRange(?string $start, ?string $end): bool
    {
        if ($start === null || $end === null) {
            return false;
        }
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false) {
            return false;
        }
        return ($e - $s) <= 172800;
    }

    /**
     * Resolve the set of identity names the authenticated user may see.
     * Mirrors the same logic as SurveillanceAnalyticsController::resolveScope().
     *
     * null  → admin: unrestricted
     * array → scoped list (may be empty for unmapped viewer)
     */
    private function resolveScope(User $user): ?array
    {
        if ($user->role === 'admin') {
            return null;
        }

        if ($user->role === 'superviseur') {
            $employeeIdentities = User::where('supervisor_id', $user->id)
                ->whereNotNull('surveillance_identity')
                ->where('surveillance_identity', '<>', '')
                ->pluck('surveillance_identity')
                ->all();

            $own = $user->surveillance_identity;
            if ($own !== null && $own !== '') {
                $employeeIdentities[] = $own;
            }

            return array_values(array_unique($employeeIdentities));
        }

        // viewer — self only
        $identity = $user->surveillance_identity;
        return ($identity !== null && $identity !== '') ? [$identity] : [];
    }
}
