<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * SurveillanceAnalyticsService
 *
 * Read-only analytics over the surveillance.surveillance_events PostgreSQL table
 * written by the Python surveillance runtime.
 *
 * All public methods accept string datetimes (any format parseable by
 * PHP's strtotime) and normalise them to 'Y-m-d H:i:s' before querying,
 * which PostgreSQL implicitly casts when comparing to TIMESTAMPTZ columns.
 *
 * Design principles:
 *  - Every SQL query uses bound parameters — no interpolated user input.
 *  - The service never writes to or alters the surveillance database.
 *  - Numeric values are explicitly cast so callers receive proper PHP types.
 */
class SurveillanceAnalyticsService
{
    private const CONNECTION = 'surveillance';
    private const TABLE      = 'surveillance_events';

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
        $query = $this->db()
            ->table(self::TABLE)
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
        $query = $this->db()
            ->table(self::TABLE)
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
        $query = $this->db()
            ->table(self::TABLE)
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

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function db(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION);
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
