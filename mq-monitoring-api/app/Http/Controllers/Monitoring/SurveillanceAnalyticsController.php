<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\IdentitiesRequest;
use App\Http\Requests\Monitoring\OverviewRequest;
use App\Http\Requests\Monitoring\TimelineRequest;
use App\Models\User;
use App\Services\Monitoring\SurveillanceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * SurveillanceAnalyticsController
 *
 * Exposes read-only dashboard endpoints backed by the shared Python-written
 * SQLite surveillance_events database.  All write operations remain in the
 * Python surveillance runtime — this controller never modifies data.
 *
 * Routes (see routes/api.php):
 *   GET /api/monitoring/surveillance/overview
 *   GET /api/monitoring/surveillance/identities
 *   GET /api/monitoring/surveillance/identities/{identityName}/timeline
 */
class SurveillanceAnalyticsController extends Controller
{
    public function __construct(
        private readonly SurveillanceAnalyticsService $analytics,
    ) {}

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/overview
    // -----------------------------------------------------------------------

    /**
     * Global activity breakdown for a time window.
     *
     * Query params:
     *   start             (required) ISO datetime, e.g. 2026-03-24T00:00:00
     *   end               (required) ISO datetime, after or equal to start
     *   include_unknown   (optional, bool, default true)
     *   include_triggers  (optional, array of strings)
     */
    public function overview(OverviewRequest $request): JsonResponse
    {
        $includeUnknown  = $this->asBool($request->validated('include_unknown'), default: true);
        $includeTriggers = (array) ($request->validated('include_triggers') ?? []);
        $scope           = $this->resolveScope($request);

        try {
            $data = $this->analytics->overview(
                start:             $request->validated('start'),
                end:               $request->validated('end'),
                includeUnknown:    $includeUnknown,
                includeTriggers:   $includeTriggers,
                allowedIdentities: $scope,
            );
        } catch (RuntimeException $e) {
            return $this->dbNotReady($e);
        }

        $meta = [
            'start'            => $request->validated('start'),
            'end'              => $request->validated('end'),
            'include_unknown'  => $includeUnknown,
            'include_triggers' => $includeTriggers,
        ];

        return response()->json(['meta' => $meta] + $data);
    }

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/identities
    // -----------------------------------------------------------------------

    /**
     * Per-identity activity breakdown for a time window.
     *
     * Query params:
     *   start             (required)
     *   end               (required)
     *   include_unknown   (optional, bool, default false)
     *   include_triggers  (optional, array)
     *   identity          (optional, string — restrict to one name)
     */
    public function identities(IdentitiesRequest $request): JsonResponse
    {
        $includeUnknown  = $this->asBool($request->validated('include_unknown'), default: false);
        $includeTriggers = (array) ($request->validated('include_triggers') ?? []);
        $identity        = $request->validated('identity');
        $scope           = $this->resolveScope($request);

        try {
            $data = $this->analytics->identities(
                start:             $request->validated('start'),
                end:               $request->validated('end'),
                includeUnknown:    $includeUnknown,
                includeTriggers:   $includeTriggers,
                identity:          $identity,
                allowedIdentities: $scope,
            );
        } catch (RuntimeException $e) {
            return $this->dbNotReady($e);
        }

        $meta = [
            'start'            => $request->validated('start'),
            'end'              => $request->validated('end'),
            'include_unknown'  => $includeUnknown,
            'include_triggers' => $includeTriggers,
            'identity_filter'  => $identity,
        ];

        return response()->json(['meta' => $meta] + $data);
    }

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/identities/{identityName}/timeline
    // -----------------------------------------------------------------------

    /**
     * Ordered activity segments for one identity.
     *
     * Route param:
     *   identityName      (string, URL-encoded if necessary)
     *
     * Query params:
     *   start             (optional)
     *   end               (optional)
     *   include_triggers  (optional, array)
     */
    public function timeline(TimelineRequest $request, string $identityName): JsonResponse
    {
        // Basic sanity check: identity names must be non-empty printable strings.
        if ($identityName === '' || strlen($identityName) > 120) {
            return response()->json(
                ['message' => 'Invalid identity name.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Role-based access gate: superviseur sees assigned employees + self;
        // viewer sees self only; admin is unrestricted (scope = null).
        $scope = $this->resolveScope($request);
        if ($scope !== null && ! in_array($identityName, $scope, true)) {
            return response()->json(
                ['message' => 'Access denied.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $includeTriggers = (array) ($request->validated('include_triggers') ?? []);

        try {
            $data = $this->analytics->timeline(
                identityName:    $identityName,
                start:           $request->validated('start'),
                end:             $request->validated('end'),
                includeTriggers: $includeTriggers,
            );
        } catch (RuntimeException $e) {
            return $this->dbNotReady($e);
        }

        $meta = [
            'start'            => $request->validated('start'),
            'end'              => $request->validated('end'),
            'include_triggers' => $includeTriggers,
        ];

        return response()->json(['meta' => $meta] + $data);
    }

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/identities/{identityName}/export/csv
    // -----------------------------------------------------------------------

    /**
     * Export timeline segments for one identity as CSV.
     *
     * Route params:
     *   identityName      (string)
     *
     * Query params:
     *   start             (optional)
     *   end               (optional)
     *   include_triggers  (optional, array)
     *
     * Returns CSV attachment with columns:
     *   activity, timestamp_start, timestamp_end, duration_sec
     */
    public function exportCsv(TimelineRequest $request, string $identityName)
    {
        // Basic sanity check: identity names must be non-empty printable strings.
        if ($identityName === '' || strlen($identityName) > 120) {
            return response()->json(
                ['message' => 'Invalid identity name.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Role-based access gate: same as timeline().
        $scope = $this->resolveScope($request);
        if ($scope !== null && ! in_array($identityName, $scope, true)) {
            return response()->json(
                ['message' => 'Access denied.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $includeTriggers = (array) ($request->validated('include_triggers') ?? []);

        try {
            $data = $this->analytics->timeline(
                identityName:    $identityName,
                start:           $request->validated('start'),
                end:             $request->validated('end'),
                includeTriggers: $includeTriggers,
            );
        } catch (RuntimeException $e) {
            return $this->dbNotReady($e);
        }

        // Convert segments to CSV
        $segments = $data['segments'] ?? [];
        $csv = $this->segmentsToCSV($segments, $identityName);

        // Generate sensible filename: identity + timestamp
        $timestamp = now()->format('YmdHis');
        $filename  = "{$identityName}_report_{$timestamp}.csv";

        // Return CSV as downloadable attachment
        return response($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Convert segment array to CSV string.
     *
     * @param  array  $segments  Array of segment data from timeline service
     * @param  string $identity  Identity name for the report
     * @return string            CSV-formatted content
     */
    private function segmentsToCSV(array $segments, string $identity): string
    {
        $output = fopen('php://memory', 'r+');

        // CSV header (semicolon-delimited for Excel compatibility)
        fputcsv($output, [
            'Identity',
            'Activity',
            'Start Time',
            'End Time',
            'Duration (seconds)',
        ], ';');

        // CSV rows (semicolon-delimited for Excel compatibility)
        foreach ($segments as $seg) {
            fputcsv($output, [
                $identity,
                $seg['activity'] ?? '',
                $seg['timestamp_start'] ?? '',
                $seg['timestamp_end'] ?? '',
                number_format($seg['duration_sec'] ?? 0, 2),
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the set of identity names the authenticated user may see.
     *
     * Returns null  → no restriction (admin: all data visible).
     * Returns array → only the returned names are accessible.
     *                 An empty array means zero identities are accessible.
     *
     * Roles (from access-model-v1.md):
     *  admin       → null (unrestricted)
     *  superviseur → surveillance_identity of assigned employees (non-null/non-empty),
     *                plus the supervisor's own surveillance_identity if set
     *  viewer      → [user->surveillance_identity] if set, otherwise []
     *
     * Note: users.name is no longer used for scoping. The dedicated
     * surveillance_identity field provides the exact mapping to
     * surveillance_events.identity_name.
     */
    private function resolveScope(Request $request): ?array
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->role === 'admin') {
            return null;
        }

        if ($user->role === 'superviseur') {
            // Collect surveillance identities of all assigned employees
            // that have a non-null, non-empty mapping.
            $employeeIdentities = User::where('supervisor_id', $user->id)
                ->whereNotNull('surveillance_identity')
                ->where('surveillance_identity', '<>', '')
                ->pluck('surveillance_identity')
                ->all();

            // Include the supervisor's own identity if mapped.
            $ownIdentity = $user->surveillance_identity;
            if ($ownIdentity !== null && $ownIdentity !== '') {
                $employeeIdentities[] = $ownIdentity;
            }

            return array_values(array_unique($employeeIdentities));
        }

        // viewer — self only; empty array if no surveillance_identity is set.
        $identity = $user->surveillance_identity;
        if ($identity === null || $identity === '') {
            return [];
        }

        return [$identity];
    }

    /**
     * Safely coerce a validated value (string|bool|null) to bool with a default.
     * Laravel's 'boolean' rule casts "1"/"0"/"true"/"false" but the cast arrives
     * as a mixed value from validated(); this ensures a definitive bool.
     */
    private function asBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Return a clean 503 JSON response when the surveillance DB is unavailable.
     */
    private function dbNotReady(RuntimeException $e): JsonResponse
    {
        return response()->json(
            ['message' => $e->getMessage()],
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }
}
