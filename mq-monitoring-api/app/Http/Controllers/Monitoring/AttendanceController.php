<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AttendanceController
 *
 * Returns per-employee attendance status for a given date range.
 * Status is derived from attendance_events (check_in time) and
 * surveillance_events (last-seen time for early_leave detection).
 *
 * Status logic:
 *   on_time    — first check_in ≤ workday_start + tolerance
 *   late       — first check_in > workday_start + tolerance
 *   absent     — no check_in recorded for the day
 *   early_leave — checked in but last surveillance presence before
 *                  workday_end - tolerance (today only; historical
 *                  rows use check_out as proxy when no surv data)
 *
 * Route: GET /api/monitoring/attendance
 */
class AttendanceController extends Controller
{
    private const ATTENDANCE_CONN  = 'attendance';
    private const ATTENDANCE_TABLE = 'attendance_events';
    private const SURV_CONN        = 'surveillance';
    private const SURV_TABLE       = 'surveillance_events';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $start = $validated['start'] ?? now()->format('Y-m-d');
        $end   = $validated['end']   ?? $start;

        // Clamp so end is never before start.
        if ($end < $start) {
            $end = $start;
        }

        // Build date range array.
        $dates = [];
        $cursor = \Carbon\Carbon::parse($start);
        $endCarbon = \Carbon\Carbon::parse($end);
        while ($cursor->lte($endCarbon)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        // Config thresholds (same values AlertEvaluationService uses).
        $workdayStart     = (string) config('alerts.late_workday_start', '08:00');
        $lateTolerance    = (int)    config('alerts.late_tolerance_minutes', 15);
        $workdayEnd       = (string) config('alerts.early_leave_workday_end', '18:00');
        $earlyTolerance   = (int)    config('alerts.early_leave_tolerance_minutes', 15);

        // Fetch all active users who have an attendance_identity.
        /** @var \Illuminate\Support\Collection<int, User> $users */
        $users = User::whereNotNull('attendance_identity')
            ->where('attendance_identity', '<>', '')
            ->where('is_active', true)
            ->get(['id', 'name', 'attendance_identity', 'surveillance_identity']);

        if ($users->isEmpty()) {
            return response()->json([
                'kpis' => $this->emptyKpis(),
                'rows' => [],
            ]);
        }

        $persons = $users->pluck('attendance_identity')->all();

        // Bulk-fetch all check_in / check_out events for the range.
        $events = DB::connection(self::ATTENDANCE_CONN)
            ->table(self::ATTENDANCE_TABLE)
            ->whereIn('person', $persons)
            ->whereIn('event', ['check_in', 'check_out'])
            ->whereBetween(
                DB::raw("DATE(ts_at AT TIME ZONE 'UTC')"),
                [$start, $end]
            )
            ->select(['person', 'event', 'ts_at'])
            ->orderBy('ts_at')
            ->get();

        // Index events: [person][date][event] → [timestamps]
        $indexed = [];
        foreach ($events as $ev) {
            $date   = substr((string) $ev->ts_at, 0, 10);
            $person = (string) $ev->person;
            $indexed[$person][$date][$ev->event][] = (string) $ev->ts_at;
        }

        // For today: bulk-fetch last surveillance presence per identity.
        $today          = now()->format('Y-m-d');
        $survIdentities = $users->whereNotNull('surveillance_identity')
            ->pluck('surveillance_identity', 'attendance_identity')
            ->all();

        $lastSeen = [];
        if (! empty($survIdentities) && in_array($today, $dates, true)) {
            $survRows = DB::connection(self::SURV_CONN)
                ->table(self::SURV_TABLE)
                ->selectRaw(
                    "identity_name,
                     MAX(timestamp_start + (duration_sec || ' seconds')::INTERVAL) AS last_seen_end"
                )
                ->whereIn('identity_name', array_values($survIdentities))
                ->whereDate('timestamp_start', $today)
                ->groupBy('identity_name')
                ->get();

            foreach ($survRows as $sr) {
                $lastSeen[(string) $sr->identity_name] = (string) $sr->last_seen_end;
            }
        }

        // Build result rows.
        $rows       = [];
        $kpiCounts  = ['on_time' => 0, 'late' => 0, 'absent' => 0, 'early_leave' => 0];

        foreach ($dates as $date) {
            $cutoffTs      = strtotime($date . ' ' . $workdayStart) + $lateTolerance * 60;
            $earlyLeaveTs  = strtotime($date . ' ' . $workdayEnd)   - $earlyTolerance * 60;
            $dateIsPast    = ($date < $today); // historical day — all statuses are final

            foreach ($users as $user) {
                $person    = (string) $user->attendance_identity;
                $survIdent = (string) ($user->surveillance_identity ?? '');
                $checkins  = $indexed[$person][$date]['check_in']  ?? [];
                $checkouts = $indexed[$person][$date]['check_out'] ?? [];

                $firstCheckin = ! empty($checkins)  ? min($checkins)  : null;
                $lastCheckout = ! empty($checkouts) ? max($checkouts) : null;

                if ($firstCheckin === null) {
                    // No check_in: absent (only report if day has started past workday_start + grace)
                    $dayStartTs = strtotime($date . ' ' . $workdayStart) + $lateTolerance * 60 + 600; // +10 min buffer
                    if (time() < $dayStartTs && ! $dateIsPast) {
                        // Day hasn't started yet — skip row.
                        continue;
                    }
                    $status = 'absent';
                } else {
                    $firstTs = strtotime($firstCheckin);

                    if ($firstTs > $cutoffTs) {
                        $status = 'late';
                    } else {
                        $status = 'on_time';
                    }

                    // Check for early leave.
                    // For today: only evaluate AFTER the early-leave cutoff time has passed
                    //   (same guard as AlertEvaluationService::checkEarlyLeave).
                    //   Before that moment there is no way to know whether the person left early.
                    // For past days: use last checkout as proxy if available.
                    if ($date === $today && time() >= $earlyLeaveTs && isset($lastSeen[$survIdent])) {
                        $lastPresenceTs = strtotime($lastSeen[$survIdent]);
                        if ($lastPresenceTs < $earlyLeaveTs) {
                            $status = 'early_leave';
                        }
                    } elseif ($dateIsPast && $lastCheckout !== null) {
                        $checkoutTs = strtotime($lastCheckout);
                        if ($checkoutTs < $earlyLeaveTs) {
                            $status = 'early_leave';
                        }
                    }
                }

                $kpiCounts[$status]++;

                $rows[] = [
                    'name'          => $user->name,
                    'identity'      => $person,
                    'date'          => $date,
                    'status'        => $status,
                    'checkin_time'  => $firstCheckin  ? $this->formatTime($firstCheckin)  : null,
                    'checkout_time' => $lastCheckout  ? $this->formatTime($lastCheckout)  : null,
                ];
            }
        }

        $totalRows  = array_sum($kpiCounts);
        $onTimePct  = $totalRows > 0
            ? round($kpiCounts['on_time'] / $totalRows * 100, 1)
            : 0.0;

        return response()->json([
            'kpis' => [
                'total_on_time'    => $kpiCounts['on_time'],
                'total_late'       => $kpiCounts['late'],
                'total_absent'     => $kpiCounts['absent'],
                'total_early_leave'=> $kpiCounts['early_leave'],
                'on_time_pct'      => $onTimePct,
            ],
            'rows' => $rows,
        ]);
    }

    // -------------------------------------------------------------------------

    private function formatTime(string $tsStr): string
    {
        $ts = strtotime($tsStr);
        return $ts !== false ? date('H:i', $ts) : $tsStr;
    }

    private function emptyKpis(): array
    {
        return [
            'total_on_time'     => 0,
            'total_late'        => 0,
            'total_absent'      => 0,
            'total_early_leave' => 0,
            'on_time_pct'       => 0.0,
        ];
    }
}
