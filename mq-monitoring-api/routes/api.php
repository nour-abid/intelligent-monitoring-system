<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Face\ValidateFrameController;
use App\Http\Controllers\Monitoring\AlertHistoryController;
use App\Http\Controllers\Monitoring\AnalyticsViewController;
use App\Http\Controllers\Monitoring\DashboardSummaryController;
use App\Http\Controllers\Monitoring\EmployeeHighlightsController;
use App\Http\Controllers\Monitoring\ClipIngestionController;
use App\Http\Controllers\Monitoring\ExportController;
use App\Http\Controllers\Monitoring\ReplayController;
use App\Http\Controllers\Monitoring\SurveillanceAnalyticsController;
use App\Http\Controllers\Users\IdentityPhotoController;
use App\Http\Controllers\Users\UserController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureInternalToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Public — no bearer token required.
|
*/

Route::prefix('auth')->name('auth.')->group(function () {

    // POST /api/auth/login — exchange credentials for a Sanctum bearer token.
    Route::post('login', [AuthController::class, 'login'])
        ->name('login');

    // GET /api/auth/me — return the authenticated user's profile.
    Route::get('me', [AuthController::class, 'me'])
        ->middleware('auth:sanctum')
        ->name('me');

    // ── Forgot / reset password (OTP-based) ──────────────────────────────

    // POST /api/auth/forgot-password — generate and email a 6-digit OTP.
    Route::post('forgot-password', [PasswordResetController::class, 'sendOtp'])
        ->name('forgot-password')
        ->middleware('throttle:5,1');

    // POST /api/auth/verify-otp — confirm the OTP before showing reset form.
    Route::post('verify-otp', [PasswordResetController::class, 'verifyOtp'])
        ->name('verify-otp')
        ->middleware('throttle:10,1');

    // POST /api/auth/reset-password — re-validate OTP and update password.
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword'])
        ->name('reset-password')
        ->middleware('throttle:5,1');
});

/*
|--------------------------------------------------------------------------
| Surveillance Analytics Routes
|--------------------------------------------------------------------------
|
| All routes below are prefixed with /api (applied by bootstrap/app.php).
| They consume the shared SQLite surveillance_events database written by
| the Python surveillance runtime — no write operations occur here.
|
| Protected with auth:sanctum — a valid bearer token is required.
| Response shapes are unchanged from V1; only the access gate is new.
|
*/

Route::prefix('monitoring/surveillance')
    ->name('monitoring.surveillance.')
    ->middleware('auth:sanctum')
    ->group(function () {

        // GET /api/monitoring/surveillance/overview
        Route::get('overview', [SurveillanceAnalyticsController::class, 'overview'])
            ->name('overview');

        // GET /api/monitoring/surveillance/identities
        Route::get('identities', [SurveillanceAnalyticsController::class, 'identities'])
            ->name('identities');

        // GET /api/monitoring/surveillance/identities/{identityName}/timeline
        // identityName: letters, digits, underscores, hyphens (matches enrolled names).
        Route::get('identities/{identityName}/timeline', [SurveillanceAnalyticsController::class, 'timeline'])
            ->name('identity.timeline')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/identities/{identityName}/summary
        // Aggregated personal summary: working_sec, phone_sec, inactive_sec, focus_score.
        Route::get('identities/{identityName}/summary', [SurveillanceAnalyticsController::class, 'personalSummary'])
            ->name('identity.summary')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/identities/{identityName}/daily
        // Per-day activity breakdown with backend-computed focus_score per day.
        Route::get('identities/{identityName}/daily', [SurveillanceAnalyticsController::class, 'personalDaily'])
            ->name('identity.daily')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/export/overview
        // Admin/superviseur: download a multi-sheet Excel workbook for the overview page.
        Route::get('export/overview', [ExportController::class, 'overview'])
            ->name('export.overview');

        // GET /api/monitoring/surveillance/identities/{identityName}/export/xlsx
        // Personal multi-sheet Excel workbook: Summary, Daily, Timeline, Alerts.
        Route::get('identities/{identityName}/export/xlsx', [ExportController::class, 'person'])
            ->name('identity.export.xlsx')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/identities/{identityName}/export/csv
        // Legacy CSV export — kept for compatibility.
        Route::get('identities/{identityName}/export/csv', [SurveillanceAnalyticsController::class, 'exportCsv'])
            ->name('identity.export.csv')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/identities/{identityName}/highlights
        // Return the top "moments forts" for a single employee in the selected range.
        Route::get('identities/{identityName}/highlights', [EmployeeHighlightsController::class, 'index'])
            ->name('identity.highlights')
            ->where('identityName', '[A-Za-z][A-Za-z0-9_\-]*');

        // GET /api/monitoring/surveillance/summary
        // Dashboard KPI + chart data sourced from behavior_alerts and surveillance_events.
        Route::get('summary', [DashboardSummaryController::class, 'index'])
            ->name('summary');

        // ── Alert replay (recorded-video mode) ────────────────────────────────

        // POST /api/monitoring/surveillance/alerts/{alertId}/replay/source
        // Admin-only: register or update the recorded-video metadata for an alert.
        // Source metadata tells the system which video file and timing to use
        // when generating a replay clip on demand.
        Route::post('alerts/{alertId}/replay/source', [ReplayController::class, 'registerSource'])
            ->name('alert.replay.source')
            ->middleware(EnsureAdmin::class)
            ->where('alertId', '[0-9]+');

        // GET /api/monitoring/surveillance/alerts/{alertId}/replay
        // Generate (or return cached) the replay clip and stream it as video/mp4.
        // Admin can replay any alert; other roles can only replay their own alerts.
        Route::get('alerts/{alertId}/replay', [ReplayController::class, 'stream'])
            ->name('alert.replay.stream')
            ->where('alertId', '[0-9]+');
    });

/*
|--------------------------------------------------------------------------
| User Management Routes
|--------------------------------------------------------------------------
|
| Admin-only endpoints for managing user accounts and supervisor
| assignments.  Both auth:sanctum and EnsureAdmin middleware are required.
|
*/

Route::prefix('users')
    ->name('users.')
    ->middleware(['auth:sanctum', EnsureAdmin::class])
    ->group(function () {

        // GET  /api/users   — list all users
        Route::get('/',  [UserController::class, 'index'])->name('index');

        // POST /api/users   — create a new user
        Route::post('/', [UserController::class, 'store'])->name('store');

        // PATCH /api/users/{id} — partial update (profile, role, is_active, supervisor)
        Route::patch('/{id}', [UserController::class, 'update'])
            ->name('update')
            ->where('id', '[0-9]+');

        // POST  /api/users/{userId}/photos   — upload one or many enrollment photos
        Route::post('/{userId}/photos', [IdentityPhotoController::class, 'store'])
            ->name('photos.store')
            ->where('userId', '[0-9]+');

        // GET   /api/users/{userId}/photos   — list enrollment photos for a user
        Route::get('/{userId}/photos', [IdentityPhotoController::class, 'index'])
            ->name('photos.index')
            ->where('userId', '[0-9]+');
    });

/*
|--------------------------------------------------------------------------
| Identity Photo Asset & Delete Routes
|--------------------------------------------------------------------------
|
| Scoped to individual photos — not nested under /users because a photo
| delete does not need to re-identify the parent user.
|
*/

Route::prefix('photos')
    ->name('photos.')
    ->middleware(['auth:sanctum', EnsureAdmin::class])
    ->group(function () {

        // DELETE /api/photos/{photoId} — hard-delete photo record + file on disk
        Route::delete('/{photoId}', [IdentityPhotoController::class, 'destroy'])
            ->name('destroy')
            ->where('photoId', '[0-9]+');

        // GET /api/photos/{photoId}/image — stream the image file through the API
        Route::get('/{photoId}/image', [IdentityPhotoController::class, 'image'])
            ->name('image')
            ->where('photoId', '[0-9]+');
    });

/*
|--------------------------------------------------------------------------
| Face / Frame Validation Routes
|--------------------------------------------------------------------------
|
| Lightweight real-time validation proxy used by the guided camera capture
| modal. Forwards a single webcam frame to the Python embedding service,
| which runs only the face detector (no embedding generated).
|
*/

// POST /api/face/validate-frame
Route::post('/face/validate-frame', ValidateFrameController::class)
    ->middleware(['auth:sanctum', EnsureAdmin::class])
    ->name('face.validate-frame');

/*
|--------------------------------------------------------------------------
| Alert History
|--------------------------------------------------------------------------
|
| Returns the authenticated user's recent persisted behavior alerts.
| Used by the Angular frontend to populate the bell-dropdown on load
| so history survives page reloads and new sessions.
|
*/

// GET /api/alerts — last 50 persisted alerts for the authenticated user.
Route::get('alerts', [AlertHistoryController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('alerts.index');

/*
|--------------------------------------------------------------------------
| Analytics Routes
|--------------------------------------------------------------------------
|
| Read-only endpoints over the five analytics views in the analytics schema.
| Three views are TimescaleDB continuous aggregates (surveillance-based);
| two are materialized views (alert-based).
|
| All endpoints support optional ?start=YYYY-MM-DD &end=YYYY-MM-DD filters.
| Identity-bearing views are additionally scoped by user role.
|
*/

Route::prefix('analytics')
    ->name('analytics.')
    ->middleware('auth:sanctum')
    ->group(function () {

        Route::prefix('surveillance')->name('surveillance.')->group(function () {

            // GET /api/analytics/surveillance/hourly-activity
            Route::get('hourly-activity', [AnalyticsViewController::class, 'hourlyActivity'])
                ->name('hourly-activity');

            // GET /api/analytics/surveillance/daily-inactivity
            Route::get('daily-inactivity', [AnalyticsViewController::class, 'dailyInactivity'])
                ->name('daily-inactivity');

            // GET /api/analytics/surveillance/daily-activity
            Route::get('daily-activity', [AnalyticsViewController::class, 'dailyActivity'])
                ->name('daily-activity');
        });

        Route::prefix('alerts')->name('alerts.')->group(function () {

            // GET /api/analytics/alerts/by-type
            Route::get('by-type', [AnalyticsViewController::class, 'alertsByType'])
                ->name('by-type');

            // GET /api/analytics/alerts/by-employee
            Route::get('by-employee', [AnalyticsViewController::class, 'alertsByEmployee'])
                ->name('by-employee');
        });
    });

/*
|--------------------------------------------------------------------------
| Internal Machine-to-Machine Routes
|--------------------------------------------------------------------------
|
| These endpoints are consumed by internal services (e.g. the Python
| surveillance pipeline) and are NOT exposed to browser clients.
|
| Protected by EnsureInternalToken (X-Internal-Token header) — no Sanctum
| session or user account is required or expected.
|
*/

Route::prefix('internal')
    ->name('internal.')
    ->middleware(EnsureInternalToken::class)
    ->group(function () {

        // POST /api/internal/clips
        // The Python surveillance pipeline calls this after saving a confirmed
        // alert clip to disk.  Laravel persists only the metadata in
        // surveillance.surveillance_clips — no video data is transmitted.
        Route::post('clips', [ClipIngestionController::class, 'store'])
            ->name('clips.store');
    });

/*
|--------------------------------------------------------------------------
| AI / Business Intelligence Routes
|--------------------------------------------------------------------------
|
| Gemini-backed endpoints for the chatbot assistant and AI report export.
| Both require a valid Sanctum bearer token.
| Rate-limited to protect free-tier API quota.
|
*/

Route::prefix('ai')
    ->name('ai.')
    ->middleware(['auth:sanctum', 'throttle:30,1'])
    ->group(function () {

        // POST /api/ai/chat
        // Conversational BI assistant grounded only in the data context sent
        // by the client. Maintains history for multi-turn conversations.
        Route::post('chat', [AiController::class, 'chat'])
            ->name('chat');

        // POST /api/ai/report
        // Generates a structured AI-formulated analytical report from
        // a serialised employee data snapshot.
        Route::post('report', [AiController::class, 'report'])
            ->name('report');

        // GET /api/ai/context?identity=...
        // Returns serialised surveillance data for the AI context
        // and the list of available identities for the selector.
        Route::get('context', [AiController::class, 'context'])
            ->name('context');
    });
