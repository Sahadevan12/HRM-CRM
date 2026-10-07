<?php

namespace Workdo\Hrm\Services;

use App\Events\PaySalary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Loan;
use Workdo\Hrm\Models\Payroll;
use Workdo\Hrm\Models\Payslip;
use Workdo\Hrm\Models\PayslipLine;

/**
 * Monthly payroll: draft (can be regenerated) -> approved (loan instalments are booked as repaid) -> paid (accounting is told).
 *
 *   basic earned   = basic * (working days since joining / working days of the month)
 *   daily rate     = basic / working days of the month
 *   unpaid days    = absent + unpaid leave + half days / 2         (from AttendanceSummary, the same numbers as the attendance report)
 *   absence        = daily rate * unpaid days
 *   overtime       = overtime hours * hourly rate (employee's hourly_rate, else daily rate / 8) * multiplier (setting hrmOvertimeMultiplier, default 1.5)
 *   allowances / deductions of the employee = fixed amount or % of the basic, prorated like the basic
 *   loan           = instalment, never more than what is left and never more than the pay that is left after the other deductions
 *   net            = basic earned + allowances + overtime - absence - deductions - loans
 */
class PayrollService
{
    public const DEFAULT_OVERTIME_MULTIPLIER = 1.5;
    public const PAY_METHODS = ['cash', 'bank'];

    public function __construct(private AttendanceSummary $summary)
    {
    }

    public function generate(int $tenantId, string $month, ?int $actorId): Payroll
    {
        [$from, $to] = $this->bounds($month);

        if ($from > now()->endOfMonth()->toDateString()) {
            throw new HrmException(__('Payroll cannot be generated for a future month.'));
        }

        return DB::transaction(function () use ($tenantId, $month, $from, $to, $actorId) {
            $payroll = Payroll::where('created_by', $tenantId)->where('month', $month)->lockForUpdate()->first();

            if ($payroll && $payroll->status !== 'draft') {
                throw new HrmException(__('The payroll of :month is already :status and cannot be generated again.', ['month' => $month, 'status' => __($payroll->status)]));
            }

            $payroll ??= Payroll::create(['month' => $month, 'status' => 'draft', 'creator_id' => $actorId, 'created_by' => $tenantId]);
            $payroll->payslips()->delete(); // lines cascade

            $employees = Employee::where('created_by', $tenantId)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhere('date_of_joining', '<=', $to))
                ->with(['salaryComponents.component'])->orderBy('employee_code')->get();

            $count = 0;
            foreach ($employees as $employee) {
                $count += $this->makePayslip($payroll, $employee, $from, $to) ? 1 : 0;
            }

            if ($count === 0) {
                throw new HrmException(__('There is no active employee with a working day in :month.', ['month' => $month]));
            }

            $this->refreshTotals($payroll);

            return $payroll->refresh();
        });
    }

    public function approve(Payroll $payroll, int $approverId): Payroll
    {
        return DB::transaction(function () use ($payroll, $approverId) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($payroll, 'draft', __('Only a draft payroll can be approved.'));

            foreach (PayslipLine::whereIn('payslip_id', $payroll->payslips()->pluck('id'))->where('kind', 'loan')->get() as $line) {
                $loan = Loan::whereKey($line->loan_id)->lockForUpdate()->first();
                if (!$loan || $loan->status !== 'active' || $line->amount > $loan->remaining() + 0.005) {
                    throw new HrmException(__('A loan changed since the payroll was generated. Generate the payroll again.'));
                }

                $loan->repaid = round($loan->repaid + $line->amount, 2);
                $loan->status = $loan->remaining() <= 0.004 ? 'completed' : 'active';
                $loan->save();
            }

            $payroll->update(['status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now()]);

            return $payroll;
        });
    }

    /** Back to draft (only before it is paid): the loan instalments are given back. */
    public function reopen(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($payroll, 'approved', __('Only an approved payroll can be reopened.'));

            foreach (PayslipLine::whereIn('payslip_id', $payroll->payslips()->pluck('id'))->where('kind', 'loan')->get() as $line) {
                $loan = Loan::whereKey($line->loan_id)->lockForUpdate()->first();
                if ($loan) {
                    $loan->repaid = max(0, round($loan->repaid - $line->amount, 2));
                    $loan->status = 'active';
                    $loan->save();
                }
            }

            $payroll->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);

            return $payroll;
        });
    }

    public function pay(Payroll $payroll, string $date, string $method, ?int $actorId): Payroll
    {
        if (!in_array($method, self::PAY_METHODS, true)) {
            throw new HrmException(__('Choose how the salaries are paid: cash or bank.'));
        }

        return DB::transaction(function () use ($payroll, $date, $method, $actorId) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($payroll, 'approved', __('Only an approved payroll can be paid.'));

            $sum = fn (array $kinds) => (float) PayslipLine::whereIn('payslip_id', $payroll->payslips()->pluck('id'))->whereIn('kind', $kinds)->sum('amount');
            $absence = $sum(['absence']);

            // accounting books it inside this transaction: if it fails nothing is marked as paid
            PaySalary::dispatch(
                $payroll->created_by, $payroll->id, $payroll->month, $date,
                round($payroll->total_earnings - $absence, 2), $payroll->total_net, round($sum(['deduction']), 2), round($sum(['loan']), 2),
                $method, $actorId,
            );

            $payroll->update(['status' => 'paid', 'paid_on' => $date]);

            return $payroll;
        });
    }

    public function delete(Payroll $payroll): void
    {
        $this->assertStatus($payroll, 'draft', __('Only a draft payroll can be deleted.'));
        $payroll->delete();
    }

    // ───────────── calculation ─────────────

    private function makePayslip(Payroll $payroll, Employee $employee, string $from, string $to): bool
    {
        $calendar = WorkCalendar::for($payroll->created_by);
        $effectiveFrom = $employee->date_of_joining && $employee->date_of_joining->toDateString() > $from ? $employee->date_of_joining->toDateString() : $from;

        $monthDays = $calendar->countWorkingDays($from, $to);
        $employedDays = $calendar->countWorkingDays($effectiveFrom, $to);
        if ($monthDays === 0 || $employedDays === 0) {
            return false;
        }

        $share = $employedDays / $monthDays;
        $basic = (float) $employee->basic_salary;
        $daily = $basic / $monthDays;
        $totals = $this->summary->forEmployee($employee, $effectiveFrom, $to)['totals'];

        $lines = [];
        $add = function (string $kind, string $label, float $amount, ?int $loanId = null) use (&$lines) {
            $amount = round($amount, 2);
            if ($amount > 0) {
                $lines[] = ['kind' => $kind, 'label' => $label, 'amount' => $amount, 'loan_id' => $loanId];
            }
        };

        $basicEarned = round($basic * $share, 2);
        $add('basic', $employedDays < $monthDays ? __('Basic salary (:a of :b working days)', ['a' => $employedDays, 'b' => $monthDays]) : __('Basic salary'), $basicEarned);

        foreach ($employee->salaryComponents as $assigned) {
            $component = $assigned->component;
            $value = $component->calc === 'percent' ? $basic * $assigned->value / 100 : $assigned->value;
            $add($component->type === 'allowance' ? 'allowance' : 'deduction', $component->name, $value * $share);
        }

        $unpaid = $totals['absent'] + $totals['leave_unpaid'] + $totals['half_day'] * 0.5;
        $add('absence', __(':days unpaid day(s)', ['days' => rtrim(rtrim(number_format($unpaid, 1), '0'), '.')]), min($basicEarned, $daily * $unpaid));

        $hourly = $employee->hourly_rate > 0 ? $employee->hourly_rate : $daily / AttendanceService::DEFAULT_SHIFT_HOURS;
        $multiplier = (float) (tenantSettings($payroll->created_by)['hrmOvertimeMultiplier'] ?? self::DEFAULT_OVERTIME_MULTIPLIER);
        $add('overtime', __('Overtime (:hours h)', ['hours' => $totals['overtime_hours']]), $totals['overtime_hours'] * $hourly * $multiplier);

        $earnings = $this->total($lines, true);
        $deductions = $this->total($lines, false);

        // loan instalments come last and can never push the net pay below zero
        foreach (Loan::where('employee_id', $employee->id)->where('status', 'active')->where('start_month', '<=', $from)->orderBy('id')->get() as $loan) {
            $available = round($earnings - $deductions, 2);
            $instalment = min($loan->installment, $loan->remaining(), max(0, $available));
            $add('loan', __('Loan: :title', ['title' => $loan->title]), $instalment, $loan->id);
            $deductions = $this->total($lines, false);
        }

        $slip = Payslip::create([
            'payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'basic_salary' => $basic, 'working_days' => $employedDays,
            'unpaid_days' => $unpaid, 'overtime_hours' => $totals['overtime_hours'],
            'earnings' => $earnings, 'deductions' => $deductions, 'net_pay' => round($earnings - $deductions, 2),
        ]);
        $slip->lines()->createMany($lines);

        return true;
    }

    /** @param array<int, array{kind: string, amount: float}> $lines */
    private function total(array $lines, bool $earnings): float
    {
        return round(array_sum(array_map(
            fn ($l) => in_array($l['kind'], PayslipLine::EARNINGS, true) === $earnings ? $l['amount'] : 0,
            $lines,
        )), 2);
    }

    private function refreshTotals(Payroll $payroll): void
    {
        $payroll->update([
            'total_earnings' => round($payroll->payslips()->sum('earnings'), 2),
            'total_deductions' => round($payroll->payslips()->sum('deductions'), 2),
            'total_net' => round($payroll->payslips()->sum('net_pay'), 2),
        ]);
    }

    private function assertStatus(Payroll $payroll, string $status, string $message): void
    {
        if ($payroll->status !== $status) {
            throw new HrmException($message);
        }
    }

    /** @return array{0: string, 1: string} first and last day of a YYYY-MM month */
    private function bounds(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new HrmException(__('Choose a valid month.'));
        }

        $start = Carbon::parse("{$month}-01");

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
