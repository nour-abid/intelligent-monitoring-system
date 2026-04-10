<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced by EnsureAdmin middleware
    }

    public function rules(): array
    {
        $userId = (int) $this->route('id');

        return [
            'name'                  => ['sometimes', 'string', 'max:255'],
            'email'                 => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
        //    lezemni narj3elha
            'password'              => ['sometimes', 'string', 'min:8'],
            'role'                  => ['sometimes', Rule::in(['admin', 'superviseur', 'viewer'])],
            'is_active'             => ['sometimes', 'boolean'],
            'supervisor_id'         => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'surveillance_identity' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('users', 'surveillance_identity')->ignore($userId)],
            'attendance_identity'   => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('users', 'attendance_identity')->ignore($userId)],
        ];
    }

    public function withValidator($validator): void
    {
        $userId = (int) $this->route('id');

        $validator->after(function ($validator) use ($userId) {
            // Only validate supervisor_id if it was explicitly sent.
            if (! $this->has('supervisor_id')) {
                return;
            }

            // Skip if basic validation already caught an error.
            if ($validator->errors()->has('supervisor_id')) {
                return;
            }

            $supId = $this->input('supervisor_id');

            if ($supId === null) {
                return; // Null removes the assignment — always valid.
            }

            $supId = (int) $supId;

            // A user cannot supervise themselves.
            if ($supId === $userId) {
                $validator->errors()->add(
                    'supervisor_id',
                    'A user cannot be their own supervisor.'
                );
                return;
            }

            // The referenced user must hold the superviseur role.
            $supervisor = User::find($supId);

            if ($supervisor && $supervisor->role !== 'superviseur') {
                $validator->errors()->add(
                    'supervisor_id',
                    'The assigned supervisor must have the superviseur role.'
                );
            }
        });
    }
}
