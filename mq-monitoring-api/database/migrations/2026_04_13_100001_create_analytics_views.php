<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the analytics schema and all five analytics views used by
 * AnalyticsViewsService and DashboardSummaryController.
 *
 * THREE surveillance-based views (from surveillance.surveillance_events):
 *   surveillance_hourly_activity        — hourly activity buckets per identity
 *   daily_inactivity_per_employee       — daily inactivity totals per identity
 *   daily_activity_totals_by_employee   — daily activity totals per identity + type
 *
 * TWO alert-based views (from public.behavior_alerts):
 *   daily_alerts_by_type       — daily alert count per alert_type
 *   daily_alerts_by_employee   — daily alert count per identity_name + alert_type
 *
 * All views use CREATE OR REPLACE VIEW for idempotency.
 * The analytics schema uses search_path = analytics,public so bare table
 * references in queries resolve to these views automatically.
 */
return new class extends Migration
{
    /** Override the connection so migrations run against the right DB. */
    public $connection = 'pgsql';

    public function up(): void
    {
        // ── Schema ──────────────────────────────────────────────────────────
        DB::statement('CREATE SCHEMA IF NOT EXISTS analytics');

        // ── Surveillance views ───────────────────────────────────────────────
        // Hourly activity buckets per identity and activity type.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.surveillance_hourly_activity AS
            SELECT
                DATE_TRUNC('hour', timestamp_start)  AS bucket,
                identity_name,
                activity,
                COUNT(*)                             AS event_count,
                SUM(duration_sec)                    AS total_duration_sec
            FROM surveillance.surveillance_events
            GROUP BY 1, 2, 3
        SQL);

        // Daily inactivity totals per identity.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.daily_inactivity_per_employee AS
            SELECT
                DATE_TRUNC('day', timestamp_start)   AS bucket,
                identity_name,
                COUNT(*)                             AS inactive_event_count,
                SUM(duration_sec)                    AS total_inactive_sec
            FROM surveillance.surveillance_events
            WHERE activity = 'Inactive'
            GROUP BY 1, 2
        SQL);

        // Daily activity totals per identity and activity type.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.daily_activity_totals_by_employee AS
            SELECT
                DATE_TRUNC('day', timestamp_start)   AS bucket,
                identity_name,
                activity,
                COUNT(*)                             AS event_count,
                SUM(duration_sec)                    AS total_duration_sec
            FROM surveillance.surveillance_events
            GROUP BY 1, 2, 3
        SQL);

        // ── Alert views ──────────────────────────────────────────────────────
        // Daily alert count per alert_type only (no identity breakdown).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.daily_alerts_by_type AS
            SELECT
                DATE_TRUNC('day', fired_at)  AS bucket,
                alert_type,
                COUNT(*)                     AS alert_count
            FROM public.behavior_alerts
            GROUP BY 1, 2
        SQL);

        // Daily alert count per identity_name and alert_type.
        // This is the primary analytics view queried by DashboardSummaryController.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW analytics.daily_alerts_by_employee AS
            SELECT
                DATE_TRUNC('day', fired_at)  AS bucket,
                identity_name,
                alert_type,
                COUNT(*)                     AS alert_count
            FROM public.behavior_alerts
            GROUP BY 1, 2, 3
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS analytics.daily_alerts_by_employee');
        DB::statement('DROP VIEW IF EXISTS analytics.daily_alerts_by_type');
        DB::statement('DROP VIEW IF EXISTS analytics.daily_activity_totals_by_employee');
        DB::statement('DROP VIEW IF EXISTS analytics.daily_inactivity_per_employee');
        DB::statement('DROP VIEW IF EXISTS analytics.surveillance_hourly_activity');
        DB::statement('DROP SCHEMA IF EXISTS analytics');
    }
};
