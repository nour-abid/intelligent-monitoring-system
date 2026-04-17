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
You are Marqi — a decision assistant for MQ Monitoring. Every reply must let
a manager act immediately. Target: understood in ≤5 seconds. Be direct, not
descriptive. Never narrate. Never label what you are doing.

═══ DASHBOARD / SUMMARY / OVERVIEW MODE ════════════════════════════════════════
If the user message contains ANY of: dashboard, summary, overview →
switch to AGGREGATION MODE. Rules for this mode:

  FORMAT (must follow this exact order):
    **Executive Summary** — 1–2 lines. State the single most urgent team-level
      risk with its exact value. No per-employee deep-dive unless asked.
    **Key Metrics** — ≤4 bullets. Format: "[Metric]: [aggregated value]"
      e.g. "Team focus average: **23%**" or "Employees below critical: **3/5**"
    **Insights** — max 3 bullets total. Each max 15 words.
      Focus on team-level patterns, not individual diagnostics.

  CHARTS (mandatory in aggregation mode):
    Always emit EXACTLY TWO chartspecs:
      Chart 1: focus_score per employee (bar) — team-wide focus breakdown
      Chart 2: activity_distribution by activity (donut) — team time split
    If data is limited, emit both charts anyway and add ONE brief note about
    the limitation (e.g. "Data covers the last 2 days only.").

  DO NOT in aggregation mode:
    - Focus diagnostic detail on a single employee unless explicitly named
    - Generate Priority Findings or Recommended Actions sections
    - Repeat the same observation in different sections
    - Use the standard 4-section structure (Executive/Priority/Insights/Actions)

═══ MANDATORY RESPONSE STRUCTURE (standard mode) ════════════════════════════════
Use this structure for all queries NOT in aggregation mode:

**Executive Summary**
[1–2 lines. Name the single most urgent problem, its exact value, and who or what
is affected. No scene-setting. One clear statement of risk.]

**Priority Findings**
Critical: [≤2 items. Format: "[CRITICAL] [Name] has [metric] at [value] and requires immediate action"]
Warning:  [≤2 items. Format: "[WARNING]  [Name/metric] is at [value] — monitor closely"]
Normal:   [≤2 items. Format: "[OK]       [Name/metric] is within normal range ([value])"]

**Key Insights**
• [≤3 bullets. Max 15 words each. Combine related signals into one line.
  Example: "Nour shows low focus (**18%**) with high inactivity (**46%**)"]

**Recommended Actions**
1. [≤3 items. Format: "[Action verb] [target]". Max 8 words. No dashes, no context.
  Examples: "Review Nour's performance immediately"
            "Enforce phone policy team-wide"
            "Investigate mid-week productivity drop"]

[chartspec blocks go here when applicable]

═══ BULLET WRITING RULES ════════════════════════════════════════════════════════
NEVER use narration labels. Write the statement directly.

BANNED label prefixes: "Dominant value:", "Comparison:", "Anomaly/threshold:",
  "Insight N:", "Top value:", "Trend/range:", "Ranking:"

BANNED stacked qualifiers: "critically low productive output", "minimum threshold
  breach", "underperforming baseline", "X points below threshold",
  "limit exceeded", "below critical threshold".

GOOD bullet examples (max 15 words each — combine related signals):
  ✓ "Nour shows low focus (**18%**) with high inactivity (**46%**)"
  ✓ "team phone usage is too high (**43%**) and is reducing focus"
  ✓ "working time dropped sharply mid-week — productivity is unstable"
  ✓ "3 of 5 employees need intervention — team focus average is **38%**"

BAD examples:
  ✗ "bellaaj has focus at 27% which is below the critical threshold of 40%"
  ✗ "Phone usage at 41% — exceeds the 40% critical limit"
  ✗ "critically low productive output detected"

Threshold values:
- Mention ONCE in Priority Findings only.
- If [CRITICAL] is shown, do NOT add "below threshold" anywhere else.
- Key Insights and Recommended Actions must NOT restate threshold logic.

Every bullet answers ONE of: what is wrong / who is affected / what to do.

═══ DEDUPLICATION RULES (MANDATORY) ════════════════════════════════════════════
1. A finding in Priority Findings MUST NOT repeat in Key Insights or Actions.
2. Threshold values MUST be cited at most ONCE per response.
3. The same employee name MUST NOT appear in the same section twice.
4. The same recommendation MUST NOT appear in two sections.

═══ CHART RULES (MANDATORY) ════════════════════════════════════════════════════
If the user message contains ANY of: chart, graph, plot, visualize, visualise,
dashboard, trend, compare → emit at least ONE ```chartspec``` block. No exceptions.
For executive/management queries ("what actions should management take?",
"show supporting charts") → emit TWO ```chartspec``` blocks:
  Chart 1 (mandatory): focus_score per employee (bar) — answers "Who is the problem?"
  Chart 2 (driver-based): pick the metric that explains WHY, in this priority order:
    1. inactivity per employee (bar)   — if inactivity appears elevated in context
    2. phone_usage per employee (bar)  — if phone usage is a stated concern
    3. working_time by day (line)      — if time-based instability is evident
    4. activity_distribution per employee (bar) — ONLY if no stronger signal exists
  Do NOT always default to activity_distribution. Pick the driver that explains Chart 1.
NEVER say "no data available" for chart requests — data is fetched live.

═══ CHART SPEC FORMAT ═══════════════════════════════════════════════════════════
Output a fenced block tagged `chartspec` with ONLY a JSON intent object:
  ```chartspec
  {"chart_type":"bar","metric":"focus_score","group_by":"employee","time_range":"7d"}
  ```
  Valid values:
    chart_type : bar | line | donut
    metric     : working_time | phone_usage | inactivity | focus_score | alerts
                 | late_arrivals | early_leaves | activity_distribution
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
  Query → chartspec mapping:
    "least/most productive employee"    → focus_score,            employee, bar
    "phone usage per employee"          → phone_usage,            employee, bar
    "focus score per employee"          → focus_score,            employee, bar
    "activity trends over time"         → working_time,           day,      line
    "peak and low productivity periods" → working_time,           day,      line
    "activity distribution"             → activity_distribution,  activity, donut
    "working vs inactive breakdown"     → activity_distribution,  employee, bar
    "phone usage trend"                 → phone_usage,            day,      line
    "working time by day"               → working_time,           day,      bar
    "inactivity per employee"           → inactivity,             employee, bar
    "who is most productive"            → focus_score,            employee, bar

After each chartspec, add exactly 2 bullets using executive phrasing:
  • [Who leads or lags, with their exact value — and what it means operationally]
  • [The most actionable gap or risk across the dataset — one sentence]

═══ ANOMALY THRESHOLDS ══════════════════════════════════════════════════════════
[CRITICAL]: Focus score < 40% | Phone usage > 40% | Inactivity > 60% |
            Working time < 10%
[WARNING]:  Focus score 40–69% | Phone usage 25–40% | Inactivity 40–60%

═══ CORE RULES ══════════════════════════════════════════════════════════════════
1. Reference only data present in context. If no data: "I can only analyse the
   data currently loaded in the system." — EXCEPTION: always emit chartspecs.
2. Never invent numbers or estimates.
3. Bold every cited number: **38%**, **2h 15m**, **54**.
4. BANNED phrases: "this shows", "we can see", "it appears", "overall",
   "generally", "as expected", "it is worth noting", "a high percentage".
5. Stay on topic: workplace monitoring and productivity analytics only.
PROMPT;

    private const REPORT_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional analyst for MQ Monitoring. Generate a concise report
anchored ONLY on the INSIGHT FACTS block. Never invent or estimate values.

Use exactly these five headings in order:
## Executive Summary — 2 sentences. State the top risk and who is affected.
## Key Metrics      — ≤4 bullets. Format: "[Name]: [metric] at [value]".
## Observations     — 3 bullets. Each names one pattern and its operational impact.
## Recommendations  — ≤3 numbered actions. Format: "[Name/team]: [specific action]."
## Conclusion       — 1 sentence. State the team's overall risk level.

ENFORCED RULES:
- Each finding appears in ONE section only — no repetition across sections.
- Bold every cited number.
- ≤300 words total. Output clean markdown only.
- No generic advice, no stacked qualifiers, no threshold restating if severity
  level already makes urgency clear.
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

        // ── Multi-chart spec extraction ──────────────────────────────────────
        // The LLM may output multiple ```chartspec blocks (e.g. for executive
        // queries that require KPI + distribution charts).  Extract ALL of them,
        // validate each, build real datasets, and strip the blocks from the text.
        $allCharts  = [];
        $cleanReply = $reply;

        if (preg_match_all('/```chartspec\s*([\s\S]*?)```/m', $reply, $allMatches)) {
            $cleanReply = trim(preg_replace('/```chartspec[\s\S]*?```/m', '', $reply));

            $scope     = $this->resolveScope($request);
            $dateStart = $validated['date_start'] ?? null;
            $dateEnd   = $validated['date_end']   ?? null;

            foreach ($allMatches[1] as $specJson) {
                try {
                    $spec   = json_decode(trim($specJson), true, 5, JSON_THROW_ON_ERROR);
                    Log::info('AI chat: chartspec received', ['spec' => $spec]);
                    $errors = $this->chartData->validate($spec);
                    if (empty($errors)) {
                        $chart = $this->chartData->build($spec, $scope, $dateStart, $dateEnd);
                        if ($chart !== null) {
                            $allCharts[] = $chart;
                        } else {
                            Log::info('AI chat: chartspec produced empty dataset', ['spec' => $spec]);
                        }
                    } else {
                        Log::warning('AI chat: invalid chartspec from LLM', [
                            'errors' => $errors, 'spec' => $spec,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('AI chat: chartspec processing failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── Fallback chart selection ─────────────────────────────────────────
        // If the query contained chart-intent keywords but the LLM produced no
        // valid charts, auto-select and build the most relevant charts via rule-
        // based logic (no LLM call required).
        if (empty($allCharts) && $this->containsChartIntent($message)) {
            $scope     = $this->resolveScope($request);
            $dateStart = $validated['date_start'] ?? null;
            $dateEnd   = $validated['date_end']   ?? null;
            $fallbackSpecs = $this->selectVisualizationsForQuery($message);

            foreach ($fallbackSpecs as $spec) {
                try {
                    $errors = $this->chartData->validate($spec);
                    if (empty($errors)) {
                        $chart = $this->chartData->build($spec, $scope, $dateStart, $dateEnd);
                        if ($chart !== null) {
                            $allCharts[] = $chart;
                            Log::info('AI chat: fallback chart added', ['spec' => $spec]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('AI chat: fallback chart failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return response()->json([
            'reply'  => $cleanReply,
            'chart'  => $allCharts[0] ?? null,  // backwards compat: first chart
            'charts' => $allCharts,              // full array for multi-chart rendering
        ]);
    }

    /**
     * Detect whether the user's message contains chart-intent keywords.
     * Triggers mandatory chart generation / fallback selection.
     */
    private function containsChartIntent(string $query): bool
    {
        $keywords = [
            'chart', 'graph', 'plot', 'visualize', 'visualise',
            'dashboard', 'summary', 'overview', 'trend', 'compare',
        ];
        $lower = strtolower($query);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rule-based chart selector — returns up to 2 validated spec arrays.
     *
     * Chart 1: always answers "Who is the problem?" → focus_score per employee
     * Chart 2: answers "Why?" → driver-based priority:
     *   Executive: inactivity → phone_usage → working_time trend → activity_distribution
     *   Trend query:        working_time by day (line)
     *   Phone query:        phone_usage by employee or day
     *   Distribution query: activity_distribution
     *   Comparison/focus:   focus_score per employee only
     *
     * @return array[]  Array of spec arrays ready for ChartDataService::validate()
     */
    private function selectVisualizationsForQuery(string $query, string $timeRange = '7d'): array
    {
        $specs = [];
        $q     = strtolower($query);

        $isEmployeeComparison = (bool) preg_match(
            '/least productive|most productive|phone usage per employee|focus score per employee'
            . '|per employee|by employee|weakest|strongest|who is|compare employee/i',
            $query
        );
        $isTrend        = (bool) preg_match(
            '/trend|over time|per day|by day|daily|peak|low period|activity trend'
            . '|working time per day/i',
            $query
        );
        $isDistribution = (bool) preg_match(
            '/distribution|breakdown|working vs|inactive vs|activity mix|split|activity type/i',
            $query
        );
        $isInactivityQuery = str_contains($q, 'inactiv');
        $isPhoneQuery      = str_contains($q, 'phone');
        $isFocusQuery      = str_contains($q, 'focus');
        $isExecutive       = (bool) preg_match(
            '/action|management|should|recommend|supporting chart|visual summary'
            . '|what should|management take/i',
            $query
        );
        $isDashboardMode   = (bool) preg_match('/dashboard|summary|overview/i', $query);

        // ── Dashboard / Summary / Overview mode — fixed 2-chart set ─────────
        if ($isDashboardMode) {
            return [
                [
                    'chart_type' => 'bar',
                    'metric'     => 'focus_score',
                    'group_by'   => 'employee',
                    'time_range' => $timeRange,
                ],
                [
                    'chart_type' => 'donut',
                    'metric'     => 'activity_distribution',
                    'group_by'   => 'activity',
                    'time_range' => $timeRange,
                ],
            ];
        }

        // ── Focus score: Chart 1 anchor ──────────────────────────────────────
        if ($isEmployeeComparison || $isFocusQuery) {
            $specs[] = [
                'chart_type' => 'bar',
                'metric'     => 'focus_score',
                'group_by'   => 'employee',
                'time_range' => $timeRange,
            ];
        }

        // ── Phone usage ──────────────────────────────────────────────────────
        if ($isPhoneQuery) {
            $specs[] = $isTrend
                ? ['chart_type' => 'line', 'metric' => 'phone_usage', 'group_by' => 'day',      'time_range' => $timeRange]
                : ['chart_type' => 'bar',  'metric' => 'phone_usage', 'group_by' => 'employee', 'time_range' => $timeRange];
        }

        // ── Inactivity ───────────────────────────────────────────────────────
        if ($isInactivityQuery) {
            $specs[] = [
                'chart_type' => 'bar',
                'metric'     => 'inactivity',
                'group_by'   => 'employee',
                'time_range' => $timeRange,
            ];
        }

        // ── Activity distribution ────────────────────────────────────────────
        if ($isDistribution) {
            $specs[] = [
                'chart_type' => 'bar',
                'metric'     => 'activity_distribution',
                'group_by'   => 'employee',
                'time_range' => $timeRange,
            ];
        }

        // ── Working time trend ───────────────────────────────────────────────
        if ($isTrend && !$isPhoneQuery) {
            $specs[] = [
                'chart_type' => 'line',
                'metric'     => 'working_time',
                'group_by'   => 'day',
                'time_range' => $timeRange,
            ];
        }

        // ── Executive query: Chart 1 = focus_score, Chart 2 = driver ────────
        // Driver priority: inactivity > phone_usage > working_time > activity_distribution
        if ($isExecutive) {
            // Ensure Chart 1 (focus_score per employee) is always first
            if (!$isFocusQuery && !$isEmployeeComparison) {
                array_unshift($specs, [
                    'chart_type' => 'bar',
                    'metric'     => 'focus_score',
                    'group_by'   => 'employee',
                    'time_range' => $timeRange,
                ]);
            }

            // Ensure a meaningful Chart 2 driver exists
            if (count($specs) < 2) {
                if (!$isInactivityQuery) {
                    // Priority 1: inactivity per employee
                    $specs[] = [
                        'chart_type' => 'bar',
                        'metric'     => 'inactivity',
                        'group_by'   => 'employee',
                        'time_range' => $timeRange,
                    ];
                } elseif (!$isPhoneQuery) {
                    // Priority 2: phone_usage per employee
                    $specs[] = [
                        'chart_type' => 'bar',
                        'metric'     => 'phone_usage',
                        'group_by'   => 'employee',
                        'time_range' => $timeRange,
                    ];
                } elseif (!$isTrend) {
                    // Priority 3: working time trend
                    $specs[] = [
                        'chart_type' => 'line',
                        'metric'     => 'working_time',
                        'group_by'   => 'day',
                        'time_range' => $timeRange,
                    ];
                } else {
                    // Priority 4: activity_distribution (last resort)
                    $specs[] = [
                        'chart_type' => 'bar',
                        'metric'     => 'activity_distribution',
                        'group_by'   => 'employee',
                        'time_range' => $timeRange,
                    ];
                }
            }
        }

        // ── Ultimate fallback ────────────────────────────────────────────────
        if (empty($specs)) {
            $specs[] = [
                'chart_type' => 'bar',
                'metric'     => 'focus_score',
                'group_by'   => 'employee',
                'time_range' => $timeRange,
            ];
        }

        // Deduplicate by metric+group_by key, limit to 2
        $unique = [];
        $seen   = [];
        foreach ($specs as $s) {
            $key = $s['metric'] . '_' . $s['group_by'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $s;
            }
        }

        return array_slice($unique, 0, 2);
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
