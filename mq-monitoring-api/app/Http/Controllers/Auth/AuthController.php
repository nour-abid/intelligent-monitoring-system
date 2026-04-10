<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     *
     * Stateless credential check — no session is created.
     * Returns a Sanctum bearer token on success.
     *
     * Response 200:
     *   { token: string, user: { id, name, email, role, supervisor_id, is_active } }
     *
     * Response 401:
     *   { message: "Invalid credentials." }
     *
     * Response 403:
     *   { message: "Account is inactive." }
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * GET /api/auth/me  [auth:sanctum]
     *
     * Returns the current authenticated user's public profile.
     * Stable minimal payload — safe to use for Angular bootstrap.
     *
     * Response 200:
     *   { id, name, email, role, supervisor_id, is_active }
     *
     * Response 401 (no / invalid token):
     *   { message: "Unauthenticated." }
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json($this->userPayload($user));
    }

    /**
     * Canonical public user payload shared by login and me responses.
     */
    private function userPayload(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'role'                  => $user->role,
            'supervisor_id'         => $user->supervisor_id,
            'is_active'             => $user->is_active,
            'surveillance_identity' => $user->surveillance_identity,
        ];
    }
}
