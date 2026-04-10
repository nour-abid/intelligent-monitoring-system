<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Records the last-alerted timestamp per (identity_name, alert_type) pair.
 * Used to enforce the alert cooldown window.
 *
 * @property string              $identity_name
 * @property string              $alert_type
 * @property \Carbon\Carbon      $last_alerted_at
 */
class AlertCooldown extends Model
{
    protected $fillable = ['identity_name', 'alert_type', 'last_alerted_at'];

    protected function casts(): array
    {
        return [
            'last_alerted_at' => 'datetime',
        ];
    }
}
