<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\EmployeeHighlightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * EmployeeHighlightsController
 *
 * Returns the top "moments forts" (highlight moments) for a single
 * employee within an optional date range.
 *
 * Route: GET /api/monitoring/surveillance/identities/{identityName}/highlights
 *
 * Query params:
 *   start  (optional) ISO-compatible date, e.g. 2026-03-01 — defaults to 7 days ago
 *   end    (optional) ISO-compatible date, e.g. 2026-03-30 — defaults to today
 *
 * The role-based access gate mirrors the one used in SurveillanceAnalyticsController:
 *   admin        → unrestricted
 *   superviseur  → assigned identities only
 *   viewer       → own identity only
 */
class EmployeeHighlightsController extends Controller
{
    public function __construct(
        private readonly EmployeeHighlightsService $service,
    ) {}

    /**
     * GET /api/monitoring/surveillance/identities/{identityName}/highlights
     */
    public function index(Request $request, string $identityName): JsonResponse
    {
        // ── Input validation ──────────────────────────────────────────────────
        if ($identityName === '' || strlen($identityName) > 120) {
            return response()->json(
                ['message' => 'Invalid identity name.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end'   => ['nullable', 'date', 'after_or_equal:start'],
        ]);

        // ── Role-based access gate ────────────────────────────────────────────
        /** @var \App\Models\User $user */
        $user  = $request->user();
        $scope = $this->resolveScope($user);

        if ($scope !== null && ! in_array($identityName, $scope, true)) {
            return response()->json(
                ['message' => 'Access denied.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // ── Compute and return ────────────────────────────────────────────────
        $result = $this->service->highlights(
            identityName: $identityName,
            start:        $validated['start'] ?? null,
            end:          $validated['end']   ?? null,
        );

        return response()->json($result);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the set of identity names the authenticated user may access.
     * Returns null for admin (unrestricted).
     *
     * @return string[]|null
     */
    private function resolveScope(\App\Models\User $user): ?array
    {
        if ($user->role === 'admin') {
            return null;
        }

        // Build the set: user's own surveillance_identity + any assigned employees.
        $scope = [];

        if ($user->surveillance_identity) {
            $scope[] = $user->surveillance_identity;
        }

        // superviseur sees all identities of users they supervise.
        if ($user->role === 'superviseur') {
            $supervised = \App\Models\User::where('supervisor_id', $user->id)
                ->whereNotNull('surveillance_identity')
                ->pluck('surveillance_identity')
                ->all();

            $scope = array_merge($scope, $supervised);
        }

        return array_values(array_unique($scope));
    }
}
