<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the clip metadata payload POSTed by the Python surveillance
 * pipeline to POST /api/internal/clips.
 *
 * Required fields match the minimum metadata contract agreed with the
 * Python ClipManager.  All optional fields are accepted when present
 * but never required so that older pipeline versions remain compatible.
 */
class StoreClipMetadataRequest extends FormRequest
{
    /**
     * The endpoint is guarded by EnsureInternalToken, not by user authentication,
     * so there is no user() to authorise against.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Required ──────────────────────────────────────────────────
            'camera_id'        => ['required', 'string', 'max:120'],
            'identity_name'    => ['required', 'string', 'max:120'],
            'event_type'       => ['required', 'string', 'max:80'],
            'started_at'       => ['required', 'date'],
            'ended_at'         => ['required', 'date', 'after_or_equal:started_at'],
            'duration_sec'     => ['required', 'numeric', 'min:0'],
            'file_path'        => ['required', 'string', 'max:1024'],

            // ── Optional ──────────────────────────────────────────────────
            'file_name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'file_size_bytes'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'mime_type'        => ['sometimes', 'nullable', 'string', 'max:80'],
            'pre_buffer_sec'   => ['sometimes', 'nullable', 'integer', 'min:0'],
            'post_buffer_sec'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'clip_status'      => ['sometimes', 'nullable', 'string', 'in:pending,ready,failed,expired'],
        ];
    }

    public function messages(): array
    {
        return [
            'ended_at.after_or_equal' => 'ended_at must not be before started_at.',
            'clip_status.in'          => 'clip_status must be one of: pending, ready, failed, expired.',
        ];
    }
}
