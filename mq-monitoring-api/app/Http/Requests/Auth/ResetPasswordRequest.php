<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'otp'      => ['required', 'string', 'digits:6'],
            // 'confirmed' checks that password_confirmation field matches.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
