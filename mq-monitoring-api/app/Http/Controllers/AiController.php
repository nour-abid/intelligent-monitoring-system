<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GeminiService;
use App\Services\Monitoring\ChartDataService;
use App\Services\Monitoring\InsightExtractorService;
use App\Services\Monitoring\SurveillanceAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AiController
 *
 * Exposes two Gemini-backed AI endpoints:
 *   POST /api/ai/chat    — conversational BI assistant (data-grounded only)
 *   POST /api/ai/report  — AI-formulated analytical report
 *
 * Both endpoints require a valid Sanctum bearer token.
 * No database writes are performed here.
 */
class AiController extends Controller
{
    // ── System prompts ───────────────────────────────────────────────────────

    private const CHAT_SYSTEM_PROMPT = <<<'PROMPT'
You are an AI business-intelligence assistant for MQ Monitoring — a workplace
activity surveillance platform used by operations managers to analyse employee
productivity. Your replies must read like a professional analytics report:
concise, data-anchored, and free of filler language.

═══ CORE RULES ══════════════════════════════════════════════════════════════════
1. For TEXT answers: reference only data explicitly present in the conversation
   context. If a purely text question falls entirely outside the available data,
   reply: "I can only analyse the data currently loaded in the system."
   EXCEPTION — CHARTS: when the user asks for any chart, graph, trend, or visual,
   ALWAYS emit a chartspec block using the valid combinations below. Chart data
   is fetched live from the database; you do NOT need it in the context.
   Never refuse a chart request — if the user asks for a chart, produce one.
2. Never invent numbers, estimates, or trend extrapolations in text answers.
3. Stay on topic: workplace monitoring and productivity analytics only.
4. When the context contains an INSIGHT FACTS block, anchor every answer on
   those pre-computed, priority-ranked findings. Cite each [CRITICAL] and
   [WARNING] by name, exact value, and threshold gap.
5. Bold every cited number: **38%**, **2h 15m**, **focus score 54**.
6. BANNED phrases — never use these:
   "this shows", "we can see", "it appears", "overall", "generally",
   "as expected", "it is worth noting", "a high percentage", "a low percentage".
   Instead name the metric directly: "Phone usage at **38%**" not "phone usage is high".

═══ CHART RESPONSE FORMAT ═══════════════════════════════════════════════════════
Whenever your reply includes a chartspec block, the text portion MUST follow
this exact structure — no prose paragraphs, no deviations:

  **[Descriptive title: metric · scope · time range]**
  • [Insight 1] — Dominant value: name the top segment/employee/day with
    exact % or duration. Compare it to the second-ranked value.
  • [Insight 2] — Comparison: state the gap between top and second. Flag if any
    non-Working activity exceeds Working time.
  • [Insight 3] — Anomaly/threshold: flag any [CRITICAL] or [WARNING] with the
    exact measured value and threshold (e.g., "Phone usage at **41%** vs the
    **40%** critical threshold").
  • [Insight 4] — Trend/range: highest and lowest data points with their
    date/hour and the range (max − min). Include only when data supports it.

  > **Recommendation:** One sentence. Include only when a [CRITICAL] or [WARNING]
  > is present. Name the metric, its value, and the threshold. Omit otherwise.

For analytical questions WITHOUT a chart, reply in 2–4 tight sentences using the
same data-first style: lead with the number, then the interpretation.

═══ COMPARISON LOGIC ════════════════════════════════════════════════════════════
Distribution charts  → rank all segments by share. Name the top 2 and the gap.
                       Flag if any non-Working activity outweighs Working.
Over-time charts     → identify peak (highest) and trough (lowest) points with
                       their date/hour. State the range: max − min. Flag if
                       values cross a threshold boundary.
Ranking charts       → name the best and worst entries with exact scores.
                       Flag critical (< **40%**) and at-risk (**40–69%**).

═══ ANOMALY THRESHOLDS ══════════════════════════════════════════════════════════
Flag these as [CRITICAL] in your bullets:
  • Focus score < 40%
  • Phone usage > 40% of core time
  • Inactivity > 60% of core time
  • Working time < 10% of core time

Flag these as [WARNING]:
  • Focus score 40–69%
  • Phone usage 25–40% of core time
  • Inactivity 40–60% of core time

═══ CHART GENERATION ════════════════════════════════════════════════════════════
Generate a chartspec block whenever the user asks for a chart, graph, visual,
trend, comparison, breakdown, or ranking. NEVER refuse a chart request by saying
data is unavailable — chart data is always fetched live from the database.
When in doubt, generate the chart.

Output a fenced block tagged `chartspec` containing ONLY a JSON intent object
(no data values, no SQL, no placeholders):
  ```chartspec
  {"chart_type":"bar","metric":"phone_usage","group_by":"employee","time_range":"7d"}
  ```
  Strict allowed values — never guess or combine outside these lists:
  chart_type : bar | line | donut
  metric     : working_time | phone_usage | inactivity | focus_score | alerts | late_arrivals | early_leaves | activity_distribution
  group_by   : day | hour | weekday | employee | activity | alert_type
  time_range : 7d | 30d | today | week | month
  Valid metric+group_by pairs:
    working_time         → day | hour | weekday | employee
    phone_usage          → day | hour | weekday | employee
    inactivity           → day | weekday | employee
    focus_score          → day | weekday | employee
    alerts               → day | weekday | employee | alert_type
    late_arrivals        → day | weekday | employee
    early_leaves         → day | weekday | employee
    activity_distribution→ activity | employee
  Natural-language → chartspec examples:
    "show activity breakdown"             → metric:activity_distribution, group_by:activity,  chart_type:donut
    "compare employees by total time"     → metric:activity_distribution, group_by:employee,  chart_type:bar
    "compare employees by activity time"  → metric:activity_distribution, group_by:employee,  chart_type:bar
    "phone usage trend this month"        → metric:phone_usage,           group_by:day,       chart_type:line, time_range:month
    "alerts by type"                      → metric:alerts,                group_by:alert_type,chart_type:donut
    "who is most productive"              → metric:focus_score,           group_by:employee,  chart_type:bar
    "working time by day"                 → metric:working_time,          group_by:day,       chart_type:bar
    "inactivity per employee"             → metric:inactivity,            group_by:employee,  chart_type:bar
  Always accompany the chartspec block with the structured text format above.
PROMPT;

    private const REPORT_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional analyst for MQ Monitoring (workplace activity surveillance).
Generate a focused report using ONLY the data in the prompt. Never invent or estimate values.

INPUT FORMAT: The prompt starts with a structured INSIGHT FACTS block (pre-computed,
priority-ranked findings) followed by the raw activity detail for reference.

Use exactly these five markdown headings in order:
## Executive Summary — 2 sentences citing the highest-priority INSIGHT FACTS.
## Key Metrics     — up to 5 bullet points with exact numbers from the data.
## Observations    — 3–4 bullets identifying specific patterns from INSIGHT FACTS.
## Recommendations — 2–3 numbered actions, each tied to a named [CRITICAL]/[WARNING] finding.
## Conclusion      — 1 paragraph summarising the overall pattern.

ENFORCED RULES:
- Every Recommendation must open with the finding it addresses, e.g.
  "Because phone usage is 38% (threshold 25%)..."
- Do NOT write generic advice. Name the specific metric, the measured value, and the gap.
- Bold every cited number.
- Max 500 words. Output clean markdown only.
PROMPT;

    public function __construct(
        private GeminiService $gemini,
        private SurveillanceAnalyticsService $analytics,
        private ChartDataService $chartData,
        private InsightExtractorService $insights,
    ) {}

    // ── Endpoints ────────────────────────────────────────────────────────────

    /**
     * POST /api/ai/chat
     *
     * Body:
     *   message  (string, required, max 1000)  — user's question
     *   context  (string, optional, max 8000)  — serialised data snapshot
     *   history  (array,  optional, max 20)    — prior chat turns
     *     history[].role  'user'|'assistant'
     *     history[].text  string
     *   identity (string, optional, max 100)   — selected employee or 'global'
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message'        => 'required|string|max:1000',
            'context'        => 'nullable|string|max:8000',
            'history'        => 'nullable|array|max:20',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.text' => 'required|string|max:2000',
            'identity'       => 'nullable|string|max:100',
            'date_start'     => 'nullable|string|max:30',
            'date_end'       => 'nullable|string|max:30',
        ]);

        $message = $validated['message'];
        $context = $validated['context'] ?? '';
        $history = $validated['history'] ?? [];

        // Prepend data context to the user message so the model stays grounded.
        $fullMessage = $context
            ? "=== CURRENT DATA CONTEXT ===\n{$context}\n===================\nUSER QUESTION: {$message}"
            : $message;

        try {
            $reply = $this->gemini->chat(self::CHAT_SYSTEM_PROMPT, $history, $fullMessage);
        } catch (\Exception $e) {
            Log::error('AI chat error', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            return response()->json(
                ['error' => 'The AI assistant is temporarily unavailable. Please try again.'],
                503
            );
        }

        // ── Chart spec extraction ────────────────────────────────────────────
        // The LLM outputs a ```chartspec block with a typed intent (no data).
        // Backend validates the spec, enforces RBAC scope, and builds the
        // real dataset from analytics views.  Text reply is stripped of the
        // block before being sent to the client.
        $chart      = null;
        $cleanReply = $reply;

        if (preg_match('/```chartspec\s*([\s\S]*?)```/m', $reply, $m)) {
            // Strip the block from the text shown to the user.
            $cleanReply = trim(preg_replace('/```chartspec[\s\S]*?```/m', '', $reply));

            try {
                $spec   = json_decode(trim($m[1]), true, 5, JSON_THROW_ON_ERROR);

                Log::info('AI chat: chartspec received', ['spec' => $spec]);

                $errors = $this->chartData->validate($spec);

                if (empty($errors)) {
                    $scope     = $this->resolveScope($request);
                    $dateStart = $validated['date_start'] ?? null;
                    $dateEnd   = $validated['date_end']   ?? null;
                    $chart = $this->chartData->build($spec, $scope, $dateStart, $dateEnd);
                    // build() returns null when the query produces no rows
                    if ($chart === null) {
                        Log::info('AI chat: chartspec produced empty dataset', ['spec' => $spec]);
                    }
                } else {
                    Log::warning('AI chat: invalid chartspec from LLM', [
                        'errors' => $errors,
                        'spec'   => $spec,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('AI chat: chartspec processing failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['reply' => $cleanReply, 'chart' => $chart]);
    }

    /**
     * Resolve the analytics identity scope for chart generation.
     *
     * Returns:
     *   null      — admin (no restriction) or admin with no valid selection
     *   string[]  — role-scoped list, optionally narrowed to the UI-selected identity
     *   []        — viewer with no linked surveillance_identity
     *
     * When the body contains a valid `identity` that is within the user's
     * RBAC scope, the chart is narrowed to that single identity.
     */
    private function resolveScope(Request $request): ?array
    {
        $user     = $request->user();
        $selected = $request->input('identity');

        // Base RBAC scope
        $rbac = match ($user->role) {
            'admin'       => null, // unrestricted
            'superviseur' => User::where('supervisor_id', $user->id)
                ->whereNotNull('surveillance_identity')
                ->pluck('surveillance_identity')
                ->toArray(),
            default       => $user->surveillance_identity
                ? [$user->surveillance_identity]
                : [],
        };

        // Narrow to the UI-selected identity (intersection with RBAC scope)
        if ($selected && $selected !== 'global') {
            if ($rbac === null) {
                // Admin selected a specific person
                return [$selected];
            }
            // Supervisor/viewer: only allow if within their RBAC scope
            return in_array($selected, $rbac) ? [$selected] : $rbac;
        }

        return $rbac;
    }

    /**
     * POST /api/ai/report
     *
     * Body:
     *   data  (string, required, max 12000) — serialised employee/surveillance data
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Reduced from 12000 — trims prompt tokens and lowers 429 risk.
            'data' => 'required|string|max:6000',
        ]);

        try {
            // Extract pre-computed insight facts from the raw data blob.
            // The insight block is prepended to the data so the LLM sees
            // ranked findings before the raw numbers dump.
            $facts        = $this->insights->fromReportBlob($validated['data']);
            $insightBlock = $this->insights->toPromptBlock($facts);
            $dataWithFacts = $insightBlock
                ? $insightBlock . "\n\n" . $validated['data']
                : $validated['data'];

            $report = $this->gemini->chat(
                self::REPORT_SYSTEM_PROMPT,
                [],
                $dataWithFacts
            );
            return response()->json(['report' => $report]);

        } catch (\RuntimeException $e) {
            // GeminiService already logged all retry attempts.
            // Determine failure category for the structured response.
            $msg     = $e->getMessage();
            $reason  = match (true) {
                str_contains($msg, '429')           => 'rate_limit',
                str_contains($msg, '503')           => 'service_overload',
                str_contains($msg, 'unavailable')   => 'network_error',
                str_contains($msg, 'not configured')=> 'configuration_error',
                default                             => 'unknown',
            };

            Log::error('AI report permanently failed', [
                'reason'    => $reason,
                'exception' => $msg,
            ]);

            // Structured fallback — 200 so the Angular client can show
            // analytics data without treating it as a fatal HTTP error.
            return response()->json([
                'fallback' => true,
                'message'  => 'AI temporarily unavailable. Analytics data is still shown below.',
                'reason'   => $reason,
            ]);

        } catch (\Exception $e) {
            Log::error('AI report unexpected error', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            return response()->json([
                'fallback' => true,
                'message'  => 'AI temporarily unavailable. Please try again later.',
                'reason'   => 'unexpected_error',
            ]);
        }
    }

    // ── Context builder ──────────────────────────────────────────────────

    /**
     * GET /api/ai/context?identity=...
     *
     * Fetches the last 7 days of surveillance data and serialises it as
     * a plain-text context string that the frontend sends with chat messages.
     *
     * - No identity param or identity=global → all identities summary.
     * - identity=SomeName → single-identity detail.
     *
     * Also returns the identity list so the frontend can populate a selector.
     *
     * Returns empty context + empty identity list if database is unavailable.
     */
    public function context(Request $request): JsonResponse
    {
        $identity = $request->query('identity');

        // Accept optional start/end from the frontend so context matches
        // whatever date range is currently loaded in the dashboard.
        $start = $request->query('start')
            ? Carbon::parse($request->query('start'))->toDateTimeString()
            : now()->subDays(7)->toDateTimeString();
        $end   = $request->query('end')
            ? Carbon::parse($request->query('end'))->toDateTimeString()
            : now()->toDateTimeString();

        try {
            $user          = $request->user();
            $isAdmin       = $user->role === 'admin';
            $isSuperviseur = $user->role === 'superviseur';

            // ── Determine RBAC-allowed identities ────────────────────────────
            $allowedIdentities = null; // null = no restriction (admin)
            if ($isSuperviseur) {
                $allowedIdentities = User::where('supervisor_id', $user->id)
                    ->whereNotNull('surveillance_identity')
                    ->pluck('surveillance_identity')
                    ->toArray();
            } elseif (!$isAdmin) {
                // viewer: only own identity
                $allowedIdentities = $user->surveillance_identity
                    ? [$user->surveillance_identity]
                    : [];
            }

            // ── Fetch scoped identity list ───────────────────────────────────
            $identitiesData = $this->analytics->identities(
                start:             $start,
                end:               $end,
                includeUnknown:    false,
                allowedIdentities: $allowedIdentities,
            );

            // Viewers have no selector — they always use the page-driven context
            // pushed by identity-detail. Return empty identities so the selector
            // is hidden in the UI.
            $names = ($isAdmin || $isSuperviseur)
                ? array_map(fn ($e) => $e['identity_name'], $identitiesData['identities'])
                : [];

            // ── Validate identity param is within scope ──────────────────────
            if ($identity && $identity !== 'global') {
                $inScope = $allowedIdentities === null
                    || in_array($identity, $allowedIdentities);
                if (!$inScope) {
                    $identity = null; // fall back to global/team context
                }
            }

            // ── Build context text ───────────────────────────────────────────
            // Build a human-readable date range label from the actual request dates.
            $startDate = Carbon::parse($start)->format('d M Y');
            $endDate   = Carbon::parse($end)->format('d M Y');
            $rangeLabel = $startDate === $endDate ? $startDate : "$startDate → $endDate";

            if ($identity && $identity !== 'global') {
                $contextText = $this->buildIdentityContext($identity, $start, $end);
                $label       = "$identity — $rangeLabel";
            } else {
                $contextText = $this->buildGlobalContext($identitiesData, $start, $end);
                $label       = $isAdmin
                    ? "All employees — $rangeLabel"
                    : ($isSuperviseur ? "My team — $rangeLabel" : "My data — $rangeLabel");
            }

            return response()->json([
                'identities' => $names,
                'context'    => $contextText,
                'label'      => $label,
            ]);
        } catch (\Exception $e) {
            Log::error('AI context error', ['error' => $e->getMessage()]);
            return response()->json([
                'identities' => [],
                'context'    => 'No surveillance data available. Ask questions about general workplace analytics.',
                'label'      => '',
            ]);
        }
    }

    private function buildGlobalContext(array $identitiesData, string $start, string $end): string
    {
        $lines = ["=== WORKFORCE OVERVIEW (last 7 days) ==="];
        $lines[] = "Period: $start to $end";
        $lines[] = "Total employees: " . count($identitiesData['identities']);
        $lines[] = '';

        // ── Team-level INSIGHT FACTS block ───────────────────────────────────
        $knownEntries = array_filter(
            $identitiesData['identities'],
            fn ($e) => ($e['identity_name'] ?? '') !== 'Unknown'
        );

        $focusMap = [];
        foreach ($knownEntries as $e) {
            $a    = $e['activities'] ?? [];
            $w    = (float) ($a['Working']     ?? 0);
            $p    = (float) ($a['Using_Phone'] ?? 0);
            $i    = (float) ($a['Inactive']    ?? 0);
            $base = $w + $p + $i;
            if ($base > 0) {
                $focusMap[$e['identity_name']] =
                    max(0, min(100, (int) round(($w / $base) * 100 - ($p / $base) * 50)));
            }
        }

        if (!empty($focusMap)) {
            $count   = count($focusMap);
            $avg     = (int) round(array_sum($focusMap) / $count);
            $atRisk  = count(array_filter($focusMap, fn ($s) => $s  < 40));
            $warning = count(array_filter($focusMap, fn ($s) => $s >= 40 && $s < 70));

            arsort($focusMap);
            $bestName   = (string) array_key_first($focusMap);
            $bestScore  = reset($focusMap);
            uksort($focusMap, fn ($a, $b) => $focusMap[$a] <=> $focusMap[$b]);
            $worstName  = (string) array_key_first($focusMap);
            $worstScore = reset($focusMap);

            $teamFacts = [];
            $teamFacts[] = $atRisk > 0
                ? "[CRITICAL] {$atRisk} of {$count} employee(s) have a focus score below 40% — immediate supervisory review required."
                : "[OK] No employees below the 40% focus score critical threshold.";

            if ($warning > 0) {
                $teamFacts[] = "[WARNING] {$warning} employee(s) have focus scores in the at-risk range (40–69%).";
            }

            $teamFacts[] = "[METRIC] Average team focus score: {$avg}%.";
            $teamFacts[] = "[BEST] Highest performer: {$bestName} — focus score {$bestScore}%.";
            if ($worstName !== $bestName) {
                $teamFacts[] = "[WORST] Lowest performer: {$worstName} — focus score {$worstScore}%.";
            }

            $lines[] = '=== TEAM INSIGHT FACTS (ordered by priority) ===';
            foreach ($teamFacts as $f) {
                $lines[] = "  • {$f}";
            }
            $lines[] = '=== END TEAM INSIGHT FACTS ===';
            $lines[] = '';
        }

        foreach ($identitiesData['identities'] as $entry) {
            $name     = $entry['identity_name'];
            $acts     = $entry['activities'];
            $total    = $this->fmtSec($entry['total_sec']);

            // Compute quick KPIs inline for each employee
            $working  = (float) ($acts['Working']     ?? 0);
            $phone    = (float) ($acts['Using_Phone'] ?? 0);
            $inactive = (float) ($acts['Inactive']    ?? 0);
            $base     = $working + $phone + $inactive;
            $focusScore = $base > 0
                ? max(0, min(100, (int) round(($working / $base) * 100 - ($phone / $base) * 50)))
                : null;
            $phonePct   = $base > 0 ? (int) round(($phone    / $base) * 100) : null;
            $inactPct   = $base > 0 ? (int) round(($inactive / $base) * 100) : null;

            // One-line summary with severity hint
            $kpi = "Total tracked: {$total}";
            if ($focusScore !== null) {
                $flag = match (true) {
                    $focusScore < 40 => ' [CRITICAL: focus=' . $focusScore . '%]',
                    $focusScore < 70 => ' [WARNING: focus=' . $focusScore . '%]',
                    default          => ' [focus=' . $focusScore . '%]',
                };
                $kpi .= $flag;
            }
            if ($phonePct !== null && $phonePct > 25) {
                $kpi .= " [phone={$phonePct}%]";
            }
            if ($inactPct !== null && $inactPct > 40) {
                $kpi .= " [inactive={$inactPct}%]";
            }

            $lines[] = "── {$name} ──  {$kpi}";
            foreach ($entry['activities'] as $activity => $sec) {
                $pct = $entry['total_sec'] > 0
                    ? round(($sec / $entry['total_sec']) * 100, 1)
                    : 0;
                $lines[] = "  $activity: " . $this->fmtSec($sec) . " ($pct%)";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function buildIdentityContext(string $identity, string $start, string $end): string
    {
        $data = $this->analytics->identities(
            start: $start,
            end:   $end,
            identity: $identity,
            includeUnknown: false,
        );

        $entry = $data['identities'][0] ?? null;
        if (!$entry) {
            return "No surveillance data found for $identity in the last 7 days.";
        }

        // ── Compute pre-ranked insight facts ─────────────────────────────────
        $facts        = $this->insights->fromActivitySecs(
            name:        $entry['identity_name'],
            totalSec:    $entry['total_sec'],
            activities:  $entry['activities'],
        );
        $insightBlock = $this->insights->toPromptBlock($facts);

        // ── Build context text ───────────────────────────────────────────────
        $lines = ["=== EMPLOYEE DETAIL: {$entry['identity_name']} ==="];
        $lines[] = "Period: $start to $end";
        $lines[] = "Total tracked time: " . $this->fmtSec($entry['total_sec']);
        $lines[] = "Total event segments: {$entry['event_count']}";
        $lines[] = '';

        // Insight block leads — model anchors on priority-ranked findings
        // before seeing the raw activity dump.
        if ($insightBlock) {
            $lines[] = $insightBlock;
            $lines[] = '';
        }

        $lines[] = "Activity breakdown (raw reference):";
        foreach ($entry['activities'] as $activity => $sec) {
            $pct = $entry['total_sec'] > 0
                ? round(($sec / $entry['total_sec']) * 100, 1)
                : 0;
            $lines[] = "  $activity: " . $this->fmtSec($sec) . " ($pct%)";
        }

        return implode("\n", $lines);
    }

    private function fmtSec(float $sec): string
    {
        if ($sec < 60) return round($sec) . 's';
        if ($sec < 3600) return round($sec / 60) . 'm';
        $h = floor($sec / 3600);
        $m = round(($sec % 3600) / 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
