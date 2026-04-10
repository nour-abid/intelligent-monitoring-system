<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /api/users
     *
     * Returns every user ordered alphabetically by name.
     * Password hashes are never included.
     *
     * Response 200:
     *   { users: [ { id, name, email, role, supervisor_id, is_active }, … ] }
     */
    public function index(): JsonResponse
    {
        $users = User::orderBy('name')
            ->get()
            ->map(fn(User $u) => $this->userPayload($u))
            ->values();

        return response()->json(['users' => $users]);
    }

    /**
     * POST /api/users
     *
     * Creates a new user account.
     * All fields (except is_active, supervisor_id) are required.
     * is_active defaults to true when omitted.
     *
     * Response 201:
     *   { id, name, email, role, supervisor_id, is_active }
     *
     * Response 422:
     *   { message, errors }
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name'                  => $request->input('name'),
            'email'                 => $request->input('email'),
            'password'              => Hash::make($request->input('password')),
            'role'                  => $request->input('role'),
            'is_active'             => $request->boolean('is_active', true),
            'supervisor_id'         => $request->input('supervisor_id'),
            'surveillance_identity' => $request->filled('surveillance_identity')
                                           ? $request->input('surveillance_identity')
                                           : null,
            'attendance_identity'   => $request->filled('attendance_identity')
                                           ? $request->input('attendance_identity')
                                           : null,
        ]);

        return response()->json($this->userPayload($user), 201);
    }

    /**
     * PATCH /api/users/{id}
     *
     * Partially updates a user. Only fields present in the request body are
     * changed. Omitting a field leaves it untouched.
     *
     * Updatable fields:
     *   name, email, password (optional), role, is_active, supervisor_id
     *
     * supervisor_id may be set to null to remove the assignment.
     *
     * Response 200:
     *   { id, name, email, role, supervisor_id, is_active }
     *
     * Response 404: user not found
     * Response 422: validation error
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user   = User::findOrFail($id);
        $isSelf = $user->id === $request->user()->id;

        // ── Guard 1: admin may not deactivate themselves. ─────────────────────
        if ($isSelf && $request->has('is_active') && ! $request->boolean('is_active')) {
            return response()->json(
                ['message' => 'You cannot deactivate your own account.'],
                422
            );
        }

        // ── Guard 2: admin may not change their own role. ──────────────────────
        if ($isSelf && $request->has('role') && $request->input('role') !== 'admin') {
            return response()->json(
                ['message' => 'You cannot change your own role.'],
                422
            );
        }

        // ── Guard 3: last active admin must not be deactivated or demoted. ─────
        $wouldDeactivate = $request->has('is_active')
                        && ! $request->boolean('is_active')
                        && $user->is_active;

        $wouldDemote     = $request->has('role')
                        && $request->input('role') !== 'admin'
                        && $user->role === 'admin';

        if ($user->is_active && $user->role === 'admin' && ($wouldDeactivate || $wouldDemote)) {
            $activeAdminCount = User::where('role', 'admin')
                                    ->where('is_active', true)
                                    ->count();

            if ($activeAdminCount <= 1) {
                return response()->json(
                    ['message' => 'Cannot deactivate or demote the last active admin.'],
                    422
                );
            }
        }

        $data = [];

        if ($request->has('name'))          $data['name']          = $request->input('name');
        if ($request->has('email'))         $data['email']         = $request->input('email');
        if ($request->has('password'))      $data['password']      = Hash::make($request->input('password'));
        if ($request->has('role'))          $data['role']          = $request->input('role');
        if ($request->has('is_active'))     $data['is_active']     = $request->boolean('is_active');
        if ($request->has('supervisor_id')) $data['supervisor_id'] = $request->input('supervisor_id');

        if ($request->has('surveillance_identity')) {
            $data['surveillance_identity'] = $request->filled('surveillance_identity')
                ? $request->input('surveillance_identity')
                : null;
        }

        if ($request->has('attendance_identity')) {
            $data['attendance_identity'] = $request->filled('attendance_identity')
                ? $request->input('attendance_identity')
                : null;
        }

        $user->update($data);

        return response()->json($this->userPayload($user->fresh()));
    }

    /**
     * Minimal user payload — password hash is never exposed.
     */
    private function userPayload(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'role'                  => $user->role,
            'supervisor_id'         => $user->supervisor_id,
            'is_active'             => (bool) $user->is_active,
            'surveillance_identity' => $user->surveillance_identity,
            'attendance_identity'   => $user->attendance_identity,
        ];
    }
}
