<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters for GET /api/monitoring/surveillance/overview.
 */
class OverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start'               => ['sometimes', 'nullable', 'date'],
            'end'                 => ['sometimes', 'nullable', 'date', 'after_or_equal:start'],
            'include_unknown'     => ['sometimes', 'boolean'],
            'include_triggers'    => ['sometimes', 'array'],
            'include_triggers.*'  => ['string', 'in:activity_change,track_lost,session_end'],
        ];
    }

    public function messages(): array
    {
        return [
            'end.after_or_equal' => 'The end date must be on or after the start date.',
            'include_triggers.*.in' => 'Each trigger must be one of: activity_change, track_lost, session_end.',
        ];
    }
}
