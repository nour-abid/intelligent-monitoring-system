<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\OverviewRequest;
use App\Http\Requests\Monitoring\TimelineRequest;
use App\Models\User;
use App\Services\Export\OverviewExportService;
use App\Services\Export\PersonExportService;
use App\Services\Monitoring\EmployeeHighlightsService;
use App\Services\Monitoring\SurveillanceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ExportController
 *
 * Generates professional Excel (XLSX) workbooks for download.
 *
 * Endpoints:
 *   GET /api/monitoring/surveillance/export/overview
 *       → Multi-sheet: Overview + Rankings + one sheet per accessible employee.
 *       → Admin and superviseur only (scope-gated; viewers get 403).
 *
 *   GET /api/monitoring/surveillance/identities/{identityName}/export/xlsx
 *       → Multi-sheet: Summary + Daily Breakdown + Activity Timeline + Alerts.
 *       → Any authenticated role (RBAC scope enforced per existing pattern).
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly SurveillanceAnalyticsService $analytics,
        private readonly EmployeeHighlightsService $highlights,
        private readonly OverviewExportService $overviewExport,
        private readonly PersonExportService $personExport,
    ) {}

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/export/overview
    // -----------------------------------------------------------------------

    /**
     * Admin/superviseur workbook: Overview + Rankings + per-employee sheets.
     *
     * Query params:
     *   start            (required) ISO datetime
     *   end              (required) ISO datetime
     *   include_unknown  (optional, bool, default false)
     */
    public function overview(OverviewRequest $request): StreamedResponse|JsonResponse
    {
        // Viewers have no access to the overview export
        /** @var User $user */
        $user = $request->user();
        if ($user->role === 'viewer') {
            return response()->json(['message' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $start   = $request->validated('start');
        $end     = $request->validated('end');
        $inclUnk = $this->asBool($request->validated('include_unknown'), false);
        $scope   = $this->resolveScope($request);

        try {
            $overviewData = $this->analytics->overview(
                start:             $start,
                end:               $end,
                includeUnknown:    $inclUnk,
                includeTriggers:   [],
                allowedIdentities: $scope,
            );

            $identitiesData = $this->analytics->identities(
                start:             $start,
                end:               $end,
                includeUnknown:    false,
                includeTriggers:   [],
                identity:          null,
                allowedIdentities: $scope,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $spreadsheet = $this->overviewExport->build(
            start:      $start,
            end:        $end,
            overview:   $overviewData,
            identities: $identitiesData['identities'],
        );

        $timestamp = now()->format('YmdHis');
        $filename  = "overview_report_{$timestamp}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    // -----------------------------------------------------------------------
    // GET /api/monitoring/surveillance/identities/{identityName}/export/xlsx
    // -----------------------------------------------------------------------

    /**
     * Personal workbook: Summary + Daily Breakdown + Activity Timeline + Alerts.
     *
     * Route param:
     *   identityName  (string)
     *
     * Query params:
     *   start         (optional) ISO datetime
     *   end           (optional) ISO datetime
     */
    public function person(TimelineRequest $request, string $identityName): StreamedResponse|JsonResponse
    {
        if ($identityName === '' || strlen($identityName) > 120) {
            return response()->json(
                ['message' => 'Invalid identity name.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $scope = $this->resolveScope($request);
        if ($scope !== null && ! in_array($identityName, $scope, true)) {
            return response()->json(['message' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $start = $request->validated('start');
        $end   = $request->validated('end');

        try {
            $summary    = $this->analytics->identitySummary(
                identityName: $identityName,
                start:        $start,
                end:          $end,
            );
            $daily      = $this->analytics->identityDaily(
                identityName: $identityName,
                start:        $start,
                end:          $end,
            );
            $timeline   = $this->analytics->timeline(
                identityName:    $identityName,
                start:           $start,
                end:             $end,
                includeTriggers: [],
            );
            $highlights = $this->highlights->highlights(
                identityName: $identityName,
                start:        $start,
                end:          $end,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $spreadsheet = $this->personExport->build(
            identityName: $identityName,
            start:        $start,
            end:          $end,
            summary:      array_merge($summary, ['segment_count' => count($timeline['segments'])]),
            daily:        $daily['days'],
            segments:     $timeline['segments'],
            highlights:   $highlights['highlights'],
        );

        $safeIdentity = preg_replace('/[^A-Za-z0-9_-]/', '_', $identityName);
        $timestamp    = now()->format('YmdHis');
        $filename     = "{$safeIdentity}_report_{$timestamp}.xlsx";

        return $this->streamXlsx($spreadsheet, $filename);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** Stream a Spreadsheet object as a downloadable XLSX response. */
    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $writer = new XlsxWriter($spreadsheet);
        $writer->setIncludeCharts(true);

        return response()->streamDownload(
            function () use ($writer): void {
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                'Pragma'              => 'no-cache',
                'Expires'             => '0',
            ]
        );
    }

    /**
     * Resolve the set of identity names the authenticated user may see.
     * Mirrors the same logic in SurveillanceAnalyticsController.
     *
     * null  → admin  (unrestricted)
     * array → scoped (superviseur = assigned; viewer = self-only / empty)
     */
    private function resolveScope(Request $request): ?array
    {
        /** @var User $user */
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

        // viewer — self only
        $identity = $user->surveillance_identity;
        if ($identity === null || $identity === '') {
            return [];
        }

        return [$identity];
    }

    /** Safely coerce a mixed validated value to bool. */
    private function asBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
