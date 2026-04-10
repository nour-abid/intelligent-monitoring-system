<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate enforced by EnsureAdmin middleware
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'              => ['required', 'string', 'min:8'],
            'role'                  => ['required', Rule::in(['admin', 'superviseur', 'viewer'])],
            'is_active'             => ['boolean'],
            'supervisor_id'         => ['nullable', 'integer', 'exists:users,id'],
            'surveillance_identity' => ['nullable', 'string', 'max:100', Rule::unique('users', 'surveillance_identity')],
            'attendance_identity'   => ['nullable', 'string', 'max:100', Rule::unique('users', 'attendance_identity')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Skip further checks if supervisor_id already failed basic validation.
            if ($validator->errors()->has('supervisor_id')) {
                return;
            }

            $supId = $this->input('supervisor_id');

            if ($supId === null) {
                return;
            }

            $supervisor = User::find((int) $supId);

            if ($supervisor && $supervisor->role !== 'superviseur') {
                $validator->errors()->add(
                    'supervisor_id',
                    'The assigned supervisor must have the superviseur role.'
                );
            }
        });
    }
}
