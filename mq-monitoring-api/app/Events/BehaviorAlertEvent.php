<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a monitored employee exceeds a behavior threshold.
 *
 * Implements ShouldBroadcastNow so it is delivered synchronously without
 * a queue driver — suitable for local development and the current setup.
 *
 * Each alert is broadcast on the private channel for every recipient user
 * (admin + the employee's supervising superviseur). Channel auth is handled
 * via routes/channels.php.
 */
class BehaviorAlertEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param string  $type              'inactive' | 'phone'
     * @param string  $identity          Surveillance identity name (e.g. "Amir")
     * @param float   $duration_minutes  Accumulated minutes detected in the window
     * @param int     $threshold_minutes Configured threshold that was crossed
     * @param string  $timestamp         ISO-8601 timestamp of the alert
     * @param int[]   $recipientUserIds  User IDs that should receive this alert
     */
    public function __construct(
        public readonly string $type,
        public readonly string $identity,
        public readonly float  $duration_minutes,
        public readonly int    $threshold_minutes,
        public readonly string $timestamp,
        private readonly array $recipientUserIds,
    ) {}

    /** @return PrivateChannel[] */
    public function broadcastOn(): array
    {
        return array_map(
            fn(int $id) => new PrivateChannel("alerts.{$id}"),
            $this->recipientUserIds,
        );
    }

    /**
     * Use a dot-prefixed event name on the frontend so Echo does not
     * automatically apply the channel prefix.
     */
    public function broadcastAs(): string
    {
        return 'behavior.alert';
    }

    /**
     * Expose recipient IDs so the StoreBehaviorAlert listener can persist
     * one history row per recipient without touching the evaluator logic.
     *
     * @return int[]
     */
    public function getRecipientUserIds(): array
    {
        return $this->recipientUserIds;
    }

    /**
     * Exclude recipientUserIds from the JSON payload — it is routing-only.
     */
    public function broadcastWith(): array
    {
        return [
            'type'              => $this->type,
            'identity'          => $this->identity,
            'duration_minutes'  => $this->duration_minutes,
            'threshold_minutes' => $this->threshold_minutes,
            'timestamp'         => $this->timestamp,
        ];
    }
}
