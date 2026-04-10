<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * AnalyticsViewsService
 *
 * Read-only queries over the five analytics views in the analytics PostgreSQL
 * schema.  Three are TimescaleDB continuous-aggregate views (surveillance-based)
 * and two are materialized views (alert-based).
 *
 * Views queried (all in analytics schema):
 *   surveillance_hourly_activity          — bucket TIMESTAMPTZ, identity_name, activity, event_count, total_duration_sec
 *   daily_inactivity_per_employee         — bucket TIMESTAMPTZ, identity_name, inactive_event_count, total_inactive_sec
 *   daily_activity_totals_by_employee     — bucket TIMESTAMPTZ, identity_name, activity, event_count, total_duration_sec
 *   daily_alerts_by_type                  — bucket TIMESTAMP,   alert_type, alert_count
 *   daily_alerts_by_employee              — bucket TIMESTAMP,   identity_name, alert_type, alert_count
 *
 * All public methods:
 *   $start / $end  — optional 'YYYY-MM-DD' date strings; null means unbounded.
 *   $scope         — null means no restriction (admin); [] means no access;
 *                    non-empty array restricts to the listed identity_name values.
 *
 * Returns plain PHP arrays — no serialization in this layer.
 */
class AnalyticsViewsService
{
    private const CONN = 'analytics';

    // -----------------------------------------------------------------------
    // Surveillance views (TimescaleDB continuous aggregates)
    // -----------------------------------------------------------------------

    /**
     * Hourly activity buckets (1-hour windows) per identity and activity type.
     *
     * @return list<array{bucket:string, identity_name:string, activity:string, event_count:int, total_duration_sec:float}>
     */
    public function hourlyActivity(?string $start, ?string $end, ?array $scope): array
    {
        $query = DB::connection(self::CONN)
            ->table('surveillance_hourly_activity')
            ->orderBy('bucket')
            ->orderBy('identity_name');

        $this->applyDateRange($query, $start, $end);
        $this->applyScope($query, $scope);

        return $query->get()->map(fn ($r) => [
            'bucket'             => (string) $r->bucket,
            'identity_name'      => $r->identity_name,
            'activity'           => $r->activity,
            'event_count'        => (int) $r->event_count,
            'total_duration_sec' => (float) $r->total_duration_sec,
        ])->all();
    }

    /**
     * Daily inactivity totals (1-day windows) per identity.
     *
     * @return list<array{bucket:string, identity_name:string, inactive_event_count:int, total_inactive_sec:float}>
     */
    public function dailyInactivity(?string $start, ?string $end, ?array $scope): array
    {
        $query = DB::connection(self::CONN)
            ->table('daily_inactivity_per_employee')
            ->orderBy('bucket')
            ->orderBy('identity_name');

        $this->applyDateRange($query, $start, $end);
        $this->applyScope($query, $scope);

        return $query->get()->map(fn ($r) => [
            'bucket'               => (string) $r->bucket,
            'identity_name'        => $r->identity_name,
            'inactive_event_count' => (int) $r->inactive_event_count,
            'total_inactive_sec'   => (float) $r->total_inactive_sec,
        ])->all();
    }

    /**
     * Daily activity totals (1-day windows) per identity and activity type.
     *
     * @return list<array{bucket:string, identity_name:string, activity:string, event_count:int, total_duration_sec:float}>
     */
    public function dailyActivity(?string $start, ?string $end, ?array $scope): array
    {
        $query = DB::connection(self::CONN)
            ->table('daily_activity_totals_by_employee')
            ->orderBy('bucket')
            ->orderBy('identity_name');

        $this->applyDateRange($query, $start, $end);
        $this->applyScope($query, $scope);

        return $query->get()->map(fn ($r) => [
            'bucket'             => (string) $r->bucket,
            'identity_name'      => $r->identity_name,
            'activity'           => $r->activity,
            'event_count'        => (int) $r->event_count,
            'total_duration_sec' => (float) $r->total_duration_sec,
        ])->all();
    }

    // -----------------------------------------------------------------------
    // Alert materialized views
    // -----------------------------------------------------------------------

    /**
     * Daily alert counts grouped by alert type (no identity breakdown).
     * Returned as-is for all authenticated users — no scope applied.
     *
     * @return list<array{bucket:string, alert_type:string, alert_count:int}>
     */
    public function alertsByType(?string $start, ?string $end): array
    {
        $query = DB::connection(self::CONN)
            ->table('daily_alerts_by_type')
            ->orderBy('bucket')
            ->orderBy('alert_type');

        $this->applyDateRange($query, $start, $end);

        return $query->get()->map(fn ($r) => [
            'bucket'      => (string) $r->bucket,
            'alert_type'  => $r->alert_type,
            'alert_count' => (int) $r->alert_count,
        ])->all();
    }

    /**
     * Daily alert counts grouped by identity and alert type.
     *
     * @return list<array{bucket:string, identity_name:string, alert_type:string, alert_count:int}>
     */
    public function alertsByEmployee(?string $start, ?string $end, ?array $scope): array
    {
        $query = DB::connection(self::CONN)
            ->table('daily_alerts_by_employee')
            ->orderBy('bucket')
            ->orderBy('identity_name');

        $this->applyDateRange($query, $start, $end);
        $this->applyScope($query, $scope);

        return $query->get()->map(fn ($r) => [
            'bucket'        => (string) $r->bucket,
            'identity_name' => $r->identity_name,
            'alert_type'    => $r->alert_type,
            'alert_count'   => (int) $r->alert_count,
        ])->all();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Restrict the query to rows whose bucket falls within [start, end].
     *
     * $start and $end must be date-only strings ('YYYY-MM-DD') or null.
     * Start is expanded to 00:00:00 and end to 23:59:59 so that whole days
     * are included for both TIMESTAMPTZ and TIMESTAMP bucket columns.
     */
    private function applyDateRange($query, ?string $start, ?string $end): void
    {
        if ($start !== null && $start !== '') {
            $query->where('bucket', '>=', $start . ' 00:00:00');
        }
        if ($end !== null && $end !== '') {
            $query->where('bucket', '<=', $end . ' 23:59:59');
        }
    }

    /**
     * Restrict the query to rows matching the identity scope.
     *
     * null  → no filter (admin: all rows visible).
     * []    → whereIn with empty list → zero rows returned.
     * array → only the listed identity_name values are included.
     */
    private function applyScope($query, ?array $scope): void
    {
        if ($scope !== null) {
            $query->whereIn('identity_name', $scope);
        }
    }
}
