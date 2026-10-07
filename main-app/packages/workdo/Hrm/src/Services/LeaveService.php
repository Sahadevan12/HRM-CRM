<?php

namespace Workdo\Hrm\Services;

use Illuminate\Support\Facades\DB;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\LeaveType;

/**
 * Leave rules: only working days count, no overlap with another pending/approved leave of the same person, and the yearly
 * allowance of the leave type (days_per_year, 0 = unlimited) is checked against the approved + pending days of that year.
 */
class LeaveService
{
    public function apply(Employee $employee, LeaveType $type, string $from, string $to, ?string $reason, ?int $actorId): LeaveApplication
    {
        if ($from > $to) {
            throw new HrmException(__('The end date cannot be before the start date.'));
        }
        if (substr($from, 0, 4) !== substr($to, 0, 4)) {
            throw new HrmException(__('A leave cannot span two calendar years. Apply for each year separately.'));
        }
        if (!$employee->isActive()) {
            throw new HrmException(__('Only active employees can apply for leave.'));
        }

        return DB::transaction(function () use ($employee, $type, $from, $to, $reason, $actorId) {
            // lock the employee row so two simultaneous applications cannot both pass the balance check
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $days = WorkCalendar::for($employee->created_by)->countWorkingDays($from, $to);
            if ($days === 0) {
                throw new HrmException(__('The selected dates contain no working day (weekly offs and holidays are not counted).'));
            }

            $this->assertNoOverlap($employee, $from, $to);
            $this->assertBalance($employee, $type, $days, (int) substr($from, 0, 4));

            return LeaveApplication::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
                'total_days' => $days, 'reason' => $reason, 'status' => 'pending',
                'creator_id' => $actorId, 'created_by' => $employee->created_by,
            ]);
        });
    }

    public function approve(LeaveApplication $leave, ?string $comment, int $approverId): LeaveApplication
    {
        return DB::transaction(function () use ($leave, $comment, $approverId) {
            $leave = LeaveApplication::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($leave);

            // the allowance may have been used up since the application was made
            $this->assertBalance($leave->employee, $leave->type, $leave->total_days, (int) $leave->start_date->format('Y'), $leave->id);

            $leave->update(['status' => 'approved', 'approver_comment' => $comment, 'approved_by' => $approverId, 'approved_at' => now()]);

            return $leave;
        });
    }

    public function reject(LeaveApplication $leave, ?string $comment, int $approverId): LeaveApplication
    {
        $this->assertPending($leave);
        $leave->update(['status' => 'rejected', 'approver_comment' => $comment, 'approved_by' => $approverId, 'approved_at' => now()]);

        return $leave;
    }

    /**
     * Balance sheet of one employee for a year: per leave type the allowance, approved days, pending days and what is left.
     *
     * @return array<int, array{type_id: int, name: string, is_paid: bool, allowance: int, used: float, pending: float, remaining: ?float}>
     */
    public function balance(Employee $employee, int $year): array
    {
        $usage = LeaveApplication::where('employee_id', $employee->id)->whereYear('start_date', $year)->whereIn('status', ['approved', 'pending'])
            ->selectRaw('leave_type_id, status, SUM(total_days) as days')->groupBy('leave_type_id', 'status')->get();

        return LeaveType::where('created_by', $employee->created_by)->orderBy('name')->get()->map(function (LeaveType $type) use ($usage) {
            $used = (float) $usage->where('leave_type_id', $type->id)->where('status', 'approved')->sum('days');
            $pending = (float) $usage->where('leave_type_id', $type->id)->where('status', 'pending')->sum('days');

            return [
                'type_id' => $type->id, 'name' => $type->name, 'is_paid' => $type->is_paid, 'allowance' => $type->days_per_year,
                'used' => $used, 'pending' => $pending,
                'remaining' => $type->days_per_year > 0 ? round($type->days_per_year - $used - $pending, 1) : null,
            ];
        })->all();
    }

    private function assertPending(LeaveApplication $leave): void
    {
        if ($leave->status !== 'pending') {
            throw new HrmException(__('Only pending applications can be approved or rejected.'));
        }
    }

    private function assertNoOverlap(Employee $employee, string $from, string $to): void
    {
        $clash = LeaveApplication::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->exists();

        if ($clash) {
            throw new HrmException(__('These dates overlap another leave application of this employee.'));
        }
    }

    private function assertBalance(Employee $employee, LeaveType $type, float $days, int $year, ?int $ignoreId = null): void
    {
        if ($type->days_per_year <= 0) {
            return;
        }

        $taken = (float) LeaveApplication::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereYear('start_date', $year)
            ->whereIn('status', ['approved', 'pending'])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->sum('total_days');

        if ($taken + $days > $type->days_per_year) {
            throw new HrmException(__('Not enough :type balance: :left day(s) left of :allowed for :year.', [
                'type' => $type->name, 'left' => max(0, $type->days_per_year - $taken), 'allowed' => $type->days_per_year, 'year' => $year,
            ]));
        }
    }
}
