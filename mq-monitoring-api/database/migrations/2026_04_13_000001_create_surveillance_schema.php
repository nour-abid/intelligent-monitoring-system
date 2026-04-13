<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the surveillance schema and surveillance_events table to support
     * analytics queries. Uses IF NOT EXISTS for idempotency; compatible with
     * Python's SurveillanceRepository which also uses IF NOT EXISTS.
     *
     * Table columns match the Python persist contract exactly:
     * - timestamp_start/end: TIMESTAMPTZ (wall-clock times with timezone)
     * - duration_sec: DOUBLE PRECISION (elapsed seconds)
     * - track_id: INTEGER (DeepSORT session-scoped ID)
     * - identity_name: TEXT (employee name or "Unknown")
     * - identity_confidence: DOUBLE PRECISION (ArcFace cosine-sim [0,1])
     * - identity_source: TEXT (recognition method)
     * - activity: TEXT (Working, Meeting, Inactive, Using_Phone, Unknown)
     * - event_trigger: TEXT (activity_change, track_lost, session_end)
     *
     * Indices on timestamp_start, identity_name, event_trigger for analytics speed.
     */
    public function up(): void
    {
        // Create the surveillance schema if it doesn't exist.
        DB::statement('CREATE SCHEMA IF NOT EXISTS surveillance');

        // Create the surveillance_events table if it doesn't exist.
        // Uses raw SQL for PostgreSQL specifics (BIGSERIAL, TIMESTAMPTZ, DOUBLE PRECISION).
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS surveillance.surveillance_events (
                id                  BIGSERIAL        PRIMARY KEY,
                timestamp_start     TIMESTAMPTZ      NOT NULL,
                timestamp_end       TIMESTAMPTZ      NOT NULL,
                duration_sec        DOUBLE PRECISION NOT NULL,
                track_id            INTEGER          NOT NULL,
                identity_name       TEXT             NOT NULL,
                identity_confidence DOUBLE PRECISION NOT NULL,
                identity_source     TEXT             NOT NULL,
                activity            TEXT             NOT NULL,
                event_trigger       TEXT             NOT NULL
            )
        SQL);

        // Create indices for common query patterns used by analytics.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_se_ts_start ON surveillance.surveillance_events (timestamp_start)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_se_identity ON surveillance.surveillance_events (identity_name)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_se_trigger ON surveillance.surveillance_events (event_trigger)');
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table first (to ensure indices are handled), then the schema.
     * Safe: only drops if migration ran; idempotent with IF EXISTS.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS surveillance.surveillance_events');
        DB::statement('DROP SCHEMA IF EXISTS surveillance');
    }
};
