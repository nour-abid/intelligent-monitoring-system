<?php

namespace App\Services\Monitoring;

/**
 * InsightExtractorService
 *
 * Deterministic, zero-LLM layer that computes ranked insight facts from
 * structured analytics data BEFORE the prompt is sent to Gemini.
 *
 * Goals
 *   • Force the LLM to anchor on concrete, pre-computed findings instead of
 *     free-form interpretation of a raw numbers dump.
 *   • Attach severity labels ([CRITICAL] / [WARNING] / [OK] / [POSITIVE]) so
 *     the model knows what to prioritise without guessing.
 *   • Remain zero-database: every fact is derived from data already loaded
 *     by the controller or parsed from the frontend-generated blob.
 *
 * Two entry points:
 *   fromActivitySecs()  — used by buildIdentityContext() which already has
 *                          structured seconds from SurveillanceAnalyticsService.
 *   fromReportBlob()    — used by report() which receives a pre-formatted
 *                          text string from the Angular frontend.
 *
 * Computed insight types (ordered by priority in output):
 *   1. Focus Score          — derived from working/phone/inactive split
 *   2. Phone usage rate     — threshold-classified
 *   3. Inactivity rate      — threshold-classified
 *   4. Working time share   — threshold-classified
 *   5. Dominant activity    — top activity by duration
 *   6. Best / Worst day     — only when dailyMetrics provided (activity-secs path)
 *   7. Period trend         — focus-score trend across halves (≥4 days required)
 *   8. System insights      — frontend-computed behavioural alerts (blob path)
 *   9. Alert summary        — notable incident count, when present
 */
class InsightExtractorService
{
    // ── Thresholds (mirror the Angular identity-detail component logic) ─────

    private const FOCUS_CRIT  = 40;   // % — below this is critical
    private const FOCUS_WARN  = 70;   // % — below this is "moderate"
    private const PHONE_WARN  = 25;   // % of core time
    private const PHONE_CRIT  = 40;   // % of core time
    private const INACT_WARN  = 40;   // % of core time
    private const INACT_CRIT  = 60;   // % of core time
    private const WORK_CRIT   = 10;   // % of core time — "very low"

    // ── Entry point 1: structured seconds ────────────────────────────────────

    /**
     * Compute ranked insight facts from a raw activity map (seconds).
     * This is the preferred path: no string parsing, no guessing.
     *
     * @param string $name         Identity name (used for context header only)
     * @param float  $totalSec     Total tracked seconds for the period
     * @param array  $activities   ['Working' => sec, 'Using_Phone' => sec, 'Inactive' => sec, ...]
     * @param array  $dailyMetrics Optional per-day breakdown:
     *               [['date'=>'YYYY-MM-DD','working_sec'=>X,'phone_sec'=>Y,
     *                 'inactive_sec'=>Z,'total_sec'=>T,'focus_score'=>N], ...]
     * @return string[]            Ordered insight fact strings, highest priority first
     */
    public function fromActivitySecs(
        string $name,
        float  $totalSec,
        array  $activities,
        array  $dailyMetrics = [],
    ): array {
        if ($totalSec <= 0) {
            return ['[INFO] No recorded activity data for this period.'];
        }

        $working  = (float) ($activities['Working']     ?? 0);
        $phone    = (float) ($activities['Using_Phone'] ?? 0);
        $inactive = (float) ($activities['Inactive']    ?? 0);

        // Base = the three core activities; excludes Unknown
        // so percentages match the Angular threshold computations.
        $base = $working + $phone + $inactive;
        if ($base <= 0) {
            return ['[INFO] Insufficient distributable activity data for this period.'];
        }

        $workPct  = (int) round(($working  / $base) * 100);
        $phonePct = (int) round(($phone    / $base) * 100);
        $inactPct = (int) round(($inactive / $base) * 100);

        // Focus score: identical to Angular identity-detail + backend formula
        $focusScore = max(0, min(100, (int) round($workPct - $phonePct * 0.5)));

        $facts = [];

        // ── Priority 1: Focus score ─────────────────────────────────────────
        if ($focusScore < self::FOCUS_CRIT) {
            $facts[] = "[CRITICAL] Focus Score: {$focusScore}% (threshold ≥" . self::FOCUS_CRIT . "%) — productive time is critically low relative to phone use and inactivity.";
        } elseif ($focusScore < self::FOCUS_WARN) {
            $facts[] = "[WARNING] Focus Score: {$focusScore}% — moderate productive output, room for improvement.";
        } else {
            $facts[] = "[POSITIVE] Focus Score: {$focusScore}% — healthy productive pattern.";
        }

        // ── Priority 2: Phone usage ─────────────────────────────────────────
        $phoneDur = $this->fmtSec($phone);
        if ($phonePct > self::PHONE_CRIT) {
            $facts[] = "[CRITICAL] Phone usage: {$phonePct}% ({$phoneDur}) — exceeds the " . self::PHONE_CRIT . "% critical threshold.";
        } elseif ($phonePct > self::PHONE_WARN) {
            $facts[] = "[WARNING] Phone usage: {$phonePct}% ({$phoneDur}) — above the " . self::PHONE_WARN . "% acceptable limit.";
        } else {
            $facts[] = "[OK] Phone usage: {$phonePct}% ({$phoneDur}) — within limits.";
        }

        // ── Priority 3: Inactivity ──────────────────────────────────────────
        $inactDur = $this->fmtSec($inactive);
        if ($inactPct > self::INACT_CRIT) {
            $facts[] = "[CRITICAL] Inactivity: {$inactPct}% ({$inactDur}) — exceeds the " . self::INACT_CRIT . "% critical threshold.";
        } elseif ($inactPct > self::INACT_WARN) {
            $facts[] = "[WARNING] Inactivity: {$inactPct}% ({$inactDur}) — above the " . self::INACT_WARN . "% threshold.";
        } else {
            $facts[] = "[OK] Inactivity: {$inactPct}% ({$inactDur}) — within limits.";
        }

        // ── Priority 4: Working time ────────────────────────────────────────
        $workDur = $this->fmtSec($working);
        if ($workPct < self::WORK_CRIT) {
            $facts[] = "[CRITICAL] Working time: only {$workPct}% ({$workDur}) — less than " . self::WORK_CRIT . "% of tracked time.";
        } else {
            $facts[] = "[METRIC] Working time: {$workPct}% ({$workDur}).";
        }

        // ── Priority 5: Dominant activity ──────────────────────────────────
        $topKey = $this->dominantKey($activities);
        if ($topKey) {
            $facts[] = "[PATTERN] Dominant activity: " . str_replace('_', ' ', $topKey) . ".";
        }

        // ── Priority 6 / 7: Daily analysis (optional) ──────────────────────
        if (count($dailyMetrics) >= 2) {
            $sorted = $dailyMetrics;
            usort($sorted, fn ($a, $b) => ($b['working_sec'] ?? 0) <=> ($a['working_sec'] ?? 0));

            $best  = $sorted[0];
            $worst = $sorted[count($sorted) - 1];

            if (($best['working_sec'] ?? 0) > 0) {
                $facts[] = '[BEST DAY] ' . $best['date'] . ': ' . $this->fmtSec($best['working_sec'] ?? 0) . ' working time.';
            }
            if (
                !empty($worst['date'])
                && $worst['date'] !== $best['date']
                && ($worst['total_sec'] ?? 0) > 0
            ) {
                $facts[] = '[WORST DAY] ' . $worst['date'] . ': only ' . $this->fmtSec($worst['working_sec'] ?? 0) . ' working time.';
            }

            // Period-over-period trend (split into halves; ≥4 days required)
            if (count($dailyMetrics) >= 4) {
                $chrono = $dailyMetrics;
                usort($chrono, fn ($a, $b) => strcmp($a['date'], $b['date']));
                $half        = (int) floor(count($chrono) / 2);
                $earlyDays   = array_slice($chrono, 0, $half);
                $recentDays  = array_slice($chrono, -$half);
                $earlyFocus  = array_sum(array_column($earlyDays,  'focus_score')) / $half;
                $recentFocus = array_sum(array_column($recentDays, 'focus_score')) / $half;
                $delta       = (int) round($recentFocus - $earlyFocus);

                if (abs($delta) >= 10) {
                    $abs  = abs($delta);
                    $dir  = $delta > 0 ? 'IMPROVING' : 'DECLINING';
                    $verb = $delta > 0 ? 'up' : 'down';
                    $facts[] = "[TREND-{$dir}] Focus score trended {$verb} {$abs} points in the second half of this period vs the first half.";
                } else {
                    $sign    = $delta >= 0 ? '+' : '';
                    $facts[] = "[TREND-STABLE] Focus score was stable across the period (Δ{$sign}{$delta} pts).";
                }
            }
        }

        return $facts;
    }

    // ── Entry point 2: parse frontend report blob ─────────────────────────────

    /**
     * Extract ranked insight facts from the serialised report data string
     * built by Angular's identity-detail openAiReport() method.
     *
     * Parses:
     *   • "Employee: NAME"
     *   • "Period:   PERIOD"
     *   • "  Focus Score:   72%"   (Personal Metrics section)
     *   • "  Working: 5h 20m (62.7%)"  (Activity Distribution section)
     *   • "  Phone Usage: 1h 10m (13.7%)"
     *   • "  Inactivity: 45m (8.8%)"
     *   • "  [WARNING] ..."  (Automated Insights — already computed by Angular)
     *   • "NOTABLE INCIDENTS (N):"
     *
     * @return string[]  Ordered insight fact strings, highest priority first
     */
    public function fromReportBlob(string $blob): array
    {
        $lines       = explode("\n", $blob);
        $name        = null;
        $period      = null;
        $focusScore  = null;
        $activities  = [];   // ['Working' => pct%, 'Using_Phone' => pct%, 'Inactive' => pct%]
        $sysInsights = [];   // Pre-computed insights from Angular
        $alertsCount = 0;

        foreach ($lines as $line) {
            $t = trim($line);

            if (preg_match('/^Employee:\s+(.+)$/i', $t, $m)) {
                $name = trim($m[1]);
            }
            if (preg_match('/^Period:\s+(.+)$/i', $t, $m)) {
                $period = trim($m[1]);
            }

            // "  Focus Score:   72%" (Personal Metrics section)
            if (preg_match('/focus\s+score[:\s]+(\d+)\s*%/i', $t, $m)) {
                $focusScore = (int) $m[1];
            }

            // Activity Distribution lines with parenthesised percentage:
            // "  Working: 5h 20m (62.7%)" or "  Phone Usage: 1h 10m (13.7%, 12 segs)"
            if (preg_match('/^\s+([\w\s]+):\s+.+\((\d+(?:\.\d+)?)\s*%/i', $line, $m)) {
                $label = strtolower(trim($m[1]));
                $pct   = (float) $m[2];
                $key   = match ($label) {
                    'working'     => 'Working',
                    'phone usage' => 'Using_Phone',
                    'inactivity'  => 'Inactive',
                    default       => null,
                };
                if ($key !== null) {
                    $activities[$key] = $pct;
                }
            }

            // Automated insights pre-computed by Angular: "  [WARNING] High phone usage..."
            if (preg_match('/^\s+\[(WARNING|CRITICAL|INFO|POSITIVE)\]\s+(.+)$/i', $line, $m)) {
                $sysInsights[] = "[{$m[1]}] " . trim($m[2]);
            }

            // "NOTABLE INCIDENTS (N):"
            if (preg_match('/NOTABLE INCIDENTS \((\d+)\)/i', $line, $m)) {
                $alertsCount = (int) $m[1];
            }
        }

        $facts = [];

        // ── Header ─────────────────────────────────────────────────────────
        if ($name) {
            $header = "[SUBJECT] {$name}";
            if ($period) {
                $header .= " — Period: {$period}";
            }
            $facts[] = $header;
        }

        // ── Focus score ─────────────────────────────────────────────────────
        if ($focusScore !== null) {
            if ($focusScore < self::FOCUS_CRIT) {
                $facts[] = "[CRITICAL] Focus Score: {$focusScore}% (threshold ≥" . self::FOCUS_CRIT . "%) — significantly below target.";
            } elseif ($focusScore < self::FOCUS_WARN) {
                $facts[] = "[WARNING] Focus Score: {$focusScore}% — moderate productivity.";
            } else {
                $facts[] = "[POSITIVE] Focus Score: {$focusScore}% — strong productivity.";
            }
        }

        // ── Phone usage ─────────────────────────────────────────────────────
        if (isset($activities['Using_Phone'])) {
            $pct = (int) round($activities['Using_Phone']);
            if ($pct > self::PHONE_CRIT) {
                $facts[] = "[CRITICAL] Phone usage: {$pct}% of tracked time — exceeds " . self::PHONE_CRIT . "% critical threshold.";
            } elseif ($pct > self::PHONE_WARN) {
                $facts[] = "[WARNING] Phone usage: {$pct}% — above " . self::PHONE_WARN . "% acceptable limit.";
            } else {
                $facts[] = "[OK] Phone usage: {$pct}% — within acceptable range.";
            }
        }

        // ── Inactivity ──────────────────────────────────────────────────────
        if (isset($activities['Inactive'])) {
            $pct = (int) round($activities['Inactive']);
            if ($pct > self::INACT_CRIT) {
                $facts[] = "[CRITICAL] Inactivity: {$pct}% — exceeds " . self::INACT_CRIT . "% critical threshold.";
            } elseif ($pct > self::INACT_WARN) {
                $facts[] = "[WARNING] Inactivity: {$pct}% — above " . self::INACT_WARN . "% threshold.";
            } else {
                $facts[] = "[OK] Inactivity: {$pct}% — within acceptable range.";
            }
        }

        // ── Working time ────────────────────────────────────────────────────
        if (isset($activities['Working'])) {
            $pct = (int) round($activities['Working']);
            if ($pct < self::WORK_CRIT) {
                $facts[] = "[CRITICAL] Working time: only {$pct}% — less than " . self::WORK_CRIT . "% of tracked time.";
            } else {
                $facts[] = "[METRIC] Working time: {$pct}% of tracked time.";
            }
        }

        // ── System insights (frontend-computed, deterministic) ──────────────
        // Include top 4; the LLM should not duplicate them but may reference them.
        foreach (array_slice($sysInsights, 0, 4) as $insight) {
            // Strip the redundant [SEVERITY] prefix since facts already carry one.
            $clean   = preg_replace('/^\[(WARNING|CRITICAL|INFO|POSITIVE)\]\s+/i', '', $insight);
            $facts[] = "[SYSTEM] {$clean}";
        }

        // ── Alert summary ────────────────────────────────────────────────────
        if ($alertsCount > 0) {
            $facts[] = "[ALERTS] {$alertsCount} notable incident(s) flagged in this period.";
        }

        return $facts;
    }

    // ── Shared helper ─────────────────────────────────────────────────────────

    /**
     * Format insight facts as a structured block ready to prepend to an LLM prompt.
     *
     * @param string[] $facts  Output of fromActivitySecs() or fromReportBlob()
     */
    public function toPromptBlock(array $facts): string
    {
        if (empty($facts)) {
            return '';
        }

        $lines = ['=== INSIGHT FACTS (ordered by priority — address CRITICAL before WARNING) ==='];
        foreach ($facts as $fact) {
            $lines[] = "  • {$fact}";
        }
        $lines[] = '=== END INSIGHT FACTS ===';

        return implode("\n", $lines);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function dominantKey(array $activities): ?string
    {
        if (empty($activities)) {
            return null;
        }
        arsort($activities);
        return (string) array_key_first($activities);
    }

    private function fmtSec(float $sec): string
    {
        if ($sec < 60) {
            return round($sec) . 's';
        }
        if ($sec < 3600) {
            return round($sec / 60) . 'm';
        }
        $h = (int) floor($sec / 3600);
        $m = (int) round(($sec % 3600) / 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
