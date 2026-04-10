<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Monitoring\AnalyticsViewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AnalyticsViewController
 *
 * Read-only endpoints backed by five analytics views in the analytics
 * PostgreSQL schema.  Three views are TimescaleDB continuous aggregates
 * over surveillance events; two are materialized views over behavior alerts.
 *
 * Routes (see routes/api.php):
 *   GET /api/analytics/surveillance/hourly-activity
 *   GET /api/analytics/surveillance/daily-inactivity
 *   GET /api/analytics/surveillance/daily-activity
 *   GET /api/analytics/alerts/by-type
 *   GET /api/analytics/alerts/by-employee
 *
 * Common query params (all optional):
 *   start   'YYYY-MM-DD' — lower bound (inclusive, full day)
 *   end     'YYYY-MM-DD' — upper bound (inclusive, full day), must be >= start
 *
 * Role scoping (applied to views that carry identity_name):
 *   admin       → unrestricted
 *   superviseur → assigned employees + own surveillance_identity
 *   viewer      → own surveillance_identity only
 *
 * alerts/by-type has no identity_name column and is returned unscoped for
 * all authenticated users.
 */
class AnalyticsViewController extends Controller
{
    public function __construct(
        private readonly AnalyticsViewsService $analytics,
    ) {}

    // -----------------------------------------------------------------------
    // GET /api/analytics/surveillance/hourly-activity
    // -----------------------------------------------------------------------

    public function hourlyActivity(Request $request): JsonResponse
    {
        ['start' => $start, 'end' => $end] = $this->validateDates($request);

        $data = $this->analytics->hourlyActivity($start, $end, $this->resolveScope($request));

        return response()->json(['meta' => compact('start', 'end'), 'data' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /api/analytics/surveillance/daily-inactivity
    // -----------------------------------------------------------------------

    public function dailyInactivity(Request $request): JsonResponse
    {
        ['start' => $start, 'end' => $end] = $this->validateDates($request);

        $data = $this->analytics->dailyInactivity($start, $end, $this->resolveScope($request));

        return response()->json(['meta' => compact('start', 'end'), 'data' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /api/analytics/surveillance/daily-activity
    // -----------------------------------------------------------------------

    public function dailyActivity(Request $request): JsonResponse
    {
        ['start' => $start, 'end' => $end] = $this->validateDates($request);

        $data = $this->analytics->dailyActivity($start, $end, $this->resolveScope($request));

        return response()->json(['meta' => compact('start', 'end'), 'data' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /api/analytics/alerts/by-type
    // -----------------------------------------------------------------------

    public function alertsByType(Request $request): JsonResponse
    {
        ['start' => $start, 'end' => $end] = $this->validateDates($request);

        $data = $this->analytics->alertsByType($start, $end);

        return response()->json(['meta' => compact('start', 'end'), 'data' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /api/analytics/alerts/by-employee
    // -----------------------------------------------------------------------

    public function alertsByEmployee(Request $request): JsonResponse
    {
        ['start' => $start, 'end' => $end] = $this->validateDates($request);

        $data = $this->analytics->alertsByEmployee($start, $end, $this->resolveScope($request));

        return response()->json(['meta' => compact('start', 'end'), 'data' => $data]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Validate and extract date-range query params.
     * Both are optional; end must be >= start when both are present.
     */
    private function validateDates(Request $request): array
    {
        $validated = $request->validate([
            'start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        return [
            'start' => $validated['start'] ?? null,
            'end'   => $validated['end']   ?? null,
        ];
    }

    /**
     * Resolve the identity names the authenticated user may see.
     *
     * Returns null  → no restriction (admin).
     * Returns array → allowed identity names; [] means zero access.
     *
     * Roles (from access-model-v1.md):
     *   admin       → null (unrestricted)
     *   superviseur → surveillance_identity of assigned employees + own identity
     *   viewer      → [own surveillance_identity], or [] if unset
     */
    private function resolveScope(Request $request): ?array
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->role === 'admin') {
            return null;
        }

        if ($user->role === 'superviseur') {
            $employeeIdentities = User::where('supervisor_id', $user->id)
                ->whereNotNull('surveillance_identity')
                ->where('surveillance_identity', '<>', '')
                ->pluck('surveillance_identity')
                ->all();

            $ownIdentity = $user->surveillance_identity;
            if ($ownIdentity !== null && $ownIdentity !== '') {
                $employeeIdentities[] = $ownIdentity;
            }

            return array_values(array_unique($employeeIdentities));
        }

        // viewer — self only.
        $own = $user->surveillance_identity;
        return ($own !== null && $own !== '') ? [$own] : [];
    }
}
