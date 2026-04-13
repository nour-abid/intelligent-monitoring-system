<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureInternalToken
 *
 * Guards internal machine-to-machine endpoints (e.g. the Python surveillance
 * pipeline clip-metadata ingestion route) with a shared secret token.
 *
 * The caller must supply the header:
 *   X-Internal-Token: <value of SURVEILLANCE_INTERNAL_TOKEN in .env>
 *
 * Returns 401 when the header is absent or blank, 403 when the value
 * does not match.  A constant-time comparison is used to prevent
 * timing-based token enumeration attacks.
 */
class EnsureInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('replay.internal_token');

        // Refuse to operate when the operator has not configured a token.
        if ($expected === '') {
            return response()->json(
                ['message' => 'Internal endpoint not configured.'],
                503,
            );
        }

        $provided = (string) $request->header('X-Internal-Token', '');

        if ($provided === '') {
            return response()->json(['message' => 'Missing internal token.'], 401);
        }

        if (!hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid internal token.'], 403);
        }

        return $next($request);
    }
}
