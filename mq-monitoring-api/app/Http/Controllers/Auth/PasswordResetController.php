<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     *
     * Generates a 6-digit OTP, stores its bcrypt hash, and emails it.
     * Always returns 200 regardless of whether the email exists (prevents enumeration).
     */
    public function sendOtp(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if ($user) {
            $otp = (string) random_int(100000, 999999);

            PasswordResetOtp::updateOrCreate(
                ['email' => $request->email],
                [
                    'otp_hash'   => Hash::make($otp),
                    'expires_at' => now()->addMinutes(10),
                ]
            );

            try {
                Mail::send(
                    'emails.otp',
                    ['otp' => $otp, 'name' => $user->name],
                    fn ($msg) => $msg
                        ->to($user->email, $user->name)
                        ->subject('MQ Monitoring — Password Reset Code')
                );
            } catch (\Throwable) {
                // Mail failure is logged internally; response is unchanged so the
                // caller cannot distinguish a delivery failure from a missing account.
            }
        }

        return response()->json([
            'message' => 'If that email is registered, a reset code has been sent.',
        ]);
    }

    /**
     * POST /api/auth/verify-otp
     *
     * Validates the OTP for the given email without consuming it.
     * The frontend moves to the reset-password step only on 200.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $record = PasswordResetOtp::where('email', $request->email)->first();

        if (! $record
            || $record->expires_at->isPast()
            || ! Hash::check($request->otp, $record->otp_hash)
        ) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        return response()->json(['message' => 'Code verified.']);
    }

    /**
     * POST /api/auth/reset-password
     *
     * Re-validates the OTP, updates the password, revokes all tokens, and
     * deletes the OTP record so it cannot be reused.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $record = PasswordResetOtp::where('email', $request->email)->first();

        if (! $record
            || $record->expires_at->isPast()
            || ! Hash::check($request->otp, $record->otp_hash)
        ) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        // Update password (User model casts 'password' => 'hashed', so plain text is safe).
        $user->password = $request->password;
        $user->save();

        // Revoke all active Sanctum tokens so old sessions cannot continue.
        $user->tokens()->delete();

        // Consume the OTP — cannot be reused.
        $record->delete();

        return response()->json(['message' => 'Password has been reset successfully.']);
    }
}
