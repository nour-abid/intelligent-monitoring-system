<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted record of a fired behavior alert.
 *
 * One row is written per recipient user when AlertEvaluationService
 * broadcasts a BehaviorAlertEvent, via the StoreBehaviorAlert listener.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $identity_name
 * @property string      $alert_type
 * @property float       $duration_minutes
 * @property int         $threshold_minutes
 * @property \Carbon\Carbon $fired_at
 */
class BehaviorAlert extends Model
{
    protected $fillable = [
        'user_id',
        'identity_name',
        'alert_type',
        'duration_minutes',
        'threshold_minutes',
        'fired_at',
    ];

    protected function casts(): array
    {
        return [
            'fired_at'          => 'datetime',
            'duration_minutes'  => 'float',
            'threshold_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
