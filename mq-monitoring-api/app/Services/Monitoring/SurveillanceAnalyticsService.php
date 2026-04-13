<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * SurveillanceAnalyticsService
 *
 * Read-only analytics over the surveillance_events PostgreSQL table
 * written by the Python surveillance runtime.
 *
 * Connection and table names are configurable via config/surveillance.php
 * and environment variables for flexibility across environments.
 *
 * All public methods accept string datetimes (any format parseable by
 * PHP's strtotime) and normalise them to 'Y-m-d H:i:s' before querying,
 * which PostgreSQL implicitly casts when comparing to TIMESTAMPTZ columns.
 *
 * Design principles:
 *  - Every SQL query uses bound parameters — no interpolated user input.
 *  - The service never writes to or alters the surveillance database.
 *  - Numeric values are explicitly cast so callers receive proper PHP types.
 *  - Connection and table existence are verified on first access.
 */
class SurveillanceAnalyticsService
{
    private ?string $connectionName = null;
    private ?string $qualifiedTable = null;
    private bool $initialized = false;
    private bool $verificationFailed = false;

    /**
     * Ensure the surveillance connection and table are configured and accessible.
     * This is called implicitly on first query; exceptions are logged and thrown.
     *
     * @throws RuntimeException if connection is not configured or table doesn't exist
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        if ($this->verificationFailed) {
            throw new RuntimeException(
                'Surveillance analytics initialization failed. Check logs for details.'
            );
        }

        try {
            $this->initializeConnection();
            $this->initializeTable();
            $this->initialized = true;
            Log::info('Surveillance analytics service initialized', [
                'connection' => $this->connectionName,
                'table'      => $this->qualifiedTable,
            ]);
        } catch (RuntimeException $e) {
            $this->verificationFailed = true;
            Log::error('Surveillance analytics initialization failed: ' . $e->getMessage(), [
                'connection' => $this->connectionName ?? 'unknown',
            ]);
            throw $e;
        }
    }

    /**
     * Resolve and verify the database connection exists.
     *
     * @throws RuntimeException if no connection available
     */
    private function initializeConnection(): void
    {
        $configuredName = Config::get('surveillance.connection', 'surveillance');
        $defaultName    = config('database.default', 'pgsql');

        // Try the configured connection first.
        if ($this->connectionExists($configuredName)) {
            $this->connectionName = $configuredName;
            return;
        }

        // Fall back to default connection.
        if ($configuredName !== $defaultName && $this->connectionExists($defaultName)) {
            Log::warning("Configured surveillance connection [$configuredName] not found, falling back to [$defaultName]");
            $this->connectionName = $defaultName;
            return;
        }

        throw new RuntimeException(
            "Surveillance DB connection [$configuredName] is not configured in config/database.php. "
            . "Verify that the connection exists or set SURVEILLANCE_DB_CONNECTION to a valid connection name."
        );
    }

    /**
     * Verify that a named database connection is registered.
     */
    private function connectionExists(string $name): bool
    {
        try {
            DB::connection($name);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build and verify the qualified table name exists in the database.
     *
     * @throws RuntimeException if table doesn't exist
     */
    private function initializeTable(): void
    {
        $schema = Config::get('surveillance.schema', 'surveillance');
        $table  = Config::get('surveillance.table', 'surveillance_events');

        // Build fully-qualified table name.
        if ($schema && $schema !== 'public') {
            $this->qualifiedTable = "{$schema}.{$table}";
        } else {
            $this->qualifiedTable = $table;
        }

        // Verify table exists.
        if (! $this->tableExists($this->qualifiedTable)) {
            throw new RuntimeException(
                "Surveillance table [{$this->qualifiedTable}] does not exist. "
                . "Verify the Python surveillance runtime has written to the database, "
                . "or check SURVEILLANCE_DB_SCHEMA and SURVEILLANCE_DB_TABLE environment variables."
            );
        }
    }

    /**
     * Check if a table exists in the database.
     */
    private function tableExists(string $qualifiedTable): bool
    {
        try {
            $parts = explode('.', $qualifiedTable);
            if (count($parts) === 2) {
                [$schema, $table] = $parts;
            } else {
                $schema = null;
                $table  = $parts[0];
            }

            // Query information_schema to check table existence.
            $query = DB::connection($this->connectionName)
                ->table('information_schema.tables')
                ->where('table_name', $table);

            if ($schema !== null) {
                $query->where('table_schema', $schema);
            }

            return $query->exists();
        } catch (\Exception $e) {
            Log::warning("Failed to verify surveillance table exists: " . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Global activity overview for a time window.
     *
     * Aggregates total duration and event count per activity label, then
     * derives each label's fractional share (distribution).
     *
     * @param string   $start           ISO-compatible start datetime (inclusive).
     * @param string   $end             ISO-compatible end datetime (inclusive).
     * @param bool     $includeUnknown  When false, rows with identity_name
     *                                  = 'Unknown' are excluded.
     * @param string[] $includeTriggers When non-empty, only rows whose
     *                                  event_trigger is in this list are counted.
     *
     * @return array{
     *   totals:       array<string, float>,
     *   total_sec:    float,
     *   event_count:  int,
     *   distribution: array<string, float>,
     * }
     */
    public function overview(
        ?string $start             = null,
        ?string $end               = null,
        bool    $includeUnknown    = true,
        array   $includeTriggers   = [],
        ?array  $allowedIdentities = null,
    ): array {
        $this->ensureInitialized();

        $query = $this->db()
            ->table($this->qualifiedTable)
            ->select([
                'activity',
                DB::raw('SUM(duration_sec) AS total_sec'),
                DB::raw('COUNT(*) AS event_count'),
            ]);

        if ($start !== null) {
            $query->where('timestamp_start', '>=', $this->normalizeDt($start));
        }
        if ($end !== null) {
            $query->where('timestamp_start', '<=', $this->normalizeDt($end));
        }

        if (! $includeUnknown) {
            $query->where('identity_name', '!=', 'Unknown');
        }
        if (! empty($includeTriggers)) {
            $query->whereIn('event_trigger', $includeTriggers);
        }
        if ($allowedIdentities !== null) {
            $query->whereIn('identity_name', $allowedIdentities);
        }

        $rows = $query->groupBy('activity')->get();

        $totals     = [];
        $totalSec   = 0.0;
        $eventCount = 0;

        foreach ($rows as $row) {
            $sec = (float) $row->total_sec;
            $totals[$row->activity] = $sec;
            $totalSec   += $sec;
            $eventCount += (int) $row->event_count;
        }

        arsort($totals);

        $distribution = [];
        foreach ($totals as $activity => $sec) {
            $distribution[$activity] = $totalSec > 0
                ? round($sec / $totalSec, 4)
                : 0.0;
        }

        return [
            'totals'       => $totals,
            'total_sec'    => round($totalSec, 2),
            'event_count'  => $eventCount,
            'distribution' => $distribution,
        ];
    }

    /**
     * Per-identity breakdown for a time window.
     *
     * Groups rows by identity_name + activity, then re-groups into a
     * per-identity structure with per-activity totals.
     *
     * @param string      $start
     * @param string      $end
     * @param bool        $includeUnknown
     * @param string[]    $includeTriggers
     * @param string|null $identity        When set, restrict to this name only.
     *
     * @return array{identities: list<array{
     *   identity_name: string,
     *   total_sec:     float,
     *   event_count:   int,
     *   activities:    array<string, float>,
     * }>}
     */
    public function identities(
        ?string $start             = null,
        ?string $end               = null,
        bool    $includeUnknown    = false,
        array   $includeTriggers   = [],
        ?string $identity          = null,
        ?array  $allowedIdentities = null,
    ): array {
        $this->ensureInitialized();

        $query = $this->db()
            ->table($this->qualifiedTable)
            ->select([
                'identity_name',
                'activity',
                DB::raw('SUM(duration_sec) AS total_sec'),
                DB::raw('COUNT(*) AS event_count'),
            ]);

        if ($start !== null) {
            $query->where('timestamp_start', '>=', $this->normalizeDt($start));
        }
        if ($end !== null) {
            $query->where('timestamp_start', '<=', $this->normalizeDt($end));
        }

        if (! $includeUnknown) {
            $query->where('identity_name', '!=', 'Unknown');
        }
        if ($identity !== null) {
            $query->where('identity_name', $identity);
        }
        if (! empty($includeTriggers)) {
            $query->whereIn('event_trigger', $includeTriggers);
        }
        if ($allowedIdentities !== null) {
            $query->whereIn('identity_name', $allowedIdentities);
        }

        $rows = $query->groupBy('identity_name', 'activity')->get();

        // Reduce flat (identity, activity, sec) rows → nested identity map.
        $grouped = [];
        foreach ($rows as $row) {
            $name = $row->identity_name;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'identity_name' => $name,
                    'total_sec'     => 0.0,
                    'event_count'   => 0,
                    'activities'    => [],
                ];
            }
            $sec = (float) $row->total_sec;
            $grouped[$name]['activities'][$row->activity] = $sec;
            $grouped[$name]['total_sec']   += $sec;
            $grouped[$name]['event_count'] += (int) $row->event_count;
        }

        // Sort each identity's activities by descending time; round totals.
        foreach ($grouped as &$entry) {
            arsort($entry['activities']);
            $entry['total_sec'] = round($entry['total_sec'], 2);
        }
        unset($entry);

        // Sort identities by descending total_sec.
        usort($grouped, fn ($a, $b) => $b['total_sec'] <=> $a['total_sec']);

        return ['identities' => array_values($grouped)];
    }

    /**
     * Ordered activity segments for one identity within an optional window.
     *
     * All 9 stable schema fields are returned per segment so the caller
     * (Angular timeline component, CSV export, etc.) has the full picture.
     *
     * @param string      $identityName
     * @param string|null $start           Optional inclusive lower bound.
     * @param string|null $end             Optional inclusive upper bound.
     * @param string[]    $includeTriggers
     *
     * @return array{
     *   identity: string,
     *   count:    int,
     *   segments: list<array{
     *     timestamp_start:     string,
     *     timestamp_end:       string,
     *     duration_sec:        float,
     *     track_id:            int,
     *     activity:            string,
     *     identity_confidence: float,
     *     identity_source:     string,
     *     event_trigger:       string,
     *   }>,
     * }
     */
    public function timeline(
        string  $identityName,
        ?string $start          = null,
        ?string $end            = null,
        array   $includeTriggers = [],
    ): array {
        $this->ensureInitialized();

        $query = $this->db()
            ->table($this->qualifiedTable)
            ->where('identity_name', $identityName)
            ->orderBy('timestamp_start');

        if ($start !== null) {
            $query->where('timestamp_start', '>=', $this->normalizeDt($start));
        }
        if ($end !== null) {
            $query->where('timestamp_start', '<=', $this->normalizeDt($end));
        }
        if (! empty($includeTriggers)) {
            $query->whereIn('event_trigger', $includeTriggers);
        }

        $rows = $query->get();

        $segments = $rows->map(fn ($r) => [
            'timestamp_start'     => $r->timestamp_start,
            'timestamp_end'       => $r->timestamp_end,
            'duration_sec'        => (float) $r->duration_sec,
            'track_id'            => (int)   $r->track_id,
            'activity'            => $r->activity,
            'identity_confidence' => (float) $r->identity_confidence,
            'identity_source'     => $r->identity_source,
            'event_trigger'       => $r->event_trigger,
        ])->values()->all();

        return [
            'identity' => $identityName,
            'count'    => count($segments),
            'segments' => $segments,
        ];
    }

    /**
     * Aggregated personal summary for one identity.
     *
     * Returns per-activity second totals for the three core activity labels
     * (Working, Inactive, Using_Phone) plus a derived focus_score integer.
     *
     * focus_score = working_sec / (working_sec + inactive_sec + phone_sec)
     *   - Range 0..100 integer, or null when denominator is 0.
     *   - No Meeting activity is present in this system.
     *
     * @return array{
     *   working_sec:  float,
     *   phone_sec:    float,
     *   inactive_sec: float,
     *   other_sec:    float,
     *   total_sec:    float,
     *   focus_score:  int|null,
     * }
     */
    public function identitySummary(
        string  $identityName,
        ?string $start = null,
        ?string $end   = null,
    ): array {
        $this->ensureInitialized();

        $query = $this->db()
            ->table($this->qualifiedTable)
            ->where('identity_name', $identityName)
            ->select([
                'activity',
                DB::raw('SUM(duration_sec) AS total_sec'),
            ]);

        if ($start !== null) {
            $query->where('timestamp_start', '>=', $this->normalizeDt($start));
        }
        if ($end !== null) {
            $query->where('timestamp_start', '<=', $this->normalizeDt($end));
        }

        $rows = $query->groupBy('activity')->get();

        $bySec = [];
        foreach ($rows as $row) {
            $bySec[$row->activity] = (float) $row->total_sec;
        }

        $workingSec  = $bySec['Working']      ?? 0.0;
        $phoneSec    = $bySec['Using_Phone']  ?? 0.0;
        $inactiveSec = $bySec['Inactive']     ?? 0.0;
        $total       = array_sum($bySec);
        $otherSec    = $total - $workingSec - $phoneSec - $inactiveSec;

        $denom       = $workingSec + $phoneSec + $inactiveSec;
        $focusScore  = $denom > 0
            ? (int) round(($workingSec / $denom) * 100)
            : null;

        return [
            'working_sec'  => round($workingSec,  2),
            'phone_sec'    => round($phoneSec,    2),
            'inactive_sec' => round($inactiveSec, 2),
            'other_sec'    => round(max($otherSec, 0.0), 2),
            'total_sec'    => round($total,        2),
            'focus_score'  => $focusScore,
        ];
    }

    /**
     * Per-day aggregated breakdown for one identity.
     *
     * Groups segments by calendar date (YYYY-MM-DD derived from timestamp_start)
     * and computes the same activity buckets + focus_score per day.
     *
     * @return array{
     *   days: list<array{
     *     date:         string,
     *     working_sec:  float,
     *     phone_sec:    float,
     *     inactive_sec: float,
     *     other_sec:    float,
     *     total_sec:    float,
     *     focus_score:  int|null,
     *   }>
     * }
     */
    public function identityDaily(
        string  $identityName,
        ?string $start = null,
        ?string $end   = null,
    ): array {
        $this->ensureInitialized();

        $query = $this->db()
            ->table($this->qualifiedTable)
            ->where('identity_name', $identityName)
            ->select([
                DB::raw("DATE(timestamp_start) AS day"),
                'activity',
                DB::raw('SUM(duration_sec) AS total_sec'),
            ]);

        if ($start !== null) {
            $query->where('timestamp_start', '>=', $this->normalizeDt($start));
        }
        if ($end !== null) {
            $query->where('timestamp_start', '<=', $this->normalizeDt($end));
        }

        $rows = $query->groupBy('day', 'activity')
                      ->orderBy('day')
                      ->get();

        // Aggregate flat rows → keyed by date.
        $byDay = [];
        foreach ($rows as $row) {
            $date = $row->day;
            if (! isset($byDay[$date])) {
                $byDay[$date] = [
                    'Working'     => 0.0,
                    'Using_Phone' => 0.0,
                    'Inactive'    => 0.0,
                    '_total'      => 0.0,
                ];
            }
            $sec = (float) $row->total_sec;
            $byDay[$date][$row->activity] = ($byDay[$date][$row->activity] ?? 0.0) + $sec;
            $byDay[$date]['_total'] += $sec;
        }

        $days = [];
        foreach ($byDay as $date => $secs) {
            $workingSec  = $secs['Working']      ?? 0.0;
            $phoneSec    = $secs['Using_Phone']  ?? 0.0;
            $inactiveSec = $secs['Inactive']     ?? 0.0;
            $total       = $secs['_total'];
            $otherSec    = max($total - $workingSec - $phoneSec - $inactiveSec, 0.0);
            $denom       = $workingSec + $phoneSec + $inactiveSec;
            $focusScore  = $denom > 0
                ? (int) round(($workingSec / $denom) * 100)
                : null;

            $days[] = [
                'date'         => $date,
                'working_sec'  => round($workingSec,  2),
                'phone_sec'    => round($phoneSec,    2),
                'inactive_sec' => round($inactiveSec, 2),
                'other_sec'    => round($otherSec,    2),
                'total_sec'    => round($total,        2),
                'focus_score'  => $focusScore,
            ];
        }

        return ['days' => $days];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }

    /**
     * Normalise any ISO-compatible datetime string to 'Y-m-d H:i:s'.
     * PostgreSQL implicitly casts this string when comparing to TIMESTAMPTZ.
     */
    private function normalizeDt(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException(
                "Cannot parse datetime value: \"{$value}\""
            );
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
