<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Each authenticated user subscribes to their own private channel:
|   private-alerts.{userId}
|
| The auth callback returns true only when the authenticated user's ID
| matches the channel's userId segment — preventing cross-user access.
|
| Admin and superviseur users are targeted by the AlertEvaluationService
| which includes their IDs in the recipientUserIds list at broadcast time.
|
*/

Broadcast::channel('alerts.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});
