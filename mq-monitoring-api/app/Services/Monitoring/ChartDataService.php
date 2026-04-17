<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * ChartDataService
 *
 * Validates an LLM-produced chart intent spec and builds a real dataset
 * from the analytics views.  The LLM NEVER produces data values — it only
 * emits a typed spec (chart_type + metric + group_by + time_range).
 * This service is the single gate: spec validation → RBAC scope enforcement
 * → analytics query → structured dataset ready for the frontend renderer.
 *
 * Allowed spec values
 *   chart_type : bar | line | donut
 *   metric     : working_time | phone_usage | inactivity | focus_score |
 *                alerts | late_arrivals | early_leaves
 *   group_by   : day | hour | weekday | employee | activity | alert_type
 *   time_range : 7d | 30d | today | week | month
 *
 * Scope contract (same as AnalyticsViewsService)
 *   null    → admin: no restriction
 *   [...]   → supervisor / viewer: restrict to listed identity_name values
 *   []      → viewer with no linked identity: return empty dataset
 *
 * Return shape
 *   type   : 'bar' | 'line' | 'pie'   (donut → pie for frontend)
 *   title  : human-readable string
 *   labels : string[]
 *   values : number[]              (seconds for time metrics, % for focus_score, count otherwise)
 *   colors : string[]
 *   unit   : 'sec' | '%' | 'count'
 */
class ChartDataService
{
    // ── Allowed enum values ──────────────────────────────────────────────────

    private const ALLOWED_TYPES   = ['bar', 'line', 'donut'];
    private const ALLOWED_METRICS = [
        'working_time', 'phone_usage', 'inactivity',
        'focus_score', 'alerts', 'late_arrivals', 'early_leaves',
        'activity_distribution',
    ];
    private const ALLOWED_GROUPBY = ['day', 'hour', 'weekday', 'employee', 'activity', 'alert_type'];
    private const ALLOWED_RANGES  = ['7d', '30d', 'today', 'week', 'month'];

    /**
     * Permitted metric → group_by combinations.
     * Combinations not listed are semantically invalid even if enum values are valid.
     */
    private const VALID_COMBINATIONS = [
        'working_time'         => ['day', 'hour', 'weekday', 'employee'],
        'phone_usage'          => ['day', 'hour', 'weekday', 'employee'],
        'inactivity'           => ['day', 'weekday', 'employee'],
        'focus_score'          => ['day', 'weekday', 'employee'],
        'alerts'               => ['day', 'weekday', 'employee', 'alert_type'],
        'late_arrivals'        => ['day', 'weekday', 'employee'],
        'early_leaves'         => ['day', 'weekday', 'employee'],
        'activity_distribution'=> ['activity', 'employee'],
    ];

    /** Max data points for time-series (day / hour) charts. */
    private const MAX_TIME_POINTS = 31;

    /** Max bars for categorical (employee / weekday / alert_type / activity) charts. */
    private const MAX_BARS = 10;

    private const ANALYTICS_CONN = 'analytics';

    // ── Color constants ──────────────────────────────────────────────────────

    private const ACTIVITY_COLORS = [
        'Working'     => '#10b981',
        'Using_Phone' => '#ef4444',
        'Inactive'    => '#f59e0b',
        'Unknown'     => '#94a3b8',
    ];

    private const PALETTE = [
        '#6366f1', '#10b981', '#ef4444', '#f59e0b',
        '#06b6d4', '#8b5cf6', '#f97316', '#ec4899',
    ];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Validate a raw spec array from JSON-decoded LLM output.
     * Returns a list of error strings; empty array = valid.
     */
    public function validate(array $spec): array
    {
        $errors = [];

        if (!isset($spec['chart_type']) || !in_array($spec['chart_type'], self::ALLOWED_TYPES, true)) {
            $errors[] = 'chart_type must be one of: ' . implode(', ', self::ALLOWED_TYPES);
        }

        $metricOk  = isset($spec['metric'])   && in_array($spec['metric'],   self::ALLOWED_METRICS, true);
        $groupByOk = isset($spec['group_by']) && in_array($spec['group_by'], self::ALLOWED_GROUPBY, true);

        if (!$metricOk) {
            $errors[] = 'metric must be one of: ' . implode(', ', self::ALLOWED_METRICS);
        }
        if (!$groupByOk) {
            $errors[] = 'group_by must be one of: ' . implode(', ', self::ALLOWED_GROUPBY);
        }

        // Semantic combination check — only run when both values are individually valid
        if ($metricOk && $groupByOk) {
            $allowed = self::VALID_COMBINATIONS[$spec['metric']] ?? [];
            if (!in_array($spec['group_by'], $allowed, true)) {
                $errors[] = "metric '{$spec['metric']}' cannot be grouped by '{$spec['group_by']}'. "
                    . 'Allowed group_by values: ' . implode(', ', $allowed);
            }
        }

        // time_range is optional; validate only if present
        if (isset($spec['time_range']) && !in_array($spec['time_range'], self::ALLOWED_RANGES, true)) {
            $errors[] = 'time_range must be one of: ' . implode(', ', self::ALLOWED_RANGES);
        }

        return $errors;
    }

    /**
     * Build the chart dataset from a validated spec.
     *
     * @param  array       $spec       Already validated spec (chart_type, metric, group_by, time_range?)
     * @param  array|null  $scope      null = admin; [] = no access; [...] = allowed identities
     * @param  string|null $dateStart  Custom start date from the active dashboard range (overrides time_range)
     * @param  string|null $dateEnd    Custom end date from the active dashboard range (overrides time_range)
     * @return array{type:string, title:string, labels:string[], values:float[], colors:string[], unit:string}|null
     *         null when the query returns no data (empty dataset guard)
     */
    public function build(array $spec, ?array $scope, ?string $dateStart = null, ?string $dateEnd = null): ?array
    {
        $chartType = $spec['chart_type'] === 'donut' ? 'pie' : $spec['chart_type'];
        $metric    = $spec['metric'];
        $groupBy   = $spec['group_by'];
        $range     = $spec['time_range'] ?? '7d';

        // Viewer with no linked surveillance identity → empty dataset, no query
        if (is_array($scope) && count($scope) === 0) {
            return $this->emptyChart($chartType, $metric, $groupBy, $range);
        }

        // Custom dashboard date range takes priority over the AI-emitted preset.
        if ($dateStart && $dateEnd) {
            $start = substr($dateStart, 0, 10); // ensure YYYY-MM-DD
            $end   = substr($dateEnd,   0, 10);
        } else {
            [$start, $end] = $this->resolveTimeRange($range);
        }

        try {
            $rows = $this->queryRows($metric, $groupBy, $start, $end, $scope);
        } catch (\Exception $e) {
            // Analytics views may not exist yet — fall through to raw-events fallback.
            Log::info('ChartDataService: analytics view unavailable, trying surveillance_events fallback', [
                'metric'  => $metric,
                'group_by'=> $groupBy,
                'error'   => $e->getMessage(),
            ]);
            $rows = [];
        }

        // When analytics views return no data, fall back to querying surveillance_events directly.
        if (empty($rows)) {
            try {
                $rows = $this->queryRowsFromSurveillanceEvents($metric, $groupBy, $start, $end, $scope);
            } catch (\Exception $e) {
                Log::info('ChartDataService: surveillance_events fallback also failed', [
                    'metric' => $metric, 'error' => $e->getMessage(),
                ]);
                $rows = [];
            }
        }

        // Empty dataset guard: return null signal so controller can drop the chart
        if (empty($rows)) {
            return null;
        }

        // Apply result limits to prevent oversized payloads
        $rows = $this->applyLimits($groupBy, $rows);

        // Build title: use custom date range label when the dashboard range is active
        if ($dateStart && $dateEnd) {
            $startLabel = substr($dateStart, 0, 10);
            $endLabel   = substr($dateEnd,   0, 10);
            $rangeLabel = $startLabel === $endLabel ? $startLabel : "$startLabel → $endLabel";
            $title = $this->buildTitle($metric, $groupBy, $range, $rangeLabel);
        } else {
            $title = $this->buildTitle($metric, $groupBy, $range);
        }
        $unit   = $this->unitFor($metric);
        $labels = array_keys($rows);
        $values = array_values($rows);
        $colors = $this->colorsFor($metric, $groupBy, $labels);

        return [
            'type'   => $chartType,
            'title'  => $title,
            'labels' => $labels,
            'values' => $values,
            'colors' => $colors,
            'unit'   => $unit,
        ];
    }

    // ── Limit enforcement ────────────────────────────────────────────────────

    /**
     * Trim the result set to the configured caps.
     * Time-series (day / hour) → MAX_TIME_POINTS most recent entries.
     * Categorical → MAX_BARS top entries (already sorted by value desc or key).
     */
    private function applyLimits(string $groupBy, array $rows): array
    {
        if (in_array($groupBy, ['day', 'hour'], true)) {
            // Keep the last MAX_TIME_POINTS entries (already ksort-ed asc)
            if (count($rows) > self::MAX_TIME_POINTS) {
                $rows = array_slice($rows, -self::MAX_TIME_POINTS, null, true);
            }
        } else {
            // Categorical: cap at MAX_BARS; rows are already sorted descending by value
            if (count($rows) > self::MAX_BARS) {
                $rows = array_slice($rows, 0, self::MAX_BARS, true);
            }
        }
        return $rows;
    }

    // ── Query dispatch ───────────────────────────────────────────────────────

    /**
     * Route metric + group_by to the correct analytics view query.
     * Returns an ordered associative array: label → value.
     */
    private function queryRows(
        string $metric,
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        return match ($metric) {
            'working_time'         => $this->queryActivityMetric('Working',     $groupBy, $start, $end, $scope),
            'phone_usage'          => $this->queryActivityMetric('Using_Phone', $groupBy, $start, $end, $scope),
            'inactivity'           => $this->queryInactivity($groupBy, $start, $end, $scope),
            'focus_score'          => $this->queryFocusScore($groupBy, $start, $end, $scope),
            'alerts'               => $this->queryAlerts($groupBy, $start, $end, $scope),
            'late_arrivals'        => $this->queryAlertsByType('late_arrival', $groupBy, $start, $end, $scope),
            'early_leaves'         => $this->queryAlertsByType('early_leave',  $groupBy, $start, $end, $scope),
            'activity_distribution'=> $this->queryActivityDistribution($groupBy, $start, $end, $scope),
            default                => [],
        };
    }

    // ── Per-metric query methods ─────────────────────────────────────────────

    /**
     * Total duration (seconds) for a single activity type.
     * Uses surveillance_hourly_activity for hour group_by; daily view otherwise.
     */
    private function queryActivityMetric(
        string $activity,
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        // Hour-level granularity requires the hourly continuous aggregate view.
        if ($groupBy === 'hour') {
            $query = DB::connection(self::ANALYTICS_CONN)
                ->table('surveillance_hourly_activity')
                ->where('activity', $activity)
                ->where('bucket', '>=', $start . ' 00:00:00')
                ->where('bucket', '<=', $end . ' 23:59:59');
            if ($scope !== null) {
                $query->whereIn('identity_name', $scope);
            }
            return $this->aggregateByHour($query->get(), 'total_duration_sec');
        }

        // All other group_by: use the daily totals view.
        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->where('activity', $activity)
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        return match ($groupBy) {
            'day'      => $this->aggregateByDay($rows, 'total_duration_sec'),
            'weekday'  => $this->aggregateByWeekday($rows, 'total_duration_sec'),
            'employee' => $this->aggregateByEmployee($rows, 'total_duration_sec'),
            'activity' => [$activity => (float) $rows->sum('total_duration_sec')],
            default    => $this->aggregateByDay($rows, 'total_duration_sec'),
        };
    }

    /**
     * Total inactivity seconds from daily_inactivity_per_employee.
     */
    private function queryInactivity(
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_inactivity_per_employee')
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        return match ($groupBy) {
            'day'      => $this->aggregateByDay($rows, 'total_inactive_sec'),
            'weekday'  => $this->aggregateByWeekday($rows, 'total_inactive_sec'),
            'employee' => $this->aggregateByEmployee($rows, 'total_inactive_sec'),
            default    => $this->aggregateByDay($rows, 'total_inactive_sec'),
        };
    }

    /**
     * Focus score (%) = max(0, min(100, round(working_pct − phone_pct × 0.5))).
     * Aggregated from daily_activity_totals_by_employee.
     */
    private function queryFocusScore(
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        // Accumulate working/phone/total per grouping key
        $buckets = [];
        foreach ($rows as $r) {
            $key = match ($groupBy) {
                'day'      => substr($r->bucket, 0, 10),
                'weekday'  => $this->bucketToWeekday($r->bucket),
                'employee' => $r->identity_name,
                default    => substr($r->bucket, 0, 10),
            };
            $buckets[$key] ??= ['working' => 0.0, 'phone' => 0.0, 'total' => 0.0];
            $buckets[$key]['total'] += $r->total_duration_sec;
            if ($r->activity === 'Working') {
                $buckets[$key]['working'] += $r->total_duration_sec;
            } elseif ($r->activity === 'Using_Phone') {
                $buckets[$key]['phone'] += $r->total_duration_sec;
            }
        }

        // Compute score per key and sort
        $out = [];
        foreach ($buckets as $key => $v) {
            $out[$key] = $v['total'] > 0
                ? (float) max(0, min(100, round(
                    ($v['working'] / $v['total']) * 100 - ($v['phone'] / $v['total']) * 50
                  )))
                : 0.0;
        }

        if ($groupBy === 'day' || $groupBy === 'weekday') {
            ksort($out);
        }

        return $out;
    }

    /**
     * Alert counts from daily_alerts_by_type (alert_type group_by) or
     * daily_alerts_by_employee (all other group_by values).
     */
    private function queryAlerts(
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        if ($groupBy === 'alert_type') {
            // No identity scope on this view — it's already aggregated across all employees.
            $rows = DB::connection(self::ANALYTICS_CONN)
                ->table('daily_alerts_by_type')
                ->where('bucket', '>=', $start . ' 00:00:00')
                ->where('bucket', '<=', $end . ' 23:59:59')
                ->get();
            $out = [];
            foreach ($rows as $r) {
                $out[$r->alert_type] = ($out[$r->alert_type] ?? 0) + $r->alert_count;
            }
            arsort($out);
            return $out;
        }

        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_alerts_by_employee')
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        return match ($groupBy) {
            'day'      => $this->aggregateByDay($rows, 'alert_count'),
            'weekday'  => $this->aggregateByWeekday($rows, 'alert_count'),
            'employee' => $this->aggregateByEmployee($rows, 'alert_count'),
            default    => $this->aggregateByDay($rows, 'alert_count'),
        };
    }

    /**
     * Alert counts filtered to a specific alert_type slug.
     */
    private function queryAlertsByType(
        string $alertType,
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_alerts_by_employee')
            ->where('alert_type', $alertType)
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        return match ($groupBy) {
            'day'      => $this->aggregateByDay($rows, 'alert_count'),
            'weekday'  => $this->aggregateByWeekday($rows, 'alert_count'),
            'employee' => $this->aggregateByEmployee($rows, 'alert_count'),
            default    => $this->aggregateByDay($rows, 'alert_count'),
        };
    }

    /**
     * Activity distribution: total seconds per activity type (or per employee)
     * from daily_activity_totals_by_employee.
     *
     * group_by=activity → ['Working'=>sec, 'Using_Phone'=>sec, ...]
     * group_by=employee → total time per employee (all activities summed)
     */
    private function queryActivityDistribution(
        string $groupBy,
        string $start,
        string $end,
        ?array $scope
    ): array {
        $query = DB::connection(self::ANALYTICS_CONN)
            ->table('daily_activity_totals_by_employee')
            ->where('bucket', '>=', $start . ' 00:00:00')
            ->where('bucket', '<=', $end . ' 23:59:59');
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
        $rows = $query->get();

        if ($groupBy === 'activity') {
            $out = [];
            foreach ($rows as $r) {
                $out[$r->activity] = ($out[$r->activity] ?? 0.0) + (float) $r->total_duration_sec;
            }
            // Order by semantic importance: Working first, then descending
            $preferred = ['Working', 'Using_Phone', 'Inactive', 'Unknown'];
            $ordered   = [];
            foreach ($preferred as $act) {
                if (isset($out[$act])) {
                    $ordered[$act] = $out[$act];
                }
            }
            // Any unknown activities appended
            foreach ($out as $k => $v) {
                if (!isset($ordered[$k])) {
                    $ordered[$k] = $v;
                }
            }
            return $ordered;
        }

        // group_by=employee: total all-activity time per person
        return $this->aggregateByEmployee($rows, 'total_duration_sec');
    }

    // ── Aggregation helpers ──────────────────────────────────────────────────

    private function aggregateByDay($rows, string $col): array
    {
        $out = [];
        foreach ($rows as $r) {
            $day = substr($r->bucket, 0, 10);
            $out[$day] = ($out[$day] ?? 0.0) + $r->$col;
        }
        ksort($out);
        return $out;
    }

    private function aggregateByWeekday($rows, string $col): array
    {
        // Ordered Mon→Sun
        $ordered = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
        foreach ($rows as $r) {
            $key = $this->bucketToWeekday($r->bucket);
            $ordered[$key] = ($ordered[$key] ?? 0.0) + $r->$col;
        }
        // Remove days with zero value if at least some days have data
        $hasData = array_filter($ordered, fn ($v) => $v > 0);
        return $hasData ?: $ordered;
    }

    private function aggregateByEmployee($rows, string $col): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r->identity_name] = ($out[$r->identity_name] ?? 0.0) + $r->$col;
        }
        arsort($out);
        return $out;
    }

    private function aggregateByHour($rows, string $col): array
    {
        $out = [];
        foreach ($rows as $r) {
            // Bucket is a TIMESTAMPTZ — extract HH:00
            $hour = substr($r->bucket, 11, 2) . ':00';
            $out[$hour] = ($out[$hour] ?? 0.0) + $r->$col;
        }
        ksort($out);
        return $out;
    }

    private function bucketToWeekday(string $bucket): string
    {
        return date('D', strtotime(substr($bucket, 0, 10)));
    }

    // ── Meta helpers ─────────────────────────────────────────────────────────

    private function resolveTimeRange(string $range): array
    {
        return match ($range) {
            '7d'    => [now()->subDays(7)->format('Y-m-d'),        now()->format('Y-m-d')],
            '30d'   => [now()->subDays(30)->format('Y-m-d'),       now()->format('Y-m-d')],
            'today' => [now()->format('Y-m-d'),                     now()->format('Y-m-d')],
            'week'  => [now()->startOfWeek()->format('Y-m-d'),     now()->endOfWeek()->format('Y-m-d')],
            'month' => [now()->startOfMonth()->format('Y-m-d'),    now()->endOfMonth()->format('Y-m-d')],
            default => [now()->subDays(7)->format('Y-m-d'),        now()->format('Y-m-d')],
        };
    }

    private function buildTitle(string $metric, string $groupBy, string $range, ?string $customRangeLabel = null): string
    {
        $metricLabels = [
            'working_time'         => 'Working Time',
            'phone_usage'          => 'Phone Usage',
            'inactivity'           => 'Inactivity',
            'focus_score'          => 'Focus Score',
            'alerts'               => 'Alerts',
            'late_arrivals'        => 'Late Arrivals',
            'early_leaves'         => 'Early Leaves',
            'activity_distribution'=> 'Activity Distribution',
        ];
        $groupLabels = [
            'day'        => 'per Day',
            'hour'       => 'per Hour',
            'weekday'    => 'per Weekday',
            'employee'   => 'by Employee',
            'activity'   => 'by Activity',
            'alert_type' => 'by Type',
        ];
        $rangeLabels = [
            '7d'    => 'last 7 days',
            '30d'   => 'last 30 days',
            'today' => 'today',
            'week'  => 'this week',
            'month' => 'this month',
        ];

        $m = $metricLabels[$metric]  ?? $metric;
        $g = $groupLabels[$groupBy]  ?? $groupBy;
        $r = $customRangeLabel ?? ($rangeLabels[$range] ?? $range);

        return "{$m} {$g} ({$r})";
    }

    private function unitFor(string $metric): string
    {
        return match ($metric) {
            'focus_score'                   => '%',
            'alerts', 'late_arrivals',
            'early_leaves'                  => 'count',
            'activity_distribution'         => 'sec',
            default                         => 'sec',
        };
    }

    private function colorsFor(string $metric, string $groupBy, array $labels): array
    {
        // Activity breakdown: use per-activity semantic colors
        if ($groupBy === 'activity') {
            return array_map(fn ($l) => self::ACTIVITY_COLORS[$l] ?? '#94a3b8', $labels);
        }

        // Employee / weekday / alert_type: use rotating palette
        if (in_array($groupBy, ['employee', 'weekday', 'alert_type'], true)) {
            return array_map(
                fn ($i) => self::PALETTE[$i % count(self::PALETTE)],
                range(0, max(0, count($labels) - 1))
            );
        }

        // Time series (day, hour): flat semantic color per metric
        $base = match ($metric) {
            'working_time'  => '#10b981',
            'phone_usage'   => '#ef4444',
            'inactivity'    => '#f59e0b',
            'focus_score'   => '#6366f1',
            'alerts'        => '#ef4444',
            'late_arrivals' => '#f97316',
            'early_leaves'  => '#8b5cf6',
            default         => '#6366f1',
        };

        return array_fill(0, count($labels), $base);
    }

    private function emptyChart(string $type, string $metric, string $groupBy, string $range): array
    {
        return [
            'type'   => $type,
            'title'  => $this->buildTitle($metric, $groupBy, $range),
            'labels' => [],
            'values' => [],
            'colors' => [],
            'unit'   => $this->unitFor($metric),
        ];
    }

    // ── Surveillance-events fallback queries ─────────────────────────────────
    // Used when TimescaleDB continuous-aggregate views have no data for the
    // requested window.  Queries surveillance.surveillance_events directly.

    private const SURVEILLANCE_CONN  = 'surveillance';
    private const SURVEILLANCE_TABLE = 'surveillance_events';

    /**
     * Dispatch metric+group_by to the appropriate raw-events fallback query.
     * Alerts / late_arrivals / early_leaves are skipped (different source table).
     */
    private function queryRowsFromSurveillanceEvents(
        string  $metric,
        string  $groupBy,
        string  $start,
        string  $end,
        ?array  $scope
    ): array {
        $from = $start . ' 00:00:00';
        $to   = $end   . ' 23:59:59';

        return match ($metric) {
            'working_time'          => $this->fallbackActivityDuration('Working',     $groupBy, $from, $to, $scope),
            'phone_usage'           => $this->fallbackActivityDuration('Using_Phone', $groupBy, $from, $to, $scope),
            'inactivity'            => $this->fallbackActivityDuration('Inactive',    $groupBy, $from, $to, $scope),
            'focus_score'           => $this->fallbackFocusScore($groupBy, $from, $to, $scope),
            'activity_distribution' => $this->fallbackActivityDistribution($groupBy, $from, $to, $scope),
            default                 => [],   // alerts / late_arrivals / early_leaves: no fallback
        };
    }

    /** Total duration for a single activity type from surveillance_events. */
    private function fallbackActivityDuration(
        string $activity,
        string $groupBy,
        string $from,
        string $to,
        ?array $scope
    ): array {
        $db    = DB::connection(self::SURVEILLANCE_CONN);
        $query = $db->table(self::SURVEILLANCE_TABLE)
            ->where('activity',          $activity)
            ->where('timestamp_start', '>=', $from)
            ->where('timestamp_start', '<=', $to);
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }

        switch ($groupBy) {
            case 'employee':
                $rows = $query->select('identity_name', DB::raw('SUM(duration_sec) as total'))
                    ->groupBy('identity_name')->get();
                $out = [];
                foreach ($rows as $r) {
                    $out[$r->identity_name] = (float) $r->total;
                }
                arsort($out);
                return $out;

            case 'day':
                $rows = $query->select(
                    DB::raw("DATE(timestamp_start) as day"),
                    DB::raw('SUM(duration_sec) as total')
                )->groupBy(DB::raw('DATE(timestamp_start)'))->get();
                $out = [];
                foreach ($rows as $r) {
                    $out[(string)$r->day] = (float) $r->total;
                }
                ksort($out);
                return $out;

            case 'weekday':
                $rows = $query->select(
                    DB::raw("TO_CHAR(timestamp_start, 'Dy') as weekday"),
                    DB::raw('SUM(duration_sec) as total')
                )->groupBy(DB::raw("TO_CHAR(timestamp_start, 'Dy')"))->get();
                $ordered = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0,
                            'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
                foreach ($rows as $r) {
                    if (isset($ordered[$r->weekday])) {
                        $ordered[$r->weekday] = (float) $r->total;
                    }
                }
                $hasData = array_filter($ordered, fn($v) => $v > 0);
                return $hasData ?: $ordered;

            case 'hour':
                $rows = $query->select(
                    DB::raw("EXTRACT(HOUR FROM timestamp_start)::int as hr"),
                    DB::raw('SUM(duration_sec) as total')
                )->groupBy(DB::raw('EXTRACT(HOUR FROM timestamp_start)::int'))->get();
                $out = [];
                foreach ($rows as $r) {
                    $out[str_pad((string)$r->hr, 2, '0', STR_PAD_LEFT) . ':00'] = (float) $r->total;
                }
                ksort($out);
                return $out;

            default:
                return [];
        }
    }

    /** Focus score per grouping key from surveillance_events. */
    private function fallbackFocusScore(
        string $groupBy,
        string $from,
        string $to,
        ?array $scope
    ): array {
        $db    = DB::connection(self::SURVEILLANCE_CONN);
        $query = $db->table(self::SURVEILLANCE_TABLE)
            ->where('timestamp_start', '>=', $from)
            ->where('timestamp_start', '<=', $to);
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }

        $groupExpr = match ($groupBy) {
            'day'     => 'DATE(timestamp_start)',
            'weekday' => "TO_CHAR(timestamp_start, 'Dy')",
            default   => 'identity_name',
        };

        $rows = $query->select(
            DB::raw("$groupExpr as grp"),
            DB::raw("SUM(CASE WHEN activity = 'Working'     THEN duration_sec ELSE 0 END) as working_sec"),
            DB::raw("SUM(CASE WHEN activity = 'Using_Phone' THEN duration_sec ELSE 0 END) as phone_sec"),
            DB::raw('SUM(duration_sec) as total_sec')
        )->groupBy(DB::raw($groupExpr))->get();

        $out = [];
        foreach ($rows as $r) {
            $total = (float) $r->total_sec;
            $out[(string)$r->grp] = $total > 0
                ? (float) max(0, min(100, round(
                    ((float)$r->working_sec / $total) * 100
                    - ((float)$r->phone_sec  / $total) * 50
                  )))
                : 0.0;
        }

        if ($groupBy === 'day' || $groupBy === 'weekday') {
            ksort($out);
        } else {
            arsort($out);
        }

        return $out;
    }

    /** Activity distribution from surveillance_events. */
    private function fallbackActivityDistribution(
        string $groupBy,
        string $from,
        string $to,
        ?array $scope
    ): array {
        $db    = DB::connection(self::SURVEILLANCE_CONN);
        $query = $db->table(self::SURVEILLANCE_TABLE)
            ->where('timestamp_start', '>=', $from)
            ->where('timestamp_start', '<=', $to);
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }

        if ($groupBy === 'activity') {
            $rows = $query->select('activity', DB::raw('SUM(duration_sec) as total'))
                ->groupBy('activity')->get();
            $out = [];
            foreach ($rows as $r) {
                $out[$r->activity] = (float) $r->total;
            }
            $preferred = ['Working', 'Using_Phone', 'Inactive', 'Unknown'];
            $ordered   = [];
            foreach ($preferred as $act) {
                if (isset($out[$act])) $ordered[$act] = $out[$act];
            }
            foreach ($out as $k => $v) {
                if (!isset($ordered[$k])) $ordered[$k] = $v;
            }
            return $ordered;
        }

        // group_by=employee: total all-activity time per person
        $rows = $query->select('identity_name', DB::raw('SUM(duration_sec) as total'))
            ->groupBy('identity_name')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->identity_name] = (float) $r->total;
        }
        arsort($out);
        return $out;
    }
}
