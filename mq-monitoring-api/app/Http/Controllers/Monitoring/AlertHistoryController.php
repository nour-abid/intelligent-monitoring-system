<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\BehaviorAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AlertHistoryController
 *
 * Serves persisted behavior alert history for the authenticated user.
 * Used by the Angular frontend to populate the bell-dropdown on session start,
 * so notifications survive page reloads.
 *
 * Route: GET /api/alerts  [auth:sanctum]
 */
class AlertHistoryController extends Controller
{
    private const MAX_HISTORY = 50;

    /**
     * GET /api/alerts
     *
     * Returns the most recent persisted alerts for the authenticated user,
     * ordered newest-first.
     *
     * Response 200:
     *   {
     *     "alerts": [
     *       {
     *         "id":                <int>,
     *         "identity_name":     <string>,
     *         "alert_type":        <string>,
     *         "duration_minutes":  <float>,
     *         "threshold_minutes": <int>,
     *         "fired_at":          <ISO-8601 string>
     *       },
     *       ...
     *     ]
     *   }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $alerts = BehaviorAlert::where('user_id', $user->id)
            ->orderBy('fired_at', 'desc')
            ->limit(self::MAX_HISTORY)
            ->get(['id', 'identity_name', 'alert_type', 'duration_minutes', 'threshold_minutes', 'fired_at'])
            ->map(fn (BehaviorAlert $a) => [
                'id'                => $a->id,
                'identity_name'     => $a->identity_name,
                'alert_type'        => $a->alert_type,
                'duration_minutes'  => $a->duration_minutes,
                'threshold_minutes' => $a->threshold_minutes,
                'fired_at'          => $a->fired_at->toIso8601String(),
            ]);

        return response()->json(['alerts' => $alerts]);
    }
}
