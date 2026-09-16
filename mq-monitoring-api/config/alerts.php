<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Behavior Alert Thresholds
    |--------------------------------------------------------------------------
    |
    | inactive_threshold_minutes — cumulati
    ve Inactive seconds in the last N
    |   minutes must reach this threshold to trigger an alert.
    |
    | phone_threshold_minutes — same logic for Using_Phone activity.
    |
    | cooldown_minutes — minimum quiet period between repeated alerts for the
    |   same employee + activity pair. Prevents notification spam.
    |
    */
    'inactive_threshold_minutes' => (int) env('ALERT_INACTIVE_THRESHOLD_MINUTES', 10),
    'phone_threshold_minutes'    => (int) env('ALERT_PHONE_THRESHOLD_MINUTES', 5),
    'cooldown_minutes'           => (int) env('ALERT_COOLDOWN_MINUTES', 30),

    // Demo/testing overrides: when set (> 0), these are used instead of the minute-based
    // thresholds above. Allows sub-minute alert windows for live demos.
    'inactive_threshold_seconds' => (int) env('ALERT_INACTIVE_THRESHOLD_SECONDS', 0),
    'phone_threshold_seconds'    => (int) env('ALERT_PHONE_THRESHOLD_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | Late Arrival Thresholds
    |--------------------------------------------------------------------------
    |
    | late_workday_start     — expected start of the working day (HH:MM, 24-hour).
    |
    | late_tolerance_minutes — grace period after workday_start before an
    |   employee is considered late.  Default: 15 minutes.
    |   An alert fires when the first check-in of the day is recorded
    |   after (late_workday_start + late_tolerance_minutes).
    |
    | At most one late_arrival alert is fired per employee per calendar day.
    |
    */
    'late_workday_start'     => env('ALERT_LATE_WORKDAY_START', '08:00'),
    'late_tolerance_minutes' => (int) env('ALERT_LATE_TOLERANCE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Early Leave Thresholds
    |--------------------------------------------------------------------------
    |
    | early_leave_workday_end      — expected end of the working day (HH:MM, 24-hour).
    |
    | early_leave_tolerance_minutes — how many minutes before workday_end an employee
    |   must still be observable before triggering an alert.  Default: 15 minutes.
    |   An alert fires when the last observed presence  for the day is before
    |   (early_leave_workday_end - early_leave_tolerance_minutes), and only after
    |   the workday end time has passed.
    |
    | At most one early_leave alert is fired per employee per calendar day.
    |
    */
    'early_leave_workday_end'         => env('ALERT_EARLY_LEAVE_WORKDAY_END', '18:00'),
    'early_leave_tolerance_minutes'   => (int) env('ALERT_EARLY_LEAVE_TOLERANCE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Planned Absence / Lunch Break
    |--------------------------------------------------------------------------
    |
    | lunch_break_enabled — whether to exclude lunch break from monitoring.
    |   When enabled, inactive and phone alerts will not fire during lunch.
    |
    | lunch_break_start — start of lunch break (HH:MM, 24-hour).
    |   Default: 12:00
    |
    | lunch_break_end — end of lunch break (HH:MM, 24-hour).
    |   Default: 14:00
    |
    | During lunch break:
    |   - Inactive alerts are suppressed
    |   - Phone usage alerts are suppressed
    |   - Early leave checks are paused (no alert if person leaves during lunch)
    |
    */
    'lunch_break_enabled'   => (bool) env('ALERT_LUNCH_BREAK_ENABLED', true),
    'lunch_break_start'     => env('ALERT_LUNCH_BREAK_START', '12:00'),
    'lunch_break_end'      => env('ALERT_LUNCH_BREAK_END', '14:00'),

];
