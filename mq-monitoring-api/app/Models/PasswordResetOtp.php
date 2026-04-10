<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stores hashed OTP codes for the forgot/reset-password flow.
 *
 * @property string    $email
 * @property string    $otp_hash
 * @property \Carbon\Carbon $expires_at
 */
class PasswordResetOtp extends Model
{
    protected $fillable = ['email', 'otp_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
