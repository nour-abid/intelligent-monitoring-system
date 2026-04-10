# Early Leave Alert Testing Guide

**Created**: March 30, 2026  
**Purpose**: Safe, minimal test procedure for `early_leave` alert using real business values

---

## System Configuration (Verified)

| Parameter | Value | Source |
|-----------|-------|--------|
| Workday Start | 08:00 | `alerts.late_workday_start` (config/alerts.php) |
| Workday End | 18:00 | `alerts.early_leave_workday_end` (config/alerts.php) |
| Tolerance | 15 minutes | `alerts.early_leave_tolerance_minutes` (config/alerts.php) |
| **Alert Cutoff** | **17:45:00** | workday_end - tolerance |

**Early leave occurs if**: Last observed presence in surveillance today is **before 17:45:00**

---

## Implementation Summary

### Files Modified/Created
- ✅ **Created**: `test_insert_early_leave.py` — minimal test helper (no production code changed)
- ✅ **Created**: `TEST_EARLY_LEAVE_PROCEDURE.php` — documentation reference file

### What Was NOT Changed
- ❌ `AlertEvaluationService.php` — alert logic untouched
- ❌ Any Laravel alert evaluation code
- ❌ Angular frontend
- ❌ Scheduler setup
- ❌ Python surveillance runtime
- ❌ Authentication, analytics, user CRUD
- ❌ Notification persistence

### Design Rationale
- **Smallest safe patch**: Two helper files only
- **Production-ready**: Uses real configured values (no fake lowering of cutoff)
- **Reusable**: `test_insert_early_leave.py` can be called multiple times
- **Clean separation**: Test code is isolated in standalone files
- **Low risk**: Only inserts rows, no schema changes, no logic modifications

---

## Real Business Values Preserved

```
Workday End:      18:00:00  (unchanged, from config)
Tolerance:        15 min    (unchanged, from config)
Cutoff:           17:45:00  (computed: 18:00 - 15min)
Alert fires:      ONLY after 18:00:01 (workday_end passes)
```

Test data follows the exact same rules as production.

---

## Step-by-Step Test Procedure

### Step 1: Create Test User in Laravel

Open Laravel Tinker from `mq-monitoring-api` folder:

```bash
cd mq-monitoring-api
php artisan tinker
```

Inside Tinker:

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$amir = User::where('email', 'amir.dammak@mq-monitoring.local')->first();

User::updateOrCreate(
    ['email' => 'test_early_real@mq-monitoring.local'],
    [
        'name'                  => 'Test Early Real',
        'password'              => Hash::make('Test@1234'),
        'role'                  => 'viewer',
        'supervisor_id'         => $amir->id ?? 1,
        'is_active'             => true,
        'surveillance_identity' => 'test_early_real',
    ]
);

exit
```

**Verification**: User created or updated with `surveillance_identity = 'test_early_real'` and `is_active = true`.

---

### Step 2: Insert Surveillance Event Ending Before Cutoff

From workspace root:

```bash
python test_insert_early_leave.py test_early_real 17:30
```

Example output:
```
======================================================================
EARLY_LEAVE ALERT TEST EVENT INSERTED
======================================================================
Row ID              : 154
Insert timestamp    : 2026-03-30T13:04:20.359982
Event start         : 2026-03-30 17:00:00
Event end           : 2026-03-30 17:30:00
Duration            : 1800 sec (30 min)
Identity            : test_early_real
Activity            : Working (session_end)
======================================================================
```

**Verification**: Exactly one row inserted with end time **before 17:45**.

Valid end times (examples):
- `test_insert_early_leave.py test_early_real 17:30` ✅ (15 min early)
- `test_insert_early_leave.py test_early_real 16:00` ✅ (2 hours early)
- `test_insert_early_leave.py test_early_real 17:45` ❌ Rejected (at cutoff, not before)
- `test_insert_early_leave.py test_early_real 18:00` ❌ Rejected (at workday end)

---

### Step 3: Verify No Later Row Exists for Today

Open Laravel Tinker:

```php
DB::connection('surveillance')
    ->table('surveillance_events')
    ->where('identity_name', 'test_early_real')
    ->whereRaw("substr(timestamp_start, 1, 10) = ?", [now()->format('Y-m-d')])
    ->orderBy('timestamp_end', 'desc')
    ->get();
```

**Expected result**: Only one row (the one just inserted).  
**If multiple**: Delete any extra rows manually (see cleanup section).

---

### Step 4: Wait Until After 18:00 (Workday End)

**CRITICAL**: The alert will **NOT fire before 18:00:01**.

The `checkEarlyLeave()` method includes this guard:

```php
$workdayEndTs = strtotime($today . ' ' . $this->earlyLeaveWorkdayEnd);
if (time() < $workdayEndTs) {
    return 0;  // Skip
}
```

- If system time is 17:59:59 → alert check skipped
- If system time is 18:00:01 → alert check runs

**Option**: Set system clock forward if needed for testing (in dev environment only).

---

### Step 5: Trigger Alert Evaluation

**Option A: Wait for Scheduler** (automatic)

- The Laravel scheduler typically runs on a 1-minute interval (if configured)
- Check scheduler console or log for `AlertEvaluationService::evaluate()` calls

**Option B: Manual Trigger via Tinker** (immediate)

Open Laravel Tinker:

```php
$fired = app(App\Services\Monitoring\AlertEvaluationService::class)->evaluate();
echo "Alerts fired: " . $fired . "\n";
exit
```

Expected output: `Alerts fired: 1` (if all conditions met).

---

### Step 6: Verify Alert and Cooldown

#### Check Broadcast (Frontend/Dashboard)

The alert should appear in the admin or superviseur dashboard in real-time via WebSocket broadcast (identity `test_early_real`).

Verify the `BehaviorAlertEvent` contains:
- `type: 'early_leave'`
- `identity: 'test_early_real'`
- `duration_minutes: ~15` (time from end_time to cutoff, rounded)
- `threshold_minutes: 15`

#### Check Database Cooldown

Open Laravel Tinker:

```php
DB::table('alert_cooldowns')
    ->where('identity_name', 'test_early_real')
    ->where('alert_type', 'early_leave')
    ->first();
```

Expected result:
```
{
    "id": <some_id>,
    "identity_name": "test_early_real",
    "alert_type": "early_leave",
    "last_alerted_at": "2026-03-30 18:00:15",  // current time
    "cooldown_until": null,
    "created_at": "2026-03-30T18:00:15.000000Z",
    "updated_at": "2026-03-30T18:00:15.000000Z"
}
```

This proves:
1. Alert was recognized
2. Cooldown recorded (one per day per identity)
3. Logic executed correctly

---

## Cleanup Procedures

### Delete Test Surveillance Row

Option A (Tinker):
```php
DB::connection('surveillance')
    ->table('surveillance_events')
    ->where('id', 154)  // Replace 154 with actual row ID from test output
    ->delete();
exit
```

Option B (Direct SQL):
```bash
sqlite3 logs/surveillance_events.db "DELETE FROM surveillance_events WHERE id = 154;"
```

### Delete Test User

```php
User::where('email', 'test_early_real@mq-monitoring.local')->delete();
exit
```

### Delete Cooldown Record

```php
DB::table('alert_cooldowns')
    ->where('identity_name', 'test_early_real')
    ->where('alert_type', 'early_leave')
    ->delete();
exit
```

---

## Troubleshooting

### Alert Did Not Fire After 18:00

1. **Check system time**: Ensure actual clock has passed 18:00
2. **Verify user exists**: `User::where('surveillance_identity', 'test_early_real')->first()`
3. **Verify user is_active**: Ensure `is_active = true`
4. **Verify surveillance row exists**: Query `surveillance_events` directly
5. **Check for cooldown**: If alert fired once, it won't fire again today for same identity
6. **Trigger manual evaluation**: Run `evaluate()` again from Tinker

### Invalid Time Format Error

```
ERROR: Invalid time format: 1730. Use HH:MM (24-hour).
```

Use format `HH:MM` (24-hour), e.g., `17:30` not `5:30 PM` or `1730`.

### Row ID Not Found When Deleting

The script outputs the row ID. If you lost it, query the latest row:

```php
DB::connection('surveillance')
    ->table('surveillance_events')
    ->where('identity_source', 'test_early_leave')
    ->orderBy('id', 'desc')
    ->first();
```

---

## Assumptions Made

1. **Laravel scheduler is active** or alert evaluator is triggered manually
2. **WebSocket broadcast is working** (or you can inspect database for cooldown proof)
3. **Primary database supports `alert_cooldowns` table** (schema includes it)
4. **Surveillance DB path is configured correctly** in `config/database.php`
5. **No existing user has `surveillance_identity='test_early_real'`** (use different name if collision)
6. **Test is run after existing production/seeded data** (row IDs may not be sequential)

---

## Contracts & Behavior Preserved

✅ **Alert Logic**: `checkEarlyLeave()` method unchanged — real cutoff (17:45) enforced  
✅ **Cooldown System**: Exact same one-per-day-per-identity rule applies  
✅ **Broadcast**: Same `BehaviorAlertEvent` structure and recipients  
✅ **Configuration**: Real configured values used (18:00, 15 min tolerance)  
✅ **Database**: Surveillance schema untouched, only new rows inserted  
✅ **Security**: No auth bypass, same user-supervisor scoping applies  
✅ **Performance**: Single row insert, minimal DB footprint  

---

## Files Created

### 1. `test_insert_early_leave.py`

**Purpose**: Python CLI tool to insert a single surveillance event with end time before cutoff  
**Location**: Workspace root  
**Size**: ~150 lines  
**Usage**: `python test_insert_early_leave.py IDENTITY END_TIME_HH:MM`  
**Risk**: None — read-only from schema perspective, only appends rows  

### 2. `TEST_EARLY_LEAVE_PROCEDURE.php`

**Purpose**: Documentation reference for Tinker commands and procedure  
**Location**: Workspace root  
**Size**: ~120 lines (comments only)  
**Usage**: Reference guide, not executed  
**Risk**: None — documentation file  

---

## Summary

This test patch is **production-safe** because it:

1. ✅ Creates zero new dependencies or imports
2. ✅ Does not modify any alert evaluation logic
3. ✅ Uses the exact real business configuration values
4. ✅ Only inserts test rows, never modifies schema
5. ✅ Follows the same patterns as existing test helpers (`test_insert_event.py`, `test_insert_attendance.py`)
6. ✅ Provides clear, documented procedure for repeatable testing
7. ✅ Includes cleanup guidelines
8. ✅ Minimal code footprint (2 small files, ~270 lines combined)

The test validates that the alert system correctly identifies employees who leave before the configured tolerance window, using the exact real values from the business configuration.
