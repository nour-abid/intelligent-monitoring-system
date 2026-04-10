<?php

namespace App\Listeners;

use App\Events\BehaviorAlertEvent;
use App\Models\BehaviorAlert;

/**
 * Persists each fired BehaviorAlertEvent as historical rows in behavior_alerts.
 *
 * One row is created per recipient user so the GET /api/alerts endpoint
 * can filter by user_id without joins.
 *
 * This listener is the ONLY place alert history is written.
 * AlertEvaluationService remains untouched.
 */
class StoreBehaviorAlert
{
    public function handle(BehaviorAlertEvent $event): void
    {
        $rows = [];

        foreach ($event->getRecipientUserIds() as $userId) {
            $rows[] = [
                'user_id'           => $userId,
                'identity_name'     => $event->identity,
                'alert_type'        => $event->type,
                'duration_minutes'  => $event->duration_minutes,
                'threshold_minutes' => $event->threshold_minutes,
                'fired_at'          => $event->timestamp,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }

        if (! empty($rows)) {
            BehaviorAlert::insert($rows);
        }
    }
}
