<?php

namespace Workdo\Hrm\Services;

use Illuminate\Support\Carbon;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\IpRestriction;

/**
 * Attendance rules.
 *  - Self service: clock in / clock out (optionally only from the company's allowed IP addresses).
 *  - HR: manual records (also "absent" markers).
 *  - total hours = (out - in) - shift break; overtime = hours above the shift length; half day when less than half a shift was worked;
 *    late = minutes after shift start + grace (setting hrmLateGraceMinutes, default 10).
 * Times are compared in the company's time zone (setting `timezone`).
 */
class AttendanceService
{
    public const DEFAULT_SHIFT_HOURS = 8.0;
    public const DEFAULT_GRACE = 10;

    public function clockIn(Employee $employee, ?string $ip, ?Carbon $at = null): Attendance
    {
        $tenant = $employee->created_by;
        $at ??= now();
        $this->assertCanClock($employee, $ip);

        $open = Attendance::where('employee_id', $employee->id)->whereNotNull('clock_in')->whereNull('clock_out')->first();
        if ($open) {
            throw new HrmException(__('You are already clocked in since :time. Clock out first.', ['time' => $open->clock_in->setTimezone($this->tz($tenant))->format('d M H:i')]));
        }

        $date = $at->copy()->setTimezone($this->tz($tenant))->toDateString();
        if (Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->exists()) {
            throw new HrmException(__('Your attendance for today is already recorded.'));
        }

        $employee->loadMissing('shift');

        return Attendance::create([
            'employee_id' => $employee->id,
            'shift_id' => $employee->shift_id,
            'date' => $date,
            'clock_in' => $at,
            'late_minutes' => $this->lateMinutes($employee->shift, $at, $tenant),
            'status' => 'present',
            'source' => 'self',
            'ip_address' => $ip,
            'created_by' => $tenant,
            'creator_id' => $employee->user_id,
        ]);
    }

    public function clockOut(Employee $employee, ?string $ip = null, ?Carbon $at = null): Attendance
    {
        $at ??= now();
        $this->assertCanClock($employee, $ip);

        $record = Attendance::where('employee_id', $employee->id)->whereNotNull('clock_in')->whereNull('clock_out')->latest('clock_in')->first();
        if (!$record) {
            throw new HrmException(__('You are not clocked in.'));
        }
        if ($at->lessThanOrEqualTo($record->clock_in)) {
            throw new HrmException(__('The clock out time must be after the clock in time.'));
        }

        $record->clock_out = $at;

        return $this->recompute($record);
    }

    /**
     * HR entry. `clock_in` / `clock_out` are H:i on `date`; a clock out earlier than the clock in means the next day (night shift).
     * status 'absent' stores a day without times.
     *
     * @param  array{date: string, clock_in?: ?string, clock_out?: ?string, status?: ?string, notes?: ?string}  $data
     */
    public function saveManual(Employee $employee, array $data, ?int $actorId, ?Attendance $existing = null): Attendance
    {
        $tenant = $employee->created_by;
        $tz = $this->tz($tenant);
        $date = $data['date'];

        $duplicate = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
        if ($duplicate) {
            throw new HrmException(__('There is already an attendance record for this employee on :date.', ['date' => $date]));
        }

        $record = $existing ?? new Attendance(['employee_id' => $employee->id, 'created_by' => $tenant, 'creator_id' => $actorId, 'source' => 'manual']);
        $employee->loadMissing('shift');

        $record->fill(['date' => $date, 'shift_id' => $employee->shift_id, 'notes' => $data['notes'] ?? null]);

        if (($data['status'] ?? null) === 'absent' || empty($data['clock_in'])) {
            $record->fill(['clock_in' => null, 'clock_out' => null, 'total_hours' => 0, 'overtime_hours' => 0, 'late_minutes' => 0, 'status' => 'absent']);
            $record->save();

            return $record;
        }

        $in = Carbon::parse("{$date} {$data['clock_in']}", $tz);
        $out = null;
        if (!empty($data['clock_out'])) {
            $out = Carbon::parse("{$date} {$data['clock_out']}", $tz);
            if ($out->lessThanOrEqualTo($in)) {
                $out->addDay(); // night shift
            }
        }

        $record->clock_in = $in->setTimezone(config('app.timezone'));
        $record->clock_out = $out?->setTimezone(config('app.timezone'));
        $record->late_minutes = $this->lateMinutes($employee->shift, $in, $tenant);

        return $this->recompute($record);
    }

    /** Hours, overtime and status of a record that has (or has not yet) a clock out. */
    public function recompute(Attendance $record): Attendance
    {
        $record->loadMissing('employee.shift');
        $shift = $record->shift ?? $record->employee->shift;

        if ($record->clock_in && $record->clock_out) {
            $minutes = max(0, $record->clock_in->diffInMinutes($record->clock_out) - ($shift?->break_minutes ?? 0));
            $worked = round($minutes / 60, 2);
            $shiftHours = $this->shiftHours($shift);

            $record->total_hours = $worked;
            $record->overtime_hours = max(0, round($worked - $shiftHours, 2));
            $record->status = $worked >= $shiftHours / 2 ? 'present' : 'half_day';
        } elseif ($record->clock_in) {
            $record->fill(['total_hours' => 0, 'overtime_hours' => 0, 'status' => 'present']); // still at work
        }

        $record->save();

        return $record;
    }

    /** Paid length of a shift in hours (end - start - break, night shifts cross midnight). */
    public function shiftHours(?\Workdo\Hrm\Models\Shift $shift): float
    {
        if (!$shift) {
            return self::DEFAULT_SHIFT_HOURS;
        }

        $start = Carbon::createFromFormat('H:i:s', strlen($shift->start_time) === 5 ? $shift->start_time . ':00' : $shift->start_time);
        $end = Carbon::createFromFormat('H:i:s', strlen($shift->end_time) === 5 ? $shift->end_time . ':00' : $shift->end_time);
        $minutes = $start->diffInMinutes($end, false);
        if ($minutes <= 0) {
            $minutes += 24 * 60;
        }

        return max(0.5, round(($minutes - $shift->break_minutes) / 60, 2));
    }

    public function lateMinutes(?\Workdo\Hrm\Models\Shift $shift, Carbon $clockIn, int $tenant): int
    {
        if (!$shift) {
            return 0;
        }

        $local = $clockIn->copy()->setTimezone($this->tz($tenant));
        $start = Carbon::parse($local->toDateString() . ' ' . $shift->start_time, $this->tz($tenant));
        $late = (int) $start->diffInMinutes($local, false);

        // arriving within the grace period is not late; beyond it the whole delay (from the shift start) counts
        return $late > $this->grace($tenant) ? $late : 0;
    }

    public function tz(int $tenant): string
    {
        $zone = tenantSettings($tenant)['timezone'] ?? null;

        return $zone && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : config('app.timezone');
    }

    private function grace(int $tenant): int
    {
        return max(0, (int) (tenantSettings($tenant)['hrmLateGraceMinutes'] ?? self::DEFAULT_GRACE));
    }

    private function assertCanClock(Employee $employee, ?string $ip): void
    {
        if (!$employee->isActive()) {
            throw new HrmException(__('Only active employees can clock in.'));
        }

        // when the company lists allowed addresses, self service works only from them
        $allowed = IpRestriction::where('created_by', $employee->created_by)->pluck('ip_address');
        if ($allowed->isNotEmpty() && !$allowed->contains($ip)) {
            throw new HrmException(__('You can only clock in from the office network (your address: :ip).', ['ip' => $ip ?? '?']));
        }
    }
}
