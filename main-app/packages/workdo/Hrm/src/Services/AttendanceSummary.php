<?php

namespace Workdo\Hrm\Services;

use Illuminate\Support\Carbon;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveApplication;

/**
 * One employee, one period, every WORKING day classified. The monthly attendance report and payroll use the same numbers.
 *
 *   day status: leave_paid / leave_unpaid (approved leave) > present / half_day / absent (attendance record)
 *               > absent (a past working day without any record) ; days after $asOf are 'upcoming' and count as nothing
 *   Weekly offs and holidays are not working days at all and are never counted as absent.
 */
class AttendanceSummary
{
    /**
     * @return array{days: array<string, string>, totals: array<string, float|int>}
     */
    public function forEmployee(Employee $employee, string $from, string $to, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();

        $records = Attendance::where('employee_id', $employee->id)->whereBetween('date', [$from, $to])->get()->keyBy(fn ($a) => $a->date->toDateString());
        $leaves = $this->leaveDays($employee, $from, $to);
        $working = WorkCalendar::for($employee->created_by)->workingDays($from, $to);

        $days = [];
        $t = ['working_days' => count($working), 'present' => 0.0, 'half_day' => 0.0, 'absent' => 0, 'leave_paid' => 0.0, 'leave_unpaid' => 0.0,
            'late_count' => 0, 'late_minutes' => 0, 'worked_hours' => 0.0, 'overtime_hours' => 0.0];

        foreach ($working as $date) {
            if (isset($leaves[$date])) {
                $days[$date] = $leaves[$date];
                $t[$leaves[$date]]++;
                continue;
            }

            if ($record = $records[$date] ?? null) {
                $days[$date] = $record->status;
                if ($record->status === 'present') {
                    $t['present']++;
                } elseif ($record->status === 'half_day') {
                    $t['half_day']++;
                } else {
                    $t['absent']++;
                }
                continue;
            }

            if ($date <= $asOf) {
                $days[$date] = 'absent';
                $t['absent']++;
            } else {
                $days[$date] = 'upcoming';
            }
        }

        // hours and lateness count for every record of the period (an extra shift on a day off also counts)
        foreach ($records as $record) {
            $t['worked_hours'] += $record->total_hours;
            $t['overtime_hours'] += $record->overtime_hours;
            if ($record->late_minutes > 0) {
                $t['late_count']++;
                $t['late_minutes'] += $record->late_minutes;
            }
        }
        $t['worked_hours'] = round($t['worked_hours'], 2);
        $t['overtime_hours'] = round($t['overtime_hours'], 2);

        return ['days' => $days, 'totals' => $t];
    }

    /** @return array<string, string> [date => leave_paid|leave_unpaid] for the approved leaves that fall on working days */
    private function leaveDays(Employee $employee, string $from, string $to): array
    {
        $out = [];

        $leaves = LeaveApplication::with('type:id,is_paid')->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get();

        foreach ($leaves as $leave) {
            $kind = $leave->type->is_paid ? 'leave_paid' : 'leave_unpaid';

            for ($d = Carbon::parse(max($leave->start_date->toDateString(), $from)); $d->toDateString() <= min($leave->end_date->toDateString(), $to); $d->addDay()) {
                $out[$d->toDateString()] = $kind;
            }
        }

        return $out;
    }
}
