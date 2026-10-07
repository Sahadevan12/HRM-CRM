<?php

namespace Workdo\Hrm\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Promotion;
use Workdo\Hrm\Models\Resignation;
use Workdo\Hrm\Models\Termination;
use Workdo\Hrm\Models\Transfer;

/**
 * Employee lifecycle.
 *  - Promotion: applied at once (designation changes), kept as history.
 *  - Resignation / Termination / Transfer: pending -> approved | rejected. An approved one is APPLIED on its effective date
 *    (last working day / termination date / transfer date): immediately when that date has come, otherwise by `hrm:apply-lifecycle`
 *    (scheduled daily). Applying a resignation/termination sets the employee's status and switches the login off.
 */
class LifecycleService
{
    /** kind => [model class, effective date column] */
    public const WORKFLOWS = [
        'resignations' => [Resignation::class, 'last_working_date'],
        'terminations' => [Termination::class, 'termination_date'],
        'transfers' => [Transfer::class, 'transfer_date'],
    ];

    public function promote(Employee $employee, int $designationId, string $title, string $date, ?string $notes, ?int $actorId): Promotion
    {
        $this->assertActive($employee);
        if ($employee->designation_id === $designationId) {
            throw new HrmException(__('The employee already has this designation.'));
        }

        return DB::transaction(function () use ($employee, $designationId, $title, $date, $notes, $actorId) {
            $promotion = Promotion::create([
                'employee_id' => $employee->id, 'previous_designation_id' => $employee->designation_id, 'designation_id' => $designationId,
                'title' => $title, 'promotion_date' => $date, 'notes' => $notes, 'creator_id' => $actorId, 'created_by' => $employee->created_by,
            ]);
            $employee->update(['designation_id' => $designationId]);

            return $promotion;
        });
    }

    public function resign(Employee $employee, string $noticeDate, string $lastDay, ?string $reason, ?int $actorId): Resignation
    {
        if ($lastDay < $noticeDate) {
            throw new HrmException(__('The last working day cannot be before the notice date.'));
        }

        return $this->open($employee, Resignation::class, [
            'notice_date' => $noticeDate, 'last_working_date' => $lastDay, 'reason' => $reason,
        ], $actorId);
    }

    public function terminate(Employee $employee, string $type, string $noticeDate, string $date, ?string $reason, ?int $actorId): Termination
    {
        if ($date < $noticeDate) {
            throw new HrmException(__('The termination date cannot be before the notice date.'));
        }

        return $this->open($employee, Termination::class, [
            'type' => $type, 'notice_date' => $noticeDate, 'termination_date' => $date, 'reason' => $reason,
        ], $actorId);
    }

    public function transfer(Employee $employee, ?int $branchId, ?int $departmentId, string $date, ?string $reason, ?int $actorId): Transfer
    {
        if ($branchId === $employee->branch_id && $departmentId === $employee->department_id) {
            throw new HrmException(__('The employee is already in this branch and department.'));
        }

        return $this->open($employee, Transfer::class, [
            'from_branch_id' => $employee->branch_id, 'from_department_id' => $employee->department_id,
            'branch_id' => $branchId, 'department_id' => $departmentId, 'transfer_date' => $date, 'reason' => $reason,
        ], $actorId);
    }

    /** @param class-string<Model> $model */
    private function open(Employee $employee, string $model, array $data, ?int $actorId): Model
    {
        $this->assertActive($employee);

        return DB::transaction(function () use ($employee, $model, $data, $actorId) {
            // lock the employee so two simultaneous requests cannot both pass the "one pending request" check
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            if ($model::where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
                throw new HrmException(__('This employee already has a pending request of this kind.'));
            }

            return $model::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'creator_id' => $actorId, 'created_by' => $employee->created_by]);
        });
    }

    public function approve(Model $request, ?string $comment, int $actorId): Model
    {
        return DB::transaction(function () use ($request, $comment, $actorId) {
            $request = $request::whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($request);

            $employee = Employee::whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
            $this->assertActive($employee);

            $request->update(['status' => 'approved', 'decision_comment' => $comment, 'decided_by' => $actorId, 'decided_at' => now()]);

            if ($request->{$this->dateColumn($request)}->toDateString() <= now()->toDateString()) {
                $this->apply($request, $employee);
            }

            return $request->refresh();
        });
    }

    public function reject(Model $request, ?string $comment, int $actorId): Model
    {
        $this->assertPending($request);
        $request->update(['status' => 'rejected', 'decision_comment' => $comment, 'decided_by' => $actorId, 'decided_at' => now()]);

        return $request;
    }

    /** Apply every approved request whose effective date has come (idempotent; run daily). Returns how many were applied. */
    public function applyDue(?int $tenantId = null): int
    {
        $applied = 0;

        foreach (self::WORKFLOWS as [$model, $column]) {
            $due = $model::where('status', 'approved')->whereNull('applied_at')->whereDate($column, '<=', now()->toDateString())
                ->when($tenantId, fn ($q) => $q->where('created_by', $tenantId))->get();

            foreach ($due as $request) {
                DB::transaction(function () use ($request, &$applied) {
                    $employee = Employee::whereKey($request->employee_id)->lockForUpdate()->first();
                    if ($employee) {
                        $this->apply($request, $employee);
                        $applied++;
                    }
                });
            }
        }

        return $applied;
    }

    private function apply(Model $request, Employee $employee): void
    {
        if ($request instanceof Transfer) {
            $employee->update(['branch_id' => $request->branch_id, 'department_id' => $request->department_id]);
        } else {
            $employee->update(['status' => $request instanceof Resignation ? 'resigned' : 'terminated']);
            $employee->user()->update(['is_enable_login' => 0]); // nobody who left can log in any more
        }

        $request->update(['applied_at' => now()]);
    }

    private function dateColumn(Model $request): string
    {
        foreach (self::WORKFLOWS as [$model, $column]) {
            if ($request instanceof $model) {
                return $column;
            }
        }

        throw new \LogicException('Unknown lifecycle request');
    }

    private function assertPending(Model $request): void
    {
        if ($request->status !== 'pending') {
            throw new HrmException(__('Only a pending request can be approved or rejected.'));
        }
    }

    private function assertActive(Employee $employee): void
    {
        if (!$employee->isActive()) {
            throw new HrmException(__('This employee is not active.'));
        }
    }
}
