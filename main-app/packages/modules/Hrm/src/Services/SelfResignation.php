<?php

namespace Modules\Hrm\Services;

use App\Models\User;
use App\Services\MailTemplates;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\Resignation;

/**
 * An employee resigns for themselves (website and mobile app share this). It becomes an ordinary PENDING resignation: HR still approves or
 * rejects it in Employee Lifecycle, and nothing changes for the employee until it is approved and the last working day has come.
 * The notice date is always today, the last working day cannot be in the past.
 */
class SelfResignation
{
    public function __construct(private LifecycleService $lifecycle, private MailTemplates $mails)
    {
    }

    public function apply(Employee $employee, string $lastWorkingDay, ?string $reason): Resignation
    {
        if ($lastWorkingDay < now()->toDateString()) {
            throw new HrmException(__('The last working day cannot be in the past.'));
        }

        $resignation = $this->lifecycle->resign($employee, now()->toDateString(), $lastWorkingDay, $reason, $employee->user_id);

        // the owner hears about it (a mail problem never undoes the request)
        $employee->loadMissing('user');
        $this->mails->send('hrm_resignation_submitted', $employee->created_by, User::find($employee->created_by), [
            'name' => User::whereKey($employee->created_by)->value('name'), 'employee' => $employee->user->name, 'code' => $employee->employee_code,
            'last_day' => $lastWorkingDay, 'reason' => $reason ?: '-',
        ]);

        return $resignation;
    }

    /** Only the employee's own request, and only while HR has not decided yet. */
    public function withdraw(Resignation $resignation, Employee $employee): void
    {
        if ($resignation->employee_id !== $employee->id || $resignation->created_by !== $employee->created_by) {
            throw new HrmException(__('Permission denied'));
        }
        if ($resignation->status !== 'pending') {
            throw new HrmException(__('Only a pending request can be withdrawn.'));
        }

        $resignation->delete();
    }

    /** @return array<int, array<string, mixed>> */
    public function history(Employee $employee): array
    {
        return Resignation::where('employee_id', $employee->id)->latest('id')->get()->map(fn (Resignation $r) => [
            'id' => $r->id, 'notice_date' => $r->notice_date->toDateString(), 'last_working_date' => $r->last_working_date->toDateString(), 'reason' => $r->reason,
            'status' => $r->status, 'comment' => $r->decision_comment, 'applied' => $r->applied_at !== null,
        ])->all();
    }
}
