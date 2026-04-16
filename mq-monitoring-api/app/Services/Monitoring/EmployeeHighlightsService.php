<?php

namespace App\Services\Monitoring;

use App\Models\AlertReplaySource;
use App\Models\BehaviorAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeHighlightsService
 *
 * Computes the top "moments forts" (highlight moments) for a given
 * employee and date range using a simple rule-based scoring method.
 *
 * Data sources (read-only):
 *  - behavior_alerts          (Laravel SQLite) — persisted alert records
 *  - surveillance_events      (surveillance SQLite) — raw activity segments
 *  - alert_replay_sources     (Laravel SQLite) — replay video metadata
 *
 * Algorithm overview:
 *  1. Fetch all behavior_alerts for the identity in the date range.
 *  2. Cluster consecutive alerts that fire within CLUSTER_GAP_MIN of
 *     each other — nearby events indicate a concentrated problem period.
 *  3. For each cluster, compute a window (pre-buffer before first alert,
 *     post-buffer after last alert) and pull surveillance activity totals.
 *  4. Score = Σ(per-alert type weight)
 *           + cluster bonus (extra alerts = amplified concern)
 *           + duration bonus from phone/inactive seconds in the window.
 *  5. Sort by score descending, return the top MAX_HIGHLIGHTS results.
 */
class EmployeeHighlightsService
{
    /** Maximum highlights returned per request. */
    private const MAX_HIGHLIGHTS = 5;

    /** Minutes gap that separates two alerts into different clusters. */
    private const CLUSTER_GAP_MIN = 10;

    /** Minutes before the first alert in a cluster to begin the window. */
    private const WINDOW_PRE_MIN = 5;

    /** Minutes after the last alert in a cluster to end the window. */
    private const WINDOW_POST_MIN = 10;

    /**
     * Base score per occurrence of each alert type.
     * Higher weight = more immediately alarming signal.
     */
    private const TYPE_SCORES = [
        'phone'        => 35,
        'late_arrival' => 30,
        'early_leave'  => 30,
        'inactive'     => 25,
    ];

    private const DEFAULT_TYPE_SCORE  = 20;   // for any unlisted type
    private const CLUSTER_BONUS       = 20;   // per extra alert in the cluster
    private const PHONE_SEC_WEIGHT    = 0.4;  // score per second of phone activity
    private const INACTIVE_SEC_WEIGHT = 0.3;  // score per second of inactivity

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Return the top highlight moments for the given employee.
     *
     * @param  string      $identityName  The surveillance identity name.
     * @param  string|null $start         ISO-compatible start datetime (inclusive).
     * @param  string|null $end           ISO-compatible end datetime (inclusive).
     *
     * @return array{
     *   identity: string,
     *   period: array{start: string, end: string},
     *   highlights: list<array{
     *     timestamp: string,
     *     window_start: string,
     *     window_end: string,
     *     score: int,
     *     dominant_issue: string,
     *     summary: string,
     *     alert_count: int,
     *     alert_ids: int[],
     *     phone_duration_min: float,
     *     inactive_duration_min: float,
     *     replay_available: bool,
     *     replay_alert_id: int|null,
     *   }>,
     * }
     */
    public function highlights(
        string  $identityName,
        ?string $start = null,
        ?string $end   = null,
    ): array {
        $startDt = $start
            ? Carbon::parse($start)->startOfDay()
            : now()->subDays(7)->startOfDay();

        $endDt = $end
            ? Carbon::parse($end)->endOfDay()
            : now()->endOfDay();

        // 1. Pull alerts for this identity in the window.
        $alerts = BehaviorAlert::where('identity_name', $identityName)
            ->whereBetween('fired_at', [$startDt->toDateTimeString(), $endDt->toDateTimeString()])
            ->orderBy('fired_at')
            ->get();

        if ($alerts->isEmpty()) {
            return $this->emptyResult($identityName, $startDt, $endDt);
        }

        // 2. Cluster nearby alerts.
        $clusters = $this->clusterAlerts($alerts);

        // 3. Score each cluster.
        $highlights = [];
        foreach ($clusters as $cluster) {
            $highlights[] = $this->scoreCluster($cluster, $identityName);
        }

        // 4. Sort by score descending, cap at MAX_HIGHLIGHTS.
        usort($highlights, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'identity'   => $identityName,
            'period'     => [
                'start' => $startDt->toIso8601String(),
                'end'   => $endDt->toIso8601String(),
            ],
            'highlights' => array_slice($highlights, 0, self::MAX_HIGHLIGHTS),
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Group a time-ordered collection of alerts into temporal clusters.
     * A new cluster begins when consecutive alerts are more than
     * CLUSTER_GAP_MIN apart.
     *
     * @return array<int, list<BehaviorAlert>>
     */
    private function clusterAlerts(Collection $alerts): array
    {
        $clusters = [];
        $current  = [];

        foreach ($alerts as $alert) {
            if (empty($current)) {
                $current[] = $alert;
                continue;
            }

            /** @var BehaviorAlert $last */
            $last   = end($current);
            $gapMin = Carbon::parse($last->fired_at)
                ->diffInMinutes(Carbon::parse($alert->fired_at), absolute: true);

            if ($gapMin <= self::CLUSTER_GAP_MIN) {
                $current[] = $alert;
            } else {
                $clusters[] = $current;
                $current    = [$alert];
            }
        }

        if (! empty($current)) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    /**
     * Compute the score and build the full highlight record for one cluster.
     *
     * @param  list<BehaviorAlert> $cluster
     */
    private function scoreCluster(array $cluster, string $identityName): array
    {
        /** @var BehaviorAlert $first */
        $first = $cluster[0];
        /** @var BehaviorAlert $last */
        $last  = end($cluster);

        $windowStart = Carbon::parse($first->fired_at)->subMinutes(self::WINDOW_PRE_MIN);
        $windowEnd   = Carbon::parse($last->fired_at)->addMinutes(self::WINDOW_POST_MIN);
        $alertCount  = count($cluster);
        $alertIds    = array_map(fn ($a) => $a->id, $cluster);

        // ── Base score from alert types ──────────────────────────────────────
        $typeTotals = [];
        $typeScore  = 0;
        foreach ($cluster as $alert) {
            $type                  = $alert->alert_type;
            $typeTotals[$type]     = ($typeTotals[$type] ?? 0) + 1;
            $typeScore            += self::TYPE_SCORES[$type] ?? self::DEFAULT_TYPE_SCORE;
        }

        // Each extra alert in the same window amplifies the concern.
        $clusterBonus = ($alertCount - 1) * self::CLUSTER_BONUS;

        // ── Supplement with surveillance activity in the window ──────────────
        $phoneSec    = 0.0;
        $inactiveSec = 0.0;

        try {
            $rows = $this->querySurveillanceWindow($identityName, $windowStart, $windowEnd);
            foreach ($rows as $row) {
                $act = strtolower((string) $row->activity);
                if ($act === 'using_phone') {
                    $phoneSec += (float) $row->total_sec;
                } elseif ($act === 'inactive') {
                    $inactiveSec += (float) $row->total_sec;
                }
            }
        } catch (\Throwable) {
            // Surveillance DB unavailable — score from alerts only, no crash.
        }

        $durationBonus = (int) round(
            $phoneSec    * self::PHONE_SEC_WEIGHT
            + $inactiveSec * self::INACTIVE_SEC_WEIGHT
        );

        $score = $typeScore + $clusterBonus + $durationBonus;

        // ── Dominant issue ───────────────────────────────────────────────────
        $dominantIssue = $this->dominantType($typeTotals);

        // ── Summary label ────────────────────────────────────────────────────
        $summary = $this->buildSummary(
            $dominantIssue,
            $typeTotals,
            $alertCount,
            $phoneSec,
            $inactiveSec,
        );

        // ── Replay availability ──────────────────────────────────────────────
        $replayAlertId   = null;
        $replayAvailable = false;

        foreach ($alertIds as $aid) {
            if (AlertReplaySource::where('behavior_alert_id', $aid)->exists()) {
                $replayAvailable = true;
                $replayAlertId   = $aid;
                break;
            }
        }

        return [
            'timestamp'             => Carbon::parse($first->fired_at)->toIso8601String(),
            'window_start'          => $windowStart->toIso8601String(),
            'window_end'            => $windowEnd->toIso8601String(),
            'score'                 => $score,
            'dominant_issue'        => $dominantIssue,
            'summary'               => $summary,
            'alert_count'           => $alertCount,
            'alert_ids'             => $alertIds,
            'phone_duration_min'    => round($phoneSec / 60, 1),
            'inactive_duration_min' => round($inactiveSec / 60, 1),
            'replay_available'      => $replayAvailable,
            'replay_alert_id'       => $replayAlertId,
        ];
    }

    /**
     * Query surveillance_events for a specific identity and time window,
     * returning total seconds per activity.
     */
    private function querySurveillanceWindow(
        string $identityName,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): Collection {
        return DB::connection('surveillance')
            ->table('surveillance_events')
            ->where('identity_name', $identityName)
            ->where('timestamp_start', '>=', $windowStart->format('Y-m-d H:i:s'))
            ->where('timestamp_start', '<=', $windowEnd->format('Y-m-d H:i:s'))
            ->select([
                'activity',
                DB::raw('SUM(duration_sec) AS total_sec'),
            ])
            ->groupBy('activity')
            ->get();
    }

    /**
     * Select the most impactful alert type from a frequency map by
     * weighted score (type weight × count).
     */
    private function dominantType(array $typeTotals): string
    {
        $best      = null;
        $bestScore = -1;

        foreach ($typeTotals as $type => $count) {
            $weighted = ($count) * (self::TYPE_SCORES[$type] ?? self::DEFAULT_TYPE_SCORE);
            if ($weighted > $bestScore) {
                $bestScore = $weighted;
                $best      = $type;
            }
        }

        return $best ?? 'unknown';
    }

    /**
     * Build a concise, human-readable one-line summary.
     */
    private function buildSummary(
        string $dominantIssue,
        array  $typeTotals,
        int    $alertCount,
        float  $phoneSec,
        float  $inactiveSec,
    ): string {
        $parts = [];

        switch ($dominantIssue) {
            case 'phone':
                $n       = $typeTotals['phone'];
                $minStr  = $phoneSec > 0 ? ' — ' . round($phoneSec / 60, 1) . ' min' : '';
                $parts[] = "Phone use detected {$n}×{$minStr}";
                break;

            case 'inactive':
                $n       = $typeTotals['inactive'];
                $minStr  = $inactiveSec > 0 ? ' — ' . round($inactiveSec / 60, 1) . ' min' : '';
                $parts[] = "Inactivity flagged {$n}×{$minStr}";
                break;

            case 'late_arrival':
                $parts[] = 'Late arrival flagged';
                break;

            case 'early_leave':
                $parts[] = 'Early departure flagged';
                break;

            default:
                $parts[] = "{$alertCount} alert" . ($alertCount > 1 ? 's' : '') . ' flagged';
        }

        // Append secondary issue types if the cluster had mixed signals.
        $others = array_keys(array_filter(
            $typeTotals,
            fn ($type) => $type !== $dominantIssue,
            ARRAY_FILTER_USE_KEY,
        ));

        if (! empty($others)) {
            $labels  = array_map(fn ($t) => str_replace('_', ' ', $t), $others);
            $parts[] = '+ ' . implode(', ', $labels);
        }

        return implode(' ', $parts);
    }

    private function emptyResult(string $identityName, Carbon $start, Carbon $end): array
    {
        return [
            'identity'   => $identityName,
            'period'     => [
                'start' => $start->toIso8601String(),
                'end'   => $end->toIso8601String(),
            ],
            'highlights' => [],
        ];
    }
}
