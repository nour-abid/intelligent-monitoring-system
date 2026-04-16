<?php

namespace App\Services\Monitoring;

use App\Events\BehaviorAlertEvent;
use App\Models\AlertCooldown;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AlertEvaluationService
 *
 * Queries the surveillance SQLite database for cumulative activity durations
 * within a rolling time window.  When a monitored identity exceeds the
 * configured threshold, and is not in cooldown, a BehaviorAlertEvent is
 * broadcast to the relevant admin and superviseur user channels.
 *
 * Supported alert types (V1):
 *   - 'inactive'     — employee accumulated >= inactive_threshold_minutes of
 *                      Inactive activity in the last inactive_threshold_minutes window.
 *   - 'phone'        — same logic for Using_Phone activity.
 *   - 'late_arrival' — first check-in of the day is after workday_start + tolerance.
 *   - 'early_leave'  — last observed presence in surveillance for the day is before
 *                      workday_end - tolerance, checked only after workday_end.
 *
 * Cooldown / dedup:
 *   The alert_cooldowns table stores the last-alerted timestamp per
 *   (identity_name, alert_type) pair.  A new alert is suppressed if
 *   last_alerted_at + cooldown_minutes > now().
 */
class AlertEvaluationService
{
    private const SURVEILLANCE_CONN = 'surveillance';
    private const ATTENDANCE_CONN   = 'attendance';
    private const TABLE             = 'surveillance_events';
    private const ATTENDANCE_TABLE  = 'attendance_events';

    private int    $inactiveThresholdSec;
    private int    $phoneThresholdSec;
    private int    $cooldownMinutes;
    private string $lateWorkdayStart;
    private int    $lateTolerance;
    private string $earlyLeaveWorkdayEnd;
    private int    $earlyLeaveTolerance;

    public function __construct()
    {
        // Seconds-based override takes priority (> 0) — useful for demos.
        // Falls back to the minute-based config × 60.
        $inactiveOverrideSec = (int) config('alerts.inactive_threshold_seconds', 0);
        $phoneOverrideSec    = (int) config('alerts.phone_threshold_seconds',    0);

        $this->inactiveThresholdSec = $inactiveOverrideSec > 0
            ? $inactiveOverrideSec
            : (int) config('alerts.inactive_threshold_minutes', 10) * 60;

        $this->phoneThresholdSec = $phoneOverrideSec > 0
            ? $phoneOverrideSec
            : (int) config('alerts.phone_threshold_minutes', 5) * 60;

        $this->cooldownMinutes          = (int)    config('alerts.cooldown_minutes', 30);
        $this->lateWorkdayStart         = (string) config('alerts.late_workday_start', '08:00');
        $this->lateTolerance            = (int)    config('alerts.late_tolerance_minutes', 15);
        $this->earlyLeaveWorkdayEnd     = (string) config('alerts.early_leave_workday_end', '18:00');
        $this->earlyLeaveTolerance      = (int)    config('alerts.early_leave_tolerance_minutes', 15);
    }

    /**
     * Run all threshold checks.
     *
     * @param  bool $forceEarlyLeave  Skip the "after workday_end" guard — useful for demos/testing.
     * @return int  Number of alerts broadcast.
     */
    public function evaluate(bool $forceEarlyLeave = false): int
    {
        $fired = 0;

        // Surveillance-based activity checks (PostgreSQL).
        $fired += $this->checkActivity('Inactive',    $this->inactiveThresholdSec, 'inactive');
        $fired += $this->checkActivity('Using_Phone', $this->phoneThresholdSec,    'phone');

        // Attendance-based checks — guarded independently inside checkLateArrival().
        $fired += $this->checkLateArrival();

        // Surveillance-based end-of-day check — guarded independently inside checkEarlyLeave().
        $fired += $this->checkEarlyLeave($forceEarlyLeave);

        return $fired;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * For each identity: sum activity seconds in the rolling window.
     * If the sum meets the threshold, fire an alert (subject to cooldown).
     *
     * @param string $activity      Surveillance activity label (e.g. 'Inactive')
     * @param int    $thresholdSec  Window size AND required total, in seconds
     * @param string $alertType     Alert type label ('inactive' | 'phone')
     */
    private function checkActivity(
        string $activity,
        int    $thresholdSec,
        string $alertType,
    ): int {
        $windowStart = now()->subSeconds($thresholdSec)->format('Y-m-d H:i:s');

        $rows = DB::connection(self::SURVEILLANCE_CONN)
            ->table(self::TABLE)
            ->select([
                'identity_name',
                DB::raw('SUM(duration_sec) AS total_sec'),
            ])
            ->where('activity', $activity)
            // Match events that OVERLAP the window: they ended after the window opened.
            // This catches heartbeat rows whose activity_start was before the window.
            ->where('timestamp_end', '>=', $windowStart)
            ->where('identity_name', '!=', 'Unknown')
            ->groupBy('identity_name')
            ->havingRaw('SUM(duration_sec) >= ?', [$thresholdSec])
            ->get();

        $fired = 0;

        foreach ($rows as $row) {
            $identity = (string) $row->identity_name;

            if ($this->isOnCooldown($identity, $alertType)) {
                Log::debug("[ALERT SKIPPED] type={$alertType} person={$identity} reason=cooldown_active");
                continue;
            }

            $recipientIds = $this->recipientIds($identity);

            if (empty($recipientIds)) {
                Log::debug("[ALERT SKIPPED] type={$alertType} person={$identity} reason=no_recipients");
                continue;
            }

            $durationMinutes = round((float) $row->total_sec / 60, 1);
            $thresholdMinutes = (int) ceil($thresholdSec / 60);

            try {
                broadcast(new BehaviorAlertEvent(
                    type:              $alertType,
                    identity:          $identity,
                    duration_minutes:  $durationMinutes,
                    threshold_minutes: $thresholdMinutes,
                    timestamp:         now()->toIso8601String(),
                    recipientUserIds:  $recipientIds,
                ));
            } catch (\Throwable $e) {
                Log::warning("[ALERT BROADCAST_FAILED] type={$alertType} person={$identity} error=" . $e->getMessage());
            }

            $this->recordCooldown($identity, $alertType);
            Log::info("[ALERT FIRED] type={$alertType} person={$identity} duration_minutes={$durationMinutes}");
            $fired++;
        }

        return $fired;
    }

    private function isOnCooldown(string $identity, string $alertType): bool
    {
        $cooldown = AlertCooldown::where('identity_name', $identity)
            ->where('alert_type', $alertType)
            ->first();

        if (! $cooldown) {
            return false;
        }

        return $cooldown->last_alerted_at->addMinutes($this->cooldownMinutes)->isFuture();
    }

    private function recordCooldown(string $identity, string $alertType): void
    {
        AlertCooldown::updateOrCreate(
            ['identity_name' => $identity, 'alert_type' => $alertType],
            ['last_alerted_at' => now()],
        );
    }

    /**
     * Determine which user IDs should receive the alert.
     *
     * Always includes active admin users.
     * Also includes the active superviseur assigned to the employee (if any).
     */
    private function recipientIds(string $identity): array
    {
        // All active admins receive every alert.
        $ids = User::where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        // Find the employee's User record by surveillance identity mapping.
        $employee = User::where('surveillance_identity', $identity)->first();

        if ($employee?->supervisor_id) {
            $supervisor = User::find($employee->supervisor_id);
            if ($supervisor?->is_active) {
                $ids[] = $supervisor->id;
            }
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------------
    // Late arrival check (attendance-based)
    // -----------------------------------------------------------------------

    /**
     * For each active user with an attendance_identity, check whether their
     * first check_in of today is after (workday_start + tolerance).
     * Fires at most one late_arrival alert per person per calendar day.
     */
    private function checkLateArrival(): int
    {
        $today = now()->format('Y-m-d');

        // Cutoff = workday start + tolerance, e.g. "2026-03-27 08:15:00"
        $cutoffTs = strtotime($today . ' ' . $this->lateWorkdayStart) + $this->lateTolerance * 60;

        // Only consider active users who have an attendance_identity mapped.
        $users = User::whereNotNull('attendance_identity')
            ->where('attendance_identity', '<>', '')
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $fired = 0;

        foreach ($users as $user) {
            $person = (string) $user->attendance_identity;

            // Find the first check_in recorded for this person today.
            $row = DB::connection(self::ATTENDANCE_CONN)
                ->table(self::ATTENDANCE_TABLE)
                ->selectRaw('MIN(ts_at) AS first_checkin')
                ->where('person', $person)
                ->where('event', 'check_in')
                ->whereDate('ts_at', $today)
                ->first();

            // No check_in recorded yet — not actionable as late_arrival.
            if (! $row || ! $row->first_checkin) {
                continue;
            }

            // Parse to Unix timestamp so timezone-aware PG values compare correctly.
            $firstCheckinTs = strtotime((string) $row->first_checkin);

            // Only alert when the first check_in is strictly after the cutoff.
            if ($firstCheckinTs <= $cutoffTs) {
                continue;
            }

            // At most one late_arrival alert per person per calendar day.
            if ($this->isAlertedOnDate($person, 'late_arrival', $today)) {
                Log::debug("[ALERT SKIPPED] type=late_arrival person={$person} reason=already_alerted_today");
                continue;
            }

            $recipientIds = $this->recipientIdsForAttendance($user);

            if (empty($recipientIds)) {
                Log::debug("[ALERT SKIPPED] type=late_arrival person={$person} reason=no_recipients");
                continue;
            }

            // duration_minutes = how many minutes past the cutoff the employee arrived.
            $minutesLate = round(($firstCheckinTs - $cutoffTs) / 60, 1);

            try {
                broadcast(new BehaviorAlertEvent(
                    type:              'late_arrival',
                    identity:          $person,
                    duration_minutes:  $minutesLate,
                    threshold_minutes: $this->lateTolerance,
                    timestamp:         now()->toIso8601String(),
                    recipientUserIds:  $recipientIds,
                ));
            } catch (\Throwable $e) {
                Log::warning("[ALERT BROADCAST_FAILED] type=late_arrival person={$person} error=" . $e->getMessage());
            }

            $this->recordCooldown($person, 'late_arrival');
            Log::info("[ALERT FIRED] type=late_arrival person={$person} minutes_late={$minutesLate}");
            $fired++;
        }

        return $fired;
    }

    /**
     * Date-based dedup: returns true if an alert of the given type was already
     * fired for this identity on the given calendar date.
     * Used instead of the time-window cooldown for once-per-day semantics.
     */
    private function isAlertedOnDate(string $identity, string $alertType, string $date): bool
    {
        $cooldown = AlertCooldown::where('identity_name', $identity)
            ->where('alert_type', $alertType)
            ->first();

        if (! $cooldown) {
            return false;
        }

        return $cooldown->last_alerted_at->toDateString() === $date;
    }

    /**
     * Determine recipients for an attendance-based alert.
     * Always includes active admins; adds the employee's supervisor if assigned.
     * Accepts the User model directly (already resolved by the caller).
     */
    private function recipientIdsForAttendance(User $employee): array
    {
        $ids = User::where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        if ($employee->supervisor_id) {
            $supervisor = User::find($employee->supervisor_id);
            if ($supervisor?->is_active) {
                $ids[] = $supervisor->id;
            }
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------------
    // Early leave check (surveillance last-seen based)
    // -----------------------------------------------------------------------

    /**
     * For each active user with a surveillance_identity, check whether their
     * last observed presence for today ended before (workday_end - tolerance).
     *
     * "Last observed presence" is derived from the surveillance_events table as:
     *   MAX( datetime(timestamp_start, '+' || duration_sec || ' seconds') )
     *
     * This represents the latest moment the surveillance system has evidence
     * the employee was present.  It is not a formal checkout event.
     *
     * Only runs after the configured workday end time has passed.
     * Fires at most one early_leave alert per person per calendar day.
     */
    private function checkEarlyLeave(bool $force = false): int
    {
        $today = now()->format('Y-m-d');

        // Only run this check after the workday end time has passed.
        // Before that, absence of a recent record is expected and not actionable.
        // Pass $force=true from the demo command to bypass this guard.
        $workdayEndTs = strtotime($today . ' ' . $this->earlyLeaveWorkdayEnd);
        if (!$force && time() < $workdayEndTs) {
            return 0;
        }

        // Cutoff = workday_end - tolerance, e.g. "2026-03-27 17:45:00"
        $cutoffTs = $workdayEndTs - $this->earlyLeaveTolerance * 60;

        // Only consider active users who have a surveillance_identity mapped.
        $users = User::whereNotNull('surveillance_identity')
            ->where('surveillance_identity', '<>', '')
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $fired = 0;

        foreach ($users as $user) {
            $identity = (string) $user->surveillance_identity;

            // Find the latest moment this person was observed present today.
            // We compute the end of each detected activity segment and take the max.
            $row = DB::connection(self::SURVEILLANCE_CONN)
                ->table(self::TABLE)
                ->selectRaw(
                    "MAX(timestamp_start + (duration_sec || ' seconds')::INTERVAL) AS last_seen_end"
                )
                ->where('identity_name', $identity)
                ->whereDate('timestamp_start', $today)
                ->first();

            // Not observed at all today — no evidence to act on.
            if (! $row || ! $row->last_seen_end) {
                continue;
            }

            // Parse to Unix timestamp so timezone-aware PG values compare correctly.
            $lastSeenTs = strtotime((string) $row->last_seen_end);

            // Last observed presence is at or after the cutoff — no early leave.
            if ($lastSeenTs >= $cutoffTs) {
                continue;
            }

            // At most one early_leave alert per person per calendar day.
            if ($this->isAlertedOnDate($identity, 'early_leave', $today)) {
                Log::debug("[ALERT SKIPPED] type=early_leave person={$identity} reason=already_alerted_today");
                continue;
            }

            $recipientIds = $this->recipientIds($identity);

            if (empty($recipientIds)) {
                Log::debug("[ALERT SKIPPED] type=early_leave person={$identity} reason=no_recipients");
                continue;
            }

            // duration_minutes = how many minutes before the cutoff the employee
            // was last observed (i.e., how early they appear to have left).
            $minutesEarly = round(($cutoffTs - $lastSeenTs) / 60, 1);

            try {
                broadcast(new BehaviorAlertEvent(
                    type:              'early_leave',
                    identity:          $identity,
                    duration_minutes:  $minutesEarly,
                    threshold_minutes: $this->earlyLeaveTolerance,
                    timestamp:         now()->toIso8601String(),
                    recipientUserIds:  $recipientIds,
                ));
            } catch (\Throwable $e) {
                Log::warning("[ALERT BROADCAST_FAILED] type=early_leave person={$identity} error=" . $e->getMessage());
            }

            $this->recordCooldown($identity, 'early_leave');
            Log::info("[ALERT FIRED] type=early_leave person={$identity} minutes_early={$minutesEarly}");
            $fired++;
        }

        return $fired;
    }
}
