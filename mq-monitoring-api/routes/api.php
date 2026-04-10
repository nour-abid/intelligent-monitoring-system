<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Monitoring\AlertHistoryController;
use App\Http\Controllers\Monitoring\AnalyticsViewController;
use App\Http\Controllers\Monitoring\DashboardSummaryController;
use App\Http\Controllers\Monitoring\EmployeeHighlightsController;
use App\Http\Controllers\Monitoring\ReplayController;
use App\Http\Controllers\Monitoring\SurveillanceAnalyticsController;
use App\Http\Controllers\Users\UserController;
use App\Http\Middleware\EnsureAdmin;
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

        // GET /api/monitoring/surveillance/identities/{identityName}/export/csv
        // Export timeline segments for one identity as downloadable CSV.
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
    });

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
