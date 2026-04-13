<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // API routes are auto-prefixed with /api.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        // Broadcast auth route uses Sanctum bearer-token authentication.
        attributes: ['middleware' => ['auth:sanctum']],
    )
    ->withSchedule(function (Schedule $schedule) {
        // Evaluate behavior alert thresholds every 30 seconds (near-realtime).
        // Start the scheduler in development with: php artisan schedule:work
        // In production, run: php artisan schedule:run in a * * * * * cron job.
        $schedule->command('alerts:evaluate')->everyThirtySeconds();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // This is a pure JSON API — there is no web login page.
        // For API requests, throw AuthenticationException instead of redirecting.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson()) {
                throw new AuthenticationException('Unauthenticated.');
            }
            // Fallback for non-API routes (shouldn't occur)
            return redirect('/');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always respond with a JSON 401 for unauthenticated requests.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })
    ->create();
